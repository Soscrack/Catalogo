"""
EXPORTER.PY - Exportación a WooCommerce con Validaciones
Responsabilidad: Exportar productos revisados a CSV para WooCommerce
Método: Validar datos, filtrar aprobados, generar CSV final
Salida: CSV en data/reviewed/ listo para importar
"""

import pandas as pd
import logging
from typing import Dict, List, Tuple, Optional
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
import re

logger = logging.getLogger(__name__)


@dataclass
class ValidationError:
    """Representa un error de validación."""
    sku: str
    field: str
    message: str
    severity: str = 'error'  # 'error' o 'warning'


@dataclass
class ExportReport:
    """Reporte de exportación."""
    total_input: int = 0
    approved_count: int = 0
    rejected_count: int = 0
    exported_count: int = 0
    errors: List[ValidationError] = field(default_factory=list)
    warnings: List[ValidationError] = field(default_factory=list)
    output_path: Optional[Path] = None
    
    def add_error(self, sku: str, field: str, message: str):
        self.errors.append(ValidationError(sku, field, message, 'error'))
    
    def add_warning(self, sku: str, field: str, message: str):
        self.warnings.append(ValidationError(sku, field, message, 'warning'))
    
    def is_valid(self) -> bool:
        return len(self.errors) == 0
    
    def summary(self) -> str:
        lines = [
            "",
            "╔════════════════════════════════════════════════════════════════╗",
            "║             REPORTE DE EXPORTACIÓN WOOCOMMERCE                ║",
            "╚════════════════════════════════════════════════════════════════╝",
            "",
            f"📊 ESTADÍSTICAS:",
            f"   • Total productos entrada: {self.total_input}",
            f"   • Aprobados (Revisado_Humano=Sí): {self.approved_count}",
            f"   • Rechazados/Pendientes: {self.rejected_count}",
            f"   • Exportados: {self.exported_count}",
            "",
        ]
        
        if self.errors:
            lines.append(f"❌ ERRORES ({len(self.errors)}):")
            for err in self.errors[:10]:
                lines.append(f"   • [{err.sku}] {err.field}: {err.message}")
            if len(self.errors) > 10:
                lines.append(f"   ... y {len(self.errors) - 10} errores más")
            lines.append("")
        
        if self.warnings:
            lines.append(f"⚠️ ADVERTENCIAS ({len(self.warnings)}):")
            for warn in self.warnings[:10]:
                lines.append(f"   • [{warn.sku}] {warn.field}: {warn.message}")
            if len(self.warnings) > 10:
                lines.append(f"   ... y {len(self.warnings) - 10} advertencias más")
            lines.append("")
        
        if self.is_valid() and self.exported_count > 0:
            lines.append(f"✅ EXPORTACIÓN EXITOSA")
            lines.append(f"   Archivo: {self.output_path}")
        elif not self.is_valid():
            lines.append(f"❌ EXPORTACIÓN FALLIDA - Corregir errores antes de exportar")
        else:
            lines.append(f"⚠️ SIN PRODUCTOS PARA EXPORTAR")
        
        return "\n".join(lines)


