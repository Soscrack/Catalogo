"""
Parser de facturas XML DTE (Documento Tributario Electrónico) - Chile SII

Este módulo extrae información estructurada de documentos tributarios electrónicos
chilenos para su integración con sistemas de inventario y WooCommerce.

Tipos DTE soportados:
- 33: Factura Electrónica
- 34: Factura No Afecta o Exenta
- 52: Guía de Despacho
- 61: Nota de Crédito Electrónica

Uso:
    from xml_invoice_parser import DTEParser
    
    parser = DTEParser()
    factura = parser.parse_file('factura.xml')
    print(factura.to_dict())
"""

import os
import re
import json
import logging
from datetime import datetime
from dataclasses import dataclass, field, asdict
from typing import List, Optional, Dict, Any
from pathlib import Path

try:
    from lxml import etree
except ImportError:
    import xml.etree.ElementTree as etree
    print("Advertencia: lxml no instalado, usando xml.etree (menos robusto para encoding)")

# Configurar logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Namespace SII
NS = {'sii': 'http://www.sii.cl/SiiDte'}

# Tipos de DTE
TIPOS_DTE = {
    33: 'Factura Electrónica',
    34: 'Factura No Afecta o Exenta',
    52: 'Guía de Despacho',
    61: 'Nota de Crédito Electrónica',
    56: 'Nota de Débito Electrónica',
    110: 'Factura de Exportación',
    111: 'Nota de Débito de Exportación',
    112: 'Nota de Crédito de Exportación'
}


@dataclass
class ItemDetalle:
    """Representa un ítem/línea de detalle en el documento"""
    numero_linea: int
    codigo_tipo: Optional[str] = None
    codigo_valor: Optional[str] = None
    nombre: str = ''
    descripcion: Optional[str] = None
    cantidad: float = 0.0
    unidad: Optional[str] = None
    precio_unitario: float = 0.0
    descuento_porcentaje: Optional[float] = None
    descuento_monto: Optional[float] = None
    recargo_monto: Optional[float] = None
    monto_item: float = 0.0
    codigo_impuesto_adicional: Optional[int] = None
    
    def to_dict(self) -> Dict[str, Any]:
        return {k: v for k, v in asdict(self).items() if v is not None}


@dataclass
class Emisor:
    """Información del emisor del documento"""
    rut: str
    razon_social: str
    giro: Optional[str] = None
    actividad_economica: List[int] = field(default_factory=list)
    sucursal: Optional[str] = None
    direccion: Optional[str] = None
    comuna: Optional[str] = None
    ciudad: Optional[str] = None
    codigo_vendedor: Optional[str] = None
    correo: Optional[str] = None


@dataclass
class Receptor:
    """Información del receptor del documento"""
    rut: str
    razon_social: str
    giro: Optional[str] = None
    direccion: Optional[str] = None
    comuna: Optional[str] = None
    ciudad: Optional[str] = None
    direccion_postal: Optional[str] = None
    comuna_postal: Optional[str] = None
    ciudad_postal: Optional[str] = None
    codigo_interno: Optional[str] = None
    correo: Optional[str] = None


@dataclass
class Totales:
    """Totales del documento"""
    monto_neto: float = 0.0
    monto_exento: float = 0.0
    tasa_iva: float = 19.0
    iva: float = 0.0
    monto_total: float = 0.0
    impuestos_retenidos: List[Dict[str, Any]] = field(default_factory=list)


@dataclass
class Referencia:
    """Referencia a otro documento"""
    numero_linea: int
    tipo_documento: int
    folio: str
    fecha: Optional[str] = None
    codigo: Optional[int] = None
    razon: Optional[str] = None


