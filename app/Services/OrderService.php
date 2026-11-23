<?php

namespace App\Services;

use App\Exceptions\MidtransException;
use App\Exceptions\OrderOperationException;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\RupiahAmount;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class OrderService
{
    public const PLATFORM_FEE_IDR = 0;

    public function __construct(
        private readonly RajaOngkirService $rajaOngkirService,
        private readonly MidtransService $midtransService
    ) {}

    /**
     * Buat pesanan baru untuk 1 lot material berbayar (server-authoritative, atomik, idempoten).
     *
     * @param  array<string, mixed>  $input
     * @return array{order: Order, idempotent_replay: bool}
     */
    public function createOrder(User $buyer, array $input): array
    {
        $listingId      = (int) $input['listing_id'];
        $addressId      = (int) $input['address_id'];
        $courierCode    = strtolower(trim((string) $input['courier_code']));
        $courierService = trim((string) $input['courier_service']);
        $buyerNote      = isset($input['buyer_note']) && trim((string) $input['buyer_note']) !== ''
            ? trim((string) $input['buyer_note'])
            : null;
        $idempotencyKey = trim((string) $input['idempotency_key']);

        // 1. Fast-path idempotency check sebelum memanggil API eksternal
        $existing = Order::where('buyer_id', $buyer->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->resolveIdempotentOrder($existing, $listingId, $addressId, $courierCode, $courierService, $buyerNote);
        }

        // 2. Pre-validation listing & alamat pembeli (di luar DB lock sebelum panggil RajaOngkir)
        $preListing = MaterialListing::find($listingId);
        if (! $preListing) {
            throw new OrderOperationException('Listing material tidak ditemukan.', 404);
        }

        $preAddress = UserAddress::where('user_id', $buyer->id)
            ->where('id', $addressId)
            ->first();

        if (! $preAddress) {
            throw new OrderOperationException('Alamat pengiriman tidak ditemukan.', 404);
        }

        $preCheck = $this->validateListingForCheckout($preListing, $buyer);

        // 3. Panggil RajaOngkir di luar DB lock untuk mendapatkan ongkir otoritatif
        try {
            $shippingOptions = $this->rajaOngkirService->calculateDomesticCost(
                $preCheck['origin_destination_id'],
                (int) $preAddress->destination_id,
                $preCheck['weight_grams']
            );
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            $http = in_array($code, [422, 429, 502, 503, 504], true) ? $code : 502;
            throw new OrderOperationException(
                $e->getMessage() ?: 'Gagal mengambil tarif pengiriman dari RajaOngkir.',
                $http,
                previous: $e
            );
        }

        $matchedOption = $this->matchCourierOption($shippingOptions, $courierCode, $courierService);
        if ($matchedOption === null) {
            throw new OrderOperationException(
                'Layanan kurir yang dipilih tidak tersedia untuk rute dan berat material ini.',
                422
            );
        }

        try {
            $shippingCost = RupiahAmount::parseExactWhole($matchedOption['cost']);
        } catch (InvalidArgumentException $e) {
            throw new OrderOperationException('Tarif pengiriman dari penyedia kurir tidak valid.', 502, previous: $e);
        }

        // 4. Transaksi DB singkat dengan row lock (tanpa HTTP eksternal di dalam transaksi)
        try {
            $txResult = DB::transaction(function () use (
                $buyer,
                $listingId,
                $addressId,
                $idempotencyKey,
                $buyerNote,
                $preCheck,
                $preAddress,
                $matchedOption,
                $shippingCost,
                $courierCode,
                $courierService
            ): array {
                // Re-check idempotency di dalam transaksi
                $existingInTx = Order::where('buyer_id', $buyer->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingInTx) {
                    return $this->resolveIdempotentOrder(
                        $existingInTx,
                        $listingId,
                        $addressId,
                        $courierCode,
                        $courierService,
                        $buyerNote
                    );
                }

                // Kunci baris MaterialListing (Prioritas Lock Utama untuk reservasi)
                $listing = MaterialListing::where('id', $listingId)->lockForUpdate()->first();
                if (! $listing) {
                    throw new OrderOperationException('Listing material tidak ditemukan.', 404);
                }

                // Re-validasi penuh di dalam lock
                $lockedCheck = $this->validateListingForCheckout($listing, $buyer, true);

                // Pastikan atribut ongkir listing tidak berubah di antara call RajaOngkir dan lock
                if (
                    $lockedCheck['origin_destination_id'] !== $preCheck['origin_destination_id']
                    || $lockedCheck['weight_grams'] !== $preCheck['weight_grams']
                    || $lockedCheck['item_price'] !== $preCheck['item_price']
                ) {
                    throw new OrderOperationException(
                        'Data harga, berat, atau asal listing baru saja berubah. Silakan muat ulang dan coba lagi.',
                        409
                    );
                }

                // Kunci/baca ulang alamat milik pembeli
                $address = UserAddress::where('user_id', $buyer->id)
                    ->where('id', $addressId)
                    ->lockForUpdate()
                    ->first();

                if (! $address) {
                    throw new OrderOperationException('Alamat pengiriman tidak ditemukan.', 404);
                }

                if ((int) $address->destination_id !== (int) $preAddress->destination_id) {
                    throw new OrderOperationException(
                        'Alamat pengiriman baru saja berubah. Silakan muat ulang estimasi ongkir.',
                        409
                    );
                }

                // Cek konflik order aktif atau handover aktif terhadap listing yang sama
                $hasActiveOrder = Order::where('listing_id', $listing->id)
                    ->whereIn('status', Order::ACTIVE_RESERVATION_STATUSES)
                    ->exists();

                $hasActiveHandover = HandoverRequest::where('listing_id', $listing->id)
                    ->whereIn('status', ['approved', 'handed_over'])
                    ->exists();

                if ($hasActiveOrder || $hasActiveHandover) {
                    throw new OrderOperationException(
                        'Listing material ini sudah direservasi oleh transaksi lain.',
                        409
                    );
                }

                $itemPrice   = $lockedCheck['item_price'];
                $platformFee = self::PLATFORM_FEE_IDR;
                $totalAmount = $itemPrice + $shippingCost + $platformFee;

                if ($totalAmount <= 0) {
                    throw new OrderOperationException('Total pembayaran pesanan tidak valid.', 422);
                }

                $now              = now();
                $expiryMinutes    = $this->midtransService->paymentExpiryMinutes();
                $paymentExpiresAt = $now->copy()->addMinutes($expiryMinutes);
                $orderNumber      = $this->generateOrderNumber($now);
                $midtransOrderId  = $this->generateMidtransOrderId($now);

                $order = Order::create([
                    'order_number'                   => $orderNumber,
                    'idempotency_key'                => $idempotencyKey,
                    'buyer_id'                       => $buyer->id,
                    'seller_id'                      => $listing->owner_id,
                    'listing_id'                     => $listing->id,
                    'buyer_address_id'               => $address->id,
                    'listing_name_snapshot'          => $listing->name,
                    'listing_category_snapshot'      => $listing->category,
                    'listing_weight_kg_snapshot'     => $listing->weight_kg,
                    'listing_image_url_snapshot'     => $listing->getRawOriginal('image_url'),
                    'origin_destination_id_snapshot' => $lockedCheck['origin_destination_id'],
                    'origin_label_snapshot'          => $lockedCheck['origin_label'],
                    'recipient_name_snapshot'        => $address->recipient_name,
                    'recipient_phone_snapshot'       => $address->recipient_phone,
                    'destination_id_snapshot'        => (int) $address->destination_id,
                    'destination_label_snapshot'     => $address->destination_label,
                    'address_detail_snapshot'        => $address->address_detail,
                    'postal_code_snapshot'           => $address->postal_code,
                    'delivery_note_snapshot'         => $address->delivery_note,
                    'item_price'                     => $itemPrice,
                    'shipping_cost'                  => $shippingCost,
                    'platform_fee'                   => $platformFee,
                    'total_amount'                   => $totalAmount,
                    'currency'                       => 'IDR',
                    'courier_code'                   => $matchedOption['courier_code'],
                    'courier_name'                   => $matchedOption['courier_name'],
                    'courier_service'                => $matchedOption['service'],
                    'courier_description'            => $matchedOption['description'] ?? null,
                    'estimated_etd'                  => $matchedOption['etd'] ?? null,
                    'status'                         => Order::STATUS_PENDING_PAYMENT,
                    'payment_status'                 => Order::PAYMENT_UNPAID,
                    'payment_expires_at'             => $paymentExpiresAt,
                    'buyer_note'                     => $buyerNote,
                    'midtrans_order_id'              => $midtransOrderId,
                    'created_at'                     => $now,
                    'updated_at'                     => $now,
                ]);

                // Reservasi listing secara atomik
                $listing->update(['status' => 'reserved']);

                OrderStatusHistory::create([
                    'order_id'    => $order->id,
                    'from_status' => null,
                    'to_status'   => Order::STATUS_PENDING_PAYMENT,
                    'actor_type'  => OrderStatusHistory::ACTOR_BUYER,
                    'actor_id'    => $buyer->id,
                    'source'      => 'checkout',
                    'reason'      => 'Pesanan dibuat dan menunggu pembayaran.',
                    'metadata'    => [
                        'courier_code'    => $order->courier_code,
                        'courier_service' => $order->courier_service,
                        'total_amount'    => $order->total_amount,
                    ],
                ]);

                return ['order' => $order, 'idempotent_replay' => false];
            });
        } catch (QueryException $e) {
            // Tangani race unique constraint (buyer_id, idempotency_key)
            $racedOrder = Order::where('buyer_id', $buyer->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($racedOrder) {
                return $this->resolveIdempotentOrder(
                    $racedOrder,
                    $listingId,
                    $addressId,
                    $courierCode,
                    $courierService,
                    $buyerNote
                );
            }

            throw $e;
        }

        if ($txResult['idempotent_replay']) {
            return $txResult;
        }

        /** @var Order $order */
        $order = $txResult['order'];

        // 5. Panggil Midtrans Snap DI LUAR DB lock
        $this->initiateSnapForOrder($order, $buyer, isRetry: false);

        return ['order' => $order->fresh(), 'idempotent_replay' => false];
    }

    /**
     * Ulangi/lanjutkan sesi pembayaran Snap untuk pesanan pending_payment milik pembeli.
     */
    public function retryPayment(Order $order, User $buyer): Order
    {
        $fresh = Order::findOrFail($order->id);

        if ($fresh->status === Order::STATUS_PAID || $fresh->payment_status === Order::PAYMENT_PAID) {
            throw new OrderOperationException(
                'Pesanan ini sudah dibayar dan tidak memerlukan sesi pembayaran baru.',
                409,
                ['order' => $this->serializeDetail($fresh->loadMissing(['buyer:id,name', 'seller:id,name', 'statusHistories']), $buyer)]
            );
        }

        if ($fresh->status !== Order::STATUS_PENDING_PAYMENT) {
            throw new OrderOperationException(
                'Pesanan tidak lagi dalam status menunggu pembayaran.',
                409
            );
        }

        // Jika Snap URL sudah ada dan belum melewati batas expiry, kembalikan secara idempoten
        if (
            ! empty($fresh->midtrans_snap_token)
            && ! empty($fresh->midtrans_redirect_url)
            && ($fresh->payment_expires_at === null || $fresh->payment_expires_at->isFuture())
        ) {
            return $fresh;
        }

        // Periksa status otoritatif Midtrans sebelum mencoba ulang Snap dengan midtrans_order_id yang sama
        $providerStatus = $this->fetchMidtransStatusOrNull($fresh->midtrans_order_id);

        if ($providerStatus !== null) {
            $reconciled = $this->reconcilePayment(
                $fresh,
                $providerStatus,
                'retry_payment',
                OrderStatusHistory::ACTOR_BUYER,
                $buyer->id
            );

            $updated = $reconciled['order'];

            if ($updated->status === Order::STATUS_PAID || $updated->payment_status === Order::PAYMENT_PAID) {
                throw new OrderOperationException(
                    'Pembayaran untuk pesanan ini sudah berhasil dikonfirmasi.',
                    409,
                    ['order' => $this->serializeDetail($updated->loadMissing(['buyer:id,name', 'seller:id,name', 'statusHistories']), $buyer)]
                );
            }

            if (in_array($updated->status, [Order::STATUS_EXPIRED, Order::STATUS_CANCELLED], true)) {
                throw new OrderOperationException(
                    'Transaksi pembayaran untuk pesanan ini sudah kadaluarsa atau dibatalkan.',
                    409,
                    ['order' => $this->serializeDetail($updated->loadMissing(['buyer:id,name', 'seller:id,name', 'statusHistories']), $buyer)]
                );
            }

            if (! empty($updated->midtrans_redirect_url)) {
                return $updated;
            }
        }

        if ($fresh->payment_expires_at !== null && $fresh->payment_expires_at->isPast()) {
            throw new OrderOperationException(
                'Batas waktu pembayaran untuk pesanan ini telah habis.',
                409
            );
        }

        $this->initiateSnapForOrder($fresh, $buyer, isRetry: true);

        return $fresh->fresh();
    }

    /**
     * Sinkronisasi status pembayaran langsung dari Midtrans GET Status API (server-to-server).
     *
     * @return array{order: Order, synced: bool, message: string}
     */
    public function refreshPaymentStatus(Order $order, User $buyer): array
    {
        $fresh = Order::findOrFail($order->id);

        try {
            $providerStatus = $this->fetchMidtransStatusOrNull($fresh->midtrans_order_id);
        } catch (MidtransException $e) {
            throw new OrderOperationException(
                $e->getMessage(),
                $this->mapMidtransHttpStatus($e),
                ['reconciliation_required' => $e->reconciliationRequired],
                previous: $e
            );
        }

        // 404 pada GET Status berarti pembeli belum memilih metode pembayaran di Snap (bukan gagal bayar)
        if ($providerStatus === null) {
            return [
                'order'   => $fresh,
                'synced'  => false,
                'message' => 'Pembeli belum menyelesaikan pemilihan metode pembayaran di Midtrans.',
            ];
        }

        $reconciled = $this->reconcilePayment(
            $fresh,
            $providerStatus,
            'payment_refresh',
            OrderStatusHistory::ACTOR_BUYER,
            $buyer->id
        );

        return [
            'order'   => $reconciled['order'],
            'synced'  => true,
            'message' => 'Status pembayaran berhasil diperbarui dari Midtrans.',
        ];
    }

    /**
     * Pembatalan pesanan oleh pembeli (hanya untuk pending_payment setelah verifikasi Midtrans).
     */
    public function cancelOrderByBuyer(Order $order, User $buyer, ?string $reason = null): Order
    {
        $fresh = Order::findOrFail($order->id);

        if ($fresh->status === Order::STATUS_PAID || $fresh->status === Order::STATUS_SHIPPED || $fresh->status === Order::STATUS_COMPLETED) {
            throw new OrderOperationException(
                'Pesanan yang sudah dibayar tidak dapat dibatalkan langsung. Silakan hubungi dukungan pesanan.',
                409
            );
        }

        // Idempoten: pembatalan ulang pada pesanan yang sudah cancelled mengembalikan state saat ini tanpa error/duplikasi
        if ($fresh->status === Order::STATUS_CANCELLED) {
            return $fresh;
        }

        if ($fresh->status !== Order::STATUS_PENDING_PAYMENT) {
            throw new OrderOperationException(
                'Pesanan ini sudah tidak dalam status menunggu pembayaran.',
                409
            );
        }

        // 1. Cek status otoritatif transaksi pada Core API GET Status terlebih dahulu
        try {
            $providerStatus = $this->fetchMidtransStatusOrNull($fresh->midtrans_order_id);
        } catch (MidtransException $e) {
            throw new OrderOperationException(
                'Gagal memverifikasi status pembayaran di Midtrans. Pesanan tetap dipertahankan untuk keamanan transaksi.',
                $this->mapMidtransHttpStatus($e),
                ['reconciliation_required' => true],
                previous: $e
            );
        }

        // 2. Belum ada transaksi Core API (404): batalkan Snap Session via POST /snap/v1/transactions/{token}/cancel
        if ($providerStatus === null) {
            if (! empty($fresh->midtrans_snap_token)) {
                try {
                    $snapCancel = $this->midtransService->cancelSnapSession($fresh->midtrans_snap_token);
                } catch (MidtransException $e) {
                    throw new OrderOperationException(
                        'Gagal membatalkan sesi pembayaran Midtrans Snap. Reservasi listing dipertahankan untuk rekonsiliasi.',
                        $this->mapMidtransHttpStatus($e),
                        ['reconciliation_required' => true],
                        previous: $e
                    );
                }

                if (in_array($snapCancel['status'], ['canceled', 'already_canceled'], true)) {
                    return $this->finalizeUnpaidOrderCancellation(
                        $fresh,
                        Order::STATUS_CANCELLED,
                        Order::PAYMENT_CANCELLED,
                        Order::CANCELLED_BY_BUYER,
                        OrderStatusHistory::ACTOR_BUYER,
                        $buyer->id,
                        'buyer_cancel_snap',
                        $reason ?? 'Sesi pembayaran Snap dibatalkan oleh pembeli.'
                    );
                }

                if ($snapCancel['status'] === 'in_progress') {
                    // Transaksi sedang diproses di Midtrans -> JANGAN lepas listing; periksa GET Status & rekonsiliasi
                    return $this->reconcileOnInProgressSnapCancel($fresh, $buyer);
                }

                // token not found: jika batas expiry belum lewat, jangan asumsikan aman dilepas tanpa verifikasi
                if ($fresh->payment_expires_at === null || $fresh->payment_expires_at->isFuture()) {
                    throw new OrderOperationException(
                        'Token sesi pembayaran tidak ditemukan di Midtrans. Reservasi dipertahankan hingga status pembayaran terkonfirmasi.',
                        409,
                        ['reconciliation_required' => true]
                    );
                }
            }

            // Tidak ada token Snap tersimpan atau masa expiry sudah berlalu dengan GET Status 404 -> aman dibatalkan
            return $this->finalizeUnpaidOrderCancellation(
                $fresh,
                Order::STATUS_CANCELLED,
                Order::PAYMENT_CANCELLED,
                Order::CANCELLED_BY_BUYER,
                OrderStatusHistory::ACTOR_BUYER,
                $buyer->id,
                'buyer_cancel',
                $reason ?? 'Dibatalkan oleh pembeli.'
            );
        }

        $txStatus = strtolower((string) ($providerStatus['transaction_status'] ?? ''));

        // Jika ternyata sudah dibayar di Midtrans -> rekonsiliasi ke paid dan tolak cancel
        if ($txStatus === 'settlement' || ($txStatus === 'capture' && $this->isCaptureAccepted($providerStatus['fraud_status'] ?? null))) {
            $reconciled = $this->reconcilePayment(
                $fresh,
                $providerStatus,
                'buyer_cancel_check',
                OrderStatusHistory::ACTOR_MIDTRANS
            );

            throw new OrderOperationException(
                'Pembayaran telah diterima oleh Midtrans sehingga pesanan tidak dapat dibatalkan.',
                409,
                ['order' => $this->serializeDetail($reconciled['order']->loadMissing(['buyer:id,name', 'seller:id,name', 'statusHistories']), $buyer)]
            );
        }

        // Jika sudah terminal di Midtrans (expire/cancel/deny/failure) -> langsung rekonsiliasi
        if (in_array($txStatus, ['expire', 'cancel', 'deny', 'failure'], true)) {
            $reconciled = $this->reconcilePayment(
                $fresh,
                $providerStatus,
                'buyer_cancel',
                OrderStatusHistory::ACTOR_BUYER,
                $buyer->id,
                $reason
            );

            return $reconciled['order'];
        }

        // Jika pending -> minta Midtrans membatalkan transaksi secara otoritatif
        if ($txStatus === 'pending') {
            try {
                $cancelResult = $this->midtransService->cancelTransaction($fresh->midtrans_order_id);
            } catch (MidtransException $e) {
                throw new OrderOperationException(
                    'Gagal membatalkan transaksi di Midtrans. Reservasi listing dipertahankan hingga status terkonfirmasi.',
                    $this->mapMidtransHttpStatus($e),
                    previous: $e
                );
            }

            $reconciled = $this->reconcilePayment(
                $fresh,
                $cancelResult,
                'buyer_cancel',
                OrderStatusHistory::ACTOR_BUYER,
                $buyer->id,
                $reason ?? 'Dibatalkan oleh pembeli.'
            );

            if (! in_array($reconciled['order']->status, [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED], true)) {
                throw new OrderOperationException(
                    'Pembatalan belum terkonfirmasi terminal oleh Midtrans.',
                    409
                );
            }

            return $reconciled['order'];
        }

        throw new OrderOperationException(
            'Status pembayaran saat ini tidak memungkinkan pembatalan otomatis.',
            409
        );
    }

    /**
     * Penjual memasukkan nomor resi dan menandai pesanan telah dikirim (paid -> shipped).
     */
    public function shipOrder(Order $order, User $seller, string $trackingNumber, ?string $shipmentNotes = null): Order
    {
        return DB::transaction(function () use ($order, $seller, $trackingNumber, $shipmentNotes): Order {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Order::STATUS_SHIPPED) {
                throw new OrderOperationException('Pesanan ini sudah ditandai dikirim sebelumnya.', 409);
            }

            if ($locked->status !== Order::STATUS_PAID) {
                throw new OrderOperationException(
                    'Hanya pesanan dengan status sudah dibayar (paid) yang dapat dikirim.',
                    409
                );
            }

            $fromStatus = $locked->status;

            $locked->update([
                'status'          => Order::STATUS_SHIPPED,
                'tracking_number' => trim($trackingNumber),
                'shipment_notes'  => $shipmentNotes !== null && trim($shipmentNotes) !== '' ? trim($shipmentNotes) : null,
                'shipped_at'      => now(),
            ]);

            OrderStatusHistory::create([
                'order_id'    => $locked->id,
                'from_status' => $fromStatus,
                'to_status'   => Order::STATUS_SHIPPED,
                'actor_type'  => OrderStatusHistory::ACTOR_SELLER,
                'actor_id'    => $seller->id,
                'source'      => 'seller_ship',
                'reason'      => 'Penjual mengirimkan pesanan dengan nomor resi.',
                'metadata'    => [
                    'courier_code'    => $locked->courier_code,
                    'courier_service' => $locked->courier_service,
                    'tracking_number' => $locked->tracking_number,
                ],
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Pembeli mengonfirmasi penerimaan barang (shipped -> completed, listing -> completed).
     * Konfirmasi ganda pada order yang sudah completed bersifat aman (idempoten, tanpa duplikasi history).
     *
     * @return array{order: Order, already_completed: bool}
     */
    public function confirmDelivery(Order $order, User $buyer): array
    {
        return DB::transaction(function () use ($order, $buyer): array {
            $lockedOrder   = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $lockedListing = MaterialListing::where('id', $lockedOrder->listing_id)->lockForUpdate()->first();

            if ($lockedOrder->status === Order::STATUS_COMPLETED) {
                return ['order' => $lockedOrder, 'already_completed' => true];
            }

            if ($lockedOrder->status !== Order::STATUS_SHIPPED) {
                throw new OrderOperationException(
                    'Hanya pesanan yang sedang dikirim (shipped) yang dapat dikonfirmasi penerimaannya.',
                    409
                );
            }

            $now = now();

            $lockedOrder->update([
                'status'       => Order::STATUS_COMPLETED,
                'delivered_at' => $now,
                'completed_at' => $now,
            ]);

            if ($lockedListing && $lockedListing->status !== 'completed') {
                $lockedListing->update(['status' => 'completed']);
            }

            OrderStatusHistory::create([
                'order_id'    => $lockedOrder->id,
                'from_status' => Order::STATUS_SHIPPED,
                'to_status'   => Order::STATUS_COMPLETED,
                'actor_type'  => OrderStatusHistory::ACTOR_BUYER,
                'actor_id'    => $buyer->id,
                'source'      => 'buyer_confirm_delivery',
                'reason'      => 'Pembeli mengonfirmasi pesanan telah diterima.',
            ]);

            return ['order' => $lockedOrder->fresh(), 'already_completed' => false];
        });
    }

    /**
     * SATU-SATUNYA metode rekonsiliasi pembayaran otoritatif (dipakai webhook, refresh-payment, cancel, dan expiry command).
     *
     * @param  array<string, mixed>  $providerData
     * @return array{order: Order, result: string, changed: bool}
     */
    public function reconcilePayment(
        Order $order,
        array $providerData,
        string $source,
        string $actorType = OrderStatusHistory::ACTOR_MIDTRANS,
        ?int $actorId = null,
        ?string $customReason = null
    ): array {
        return DB::transaction(function () use ($order, $providerData, $source, $actorType, $actorId, $customReason): array {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            // 1. Validasi order_id Midtrans cocok
            $providerOrderId = trim((string) ($providerData['order_id'] ?? ''));
            if ($providerOrderId === '' || $providerOrderId !== $lockedOrder->midtrans_order_id) {
                throw new OrderOperationException('Midtrans order_id tidak cocok dengan pesanan internal.', 422);
            }

            // 2. Validasi currency (wajib IDR jika disertakan)
            if (isset($providerData['currency']) && $providerData['currency'] !== null && trim((string) $providerData['currency']) !== '') {
                if (strtoupper(trim((string) $providerData['currency'])) !== 'IDR') {
                    throw new OrderOperationException('Mata uang transaksi tidak valid (harus IDR).', 422);
                }
            }

            // 3. Validasi nominal tepat tanpa float
            $rawGrossAmount = $providerData['gross_amount'] ?? null;
            if ($rawGrossAmount === null || (! is_string($rawGrossAmount) && ! is_int($rawGrossAmount))) {
                throw new OrderOperationException('Nilai gross_amount dari Midtrans tidak valid.', 422);
            }

            try {
                $parsedGrossAmount = RupiahAmount::parseExactWhole($rawGrossAmount);
            } catch (InvalidArgumentException $e) {
                throw new OrderOperationException('Format gross_amount dari Midtrans tidak valid.', 422, previous: $e);
            }

            if ($parsedGrossAmount !== (int) $lockedOrder->total_amount) {
                throw new OrderOperationException('Nominal pembayaran tidak cocok dengan total pesanan.', 422);
            }

            $txStatus    = strtolower(trim((string) ($providerData['transaction_status'] ?? '')));
            $fraudStatus = isset($providerData['fraud_status']) && trim((string) $providerData['fraud_status']) !== ''
                ? strtolower(trim((string) $providerData['fraud_status']))
                : null;
            $txId        = isset($providerData['transaction_id']) && trim((string) $providerData['transaction_id']) !== ''
                ? trim((string) $providerData['transaction_id'])
                : $lockedOrder->midtrans_transaction_id;
            $paymentType = isset($providerData['payment_type']) && trim((string) $providerData['payment_type']) !== ''
                ? trim((string) $providerData['payment_type'])
                : $lockedOrder->midtrans_payment_type;

            if ($txStatus === '') {
                return ['order' => $lockedOrder, 'result' => 'ignored_empty_status', 'changed' => false];
            }

            // 4. Penanganan settlement / capture
            if ($txStatus === 'settlement' || $txStatus === 'capture') {
                if ($txStatus === 'capture' && $fraudStatus === 'challenge') {
                    // Challenge tetap pending_payment, tidak dirilis dan tidak ditandai paid
                    if (in_array($lockedOrder->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                        return ['order' => $lockedOrder, 'result' => 'ignored_monotonic', 'changed' => false];
                    }

                    $lockedOrder->update([
                        'payment_status'              => Order::PAYMENT_PENDING,
                        'midtrans_transaction_id'     => $txId,
                        'midtrans_payment_type'       => $paymentType,
                        'midtrans_transaction_status' => $txStatus,
                        'midtrans_fraud_status'       => $fraudStatus,
                    ]);

                    return ['order' => $lockedOrder->fresh(), 'result' => 'challenge_pending', 'changed' => false];
                }

                if ($txStatus === 'capture' && ! $this->isCaptureAccepted($fraudStatus)) {
                    // Capture dengan fraud_status deny/tidak diterima
                    return $this->applyTerminalFailureInTx(
                        $lockedOrder,
                        Order::STATUS_CANCELLED,
                        Order::PAYMENT_FAILED,
                        $txId,
                        $paymentType,
                        $txStatus,
                        $fraudStatus,
                        $source,
                        $actorType,
                        $actorId,
                        $customReason ?? 'Pembayaran ditolak berdasarkan pemeriksaan fraud Midtrans.'
                    );
                }

                // Pembayaran terverifikasi sukses (settlement atau capture accept)
                $fromStatus = $lockedOrder->status;
                $updates    = [
                    'payment_status'              => Order::PAYMENT_PAID,
                    'paid_at'                     => $lockedOrder->paid_at ?? now(),
                    'midtrans_transaction_id'     => $txId,
                    'midtrans_payment_type'       => $paymentType,
                    'midtrans_transaction_status' => $txStatus,
                    'midtrans_fraud_status'       => $fraudStatus,
                ];

                // Sudah paid/shipped/completed -> jaga monotonicity tanpa duplikasi history
                if (in_array($fromStatus, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                    $lockedOrder->update($updates);

                    return ['order' => $lockedOrder->fresh(), 'result' => 'already_paid_or_progressed', 'changed' => false];
                }

                // Late settlement setelah order expired/cancelled -> tandai untuk rekonsiliasi manual tanpa rusak listing
                if (in_array($fromStatus, [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED], true)) {
                    $lockedOrder->update($updates);

                    OrderStatusHistory::create([
                        'order_id'    => $lockedOrder->id,
                        'from_status' => $fromStatus,
                        'to_status'   => $fromStatus,
                        'actor_type'  => $actorType,
                        'actor_id'    => $actorId,
                        'source'      => $source,
                        'reason'      => 'Pembayaran diterima setelah pesanan berakhir; memerlukan rekonsiliasi manual.',
                        'metadata'    => [
                            'manual_reconciliation_required' => true,
                            'midtrans_transaction_status'    => $txStatus,
                            'midtrans_transaction_id'        => $txId,
                        ],
                    ]);

                    return ['order' => $lockedOrder->fresh(), 'result' => 'late_settlement_flagged', 'changed' => false];
                }

                // Normal: pending_payment -> paid (listing tetap reserved)
                $updates['status'] = Order::STATUS_PAID;
                $lockedOrder->update($updates);

                OrderStatusHistory::create([
                    'order_id'    => $lockedOrder->id,
                    'from_status' => $fromStatus,
                    'to_status'   => Order::STATUS_PAID,
                    'actor_type'  => $actorType,
                    'actor_id'    => $actorId,
                    'source'      => $source,
                    'reason'      => 'Pembayaran terverifikasi oleh Midtrans.',
                    'metadata'    => [
                        'payment_type'                => $paymentType,
                        'midtrans_transaction_status' => $txStatus,
                    ],
                ]);

                return ['order' => $lockedOrder->fresh(), 'result' => 'marked_paid', 'changed' => true];
            }

            // 5. Penanganan pending
            if ($txStatus === 'pending') {
                // Jangan pernah downgrade order yang sudah paid/shipped/completed/cancelled/expired
                if ($lockedOrder->status !== Order::STATUS_PENDING_PAYMENT) {
                    return ['order' => $lockedOrder, 'result' => 'ignored_monotonic', 'changed' => false];
                }

                $lockedOrder->update([
                    'payment_status'              => Order::PAYMENT_PENDING,
                    'midtrans_transaction_id'     => $txId,
                    'midtrans_payment_type'       => $paymentType,
                    'midtrans_transaction_status' => $txStatus,
                    'midtrans_fraud_status'       => $fraudStatus,
                ]);

                return ['order' => $lockedOrder->fresh(), 'result' => 'marked_pending', 'changed' => false];
            }

            // 6. Penanganan expire
            if ($txStatus === 'expire') {
                return $this->applyTerminalFailureInTx(
                    $lockedOrder,
                    Order::STATUS_EXPIRED,
                    Order::PAYMENT_EXPIRED,
                    $txId,
                    $paymentType,
                    $txStatus,
                    $fraudStatus,
                    $source,
                    $actorType,
                    $actorId,
                    $customReason ?? 'Pembayaran kadaluarsa di Midtrans.'
                );
            }

            // 7. Penanganan cancel / deny / failure
            if (in_array($txStatus, ['cancel', 'deny', 'failure'], true)) {
                $targetPaymentStatus = $txStatus === 'cancel' ? Order::PAYMENT_CANCELLED : Order::PAYMENT_FAILED;

                return $this->applyTerminalFailureInTx(
                    $lockedOrder,
                    Order::STATUS_CANCELLED,
                    $targetPaymentStatus,
                    $txId,
                    $paymentType,
                    $txStatus,
                    $fraudStatus,
                    $source,
                    $actorType,
                    $actorId,
                    $customReason ?? "Transaksi pembayaran berakhir dengan status '{$txStatus}' di Midtrans."
                );
            }

            // 8. Penanganan refund / partial_refund / chargeback / partial_chargeback
            if (in_array($txStatus, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
                $lockedOrder->update([
                    'payment_status'              => Order::PAYMENT_REFUNDED,
                    'midtrans_transaction_id'     => $txId,
                    'midtrans_payment_type'       => $paymentType,
                    'midtrans_transaction_status' => $txStatus,
                    'midtrans_fraud_status'       => $fraudStatus,
                ]);

                // Jangan pernah otomatis relist item yang sudah paid/shipped/completed; catat untuk rekonsiliasi manual
                OrderStatusHistory::create([
                    'order_id'    => $lockedOrder->id,
                    'from_status' => $lockedOrder->status,
                    'to_status'   => $lockedOrder->status,
                    'actor_type'  => $actorType,
                    'actor_id'    => $actorId,
                    'source'      => $source,
                    'reason'      => "Notifikasi pembalikan dana ({$txStatus}) diterima; memerlukan rekonsiliasi manual.",
                    'metadata'    => [
                        'financial_reversal'             => $txStatus,
                        'manual_reconciliation_required' => true,
                        'midtrans_transaction_id'        => $txId,
                    ],
                ]);

                return ['order' => $lockedOrder->fresh(), 'result' => 'financial_reversal_recorded', 'changed' => false];
            }

            return ['order' => $lockedOrder, 'result' => 'ignored_unhandled_status', 'changed' => false];
        });
    }

    /**
     * Proses satu pesanan pending_payment yang telah melewati payment_expires_at (dipanggil Artisan command).
     *
     * @return string paid|expired|cancelled|still_pending|reconciliation_needed|unchanged
     */
    public function processExpiredCandidate(Order $order): string
    {
        $fresh = Order::find($order->id);
        if (! $fresh || $fresh->status !== Order::STATUS_PENDING_PAYMENT) {
            return 'unchanged';
        }

        // 1. Cek status otoritatif Midtrans (DI LUAR DB lock)
        try {
            $providerStatus = $this->fetchMidtransStatusOrNull($fresh->midtrans_order_id);
        } catch (MidtransException $e) {
            Log::warning('OrderService: gagal memeriksa status Midtrans pada expiry sweep; reservasi dipertahankan.', [
                'order_id'          => $fresh->id,
                'midtrans_order_id' => $fresh->midtrans_order_id,
                'is_timeout'        => $e->isTimeout,
                'http_status'       => $e->httpStatus,
            ]);

            return 'reconciliation_needed';
        }

        // 2. Jika GET Status 404 (pembeli belum pernah memilih metode bayar) & masa expiry Snap sudah lewat -> aman expired
        if ($providerStatus === null) {
            if ($fresh->payment_expires_at === null || $fresh->payment_expires_at->isFuture()) {
                return 'still_pending';
            }

            $this->finalizeUnpaidOrderCancellation(
                $fresh,
                Order::STATUS_EXPIRED,
                Order::PAYMENT_EXPIRED,
                Order::CANCELLED_BY_SYSTEM,
                OrderStatusHistory::ACTOR_SYSTEM,
                null,
                'expiry_command',
                'Sesi pembayaran Snap berakhir tanpa metode pembayaran dipilih.'
            );

            return 'expired';
        }

        $txStatus = strtolower((string) ($providerStatus['transaction_status'] ?? ''));

        // 3. Jika Midtrans melaporkan settlement / capture accepted -> tandai paid (JANGAN expire!)
        if ($txStatus === 'settlement' || ($txStatus === 'capture' && $this->isCaptureAccepted($providerStatus['fraud_status'] ?? null))) {
            try {
                $this->reconcilePayment($fresh, $providerStatus, 'expiry_command', OrderStatusHistory::ACTOR_MIDTRANS);
            } catch (OrderOperationException $e) {
                Log::warning('OrderService: mismatch data saat rekonsiliasi paid di expiry sweep.', [
                    'order_id' => $fresh->id,
                ]);

                return 'reconciliation_needed';
            }

            return 'paid';
        }

        // 4. Jika masih pending -> pastikan expiry transaksi aktual benar-benar lewat lalu minta Midtrans expire
        if ($txStatus === 'pending') {
            $effectiveExpiry = $this->resolveEffectiveExpiryTime($providerStatus['expiry_time'] ?? null, $fresh->payment_expires_at);
            if ($effectiveExpiry === null || $effectiveExpiry->isFuture()) {
                return 'still_pending';
            }

            try {
                $expireResult = $this->midtransService->expireTransaction($fresh->midtrans_order_id);
                $reconciled   = $this->reconcilePayment($fresh, $expireResult, 'expiry_command', OrderStatusHistory::ACTOR_SYSTEM);

                return $reconciled['order']->status === Order::STATUS_EXPIRED ? 'expired' : 'reconciliation_needed';
            } catch (MidtransException|OrderOperationException $e) {
                Log::warning('OrderService: gagal meng-expire transaksi pending di Midtrans; reservasi dipertahankan.', [
                    'order_id'          => $fresh->id,
                    'midtrans_order_id' => $fresh->midtrans_order_id,
                ]);

                return 'reconciliation_needed';
            }
        }

        // 5. Status terminal dari Midtrans (expire / cancel / deny / failure)
        if (in_array($txStatus, ['expire', 'cancel', 'deny', 'failure'], true)) {
            try {
                $reconciled = $this->reconcilePayment($fresh, $providerStatus, 'expiry_command', OrderStatusHistory::ACTOR_SYSTEM);

                return $reconciled['order']->status === Order::STATUS_EXPIRED ? 'expired' : 'cancelled';
            } catch (OrderOperationException) {
                return 'reconciliation_needed';
            }
        }

        return 'reconciliation_needed';
    }

    /**
     * Serialisasi ringkasan pesanan untuk daftar GET /api/orders (tanpa PII alamat/telepon pembeli).
     *
     * @return array<string, mixed>
     */
    public function serializeSummary(Order $order, User $viewer): array
    {
        $viewerRole = (int) $order->buyer_id === (int) $viewer->id ? 'buyer' : 'seller';

        return [
            'id'              => $order->id,
            'order_number'    => $order->order_number,
            'role'            => $viewerRole,
            'status'          => $order->status,
            'payment_status'  => $order->payment_status,
            'item_price'      => (int) $order->item_price,
            'shipping_cost'   => (int) $order->shipping_cost,
            'platform_fee'    => (int) $order->platform_fee,
            'total_amount'    => (int) $order->total_amount,
            'currency'        => $order->currency,
            'courier'         => [
                'code'    => $order->courier_code,
                'name'    => $order->courier_name,
                'service' => $order->courier_service,
                'etd'     => $order->estimated_etd,
            ],
            'tracking_number' => $order->tracking_number,
            'listing'         => [
                'id'        => $order->listing_id,
                'name'      => $order->listing_name_snapshot,
                'category'  => $order->listing_category_snapshot,
                'weight_kg' => (float) $order->listing_weight_kg_snapshot,
                'image_url' => $this->resolveSnapshotImageUrl($order->listing_image_url_snapshot),
            ],
            'buyer'           => $order->relationLoaded('buyer') && $order->buyer
                ? ['id' => $order->buyer->id, 'name' => $order->buyer->name]
                : ['id' => $order->buyer_id, 'name' => null],
            'seller'          => $order->relationLoaded('seller') && $order->seller
                ? ['id' => $order->seller->id, 'name' => $order->seller->name]
                : ['id' => $order->seller_id, 'name' => null],
            'payment_expires_at' => $order->payment_expires_at?->toIso8601String(),
            'paid_at'            => $order->paid_at?->toIso8601String(),
            'shipped_at'         => $order->shipped_at?->toIso8601String(),
            'completed_at'       => $order->completed_at?->toIso8601String(),
            'created_at'         => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Serialisasi detail pesanan dengan penegakan privasi alamat pembeli terhadap penjual sebelum pembayaran.
     *
     * @return array<string, mixed>
     */
    public function serializeDetail(Order $order, User $viewer): array
    {
        $isBuyer         = (int) $order->buyer_id === (int) $viewer->id;
        $canSeeFullPii   = $isBuyer
            || ($order->paid_at !== null && in_array($order->status, [
                Order::STATUS_PAID,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
            ], true));

        $shippingAddress = $canSeeFullPii
            ? [
                'recipient_name'    => $order->recipient_name_snapshot,
                'recipient_phone'   => $order->recipient_phone_snapshot,
                'destination_id'    => (int) $order->destination_id_snapshot,
                'destination_label' => $order->destination_label_snapshot,
                'address_detail'    => $order->address_detail_snapshot,
                'postal_code'       => $order->postal_code_snapshot,
                'delivery_note'     => $order->delivery_note_snapshot,
            ]
            : [
                'destination_id'    => (int) $order->destination_id_snapshot,
                'destination_label' => $order->destination_label_snapshot,
            ];

        $payment = [
            'midtrans_order_id'  => $order->midtrans_order_id,
            'payment_type'       => $order->midtrans_payment_type,
            'transaction_status' => $order->midtrans_transaction_status,
            'expires_at'         => $order->payment_expires_at?->toIso8601String(),
        ];

        if ($isBuyer) {
            $payment['snap_token']   = $order->midtrans_snap_token;
            $payment['redirect_url'] = $order->midtrans_redirect_url;
        }

        $histories = $order->relationLoaded('statusHistories')
            ? $order->statusHistories
                ->sortBy('created_at')
                ->values()
                ->map(fn (OrderStatusHistory $h) => [
                    'id'          => $h->id,
                    'from_status' => $h->from_status,
                    'to_status'   => $h->to_status,
                    'actor_type'  => $h->actor_type,
                    'source'      => $h->source,
                    'reason'      => $h->reason,
                    'created_at'  => $h->created_at?->toIso8601String(),
                ])
                ->all()
            : [];

        return array_merge($this->serializeSummary($order, $viewer), [
            'buyer_note' => $order->buyer_note,
            'origin'     => [
                'destination_id' => (int) $order->origin_destination_id_snapshot,
                'label'          => $order->origin_label_snapshot,
            ],
            'shipping_address'    => $shippingAddress,
            'courier'             => [
                'code'        => $order->courier_code,
                'name'        => $order->courier_name,
                'service'     => $order->courier_service,
                'description' => $order->courier_description,
                'etd'         => $order->estimated_etd,
            ],
            'shipment_notes'      => $order->shipment_notes,
            'delivered_at'        => $order->delivered_at?->toIso8601String(),
            'cancelled_at'        => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_by'        => $order->cancelled_by,
            'payment'             => $payment,
            'status_histories'    => $histories,
        ]);
    }

    // ─── Internal Helpers ─────────────────────────────────────────────────

    /**
     * @return array{item_price: int, weight_grams: int, origin_destination_id: int, origin_label: string}
     */
    private function validateListingForCheckout(MaterialListing $listing, User $buyer, bool $inLock = false): array
    {
        if ((int) $listing->owner_id === (int) $buyer->id) {
            throw new OrderOperationException('Anda tidak dapat membeli listing milik sendiri.', 422);
        }

        if ((bool) $listing->is_free) {
            throw new OrderOperationException(
                'Listing gratis tidak menggunakan checkout berbayar. Silakan gunakan alur serah terima (Handover).',
                422
            );
        }

        if ($listing->status !== 'available') {
            throw new OrderOperationException(
                'Listing material ini sedang tidak tersedia untuk dibeli.',
                $inLock ? 409 : 409
            );
        }

        $originId    = $listing->origin_destination_id;
        $originLabel = is_string($listing->origin_label) ? trim($listing->origin_label) : '';

        if ($originId === null || (int) $originId <= 0 || $originLabel === '') {
            throw new OrderOperationException(
                'Penjual belum mengatur lokasi asal pengiriman yang valid untuk listing ini.',
                422
            );
        }

        try {
            $weightGrams = RupiahAmount::weightKgToGrams((string) $listing->getRawOriginal('weight_kg'));
        } catch (InvalidArgumentException $e) {
            throw new OrderOperationException('Berat material pada listing tidak valid.', 422, previous: $e);
        }

        try {
            $itemPrice = RupiahAmount::parseExactWhole((string) $listing->getRawOriginal('price'));
        } catch (InvalidArgumentException $e) {
            throw new OrderOperationException(
                'Harga listing tidak valid (harus berupa nilai Rupiah utuh tanpa pecahan desimal).',
                422,
                previous: $e
            );
        }

        if ($itemPrice <= 0) {
            throw new OrderOperationException('Harga listing berbayar harus lebih dari Rp 0.', 422);
        }

        return [
            'item_price'            => $itemPrice,
            'weight_grams'          => $weightGrams,
            'origin_destination_id' => (int) $originId,
            'origin_label'          => $originLabel,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function matchCourierOption(array $options, string $courierCode, string $courierService): ?array
    {
        $needleCode    = strtolower($courierCode);
        $needleService = strtoupper($courierService);

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $optCode    = strtolower(trim((string) ($option['courier_code'] ?? '')));
            $optService = strtoupper(trim((string) ($option['service'] ?? '')));

            if ($optCode === $needleCode && $optService === $needleService) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{order: Order, idempotent_replay: bool}
     */
    private function resolveIdempotentOrder(
        Order $existing,
        int $listingId,
        int $addressId,
        string $courierCode,
        string $courierService,
        ?string $buyerNote
    ): array {
        $sameListing = (int) $existing->listing_id === $listingId;
        $sameAddress = $existing->buyer_address_id !== null && (int) $existing->buyer_address_id === $addressId;
        $sameCourier = strtolower($existing->courier_code) === strtolower($courierCode)
            && strtoupper($existing->courier_service) === strtoupper($courierService);
        $sameNote    = ($existing->buyer_note ?? null) === $buyerNote;

        if (! ($sameListing && $sameAddress && $sameCourier && $sameNote)) {
            throw new OrderOperationException(
                'Idempotency key sudah digunakan untuk detail checkout yang berbeda.',
                409
            );
        }

        return ['order' => $existing, 'idempotent_replay' => true];
    }

    /**
     * Bangun payload Snap dengan jaminan sum(item_details) == gross_amount dan expiry seragam.
     *
     * @return array<string, mixed>
     */
    private function buildSnapPayload(Order $order, User $buyer): array
    {
        $itemDetails = [
            [
                'id'       => 'LOT-' . $order->listing_id,
                'price'    => (int) $order->item_price,
                'quantity' => 1,
                'name'     => mb_substr($order->listing_name_snapshot, 0, 50),
            ],
            [
                'id'       => 'SHIPPING',
                'price'    => (int) $order->shipping_cost,
                'quantity' => 1,
                'name'     => mb_substr("Ongkir {$order->courier_name} {$order->courier_service}", 0, 50),
            ],
        ];

        if ((int) $order->platform_fee > 0) {
            $itemDetails[] = [
                'id'       => 'PLATFORM-FEE',
                'price'    => (int) $order->platform_fee,
                'quantity' => 1,
                'name'     => 'Biaya Layanan Platform',
            ];
        }

        $expiryMinutes = $this->midtransService->paymentExpiryMinutes();
        // Gunakan waktu awal order dalam timezone eksplisit +0700 (Asia/Jakarta) sesuai dokumentasi Midtrans Snap expiry
        $startTimeWib = ($order->created_at ? $order->created_at->copy() : now())
            ->setTimezone(MidtransService::MIDTRANS_TIMEZONE);

        // Blanket expiry tetap berjangkar pada created_at + durasi kebijakan (24 jam default) agar konsisten dengan payment_expires_at
        $blanketDuration = $order->payment_expires_at && $order->created_at
            ? max(
                MidtransService::MIN_EXPIRY_MINUTES,
                min($expiryMinutes, (int) round($order->created_at->diffInSeconds($order->payment_expires_at, false) / 60))
            )
            : $expiryMinutes;

        // page_expiry mengikuti sisa waktu dari sekarang hingga payment_expires_at (tidak melebihi kebijakan utama)
        $pageDuration = $order->payment_expires_at
            ? max(
                MidtransService::MIN_EXPIRY_MINUTES,
                min($blanketDuration, (int) ceil(now()->diffInSeconds($order->payment_expires_at, false) / 60))
            )
            : $blanketDuration;

        return [
            'transaction_details' => [
                'order_id'     => $order->midtrans_order_id,
                'gross_amount' => (int) $order->total_amount,
            ],
            'item_details'        => $itemDetails,
            'customer_details'    => [
                'first_name' => mb_substr($buyer->name, 0, 50),
                'email'      => $buyer->email,
            ],
            'page_expiry'         => [
                'duration' => $pageDuration,
                'unit'     => 'minutes',
            ],
            'expiry'              => [
                'start_time' => $startTimeWib->format('Y-m-d H:i:s O'),
                'unit'       => 'minutes',
                'duration'   => $blanketDuration,
            ],
        ];
    }

    /**
     * Tangani respons "Transaction is on progress" dari Snap cancel dengan cek ulang GET Status & rekonsiliasi.
     */
    private function reconcileOnInProgressSnapCancel(Order $order, User $buyer): never
    {
        try {
            $status = $this->fetchMidtransStatusOrNull($order->midtrans_order_id);
        } catch (MidtransException $e) {
            throw new OrderOperationException(
                'Transaksi sedang diproses di Midtrans dan status terbaru belum dapat diverifikasi. Reservasi dipertahankan.',
                $this->mapMidtransHttpStatus($e),
                ['reconciliation_required' => true],
                previous: $e
            );
        }

        if ($status !== null) {
            $reconciled = $this->reconcilePayment(
                $order,
                $status,
                'buyer_cancel_reconcile',
                OrderStatusHistory::ACTOR_MIDTRANS
            );

            $updated = $reconciled['order'];
            $message = $updated->status === Order::STATUS_PAID
                ? 'Pembayaran telah diterima oleh Midtrans sehingga pesanan tidak dapat dibatalkan.'
                : 'Transaksi pembayaran sedang diproses di Midtrans. Status pesanan telah disinkronkan; reservasi tetap dipertahankan.';

            throw new OrderOperationException(
                $message,
                409,
                ['order' => $this->serializeDetail($updated->loadMissing(['buyer:id,name', 'seller:id,name', 'statusHistories']), $buyer)]
            );
        }

        throw new OrderOperationException(
            'Transaksi pembayaran sedang diproses di Midtrans sehingga sesi Snap belum dapat dibatalkan.',
            409,
            ['reconciliation_required' => true]
        );
    }

    private function initiateSnapForOrder(Order $order, User $buyer, bool $isRetry): void
    {
        $payload = $this->buildSnapPayload($order, $buyer);

        try {
            $snap = $this->midtransService->createSnapTransaction($payload);

            $order->update([
                'midtrans_snap_token'   => $snap['token'],
                'midtrans_redirect_url' => $snap['redirect_url'],
            ]);
        } catch (MidtransException $e) {
            // Kegagalan ambigu (timeout / 5xx / malformed) -> PERTAHANKAN reservasi order & listing
            if ($e->isTimeout || $e->reconciliationRequired) {
                throw new OrderOperationException(
                    'Sesi pembayaran sedang diproses atau mengalami gangguan sementara. Pesanan Anda tetap tersimpan; silakan gunakan coba ulang pembayaran.',
                    $this->mapMidtransHttpStatus($e),
                    [
                        'order_id'                => $order->id,
                        'order_number'            => $order->order_number,
                        'status'                  => $order->status,
                        'reconciliation_required' => true,
                    ],
                    previous: $e
                );
            }

            // Kegagalan permanen definitif pada checkout awal (400/401/403/422/503 config): pastikan transaksi tidak ada lalu rilis listing
            if (! $isRetry) {
                $this->finalizeUnpaidOrderCancellation(
                    $order,
                    Order::STATUS_CANCELLED,
                    Order::PAYMENT_FAILED,
                    Order::CANCELLED_BY_SYSTEM,
                    OrderStatusHistory::ACTOR_SYSTEM,
                    null,
                    'snap_creation_failed',
                    'Gagal membuat sesi pembayaran Midtrans.'
                );
            }

            throw new OrderOperationException(
                $e->getMessage(),
                $this->mapMidtransHttpStatus($e),
                previous: $e
            );
        }
    }

    /**
     * @return array<string, mixed>|null  Null hanya jika Midtrans mengonfirmasi 404 (belum ada transaksi).
     */
    private function fetchMidtransStatusOrNull(string $midtransOrderId): ?array
    {
        try {
            $status = $this->midtransService->getTransactionStatus($midtransOrderId);
        } catch (MidtransException $e) {
            if ($e->httpStatus === 404 && ! $e->isTimeout) {
                return null;
            }

            throw $e;
        }

        if (($status['status_code'] ?? null) === '404' && ($status['transaction_status'] ?? null) === null) {
            return null;
        }

        return $status;
    }

    /**
     * @return array{order: Order, result: string, changed: bool}
     */
    private function applyTerminalFailureInTx(
        Order $lockedOrder,
        string $targetStatus,
        string $targetPaymentStatus,
        ?string $txId,
        ?string $paymentType,
        string $txStatus,
        ?string $fraudStatus,
        string $source,
        string $actorType,
        ?int $actorId,
        string $reason
    ): array {
        // Jangan pernah downgrade/rilis pesanan yang sudah paid, shipped, atau completed
        if (in_array($lockedOrder->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
            return ['order' => $lockedOrder, 'result' => 'ignored_monotonic', 'changed' => false];
        }

        // Sudah terminal cancelled/expired -> idempoten tanpa history ganda
        if (in_array($lockedOrder->status, [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED], true)) {
            return ['order' => $lockedOrder, 'result' => 'already_terminal', 'changed' => false];
        }

        $fromStatus  = $lockedOrder->status;
        $cancelledBy = $targetStatus === Order::STATUS_CANCELLED
            ? ($actorType === OrderStatusHistory::ACTOR_BUYER ? Order::CANCELLED_BY_BUYER : Order::CANCELLED_BY_SYSTEM)
            : null;

        $lockedOrder->update([
            'status'                      => $targetStatus,
            'payment_status'              => $targetPaymentStatus,
            'cancelled_at'                => $targetStatus === Order::STATUS_CANCELLED ? now() : $lockedOrder->cancelled_at,
            'cancellation_reason'         => $reason,
            'cancelled_by'                => $cancelledBy,
            'midtrans_transaction_id'     => $txId,
            'midtrans_payment_type'       => $paymentType,
            'midtrans_transaction_status' => $txStatus,
            'midtrans_fraud_status'       => $fraudStatus,
        ]);

        $this->releaseListingSafelyInTx($lockedOrder);

        OrderStatusHistory::create([
            'order_id'    => $lockedOrder->id,
            'from_status' => $fromStatus,
            'to_status'   => $targetStatus,
            'actor_type'  => $actorType,
            'actor_id'    => $actorId,
            'source'      => $source,
            'reason'      => $reason,
            'metadata'    => [
                'midtrans_transaction_status' => $txStatus,
                'midtrans_transaction_id'     => $txId,
            ],
        ]);

        return ['order' => $lockedOrder->fresh(), 'result' => "marked_{$targetStatus}", 'changed' => true];
    }

    private function finalizeUnpaidOrderCancellation(
        Order $order,
        string $targetStatus,
        string $targetPaymentStatus,
        string $cancelledBy,
        string $actorType,
        ?int $actorId,
        string $source,
        string $reason
    ): Order {
        return DB::transaction(function () use (
            $order,
            $targetStatus,
            $targetPaymentStatus,
            $cancelledBy,
            $actorType,
            $actorId,
            $source,
            $reason
        ): Order {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_PENDING_PAYMENT) {
                return $lockedOrder;
            }

            $lockedOrder->update([
                'status'              => $targetStatus,
                'payment_status'      => $targetPaymentStatus,
                'cancelled_at'        => $targetStatus === Order::STATUS_CANCELLED ? now() : null,
                'cancellation_reason' => $reason,
                'cancelled_by'        => $targetStatus === Order::STATUS_CANCELLED ? $cancelledBy : null,
            ]);

            $this->releaseListingSafelyInTx($lockedOrder);

            OrderStatusHistory::create([
                'order_id'    => $lockedOrder->id,
                'from_status' => Order::STATUS_PENDING_PAYMENT,
                'to_status'   => $targetStatus,
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'source'      => $source,
                'reason'      => $reason,
            ]);

            return $lockedOrder->fresh();
        });
    }

    /**
     * Kembalikan status listing ke 'available' hanya jika listing masih reserved dan tidak dikunci transaksi aktif lain.
     */
    private function releaseListingSafelyInTx(Order $lockedOrder): void
    {
        $lockedListing = MaterialListing::where('id', $lockedOrder->listing_id)->lockForUpdate()->first();
        if (! $lockedListing || $lockedListing->status !== 'reserved') {
            return;
        }

        $otherActiveOrder = Order::where('listing_id', $lockedListing->id)
            ->where('id', '!=', $lockedOrder->id)
            ->whereIn('status', Order::ACTIVE_RESERVATION_STATUSES)
            ->exists();

        $otherActiveHandover = HandoverRequest::where('listing_id', $lockedListing->id)
            ->whereIn('status', ['approved', 'handed_over'])
            ->exists();

        if (! $otherActiveOrder && ! $otherActiveHandover) {
            $lockedListing->update(['status' => 'available']);
        }
    }

    private function isCaptureAccepted(?string $fraudStatus): bool
    {
        // Sesuai semantik Midtrans: capture sukses saat fraud_status === 'accept' (atau absen pada metode low-risk tertentu)
        return $fraudStatus === null || $fraudStatus === 'accept';
    }

    private function resolveEffectiveExpiryTime(?string $providerExpiryTime, ?Carbon $orderExpiresAt): ?Carbon
    {
        if ($providerExpiryTime !== null && trim($providerExpiryTime) !== '') {
            try {
                // Midtrans mengembalikan expiry_time dalam WIB (+0700) bila tanpa offset eksplisit
                $trimmed = trim($providerExpiryTime);
                $hasOffset = preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/', $trimmed) === 1;
                $parsed = $hasOffset
                    ? Carbon::parse($trimmed)
                    : Carbon::parse($trimmed, MidtransService::MIDTRANS_TIMEZONE);

                if ($orderExpiresAt === null) {
                    return $parsed;
                }

                // Ambil waktu yang paling akhir agar tidak merilis listing sebelum kode bayar benar-benar kadaluarsa
                return $parsed->greaterThan($orderExpiresAt) ? $parsed : $orderExpiresAt;
            } catch (\Throwable) {
                return $orderExpiresAt;
            }
        }

        return $orderExpiresAt;
    }

    private function generateOrderNumber(Carbon $now): string
    {
        return 'PLV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(8));
    }

    private function generateMidtransOrderId(Carbon $now): string
    {
        return 'PLV-MID-' . $now->format('YmdHis') . '-' . strtoupper(Str::random(8));
    }

    private function resolveSnapshotImageUrl(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            return $raw;
        }

        return asset('storage/' . ltrim($raw, '/'));
    }

    private function mapMidtransHttpStatus(MidtransException $e): int
    {
        $code = (int) $e->getCode();

        return in_array($code, [404, 422, 502, 503, 504], true) ? $code : 502;
    }
}
