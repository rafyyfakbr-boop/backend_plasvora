# BACKUP MANIFEST — BACKEND PLASVORA

## Backup Backend Rate Limiting Fix (SEC-001 & SEC-007)

**Tanggal**: 13 November 2026  
**Tujuan**:
1. SEC-001: Menerapkan rate limiting pada login (5 req/menit per email+IP hash, 60 req/menit per IP) dan registrasi (3 req/menit per IP, 20 req/jam per IP).
2. SEC-007: Menerapkan rate limiting pada analisis plastik (3 req/menit per user Sanctum, 20 req/hari per user Sanctum).
3. Penyiapan unit/feature tests untuk skenario rate limiting tanpa menjalankannya.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting\`

Struktur Folder Backup:
```
backup_rate_limiting/
├── app/
│   └── Providers/
│       └── AppServiceProvider.php
├── routes/
│   └── api.php
└── tests/
    └── Feature/
        ├── AuthTest.php
        └── ActivityAndAnalysisTest.php
```

### File yang Di-backup Sebelum Diubah:
1. `app/Providers/AppServiceProvider.php`
2. `routes/api.php`
3. `tests/Feature/AuthTest.php`
4. `tests/Feature/ActivityAndAnalysisTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting\app\Providers\AppServiceProvider.php" "C:\xampp\htdocs\backend_plasvora\app\Providers\AppServiceProvider.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting\routes\api.php" "C:\xampp\htdocs\backend_plasvora\routes\api.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting\tests\Feature\AuthTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\AuthTest.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting\tests\Feature\ActivityAndAnalysisTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\ActivityAndAnalysisTest.php" -Force
```

---

## 2. Backup Penambahan Test Coverage Rate Limiting

**Tanggal**: 13 November 2026  
**Tujuan**:
1. Menambahkan pengujian rate limiting login 60 permintaan/menit per IP.
2. Menambahkan pengujian rate limiting registrasi 20 permintaan/jam per IP.
3. Menambahkan pengujian rate limiting analisis 20 permintaan/hari per user.
4. Menambahkan pengujian verifikasi permintaan analisis yang dibatasi (throttled) tidak memanggil layanan Gemini API dan tidak menyimpan gambar ke disk.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting_tests\`

Struktur Folder Backup:
```
backup_rate_limiting_tests/
└── tests/
    └── Feature/
        ├── AuthTest.php
        ├── ActivityAndAnalysisTest.php
        └── GeminiVisionAnalysisTest.php
```

### File yang Di-backup Sebelum Diubah:
1. `tests/Feature/AuthTest.php`
2. `tests/Feature/ActivityAndAnalysisTest.php`
3. `tests/Feature/GeminiVisionAnalysisTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting_tests\tests\Feature\AuthTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\AuthTest.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting_tests\tests\Feature\ActivityAndAnalysisTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\ActivityAndAnalysisTest.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_rate_limiting_tests\tests\Feature\GeminiVisionAnalysisTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\GeminiVisionAnalysisTest.php" -Force
```

---

## 3. Backup Perbaikan Kerentanan SEC-003: Race Condition Serah Terima

**Tanggal**: 13 November 2026, 21:09 WIB  
**Tujuan**:
1. Mengamankan alur perubahan status serah terima (`PATCH /api/handover-requests/{id}/status`) dari race condition / concurrency issue saat persetujuan (approval) permintaan serah terima material.
2. Membungkus seluruh proses perubahan status dan validasi state machine dalam `DB::transaction()` dengan row-level locking (`lockForUpdate()`) berurutan: `HandoverRequest` lalu `MaterialListing`.
3. Memastikan ketersediaan material dicek ulang di dalam transaksi sehingga material yang sama tidak dapat disetujui lebih dari satu kali (`409 Conflict` jika material sudah bukan `available`).
4. Menambahkan test cases komprehensif pada `HandoverRequestTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_sec003\`

Struktur Folder Backup:
```
backup_sec003/
├── app/
│   └── Http/
│       └── Controllers/
│           └── Api/
│               └── HandoverRequestController.php
└── tests/
    └── Feature/
        └── HandoverRequestTest.php
