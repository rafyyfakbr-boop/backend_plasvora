# Plasvora Backend — Database Entity Relationship Diagram (ERD)

This directory contains the clean, minimalist, editable Entity Relationship Diagram (ERD) for the **Plasvora Laravel 12 Backend** (`C:\xampp\htdocs\backend_plasvora`), reverse-engineered directly from the active migrations and Eloquent models.

---

## 1. Files in This Directory

| File | Purpose |
| :--- | :--- |
| `plasvora-erd.drawio` | Primary native editable **diagrams.net / draw.io** document using standard minimalist ERD table styling on a plain white canvas, containing **5 pages** (1 Master ERD + 4 Domain ERD pages). |
| `plasvora-erd-overview.png` | High-resolution visual preview (`5145 × 2295` px) of **Page 1 — Master ERD**. |
| `README.md` | Documentation of inspected sources, core tables, relationships, notation, known discrepancies, and editing instructions. |

---

## 2. Scope & Core Business Tables (12 Tables, 18 Enforced Foreign Keys)

The diagram focuses strictly on the **12 core application business tables** on a clean white canvas (Laravel system/infrastructure tables such as `personal_access_tokens`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, and `migrations` are intentionally excluded from the diagram):

1. `users`
2. `user_addresses`
3. `material_listings`
4. `material_requests`
5. `handover_requests`
6. `analysis_histories`
7. `orders`
8. `order_status_histories`
9. `payment_webhook_events`
10. `gallery_works`
11. `gallery_work_images`
12. `gallery_work_likes` *(Junction/Pivot table with `UNIQUE(gallery_work_id, user_id)` resolving N:M likes between `users` and `gallery_works`)*

### Enforced Database Foreign Keys (18 total)
| Parent Table | Child Table | FK Column | Cardinality | On Delete |
| :--- | :--- | :--- | :--- | :--- |
| `users.id` | `user_addresses` | `user_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `analysis_histories` | `user_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `material_listings` | `owner_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `material_requests` | `requester_id` | `1 : 0..*` | `RESTRICT` |
| `material_listings.id` | `handover_requests` | `listing_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `handover_requests` | `owner_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `handover_requests` | `requester_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `orders` | `buyer_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `orders` | `seller_id` | `1 : 0..*` | `RESTRICT` |
| `material_listings.id` | `orders` | `listing_id` | `1 : 0..*` | `RESTRICT` |
| `user_addresses.id` | `orders` | `buyer_address_id` | `0..1 : 0..*` | `SET NULL` |
| `orders.id` | `order_status_histories` | `order_id` | `1 : 0..*` | `RESTRICT` |
| `users.id` | `order_status_histories` | `actor_id` | `0..1 : 0..*` | `SET NULL` |
| `orders.id` | `payment_webhook_events` | `order_id` | `0..1 : 0..*` | `SET NULL` |
| `users.id` | `gallery_works` | `user_id` | `1 : 0..*` | `CASCADE` |
| `gallery_works.id` | `gallery_work_images` | `gallery_work_id` | `1 : 0..*` | `CASCADE` |
| `gallery_works.id` | `gallery_work_likes` | `gallery_work_id` | `1 : 0..*` *(N:M pivot)* | `CASCADE` |
| `users.id` | `gallery_work_likes` | `user_id` | `1 : 0..*` *(N:M pivot)* | `CASCADE` |

---

## 3. Project Sources Inspected

- **Migrations (`database/migrations/` — 20 files):** All `Schema::create()` and `Schema::table()` definitions for the 12 core business tables.
- **Eloquent Models (`app/Models/` — 12 models):** `User`, `UserAddress`, `MaterialListing`, `MaterialRequest`, `HandoverRequest`, `AnalysisHistory`, `Order`, `OrderStatusHistory`, `PaymentWebhookEvent`, `GalleryWork`, `GalleryWorkImage`, `GalleryWorkLike`.
- **Legacy SQL Dump Cross-Check:** `database/backup_plasvora_db.sql` (outdated pre-refactor dump; see Section 6).

---

## 4. Notation

- **Table Format:** Standard Draw.io Entity Relation table (`shape=table`) with solid neutral header (`#2C3E50`), key indicator column (`PK`, `FK`, `UQ`), column name, and SQL data type (`NULL` suffix for nullable columns). Dashed lighter header (`#64748B`) on domain sub-pages denotes a compact external table reference.
- **Connectors:** Orthogonal crow's-foot lines attached directly to PK/FK rows:
  - **`1 : 0..*`** — Mandatory one (`||`) to zero-or-many (`o<`) for non-nullable FKs.
  - **`0..1 : 0..*`** — Optional one (`o|`) to zero-or-many (`o<`) for nullable FKs (`ON DELETE SET NULL`).
- **External Reference IDs (not FKs):** `destination_id` / `origin_destination_id` (RajaOngkir) and `midtrans_order_id` / `midtrans_transaction_id` (Midtrans) reference external services and have no local parent tables.

---

## 5. Diagram Pages (`plasvora-erd.drawio`)

| Page | Tab Name | Contents |
| :--- | :--- | :--- |
| **Page 1** | `Master ERD` | All **12 core business tables** and all **18 enforced foreign key relationships** on a plain white canvas. |
| **Page 2** | `1. Users & Addresses` | `users` and `user_addresses` (`1` FK). |
| **Page 3** | `2. Marketplace & Handovers` | `material_listings`, `material_requests`, `handover_requests`, with compact references to `users` and `orders` (`6` FKs). |
| **Page 4** | `3. Orders & Payments` | Full 52-column `orders`, `order_status_histories`, `payment_webhook_events`, with compact references to `users`, `material_listings`, and `user_addresses` (`7` FKs). |
| **Page 5** | `4. Gallery & AI Analysis` | `gallery_works`, `gallery_work_images`, `gallery_work_likes` (N:M pivot), `analysis_histories`, with compact reference to `users` (`5` FKs). |

---

## 6. Known Discrepancies & Notes

- **Legacy SQL Dump (`database/backup_plasvora_db.sql`):** Contains an outdated pre-refactor 6-table schema (`analysis_history` singular, `users.role/address`, `material_listings.user_id/weight`, `material_requests.listing_id`, `handover_requests.material_request_id`). The active `database/migrations/` and `app/Models/` are the authoritative source of truth.
- **Live MySQL Check:** Direct socket access to local XAMPP MySQL was blocked by the sandbox; run `php artisan migrate:status` locally to verify pending migrations.

---

## 7. Opening and Editing `plasvora-erd.drawio`

- **diagrams.net (Web):** Open `https://app.diagrams.net/` → **Open Existing Diagram** → select `docs/erd/plasvora-erd.drawio`.
- **draw.io Desktop / VS Code Extension (`hediet.vscode-drawio`):** Open `docs/erd/plasvora-erd.drawio` directly and switch tabs along the bottom bar.
