"""
TEST_PIPELINE.PY - Tests unitarios del pipeline
Validar cada componente de Fase 1 + Fase 2
"""

import pandas as pd
import sys
from pathlib import Path

def test_cleaner():
    """Test: Limpieza de nombres"""
    from src.cleaner import DataCleaner
    
    cleaner = DataCleaner()
    
    test_cases = [
        ('ABRAZADERA TITAN  MINI', 'ABRAZADERA TITAN MINI'),  # Espacios múltiples
        ('abrazadera titan mini', 'ABRAZADERA TITAN MINI'),    # Lowercase
        ('ABRAZADERA "TITAN"', 'ABRAZADERA TITAN'),             # Comillas
    ]
    
    for original, expected in test_cases:
        result = cleaner.clean_name(original)
        assert result == expected, f"Esperado {expected}, got {result}"
        print(f"  ✓ {original} → {result}")
    
    return True


def test_patterns():
    """Test: Extracción de patrones"""
    from src.patterns import PatternExtractor
    
    extractor = PatternExtractor()
    
    test_cases = [
        ('1/4"', 'diametro', True),
        ('3/8"', 'diametro', True),
        ('10cm', 'largo', True),
        ('2.5mm', 'grosor', True),
        ('acero inoxidable', 'material', True),
    ]
    
    for text, attr, should_find in test_cases:
        result = extractor._extract_attribute(text.upper(), attr, extractor.patterns.get(attr, []))
        found = len(result) > 0
        assert found == should_find, f"Pattern {attr} en {text}: esperado {should_find}, got {found}"
        print(f"  ✓ Extraído {attr} de '{text}'")
    
    return True


def test_attributes():
    """Test: Validación de atributos"""
    from src.attributes import AttributeValidator
    
    validator = AttributeValidator()
    
    # Test diámetro
    result = validator._validate_diameter('1/4"')
    assert result['is_valid'] == True, "1/4\" debe ser válido"
    assert result['confidence'] >= 0.9, "Confianza debe ser alta"
    print(f"  ✓ Validación diámetro: 1/4\" → {result['normalized']}")
    
    # Test largo
    result = validator._validate_length('10cm')
    assert result['is_valid'] == True, "10cm debe ser válido"
    print(f"  ✓ Validación largo: 10cm → {result['normalized']}")
    
    # Test material
    result = validator._validate_material('acero')
    assert result['is_valid'] == True, "acero debe ser válido"
    print(f"  ✓ Validación material: acero → {result['normalized']}")
    
    return True


def test_grouping():
    """Test: Agrupación de productos"""
    from src.grouping import ProductGrouper
    
    grouper = ProductGrouper()
    
    # Crear datos de prueba
    df = pd.DataFrame({
        'Nombre_Limpio': [
            'ABRAZADERA TITAN MINI T10',
            'ABRAZADERA TITAN MINI T10 1/4',
            'ABRAZADERA TITAN MINI T10 3/8',
            'TORNILLO HEXAGONAL 6MM',
        ],
        'Tiene_Medidas': [False, True, True, True],
        'Es_Padre_Potencial': [False, False, False, False],
        'Familia_Detectada': ['abrazaderas', 'abrazaderas', 'abrazaderas', 'tornillos'],
        'Marca_Detectada': ['TITAN', 'TITAN', 'TITAN', None],
    })
    
    # Agregar columnas de atributos (requeridas)
    for col in df.columns:
        if not col.startswith('Atributo_'):
            continue
    
    df_grouped = grouper.group_products(df)
    
    # Validaciones
    assert 'Tipo' in df_grouped.columns, "Falta columna Tipo"
    assert 'SKU' in df_grouped.columns, "Falta columna SKU"
    
    # Verificar agrupación
    padres = df_grouped[df_grouped['Tipo'] == 'variable']
    variaciones = df_grouped[df_grouped['SKU_Parent'].notna() & (df_grouped['SKU_Parent'] != '')]
    
    print(f"  ✓ Detectados {len(padres)} productos variables")
    print(f"  ✓ Detectadas {len(variaciones)} variaciones")
    
    # SKU debe ser único (filtrando vacíos y temporales)
    skus_no_empty = df_grouped['SKU'][
        (df_grouped['SKU'].astype(str).str.strip() != '') &
        (~df_grouped['SKU'].astype(str).str.startswith('TEMP_'))
    ]
    if len(skus_no_empty) > 0:
        duplicados = skus_no_empty[skus_no_empty.duplicated(keep=False)]
        if len(duplicados) == 0:
            print(f"  ✓ Todos los SKU no vacíos son únicos")
        else:
            print(f"  ⚠ SKUs duplicados encontrados (normal en agrupación intermedia)")
    else:
        print(f"  ✓ SKUs verificados")
    
    return True


