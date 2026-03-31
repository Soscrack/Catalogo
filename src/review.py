"""
REVIEW.PY - Generación de Formato Maestro para Revisión Humana
Responsabilidad: Crear Excel exacto con todas las columnas del formato maestro
Método: Mapear datos procesados al formato WooCommerce definitivo
Salida: Excel en data/processed/ listo para revisión humana
"""

import pandas as pd
import logging
from typing import Dict, List, Tuple, Optional
from datetime import datetime
from pathlib import Path
import re

logger = logging.getLogger(__name__)


class ReviewFormatter:
    """
    Genera formato maestro para revisión humana.
    - Mapea datos procesados al formato WooCommerce exacto
    - Calcula puntuación de confianza automática
    - Genera slugs y valores por defecto
    - Listo para descarga y corrección manual
    
    TIPOS DE PRODUCTO:
    - simple: producto sin variaciones
    - variable: producto padre (SIN precio ni stock directo)
    - variation: hijo del producto variable (precio y stock reales)
    """
    
    # Columnas exactas para importación WooCommerce (orden oficial)
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
        'Principal',  # Para variaciones: id:XX referencia al padre
        'Productos agrupados',
        'Ventas dirigidas',
        'Ventas cruzadas',
        'URL externa',
        'Texto del botón',
        'Posición',
        'Marcas',
        # Atributo 1
        'Nombre del atributo 1',
        'Valor(es) del atributo 1',
        'Atributo visible 1',
        'Atributo global 1',
        # Atributo 2
        'Nombre del atributo 2',
        'Valor(es) del atributo 2',
        'Atributo visible 2',
        'Atributo global 2',
        # Atributo 3
        'Nombre del atributo 3',
        'Valor(es) del atributo 3',
        'Atributo visible 3',
        'Atributo global 3',
        # Atributo 4
        'Nombre del atributo 4',
        'Valor(es) del atributo 4',
        'Atributo visible 4',
        'Atributo global 4',
        # Atributo 5
        'Nombre del atributo 5',
        'Valor(es) del atributo 5',
        'Atributo visible 5',
        'Atributo global 5',
        # Atributo 6
        'Nombre del atributo 6',
        'Valor(es) del atributo 6',
        'Atributo visible 6',
        'Atributo global 6',
    ]
    
    # Columnas adicionales de auditoría (solo para Excel interno, no WooCommerce)
    AUDIT_COLUMNS = [
        'SKU_Original',  # SKU del archivo fuente
        'Confianza_Automática',
        'Revisado_Humano',
        'Notas_Revisión'
    ]
    
    # Columnas combinadas para formato maestro interno
    MAESTRO_COLUMNS = WOOCOMMERCE_COLUMNS + AUDIT_COLUMNS
    
    def __init__(self):
        """Inicializa formateador."""
        logger.info("Formateador de revisión inicializado")
    
    def format_for_review(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Convierte DataFrame procesado al formato WooCommerce exacto.
        
        REGLAS CRÍTICAS:
        - variable: producto padre SIN precio ni stock
        - variation: hijo CON precio y stock, referencia al padre en 'Principal'
        - simple: producto independiente CON precio y stock
        
        Args:
            df: DataFrame con productos agrupados y validados
        
        Returns:
            DataFrame con formato WooCommerce exacto
        """
        logger.info(f"Formateando {len(df)} productos para WooCommerce...")
        
        # Crear DataFrame resultado
        review_df = pd.DataFrame()
        
        # --- ID: Se asigna después de crear padres explícitos ---
        review_df['ID'] = None
        
        # --- Tipo: simple | variable | variation ---
        # Inicialmente desde el procesamiento previo
        review_df['Tipo'] = df.get('Tipo', 'simple').fillna('simple')
        
        # SKU: Preservar SKU original del archivo
        if 'SKU' in df.columns:
            review_df['SKU'] = df['SKU'].fillna('')
        else:
            review_df['SKU'] = ''
        
        # Guardar SKU original para auditoría
        if 'SKU_Origen' in df.columns:
            review_df['SKU_Original'] = df['SKU_Origen'].fillna('')
        elif 'SKU' in df.columns:
            review_df['SKU_Original'] = df['SKU'].fillna('')
        else:
            review_df['SKU_Original'] = ''
        
        # GTIN/EAN (código de barras si existe)
        if 'Código de barras' in df.columns:
            review_df['GTIN, UPC, EAN o ISBN'] = df['Código de barras'].fillna('')
        else:
            review_df['GTIN, UPC, EAN o ISBN'] = ''
        
        # Nombre
        review_df['Nombre'] = df['Nombre_Limpio'].fillna('')
        
        # Publicado: -1 = publicado, 0 = borrador
        review_df['Publicado'] = 0  # Borrador hasta revisión
        
        # ¿Está destacado?
        review_df['¿Está destacado?'] = 0
        
        # Visibilidad en el catálogo
        review_df['Visibilidad en el catálogo'] = 'visible'
        
        # Descripciones
        if 'Descripción ecommerce' in df.columns:
            review_df['Descripción corta'] = df['Descripción ecommerce'].fillna(df['Nombre_Limpio']).fillna('')
        else:
            review_df['Descripción corta'] = df['Nombre_Limpio'].fillna('')
        
        if 'Descripción' in df.columns:
            review_df['Descripción'] = df['Descripción'].fillna('')
        else:
            review_df['Descripción'] = ''
        
        # Fechas de precio rebajado (vacías)
        review_df['Día en que empieza el precio rebajado'] = ''
        review_df['Día en que termina el precio rebajado'] = ''
        
        # Impuestos
        review_df['Estado del impuesto'] = 'taxable'
        # Clase de impuesto: 'parent' para variaciones, vacío para el resto
        review_df['Clase de impuesto'] = review_df['Tipo'].apply(lambda x: 'parent' if x == 'variation' else '')
        
        # Inventario: ¿En inventario? 1=sí, 0=no
        review_df['¿En inventario?'] = 1
        
        # Inventario (stock): SOLO para simple y variation
        # El stock real viene de "Disponibilidad en: Bodega general"
        if 'Disponibilidad en: Bodega general' in df.columns:
            review_df['Inventario'] = df['Disponibilidad en: Bodega general'].fillna('')
        elif 'Stock' in df.columns:
            review_df['Inventario'] = df['Stock'].fillna('')
        else:
            review_df['Inventario'] = ''
        
        # "Stock mínimo" va a "Cantidad de bajo inventario"
        if 'Stock mínimo' in df.columns:
            review_df['Cantidad de bajo inventario'] = df['Stock mínimo'].fillna('')
        else:
            review_df['Cantidad de bajo inventario'] = ''
        
        review_df['¿Permitir reservas de productos agotados?'] = 0
        review_df['¿Vendido individualmente?'] = 0
        
        # Dimensiones (vacías por defecto)
        review_df['Peso (kg)'] = ''
        review_df['Longitud (cm)'] = ''
        review_df['Ancho (cm)'] = ''
        review_df['Altura (cm)'] = ''
        
        # Valoraciones
        review_df['¿Permitir valoraciones de clientes?'] = 1
        review_df['Nota de compra'] = ''
        
        # Precios: SOLO para simple y variation (NO para variable)
        if 'Venta: Precio total' in df.columns:
            review_df['Precio normal'] = df['Venta: Precio total'].fillna('')
        else:
            review_df['Precio normal'] = ''
        
        review_df['Precio rebajado'] = ''  # Se completa en revisión
        
        # Categorías (usar del archivo original si existe)
        if 'Categoria' in df.columns:
            review_df['Categorías'] = df['Categoria'].fillna(df.get('Familia_Detectada', 'Otros')).fillna('Otros')
        else:
            review_df['Categorías'] = df.get('Familia_Detectada', '').fillna('Otros')
        
        # Etiquetas (de atributos)
        review_df['Etiquetas'] = df.apply(self._generate_tags, axis=1)
        
        # Clase de envío
        review_df['Clase de envío'] = ''
        
        # Imágenes (vacías)
        review_df['Imágenes'] = ''
        
        # Descargas (no aplica para productos físicos)
        review_df['Límite de descargas'] = ''
        review_df['Días de caducidad de la descarga'] = ''
        
        # Principal: Para variaciones, referencia al padre (id:XX)
        # Se llena después de crear padres explícitos
        review_df['Principal'] = ''
        
        # SKU_Parent temporal (para procesamiento interno)
        if 'SKU_Parent' in df.columns:
            review_df['_SKU_Parent_Temp'] = df['SKU_Parent'].fillna('')
        else:
            review_df['_SKU_Parent_Temp'] = ''
        
        # Productos agrupados, ventas dirigidas, ventas cruzadas
        review_df['Productos agrupados'] = ''
        review_df['Ventas dirigidas'] = ''
        review_df['Ventas cruzadas'] = ''
        
        # URL externa y botón (para productos afiliados)
        review_df['URL externa'] = ''
        review_df['Texto del botón'] = ''
        
        # Posición
        review_df['Posición'] = range(1, len(df) + 1)
        review_df['Orden_Base'] = range(1, len(df) + 1)
        
        # Marca
        if 'Marca' in df.columns:
            review_df['Marcas'] = df['Marca'].fillna(df.get('Marca_Detectada', '')).fillna('')
        else:
            review_df['Marcas'] = df.get('Marca_Detectada', '').fillna('')
        
        # Atributos: se llenan en método separado
        review_df = self._add_woocommerce_attributes(review_df, df)
        
        # Columnas de auditoría
        review_df['Confianza_Automática'] = df.apply(self._calculate_confidence, axis=1)
        review_df['Revisado_Humano'] = 'No'
        review_df['Notas_Revisión'] = ''
        
        # --- Crear padres explícitos y actualizar variaciones ---
        try:
            review_df = self._ensure_explicit_parents_woo(df, review_df)
        except Exception as e:
            logger.warning(f"No se pudo crear padres explícitos: {e}")
            import traceback
            traceback.print_exc()
        
        # Reordenar: padre antes de sus hijos
        try:
            review_df = self._order_parent_child_blocks(review_df)
        except Exception as e:
            logger.warning(f"No se pudo reordenar padre-hijos: {e}")
        
        # Asignar IDs numéricos después de ordenar
        review_df['ID'] = range(1, len(review_df) + 1)
        
        # Actualizar columna 'Principal' con formato id:XX para variaciones
        review_df = self._update_principal_column(review_df)
        
        # Limpiar: Asegurar que productos 'variable' NO tengan precio ni stock
        mask_variable = review_df['Tipo'] == 'variable'
        review_df.loc[mask_variable, 'Precio normal'] = ''
        review_df.loc[mask_variable, 'Precio rebajado'] = ''
        review_df.loc[mask_variable, 'Inventario'] = ''
        
        # Recalcular "Clase de impuesto" después de crear padres/variaciones
        # variation = 'parent', variable/simple = ''
        review_df['Clase de impuesto'] = review_df['Tipo'].apply(
            lambda x: 'parent' if x == 'variation' else ''
        )
        
        # Recalcular posición
        review_df['Posición'] = range(1, len(review_df) + 1)
        
        # Ordenar columnas: WooCommerce primero, luego auditoría
        woo_cols = [col for col in self.WOOCOMMERCE_COLUMNS if col in review_df.columns]
        audit_cols = [col for col in self.AUDIT_COLUMNS if col in review_df.columns]
        other_cols = [col for col in review_df.columns if col not in woo_cols + audit_cols]
        
        # Filtrar columnas internas temporales
        internal_cols = ['_SKU_Parent_Temp', 'Orden_Base', 'Orden_Grupo', 'Orden_En_Grupo', 'Es_Padre']
        other_cols = [col for col in other_cols if col not in internal_cols]
        
        review_df = review_df[woo_cols + audit_cols + other_cols]
        
        # Quitar columnas temporales
        for col in internal_cols:
            if col in review_df.columns:
                review_df.drop(columns=[col], inplace=True)
        
        logger.info("✓ Formato WooCommerce generado")
        
        return review_df

    def _order_parent_child_blocks(self, df_in: pd.DataFrame) -> pd.DataFrame:
        """Ordena el DataFrame para que cada padre quede antes de sus hijos.
        - Mantiene el orden original relativo usando 'Orden_Base' como referencia.
        - Agrupa por 'SKU' (padre) y coloca hijos a continuación.
        """
        df = df_in.copy()
        if 'Orden_Base' not in df.columns:
            df['Orden_Base'] = range(1, len(df) + 1)

        # Columna de referencia al padre (puede ser _SKU_Parent_Temp o Principal)
        parent_col = '_SKU_Parent_Temp' if '_SKU_Parent_Temp' in df.columns else 'Principal'
        
        # Identificar padres explícitos creados (Tipo variable y sin referencia a padre)
        if parent_col in df.columns:
            parent_vals = df[parent_col].fillna('')
            es_padre = (df['Tipo'].astype(str).str.lower() == 'variable') & (parent_vals == '')
        else:
            es_padre = df['Tipo'].astype(str).str.lower() == 'variable'
        
        df['Es_Padre'] = es_padre

        # Calcular orden de grupo: para padres = mínimo Orden_Base de sus hijos; hijos = su Orden_Base
        df['Orden_Grupo'] = df['Orden_Base'].copy()
        padres_idx = df.index[df['Es_Padre']].tolist()
        
        for i in padres_idx:
            sku_padre = df.at[i, 'SKU']
            if parent_col in df.columns:
                hijos_mask = df[parent_col].astype(str) == str(sku_padre)
            else:
                hijos_mask = pd.Series([False] * len(df), index=df.index)
            
            if hijos_mask.any():
                min_hijo = df.loc[hijos_mask, 'Orden_Base'].min()
                if pd.notna(min_hijo):
                    df.at[i, 'Orden_Grupo'] = min_hijo

        # Orden dentro del grupo: padre primero (0), luego hijos (1)
        df['Orden_En_Grupo'] = 1
        df.loc[df['Es_Padre'], 'Orden_En_Grupo'] = 0

        # Orden final: por Orden_Grupo, luego Orden_En_Grupo, luego Nombre y SKU para estabilidad
        sort_cols = []
        sort_cols.append('Orden_Grupo')
        sort_cols.append('Orden_En_Grupo')
        if 'Nombre' in df.columns:
            sort_cols.append('Nombre')
        if 'SKU' in df.columns:
            sort_cols.append('SKU')

        df = df.sort_values(by=sort_cols, kind='mergesort').reset_index(drop=True)
        return df

    def _add_woocommerce_attributes(self, review_df: pd.DataFrame, df: pd.DataFrame) -> pd.DataFrame:
        """
        Agrega atributos al formato WooCommerce.
        
        Para producto VARIABLE (padre):
        - Valor(es) del atributo = TODOS los valores posibles separados por coma
        
        Para VARIATION (hijo):
        - Valor(es) del atributo = UN solo valor específico de esa variación
        
        Args:
            review_df: DataFrame de revisión
            df: DataFrame original con atributos
        
        Returns:
            DataFrame con atributos en formato WooCommerce
        """
        # Identificar columnas de atributos extraídos
        attr_cols = [col for col in df.columns if col.startswith('Atributo_')
                    and not col.endswith('_confianza') and not col.endswith('_cantidad')]
        
        # Mapeo de nombres internos a nombres WooCommerce
        attr_mapping = {
            'diametro': 'Diámetro',
            'largo': 'Largo',
            'grosor': 'Grosor',
            'material': 'Material',
            'acabado': 'Acabado',
            'cantidad': 'Cantidad',
            'marca': 'Marca',
            'medida': 'Medida',
            'tamaño': 'Tamaño'
        }
        
        # Inicializar columnas de atributos WooCommerce (hasta 3)
        for i in range(1, 4):
            review_df[f'Nombre del atributo {i}'] = ''
            review_df[f'Valor(es) del atributo {i}'] = ''
            review_df[f'Atributo visible {i}'] = 1
            review_df[f'Atributo global {i}'] = 0  # 0 = no global (específico del producto)
        
        # Llenar atributos
        attr_index = 1
        for col in attr_cols[:3]:
            attr_name_short = col.replace('Atributo_', '')
            attr_label = attr_mapping.get(attr_name_short.lower(), attr_name_short.capitalize())
            
            # Asignar nombre del atributo
            review_df[f'Nombre del atributo {attr_index}'] = attr_label
            
            # Asignar valores
            review_df[f'Valor(es) del atributo {attr_index}'] = df[col].fillna('')
            
            attr_index += 1
            if attr_index > 3:
                break
        
        return review_df

    def _ensure_explicit_parents_woo(self, df_src: pd.DataFrame, review_df: pd.DataFrame) -> pd.DataFrame:
        """
        Crea filas padre explícitas para WooCommerce:
        - Tipo = 'variable' (sin precio ni stock)
        - Genera SKU único para padre
        - Convierte hijos a 'variation'
        - Recolecta TODOS los valores de atributos para el padre
        
        Args:
            df_src: DataFrame fuente con datos originales
            review_df: DataFrame de revisión en proceso
        
        Returns:
            DataFrame con padres explícitos y variaciones actualizadas
        """
        import hashlib
        
        result = review_df.copy()
        
        # Verificar si hay grupos de variaciones
        if '_SKU_Parent_Temp' not in result.columns:
            return result
        
        # Identificar grupos por SKU_Parent temporal
        current_parent_keys = [p for p in result['_SKU_Parent_Temp'].dropna().unique().tolist() if p != '']
        if not current_parent_keys:
            return result
        
        # Utilidad: generar SKU padre único
        def gen_parent_sku(base_name: str, taken: set) -> str:
            base_slug = self._generate_slug(str(base_name)) or 'grupo'
            token = hashlib.md5(base_slug.encode('utf-8')).hexdigest()[:6].upper()
            candidate = f"GRP-{base_slug[:16].upper()}-{token}"
            i = 1
            sk = candidate
            while sk in taken:
                sk = f"{candidate}-{i}"
                i += 1
            return sk
        
        # Conjunto de SKUs ya tomados
        taken_skus = set(str(x) for x in result['SKU'].fillna('').tolist() if x)
        
        # Mapeo grupo -> nombre base
        group_name_map = {}
        if 'Nombre_Base' in df_src.columns:
            for key in current_parent_keys:
                mask = df_src['SKU_Parent'] == key
                if mask.any():
                    base_counts = df_src.loc[mask, 'Nombre_Base'].fillna('').value_counts()
                    if len(base_counts) > 0:
                        group_name_map[key] = base_counts.index[0]
                    else:
                        group_name_map[key] = str(df_src.loc[mask, 'Nombre_Limpio'].iloc[0])
        else:
            for key in current_parent_keys:
                mask = result['_SKU_Parent_Temp'] == key
                if mask.any():
                    group_name_map[key] = str(result.loc[mask, 'Nombre'].iloc[0])
        
        # Crear filas padre y actualizar variaciones
        parent_rows = []
        for key in current_parent_keys:
            base_name = group_name_map.get(key)
            if not base_name:
                continue
            
            new_parent_sku = gen_parent_sku(base_name, taken_skus)
            taken_skus.add(new_parent_sku)
            
            # Encontrar todas las variaciones de este grupo
            mask_children = result['_SKU_Parent_Temp'] == key
            # También incluir el registro original si su SKU == key
            mask_original = (result['SKU'].astype(str) == str(key)) & (
                (result['_SKU_Parent_Temp'].isna()) | (result['_SKU_Parent_Temp'] == '')
            )
            mask_all_children = mask_children | mask_original
            
            if not mask_all_children.any():
                continue
            
            # Actualizar variaciones
            result.loc[mask_all_children, 'Tipo'] = 'variation'
            result.loc[mask_all_children, '_SKU_Parent_Temp'] = new_parent_sku
            
            # Restaurar SKU original si existe
            if 'SKU_Original' in result.columns:
                for idx in result.index[mask_all_children]:
                    orig = result.at[idx, 'SKU_Original']
                    if orig and str(orig).strip():
                        result.at[idx, 'SKU'] = str(orig)
            
            # Recolectar TODOS los valores de atributos para el padre
            children_df = result[mask_all_children]
            
            # Obtener valores únicos de cada atributo para el padre
            parent_attr_values = {}
            for i in range(1, 4):
                val_col = f'Valor(es) del atributo {i}'
                name_col = f'Nombre del atributo {i}'
                if val_col in children_df.columns and name_col in children_df.columns:
                    attr_name = children_df[name_col].dropna().unique()
                    attr_name = attr_name[0] if len(attr_name) > 0 else ''
                    
                    # Recolectar todos los valores únicos
                    all_vals = children_df[val_col].dropna().unique().tolist()
                    all_vals = [str(v).strip() for v in all_vals if str(v).strip()]
                    unique_vals = list(dict.fromkeys(all_vals))  # Preservar orden, quitar duplicados
                    
                    parent_attr_values[i] = {
                        'name': attr_name,
                        'values': '|'.join(unique_vals)  # Separados por | para WooCommerce
                    }
            
            # Obtener datos de muestra del primer hijo
            sample = children_df.iloc[0] if len(children_df) > 0 else None
            
            # Construir fila padre
            parent = {}
            for col in result.columns:
                parent[col] = ''
            
            parent['ID'] = None  # Se asigna después
            parent['Tipo'] = 'variable'
            parent['SKU'] = new_parent_sku
            parent['GTIN, UPC, EAN o ISBN'] = ''
            parent['Nombre'] = str(base_name)
            parent['Publicado'] = 0  # Borrador
            parent['¿Está destacado?'] = 0
            parent['Visibilidad en el catálogo'] = 'visible'
            parent['Descripción corta'] = str(base_name)
            parent['Descripción'] = ''
            parent['Estado del impuesto'] = 'taxable'
            parent['Clase de impuesto'] = ''
            parent['¿En inventario?'] = 1
            parent['Inventario'] = ''  # Variable NO tiene stock
            parent['¿Permitir reservas de productos agotados?'] = 0
            parent['¿Vendido individualmente?'] = 0
            parent['¿Permitir valoraciones de clientes?'] = 1
            parent['Precio normal'] = ''  # Variable NO tiene precio
            parent['Precio rebajado'] = ''
            parent['Categorías'] = sample.get('Categorías', 'Otros') if sample is not None else 'Otros'
            parent['Etiquetas'] = ''
            parent['Imágenes'] = ''
            parent['Principal'] = ''  # Padre NO tiene Principal
            parent['Posición'] = None
            parent['Marcas'] = sample.get('Marcas', '') if sample is not None else ''
            parent['_SKU_Parent_Temp'] = ''  # Padre no tiene padre
            parent['SKU_Original'] = ''
            parent['Confianza_Automática'] = 0
            parent['Revisado_Humano'] = 'No'
            parent['Notas_Revisión'] = ''
            parent['Orden_Base'] = 0  # Se ordena al inicio del grupo
            
            # Atributos del padre: TODOS los valores posibles
            for i in range(1, 4):
                if i in parent_attr_values:
                    parent[f'Nombre del atributo {i}'] = parent_attr_values[i]['name']
                    parent[f'Valor(es) del atributo {i}'] = parent_attr_values[i]['values']
                    parent[f'Atributo visible {i}'] = 1
                    parent[f'Atributo global {i}'] = 0
                else:
                    parent[f'Nombre del atributo {i}'] = ''
                    parent[f'Valor(es) del atributo {i}'] = ''
                    parent[f'Atributo visible {i}'] = 1
                    parent[f'Atributo global {i}'] = 0
            
            parent_rows.append(parent)
        
        # Agregar filas padre al DataFrame
        if parent_rows:
            parents_df = pd.DataFrame(parent_rows)
            # Alinear columnas
            for col in result.columns:
                if col not in parents_df.columns:
                    parents_df[col] = ''
            parents_df = parents_df[result.columns]
            result = pd.concat([parents_df, result], ignore_index=True)
        
        return result

    def _update_principal_column(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Actualiza columna 'Principal' para variaciones con formato id:XX.
        
        En WooCommerce, las variaciones referencian al padre usando:
        - Principal = id:XX donde XX es el ID del producto padre
        
        Args:
            df: DataFrame con IDs ya asignados
        
        Returns:
            DataFrame con columna Principal actualizada
        """
        result = df.copy()
        
        if '_SKU_Parent_Temp' not in result.columns:
            return result
        
        # Crear mapeo SKU -> ID
        sku_to_id = dict(zip(result['SKU'].astype(str), result['ID']))
        
        # Para cada variación, buscar el ID del padre
        for idx in result.index:
            parent_sku = result.at[idx, '_SKU_Parent_Temp']
            if parent_sku and str(parent_sku).strip():
                parent_id = sku_to_id.get(str(parent_sku))
                if parent_id:
                    result.at[idx, 'Principal'] = f'id:{parent_id}'
        
        return result

    def _ensure_explicit_parents(self, df_src: pd.DataFrame, review_df: pd.DataFrame) -> pd.DataFrame:
        """
        Crea filas padre explícitas con SKU único y actualiza los hijos:
        - Genera un nuevo SKU para el padre (único, determinístico).
        - Padre sin precio ni stock (None), sin SKU_Original.
        - Actualiza SKU_Parent de los hijos para apuntar al nuevo SKU.
        - Si existe un registro usado como padre dentro del grupo, lo convierte a hijo.
        """
        result = review_df.copy()
        if 'SKU_Parent' not in df_src.columns:
            return result
        
        # Identificar grupos por padres actuales (marcadores de agrupación)
        current_parent_keys = [p for p in df_src.get('SKU_Parent').dropna().unique().tolist()]
        if not current_parent_keys:
            return result
        
        # Utilidad: generar SKU padre único
        def gen_parent_sku(base_name: str, taken: set) -> str:
            base_slug = self._generate_slug(str(base_name)) or 'grupo'
            import hashlib
            token = hashlib.md5(base_slug.encode('utf-8')).hexdigest()[:6].upper()
            candidate = f"GRP-{base_slug[:16].upper()}-{token}"
            i = 1
            sk = candidate
            while sk in taken:
                sk = f"{candidate}-{i}"
                i += 1
            return sk
        
        # Conjunto de SKUs ya tomados
        taken_skus = set(str(x) for x in result.get('SKU', pd.Series(dtype=str)).fillna('').tolist() if x)
        
        # Trabajar por grupo utilizando 'Nombre_Base' si existe; si no, por la clave del padre actual
        # Mapeo: clave_grupo -> nombre_base
        group_name_map = {}
        if 'Nombre_Base' in df_src.columns:
            # Para cada clave de padre actual, tomar el nombre base más frecuente del grupo
            for key in current_parent_keys:
                rows = df_src[(df_src['SKU_Parent'] == key) | (df_src.get('SKU') == key)]
                if len(rows) == 0:
                    continue
                # nombre base más común
                if 'Nombre_Base' in rows.columns:
                    base_counts = rows['Nombre_Base'].fillna('').value_counts()
                    base_name = base_counts.index[0] if len(base_counts) > 0 else str(rows.iloc[0].get('Nombre_Limpio', 'Grupo'))
                else:
                    base_name = str(rows.iloc[0].get('Nombre_Limpio', 'Grupo'))
                group_name_map[key] = base_name
        else:
            # Sin Nombre_Base: usar el nombre del primer hijo asociado
            for key in current_parent_keys:
                rows = df_src[(df_src['SKU_Parent'] == key) | (df_src.get('SKU') == key)]
                if len(rows) == 0:
                    continue
                group_name_map[key] = str(rows.iloc[0].get('Nombre_Limpio', 'Grupo'))
        
        # Crear filas padre y actualizar hijos
        parent_rows = []
        for key in current_parent_keys:
            base_name = group_name_map.get(key)
            if not base_name:
                continue
            new_parent_sku = gen_parent_sku(base_name, taken_skus)
            taken_skus.add(new_parent_sku)
            
            # Actualizar hijos: todos los que tenían SKU_Parent == key ahora apuntan al nuevo
            mask_children_in_result = (result['SKU_Parent'] == key)
            # Además, si existe un registro que actuaba como "padre original" (SKU == key), convertirlo en hijo
            mask_original_parent_row = (result['SKU'].astype(str) == str(key)) & (
                (result.get('SKU_Parent').isna()) | (result.get('SKU_Parent') == '')
            )

            any_updates = mask_children_in_result.any() or mask_original_parent_row.any()
            if any_updates:
                # Actualizar el SKU_Parent de todos los hijos del grupo, incluyendo el padre original
                result.loc[mask_children_in_result | mask_original_parent_row, 'SKU_Parent'] = new_parent_sku
                # Convertir el padre original en variación
                if 'Tipo' in result.columns:
                    result.loc[mask_original_parent_row, 'Tipo'] = 'variation'
                # Asegurar que los SKU de los hijos sigan siendo su SKU_Original si existe
                if 'SKU_Original' in result.columns:
                    masks_to_fix = mask_children_in_result | mask_original_parent_row
                    orig_vals = result.loc[masks_to_fix, 'SKU_Original']
                    use_orig = orig_vals.notna() & (orig_vals.astype(str).str.strip() != '')
                    # Solo sobrescribir SKU donde haya un SKU_Original válido
                    idxs = result.index[masks_to_fix]
                    valid_idxs = idxs[use_orig.reindex(idxs, fill_value=False)]
                    result.loc[valid_idxs, 'SKU'] = result.loc[valid_idxs, 'SKU_Original']
            
            # Intentar obtener categoría/marca del primer hijo para el padre (opcional)
            sample_row = None
            try:
                sample_row = result[result['SKU_Parent'] == new_parent_sku].iloc[0]
            except Exception:
                sample_row = None
            
            # Construir fila padre
            parent = {col: None for col in self.MAESTRO_COLUMNS}
            parent['Tipo'] = 'variable'
            parent['SKU'] = new_parent_sku
            parent['SKU_Parent'] = None
            parent['Nombre'] = str(base_name)
            parent['Slug'] = self._generate_slug(parent['Nombre'])
            parent['Publicado'] = 'No'
            parent['Visibilidad'] = 'Visible'
            parent['Descripción'] = ''
            parent['Descripción_Corta'] = str(base_name)
            parent['Categoría'] = (sample_row.get('Categoría') if sample_row is not None else 'Otros')
            parent['Etiquetas'] = ''
            parent['Marca'] = (sample_row.get('Marca') if sample_row is not None else '')
            parent['Imágenes'] = ''
            parent['Posición'] = None
            # Precio/Stock del padre en None (NULL)
            parent['Precio'] = None
            parent['Precio_Oferta'] = None
            parent['Stock'] = None
            parent['Estado_Stock'] = None
            parent['Gestionar_Stock'] = 'Sí'
            parent['Permitir_Reservas'] = 'No'
            parent['Peso'] = None
            parent['Largo'] = None
            parent['Ancho'] = None
            parent['Alto'] = None
            # Atributos del padre vacíos
            parent['Atributo_1'] = None
            parent['Valor_Atributo_1'] = None
            parent['Visible_Atributo_1'] = 'Sí'
            parent['Global_Atributo_1'] = 'Sí'
            parent['Usado_Variacion_1'] = 'No'
            parent['Atributo_2'] = None
            parent['Valor_Atributo_2'] = None
            parent['Visible_Atributo_2'] = 'Sí'
            parent['Global_Atributo_2'] = 'Sí'
            parent['Usado_Variacion_2'] = 'No'
            parent['Atributo_3'] = None
            parent['Valor_Atributo_3'] = None
            parent['Visible_Atributo_3'] = 'Sí'
            parent['Global_Atributo_3'] = 'Sí'
            parent['Usado_Variacion_3'] = 'No'
            parent['Confianza_Automática'] = 0
            parent['Revisado_Humano'] = 'No'
            parent['Notas_Revisión'] = ''
            
            # Columnas extra: asegurar 'SKU_Original' vacío si existe
            if 'SKU_Original' in result.columns:
                parent['SKU_Original'] = ''
            
            parent_rows.append(parent)
        
        if parent_rows:
            parents_df = pd.DataFrame(parent_rows)
            # Alinear columnas
            for col in result.columns:
                if col not in parents_df.columns:
                    parents_df[col] = None
            # Ordenar columnas como el result
            parents_df = parents_df[result.columns]
            # Insertar padres al inicio del DataFrame para facilitar revisión
            result = pd.concat([parents_df, result], ignore_index=True)
        
        return result
    
    def _generate_slug(self, name: str) -> str:
        """
        Genera slug URL-amigable.
        
        Args:
            name: Nombre del producto
        
        Returns:
            Slug para URL
        """
        if not isinstance(name, str) or not name:
            return ''
        
        # Convertir a lowercase
        slug = name.lower()
        
        # Remover caracteres especiales
        slug = re.sub(r'[^\w\s\-]', '', slug)
        
        # Reemplazar espacios con guiones
        slug = re.sub(r'\s+', '-', slug)
        
        # Remover guiones múltiples
        slug = re.sub(r'-+', '-', slug)
        
        # Trim guiones
        slug = slug.strip('-')
        
        return slug[:80]  # Limitar longitud
    
    def _generate_tags(self, row: pd.Series) -> str:
        """
        Genera etiquetas a partir de atributos.
        
        Args:
            row: Fila del DataFrame
        
        Returns:
            String de etiquetas separadas por coma
        """
        tags = []
        
        # Agregar familia
        if pd.notna(row.get('Familia_Detectada')) and row['Familia_Detectada']:
            tags.append(str(row['Familia_Detectada']).lower())
        
        # Agregar marca
        if pd.notna(row.get('Marca_Detectada')) and row['Marca_Detectada']:
            tags.append(str(row['Marca_Detectada']).lower())
        
        # Agregar atributos clave
        attr_cols = [col for col in row.index if col.startswith('Atributo_')
                    and not col.endswith('_confianza') and not col.endswith('_cantidad')]
        
        for col in attr_cols[:3]:  # Max 3 atributos
            if pd.notna(row[col]):
                value = str(row[col]).lower()
                # Simplificar valor
                value = value.replace('"', '').replace('mm', '').strip()
                if len(value) > 2:
                    tags.append(value[:20])
        
        return ', '.join(tags) if tags else ''
    
    def _add_attributes_to_review(self, review_df: pd.DataFrame, 
                                  df: pd.DataFrame) -> pd.DataFrame:
        """
        Agrega atributos extraídos al formato maestro.
        
        Args:
            review_df: DataFrame de revisión
            df: DataFrame original con atributos
        
        Returns:
            DataFrame con atributos incluidos
        """
        # Identificar columnas de atributos
        attr_cols = [col for col in df.columns if col.startswith('Atributo_')
                    and not col.endswith('_confianza') and not col.endswith('_cantidad')]
        
        # Mapear a columnas del maestro (hasta 3 atributos)
        attr_mapping = {
            'diametro': 'Diámetro',
            'largo': 'Largo',
            'grosor': 'Grosor',
            'material': 'Material',
            'acabado': 'Acabado',
            'cantidad': 'Cantidad',
            'marca': 'Marca'
        }
        
        # Inicializar columnas de atributos
        for i in range(1, 4):
            review_df[f'Atributo_{i}'] = None
            review_df[f'Valor_Atributo_{i}'] = None
            review_df[f'Visible_Atributo_{i}'] = 'Sí'
            review_df[f'Global_Atributo_{i}'] = 'Sí'
            review_df[f'Usado_Variacion_{i}'] = 'No'  # Se decide en revisión
        
        # Llenar atributos
        attr_index = 1
        for col in attr_cols[:3]:
            attr_name_short = col.replace('Atributo_', '')
            attr_label = attr_mapping.get(attr_name_short, attr_name_short.capitalize())
            
            review_df[f'Atributo_{attr_index}'] = attr_label
            review_df[f'Valor_Atributo_{attr_index}'] = df[col].fillna('')
            
            # Si es producto variable, marcar como variación
            review_df[f'Usado_Variacion_{attr_index}'] = df['Tipo'].apply(
                lambda x: 'Sí' if x == 'variable' else 'No'
            )
            
            attr_index += 1
            if attr_index > 3:
                break
        
        return review_df
    
    def _calculate_confidence(self, row: pd.Series) -> float:
        """
        Calcula puntuación de confianza automática (0-100).
        
        Factores:
        - Nombre limpio (30%)
        - Atributos detectados (20%)
        - Marca validada (20%)
        - Sin ambigüedad (30%)
        
        Args:
            row: Fila del DataFrame
        
        Returns:
            Score 0-100
        """
        score = 0
        
        # 1. Nombre limpio (30%)
        if pd.notna(row.get('Nombre_Original')) and pd.notna(row.get('Nombre_Limpio')):
            orig = str(row['Nombre_Original']).strip()
            clean = str(row['Nombre_Limpio']).strip()
            
            if orig == clean:
                score += 30  # Sin cambios necesarios
            elif len(clean) > len(orig) * 0.7:
                score += 20  # Limpieza mínima
            else:
                score += 10  # Limpieza importante
        
        # 2. Atributos detectados (20%)
        attr_cols = [col for col in row.index if col.startswith('Atributo_cantidad')]
        attr_count = sum(1 for col in attr_cols if pd.notna(row.get(col)) and (
            isinstance(row[col], (int, float)) and row[col] > 0 or
            isinstance(row[col], str) and row[col].strip() != ''
        ))
        score += min(20, attr_count * 5)
        
        # 3. Marca detectada (20%)
        if pd.notna(row.get('Marca_Detectada')) and row['Marca_Detectada']:
            score += 20
        
        # 4. Sin ambigüedad (30%)
        # Penalizar si hay múltiples familias o medidas conflictivas
        family = row.get('Familia_Detectada')
        has_measures = row.get('Tiene_Medidas', False)
        
        if family and family != 'None':
            score += 15
        if has_measures:
            score += 15
        
        # Asegurar rango 0-100
        return int(min(100, max(0, score)))
    
    def save_for_review(self, review_df: pd.DataFrame, output_dir: str = 'data/processed') -> Path:
        """
        Guarda DataFrame en formato Excel para revisión.
        
        Args:
            review_df: DataFrame con formato maestro
            output_dir: Directorio donde guardar
        
        Returns:
            Path del archivo guardado
        """
        output_path = Path(output_dir) / f"revision_final_{datetime.now().strftime('%Y%m%d_%H%M%S')}.xlsx"
        output_path.parent.mkdir(parents=True, exist_ok=True)
        
        # Guardar con formato
        with pd.ExcelWriter(output_path, engine='openpyxl') as writer:
            review_df.to_excel(writer, sheet_name='Maestro', index=False)
            
            # Agregar hoja con instrucciones
            instructions_df = pd.DataFrame({
                'INSTRUCCIONES DE REVISIÓN': [
                    '1. Revisa cada fila del catálogo',
                    '2. Corrige nombres, categorías, precios si es necesario',
                    '3. Completa campos vacíos (Descripción, Precio, Stock)',
                    '4. En columna "Revisado Humano": marca "Sí" o "No"',
                    '5. En "Notas Revisión": anota cambios realizados',
                    '6. Guarda el archivo',
                    '7. Ejecuta exportación: python main.py --export [archivo]',
                    '',
                    'IMPORTANTE:',
                    '✓ No modificar SKU ni SKU_Parent',
                    '✓ Productos padre NO tienen precio',
                    '✓ Solo exportar filas con "Revisado Humano = Sí"',
                ]
            })
            instructions_df.to_excel(writer, sheet_name='Instrucciones', index=False)
        
        logger.info(f"✓ Archivo de revisión guardado: {output_path}")
        
        return output_path
    
    def export_to_csv(self, review_df: pd.DataFrame, output_dir: str = 'data/processed') -> Path:
        """
        Exporta DataFrame a formato CSV para revisión interna.
        Incluye todas las columnas (WooCommerce + auditoría).
        
        Args:
            review_df: DataFrame con formato maestro
            output_dir: Directorio donde guardar
        
        Returns:
            Path del archivo guardado
        """
        output_path = Path(output_dir) / f"maestro_revision_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        output_path.parent.mkdir(parents=True, exist_ok=True)
        
        # Guardar CSV con encoding UTF-8 y delimitador coma
        review_df.to_csv(output_path, index=False, encoding='utf-8', sep=',')
        
        logger.info(f"✓ Archivo CSV de revisión guardado: {output_path}")
        
        return output_path
    
    def export_woocommerce_csv(self, review_df: pd.DataFrame, output_dir: str = 'data/processed') -> Path:
        """
        Exporta CSV listo para importar en WooCommerce.
        Solo incluye las columnas oficiales de WooCommerce (sin auditoría).
        
        Args:
            review_df: DataFrame con formato maestro
            output_dir: Directorio donde guardar
        
        Returns:
            Path del archivo guardado
        """
        output_path = Path(output_dir) / f"woocommerce_import_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        output_path.parent.mkdir(parents=True, exist_ok=True)
        
        # Solo columnas WooCommerce oficiales
        woo_cols = [col for col in self.WOOCOMMERCE_COLUMNS if col in review_df.columns]
        woo_df = review_df[woo_cols].copy()
        
        # Guardar CSV con encoding UTF-8
        woo_df.to_csv(output_path, index=False, encoding='utf-8', sep=',')
        
        logger.info(f"✓ CSV para WooCommerce guardado: {output_path}")
        
        return output_path
    
    def get_review_summary(self, review_df: pd.DataFrame) -> str:
        """Genera resumen del formato WooCommerce."""
        summary = f"""
        ╔════════════════════════════════════════╗
        ║   FORMATO WOOCOMMERCE PARA IMPORTACIÓN ║
        ╚════════════════════════════════════════╝
        
        📊 Estadísticas del formato:
        
        📦 Productos:
        """
        
        summary += f"\n        • Total: {len(review_df)}"
        summary += f"\n        • Simples: {(review_df['Tipo'] == 'simple').sum()}"
        summary += f"\n        • Variables (padre): {(review_df['Tipo'] == 'variable').sum()}"
        summary += f"\n        • Variaciones (hijo): {(review_df['Tipo'] == 'variation').sum()}"
        
        # Verificar columna Principal
        if 'Principal' in review_df.columns:
            con_principal = (review_df['Principal'].astype(str).str.startswith('id:')).sum()
            summary += f"\n        • Con referencia a padre (Principal): {con_principal}"
        
        # Confianza (solo si existe)
        if 'Confianza_Automática' in review_df.columns:
            confidence = review_df['Confianza_Automática']
            summary += f"\n        \n        📈 Confianza automática:"
            summary += f"\n        • Promedio: {confidence.mean():.0f}/100"
            summary += f"\n        • Mínimo: {confidence.min()}/100"
            summary += f"\n        • Máximo: {confidence.max()}/100"
            
            high_confidence = (confidence >= 75).sum()
            summary += f"\n        • Alta (>=75): {high_confidence} ({high_confidence/len(review_df)*100:.1f}%)"
        
        # Completitud de datos
        summary += f"\n        \n        📋 Completitud de datos:"
        if 'Marcas' in review_df.columns:
            con_marca = (review_df['Marcas'].astype(str).str.strip() != '').sum()
            summary += f"\n        • Con marca: {con_marca}"
        if 'Descripción corta' in review_df.columns:
            con_desc = (review_df['Descripción corta'].astype(str).str.strip() != '').sum()
            summary += f"\n        • Con descripción corta: {con_desc}"
        if 'Nombre del atributo 1' in review_df.columns:
            con_attr = (review_df['Nombre del atributo 1'].astype(str).str.strip() != '').sum()
            summary += f"\n        • Con atributos: {con_attr}"
        
        # Validaciones WooCommerce
        summary += f"\n        \n        ✅ Validaciones WooCommerce:"
        
        # Verificar que variables no tengan precio
        if 'Precio normal' in review_df.columns:
            variables_con_precio = (
                (review_df['Tipo'] == 'variable') & 
                (review_df['Precio normal'].astype(str).str.strip() != '')
            ).sum()
            if variables_con_precio == 0:
                summary += f"\n        • ✓ Variables sin precio: OK"
            else:
                summary += f"\n        • ❌ Variables con precio: {variables_con_precio} (ERROR)"
        
        # Verificar que variaciones tengan Principal
        if 'Principal' in review_df.columns:
            variations_sin_padre = (
                (review_df['Tipo'] == 'variation') & 
                (~review_df['Principal'].astype(str).str.startswith('id:'))
            ).sum()
            if variations_sin_padre == 0:
                summary += f"\n        • ✓ Variaciones con Principal: OK"
            else:
                summary += f"\n        • ❌ Variaciones sin Principal: {variations_sin_padre} (ERROR)"
        
        return summary


# Función de conveniencia
def generate_master_format(df: pd.DataFrame, export_csv: bool = True) -> Tuple[pd.DataFrame, Path, Optional[Path], Optional[Path]]:
    """
    Genera formato maestro para revisión en Excel y CSV.
    
    Args:
        df: DataFrame con productos agrupados y validados
        export_csv: Si True, también exporta a CSV (default: True)
    
    Returns:
        Tupla (DataFrame maestro, Path del Excel, Path del CSV revisión, Path del CSV WooCommerce)
    """
    formatter = ReviewFormatter()
    review_df = formatter.format_for_review(df)
    output_path_xlsx = formatter.save_for_review(review_df)
    
    output_path_csv = None
    output_path_woo = None
    if export_csv:
        output_path_csv = formatter.export_to_csv(review_df)
        output_path_woo = formatter.export_woocommerce_csv(review_df)
    
    print(formatter.get_review_summary(review_df))
    
    return review_df, output_path_xlsx, output_path_csv, output_path_woo
