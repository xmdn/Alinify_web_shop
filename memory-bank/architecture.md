# UpScale WP Test Store — Architecture

> Derived from `docker-compose.yml`, `scripts/` (`setup.ps1`, `setup.sh`,
> `setup-site.php`, `verify.py`, `smoke.ps1`), `mock-ups/`, and `config/`. This is
> a map, not a replacement for the files.

## Runtime — the Docker Compose stack

Four services on one bridge network (`upscale_test_network`), project name
`upscale-wp-test`:

| Service | Image | Host port | Responsibility |
| --- | --- | --- | --- |
| `wp` | `wordpress:7.1` | `8080:80` | The store. `WORDPRESS_DEBUG=1`; volume `wp_data` → `/var/www/html` |
| `wpdb` | `mysql:8.4` | `3308:3306` | WordPress DB (`wordpress` / `wpuser` / `WpTest__23`); volume `wp_db`; `mysqladmin ping` healthcheck |
| `cli` | `wordpress:cli` | — | WP-CLI, kept alive with `tail -f /dev/null`, runs as uid `33:33`; mounts `wp_data` **and** the project root at `/work` |
| `ups-mock` | built from `./mock-ups` | `9000:9000` | FastAPI stand-in for the "UPS" category API; mounts `mock-ups/categories.json` read-only |

`wordpress:7.1` is required: WooCommerce 11 needs WordPress 7.0+, so the older
`wordpress:6` image fails. Host port `3308` is chosen so it does not clash with
the backend's MySQL on `3307`.

The `cli` container mounts the **project root at `/work`** specifically so
`wp eval-file /tmp/setup-site.php` can write `config/mapping.json` and
`mock-ups/categories.json` with the store's real ids — the container writes into
the host working tree.

## The setup path

```
docker compose up -d --build
        │
setup.ps1  (Windows)  /  setup.sh  (Linux/macOS)
        │  docker compose exec -T cli wp ...
        ├─ wait for /var/www/html/wp-config.php
        ├─ wp core install  (URL http://localhost:8080, admin/admin123)
        ├─ wp plugin install --activate: woocommerce, wordpress-seo,
        │     yikes-inc-easy-custom-woocommerce-product-tabs
        ├─ wp rewrite structure '/%postname%/' --hard   (REST 404s without this)
        ├─ docker compose cp ../UpScale-Back-master/snippets/upscale-woocommerce.php
        │     → /var/www/html/wp-content/mu-plugins/
        └─ wp eval-file scripts/setup-site.php  (env passed inline)
                 ├─ register the required global attributes (mu-plugin Section A)
                 ├─ ensure the test product_cat
                 ├─ insert a read/write WooCommerce REST key pair
                 └─ write /work/config/mapping.json + /work/mock-ups/categories.json
```

`setup-site.php` prints a JSON summary on stdout; the host script parses it and
prints the `.env` block to paste into `../UpScale-Back-master/.env`.

## The id bridge (the reason this project exists)

The backend keeps two static snapshots of one production store in
`infrastructure/api_clients/gpt_data.py`:

- `PromptData.actual_attributes` — attribute `id` ↔ `name`
  (`Производители`, `Бренд`, `mpn`, `gcategory` mandatory).
- `PromptData.gcategories_json` — category id → Google product category.

A fresh store has different ids, so on an unmodified backend the generated
attributes fall back to `"id": 0` (per-product custom attributes) and `gcategory`
comes out empty.

The bridge, without touching production values: set `UPSCALE_MAPPING_FILE` to the
generated `config/mapping.json`; the backend's `apply_store_mapping_override()`
then replaces `actual_attributes`, `spec_example`, and `gcategories_json`.
**Unset → production is unchanged.**

The three-way category agreement that must hold:

```
NewProduct.category_id  ←  the UPS mock (mock-ups/categories.json)
                        →  PromptData.gcategories_json (config/mapping.json)
                        →  WooCommerce product_cat id (the test store)
```

See backend decisions **D19** (this project + override) and **D20** (auth).

## The UPS mock (`mock-ups/main.py`)

A FastAPI app implementing the endpoints `CategoryService` calls:

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/` | Health/info |
| `GET` | `/categories/get_categories` | Full list |
| `GET` | `/categories/get_category/{id}` | One category (404 if absent) |
| `POST` | `/categories/update/{id}` | Accepts anything (the backend posts no body today) |

Auth is **HTTP Basic `admin:<UPS_API_KEY>`** (`UPS_MOCK_KEY=mock-ups-key`). Each
category exposes `id, name, slug, keywords, icp`. The `id` **must equal** the
store's `product_cat` id, because `push_product` sends
`categories: [{"id": product.category_id}]` verbatim. The file is re-read on
every request, so it can change without a restart.

## Generated artifacts (never hand-edit)

| File | Shape | Written by |
| --- | --- | --- |
| `config/mapping.json` | `actual_attributes`, `spec_example`, `gcategories_json` | `setup-site.php` into `/work/config/` |
| `mock-ups/categories.json` | list of `{id, name, slug, keywords, icp}` | `setup-site.php` into `/work/mock-ups/` |

`config/mapping.json` is git-ignored (as is `.env`). Only
`config/mapping.example.json` is a stable reference to the expected shape.

## Verification and smoke

- `scripts/verify.py` — signs with **OAuth 1.0a** (like the backend), so it works
  over plain HTTP where `curl -u` returns 401 (backend D20). Checks REST +
  credentials, global attributes (incl. `Бренд`, `Производители`, `mpn`,
  `gcategory`), categories, and write scope (create draft + delete).
- `scripts/smoke.ps1` — health, signup/login, categories via the mock, then
  `create → collect → process → generate`, printing `data_all`; `push` only with
  `-Push`.

## Networking — what the backend's `WC_API_URL` must be

| Where the UpScale API runs | `WC_API_URL` | `UPS_API_URL` |
| --- | --- | --- |
| On the host (`uvicorn main:app`) | `http://localhost:8080/` | `http://localhost:9000` |
| In Docker | `http://host.docker.internal:8080/` | `http://host.docker.internal:9000` |

## Not wired in / boundaries

- This project has **no git repository** and **no test suite**.
- `scripts/setup.sh` is CRLF-terminated and therefore unreliable under Git Bash
  on Windows; `scripts/setup.ps1` is the Windows path (see `activeContext.md`).
- The backend's live-fetch fix (its `docs/woocommerce-site-setup.md` §7) is out of
  scope here; the `UPSCALE_MAPPING_FILE` override is a test enabler, not that fix.
- There is no automated way to keep the generated ids in sync if the store is
  rebuilt; re-run `scripts/setup.*` after `docker compose down -v`.