@dataclass
class DocumentoDTE:
    """Documento Tributario Electrónico completo"""
    id_documento: str
    tipo_dte: int
    tipo_dte_nombre: str
    folio: str
    fecha_emision: str
    fecha_vencimiento: Optional[str] = None
    
    emisor: Optional[Emisor] = None
    receptor: Optional[Receptor] = None
    totales: Optional[Totales] = None
    
    items: List[ItemDetalle] = field(default_factory=list)
    referencias: List[Referencia] = field(default_factory=list)
    
    # Metadatos
    forma_pago: Optional[str] = None
    medio_pago: Optional[str] = None
    timestamp_firma: Optional[str] = None
    archivo_origen: Optional[str] = None
    
    def to_dict(self) -> Dict[str, Any]:
        """Convierte el documento a diccionario"""
        result = {
            'id_documento': self.id_documento,
            'tipo_dte': self.tipo_dte,
            'tipo_dte_nombre': self.tipo_dte_nombre,
            'folio': self.folio,
            'fecha_emision': self.fecha_emision,
            'fecha_vencimiento': self.fecha_vencimiento,
            'forma_pago': self.forma_pago,
            'medio_pago': self.medio_pago,
            'timestamp_firma': self.timestamp_firma,
            'archivo_origen': self.archivo_origen,
        }
        
        if self.emisor:
            result['emisor'] = asdict(self.emisor)
        if self.receptor:
            result['receptor'] = asdict(self.receptor)
        if self.totales:
            result['totales'] = asdict(self.totales)
        
        result['items'] = [item.to_dict() for item in self.items]
        result['referencias'] = [asdict(ref) for ref in self.referencias]
        
        return result
    
    def to_json(self, indent: int = 2) -> str:
        """Convierte el documento a JSON"""
        return json.dumps(self.to_dict(), indent=indent, ensure_ascii=False, default=str)
    
    def get_codigos_proveedor(self) -> List[Dict[str, str]]:
        """Extrae los códigos de proveedor de todos los items"""
        codigos = []
        for item in self.items:
            if item.codigo_valor:
                codigos.append({
                    'codigo': item.codigo_valor,
                    'tipo': item.codigo_tipo or 'DESCONOCIDO',
                    'nombre': item.nombre,
                    'cantidad': item.cantidad,
                    'precio': item.precio_unitario
                })
        return codigos
    
    def resumen(self) -> str:
        """Genera un resumen legible del documento"""
        lines = [
            f"{'='*60}",
            f"DTE: {self.tipo_dte_nombre} (Tipo {self.tipo_dte})",
            f"Folio: {self.folio}",
            f"Fecha: {self.fecha_emision}",
            f"{'='*60}",
        ]
        
        if self.emisor:
            lines.append(f"EMISOR: {self.emisor.razon_social} ({self.emisor.rut})")
        if self.receptor:
            lines.append(f"RECEPTOR: {self.receptor.razon_social} ({self.receptor.rut})")
        
        lines.append(f"\nITEMS ({len(self.items)}):")
        lines.append("-" * 60)
        
        for item in self.items:
            codigo = item.codigo_valor or 'SIN CÓDIGO'
            lines.append(f"  [{codigo}] {item.nombre}")
            lines.append(f"    Cant: {item.cantidad} {item.unidad or ''} x ${item.precio_unitario:,.0f} = ${item.monto_item:,.0f}")
        
        if self.totales:
            lines.append("-" * 60)
            lines.append(f"NETO: ${self.totales.monto_neto:,.0f}")
            lines.append(f"IVA ({self.totales.tasa_iva}%): ${self.totales.iva:,.0f}")
            lines.append(f"TOTAL: ${self.totales.monto_total:,.0f}")
        
        return "\n".join(lines)