```

### File yang Di-backup Sebelum Diubah:
1. `app/Http/Controllers/Api/HandoverRequestController.php` (`C:\xampp\htdocs\backend_plasvora\app\Http\Controllers\Api\HandoverRequestController.php`)
2. `tests/Feature/HandoverRequestTest.php` (`C:\xampp\htdocs\backend_plasvora\tests\Feature\HandoverRequestTest.php`)

### Langkah Pemulihan (Non-Git):
```powershell
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_sec003\app\Http\Controllers\Api\HandoverRequestController.php" "C:\xampp\htdocs\backend_plasvora\app\Http\Controllers\Api\HandoverRequestController.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_sec003\tests\Feature\HandoverRequestTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\HandoverRequestTest.php" -Force
```

---

## 4. Backup Pematangan Fitur Marketplace (Integrasi Backend & Filter Nyata)

**Tanggal**: 14 November 2026  
**Tujuan**:
1. Menghubungkan seluruh interaksi pengguna pada Marketplace secara nyata ke backend API (`GET /api/materials` & `GET /api/materials/{id}`).
2. Menghapus filter semu / SnackBar tiruan dan menggantikannya dengan query backend aktual (kategori, kondisi, rentang berat, lokasi, pencarian teks dengan debounce).
3. Memperbaiki model `MaterialListing` agar mendukung `ownerName` dari respons backend dan tidak menampilkan jarak fiktif jika tidak disediakan backend.
4. Menambahkan navigasi dan halaman detail material (`MaterialDetailScreen`) yang mengambil data aktual via `GET /api/materials/{id}`.
5. Memastikan modal filter lanjutan (Advanced Filter Modal) bekerja nyata untuk tombol Terapkan Filter dan Reset.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_marketplace_maturing\`

Struktur Folder Backup:
```
backup_marketplace_maturing/
└── lib/
    ├── models/
    │   └── material_listing.dart
    ├── repositories/
    │   └── material_repository.dart
    ├── viewmodels/
    │   └── marketplace_viewmodel.dart
    └── views/
        └── marketplace/
            └── marketplace_screen.dart
```

### File yang Di-backup Sebelum Diubah:
1. `lib/models/material_listing.dart`
2. `lib/repositories/material_repository.dart`
3. `lib/viewmodels/marketplace_viewmodel.dart`
4. `lib/views/marketplace/marketplace_screen.dart`

### Langkah Pemulihan (Non-Git):
```powershell
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_marketplace_maturing\lib\models\material_listing.dart" "C:\Project\Plasvora_app\lib\models\material_listing.dart" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_marketplace_maturing\lib\repositories\material_repository.dart" "C:\Project\Plasvora_app\lib\repositories\material_repository.dart" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_marketplace_maturing\lib\viewmodels\marketplace_viewmodel.dart" "C:\Project\Plasvora_app\lib\viewmodels\marketplace_viewmodel.dart" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_marketplace_maturing\lib\views\marketplace\marketplace_screen.dart" "C:\Project\Plasvora_app\lib\views\marketplace\marketplace_screen.dart" -Force
```


---

## 5. Backup Integrasi Riwayat Analisis ke Aktivitas & Detail Analisis

**Tanggal**: 14 November 2026  
**Tujuan**:
1. Menambahkan endpoint `GET /api/analyses/{id}` (`AnalysisController@show`) untuk mengambil detail riwayat analisis plastik milik user yang terautentikasi (404 jika bukan milik user).
2. Memasukkan riwayat analisis plastik (`AnalysisHistory`) ke dalam agregasi timeline aktivitas pada `GET /api/activities` (`ActivityController@index`) dengan tipe `'plastic_analysis'`.
3. Menambahkan accessor `image_url` pada model `AnalysisHistory` agar URI gambar input dapat diakses secara konsisten oleh frontend.
4. Menambahkan test cases komprehensif pada `tests/Feature/ActivityAndAnalysisTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\`

Struktur Folder Backup:
```
backup_analysis_marketplace_activity_be/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   │           ├── ActivityController.php
│   │           └── AnalysisController.php
│   └── Models/
│       └── AnalysisHistory.php
├── routes/
│   └── api.php
└── tests/
    └── Feature/
        └── ActivityAndAnalysisTest.php
