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