class DTEParser:
    """Parser de documentos DTE XML del SII Chile"""
    
    def __init__(self):
        self.encoding_fallbacks = ['ISO-8859-1', 'UTF-8', 'CP1252']
    
    def _find_element(self, parent, tag: str):
        """Busca un elemento con o sin namespace"""
        # Intentar con namespace
        elem = parent.find(f'sii:{tag}', NS)
        if elem is not None:
            return elem
        # Intentar sin namespace
        elem = parent.find(tag)
        if elem is not None:
            return elem
        # Intentar búsqueda local
        for child in parent:
            local_name = child.tag.split('}')[-1] if '}' in child.tag else child.tag
            if local_name == tag:
                return child
        return None
    
    def _find_all(self, parent, tag: str):
        """Busca todos los elementos con o sin namespace"""
        # Intentar con namespace
        elems = parent.findall(f'sii:{tag}', NS)
        if elems:
            return elems
        # Intentar sin namespace
        elems = parent.findall(tag)
        if elems:
            return elems
        # Búsqueda local
        result = []
        for child in parent:
            local_name = child.tag.split('}')[-1] if '}' in child.tag else child.tag
            if local_name == tag:
                result.append(child)
        return result
    
    def _get_text(self, parent, tag: str, default: str = None) -> Optional[str]:
        """Obtiene el texto de un elemento hijo"""
        elem = self._find_element(parent, tag)
        if elem is not None and elem.text:
            return elem.text.strip()
        return default
    
    def _get_float(self, parent, tag: str, default: float = 0.0) -> float:
        """Obtiene un valor float de un elemento hijo"""
        text = self._get_text(parent, tag)
        if text:
            try:
                return float(text.replace(',', '.'))
            except ValueError:
                logger.warning(f"No se pudo convertir '{text}' a float para {tag}")
        return default
    
    def _get_int(self, parent, tag: str, default: int = 0) -> int:
        """Obtiene un valor int de un elemento hijo"""
        text = self._get_text(parent, tag)
        if text:
            try:
                return int(text)
            except ValueError:
                logger.warning(f"No se pudo convertir '{text}' a int para {tag}")
        return default
    
    def _parse_emisor(self, elem) -> Emisor:
        """Parsea la información del emisor"""
        acteco = []
        for act in self._find_all(elem, 'Acteco'):
            if act.text:
                try:
                    acteco.append(int(act.text))
                except ValueError:
                    pass
        
        return Emisor(
            rut=self._get_text(elem, 'RUTEmisor', ''),
            razon_social=self._get_text(elem, 'RznSoc', ''),
            giro=self._get_text(elem, 'GiroEmis'),
            actividad_economica=acteco,
            sucursal=self._get_text(elem, 'Sucursal'),
            direccion=self._get_text(elem, 'DirOrigen'),
            comuna=self._get_text(elem, 'CmnaOrigen'),
            ciudad=self._get_text(elem, 'CiudadOrigen'),
            codigo_vendedor=self._get_text(elem, 'CdgVendedor'),
            correo=self._get_text(elem, 'CorreoEmisor')
        )
    
    def _parse_receptor(self, elem) -> Receptor:
        """Parsea la información del receptor"""
        return Receptor(
            rut=self._get_text(elem, 'RUTRecep', ''),
            razon_social=self._get_text(elem, 'RznSocRecep', ''),
            giro=self._get_text(elem, 'GiroRecep'),
            direccion=self._get_text(elem, 'DirRecep'),
            comuna=self._get_text(elem, 'CmnaRecep'),
            ciudad=self._get_text(elem, 'CiudadRecep'),
            direccion_postal=self._get_text(elem, 'DirPostal'),
            comuna_postal=self._get_text(elem, 'CmnaPostal'),
            ciudad_postal=self._get_text(elem, 'CiudadPostal'),
            codigo_interno=self._get_text(elem, 'CdgIntRecep'),
            correo=self._get_text(elem, 'CorreoRecep')
        )
    
    def _parse_totales(self, elem) -> Totales:
        """Parsea los totales del documento"""
        impuestos = []
        for imp in self._find_all(elem, 'ImptoReten'):
            impuestos.append({
                'tipo': self._get_int(imp, 'TipoImp'),
                'tasa': self._get_float(imp, 'TasaImp'),
                'monto': self._get_float(imp, 'MontoImp')
            })
        
        return Totales(
            monto_neto=self._get_float(elem, 'MntNeto'),
            monto_exento=self._get_float(elem, 'MntExe'),
            tasa_iva=self._get_float(elem, 'TasaIVA', 19.0),
            iva=self._get_float(elem, 'IVA'),
            monto_total=self._get_float(elem, 'MntTotal'),
            impuestos_retenidos=impuestos
        )
    
    def _parse_detalle(self, elem) -> ItemDetalle:
        """Parsea una línea de detalle"""
        codigo_tipo = None
        codigo_valor = None
        
        cdg_item = self._find_element(elem, 'CdgItem')
        if cdg_item is not None:
            codigo_tipo = self._get_text(cdg_item, 'TpoCodigo')
            codigo_valor = self._get_text(cdg_item, 'VlrCodigo')
            # Limpiar código EAN de ceros a la izquierda si es muy largo
            if codigo_valor and codigo_tipo == 'EAN' and len(codigo_valor) > 13:
                codigo_valor = codigo_valor.lstrip('0') or '0'
        
        return ItemDetalle(
            numero_linea=self._get_int(elem, 'NroLinDet', 0),
            codigo_tipo=codigo_tipo,
            codigo_valor=codigo_valor,
            nombre=self._get_text(elem, 'NmbItem', ''),
            descripcion=self._get_text(elem, 'DscItem'),
            cantidad=self._get_float(elem, 'QtyItem', 1.0),
            unidad=self._get_text(elem, 'UnmdItem'),
            precio_unitario=self._get_float(elem, 'PrcItem'),
            descuento_porcentaje=self._get_float(elem, 'DescuentoPct') or None,
            descuento_monto=self._get_float(elem, 'DescuentoMonto') or None,
            recargo_monto=self._get_float(elem, 'RecargoMonto') or None,
            monto_item=self._get_float(elem, 'MontoItem'),
            codigo_impuesto_adicional=self._get_int(elem, 'CodImpAdic') or None
        )
    
    def _parse_referencia(self, elem) -> Referencia:
        """Parsea una referencia a otro documento"""
        return Referencia(
            numero_linea=self._get_int(elem, 'NroLinRef', 0),
            tipo_documento=self._get_int(elem, 'TpoDocRef', 0),
            folio=self._get_text(elem, 'FolioRef', ''),
            fecha=self._get_text(elem, 'FchRef'),
            codigo=self._get_int(elem, 'CodRef') or None,
            razon=self._get_text(elem, 'RazonRef')
        )
    
    def parse_content(self, xml_content: bytes, filename: str = None) -> DocumentoDTE:
        """Parsea contenido XML como bytes"""
        # Intentar parsear con diferentes encodings si falla
        tree = None
        for encoding in self.encoding_fallbacks:
            try:
                if hasattr(etree, 'fromstring'):
                    # lxml o ElementTree
                    tree = etree.fromstring(xml_content)
                else:
                    tree = etree.ElementTree(etree.fromstring(xml_content)).getroot()
                break
            except Exception as e:
                logger.debug(f"Fallo parseo con encoding implícito: {e}")
                try:
                    content_str = xml_content.decode(encoding)
                    tree = etree.fromstring(content_str.encode('utf-8'))
                    break
                except Exception:
                    continue
        
        if tree is None:
            raise ValueError("No se pudo parsear el XML con ningún encoding")
        
        return self._parse_tree(tree, filename)
    
    def parse_file(self, filepath: str) -> DocumentoDTE:
        """Parsea un archivo XML DTE"""
        filepath = Path(filepath)
        
        if not filepath.exists():
            raise FileNotFoundError(f"Archivo no encontrado: {filepath}")
        
        with open(filepath, 'rb') as f:
            content = f.read()
        
        return self.parse_content(content, str(filepath))
    
    def _parse_tree(self, root, filename: str = None) -> DocumentoDTE:
        """Parsea el árbol XML"""
        # Encontrar el documento
        documento = self._find_element(root, 'Documento')
        if documento is None:
            # El root podría ser el documento mismo
            documento = root
        
        # Obtener ID del documento
        id_doc = documento.get('ID', '')
        
        # Parsear encabezado
        encabezado = self._find_element(documento, 'Encabezado')
        if encabezado is None:
            raise ValueError("No se encontró el Encabezado del documento")
        
        id_doc_elem = self._find_element(encabezado, 'IdDoc')
        if id_doc_elem is None:
            raise ValueError("No se encontró IdDoc en el encabezado")
        
        tipo_dte = self._get_int(id_doc_elem, 'TipoDTE')
        tipo_dte_nombre = TIPOS_DTE.get(tipo_dte, f'Tipo {tipo_dte}')
        
        # Parsear emisor y receptor
        emisor_elem = self._find_element(encabezado, 'Emisor')
        receptor_elem = self._find_element(encabezado, 'Receptor')
        totales_elem = self._find_element(encabezado, 'Totales')
        
        emisor = self._parse_emisor(emisor_elem) if emisor_elem is not None else None
        receptor = self._parse_receptor(receptor_elem) if receptor_elem is not None else None
        totales = self._parse_totales(totales_elem) if totales_elem is not None else None
        
        # Parsear detalles
        items = []
        for detalle in self._find_all(documento, 'Detalle'):
            items.append(self._parse_detalle(detalle))
        
        # Parsear referencias
        referencias = []
        for ref in self._find_all(documento, 'Referencia'):
            referencias.append(self._parse_referencia(ref))
        
        # Timestamp de firma
        timestamp = self._get_text(documento, 'TmstFirma')
        
        return DocumentoDTE(
            id_documento=id_doc,
            tipo_dte=tipo_dte,
            tipo_dte_nombre=tipo_dte_nombre,
            folio=self._get_text(id_doc_elem, 'Folio', ''),
            fecha_emision=self._get_text(id_doc_elem, 'FchEmis', ''),
            fecha_vencimiento=self._get_text(id_doc_elem, 'FchVenc'),
            emisor=emisor,
            receptor=receptor,
            totales=totales,
            items=items,
            referencias=referencias,
            forma_pago=self._get_text(id_doc_elem, 'FmaPago'),
            medio_pago=self._get_text(id_doc_elem, 'MedioPago'),
            timestamp_firma=timestamp,
            archivo_origen=filename
        )
    
    def parse_directory(self, directory: str, pattern: str = "*.xml") -> List[DocumentoDTE]:
        """Parsea todos los archivos XML de un directorio"""
        directory = Path(directory)
        documentos = []
        errores = []
        
        for filepath in directory.glob(pattern):
            try:
                doc = self.parse_file(str(filepath))
                documentos.append(doc)
                logger.info(f"✓ Parseado: {filepath.name} - {doc.tipo_dte_nombre} #{doc.folio}")
            except Exception as e:
                errores.append((str(filepath), str(e)))
                logger.error(f"✗ Error en {filepath.name}: {e}")
        
        if errores:
            logger.warning(f"Se encontraron {len(errores)} errores de {len(documentos) + len(errores)} archivos")
        
        return documentos