```

### File yang Di-backup Sebelum Diubah:
1. `app/Http/Controllers/Api/ActivityController.php`
2. `app/Http/Controllers/Api/AnalysisController.php`
3. `app/Models/AnalysisHistory.php`
4. `routes/api.php`
5. `tests/Feature/ActivityAndAnalysisTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\app\Http\Controllers\Api\ActivityController.php" "C:\xampp\htdocs\backend_plasvora\app\Http\Controllers\Api\ActivityController.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\app\Http\Controllers\Api\AnalysisController.php" "C:\xampp\htdocs\backend_plasvora\app\Http\Controllers\Api\AnalysisController.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\app\Models\AnalysisHistory.php" "C:\xampp\htdocs\backend_plasvora\app\Models\AnalysisHistory.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\routes\api.php" "C:\xampp\htdocs\backend_plasvora\routes\api.php" -Force
Copy-Item "C:\Users\PPLG\.gemini\antigravity\brain\ad4f1028-05be-433f-9c28-ee55610e9d07\backup_analysis_marketplace_activity_be\tests\Feature\ActivityAndAnalysisTest.php" "C:\xampp\htdocs\backend_plasvora\tests\Feature\ActivityAndAnalysisTest.php" -Force
```



---

## 6. Backup Stage 1 Marketplace Foundation (Phone, Seller Contact, Structured Origin & Listing Image Upload — Backend)

**Tanggal**: 21 November 2026

**Tujuan**:
1. Menambahkan kolom nullable `phone` pada tabel `users`, normalisasi/validasi nomor Indonesia (`08...`, `62...`, `+62...`), serta endpoint `PATCH /api/me` untuk pembaruan profil/nomor telepon pengguna.
2. Mengekspos `owner.phone` secara eksklusif pada detail material (`GET /api/materials/{id}`) tanpa menambahkannya pada endpoint daftar/pencarian (`GET /api/materials`).
3. Menambahkan kolom nullable `origin_destination_id` dan `origin_label` pada tabel `material_listings` sebagai fondasi Stage 2 tanpa mengganggu listing lama.
4. Mendukung upload gambar multipart pada `POST /api/materials` dan pembaruan gambar pada `PATCH /api/materials/{id}` ke disk `public` direktori `materials/`, dengan penghapusan aman khusus file milik storage aplikasi saat gambar diganti.
5. Menambahkan feature tests pada `AuthTest.php` dan `MaterialListingTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage1_marketplace_foundation_be\`

### File yang Di-backup Sebelum Diubah:
1. `routes/api.php`
2. `app/Models/User.php`
3. `app/Models/MaterialListing.php`
4. `app/Http/Controllers/Api/AuthController.php`
5. `app/Http/Controllers/Api/MaterialController.php`
6. `app/Http/Requests/StoreMaterialListingRequest.php`
7. `app/Http/Requests/UpdateMaterialListingRequest.php`
8. `tests/Feature/AuthTest.php`
9. `tests/Feature/MaterialListingTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage1_marketplace_foundation_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 7. Backup Stage 1.1 WhatsApp Contact Privacy (Consent Enforcement — Backend)

**Tanggal**: 21 November 2026
**Tujuan**:
1. Menambahkan kolom boolean NOT NULL `whatsapp_public` (default `false`) pada tabel `users`.
2. Memperluas `PATCH /api/me`, `GET /api/me`, `POST /api/login`, dan `POST /api/register` agar mendukung `whatsapp_public` dengan default selalu `false` untuk akun lama maupun baru, validasi nomor telepon sebelum aktivasi, serta penonaktifan otomatis saat nomor telepon dihapus.
3. Menegakkan privasi di `GET /api/materials/{id}` agar `owner.phone` hanya dikembalikan jika `owner.whatsapp_public === true` (dan tetap tidak pernah muncul di `GET /api/materials`).
4. Menambahkan feature tests privasi WhatsApp pada `AuthTest.php` dan `MaterialListingTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage1_1_whatsapp_privacy_be\`

### File yang Di-backup Sebelum Diubah:
1. `app/Models/User.php`
2. `app/Http/Controllers/Api/AuthController.php`
3. `app/Http/Controllers/Api/MaterialController.php`
4. `tests/Feature/AuthTest.php`
5. `tests/Feature/MaterialListingTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage1_1_whatsapp_privacy_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 8. Backup Stage 2 Batch 1 — Backend Foundation (RajaOngkir Config, Rate Limiters, UserAddress Schema & Service)

