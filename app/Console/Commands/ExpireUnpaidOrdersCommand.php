<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

class ExpireUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Rekonsiliasi dan kadaluarsakan pesanan pending_payment yang telah melewati batas waktu pembayaran secara aman.';

    public function handle(OrderService $orderService): int
    {
        $counters = [
            'checked'               => 0,
            'paid'                  => 0,
            'expired'               => 0,
            'cancelled'             => 0,
            'still_pending'         => 0,
            'reconciliation_needed' => 0,
            'unchanged'             => 0,
        ];

        Order::where('status', Order::STATUS_PENDING_PAYMENT)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($orders) use ($orderService, &$counters): void {
                foreach ($orders as $order) {
                    $counters['checked']++;
                    $outcome = $orderService->processExpiredCandidate($order);
                    if (array_key_exists($outcome, $counters)) {
                        $counters[$outcome]++;
                    } else {
                        $counters['reconciliation_needed']++;
                    }
                }
            });

        $this->info(sprintf(
            'orders:expire-unpaid selesai — checked=%d paid=%d expired=%d cancelled=%d still_pending=%d reconciliation_needed=%d unchanged=%d',
            $counters['checked'],
            $counters['paid'],
            $counters['expired'],
            $counters['cancelled'],
            $counters['still_pending'],
            $counters['reconciliation_needed'],
            $counters['unchanged']
        ));

        return self::SUCCESS;
    }
}
