#!/usr/bin/env python3
"""Build the test store's catalog inputs from the public storefront.

Produces two files (both are *inputs* to the store, never the store's own
generated artifacts):

  data/catalog.json         the category tree to create in WooCommerce, with the
                            Google product category, keywords and icp taken from
                            the backend's snapshot / our own templates;
  data/source-products.csv  one row per public product page:
                            `<url> <image-url> [<image-url> ...]` — the exact
                            CSV shape `POST /new_products/upload` expects, i.e.
                            the *input list* the UpScale analyzer works through.

Nothing here is copied into the store: only the public taxonomy names/slugs
(used to mirror the catalog structure) and public URLs. Product names,
descriptions and photographs stay on the source site and are never downloaded
into the test store.

Usage:
    python scripts/build_catalog.py
    python scripts/build_catalog.py --base https://beauty-mafia.com.ua --skip-products
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = "https://beauty-mafia.com.ua"
DEFAULT_GOOGLE_CATEGORY = 4508

# The backend keeps a static snapshot of the target store's category ids and
# their Google product categories (infrastructure/api_clients/gpt_data.py). We
# reuse its `g_category` values, keyed by slug, so the test store generates the
# same Google category the production store would.
BACKEND_SNAPSHOT = (
    ROOT.parent / "UpScale-Back-master" / "infrastructure" / "api_clients" / "gpt_data.py"
)

USER_AGENT = "UpScale-test-store-catalog/1.0 (+local integration test)"
CATEGORY_PAGE_SIZE = 100
MAX_IMAGES_PER_ROW = 6


def log(message: str) -> None:
    print(f"[catalog] {message}")


def _force_utf8_stdout() -> None:
    """Windows consoles default to cp1252 and cannot print '→' or Cyrillic."""
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            try:
                reconfigure(encoding="utf-8", errors="replace")
            except (ValueError, OSError):
                pass


_force_utf8_stdout()


def fetch(url: str, timeout: int = 60) -> str:
    request = urllib.request.Request(
        url, headers={"User-Agent": USER_AGENT, "Accept": "application/json,text/xml,*/*"}
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read().decode("utf-8", "replace")


def fetch_categories(base: str) -> list[dict]:
    """All public product categories, in the order the Store API returns them."""
    categories: list[dict] = []
    page = 1
    while True:
        url = (
            f"{base}/wp-json/wc/store/v1/products/categories"
            f"?per_page={CATEGORY_PAGE_SIZE}&page={page}&order=asc"
        )
        try:
            items = json.loads(fetch(url))
        except urllib.error.HTTPError as exc:
            raise SystemExit(f"Could not read categories from {url}: {exc}") from exc
        if not items:
            break
        categories.extend(items)
        log(f"categories page {page}: {len(items)} (running total {len(categories)})")
        if len(items) < CATEGORY_PAGE_SIZE:
            break
        page += 1
    return categories


def parse_backend_gcategories(path: Path) -> dict[str, int]:
    """`{slug: g_category}` from the backend's snapshot, if it is present."""
    if not path.is_file():
        log(f"backend snapshot not found at {path} — falling back to defaults")
        return {}
    text = path.read_text(encoding="utf-8", errors="replace")
    pattern = re.compile(
        r'"([a-z0-9][a-z0-9\-]*)"\s*:\s*\{\s*"category_id"\s*:\s*(\d+)\s*,'
        r'\s*"g_category"\s*:\s*(\d+)\s*\}'
    )
    found = {m.group(1): int(m.group(3)) for m in pattern.finditer(text)}
    log(f"backend snapshot: {len(found)} slug → g_category entries")
    return found


def keywords_for(name: str, parent_name: str) -> str:
    """Our own Ukrainian keyword set — never the source site's copy."""
    parts = [
        f"купити {name.lower()}",
        f"ціна {name.lower()}",
        f"{name.lower()} доставка",
        f"{name.lower()} гарантія",
    ]
    if parent_name:
        parts.append(f"купити {parent_name.lower()}")
    parts.append("обладнання для салону краси")
    return ", ".join(parts)


