#!/usr/bin/env python3
"""Verifica API Inbox FACTO: paginación, rango por fechas y encoding XML."""
from __future__ import annotations

import json
import re
import ssl
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
OUT = Path(__file__).with_name("facto_inbox_test_results.json")


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
            return resp.status, json.loads(raw) if raw else None, raw
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


def doc_date(item: dict) -> str:
    return (item.get("document_date") or "")[:10]


def page_bounds(token: str, page: int) -> tuple[str | None, str | None]:
    code, data, _ = req(f"{BASE}/inbox_documents?page={page}", token=token)
    if code != 200:
        return None, None
    items = embedded(data, "inbox_documents")
    dates = [doc_date(i) for i in items if doc_date(i)]
    if not dates:
        return None, None
    return min(dates), max(dates)


def binary_page_from(token: str, target: str, page_count: int) -> int:
    lo, hi = 1, page_count
    result = page_count
    while lo <= hi:
        mid = (lo + hi) // 2
        mn, mx = page_bounds(token, mid)
        if mx is None:
            break
        if mx < target:
            lo = mid + 1
        else:
            result = mid
            hi = mid - 1
    return result


def normalize_xml_encoding(xml: str) -> str:
    xml = xml.lstrip("\ufeff")
    m = re.search(r'encoding=["\']([^"\']+)["\']', xml)
    if m and m.group(1).upper() != "UTF-8":
        xml = re.sub(r'encoding=["\'][^"\']+["\']', 'encoding="UTF-8"', xml, count=1, flags=re.I)
    return xml


def main():
    results = {"ok": False, "checks": []}

    code, data, raw = req(f"{BASE}/auth", "POST", CREDS)
    if code != 200:
        OUT.write_text(json.dumps({"auth_failed": code, "raw": raw[:500]}, indent=2), encoding="utf-8")
        print("AUTH FAILED", code)
        return 1

    token = data["access_token"]
    results["checks"].append({"check": "auth", "ok": True})

    code, meta, _ = req(f"{BASE}/inbox_documents?page=1", token=token)
    page_count = int((meta or {}).get("page_count") or 1)
    total_items = int((meta or {}).get("total_items") or 0)
    items = embedded(meta, "inbox_documents")
    results["checks"].append({
        "check": "list_page_1",
        "ok": code == 200 and len(items) > 0,
        "page_count": page_count,
        "total_items": total_items,
        "items_on_page": len(items),
    })

    per_page_ignored = True
    code2, meta2, _ = req(f"{BASE}/inbox_documents?page=1&per_page=5", token=token)
    if code2 == 200:
        per_page_ignored = len(embedded(meta2, "inbox_documents")) == len(items)
    results["checks"].append({"check": "per_page_ignored", "ok": per_page_ignored})

    first = items[0] if items else {}
    xml = first.get("document_xml") or ""
    has_xml = xml.startswith("<?xml") and "<DTE" in xml
    normalized = normalize_xml_encoding(xml)
    encoding_utf8 = 'encoding="UTF-8"' in normalized or "encoding='UTF-8'" in normalized
    results["checks"].append({
        "check": "document_xml_present",
        "ok": has_xml,
        "inbox_document_id": first.get("inbox_document_id"),
        "encoding_normalized": encoding_utf8 or "ISO-8859-1" not in normalized.upper(),
    })

    if page_count >= 1:
        mn1, mx1 = page_bounds(token, 1)
        mnL, mxL = page_bounds(token, page_count)
        ascending = mn1 and mxL and mn1 <= mxL
        results["checks"].append({
            "check": "ascending_order",
            "ok": ascending,
            "page1_min": mn1,
            "page1_max": mx1,
            "last_min": mnL,
            "last_max": mxL,
        })

        if mn1:
            short_from = mn1
            short_to = mx1
            pf = binary_page_from(token, short_from, page_count)
            results["checks"].append({
                "check": "binary_search_short_range",
                "ok": 1 <= pf <= page_count,
                "from": short_from,
                "to": short_to,
                "page_from": pf,
            })

    results["ok"] = all(c.get("ok") for c in results["checks"])
    OUT.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(results, indent=2, ensure_ascii=False))
    return 0 if results["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
