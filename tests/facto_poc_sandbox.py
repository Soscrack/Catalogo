#!/usr/bin/env python3
"""PoC sandbox FACTO Chile: auth, catalogs, POST product, PUT name+sku vs full body."""
from __future__ import annotations

import json
import ssl
import uuid
import urllib.error
import urllib.request
from pathlib import Path

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"
)
BASE = "https://apifacto.com/v1"
CREDS = {
    "grant_type": "password",
    "client_id": "demo/001",
    "client_secret": "ad258748356c5104df2bf4bdbabd3352",
    "username": "1.111.111-1/demoapi",
    "password": "76be37bcc4970d29e519fca46edead19",
}
OUT = Path(__file__).with_name("facto_poc_results.json")


def req(url: str, method: str = "GET", data=None, token: str | None = None):
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": UA,
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    body = None if data is None else json.dumps(data).encode("utf-8")
    request = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(
            request, timeout=90, context=ssl.create_default_context()
        ) as resp:
            raw = resp.read().decode("utf-8", "replace")
            try:
                parsed = json.loads(raw) if raw else None
            except json.JSONDecodeError:
                parsed = None
            return resp.status, parsed, raw
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", "replace")
        try:
            parsed = json.loads(raw) if raw else None
        except json.JSONDecodeError:
            parsed = None
        return exc.code, parsed, raw


def embedded(payload, key: str):
    if not isinstance(payload, dict):
        return []
    return (payload.get("_embedded") or {}).get(key) or []


