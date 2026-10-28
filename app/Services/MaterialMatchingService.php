<?php

namespace App\Services;

use App\Models\MaterialListing;
use App\Models\MaterialRequest;

/**
 * MaterialMatchingService
 *
 * Layanan matching berbasis aturan (rule-based) untuk mencocokkan
 * kebutuhan material dengan listing yang tersedia.
 *
 * CATATAN: Ini adalah matching berbasis aturan, BUKAN AI atau machine learning.
 * Hasil matching merupakan saran berdasarkan kriteria yang sudah ditentukan.
 */
class MaterialMatchingService
{
    /**
     * Urutan kondisi dari terbaik ke terburuk.
     * Digunakan untuk menentukan apakah kondisi listing memenuhi minimum_condition.
     */
    private const CONDITION_RANK = [
        'clean' => 4,
        'slightly_dirty' => 3,
        'dirty' => 2,
        'mixed' => 1,
    ];

    /**
     * Temukan listing yang cocok untuk sebuah material request.
     *
     * @param  MaterialRequest  $request  Kebutuhan material yang ingin dicocokkan.
     * @param  int  $limit  Maksimum jumlah hasil.
     * @return array<int, array{listing: MaterialListing, match_reasons: string[], score: int}>
     */
    public function findMatchesForRequest(MaterialRequest $request, int $limit = 10): array
    {
        // Hanya cari listing yang tersedia (available) dengan relasi owner di-eager load untuk hindari N+1
        $listings = MaterialListing::with('owner:id,name')
            ->where('status', 'available')
            ->where('category', $request->material_category)
            ->get();

        $results = [];

        foreach ($listings as $listing) {
            $matchResult = $this->evaluateListing($listing, $request);

            // Hanya sertakan listing yang memenuhi setidaknya kategori
            if (! empty($matchResult['match_reasons'])) {
                $results[] = array_merge(['listing' => $listing], $matchResult);
            }
        }

        // Urutkan berdasarkan skor tertinggi
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Temukan material request yang cocok untuk sebuah listing.
     *
     * @param  MaterialListing  $listing  Listing material yang ingin dicocokkan.
     * @param  int  $limit  Maksimum jumlah hasil.
     * @return array<int, array{request: MaterialRequest, match_reasons: string[], score: int}>
     */
    public function findMatchesForListing(MaterialListing $listing, int $limit = 10): array
    {
        // Hanya cari request yang masih aktif dengan relasi requester di-eager load untuk hindari N+1
        $requests = MaterialRequest::with('requester:id,name')
            ->where('status', 'active')
            ->where('material_category', $listing->category)
            ->get();

        $results = [];

        foreach ($requests as $request) {
            $matchResult = $this->evaluateRequest($request, $listing);

            if (! empty($matchResult['match_reasons'])) {
                $results[] = array_merge(['request' => $request], $matchResult);
            }
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Evaluasi seberapa cocok sebuah listing dengan sebuah request.
     *
     * @return array{match_reasons: string[], score: int}
     */
    private function evaluateListing(MaterialListing $listing, MaterialRequest $request): array
    {
        $matchReasons = [];
        $score = 0;

        // ─── 1. Cek kecocokan kategori (wajib ada) ────────────────────────────
        if ($listing->category === $request->material_category) {
            $matchReasons[] = 'category_match';
            $score += 40;
        } else {
            // Kategori tidak cocok — skip, ini syarat utama
            return ['match_reasons' => [], 'score' => 0];
        }

        // ─── 2. Cek kecocokan berat ────────────────────────────────────────────
        if ($listing->weight_kg >= $request->requested_weight_kg) {
            $matchReasons[] = 'weight_match';
            $score += 30;
        } elseif ($listing->weight_kg >= ($request->requested_weight_kg * 0.5)) {
            // Listing memenuhi setidaknya 50% dari kebutuhan
            $matchReasons[] = 'partial_weight_match';
            $score += 15;
        }

        // ─── 3. Cek kecocokan kondisi ─────────────────────────────────────────
        $listingConditionRank = self::CONDITION_RANK[$listing->condition] ?? 0;
        $minConditionRank = self::CONDITION_RANK[$request->minimum_condition] ?? 0;

        if ($listingConditionRank >= $minConditionRank) {
            $matchReasons[] = 'condition_match';
            $score += 20;
        }

        // ─── 4. Cek kecocokan lokasi ──────────────────────────────────────────
        if ($this->locationMatches($listing->general_location, $request->general_location)) {
            $matchReasons[] = 'nearby_location';
            $score += 10;
        }

        return [
            'match_reasons' => $matchReasons,
            'score' => $score,
        ];
    }

    /**
     * Evaluasi seberapa cocok sebuah request dengan sebuah listing.
     *
     * @return array{match_reasons: string[], score: int}
     */
    private function evaluateRequest(MaterialRequest $request, MaterialListing $listing): array
    {
        $matchReasons = [];
        $score = 0;

        // ─── 1. Kategori (wajib) ──────────────────────────────────────────────
        if ($request->material_category === $listing->category) {
            $matchReasons[] = 'category_match';
            $score += 40;
        } else {
            return ['match_reasons' => [], 'score' => 0];
        }

        // ─── 2. Berat ─────────────────────────────────────────────────────────
        if ($listing->weight_kg >= $request->requested_weight_kg) {
            $matchReasons[] = 'weight_match';
            $score += 30;
        } elseif ($listing->weight_kg >= ($request->requested_weight_kg * 0.5)) {
            $matchReasons[] = 'partial_weight_match';
            $score += 15;
        }

        // ─── 3. Kondisi ───────────────────────────────────────────────────────
        $listingRank = self::CONDITION_RANK[$listing->condition] ?? 0;
        $minRank = self::CONDITION_RANK[$request->minimum_condition] ?? 0;

        if ($listingRank >= $minRank) {
            $matchReasons[] = 'condition_match';
            $score += 20;
        }

        // ─── 4. Lokasi ────────────────────────────────────────────────────────
        if ($this->locationMatches($listing->general_location, $request->general_location)) {
            $matchReasons[] = 'nearby_location';
            $score += 10;
        }

        return [
            'match_reasons' => $matchReasons,
            'score' => $score,
        ];
    }

    /**
     * Cek apakah dua lokasi umum dianggap "dekat" secara string matching.
     *
     * Metode: case-insensitive substring check pada kata pertama lokasi.
     * Ini adalah pendekatan sederhana untuk MVP — bukan geolocation nyata.
     */
    private function locationMatches(string $locationA, string $locationB): bool
    {
        $locationA = mb_strtolower(trim($locationA));
        $locationB = mb_strtolower(trim($locationB));

        // Cek apakah kata pertama dari masing-masing lokasi sama (misal: "bandung")
        $wordA = explode(' ', $locationA)[0];
        $wordB = explode(' ', $locationB)[0];

        if ($wordA === $wordB) {
            return true;
        }

        // Cek substring sederhana
        return str_contains($locationA, $wordB) || str_contains($locationB, $wordA);
    }

    /**
     * Format penjelasan alasan kecocokan ke dalam bahasa yang mudah dipahami.
     *
     * @param  string[]  $reasons
     * @return array<string, string>
     */
    public function formatMatchReasons(array $reasons): array
    {
        $descriptions = [
            'category_match' => 'Kategori material sesuai dengan yang dibutuhkan.',
            'weight_match' => 'Jumlah berat tersedia mencukupi kebutuhan.',
            'partial_weight_match' => 'Jumlah berat tersedia memenuhi sebagian kebutuhan (minimal 50%).',
            'condition_match' => 'Kondisi material memenuhi standar minimum yang diminta.',
            'nearby_location' => 'Lokasi material dekat dengan lokasi yang diinginkan.',
        ];

        $result = [];
        foreach ($reasons as $reason) {
            $result[$reason] = $descriptions[$reason] ?? $reason;
        }

        return $result;
    }
}
