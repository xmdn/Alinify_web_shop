# UpScale — WordPress/WooCommerce Test Store

A local, disposable WooCommerce store used to test the UpScale import pipeline
(`D:/UpScale-Back-master`). This is a **separate project**: it never imports or
modifies the backend, and the backend never writes to this store unless you run
the `push` stage yourself.

## Why a dedicated store

UpScale pushes products through the standard WooCommerce REST API (`wc/v3`).
Testing against the production store is not safe (publication requires explicit
authorization — decision D1), and a production store's ids are not reproducible.
This project gives you a controlled store where the real attribute/category ids
can be read once and pinned into the backend.

## Services

| Service | Image | Host port | Role |
| --- | --- | --- | --- |
| `wp` | `wordpress:6` | **8080** | The store itself |
| `wpdb` | `mysql:8.0` | **3308** | WordPress database (does not clash with UpScale's 3307) |
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
docker compose up -d --build
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
```

Linux/macOS:

```bash
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
Categories readable                            OK 16=Test Category, 15=Uncategorized
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
│   └── categories.json    # rewritten by scripts/setup.*
├── scripts/
│   ├── setup.ps1          # Windows host
│   ├── setup.sh           # Linux/macOS host
│   ├── setup-site.php     # runs inside the container (wp eval-file)
│   ├── verify.py          # OAuth1 store checks
│   └── smoke.ps1          # one product through the UpScale pipeline
├── config/
│   └── mapping.example.json
└── README.md
```

## Related

- `../UpScale-Back-master/docs/woocommerce-site-setup.md` — store requirements, id contracts, verification checklist.
- `../UpScale-Back-master/snippets/README.md` — the mu-plugin snippet.
- `../UpScale-Back-master/docs/known-issues.md` — known defects the test will surface.

