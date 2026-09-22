# UpScale WP Test Store — Project Brief

> Persistent context. Derived from `docker-compose.yml`, `README.md`, `scripts/`,
> `mock-ups/`, and the backend's `D:/UpScale-Back-master/memory-bank/decisions.md`
> (D19, D20). This is a map, not a substitute for those files.

## What it is

A **local, disposable WordPress + WooCommerce store** used to exercise the UpScale
product-import pipeline end to end. It is the integration target the backend
records as decision **D19**.

It exists because:

- publishing test products to a real store is not permitted (backend **D1**), and
- UpScale's attribute and category ids are a **static snapshot of one production
  store** (backend **D13**), so a fresh store cannot reproduce them on its own.

This project bridges that id gap by generating a mapping file from the store's
**real** ids and feeding it to the backend through the optional
`UPSCALE_MAPPING_FILE` override — without editing the production values.

## The chain in one line

```
competitor URL + images
   │  (UpScale backend: D:/UpScale-Back-master)
   ▼
create → collect → process → generate → push
   │                                        │
   └── reads categories from the UPS mock ───┴──▶ this WooCommerce store
```

## What it provides

| Piece | Role |
| --- | --- |
| `wp` (WordPress 7.1) | The store itself, admin at <http://localhost:8080> |
| `wpdb` (MySQL 8.4) | WordPress database, host port `3308` |
| `cli` (WP-CLI) | Drives the setup via `docker compose exec` |
| `ups-mock` (FastAPI) | Stand-in for the external "UPS" category API at `:9000` |
| `scripts/setup.*` + `setup-site.php` | Install/configure the store; generate the id artifacts |
| `scripts/build_catalog.py` | Host: mirror the reference store's category tree + build the analyzer's URL list |
| `scripts/fetch_product_photos.py` | Host: real product photographs from Wikimedia Commons + their licences |
| `scripts/seed-catalog.php` | In-container: demo products, brands, navigation menu, pages, store settings |
| `scripts/seed-photos.php` | In-container: media import, product/category images, attribution page |
| `scripts/inc/demo-data.php` | Shared helpers: Ukrainian labels, category → photo keyword |
| `scripts/make_placeholders.py` | Host: generate the fallback placeholder images (pure Python) |
| `theme/upscale-storefront` | Child theme of the free Storefront theme: logo, hero, tiles, footer |
| `scripts/verify.py` | OAuth1 verification of the store |
| `scripts/smoke.ps1` | One product through the pipeline, `push` only with `-Push` |
| `config/mapping.json` | **Generated**; the `UPSCALE_MAPPING_FILE` payload |
| `mock-ups/categories.json` | **Generated**; the mock's category list with real ids |
| `data/catalog.json` | **Generated**; the category tree `setup-site.php` creates |
| `data/source-products.csv` | **Generated**; public product URLs for `POST /new_products/upload` |
| `data/photo-sources.json` | **Generated**; photographs + author/licence/file page |
| `assets/product-photos/` | **Generated**; real photos of real products, one folder per type |

## Goals

1. Give the pipeline a reproducible store where ids are known and pinned.
2. Let an operator satisfy the store-side contract (WooCommerce, Yoast SEO, Yikes
   tabs, pretty permalinks, read/write REST key pair, mu-plugin snippet) in one
   command.
3. Make the three-way category agreement exact:
   `NewProduct.category_id` ← the UPS mock ↔ `gcategories_json` ↔ the store.
4. Stay completely separate from the backend and disposable at any time.

## Non-goals

- **Not part of the backend.** The backend never depends on this store at runtime.
- **Not production.** Never given production keys, credentials, or data.
- **Not a real storefront.** It ships a demo theme and a demo catalogue so the
  pipeline runs against a realistic store, but it holds no production content and
  nothing copied from another site's catalogue (see **T8**); the reference store's
  product *URLs* are only ever a test input list.
- **Not a general WordPress project.** It has no product features of its own.
- **Not the home of the pipeline.** All pipeline code lives in
  `D:/UpScale-Back-master`.

## External dependencies

| Dependency | Role | Where configured |
| --- | --- | --- |
| Docker Desktop + Compose v2 | Runs the whole stack | host |
| wordpress.org | Plugin downloads (`woocommerce`, `wordpress-seo`, Yikes) | container internet access |
| The UpScale backend | Drives the pipeline against this store | `../UpScale-Back-master/.env` |
| OpenAI key + Claid token | Required by the backend's `collect`/`process`/`generate` | backend `.env` (not this project) |

## Where to read more

- `README.md` — canonical runbook, verification, troubleshooting, tear down.
- `CLAUDE.md` / `AGENTS.md` — agent instructions.
- `memory-bank/architecture.md` — the stack and id bridge in detail.
- `memory-bank/decisions.md` — durable decisions (T1…Tn).
- `../UpScale-Back-master/docs/woocommerce-site-setup.md` — store requirements.
- `../UpScale-Back-master/memory-bank/decisions.md` — D19, D20.
