"""
Generador de archivos WooCommerce con atributos del catálogo Mamut.
Conserva los datos originales del Excel, actualiza atributos de hijos y crea padres si no existen.
"""

import pandas as pd
import json
import re
from pathlib import Path
from datetime import datetime
from typing import Any

# Ruta del archivo de mapeo SKU
SKU_MAPPING_PATH = Path("data/sku_mapping.json")


def load_sku_mapping() -> dict[str, str]:
    """Carga el diccionario de mapeo SKU_PROVEEDOR -> SKU_LOCAL."""
    if SKU_MAPPING_PATH.exists():
        with open(SKU_MAPPING_PATH, 'r', encoding='utf-8') as f:
            return json.load(f)
    return {}


def save_sku_mapping(mapping: dict[str, str]) -> None:
    """Guarda el diccionario de mapeo SKU_PROVEEDOR -> SKU_LOCAL."""
    SKU_MAPPING_PATH.parent.mkdir(parents=True, exist_ok=True)
    with open(SKU_MAPPING_PATH, 'w', encoding='utf-8') as f:
        json.dump(mapping, f, indent=2, ensure_ascii=False)
    print(f"Mapeo SKU guardado: {len(mapping)} relaciones en {SKU_MAPPING_PATH}")


def load_catalog(catalog_path: str) -> dict[str, dict]:
    """Carga el catálogo JSON extraído."""
    with open(catalog_path, 'r', encoding='utf-8') as f:
        catalog = json.load(f)
    # El JSON puede ser directamente un dict SKU->producto o tener key 'products'
    if isinstance(catalog, dict) and 'products' in catalog:
        return catalog['products']
    return catalog


def find_sku_in_text(text: str, catalog_skus: set[str]) -> str | None:
    """Busca un SKU del catálogo dentro de un texto (nombre o SKU del Excel)."""
    text_upper = text.upper()
    # Ordenar SKUs por longitud descendente para encontrar el más específico primero
    for sku in sorted(catalog_skus, key=len, reverse=True):
        sku_escaped = re.escape(sku)
        patterns = [
            rf'\({sku_escaped}\)',  # (SKU)
            rf'\b{sku_escaped}\b',  # SKU como palabra
            rf'{sku_escaped}$',     # SKU al final
        ]
        for pattern in patterns:
            if re.search(pattern, text_upper, re.IGNORECASE):
                return sku
    return None


def aggregate_attributes(products_data: list[dict]) -> dict[str, list[str]]:
    """Agrega atributos de múltiples productos para crear padre variable."""
    aggregated = {}
    for product in products_data:
        for attr in product.get('attributes', []):
            name = attr['name']
            value = str(attr['value'])
            if name not in aggregated:
                aggregated[name] = []
            if value and value not in aggregated[name]:
                aggregated[name].append(value)
    return aggregated


