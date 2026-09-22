# Testing the UpScale product against this test store

> Runbook for the end-to-end test of the UpScale import pipeline
> (`D:/UpScale-Back-master`) against the disposable WooCommerce store in this
> repository. Companion documents: `../README.md` (the store runbook),
> `../memory-bank/architecture.md` (how the id bridge works), and the backend's
> own `docs/quickstart.md`, `docs/pipeline.md`, `docs/environment.md`.

## 0. What is already in place

| Piece | Where | State |
| --- | --- | --- |
| WordPress/WooCommerce store | <http://localhost:8080> (admin `admin` / `admin123`) | 174 categories, 304 demo products with real photographs, Ukrainian UI |
| "UPS" category API mock | <http://localhost:9000> (Basic `admin:mock-ups-key`) | 174 categories, each with `icp` and `keywords` |
| Generated id artifacts | `config/mapping.json`, `mock-ups/categories.json` | built from the store's **real** ids (T2/T6) |
| Analyzer input list | `data/source-products.csv` | 2944 rows of `product URL + image URLs` (the `POST /new_products/upload` format) |
| Products to import (target) | the same store | imported products land as drafts unless `push` is authorized |

```powershell
docker compose ps          # wp, wpdb, cli, ups-mock must all be "Up"
```

If the stack is not running: `docker compose up -d --build` and then
`powershell -ExecutionPolicy Bypass -File scripts/setup.ps1`.

## 1. Get the store credentials (once per setup run)

The WooCommerce REST key pair is **regenerated on every `setup.ps1` run**, so the
secret you pasted last time may already be stale. To print a fresh pair without
touching the catalogue:

```powershell
cd D:\UpScale-WP-Test
powershell -ExecutionPolicy Bypass -File scripts\setup.ps1 -SkipCatalog -SkipPhotos
```

Copy the `.env` block the script prints at the end
(`WC_API_URL`, `WC_API_KEY`, `WC_API_SECRET`, `UPS_API_URL`, `UPS_API_KEY`,
`UPSCALE_MAPPING_FILE`).

## 2. Point the backend at this store

Edit `D:\UpScale-Back-master\.env`:

```env
# Credentials you must supply yourself
GPT_API_KEY=sk-…            # OpenAI; used by collect / process / generate
CLAID_TOKEN=…               # Claid.ai; MUST have credits, see the blocker note

# The test store (values printed by scripts/setup.ps1)
WC_API_URL=…
WC_API_KEY=…
WC_API_SECRET=…

# The "UPS" mock
UPS_API_URL=…
UPS_API_KEY=mock-ups-key

# The id bridge (see below — the path must be readable by the backend process)
UPSCALE_MAPPING_FILE=…

# Backend infrastructure (see ../UpScale-Back-master/docs/environment.md)
DB_HOST=db
DB_PORT=3306
DB_NAME=upscaledb
DB_USER=admin
DB_PASSWORD=UpScale__23
REDIS_URL=redis://my_redis:6379/0
ALGORITHM=HS256
SECRET_KEY=…
```

### 2.1 ⚠️ Networking — the backend cannot reach the store by `localhost` from Docker

The store stack (`upscale_test_network`) and the backend stack
(`upscale_network`) are **separate Docker networks**. Use:

| Where the backend runs | `WC_API_URL` | `UPS_API_URL` |
| --- | --- | --- |
| In Docker (`my_fastapi_app`) | `http://host.docker.internal:8080/` | `http://host.docker.internal:9000` |
| On the host (`uvicorn main:app`) | `http://localhost:8080/` | `http://localhost:9000` |

### 2.2 ⚠️ `UPSCALE_MAPPING_FILE` must be readable *by the backend process*

`gpt_data.apply_store_mapping_override()` reads the variable, calls
`os.path.isfile()` and **silently returns `False`** when the file is not there.
With the override off, this store's output degrades exactly as documented:

- mandatory attributes arrive as `"id": 0` → WooCommerce creates **per-product
  custom** attributes instead of global/filterable ones;
- `gcategory` comes out **empty** (no category id matches the production
  snapshot).

- **Backend on the host** → `UPSCALE_MAPPING_FILE=D:/UpScale-WP-Test/config/mapping.json`
  works as printed.
