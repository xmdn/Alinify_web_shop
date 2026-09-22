#!/usr/bin/env python3
"""Download real product photographs from Wikimedia Commons for the demo store.

Commons files carry an explicit licence and a required attribution line, so the
storefront can show *real* photographs of real products (clippers, dryers,
chairs, lamps …) without copying another shop's imagery.

Outputs
    assets/product-photos/<keyword>/<n>.jpg   the photographs
    assets/hero/hero-<n>.jpg                  hero banner candidates
    data/photo-sources.json                   attribution record (author/licence/URL)
    data/photo-map.json                       category slug → keyword

Usage
    python scripts/fetch_product_photos.py
    python scripts/fetch_product_photos.py --per-keyword 2
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "data" / "catalog.json"
API = "https://commons.wikimedia.org/w/api.php"

# Wikimedia rejects requests without a descriptive User-Agent.
USER_AGENT = (
    "UpScaleTestStore/1.0 (local WooCommerce demo store; test@example.com) python-urllib"
)

ALLOWED_LICENCES = ("cc0", "public domain", "cc by", "cc by-sa", "cc-by", "cc-by-sa")

# Documents, diagrams and logos that are not product photographs.
TITLE_REJECT = re.compile(
    r"(logo|coat of arms|map|diagram|chart|signature|banknote|poster|screenshot|"
    r"book|patent|drawing|svg|icon|flag|plaque|stamp|menu)",
    re.IGNORECASE,
)

# keyword → Commons search phrases (the first acceptable hits are used)
KEYWORDS: dict[str, list[str]] = {
    "hair_clipper": ["electric hair clipper", "Maszynka do strzyżenia"],
    "trimmer": ["beard trimmer", "Trymer do brody"],
    "shaver": ["electric shaver", "electric razor"],
    "hair_dryer": ["hair dryer", "Suszarka do włosów"],
    "hair_dryer_stand": ["wall mounted hair dryer", "salon hair dryer"],
    "hair_styler": ["hair styling tool", "curling wand", "hot air styler"],
    "straightener": ["hair straightener", "flat iron hair"],
    "curling_iron": ["curling iron", "curling tongs"],
    "curlers": ["hair curlers", "hair rollers"],
    "comb": ["hairbrush", "hair comb"],
    "scissors": ["hair cutting scissors", "hairdressing scissors"],
    "barber_chair": ["barber chair", "barbershop chair"],
    "salon_chair": ["hairdresser chair", "beauty salon chair"],
    "wash_unit": ["shampoo bowl salon", "hair washing basin", "shampoo sink basin"],
    "mirror": ["illuminated mirror", "makeup mirror light"],
    "massage_table": ["massage table", "massage couch"],
    "cosmetic_couch": ["beauty couch", "cosmetology couch"],
    "salon_table": ["manicure table", "salon work table"],
    "salon_trolley": ["hairdressing trolley", "beauty trolley"],
    "salon_cabinet": ["pedestal cabinet furniture", "bedside cabinet"],
    "salon_sofa": ["waiting room sofa", "lounge sofa"],
    "salon_stool": ["bar stool", "hairdresser stool"],
    "reception": ["reception desk", "shop counter desk"],
    "display_case": ["display cabinet glass", "vitrine display case"],
    "manicure_lamp": ["UV nail lamp", "nail lamp"],
    "nail_drill": ["nail drill", "manicure machine nail"],
    "sterilizer": ["autoclave sterilizer", "instrument sterilizer"],
    "extractor": ["nail dust extractor", "manicure dust collector"],
    "wax": ["depilatory wax", "wax hair removal"],
    "wax_heater": ["wax warmer", "wax melting pot"],
    "sugaring": ["sugaring paste", "sugar hair removal"],
    "bath_accessory": ["bath sponge", "loofah bath"],
    "care_product": ["beard oil", "hair care product bottle"],
    "gift": ["gift box present", "gift set box"],
    "spare_part": ["hydraulic cylinder small", "spare parts metal"],
    "accessory": ["salon tools set", "hair clips metal"],
}

# Fallback per catalogue branch (slug → keyword) when a leaf has no keyword.
BRANCH_DEFAULTS = {
    "mebel-dlya-salonov-krasoty-katalog": "salon_chair",
    "manikyur-i-pedikyur": "manicure_lamp",
    "parikmaherskie-instrumenty": "hair_clipper",
    "epilyacziya": "wax",
    "muzhskaya-kosmetika": "care_product",
    "uhod-za-telom": "bath_accessory",
    "tekhnika-daison": "hair_dryer",
    "idei-besproigryshnyh-podarkov": "gift",
    "skidki": "accessory",
}

# Substring → keyword, matched against the (Russian) category names of the mirror.
NAME_RULES: list[tuple[str, str]] = [
    ("стационарн", "hair_dryer_stand"),
    ("машинк", "hair_clipper"),
    ("триммер", "trimmer"),
    ("шейвер", "shaver"),
    ("электробритв", "shaver"),
    ("фен", "hair_dryer"),
    ("плойк", "curling_iron"),
    ("выпрямител", "straightener"),
    ("утюж", "straightener"),
    ("гофре", "hair_styler"),
    ("терморасчес", "hair_styler"),
    ("стайлер", "hair_styler"),
    ("бигуди", "curlers"),
    ("брашинг", "comb"),
    ("расчес", "comb"),
    ("щетк", "comb"),
    ("ножниц", "scissors"),
    ("кресл", "salon_chair"),
    ("мойк", "wash_unit"),
    ("зеркал", "mirror"),
    ("массажн", "massage_table"),
    ("кушетк", "cosmetic_couch"),
    ("столик", "salon_table"),
    ("стол", "salon_table"),
    ("тележк", "salon_trolley"),
    ("тумбочк", "salon_cabinet"),
    ("диван", "salon_sofa"),
    ("стул", "salon_stool"),
    ("ресепшн", "reception"),
    ("витрин", "display_case"),
    ("лампа", "manicure_lamp"),
    ("фрезер", "nail_drill"),
    ("стерилизатор", "sterilizer"),
    ("вытяжк", "extractor"),
    ("воскоплав", "wax_heater"),
    ("воск", "wax"),
    ("шугаринг", "sugaring"),
    ("мочалк", "bath_accessory"),
    ("масл", "care_product"),
    ("уход", "care_product"),
    ("средств", "care_product"),
    ("подар", "gift"),
    ("запчаст", "spare_part"),
    ("аксессуар", "accessory"),
]

HERO_QUERIES = ["hair salon interior", "barbershop interior", "beauty salon interior"]


def log(message: str) -> None:
    print(f"[photos] {message}")


def force_utf8_stdout() -> None:
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            try:
                reconfigure(encoding="utf-8", errors="replace")
            except (ValueError, OSError):
                pass


def api(params: dict, retries: int = 3) -> dict:
    """Call the Commons API, retrying transient network failures."""
    query = urllib.parse.urlencode(params)
    request = urllib.request.Request(
        f"{API}?{query}", headers={"User-Agent": USER_AGENT, "Accept": "application/json"}
    )
    for attempt in range(1, retries + 1):
        try:
            with urllib.request.urlopen(request, timeout=45) as response:
                return json.loads(response.read().decode("utf-8", "replace"))
        except (urllib.error.URLError, json.JSONDecodeError) as error:
            if attempt == retries:
                log(f"API failed: {error}")
                return {}
            time.sleep(2 * attempt)
    return {}


def strip_html(value: str) -> str:
    return re.sub(r"<[^>]+>", "", value or "").strip()


def search_files(phrase: str, limit: int) -> list[dict]:
    """Search Commons for bitmap files and return their imageinfo records."""
    data = api(
        {
            "action": "query",
            "format": "json",
            "generator": "search",
            "gsrsearch": f"{phrase} filetype:bitmap",
            "gsrnamespace": "6",
            "gsrlimit": str(limit),
            "prop": "imageinfo",
            "iiprop": "url|mime|size|extmetadata",
            "iiurlwidth": "1200",
        }
    )
    pages = (data.get("query") or {}).get("pages") or {}
    return list(pages.values())


def acceptable(page: dict) -> dict | None:
    """Normalise a file when it is a usable, licensed, reasonably large photo."""
    title = str(page.get("title") or "")
    if not title or TITLE_REJECT.search(title):
        return None

    info = (page.get("imageinfo") or [{}])[0]
    if str(info.get("mime") or "") not in ("image/jpeg", "image/png"):
        return None
    if int(info.get("width") or 0) < 700:
        return None

    meta = info.get("extmetadata") or {}
    license_name = strip_html((meta.get("LicenseShortName") or {}).get("value", ""))
    if not license_name or not any(token in license_name.lower() for token in ALLOWED_LICENCES):
        return None

    download_url = str(info.get("thumburl") or info.get("url") or "")
    if not download_url:
        return None

    return {
        "title": title,
        "author": strip_html((meta.get("Artist") or {}).get("value", ""))[:120] or "невідомий",
        "license": license_name,
        "license_url": strip_html((meta.get("LicenseUrl") or {}).get("value", "")),
        "page_url": str(info.get("descriptionurl") or ""),
        "download": download_url,
        "mime": str(info.get("mime") or ""),
        "width": int(info.get("width") or 0),
        "height": int(info.get("height") or 0),
        "description": strip_html((meta.get("ImageDescription") or {}).get("value", ""))[:200],
    }


def download(url: str, target: Path) -> bool:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            payload = response.read()
    except urllib.error.URLError as error:
        log(f"download failed ({error}): {url[:70]}")
        return False
    if len(payload) < 4096:
        return False
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(payload)
    return True


def extension_for(mime: str) -> str:
    return ".png" if mime == "image/png" else ".jpg"


def collect_keyword(keyword: str, phrases: list[str], per_keyword: int) -> list[dict]:
    """The first `per_keyword` acceptable photos for a keyword."""
    found: list[dict] = []
    seen: set[str] = set()

    for phrase in phrases:
        for page in search_files(phrase, 12):
            record = acceptable(page)
            if record is None or record["title"] in seen:
                continue
            seen.add(record["title"])
            record["keyword"] = keyword
            found.append(record)
            if len(found) >= per_keyword:
                return found
        if found:
            break
    return found


def keyword_for(name: str) -> str | None:
    lowered = (name or "").lower()
    for needle, keyword in NAME_RULES:
        if needle in lowered:
            return keyword
    return None


def build_category_map(catalog: dict) -> dict[str, str]:
    """category slug → keyword (leaves match by name; ancestors inherit in PHP)."""
    mapping: dict[str, str] = {}
    for entry in catalog.get("categories", []):
        slug = str(entry.get("slug") or "")
        if not slug:
            continue
        keyword = keyword_for(str(entry.get("name") or "")) or BRANCH_DEFAULTS.get(slug)
        if keyword:
            mapping[slug] = keyword
    return mapping


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--per-keyword", type=int, default=3, help="photos per keyword")
    parser.add_argument("--heroes", type=int, default=2, help="hero banner candidates")
    parser.add_argument("--skip-hero", action="store_true")
    parser.add_argument(
        "--only",
        default="",
        help="comma-separated keywords to refresh; the rest of the record is kept",
    )
    parser.add_argument("--photos-out", default=str(ROOT / "assets" / "product-photos"))
    parser.add_argument("--hero-out", default=str(ROOT / "assets" / "hero"))
    parser.add_argument("--sources-out", default=str(ROOT / "data" / "photo-sources.json"))
    parser.add_argument("--map-out", default=str(ROOT / "data" / "photo-map.json"))
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    force_utf8_stdout()
    args = parse_args(argv)

    if not CATALOG.is_file():
        print(f"{CATALOG} is missing — run scripts/build_catalog.py first", file=sys.stderr)
        return 1

    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    photos_dir = Path(args.photos_out)
    hero_dir = Path(args.hero_out)

    only = [item.strip() for item in (args.only or "").split(",") if item.strip()]
    keywords = {name: phrases for name, phrases in KEYWORDS.items() if not only or name in only}

    sources: dict[str, list[dict]] = {}
    for keyword, phrases in keywords.items():
        records = collect_keyword(keyword, phrases, max(1, args.per_keyword))
        kept: list[dict] = []
        for index, record in enumerate(records, 1):
            target = photos_dir / keyword / f"{index}{extension_for(record['mime'])}"
            if not download(record["download"], target):
                continue
            entry = {k: v for k, v in record.items() if k != "download"}
            entry["file"] = str(target.relative_to(ROOT)).replace("\\", "/")
            kept.append(entry)
            log(f"{keyword}: {entry['file']} — {entry['license']}")
        if not kept:
            log(f"{keyword}: nothing usable")
        sources[keyword] = kept

    heroes: list[dict] = []
    if not args.skip_hero:
        for phrase in HERO_QUERIES:
            for page in search_files(phrase, 10):
                record = acceptable(page)
                if record is None:
                    continue
                target = hero_dir / f"hero-{len(heroes) + 1}{extension_for(record['mime'])}"
                if not download(record["download"], target):
                    continue
                entry = {k: v for k, v in record.items() if k != "download"}
                entry["file"] = str(target.relative_to(ROOT)).replace("\\", "/")
                heroes.append(entry)
                log(f"hero: {entry['file']} — {entry['license']}")
                if len(heroes) >= args.heroes:
                    break
            if len(heroes) >= args.heroes:
                break

    # A targeted run (--only) merges into the existing record instead of dropping
    # the other keywords and the hero banners.
    sources_path = Path(args.sources_out)
    if only and sources_path.is_file():
        try:
            previous = json.loads(sources_path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, OSError):
            previous = {}
        merged = dict(previous.get("keywords") or {})
        merged.update(sources)
        sources = merged
        if not heroes:
            heroes = list(previous.get("heroes") or [])

    generated_at = datetime.now(timezone.utc).isoformat(timespec="seconds")
    document = {
        "generated_by": "scripts/fetch_product_photos.py",
        "generated_at": generated_at,
        "source": "Wikimedia Commons (https://commons.wikimedia.org)",
        "note": (
            "Photographs of real products under CC0 / public domain / CC BY / CC BY-SA. "
            "Attribution is mandatory for the CC BY and CC BY-SA files and is published "
            "on the store's \"Джерела зображень\" page."
        ),
        "per_keyword": args.per_keyword,
        "keywords": sources,
        "heroes": heroes,
        "category_keywords": build_category_map(catalog),
    }

    sources_path = Path(args.sources_out)
    sources_path.parent.mkdir(parents=True, exist_ok=True)
    sources_path.write_text(
        json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    log(f"wrote {sources_path}")

    mapping_path = Path(args.map_out)
    mapping_path.write_text(
        json.dumps(
            {
                "generated_by": "scripts/fetch_product_photos.py",
                "generated_at": generated_at,
                "category_keywords": document["category_keywords"],
                "branch_defaults": BRANCH_DEFAULTS,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    log(f"wrote {mapping_path}")

    total = sum(len(items) for items in sources.values())
    log(f"downloaded {total} product photos + {len(heroes)} hero candidates")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
