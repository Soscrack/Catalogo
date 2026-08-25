<?php
/**
 * Activador del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Activator {
    
    /**
     * Ejecuta al activar el plugin
     */
    public static function activate() {
        // Verificar permisos
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        // Crear tablas
        self::create_tables();
        
        // Crear roles y capacidades
        self::create_roles();
        
        // Opciones por defecto
        self::set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Marcar como activado
        update_option('riverso_pos_activated', time());
    }
    
    /**
     * Crea las tablas de la base de datos
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Tabla: Proveedores
        $sql = "CREATE TABLE {$prefix}proveedores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rut VARCHAR(20) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            giro VARCHAR(255) DEFAULT NULL,
            direccion VARCHAR(255) DEFAULT NULL,
            comuna VARCHAR(100) DEFAULT NULL,
            ciudad VARCHAR(100) DEFAULT NULL,
            telefono VARCHAR(50) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            contacto VARCHAR(255) DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY rut (rut)
        ) $charset_collate;";
        dbDelta($sql);

        // Tabla: Apodos de proveedores (nombres cortos / alias de búsqueda)
        $sql = "CREATE TABLE {$prefix}proveedor_apodos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            proveedor_id BIGINT UNSIGNED NOT NULL,
            apodo VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_proveedor_apodo (proveedor_id, apodo),
            KEY idx_apodo (apodo)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Códigos
        $sql = "CREATE TABLE {$prefix}codigos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku_local VARCHAR(100) DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            product_base_id BIGINT UNSIGNED DEFAULT NULL,
            supplier_product_id BIGINT UNSIGNED DEFAULT NULL,
            codigo_proveedor VARCHAR(100) NOT NULL,
            codigo_tipo VARCHAR(20) DEFAULT 'INT1',
            codigo_barras VARCHAR(50) DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            nombre_proveedor VARCHAR(255) DEFAULT NULL,
            unidad_medida VARCHAR(20) DEFAULT NULL,
            factor_conversion DECIMAL(10,4) DEFAULT 1.0000,
            precio_referencia DECIMAL(12,2) DEFAULT NULL,
            verificado TINYINT(1) DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            verificado_por BIGINT UNSIGNED DEFAULT NULL,
            verificado_at DATETIME DEFAULT NULL,
            sku_mapped_at DATETIME DEFAULT NULL,
            last_seen_document_date DATE DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY codigo_proveedor_proveedor (codigo_proveedor, proveedor_id),
            KEY sku_local (sku_local),
            KEY product_id (product_id),
            KEY product_base_id (product_base_id),
            KEY supplier_product_id (supplier_product_id),
            KEY codigo_barras (codigo_barras),
            KEY proveedor_codigo_activo (proveedor_id, codigo_proveedor, activo)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Historial de códigos
        $sql = "CREATE TABLE {$prefix}codigos_historial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo_id BIGINT UNSIGNED NOT NULL,
            accion VARCHAR(20) NOT NULL,
            campo_modificado VARCHAR(50) DEFAULT NULL,
            valor_anterior TEXT DEFAULT NULL,
            valor_nuevo TEXT DEFAULT NULL,
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY codigo_id (codigo_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Facturas
        $sql = "CREATE TABLE {$prefix}facturas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo_dte INT NOT NULL,
            folio VARCHAR(50) NOT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            rut_emisor VARCHAR(20) NOT NULL,
            razon_social_emisor VARCHAR(255) DEFAULT NULL,
            fecha_emision DATE NOT NULL,
            fecha_vencimiento DATE DEFAULT NULL,
            monto_neto DECIMAL(12,2) DEFAULT 0,
            monto_iva DECIMAL(12,2) DEFAULT 0,
            monto_total DECIMAL(12,2) DEFAULT 0,
            tasa_iva DECIMAL(8,4) DEFAULT NULL,
            impuestos_adicionales LONGTEXT DEFAULT NULL,
            estado VARCHAR(20) DEFAULT 'pendiente',
            xml_path VARCHAR(255) DEFAULT NULL,
            xml_hash VARCHAR(64) DEFAULT NULL,
            items_total INT DEFAULT 0,
            items_vinculados INT DEFAULT 0,
            procesado_por BIGINT UNSIGNED DEFAULT NULL,
            procesado_at DATETIME DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tipo_folio_rut (tipo_dte, folio, rut_emisor),
            KEY estado (estado),
            KEY fecha_emision (fecha_emision)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Items de factura
        $sql = "CREATE TABLE {$prefix}factura_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            factura_id BIGINT UNSIGNED NOT NULL,
            numero_linea INT NOT NULL,
            codigo_proveedor VARCHAR(100) DEFAULT NULL,
            codigo_tipo VARCHAR(20) DEFAULT NULL,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT DEFAULT NULL,
            cantidad DECIMAL(12,4) NOT NULL,
            unidad VARCHAR(20) DEFAULT NULL,
            precio_unitario DECIMAL(12,4) DEFAULT 0,
            descuento_porcentaje DECIMAL(8,4) DEFAULT NULL,
            descuento_monto DECIMAL(12,4) DEFAULT NULL,
            recargo_porcentaje DECIMAL(8,4) DEFAULT NULL,
            recargo_monto DECIMAL(12,4) DEFAULT NULL,
            cod_imp_adic VARCHAR(10) DEFAULT NULL,
            impuesto_especifico_tasa DECIMAL(8,4) DEFAULT NULL,
            impuesto_especifico_monto DECIMAL(12,4) DEFAULT NULL,
            costo_neto_base DECIMAL(12,4) DEFAULT NULL,
            costo_bruto_base DECIMAL(12,4) DEFAULT NULL,
            costo_neto_final DECIMAL(12,4) DEFAULT NULL,
            costo_bruto_final DECIMAL(12,4) DEFAULT NULL,
            monto_total DECIMAL(12,2) DEFAULT 0,
            codigo_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) DEFAULT 'pendiente',
            cantidad_recibida DECIMAL(12,4) DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY factura_id (factura_id),
            KEY codigo_proveedor (codigo_proveedor),
            KEY estado (estado)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Tareas
        $sql = "CREATE TABLE {$prefix}tareas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(30) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            descripcion TEXT DEFAULT NULL,
            prioridad VARCHAR(20) DEFAULT 'normal',
            estado VARCHAR(20) DEFAULT 'pendiente',
            asignado_a BIGINT UNSIGNED DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            referencia_tipo VARCHAR(50) DEFAULT NULL,
            referencia_id BIGINT UNSIGNED DEFAULT NULL,
            datos_extra JSON DEFAULT NULL,
            fecha_limite DATETIME DEFAULT NULL,
            completado_en DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tipo (tipo),
            KEY estado (estado),
            KEY asignado_a (asignado_a),
            KEY prioridad (prioridad)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Ubicaciones
        $sql = "CREATE TABLE {$prefix}ubicaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            nombre VARCHAR(100) DEFAULT NULL,
            tipo VARCHAR(20) DEFAULT 'estante',
            descripcion TEXT DEFAULT NULL,
            capacidad INT DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY codigo (codigo),
            KEY tipo (tipo),
            KEY activo (activo)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Producto-Ubicación
        $sql = "CREATE TABLE {$prefix}producto_ubicacion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_id BIGINT UNSIGNED NOT NULL,
            cantidad INT DEFAULT 0,
            posicion VARCHAR(50) DEFAULT NULL,
            es_principal TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY producto_ubicacion (product_id, variation_id, ubicacion_id),
            KEY ubicacion_id (ubicacion_id),
            KEY es_principal (es_principal)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Movimientos
        $sql = "CREATE TABLE {$prefix}movimientos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED DEFAULT NULL,
            lote_id BIGINT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(20) NOT NULL,
            cantidad DECIMAL(12,4) NOT NULL,
            stock_anterior DECIMAL(12,4) DEFAULT NULL,
            stock_nuevo DECIMAL(12,4) DEFAULT NULL,
            ubicacion_origen BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_destino BIGINT UNSIGNED DEFAULT NULL,
            referencia_tipo VARCHAR(50) DEFAULT NULL,
            referencia_id BIGINT UNSIGNED DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY variation_id (variation_id),
            KEY lote_id (lote_id),
            KEY tipo (tipo),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        self::create_module_tables();
        self::create_phase1_domain_tables($prefix, $charset_collate);
        self::create_phase1_domain_views($prefix);
        self::run_phase1_backfill($prefix);
        self::create_phase2_governance($prefix);
        self::create_phase2_matching($prefix);
        self::create_phase8_publication($prefix);
        self::create_phase9_invoice_intake($prefix);
        self::create_phase10_mamut_sku_repair();
        self::create_phase11_flete_vinculos($prefix);
        
        // Tabla: Empleados (Fase 1 - Core infrastructure)
        self::ensure_employees_table();
        
        // Tabla: Auditoría
        if (!class_exists('Riverso_POS_Audit')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/audit/class-audit.php';
        }
        Riverso_POS_Audit::create_table();

        // Fases ERP 10–12 (core/códigos/inventario/OC/sync)
        self::create_erp_phase_tables($prefix, $charset_collate);
        self::create_phase15_catalog_health($prefix, $charset_collate);
        self::create_phase16_invoice_credit_notes($prefix, $charset_collate);
        self::create_phase17_invoice_item_costs($prefix);
        self::create_phase18_catalogs($prefix, $charset_collate);
        self::create_phase19_clear_catalog_as_local_sku($prefix);
        self::create_phase20_hub_v2_images($prefix);
        self::create_phase21_invoice_tipo_confirmado($prefix);
        self::create_phase22_untrusted_supplier_sku_repair();
        self::create_phase23_sku_mapping_dates($prefix);
        self::create_phase24_identity_sku_repair();
        self::create_phase25_print_orders($prefix, $charset_collate);
        self::create_phase26_inventory_locations($prefix, $charset_collate);
        self::create_phase27_sort_orders($prefix, $charset_collate);
        self::create_phase28_stock_status($prefix, $charset_collate);
        self::create_phase29_barcodes_authoritative($prefix);
        self::create_phase30_envase_tipos($prefix, $charset_collate);
        self::create_phase31_scan_documents($prefix, $charset_collate);
        self::create_phase32_factura_origen_ingreso($prefix);
        self::create_phase33_facto_integration($prefix, $charset_collate);
        self::create_phase34_facto_inbox_import($prefix, $charset_collate);
        self::create_phase35_tecbolt_unify($prefix);
        self::create_phase35b_catalog_mapping_confirm($prefix);

        // Inicializar servicios core
        self::init_core_services();
        
        update_option('riverso_pos_db_version', RIVERSO_POS_VERSION);
    }
    
    /**
     * Crea los roles personalizados
     */
    public static function create_roles() {
        // Usar el método centralizado de la clase de permisos
        if (!class_exists('Riverso_POS_Permissions')) {
            $perm = RIVERSO_POS_PLUGIN_DIR . 'core/permissions/class-permissions.php';
            if (file_exists($perm)) {
                require_once $perm;
            } else {
                require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-permissions.php';
            }
        }
        Riverso_POS_Permissions::setup_roles();
    }
    
    /**
     * Establece opciones por defecto
     */
    public static function set_default_options() {
        $defaults = [
            'riverso_pos_settings' => [
                'currency' => 'CLP',
                'tax_rate' => 19,
                'default_stock_status' => 'instock',
                'low_stock_threshold' => 5,
                'enable_barcode_scanner' => true,
                'task_auto_assign' => false,
                'auto_inventory_on_approve' => true,
                'create_reception_task_on_upload' => true,
                'prorate_shipping_to_products' => true,
                'create_link_task_on_upload' => true,
                'default_intake_mode' => 'solo_costos',
            ]
        ];
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }

        // Asegurar claves nuevas en instalaciones existentes.
        $settings = get_option('riverso_pos_settings', []);
        $settings_changed = false;
        foreach (['auto_inventory_on_approve', 'create_reception_task_on_upload', 'prorate_shipping_to_products', 'create_link_task_on_upload', 'default_intake_mode'] as $key) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = $defaults['riverso_pos_settings'][$key];
                $settings_changed = true;
            }
        }
        if ($settings_changed) {
            update_option('riverso_pos_settings', $settings);
        }
    }
    
    /**
     * Actualiza la base de datos si es necesario
     */
    public static function update_database() {
        self::create_tables();
        // Refrescar roles/capacidades para incorporar capabilities nuevas
        // (precios, matching, embolsado, órdenes POS) en actualizaciones.
        self::create_roles();
    }

    /**
     * Crea tablas de módulos que mantienen su propio schema.
     */
    private static function create_module_tables() {
        $module_defs = [
            ['path' => 'modules/codes/class-supplier-links-module.php', 'class' => 'Riverso_Supplier_Links_Module'],
            ['path' => 'modules/barcodes/class-barcode-module.php', 'class' => 'Riverso_Barcode_Module'],
            ['path' => 'modules/products/class-product-module.php', 'class' => 'Riverso_Product_Module'],
            ['path' => 'modules/tienda-local/class-tienda-local-module.php', 'class' => 'Riverso_Tienda_Local_Module'],
            ['path' => 'modules/costs/class-cost-history-module.php', 'class' => 'Riverso_Cost_History_Module'],
            ['path' => 'modules/pos/class-pos-module.php', 'class' => 'Riverso_POS_Module'],
            ['path' => 'modules/quotes/class-received-quote-module.php', 'class' => 'Riverso_POS_Received_Quote_Module'],
            ['path' => 'modules/customer-quotes/class-customer-quote-module.php', 'class' => 'Riverso_Customer_Quote_Module'],
            ['path' => 'modules/pricing/class-pricing-module.php', 'class' => 'Riverso_Pricing_Module'],
            ['path' => 'modules/publish/class-woo-publisher-module.php', 'class' => 'Riverso_Woo_Publisher_Module'],
            ['path' => 'modules/packaging/class-packaging-module.php', 'class' => 'Riverso_Packaging_Module'],
            ['path' => 'modules/print-orders/class-print-order-module.php', 'class' => 'Riverso_Print_Order_Module'],
            ['path' => 'modules/inventory/class-inventory-module.php', 'class' => 'Riverso_Inventory_Count_Module'],
        ];

        foreach ($module_defs as $def) {
            $file = RIVERSO_POS_PLUGIN_DIR . $def['path'];
            if (!file_exists($file)) {
                continue;
            }
            require_once $file;
            if (class_exists($def['class']) && method_exists($def['class'], 'create_tables')) {
                $def['class']::create_tables();
            }
        }
    }

    /**
     * Crea estructuras de dominio Fase 1 (producto/proveedor, lotes y equivalencias).
     */
    private static function create_phase1_domain_tables($prefix, $charset_collate) {
        global $wpdb;

        $sql = "CREATE TABLE {$prefix}producto_base (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            woocommerce_product_id BIGINT UNSIGNED DEFAULT NULL,
            woocommerce_variation_id BIGINT UNSIGNED DEFAULT NULL,
            canonical_sku VARCHAR(100) DEFAULT NULL,
            nombre_canonico VARCHAR(255) DEFAULT NULL,
            unidad_base VARCHAR(20) DEFAULT 'unidad',
            permite_decimal TINYINT(1) DEFAULT 0,
            permite_ean13_personalizado TINYINT(1) DEFAULT 1,
            stock_abierto_habilitado TINYINT(1) DEFAULT 0,
            codigo_abierto VARCHAR(100) DEFAULT NULL,
            estado VARCHAR(20) DEFAULT 'activo',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_wc_ref (woocommerce_product_id, woocommerce_variation_id),
            UNIQUE KEY ux_canonical_sku (canonical_sku),
            UNIQUE KEY ux_codigo_abierto (codigo_abierto),
            KEY idx_estado (estado)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}producto_proveedor (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            grupo_id BIGINT UNSIGNED DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED NOT NULL,
            supplier_link_id BIGINT UNSIGNED DEFAULT NULL,
            catalogo_id BIGINT UNSIGNED DEFAULT NULL,
            codigo_proveedor VARCHAR(100) NOT NULL,
            codigo_barras_proveedor VARCHAR(50) DEFAULT NULL,
            nombre_proveedor VARCHAR(255) DEFAULT NULL,
            unidad_compra VARCHAR(20) DEFAULT NULL,
            factor_conversion DECIMAL(10,4) DEFAULT 1.0000,
            precio_referencia DECIMAL(12,2) DEFAULT NULL,
            match_confidence INT DEFAULT NULL,
            es_preferido TINYINT(1) DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            origen_datos VARCHAR(50) DEFAULT 'manual',
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_proveedor_codigo (proveedor_id, codigo_proveedor),
            KEY idx_producto_base (producto_base_id),
            KEY idx_grupo_id (grupo_id),
            KEY idx_catalogo (catalogo_id),
            KEY idx_codigo_barras (codigo_barras_proveedor),
            KEY idx_supplier_link (supplier_link_id),
            KEY idx_activo (activo)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}lotes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_proveedor_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            variation_id BIGINT UNSIGNED DEFAULT NULL,
            lote_codigo VARCHAR(100) NOT NULL,
            fecha_recepcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_vencimiento DATE DEFAULT NULL,
            cantidad_inicial DECIMAL(12,4) NOT NULL DEFAULT 0,
            cantidad_disponible DECIMAL(12,4) NOT NULL DEFAULT 0,
            costo_total DECIMAL(12,2) DEFAULT NULL,
            costo_unitario DECIMAL(12,4) DEFAULT NULL,
            moneda VARCHAR(3) DEFAULT 'CLP',
            estado VARCHAR(20) DEFAULT 'abierto',
            documento_tipo VARCHAR(30) DEFAULT NULL,
            documento_id BIGINT UNSIGNED DEFAULT NULL,
            documento_item_id BIGINT UNSIGNED DEFAULT NULL,
            origen_datos VARCHAR(50) DEFAULT 'manual',
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_lote_proveedor (producto_proveedor_id, lote_codigo),
            KEY idx_producto (product_id),
            KEY idx_variacion (variation_id),
            KEY idx_estado (estado),
            KEY idx_recepcion (fecha_recepcion)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}equivalence_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo_grupo VARCHAR(100) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            tipo_sustitucion VARCHAR(20) DEFAULT 'exacta',
            activo TINYINT(1) DEFAULT 1,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_codigo_grupo (codigo_grupo),
            KEY idx_tipo (tipo_sustitucion),
            KEY idx_activo (activo)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}equivalence_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grupo_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            prioridad INT DEFAULT 100,
            es_reemplazo_preferido TINYINT(1) DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_group_member (grupo_id, producto_base_id),
            KEY idx_producto (producto_base_id),
            KEY idx_preferido (es_reemplazo_preferido)
        ) $charset_collate;";
        dbDelta($sql);

        // Índices/columnas puente de compatibilidad para tablas existentes.
        self::add_column_if_missing("{$prefix}supplier_product_links", 'producto_proveedor_id', 'producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing("{$prefix}supplier_product_links", 'product_base_id', 'product_base_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_index_if_missing("{$prefix}supplier_product_links", 'idx_producto_proveedor_id', 'KEY idx_producto_proveedor_id (producto_proveedor_id)');
        self::add_index_if_missing("{$prefix}supplier_product_links", 'idx_product_base_id', 'KEY idx_product_base_id (product_base_id)');
        self::add_column_if_missing("{$prefix}barcodes", 'is_active', 'is_active TINYINT(1) DEFAULT 1');
        self::add_index_if_missing("{$prefix}barcodes", 'idx_is_active', 'KEY idx_is_active (is_active)');
    }

    /**
     * Vistas para consultas operativas de Fase 1.
     */
    private static function create_phase1_domain_views($prefix) {
        global $wpdb;

        $wpdb->query("DROP VIEW IF EXISTS {$prefix}v_producto_proveedor_activo");
        $wpdb->query("
            CREATE VIEW {$prefix}v_producto_proveedor_activo AS
            SELECT
                pp.id AS producto_proveedor_id,
                pp.proveedor_id,
                pp.codigo_proveedor,
                pp.codigo_barras_proveedor,
                pp.nombre_proveedor,
                pp.precio_referencia,
                pb.id AS producto_base_id,
                pb.canonical_sku,
                pb.nombre_canonico,
                pb.woocommerce_product_id,
                pb.woocommerce_variation_id
            FROM {$prefix}producto_proveedor pp
            INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
            WHERE pp.activo = 1
        ");

        $wpdb->query("DROP VIEW IF EXISTS {$prefix}v_lotes_disponibles");
        $wpdb->query("
            CREATE VIEW {$prefix}v_lotes_disponibles AS
            SELECT
                l.id,
                l.producto_proveedor_id,
                l.product_id,
                l.variation_id,
                l.lote_codigo,
                l.cantidad_inicial,
                l.cantidad_disponible,
                l.costo_unitario,
                l.estado,
                l.fecha_recepcion
            FROM {$prefix}lotes l
            WHERE l.cantidad_disponible > 0 AND l.estado IN ('abierto', 'parcial')
        ");
    }

    /**
     * Backfill inicial no destructivo desde tablas legacy.
     */
    private static function run_phase1_backfill($prefix) {
        global $wpdb;

        $wpdb->query("
            INSERT IGNORE INTO {$prefix}producto_base (
                woocommerce_product_id,
                woocommerce_variation_id,
                canonical_sku,
                nombre_canonico,
                unidad_base,
                permite_decimal,
                permite_ean13_personalizado,
                stock_abierto_habilitado,
                estado
            )
            SELECT DISTINCT
                COALESCE(c.product_id, 0),
                0,
                NULLIF(TRIM(c.sku_local), ''),
                NULLIF(TRIM(c.nombre_proveedor), ''),
                COALESCE(NULLIF(TRIM(c.unidad_medida), ''), 'unidad'),
                0,
                1,
                0,
                'activo'
            FROM {$prefix}codigos c
            WHERE COALESCE(c.product_id, 0) > 0 OR NULLIF(TRIM(c.sku_local), '') IS NOT NULL
        ");

        $wpdb->query("
            INSERT IGNORE INTO {$prefix}producto_proveedor (
                producto_base_id,
                proveedor_id,
                codigo_proveedor,
                codigo_barras_proveedor,
                nombre_proveedor,
                unidad_compra,
                factor_conversion,
                precio_referencia,
                match_confidence,
                es_preferido,
                activo,
                origen_datos,
                notas
            )
            SELECT
                pb.id,
                COALESCE(c.proveedor_id, 0),
                c.codigo_proveedor,
                c.codigo_barras,
                c.nombre_proveedor,
                c.unidad_medida,
                c.factor_conversion,
                c.precio_referencia,
                CASE WHEN c.verificado = 1 THEN 100 ELSE NULL END,
                0,
                COALESCE(c.activo, 1),
                'riverso_codigos',
                c.notas
            FROM {$prefix}codigos c
            INNER JOIN {$prefix}producto_base pb
                ON pb.woocommerce_product_id = c.product_id
               AND pb.woocommerce_variation_id = 0
            WHERE c.codigo_proveedor IS NOT NULL
              AND c.codigo_proveedor <> ''
              AND COALESCE(c.proveedor_id, 0) > 0
              AND COALESCE(c.product_id, 0) > 0
        ");

        $wpdb->query("
            UPDATE {$prefix}codigos c
            INNER JOIN {$prefix}producto_base pb
                ON pb.woocommerce_product_id = c.product_id
               AND pb.woocommerce_variation_id = 0
            SET c.product_base_id = pb.id
            WHERE c.product_base_id IS NULL
              AND COALESCE(c.product_id, 0) > 0
        ");

        $wpdb->query("
            UPDATE {$prefix}codigos c
            INNER JOIN {$prefix}producto_proveedor pp
                ON pp.proveedor_id = c.proveedor_id
               AND pp.codigo_proveedor = c.codigo_proveedor
            SET c.supplier_product_id = pp.id
            WHERE c.supplier_product_id IS NULL
        ");

        $wpdb->query("
            UPDATE {$prefix}supplier_product_links spl
            LEFT JOIN {$prefix}codigos c
                ON c.proveedor_id = spl.supplier_id
               AND c.codigo_proveedor = spl.supplier_code
            SET
                spl.producto_proveedor_id = c.supplier_product_id,
                spl.product_base_id = c.product_base_id
            WHERE spl.producto_proveedor_id IS NULL OR spl.product_base_id IS NULL
        ");

        $wpdb->query("
            INSERT IGNORE INTO {$prefix}lotes (
                producto_proveedor_id,
                product_id,
                lote_codigo,
                fecha_recepcion,
                cantidad_inicial,
                cantidad_disponible,
                costo_total,
                costo_unitario,
                estado,
                documento_tipo,
                documento_id,
                documento_item_id,
                origen_datos,
                notas
            )
            SELECT
                c.supplier_product_id,
                fi.product_id,
                CONCAT('FAC-', fi.factura_id, '-', fi.id),
                COALESCE(f.fecha_emision, CURRENT_DATE),
                fi.cantidad,
                fi.cantidad,
                fi.monto_total,
                CASE WHEN fi.cantidad > 0 THEN fi.monto_total / fi.cantidad ELSE NULL END,
                'cerrado',
                'factura',
                fi.factura_id,
                fi.id,
                'factura_items',
                'Backfill inicial desde factura_items'
            FROM {$prefix}factura_items fi
            INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
            INNER JOIN {$prefix}codigos c ON c.id = fi.codigo_id
            WHERE c.supplier_product_id IS NOT NULL
              AND fi.cantidad > 0
        ");
    }

    /**
     * Fase 15 - Presentaciones, ciclo de vida de códigos y salud del catálogo.
     *
     * Amplía tablas existentes de forma compatible y agrega las estructuras
     * necesarias para EAN internos con alias y detección idempotente de brechas.
     */
    private static function create_phase15_catalog_health($prefix, $charset_collate) {
        global $wpdb;

        $envases = "{$prefix}envases";
        self::add_column_if_missing($envases, 'producto_proveedor_id', "producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL");
        self::add_column_if_missing($envases, 'proveedor_id', "proveedor_id BIGINT UNSIGNED DEFAULT NULL");
        self::add_column_if_missing($envases, 'codigo_proveedor', "codigo_proveedor VARCHAR(100) DEFAULT NULL");
        self::add_column_if_missing($envases, 'tipo_envase', "tipo_envase VARCHAR(30) NOT NULL DEFAULT 'envase'");
        self::add_column_if_missing($envases, 'es_vendible', "es_vendible TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($envases, 'lleva_stock_propio', "lleva_stock_propio TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($envases, 'permite_apertura', "permite_apertura TINYINT(1) NOT NULL DEFAULT 1");
        self::add_column_if_missing($envases, 'origen_datos', "origen_datos VARCHAR(50) NOT NULL DEFAULT 'manual'");
        self::add_column_if_missing($envases, 'requires_human_review', "requires_human_review TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($envases, 'review_status', "review_status VARCHAR(20) NOT NULL DEFAULT 'aprobado'");
        self::add_index_if_missing($envases, 'idx_presentacion_proveedor', "KEY idx_presentacion_proveedor (proveedor_id, codigo_proveedor)");
        self::add_index_if_missing($envases, 'idx_presentacion_review', "KEY idx_presentacion_review (requires_human_review, review_status)");

        $codigos = "{$prefix}codigo_barra";
        self::add_column_if_missing($codigos, 'estado', "estado VARCHAR(20) NOT NULL DEFAULT 'verificado'");
        self::add_column_if_missing($codigos, 'motivo_estado', "motivo_estado VARCHAR(255) DEFAULT NULL");
        self::add_column_if_missing($codigos, 'estado_por', "estado_por BIGINT UNSIGNED DEFAULT NULL");
        self::add_column_if_missing($codigos, 'estado_at', "estado_at DATETIME DEFAULT NULL");
        self::add_column_if_missing($codigos, 'origen_datos', "origen_datos VARCHAR(50) NOT NULL DEFAULT 'legacy'");
        self::add_column_if_missing($codigos, 'requires_human_review', "requires_human_review TINYINT(1) NOT NULL DEFAULT 0");
        self::add_index_if_missing($codigos, 'idx_codigo_estado', "KEY idx_codigo_estado (estado, activo)");

        $sql = "CREATE TABLE {$prefix}ean_aliases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            alias_tipo CHAR(1) NOT NULL DEFAULT '1',
            alias_codigo CHAR(5) DEFAULT NULL,
            payload CHAR(6) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_alias_producto (producto_base_id),
            UNIQUE KEY ux_alias_payload (payload),
            KEY idx_alias_activo (activo)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}data_gaps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            regla VARCHAR(100) NOT NULL,
            entidad_tipo VARCHAR(50) NOT NULL,
            entidad_id BIGINT UNSIGNED NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            severidad VARCHAR(20) NOT NULL DEFAULT 'media',
            estado VARCHAR(20) NOT NULL DEFAULT 'abierto',
            detalle_json LONGTEXT DEFAULT NULL,
            origen VARCHAR(50) NOT NULL DEFAULT 'scanner',
            detectado_at DATETIME NOT NULL,
            visto_ultima_vez_at DATETIME NOT NULL,
            resuelto_at DATETIME DEFAULT NULL,
            ignorado_hasta DATETIME DEFAULT NULL,
            tarea_id BIGINT UNSIGNED DEFAULT NULL,
            scan_token CHAR(36) DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_gap_fingerprint (regla, entidad_tipo, entidad_id, fingerprint),
            KEY idx_gap_estado_severidad (estado, severidad),
            KEY idx_gap_regla (regla),
            KEY idx_gap_tarea (tarea_id),
            KEY idx_gap_scan_token (scan_token)
        ) $charset_collate;";
        dbDelta($sql);
        self::add_column_if_missing("{$prefix}data_gaps", 'scan_token', 'scan_token CHAR(36) DEFAULT NULL');
        self::add_index_if_missing("{$prefix}data_gaps", 'idx_gap_scan_token', 'KEY idx_gap_scan_token (scan_token)');
    }

    /**
     * Agrega una columna a una tabla solo si no existe (idempotente).
     */
    private static function add_column_if_missing($table, $column, $definition) {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        ));

        if ((int) $exists === 0) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }

    /**
     * Elimina un índice si existe (idempotente).
     */
    private static function drop_index_if_exists($table, $index) {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $index
        ));

        if ((int) $exists > 0) {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private static function add_index_if_missing($table, $index, $definition) {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $index
        ));

        if ((int) $exists === 0) {
            $wpdb->query("ALTER TABLE `{$table}` ADD {$definition}");
        }
    }

    private static function table_exists($table) {
        global $wpdb;
        $like = $wpdb->esc_like($table);
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
    }

    /**
     * Fase 2 - Gobernanza transversal: created_by_system / requires_human_review
     * / review_status en tablas de dominio y operativas, y actor_type en auditoría.
     */
    private static function create_phase2_governance($prefix) {
        $governance_tables = [
            "{$prefix}producto_base",
            "{$prefix}producto_proveedor",
            "{$prefix}equivalence_groups",
            "{$prefix}equivalence_members",
            "{$prefix}barcodes",
        ];

        foreach ($governance_tables as $table) {
            self::add_column_if_missing($table, 'created_by_system', "created_by_system TINYINT(1) NOT NULL DEFAULT 0");
            self::add_column_if_missing($table, 'requires_human_review', "requires_human_review TINYINT(1) NOT NULL DEFAULT 0");
            self::add_column_if_missing($table, 'review_status', "review_status VARCHAR(20) NOT NULL DEFAULT 'aprobado'");
            self::add_index_if_missing($table, 'idx_requires_review', "KEY idx_requires_review (requires_human_review)");
        }

        // Tareas: marcar las generadas automáticamente.
        self::add_column_if_missing("{$prefix}tareas", 'created_by_system', "created_by_system TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing("{$prefix}tareas", 'requires_human_review', "requires_human_review TINYINT(1) NOT NULL DEFAULT 0");

        // Auditoría: tipo de actor (human/computer/migration/import/api).
        self::add_column_if_missing("{$prefix}audit_log", 'actor_type', "actor_type VARCHAR(20) NOT NULL DEFAULT 'human'");

        // Inventario abierto (embolsado): stock suelto del producto_base.
        self::add_column_if_missing("{$prefix}producto_base", 'stock_abierto', "stock_abierto DECIMAL(12,4) NOT NULL DEFAULT 0");
    }

    /**
     * Fase 3 - Matching progresivo: estado y scoring en producto_proveedor.
     */
    private static function create_phase2_matching($prefix) {
        $table = "{$prefix}producto_proveedor";
        self::add_column_if_missing($table, 'match_estado', "match_estado VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED'");
        self::add_column_if_missing($table, 'match_score', "match_score INT DEFAULT NULL");
        self::add_column_if_missing($table, 'match_origen', "match_origen VARCHAR(20) DEFAULT NULL");
        self::add_column_if_missing($table, 'matched_at', "matched_at DATETIME DEFAULT NULL");
        self::add_index_if_missing($table, 'idx_match_estado', "KEY idx_match_estado (match_estado)");
    }

    /**
     * Fase 8 - Ciclo de vida, gates de revisión y match online para publicación.
     */
    private static function create_phase8_publication($prefix) {
        $table = "{$prefix}producto_base";

        // Permite multiples productos base aun no vinculados a WooCommerce.
        // El indice ux_wc_ref sigue protegiendo vinculos reales duplicados.
        global $wpdb;
        $wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN woocommerce_product_id BIGINT UNSIGNED DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN woocommerce_variation_id BIGINT UNSIGNED DEFAULT NULL");
        $wpdb->query("UPDATE `{$table}` SET woocommerce_product_id = NULL, woocommerce_variation_id = NULL WHERE woocommerce_product_id = 0 AND woocommerce_variation_id = 0");

        self::add_column_if_missing($table, 'deleted_at', "deleted_at DATETIME DEFAULT NULL");
        self::add_column_if_missing($table, 'archived_at', "archived_at DATETIME DEFAULT NULL");
        self::add_column_if_missing($table, 'human_product_review', "human_product_review VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($table, 'human_price_review', "human_price_review VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($table, 'human_category_review', "human_category_review VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($table, 'human_attribute_review', "human_attribute_review VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($table, 'publication_stage', "publication_stage VARCHAR(40) NOT NULL DEFAULT 'computer_created'");
        self::add_column_if_missing($table, 'match_estado_online', "match_estado_online VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED'");
        self::add_column_if_missing($table, 'match_score_online', "match_score_online INT DEFAULT NULL");
        self::add_column_if_missing($table, 'match_origen_online', "match_origen_online VARCHAR(20) DEFAULT NULL");
        self::add_column_if_missing($table, 'matched_online_at', "matched_online_at DATETIME DEFAULT NULL");
        self::add_column_if_missing($table, 'woocommerce_candidate_id', "woocommerce_candidate_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
        self::add_column_if_missing($table, 'origen_datos', "origen_datos VARCHAR(64) DEFAULT NULL COMMENT 'Fuente: manual, xml, woo, tienda_local_legacy'");

        self::add_index_if_missing($table, 'idx_deleted_at', "KEY idx_deleted_at (deleted_at)");
        self::add_index_if_missing($table, 'idx_archived_at', "KEY idx_archived_at (archived_at)");
        self::add_index_if_missing($table, 'idx_publication_stage', "KEY idx_publication_stage (publication_stage)");
        self::add_index_if_missing($table, 'idx_match_estado_online', "KEY idx_match_estado_online (match_estado_online)");
    }

    /**
     * Fase 9 - Ingreso XML: envío vs producto, costos landed y lotes.
     */
    private static function create_phase9_invoice_intake($prefix) {
        self::add_column_if_missing("{$prefix}facturas", 'documento_subtipo', "documento_subtipo VARCHAR(20) NOT NULL DEFAULT 'productos'");
        self::add_column_if_missing("{$prefix}facturas", 'factura_productos_id', "factura_productos_id BIGINT UNSIGNED DEFAULT NULL");
        self::add_column_if_missing("{$prefix}facturas", 'costo_envio_total', "costo_envio_total DECIMAL(12,2) NOT NULL DEFAULT 0");
        self::add_column_if_missing("{$prefix}facturas", 'envio_prorrateado', "envio_prorrateado TINYINT(1) NOT NULL DEFAULT 0");
        self::add_index_if_missing("{$prefix}facturas", 'idx_factura_productos_id', "KEY idx_factura_productos_id (factura_productos_id)");
        self::add_index_if_missing("{$prefix}facturas", 'idx_documento_subtipo', "KEY idx_documento_subtipo (documento_subtipo)");

        self::add_column_if_missing("{$prefix}factura_items", 'item_tipo', "item_tipo VARCHAR(20) NOT NULL DEFAULT 'producto'");
        self::add_column_if_missing("{$prefix}factura_items", 'codigo_tipo', "codigo_tipo VARCHAR(20) DEFAULT NULL");
        self::add_column_if_missing("{$prefix}factura_items", 'sku_local', "sku_local VARCHAR(100) DEFAULT NULL");
        self::add_column_if_missing("{$prefix}factura_items", 'costo_envio_prorrateado', "costo_envio_prorrateado DECIMAL(12,4) NOT NULL DEFAULT 0");
        self::add_column_if_missing("{$prefix}factura_items", 'costo_landed_unitario', "costo_landed_unitario DECIMAL(12,4) DEFAULT NULL");
        self::add_index_if_missing("{$prefix}factura_items", 'idx_item_tipo', "KEY idx_item_tipo (item_tipo)");
        self::add_index_if_missing("{$prefix}factura_items", 'idx_sku_local', "KEY idx_sku_local (sku_local)");

        self::add_column_if_missing("{$prefix}lotes", 'costo_envio_unitario', "costo_envio_unitario DECIMAL(12,4) NOT NULL DEFAULT 0");

        self::add_column_if_missing("{$prefix}facturas", 'modo_ingreso', "modo_ingreso VARCHAR(20) NOT NULL DEFAULT 'recepcion'");
        self::add_index_if_missing("{$prefix}facturas", 'idx_modo_ingreso', "KEY idx_modo_ingreso (modo_ingreso)");
        self::add_index_if_missing("{$prefix}facturas", 'idx_proveedor', "KEY idx_proveedor (proveedor_id)");

        // Historial de costos: permitir registros pendientes de vinculación (sin product_id WC).
        $cost_table = "{$prefix}cost_history";
        global $wpdb;
        $wpdb->query("ALTER TABLE `{$cost_table}` MODIFY COLUMN product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL");
        self::add_column_if_missing($cost_table, 'descripcion_proveedor', "descripcion_proveedor VARCHAR(255) DEFAULT NULL");
        self::add_column_if_missing($cost_table, 'costo_producto_unitario', "costo_producto_unitario DECIMAL(12,4) DEFAULT NULL");
        self::add_column_if_missing($cost_table, 'costo_envio_prorrateado', "costo_envio_prorrateado DECIMAL(12,4) DEFAULT NULL");
        self::add_column_if_missing($cost_table, 'pendiente_vinculacion', "pendiente_vinculacion TINYINT(1) NOT NULL DEFAULT 0");
    }

    /**
     * Fase 11 - Un flete puede vincularse a varias facturas de productos (N:M).
     */
    private static function create_phase11_flete_vinculos($prefix) {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = "{$prefix}factura_flete_vinculos";

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            factura_envio_id BIGINT UNSIGNED NOT NULL,
            factura_productos_id BIGINT UNSIGNED NOT NULL,
            monto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_envio_productos (factura_envio_id, factura_productos_id),
            KEY idx_envio (factura_envio_id),
            KEY idx_productos (factura_productos_id)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migrar vínculos legacy (factura_productos_id en factura de flete).
        $wpdb->query(
            "INSERT IGNORE INTO {$table} (factura_envio_id, factura_productos_id, monto_asignado, created_at)
             SELECT f.id, f.factura_productos_id, COALESCE(f.monto_total, 0), f.created_at
             FROM {$prefix}facturas f
             WHERE f.documento_subtipo = 'envio'
               AND f.factura_productos_id IS NOT NULL
               AND f.factura_productos_id > 0"
        );
    }

    /**
     * Garantiza tabla N:M flete↔facturas (p. ej. si el deploy no bumpió versión).
     */
    public static function ensure_flete_vinculos_table() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $table = $prefix . 'factura_flete_vinculos';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            return;
        }
        self::create_phase11_flete_vinculos($prefix);
    }

    /**
     * Fase 10 - Reparar vínculos SKU online → local Mamut en facturas existentes.
     */
    private static function create_phase10_mamut_sku_repair() {
        $done_version = get_option('riverso_pos_mamut_sku_repair_version', '');
        if ($done_version === RIVERSO_POS_VERSION) {
            return;
        }

        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-intake-service.php';

        if (!function_exists('riverso_mamut_online_to_local_sku')) {
            return;
        }

        $intake = Riverso_Invoice_Intake_Service::get_instance();
        $intake->repair_mislinked_invoice_items(['folio' => '737966']);
        $intake->repair_mislinked_invoice_items();

        update_option('riverso_pos_mamut_sku_repair_version', RIVERSO_POS_VERSION);
    }

    /**
     * Asegura tabla de empleados vía módulo (dbDelta compatible).
     */
    private static function ensure_employees_table() {
        $paths = [
            RIVERSO_POS_PLUGIN_DIR . 'core/employees/class-employee-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'modules/employees/class-employee-module.php',
        ];
        foreach ($paths as $file) {
            if (file_exists($file)) {
                require_once $file;
                break;
            }
        }
        if (class_exists('Riverso_POS_Employee_Module') && method_exists('Riverso_POS_Employee_Module', 'create_table')) {
            Riverso_POS_Employee_Module::create_table();
        }
    }

    /**
     * Tablas ERP Fases 10–12 (core, códigos, inventario avanzado, OC, sync).
     */
    private static function create_erp_phase_tables($prefix, $charset_collate) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Audit: employee_id + module
        self::add_column_if_missing("{$prefix}audit_log", 'employee_id', 'employee_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing("{$prefix}audit_log", 'module', 'module VARCHAR(50) DEFAULT NULL');
        self::add_index_if_missing("{$prefix}audit_log", 'idx_audit_employee', 'KEY idx_audit_employee (employee_id)');
        self::add_index_if_missing("{$prefix}audit_log", 'idx_audit_module', 'KEY idx_audit_module (module)');

        // Tareas: employee_id
        self::add_column_if_missing("{$prefix}tareas", 'employee_id', 'employee_id BIGINT UNSIGNED DEFAULT NULL');

        $sql = "CREATE TABLE {$prefix}tarea_historial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tarea_id BIGINT UNSIGNED NOT NULL,
            estado_anterior VARCHAR(50) DEFAULT NULL,
            estado_nuevo VARCHAR(50) NOT NULL,
            cambio_por BIGINT UNSIGNED NOT NULL,
            cambio_en DATETIME DEFAULT CURRENT_TIMESTAMP,
            razon TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_tarea (tarea_id),
            KEY idx_cambio_en (cambio_en)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}codigo_barra (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'ean13',
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
            unidad_medida VARCHAR(20) NOT NULL DEFAULT 'unidad',
            envase_id BIGINT UNSIGNED DEFAULT NULL,
            factor_a_unidad_base DECIMAL(10,3) NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            estado VARCHAR(20) NOT NULL DEFAULT 'verificado',
            motivo_estado VARCHAR(255) DEFAULT NULL,
            estado_por BIGINT UNSIGNED DEFAULT NULL,
            estado_at DATETIME DEFAULT NULL,
            origen_datos VARCHAR(50) NOT NULL DEFAULT 'manual',
            requires_human_review TINYINT(1) NOT NULL DEFAULT 0,
            sku_local VARCHAR(100) DEFAULT NULL,
            pending_sku VARCHAR(100) DEFAULT NULL,
            legacy_ref LONGTEXT DEFAULT NULL,
            conflicto TINYINT(1) NOT NULL DEFAULT 0,
            migrado_de_tabla VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_codigo (codigo),
            KEY idx_producto (producto_base_id),
            KEY idx_proveedor (proveedor_id),
            KEY idx_activo (activo),
            KEY idx_codigo_estado (codigo, estado),
            KEY idx_sku_local (sku_local),
            KEY idx_pending_sku (pending_sku),
            KEY idx_conflicto (conflicto)
        ) $charset_collate;";
        dbDelta($sql);

        // Schema canónico alineado con Riverso_Reservation_Service.
        $sql = "CREATE TABLE {$prefix}reservas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad DECIMAL(12,4) NOT NULL DEFAULT 0,
            origen VARCHAR(30) NOT NULL DEFAULT 'pos',
            referencia_tipo VARCHAR(50) DEFAULT NULL,
            referencia_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activa',
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            released_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_producto_estado (producto_base_id, estado),
            KEY idx_ubicacion (ubicacion_id),
            KEY idx_referencia (referencia_tipo, referencia_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Migración incremental si la tabla ya existía con schema antiguo.
        self::add_column_if_missing("{$prefix}reservas", 'ubicacion_id', 'ubicacion_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing("{$prefix}reservas", 'expires_at', 'expires_at DATETIME DEFAULT NULL');
        self::add_column_if_missing("{$prefix}reservas", 'origen', "origen VARCHAR(30) NOT NULL DEFAULT 'pos'");
        self::add_index_if_missing("{$prefix}reservas", 'idx_ubicacion', 'KEY idx_ubicacion (ubicacion_id)');
        self::add_index_if_missing("{$prefix}reservas", 'idx_referencia', 'KEY idx_referencia (referencia_tipo, referencia_id)');

        $sql = "CREATE TABLE {$prefix}conteos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(20) NOT NULL DEFAULT 'parcial',
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'abierto',
            iniciado_por BIGINT UNSIGNED DEFAULT NULL,
            aprobado_por BIGINT UNSIGNED DEFAULT NULL,
            iniciado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
            cerrado_en DATETIME DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_estado (estado)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}conteo_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conteo_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            codigo VARCHAR(50) DEFAULT NULL,
            envase_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad_teorica DECIMAL(12,4) DEFAULT 0,
            cantidad_contada DECIMAL(12,4) DEFAULT 0,
            diferencia DECIMAL(12,4) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conteo (conteo_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}ordenes_compra (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero VARCHAR(50) DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'borrador',
            cotizacion_id BIGINT UNSIGNED DEFAULT NULL,
            total DECIMAL(12,2) DEFAULT 0,
            notas TEXT DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            enviado_en DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_proveedor (proveedor_id),
            KEY idx_estado (estado)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}orden_compra_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            codigo_proveedor VARCHAR(100) DEFAULT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            cantidad DECIMAL(12,4) NOT NULL DEFAULT 0,
            cantidad_recibida DECIMAL(12,4) NOT NULL DEFAULT 0,
            precio_unitario DECIMAL(12,4) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_orden (orden_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Alias de nombre usado por Purchase_Order_Module
        $sql = "CREATE TABLE {$prefix}ordenes_compra_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
            cantidad_recibida DECIMAL(12,3) NOT NULL DEFAULT 0,
            unidad VARCHAR(30) DEFAULT 'unidad',
            precio_unitario DECIMAL(12,4) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY orden_id (orden_id),
            KEY producto_base_id (producto_base_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}woo_sync_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            woo_product_id BIGINT UNSIGNED DEFAULT NULL,
            woo_variation_id BIGINT UNSIGNED DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            attempts INT NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}precio_historial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            canal VARCHAR(10) NOT NULL DEFAULT 'local',
            precio_sugerido DECIMAL(12,4) DEFAULT NULL,
            precio_aprobado DECIMAL(12,4) DEFAULT NULL,
            precio_online DECIMAL(12,4) DEFAULT NULL,
            precio_local DECIMAL(12,4) DEFAULT NULL,
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_producto_canal (producto_base_id, canal)
        ) $charset_collate;";
        dbDelta($sql);

        // Columnas útiles en movimientos para kardex ERP
        self::add_column_if_missing("{$prefix}movimientos", 'producto_base_id', 'producto_base_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing("{$prefix}movimientos", 'cantidad_neta', 'cantidad_neta DECIMAL(12,4) DEFAULT NULL');
        self::add_index_if_missing("{$prefix}movimientos", 'idx_producto_base', 'KEY idx_producto_base (producto_base_id)');

        // Migrar códigos legacy → codigo_barra (idempotente)
        self::migrate_legacy_barcodes($prefix);

        // Consolidar esquema de familia (phase12)
        self::consolidate_family_schema($prefix);

        // Normalizar tipo_sustitucion a exacta|preferida|complementaria
        self::normalize_family_tipo_sustitucion($prefix);

        // Agregar soporte para asignación proveedor → familia (phase13)
        self::add_family_assignment_support($prefix);

        // Integrar tienda_local_productos al dominio producto_base (phase14)
        self::integrate_local_store_products($prefix);
    }

    /**
     * Migra valores legacy de tipo_sustitucion al whitelist canónico.
     * compatible → complementaria; sustituto|preferido → preferida.
     */
    private static function normalize_family_tipo_sustitucion($prefix) {
        global $wpdb;
        $table = "{$prefix}equivalence_groups";
        if (!self::table_exists($table)) {
            return;
        }

        $wpdb->query(
            "UPDATE {$table} SET tipo_sustitucion = 'complementaria'
             WHERE tipo_sustitucion IN ('compatible', 'COMPATIBLE')"
        );
        $wpdb->query(
            "UPDATE {$table} SET tipo_sustitucion = 'preferida'
             WHERE tipo_sustitucion IN ('sustituto', 'preferido', 'SUSTITUTO', 'PREFERIDO')"
        );
        $wpdb->query(
            "UPDATE {$table} SET tipo_sustitucion = 'exacta'
             WHERE tipo_sustitucion IS NULL OR tipo_sustitucion = ''
                OR tipo_sustitucion NOT IN ('exacta', 'preferida', 'complementaria')"
        );

        $wpdb->query(
            "ALTER TABLE {$table}
             MODIFY COLUMN tipo_sustitucion VARCHAR(20) DEFAULT 'exacta'"
        );
    }

    /**
     * Consolida el esquema de familia: equivalencia_grupo → equivalence_groups (fase 12).
     * Mantiene equivalence_groups/members como canónico; depreca las tablas de phase11.
     */
    private static function consolidate_family_schema($prefix) {
        global $wpdb;
        $legacy_groups = "{$prefix}equivalencia_grupo";
        $legacy_members = "{$prefix}equivalencia_miembro";
        if (!self::table_exists($legacy_groups) || !self::table_exists($legacy_members)) {
            return;
        }

        // 1. Migrar datos de equivalencia_grupo → equivalence_groups
        $wpdb->query(
            "INSERT IGNORE INTO {$prefix}equivalence_groups 
                (codigo_grupo, nombre, tipo_sustitucion, activo, notas, created_at, updated_at)
             SELECT 
                CONCAT('LEGACY_', eg.id),
                eg.nombre,
                'compatible',
                eg.activo,
                CONCAT('Migrado de phase11 equivalencia_grupo id=', eg.id),
                eg.created_at,
                eg.updated_at
             FROM {$prefix}equivalencia_grupo eg
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$prefix}equivalence_groups eg2 
                 WHERE eg2.codigo_grupo = CONCAT('LEGACY_', eg.id)
             )"
        );

        // 2. Migrar datos de equivalencia_miembro → equivalence_members
        $wpdb->query(
            "INSERT IGNORE INTO {$prefix}equivalence_members 
                (grupo_id, producto_base_id, prioridad, es_reemplazo_preferido, activo, created_at, updated_at)
             SELECT 
                eg_canon.id,
                em.producto_base_id,
                em.prioridad,
                0,
                1,
                em.created_at,
                NOW()
             FROM {$prefix}equivalencia_miembro em
             INNER JOIN {$prefix}equivalencia_grupo eg ON eg.id = em.grupo_id
             LEFT JOIN {$prefix}equivalence_groups eg_canon ON eg_canon.codigo_grupo = CONCAT('LEGACY_', eg.id)
             WHERE eg_canon.id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}equivalence_members em2
                    WHERE em2.grupo_id = eg_canon.id 
                        AND em2.producto_base_id = em.producto_base_id
                )"
        );

        // 3. Marcar las tablas phase11 como deprecadas (si no existe la columna)
        $wpdb->query(
            "ALTER TABLE {$prefix}equivalencia_grupo 
             ADD COLUMN IF NOT EXISTS deprecated_at DATETIME DEFAULT NULL COMMENT 'Marca de deprecación: phase12+ usa equivalence_groups'"
        );

        $wpdb->query(
            "ALTER TABLE {$prefix}equivalencia_miembro 
             ADD COLUMN IF NOT EXISTS deprecated_at DATETIME DEFAULT NULL COMMENT 'Marca de deprecación: phase12+ usa equivalence_members'"
        );

        // 4. Marcar filas como deprecadas
        $wpdb->query(
            "UPDATE {$prefix}equivalencia_grupo 
             SET deprecated_at = NOW() 
             WHERE deprecated_at IS NULL 
                AND EXISTS (
                    SELECT 1 FROM {$prefix}equivalence_groups eg2 
                    WHERE eg2.codigo_grupo = CONCAT('LEGACY_', {$prefix}equivalencia_grupo.id)
                )"
        );
    }

    /**
     * Copia barcodes/códigos legacy a riverso_codigo_barra sin duplicar.
     */
    private static function migrate_legacy_barcodes($prefix) {
        global $wpdb;

        $wpdb->query(
            "INSERT IGNORE INTO {$prefix}codigo_barra
                (codigo, tipo, producto_base_id, cantidad, unidad_medida, factor_a_unidad_base, activo, migrado_de_tabla)
             SELECT b.barcode, 'ean13', pb.id, 1, COALESCE(pb.unidad_base, 'unidad'), 1, 1, 'riverso_barcodes'
             FROM {$prefix}barcodes b
             INNER JOIN {$prefix}producto_base pb
               ON pb.woocommerce_product_id = b.product_id
              AND (pb.woocommerce_variation_id = COALESCE(b.variation_id, 0)
                   OR COALESCE(b.variation_id, 0) = 0)
             WHERE b.barcode IS NOT NULL AND b.barcode <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}codigo_barra cb WHERE cb.codigo = b.barcode
               )"
        );

        $wpdb->query(
            "INSERT IGNORE INTO {$prefix}codigo_barra
                (codigo, tipo, producto_base_id, proveedor_id, cantidad, unidad_medida, factor_a_unidad_base, activo, migrado_de_tabla)
             SELECT c.codigo_proveedor, 'supplier', c.product_base_id, c.proveedor_id,
                    COALESCE(c.factor_conversion, 1), COALESCE(c.unidad_medida, 'unidad'),
                    COALESCE(c.factor_conversion, 1), COALESCE(c.activo, 1), 'riverso_codigos'
             FROM {$prefix}codigos c
             WHERE c.codigo_proveedor IS NOT NULL AND c.codigo_proveedor <> ''
               AND c.product_base_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}codigo_barra cb WHERE cb.codigo = c.codigo_proveedor
               )"
        );
    }

    /**
     * Agrega soporte para asignación de proveedor → familia (phase 13).
     * Permite que producto_proveedor se asigne a un grupo_id (familia) en lugar de producto_base_id.
     */
    private static function add_family_assignment_support($prefix) {
        global $wpdb;

        // 0. producto_base_id debe aceptar NULL para destino familia
        $wpdb->query(
            "ALTER TABLE {$prefix}producto_proveedor 
             MODIFY COLUMN producto_base_id BIGINT UNSIGNED DEFAULT NULL 
             COMMENT 'FK producto_base; NULL si asignado a familia (grupo_id)'"
        );

        // 1. Agregar columna grupo_id a producto_proveedor si no existe
        $wpdb->query(
            "ALTER TABLE {$prefix}producto_proveedor 
             ADD COLUMN IF NOT EXISTS grupo_id BIGINT UNSIGNED DEFAULT NULL 
             COMMENT 'FK a equivalence_groups: si es familia, producto_base_id debe ser NULL' AFTER producto_base_id"
        );

        // 2. Crear índice en grupo_id
        $wpdb->query(
            "ALTER TABLE {$prefix}producto_proveedor 
             ADD KEY IF NOT EXISTS idx_grupo_id (grupo_id)"
        );

        // 3. Agregar FK solo cuando el servidor la soporta y aún no existe.
        $fk_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_pp_grupo_id'",
            DB_NAME,
            "{$prefix}producto_proveedor"
        ));
        if (!(int) $fk_exists) {
            $wpdb->query(
                "ALTER TABLE {$prefix}producto_proveedor
                 ADD CONSTRAINT fk_pp_grupo_id
                 FOREIGN KEY (grupo_id) REFERENCES {$prefix}equivalence_groups(id) ON DELETE SET NULL"
            );
        }

        // 4. Agregar columnas de auditoría
        $wpdb->query(
            "ALTER TABLE {$prefix}producto_proveedor 
             ADD COLUMN IF NOT EXISTS assigned_to_family_at DATETIME DEFAULT NULL 
             COMMENT 'Timestamp de asignación a familia' AFTER grupo_id"
        );

        $wpdb->query(
            "ALTER TABLE {$prefix}producto_proveedor 
             ADD COLUMN IF NOT EXISTS assigned_to_family_by BIGINT UNSIGNED DEFAULT NULL 
             COMMENT 'user_id que asignó a familia' AFTER assigned_to_family_at"
        );

        // La exclusividad producto/familia se valida en la capa de dominio.
        // MariaDB rechaza este CHECK cuando grupo_id participa en una FK ON DELETE.
    }

    /**
     * Integra productos de tienda local legacy (CSV importado) al dominio producto_base.
     * Vincula tienda_local_productos con producto_base por SKU o código de barra.
     *
     * @param string $prefix
     */
    private static function integrate_local_store_products($prefix) {
        global $wpdb;

        // Asegurar columnas requeridas (prod puede no tenerlas aún)
        self::add_column_if_missing(
            "{$prefix}producto_base",
            'origen_datos',
            "origen_datos VARCHAR(64) DEFAULT NULL COMMENT 'Fuente: manual, xml, woo, tienda_local_legacy'"
        );
        self::add_column_if_missing(
            "{$prefix}tienda_local_productos",
            'integrated_at',
            "integrated_at DATETIME DEFAULT NULL COMMENT 'Migración a producto_base'"
        );

        // Migración: para cada producto en tienda_local_productos sin vinculación
        // Intentar matchear con producto_base por SKU exacto o código de barra

        $local_products = $wpdb->get_results(
            "SELECT tlp.sku, tlp.nombre, tlp.precio, tlp.stock, tlb.barcode 
             FROM {$prefix}tienda_local_productos tlp
             LEFT JOIN {$prefix}tienda_local_barcodes tlb ON tlb.sku = tlp.sku
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$prefix}producto_base pb 
                 WHERE pb.canonical_sku = tlp.sku OR pb.canonical_sku LIKE CONCAT('%', tlp.sku, '%')
             )
             LIMIT 100"
        );

        foreach ($local_products as $local_prod) {
            // Intentar matchear por barcode si existe
            if ($local_prod->barcode) {
                $barcode_match = $wpdb->get_row($wpdb->prepare(
                    "SELECT pb.id FROM {$prefix}producto_base pb
                     WHERE pb.id IN (
                         SELECT DISTINCT producto_base_id FROM {$prefix}codigo_barra
                         WHERE codigo = %s
                     )
                     LIMIT 1",
                    $local_prod->barcode
                ), ARRAY_A);

                if ($barcode_match) {
                    // Si matcheó, crear anotación para auditoría
                    continue; // Ya vinculado
                }
            }

            // Si no hay match exacto, crear nuevo producto_base con origen "tienda_local_legacy"
            $wpdb->insert(
                "{$prefix}producto_base",
                [
                    'canonical_sku' => $local_prod->sku,
                    'nombre_canonico' => $local_prod->nombre,
                    'unidad_base' => 'unidad',
                    'estado' => 'activo',
                    'created_by_system' => 1,
                    'requires_human_review' => 1,
                    'origen_datos' => 'tienda_local_legacy',
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s']
            );

            if ($wpdb->insert_id) {
                $pb_id = $wpdb->insert_id;

                // Crear entrada en codigo_barra si existe el barcode local
                if ($local_prod->barcode) {
                    $wpdb->insert(
                        "{$prefix}codigo_barra",
                        [
                            'codigo' => $local_prod->barcode,
                            'tipo' => 'internal',
                            'producto_base_id' => $pb_id,
                            'cantidad' => 1,
                            'unidad_medida' => 'unidad',
                            'factor_a_unidad_base' => 1,
                            'activo' => 1,
                            'migrado_de_tabla' => 'tienda_local_barcodes',
                        ]
                    );
                }

                // Crear precio local si tienda local tenía precio
                if ($local_prod->precio) {
                    $wpdb->insert(
                        "{$prefix}precios",
                        [
                            'producto_base_id' => $pb_id,
                            'canal' => 'local',
                            'woocommerce_variation_id' => 0,
                            'p_asignado' => floatval($local_prod->precio),
                            'estado_aprobacion' => 'aprobado',
                            'created_by_system' => 1,
                        ],
                        ['%d', '%s', '%d', '%f', '%s', '%d']
                    );
                }
            }
        }

        // Marcar columnas de auditoría en tienda_local_productos
        $wpdb->query(
            "ALTER TABLE {$prefix}tienda_local_productos 
             ADD COLUMN IF NOT EXISTS integrated_at DATETIME DEFAULT NULL COMMENT 'Migración a producto_base'"
        );

        // Marcar productos integrados
        $wpdb->query(
            "UPDATE {$prefix}tienda_local_productos tlp
             SET integrated_at = NOW()
             WHERE integrated_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM {$prefix}producto_base pb 
                   WHERE pb.canonical_sku = tlp.sku
                       OR pb.canonical_sku LIKE CONCAT('%', tlp.sku, '%')
               )"
        );
    }

    /**
     * Inicializa servicios core (eventos + autenticación auditada).
     */
    private static function init_core_services() {
        if (file_exists(RIVERSO_POS_PLUGIN_DIR . 'core/events/class-event-bus.php')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/events/class-event-bus.php';
        }
        if (file_exists(RIVERSO_POS_PLUGIN_DIR . 'core/auth/class-auth-service.php')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/auth/class-auth-service.php';
            if (class_exists('Riverso_Auth_Service')) {
                Riverso_Auth_Service::get_instance()->init();
            }
        }
    }

    /**
     * Fase 16: Notas de crédito, referencias DTE y pagos agrupados
     * Crea tablas para:
     *   - factura_referencias: Registra nodos <Referencia> XML
     *   - factura_pagos: Cabecera de tickets de pago
     *   - factura_pago_documentos: Relación N:M facturas ↔ pagos
     *   - factura_reversa_inventario: Auditoría de reversas de NC
     *
     * @param string $prefix Prefijo de tablas
     * @param string $charset_collate Charset/collation de BD
     */
    private static function create_phase16_invoice_credit_notes($prefix, $charset_collate) {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Agregar columna estado_pago a riverso_facturas
        self::add_column_if_missing(
            "{$prefix}facturas",
            'estado_pago',
            "estado_pago VARCHAR(50) DEFAULT 'no_pagada' COMMENT 'Estado de pago (no_pagada, parcialmente_pagada, pagada, cancelada)'"
        );
        self::add_index_if_missing(
            "{$prefix}facturas",
            'idx_estado_pago',
            "KEY idx_estado_pago (estado_pago)"
        );

        // Agregar columnas de recepción faltantes
        self::add_column_if_missing(
            "{$prefix}facturas",
            'reception_started_at',
            "reception_started_at DATETIME DEFAULT NULL COMMENT 'Timestamp de inicio de recepción'"
        );
        self::add_column_if_missing(
            "{$prefix}facturas",
            'reception_completed_at',
            "reception_completed_at DATETIME DEFAULT NULL COMMENT 'Timestamp de fin de recepción'"
        );
        self::add_column_if_missing(
            "{$prefix}facturas",
            'approved_at',
            "approved_at DATETIME DEFAULT NULL COMMENT 'Timestamp de aprobación'"
        );

        // Tabla: factura_referencias
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}factura_referencias (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a riverso_facturas (la NC)',
            tipo_doc_ref INT NOT NULL COMMENT 'TpoDocRef: 33=Factura, 34=Boleta, 52=Guía, etc.',
            folio_ref VARCHAR(50) NOT NULL COMMENT 'FolioRef: puede ser 0 si global',
            ind_global TINYINT(1) DEFAULT 0 COMMENT 'IndGlobal: 1=afecta totalmente, otros=parcial',
            cod_ref INT DEFAULT NULL COMMENT 'CodRef: código de referencia del SII',
            razon_ref VARCHAR(255) DEFAULT NULL COMMENT 'RazonRef: motivo de la referencia',
            fecha_ref DATE DEFAULT NULL COMMENT 'FchRef: fecha de referencia',
            factura_origen_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK resuelto: factura que se descuenta',
            estado_resolucion VARCHAR(50) DEFAULT 'pendiente' COMMENT 'pendiente, resuelta_automatica, resuelta_manual, ambigua',
            estado_reversa_inventario VARCHAR(50) DEFAULT 'sin_reversa' COMMENT 'sin_reversa, pendiente_aplicar, aplicada, cancelada',
            reversa_aplicada_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id que ejecutó reversa',
            reversa_aplicada_at DATETIME DEFAULT NULL COMMENT 'Timestamp ejecución reversa',
            motivo_reversa TEXT DEFAULT NULL COMMENT 'Descripción si hay reversa',
            monto_descuento DECIMAL(12,2) DEFAULT 0 COMMENT 'abs(monto_total NC) para auditoría',
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_factura_ref (factura_id, tipo_doc_ref, folio_ref, cod_ref),
            KEY idx_factura_id (factura_id),
            KEY idx_factura_origen (factura_origen_id),
            KEY idx_estado_resolucion (estado_resolucion),
            KEY idx_estado_reversa (estado_reversa_inventario)
        ) $charset_collate;";
        dbDelta($sql);

        // Tabla: factura_pagos
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}factura_pagos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero_ticket VARCHAR(50) NOT NULL COMMENT 'Identificador único del ticket (ej: PAG-2026-0001)',
            estado VARCHAR(50) DEFAULT 'activo' COMMENT 'activo, cancelado, reversado',
            monto_total DECIMAL(12,2) DEFAULT 0 COMMENT 'Suma de saldos efectivos (lectura única)',
            moneda VARCHAR(10) DEFAULT 'CLP',
            fecha_pago DATE DEFAULT NULL COMMENT 'Fecha registrada del pago',
            comprobante_nombre_original VARCHAR(255) DEFAULT NULL COMMENT 'Nombre archivo subido',
            comprobante_ruta_relativa VARCHAR(255) DEFAULT NULL COMMENT 'Ruta relativa almacenada (ej: /uploads/facturas/pago_xyz.jpg)',
            comprobante_mime_type VARCHAR(50) DEFAULT NULL COMMENT 'image/jpeg, image/png, image/webp, etc.',
            comprobante_tamaño INT DEFAULT NULL COMMENT 'Tamaño en bytes',
            comprobante_hash VARCHAR(64) DEFAULT NULL COMMENT 'SHA256 del archivo',
            notas TEXT DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id WordPress',
            cancelado_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id si fue cancelado',
            cancelado_at DATETIME DEFAULT NULL,
            razon_cancelacion TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_numero_ticket (numero_ticket),
            KEY idx_estado (estado),
            KEY idx_fecha_pago (fecha_pago),
            KEY idx_creado_por (creado_por),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        // Tabla: factura_pago_documentos
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}factura_pago_documentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pago_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_pagos',
            factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a riverso_facturas',
            monto_pagado DECIMAL(12,2) NOT NULL COMMENT 'Monto de esta factura en el ticket (puede ser parcial)',
            tipo_aplicacion VARCHAR(50) DEFAULT 'saldo_efectivo' COMMENT 'saldo_efectivo, monto_total, otro',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_pago_factura (pago_id, factura_id),
            KEY idx_pago_id (pago_id),
            KEY idx_factura_id (factura_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Tabla: factura_reversa_inventario
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}factura_reversa_inventario (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referencia_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_referencias',
            factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_origen (redundante pero útil)',
            factura_item_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a factura_items (si es específico)',
            producto_id BIGINT UNSIGNED NOT NULL COMMENT 'producto_base en catálogo',
            movimiento_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a riverso_movimientos (si fue registrado)',
            cantidad_revertida DECIMAL(12,4) NOT NULL COMMENT 'Cantidad que se deshace',
            costo_unitario_original DECIMAL(12,4) DEFAULT NULL COMMENT 'Costo capturado en momento reversa',
            estado VARCHAR(50) DEFAULT 'aplicada' COMMENT 'aplicada, anulada',
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_referencia_id (referencia_id),
            KEY idx_factura_id (factura_id),
            KEY idx_movimiento_id (movimiento_id),
            KEY idx_estado (estado)
        ) $charset_collate;";
        dbDelta($sql);

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase16_credit_notes_payments',
                'riverso_facturas',
                0,
                'info',
                'Fase 16: Agregado soporte para notas de crédito, referencias DTE, pagos agrupados y reversas de inventario'
            );
        }
    }

    /**
     * Fase 17: Descuentos/recargos/impuestos específicos y costos neto/bruto base/final en ítems.
     */
    private static function create_phase17_invoice_item_costs($prefix) {
        global $wpdb;

        self::add_column_if_missing(
            "{$prefix}facturas",
            'tasa_iva',
            "tasa_iva DECIMAL(8,4) DEFAULT NULL COMMENT 'TasaIVA del DTE'"
        );
        self::add_column_if_missing(
            "{$prefix}facturas",
            'impuestos_adicionales',
            "impuestos_adicionales LONGTEXT DEFAULT NULL COMMENT 'JSON ImptoReten del DTE'"
        );

        $items = "{$prefix}factura_items";
        self::add_column_if_missing($items, 'descuento_porcentaje', "descuento_porcentaje DECIMAL(8,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'descuento_monto', "descuento_monto DECIMAL(12,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'recargo_porcentaje', "recargo_porcentaje DECIMAL(8,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'recargo_monto', "recargo_monto DECIMAL(12,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'cod_imp_adic', "cod_imp_adic VARCHAR(10) DEFAULT NULL COMMENT 'CodImpAdic SII'");
        self::add_column_if_missing($items, 'impuesto_especifico_tasa', "impuesto_especifico_tasa DECIMAL(8,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'impuesto_especifico_monto', "impuesto_especifico_monto DECIMAL(12,4) DEFAULT NULL");
        self::add_column_if_missing($items, 'costo_neto_base', "costo_neto_base DECIMAL(12,4) DEFAULT NULL COMMENT 'Neto antes de descuentos/recargos'");
        self::add_column_if_missing($items, 'costo_bruto_base', "costo_bruto_base DECIMAL(12,4) DEFAULT NULL COMMENT 'Bruto antes de descuentos/recargos'");
        self::add_column_if_missing($items, 'costo_neto_final', "costo_neto_final DECIMAL(12,4) DEFAULT NULL COMMENT 'Neto después de descuentos/recargos'");
        self::add_column_if_missing($items, 'costo_bruto_final', "costo_bruto_final DECIMAL(12,4) DEFAULT NULL COMMENT 'Bruto después de descuentos/recargos + imp. específico'");

        // Backfill documentos ya guardados (sin XML): base = qty*precio, final = monto_total
        $facturas = "{$prefix}facturas";
        $wpdb->query(
            "UPDATE {$items} fi
             LEFT JOIN {$facturas} f ON f.id = fi.factura_id
             SET
                fi.costo_neto_base = COALESCE(fi.costo_neto_base, ROUND(fi.cantidad * fi.precio_unitario, 4)),
                fi.costo_neto_final = COALESCE(fi.costo_neto_final, ROUND(fi.monto_total, 4)),
                fi.costo_bruto_base = COALESCE(
                    fi.costo_bruto_base,
                    ROUND(
                        (fi.cantidad * fi.precio_unitario) * (1 + COALESCE(f.tasa_iva, 19) / 100)
                        + COALESCE(fi.impuesto_especifico_monto, 0),
                        4
                    )
                ),
                fi.costo_bruto_final = COALESCE(
                    fi.costo_bruto_final,
                    ROUND(
                        fi.monto_total * (1 + COALESCE(f.tasa_iva, 19) / 100)
                        + COALESCE(fi.impuesto_especifico_monto, 0),
                        4
                    )
                )
             WHERE fi.costo_neto_final IS NULL
                OR fi.costo_neto_base IS NULL
                OR fi.costo_bruto_final IS NULL
                OR fi.costo_bruto_base IS NULL"
        );

        // Inferir descuento_monto residual si solo hay diferencia base vs monto y no hay recargo guardado
        $wpdb->query(
            "UPDATE {$items}
             SET descuento_monto = ROUND((cantidad * precio_unitario) - monto_total, 4)
             WHERE descuento_monto IS NULL
               AND (recargo_monto IS NULL OR recargo_monto = 0)
               AND monto_total IS NOT NULL
               AND (cantidad * precio_unitario) > monto_total + 0.009"
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase17_invoice_item_costs',
                'factura_items',
                0,
                'info',
                'Fase 17: Columnas de descuentos/recargos/impuestos específicos y costos neto/bruto base/final + backfill'
            );
        }
    }

    /**
     * Fase 18: Catálogos de proveedores como entidad de primer nivel
     */
    private static function create_phase18_catalogs($prefix, $charset_collate) {
        global $wpdb;

        $sql = "CREATE TABLE {$prefix}catalogos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            proveedor_id BIGINT UNSIGNED NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            alias VARCHAR(50) DEFAULT NULL,
            version VARCHAR(20) DEFAULT NULL,
            archivo_origen VARCHAR(255) DEFAULT NULL,
            total_productos INT DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            fecha_vigencia_desde DATE DEFAULT NULL,
            fecha_vigencia_hasta DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_alias_version (alias, version),
            KEY idx_proveedor (proveedor_id),
            KEY idx_activo (activo)
        ) $charset_collate;";
        dbDelta($sql);

        // Agregar columna a producto_proveedor si no existe
        self::add_column_if_missing(
            "{$prefix}producto_proveedor",
            'catalogo_id',
            "catalogo_id BIGINT UNSIGNED DEFAULT NULL"
        );
        self::add_index_if_missing(
            "{$prefix}producto_proveedor",
            'idx_catalogo',
            "KEY idx_catalogo (catalogo_id)"
        );

        // Backfill: catálogo MAMUT bajo Tecbolt (no crear proveedor MAMUT).
        $mamut_id = (int) $wpdb->get_var(
            "SELECT p.id FROM {$prefix}proveedores p
             LEFT JOIN {$prefix}proveedor_apodos a ON a.proveedor_id = p.id
             WHERE p.nombre LIKE '%Tecbolt%'
                OR a.apodo LIKE '%Mamut%'
                OR a.apodo = 'MAMUT'
                OR p.nombre = 'MAMUT'
                OR p.rut = 'MAMUT'
             ORDER BY
                CASE WHEN p.nombre LIKE '%Tecbolt%' THEN 0 ELSE 1 END,
                p.id ASC
             LIMIT 1"
        );

        if (!$mamut_id) {
            // Último recurso: crear Tecbolt SA (nunca MAMUT como nombre canónico).
            $wpdb->insert(
                "{$prefix}proveedores",
                [
                    'rut' => '76.000.000-0',
                    'nombre' => 'Tecbolt SA',
                    'activo' => 1,
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s']
            );
            $mamut_id = (int) $wpdb->insert_id;
            if ($mamut_id > 0) {
                $wpdb->insert(
                    "{$prefix}proveedor_apodos",
                    ['proveedor_id' => $mamut_id, 'apodo' => 'Mamut'],
                    ['%d', '%s']
                );
            }
        }

        // Crear catálogo MAMUT 2025 si no existe (también por alias, por si proveedor_id diverge)
        $existing_catalog = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, proveedor_id FROM {$prefix}catalogos
                 WHERE (proveedor_id = %d AND alias = %s) OR (alias = %s AND version = %s)
                 ORDER BY id ASC LIMIT 1",
                $mamut_id,
                'mamut',
                'mamut',
                '2025'
            )
        );

        if (!$existing_catalog) {
            $wpdb->insert(
                "{$prefix}catalogos",
                [
                    'proveedor_id' => $mamut_id,
                    'nombre' => 'Catálogo Mamut 2025',
                    'alias' => 'mamut',
                    'version' => '2025',
                    'total_productos' => 0,
                    'activo' => 1,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s']
            );
            $catalog_id = (int) $wpdb->insert_id;
        } else {
            $catalog_id = (int) $existing_catalog->id;
            // Alinear proveedor_id del catálogo con el proveedor MAMUT canónico
            if ((int) $existing_catalog->proveedor_id !== (int) $mamut_id) {
                $wpdb->update(
                    "{$prefix}catalogos",
                    ['proveedor_id' => $mamut_id],
                    ['id' => $catalog_id],
                    ['%d'],
                    ['%d']
                );
            }
        }

        // Siempre backfill: el filtro Hub/padre usa pp.catalogo_id; si el catálogo
        // ya existía en un deploy previo, el backfill anterior se saltaba y quedaba vacío.
        if ($catalog_id > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$prefix}producto_proveedor
                     SET catalogo_id = %d
                     WHERE catalogo_id IS NULL
                       AND proveedor_id = %d",
                    $catalog_id,
                    $mamut_id
                )
            );

            // Mamut histórico: muchos PP quedaron con activo=0 y el Hub los excluía.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$prefix}producto_proveedor
                     SET activo = 1
                     WHERE catalogo_id = %d AND activo = 0",
                    $catalog_id
                )
            );

            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}producto_proveedor WHERE catalogo_id = %d",
                    $catalog_id
                )
            );
            $wpdb->update(
                "{$prefix}catalogos",
                ['total_productos' => $total],
                ['id' => $catalog_id],
                ['%d'],
                ['%d']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase18_catalogs',
                'catalogos',
                $catalog_id ?: 0,
                'info',
                'Fase 18: catalogos + backfill idempotente catalogo_id MAMUT (total=' . ($total ?? 0) . ')'
            );
        }
    }

    /**
     * Fase 19: El código de catálogo/proveedor no es SKU Local.
     * Vacía canonical_sku cuando coincide con codigo_proveedor (import Mamut histórico)
     * y genera tareas crear_contraparte_local.
     */
    private static function create_phase19_clear_catalog_as_local_sku($prefix) {
        global $wpdb;

        $cleared = (int) $wpdb->query(
            "UPDATE {$prefix}producto_base pb
             INNER JOIN {$prefix}producto_proveedor pp
                ON pp.producto_base_id = pb.id
               AND pp.catalogo_id IS NOT NULL
               AND pp.catalogo_id > 0
             SET pb.canonical_sku = NULL,
                 pb.updated_at = NOW()
             WHERE pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL
               AND pb.canonical_sku != ''
               AND pb.canonical_sku = pp.codigo_proveedor"
        );

        $candidates = $wpdb->get_results(
            "SELECT DISTINCT pb.id, pp.codigo_proveedor, pp.id AS pp_id
             FROM {$prefix}producto_base pb
             INNER JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id
             WHERE pb.deleted_at IS NULL
               AND (
                    pb.canonical_sku IS NULL
                    OR pb.canonical_sku = ''
                    OR pb.canonical_sku = pp.codigo_proveedor
               )
             ORDER BY pb.id ASC
             LIMIT 6000",
            ARRAY_A
        );

        $tasks_created = 0;
        foreach ($candidates ?: [] as $row) {
            if (!function_exists('riverso_create_review_task')) {
                break;
            }
            $tid = riverso_create_review_task(
                'crear_contraparte_local',
                sprintf(
                    'Asignar SKU Local para código catálogo %s (base #%d)',
                    $row['codigo_proveedor'],
                    (int) $row['id']
                ),
                'producto_base',
                (int) $row['id'],
                [
                    'prioridad' => 'normal',
                    'datos_extra' => [
                        'base_id' => (int) $row['id'],
                        'pp_id' => (int) $row['pp_id'],
                        'codigo_proveedor' => $row['codigo_proveedor'],
                        'codigo_catalogo' => $row['codigo_proveedor'],
                        'origen' => 'phase19_backfill',
                    ],
                ]
            );
            if ($tid) {
                $tasks_created++;
            }
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase19_clear_catalog_as_local_sku',
                'producto_base',
                0,
                'info',
                "Fase 19: cleared≈{$cleared} candidates=" . count($candidates ?: []) . " tasks={$tasks_created}"
            );
        }
    }

    /**
     * Fase 20: Hub v2 - Agregar campo imagen_id para media picker
     */
    private static function create_phase20_hub_v2_images($prefix) {
        global $wpdb;
        
        self::add_column_if_missing(
            "{$prefix}producto_base",
            'imagen_id',
            "imagen_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID de attachment (imagen) de WordPress'"
        );
        
        // Crear índice si no existe
        $index_name = 'idx_imagen_id';
        $table = "{$prefix}producto_base";
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = '{$index_name}'");
        
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY {$index_name} (imagen_id)");
        }
        
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase20_hub_v2_images',
                'producto_base',
                0,
                'info',
                'Fase 20: Agregado campo imagen_id para media picker'
            );
        }
    }

    /**
     * Fase 21: Confirmar tipo de documento - Agregar control de confirmación de tipo_documento
     * Permite marcar si el tipo de documento (documento_subtipo) ha sido confirmado por usuario
     * Al subir, se inicia con tipo_confirmado = 0 y se genera tarea de confirmación
     */
    private static function create_phase21_invoice_tipo_confirmado($prefix) {
        global $wpdb;

        $table = "{$prefix}facturas";
        $column_existed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            'tipo_confirmado'
        ));

        self::add_column_if_missing(
            $table,
            'tipo_confirmado',
            "tipo_confirmado TINYINT(1) DEFAULT 0 COMMENT 'Si el documento_subtipo fue confirmado manualmente (0=pendiente, 1=confirmado)'"
        );

        self::add_index_if_missing($table, 'idx_tipo_confirmado', 'KEY idx_tipo_confirmado (tipo_confirmado)');

        // Solo al crear la columna: las facturas históricas ya fueron procesadas.
        if ($column_existed === 0) {
            $wpdb->query("UPDATE {$table} SET tipo_confirmado = 1 WHERE tipo_confirmado = 0");
        }

        if (function_exists('riverso_create_review_task')) {
            $pending = $wpdb->get_results(
                "SELECT id, folio, documento_subtipo FROM {$table} WHERE tipo_confirmado = 0",
                ARRAY_A
            );
            foreach ($pending ?: [] as $row) {
                $tipo_label = [
                    'productos' => 'Productos',
                    'envio' => 'Flete',
                    'nota_credito' => 'Nota de Crédito',
                    'guia_despacho' => 'Guía de Despacho',
                    'gastos' => 'Gastos',
                ][$row['documento_subtipo'] ?? ''] ?? ($row['documento_subtipo'] ?: 'sin clasificar');
                riverso_create_review_task(
                    'confirmar_tipo_documento',
                    sprintf('Confirmar tipo de documento - Folio %s (Sugerido: %s)', $row['folio'], $tipo_label),
                    'factura',
                    (int) $row['id'],
                    [
                        'descripcion' => sprintf(
                            'Se sugiere que esta factura (folio %s) sea de tipo "%s". Confirme o cambie el tipo desde Facturas DTE.',
                            $row['folio'],
                            $tipo_label
                        ),
                        'prioridad' => 'alta',
                    ]
                );
            }
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase21_invoice_tipo_confirmado',
                'facturas',
                0,
                'info',
                'Fase 21: Agregado campo tipo_confirmado para confirmar tipo de documento'
            );
        }
    }

    /**
     * Fase 22: Andina y similares — no usar SKU local de un match UNMATCHED.
     * Folio 96946179 quedó con SKU 853 (tornillo) en todas las líneas.
     */
    private static function create_phase22_untrusted_supplier_sku_repair() {
        if (get_option('riverso_pos_phase22_untrusted_sku_repair') === '1') {
            return;
        }

        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-intake-service.php';

        $intake = Riverso_Invoice_Intake_Service::get_instance();
        $andina_id = 0;
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $andina_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}proveedores WHERE rut = %s LIMIT 1",
            '911440008'
        ));
        if (!$andina_id) {
            $andina_id = (int) $wpdb->get_var(
                "SELECT proveedor_id FROM {$prefix}facturas WHERE folio = '96946179' LIMIT 1"
            );
        }

        $result = $andina_id
            ? $intake->repair_untrusted_supplier_sku_links($andina_id)
            : $intake->repair_untrusted_supplier_sku_links();

        $intake->repair_mislinked_invoice_items(['folio' => '96946179']);

        update_option('riverso_pos_phase22_untrusted_sku_repair', '1');

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase22_untrusted_supplier_sku_repair',
                'factura_items',
                0,
                [
                    'actor_type' => 'computer',
                    'details' => 'Fase 22: desvinculados códigos Andina pegados al SKU 853',
                    'new_value' => $result,
                ]
            );
        }
    }

    /**
     * Fase 23: fechas del mapeo SKU (modificación y último documento visto).
     */
    private static function create_phase23_sku_mapping_dates($prefix) {
        $table = "{$prefix}codigos";
        self::add_column_if_missing(
            $table,
            'sku_mapped_at',
            "sku_mapped_at DATETIME DEFAULT NULL COMMENT 'Fecha de última modificación del mapeo SKU'"
        );
        self::add_column_if_missing(
            $table,
            'last_seen_document_date',
            "last_seen_document_date DATE DEFAULT NULL COMMENT 'Fecha del último documento en que se vio este código'"
        );
        self::add_index_if_missing(
            $table,
            'idx_last_seen_document_date',
            'KEY idx_last_seen_document_date (last_seen_document_date)'
        );
    }

    /**
     * Fase 24: códigos Mamut sin SKU local (04TRC → 04TRC) no deben quedar vinculados.
     * Folio 744064 quedó procesado con todos los ítems "vinculados".
     */
    private static function create_phase24_identity_sku_repair() {
        if (get_option('riverso_pos_phase24_identity_sku_repair') === '1') {
            return;
        }

        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-intake-service.php';

        $intake = Riverso_Invoice_Intake_Service::get_instance();
        $scoped = $intake->repair_identity_mapped_invoice_items(['folio' => '744064']);
        $all = $intake->repair_identity_mapped_invoice_items();

        update_option('riverso_pos_phase24_identity_sku_repair', '1');

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase24_identity_sku_repair',
                'factura_items',
                0,
                [
                    'actor_type' => 'computer',
                    'details' => 'Fase 24: desvinculados SKU locales iguales al código proveedor (sin usar)',
                    'new_value' => ['folio_744064' => $scoped, 'global' => $all],
                ]
            );
        }
    }

    /**
     * Fase 25: órdenes de impresión (cabecera + ítems).
     */
    private static function create_phase25_print_orders($prefix, $charset_collate) {
        global $wpdb;

        $sql = "CREATE TABLE {$prefix}ordenes_impresion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero_orden VARCHAR(20) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
            tipo VARCHAR(30) NOT NULL DEFAULT 'etiqueta_producto',
            prioridad TINYINT(1) NOT NULL DEFAULT 0,
            notas TEXT DEFAULT NULL,
            solicitado_por BIGINT UNSIGNED DEFAULT NULL,
            solicitado_por_nombre VARCHAR(100) DEFAULT NULL,
            aprobado_por BIGINT UNSIGNED DEFAULT NULL,
            impreso_por BIGINT UNSIGNED DEFAULT NULL,
            impresora_nombre VARCHAR(100) DEFAULT NULL,
            impreso_en DATETIME DEFAULT NULL,
            cancelado_por BIGINT UNSIGNED DEFAULT NULL,
            cancelado_en DATETIME DEFAULT NULL,
            motivo_cancelacion TEXT DEFAULT NULL,
            total_items INT NOT NULL DEFAULT 0,
            total_copias INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY numero_orden (numero_orden),
            KEY estado (estado),
            KEY solicitado_por (solicitado_por),
            KEY created_at (created_at),
            KEY tipo (tipo)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}orden_impresion_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(50) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            precio DECIMAL(12,2) DEFAULT NULL,
            precio_original DECIMAL(12,2) DEFAULT NULL,
            cantidad_ean INT NOT NULL DEFAULT 100,
            copias INT NOT NULL DEFAULT 1,
            modo VARCHAR(30) NOT NULL DEFAULT 'BolsaCOD',
            color VARCHAR(10) NOT NULL DEFAULT 'BN',
            ean13 VARCHAR(13) DEFAULT NULL,
            impreso TINYINT(1) NOT NULL DEFAULT 0,
            orden_posicion INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY orden_id (orden_id),
            KEY sku (sku)
        ) $charset_collate;";
        dbDelta($sql);

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$prefix}orden_impresion_items LIKE 'precio_original'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$prefix}orden_impresion_items ADD precio_original DECIMAL(12,2) DEFAULT NULL AFTER precio");
        }

        if (get_option('riverso_pos_phase25_print_orders') !== '1') {
            update_option('riverso_pos_phase25_print_orders', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase25_print_orders',
                    'print_order',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 25: tablas de órdenes de impresión',
                        'new_value' => [
                            'ordenes_impresion' => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . 'ordenes_impresion')) ? 1 : 0,
                            'orden_impresion_items' => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . 'orden_impresion_items')) ? 1 : 0,
                        ],
                    ]
                );
            }
        }
    }

    /**
     * Fase 26: ubicaciones de bodega, conteos de inventario y órdenes.
     */
    private static function create_phase26_inventory_locations($prefix, $charset_collate) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $ubicaciones = "{$prefix}ubicaciones";
        self::add_column_if_missing($ubicaciones, 'barcode', 'barcode VARCHAR(50) DEFAULT NULL');
        self::add_column_if_missing($ubicaciones, 'zona', 'zona VARCHAR(50) DEFAULT NULL');
        $wpdb->query("UPDATE {$ubicaciones} SET barcode = NULL WHERE barcode = ''");
        self::add_index_if_missing($ubicaciones, 'ux_ubicacion_barcode', 'UNIQUE KEY ux_ubicacion_barcode (barcode)');

        $conteos = "{$prefix}conteos";
        self::add_column_if_missing($conteos, 'nombre', 'nombre VARCHAR(100) DEFAULT NULL');
        self::add_column_if_missing($conteos, 'tipo_conteo', "tipo_conteo VARCHAR(30) NOT NULL DEFAULT 'general'");
        self::add_column_if_missing($conteos, 'producto_base_id', 'producto_base_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing($conteos, 'orden_id', 'orden_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_index_if_missing($conteos, 'idx_tipo_conteo', 'KEY idx_tipo_conteo (tipo_conteo)');
        self::add_index_if_missing($conteos, 'idx_producto_base', 'KEY idx_producto_base (producto_base_id)');

        $items = "{$prefix}conteo_items";
        self::add_column_if_missing($items, 'ubicacion_id', 'ubicacion_id BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing($items, 'es_abierto', 'es_abierto TINYINT(1) NOT NULL DEFAULT 0');
        self::add_column_if_missing($items, 'cantidad_manual', 'cantidad_manual DECIMAL(12,4) DEFAULT NULL');
        self::add_index_if_missing($items, 'idx_ubicacion', 'KEY idx_ubicacion (ubicacion_id)');
        self::add_index_if_missing($items, 'idx_producto_base', 'KEY idx_producto_base (producto_base_id)');

        $sql = "CREATE TABLE {$prefix}producto_ubicacion_preferida (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            ubicacion_id BIGINT UNSIGNED NOT NULL,
            es_preferido TINYINT(1) NOT NULL DEFAULT 0,
            prioridad INT NOT NULL DEFAULT 100,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_producto_ubicacion (producto_base_id, ubicacion_id),
            KEY idx_ubicacion (ubicacion_id),
            KEY idx_preferido (producto_base_id, es_preferido)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}producto_ubicacion_historial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            ubicacion_id BIGINT UNSIGNED NOT NULL,
            conteo_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad_contada DECIMAL(12,4) NOT NULL DEFAULT 0,
            fecha_conteo DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_producto_fecha (producto_base_id, fecha_conteo),
            KEY idx_ubicacion (ubicacion_id),
            KEY idx_conteo (conteo_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}conteo_scan_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conteo_id BIGINT UNSIGNED NOT NULL,
            conteo_item_id BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            barcode_raw VARCHAR(100) DEFAULT NULL,
            tipo_barcode VARCHAR(20) DEFAULT NULL,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            envase_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad_decodificada DECIMAL(12,4) DEFAULT NULL,
            es_abierto TINYINT(1) NOT NULL DEFAULT 0,
            accion VARCHAR(20) NOT NULL DEFAULT 'scan',
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conteo (conteo_id),
            KEY idx_item (conteo_item_id),
            KEY idx_producto (producto_base_id),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}ordenes_inventario (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(150) DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            tipo VARCHAR(30) NOT NULL DEFAULT 'general',
            prioridad TINYINT(1) NOT NULL DEFAULT 0,
            descripcion TEXT DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            asignado_a BIGINT UNSIGNED DEFAULT NULL,
            fecha_programada DATE DEFAULT NULL,
            completado_en DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_estado (estado),
            KEY idx_tipo (tipo),
            KEY idx_asignado (asignado_a)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}orden_inventario_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            conteo_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_orden (orden_id),
            KEY idx_ubicacion (ubicacion_id),
            KEY idx_producto (producto_base_id)
        ) $charset_collate;";
        dbDelta($sql);

        $wpdb->query("ALTER TABLE {$prefix}ordenes_inventario MODIFY titulo VARCHAR(150) NULL DEFAULT NULL");

        if (get_option('riverso_pos_phase26_inventory_locations') !== '1') {
            update_option('riverso_pos_phase26_inventory_locations', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase26_inventory_locations',
                    'inventory',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 26: ubicaciones preferidas, historial y órdenes de inventario',
                    ]
                );
            }
        }
    }

    private static function create_phase27_sort_orders($prefix, $charset_collate) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$prefix}ordenes_ordenar (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(150) DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            ubicacion_origen_id BIGINT UNSIGNED DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            asignado_a BIGINT UNSIGNED DEFAULT NULL,
            completado_en DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_estado (estado),
            KEY idx_origen (ubicacion_origen_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}orden_ordenar_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            cantidad INT NOT NULL DEFAULT 1,
            ubicacion_destino_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            movement_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_orden (orden_id),
            KEY idx_producto (producto_base_id),
            KEY idx_destino (ubicacion_destino_id)
        ) $charset_collate;";
        dbDelta($sql);

        if (get_option('riverso_pos_phase27_sort_orders') !== '1') {
            update_option('riverso_pos_phase27_sort_orders', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase27_sort_orders',
                    'inventory',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 27: órdenes de ordenar (traslados a lugar preferido)',
                    ]
                );
            }
        }
    }

    private static function create_phase28_stock_status($prefix, $charset_collate) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Tabla: Config de límites de stock (min/critico) por producto
        $sql = "CREATE TABLE {$prefix}producto_stock_config (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            stock_minimo INT DEFAULT NULL,
            stock_critico INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_producto (producto_base_id),
            KEY idx_stock_minimo (stock_minimo),
            KEY idx_stock_critico (stock_critico)
        ) $charset_collate;";
        dbDelta($sql);

        // Asegurar columnas para ubicar '?' (si ya fueron agregadas en phase26, no pasa nada)
        $ubicaciones = "{$prefix}ubicaciones";
        self::add_column_if_missing($ubicaciones, 'barcode', 'barcode VARCHAR(50) DEFAULT NULL');
        self::add_column_if_missing($ubicaciones, 'zona', 'zona VARCHAR(50) DEFAULT NULL');

        // Insertar / actualizar ubicacion especial '?'
        $unknown_code = '?';
        $unknown_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}ubicaciones WHERE codigo = %s LIMIT 1",
            $unknown_code
        )));

        if ($unknown_id <= 0) {
            $wpdb->insert("{$prefix}ubicaciones", [
                'codigo' => $unknown_code,
                'nombre' => 'Desconocido',
                'tipo' => 'bodega_ext',
                'capacidad' => 0,
                'activo' => 1,
                'barcode' => $unknown_code,
                'zona' => null,
            ]);
            $unknown_id = intval($wpdb->insert_id);
        } else {
            $wpdb->update("{$prefix}ubicaciones", [
                'nombre' => 'Desconocido',
                'tipo' => 'bodega_ext',
                'capacidad' => 0,
                'activo' => 1,
                'barcode' => $unknown_code,
                'zona' => null,
            ], ['id' => $unknown_id]);
        }

        // Nunca permitir que '?' sea preferida
        if ($unknown_id > 0) {
            $wpdb->delete("{$prefix}producto_ubicacion_preferida", ['ubicacion_id' => $unknown_id], ['%d']);
        }

        if (get_option('riverso_pos_phase28_stock_status') !== '1') {
            update_option('riverso_pos_phase28_stock_status', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase28_stock_status',
                    'inventory',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 28: estado de stock + ubicacion especial ?',
                    ]
                );
            }
        }
    }

    /**
     * Fase 29: mapeo autoritativo de barcodes (codigo_barra es la fuente de verdad).
     */
    private static function create_phase29_barcodes_authoritative($prefix) {
        global $wpdb;
        $table = "{$prefix}codigo_barra";
        if (!self::table_exists($table)) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE `{$table}`
             MODIFY COLUMN producto_base_id BIGINT UNSIGNED DEFAULT NULL"
        );

        self::add_column_if_missing($table, 'estado', "estado VARCHAR(20) NOT NULL DEFAULT 'verificado'");
        self::add_column_if_missing($table, 'motivo_estado', 'motivo_estado VARCHAR(255) DEFAULT NULL');
        self::add_column_if_missing($table, 'estado_por', 'estado_por BIGINT UNSIGNED DEFAULT NULL');
        self::add_column_if_missing($table, 'estado_at', 'estado_at DATETIME DEFAULT NULL');
        self::add_column_if_missing($table, 'origen_datos', "origen_datos VARCHAR(50) NOT NULL DEFAULT 'legacy'");
        self::add_column_if_missing($table, 'requires_human_review', 'requires_human_review TINYINT(1) NOT NULL DEFAULT 0');
        self::add_column_if_missing($table, 'sku_local', 'sku_local VARCHAR(100) DEFAULT NULL');
        self::add_column_if_missing($table, 'pending_sku', 'pending_sku VARCHAR(100) DEFAULT NULL');
        self::add_column_if_missing($table, 'legacy_ref', 'legacy_ref LONGTEXT DEFAULT NULL');
        self::add_column_if_missing($table, 'conflicto', 'conflicto TINYINT(1) NOT NULL DEFAULT 0');

        self::drop_index_if_exists($table, 'ux_codigo');
        self::drop_index_if_exists($table, 'codigo');
        self::add_index_if_missing($table, 'idx_codigo', 'KEY idx_codigo (codigo)');
        self::add_index_if_missing($table, 'idx_codigo_estado', 'KEY idx_codigo_estado (codigo, estado)');
        self::add_index_if_missing($table, 'idx_codigo_origen', 'KEY idx_codigo_origen (codigo, origen_datos)');
        self::add_index_if_missing($table, 'idx_sku_local', 'KEY idx_sku_local (sku_local)');
        self::add_index_if_missing($table, 'idx_pending_sku', 'KEY idx_pending_sku (pending_sku)');
        self::add_index_if_missing($table, 'idx_conflicto', 'KEY idx_conflicto (conflicto)');

        $wpdb->query(
            "UPDATE `{$table}`
             SET estado = 'propuesto',
                 requires_human_review = 1,
                 activo = 1
             WHERE estado = 'verificado'
               AND (
                    (migrado_de_tabla IS NOT NULL AND migrado_de_tabla <> '')
                    OR origen_datos IN ('legacy', 'legacy_tienda_local', 'legacy_wp_riverso_barcodes', 'codigos_legacy')
               )"
        );

        $import_file = RIVERSO_POS_PLUGIN_DIR . 'migrations/phase29_barcodes_import_legacy.php';
        if (file_exists($import_file)) {
            require_once $import_file;
        }

        if (class_exists('Riverso_Barcode_Legacy_Importer')) {
            $imported = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE origen_datos IN ('legacy_tienda_local', 'legacy_wp_riverso_barcodes')"
            );
            if ($imported === 0 || get_option('riverso_pos_phase29_barcode_import_v2') !== '1') {
                Riverso_Barcode_Legacy_Importer::run(false);
                update_option('riverso_pos_phase29_barcode_import_v2', '1');
            }
        }

        if (get_option('riverso_pos_phase29_barcodes') !== '1') {
            update_option('riverso_pos_phase29_barcodes', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase29_barcodes_authoritative',
                    'barcode',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 29: mapeo autoritativo de códigos de barra',
                    ]
                );
            }
        }
    }

    /**
     * Fase 30: catálogo maestro de tipos de envase (Envase, Caja, Balde).
     */
    private static function create_phase30_envase_tipos($prefix, $charset_collate = '') {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
        }

        $table = "{$prefix}envase_tipos";
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(30) NOT NULL,
            nombre VARCHAR(80) NOT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_slug (slug)
        ) $charset_collate;";
        dbDelta($sql);

        $wpdb->query(
            "INSERT IGNORE INTO {$table} (slug, nombre, descripcion, activo, orden)
             VALUES
                ('envase', 'Envase', 'Unidad de venta o compra cerrada', 1, 10),
                ('caja', 'Caja', 'Caja con varias unidades', 1, 20),
                ('balde', 'Balde', 'Balde o contenedor', 1, 30)"
        );

        if (get_option('riverso_pos_phase30_envase_tipos') !== '1') {
            update_option('riverso_pos_phase30_envase_tipos', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase30_envase_tipos',
                    'packaging',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 30: catálogo de tipos de envase',
                    ]
                );
            }
        }
    }

    /**
     * Fase 31: documentos escaneados (PDF/imagen) con extracción IA.
     */
    private static function create_phase31_scan_documents($prefix, $charset_collate = '') {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
        }

        $sql = "CREATE TABLE {$prefix}documentos_archivos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            archivo_hash CHAR(64) NOT NULL,
            nombre_original VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            paginas INT UNSIGNED NOT NULL DEFAULT 1,
            r2_key_original VARCHAR(512) DEFAULT NULL,
            r2_key_json VARCHAR(512) DEFAULT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
            gemini_model VARCHAR(80) DEFAULT NULL,
            gemini_tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
            gemini_tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
            gemini_llamadas INT UNSIGNED NOT NULL DEFAULT 0,
            gemini_reutilizado TINYINT(1) NOT NULL DEFAULT 0,
            error_mensaje TEXT DEFAULT NULL,
            subido_por BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_archivo_hash (archivo_hash),
            KEY idx_estado (estado),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}documentos_escaneados (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            archivo_id BIGINT UNSIGNED NOT NULL,
            pagina_inicio INT UNSIGNED NOT NULL DEFAULT 1,
            pagina_fin INT UNSIGNED NOT NULL DEFAULT 1,
            doc_hash CHAR(64) NOT NULL,
            tipo_documento VARCHAR(50) DEFAULT NULL,
            tipo_dte INT DEFAULT NULL,
            folio VARCHAR(50) DEFAULT NULL,
            rut_emisor VARCHAR(20) DEFAULT NULL,
            razon_social_emisor VARCHAR(255) DEFAULT NULL,
            rut_receptor VARCHAR(20) DEFAULT NULL,
            fecha_emision DATE DEFAULT NULL,
            fecha_vencimiento DATE DEFAULT NULL,
            monto_neto DECIMAL(12,2) DEFAULT 0,
            monto_exento DECIMAL(12,2) DEFAULT 0,
            monto_iva DECIMAL(12,2) DEFAULT 0,
            monto_total DECIMAL(12,2) DEFAULT 0,
            confianza DECIMAL(5,4) DEFAULT NULL,
            validacion LONGTEXT DEFAULT NULL,
            estado_revision VARCHAR(30) NOT NULL DEFAULT 'pendiente',
            factura_id BIGINT UNSIGNED DEFAULT NULL,
            r2_key_json VARCHAR(512) DEFAULT NULL,
            datos_json LONGTEXT DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_doc_hash (doc_hash),
            KEY idx_archivo (archivo_id),
            KEY idx_estado (estado_revision),
            KEY idx_folio_rut (folio, rut_emisor),
            KEY idx_factura (factura_id),
            KEY idx_fecha (fecha_emision)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}documento_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            documento_id BIGINT UNSIGNED NOT NULL,
            numero_linea INT NOT NULL DEFAULT 1,
            codigo VARCHAR(100) DEFAULT NULL,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT DEFAULT NULL,
            cantidad DECIMAL(12,4) NOT NULL DEFAULT 0,
            unidad VARCHAR(20) DEFAULT NULL,
            precio_unitario DECIMAL(12,4) DEFAULT 0,
            monto_total DECIMAL(12,2) DEFAULT 0,
            confianza DECIMAL(5,4) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_documento (documento_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}documento_referencias (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            documento_id BIGINT UNSIGNED NOT NULL,
            tipo_ref VARCHAR(50) NOT NULL,
            tipo_doc_ref INT DEFAULT NULL,
            folio_ref VARCHAR(50) NOT NULL,
            fecha_ref DATE DEFAULT NULL,
            razon TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_documento (documento_id),
            KEY idx_folio_ref (folio_ref),
            KEY idx_tipo_folio (tipo_doc_ref, folio_ref)
        ) $charset_collate;";
        dbDelta($sql);

        if (get_option('riverso_pos_phase31_scan_documents') !== '1') {
            update_option('riverso_pos_phase31_scan_documents', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase31_scan_documents',
                    'invoice_scan',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 31: documentos escaneados con extracción IA',
                    ]
                );
            }
        }
    }

    /**
     * Fase 32: origen de ingreso de factura (xml / escaneo / ambos).
     */
    private static function create_phase32_factura_origen_ingreso($prefix) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = "{$prefix}facturas";
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'origen_ingreso'");
        if (empty($col)) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN origen_ingreso VARCHAR(20) NOT NULL DEFAULT 'xml'
                 COMMENT 'xml|escaneo|ambos' AFTER xml_hash"
            );
        }

        // Backfill: facturas con escaneos vinculados
        $docs_table = "{$prefix}documentos_escaneados";
        $wpdb->query(
            "UPDATE {$table} f
             SET f.origen_ingreso = 'escaneo'
             WHERE f.origen_ingreso = 'xml'
               AND EXISTS (
                   SELECT 1 FROM {$docs_table} d
                   WHERE d.factura_id = f.id
                     AND d.estado_revision IN ('confirmado', 'duplicado')
               )
               AND (f.xml_path IS NULL OR f.xml_path = '' OR f.xml_path NOT LIKE '[%')"
        );
        $wpdb->query(
            "UPDATE {$table} f
             SET f.origen_ingreso = 'ambos'
             WHERE f.origen_ingreso = 'xml'
               AND f.xml_path IS NOT NULL AND f.xml_path != '' AND f.xml_path LIKE '[%'
               AND EXISTS (
                   SELECT 1 FROM {$docs_table} d
                   WHERE d.factura_id = f.id
                     AND d.estado_revision IN ('confirmado', 'duplicado')
               )"
        );

        if (get_option('riverso_pos_phase32_origen_ingreso') !== '1') {
            update_option('riverso_pos_phase32_origen_ingreso', '1');
        }
    }

    /**
     * Fase 33: mapeo productos Riverso ↔ FACTO + outbox de sincronización.
     */
    private static function create_phase33_facto_integration($prefix, $charset_collate = '') {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
        }

        $sql = "CREATE TABLE {$prefix}facto_producto_map (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            facto_product_id BIGINT UNSIGNED NOT NULL,
            facto_sku VARCHAR(100) DEFAULT NULL,
            last_payload_hash CHAR(40) DEFAULT NULL,
            sync_state VARCHAR(20) NOT NULL DEFAULT 'linked',
            last_error TEXT DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_producto_base (producto_base_id),
            UNIQUE KEY ux_facto_product (facto_product_id),
            KEY idx_facto_sku (facto_sku),
            KEY idx_sync_state (sync_state)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}facto_sync_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            operation VARCHAR(20) NOT NULL,
            payload LONGTEXT DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_error TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_state_next (state, next_attempt_at),
            KEY idx_producto (producto_base_id),
            KEY idx_operation (operation)
        ) $charset_collate;";
        dbDelta($sql);

        if (get_option('riverso_pos_phase33_facto_integration') !== '1') {
            update_option('riverso_pos_phase33_facto_integration', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase33_facto_integration',
                    'facto',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 33: integración FACTO (map + outbox)',
                    ]
                );
            }
        }
    }

    /**
     * Fase 34: importación DTE desde Inbox FACTO + historial de intervalos.
     */
    private static function create_phase34_facto_inbox_import($prefix, $charset_collate = '') {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
        }

        $sql = "CREATE TABLE {$prefix}facto_inbox_map (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inbox_document_id BIGINT UNSIGNED NOT NULL,
            factura_id BIGINT UNSIGNED DEFAULT NULL,
            tipo_dte TINYINT UNSIGNED DEFAULT NULL,
            folio VARCHAR(50) DEFAULT NULL,
            rut_emisor VARCHAR(20) DEFAULT NULL,
            document_date DATE DEFAULT NULL,
            total_amount DECIMAL(14,2) DEFAULT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'imported',
            last_error TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_inbox_document (inbox_document_id),
            KEY idx_state (state),
            KEY idx_document_date (document_date),
            KEY idx_factura (factura_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}facto_inbox_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fecha_desde DATE NOT NULL,
            fecha_hasta DATE NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'running',
            docs_found INT UNSIGNED NOT NULL DEFAULT 0,
            docs_imported INT UNSIGNED NOT NULL DEFAULT 0,
            docs_merged INT UNSIGNED NOT NULL DEFAULT 0,
            docs_duplicate INT UNSIGNED NOT NULL DEFAULT 0,
            docs_skipped INT UNSIGNED NOT NULL DEFAULT 0,
            docs_error INT UNSIGNED NOT NULL DEFAULT 0,
            pages_scanned INT UNSIGNED NOT NULL DEFAULT 0,
            page_from INT UNSIGNED DEFAULT NULL,
            page_to INT UNSIGNED DEFAULT NULL,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME DEFAULT NULL,
            started_by BIGINT UNSIGNED DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_fechas (fecha_desde, fecha_hasta),
            KEY idx_state (state)
        ) $charset_collate;";
        dbDelta($sql);

        if (get_option('riverso_pos_phase34_facto_inbox_import') !== '1') {
            update_option('riverso_pos_phase34_facto_inbox_import', '1');
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log(
                    'schema.phase34_facto_inbox_import',
                    'facto_inbox',
                    0,
                    [
                        'actor_type' => 'computer',
                        'details' => 'Fase 34: importación DTE Inbox FACTO (map + runs)',
                    ]
                );
            }
        }
    }

    /**
     * Fase 35: unificar MAMUT → Tecbolt SA, normalizar origen_datos,
     * marcar matches legacy por confirmar y crear tasks.
     */
    private static function create_phase35_tecbolt_unify($prefix) {
        global $wpdb;

        if (get_option('riverso_pos_phase35_tecbolt_unify') === '1') {
            // Asegurar apodo Mamut (además de MAMUT) sin re-ejecutar la unificación.
            $tecbolt_id = (int) $wpdb->get_var(
                "SELECT id FROM {$prefix}proveedores WHERE nombre LIKE '%Tecbolt%' ORDER BY id ASC LIMIT 1"
            );
            if ($tecbolt_id > 0) {
                foreach (['Mamut', 'MAMUT'] as $apodo) {
                    $exists = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$prefix}proveedor_apodos
                         WHERE proveedor_id = %d AND LOWER(apodo) = LOWER(%s) LIMIT 1",
                        $tecbolt_id,
                        $apodo
                    ));
                    if (!$exists) {
                        $wpdb->insert(
                            "{$prefix}proveedor_apodos",
                            ['proveedor_id' => $tecbolt_id, 'apodo' => $apodo],
                            ['%d', '%s']
                        );
                    }
                }
            }
            return;
        }

        $report = [
            'origen_normalized' => 0,
            'pp_moved' => 0,
            'pp_merged' => 0,
            'marked_pending' => 0,
            'tasks_created' => 0,
            'mamut_deleted' => false,
            'tecbolt_id' => 0,
            'warnings' => [],
        ];

        // --- A) Normalizar origen_datos ---
        $report['origen_normalized'] += (int) $wpdb->query(
            "UPDATE {$prefix}producto_proveedor
             SET origen_datos = 'legacy'
             WHERE origen_datos = 'riverso_codigos'"
        );
        $report['origen_normalized'] += (int) $wpdb->query(
            "UPDATE {$prefix}producto_proveedor
             SET origen_datos = 'catalogo'
             WHERE origen_datos IN ('computer', 'mamut_import')
                OR (origen_datos = 'manual' AND catalogo_id IS NOT NULL AND catalogo_id > 0)"
        );
        $report['origen_normalized'] += (int) $wpdb->query(
            "UPDATE {$prefix}producto_proveedor
             SET origen_datos = 'factura'
             WHERE origen_datos = 'factura_intake'"
        );

        // --- B) Resolver Tecbolt ---
        $tecbolt_id = (int) $wpdb->get_var(
            "SELECT id FROM {$prefix}proveedores
             WHERE nombre LIKE '%Tecbolt%'
             ORDER BY id ASC LIMIT 1"
        );
        if (!$tecbolt_id) {
            $wpdb->insert(
                "{$prefix}proveedores",
                [
                    'rut' => '76.000.000-0',
                    'nombre' => 'Tecbolt SA',
                    'activo' => 1,
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s']
            );
            $tecbolt_id = (int) $wpdb->insert_id;
            $report['warnings'][] = 'Tecbolt SA no existía; se creó con RUT placeholder.';
        }
        $report['tecbolt_id'] = $tecbolt_id;

        // Apodos Mamut / MAMUT
        foreach (['Mamut', 'MAMUT'] as $apodo) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}proveedor_apodos
                 WHERE proveedor_id = %d AND LOWER(apodo) = LOWER(%s) LIMIT 1",
                $tecbolt_id,
                $apodo
            ));
            if (!$exists) {
                $wpdb->insert(
                    "{$prefix}proveedor_apodos",
                    ['proveedor_id' => $tecbolt_id, 'apodo' => $apodo],
                    ['%d', '%s']
                );
            }
        }

        $mamut_id = (int) $wpdb->get_var(
            "SELECT id FROM {$prefix}proveedores
             WHERE (nombre = 'MAMUT' OR rut = 'MAMUT') AND id <> {$tecbolt_id}
             LIMIT 1"
        );

        if ($mamut_id > 0 && $tecbolt_id > 0 && $mamut_id !== $tecbolt_id) {
            // Catálogo mamut → Tecbolt
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}catalogos SET proveedor_id = %d
                 WHERE alias = 'mamut' OR proveedor_id = %d",
                $tecbolt_id,
                $mamut_id
            ));

            // Mover PP MAMUT → Tecbolt con manejo de colisiones
            $mamut_pps = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}producto_proveedor WHERE proveedor_id = %d",
                $mamut_id
            ), ARRAY_A);

            foreach ($mamut_pps ?: [] as $mamut_pp) {
                $clash = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$prefix}producto_proveedor
                     WHERE proveedor_id = %d AND codigo_proveedor = %s AND id <> %d
                     LIMIT 1",
                    $tecbolt_id,
                    $mamut_pp['codigo_proveedor'],
                    $mamut_pp['id']
                ), ARRAY_A);

                if (!$clash) {
                    $wpdb->update(
                        "{$prefix}producto_proveedor",
                        ['proveedor_id' => $tecbolt_id],
                        ['id' => (int) $mamut_pp['id']],
                        ['%d'],
                        ['%d']
                    );
                    $report['pp_moved']++;
                    continue;
                }

                // Colisión: conservar Tecbolt, enriquecer desde Mamut si hace falta
                $patch = [];
                $formats = [];
                if (empty($clash['catalogo_id']) && !empty($mamut_pp['catalogo_id'])) {
                    $patch['catalogo_id'] = (int) $mamut_pp['catalogo_id'];
                    $formats[] = '%d';
                }
                $clash_verified = strtoupper((string) ($clash['match_estado'] ?? '')) === 'VERIFIED';
                $mamut_has_base = !empty($mamut_pp['producto_base_id']);
                $clash_has_base = !empty($clash['producto_base_id']);
                if (!$clash_verified && $mamut_has_base && !$clash_has_base) {
                    $patch['producto_base_id'] = (int) $mamut_pp['producto_base_id'];
                    $formats[] = '%d';
                }
                if (empty($clash['nombre_proveedor']) && !empty($mamut_pp['nombre_proveedor'])) {
                    $patch['nombre_proveedor'] = $mamut_pp['nombre_proveedor'];
                    $formats[] = '%s';
                }
                // Preferir origen catalogo si el clash no lo tiene
                if (($clash['origen_datos'] ?? '') === 'manual' && in_array($mamut_pp['origen_datos'] ?? '', ['catalogo', 'computer'], true)) {
                    $patch['origen_datos'] = 'catalogo';
                    $formats[] = '%s';
                }
                if (!empty($patch)) {
                    $wpdb->update(
                        "{$prefix}producto_proveedor",
                        $patch,
                        ['id' => (int) $clash['id']],
                        $formats,
                        ['%d']
                    );
                }
                // Borrar duplicado MAMUT (UNIQUE impide reasignar el mismo código).
                $wpdb->delete("{$prefix}producto_proveedor", ['id' => (int) $mamut_pp['id']], ['%d']);
                $report['pp_merged']++;
            }

            // Reapuntar tablas relacionadas
            foreach (['codigos', 'codigo_barra', 'facturas'] as $suffix) {
                $table = "{$prefix}{$suffix}";
                if (self::table_exists($table)) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET proveedor_id = %d WHERE proveedor_id = %d",
                        $tecbolt_id,
                        $mamut_id
                    ));
                }
            }
            if (self::table_exists("{$prefix}facturas_recibidas")) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$prefix}facturas_recibidas SET proveedor_id = %d WHERE proveedor_id = %d",
                    $tecbolt_id,
                    $mamut_id
                ));
            }
            if (self::table_exists("{$prefix}supplier_product_links")) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$prefix}supplier_product_links SET supplier_id = %d WHERE supplier_id = %d",
                    $tecbolt_id,
                    $mamut_id
                ));
            }

            // Borrar apodos y proveedor MAMUT
            $wpdb->delete("{$prefix}proveedor_apodos", ['proveedor_id' => $mamut_id], ['%d']);
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor WHERE proveedor_id = %d",
                $mamut_id
            ));
            if ($remaining === 0) {
                $deleted = $wpdb->delete("{$prefix}proveedores", ['id' => $mamut_id], ['%d']);
                $report['mamut_deleted'] = (bool) $deleted;
            } else {
                $wpdb->update(
                    "{$prefix}proveedores",
                    ['activo' => 0],
                    ['id' => $mamut_id],
                    ['%d'],
                    ['%d']
                );
                $report['warnings'][] = "MAMUT aún tiene {$remaining} PP; se desactivó en vez de borrar.";
            }
        }

        // --- C) Marcar por confirmar + crear tasks ---
        $pending = $wpdb->get_results(
            "SELECT pp.id, pp.codigo_proveedor, pp.proveedor_id, pp.producto_base_id,
                    pp.origen_datos, pp.match_estado, pp.match_origen, pb.canonical_sku
             FROM {$prefix}producto_proveedor pp
             INNER JOIN {$prefix}producto_base pb
                ON pb.id = pp.producto_base_id
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
             WHERE pp.activo = 1
               AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
               AND (
                    pp.origen_datos IN ('legacy', 'riverso_codigos')
                 OR (
                        pp.origen_datos IN ('catalogo', 'computer')
                    AND (
                           COALESCE(pp.match_origen, '') IN ('computer', 'mamut', 'sku_mapping', '')
                        OR COALESCE(pp.match_estado, '') IN ('AUTO_MATCH', 'HUMAN_REVIEW', 'UNMATCHED', '')
                    )
                 )
               )",
            ARRAY_A
        );

        $now = current_time('mysql');
        $task_module = class_exists('Riverso_Task_Module') ? Riverso_Task_Module::get_instance() : null;

        foreach ($pending ?: [] as $row) {
            $wpdb->update(
                "{$prefix}producto_proveedor",
                [
                    'requires_human_review' => 1,
                    'review_status' => 'pendiente',
                    'updated_at' => $now,
                ],
                ['id' => (int) $row['id']],
                ['%d', '%s', '%s'],
                ['%d']
            );
            $report['marked_pending']++;

            if ($task_module) {
                $codigo = (string) $row['codigo_proveedor'];
                $task_id = $task_module->create_review_task(
                    'confirmar_codigo_proveedor',
                    "Confirmar código proveedor {$codigo}",
                    'producto_proveedor',
                    (int) $row['id'],
                    [
                        'prioridad' => 'normal',
                        'datos_extra' => [
                            'producto_base_id' => (int) $row['producto_base_id'],
                            'codigo_proveedor' => $codigo,
                            'proveedor_id' => (int) $row['proveedor_id'],
                            'canonical_sku' => $row['canonical_sku'],
                            'origen' => 'phase35',
                        ],
                    ]
                );
                if ($task_id) {
                    $report['tasks_created']++;
                }
            }
        }

        update_option('riverso_pos_phase35_tecbolt_unify', '1');
        update_option('riverso_pos_phase35_tecbolt_unify_report', $report);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase35_tecbolt_unify',
                'proveedor',
                $tecbolt_id,
                [
                    'actor_type' => 'computer',
                    'details' => 'Fase 35: MAMUT→Tecbolt, orígenes, confirmación legacy',
                    'new_value' => $report,
                ]
            );
        }
    }

    /**
     * Fase 35b: códigos de catálogo Mamut con SKU local en sku_mapping.json
     * se vinculan al producto_base local y quedan por confirmar (+ tasks).
     */
    private static function create_phase35b_catalog_mapping_confirm($prefix) {
        global $wpdb;

        if (get_option('riverso_pos_phase35b_catalog_mapping_confirm') === '1') {
            return;
        }

        if (!function_exists('riverso_mamut_online_to_local_sku')) {
            $helper = RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
            if (file_exists($helper)) {
                require_once $helper;
            }
        }
        if (!function_exists('riverso_mamut_online_to_local_sku')) {
            // No marcar como hecha: reintentar en el próximo update_database.
            return;
        }

        if (!class_exists('Riverso_Task_Module')) {
            $task_file = RIVERSO_POS_PLUGIN_DIR . 'core/tasks/class-task-module.php';
            if (file_exists($task_file)) {
                require_once $task_file;
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $cat_id = (int) $wpdb->get_var(
            "SELECT id FROM {$prefix}catalogos WHERE alias = 'mamut' ORDER BY id ASC LIMIT 1"
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pp.id, pp.codigo_proveedor, pp.proveedor_id, pp.producto_base_id,
                        pp.match_estado, pb.canonical_sku
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}producto_base pb
                   ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                 WHERE pp.activo = 1
                   AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
                   AND (
                        pp.catalogo_id = %d
                     OR pp.origen_datos IN ('catalogo', 'computer', 'legacy')
                   )",
                $cat_id > 0 ? $cat_id : 0
            ),
            ARRAY_A
        );

        $report = [
            'scanned' => count($rows ?: []),
            'linked' => 0,
            'already_on_local' => 0,
            'marked_pending' => 0,
            'tasks_created' => 0,
            'skipped_no_map' => 0,
            'skipped_no_base' => 0,
        ];

        $now = current_time('mysql');
        $task_module = class_exists('Riverso_Task_Module') ? Riverso_Task_Module::get_instance() : null;
        $sku_base_cache = [];

        foreach ($rows ?: [] as $row) {
            $pp_id = (int) $row['id'];
            $code = (string) $row['codigo_proveedor'];
            $current_sku = trim((string) ($row['canonical_sku'] ?? ''));
            $target_sku = $current_sku;

            if ($target_sku === '') {
                $mapped = riverso_mamut_online_to_local_sku($code);
                if (!$mapped) {
                    $report['skipped_no_map']++;
                    continue;
                }
                $target_sku = (string) $mapped;
            }

            if (!isset($sku_base_cache[$target_sku])) {
                $sku_base_cache[$target_sku] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}producto_base
                     WHERE canonical_sku = %s AND deleted_at IS NULL
                     ORDER BY id ASC LIMIT 1",
                    $target_sku
                ));
            }
            $local_base_id = $sku_base_cache[$target_sku];
            if ($local_base_id <= 0) {
                $report['skipped_no_base']++;
                continue;
            }

            $update = [
                'requires_human_review' => 1,
                'review_status' => 'pendiente',
                'updated_at' => $now,
            ];
            $formats = ['%d', '%s', '%s'];

            if ((int) ($row['producto_base_id'] ?? 0) !== $local_base_id) {
                $update['producto_base_id'] = $local_base_id;
                $formats[] = '%d';
                $update['match_origen'] = 'sku_mapping';
                $formats[] = '%s';
                if (strtoupper((string) ($row['match_estado'] ?? '')) === ''
                    || strtoupper((string) ($row['match_estado'] ?? '')) === 'UNMATCHED') {
                    $update['match_estado'] = 'HUMAN_REVIEW';
                    $formats[] = '%s';
                }
                $report['linked']++;
            } else {
                $report['already_on_local']++;
            }

            $wpdb->update(
                "{$prefix}producto_proveedor",
                $update,
                ['id' => $pp_id],
                $formats,
                ['%d']
            );
            $report['marked_pending']++;

            if ($task_module) {
                $task_id = $task_module->create_review_task(
                    'confirmar_codigo_proveedor',
                    "Confirmar código proveedor {$code}",
                    'producto_proveedor',
                    $pp_id,
                    [
                        'prioridad' => 'normal',
                        'datos_extra' => [
                            'producto_base_id' => $local_base_id,
                            'codigo_proveedor' => $code,
                            'proveedor_id' => (int) $row['proveedor_id'],
                            'canonical_sku' => $target_sku,
                            'origen' => 'phase35b_catalog_mapping',
                        ],
                    ]
                );
                if ($task_id) {
                    $report['tasks_created']++;
                }
            }
        }

        update_option('riverso_pos_phase35b_catalog_mapping_confirm', '1');
        update_option('riverso_pos_phase35b_catalog_mapping_confirm_report', $report);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'schema.phase35b_catalog_mapping_confirm',
                'producto_proveedor',
                0,
                [
                    'actor_type' => 'computer',
                    'details' => 'Fase 35b: catálogo Mamut con SKU mapeado → por confirmar',
                    'new_value' => $report,
                ]
            );
        }
    }
}