- **Backend in Docker** (the default `docker compose up`) → the file is *not*
  mounted. Add a read-only mount to the `app` **and** `celery_worker` services in
  `D:\UpScale-Back-master\docker-compose.yml`:

  ```yaml
      volumes:
        - D:/UpScale-WP-Test/config:/work/config:ro
  ```

  and use the in-container path:

  ```env
  UPSCALE_MAPPING_FILE=/work/config/mapping.json
  ```

  This edit belongs to the backend repository — make it deliberately, following
  that project's own rules. The alternative is to run `uvicorn` + the Celery
  worker on the host instead of in Docker.

## 3. Start the backend

```powershell
cd D:\UpScale-Back-master
docker compose up --build          # add -d to run it in the background
curl.exe http://localhost:8000/    # {"message":"API is running!"}
```

The Celery worker's healthcheck can report `unhealthy` while the API is fine —
a successful API response is the readiness signal (`docs/quickstart.md` §3).

## 4. Optional: verify the store itself first

```powershell
python D:\UpScale-WP-Test\scripts\verify.py --url http://localhost:8080 --key ck_… --secret cs_…
```

Expected: `All checks passed.` (REST over OAuth 1.0a, the four global
attributes, the category list, and a create+delete write test). Do **not** use
`curl -u ck:cs` against this store: Basic auth is only attempted over HTTPS, so
it returns `401` even with valid credentials (see T3).

## 5. Smoke test — one product, no publication

