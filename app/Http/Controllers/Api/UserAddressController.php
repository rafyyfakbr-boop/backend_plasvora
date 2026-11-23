<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserAddressRequest;
use App\Http\Requests\UpdateUserAddressRequest;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserAddressController extends Controller
{
    /**
     * GET /api/addresses
     * Ambil daftar alamat milik user yang sedang login (default dulu, terbaru, ID desc).
     */
    public function index(): JsonResponse
    {
        $addresses = UserAddress::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar alamat berhasil diambil.',
            'data'    => $addresses,
        ]);
    }

    /**
     * POST /api/addresses
     * Tambah alamat baru milik user yang sedang login dengan penguncian default-address.
     */
    public function store(StoreUserAddressRequest $request): JsonResponse
    {
        $userId    = (int) Auth::id();
        $validated = $request->validated();

        $normalizedPhone = User::normalizeIndonesianPhone($validated['recipient_phone']);

        $address = DB::transaction(function () use ($userId, $validated, $normalizedPhone): UserAddress {
            // Urutan kunci 1: Kunci baris user (menangani race condition alamat pertama)
            User::whereKey($userId)->lockForUpdate()->firstOrFail();

            // Urutan kunci 2: Cek/kunci alamat milik user ini
            $hasExisting = UserAddress::where('user_id', $userId)
                ->lockForUpdate()
                ->exists();

            $shouldBeDefault = ! $hasExisting || (bool) ($validated['is_default'] ?? false);

            if ($shouldBeDefault && $hasExisting) {
                UserAddress::where('user_id', $userId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return UserAddress::create([
                'user_id'           => $userId,
                'label'             => $validated['label'] ?? 'Rumah',
                'recipient_name'    => $validated['recipient_name'],
                'recipient_phone'   => $normalizedPhone,
                'destination_id'    => (int) $validated['destination_id'],
                'destination_label' => $validated['destination_label'],
                'address_detail'    => $validated['address_detail'],
                'postal_code'       => $validated['postal_code'] ?? null,
                'delivery_note'     => $validated['delivery_note'] ?? null,
                'is_default'        => $shouldBeDefault,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil ditambahkan.',
            'data'    => $address,
        ], 201);
    }

    /**
     * PATCH /api/addresses/{id}
     * Perbarui alamat milik user yang sedang login. Mengembalikan 404 untuk alamat milik user lain.
     */
    public function update(UpdateUserAddressRequest $request, int $id): JsonResponse
    {
        $userId    = (int) Auth::id();
        $validated = $request->validated();

        $address = DB::transaction(function () use ($userId, $id, $validated): ?UserAddress {
            // Urutan kunci 1: Kunci baris user
            User::whereKey($userId)->lockForUpdate()->firstOrFail();

            // Urutan kunci 2: Kunci baris alamat milik user ini (IDOR -> null -> 404)
            $address = UserAddress::where('user_id', $userId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $address) {
                return null;
            }

            $updates = [];
            foreach (['recipient_name', 'destination_label', 'address_detail', 'postal_code', 'delivery_note'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if (array_key_exists('label', $validated)) {
                $updates['label'] = $validated['label'] ?? 'Rumah';
            }

            if (array_key_exists('destination_id', $validated)) {
                $updates['destination_id'] = (int) $validated['destination_id'];
            }

            if (array_key_exists('recipient_phone', $validated)) {
                $updates['recipient_phone'] = User::normalizeIndonesianPhone($validated['recipient_phone']);
            }

            if (array_key_exists('is_default', $validated)) {
                $requestedDefault = (bool) $validated['is_default'];

                if ($requestedDefault) {
                    // Urutan kunci 3: Nonaktifkan alamat default lain milik user ini
                    UserAddress::where('user_id', $userId)
                        ->where('id', '!=', $address->id)
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
                    $updates['is_default'] = true;
                } elseif ($address->is_default) {
                    // Jika alamat default dinonaktifkan, promosikan alamat lain secara deterministik;
                    // jika ini satu-satunya alamat, tetap pertahankan sebagai default.
                    $replacement = UserAddress::where('user_id', $userId)
                        ->where('id', '!=', $address->id)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    if ($replacement) {
                        $replacement->update(['is_default' => true]);
                        $updates['is_default'] = false;
                    } else {
                        $updates['is_default'] = true;
                    }
                } else {
                    $updates['is_default'] = false;
                }
            }

            if (! empty($updates)) {
                $address->update($updates);
                $address->refresh();
            }

            return $address;
        });

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil diperbarui.',
            'data'    => $address,
        ]);
    }

    /**
     * DELETE /api/addresses/{id}
     * Hapus alamat milik user yang sedang login. Jika alamat default dihapus, promosikan alamat lain.
     */
    public function destroy(int $id): JsonResponse
    {
        $userId = (int) Auth::id();

        $deleted = DB::transaction(function () use ($userId, $id): bool {
            // Urutan kunci 1: Kunci baris user
            User::whereKey($userId)->lockForUpdate()->firstOrFail();

            // Urutan kunci 2: Kunci baris alamat milik user ini (IDOR -> false -> 404)
            $address = UserAddress::where('user_id', $userId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $address) {
                return false;
            }

            $wasDefault = (bool) $address->is_default;
            $address->delete();

            if ($wasDefault) {
                // Urutan kunci 3: Promosikan alamat tersisa secara deterministik
                $replacement = UserAddress::where('user_id', $userId)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                }
            }

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil dihapus.',
            'data'    => null,
        ]);
    }
}
