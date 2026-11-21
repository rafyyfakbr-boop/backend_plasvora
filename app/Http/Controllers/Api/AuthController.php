<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * POST /api/register
     * Daftarkan pengguna baru dan kembalikan token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => [
                'nullable',
                'string',
                'max:25',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && User::normalizeIndonesianPhone($value) === null) {
                        $fail('Format nomor telepon Indonesia tidak valid. Gunakan awalan 08, 62, atau +62.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.min' => 'Nama minimal 2 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
            'phone.max' => 'Nomor telepon terlalu panjang.',
            'password.required' => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $normalizedPhone = isset($validated['phone'])
            ? User::normalizeIndonesianPhone($validated['phone'])
            : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $normalizedPhone,
            'whatsapp_public' => false,
            'password' => $validated['password'],
        ]);

        $token = $user->createToken('plasvora-auth')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'whatsapp_public' => (bool) $user->whatsapp_public,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * POST /api/login
     * Login pengguna dan kembalikan token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // Cari user berdasarkan email
        $user = User::where('email', $validated['email'])->first();

        // Cek kredensial
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password tidak valid',
            ], 401);
        }

        // Hapus token lama (opsional — untuk single session)
        // $user->tokens()->delete();

        $token = $user->createToken('plasvora-auth')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'whatsapp_public' => (bool) $user->whatsapp_public,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * POST /api/logout
     * Cabut token pengguna yang sedang login.
     */
    public function logout(Request $request): JsonResponse
    {
        // Hapus token yang sedang digunakan
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * GET /api/me
     * Kembalikan informasi pengguna yang sedang login.
     * Password tidak dikembalikan.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'User profile retrieved successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'whatsapp_public' => (bool) $user->whatsapp_public,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * PATCH /api/me
     * Perbarui profil pengguna yang sedang login (termasuk nomor telepon dan privasi WhatsApp).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:25',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && User::normalizeIndonesianPhone($value) === null) {
                        $fail('Format nomor telepon Indonesia tidak valid. Gunakan awalan 08, 62, atau +62.');
                    }
                },
            ],
            'whatsapp_public' => ['sometimes', 'boolean'],
        ], [
            'name.min' => 'Nama minimal 2 karakter.',
            'phone.max' => 'Nomor telepon terlalu panjang.',
            'whatsapp_public.boolean' => 'Status visibilitas WhatsApp harus bernilai boolean.',
        ]);

        $user = $request->user();
        $updates = [];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = trim($validated['name']);
        }

        $effectivePhone = $user->phone;
        if (array_key_exists('phone', $validated)) {
            $effectivePhone = User::normalizeIndonesianPhone($validated['phone']);
            $updates['phone'] = $effectivePhone;
        }

        $requestedPublic = array_key_exists('whatsapp_public', $validated)
            ? (bool) $validated['whatsapp_public']
            : null;

        if ($effectivePhone === null) {
            if ($requestedPublic === true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'whatsapp_public' => [
                            'Nomor telepon harus diisi terlebih dahulu sebelum menampilkan WhatsApp secara publik.',
                        ],
                    ],
                ], 422);
            }

            // Menghapus nomor telepon otomatis menonaktifkan visibilitas WhatsApp
            if (array_key_exists('phone', $validated) || $requestedPublic === false) {
                $updates['whatsapp_public'] = false;
            }
        } elseif ($requestedPublic !== null) {
            $updates['whatsapp_public'] = $requestedPublic;
        }

        if (! empty($updates)) {
            $user->update($updates);
            $user->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'whatsapp_public' => (bool) $user->whatsapp_public,
                'created_at' => $user->created_at,
            ],
        ]);
    }
}