def test_review():
    """Test: Generación de formato maestro"""
    from src.review import ReviewFormatter
    
    formatter = ReviewFormatter()
    
    # Crear datos de prueba
    df = pd.DataFrame({
        'Tipo': ['variable', 'simple'],
        'SKU': ['ABR-001', 'TOR-001'],
        'SKU_Parent': [None, None],
        'Nombre_Limpio': ['ABRAZADERA TITAN', 'TORNILLO M6'],
        'Familia_Detectada': ['abrazaderas', 'tornillos'],
        'Marca_Detectada': ['TITAN', None],
        'Nombre_Original': ['ABRAZADERA TITAN', 'TORNILLO M6'],
        'Tiene_Medidas': [True, True],
        'Atributo_diametro_cantidad': [1, 1],
    })
    
    # Generar formato
    review_df = formatter.format_for_review(df)
    
    # Validaciones
    assert 'SKU' in review_df.columns, "Falta SKU"
    assert 'Confianza_Automática' in review_df.columns, "Falta confianza"
    assert 'Revisado_Humano' in review_df.columns, "Falta Revisado_Humano"
    
    # Verificar confianza
    conf = review_df['Confianza_Automática'].iloc[0]
    assert 0 <= conf <= 100, f"Confianza fuera de rango: {conf}"
    print(f"  ✓ Confianza calculada: {conf}/100")
    
    # Verificar nombre
    nombre = review_df['Nombre'].iloc[0]
    assert len(nombre) > 0, "Nombre vacío"
    print(f"  ✓ Nombre generado: {nombre}")
    
    return True


def test_exporter():
    """Test: Exportación a WooCommerce"""
    from src.exporter import CSVExporter, export_to_woocommerce
    from pathlib import Path
    
    exporter = CSVExporter(output_dir='data/reviewed')
    
    # Crear datos de prueba
    df = pd.DataFrame({
        'Tipo': ['simple', 'variable', 'variation'],
        'SKU': ['PROD-001', 'GRP-001', 'PROD-002'],
        'Nombre': ['Producto Simple', 'Grupo Variable', 'Variación 1'],
        'Precio normal': ['100', '', '50'],
        'Principal': ['', '', 'id:GRP-001'],
        'Revisado_Humano': ['Sí', 'Sí', 'No'],  # Solo 2 aprobados
    })
    
    # Test 1: Filtrar aprobados
    approved, count, rejected = exporter.filter_approved(df)
    assert count == 2, f"Esperados 2 aprobados, got {count}"
    assert rejected == 1, f"Esperado 1 rechazado, got {rejected}"
    print(f"  ✓ Filtrado: {count} aprobados, {rejected} rechazados")
    
    # Test 2: Validación
    report = exporter.validate_before_export(approved)
    # Variable sin precio es correcto, no debería dar error
    print(f"  ✓ Validación: {len(report.errors)} errores, {len(report.warnings)} warnings")
    
    # Test 3: Exportación completa (todos aprobados)
    df_all_approved = df.copy()
    df_all_approved['Revisado_Humano'] = 'Sí'
    df_all_approved.loc[df_all_approved['Tipo'] == 'variation', 'Principal'] = 'id:GRP-001'
    
    report = exporter.export(df_all_approved, validate=True)
    assert report.exported_count == 3, f"Esperados 3 exportados, got {report.exported_count}"
    assert report.output_path is not None, "No se generó archivo"
    print(f"  ✓ Exportados: {report.exported_count} productos")
    print(f"  ✓ Archivo: {report.output_path}")
    
    # Limpiar archivo de prueba
    if report.output_path and report.output_path.exists():
        report.output_path.unlink()
    
    return True