**Tanggal**: 21 November 2026
**Tujuan**:
1. Menambahkan konfigurasi `rajaongkir` di `config/services.php` dan placeholder non-secret pada `.env.example`.
2. Menambahkan named rate limiters `shipping-search` (30 req/menit) dan `shipping-estimate` (20 req/menit) di `app/Providers/AppServiceProvider.php`.
3. Menambahkan relasi `addresses(): HasMany` pada `app/Models/User.php`.
4. Membuat migrasi `user_addresses`, model `UserAddress`, dan layanan `RajaOngkirService`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage2_batch1_be\`

### File yang Di-backup Sebelum Diubah:
1. `config/services.php`
2. `.env.example`
3. `app/Providers/AppServiceProvider.php`
4. `app/Models/User.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage2_batch1_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 9. Backup Stage 2 Batch 2 — Backend APIs & Feature Tests (Addresses, Shipping & Origin Pair Validation)

**Tanggal**: 21 November 2026
**Tujuan**:
1. Menambahkan CRUD API alamat pembeli (`/api/addresses`) dengan penguncian transaksi default-address pada baris `users` dan proteksi kepemilikan (IDOR).
2. Menambahkan API pencarian destinasi (`/api/shipping/destinations`) dan estimasi ongkos kirim otoritatif server-side (`/api/shipping/estimate`).
3. Menambahkan validasi konsistensi berpasangan `origin_destination_id` & `origin_label` pada `StoreMaterialListingRequest` dan `UpdateMaterialListingRequest`.
4. Menambahkan feature tests komprehensif (`UserAddressTest.php`, `ShippingTest.php`, serta tambahan uji pair validation pada `MaterialListingTest.php`).

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage2_batch2_be\`

### File yang Di-backup Sebelum Diubah:
1. `routes/api.php`
2. `app/Http/Requests/StoreMaterialListingRequest.php`
3. `app/Http/Requests/UpdateMaterialListingRequest.php`
4. `tests/Feature/MaterialListingTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage2_batch2_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 10. Backup Stage 3 Batch 1 — Backend Database, Models & Midtrans Foundation

**Tanggal**: 22 November 2026  
**Batch**: Stage 3 — Batch 1 (Database, Models & Midtrans Foundation)  
**Tujuan**:
1. Menambahkan tiga migrasi fondasi transaksi: `orders`, `order_status_histories`, dan `payment_webhook_events` dengan snapshot immutable, harga whole-Rupiah (`unsignedBigInteger`), foreign key protektif (`restrictOnDelete` / `nullOnDelete`), dan indeks terukur.
2. Membuat model Eloquent `Order`, `OrderStatusHistory`, dan `PaymentWebhookEvent`, serta memperluas relasi non-destruktif pada `User`, `MaterialListing`, dan `UserAddress`.
3. Menambahkan konfigurasi Midtrans terpisah (`Server Key`, `Signature Secret Key`, dan opsional `Signature Secret Key Previous`) pada `config/services.php` dan placeholder non-secret pada `.env.example`.
4. Membuat `App\Exceptions\MidtransException` dan `App\Services\MidtransService` berbasis Laravel HTTP Client tanpa SDK tambahan.
5. Menambahkan unit tests fondasi `tests/Unit/Services/MidtransServiceTest.php` dan `tests/Unit/Models/OrderModelTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_batch1_be\`

### File Eksisting yang Di-backup & Dimodifikasi:
1. `.env.example` — Menambahkan placeholder non-secret konfigurasi Midtrans Access Keys.
2. `config/services.php` — Menambahkan blok konfigurasi `midtrans` dengan pemisahan Server Key vs Signature Secret Key dan default Sandbox.
3. `app/Models/User.php` — Menambahkan relasi `buyerOrders()`, `sellerOrders()`, dan `orderStatusHistories()`.
4. `app/Models/MaterialListing.php` — Menambahkan relasi `orders()`.
5. `app/Models/UserAddress.php` — Menambahkan relasi `orders()`.

### File Baru yang Dibuat:
1. `database/migrations/2026_11_22_000002_create_orders_table.php`
2. `database/migrations/2026_11_22_000003_create_order_status_histories_table.php`
3. `database/migrations/2026_11_22_000004_create_payment_webhook_events_table.php`
4. `app/Models/Order.php`
5. `app/Models/OrderStatusHistory.php`
6. `app/Models/PaymentWebhookEvent.php`
7. `app/Exceptions/MidtransException.php`
8. `app/Services/MidtransService.php`
9. `tests/Unit/Services/MidtransServiceTest.php`
10. `tests/Unit/Models/OrderModelTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_batch1_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 11. Backup Stage 3 — Backend Transaction Lifecycle (Checkout, Midtrans Snap, Webhook, Fulfillment, Expiry & Guards)