def generate_woocommerce_from_catalog(
    excel_path: str,
    catalog_path: str,
    output_path: str | None = None
) -> tuple[pd.DataFrame, str, str]:
    """
    Genera archivo Excel y CSV de WooCommerce conservando datos originales
    y actualizando atributos desde el catálogo.
    
    REGLAS DE GRUPOS:
    - Solo disuelve grupos que tienen hijos con match en el catálogo
    - Grupos sin hijos con match -> quedan intactos
    - Productos CON match en catálogo -> se agrupan por categoría del catálogo
    - Los grupos del catálogo SOLO contienen productos con código del catálogo
    """
    # Cargar datos
    catalog = load_catalog(catalog_path)
    catalog_skus = set(catalog.keys())
    df_original = pd.read_excel(excel_path, sheet_name='Maestro')
    
    # Cargar mapeo de SKUs existente
    sku_mapping = load_sku_mapping()
    new_mappings = 0
    cached_mappings = 0
    
    print(f"Productos en catálogo: {len(catalog_skus)}")
    print(f"Productos en Excel original: {len(df_original)}")
    print(f"Mapeos SKU cargados: {len(sku_mapping)}")
    
    # Copiar todas las columnas del original
    all_columns = list(df_original.columns)
    
    # === PASO 1: IDENTIFICAR ESTRUCTURA DE GRUPOS EXISTENTES ===
    # Mapear: parent_id -> lista de índices de hijos
    # Mapear: idx -> parent_id (para variations)
    existing_parents = {}  # ID -> idx del padre
    child_to_parent = {}   # idx del hijo -> ID del padre
    
    if 'Tipo' in df_original.columns:
        for idx, row in df_original.iterrows():
            tipo = str(row.get('Tipo', '')).lower().strip()
            if tipo == 'variable':
                row_id = row.get('ID')
                if pd.notna(row_id):
                    existing_parents[int(row_id)] = idx
            elif tipo == 'variation':
                principal = str(row.get('Principal', ''))
                # Extraer ID del padre de "id:123"
                if principal.startswith('id:'):
                    try:
                        parent_id = int(principal[3:])
                        child_to_parent[idx] = parent_id
                    except ValueError:
                        pass
    
    print(f"\nGrupos existentes: {len(existing_parents)} padres")
    
    # === PASO 2: ENCONTRAR MATCHES CON CATÁLOGO ===
    matches = []  # Lista de {idx, catalog_sku, catalog_data, category_path}
    groups_by_category = {}  # category_path -> lista de items
    
    total_rows = len(df_original)
    for idx, row in df_original.iterrows():
        if idx % 500 == 0:
            print(f"  Procesando fila {idx}/{total_rows}...")
        
        # Saltar padres existentes (no buscar match en ellos)
        tipo = str(row.get('Tipo', '')).lower().strip()
        if tipo == 'variable':
            continue
        
        nombre = str(row['Nombre']) if pd.notna(row['Nombre']) else ""
        sku_excel = str(row['SKU']) if pd.notna(row['SKU']) else ""
        
        # Primero buscar en el mapeo existente (por SKU local)
        found_sku = None
        for cat_sku, local_sku in sku_mapping.items():
            if local_sku == sku_excel:
                found_sku = cat_sku
                cached_mappings += 1
                break
        
        # Validar que el SKU del mapeo existe en el catálogo actual
        if found_sku and found_sku not in catalog:
            # Mapeo obsoleto: SKU ya no está en catálogo
            found_sku = None
        
        # Si no está en mapeo, buscar por texto
        if not found_sku:
            found_sku = find_sku_in_text(nombre, catalog_skus)
            if not found_sku:
                found_sku = find_sku_in_text(sku_excel, catalog_skus)
            
            # Validar que el SKU encontrado realmente existe en el catálogo
            if found_sku and found_sku not in catalog:
                # SKU encontrado por regex pero no está en catálogo (ej: T50 es atributo, no SKU)
                found_sku = None
            
            # Guardar nueva relación en el mapeo
            if found_sku and sku_excel:
                sku_mapping[found_sku] = sku_excel
                new_mappings += 1
        
        if found_sku:
            cat_data = catalog[found_sku]
            category_path = tuple(cat_data.get('category_path', []))
            
            matches.append({
                'idx': idx,
                'catalog_sku': found_sku,
                'catalog_data': cat_data,
                'category_path': category_path,
            })
            
            # Agrupar por categoría para crear padres
            if category_path not in groups_by_category:
                groups_by_category[category_path] = []
            groups_by_category[category_path].append({
                'idx': idx,
                'catalog_sku': found_sku,
                'catalog_data': cat_data,
            })
    
    print(f"\nCoincidencias encontradas: {len(matches)}")
    print(f"  - Desde caché: {cached_mappings}")
    print(f"  - Nuevas: {new_mappings}")
    print(f"Grupos de categoría (padres a crear): {len(groups_by_category)}")
    
    # === PASO 3: IDENTIFICAR PADRES A DISOLVER ===
    # Un padre se disuelve si tiene al menos un hijo con match en catálogo
    matched_indices = {m['idx'] for m in matches}
    parents_to_dissolve = set()  # IDs de padres a eliminar
    
    for idx in matched_indices:
        if idx in child_to_parent:
            parent_id = child_to_parent[idx]
            parents_to_dissolve.add(parent_id)
    
    # Hijos de padres a disolver que NO tienen match -> convertir a simple
    children_to_convert_simple = set()
    for child_idx, parent_id in child_to_parent.items():
        if parent_id in parents_to_dissolve and child_idx not in matched_indices:
            children_to_convert_simple.add(child_idx)
    
    print(f"\n=== GRUPOS A DISOLVER ===")
    print(f"  Padres con hijos en catálogo: {len(parents_to_dissolve)}")
    print(f"  Hijos sin match a convertir a simple: {len(children_to_convert_simple)}")
    print(f"  Grupos intactos: {len(existing_parents) - len(parents_to_dissolve)}")
    
    # Guardar el mapeo actualizado
    save_sku_mapping(sku_mapping)
    
    # Crear lista de filas para el nuevo DataFrame
    output_rows = []
    
    # ID máximo actual para crear nuevos padres
    max_id = df_original['ID'].max() if 'ID' in df_original.columns else 0
    max_id = int(max_id) if pd.notna(max_id) else 0
    parent_id_counter = max_id + 1000
    
    # Mapeo: category_path -> parent_id
    parent_ids = {}
    
    # 1. Primero crear los padres (tipo variable) para cada grupo de categoría
    for category_path, group_items in groups_by_category.items():
        if not category_path:
            continue
        
        # Agregar atributos de todos los hijos del grupo
        aggregated = aggregate_attributes([item['catalog_data'] for item in group_items])
        
        # Nombre del padre = última parte de la categoría (tipo de producto)
        parent_name = category_path[-1] if category_path else "Producto Variable"
        
        # SKU del padre
        parent_sku = f"GRP-{'-'.join(p[:10].upper().replace(' ', '-') for p in category_path[-2:])}"
        
        # Categorías WooCommerce
        categories_str = ' > '.join(category_path)
        
        parent_id = parent_id_counter
        parent_ids[category_path] = parent_id
        parent_id_counter += 1
        
        # Crear fila del padre con todas las columnas del original
        parent_row = {col: '' for col in all_columns}
        parent_row['ID'] = parent_id
        parent_row['Tipo'] = 'variable'
        parent_row['SKU'] = parent_sku
        parent_row['Nombre'] = parent_name
        parent_row['Publicado'] = 1
        parent_row['Visibilidad en el catálogo'] = 'visible'
        parent_row['Categorías'] = categories_str
        parent_row['¿En inventario?'] = 1
        parent_row['Marcas'] = 'MAMUT'
        parent_row['Principal'] = ''
        
        # Atributos agregados (todos los valores posibles separados por |)
        for i, (attr_name, values) in enumerate(list(aggregated.items())[:6], 1):
            parent_row[f'Nombre del atributo {i}'] = attr_name
            parent_row[f'Valor(es) del atributo {i}'] = '|'.join(str(v) for v in values)
            parent_row[f'Atributo visible {i}'] = 1
            parent_row[f'Atributo global {i}'] = 0
        
        output_rows.append(parent_row)
    
    print(f"Padres creados: {len(parent_ids)}")
    
    # 2. Ahora procesar TODOS los productos del Excel
    # - Padres a disolver -> se eliminan
    # - Padres intactos -> se mantienen tal cual
    # - Hijos con match en catálogo -> variation con nuevo padre
    # - Hijos de padres disueltos sin match -> simple
    # - Hijos de padres intactos -> se mantienen como variation
    # - Productos simple -> se mantienen
    children_updated = 0
    children_converted_to_simple = 0
    children_unchanged = 0
    parents_dissolved = 0
    parents_kept = 0
    
    for idx, row in df_original.iterrows():
        tipo = str(row.get('Tipo', '')).lower().strip()
        original_row = row.to_dict()
        row_id = row.get('ID')
        row_id_int = int(row_id) if pd.notna(row_id) else None
        
        if tipo == 'variable':
            # Es un padre existente
            if row_id_int in parents_to_dissolve:
                # Este padre tiene hijos con match -> se elimina
                parents_dissolved += 1
                continue  # No incluir en output
            else:
                # Este padre NO tiene hijos con match -> se mantiene
                parents_kept += 1
                output_rows.append(original_row)
                continue
        
        if idx in matched_indices:
            # Producto CON coincidencia en catálogo -> variation con nuevo padre
            match = next(m for m in matches if m['idx'] == idx)
            cat_data = match['catalog_data']
            category_path = match['category_path']
            
            # Actualizar tipo a variation
            original_row['Tipo'] = 'variation'
            
            # Asignar referencia al NUEVO padre del catálogo
            parent_id = parent_ids.get(category_path)
            if parent_id:
                original_row['Principal'] = f'id:{parent_id}'
            else:
                # Si no hay padre (categoría vacía), convertir a simple
                original_row['Tipo'] = 'simple'
                original_row['Principal'] = ''
            
            # Actualizar atributos con los del catálogo
            attrs = cat_data.get('attributes', [])
            for i, attr in enumerate(attrs[:6], 1):
                original_row[f'Nombre del atributo {i}'] = attr['name']
                original_row[f'Valor(es) del atributo {i}'] = attr['value']
                original_row[f'Atributo visible {i}'] = 1
                original_row[f'Atributo global {i}'] = 0
            
            # Limpiar atributos restantes si hay menos de 6
            for i in range(len(attrs) + 1, 7):
                if f'Nombre del atributo {i}' in original_row:
                    original_row[f'Nombre del atributo {i}'] = ''
                if f'Valor(es) del atributo {i}' in original_row:
                    original_row[f'Valor(es) del atributo {i}'] = ''
            
            # Agregar nota de revisión y SKU del proveedor
            original_row['Nota de Revision'] = 'Atributos por computadora'
            original_row['SKU Proveedor'] = match['catalog_sku']
            
            children_updated += 1
        elif idx in children_to_convert_simple:
            # Hijo de padre disuelto, pero SIN match -> convertir a simple
            original_row['Tipo'] = 'simple'
            original_row['Principal'] = ''
            children_converted_to_simple += 1
        else:
            # Producto sin match y no afectado por disolución -> mantener tal cual
            # (puede ser simple, o variation de padre intacto)
            children_unchanged += 1
        
        output_rows.append(original_row)
    
    print(f"\nProductos con atributos del catálogo (variation): {children_updated}")
    print(f"Hijos sin match convertidos a simple: {children_converted_to_simple}")
    print(f"Productos sin cambios: {children_unchanged}")
    print(f"Padres disueltos: {parents_dissolved}")
    print(f"Padres intactos: {parents_kept}")
    
    # Crear DataFrame
    output_df = pd.DataFrame(output_rows)
    
    # Ordenar por ID
    if 'ID' in output_df.columns:
        output_df = output_df.sort_values('ID')
    
    # Generar rutas de salida
    if output_path is None:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        output_path = f"data/processed/maestro_revision_{timestamp}"
    
    excel_output = f"{output_path}.xlsx"
    csv_output = f"{output_path}.csv"
    
    # Guardar con hoja 'Maestro' para compatibilidad con revisor_gui
    with pd.ExcelWriter(excel_output, engine='openpyxl') as writer:
        output_df.to_excel(writer, sheet_name='Maestro', index=False)
    
    output_df.to_csv(csv_output, index=False, encoding='utf-8-sig')
    
    print(f"\nGenerados:")
    print(f"  Excel: {excel_output}")
    print(f"  CSV: {csv_output}")
    print(f"  Total filas: {len(output_df)}")
    print(f"    - Padres nuevos del catálogo: {len(parent_ids)}")
    print(f"    - Padres intactos (sin hijos en catálogo): {parents_kept}")
    print(f"    - Hijos con atributos del catálogo: {children_updated}")
    print(f"    - Hijos sin match convertidos a simple: {children_converted_to_simple}")
    print(f"    - Productos sin cambios: {children_unchanged}")
    print(f"    - Padres disueltos: {parents_dissolved}")
    
    return output_df, excel_output, csv_output


