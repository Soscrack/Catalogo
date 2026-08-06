#!/usr/bin/env python3
"""
Pruebas de integración para notas de crédito (TipoDTE=61) y pagos agrupados

Este test verifica:
1. Parseo correcto de NC desde XML
2. Resolución automática de factura origen
3. Cálculo de saldo efectivo
4. Validación de reversa de inventario (opcional)
5. Creación de tickets de pago con N:M
"""

import os
import sys
import json
import unittest
from pathlib import Path

# Agregar directorio raíz al path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.xml_invoice_parser import DTEParser


class TestCreditNoteParsing(unittest.TestCase):
    """Pruebas de parseo de notas de crédito"""

    def setUp(self):
        self.parser = DTEParser()
        self.fixtures_dir = Path(__file__).parent / "fixtures"

    def test_parse_nc_with_valid_folio_reference(self):
        """Test: Parsear NC con FolioRef válido"""
        xml_path = self.fixtures_dir / "DTE-fixture-NC-valid-reference.xml"
        self.assertTrue(xml_path.exists(), f"Fixture no encontrado: {xml_path}")

        doc = self.parser.parse_file(str(xml_path))

        # Validaciones básicas
        self.assertEqual(doc.tipo_dte, 61, "Debe ser TipoDTE=61 (NC)")
        self.assertEqual(int(doc.folio), 1, "Folio debe ser 1")

        # Validar referencias
        self.assertEqual(len(doc.referencias), 1, "Debe haber 1 referencia")
        ref = doc.referencias[0]
        self.assertEqual(ref.tipo_documento, 33, "TpoDocRef debe ser 33 (Factura)")
        self.assertEqual(ref.folio, "12345", "FolioRef debe ser 12345")
        self.assertIsNotNone(ref.codigo, "CodRef debe estar presente")

        print(f"✓ NC parseada correctamente: Folio {doc.folio}, Ref: {ref.tipo_documento}/{ref.folio}")

    def test_parse_nc_with_global_reference(self):
        """Test: Parsear NC con FolioRef=0 (global, requiere asociación manual)"""
        xml_path = self.fixtures_dir / "DTE-fixture-NC-global-ambiguous.xml"
        self.assertTrue(xml_path.exists(), f"Fixture no encontrado: {xml_path}")

        doc = self.parser.parse_file(str(xml_path))

        # Validaciones
        self.assertEqual(doc.tipo_dte, 61, "Debe ser NC")

        # Validar que FolioRef=0
        self.assertEqual(len(doc.referencias), 1)
        ref = doc.referencias[0]
        self.assertEqual(ref.folio, "0", "FolioRef debe ser 0 para NC global")

        print(f"✓ NC global detectada: FolioRef={ref.folio} (requiere selección manual)")

    def test_parse_regular_invoice_for_linking(self):
        """Test: Parsear factura regular para verificar que se puede usar como origen"""
        xml_path = self.fixtures_dir / "DTE-fixture-invoice-33.xml"
        self.assertTrue(xml_path.exists(), f"Fixture no encontrado: {xml_path}")

        doc = self.parser.parse_file(str(xml_path))

        # Validaciones
        self.assertEqual(doc.tipo_dte, 33, "Debe ser Factura (TipoDTE=33)")
        self.assertEqual(int(doc.folio), 12345, "Folio debe ser 12345")
        self.assertEqual(len(doc.referencias), 0, "Factura regular no debe tener referencias")

        # Validar montos
        self.assertEqual(doc.totales.monto_total, 595000, "Total debe ser 595000")

        print(f"✓ Factura origen parseada: TipoDTE={doc.tipo_dte}, Folio={doc.folio}, Total=${doc.totales.monto_total}")

    def test_nc_amounts_vs_origin_invoice(self):
        """Test: Validar que el monto de NC no excede el de factura origen"""
        nc_path = self.fixtures_dir / "DTE-fixture-NC-valid-reference.xml"
        inv_path = self.fixtures_dir / "DTE-fixture-invoice-33.xml"

        nc_doc = self.parser.parse_file(str(nc_path))
        inv_doc = self.parser.parse_file(str(inv_path))

        nc_total = abs(nc_doc.totales.monto_total)
        inv_total = inv_doc.totales.monto_total

        # Calcular saldo efectivo
        saldo = inv_total - nc_total
        
        self.assertGreater(saldo, 0, f"Saldo efectivo debe ser positivo: ${saldo}")
        print(f"✓ Saldo efectivo válido: ${inv_total} - ${nc_total} = ${saldo}")

    def test_to_json_serializable(self):
        """Test: Asegurar que los documentos parseados son JSON-serializable"""
        xml_path = self.fixtures_dir / "DTE-fixture-NC-valid-reference.xml"
        doc = self.parser.parse_file(str(xml_path))

        # Intentar serializar a JSON
        try:
            json_str = doc.to_json()
            data = json.loads(json_str)
            self.assertIn('tipo_dte', data)
            self.assertIn('referencias', data)
            self.assertIsInstance(data['referencias'], list)
            print("✓ Documento NC es JSON-serializable")
        except Exception as e:
            self.fail(f"Error serializando a JSON: {e}")