def icp_for(name: str, top_name: str) -> str:
    """Our own ideal-customer description, used by the pipeline's `process` stage."""
    segment = top_name or "обладнання для б'юті-індустрії"
    return (
        f"Власник або керівник салону краси чи барбершопу в Україні, "
        f"який шукає {name.lower()} для оснащення робочих місць у напрямку "
        f"«{segment}»: порівнює ціну, наявність, гарантію та умови доставки "
        f"перед покупкою."
    )


def build_tree(categories: list[dict], gcategories: dict[str, int]) -> list[dict]:
    """Turn the flat API list into parent-first entries for setup-site.php."""
    by_id = {int(c["id"]): c for c in categories}
    children_of: dict[int, list[dict]] = {}
    for child in categories:
        children_of.setdefault(int(child.get("parent") or 0), []).append(child)

    def is_leaf(node: dict) -> bool:
        node_id = int(node["id"])
        return not any(int(c.get("parent") or 0) == node_id for c in categories)

    def descendant_g_category(node: dict) -> int | None:
        """Most common exact g_category among a branch's descendants.

        The reference site's top-level branches ("Парикмахерские инструменты"…)
        are not in the backend snapshot themselves — only their children are. So
        a branch without an exact match inherits the majority value of the
        children that do have one.
        """
        counts: dict[int, int] = {}
        stack = list(children_of.get(int(node["id"]), []))
        seen: set[int] = set()
        while stack:
            current = stack.pop()
            current_id = int(current["id"])
            if current_id in seen:
                continue
            seen.add(current_id)
            slug = str(current.get("slug") or "")
            if slug in gcategories:
                value = gcategories[slug]
                counts[value] = counts.get(value, 0) + 1
            stack.extend(children_of.get(current_id, []))
        if not counts:
            return None
        return max(counts.items(), key=lambda item: (item[1], -item[0]))[0]

    def resolve_g_category(node: dict) -> tuple[int, str]:
        """Own g_category, else the nearest known ancestor's, else descendants'."""
        current: dict | None = node
        seen: set[int] = set()
        while current is not None:
            node_id = int(current["id"])
            if node_id in seen:
                break
            seen.add(node_id)
            slug = str(current.get("slug") or "")
            if slug in gcategories:
                return gcategories[slug], "backend snapshot" if current is node else "inherited"
            parent_id = int(current.get("parent") or 0)
            current = by_id.get(parent_id)

        from_children = descendant_g_category(node)
        if from_children is not None:
            return from_children, "from descendants"
        return DEFAULT_GOOGLE_CATEGORY, "default"

    def top_level_name(node: dict) -> str:
        current = node
        seen: set[int] = set()
        while True:
            node_id = int(current["id"])
            if node_id in seen:
                break
            seen.add(node_id)
            parent_id = int(current.get("parent") or 0)
            parent = by_id.get(parent_id)
            if parent is None:
                return str(node["name"])
            current = parent

    entries: list[dict] = []
    for node in sorted(by_id.values(), key=lambda n: (len(chain(n, by_id)), int(n["id"]))):
        parent_id = int(node.get("parent") or 0)
        parent = by_id.get(parent_id)
        g_category, g_source = resolve_g_category(node)
        entries.append(
            {
                "source_id": int(node["id"]),
                "name": str(node["name"]),
                "slug": str(node["slug"]),
                "parent_source_id": parent_id if parent else 0,
                "parent_slug": str(parent["slug"]) if parent else "",
                "g_category": g_category,
                "g_category_source": g_source,
                "keywords": keywords_for(str(node["name"]), str(parent["name"]) if parent else ""),
                "icp": icp_for(str(node["name"]), top_level_name(node)),
                # One demo product per category, two for leaves: enough for the
                # storefront grids without turning into a copy of the source site.
                "demo_products": 2 if is_leaf(node) else 1,
            }
        )
    return entries


def chain(node: dict, by_id: dict[int, dict]) -> list[int]:
    out: list[int] = []
    current = node
    seen: set[int] = set()
    while True:
        node_id = int(current["id"])
        if node_id in seen:
            break
        seen.add(node_id)
        out.append(node_id)
        parent = by_id.get(int(current.get("parent") or 0))
        if parent is None:
            return out
        current = parent