if __name__ == "__main__":
    import sys
    import os
    
    # Agregar directorio raíz al path si estamos en src/
    if os.path.basename(os.getcwd()) == 'src':
        sys.path.insert(0, os.path.dirname(os.getcwd()))
    
    # Usar argumento o solicitar archivo interactivamente
    if len(sys.argv) > 1:
        excel_path = sys.argv[1]
    else:
        try:
            from src.utils import select_xlsx_file
        except ModuleNotFoundError:
            from utils import select_xlsx_file
        
        # Intentar buscar archivos nuevos primero, luego viejos
        try:
            excel_path = select_xlsx_file('data/processed', 'revision_final_*.xlsx')
        except FileNotFoundError:
            print("No se encontraron archivos revision_final_*.xlsx, buscando maestro_revision_*.xlsx...")
            excel_path = select_xlsx_file('data/processed', 'maestro_revision_*.xlsx')
    
    catalog_path = sys.argv[2] if len(sys.argv) > 2 else "data/catalogo_mamut_2025_spatial.json"
    
    df, excel_out, csv_out = generate_woocommerce_from_catalog(excel_path, catalog_path)
    
    print("\n=== EJEMPLOS DE PADRES CREADOS ===")
    padres = df[df['Tipo'] == 'variable'].head(3)
    for _, row in padres.iterrows():
        print(f"ID: {row['ID']}, SKU: {row['SKU']}")
        print(f"  Nombre: {row['Nombre'][:60] if pd.notna(row['Nombre']) else ''}")
        print(f"  Attr1: {row.get('Nombre del atributo 1', '')} = {row.get('Valor(es) del atributo 1', '')}")
        print()
    
    print("\n=== EJEMPLOS DE HIJOS ACTUALIZADOS ===")
    hijos = df[df['Tipo'] == 'variation'].head(5)
    for _, row in hijos.iterrows():
        print(f"ID: {row['ID']}, SKU: {row['SKU']}, Principal: {row.get('Principal', '')}")
        print(f"  Nombre: {row['Nombre'][:60] if pd.notna(row['Nombre']) else ''}")
        print(f"  Attr1: {row.get('Nombre del atributo 1', '')} = {row.get('Valor(es) del atributo 1', '')}")
        print()
