<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstimateShippingRequest;
use App\Models\MaterialListing;
use App\Models\UserAddress;
use App\Services\RajaOngkirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ShippingController extends Controller
{
    public function __construct(
        private readonly RajaOngkirService $rajaOngkirService
    ) {}

    /**
     * GET /api/shipping/destinations
     * Cari destinasi domestik Indonesia via RajaOngkirService (cached pada respons sukses).
     */
    public function destinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ], [
            'search.required' => 'Kata kunci pencarian lokasi wajib diisi.',
            'search.min'      => 'Kata kunci pencarian lokasi minimal 2 karakter.',
            'search.max'      => 'Kata kunci pencarian lokasi maksimal 100 karakter.',
            'limit.integer'   => 'Batas hasil pencarian harus berupa angka.',
            'limit.min'       => 'Batas hasil pencarian minimal 1.',
            'limit.max'       => 'Batas hasil pencarian maksimal 20.',
            'offset.integer'  => 'Offset pencarian harus berupa angka.',
            'offset.min'      => 'Offset pencarian tidak boleh negatif.',
        ]);

        try {
            $destinations = $this->rajaOngkirService->searchDestinations(
                $validated['search'],
                (int) ($validated['limit'] ?? 10),
                (int) ($validated['offset'] ?? 0)
            );
        } catch (RuntimeException $e) {
            return $this->serviceErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => empty($destinations)
                ? 'Lokasi pengiriman tidak ditemukan.'
                : 'Daftar lokasi pengiriman berhasil diambil.',
            'data'    => $destinations,
        ]);
    }

    /**
     * POST /api/shipping/estimate
     * Hitung estimasi ongkos kirim domestik secara otoritatif dari DB (listing + alamat milik pembeli).
     */
    public function estimate(EstimateShippingRequest $request): JsonResponse
    {
        $userId    = (int) Auth::id();
        $listingId = (int) $request->validated('listing_id');
        $addressId = (int) $request->validated('address_id');

        $listing = MaterialListing::find($listingId);
        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Listing material tidak ditemukan.',
            ], 404);
        }

        if ($listing->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'Listing material ini sedang tidak tersedia untuk estimasi pengiriman.',
            ], 422);
        }

        if ((int) $listing->owner_id === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat menghitung estimasi ongkos kirim untuk listing milik sendiri.',
            ], 422);
        }

        if ($listing->origin_destination_id === null || (int) $listing->origin_destination_id <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Penjual belum mengatur lokasi asal pengiriman untuk material ini.',
            ], 422);
        }

        $weightGrams = (int) round((float) $listing->weight_kg * 1000);
        if ($weightGrams < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Berat material pada listing tidak valid untuk perhitungan ongkos kirim.',
            ], 422);
        }

        // Selalu scope alamat berdasarkan user yang login (cegah IDOR -> 404)
        $address = UserAddress::where('user_id', $userId)
            ->where('id', $addressId)
            ->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tujuan tidak ditemukan.',
            ], 404);
        }

        try {
            $options = $this->rajaOngkirService->calculateDomesticCost(
                (int) $listing->origin_destination_id,
                (int) $address->destination_id,
                $weightGrams
            );
        } catch (RuntimeException $e) {
            return $this->serviceErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => empty($options)
                ? 'Belum ada layanan pengiriman yang tersedia untuk rute dan berat material ini.'
                : 'Estimasi ongkos kirim berhasil diambil.',
            'data'    => [
                'listing' => [
                    'id'                    => $listing->id,
                    'name'                  => $listing->name,
                    'weight_kg'             => (float) $listing->weight_kg,
                    'weight_grams'          => $weightGrams,
                    'origin_destination_id' => (int) $listing->origin_destination_id,
                    'origin_label'          => $listing->origin_label,
                ],
                'destination' => [
                    'address_id'        => $address->id,
                    'label'             => $address->label,
                    'destination_id'    => (int) $address->destination_id,
                    'destination_label' => $address->destination_label,
                ],
                'options' => $options,
            ],
        ]);
    }

    /**
     * Petakan RuntimeException terkontrol dari RajaOngkirService ke HTTP status Plasvora.
     */
    private function serviceErrorResponse(RuntimeException $e): JsonResponse
    {
        $code   = (int) $e->getCode();
        $status = in_array($code, [422, 429, 502, 503, 504], true) ? $code : 502;

        return response()->json([
            'success' => false,
            'message' => $e->getMessage() ?: 'Layanan pengiriman sedang mengalami gangguan sementara.',
        ], $status);
    }
}