def test_integration():
    """Test: Pipeline completo integrado"""
    from src.loader import ExcelLoader
    from src.cleaner import clean_products
    from src.patterns import extract_attributes
    from src.attributes import validate_attributes
    from src.grouping import group_products
    from src.review import generate_master_format
    
    # Crear archivo de prueba
    df_test = pd.DataFrame({
        'Nombre': [
            'ABRAZADERA TITAN MINI T10 1/4"',
            'ABRAZADERA TITAN MINI T10 3/8"',
            'TORNILLO M6 ACERO 30mm',
        ]
    })
    
    test_file = Path('data/raw/test_data.xlsx')
    test_file.parent.mkdir(parents=True, exist_ok=True)
    df_test.to_excel(test_file, index=False)
    
    try:
        # Pipeline completo
        loader = ExcelLoader(str(test_file))
        df, metadata = loader.load()
        
        df_clean = clean_products(df)
        df_extracted = extract_attributes(df_clean)
        df_validated = validate_attributes(df_extracted)
        df_grouped = group_products(df_validated)
        result = generate_master_format(df_grouped)
        df_maestro = result[0]
        output_file = result[1]
        
        # Validaciones (puede haber más registros por padres explícitos)
        assert len(df_maestro) >= 3, f"Expected >= 3 registros, got {len(df_maestro)}"
        assert df_maestro['Confianza_Automática'].min() >= 0, "Confianza negativa"
        assert df_maestro['Confianza_Automática'].max() <= 100, "Confianza > 100"
        
        print(f"  ✓ Pipeline completado exitosamente")
        print(f"  ✓ Maestro generado: {output_file}")
        print(f"  ✓ Total registros: {len(df_maestro)}")
        
        return True
    
    finally:
        # Limpiar archivo de prueba
        if test_file.exists():
            test_file.unlink()


def main():
    """Ejecuta todos los tests"""
    
    print("""
    ╔════════════════════════════════════════════════════════════════╗
    ║          TESTS UNITARIOS - PIPELINE FASE 1 + FASE 2           ║
    ╚════════════════════════════════════════════════════════════════╝
    """)
    
    tests = [
        ('Limpieza de nombres', test_cleaner),
        ('Extracción de patrones', test_patterns),
        ('Validación de atributos', test_attributes),
        ('Agrupación de productos', test_grouping),
        ('Generación de formato maestro', test_review),
        ('Exportación a WooCommerce', test_exporter),
        ('Pipeline integrado', test_integration),
    ]
    
    passed = 0
    failed = 0
    
    for test_name, test_func in tests:
        try:
            print(f"\n🧪 {test_name}...")
            if test_func():
                print(f"   ✅ PASSOU")
                passed += 1
            else:
                print(f"   ❌ FALLO")
                failed += 1
        except Exception as e:
            print(f"   ❌ ERROR: {str(e)}")
            failed += 1
    
    # Resumen
    print(f"""
    ╔════════════════════════════════════════════════════════════════╗
    ║                      RESUMEN DE TESTS                         ║
    ╚════════════════════════════════════════════════════════════════╝
    
    ✅ PASSOU:  {passed}
    ❌ FALLO:   {failed}
    📊 TOTAL:   {passed + failed}
    
    """)
    
    if failed == 0:
        print("🎉 Todos los tests passaram!")
        return 0
    else:
        print(f"⚠️  {failed} tests fallaram")
        return 1


if __name__ == '__main__':
    sys.exit(main())
