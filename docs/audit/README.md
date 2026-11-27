# Plasvora Backend — Technical & Security Audit Index

**Audit Date:** 2026-11-25  
**Repository:** `C:\xampp\htdocs\backend_plasvora` (Laravel `v12.70.1`, PHP `8.2.12`, branch `feature/gallery`)

To avoid duplicating full multi-repository reports, the complete 7-document full-stack audit suite (covering both Flutter and Laravel) is stored in:
- `C:\Project\Plasvora_app\docs\audit\00-EXECUTIVE-SUMMARY.md`
- `C:\Project\Plasvora_app\docs\audit\01-SECURITY-FINDINGS.md`
- `C:\Project\Plasvora_app\docs\audit\02-BUGS-AND-CODE-QUALITY.md`
- `C:\Project\Plasvora_app\docs\audit\03-AI-IMPLEMENTATION-AUDIT.md`
- `C:\Project\Plasvora_app\docs\audit\04-DATABASE-AND-BUSINESS-LOGIC.md`
- `C:\Project\Plasvora_app\docs\audit\05-TEST-AND-DEPENDENCY-RESULTS.md`
- `C:\Project\Plasvora_app\docs\audit\06-REMEDIATION-ROADMAP.md`

---

## Backend-Applicable Findings Quick Reference

### Confirmed Security Findings (Backend)
- **`SEC-BE-08` [HIGH]:** 9 published Composer advisories across 6 locked packages (`laravel/framework v12.70.1`, `symfony/http-foundation v7.4.21`, `symfony/http-kernel v7.4.21`, `symfony/mime v7.4.19`, `symfony/routing v7.4.21`, `symfony/yaml v7.4.21`). Fix: `composer update` to Laravel `>=12.71.0` & Symfony `>=7.4.23`.
- **`SEC-BE-01` [MEDIUM]:** Sanctum tokens never expire (`config/sanctum.php:53`) and accumulate indefinitely (`AuthController.php:104`).
- **`SEC-BE-02` [MEDIUM]:** Missing rate limiting on mutating CRUD (`POST/PATCH/DELETE /materials`, `/material-requests`, `/addresses`, `/handover-requests`), matching, activity, and 10-query public `/api/impact-summary` (`routes/api.php`).
- **`SEC-BE-03` [MEDIUM]:** BOLA / missing active-status checks on `GET /api/material-requests/{id}/matches` and `GET /api/materials/{id}/matches` (`MatchingController.php:23-96`).
- **`SEC-BE-04` [MEDIUM]:** Private user analysis images stored on unauthenticated `public` disk + orphan leak on Gemini exception (`AnalysisController.php:116,175-184`).
- **`SEC-BE-05` [MEDIUM]:** Unsanitized user `notes` concatenated into Gemini prompt + raw Gemini 400 error echoed to client (`GeminiVisionService.php:132-134,336`, `AnalysisController.php:182`).
- **`SEC-BE-06` [MEDIUM]:** Unbounded `agreed_weight_kg` / `actual_weight_kg` on handover updates allows arbitrary inflation of public impact metrics (`UpdateHandoverStatusRequest.php:24-25`, `HandoverRequestController.php:258,265`).
- **`SEC-BE-07` [MEDIUM]:** TOCTOU race condition on Gallery image count (`min 1` / `max 5`) validated before `lockForUpdate()` (`GalleryWorkController.php:223-298`).
- **`SEC-BE-09` [LOW]:** Listing `image_url` allows non-HTTPS/arbitrary URLs (`StoreMaterialListingRequest.php:58`).
- **`SEC-BE-10` [LOW]:** Uploaded listing/gallery photos retain raw EXIF metadata on public disk (`MaterialController.php:91`, `GalleryWorkController.php:161`).
- **`SEC-BE-11` [LOW]:** No `config/cors.php` published (falls back to Laravel 12 wildcard CORS).

### Confirmed Backend Bugs, AI, DB & Performance Issues
- **`BUG-03` / `AI-BUG-01` [HIGH]:** `GeminiVisionService::compressImageIfNeeded()` re-encodes large PNG/WebP images to JPEG bytes while leaving `inlineData.mimeType` as `image/png` or `image/webp` (`GeminiVisionService.php:74-75,258,496`).
- **`BUG-08` / `DB-02` [MEDIUM]:** Partial PATCH `is_free`/`price` drift and fractional decimal prices accepted on listings then rejected by `OrderService` (`UpdateMaterialListingRequest.php:87-98`, `StoreMaterialListingRequest.php:52`).
- **`BUG-09` / `AI-MATCH-01` [MEDIUM]:** Smart Matching self-matches user's own items, false-matches `"Kota"` / `"Kab."` prefixes, and loads all rows in category via unbounded `->get()` (`MaterialMatchingService.php:40-43,72-75,196-211`).
- **`DB-01` [MEDIUM]:** Obsolete 6-table `database/backup_plasvora_db.sql` is tracked in Git (uses safe placeholder password `'GANTI_DENGAN_HASH_PASSWORD'`, no real credentials, but incompatible with current 12-table migrations).
- **`DB-03` [MEDIUM]:** `HandoverRequestController::store()` does not check `$listing->is_free === true`.
- **`PERF-01` [MEDIUM]:** `ImpactSummaryController` runs 10 uncached SQL queries per hit and uses PHP `pluck()` + `whereNotIn()`.
- **`AI-CLAIM-01` [HIGH (cross-stack)]:** `ActivityController.php:128` labels every analysis `"Analisis AI"` even when `is_demo_result === true`.
- **`BUG-17` [LOW]:** Unescaped SQL `LIKE` wildcards (`%`, `_`) in `MaterialController::index()` and `MaterialRequestController::index()`.

### Backend Verification Results
- `php artisan route:list --path=api`: **PASS** (48 API routes loaded cleanly).
- `composer validate --no-interaction`: **PASS**.
- `composer audit --no-interaction`: **FAIL** (9 advisories across 6 packages).
- `phpunit tests/Unit`: **17/22 passed** (`MidtransServiceTest` 16/16 + `ExampleTest` 1/1 passed); **5 `OrderModelTest` DB tests errored** and **all 16 Feature suites blocked** because local MySQL on `127.0.0.1:3306` (`plasvora_testing`) is stopped (`SQLSTATE[HY000] [2002]`).
- Git check: `.env` is **not** tracked and never appeared in Git history.
