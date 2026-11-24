# Plasvora Gallery API ("Galeri Karya")

Dokumentasi kontrak API untuk fitur **Galeri Karya** pada backend Laravel Plasvora.

---

## Ringkasan Konvensi

- **Base URL**: `/api`
- **Format respons**: JSON standar Plasvora (`success`, `message`, `data`, dan `meta` untuk daftar berhalaman).
- **Autentikasi**: Laravel Sanctum (`Authorization: Bearer <token>`).
  - `GET /api/gallery` dan `GET /api/gallery/{id}` bersifat **publik** dengan autentikasi **opsional** (jika Bearer token dikirim, `liked_by_me` dihitung untuk pengguna tersebut dan penulis dapat melihat karya miliknya yang masih `pending`/`rejected`).
- **Publikasi & Status**:
  - Karya baru yang valid (dengan `ownership_confirmed` tercentang) **langsung tayang** (`status = "approved"`, `published_at = now()`), tanpa antrean verifikasi admin.
  - Mengedit karya berstatus `approved` mempertahankan status `approved` (tetap tayang di Galeri publik). Mengedit karya legacy berstatus `rejected` tidak otomatis mengubahnya menjadi `approved`.
  - Kolom historis moderasi (`status`, `rejection_reason`, `reviewed_at`) dan CLI Artisan (`gallery:pending`, `gallery:review`) tetap dipertahankan untuk kompatibilitas mundur / moderasi operasional bila dibutuhkan di masa depan.
  - Data legacy berstatus `pending` aktif dipublikasikan melalui data migration `2026_11_23_000004_publish_legacy_pending_gallery_works.php` saat menjalankan `php artisan migrate`.
- **Privasi Penulis**: Respons galeri hanya menampilkan `author.id` dan `author.name`. `email` dan `phone` tidak pernah dikembalikan.
- **Rate Limiting**:
  - Baca (`gallery-read`): 60 permintaan / menit (per pengguna atau IP).
  - Tulis (`gallery-write`): 5 permintaan / menit dan 30 permintaan / hari (per pengguna).
  - Like (`gallery-like`): 30 permintaan / menit (per pengguna).

---

## Kontrak Multipart (`POST /api/gallery` & `PATCH /api/gallery/{id}`)

Unggahan foto menggunakan `multipart/form-data`.

- **Foto (`images[]`)**:
  - Create: wajib **1–5 file** (`jpg`, `jpeg`, `png`, `webp`), maksimal **4 MB (4096 KB)** per file. Foto pertama (`sort_order = 0`) menjadi foto sampul (*cover*).
  - Update: gunakan **`POST /api/gallery/{id}`** dengan field **`_method=PATCH`** (konvensi standar Laravel untuk multipart update), atau `PATCH /api/gallery/{id}` untuk pembaruan JSON tanpa file.
  - Hapus foto saat edit: kirim `remove_image_ids[]` (atau `remove_image_ids[0]`, `remove_image_ids[1]`, dst.). Total foto akhir (`existing - removed + new`) harus tetap **1–5**.
- **Field Array (`materials`, `tools`, `steps`, `video_links`)**:
  - Mendukung notasi array berulang (`materials[]`) **dan** notasi berindeks (`materials[0]`, `materials[1]`, dst.) — keduanya diparsing identik oleh PHP/Laravel menjadi array terurut.
  - Untuk mengosongkan daftar opsional `tools` atau `video_links` via `multipart/form-data` saat update, kirim `clear_tools=1` dan/atau `clear_video_links=1` (atau kirim array kosong `[]` pada payload JSON).
- **Validasi Tautan Video (`video_links[]`)**:
  - Maksimal **3 tautan**, wajib **HTTPS**, tanpa kredensial URL (`user@host`), dan host harus persis atau subdomain valid dari:
    - `youtube.com` (mis. `www.youtube.com`, `m.youtube.com`)
    - `youtu.be`
    - `tiktok.com` (mis. `www.tiktok.com`, `vm.tiktok.com`)
    - `instagram.com` (mis. `www.instagram.com`)
- **Konfirmasi Kepemilikan (`ownership_confirmed`)**:
  - Wajib bernilai accepted (`1`, `true`, `yes`, `on`) saat membuat karya baru.

---

## Daftar Endpoint

### 1. Daftar Galeri Publik

`GET /api/gallery`

**Query Parameters:**
- `q` *(opsional)*: pencarian judul (case-insensitive substring).
- `difficulty` *(opsional)*: `mudah` | `menengah` | `sulit`.
- `sort` *(opsional)*: `latest` *(default)* | `popular`.
- `page` *(opsional)*: nomor halaman (default `1`).
- `per_page` *(opsional)*: jumlah item per halaman (default `12`, maksimal `24`).

**Contoh `curl`:**
```bash
curl -X GET "http://127.0.0.1:8000/api/gallery?difficulty=mudah&sort=popular&page=1&per_page=12" \
  -H "Accept: application/json"
```

**Respons `200 OK`:**
```json
{
  "success": true,
  "message": "Daftar galeri karya berhasil dimuat.",
  "data": [
    {
      "id": 1,
      "title": "Pot Hidroponik Mini dari Botol PET",
      "difficulty": "mudah",
      "cover_image_url": "http://127.0.0.1:8000/storage/gallery/example.jpg",
      "likes_count": 4,
      "liked_by_me": false,
      "published_at": "2026-11-23T08:00:00.000000Z",
      "created_at": "2026-11-23T07:50:00.000000Z",
      "author": {
        "id": 1,
        "name": "Budi Santoso"
      },
      "materials_count": 3,
      "steps_count": 4
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 12,
    "total": 1
  }
}
```

---

### 2. Detail Karya Galeri

