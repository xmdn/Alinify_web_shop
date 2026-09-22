# UpScale — WordPress/WooCommerce Test Store

A local, disposable WooCommerce store used to test the UpScale import pipeline
(`D:/UpScale-Back-master`). This is a **separate project**: it never imports or
modifies the backend, and the backend never writes to this store unless you run
the `push` stage yourself.

> **Testing the UpScale product?** [`docs/TESTING.md`](docs/TESTING.md) is the
> end-to-end runbook: credentials, the backend `.env`, the Docker
> networking / `UPSCALE_MAPPING_FILE` caveats, the one-product smoke test, and the
> bulk CSV workflow (`upload` → `collect_all` → `process_all` → `generate_all`).

## Why a dedicated store

UpScale pushes products through the standard WooCommerce REST API (`wc/v3`).
Testing against the production store is not safe (publication requires explicit
authorization — decision D1), and a production store's ids are not reproducible.
This project gives you a controlled store where the real attribute/category ids
can be read once and pinned into the backend.

## Services

| Service | Image | Host port | Role |
| --- | --- | --- | --- |
| `wp` | `wordpress:7.1` | **8080** | The store itself (the child theme `upscale-storefront` is activated by the setup) |
| `wpdb` | `mysql:8.4` | **3308** | WordPress database (does not clash with UpScale's 3307) |
| `cli` | `wordpress:cli` | — | WP-CLI, kept idle so `docker compose exec` can drive the setup |
| `ups-mock` | built from `./mock-ups` | **9000** | Stand-in for the external "UPS" category API |

## Prerequisites

- Docker Desktop with Compose v2.
- Internet access **from the containers** (plugins are downloaded from wordpress.org).
- Real credentials for the stages you exercise: `GPT_API_KEY` (OpenAI) and
  `CLAID_TOKEN` (Claid). The `process` stage calls Claid; if that account has no
  credits the stage stops by design (D3) — that is a blocker, not something to
  work around.

## Quick start

```powershell
# optional: mirror the reference store's category tree + build the source list
python scripts/build_catalog.py

docker compose up -d --build
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
```

Linux/macOS:

```bash
python scripts/build_catalog.py
docker compose up -d --build
bash scripts/setup.sh
```

The script finishes by printing an `.env` block. Paste it into
`../UpScale-Back-master/.env`.

## What the setup does

1. `wp core install` — store at <http://localhost:8080>, admin `admin` / `admin123`.
2. Installs and activates **WooCommerce**, **Yoast SEO**, **Yikes Custom Product Tabs**.
3. Sets permalinks to `/%postname%/` (WooCommerce REST returns `404` without it).
4. Installs the backend's mu-plugin `snippets/upscale-woocommerce.php` (Section A
   registers the required global attributes).
5. Registers the attributes, creates one product category and generates a
   WooCommerce REST key pair with **read/write** scope.
6. Rewrites `mock-ups/categories.json` and `config/mapping.json` with the **real**
   ids so the backend, the mock API and the store all agree.

## The storefront and its demo catalogue

`http://localhost:8080` is not an empty shell. The setup mirrors the *structure* of
a professional beauty/barber supply storefront so the pipeline can be exercised
against a catalogue that looks real:

| Piece | What it is |
| --- | --- |
| Theme | `theme/upscale-storefront` — a child theme of the free **Storefront** theme: our own SVG logo, utility top bar, dark navigation with category dropdowns, hero banner with a real photograph, category tiles with photos, product grid, brand strip, trust blocks, testimonials, four-column footer |
| Photographs | 86 real product photos of real products (clippers, dryers, chairs, lamps …) downloaded from **Wikimedia Commons** with their licence recorded; the hero banner is one of them |
| Attribution | the "Джерела зображень" page lists every file with its author, licence and Commons link (required by CC BY / CC BY-SA) |
| Categories | the tree from `data/catalog.json` (174 categories by default), created parent-first, each with its own `icp` / `keywords` |
| Products | 304 demo products named after the object in their photo (`Мийка парикмахерська BarberCraft M-870`), deterministic demo prices, featured image + gallery |
| Navigation | the "Головне меню" WP menu, built from the top-level branches of the tree, assigned to both `primary` and `handheld` |
| Pages | Про нас, Оплата і доставка, Повернення і обмін, Контакти, Джерела зображень — demo copy |
| Language | Ukrainian (`WPLANG=uk`, core + WooCommerce language packs), with a gettext fallback in the theme for the English locale |

What it deliberately does **not** do: copy another site's product names,
descriptions, photographs, banners or brand. The photographs are Wikimedia
Commons files under CC0 / public domain / CC BY / CC BY-SA — never images lifted
from the reference shop. `data/source-products.csv` holds the public **URLs** of
the reference store's product pages — that list is the input the analyzer consumes
via `POST /new_products/upload` (`url` + whitespace-separated image URLs), so the
pipeline has thousands of real targets without anything being copied.

