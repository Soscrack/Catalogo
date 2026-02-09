"""
Análisis de aciertos y discrepancias en nombres de categorías del catálogo.

Este script analiza el JSON del catálogo y detecta:
1. Títulos de producto que parecen correctos
2. Títulos con posibles problemas (muy largos, incompletos, con texto extra)
3. Patrones repetidos de errores
4. Sugerencias de corrección
"""

import json
import re
from collections import defaultdict
from pathlib import Path
from datetime import datetime


def load_catalog(json_path: str) -> dict:
    """Carga el catálogo JSON."""
    with open(json_path, 'r', encoding='utf-8') as f:
        return json.load(f)


def get_product_type(product: dict) -> str:
    """Obtiene el tipo de producto (última categoría) del path."""
    path = product.get('category_path', [])
    if len(path) >= 3:
        return path[-1]  # Última categoría = tipo de producto
    return ""


def analyze_title(title: str) -> dict:
    """Analiza un título y detecta posibles problemas."""
    issues = []
    suggestions = []
    
    # 1. Título muy largo (probablemente concatenó texto extra)
    if len(title) > 60:
        issues.append("TITULO_MUY_LARGO")
        suggestions.append(f"Revisar si concatenó texto extra. Longitud: {len(title)}")
    
    # 2. Contiene " - " (puede ser título de sección mezclado)
    # PERO: algunos títulos válidos tienen " - " como parte del nombre
    if " - " in title and not title.endswith(" - "):
        parts = title.split(" - ")
        if len(parts) == 2:
            before, after = parts[0].strip(), parts[1].strip()
            # Casos que SON válidos (no son secciones mezcladas):
            # - "PERNO ... - ROSCA FINA" (variante de rosca)
            # - "TORNILLO ... - RAN. PHILLIPS" (tipo de ranura)
            # - "TUERCA ... - ROSCA FINA" (variante de rosca)
            # - "... - MADERA" o "... - METAL" (tipo de material)
            valid_after_patterns = [
                "ROSCA FINA", "ROSCA GRUESA", "RAN. PHILLIPS", "RANURA PHILLIPS",
                "RAN. TORX", "RANURA TORX", "MADERA", "METAL", "LOSA",
                "ANTISISMICO", "ANTISÍSMICO", "ALTA CAPACIDAD",
            ]
            # Si la parte después del guión es un patrón válido, no es sección mezclada
            if any(pat in after.upper() for pat in valid_after_patterns):
                pass  # Es un título válido, no agregar issue
            else:
                issues.append("POSIBLE_SECCION_MEZCLADA")
                suggestions.append(f"Posible separación: Sección='{before}', Producto='{after}'")
    
    # 3. Termina en palabra incompleta
    incomplete_endings = ["PARA", "DE", "CON", "EN", "A", "Y", "X", "CAB.", "C/", "S/"]
    words = title.upper().split()
    if words and words[-1] in incomplete_endings:
        issues.append("TITULO_INCOMPLETO")
        suggestions.append(f"Título parece incompleto, termina en '{words[-1]}'")
    
    # 4. Contiene patrones de header/datos mezclados
    header_patterns = ["CODIGO", "NOMINAL", "ENVASE", "LARGO"]
    if any(p in title.upper() for p in header_patterns):
        issues.append("HEADER_MEZCLADO")
        suggestions.append("Contiene texto de header de tabla mezclado")
    
    # 5. Contiene números de página o marcadores
    if re.search(r'Página \d+|<<<', title):
        issues.append("MARCADOR_PAGINA")
        suggestions.append("Contiene marcador de página")
    
    # 6. Título muy corto (puede faltar continuación)
    # PERO: algunos subtipos/acabados válidos son cortos
    valid_short_types = [
        "PHILLIPS", "POZI", "TORX", "HEXAGONAL",  # Tipos de ranura/cabeza
        "PAVONADO", "ZINCADO", "BRONCE",  # Acabados
        "PLÁSTICO", "PLASTICO", "NYLON", "METAL", "MADERA",  # Materiales
        "INSERTO", "DADO",  # Herramientas
        "REMACHE",  # Tipo de producto
        # Complementos de línea - productos válidos con nombres cortos
        "ARGOLLA", "CANCAMO", "CÁNCAMO", "CHAVETA", "GANCHO", 
        "MAILLON", "MAILLÓN", "MOSQUETON", "MOSQUETÓN",
        "UNIÓN", "UNION", "GRAPA", "ABRAZADERA",
    ]
    is_valid_short = any(valid in title.upper() for valid in valid_short_types)
    if len(title) < 10 and len(words) <= 2 and not is_valid_short:
        issues.append("TITULO_MUY_CORTO")
        suggestions.append("Título muy corto, puede faltar continuación")
    
    # 7. Contiene logos no filtrados
    logos = ["ESSVE", "KNAPP", "MAMUT", "SEMAMUT", "SOMAMUT"]
    if any(logo in title.upper() for logo in logos):
        issues.append("LOGO_NO_FILTRADO")
        suggestions.append("Contiene logo/marca no filtrado")
    
    # 8. Patrón de acabado en el título (debería estar separado)
    finish_in_title = ["ZINCADO", "PAVONADO", "GALVANIZADO", "INOXIDABLE 304", "BRONCE"]
    for f in finish_in_title:
        if f in title.upper() and not title.upper().startswith(f):
            # Si el acabado está en medio del título, puede ser problema
            pass  # Esto es normal para algunos productos
    
    return {
        "issues": issues,
        "suggestions": suggestions,
        "is_valid": len(issues) == 0
    }


