"""
REVISOR.PY - Herramienta Interactiva de Revisión Humana
Permite revisar, editar y aprobar productos antes de exportar a WooCommerce.

Funcionalidades:
- Ver grupos (padre + variaciones)
- Agregar/quitar hijos de grupos
- Editar atributos de padre e hijos
- Crear nuevas familias desde productos simples
- Unir productos simples a grupos existentes
- Aprobar productos para exportación

Uso:
    python revisor.py data/processed/maestro_revision_*.xlsx
    python revisor.py  # Busca el archivo más reciente
"""

import pandas as pd
import os
import sys
from pathlib import Path
from datetime import datetime
from typing import Optional, List, Dict, Tuple
import hashlib


class Colors:
    """Colores ANSI para terminal."""
    HEADER = '\033[95m'
    BLUE = '\033[94m'
    CYAN = '\033[96m'
    GREEN = '\033[92m'
    YELLOW = '\033[93m'
    RED = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'
    UNDERLINE = '\033[4m'


def clear_screen():
    """Limpia la pantalla."""
    os.system('cls' if os.name == 'nt' else 'clear')


def print_header(title: str):
    """Imprime encabezado con formato."""
    print(f"\n{Colors.BOLD}{Colors.CYAN}{'═' * 60}{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.CYAN}  {title}{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.CYAN}{'═' * 60}{Colors.ENDC}\n")


def print_success(msg: str):
    print(f"{Colors.GREEN}✓ {msg}{Colors.ENDC}")


def print_error(msg: str):
    print(f"{Colors.RED}✗ {msg}{Colors.ENDC}")


def print_warning(msg: str):
    print(f"{Colors.YELLOW}⚠ {msg}{Colors.ENDC}")


def print_info(msg: str):
    print(f"{Colors.BLUE}ℹ {msg}{Colors.ENDC}")


