import json

# Test funciones
from src.catalogo_spatial_parser import is_title_line, is_incomplete_title, is_title_continuation

print("Testing:")
print(f"is_title_line('ROSCALATA'): {is_title_line('ROSCALATA')}")
print(f"is_incomplete_title('ROSCALATA'): {is_incomplete_title('ROSCALATA')}")
print(f"is_title_continuation('CAB. PLANA PHILLIPS'): {is_title_continuation('CAB. PLANA PHILLIPS')}")
print()

data = json.load(open('data/catalogo_mamut_2025_spatial.json'))

# Buscar en la estructura del tree
def find_all_products(tree, path=""):
    results = []
    for key, val in tree.items():
        if key == "_products":
            for p in val:
                if 'RLPP' in str(p.get('sku', '')):
                    results.append((path, p))
        elif isinstance(val, dict):
            results.extend(find_all_products(val, f"{path}/{key}"))
    return results

prods = find_all_products(data.get('tree', {}))
print(f'Productos RLPP encontrados: {len(prods)}')
for path, p in prods[:10]:
    print(f"{p.get('sku')} -> nombre: {p.get('nombre_producto', '')} | path: {path}")