def main():
    results = {"base": BASE, "steps": []}

    code, data, raw = req(f"{BASE}/auth", "POST", CREDS)
    results["steps"].append({"step": "auth", "status": code, "ok": code == 200})
    if code != 200:
        OUT.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
        print("AUTH FAILED", code, raw[:400])
        return
    token = data["access_token"]
    print("AUTH OK")

    catalogs = {}
    for path in [
        "product_categories",
        "product_locations",
        "product_price_lists",
        "company_branches",
    ]:
        code, data, raw = req(f"{BASE}/{path}", token=token)
        items = embedded(data, path)
        catalogs[path] = {
            "status": code,
            "count": len(items),
            "sample": items[:3],
        }
        print(path, code, "count", len(items))
        if items:
            print("  sample keys", list(items[0].keys()))
    results["catalogs"] = catalogs

    price_list_id = 1
    location_id = 1
    if catalogs["product_price_lists"]["sample"]:
        sample = catalogs["product_price_lists"]["sample"][0]
        price_list_id = (
            sample.get("product_price_list_id")
            or sample.get("price_list_id")
            or 1
        )
    if catalogs["product_locations"]["sample"]:
        sample = catalogs["product_locations"]["sample"][0]
        location_id = (
            sample.get("product_location_id")
            or sample.get("location_id")
            or 1
        )
    print("using price_list_id", price_list_id, "location_id", location_id)

    sku = "RIVERSO-POC-" + uuid.uuid4().hex[:8].upper()
    create_payload = {
        "name": "Riverso PoC API Test",
        "invoicing_details": "",
        "additional_details": "poc riverso",
        "model": "POC",
        "brand": "RIVERSO",
        "status": "1",
        "sku": sku,
        "barcode": "7809999000123",
        "measure_unit": "UN",
        "type": "1",
        "favorite": 0,
        "product_category_id": None,
        "cost": {"currency_id": 39, "value": 1000},
        "price": [
            {
                "price_list_id": str(price_list_id),
                "unit_net": 1680.672269,
                "unit_tax": 319.327731,
                "unit_total": 2000,
                "currency_id": "39",
                "sales_commission_percentage": None,
                "taxes": [
                    {
                        "tax_type_id": "387",
                        "tax_percentage": 19,
                        "tax_amount": 319.327731,
                    }
                ],
            }
        ],
        "inventories": {
            "details": [
                {
                    "product_location_id": location_id,
                    "available_quantity": 5,
                }
            ],
            "total_available": 5,
            "total_reserved": 0,
        },
    }

    # Also try newer API field names if needed
    create_alt = {
        "name": "Riverso PoC API Test",
        "invoicing_details": "",
        "additional_details": "poc riverso",
        "model": "POC",
        "brand": "RIVERSO",
        "status": 1,
        "sku": sku,
        "barcode": "7809999000123",
        "measure_unit": "UN",
        "type": 1,
        "embedded_quantity_barcode": 0,
        "embedded_quantity_barcode_decimals": 0,
        "favorite": 0,
        "product_category_id": 0,
        "cost": {"currency_id": 39, "value": 1000},
        "price": [
            {
                "price_list_id": price_list_id,
                "unit_net": 1680.672269,
                "unit_tax": 319.327731,
                "unit_total": 2000,
                "currency_id": 39,
                "sales_commission_percentage": 0,
                "taxes": [
                    {
                        "tax_type_id": "387",
                        "tax_percentage": 19,
                        "tax_amount": 319.327731,
                    }
                ],
            }
        ],
        "inventories": {
            "total_available": 5,
            "total_reserved": 0,
            "details": [
                {
                    "product_location_id": str(location_id),
                    "available_quantity": 5,
                    "reserved_quantity": 0,
                }
            ],
        },
    }

    code, data, raw = req(f"{BASE}/products", "POST", create_payload, token)
    print("POST(legacy schema)", code, raw[:500])
    results["steps"].append(
        {
            "step": "post_legacy",
            "status": code,
            "sku": sku,
            "response": data if data is not None else raw[:1000],
        }
    )

    if code not in (200, 201):
        code, data, raw = req(f"{BASE}/products", "POST", create_alt, token)
        print("POST(alt schema)", code, raw[:500])
        results["steps"].append(
            {
                "step": "post_alt",
                "status": code,
                "sku": sku,
                "response": data if data is not None else raw[:1000],
            }
        )

    product_id = None
    if isinstance(data, dict):
        product_id = data.get("product_id")
        if not product_id:
            emb = embedded(data, "products")
            if emb:
                product_id = emb[0].get("product_id")

    if not product_id:
        # lookup by sku
        code, data, raw = req(f"{BASE}/products?sku={sku}", token=token)
        emb = embedded(data, "products")
        if emb:
            product_id = emb[0].get("product_id")
            print("Resolved via GET?sku", product_id)
            results["steps"].append({"step": "resolve_sku", "status": code, "product": emb[0]})

    print("product_id", product_id)
    results["product_id"] = product_id
    results["sku"] = sku

    if not product_id:
        OUT.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
        print("ABORT: no product_id")
        return

    # Snapshot before PUT
    code, before, raw = req(f"{BASE}/products/{product_id}", token=token)
    results["before_put"] = before
    print("GET before", code)

    # PUT A: only name + sku (documented)
    sku_a = sku + "-A"
    put_a = {"name": "Riverso PoC UPDATED name only", "sku": sku_a}
    code, data, raw = req(f"{BASE}/products/{product_id}", "PUT", put_a, token)
    print("PUT A (name+sku)", code, raw[:400])
    results["steps"].append({"step": "put_name_sku", "status": code, "response": data or raw[:800]})

    code, after_a, raw = req(f"{BASE}/products/{product_id}", token=token)
    results["after_put_a"] = after_a
    print("GET after A", code)

    # PUT B: full body attempt
    put_b = {
        "name": "Riverso PoC FULL UPDATE",
        "invoicing_details": "invoice detail updated",
        "additional_details": "additional updated",
        "model": "POC-FULL",
        "brand": "RIVERSO-FULL",
        "status": 1,
        "sku": sku + "-B",
        "barcode": "7809999000999",
        "measure_unit": "UN",
        "type": 1,
        "favorite": 1,
        "product_category_id": 0,
        "cost": {"currency_id": 39, "value": 2500},
        "price": [
            {
                "price_list_id": price_list_id,
                "unit_net": 4201.680672,
                "unit_tax": 798.319328,
                "unit_total": 5000,
                "currency_id": 39,
                "sales_commission_percentage": 0,
                "taxes": [
                    {
                        "tax_type_id": "387",
                        "tax_percentage": 19,
                        "tax_amount": 798.319328,
                    }
                ],
            }
        ],
        "inventories": {
            "total_available": 99,
            "total_reserved": 0,
            "details": [
                {
                    "product_location_id": str(location_id),
                    "available_quantity": 99,
                    "reserved_quantity": 0,
                }
            ],
        },
    }
    code, data, raw = req(f"{BASE}/products/{product_id}", "PUT", put_b, token)
    print("PUT B (full)", code, raw[:500])
    results["steps"].append({"step": "put_full", "status": code, "response": data or raw[:800]})

    code, after_b, raw = req(f"{BASE}/products/{product_id}", token=token)
    results["after_put_b"] = after_b
    print("GET after B", code)

    # Diff summary
    def pick(obj):
        if not isinstance(obj, dict):
            return obj
        keys = [
            "product_id",
            "name",
            "sku",
            "barcode",
            "brand",
            "model",
            "status",
            "measure_unit",
            "additional_details",
            "invoicing_details",
            "cost",
            "prices",
            "price",
            "inventories",
        ]
        return {k: obj.get(k) for k in keys if k in obj}

    results["diff_summary"] = {
        "after_a": pick(after_a if isinstance(after_a, dict) else {}),
        "after_b": pick(after_b if isinstance(after_b, dict) else {}),
    }

    OUT.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
    print("WROTE", OUT)


if __name__ == "__main__":
    main()