**Tanggal**: 22 November 2026  
**Batch**: Stage 3 — Backend Transaction Lifecycle (Konsolidasi Batch 2 & Batch 3)  
**Tujuan**:
1. Mengimplementasikan seluruh siklus transaksi backend: checkout atomik & idempoten (`POST /api/orders`), Midtrans Snap Redirect, retry/refresh pembayaran, pembatalan pembeli otoritatif, webhook Midtrans SHA-512, pengiriman penjual (`POST /api/orders/{order}/ship`), konfirmasi penerimaan pembeli (`POST /api/orders/{order}/confirm-delivery`), dan daftar/detail pesanan dengan privasi alamat pembeli.
2. Menambahkan Artisan command `orders:expire-unpaid` dan penjadwalan 5 menitan pada `routes/console.php`.
3. Memperluas `MidtransService` dengan `cancelTransaction()`, `expireTransaction()`, `paymentExpiryMinutes()`, dan verifikasi opsional `signature_key` pada respons Status API.
4. Menambahkan perlindungan kontensi listing & handover (`MaterialController`, `UpdateMaterialListingRequest`, `HandoverRequestController`) agar satu lot material tidak pernah dapat terjual atau direservasi dua kali.
5. Mengintegrasikan riwayat status pesanan ke `ActivityController` (tanpa PII) dan pesanan selesai ke `ImpactSummaryController` (tanpa double-counting).
6. Menambahkan feature tests komprehensif (`OrderCheckoutTest`, `MidtransWebhookTest`, `OrderLifecycleTest`, `OrderExpiryTest`).

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_backend_lifecycle_be\`

### File Eksisting yang Di-backup & Dimodifikasi:
1. `.env.example` — Menambahkan placeholder `MIDTRANS_PAYMENT_EXPIRY_MINUTES=1440`.
2. `config/services.php` — Menambahkan kunci konfigurasi `midtrans.payment_expiry_minutes`.
3. `app/Services/MidtransService.php` — Menambahkan `paymentExpiryMinutes()`, `cancelTransaction()`, `expireTransaction()`, dan verifikasi `signature_key` pada Status API.
4. `app/Providers/AppServiceProvider.php` — Menambahkan rate limiters `checkout`, `payment-status`, dan `midtrans-webhook`.
5. `routes/api.php` — Mendaftarkan 8 endpoint terautentikasi `/api/orders*` dan 1 endpoint publik `POST /api/webhooks/midtrans`.
6. `routes/console.php` — Menjadwalkan `orders:expire-unpaid` setiap 5 menit.
7. `app/Http/Requests/UpdateMaterialListingRequest.php` — Membatasi perubahan manual status listing hanya pada `available` / `inactive`.
8. `app/Http/Controllers/Api/MaterialController.php` — Mengunci listing pada `update`/`destroy` dan menolak (`409`) jika terikat transaksi aktif.
9. `app/Http/Controllers/Api/HandoverRequestController.php` — Mengunci listing pada `store` dan menolak (`409`) jika ada Order/Handover aktif pada `store` & `updateStatus`.
10. `app/Http/Controllers/Api/ActivityController.php` — Menambahkan event transaksi dari `OrderStatusHistory` tanpa PII pembeli.
11. `app/Http/Controllers/Api/ImpactSummaryController.php` — Menghitung bobot pesanan `completed` tanpa double-counting dengan Handover.

### File Baru yang Dibuat:
1. `app/Support/RupiahAmount.php`
2. `app/Exceptions/OrderOperationException.php`
3. `app/Policies/OrderPolicy.php`
4. `app/Http/Requests/StoreOrderRequest.php`
5. `app/Http/Requests/CancelOrderRequest.php`
6. `app/Http/Requests/ShipOrderRequest.php`
7. `app/Services/OrderService.php`
8. `app/Http/Controllers/Api/OrderController.php`
9. `app/Http/Controllers/Api/MidtransWebhookController.php`
10. `app/Console/Commands/ExpireUnpaidOrdersCommand.php`
11. `tests/Feature/OrderCheckoutTest.php`
12. `tests/Feature/MidtransWebhookTest.php`
13. `tests/Feature/OrderLifecycleTest.php`
14. `tests/Feature/OrderExpiryTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_backend_lifecycle_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 12. Backup Stage 3 Targeted Correction — Snap Session Cancel API & Explicit +0700 Expiry Start Time