def extraer_codigos_facturas(directorio: str, salida_json: str = None) -> Dict[str, List[Dict]]:
    """
    Extrae todos los códigos de proveedor de las facturas en un directorio
    
    Returns:
        Diccionario con RUT de proveedor como clave y lista de códigos como valor
    """
    parser = DTEParser()
    documentos = parser.parse_directory(directorio)
    
    codigos_por_proveedor = {}
    
    for doc in documentos:
        if doc.emisor:
            rut = doc.emisor.rut
            if rut not in codigos_por_proveedor:
                codigos_por_proveedor[rut] = {
                    'razon_social': doc.emisor.razon_social,
                    'codigos': []
                }
            
            for item in doc.items:
                if item.codigo_valor:
                    codigo_info = {
                        'codigo': item.codigo_valor,
                        'tipo': item.codigo_tipo,
                        'nombre': item.nombre,
                        'folio': doc.folio,
                        'fecha': doc.fecha_emision
                    }
                    # Evitar duplicados
                    if codigo_info not in codigos_por_proveedor[rut]['codigos']:
                        codigos_por_proveedor[rut]['codigos'].append(codigo_info)
    
    if salida_json:
        with open(salida_json, 'w', encoding='utf-8') as f:
            json.dump(codigos_por_proveedor, f, indent=2, ensure_ascii=False)
        logger.info(f"Códigos guardados en: {salida_json}")
    
    return codigos_por_proveedor


