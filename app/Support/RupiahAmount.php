<?php

namespace App\Support;

use InvalidArgumentException;

final class RupiahAmount
{
    /**
     * Parse nilai Rupiah utuh (tanpa pecahan desimal) secara aman tanpa PHP float.
     * Contoh valid: 35000, "35000", "35000.00".
     * Contoh ditolak: "35000.50", "-100", "1e5", "99999999999999999999".
     */
    public static function parseExactWhole(string|int $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('Nilai Rupiah tidak boleh negatif.');
            }

            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^(\d+)(?:\.(\d+))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('Format nilai Rupiah tidak valid.');
        }

        $integerPart  = ltrim($matches[1], '0');
        $integerPart  = $integerPart === '' ? '0' : $integerPart;
        $fractionPart = $matches[2] ?? '';

        if ($fractionPart !== '' && preg_match('/^0+$/', $fractionPart) !== 1) {
            throw new InvalidArgumentException('Nilai Rupiah tidak boleh mengandung pecahan desimal.');
        }

        $maxIntString = (string) PHP_INT_MAX;
        if (
            strlen($integerPart) > strlen($maxIntString)
            || (strlen($integerPart) === strlen($maxIntString) && strcmp($integerPart, $maxIntString) > 0)
        ) {
            throw new InvalidArgumentException('Nilai Rupiah melebihi batas maksimum integer.');
        }

        return (int) $integerPart;
    }

    /**
     * Konversi berat (kg) dalam string desimal (mis. "2.50") ke gram integer tanpa float drift.
     */
    public static function weightKgToGrams(string|int $weightKg): int
    {
        $raw = is_int($weightKg) ? (string) $weightKg : trim($weightKg);
        if ($raw === '' || preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            throw new InvalidArgumentException('Format berat material tidak valid.');
        }

        $wholeKg  = ltrim($matches[1], '0');
        $wholeKg  = $wholeKg === '' ? '0' : $wholeKg;
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > 3) {
            $extra = substr($fraction, 3);
            if (preg_match('/^0+$/', $extra) !== 1) {
                throw new InvalidArgumentException('Presisi berat material tidak didukung.');
            }
            $fraction = substr($fraction, 0, 3);
        }

        $gramsString = ltrim($wholeKg . str_pad($fraction, 3, '0'), '0');
        $gramsString = $gramsString === '' ? '0' : $gramsString;

        $maxIntString = (string) PHP_INT_MAX;
        if (
            strlen($gramsString) > strlen($maxIntString)
            || (strlen($gramsString) === strlen($maxIntString) && strcmp($gramsString, $maxIntString) > 0)
        ) {
            throw new InvalidArgumentException('Berat material melebihi batas maksimum.');
        }

        $grams = (int) $gramsString;
        if ($grams < 1) {
            throw new InvalidArgumentException('Berat material harus lebih dari 0 gram.');
        }

        return $grams;
    }
}