**Tanggal**: 22 November 2026  
**Batch**: Stage 3 — Targeted Review & Correction (Snap Session Cancellation & Expiry Start Time)  
**Tujuan**:
1. Menambahkan `MidtransService::cancelSnapSession(string $snapToken)` menggunakan endpoint resmi `POST /snap/v1/transactions/{token}/cancel` (Snap host Sandbox/Production) dengan header `Authorization: {merchant_server_key}` (raw Server Key sesuai dokumentasi Midtrans) serta pemetaan respons `canceled`, `already_canceled`, `in_progress`, `not_found`, dan timeout/network failure.
2. Memperbarui `OrderService::cancelOrderByBuyer()` agar membatalkan Snap Session ketika Core API belum memiliki transaksi (`404`), melepas listing secara atomik bila terkonfirmasi `canceled`/`already_canceled`, melakukan cek ulang GET Status & rekonsiliasi tanpa melepas listing bila `Transaction is on progress`, mempertahankan reservasi saat `not_found`/timeout, serta memastikan operasi cancel idempoten dan memblokir order `paid`/`shipped`/`completed`.
3. Memperbaiki `OrderService::buildSnapPayload()` dan `resolveEffectiveExpiryTime()` agar `expiry.start_time` secara eksplisit menggunakan timezone `+0700` (`Asia/Jakarta`), konsisten dengan `orders.payment_expires_at` (24 jam) dan `page_expiry`.
4. Menambahkan unit & feature tests menggunakan `Http::fake()` di `MidtransServiceTest.php` dan `OrderCheckoutTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_snap_cancel_expiry_fix_be\`

### File Eksisting yang Di-backup & Dimodifikasi:
1. `app/Services/MidtransService.php`
2. `app/Services/OrderService.php`
3. `tests/Unit/Services/MidtransServiceTest.php`
4. `tests/Feature/OrderCheckoutTest.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_stage3_snap_cancel_expiry_fix_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```


---

## 13. Backup Fitur Galeri Karya ("Galeri Karya" — End-to-End Backend)

**Tanggal**: 23 November 2026  
**Tujuan**:
1. Menambahkan 3 migrasi baru (`gallery_works`, `gallery_work_images`, `gallery_work_likes`) dengan foreign key protektif, indeks moderasi & popularitas, serta `SoftDeletes` pada karya galeri.
2. Membuat konfigurasi `config/gallery.php`, model `GalleryWork`, `GalleryWorkImage`, `GalleryWorkLike`, rule `AllowedVideoUrl` (ketat HTTPS + host YouTube/TikTok/Instagram tanpa kredensial), policy `GalleryWorkPolicy`, FormRequest `StoreGalleryWorkRequest` & `UpdateGalleryWorkRequest`, serta controller `GalleryWorkController`.
3. Menambahkan named rate limiters (`gallery-read`, `gallery-write`, `gallery-like`) pada `AppServiceProvider` dan mendaftarkan 7 endpoint galeri (`/api/gallery*`, `/api/me/gallery`) pada `routes/api.php`.
4. Menambahkan Artisan commands moderasi (`gallery:pending` dan `gallery:review`), seeder standalone `GallerySeeder` (tidak dimasukkan ke `DatabaseSeeder`), dokumentasi kontrak API `GALLERY_API.md`, dan suite feature tests `tests/Feature/GalleryWorkTest.php`.

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_gallery_feature_be\`

### File Eksisting yang Di-backup & Dimodifikasi:
1. `routes/api.php`
2. `app/Providers/AppServiceProvider.php`
3. `app/Models/User.php`

### File Baru yang Dibuat:
1. `config/gallery.php`
2. `database/migrations/2026_11_23_000001_create_gallery_works_table.php`
3. `database/migrations/2026_11_23_000002_create_gallery_work_images_table.php`
4. `database/migrations/2026_11_23_000003_create_gallery_work_likes_table.php`
5. `app/Models/GalleryWork.php`
6. `app/Models/GalleryWorkImage.php`
7. `app/Models/GalleryWorkLike.php`
8. `app/Rules/AllowedVideoUrl.php`
9. `app/Policies/GalleryWorkPolicy.php`
10. `app/Http/Requests/StoreGalleryWorkRequest.php`
11. `app/Http/Requests/UpdateGalleryWorkRequest.php`
12. `app/Http/Controllers/Api/GalleryWorkController.php`
13. `app/Console/Commands/GalleryPendingCommand.php`
14. `app/Console/Commands/GalleryReviewCommand.php`
15. `database/seeders/GallerySeeder.php`
16. `tests/Feature/GalleryWorkTest.php`
17. `GALLERY_API.md`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_gallery_feature_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
```