class ProductReviewer:
    """Revisor interactivo de productos."""
    
    def __init__(self, file_path: str):
        """
        Inicializa el revisor.
        
        Args:
            file_path: Ruta al archivo Excel/CSV de revisión
        """
        self.file_path = Path(file_path)
        self.df = None
        self.modified = False
        self.current_index = 0
        
        # Columnas de atributos WooCommerce (hasta 6 atributos)
        self.attr_cols = [
            ('Nombre del atributo 1', 'Valor(es) del atributo 1', 'Atributo visible 1'),
            ('Nombre del atributo 2', 'Valor(es) del atributo 2', 'Atributo visible 2'),
            ('Nombre del atributo 3', 'Valor(es) del atributo 3', 'Atributo visible 3'),
            ('Nombre del atributo 4', 'Valor(es) del atributo 4', 'Atributo visible 4'),
            ('Nombre del atributo 5', 'Valor(es) del atributo 5', 'Atributo visible 5'),
            ('Nombre del atributo 6', 'Valor(es) del atributo 6', 'Atributo visible 6'),
        ]
        
        # Valor especial para indicar NULL
        self.NULL_VALUE = '<NULL>'
        
    def load_file(self) -> bool:
        """Carga el archivo de revisión."""
        try:
            if self.file_path.suffix.lower() == '.csv':
                self.df = pd.read_csv(self.file_path, encoding='utf-8')
            else:
                self.df = pd.read_excel(self.file_path, sheet_name='Maestro')
            
            # Asegurar columnas necesarias
            if 'Revisado_Humano' not in self.df.columns:
                self.df['Revisado_Humano'] = 'No'
            if 'Notas_Revisión' not in self.df.columns:
                self.df['Notas_Revisión'] = ''
            
            print_success(f"Archivo cargado: {self.file_path.name}")
            print_info(f"Total productos: {len(self.df)}")
            return True
        except Exception as e:
            print_error(f"Error cargando archivo: {e}")
            return False
    
    def save_file(self) -> bool:
        """Guarda los cambios al archivo."""
        try:
            # Guardar en Excel
            xlsx_path = self.file_path.with_suffix('.xlsx')
            with pd.ExcelWriter(xlsx_path, engine='openpyxl') as writer:
                self.df.to_excel(writer, sheet_name='Maestro', index=False)
            
            # También guardar CSV
            csv_path = self.file_path.with_suffix('.csv')
            self.df.to_csv(csv_path, index=False, encoding='utf-8')
            
            # Generar CSV WooCommerce (sin columnas de auditoría)
            woo_cols = [col for col in self.df.columns if not col.startswith('SKU_Original') 
                       and col not in ['Confianza_Automática', 'Revisado_Humano', 'Notas_Revisión']]
            woo_df = self.df[woo_cols]
            woo_path = self.file_path.parent / f"woocommerce_import_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
            woo_df.to_csv(woo_path, index=False, encoding='utf-8')
            
            self.modified = False
            print_success(f"Guardado: {xlsx_path.name}")
            print_success(f"Guardado: {csv_path.name}")
            print_success(f"WooCommerce: {woo_path.name}")
            return True
        except Exception as e:
            print_error(f"Error guardando: {e}")
            return False
    
    def get_groups(self) -> Dict[str, Dict]:
        """
        Obtiene todos los grupos (padre + variaciones).
        
        Returns:
            Dict con estructura {sku_padre: {'parent': row, 'children': [rows]}}
        """
        groups = {}
        
        # Encontrar padres (variable)
        parents = self.df[self.df['Tipo'] == 'variable']
        
        for idx, parent in parents.iterrows():
            parent_sku = parent['SKU']
            parent_id = parent['ID']
            
            # Buscar hijos por Principal (id:X) - igualdad exacta
            children_mask = self.df['Principal'] == f'id:{parent_id}'
            children = self.df[children_mask]
            
            groups[parent_sku] = {
                'parent_idx': idx,
                'parent': parent,
                'children_idx': children.index.tolist(),
                'children': children
            }
        
        return groups
    
    def get_simple_products(self) -> pd.DataFrame:
        """Obtiene productos simples."""
        return self.df[self.df['Tipo'] == 'simple']
    
    def get_unreviewed(self) -> pd.DataFrame:
        """Obtiene productos no revisados."""
        return self.df[self.df['Revisado_Humano'] != 'Sí']
    
    def show_summary(self):
        """Muestra resumen del archivo."""
        print_header("📊 RESUMEN DEL ARCHIVO")
        
        total = len(self.df)
        simples = (self.df['Tipo'] == 'simple').sum()
        variables = (self.df['Tipo'] == 'variable').sum()
        variations = (self.df['Tipo'] == 'variation').sum()
        
        reviewed = (self.df['Revisado_Humano'] == 'Sí').sum()
        pending = total - reviewed
        
        print(f"  📦 Total productos:     {total}")
        print(f"     • Simples:           {simples}")
        print(f"     • Variables (padre): {variables}")
        print(f"     • Variaciones:       {variations}")
        print()
        print(f"  ✅ Revisados:           {reviewed} ({reviewed/total*100:.1f}%)")
        print(f"  ⏳ Pendientes:          {pending} ({pending/total*100:.1f}%)")
        print()
        
        if self.modified:
            print_warning("Hay cambios sin guardar")
    
    def show_groups_list(self):
        """Muestra lista de grupos."""
        print_header("👨‍👧‍👦 GRUPOS (PADRE + VARIACIONES)")
        
        groups = self.get_groups()
        
        if not groups:
            print_warning("No hay grupos de variaciones")
            return
        
        for i, (sku, group) in enumerate(groups.items(), 1):
            parent = group['parent']
            children = group['children']
            reviewed = parent.get('Revisado_Humano', 'No') == 'Sí'
            status = f"{Colors.GREEN}✓{Colors.ENDC}" if reviewed else f"{Colors.YELLOW}○{Colors.ENDC}"
            
            print(f"  {status} [{i}] {Colors.BOLD}{parent['Nombre'][:50]}{Colors.ENDC}")
            print(f"      SKU: {sku} | Variaciones: {len(children)}")
            
            # Mostrar atributos del padre
            attrs = []
            for name_col, val_col, vis_col in self.attr_cols:
                if name_col in parent.index and pd.notna(parent[name_col]) and parent[name_col]:
                    vis = parent.get(vis_col, 1) if vis_col in parent.index else 1
                    vis_str = '👁' if str(vis) == '1' else '○'
                    attrs.append(f"{parent[name_col]}: {parent[val_col]} {vis_str}")
            if attrs:
                print(f"      Atributos: {' | '.join(attrs[:3])}")
            print()
    
    def show_group_detail(self, group_num: int):
        """Muestra detalle de un grupo específico."""
        groups = self.get_groups()
        
        if group_num < 1 or group_num > len(groups):
            print_error("Número de grupo inválido")
            return
        
        sku = list(groups.keys())[group_num - 1]
        group = groups[sku]
        parent = group['parent']
        children = group['children']
        
        clear_screen()
        print_header(f"👨‍👧‍👦 GRUPO: {parent['Nombre'][:40]}")
        
        # Info del padre
        print(f"{Colors.BOLD}PADRE (variable):{Colors.ENDC}")
        print(f"  ID: {parent['ID']} | SKU: {parent['SKU']}")
        print(f"  Nombre: {parent['Nombre']}")
        print(f"  Categorías: {parent.get('Categorías', '')}")
        print(f"  Marca: {parent.get('Marcas', '')}")
        
        # Atributos del padre
        print(f"\n  {Colors.CYAN}Atributos (valores posibles):{Colors.ENDC}")
        print(f"  (👁=visible, ○=oculto, escribe {Colors.YELLOW}<NULL>{Colors.ENDC} para vaciar)")
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            if name_col in parent.index:
                name = parent.get(name_col, '')
                val = parent.get(val_col, '')
                vis = parent.get(vis_col, 1) if vis_col in parent.index else 1
                vis_str = f'{Colors.GREEN}👁{Colors.ENDC}' if str(vis) == '1' else f'{Colors.YELLOW}○{Colors.ENDC}'
                if pd.notna(name) and name:
                    print(f"    [{i}] {vis_str} {name}: {val}")
                elif i <= 3:  # Mostrar slots vacíos para los primeros 3
                    print(f"    [{i}] {Colors.YELLOW}(vacío - disponible){Colors.ENDC}")
        
        # Variaciones
        print(f"\n{Colors.BOLD}VARIACIONES ({len(children)}):{Colors.ENDC}")
        for idx, (child_idx, child) in enumerate(children.iterrows(), 1):
            reviewed = child.get('Revisado_Humano', 'No') == 'Sí'
            status = f"{Colors.GREEN}✓{Colors.ENDC}" if reviewed else f"{Colors.YELLOW}○{Colors.ENDC}"
            
            print(f"\n  {status} [{idx}] SKU: {child['SKU']}")
            print(f"      Nombre: {child['Nombre'][:50]}")
            print(f"      Precio: {child.get('Precio normal', '')} | Stock: {child.get('Inventario', '')}")
            
            # Atributos de la variación
            attrs = []
            for name_col, val_col, vis_col in self.attr_cols:
                if name_col in child.index and pd.notna(child[name_col]) and child[name_col]:
                    vis = child.get(vis_col, 1) if vis_col in child.index else 1
                    vis_str = '👁' if str(vis) == '1' else '○'
                    attrs.append(f"{child[name_col]}={child[val_col]} {vis_str}")
            if attrs:
                print(f"      Atributos: {', '.join(attrs)}")
        
        # Menú de acciones
        print(f"\n{Colors.BOLD}ACCIONES:{Colors.ENDC}")
        print("  [A] Aprobar grupo completo")
        print("  [R] Renombrar grupo (padre e hijos)")
        print("  [E] Editar atributos del padre")
        print("  [V] Editar variación específica")
        print("  [+] Agregar atributo nuevo")
        print("  [Q] Quitar variación del grupo")
        print("  [U] Agregar producto al grupo")
        print("  [N] Agregar nota de revisión")
        print("  [B] Volver")
        
        return group
    
    def edit_group_menu(self, group_num: int):
        """Menú de edición de grupo."""
        while True:
            group = self.show_group_detail(group_num)
            if group is None:
                return
            
            action = input(f"\n{Colors.CYAN}Acción: {Colors.ENDC}").strip().upper()
            
            if action == 'B':
                return
            elif action == 'A':
                self.approve_group(group)
            elif action == 'R':
                self.rename_group(group)
            elif action == 'E':
                self.edit_parent_attributes(group)
            elif action == 'V':
                self.edit_variation(group)
            elif action == '+':
                self.add_new_attribute(group)
            elif action == 'Q':
                self.remove_variation(group)
            elif action == 'U':
                self.add_to_group(group)
            elif action == 'N':
                self.add_note(group['parent_idx'])
    
    def approve_group(self, group: Dict):
        """Aprueba un grupo completo."""
        # Aprobar padre
        self.df.at[group['parent_idx'], 'Revisado_Humano'] = 'Sí'
        
        # Aprobar hijos
        for idx in group['children_idx']:
            self.df.at[idx, 'Revisado_Humano'] = 'Sí'
        
        self.modified = True
        print_success(f"Grupo aprobado: padre + {len(group['children_idx'])} variaciones")
        input("Presiona Enter para continuar...")
    
    def rename_group(self, group: Dict):
        """Renombra el grupo (padre e hijos)."""
        parent_idx = group['parent_idx']
        parent = group['parent']
        
        print(f"\n{Colors.BOLD}Renombrar grupo:{Colors.ENDC}")
        print(f"  Nombre actual del padre: {parent['Nombre']}")
        
        new_base_name = input(f"\n  Nuevo nombre base (padre): ").strip()
        if not new_base_name:
            print_warning("Operación cancelada")
            return
        
        # Actualizar nombre del padre
        self.df.at[parent_idx, 'Nombre'] = new_base_name
        self.modified = True
        
        # Preguntar cómo actualizar los hijos
        print(f"\n  {Colors.CYAN}Opciones para los hijos:{Colors.ENDC}")
        print("    [1] Usar nombre base + atributos (ej: 'TORNILLO 10mm')")
        print("    [2] Mantener nombres actuales")
        print("    [3] Editar cada hijo manualmente")
        
        option = input("\n  Opción: ").strip()
        
        if option == '1':
            # Actualizar todos los hijos con nombre base + primer atributo
            for child_idx in group['children_idx']:
                child = self.df.loc[child_idx]
                
                # Obtener valores de atributos del hijo
                attr_parts = []
                for name_col, val_col, vis_col in self.attr_cols:
                    val = child.get(val_col, '')
                    if pd.notna(val) and val:
                        attr_parts.append(str(val))
                
                if attr_parts:
                    child_name = f"{new_base_name} - {' '.join(attr_parts[:2])}"
                else:
                    child_name = f"{new_base_name} - Variante {group['children_idx'].index(child_idx) + 1}"
                
                self.df.at[child_idx, 'Nombre'] = child_name
            
            print_success(f"Padre + {len(group['children_idx'])} hijos renombrados")
        
        elif option == '3':
            # Editar cada hijo manualmente
            for i, child_idx in enumerate(group['children_idx'], 1):
                child = self.df.loc[child_idx]
                print(f"\n  Hijo {i}: {child['Nombre'][:40]}")
                child_name = input(f"    Nuevo nombre (Enter para omitir): ").strip()
                if child_name:
                    self.df.at[child_idx, 'Nombre'] = child_name
            print_success("Nombres actualizados")
        else:
            print_info("Nombres de hijos sin cambios")
        
        input("Presiona Enter para continuar...")
    
    def add_new_attribute(self, group: Dict):
        """Agrega un nuevo atributo al grupo."""
        parent_idx = group['parent_idx']
        
        # Encontrar el primer slot de atributo vacío
        empty_slot = None
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            current_name = self.df.at[parent_idx, name_col] if name_col in self.df.columns else ''
            if not current_name or pd.isna(current_name):
                empty_slot = i
                break
        
        if empty_slot is None:
            print_error("No hay slots de atributos disponibles (máximo 6)")
            input("Presiona Enter para continuar...")
            return
        
        print(f"\n{Colors.BOLD}Agregar Atributo {empty_slot}:{Colors.ENDC}")
        
        attr_name = input("  Nombre del atributo (ej: Color, Tamaño): ").strip()
        if not attr_name:
            print_warning("Operación cancelada")
            return
        
        # Obtener valores para el padre (todos los valores posibles)
        print(f"\n  Valores para el PADRE (separados por |):")
        print(f"  Ejemplo: Rojo|Azul|Verde")
        parent_values = input("  Valores: ").strip()
        
        # Visible o no
        visible = input("  ¿Visible en tienda? (1=sí, 0=no) [1]: ").strip() or '1'
        visible = int(visible) if visible in ['0', '1'] else 1
        
        # Actualizar padre
        name_col, val_col, vis_col = self.attr_cols[empty_slot - 1]
        
        # Asegurar que las columnas existen
        for col in [name_col, val_col, vis_col]:
            if col not in self.df.columns:
                self.df[col] = ''
        
        self.df.at[parent_idx, name_col] = attr_name
        self.df.at[parent_idx, val_col] = parent_values
        self.df.at[parent_idx, vis_col] = visible
        self.modified = True
        
        # Preguntar si agregar valores a los hijos
        if len(group['children_idx']) > 0:
            print(f"\n  {Colors.CYAN}¿Agregar valores a las {len(group['children_idx'])} variaciones?{Colors.ENDC}")
            add_to_children = input("  (s/n): ").strip().lower()
            
            if add_to_children == 's':
                available_values = [v.strip() for v in parent_values.split('|') if v.strip()]
                
                for i, child_idx in enumerate(group['children_idx'], 1):
                    child = self.df.loc[child_idx]
                    print(f"\n  Variación {i}: {child['Nombre'][:30]}")
                    
                    if available_values:
                        print(f"    Valores disponibles: {', '.join(available_values)}")
                    
                    child_val = input(f"    Valor de '{attr_name}': ").strip()
                    if child_val:
                        self.df.at[child_idx, name_col] = attr_name
                        self.df.at[child_idx, val_col] = child_val
                        self.df.at[child_idx, vis_col] = visible
        
        print_success(f"Atributo '{attr_name}' agregado")
        input("Presiona Enter para continuar...")
    
    def edit_parent_attributes(self, group: Dict):
        """Edita atributos del padre y propaga nombres a los hijos."""
        parent_idx = group['parent_idx']
        children_idx = group['children_idx']
        
        print(f"\n{Colors.BOLD}Editar atributos del padre:{Colors.ENDC}")
        print(f"(Deja vacío para mantener, escribe {Colors.YELLOW}<NULL>{Colors.ENDC} para vaciar)")
        print(f"{Colors.CYAN}Nota: Los cambios de nombre se propagan a las variaciones{Colors.ENDC}")
        
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            current_name = self.df.at[parent_idx, name_col] if name_col in self.df.columns else ''
            current_val = self.df.at[parent_idx, val_col] if val_col in self.df.columns else ''
            current_vis = self.df.at[parent_idx, vis_col] if vis_col in self.df.columns else 1
            
            if (not current_name or pd.isna(current_name)) and i > 3:
                continue  # Saltar atributos vacíos después del tercero
            
            vis_str = '👁' if str(current_vis) == '1' else '○'
            print(f"\n  Atributo {i}: {vis_str}")
            
            new_name = input(f"    Nombre [{current_name}]: ").strip()
            if new_name == self.NULL_VALUE:
                # Vaciar atributo en padre
                self.df.at[parent_idx, name_col] = ''
                self.df.at[parent_idx, val_col] = ''
                self.df.at[parent_idx, vis_col] = 0
                # Vaciar también en hijos
                for child_idx in children_idx:
                    self.df.at[child_idx, name_col] = ''
                    self.df.at[child_idx, val_col] = ''
                    self.df.at[child_idx, vis_col] = 0
                self.modified = True
                print_info(f"Atributo {i} vaciado (padre + {len(children_idx)} hijos)")
                continue
            elif new_name:
                # Actualizar nombre en padre
                self.df.at[parent_idx, name_col] = new_name
                # PROPAGAR nombre a todos los hijos
                for child_idx in children_idx:
                    self.df.at[child_idx, name_col] = new_name
                self.modified = True
                print_info(f"Nombre propagado a {len(children_idx)} variaciones")
            
            new_val = input(f"    Valores [{current_val}]: ").strip()
            if new_val == self.NULL_VALUE:
                self.df.at[parent_idx, val_col] = ''
                self.modified = True
            elif new_val:
                self.df.at[parent_idx, val_col] = new_val
                self.modified = True
            
            new_vis = input(f"    Visible [{current_vis}] (0=oculto, 1=visible): ").strip()
            if new_vis in ['0', '1']:
                vis_int = int(new_vis)
                self.df.at[parent_idx, vis_col] = vis_int
                # Propagar visibilidad a hijos también
                for child_idx in children_idx:
                    self.df.at[child_idx, vis_col] = vis_int
                self.modified = True
        
        print_success("Atributos actualizados (padre + variaciones)")
        input("Presiona Enter para continuar...")
    
    def edit_variation(self, group: Dict):
        """Edita una variación específica."""
        children = group['children']
        
        if len(children) == 0:
            print_warning("No hay variaciones")
            return
        
        try:
            num = int(input(f"Número de variación a editar (1-{len(children)}): "))
            if num < 1 or num > len(children):
                print_error("Número inválido")
                return
        except ValueError:
            return
        
        child_idx = group['children_idx'][num - 1]
        child = self.df.loc[child_idx]
        
        print(f"\n{Colors.BOLD}Editando: {child['Nombre'][:40]}{Colors.ENDC}")
        print(f"(Deja vacío para mantener, escribe {Colors.YELLOW}<NULL>{Colors.ENDC} para vaciar)")
        
        # Editar nombre
        new_name = input(f"  Nombre [{child['Nombre']}]: ").strip()
        if new_name == self.NULL_VALUE:
            self.df.at[child_idx, 'Nombre'] = ''
            self.modified = True
        elif new_name:
            self.df.at[child_idx, 'Nombre'] = new_name
            self.modified = True
        
        # Editar precio
        new_price = input(f"  Precio [{child.get('Precio normal', '')}]: ").strip()
        if new_price == self.NULL_VALUE:
            self.df.at[child_idx, 'Precio normal'] = ''
            self.modified = True
        elif new_price:
            self.df.at[child_idx, 'Precio normal'] = new_price
            self.modified = True
        
        # Editar stock
        new_stock = input(f"  Stock [{child.get('Inventario', '')}]: ").strip()
        if new_stock == self.NULL_VALUE:
            self.df.at[child_idx, 'Inventario'] = ''
            self.modified = True
        elif new_stock:
            self.df.at[child_idx, 'Inventario'] = new_stock
            self.modified = True
        
        # Editar atributos
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            current_name = child.get(name_col, '')
            current_val = child.get(val_col, '')
            current_vis = child.get(vis_col, 1) if vis_col in child.index else 1
            
            if (not current_name or pd.isna(current_name)) and i > 3:
                continue
            
            if pd.notna(current_name) and current_name:
                print(f"\n  Atributo {i}: {current_name}")
                new_val = input(f"    Valor [{current_val}]: ").strip()
                if new_val == self.NULL_VALUE:
                    self.df.at[child_idx, val_col] = ''
                    self.modified = True
                elif new_val:
                    self.df.at[child_idx, val_col] = new_val
                    self.modified = True
                
                new_vis = input(f"    Visible [{current_vis}] (0=oculto, 1=visible): ").strip()
                if new_vis in ['0', '1']:
                    self.df.at[child_idx, vis_col] = int(new_vis)
                    self.modified = True
        
        print_success("Variación actualizada")
        input("Presiona Enter para continuar...")
    
    def remove_variation(self, group: Dict):
        """Quita una variación del grupo."""
        children = group['children']
        
        if len(children) == 0:
            print_warning("No hay variaciones")
            return
        
        try:
            num = int(input(f"Número de variación a quitar (1-{len(children)}): "))
            if num < 1 or num > len(children):
                print_error("Número inválido")
                return
        except ValueError:
            return
        
        child_idx = group['children_idx'][num - 1]
        
        # Convertir a simple
        self.df.at[child_idx, 'Tipo'] = 'simple'
        self.df.at[child_idx, 'Principal'] = ''
        
        self.modified = True
        print_success("Variación convertida a producto simple")
        input("Presiona Enter para continuar...")
    
    def add_to_group(self, group: Dict):
        """Agrega un producto simple al grupo."""
        simples = self.get_simple_products()
        
        if len(simples) == 0:
            print_warning("No hay productos simples disponibles")
            return
        
        print(f"\n{Colors.BOLD}Productos simples disponibles:{Colors.ENDC}")
        for i, (idx, prod) in enumerate(simples.iterrows(), 1):
            print(f"  [{i}] {prod['SKU']} - {prod['Nombre'][:40]}")
        
        try:
            num = int(input(f"\nNúmero de producto a agregar (1-{len(simples)}): "))
            if num < 1 or num > len(simples):
                print_error("Número inválido")
                return
        except ValueError:
            return
        
        simple_idx = simples.index[num - 1]
        parent = group['parent']
        
        # Convertir a variation
        self.df.at[simple_idx, 'Tipo'] = 'variation'
        self.df.at[simple_idx, 'Principal'] = f"id:{parent['ID']}"
        
        self.modified = True
        print_success("Producto agregado al grupo como variación")
        input("Presiona Enter para continuar...")
    
    def add_note(self, idx: int):
        """Agrega nota de revisión."""
        current = self.df.at[idx, 'Notas_Revisión']
        print(f"Nota actual: {current}")
        
        note = input("Nueva nota (o Enter para mantener): ").strip()
        if note:
            self.df.at[idx, 'Notas_Revisión'] = note
            self.modified = True
            print_success("Nota agregada")
    
    def show_simples_list(self):
        """Muestra lista de productos simples."""
        print_header("📦 PRODUCTOS SIMPLES")
        
        simples = self.get_simple_products()
        
        if len(simples) == 0:
            print_warning("No hay productos simples")
            return
        
        # Detectar posibles familias
        family_candidates = {}
        for idx, prod in simples.iterrows():
            name = str(prod['Nombre']).upper()
            # Extraer primeras 2-3 palabras como candidato a familia
            words = name.split()[:3]
            base = ' '.join(words)
            
            if base not in family_candidates:
                family_candidates[base] = []
            family_candidates[base].append((idx, prod))
        
        # Mostrar agrupados por posible familia
        current_page = 0
        items_per_page = 15
        
        items = list(simples.iterrows())
        total_pages = (len(items) + items_per_page - 1) // items_per_page
        
        while True:
            clear_screen()
            print_header(f"📦 PRODUCTOS SIMPLES (Página {current_page + 1}/{total_pages})")
            
            start = current_page * items_per_page
            end = min(start + items_per_page, len(items))
            
            for i, (idx, prod) in enumerate(items[start:end], start + 1):
                reviewed = prod.get('Revisado_Humano', 'No') == 'Sí'
                status = f"{Colors.GREEN}✓{Colors.ENDC}" if reviewed else f"{Colors.YELLOW}○{Colors.ENDC}"
                
                cat = prod.get('Categorías', '')[:15]
                price = prod.get('Precio normal', '')
                
                print(f"  {status} [{i}] {prod['SKU'][:10]:10} | {prod['Nombre'][:35]:35} | {cat:15} | ${price}")
            
            print(f"\n{Colors.BOLD}ACCIONES:{Colors.ENDC}")
            print("  [#] Número para ver/editar producto")
            print("  [F] Detectar familias automáticamente")
            print("  [C] Crear nuevo grupo desde productos")
            print("  [N/P] Siguiente/Anterior página")
            print("  [B] Volver")
            
            action = input(f"\n{Colors.CYAN}Acción: {Colors.ENDC}").strip().upper()
            
            if action == 'B':
                return
            elif action == 'N' and current_page < total_pages - 1:
                current_page += 1
            elif action == 'P' and current_page > 0:
                current_page -= 1
            elif action == 'F':
                self.detect_families()
            elif action == 'C':
                self.create_new_group()
            elif action.isdigit():
                num = int(action)
                if 1 <= num <= len(items):
                    self.edit_simple_product(items[num - 1][0])
    
    def edit_simple_product(self, idx: int):
        """Edita un producto simple."""
        prod = self.df.loc[idx]
        
        clear_screen()
        print_header(f"📦 PRODUCTO SIMPLE")
        
        print(f"  ID: {prod['ID']} | SKU: {prod['SKU']}")
        print(f"  Nombre: {prod['Nombre']}")
        print(f"  Categoría: {prod.get('Categorías', '')}")
        print(f"  Marca: {prod.get('Marcas', '')}")
        print(f"  Precio: {prod.get('Precio normal', '')} | Stock: {prod.get('Inventario', '')}")
        print(f"  Revisado: {prod.get('Revisado_Humano', 'No')}")
        print(f"  Notas: {prod.get('Notas_Revisión', '')}")
        
        # Mostrar atributos
        print(f"\n  {Colors.CYAN}Atributos:{Colors.ENDC}")
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            name = prod.get(name_col, '')
            val = prod.get(val_col, '')
            vis = prod.get(vis_col, 1) if vis_col in prod.index else 1
            vis_str = '👁' if str(vis) == '1' else '○'
            if pd.notna(name) and name:
                print(f"    [{i}] {vis_str} {name}: {val}")
        
        # Buscar posibles grupos donde podría encajar
        groups = self.get_groups()
        similar_groups = []
        prod_name = str(prod['Nombre']).upper()
        
        for sku, group in groups.items():
            parent_name = str(group['parent']['Nombre']).upper()
            # Comparar primeras palabras
            if prod_name.split()[0] == parent_name.split()[0]:
                similar_groups.append((sku, group))
        
        if similar_groups:
            print(f"\n  {Colors.YELLOW}Grupos similares encontrados:{Colors.ENDC}")
            for i, (sku, group) in enumerate(similar_groups, 1):
                print(f"    [{i}] {group['parent']['Nombre'][:40]}")
        
        print(f"\n{Colors.BOLD}ACCIONES:{Colors.ENDC}")
        print("  [A] Aprobar producto")
        print("  [E] Editar campos")
        print("  [U] Unir a grupo existente")
        print("  [N] Agregar nota")
        print("  [B] Volver")
        
        action = input(f"\n{Colors.CYAN}Acción: {Colors.ENDC}").strip().upper()
        
        if action == 'A':
            self.df.at[idx, 'Revisado_Humano'] = 'Sí'
            self.modified = True
            print_success("Producto aprobado")
            input("Presiona Enter para continuar...")
        elif action == 'E':
            self.edit_simple_fields(idx)
        elif action == 'U':
            self.join_to_group(idx)
        elif action == 'N':
            self.add_note(idx)
    
    def edit_simple_fields(self, idx: int):
        """Edita campos de producto simple incluyendo atributos."""
        prod = self.df.loc[idx]
        
        print(f"\n{Colors.BOLD}Editando producto simple:{Colors.ENDC}")
        print(f"(Deja vacío para mantener, escribe {Colors.YELLOW}<NULL>{Colors.ENDC} para vaciar)")
        
        # Campos básicos
        new_name = input(f"  Nombre [{prod['Nombre']}]: ").strip()
        if new_name == self.NULL_VALUE:
            self.df.at[idx, 'Nombre'] = ''
            self.modified = True
        elif new_name:
            self.df.at[idx, 'Nombre'] = new_name
            self.modified = True
        
        new_cat = input(f"  Categoría [{prod.get('Categorías', '')}]: ").strip()
        if new_cat == self.NULL_VALUE:
            self.df.at[idx, 'Categorías'] = ''
            self.modified = True
        elif new_cat:
            self.df.at[idx, 'Categorías'] = new_cat
            self.modified = True
        
        new_price = input(f"  Precio [{prod.get('Precio normal', '')}]: ").strip()
        if new_price == self.NULL_VALUE:
            self.df.at[idx, 'Precio normal'] = ''
            self.modified = True
        elif new_price:
            self.df.at[idx, 'Precio normal'] = new_price
            self.modified = True
        
        new_stock = input(f"  Stock [{prod.get('Inventario', '')}]: ").strip()
        if new_stock == self.NULL_VALUE:
            self.df.at[idx, 'Inventario'] = ''
            self.modified = True
        elif new_stock:
            self.df.at[idx, 'Inventario'] = new_stock
            self.modified = True
        
        # Editar atributos
        print(f"\n  {Colors.CYAN}Atributos (hasta 6):{Colors.ENDC}")
        print(f"  (👁=visible, ○=oculto)")
        
        for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
            # Asegurar que las columnas existen
            for col in [name_col, val_col, vis_col]:
                if col not in self.df.columns:
                    self.df[col] = ''
            
            current_name = self.df.at[idx, name_col] if pd.notna(self.df.at[idx, name_col]) else ''
            current_val = self.df.at[idx, val_col] if pd.notna(self.df.at[idx, val_col]) else ''
            current_vis = self.df.at[idx, vis_col] if vis_col in self.df.columns and pd.notna(self.df.at[idx, vis_col]) else 1
            
            # Mostrar atributo actual o slot vacío
            if current_name:
                vis_str = '👁' if str(current_vis) == '1' else '○'
                print(f"\n    Atributo {i}: {vis_str} {current_name} = {current_val}")
            else:
                if i <= 3:  # Mostrar primeros 3 slots aunque estén vacíos
                    print(f"\n    Atributo {i}: {Colors.YELLOW}(vacío){Colors.ENDC}")
                else:
                    continue  # Saltar slots vacíos después del 3
            
            # Opciones para este atributo
            if current_name:
                action = input(f"      [E]ditar, [Q]uitar, [S]altar: ").strip().upper()
            else:
                action = input(f"      [A]gregar, [S]altar: ").strip().upper()
            
            if action == 'S' or action == '':
                continue
            elif action == 'Q' or action == self.NULL_VALUE:
                # Quitar atributo
                self.df.at[idx, name_col] = ''
                self.df.at[idx, val_col] = ''
                self.df.at[idx, vis_col] = 0
                self.modified = True
                print_info(f"Atributo {i} eliminado")
            elif action == 'E' or action == 'A':
                # Editar/Agregar atributo
                new_attr_name = input(f"      Nombre [{current_name}]: ").strip()
                if new_attr_name == self.NULL_VALUE:
                    self.df.at[idx, name_col] = ''
                    self.df.at[idx, val_col] = ''
                    self.df.at[idx, vis_col] = 0
                    self.modified = True
                    print_info(f"Atributo {i} eliminado")
                    continue
                elif new_attr_name:
                    self.df.at[idx, name_col] = new_attr_name
                    self.modified = True
                elif action == 'A' and not current_name:
                    # Si está agregando y no puso nombre, cancelar
                    print_warning("Nombre requerido para agregar atributo")
                    continue
                
                new_attr_val = input(f"      Valor [{current_val}]: ").strip()
                if new_attr_val == self.NULL_VALUE:
                    self.df.at[idx, val_col] = ''
                    self.modified = True
                elif new_attr_val:
                    self.df.at[idx, val_col] = new_attr_val
                    self.modified = True
                
                new_vis = input(f"      Visible [{current_vis}] (0/1): ").strip()
                if new_vis in ['0', '1']:
                    self.df.at[idx, vis_col] = int(new_vis)
                    self.modified = True
                elif action == 'A':
                    # Por defecto visible al agregar
                    self.df.at[idx, vis_col] = 1
                    self.modified = True
        
        # Opción para agregar más atributos
        while True:
            add_more = input(f"\n  ¿Agregar otro atributo? (s/n): ").strip().lower()
            if add_more != 's':
                break
            
            # Encontrar siguiente slot vacío
            empty_slot = None
            for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
                current = self.df.at[idx, name_col] if name_col in self.df.columns else ''
                if not current or pd.isna(current):
                    empty_slot = i
                    break
            
            if empty_slot is None:
                print_error("No hay slots disponibles (máximo 6 atributos)")
                break
            
            name_col, val_col, vis_col = self.attr_cols[empty_slot - 1]
            
            attr_name = input(f"    Nombre del atributo {empty_slot}: ").strip()
            if not attr_name:
                break
            
            attr_val = input(f"    Valor: ").strip()
            attr_vis = input(f"    Visible (0/1) [1]: ").strip() or '1'
            
            self.df.at[idx, name_col] = attr_name
            self.df.at[idx, val_col] = attr_val
            self.df.at[idx, vis_col] = int(attr_vis) if attr_vis in ['0', '1'] else 1
            self.modified = True
            print_success(f"Atributo '{attr_name}' agregado")
        
        print_success("Producto actualizado")
        input("Presiona Enter para continuar...")
    
    def join_to_group(self, simple_idx: int):
        """Une producto simple a un grupo existente."""
        groups = self.get_groups()
        
        if not groups:
            print_warning("No hay grupos disponibles")
            return
        
        print(f"\n{Colors.BOLD}Grupos disponibles:{Colors.ENDC}")
        group_list = list(groups.items())
        for i, (sku, group) in enumerate(group_list, 1):
            print(f"  [{i}] {group['parent']['Nombre'][:40]}")
        
        try:
            num = int(input(f"\nNúmero de grupo (1-{len(group_list)}): "))
            if num < 1 or num > len(group_list):
                print_error("Número inválido")
                return
        except ValueError:
            return
        
        parent = group_list[num - 1][1]['parent']
        
        # Convertir a variation
        self.df.at[simple_idx, 'Tipo'] = 'variation'
        self.df.at[simple_idx, 'Principal'] = f"id:{parent['ID']}"
        
        self.modified = True
        print_success("Producto agregado al grupo")
        input("Presiona Enter para continuar...")
    
    def detect_families(self):
        """Detecta posibles familias entre productos simples."""
        print_header("🔍 DETECTANDO FAMILIAS")
        
        simples = self.get_simple_products()
        
        # Agrupar por primeras 2 palabras
        families = {}
        for idx, prod in simples.iterrows():
            name = str(prod['Nombre']).upper()
            words = name.split()[:2]
            base = ' '.join(words)
            
            if base not in families:
                families[base] = []
            families[base].append((idx, prod))
        
        # Mostrar familias con más de 1 producto
        potential = [(base, prods) for base, prods in families.items() if len(prods) > 1]
        
        if not potential:
            print_warning("No se detectaron familias con múltiples productos")
            input("Presiona Enter para continuar...")
            return
        
        print(f"Se encontraron {len(potential)} posibles familias:\n")
        
        for i, (base, prods) in enumerate(potential, 1):
            print(f"  [{i}] {base} ({len(prods)} productos)")
            for idx, prod in prods[:3]:
                print(f"      • {prod['SKU']} - {prod['Nombre'][:30]}")
            if len(prods) > 3:
                print(f"      ... y {len(prods) - 3} más")
            print()
        
        print(f"{Colors.BOLD}¿Crear grupo desde familia?{Colors.ENDC}")
        try:
            num = int(input("Número de familia (0 para cancelar): "))
            if num == 0:
                return
            if num < 1 or num > len(potential):
                print_error("Número inválido")
                return
        except ValueError:
            return
        
        base, prods = potential[num - 1]
        self.create_group_from_products([idx for idx, _ in prods], base)
    
    def create_new_group(self):
        """Crea un nuevo grupo desde productos seleccionados."""
        simples = self.get_simple_products()
        
        print("\nIngresa los números de productos a agrupar (separados por coma):")
        for i, (idx, prod) in enumerate(simples.iterrows(), 1):
            print(f"  [{i}] {prod['SKU']} - {prod['Nombre'][:40]}")
        
        selection = input("\nNúmeros: ").strip()
        if not selection:
            return
        
        try:
            nums = [int(n.strip()) for n in selection.split(',')]
            indices = [simples.index[n - 1] for n in nums if 1 <= n <= len(simples)]
        except (ValueError, IndexError):
            print_error("Selección inválida")
            return
        
        if len(indices) < 2:
            print_error("Necesitas al menos 2 productos")
            return
        
        base_name = input("Nombre del grupo padre: ").strip()
        if not base_name:
            base_name = self.df.loc[indices[0], 'Nombre'].split()[0]
        
        self.create_group_from_products(indices, base_name)
    
    def create_group_from_products(self, indices: List[int], base_name: str):
        """
        Crea un grupo padre + variaciones desde productos.
        
        Args:
            indices: Índices de productos a agrupar
            base_name: Nombre base para el padre
        """
        # Generar nuevo ID y SKU para el padre
        max_id = self.df['ID'].max()
        new_id = max_id + 1
        
        # Generar SKU único
        slug = base_name.lower().replace(' ', '-')[:16]
        token = hashlib.md5(slug.encode()).hexdigest()[:6].upper()
        new_sku = f"GRP-{slug.upper()}-{token}"
        
        # Obtener datos de muestra del primer producto
        sample = self.df.loc[indices[0]]
        
        # Recopilar todos los valores de atributos (hasta 6)
        attr_values = {i: set() for i in range(1, 7)}
        attr_names = {i: '' for i in range(1, 7)}
        
        for idx in indices:
            prod = self.df.loc[idx]
            for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
                if name_col in prod.index and pd.notna(prod[name_col]) and prod[name_col]:
                    attr_names[i] = prod[name_col]
                    if pd.notna(prod[val_col]) and prod[val_col]:
                        attr_values[i].add(str(prod[val_col]))
        
        # Crear fila del padre
        parent_data = {}
        for col in self.df.columns:
            parent_data[col] = ''
        
        parent_data['ID'] = new_id
        parent_data['Tipo'] = 'variable'
        parent_data['SKU'] = new_sku
        parent_data['Nombre'] = base_name.upper()
        parent_data['Publicado'] = 0
        parent_data['Visibilidad en el catálogo'] = 'visible'
        parent_data['Descripción corta'] = base_name
        parent_data['Estado del impuesto'] = 'taxable'
        parent_data['¿En inventario?'] = 1
        parent_data['Categorías'] = sample.get('Categorías', 'Otros')
        parent_data['Marcas'] = sample.get('Marcas', '')
        parent_data['Principal'] = ''
        parent_data['Revisado_Humano'] = 'No'
        
        # Atributos del padre (todos los valores, hasta 6)
        for i in range(1, 7):
            name_col, val_col, vis_col = self.attr_cols[i - 1]
            parent_data[name_col] = attr_names[i]
            parent_data[val_col] = '|'.join(sorted(attr_values[i])) if attr_values[i] else ''
            parent_data[vis_col] = 1 if attr_names[i] else 0
            parent_data[f'Atributo global {i}'] = 0
        
        # Agregar padre al DataFrame
        parent_df = pd.DataFrame([parent_data])
        self.df = pd.concat([self.df, parent_df], ignore_index=True)
        
        # Actualizar productos a variaciones
        for idx in indices:
            self.df.at[idx, 'Tipo'] = 'variation'
            self.df.at[idx, 'Principal'] = f'id:{new_id}'
        
        # Re-numerar IDs
        self.df['ID'] = range(1, len(self.df) + 1)
        
        # Actualizar referencias Principal
        for idx in indices:
            new_parent_id = self.df[self.df['SKU'] == new_sku]['ID'].values[0]
            self.df.at[idx, 'Principal'] = f'id:{new_parent_id}'
        
        self.modified = True
        print_success(f"Grupo creado: {new_sku} con {len(indices)} variaciones")
        input("Presiona Enter para continuar...")
    
    def review_pending(self):
        """Revisa productos pendientes uno por uno."""
        pending = self.get_unreviewed()
        
        if len(pending) == 0:
            print_success("¡Todos los productos han sido revisados!")
            input("Presiona Enter para continuar...")
            return
        
        current = 0
        total = len(pending)
        
        while current < total:
            idx = pending.index[current]
            prod = self.df.loc[idx]
            
            clear_screen()
            print_header(f"📝 REVISIÓN ({current + 1}/{total})")
            
            tipo = prod['Tipo']
            tipo_color = Colors.CYAN if tipo == 'variable' else (Colors.YELLOW if tipo == 'variation' else Colors.GREEN)
            
            print(f"  Tipo: {tipo_color}{tipo}{Colors.ENDC}")
            print(f"  ID: {prod['ID']} | SKU: {prod['SKU']}")
            print(f"  Nombre: {prod['Nombre']}")
            print(f"  Categoría: {prod.get('Categorías', '')}")
            print(f"  Marca: {prod.get('Marcas', '')}")
            
            if tipo != 'variable':
                print(f"  Precio: {prod.get('Precio normal', '')} | Stock: {prod.get('Inventario', '')}")
            
            if tipo == 'variation':
                print(f"  Principal: {prod.get('Principal', '')}")
            
            # Atributos
            print(f"\n  {Colors.CYAN}Atributos:{Colors.ENDC}")
            for i, (name_col, val_col, vis_col) in enumerate(self.attr_cols, 1):
                name = prod.get(name_col, '')
                val = prod.get(val_col, '')
                vis = prod.get(vis_col, 1) if vis_col in prod.index else 1
                vis_str = '👁' if str(vis) == '1' else '○'
                if pd.notna(name) and name:
                    print(f"    {vis_str} {name}: {val}")
            
            print(f"\n{Colors.BOLD}ACCIONES:{Colors.ENDC}")
            print("  [A] Aprobar")
            print("  [R] Rechazar (marcar para revisar)")
            print("  [E] Editar")
            print("  [N] Agregar nota")
            print("  [S] Saltar")
            print("  [Q] Salir de revisión")
            
            action = input(f"\n{Colors.CYAN}Acción: {Colors.ENDC}").strip().upper()
            
            if action == 'Q':
                return
            elif action == 'A':
                self.df.at[idx, 'Revisado_Humano'] = 'Sí'
                self.modified = True
                current += 1
            elif action == 'R':
                self.df.at[idx, 'Revisado_Humano'] = 'No'
                note = input("Razón del rechazo: ").strip()
                if note:
                    self.df.at[idx, 'Notas_Revisión'] = note
                self.modified = True
                current += 1
            elif action == 'E':
                self.quick_edit(idx)
            elif action == 'N':
                self.add_note(idx)
            elif action == 'S':
                current += 1
        
        print_success("¡Revisión completada!")
        input("Presiona Enter para continuar...")
    
    def quick_edit(self, idx: int):
        """Edición rápida de un producto."""
        prod = self.df.loc[idx]
        
        print("\n(Deja vacío para mantener)")
        
        new_name = input(f"Nombre [{prod['Nombre'][:40]}]: ").strip()
        if new_name:
            self.df.at[idx, 'Nombre'] = new_name
            self.modified = True
        
        if prod['Tipo'] != 'variable':
            new_price = input(f"Precio [{prod.get('Precio normal', '')}]: ").strip()
            if new_price:
                self.df.at[idx, 'Precio normal'] = new_price
                self.modified = True
    
    def main_menu(self):
        """Menú principal del revisor."""
        while True:
            clear_screen()
            print_header("🔍 REVISOR DE PRODUCTOS WOOCOMMERCE")
            self.show_summary()
            
            print(f"\n{Colors.BOLD}MENÚ PRINCIPAL:{Colors.ENDC}")
            print("  [1] Ver grupos (padre + variaciones)")
            print("  [2] Ver productos simples")
            print("  [3] Revisar productos pendientes")
            print("  [4] Buscar producto")
            print()
            print("  [S] Guardar cambios")
            print("  [Q] Salir")
            
            if self.modified:
                print(f"\n  {Colors.YELLOW}⚠ Hay cambios sin guardar{Colors.ENDC}")
            
            action = input(f"\n{Colors.CYAN}Opción: {Colors.ENDC}").strip().upper()
            
            if action == '1':
                self.groups_menu()
            elif action == '2':
                self.show_simples_list()
            elif action == '3':
                self.review_pending()
            elif action == '4':
                self.search_product()
            elif action == 'S':
                self.save_file()
                input("Presiona Enter para continuar...")
            elif action == 'Q':
                if self.modified:
                    save = input("¿Guardar cambios antes de salir? (s/n): ").strip().lower()
                    if save == 's':
                        self.save_file()
                break
    
    def groups_menu(self):
        """Menú de grupos."""
        while True:
            clear_screen()
            self.show_groups_list()
            
            groups = self.get_groups()
            if not groups:
                input("Presiona Enter para volver...")
                return
            
            print(f"{Colors.BOLD}ACCIONES:{Colors.ENDC}")
            print("  [#] Número para ver/editar grupo")
            print("  [B] Volver")
            
            action = input(f"\n{Colors.CYAN}Acción: {Colors.ENDC}").strip().upper()
            
            if action == 'B':
                return
            elif action.isdigit():
                num = int(action)
                if 1 <= num <= len(groups):
                    self.edit_group_menu(num)
    
    def search_product(self):
        """Busca producto por SKU o nombre."""
        query = input("Buscar (SKU o nombre): ").strip()
        if not query:
            return
        
        query_upper = query.upper()
        
        # Buscar
        mask = (
            self.df['SKU'].astype(str).str.upper().str.contains(query_upper, na=False) |
            self.df['Nombre'].astype(str).str.upper().str.contains(query_upper, na=False)
        )
        
        results = self.df[mask]
        
        if len(results) == 0:
            print_warning("No se encontraron resultados")
            input("Presiona Enter para continuar...")
            return
        
        print(f"\n{Colors.BOLD}Resultados ({len(results)}):{Colors.ENDC}")
        for i, (idx, prod) in enumerate(results.iterrows(), 1):
            print(f"  [{i}] {prod['Tipo']:10} | {prod['SKU'][:12]:12} | {prod['Nombre'][:35]}")
        
        try:
            num = int(input("\nNúmero para ver detalle (0 para cancelar): "))
            if num == 0:
                return
            if 1 <= num <= len(results):
                result_idx = results.index[num - 1]
                if self.df.loc[result_idx, 'Tipo'] == 'simple':
                    self.edit_simple_product(result_idx)
                else:
                    # Encontrar grupo
                    groups = self.get_groups()
                    for i, (sku, group) in enumerate(groups.items(), 1):
                        if result_idx == group['parent_idx'] or result_idx in group['children_idx']:
                            self.edit_group_menu(i)
                            break
        except ValueError:
            pass


