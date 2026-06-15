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
            descuento_porcentaje DECIMAL(5,2) DEFAULT NULL,
            descuento_monto DECIMAL(12,2) DEFAULT NULL,
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
        
        // Tabla: Auditoría
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit.php';
        Riverso_POS_Audit::create_table();
        
        update_option('riverso_pos_db_version', RIVERSO_POS_VERSION);
    }
    
    /**
     * Crea los roles personalizados
     */
    public static function create_roles() {
        // Usar el método centralizado de la clase de permisos
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-permissions.php';
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
            ]
        ];
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
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
            woocommerce_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            woocommerce_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
            producto_base_id BIGINT UNSIGNED NOT NULL,
            proveedor_id BIGINT UNSIGNED NOT NULL,
            supplier_link_id BIGINT UNSIGNED DEFAULT NULL,
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
            tipo_sustitucion VARCHAR(20) DEFAULT 'compatible',
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
        $wpdb->query("ALTER TABLE {$prefix}supplier_product_links ADD COLUMN producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$prefix}supplier_product_links ADD COLUMN product_base_id BIGINT UNSIGNED DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$prefix}supplier_product_links ADD KEY idx_producto_proveedor_id (producto_proveedor_id)");
        $wpdb->query("ALTER TABLE {$prefix}supplier_product_links ADD KEY idx_product_base_id (product_base_id)");
        $wpdb->query("ALTER TABLE {$prefix}barcodes ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        $wpdb->query("ALTER TABLE {$prefix}barcodes ADD KEY idx_is_active (is_active)");
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
                ON pb.woocommerce_product_id = COALESCE(c.product_id, 0)
               AND pb.woocommerce_variation_id = 0
            WHERE c.codigo_proveedor IS NOT NULL
              AND c.codigo_proveedor <> ''
              AND COALESCE(c.proveedor_id, 0) > 0
        ");

        $wpdb->query("
            UPDATE {$prefix}codigos c
            INNER JOIN {$prefix}producto_base pb
                ON pb.woocommerce_product_id = COALESCE(c.product_id, 0)
               AND pb.woocommerce_variation_id = 0
            SET c.product_base_id = pb.id
            WHERE c.product_base_id IS NULL
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
     * Agrega un índice a una tabla solo si no existe (idempotente).
     */
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

        self::add_index_if_missing($table, 'idx_deleted_at', "KEY idx_deleted_at (deleted_at)");
        self::add_index_if_missing($table, 'idx_archived_at', "KEY idx_archived_at (archived_at)");
        self::add_index_if_missing($table, 'idx_publication_stage', "KEY idx_publication_stage (publication_stage)");
        self::add_index_if_missing($table, 'idx_match_estado_online', "KEY idx_match_estado_online (match_estado_online)");
    }
}