---

## 14. Backup Perbaikan Alur Publikasi Langsung Galeri Karya (Keep Ownership Declaration, Remove Admin Moderation — Backend)

**Tanggal**: 23 November 2026  
**Tujuan**:
1. Memperbarui `GalleryWorkController::store()` agar karya baru yang valid (dengan `ownership_confirmed` tervalidasi wajib) langsung dipublikasikan oleh backend dengan `status = 'approved'` dan `published_at = now()` tanpa antrean moderasi admin, tetap mengabaikan field terlindungi dari klien (`status`, `likes_count`, `user_id`).
2. Memperbarui `GalleryWorkController::update()` agar mengedit karya berstatus `approved` mempertahankan status tayang (`approved`), dan mengedit karya legacy berstatus `rejected` tidak otomatis mengubahnya menjadi `approved`.
3. Menambahkan data migration baru yang terarah dan reversibel (`database/migrations/2026_11_23_000004_publish_legacy_pending_gallery_works.php`) untuk memublikasikan hanya karya aktif berstatus `pending` (`deleted_at IS NULL`) tanpa menyentuh karya `rejected`, soft-deleted, atau sudah direview.
4. Memperbarui dokumentasi `GALLERY_API.md` dan suite feature tests `tests/Feature/GalleryWorkTest.php` (termasuk pengujian langsung untuk data migration `up()`/`down()`).

Direktori Backup Fisik:
`C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_gallery_publication_flow_be\`

### File Eksisting yang Di-backup & Dimodifikasi (3 file):
1. `app/Http/Controllers/Api/GalleryWorkController.php`
2. `tests/Feature/GalleryWorkTest.php`
3. `GALLERY_API.md`

### File Baru yang Dibuat (1 file):
1. `database/migrations/2026_11_23_000004_publish_legacy_pending_gallery_works.php`

### Langkah Pemulihan (Non-Git):
```powershell
$bak = "C:\Users\PPLG\.gemini\antigravity\brain\d907174e-f225-41e2-a0df-e727922bd3aa\backup_gallery_publication_flow_be"
Copy-Item "$bak\*" "C:\xampp\htdocs\backend_plasvora\" -Recurse -Force
Remove-Item "C:\xampp\htdocs\backend_plasvora\database\migrations\2026_11_23_000004_publish_legacy_pending_gallery_works.php" -ErrorAction SilentlyContinue
```
