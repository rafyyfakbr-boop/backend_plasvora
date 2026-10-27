<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use App\Services\MaterialMatchingService;
use Illuminate\Http\JsonResponse;

class MatchingController extends Controller
{
    public function __construct(
        private readonly MaterialMatchingService $matchingService
    ) {}

    /**
     * GET /api/material-requests/{id}/matches
     *
     * Temukan listing material yang cocok untuk sebuah kebutuhan material.
     * Hasil matching berbasis aturan (rule-based), bukan AI/ML.
     */
    public function matchesForRequest(int $id): JsonResponse
    {
        $materialRequest = MaterialRequest::find($id);

        if (! $materialRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Material request not found',
            ], 404);
        }

        $matches = $this->matchingService->findMatchesForRequest($materialRequest);

        $formattedMatches = array_map(function ($match) {
            return [
                'listing'              => $match['listing'],
                'match_reasons'        => $match['match_reasons'],
                'match_reasons_detail' => $this->matchingService->formatMatchReasons($match['match_reasons']),
                'match_score'          => $match['score'],
                'score_explanation'    => 'Skor dihitung berdasarkan: kategori (40 poin), berat (30 poin), kondisi (20 poin), lokasi (10 poin). Bukan probabilitas AI.',
            ];
        }, $matches);

        return response()->json([
            'success' => true,
            'message' => 'Matching results retrieved successfully',
            'data'    => [
                'request' => $materialRequest->load('requester:id,name'),
                'matches' => $formattedMatches,
                'total'   => count($formattedMatches),
                'note'    => 'Hasil matching menggunakan sistem berbasis aturan, bukan kecerdasan buatan.',
            ],
        ]);
    }

    /**
     * GET /api/materials/{id}/matches
     *
     * Temukan kebutuhan material yang cocok untuk sebuah listing.
     */
    public function matchesForListing(int $id): JsonResponse
    {
        $listing = MaterialListing::find($id);

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Material listing not found',
            ], 404);
        }

        $matches = $this->matchingService->findMatchesForListing($listing);

        $formattedMatches = array_map(function ($match) {
            return [
                'request'              => $match['request'],
                'match_reasons'        => $match['match_reasons'],
                'match_reasons_detail' => $this->matchingService->formatMatchReasons($match['match_reasons']),
                'match_score'          => $match['score'],
                'score_explanation'    => 'Skor dihitung berdasarkan: kategori (40 poin), berat (30 poin), kondisi (20 poin), lokasi (10 poin). Bukan probabilitas AI.',
            ];
        }, $matches);

        return response()->json([
            'success' => true,
            'message' => 'Matching results retrieved successfully',
            'data'    => [
                'listing' => $listing->load('owner:id,name'),
                'matches' => $formattedMatches,
                'total'   => count($formattedMatches),
                'note'    => 'Hasil matching menggunakan sistem berbasis aturan, bukan kecerdasan buatan.',
            ],
        ]);
    }
}