Take one row from `data/source-products.csv` (a competitor URL plus its image
URLs — that is the pipeline's real input):

```powershell
cd D:\UpScale-WP-Test
powershell -ExecutionPolicy Bypass -File scripts\smoke.ps1 `
  -Api http://localhost:8000 `
  -Url "https://beauty-mafia.com.ua/…/product/" `
  -Images "https://beauty-mafia.com.ua/wp-content/uploads/…1.jpg","…2.jpg"
```

The script signs up / logs in by itself (pass `-Token <jwt>` to reuse a session,
`-CategoryId <id>` to pin a category instead of letting it pick the first one)
and drives:

```
create → collect → process → generate → data_all
```

It **stops before `push`** (T5 / backend D1).

What each stage should do against this store:

| Stage | Expected result |
| --- | --- |
| `create` | `status 0`, returns the new product id; duplicate URLs are rejected |
| `collect` | fetches the competitor page, GPT extracts SKU, price, name, description, specifications (the backend container needs outbound internet) |
| `process` | uploads every image to Claid (WebP, 1600×1600) and writes `meta_title` / `meta_description` / bullet points. **Fails without Claid credits — a blocker, not a bug (D3)** |
| `generate` | writes the HTML description and the attribute list. Check `data_all`: `Бренд` / `Производители` / `mpn` / `gcategory` must come back as **global** (ids `1`/`2`/`3`/`4` on this store, never `0`) and `gcategory` must not be empty |
| `data_all` | prints the collected + processed + generated records |

## 6. Full run including publication (explicit authorization only)

Re-run the same command with `-Push`:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\smoke.ps1 -Token $jwt `
  -Url "…" -Images "…" -Push
```

Then inspect the result in wp-admin → **Товари**:

- status and stock as set by `collect` (`draft` unless it was changed);
- images are the Claid-hosted WebP files;
- **Yoast SEO** fields (title, meta description) are populated;
- the **Yikes** "Доставка і оплата" tab renders;
- attributes are global (a filterable brand, not a per-product custom row) and
  `gcategory` carries the Google product category number.

## 7. Bulk automation — the actual product test

`data/source-products.csv` is already in the format the batch endpoint expects:
one row per product, `url` first, then the image URLs separated by whitespace.
Start with a slice so a mistake costs ten products, not three thousand:

```powershell
cd D:\UpScale-WP-Test
Get-Content data\source-products.csv -TotalCount 10 | Set-Content data\slice10.csv
```

Pick a category id. **It must be one of the mock's ids** — they are the store's
own `product_cat` ids, and the same id must appear in
`mock-ups/categories.json` and `config/mapping.json` (that three-way agreement is
what this repository exists to guarantee):

```powershell
curl.exe -s -u "admin:mock-ups-key" http://localhost:9000/categories/get_categories
```

Sign in and keep the token (it lives 30 minutes):

```powershell
$body = '{"email":"upscale-test@example.com","password":"test12345"}'
curl.exe -s -X POST http://localhost:8000/auth/signup -H "Content-Type: application/json" -d $body
$login = curl.exe -s -X POST http://localhost:8000/auth/login -H "Content-Type: application/json" -d $body | ConvertFrom-Json
$TOKEN = $login.access_token
```

Upload the slice:

```powershell
curl.exe -X POST "http://localhost:8000/new_products/upload?category_id=<ID>" `
  -H "Authorization: Bearer $TOKEN" `
  -F "file=@D:\UpScale-WP-Test\data\slice10.csv"
```

The response reports `{status: {created, exist}, user_products}` — duplicates are
counted as `exist`, not as failures.

Then enqueue the mass stages **in order** and follow each `task_id`:

```powershell
curl.exe -X POST http://localhost:8000/new_products/collect_all  -H "Authorization: Bearer $TOKEN"
curl.exe -X POST http://localhost:8000/new_products/process_all  -H "Authorization: Bearer $TOKEN"
curl.exe -X POST http://localhost:8000/new_products/generate_all -H "Authorization: Bearer $TOKEN"

curl.exe http://localhost:8000/tasks/<task_id> -H "Authorization: Bearer $TOKEN"
# live progress: ws://localhost:8000/ws/tasks/<task_id> with the same bearer token
```

Each task selects the rows left at the previous status, so a retry resumes where
the run stopped. Only when you explicitly authorize publication:

```powershell
curl.exe -X POST http://localhost:8000/new_products/push_all -H "Authorization: Bearer $TOKEN"
```

Once the ten-product slice behaves, repeat with the full file (2944 rows) — expect
a long run: every product costs one page fetch, one GPT call for `collect`, one
Claid upload per image plus one GPT call for `process`, and one GPT call for
`generate`.

## 8. Success checklist

- `scripts/verify.py` → `All checks passed.`
- After `generate`, `data_all` shows global attribute ids (`1`/`2`/`3`/`4` here,
  never `0`) and a non-empty `gcategory`.
- After `push`, the product exists in wp-admin with Claid images, Yoast fields and
  the Yikes tab.
- The storefront product page renders (<http://localhost:8080>) — the imported
  product appears alongside the 304 demo products.

## 9. Known pitfalls (and what they are not)

| Symptom | Cause / action |
| --- | --- |
| `404` on every `wc/v3` call | Permalinks reverted to "Plain": re-run `scripts/setup.ps1` |
| `401` from the REST API | Stale key pair (they rotate every setup run) — re-run the setup and re-paste `.env` |
| Attributes come back as custom (`id: 0`) | `UPSCALE_MAPPING_FILE` is unset or the backend cannot read the file (§2.2) |
| `gcategory` is empty | Same override problem, or the category id is not in `gcategories_json` |
| `process` stops with a Claid error | No Claid credits — a blocker (D3), do not bypass image processing |
| `collect` cannot fetch the page | The competitor page must be reachable **from the backend container** |
| `500` with `already exist` on `create` | The URL is already in the local database; the CSV importer reports it as `exist` |
| `update_category` appears to do nothing | Known backend defect (its `docs/known-issues.md` #4) — not a store problem |
| The API is unreachable but Celery is "unhealthy" | Expected; treat a successful `GET /` as readiness |

## 10. Safety rules

- **`push` is never implicit.** `smoke.ps1` stops before it; `push_all` is a
  separate call. Publishing requires your explicit authorization for that step.
- **Never** run `docker compose down -v` in either stack: it destroys the store
  (and the backend's database) and irrecoverably invalidates the generated ids.
- Never point this store at production keys or a real store's data — it is
  disposable by design.
- Claid and category metadata are never bypassed to make a stage pass.

## Related

- `../README.md` — the store's own runbook (services, setup, verification, teardown).
- `../memory-bank/architecture.md` — the id bridge and the setup path.
- `../memory-bank/decisions.md` — T1–T13 (why the store is shaped this way).
- `../data/source-products.csv` — the import list (regenerate with
  `python scripts/build_catalog.py`).
- `../UpScale-Back-master/docs/quickstart.md`, `docs/pipeline.md`,
  `docs/api-endpoints.md`, `docs/environment.md`, `docs/known-issues.md`.
