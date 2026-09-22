# UpScale WP Test Store — Active Context

> Current state as observed in the working tree and the running stack. Update this
> file whenever the active work changes. Everything here was read from the files or
> the running containers, not assumed.

## Current focus — real product photographs + a finished-looking storefront (2026-09-22)

The user reviewed `http://localhost:8080` next to `https://beauty-mafia.com.ua/` and
reported that the store looked empty: no logo, no banner, and — above all — no real
product photographs. The agreed fix (chosen by the user, recorded as **T12**):
photographs of **real products** from **Wikimedia Commons** under CC0 / public
domain / CC BY / CC BY-SA, with attribution published on the store. Nothing is
taken from the reference shop's catalogue, and its brand/logos are not reproduced.

Result of this session:

| Area | State |
| --- | --- |
| Photographs | 86 photos of real products across **36 product-type keywords** + 3 hero candidates; licences recorded in `data/photo-sources.json` (34 keywords needed a second, broader query; none is empty now) |
| Media library | 87 photo attachments imported once and cached in `upscale_photo_attachments`; the 8 generated placeholders remain as a fallback |
| Product cards | all 304 demo products have a featured image **and** a gallery whose subject matches the name (`Мийка парикмахерська BarberCraft M-870`, `Машинка для стрижки …`); names are built from the photo keyword (T12) |
| Category tiles | all 174 categories resolve to a photo (`upscale_category_images`), a leaf inheriting its nearest ancestor's |
| Hero | real photograph behind the dark gradient (`upscale_hero_image`) |
| Logo | `theme/upscale-storefront/assets/logo.svg` — our own SVG mark ("SalonLine" wordmark + monogram), used in the header and the (inverted) footer |
| Attribution | "Джерела зображень" page: 88 rows with file, author, licence and Commons link; linked from the footer |
| Language | Ukrainian via real language packs (`WPLANG=uk`, core + WooCommerce), page titles renamed (Кошик, Оформлення замовлення, Мій кабінет, Каталог) — **T13** |
| Navigation | "Головне меню" (41 items) assigned to `primary` **and** `handheld`; Storefront's stray WordPress page list is gone |

## Verification performed (this session)

- `scripts/setup.ps1` end to end: photos fetched (skipped, already present) → 174
  categories → 304 products → placeholder import → photo pass
  (`imported 1, reused 86, products linked 303, categories linked 174`).
- `python scripts/verify.py --url http://localhost:8080 --key … --secret …` →
  **All checks passed** (REST via OAuth1, four attributes, categories, write scope).
- Render checks: home/shop/category/product/sources pages all HTTP 200; logo,
  hero photo, 8 tiles with 8 photos, 8–12 product cards with photos; 0 stray
  page-menu items; 0 "Add to cart" left; add-to-cart label is
  "Додати в кошик" (real translation).
- Product page spot check: "Мийка парикмахерська BarberCraft M-870" with 3 gallery
  images — the name matches the photograph's subject.
- `php -l` clean on every touched PHP file; no PHP notices in `docker compose logs wp`.
- 0 demo products without an image; 96 attachments in the media library.

## Problems found and fixed while validating

1. A **single wrapper root** ("Каталог продукции") plus WooCommerce's
   `uncategorized` term broke "top-level = parent 0" logic, which produced one tile
   and an empty menu. The theme helper and the seeder now descend one level and
   ignore `uncategorized`.
2. Seeder closures missed `use ($entry_by_slug)`, so the menu filter matched nothing
   (0 items). Fixed → 41 items.
3. `scripts/verify.py` crashed on Cyrillic in a cp1252 console; it now forces UTF-8.
4. The shop page slug collided with the catalogue root term; it stays `shop`.
5. Branch categories had no exact Google category; they inherit the majority value
   of their descendants (Меблі → 7240, Манікюр → 5880, Інструменти → 533 …).
6. Storefront renders a plain **page list** for the unassigned `handheld` menu
   location; assigning the real menu removed it.
7. `hair_styler` and `wash_unit` initially had no usable Commons photo; broader
   queries plus a new `--only` flag filled both.

## Documentation added — the test runbook

`docs/TESTING.md` is the end-to-end runbook for exercising the UpScale product
against this store, linked from the top of `README.md`. It records, in order:

1. what is already in place (store, mock, artifacts, `data/source-products.csv`);
2. how to get fresh REST credentials (`setup.ps1 -SkipCatalog -SkipPhotos` — the key
   pair rotates on every setup run);
3. the backend `.env` contents and **the two caveats that silently break the test**:
   a backend running in Docker must use `host.docker.internal` for `WC_API_URL` /
   `UPS_API_URL` (the two stacks are on different Docker networks), and
   `UPSCALE_MAPPING_FILE` must be readable **by the backend process** — unmounted, the
   override returns `False` silently and attributes degrade to `id: 0` with an empty
   `gcategory`;
4. the smoke test (`create → collect → process → generate`, `push` only with `-Push`)
   and what each stage should produce;
5. the bulk workflow (`upload` → `collect_all` → `process_all` → `generate_all`,
   `push_all` separately) with a 10-row slice first;
6. a success checklist, a pitfall table and the safety rules.

## Key facts to carry forward

- Stack: `wp` (WordPress 7.1, :8080), `wpdb` (MySQL 8.4, :3308), `cli`,
  `ups-mock` (:9000, 174 categories).
- Three-way category chain agrees: UPS mock ↔ `gcategories_json` ↔ store
  `product_cat` (174 ids, checked programmatically).
- The id bridge stays the backend's `UPSCALE_MAPPING_FILE` override (backend D19).
- Verify with OAuth1, not `curl -u` (T3 / backend D20). `push` only with `-Push`
  (T5) — never run in this session.
- Attribution must stay published while the Commons photos are in use (T12).
- The checkout was **not** touched; `../UpScale-Back-master` was only read.

## Next step

1. Operator: paste the latest `.env` block into `../UpScale-Back-master/.env`
   (the REST key pair rotates on every setup run).
2. Run `scripts/smoke.ps1` with a URL from `data/source-products.csv` to exercise
   `create → collect → process → generate`; `-Push` only on explicit authorization.
3. Review the storefront at <http://localhost:8080> (admin `admin` / `admin123`).
