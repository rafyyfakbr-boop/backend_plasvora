<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OrderOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\ShipOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    /**
     * GET /api/orders
     * Daftar pesanan milik user terautentikasi berdasarkan role (buyer/seller) dan filter status opsional.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role'     => ['nullable', 'string', Rule::in(['buyer', 'seller'])],
            'status'   => ['nullable', 'string', Rule::in(Order::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user    = Auth::user();
        $role    = $validated['role'] ?? 'buyer';
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Order::with(['buyer:id,name', 'seller:id,name'])
            ->where($role === 'seller' ? 'seller_id' : 'buyer_id', $user->id);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar pesanan berhasil diambil.',
            'data'    => collect($orders->items())
                ->map(fn (Order $order) => $this->orderService->serializeSummary($order, $user))
                ->values()
                ->all(),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * POST /api/orders
     * Checkout 1 lot material berbayar (server-authoritative & idempoten).
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        /** @var User $buyer */
        $buyer = Auth::user();

        try {
            $result = $this->orderService->createOrder($buyer, $request->validated());
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $order = $result['order']->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => $result['idempotent_replay']
                ? 'Pesanan yang sama ditemukan dan dikembalikan.'
                : 'Pesanan berhasil dibuat. Silakan lanjutkan pembayaran.',
            'data'    => $this->orderService->serializeDetail($order, $buyer),
        ], $result['idempotent_replay'] ? 200 : 201);
    }

    /**
     * GET /api/orders/{order}
     * Detail pesanan bagi peserta (pembeli atau penjual). Non-peserta menerima 404.
     */
    public function show(Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! Gate::forUser($user)->allows('view', $order)) {
            return $this->notFoundResponse();
        }

        $order->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'Detail pesanan berhasil diambil.',
            'data'    => $this->orderService->serializeDetail($order, $user),
        ]);
    }

    /**
     * POST /api/orders/{order}/retry-payment
     * Ulangi/ambil kembali sesi pembayaran Snap untuk pesanan pending_payment milik pembeli.
     */
    public function retryPayment(Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($denied = $this->authorizeParticipantAction($user, $order, 'retryPayment', 'Hanya pembeli yang dapat melanjutkan pembayaran pesanan ini.')) {
            return $denied;
        }

        try {
            $updated = $this->orderService->retryPayment($order, $user);
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $updated->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'Sesi pembayaran berhasil disiapkan.',
            'data'    => $this->orderService->serializeDetail($updated, $user),
        ]);
    }

    /**
     * POST /api/orders/{order}/refresh-payment
     * Sinkronisasi status pembayaran otoritatif langsung dari Midtrans Status API.
     */
    public function refreshPayment(Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($denied = $this->authorizeParticipantAction($user, $order, 'refreshPayment', 'Hanya pembeli yang dapat memeriksa ulang status pembayaran pesanan ini.')) {
            return $denied;
        }

        try {
            $result = $this->orderService->refreshPaymentStatus($order, $user);
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $updated = $result['order']->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data'    => $this->orderService->serializeDetail($updated, $user),
            'meta'    => [
                'provider_synced' => $result['synced'],
            ],
        ]);
    }

    /**
     * POST /api/orders/{order}/cancel
     * Pembatalan pesanan oleh pembeli (setelah konfirmasi otoritatif Midtrans).
     */
    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($denied = $this->authorizeParticipantAction($user, $order, 'cancel', 'Hanya pembeli yang dapat membatalkan pesanan ini.')) {
            return $denied;
        }

        try {
            $updated = $this->orderService->cancelOrderByBuyer($order, $user, $request->validated('reason'));
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $updated->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibatalkan.',
            'data'    => $this->orderService->serializeDetail($updated, $user),
        ]);
    }

    /**
     * POST /api/orders/{order}/ship
     * Penjual memasukkan nomor resi dan menandai pesanan telah dikirim.
     */
    public function ship(ShipOrderRequest $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($denied = $this->authorizeParticipantAction($user, $order, 'ship', 'Hanya penjual yang dapat mengirim pesanan ini.')) {
            return $denied;
        }

        try {
            $updated = $this->orderService->shipOrder(
                $order,
                $user,
                (string) $request->validated('tracking_number'),
                $request->validated('shipment_notes')
            );
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $updated->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil ditandai sedang dikirim.',
            'data'    => $this->orderService->serializeDetail($updated, $user),
        ]);
    }

    /**
     * POST /api/orders/{order}/confirm-delivery
     * Pembeli mengonfirmasi penerimaan barang dan menyelesaikan pesanan.
     */
    public function confirmDelivery(Order $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($denied = $this->authorizeParticipantAction($user, $order, 'confirmDelivery', 'Hanya pembeli yang dapat mengonfirmasi penerimaan pesanan ini.')) {
            return $denied;
        }

        try {
            $result = $this->orderService->confirmDelivery($order, $user);
        } catch (OrderOperationException $e) {
            return $this->operationErrorResponse($e);
        }

        $updated = $result['order']->load(['buyer:id,name', 'seller:id,name', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => $result['already_completed']
                ? 'Pesanan sudah dikonfirmasi selesai sebelumnya.'
                : 'Pesanan berhasil dikonfirmasi selesai.',
            'data'    => $this->orderService->serializeDetail($updated, $user),
        ]);
    }

    private function authorizeParticipantAction(User $user, Order $order, string $ability, string $forbiddenMessage): ?JsonResponse
    {
        // Outsider mendapat 404 agar tidak dapat melakukan enumerasi ID pesanan
        if (! Gate::forUser($user)->allows('view', $order)) {
            return $this->notFoundResponse();
        }

        if (! Gate::forUser($user)->allows($ability, $order)) {
            return response()->json([
                'success' => false,
                'message' => $forbiddenMessage,
            ], 403);
        }

        return null;
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Pesanan tidak ditemukan.',
        ], 404);
    }

    private function operationErrorResponse(OrderOperationException $e): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $e->getMessage(),
        ];

        if ($e->data !== null) {
            $body['data'] = $e->data;
        }

        return response()->json($body, $e->httpStatus);
    }
}