# ============================================
# CLI
# ============================================

if __name__ == '__main__':
    import sys
    
    if len(sys.argv) < 2:
        print("Uso: python xml_invoice_parser.py <archivo.xml|directorio> [salida.json]")
        print("\nEjemplos:")
        print("  python xml_invoice_parser.py factura.xml")
        print("  python xml_invoice_parser.py XML/")
        print("  python xml_invoice_parser.py XML/ codigos_proveedores.json")
        sys.exit(1)
    
    ruta = sys.argv[1]
    salida = sys.argv[2] if len(sys.argv) > 2 else None
    
    parser = DTEParser()
    
    if os.path.isfile(ruta):
        # Parsear archivo individual
        doc = parser.parse_file(ruta)
        print(doc.resumen())
        print("\n" + "="*60)
        print("CÓDIGOS DE PROVEEDOR:")
        for codigo in doc.get_codigos_proveedor():
            print(f"  [{codigo['tipo']}] {codigo['codigo']}: {codigo['nombre']}")
        
        if salida:
            with open(salida, 'w', encoding='utf-8') as f:
                f.write(doc.to_json())
            print(f"\nJSON guardado en: {salida}")
    
    elif os.path.isdir(ruta):
        # Parsear directorio
        print(f"Parseando directorio: {ruta}\n")
        codigos = extraer_codigos_facturas(ruta, salida)
        
        print("\n" + "="*60)
        print("RESUMEN DE CÓDIGOS POR PROVEEDOR:")
        print("="*60)
        
        for rut, data in codigos.items():
            print(f"\n{data['razon_social']} ({rut})")
            print(f"  Total códigos únicos: {len(data['codigos'])}")
            for codigo in data['codigos'][:5]:
                print(f"    - [{codigo['tipo']}] {codigo['codigo']}: {codigo['nombre'][:40]}...")
            if len(data['codigos']) > 5:
                print(f"    ... y {len(data['codigos']) - 5} más")
    
    else:
        print(f"Error: {ruta} no es un archivo ni directorio válido")
        sys.exit(1)