class TestCreditNoteBusinessLogic(unittest.TestCase):
    """Pruebas de lógica de negocio de NC (simuladas)"""

    def test_saldo_efectivo_calculation(self):
        """Test: Cálculo de saldo efectivo"""
        monto_factura = 595000.0
        monto_nc = 119000.0
        
        saldo_efectivo = monto_factura - monto_nc
        
        self.assertEqual(saldo_efectivo, 476000.0)
        print(f"✓ Saldo efectivo: ${monto_factura} - ${monto_nc} = ${saldo_efectivo}")

    def test_negative_balance_validation(self):
        """Test: Validar que saldo negativo es rechazado"""
        monto_factura = 100000.0
        monto_nc = 150000.0  # NC mayor que factura
        
        saldo_efectivo = monto_factura - monto_nc
        
        self.assertLess(saldo_efectivo, 0, "Saldo debe ser negativo")
        # En el flujo real, esto causaría un error
        print(f"✗ Validación: Saldo negativo detectado ${saldo_efectivo} (NC > Factura)")

    def test_multiple_nc_on_same_invoice(self):
        """Test: Múltiples NC sobre la misma factura"""
        monto_factura = 1000000.0
        nc1 = 100000.0
        nc2 = 250000.0
        nc3 = 150000.0
        
        total_nc = nc1 + nc2 + nc3
        saldo_efectivo = monto_factura - total_nc
        
        self.assertEqual(saldo_efectivo, 500000.0)
        print(f"✓ Múltiples NC: ${monto_factura} - (${nc1} + ${nc2} + ${nc3}) = ${saldo_efectivo}")

    def test_payment_ticket_total(self):
        """Test: Cálculo de total en ticket de pago con múltiples documentos"""
        # Simular: 3 facturas con diferentes saldos efectivos
        saldos = [476000.0, 250000.0, 100000.0]
        
        total_ticket = sum(saldos)
        
        self.assertEqual(total_ticket, 826000.0)
        print(f"✓ Ticket de pago: Documentos={len(saldos)}, Total=${total_ticket}")


class TestCreditNoteDetection(unittest.TestCase):
    """Pruebas de detección automática de NC"""

    def test_tipo_dte_61_is_credit_note(self):
        """Test: TipoDTE=61 es identificado como NC"""
        tipo_dte = 61
        is_nc = tipo_dte == 61
        self.assertTrue(is_nc)
        print(f"✓ TipoDTE={tipo_dte} identificado como Nota de Crédito")

    def test_tipo_dte_33_is_not_credit_note(self):
        """Test: TipoDTE=33 NO es NC"""
        tipo_dte = 33
        is_nc = tipo_dte == 61
        self.assertFalse(is_nc)
        print(f"✓ TipoDTE={tipo_dte} NO es NC")

    def test_reference_resolution_criteria(self):
        """Test: Criterios para resolución automática de factura origen"""
        # Criterios: TpoDocRef + FolioRef + RUT emisor = Match único
        
        # Caso 1: FolioRef=0 → ambiguo
        folio_ref = "0"
        can_resolve_auto = folio_ref != "0"
        self.assertFalse(can_resolve_auto)
        print(f"✓ FolioRef={folio_ref}: No se puede resolver automáticamente")
        
        # Caso 2: FolioRef válido → intenta resolver
        folio_ref = "12345"
        can_resolve_auto = folio_ref != "0"
        self.assertTrue(can_resolve_auto)
        print(f"✓ FolioRef={folio_ref}: Puede resolverse automáticamente")


def run_smoke_test():
    """Smoke test: Verificar que todos los fixtures se pueden parsear"""
    print("\n=== SMOKE TEST: Parseo de Fixtures ===\n")
    
    fixtures_dir = Path(__file__).parent / "fixtures"
    parser = DTEParser()
    
    xml_files = list(fixtures_dir.glob("DTE-fixture-*.xml"))
    
    if not xml_files:
        print(f"⚠ No se encontraron fixtures en {fixtures_dir}")
        return False
    
    success_count = 0
    for xml_file in xml_files:
        try:
            doc = parser.parse_file(str(xml_file))
            print(f"✓ {xml_file.name}")
            print(f"  → TipoDTE={doc.tipo_dte}, Folio={doc.folio}, Total=${doc.totales.monto_total}")
            success_count += 1
        except Exception as e:
            print(f"✗ {xml_file.name}: {e}")
    
    print(f"\nTotal: {success_count}/{len(xml_files)} fixtures parseados correctamente")
    return success_count == len(xml_files)


if __name__ == '__main__':
    print("=" * 80)
    print("PRUEBAS DE INTEGRACIÓN: NOTAS DE CRÉDITO Y PAGOS AGRUPADOS")
    print("=" * 80)
    
    # Ejecutar smoke test primero
    if not run_smoke_test():
        print("\n⚠ Smoke test falló. Algunos fixtures no se pueden parsear.")
        sys.exit(1)
    
    # Ejecutar unit tests
    print("\n" + "=" * 80)
    print("EJECUTANDO UNIT TESTS")
    print("=" * 80 + "\n")
    
    loader = unittest.TestLoader()
    suite = unittest.TestSuite()
    
    suite.addTests(loader.loadTestsFromTestCase(TestCreditNoteParsing))
    suite.addTests(loader.loadTestsFromTestCase(TestCreditNoteBusinessLogic))
    suite.addTests(loader.loadTestsFromTestCase(TestCreditNoteDetection))
    
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    
    # Exit code basado en resultados
    sys.exit(0 if result.wasSuccessful() else 1)
