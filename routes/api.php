<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HandoverRequestController;
use App\Http\Controllers\Api\ImpactSummaryController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MaterialRequestController;
use App\Http\Controllers\Api\MatchingController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\UserAddressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PLASVORA API Routes
|--------------------------------------------------------------------------
|
| Semua endpoint API PLASVORA terdaftar di sini.
| Endpoint publik dapat diakses tanpa autentikasi.
| Endpoint yang dilindungi memerlukan Bearer token dari Laravel Sanctum.
|
*/

// ═══════════════════════════════════════════════════════════════════════
// ENDPOINT PUBLIK — Tidak memerlukan autentikasi
// ═══════════════════════════════════════════════════════════════════════

// ─── Autentikasi ────────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register')
    ->name('auth.register');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

// ─── Material Listings (read-only, publik) ─────────────────────────────
Route::get('/materials', [MaterialController::class, 'index'])->name('materials.index');
Route::get('/materials/{id}', [MaterialController::class, 'show'])->name('materials.show');

// ─── Material Requests (read-only, publik) ─────────────────────────────
Route::get('/material-requests', [MaterialRequestController::class, 'index'])->name('material-requests.index');
Route::get('/material-requests/{id}', [MaterialRequestController::class, 'show'])->name('material-requests.show');

// ─── Impact Summary (publik untuk display) ─────────────────────────────
Route::get('/impact-summary', [ImpactSummaryController::class, 'index'])->name('impact-summary.index');

// ─── Midtrans Payment Webhook (publik, diverifikasi via SHA-512 signature) ─
Route::post('/webhooks/midtrans', [MidtransWebhookController::class, 'handle'])
    ->middleware('throttle:midtrans-webhook')
    ->name('webhooks.midtrans');

// ═══════════════════════════════════════════════════════════════════════
// ENDPOINT TERPROTEKSI — Memerlukan Bearer token (Laravel Sanctum)
// ═══════════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // ─── Autentikasi ──────────────────────────────────────────────────
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    Route::patch('/me', [AuthController::class, 'updateProfile'])->name('auth.update-profile');

    // ─── Material Listings (mutasi) ───────────────────────────────────
    Route::post('/materials', [MaterialController::class, 'store'])->name('materials.store');
    Route::patch('/materials/{id}', [MaterialController::class, 'update'])->name('materials.update');
    Route::delete('/materials/{id}', [MaterialController::class, 'destroy'])->name('materials.destroy');

    // ─── Smart Matching ───────────────────────────────────────────────
    Route::get('/material-requests/{id}/matches', [MatchingController::class, 'matchesForRequest'])
        ->name('material-requests.matches');
    Route::get('/materials/{id}/matches', [MatchingController::class, 'matchesForListing'])
        ->name('materials.matches');

    // ─── Material Requests (mutasi) ───────────────────────────────────
    Route::post('/material-requests', [MaterialRequestController::class, 'store'])->name('material-requests.store');
    Route::patch('/material-requests/{id}', [MaterialRequestController::class, 'update'])->name('material-requests.update');
    Route::delete('/material-requests/{id}', [MaterialRequestController::class, 'destroy'])->name('material-requests.destroy');

    // ─── Handover Requests ────────────────────────────────────────────
    Route::get('/handover-requests', [HandoverRequestController::class, 'index'])->name('handover-requests.index');
    Route::post('/handover-requests', [HandoverRequestController::class, 'store'])->name('handover-requests.store');
    Route::get('/handover-requests/{id}', [HandoverRequestController::class, 'show'])->name('handover-requests.show');
    Route::patch('/handover-requests/{id}/status', [HandoverRequestController::class, 'updateStatus'])
        ->name('handover-requests.update-status');

    // ─── Activity History ─────────────────────────────────────────────
    Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');

    // ─── Analysis History ─────────────────────────────────────────────
    Route::get('/analyses', [AnalysisController::class, 'index'])->name('analyses.index');
    Route::get('/analyses/{id}', [AnalysisController::class, 'show'])->name('analyses.show');
    Route::post('/analyses', [AnalysisController::class, 'store'])
        ->middleware('throttle:analyses')
        ->name('analyses.store');

    // ─── Buyer Addresses (Stage 2) ────────────────────────────────────
    Route::get('/addresses', [UserAddressController::class, 'index'])->name('addresses.index');
    Route::post('/addresses', [UserAddressController::class, 'store'])->name('addresses.store');
    Route::patch('/addresses/{id}', [UserAddressController::class, 'update'])->name('addresses.update');
    Route::delete('/addresses/{id}', [UserAddressController::class, 'destroy'])->name('addresses.destroy');

    // ─── Shipping & Destination Search (Stage 2) ──────────────────────
    Route::get('/shipping/destinations', [ShippingController::class, 'destinations'])
        ->middleware('throttle:shipping-search')
        ->name('shipping.destinations');
    Route::post('/shipping/estimate', [ShippingController::class, 'estimate'])
        ->middleware('throttle:shipping-estimate')
        ->name('shipping.estimate');

    // ─── Orders & Transactions (Stage 3) ──────────────────────────────
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])
        ->middleware('throttle:checkout')
        ->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])
        ->whereNumber('order')
        ->name('orders.show');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->whereNumber('order')
        ->name('orders.cancel');
    Route::post('/orders/{order}/retry-payment', [OrderController::class, 'retryPayment'])
        ->middleware('throttle:payment-status')
        ->whereNumber('order')
        ->name('orders.retry-payment');
    Route::post('/orders/{order}/refresh-payment', [OrderController::class, 'refreshPayment'])
        ->middleware('throttle:payment-status')
        ->whereNumber('order')
        ->name('orders.refresh-payment');
    Route::post('/orders/{order}/ship', [OrderController::class, 'ship'])
        ->whereNumber('order')
        ->name('orders.ship');
    Route::post('/orders/{order}/confirm-delivery', [OrderController::class, 'confirmDelivery'])
        ->whereNumber('order')
        ->name('orders.confirm-delivery');
});
