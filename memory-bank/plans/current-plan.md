# Plan — Real product photographs and a storefront that looks finished

> Active plan. Update it as scope changes. Do not mark anything done until the
> implementation and validation actually finished.

## Status

**Implemented and verified (2026-09-22).** The store now shows real photographs of
real products, its own logo and hero banner, Ukrainian chrome and a published
attribution page. `scripts/setup.ps1` runs the whole chain end to end and
`scripts/verify.py` reports *All checks passed*.

## Goal

Close the gap the user saw between `http://localhost:8080` and the reference
storefront: no logo, no banner, category tiles without images, and — the main
complaint — **no real product photographs**, so the store looked empty next to
`https://beauty-mafia.com.ua/`.

## Scope decision

The reference shop's own photographs, banners and brand logos are its property and
are **not** copied (this continues the earlier decision **T8**). The user chose the
alternative: photographs of **real products** from **Wikimedia Commons** under
CC0 / public domain / CC BY / CC BY-SA, with attribution published on the store.
Recorded as **T12**; the language/navigation polish is **T13**.

## What was built

| Piece | File(s) |
| --- | --- |
| Photo acquisition + licence record | `scripts/fetch_product_photos.py` → `data/photo-sources.json`, `data/photo-map.json`, `assets/product-photos/`, `assets/hero/` |
| Shared demo helpers | `scripts/inc/demo-data.php` (labels, category → keyword) |
| Photo import + product/category images + attribution page | `scripts/seed-photos.php` |
| Names that match the photo, Ukrainian pages, handheld menu | `scripts/seed-catalog.php` |
| Logo, hero, image tiles, footer link, Ukrainian fallback | `theme/upscale-storefront/` (`assets/logo.svg`, `header.php`, `front-page.php`, `footer.php`, `functions.php`, `inc/layout.php`, `style.css`) |
| Wiring | `scripts/setup.ps1` (`-PhotosPath`, `-SkipPhotos`) |

## Acceptance criteria and how they were checked

- 36/36 product-type keywords have photographs (86 files); `data/photo-sources.json`
  records author, licence, licence URL and Commons file page for each.
- Every demo product: 304/304 have a featured image; product names follow the photo
  subject (spot-checked: "Мийка парикмахерська BarberCraft M-870" + 3 gallery images).
- Every category: 174/174 resolve to a photo; the homepage shows 8 tiles each with a
  photo.
- Attribution page "Джерела зображень": 88 rows, 87 Commons links, linked from the
  footer.
- Chrome is Ukrainian ("Додати в кошик"), 0 "Add to cart" strings left; page titles
  renamed; 0 stray page-menu items.
- `python scripts/verify.py` → *All checks passed*; `php -l` clean; no PHP notices.
- `scripts/setup.ps1` end to end on the existing store: idempotent (0 duplicate
  products/pages/reviews/media), photo pass reused 86 attachments.

## Constraints honoured

- No third-party catalogue content: photos are Commons files with recorded licences;
  the reference shop's images, banners and brand are untouched.
- No `docker compose down -v`, no production keys, nothing changed in
  `../UpScale-Back-master` (read-only for the `g_category` snapshot).
- `push` was never run.

## Addendum (2026-09-22) — the test runbook

`docs/TESTING.md` was added and linked from `README.md`: the end-to-end runbook for
running the UpScale product against this store (credentials → backend `.env` →
smoke test → bulk CSV → checklist → pitfalls → safety rules).

It was written from the current files and the backend's own docs/code — the two
silent failure modes (backend-in-Docker networking, and `UPSCALE_MAPPING_FILE` not
being readable inside the backend container) were confirmed in
`infrastructure/api_clients/gpt_data.py` (`apply_store_mapping_override()` →
`os.path.isfile()`) and the backend's `docker-compose.yml` (no host mounts).

The first live run of the pipeline is the operator's step: **no stage, and in
particular no `push`/`push_all`, was executed here**, and Claid credits remain a
prerequisite for `process`.

## Remaining / known gaps

1. **`scripts/setup.sh` still lags** `setup.ps1` (catalog, theme, photo and seed
   steps). Windows is the validated path (T7); POSIX parity is open work.
2. A photo shows the *type* of product, not the exact model in the card, and is
   reused across neighbouring cards — acceptable for a demo, would need a licensed
   per-product source to improve.
3. The attribution page is required while the CC BY / CC BY-SA photos are used.
4. Optional polish: brand logos for the strip (deliberately text-only), several
   distinct shots per type, a blog/FAQ block.