SITEMAP_NS = {
    "sm": "http://www.sitemaps.org/schemas/sitemap/0.9",
    "image": "http://www.google.com/schemas/sitemap-image/1.1",
}


def fetch_product_urls(base: str) -> list[tuple[str, list[str]]]:
    """`[(product_url, [image_url, ...]), ...]` from every product sitemap."""
    try:
        index = ET.fromstring(fetch(f"{base}/sitemap.xml"))
    except (urllib.error.URLError, ET.ParseError) as exc:
        log(f"could not read the sitemap index: {exc}")
        return []

    sitemaps = [
        loc.text
        for loc in index.findall(".//sm:sitemap/sm:loc", SITEMAP_NS)
        if loc.text and re.search(r"/product-sitemap\d*\.xml$", loc.text)
    ]
    rows: list[tuple[str, list[str]]] = []
    seen: set[str] = set()
    for sitemap_url in sitemaps:
        try:
            root = ET.fromstring(fetch(sitemap_url))
        except (urllib.error.URLError, ET.ParseError) as exc:
            log(f"skipping {sitemap_url}: {exc}")
            continue
        found = 0
        for url_node in root.findall("sm:url", SITEMAP_NS):
            loc = url_node.findtext("sm:loc", default="", namespaces=SITEMAP_NS)
            if not loc or loc in seen:
                continue
            images = [
                node.text
                for node in url_node.findall("image:image/image:loc", SITEMAP_NS)
                if node.text
            ]
            if not images:
                continue  # `create` rejects a product without images
            seen.add(loc)
            rows.append((loc, images[:MAX_IMAGES_PER_ROW]))
            found += 1
        log(f"{sitemap_url.split('/')[-1]}: {found} product URLs with images")
    return rows


def write_products(rows: list[tuple[str, list[str]]], path: Path, limit: int) -> int:
    if limit:
        rows = rows[:limit]
    lines = [" ".join([url] + images) for url, images in rows]
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return len(lines)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default=DEFAULT_BASE, help="public storefront base URL")
    parser.add_argument(
        "--out",
        default=str(ROOT / "data" / "catalog.json"),
        help="where to write the category tree",
    )
    parser.add_argument(
        "--products-out",
        default=str(ROOT / "data" / "source-products.csv"),
        help="where to write the analyzer's source-product list",
    )
    parser.add_argument("--skip-products", action="store_true", help="categories only")
    parser.add_argument(
        "--max-products",
        type=int,
        default=0,
        help="cap the product list (0 = no cap)",
    )
    return parser.parse_args(argv)
def main(argv: list[str]) -> int:
    args = parse_args(argv)

    categories = fetch_categories(args.base)
    if not categories:
        print("no categories returned — is the site reachable?", file=sys.stderr)
        return 1

    gcategories = parse_backend_gcategories(BACKEND_SNAPSHOT)
    entries = build_tree(categories, gcategories)

    document = {
        "generated_by": "scripts/build_catalog.py",
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "source": f"{args.base}/wp-json/wc/store/v1/products/categories",
        "note": (
            "Structural data only (taxonomy names/slugs) plus our own keywords/icp. "
            "No product names, descriptions or photographs are copied. "
            "Regenerate instead of hand-editing."
        ),
        "default_g_category": DEFAULT_GOOGLE_CATEGORY,
        "category_count": len(entries),
        "categories": entries,
    }

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(
        json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    exact = sum(1 for e in entries if e["g_category_source"] == "backend snapshot")
    inherited = sum(1 for e in entries if e["g_category_source"] == "inherited")
    from_children = sum(1 for e in entries if e["g_category_source"] == "from descendants")
    defaulted = sum(1 for e in entries if e["g_category_source"] == "default")
    log(
        f"wrote {out_path} — {len(entries)} categories "
        f"(g_category: {exact} exact, {inherited} from an ancestor, "
        f"{from_children} from descendants, {defaulted} default)"
    )

    if args.skip_products:
        return 0

    rows = fetch_product_urls(args.base)
    if not rows:
        log("no product URLs collected; leaving the product list untouched")
        return 0
    written = write_products(rows, Path(args.products_out), args.max_products)
    log(f"wrote {args.products_out} — {written} rows for `POST /new_products/upload`")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
