#!/usr/bin/env python3
"""
Riverso POS - GUI de Administración
Panel de control local para gestión de facturas, códigos y tareas.
Conecta con WordPress via REST API o SSH.
"""

import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import json
import os
import sys
from pathlib import Path
from datetime import datetime
import threading

# Agregar src al path
sys.path.insert(0, str(Path(__file__).parent / 'src'))

try:
    from xml_invoice_parser import DTEParser
except ImportError:
    DTEParser = None


class RiversoPOSApp:
    """Aplicación principal de administración Riverso POS"""
    
    def __init__(self, root):
        self.root = root
        self.root.title("Riverso POS - Panel de Administración")
        self.root.geometry("1200x700")
        self.root.minsize(900, 500)
        
        # Configuración
        self.config_file = Path(__file__).parent / "data" / "riverso_gui_config.json"
        self.config = self.load_config()
        
        # Parser DTE
        self.parser = DTEParser() if DTEParser else None
        
        # Datos en memoria
        self.facturas = []
        self.codigos_pendientes = []
        self.tareas = []
        
        # Construir UI
        self.setup_styles()
        self.create_menu()
        self.create_main_layout()
        self.create_status_bar()
        
        # Cargar datos iniciales
        self.root.after(100, self.load_initial_data)
    
    def load_config(self):
        """Carga configuración guardada"""
        default = {
            "wp_url": "https://riverso.cl",
            "api_key": "",
            "api_secret": "",
            "ssh_host": "72.61.37.37",
            "last_xml_dir": "",
            "theme": "clam"
        }
        if self.config_file.exists():
            try:
                with open(self.config_file, 'r', encoding='utf-8') as f:
                    saved = json.load(f)
                    default.update(saved)
            except:
                pass
        return default
    
    def save_config(self):
        """Guarda configuración"""
        self.config_file.parent.mkdir(parents=True, exist_ok=True)
        with open(self.config_file, 'w', encoding='utf-8') as f:
            json.dump(self.config, f, indent=2)
    
    def setup_styles(self):
        """Configura estilos visuales"""
        style = ttk.Style()
        style.theme_use(self.config.get("theme", "clam"))
        
        # Colores personalizados
        style.configure("Header.TLabel", font=("Segoe UI", 14, "bold"))
        style.configure("Title.TLabel", font=("Segoe UI", 11, "bold"))
        style.configure("Success.TLabel", foreground="#2e7d32")
        style.configure("Warning.TLabel", foreground="#ef6c00")
        style.configure("Error.TLabel", foreground="#c62828")
        
        style.configure("Card.TFrame", relief="solid", borderwidth=1)
        style.configure("Accent.TButton", font=("Segoe UI", 10, "bold"))
    
    def create_menu(self):
        """Crea menú principal"""
        menubar = tk.Menu(self.root)
        self.root.config(menu=menubar)
        
        # Archivo
        file_menu = tk.Menu(menubar, tearoff=0)
        menubar.add_cascade(label="Archivo", menu=file_menu)
        file_menu.add_command(label="Importar XML...", command=self.import_xml, accelerator="Ctrl+O")
        file_menu.add_command(label="Importar carpeta...", command=self.import_folder)
        file_menu.add_separator()
        file_menu.add_command(label="Exportar códigos...", command=self.export_codes)
        file_menu.add_separator()
        file_menu.add_command(label="Salir", command=self.root.quit)
        
        # Ver
        view_menu = tk.Menu(menubar, tearoff=0)
        menubar.add_cascade(label="Ver", menu=view_menu)
        view_menu.add_command(label="Actualizar", command=self.refresh_all, accelerator="F5")
        view_menu.add_separator()
        view_menu.add_command(label="Solo pendientes", command=lambda: self.filter_view("pendiente"))
        view_menu.add_command(label="Mostrar todos", command=lambda: self.filter_view("todos"))
        
        # Herramientas
        tools_menu = tk.Menu(menubar, tearoff=0)
        menubar.add_cascade(label="Herramientas", menu=tools_menu)
        tools_menu.add_command(label="Sincronizar con servidor", command=self.sync_server)
        tools_menu.add_command(label="Verificar conexión", command=self.test_connection)
        tools_menu.add_separator()
        tools_menu.add_command(label="Configuración...", command=self.show_settings)
        
        # Ayuda
        help_menu = tk.Menu(menubar, tearoff=0)
        menubar.add_cascade(label="Ayuda", menu=help_menu)
        help_menu.add_command(label="Documentación", command=self.show_docs)
        help_menu.add_command(label="Acerca de...", command=self.show_about)
        
        # Atajos
        self.root.bind("<Control-o>", lambda e: self.import_xml())
        self.root.bind("<F5>", lambda e: self.refresh_all())
    
    def create_main_layout(self):
        """Crea layout principal con tabs"""
        # Notebook principal
        self.notebook = ttk.Notebook(self.root)
        self.notebook.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        # Tab: Dashboard
        self.tab_dashboard = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_dashboard, text="📊 Dashboard")
        self.create_dashboard_tab()
        
        # Tab: Facturas
        self.tab_facturas = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_facturas, text="📄 Facturas XML")
        self.create_facturas_tab()
        
        # Tab: Códigos
        self.tab_codigos = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_codigos, text="🏷️ Códigos")
        self.create_codigos_tab()
        
        # Tab: Tareas
        self.tab_tareas = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_tareas, text="✅ Tareas")
        self.create_tareas_tab()
    
    def create_dashboard_tab(self):
        """Crea contenido del dashboard"""
        # Header
        header = ttk.Frame(self.tab_dashboard)
        header.pack(fill=tk.X, padx=20, pady=20)
        
        ttk.Label(header, text="Panel de Control", style="Header.TLabel").pack(side=tk.LEFT)
        ttk.Button(header, text="🔄 Actualizar", command=self.refresh_all).pack(side=tk.RIGHT)
        
        # Stats cards
        stats_frame = ttk.Frame(self.tab_dashboard)
        stats_frame.pack(fill=tk.X, padx=20, pady=10)
        
        self.stat_cards = {}
        stats = [
            ("facturas", "Facturas", "0", "#1976d2"),
            ("pendientes", "Códigos Pendientes", "0", "#ff9800"),
            ("vinculados", "Códigos Vinculados", "0", "#4caf50"),
            ("tareas", "Tareas Activas", "0", "#9c27b0"),
        ]
        
        for i, (key, label, value, color) in enumerate(stats):
            card = ttk.Frame(stats_frame, style="Card.TFrame")
            card.grid(row=0, column=i, padx=10, pady=5, sticky="nsew")
            stats_frame.columnconfigure(i, weight=1)
            
            inner = ttk.Frame(card)
            inner.pack(padx=20, pady=15)
            
            val_label = ttk.Label(inner, text=value, font=("Segoe UI", 28, "bold"))
            val_label.pack()
            ttk.Label(inner, text=label, font=("Segoe UI", 10)).pack()
            
            self.stat_cards[key] = val_label
        
        # Actividad reciente
        recent_frame = ttk.LabelFrame(self.tab_dashboard, text="Actividad Reciente")
        recent_frame.pack(fill=tk.BOTH, expand=True, padx=20, pady=10)
        
        self.activity_list = ttk.Treeview(recent_frame, columns=("fecha", "tipo", "descripcion"), show="headings", height=10)
        self.activity_list.heading("fecha", text="Fecha")
        self.activity_list.heading("tipo", text="Tipo")
        self.activity_list.heading("descripcion", text="Descripción")
        self.activity_list.column("fecha", width=120)
        self.activity_list.column("tipo", width=100)
        self.activity_list.column("descripcion", width=400)
        self.activity_list.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
    
    def create_facturas_tab(self):
        """Crea contenido de facturas"""
        # Toolbar
        toolbar = ttk.Frame(self.tab_facturas)
        toolbar.pack(fill=tk.X, padx=10, pady=10)
        
        ttk.Button(toolbar, text="📁 Importar XML", command=self.import_xml).pack(side=tk.LEFT, padx=2)
        ttk.Button(toolbar, text="📂 Importar Carpeta", command=self.import_folder).pack(side=tk.LEFT, padx=2)
        ttk.Separator(toolbar, orient=tk.VERTICAL).pack(side=tk.LEFT, fill=tk.Y, padx=10)
        ttk.Button(toolbar, text="🔄 Actualizar", command=self.load_facturas).pack(side=tk.LEFT, padx=2)
        
        # Lista de facturas
        list_frame = ttk.Frame(self.tab_facturas)
        list_frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)
        
        columns = ("folio", "tipo", "proveedor", "fecha", "total", "items", "estado")
        self.facturas_tree = ttk.Treeview(list_frame, columns=columns, show="headings")
        
        self.facturas_tree.heading("folio", text="Folio")
        self.facturas_tree.heading("tipo", text="Tipo")
        self.facturas_tree.heading("proveedor", text="Proveedor")
        self.facturas_tree.heading("fecha", text="Fecha")
        self.facturas_tree.heading("total", text="Total")
        self.facturas_tree.heading("items", text="Items")
        self.facturas_tree.heading("estado", text="Estado")
        
        self.facturas_tree.column("folio", width=80)
        self.facturas_tree.column("tipo", width=80)
        self.facturas_tree.column("proveedor", width=200)
        self.facturas_tree.column("fecha", width=100)
        self.facturas_tree.column("total", width=100, anchor="e")
        self.facturas_tree.column("items", width=60, anchor="center")
        self.facturas_tree.column("estado", width=100)
        
        scrollbar = ttk.Scrollbar(list_frame, orient=tk.VERTICAL, command=self.facturas_tree.yview)
        self.facturas_tree.configure(yscrollcommand=scrollbar.set)
        
        self.facturas_tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        
        self.facturas_tree.bind("<Double-1>", self.show_factura_detail)
    
    def create_codigos_tab(self):
        """Crea contenido de códigos"""
        # Paneles divididos
        paned = ttk.PanedWindow(self.tab_codigos, orient=tk.HORIZONTAL)
        paned.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Panel izquierdo: Lista de códigos pendientes
        left_frame = ttk.LabelFrame(paned, text="Códigos Pendientes")
        paned.add(left_frame, weight=1)
        
        self.codigos_tree = ttk.Treeview(left_frame, columns=("codigo", "descripcion", "proveedor"), show="headings", height=20)
        self.codigos_tree.heading("codigo", text="Código Prov.")
        self.codigos_tree.heading("descripcion", text="Descripción")
        self.codigos_tree.heading("proveedor", text="Proveedor")
        self.codigos_tree.column("codigo", width=100)
        self.codigos_tree.column("descripcion", width=250)
        self.codigos_tree.column("proveedor", width=120)
        self.codigos_tree.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        self.codigos_tree.bind("<<TreeviewSelect>>", self.on_codigo_select)
        
        # Panel derecho: Vincular
        right_frame = ttk.LabelFrame(paned, text="Vincular Código")
        paned.add(right_frame, weight=1)
        
        form = ttk.Frame(right_frame)
        form.pack(fill=tk.X, padx=15, pady=15)
        
        ttk.Label(form, text="Código Proveedor:", style="Title.TLabel").grid(row=0, column=0, sticky="w", pady=5)
        self.lbl_codigo = ttk.Label(form, text="-", font=("Consolas", 12, "bold"))
        self.lbl_codigo.grid(row=0, column=1, sticky="w", padx=10)
        
        ttk.Label(form, text="Descripción:").grid(row=1, column=0, sticky="w", pady=5)
        self.lbl_descripcion = ttk.Label(form, text="-", wraplength=300)
        self.lbl_descripcion.grid(row=1, column=1, sticky="w", padx=10)
        
        ttk.Separator(form, orient=tk.HORIZONTAL).grid(row=2, column=0, columnspan=2, sticky="ew", pady=15)
        
        ttk.Label(form, text="SKU Local:").grid(row=3, column=0, sticky="w", pady=5)
        self.entry_sku = ttk.Entry(form, width=20, font=("Consolas", 11))
        self.entry_sku.grid(row=3, column=1, sticky="w", padx=10)
        
        ttk.Label(form, text="Buscar producto:").grid(row=4, column=0, sticky="w", pady=5)
        self.entry_buscar = ttk.Entry(form, width=30)
        self.entry_buscar.grid(row=4, column=1, sticky="w", padx=10)
        self.entry_buscar.bind("<KeyRelease>", self.search_products)
        
        # Resultados de búsqueda
        self.search_results = ttk.Treeview(right_frame, columns=("sku", "nombre"), show="headings", height=6)
        self.search_results.heading("sku", text="SKU")
        self.search_results.heading("nombre", text="Nombre")
        self.search_results.column("sku", width=100)
        self.search_results.column("nombre", width=300)
        self.search_results.pack(fill=tk.X, padx=15, pady=5)
        self.search_results.bind("<Double-1>", self.select_product)
        
        # Botones
        btn_frame = ttk.Frame(right_frame)
        btn_frame.pack(fill=tk.X, padx=15, pady=15)
        
        self.var_guardar_mapeo = tk.BooleanVar(value=True)
        ttk.Checkbutton(btn_frame, text="Guardar mapeo para futuras facturas", variable=self.var_guardar_mapeo).pack(anchor="w")
        
        ttk.Button(btn_frame, text="✓ Vincular", style="Accent.TButton", command=self.vincular_codigo).pack(side=tk.LEFT, pady=10)
        ttk.Button(btn_frame, text="✗ Omitir", command=self.omitir_codigo).pack(side=tk.LEFT, padx=10, pady=10)
    
    def create_tareas_tab(self):
        """Crea contenido de tareas"""
        # Toolbar
        toolbar = ttk.Frame(self.tab_tareas)
        toolbar.pack(fill=tk.X, padx=10, pady=10)
        
        ttk.Button(toolbar, text="➕ Nueva Tarea", command=self.nueva_tarea).pack(side=tk.LEFT, padx=2)
        
        ttk.Label(toolbar, text="Filtrar:").pack(side=tk.LEFT, padx=(20, 5))
        self.combo_filtro_tarea = ttk.Combobox(toolbar, values=["Todas", "Pendientes", "En progreso", "Completadas"], width=15)
        self.combo_filtro_tarea.set("Pendientes")
        self.combo_filtro_tarea.pack(side=tk.LEFT)
        self.combo_filtro_tarea.bind("<<ComboboxSelected>>", lambda e: self.load_tareas())
        
        # Lista de tareas
        list_frame = ttk.Frame(self.tab_tareas)
        list_frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)
        
        columns = ("id", "tipo", "titulo", "prioridad", "asignado", "estado", "fecha")
        self.tareas_tree = ttk.Treeview(list_frame, columns=columns, show="headings")
        
        self.tareas_tree.heading("id", text="ID")
        self.tareas_tree.heading("tipo", text="Tipo")
        self.tareas_tree.heading("titulo", text="Título")
        self.tareas_tree.heading("prioridad", text="Prioridad")
        self.tareas_tree.heading("asignado", text="Asignado a")
        self.tareas_tree.heading("estado", text="Estado")
        self.tareas_tree.heading("fecha", text="Fecha")
        
        self.tareas_tree.column("id", width=50)
        self.tareas_tree.column("tipo", width=120)
        self.tareas_tree.column("titulo", width=300)
        self.tareas_tree.column("prioridad", width=80)
        self.tareas_tree.column("asignado", width=120)
        self.tareas_tree.column("estado", width=100)
        self.tareas_tree.column("fecha", width=100)
        
        scrollbar = ttk.Scrollbar(list_frame, orient=tk.VERTICAL, command=self.tareas_tree.yview)
        self.tareas_tree.configure(yscrollcommand=scrollbar.set)
        
        self.tareas_tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        
        # Menú contextual
        self.tareas_menu = tk.Menu(self.tareas_tree, tearoff=0)
        self.tareas_menu.add_command(label="Completar", command=self.completar_tarea)
        self.tareas_menu.add_command(label="Editar", command=self.editar_tarea)
        self.tareas_menu.add_separator()
        self.tareas_menu.add_command(label="Eliminar", command=self.eliminar_tarea)
        
        self.tareas_tree.bind("<Button-3>", self.show_tareas_menu)
    
    def create_status_bar(self):
        """Crea barra de estado"""
        self.status_bar = ttk.Frame(self.root)
        self.status_bar.pack(fill=tk.X, side=tk.BOTTOM)
        
        self.status_label = ttk.Label(self.status_bar, text="Listo")
        self.status_label.pack(side=tk.LEFT, padx=10, pady=3)
        
        self.connection_label = ttk.Label(self.status_bar, text="⚪ Desconectado")
        self.connection_label.pack(side=tk.RIGHT, padx=10, pady=3)
    
    def set_status(self, message, type="info"):
        """Actualiza mensaje de estado"""
        self.status_label.config(text=message)
        if type == "success":
            self.status_label.config(style="Success.TLabel")
        elif type == "warning":
            self.status_label.config(style="Warning.TLabel")
        elif type == "error":
            self.status_label.config(style="Error.TLabel")
        else:
            self.status_label.config(style="TLabel")
    
    # ============ ACCIONES ============
    
    def load_initial_data(self):
        """Carga datos iniciales"""
        self.set_status("Cargando datos...")
        self.load_facturas()
        self.load_codigos()
        self.load_tareas()
        self.update_stats()
        self.set_status("Listo", "success")
    
    def refresh_all(self):
        """Actualiza todos los datos"""
        self.load_initial_data()
    
    def update_stats(self):
        """Actualiza estadísticas del dashboard"""
        self.stat_cards["facturas"].config(text=str(len(self.facturas)))
        self.stat_cards["pendientes"].config(text=str(len(self.codigos_pendientes)))
        self.stat_cards["vinculados"].config(text="0")  # TODO: cargar de servidor
        self.stat_cards["tareas"].config(text=str(len(self.tareas)))
    
    def import_xml(self):
        """Importa archivo XML de factura"""
        if not self.parser:
            messagebox.showerror("Error", "Parser XML no disponible. Verificar instalación.")
            return
        
        filetypes = [("Archivos XML", "*.xml"), ("Todos", "*.*")]
        initial_dir = self.config.get("last_xml_dir", "")
        
        filename = filedialog.askopenfilename(
            title="Seleccionar factura XML",
            filetypes=filetypes,
            initialdir=initial_dir
        )
        
        if not filename:
            return
        
        self.config["last_xml_dir"] = str(Path(filename).parent)
        self.save_config()
        
        try:
            self.set_status(f"Procesando {Path(filename).name}...")
            result = self.parser.parse_file(filename)
            
            if result:
                self.facturas.append(result)
                self.add_factura_to_tree(result)
                
                # Extraer códigos pendientes
                for item in result.get("items", []):
                    for codigo in item.get("codigos", []):
                        if codigo.get("valor"):
                            self.codigos_pendientes.append({
                                "codigo": codigo["valor"],
                                "descripcion": item.get("nombre", ""),
                                "proveedor": result.get("emisor", {}).get("razon_social", ""),
                            })
                
                self.load_codigos()
                self.update_stats()
                self.set_status(f"Importado: Folio {result.get('folio')} - {len(result.get('items', []))} items", "success")
                
                # Agregar a actividad
                self.add_activity("Importación", f"Factura {result.get('folio')} de {result.get('emisor', {}).get('razon_social', '')}")
            else:
                self.set_status("Error procesando XML", "error")
                
        except Exception as e:
            self.set_status(f"Error: {str(e)}", "error")
            messagebox.showerror("Error", f"No se pudo procesar el archivo:\n{str(e)}")
    
    def import_folder(self):
        """Importa carpeta con XMLs"""
        if not self.parser:
            messagebox.showerror("Error", "Parser XML no disponible")
            return
        
        folder = filedialog.askdirectory(title="Seleccionar carpeta con XMLs")
        if not folder:
            return
        
        xml_files = list(Path(folder).glob("*.xml"))
        if not xml_files:
            messagebox.showinfo("Info", "No se encontraron archivos XML en la carpeta")
            return
        
        self.set_status(f"Procesando {len(xml_files)} archivos...")
        
        imported = 0
        for xml_file in xml_files:
            try:
                result = self.parser.parse_file(str(xml_file))
                if result:
                    self.facturas.append(result)
                    self.add_factura_to_tree(result)
                    imported += 1
            except:
                pass
        
        self.update_stats()
        self.set_status(f"Importados {imported} de {len(xml_files)} archivos", "success")
    
    def add_factura_to_tree(self, factura):
        """Agrega factura al treeview"""
        tipo_map = {33: "Factura", 34: "F.Exenta", 52: "Guía", 61: "N.Crédito"}
        
        self.facturas_tree.insert("", "end", values=(
            factura.get("folio", ""),
            tipo_map.get(factura.get("tipo_dte"), str(factura.get("tipo_dte", ""))),
            factura.get("emisor", {}).get("razon_social", "")[:30],
            factura.get("fecha_emision", ""),
            f"${factura.get('totales', {}).get('total', 0):,.0f}",
            len(factura.get("items", [])),
            "Procesado"
        ))
    
    def load_facturas(self):
        """Carga facturas en el treeview"""
        for item in self.facturas_tree.get_children():
            self.facturas_tree.delete(item)
        
        for factura in self.facturas:
            self.add_factura_to_tree(factura)
    
    def show_factura_detail(self, event):
        """Muestra detalle de factura"""
        selection = self.facturas_tree.selection()
        if not selection:
            return
        
        item = self.facturas_tree.item(selection[0])
        folio = item["values"][0]
        
        # Buscar factura
        factura = next((f for f in self.facturas if f.get("folio") == folio), None)
        if not factura:
            return
        
        # Crear ventana de detalle
        detail_win = tk.Toplevel(self.root)
        detail_win.title(f"Factura #{folio}")
        detail_win.geometry("700x500")
        
        # Info
        info_frame = ttk.LabelFrame(detail_win, text="Información")
        info_frame.pack(fill=tk.X, padx=10, pady=10)
        
        emisor = factura.get("emisor", {})
        ttk.Label(info_frame, text=f"Proveedor: {emisor.get('razon_social', '')}").pack(anchor="w", padx=10, pady=2)
        ttk.Label(info_frame, text=f"RUT: {emisor.get('rut', '')}").pack(anchor="w", padx=10, pady=2)
        ttk.Label(info_frame, text=f"Fecha: {factura.get('fecha_emision', '')}").pack(anchor="w", padx=10, pady=2)
        ttk.Label(info_frame, text=f"Total: ${factura.get('totales', {}).get('total', 0):,.0f}").pack(anchor="w", padx=10, pady=2)
        
        # Items
        items_frame = ttk.LabelFrame(detail_win, text="Items")
        items_frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        cols = ("linea", "codigo", "descripcion", "cant", "precio", "total")
        tree = ttk.Treeview(items_frame, columns=cols, show="headings")
        for col in cols:
            tree.heading(col, text=col.capitalize())
        tree.column("linea", width=50)
        tree.column("codigo", width=100)
        tree.column("descripcion", width=250)
        tree.column("cant", width=60)
        tree.column("precio", width=80)
        tree.column("total", width=80)
        tree.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        for item in factura.get("items", []):
            codigo = item.get("codigos", [{}])[0].get("valor", "") if item.get("codigos") else ""
            tree.insert("", "end", values=(
                item.get("numero", ""),
                codigo,
                item.get("nombre", "")[:40],
                item.get("cantidad", ""),
                f"${item.get('precio', 0):,.0f}",
                f"${item.get('monto', 0):,.0f}"
            ))
    
    def load_codigos(self):
        """Carga códigos pendientes"""
        for item in self.codigos_tree.get_children():
            self.codigos_tree.delete(item)
        
        for codigo in self.codigos_pendientes:
            self.codigos_tree.insert("", "end", values=(
                codigo.get("codigo", ""),
                codigo.get("descripcion", "")[:40],
                codigo.get("proveedor", "")[:20]
            ))
    
    def on_codigo_select(self, event):
        """Al seleccionar un código"""
        selection = self.codigos_tree.selection()
        if not selection:
            return
        
        item = self.codigos_tree.item(selection[0])
        values = item["values"]
        
        self.lbl_codigo.config(text=values[0])
        self.lbl_descripcion.config(text=values[1])
        self.entry_sku.delete(0, tk.END)
        self.entry_buscar.delete(0, tk.END)
    
    def search_products(self, event):
        """Busca productos por nombre/SKU"""
        query = self.entry_buscar.get().strip()
        if len(query) < 2:
            return
        
        # Limpiar resultados
        for item in self.search_results.get_children():
            self.search_results.delete(item)
        
        # TODO: Buscar en WooCommerce via API
        # Por ahora datos de ejemplo
        self.search_results.insert("", "end", values=("SKU-001", f"Producto que contiene '{query}'"))
    
    def select_product(self, event):
        """Selecciona producto de la búsqueda"""
        selection = self.search_results.selection()
        if not selection:
            return
        
        item = self.search_results.item(selection[0])
        sku = item["values"][0]
        self.entry_sku.delete(0, tk.END)
        self.entry_sku.insert(0, sku)
    
    def vincular_codigo(self):
        """Vincula código con SKU"""
        sku = self.entry_sku.get().strip()
        if not sku:
            messagebox.showwarning("Advertencia", "Ingrese un SKU")
            return
        
        codigo = self.lbl_codigo.cget("text")
        if codigo == "-":
            messagebox.showwarning("Advertencia", "Seleccione un código primero")
            return
        
        # Remover de pendientes
        self.codigos_pendientes = [c for c in self.codigos_pendientes if c.get("codigo") != codigo]
        self.load_codigos()
        self.update_stats()
        
        self.lbl_codigo.config(text="-")
        self.lbl_descripcion.config(text="-")
        self.entry_sku.delete(0, tk.END)
        
        self.add_activity("Vinculación", f"Código {codigo} → SKU {sku}")
        self.set_status(f"Código {codigo} vinculado a {sku}", "success")
    
    def omitir_codigo(self):
        """Omite código sin vincular"""
        codigo = self.lbl_codigo.cget("text")
        if codigo == "-":
            return
        
        self.codigos_pendientes = [c for c in self.codigos_pendientes if c.get("codigo") != codigo]
        self.load_codigos()
        self.update_stats()
        
        self.lbl_codigo.config(text="-")
        self.lbl_descripcion.config(text="-")
    
    def load_tareas(self):
        """Carga tareas"""
        for item in self.tareas_tree.get_children():
            self.tareas_tree.delete(item)
        
        filtro = self.combo_filtro_tarea.get()
        
        for tarea in self.tareas:
            if filtro == "Pendientes" and tarea.get("estado") != "pendiente":
                continue
            if filtro == "Completadas" and tarea.get("estado") != "completada":
                continue
            
            self.tareas_tree.insert("", "end", values=(
                tarea.get("id", ""),
                tarea.get("tipo", ""),
                tarea.get("titulo", ""),
                tarea.get("prioridad", "normal"),
                tarea.get("asignado", "-"),
                tarea.get("estado", "pendiente"),
                tarea.get("fecha", "")
            ))
    
    def nueva_tarea(self):
        """Crea nueva tarea"""
        dialog = tk.Toplevel(self.root)
        dialog.title("Nueva Tarea")
        dialog.geometry("400x350")
        dialog.transient(self.root)
        dialog.grab_set()
        
        form = ttk.Frame(dialog)
        form.pack(fill=tk.BOTH, expand=True, padx=20, pady=20)
        
        ttk.Label(form, text="Tipo:").grid(row=0, column=0, sticky="w", pady=5)
        tipo_combo = ttk.Combobox(form, values=["Cotización", "Picking", "Reposición", "Inventario", "Código faltante"], width=25)
        tipo_combo.grid(row=0, column=1, pady=5)
        tipo_combo.set("Cotización")
        
        ttk.Label(form, text="Título:").grid(row=1, column=0, sticky="w", pady=5)
        titulo_entry = ttk.Entry(form, width=30)
        titulo_entry.grid(row=1, column=1, pady=5)
        
        ttk.Label(form, text="Descripción:").grid(row=2, column=0, sticky="nw", pady=5)
        desc_text = tk.Text(form, width=30, height=4)
        desc_text.grid(row=2, column=1, pady=5)
        
        ttk.Label(form, text="Prioridad:").grid(row=3, column=0, sticky="w", pady=5)
        prio_combo = ttk.Combobox(form, values=["baja", "normal", "alta", "urgente"], width=25)
        prio_combo.grid(row=3, column=1, pady=5)
        prio_combo.set("normal")
        
        def guardar():
            tarea = {
                "id": len(self.tareas) + 1,
                "tipo": tipo_combo.get(),
                "titulo": titulo_entry.get(),
                "descripcion": desc_text.get("1.0", "end-1c"),
                "prioridad": prio_combo.get(),
                "estado": "pendiente",
                "fecha": datetime.now().strftime("%Y-%m-%d")
            }
            self.tareas.append(tarea)
            self.load_tareas()
            self.update_stats()
            self.add_activity("Tarea", f"Creada: {tarea['titulo']}")
            dialog.destroy()
        
        btn_frame = ttk.Frame(form)
        btn_frame.grid(row=4, column=0, columnspan=2, pady=20)
        ttk.Button(btn_frame, text="Guardar", command=guardar).pack(side=tk.LEFT, padx=5)
        ttk.Button(btn_frame, text="Cancelar", command=dialog.destroy).pack(side=tk.LEFT, padx=5)
    
    def show_tareas_menu(self, event):
        """Muestra menú contextual de tareas"""
        item = self.tareas_tree.identify_row(event.y)
        if item:
            self.tareas_tree.selection_set(item)
            self.tareas_menu.post(event.x_root, event.y_root)
    
    def completar_tarea(self):
        """Marca tarea como completada"""
        selection = self.tareas_tree.selection()
        if not selection:
            return
        
        item = self.tareas_tree.item(selection[0])
        tarea_id = item["values"][0]
        
        for tarea in self.tareas:
            if tarea.get("id") == tarea_id:
                tarea["estado"] = "completada"
                break
        
        self.load_tareas()
        self.set_status(f"Tarea #{tarea_id} completada", "success")
    
    def editar_tarea(self):
        """Edita tarea seleccionada"""
        messagebox.showinfo("Info", "Función en desarrollo")
    
    def eliminar_tarea(self):
        """Elimina tarea seleccionada"""
        selection = self.tareas_tree.selection()
        if not selection:
            return
        
        if not messagebox.askyesno("Confirmar", "¿Eliminar esta tarea?"):
            return
        
        item = self.tareas_tree.item(selection[0])
        tarea_id = item["values"][0]
        
        self.tareas = [t for t in self.tareas if t.get("id") != tarea_id]
        self.load_tareas()
        self.update_stats()
    
    def add_activity(self, tipo, descripcion):
        """Agrega actividad al log"""
        fecha = datetime.now().strftime("%Y-%m-%d %H:%M")
        self.activity_list.insert("", 0, values=(fecha, tipo, descripcion))
    
    def filter_view(self, filtro):
        """Filtra vista"""
        pass
    
    def export_codes(self):
        """Exporta códigos a JSON"""
        filename = filedialog.asksaveasfilename(
            title="Exportar códigos",
            defaultextension=".json",
            filetypes=[("JSON", "*.json")]
        )
        if not filename:
            return
        
        # TODO: Exportar códigos vinculados
        data = {"codigos_pendientes": self.codigos_pendientes}
        with open(filename, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        
        self.set_status(f"Exportado a {Path(filename).name}", "success")
    
    def sync_server(self):
        """Sincroniza con servidor WordPress"""
        self.set_status("Sincronizando con servidor...")
        # TODO: Implementar sincronización via REST API
        messagebox.showinfo("Info", "Sincronización con servidor en desarrollo.\nRequiere configurar API credentials.")
    
    def test_connection(self):
        """Prueba conexión con servidor"""
        messagebox.showinfo("Conexión", f"Servidor: {self.config.get('wp_url')}\nEstado: En desarrollo")
    
    def show_settings(self):
        """Muestra configuración"""
        dialog = tk.Toplevel(self.root)
        dialog.title("Configuración")
        dialog.geometry("450x350")
        dialog.transient(self.root)
        dialog.grab_set()
        
        form = ttk.Frame(dialog)
        form.pack(fill=tk.BOTH, expand=True, padx=20, pady=20)
        
        ttk.Label(form, text="URL WordPress:").grid(row=0, column=0, sticky="w", pady=5)
        url_entry = ttk.Entry(form, width=35)
        url_entry.insert(0, self.config.get("wp_url", ""))
        url_entry.grid(row=0, column=1, pady=5)
        
        ttk.Label(form, text="API Key:").grid(row=1, column=0, sticky="w", pady=5)
        key_entry = ttk.Entry(form, width=35)
        key_entry.insert(0, self.config.get("api_key", ""))
        key_entry.grid(row=1, column=1, pady=5)
        
        ttk.Label(form, text="API Secret:").grid(row=2, column=0, sticky="w", pady=5)
        secret_entry = ttk.Entry(form, width=35, show="*")
        secret_entry.insert(0, self.config.get("api_secret", ""))
        secret_entry.grid(row=2, column=1, pady=5)
        
        ttk.Label(form, text="SSH Host:").grid(row=3, column=0, sticky="w", pady=5)
        ssh_entry = ttk.Entry(form, width=35)
        ssh_entry.insert(0, self.config.get("ssh_host", ""))
        ssh_entry.grid(row=3, column=1, pady=5)
        
        def guardar():
            self.config["wp_url"] = url_entry.get()
            self.config["api_key"] = key_entry.get()
            self.config["api_secret"] = secret_entry.get()
            self.config["ssh_host"] = ssh_entry.get()
            self.save_config()
            dialog.destroy()
            self.set_status("Configuración guardada", "success")
        
        btn_frame = ttk.Frame(form)
        btn_frame.grid(row=5, column=0, columnspan=2, pady=30)
        ttk.Button(btn_frame, text="Guardar", command=guardar).pack(side=tk.LEFT, padx=5)
        ttk.Button(btn_frame, text="Cancelar", command=dialog.destroy).pack(side=tk.LEFT, padx=5)
    
    def show_docs(self):
        """Muestra documentación"""
        import webbrowser
        webbrowser.open("https://github.com/riverso/catalogo#readme")
    
    def show_about(self):
        """Muestra información del programa"""
        messagebox.showinfo(
            "Acerca de",
            "Riverso POS - Panel de Administración\n\n"
            "Versión: 1.0.0\n"
            "Sistema de gestión de facturas, códigos y tareas\n"
            "integrado con WooCommerce.\n\n"
            "© 2026 Riverso"
        )


def main():
    root = tk.Tk()
    app = RiversoPOSApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