```powershell
# regenerate the mirrored tree, the source list and the photographs
python scripts/build_catalog.py
python scripts/fetch_product_photos.py

# seed without touching the theme, or skip the demo catalogue entirely
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1 -SkipStorefront
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1 -SkipCatalog -SkipPhotos
```

`scripts/seed-catalog.php` and `scripts/seed-photos.php` are idempotent: re-running
them neither duplicates products, pages, reviews, menu items nor media items.

## The id problem this store exposes (and how it is bridged)

The backend keeps two static snapshots of one production store
(`infrastructure/api_clients/gpt_data.py`):

- `PromptData.actual_attributes` — attribute `id` ↔ `name`; mandatory
  `Производители`, `Бренд`, `mpn`, `gcategory`.
- `PromptData.gcategories_json` — category id → Google product category.

A fresh store has different ids, so on an unmodified backend:

- the generated attributes fall back to `"id": 0` → **per-product custom**
  attributes instead of global/filterable ones;
- `generate_data` finds no `g_category` for the product's category → `gcategory`
  is generated empty.

This project bridges it **without touching the production values**: point
`UPSCALE_MAPPING_FILE` at the generated `config/mapping.json` and the backend
overrides both maps for the test store only (see
`infrastructure/api_clients/gpt_data.py`). Production behaviour is unchanged when
the variable is unset.

The three-way category chain still has to agree:

```
NewProduct.category_id  ←  the "UPS" mock (mock-ups/categories.json)
                        →  PromptData.gcategories_json  (config/mapping.json)
                        →  WooCommerce product_cat id   (the test store)
```

`scripts/setup.*` writes the same id into all three places.

## Networking — what `WC_API_URL` must be

| Where the UpScale API runs | `WC_API_URL` | `UPS_API_URL` |
| --- | --- | --- |
| On the host (`uvicorn main:app`) | `http://localhost:8080/` | `http://localhost:9000` |
| In Docker (`my_fastapi_app`) | `http://host.docker.internal:8080/` | `http://host.docker.internal:9000` |

WordPress serves the REST API regardless of the requested host name, so normally
no extra host configuration is needed.

## Verify the store (before touching the backend)

> **Important:** `curl -u key:secret` (Basic auth) returns **401 on a plain-HTTP
> store**. WooCommerce only attempts Basic authentication when `is_ssl()` is true
> (`includes/class-wc-rest-authentication.php`), so `docs/woocommerce-site-setup.md`
> §8's curl checks need an HTTPS store. The UpScale backend does **not** use Basic
> auth — it signs with **OAuth 1.0a** (consumer key/secret), which works over HTTP.
> Use the bundled verifier, which speaks OAuth 1.0a:

```powershell
python scripts/verify.py --url http://localhost:8080 --key <WC_API_KEY> --secret <WC_API_SECRET>
```

Expected output:

```
REST reachable + credentials valid             OK HTTP 200
Global attributes readable                     OK 1=Бренд, 4=gcategory, 2=Производители, 3=mpn
  attribute present: Бренд                     OK
  attribute present: Производители             OK
  attribute present: mpn                       OK
  attribute present: gcategory                 OK
Categories readable                            OK <id>=<category>, … (one entry per product_cat)
Write scope (create draft)                     OK HTTP 201 id=10
Delete the test draft                          OK HTTP 200
```

The UPS mock (Basic auth is fine here — that API is not WooCommerce):

```powershell
curl.exe -s -u "admin:mock-ups-key" "http://localhost:9000/categories/get_categories"
```

Then confirm in wp-admin: **Yoast SEO** shows its product fields, **Yikes Custom
Product Tabs** renders the tab, and `wp-content/mu-plugins/upscale-woocommerce.php`
is present.

## Running the full pipeline (draft only)

Prerequisites: the UpScale `.env` points at this store (`WC_API_URL`,
`UPS_API_URL`, `UPSCALE_MAPPING_FILE`) and carries a real `GPT_API_KEY` plus a
`CLAID_TOKEN` with credits — `process` fails without them (D3).

The quickest way is the bundled smoke test: it drives
`create → collect → process → generate` for one product and **stops before
`push`**.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1 `
    -Api http://localhost:8000 `
    -Url https://competitor.example/product `
    -Images "https://competitor.example/1.jpg"
