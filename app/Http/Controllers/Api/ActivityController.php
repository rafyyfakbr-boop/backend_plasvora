<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ActivityController extends Controller
{
    /**
     * GET /api/activities
     *
     * Tampilkan riwayat aktivitas yang relevan dengan user yang login.
     * Mencakup: listing yang dibuat, request yang dibuat, dan handover yang terlibat.
     * Data berasal dari database nyata, diurutkan berdasarkan waktu terbaru.
     */
    public function index(): JsonResponse
    {
        $userId = Auth::id();
        $activities = collect();

        // ─── Listing Material ──────────────────────────────────────────────────
        $listings = MaterialListing::where('owner_id', $userId)
            ->latest()
            ->limit(10)
            ->get(['id', 'name', 'category', 'status', 'weight_kg', 'created_at', 'updated_at']);

        foreach ($listings as $listing) {
            $activities->push([
                'type'        => 'material_listing',
                'action'      => 'Listing material dibuat',
                'description' => "Anda menambahkan listing: {$listing->name} ({$listing->category}, {$listing->weight_kg} kg)",
                'status'      => $listing->status,
                'reference_id' => $listing->id,
                'timestamp'   => $listing->created_at,
            ]);

            // Jika status berubah (bukan available), tambahkan event update
            if ($listing->status !== 'available') {
                $statusLabels = [
                    'reserved'  => 'Listing material direservasi',
                    'completed' => 'Listing material selesai diserahkan',
                    'inactive'  => 'Listing material dinonaktifkan',
                ];
                $activities->push([
                    'type'        => 'material_listing',
                    'action'      => $statusLabels[$listing->status] ?? 'Status listing diperbarui',
                    'description' => "Listing {$listing->name} kini berstatus: {$listing->status}",
                    'status'      => $listing->status,
                    'reference_id' => $listing->id,
                    'timestamp'   => $listing->updated_at,
                ]);
            }
        }

        // ─── Material Requests ─────────────────────────────────────────────────
        $requests = MaterialRequest::where('requester_id', $userId)
            ->latest()
            ->limit(10)
            ->get(['id', 'material_category', 'requested_weight_kg', 'status', 'intended_use', 'created_at']);

        foreach ($requests as $req) {
            $activities->push([
                'type'        => 'material_request',
                'action'      => 'Kebutuhan material dibuat',
                'description' => "Anda membutuhkan {$req->requested_weight_kg} kg {$req->material_category} untuk: {$req->intended_use}",
                'status'      => $req->status,
                'reference_id' => $req->id,
                'timestamp'   => $req->created_at,
            ]);
        }

        // ─── Handover Requests ─────────────────────────────────────────────────
        $handovers = HandoverRequest::with(['listing:id,name', 'requester:id,name', 'owner:id,name'])
            ->where(function ($q) use ($userId) {
                $q->where('owner_id', $userId)
                    ->orWhere('requester_id', $userId);
            })
            ->latest()
            ->limit(15)
            ->get();

        foreach ($handovers as $handover) {
            $isOwner     = $handover->owner_id === $userId;
            $otherParty  = $isOwner ? $handover->requester->name : $handover->owner->name;
            $listingName = $handover->listing?->name ?? 'listing yang dihapus';

            $statusLabels = [
                'pending'               => $isOwner ? "Permintaan handover masuk dari {$otherParty}" : "Handover diajukan untuk listing: {$listingName}",
                'approved'              => $isOwner ? "Anda menyetujui handover untuk {$otherParty}" : "Handover Anda disetujui oleh {$otherParty}",
                'handed_over'           => $isOwner ? "Anda telah menyerahkan material kepada {$otherParty}" : "Material telah diterima dari {$otherParty}",
                'utilization_confirmed' => "Pemanfaatan material dikonfirmasi oleh {$handover->requester->name}",
                'rejected'              => $isOwner ? "Anda menolak handover untuk {$otherParty}" : "Handover Anda ditolak oleh {$otherParty}",
                'cancelled'             => "Handover dibatalkan",
            ];

            $activities->push([
                'type'        => 'handover_request',
                'action'      => $statusLabels[$handover->status] ?? 'Status handover diperbarui',
                'description' => "Handover untuk: {$listingName} ({$handover->requested_weight_kg} kg)",
                'status'      => $handover->status,
                'reference_id' => $handover->id,
                'timestamp'   => $handover->updated_at,
            ]);
        }

        // ─── Urutkan berdasarkan waktu terbaru ──────────────────────────────────
        $sorted = $activities
            ->sortByDesc('timestamp')
            ->values()
            ->take(30); // Batasi 30 aktivitas terbaru

        return response()->json([
            'success' => true,
            'message' => 'Activity history retrieved successfully',
            'data'    => $sorted,
        ]);
    }
}