def find_latest_file(directory: str = 'data/processed') -> Optional[str]:
    """Encuentra el archivo maestro más reciente."""
    dir_path = Path(directory)
    if not dir_path.exists():
        return None
    
    files = list(dir_path.glob('revision_final_*.xlsx'))
    if not files:
        files = list(dir_path.glob('maestro_revision_*.xlsx'))
    if not files:
        files = list(dir_path.glob('maestro_revision_*.csv'))
    
    if not files:
        return None
    
    return str(max(files, key=lambda f: f.stat().st_mtime))


def main():
    """Punto de entrada principal."""
    clear_screen()
    print_header("🔍 REVISOR DE PRODUCTOS WOOCOMMERCE")
    
    # Determinar archivo a cargar
    if len(sys.argv) > 1:
        file_path = sys.argv[1]
    else:
        file_path = find_latest_file()
        if not file_path:
            print_error("No se encontró archivo de revisión")
            print_info("Uso: python revisor.py [archivo.xlsx]")
            print_info("O ejecuta primero: python main.py --input [tu_excel.xlsx]")
            sys.exit(1)
        print_info(f"Usando archivo más reciente: {file_path}")
    
    # Verificar que existe
    if not Path(file_path).exists():
        print_error(f"Archivo no encontrado: {file_path}")
        sys.exit(1)
    
    # Iniciar revisor
    reviewer = ProductReviewer(file_path)
    
    if not reviewer.load_file():
        sys.exit(1)
    
    input("\nPresiona Enter para comenzar...")
    
    reviewer.main_menu()
    
    print("\n✅ ¡Hasta pronto!")


if __name__ == '__main__':
    main()