def analyze_catalog(catalog: dict) -> dict:
    """Analiza todo el catálogo y genera reporte."""
    products = catalog.get('products', {})
    
    # Agrupar por tipo de producto
    by_product_type = defaultdict(list)
    for sku, prod in products.items():
        ptype = get_product_type(prod)
        if ptype:
            by_product_type[ptype].append({
                'sku': sku,
                'nombre': prod.get('nombre_producto', ''),
                'path': prod.get('category_path', [])
            })
    
    # Analizar cada tipo de producto
    results = {
        "total_products": len(products),
        "total_product_types": len(by_product_type),
        "valid_types": [],
        "problematic_types": [],
        "statistics": {
            "by_issue": defaultdict(int)
        }
    }
    
    for ptype, prods in sorted(by_product_type.items()):
        analysis = analyze_title(ptype)
        
        entry = {
            "product_type": ptype,
            "count": len(prods),
            "sample_skus": [p['sku'] for p in prods[:5]],
            "analysis": analysis
        }
        
        if analysis['is_valid']:
            results["valid_types"].append(entry)
        else:
            results["problematic_types"].append(entry)
            for issue in analysis['issues']:
                results["statistics"]["by_issue"][issue] += 1
    
    # Convertir defaultdict a dict para JSON
    results["statistics"]["by_issue"] = dict(results["statistics"]["by_issue"])
    
    return results


def find_similar_types(product_types: list) -> list:
    """Encuentra tipos de producto similares que podrían ser el mismo."""
    similar_groups = []
    processed = set()
    
    for i, t1 in enumerate(product_types):
        if t1 in processed:
            continue
        
        group = [t1]
        t1_words = set(t1.upper().split())
        
        for t2 in product_types[i+1:]:
            if t2 in processed:
                continue
            
            t2_words = set(t2.upper().split())
            
            # Si comparten más del 70% de palabras, son similares
            intersection = t1_words & t2_words
            union = t1_words | t2_words
            if union and len(intersection) / len(union) > 0.7:
                group.append(t2)
                processed.add(t2)
        
        if len(group) > 1:
            similar_groups.append(group)
            processed.add(t1)
    
    return similar_groups


