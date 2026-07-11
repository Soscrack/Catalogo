#!/usr/bin/env python
"""
Smoke tests locales + verificación remota post-deploy del ERP Riverso 1.4.0
"""
import os
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT / "php" / "riverso-pos"

REQUIRED_FILES = [
    "riverso-pos.php",
    "loader.php",
    "includes/aliases-core.php",
    "includes/class-activator.php",
    "core/audit/class-audit.php",
    "core/permissions/class-permissions.php",
    "core/events/class-event-bus.php",
    "core/auth/class-auth-service.php",
    "core/tasks/class-task-service.php",
    "core/employees/class-employee.php",
    "catalog/catalog-module.php",
    "catalog/barcodes/class-barcode-model.php",
    "inventory/inventory-module.php",
    "inventory/movements/class-movement.php",
    "inventory/stock/class-stock-service.php",
    "inventory/reservations/class-reservation-service.php",
    "inventory/stock_count/class-stock-count-service.php",
    "woocommerce/woocommerce-module.php",
    "woocommerce/sync/class-woo-sync-manager.php",
    "purchases/purchase_orders/class-purchase-order-module.php",
    "purchases/reception/class-reception-service.php",
    "logistics/picking/class-picking-module.php",
    "sales/customers/class-customer-view.php",
    "settings/class-settings-module.php",
]

REQUIRED_SYMBOLS = [
    ("riverso-pos.php", "RIVERSO_POS_VERSION", "1.4.1"),
    ("riverso-pos.php", "boot_erp_domains", None),
    ("includes/class-activator.php", "create_erp_phase_tables", None),
    ("includes/class-activator.php", "ensure_employees_table", None),
    ("core/permissions/class-permissions.php", "user_can($user_id, $cap)", None),
    ("woocommerce/sync/class-woo-sync-manager.php", "riverso_pos_sync_stock", None),
]


def check_files():
    missing = []
    for rel in REQUIRED_FILES:
        if not (PLUGIN / rel).exists():
            missing.append(rel)
    return missing


def check_php_syntax_heuristic():
    """Chequeo básico de balanceo de llaves/paréntesis en PHP nuevos."""
    errors = []
    for path in PLUGIN.rglob("*.php"):
        # Solo carpetas ERP nuevas + includes críticos
        rel = path.relative_to(PLUGIN).as_posix()
        if not any(rel.startswith(p) for p in (
            "core/", "catalog/", "inventory/", "woocommerce/", "purchases/",
            "sales/", "logistics/", "pricing/", "reports/", "portal/", "settings/",
            "includes/class-activator.php", "includes/aliases-core.php",
            "includes/class-audit.php", "includes/class-permissions.php",
            "riverso-pos.php", "loader.php",
        )):
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        if text.count("{") != text.count("}"):
            errors.append(f"{rel}: unbalanced braces {{}}")
        if text.count("(") != text.count(")"):
            errors.append(f"{rel}: unbalanced parens")
        if "<?php" not in text[:20] and not text.lstrip().startswith("<?php"):
            # allow files that start with BOM/php
            if not text.lstrip("\ufeff").startswith("<?php"):
                errors.append(f"{rel}: missing <?php")
    return errors


def check_symbols():
    bad = []
    for rel, needle, exact in REQUIRED_SYMBOLS:
        text = (PLUGIN / rel).read_text(encoding="utf-8", errors="ignore")
        if exact is not None:
            if exact not in text or needle not in text:
                bad.append(f"{rel}: expected {needle}={exact}")
        elif needle not in text:
            bad.append(f"{rel}: missing {needle}")
    return bad


def build_zip(out_name="riverso-pos-deploy.zip"):
    out = ROOT / out_name
    if out.exists():
        out.unlink()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in PLUGIN.rglob("*"):
            if path.is_file():
                # skip caches
                if "__pycache__" in path.parts or path.suffix == ".pyc":
                    continue
                arc = Path("riverso-pos") / path.relative_to(PLUGIN)
                zf.write(path, arc.as_posix())
    return out


def main():
    print("=== Riverso ERP 1.4.0 local smoke tests ===")
    missing = check_files()
    if missing:
        print("FAIL missing files:")
        for m in missing:
            print(" -", m)
        return 1
    print(f"OK files ({len(REQUIRED_FILES)})")

    syn = check_php_syntax_heuristic()
    if syn:
        print("FAIL syntax heuristics:")
        for e in syn[:30]:
            print(" -", e)
        return 1
    print("OK brace/paren balance")

    sym = check_symbols()
    if sym:
        print("FAIL symbols:")
        for e in sym:
            print(" -", e)
        return 1
    print("OK required symbols")

    z = build_zip()
    print(f"OK zip built: {z} ({z.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
