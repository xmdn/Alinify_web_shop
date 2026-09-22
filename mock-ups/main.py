"""Minimal stand-in for the external "UPS" category API that UpScale depends on.

UpScale's `CategoryService` calls (see application/services/category_service.py):

    GET  {UPS_API_URL}/categories/get_categories
    GET  {UPS_API_URL}/categories/get_category/{id}
    POST {UPS_API_URL}/categories/update/{id}      (backend posts no body today)

with HTTP Basic auth:  admin:<UPS_API_KEY>

Every category object must expose:

    id, name, slug, keywords, icp

`id` MUST equal the WooCommerce product-category id of the target store, because
`push_product` sends `categories: [{"id": product.category_id}]` verbatim.

Categories are read from a JSON file on every request, so the host can edit
`mock-ups/categories.json` (rewritten by scripts/setup.*) without a restart.
"""

import base64
import json
import os
from typing import Any, Dict, List, Optional

from fastapi import FastAPI, Header, HTTPException, status

app = FastAPI(title="UpScale UPS mock", version="1.0.0")

CATEGORIES_FILE = os.getenv("UPS_MOCK_CATEGORIES", "/app/categories.json")
UPS_KEY = os.getenv("UPS_MOCK_KEY", "mock-ups-key")


def _load() -> List[Dict[str, Any]]:
    if not os.path.isfile(CATEGORIES_FILE):
        return []
    with open(CATEGORIES_FILE, encoding="utf-8") as fh:
        data = json.load(fh)
    return data if isinstance(data, list) else [data]


def _check_auth(authorization: Optional[str]) -> None:
    expected = "Basic " + base64.b64encode(f"admin:{UPS_KEY}".encode()).decode()
    if authorization != expected:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid credentials",
            headers={"WWW-Authenticate": "Basic"},
        )


@app.get("/")
def root() -> Dict[str, Any]:
    return {
        "message": "UPS mock is running!",
        "categories_file": CATEGORIES_FILE,
        "categories": len(_load()),
    }


@app.get("/categories/get_categories")
def get_categories(authorization: Optional[str] = Header(default=None)):
    _check_auth(authorization)
    return _load()


@app.get("/categories/get_category/{category_id}")
def get_category(category_id: int, authorization: Optional[str] = Header(default=None)):
    _check_auth(authorization)
    for item in _load():
        if int(item.get("id", -1)) == category_id:
            return item
    raise HTTPException(status_code=404, detail=f"category {category_id} not found")


@app.post("/categories/update/{category_id}")
def update_category(
    category_id: int,
    payload: Optional[Dict[str, Any]] = None,
    authorization: Optional[str] = Header(default=None),
):
    _check_auth(authorization)
    # The backend currently sends no body (known-issues #4); accept anything so
    # the endpoint still answers 200.
    return {"ok": True, "category_id": category_id, "received": payload or {}}
