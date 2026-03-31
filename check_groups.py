"""Script para verificar duplicados en grupos."""
import pandas as pd
from collections import Counter
import sys
import os

# Asegurar que el import funcione desde cualquier ubicación
try:
    from src.utils import select_xlsx_file
except ModuleNotFoundError:
    # Si falla, agregar directorio raíz al path
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from src.utils import select_xlsx_file

# Solicitar archivo xlsx interactivamente (busca nuevo y viejo formato)
try:
    xlsx_path = select_xlsx_file('data/processed', 'revision_final_*.xlsx')
except FileNotFoundError:
    print("No se encontraron archivos revision_final_*.xlsx, buscando maestro_revision_*.xlsx...")
    xlsx_path = select_xlsx_file('data/processed', 'maestro_revision_*.xlsx')

df = pd.read_excel(xlsx_path)

print("=== RESUMEN ===")
print(f"Total registros: {len(df)}")
print(df['Tipo'].value_counts())

# Verificar SKUs duplicados
sku_counts = df['SKU'].value_counts()
dups = sku_counts[sku_counts > 1]
print(f"\nSKUs duplicados: {len(dups)}")
if len(dups) > 0:
    for sku, count in dups.head(10).items():
        print(f"  {sku}: {count} veces")
        rows = df[df['SKU'] == sku][['ID', 'Tipo', 'Nombre', 'Principal']]
        print(rows.to_string())

# Verificar si hay variaciones en más de un grupo
print("\n=== VERIFICANDO VARIACIONES ===")
variations = df[df['Tipo'] == 'variation']
print(f"Total variaciones: {len(variations)}")

# Verificar por SKU_Original
if 'SKU_Original' in df.columns:
    # Contar cuantas veces aparece cada SKU_Original como variacion
    orig_counts = variations['SKU_Original'].value_counts()
    multi = orig_counts[orig_counts > 1]
    print(f"SKU_Original duplicados: {len(multi)}")
    if len(multi) > 0:
        for sku, count in multi.head(10).items():
            print(f"\n  SKU_Original '{sku}' aparece {count} veces:")
            rows = df[(df['SKU_Original'] == sku) & (df['Tipo'] == 'variation')]
            for _, r in rows.iterrows():
                print(f"    ID:{r['ID']} - {r['Nombre'][:40]} - Principal:{r['Principal']}")

# Mostrar algunos grupos
print("\n=== MUESTRA DE GRUPOS ===")
padres = df[df['Tipo'] == 'variable'].head(5)
for _, p in padres.iterrows():
    pid = p['ID']
    pname = str(p['Nombre'])[:50]
    hijos = df[df['Principal'] == f'id:{pid}']
    print(f"\nGrupo ID:{pid} - '{pname}' ({len(hijos)} variaciones)")
    for _, h in hijos.head(3).iterrows():
        print(f"  - {h['SKU']}: {str(h['Nombre'])[:40]}")
    if len(hijos) > 3:
        print(f"  ... y {len(hijos)-3} más")

# Contar tamaño de grupos
print("\n=== DISTRIBUCIÓN DE TAMAÑO DE GRUPOS ===")
group_sizes = []
for _, p in df[df['Tipo'] == 'variable'].iterrows():
    pid = p['ID']
    hijos = df[df['Principal'] == f'id:{pid}']
    group_sizes.append(len(hijos))

size_dist = Counter(group_sizes)
print("Variaciones por grupo:")
for size in sorted(size_dist.keys())[:15]:
    print(f"  {size} variaciones: {size_dist[size]} grupos")