class CSVExporter:
    """
    Exporta productos revisados a CSV para WooCommerce.
    
    REGLAS CRÍTICAS:
    - Solo exporta productos con Revisado_Humano = "Sí"
    - Valida SKUs únicos
    - Valida columnas obligatorias
    - Genera reporte de errores/warnings
    """
    
    # Columnas WooCommerce oficiales (sin columnas de auditoría)
    WOOCOMMERCE_COLUMNS = [
        'ID',
        'Tipo',
        'SKU',
        'GTIN, UPC, EAN o ISBN',
        'Nombre',
        'Publicado',
        '¿Está destacado?',
        'Visibilidad en el catálogo',
        'Descripción corta',
        'Descripción',
        'Día en que empieza el precio rebajado',
        'Día en que termina el precio rebajado',
        'Estado del impuesto',
        'Clase de impuesto',
        '¿En inventario?',
        'Inventario',
        'Cantidad de bajo inventario',
        '¿Permitir reservas de productos agotados?',
        '¿Vendido individualmente?',
        'Peso (kg)',
        'Longitud (cm)',
        'Ancho (cm)',
        'Altura (cm)',
        '¿Permitir valoraciones de clientes?',
        'Nota de compra',
        'Precio rebajado',
        'Precio normal',
        'Categorías',
        'Etiquetas',
        'Clase de envío',
        'Imágenes',
        'Límite de descargas',
        'Días de caducidad de la descarga',
        'Principal',
        'Productos agrupados',
        'Ventas dirigidas',
        'Ventas cruzadas',
        'URL externa',
        'Texto del botón',
        'Posición',
        'Marcas',
        'Nombre del atributo 1',
        'Valor(es) del atributo 1',
        'Atributo visible 1',
        'Atributo global 1',
        'Nombre del atributo 2',
        'Valor(es) del atributo 2',
        'Atributo visible 2',
        'Atributo global 2',
        'Nombre del atributo 3',
        'Valor(es) del atributo 3',
        'Atributo visible 3',
        'Atributo global 3',
        'Nombre del atributo 4',
        'Valor(es) del atributo 4',
        'Atributo visible 4',
        'Atributo global 4',
        'Nombre del atributo 5',
        'Valor(es) del atributo 5',
        'Atributo visible 5',
        'Atributo global 5',
        'Nombre del atributo 6',
        'Valor(es) del atributo 6',
        'Atributo visible 6',
        'Atributo global 6',
    ]
    
    # Columnas de auditoría (no se exportan a WooCommerce)
    AUDIT_COLUMNS = [
        'SKU_Original',
        'Confianza_Automática',
        'Revisado_Humano',
        'Notas_Revisión'
    ]
    
    # Columnas obligatorias para exportar
    REQUIRED_COLUMNS = ['SKU', 'Nombre', 'Tipo']
    
    def __init__(self, output_dir: str = 'data/reviewed'):
        """
        Inicializa exportador.
        
        Args:
            output_dir: Directorio donde guardar exportaciones
        """
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(parents=True, exist_ok=True)
        logger.info(f"Exportador inicializado. Directorio: {self.output_dir}")
    
    def validate_before_export(self, df: pd.DataFrame) -> ExportReport:
        """
        Valida datos antes de exportar.
        
        Validaciones:
        - Columnas obligatorias presentes
        - SKUs únicos (excepto vacíos para variaciones sin SKU propio)
        - Nombres no vacíos
        - Tipos válidos (simple, variable, variation)
        - Variables sin precio
        - Variaciones con referencia a padre
        
        Args:
            df: DataFrame con productos a validar
        
        Returns:
            ExportReport con errores y warnings
        """
        report = ExportReport(total_input=len(df))
        
        # 1. Validar columnas obligatorias
        missing_cols = [col for col in self.REQUIRED_COLUMNS if col not in df.columns]
        if missing_cols:
            report.add_error('GLOBAL', 'columnas', f"Faltan columnas obligatorias: {missing_cols}")
            return report
        
        # 2. Validar SKUs únicos
        skus = df['SKU'].astype(str).str.strip()
        non_empty_skus = skus[skus != '']
        duplicated = non_empty_skus[non_empty_skus.duplicated(keep=False)]
        if len(duplicated) > 0:
            dup_values = duplicated.unique().tolist()[:5]
            report.add_error('GLOBAL', 'SKU', f"SKUs duplicados: {dup_values}")
        
        # 3. Validar cada producto
        for idx, row in df.iterrows():
            sku = str(row.get('SKU', f'ROW_{idx}')).strip() or f'ROW_{idx}'
            
            # Nombre no vacío
            nombre = str(row.get('Nombre', '')).strip()
            if len(nombre) < 3:
                report.add_error(sku, 'Nombre', f"Nombre muy corto o vacío: '{nombre}'")
            
            # Tipo válido
            tipo = str(row.get('Tipo', '')).strip().lower()
            if tipo not in ('simple', 'variable', 'variation'):
                report.add_error(sku, 'Tipo', f"Tipo inválido: '{tipo}'")
            
            # Variable sin precio
            if tipo == 'variable':
                precio = str(row.get('Precio normal', '')).strip()
                if precio and precio != '0' and precio != '0.0':
                    report.add_warning(sku, 'Precio', f"Producto variable no debe tener precio: '{precio}'")
            
            # Variación con referencia a padre
            if tipo == 'variation':
                principal = str(row.get('Principal', '')).strip()
                if not principal.startswith('id:'):
                    report.add_error(sku, 'Principal', f"Variación sin referencia a padre válida: '{principal}'")
            
            # Precio positivo para simple/variation
            if tipo in ('simple', 'variation'):
                precio = str(row.get('Precio normal', '')).strip()
                if precio:
                    try:
                        precio_val = float(precio.replace(',', '.'))
                        if precio_val < 0:
                            report.add_error(sku, 'Precio', f"Precio negativo: {precio_val}")
                    except ValueError:
                        report.add_warning(sku, 'Precio', f"Precio no numérico: '{precio}'")
            
            # Stock válido
            stock = str(row.get('Inventario', '')).strip()
            if stock:
                try:
                    stock_val = int(float(stock))
                    if stock_val < 0:
                        report.add_warning(sku, 'Inventario', f"Stock negativo: {stock_val}")
                except ValueError:
                    report.add_warning(sku, 'Inventario', f"Stock no numérico: '{stock}'")
        
        return report
    
    def filter_approved(self, df: pd.DataFrame) -> Tuple[pd.DataFrame, int, int]:
        """
        Filtra solo productos con Revisado_Humano = "Sí".
        
        REGLA CRÍTICA: Nunca exportar productos no revisados.
        
        Args:
            df: DataFrame con todos los productos
        
        Returns:
            Tupla (DataFrame filtrado, count aprobados, count rechazados)
        """
        if 'Revisado_Humano' not in df.columns:
            logger.warning("Columna 'Revisado_Humano' no encontrada. Asumiendo todos no revisados.")
            return pd.DataFrame(), 0, len(df)
        
        # Normalizar valores de Revisado_Humano
        revisado = df['Revisado_Humano'].astype(str).str.strip().str.lower()
        
        # Valores que cuentan como "aprobado"
        aprobado_mask = revisado.isin(['sí', 'si', 'yes', '1', 'true', 'aprobado', 'ok'])
        
        approved_df = df[aprobado_mask].copy()
        rejected_count = len(df) - len(approved_df)
        
        logger.info(f"Filtrado: {len(approved_df)} aprobados, {rejected_count} rechazados/pendientes")
        
        return approved_df, len(approved_df), rejected_count
    
    def format_for_woocommerce(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Formatea DataFrame para importación WooCommerce.
        
        - Selecciona solo columnas WooCommerce
        - Limpia valores
        - Convierte tipos de datos
        - Marca como publicado los productos aprobados
        
        Args:
            df: DataFrame con productos aprobados
        
        Returns:
            DataFrame listo para exportar
        """
        # Seleccionar solo columnas WooCommerce que existen
        woo_cols = [col for col in self.WOOCOMMERCE_COLUMNS if col in df.columns]
        export_df = df[woo_cols].copy()
        
        # Marcar como publicado (-1 = publicado en WooCommerce)
        if 'Publicado' in export_df.columns:
            export_df['Publicado'] = -1
        
        # Limpiar caracteres problemáticos
        for col in export_df.columns:
            if export_df[col].dtype == 'object':
                export_df[col] = export_df[col].fillna('').astype(str)
                # Eliminar saltos de línea en campos de texto
                export_df[col] = export_df[col].str.replace('\n', ' ', regex=False)
                export_df[col] = export_df[col].str.replace('\r', ' ', regex=False)
        
        return export_df
    
    def generate_csv(self, df: pd.DataFrame, filename: str = None) -> Path:
        """
        Genera archivo CSV para WooCommerce.
        
        Args:
            df: DataFrame formateado
            filename: Nombre del archivo (sin extensión). Si None, genera automático.
        
        Returns:
            Path del archivo generado
        """
        if filename is None:
            filename = f"woocommerce_export_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
        
        output_path = self.output_dir / f"{filename}.csv"
        
        # Guardar con UTF-8 BOM para compatibilidad con Excel
        df.to_csv(output_path, index=False, encoding='utf-8-sig', sep=',')
        
        logger.info(f"CSV generado: {output_path}")
        return output_path
    
    def export(self, df: pd.DataFrame, validate: bool = True, 
               filename: str = None) -> ExportReport:
        """
        Exporta productos revisados a CSV para WooCommerce.
        
        Flujo completo:
        1. Validar datos (opcional pero recomendado)
        2. Filtrar solo aprobados (Revisado_Humano = Sí)
        3. Formatear para WooCommerce
        4. Generar CSV
        5. Generar reporte
        
        Args:
            df: DataFrame con productos (formato maestro)
            validate: Si True, ejecuta validaciones previas
            filename: Nombre del archivo de salida (opcional)
        
        Returns:
            ExportReport con resultado de la exportación
        """
        logger.info(f"Iniciando exportación de {len(df)} productos...")
        
        report = ExportReport(total_input=len(df))
        
        # 1. Filtrar aprobados
        approved_df, approved_count, rejected_count = self.filter_approved(df)
        report.approved_count = approved_count
        report.rejected_count = rejected_count
        
        if approved_count == 0:
            report.add_warning('GLOBAL', 'Revisado_Humano', 
                             "No hay productos aprobados para exportar. "
                             "Marca 'Revisado_Humano' como 'Sí' en los productos a exportar.")
            logger.warning("No hay productos aprobados para exportar")
            return report
        
        # 2. Validar (si está habilitado)
        if validate:
            validation_report = self.validate_before_export(approved_df)
            report.errors.extend(validation_report.errors)
            report.warnings.extend(validation_report.warnings)
            
            if not validation_report.is_valid():
                logger.error(f"Validación fallida con {len(report.errors)} errores")
                return report
        
        # 3. Formatear para WooCommerce
        export_df = self.format_for_woocommerce(approved_df)
        
        # 4. Generar CSV
        output_path = self.generate_csv(export_df, filename)
        report.output_path = output_path
        report.exported_count = len(export_df)
        
        logger.info(f"✓ Exportación completada: {report.exported_count} productos")
        
        return report
    
    def export_from_file(self, input_path: str, validate: bool = True,
                         filename: str = None) -> ExportReport:
        """
        Exporta desde archivo Excel/CSV.
        
        Args:
            input_path: Path al archivo maestro (xlsx o csv)
            validate: Si True, ejecuta validaciones
            filename: Nombre del archivo de salida (opcional)
        
        Returns:
            ExportReport con resultado
        """
        input_path = Path(input_path)
        
        if not input_path.exists():
            report = ExportReport()
            report.add_error('GLOBAL', 'archivo', f"Archivo no encontrado: {input_path}")
            return report
        
        # Cargar archivo
        logger.info(f"Cargando archivo: {input_path}")
        
        if input_path.suffix.lower() == '.csv':
            df = pd.read_csv(input_path, encoding='utf-8-sig')
        else:
            df = pd.read_excel(input_path)
        
        logger.info(f"Cargados {len(df)} registros de {input_path.name}")
        
        return self.export(df, validate=validate, filename=filename)


# Función de conveniencia
def export_to_woocommerce(df_or_path, validate: bool = True, 
                          output_dir: str = 'data/reviewed') -> ExportReport:
    """
    Exporta productos revisados a CSV para WooCommerce.
    
    REGLA CRÍTICA: Solo exporta productos con Revisado_Humano = "Sí"
    
    Args:
        df_or_path: DataFrame o path al archivo maestro
        validate: Si True, valida antes de exportar
        output_dir: Directorio de salida
    
    Returns:
        ExportReport con resultado de la exportación
    
    Uso:
        # Desde DataFrame
        report = export_to_woocommerce(df)
        
        # Desde archivo
        report = export_to_woocommerce('data/processed/maestro_revision_xxx.xlsx')
        
        # Ver resultado
        print(report.summary())
    """
    exporter = CSVExporter(output_dir=output_dir)
    
    if isinstance(df_or_path, pd.DataFrame):
        return exporter.export(df_or_path, validate=validate)
    else:
        return exporter.export_from_file(str(df_or_path), validate=validate)


if __name__ == '__main__':
    import sys
    
    if len(sys.argv) < 2:
        print("""
        USO: python exporter.py <archivo_maestro.xlsx>
        
        Exporta productos revisados (Revisado_Humano=Sí) a CSV para WooCommerce.
        
        Ejemplos:
            python exporter.py data/processed/maestro_revision_20260201.xlsx
            python exporter.py data/processed/maestro_revision_20260201.csv
        """)
        sys.exit(1)
    
    input_file = sys.argv[1]
    report = export_to_woocommerce(input_file)
    print(report.summary())
    
    sys.exit(0 if report.is_valid() else 1)
