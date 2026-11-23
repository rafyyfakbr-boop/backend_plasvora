<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class ImpactSummaryController extends Controller
{
    /**
     * GET /api/impact-summary
     *
     * Ringkasan dampak lingkungan PLASVORA berdasarkan data aktual database.
     *
     * Definisi setiap angka:
     * - listed_*       : material yang didaftarkan di platform (status apa pun, kecuali inactive)
     * - reserved_*     : material yang sedang dalam proses handover
     * - handed_over_*  : material yang sudah diserahkan secara fisik (status: handed_over atau utilization_confirmed)
     * - confirmed_*    : material yang pemanfaatannya sudah dikonfirmasi penerima (status: utilization_confirmed)
     *
     * CATATAN: Angka-angka ini dihitung dari database nyata, bukan hardcoded.
     */
    public function index(): JsonResponse
    {
        // ─── Listing Statistics ────────────────────────────────────────────────
        $totalListings = MaterialListing::whereNotIn('status', ['inactive'])->count();
        $totalListedKg = MaterialListing::whereNotIn('status', ['inactive'])->sum('weight_kg');

        $availableListings = MaterialListing::where('status', 'available')->count();
        $availableKg       = MaterialListing::where('status', 'available')->sum('weight_kg');

        // ─── Handover Statistics ───────────────────────────────────────────────
        // Material yang sudah diserahkan (handed_over atau utilization_confirmed)
        $handoverTransferredListingIds = HandoverRequest::whereIn('status', ['handed_over', 'utilization_confirmed'])
            ->pluck('listing_id')
            ->unique()
            ->all();

        $handedOverCount = HandoverRequest::whereIn('status', ['handed_over', 'utilization_confirmed'])->count();
        $handedOverKg    = (float) HandoverRequest::whereIn('status', ['handed_over', 'utilization_confirmed'])
            ->sum('actual_weight_kg');

        // Material yang pemanfaatannya sudah dikonfirmasi via handover
        $confirmedHandoverListingIds = HandoverRequest::where('status', 'utilization_confirmed')
            ->pluck('listing_id')
            ->unique()
            ->all();

        $confirmedCount = HandoverRequest::where('status', 'utilization_confirmed')->count();
        $confirmedKg    = (float) HandoverRequest::where('status', 'utilization_confirmed')
            ->sum('actual_weight_kg');

        // ─── Completed Order Statistics (tanpa double-counting terhadap Handover) ─
        $completedOrdersForHandedOverQuery = Order::where('status', Order::STATUS_COMPLETED);
        if (! empty($handoverTransferredListingIds)) {
            $completedOrdersForHandedOverQuery->whereNotIn('listing_id', $handoverTransferredListingIds);
        }
        $handedOverCount += (clone $completedOrdersForHandedOverQuery)->count();
        $handedOverKg    += (float) (clone $completedOrdersForHandedOverQuery)->sum('listing_weight_kg_snapshot');

        $completedOrdersForUtilizedQuery = Order::where('status', Order::STATUS_COMPLETED);
        if (! empty($confirmedHandoverListingIds)) {
            $completedOrdersForUtilizedQuery->whereNotIn('listing_id', $confirmedHandoverListingIds);
        }
        $confirmedCount += (clone $completedOrdersForUtilizedQuery)->count();
        $confirmedKg    += (float) (clone $completedOrdersForUtilizedQuery)->sum('listing_weight_kg_snapshot');

        // Handover yang masih pending
        $pendingHandoverCount = HandoverRequest::where('status', 'pending')->count();
        $approvedHandoverCount = HandoverRequest::where('status', 'approved')->count();

        return response()->json([
            'success' => true,
            'message' => 'Impact summary retrieved successfully',
            'data'    => [
                // Material yang terdaftar di platform
                'materials_listed' => [
                    'count'      => $totalListings,
                    'weight_kg'  => round((float) $totalListedKg, 2),
                    'definition' => 'Total material plastik yang pernah didaftarkan di PLASVORA (tidak termasuk yang dinonaktifkan)',
                ],
                // Material yang masih tersedia
                'materials_available' => [
                    'count'      => $availableListings,
                    'weight_kg'  => round((float) $availableKg, 2),
                    'definition' => 'Material yang saat ini tersedia untuk diambil',
                ],
                // Material yang sudah diserahkan secara fisik
                'materials_handed_over' => [
                    'count'      => $handedOverCount,
                    'weight_kg'  => round((float) $handedOverKg, 2),
                    'definition' => 'Material yang sudah diserahkan secara fisik kepada penerima',
                ],
                // Material yang pemanfaatannya dikonfirmasi
                'materials_utilized' => [
                    'count'      => $confirmedCount,
                    'weight_kg'  => round((float) $confirmedKg, 2),
                    'definition' => 'Material yang pemanfaatannya sudah dikonfirmasi oleh penerima',
                ],
                // Handover yang sedang berlangsung
                'active_handovers' => [
                    'pending'  => $pendingHandoverCount,
                    'approved' => $approvedHandoverCount,
                ],
                'data_source' => 'Data ini dihitung dari database PLASVORA secara real-time',
            ],
        ]);
    }
}