def generate_report(results: dict, output_path: str):
    """Genera un reporte detallado en formato texto."""
    lines = []
    lines.append("=" * 80)
    lines.append("ANÁLISIS DE CATEGORÍAS DEL CATÁLOGO MAMUT 2025")
    lines.append(f"Generado: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    lines.append("=" * 80)
    lines.append("")
    
    # Resumen
    lines.append("RESUMEN")
    lines.append("-" * 40)
    lines.append(f"Total productos: {results['total_products']}")
    lines.append(f"Total tipos de producto: {results['total_product_types']}")
    lines.append(f"Tipos válidos: {len(results['valid_types'])}")
    lines.append(f"Tipos con problemas: {len(results['problematic_types'])}")
    lines.append("")
    
    # Estadísticas por tipo de problema
    lines.append("PROBLEMAS DETECTADOS")
    lines.append("-" * 40)
    for issue, count in sorted(results['statistics']['by_issue'].items(), key=lambda x: -x[1]):
        lines.append(f"  {issue}: {count} tipos afectados")
    lines.append("")
    
    # Tipos problemáticos
    lines.append("=" * 80)
    lines.append("TIPOS DE PRODUCTO CON PROBLEMAS")
    lines.append("=" * 80)
    lines.append("")
    
    for entry in results['problematic_types']:
        lines.append(f"TIPO: {entry['product_type']}")
        lines.append(f"  Productos: {entry['count']}")
        lines.append(f"  SKUs ejemplo: {', '.join(entry['sample_skus'])}")
        lines.append(f"  Problemas: {', '.join(entry['analysis']['issues'])}")
        for sug in entry['analysis']['suggestions']:
            lines.append(f"  → {sug}")
        lines.append("")
    
    # Tipos válidos (resumen)
    lines.append("=" * 80)
    lines.append("TIPOS DE PRODUCTO VÁLIDOS (muestra)")
    lines.append("=" * 80)
    lines.append("")
    
    # Solo mostrar los primeros 20 como muestra
    for entry in results['valid_types'][:20]:
        lines.append(f"✓ {entry['product_type']} ({entry['count']} productos)")
    
    if len(results['valid_types']) > 20:
        lines.append(f"  ... y {len(results['valid_types']) - 20} más")
    
    # Guardar
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))
    
    return '\n'.join(lines)


def main():
    """Función principal."""
    # Rutas
    base_path = Path(__file__).parent.parent
    json_path = base_path / "data" / "catalogo_mamut_2025_spatial.json"
    report_path = base_path / "data" / "analisis_categorias.txt"
    json_report_path = base_path / "data" / "analisis_categorias.json"
    
    print(f"Cargando catálogo desde: {json_path}")
    catalog = load_catalog(str(json_path))
    
    print("Analizando categorías...")
    results = analyze_catalog(catalog)
    
    # Buscar tipos similares
    all_types = [e['product_type'] for e in results['valid_types'] + results['problematic_types']]
    similar = find_similar_types(all_types)
    results['similar_types'] = similar
    
    # Guardar JSON
    with open(json_report_path, 'w', encoding='utf-8') as f:
        json.dump(results, f, indent=2, ensure_ascii=False)
    print(f"Reporte JSON guardado en: {json_report_path}")
    
    # Generar y guardar reporte texto
    report = generate_report(results, str(report_path))
    print(f"Reporte texto guardado en: {report_path}")
    
    # Mostrar resumen
    print("\n" + "=" * 60)
    print("RESUMEN")
    print("=" * 60)
    print(f"Total productos: {results['total_products']}")
    print(f"Total tipos de producto: {results['total_product_types']}")
    print(f"Tipos válidos: {len(results['valid_types'])}")
    print(f"Tipos con problemas: {len(results['problematic_types'])}")
    
    if results['problematic_types']:
        print("\nPrimeros 10 tipos problemáticos:")
        for entry in results['problematic_types'][:10]:
            print(f"  - {entry['product_type'][:60]}...")
            print(f"    Problemas: {', '.join(entry['analysis']['issues'])}")
    
    if similar:
        print(f"\nGrupos de tipos similares encontrados: {len(similar)}")
        for group in similar[:5]:
            print(f"  - {group}")


if __name__ == "__main__":
    main()
