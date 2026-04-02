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
            codigo_proveedor VARCHAR(100) NOT NULL,
            codigo_tipo VARCHAR(20) DEFAULT 'INT1',
            codigo_barras VARCHAR(50) DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            nombre_proveedor VARCHAR(255) DEFAULT NULL,
            unidad_medida VARCHAR(20) DEFAULT NULL,
            factor_conversion DECIMAL(10,4) DEFAULT 1.0000,
            precio_referencia DECIMAL(12,2) DEFAULT NULL,
            verificado TINYINT(1) DEFAULT 0,
            verificado_por BIGINT UNSIGNED DEFAULT NULL,
            verificado_at DATETIME DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY codigo_proveedor_proveedor (codigo_proveedor, proveedor_id),
            KEY sku_local (sku_local),
            KEY product_id (product_id),
            KEY codigo_barras (codigo_barras)
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
            factura_id BIGINT UNSIGNED DEFAULT NULL,
            factura_item_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            codigo_id BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            asignado_a BIGINT UNSIGNED DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            fecha_limite DATETIME DEFAULT NULL,
            completado_por BIGINT UNSIGNED DEFAULT NULL,
            completado_at DATETIME DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tipo (tipo),
            KEY estado (estado),
            KEY asignado_a (asignado_a)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Ubicaciones
        $sql = "CREATE TABLE {$prefix}ubicaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            nombre VARCHAR(100) DEFAULT NULL,
            pasillo VARCHAR(10) DEFAULT NULL,
            estante VARCHAR(10) DEFAULT NULL,
            nivel VARCHAR(10) DEFAULT NULL,
            posicion VARCHAR(10) DEFAULT NULL,
            tipo VARCHAR(20) DEFAULT 'estante',
            capacidad_max INT DEFAULT NULL,
            activo TINYINT(1) DEFAULT 1,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY codigo (codigo)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Producto-Ubicación
        $sql = "CREATE TABLE {$prefix}producto_ubicacion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_id BIGINT UNSIGNED NOT NULL,
            cantidad INT DEFAULT 0,
            es_principal TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY producto_ubicacion (product_id, variation_id, ubicacion_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Tabla: Movimientos
        $sql = "CREATE TABLE {$prefix}movimientos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(20) NOT NULL,
            cantidad DECIMAL(12,4) NOT NULL,
            cantidad_anterior DECIMAL(12,4) DEFAULT NULL,
            cantidad_posterior DECIMAL(12,4) DEFAULT NULL,
            ubicacion_origen_id BIGINT UNSIGNED DEFAULT NULL,
            ubicacion_destino_id BIGINT UNSIGNED DEFAULT NULL,
            factura_id BIGINT UNSIGNED DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            referencia VARCHAR(100) DEFAULT NULL,
            motivo TEXT DEFAULT NULL,
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY tipo (tipo),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        update_option('riverso_pos_db_version', RIVERSO_POS_VERSION);
    }
    
    /**
     * Crea los roles personalizados
     */
    public static function create_roles() {
        // Capacidades base
        $base_caps = [
            'read' => true,
        ];
        
        // Rol: Cotizador
        add_role('riverso_cotizador', __('Cotizador', 'riverso-pos'), array_merge($base_caps, [
            'riverso_view_products'    => true,
            'riverso_create_quotes'    => true,
            'riverso_view_quotes'      => true,
        ]));
        
        // Rol: Vendedor
        add_role('riverso_vendedor', __('Vendedor', 'riverso-pos'), array_merge($base_caps, [
            'riverso_view_products'    => true,
            'riverso_view_stock'       => true,
            'riverso_create_quotes'    => true,
            'riverso_view_quotes'      => true,
            'riverso_create_sales'     => true,
            'riverso_view_sales'       => true,
            'riverso_view_invoices'    => true,
            'riverso_view_tasks'       => true,
            'riverso_complete_tasks'   => true,
        ]));
        
        // Rol: Editor POS
        add_role('riverso_editor', __('Editor POS', 'riverso-pos'), array_merge($base_caps, [
            'riverso_view_products'    => true,
            'riverso_edit_products'    => true,
            'riverso_view_stock'       => true,
            'riverso_edit_stock'       => true,
            'riverso_create_quotes'    => true,
            'riverso_view_quotes'      => true,
            'riverso_create_sales'     => true,
            'riverso_view_sales'       => true,
            'riverso_view_invoices'    => true,
            'riverso_process_invoices' => true,
            'riverso_view_tasks'       => true,
            'riverso_create_tasks'     => true,
            'riverso_complete_tasks'   => true,
            'riverso_assign_tasks'     => true,
            'riverso_manage_codes'     => true,
            'riverso_view_reports'     => true,
        ]));
        
        // Agregar capacidades al administrador
        $admin = get_role('administrator');
        if ($admin) {
            $all_caps = [
                'riverso_view_products',
                'riverso_edit_products',
                'riverso_view_stock',
                'riverso_edit_stock',
                'riverso_create_quotes',
                'riverso_view_quotes',
                'riverso_create_sales',
                'riverso_view_sales',
                'riverso_view_invoices',
                'riverso_process_invoices',
                'riverso_view_tasks',
                'riverso_create_tasks',
                'riverso_complete_tasks',
                'riverso_assign_tasks',
                'riverso_manage_codes',
                'riverso_view_reports',
                'riverso_manage_settings',
                'riverso_manage_users',
            ];
            
            foreach ($all_caps as $cap) {
                $admin->add_cap($cap);
            }
        }
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
    }
}