```

Add `-Push` to publish to the test store. `push` is never implicit, because
publication requires explicit authorization (D1). `smoke.ps1` signs up/logs in
by itself; pass `-Token <jwt>` to reuse an existing session.

The equivalent manual sequence is in `../UpScale-Back-master/docs/quickstart.md`
(§4–§6):

```
create → collect → process → generate → push
```

`push` publishes to this test store. Run it only against the test store — never
against production without explicit authorization. Check the created draft in
**Products** before doing anything else.

What a correct run looks like:

- `collect` fills SKU, price, name, description, specifications (needs the
  competitor page to be reachable from the backend container).
- `process` uploads each image to Claid and writes meta title/description.
- `generate` writes the HTML description and the attribute list; the mandatory
  attributes must come back as **global** (`id` = a real store id, not `0`) and
  `gcategory` must be filled.
- `push` creates the product as a draft.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `404` on every `/wp-json/wc/v3/*` call | Permalinks are "Plain". Re-run the setup script or `docker compose exec -T cli wp rewrite structure '/%postname%/' --hard`. |
| `401` from the REST API | Wrong key/secret, or the key was regenerated. Re-run the setup script and re-paste the `.env` block. |
| Attributes appear as custom, not global | `config/mapping.json` is stale or `UPSCALE_MAPPING_FILE` is not set/loaded. Re-run the setup script. |
| `gcategory` is empty in the generated product | The product's category id is missing from `gcategories_json`, or the UPS mock returns a different id. Both come from `config/mapping.json` / `mock-ups/categories.json`. |
| `process` stops with a Claid error | The Claid account has no credits. Report it; do not bypass image processing (D3). |
| The shop is empty / only "Test Category" exists | `data/catalog.json` was missing when `setup.ps1` ran, so it fell back to the legacy single category. Run `python scripts/build_catalog.py`, then re-run `scripts/setup.ps1`. |
| Products have no images | The placeholder import failed. Delete the `upscale_placeholder_ids` option and re-run `scripts/setup.ps1`. |
| Products still show the abstract placeholder tiles | The Wikimedia photo record is missing. Run `python scripts/fetch_product_photos.py`, then re-run `scripts/setup.ps1` (or `-SkipStorefront`) to import and attach them. |
| A category shows a placeholder instead of a photo | That product type has no Commons photo (check `data/photo-sources.json`); add a search phrase to `KEYWORDS` in `fetch_product_photos.py` and re-run it with `--only <keyword>`. |
| The attribution page is empty | `scripts/seed-photos.php` did not run (or found no photos). It writes "Джерела зображень"; CC BY / CC BY-SA files must keep it. |
| The theme looks like a plain blog | The `upscale-storefront` child theme was not activated (or `theme/upscale-storefront` was missing). Check `wp theme list` and re-run the setup without `-SkipStorefront`. |
| A leftover "Test Category" is visible | Created by a run that predates `data/catalog.json`; nothing references it. Delete it under **Products → Categories** if it bothers you. |
| `update_category` seems to do nothing | Known defect: the backend posts no body (`docs/known-issues.md` #4). Not a store problem. |
| WP-CLI cannot write files | The `cli` container runs as uid 33. If a file is root-owned: `docker compose exec -u root cli chown -R www-data:www-data /var/www/html`. |

## Tear down

```powershell
docker compose down            # keep the store
docker compose down -v         # also delete the WordPress database and uploads
```

`down -v` removes the `wp_data` and `wp_db` volumes. It does not touch anything in
`../UpScale-Back-master`.

## Layout

```
UpScale-WP-Test/
├── docker-compose.yml
├── mock-ups/              # stand-in for the external "UPS" category API
│   ├── main.py
│   ├── requirements.txt
│   ├── Dockerfile
│   └── categories.json    # generated: rewritten by scripts/setup.*
├── scripts/
│   ├── build_catalog.py   # host: mirrors the reference category tree → data/
│   ├── fetch_product_photos.py # host: Wikimedia Commons photos + licences
│   ├── make_placeholders.py # host: fallback placeholder tiles
│   ├── setup.ps1          # Windows host
│   ├── setup.sh           # Linux/macOS host
│   ├── inc/demo-data.php  # shared helpers (labels, category → keyword)
│   ├── setup-site.php     # runs inside the container (wp eval-file)
│   ├── seed-catalog.php   # runs inside the container: demo products/pages/menu
│   ├── seed-photos.php    # runs inside the container: photos + attribution page
│   ├── verify.py          # OAuth1 store checks
│   └── smoke.ps1          # one product through the UpScale pipeline
├── theme/
│   └── upscale-storefront/ # child theme of Storefront (layout + logo.svg)
├── data/                  # generated: catalog.json, source-products.csv,
│                          #           photo-sources.json, photo-map.json
├── assets/product-photos/ # generated: real photos per product type
├── assets/hero/           # generated: hero banner candidates
├── assets/placeholders/   # generated: fallback demo images
├── docs/
│   └── TESTING.md         # end-to-end runbook for the UpScale product
├── config/
│   └── mapping.example.json
└── README.md
```

## Related

- `docs/TESTING.md` — end-to-end runbook for testing the UpScale import product against this store.
- `../UpScale-Back-master/docs/woocommerce-site-setup.md` — store requirements, id contracts, verification checklist.
- `../UpScale-Back-master/snippets/README.md` — the mu-plugin snippet.
- `../UpScale-Back-master/docs/known-issues.md` — known defects the test will surface.