`GET /api/gallery/{id}`

- Mengembalikan `200 OK` untuk karya `approved`.
- Mengembalikan `200 OK` (beserta `status` dan `rejection_reason`) untuk karya `pending`/`rejected` **hanya jika** pemanggil adalah penulis karya tersebut.
- Mengembalikan `404 Not Found` untuk tamu atau pengguna lain yang mengakses karya non-`approved`.

**Contoh `curl`:**
```bash
curl -X GET "http://127.0.0.1:8000/api/gallery/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>"
```

**Respons `200 OK`:**
```json
{
  "success": true,
  "message": "Detail karya galeri berhasil dimuat.",
  "data": {
    "id": 1,
    "title": "Pot Hidroponik Mini dari Botol PET",
    "difficulty": "mudah",
    "cover_image_url": "http://127.0.0.1:8000/storage/gallery/example.jpg",
    "images": [
      {
        "id": 10,
        "url": "http://127.0.0.1:8000/storage/gallery/example.jpg",
        "sort_order": 0
      }
    ],
    "materials": ["Botol plastik PET 1,5 liter", "Kain flanel bekas"],
    "tools": ["Cutter", "Gunting"],
    "steps": ["Cuci bersih botol.", "Potong botol menjadi dua bagian."],
    "video_links": ["https://www.youtube.com/watch?v=dQw4w9WgXcQ"],
    "likes_count": 4,
    "liked_by_me": true,
    "published_at": "2026-11-23T08:00:00.000000Z",
    "created_at": "2026-11-23T07:50:00.000000Z",
    "updated_at": "2026-11-23T08:00:00.000000Z",
    "author": {
      "id": 1,
      "name": "Budi Santoso"
    },
    "materials_count": 2,
    "steps_count": 2
  }
}
```

---

### 3. Karya Saya (Terautentikasi)

`GET /api/me/gallery`

Mengembalikan seluruh karya milik pengguna login (status `pending`, `approved`, dan `rejected`) termasuk field `status` dan `rejection_reason`.

**Contoh `curl`:**
```bash
curl -X GET "http://127.0.0.1:8000/api/me/gallery?page=1&per_page=12" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>"
```

---

### 4. Unggah Karya Baru (Terautentikasi)

`POST /api/gallery` (`multipart/form-data`)

**Contoh `curl`:**
```bash
curl -X POST "http://127.0.0.1:8000/api/gallery" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "title=Pot Hidroponik Mini dari Botol PET" \
  -F "difficulty=mudah" \
  -F "materials[0]=Botol plastik PET 1,5L" \
  -F "materials[1]=Kain flanel bekas" \
  -F "tools[0]=Gunting" \
  -F "steps[0]=Cuci bersih botol PET lalu keringkan." \
  -F "steps[1]=Potong botol menjadi dua bagian dan pasang sumbu flanel." \
  -F "video_links[0]=https://youtu.be/dQw4w9WgXcQ" \
  -F "ownership_confirmed=1" \
  -F "images[]=@/path/to/cover.jpg" \
  -F "images[]=@/path/to/angle2.jpg"
```

**Respons `201 Created`:** Mengembalikan objek detail karya dengan `"status": "approved"` dan `"published_at"` terisi sehingga karya langsung tampil di `GET /api/gallery`.

---

### 5. Perbarui Karya (Penulis Saja)

`POST /api/gallery/{id}` dengan `_method=PATCH` (`multipart/form-data`) atau `PATCH /api/gallery/{id}` (`application/json`).

**Contoh `curl` (multipart tambah & hapus foto):**
```bash
curl -X POST "http://127.0.0.1:8000/api/gallery/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "_method=PATCH" \
  -F "title=Pot Hidroponik Mini Revisi" \
  -F "difficulty=menengah" \
  -F "materials[0]=Botol plastik PET 1,5L" \
  -F "clear_tools=1" \
  -F "steps[0]=Langkah revisi pertama." \
  -F "clear_video_links=1" \
  -F "remove_image_ids[0]=10" \
  -F "images[]=@/path/to/new_cover.jpg"
```

**Respons `200 OK`:** Karya diperbarui dengan status yang dipertahankan (karya `approved` tetap tayang di Galeri publik).

---

### 6. Hapus Karya (Penulis Saja)

`DELETE /api/gallery/{id}`

Melakukan soft delete pada karya dan menghapus file gambar terkait dari disk `public`.

**Contoh `curl`:**
```bash
curl -X DELETE "http://127.0.0.1:8000/api/gallery/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>"
```

---

### 7. Toggle Suka / Like (Terautentikasi)

`POST /api/gallery/{id}/like`

Hanya berlaku untuk karya berstatus `approved`. Pemanggilan pertama menambahkan like (`liked: true`); pemanggilan kedua membatalkan like (`liked: false`).

**Contoh `curl`:**
```bash
curl -X POST "http://127.0.0.1:8000/api/gallery/1/like" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>"
```

**Respons `200 OK`:**
```json
{
  "success": true,
  "message": "Karya disukai.",
  "data": {
    "liked": true,
    "likes_count": 5
  }
}
```

---

## Prasyarat Operasional Backend

Jalankan sekali pada lingkungan target sebelum menguji unggahan foto:

```bash
php artisan migrate
php artisan storage:link
```

Opsional untuk mengisi 3 data demo terverifikasi (tanpa gambar):

```bash
php artisan db:seed --class=GallerySeeder
```

> **Catatan URL Gambar (`APP_URL`)**: `cover_image_url` dan `images[].url` dibangun menggunakan `asset('storage/...')` berdasarkan `APP_URL` di `.env`. Pastikan `APP_URL` dapat dijangkau oleh perangkat/emulator Flutter saat pengujian fisik.
