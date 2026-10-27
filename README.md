# PLASVORA Backend API — Turn Plastic Into Possibility

PLASVORA adalah platform yang membantu masyarakat menemukan potensi pemanfaatan sampah plastik dan menghubungkan pemilik material (donor/penjual) dengan pengrajin, sekolah, komunitas lingkungan, serta pelaku daur ulang.

Repository ini merupakan backend RESTful API yang dibangun menggunakan Laravel dan MySQL, dirancang khusus untuk prototype lomba serta integrasi langsung dengan aplikasi Flutter.

---

## 🚀 Teknologi yang Digunakan

- **Framework**: Laravel 12.x (PHP 8.2+)
- **Database**: MySQL 8.0+ / MariaDB (XAMPP compatible)
- **Autentikasi**: Laravel Sanctum (Bearer Token)
- **Architecture**: MVC + Service Layer + Form Requests + Policies
- **Testing**: PHPUnit / Feature Testing dengan isolated database

---

## 📋 Persyaratan Lingkungan (Prerequisites)

1. **PHP**: >= 8.2 (ekstensi `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `curl`)
2. **Composer**: >= 2.x
3. **Database**: MySQL / MariaDB (misalnya via XAMPP)
4. **Web Server / Tooling**: Apache/Nginx atau Laravel Built-in Server (`php artisan serve`)

---

## ⚙️ Panduan Instalasi & Setup Lokal

### 1. Clone & Masuk ke Folder Proyek
```bash
cd backend_plasvora
```

### 2. Pasang Dependency Composer
```bash
composer install
```

### 3. Konfigurasi File Lingkungan (.env)
Salin contoh file `.env.example` menjadi `.env` (atau gunakan `.env` yang tersedia):
```bash
cp .env.example .env
php artisan key:generate
```

Pastikan konfigurasi database di file `.env` mengarah ke MySQL lokal Anda:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=plasvora_db
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Buat Database MySQL
Buka MySQL CLI / phpMyAdmin / terminal dan jalankan:
```sql
CREATE DATABASE IF NOT EXISTS plasvora_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS plasvora_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Jalankan Migration
Jalankan migrasi untuk membuat seluruh tabel:
```bash
php artisan migrate
```

### 6. Jalankan Database Seeder (Demo Data)
Isi database dengan data contoh realistis untuk kebutuhan lomba dan presentasi:
```bash
php artisan db:seed
```

> **Akun Demo Bawaan Seeder:**
> - **Admin**: `admin@plasvora.test` / password: `password`
> - **Pemilik Material**: `pemilik@plasvora.test` / password: `password`
> - **Peminta Material**: `peminta@plasvora.test` / password: `password`

### 7. Jalankan Server Development
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

---

## 📱 Konfigurasi Alamat Server untuk Flutter

Saat mengintegrasikan aplikasi Flutter ke backend Laravel:

| Lingkungan Flutter | Base URL API | Keterangan |
| :--- | :--- | :--- |
| **Android Emulator** | `http://10.0.2.2:8000/api` | `10.0.2.2` adalah alias host mesin pengembang pada emulator Android |
| **iOS Simulator** | `http://127.0.0.1:8000/api` | iOS simulator berbagi network stack dengan macOS lokal |
| **Device Fisik (WiFi)** | `http://<IP_KOMPUTER_LOKAL>:8000/api` | Hubungkan HP dan Komputer ke jaringan Wi-Fi yang sama, gunakan IP lokal komputer (misal: `192.168.1.15`) |
| **Web Browser (Flutter Web)** | `http://127.0.0.1:8000/api` | Pastikan CORS mengizinkan origin client |

### Header Standar Request dari Flutter
Setiap request ke backend wajib menyertakan header:
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <SANCTUM_TOKEN> (untuk endpoint terproteksi)
```

---

## 📡 Daftar Endpoint API (REST API Contract)

### 1. Autentikasi (`/api`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `POST` | `/api/register` | Publik | Mendaftarkan pengguna baru (otomatis mendapatkan token) |
| `POST` | `/api/login` | Publik | Autentikasi email & password, mengembalikan token Sanctum |
| `POST` | `/api/logout` | Bearer | Mencabut token aktif pengguna saat ini |
| `GET` | `/api/me` | Bearer | Mengambil profil pengguna yang sedang login |

### 2. Material Listings (`/api/materials`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/materials` | Publik | Daftar material tersedia (filter: `q`, `category`, `condition`, `min_weight`, `max_weight`, `location`) |
| `POST` | `/api/materials` | Bearer | Membuat listing material baru (owner otomatis dari user login) |
| `GET` | `/api/materials/{id}` | Publik | Detail listing material |
| `PATCH` | `/api/materials/{id}` | Bearer | Memperbarui listing (hanya oleh pemilik) |
| `DELETE` | `/api/materials/{id}` | Bearer | Menonaktifkan listing (`status: inactive`, riwayat transaksi tetap aman) |
| `GET` | `/api/materials/{id}/matches` | Publik | Mencari kebutuhan (`material_requests`) yang cocok dengan listing ini |

### 3. Material Requests (`/api/material-requests`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/material-requests` | Publik | Daftar kebutuhan material yang berstatus `active` |
| `POST` | `/api/material-requests` | Bearer | Membuat permintaan kebutuhan material baru |
| `GET` | `/api/material-requests/{id}` | Publik | Detail kebutuhan material |
| `PATCH` | `/api/material-requests/{id}` | Bearer | Memperbarui kebutuhan material (hanya pembuat) |
| `DELETE` | `/api/material-requests/{id}` | Bearer | Membatalkan kebutuhan (`status: cancelled`) |
| `GET` | `/api/material-requests/{id}/matches` | Publik | Smart Matching: Menemukan listing yang cocok beserta alasan kecocokan |

### 4. Handover Requests (`/api/handover-requests`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/handover-requests` | Bearer | Riwayat transaksi penyerahan milik pengguna (sebagai owner/requester) |
| `POST` | `/api/handover-requests` | Bearer | Mengajukan permohonan pengambilan material ke pemilik listing |
| `GET` | `/api/handover-requests/{id}` | Bearer | Detail transaksi handover (hanya aktor yang terlibat) |
| `PATCH` | `/api/handover-requests/{id}/status` | Bearer | Transisi status handover (state machine validasi di server) |

#### Alur Transisi Status Handover
```
[pending] ──(owner setuju)──> [approved] ──(penyerahan fisik)──> [handed_over] ──(konfirmasi penerima)──> [utilization_confirmed]
    │                               │
    ├──(owner tolak)──> [rejected]  └──(batal)──> [cancelled]
    └──(requester batal)──> [cancelled]
```

### 5. Riwayat Aktivitas & Dampak (`/api`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `GET` | `/api/activities` | Bearer | Timeline riwayat aktivitas pengguna (listing, request, handover) terurut waktu |
| `GET` | `/api/impact-summary` | Publik / Bearer | Ringkasan dampak lingkungan nyata (total berat terdaftar, diserahkan, dan dimanfaatkan) |

### 6. Rekomendasi & Analisis Material (`/api/analyses`)
| Method | Endpoint | Auth | Deskripsi |
| :--- | :--- | :---: | :--- |
| `POST` | `/api/analyses` | Bearer | Mengirim parameter material & menerima ide rekomendasi pemanfaatan |
| `GET` | `/api/analyses` | Bearer | Mengambil riwayat analisis material milik pengguna |

---

## 📦 Format Response JSON Standar

Backend PLASVORA menjamin format JSON yang seragam untuk mempermudah parsing di Flutter:

### 1. Response Sukses (200 / 201)
```json
{
  "success": true,
  "message": "Materials retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "Botol PET Bekas Minuman",
      "category": "PET",
      "weight_kg": "25.50",
      "condition": "clean",
      "price": "0.00",
      "is_free": true,
      "general_location": "Bandung Utara",
      "description": "Botol PET bekas minuman sudah dicuci bersih.",
      "image_url": null,
      "status": "available",
      "owner": {
        "id": 2,
        "name": "Budi Santoso"
      }
    }
  ]
}
```

### 2. Response Validasi Gagal (422)
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "weight_kg": [
      "The weight kg field must be greater than 0."
    ],
    "category": [
      "The selected category is invalid."
    ]
  }
}
```

### 3. Response Unauthorized / Forbidden (401 / 403)
```json
{
  "success": false,
  "message": "Unauthenticated. Please login first."
}
```

### 4. Smart Matching Response (`/api/material-requests/{id}/matches`)
```json
{
  "success": true,
  "message": "Matching listings retrieved successfully",
  "request": {
    "id": 1,
    "material_category": "PET",
    "requested_weight_kg": 20.0
  },
  "data": [
    {
      "listing": {
        "id": 1,
        "name": "Botol PET Bekas Minuman",
        "category": "PET",
        "weight_kg": "25.50",
        "condition": "clean",
        "general_location": "Bandung Utara"
      },
      "match_score": 100,
      "match_reasons": [
        "Kategori plastik cocok (PET)",
        "Stok material mencukupi kebutuhan (tersedia 25.5 kg, diminta 20 kg)",
        "Kondisi material memenuhi syarat minimum",
        "Lokasi material berada di area yang sama (Bandung Utara)"
      ]
    }
  ]
}
```

---

## 🧪 Menjalankan Pengujian Otomatis (Testing)

Project dilengkapi Feature Tests komprehensif (48 test assertions) mencakup autentikasi, otorisasi, state machine handover, CRUD listing & request, matching reasons, dan keakuratan agregasi impact summary:

```bash
php artisan test
```

Konfigurasi database testing menggunakan `plasvora_testing` yang aman dan tidak menyentuh database development utama.

---

## ⚖️ Catatan Transparansi & Demo vs Produksi

1. **Smart Matching**: Menggunakan **Rule-Based Matching Algorithm** berbasis kriteria kategori, ketersediaan bobot, toleransi kondisi, dan kedekatan lokasi teks. Hasil matching tidak diklaim sebagai prediksi AI probabilistik.
2. **Material Analysis**: Fitur `/api/analyses` memberikan rekomendasi pemanfaatan berbasis aturan dan katalog material dengan flag `is_demo_result: true`, bukan klasifikasi deep learning onboard.
3. **Impact Summary**: Dihitung murni dari data aktual pada database:
   - `total_registered_kg`: Listing yang pernah didaftarkan.
   - `total_handed_over_kg`: Transaksi yang sudah berstatus `handed_over` atau `utilization_confirmed`.
   - `total_utilized_kg`: Transaksi yang telah dikonfirmasi pemanfaatannya (`utilization_confirmed`).
   - Tidak ada angka metrik yang di-hardcode.
