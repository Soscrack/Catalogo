<?php
/**
 * Sistema de Auditoría para Riverso POS/ERP
 * 
 * Registra todas las acciones críticas del sistema para trazabilidad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Audit {
    
    /**
     * Tipos de acciones auditables
     */
    const ACTIONS = [
        // Productos
        'product_created'       => 'Producto creado',
        'product_updated'       => 'Producto actualizado',
        'product_deleted'       => 'Producto eliminado',
        'product_archived'      => 'Producto archivado',
        'product_restored'      => 'Producto restaurado',
        'product_published'     => 'Producto publicado',
        'product_unpublished'   => 'Producto despublicado',
        'sku_changed'           => 'SKU modificado',
        'price_changed'         => 'Precio modificado',
        'stock_adjusted'        => 'Stock ajustado',
        'online_match_evaluated'=> 'Match online evaluado',
        'online_match_reviewed' => 'Match online revisado',
        
        // Cotizaciones recibidas
        'quote_received'        => 'Cotización recibida',
        'quote_approved'        => 'Cotización aprobada',
        'quote_rejected'        => 'Cotización rechazada',
        
        // Facturas
        'invoice_created'       => 'Factura ingresada',
        'invoice_processed'     => 'Factura procesada',
        'invoice_approved'      => 'Factura aprobada',
        
        // Recepción
        'reception_registered'  => 'Recepción registrada',
        'reception_approved'    => 'Recepción aprobada',
        'reception_discrepancy' => 'Discrepancia en recepción',
        
        // Bodega
        'location_created'      => 'Ubicación creada',
        'location_updated'      => 'Ubicación actualizada',
        'location_deleted'      => 'Ubicación eliminada',
        'product_moved'         => 'Producto movido',
        
        // Tareas
        'task_created'          => 'Tarea creada',
        'task_assigned'         => 'Tarea asignada',
        'task_completed'        => 'Tarea completada',
        'task_approved'         => 'Tarea aprobada',
        
        // Proveedores
        'supplier_created'      => 'Proveedor creado',
        'supplier_updated'      => 'Proveedor actualizado',
        'supplier_deleted'      => 'Proveedor eliminado',
        
        // Ventas/POS
        'sale_created'          => 'Venta creada',
        'sale_voided'           => 'Venta anulada',
        'discount_applied'      => 'Descuento aplicado',
        
        // Cotizaciones a clientes
        'customer_quote_created'  => 'Cotización cliente creada',
        'customer_quote_approved' => 'Cotización cliente aprobada',
        'customer_quote_converted'=> 'Cotización convertida a pedido',
        
        // Sistema
        'user_login'            => 'Inicio de sesión',
        'user_logout'           => 'Cierre de sesión',
        'settings_changed'      => 'Configuración modificada',
        'role_assigned'         => 'Rol asignado',
    ];
    
    /**
     * Entidades auditables
     */
    const ENTITIES = [
        'product'       => 'Producto',
        'producto_base' => 'Producto base',
        'producto_proveedor' => 'Producto proveedor',
        'import'        => 'Importación',
        'precio'        => 'Precio',
        'tienda_local'  => 'Tienda local',
        'quote'         => 'Cotización recibida',
        'customer_quote'=> 'Cotización cliente',
        'invoice'       => 'Factura',
        'reception'     => 'Recepción',
        'task'          => 'Tarea',
        'supplier'      => 'Proveedor',
        'location'      => 'Ubicación',
        'sale'          => 'Venta',
        'user'          => 'Usuario',
        'settings'      => 'Configuración',
    ];
    
    /**
     * Tabla de la base de datos
     */
    private static $table_name;

    const ACTOR_TYPES = ['human', 'computer', 'migration', 'import', 'api'];
    
    /**
     * Inicializa la clase
     */
    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'riverso_audit_log';
    }
    
    /**
     * Crea la tabla de auditoría
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'riverso_audit_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            entity_name VARCHAR(255) DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            user_name VARCHAR(100) DEFAULT NULL,
            user_role VARCHAR(50) DEFAULT NULL,
            actor_type VARCHAR(20) DEFAULT 'human',
            old_value LONGTEXT DEFAULT NULL,
            new_value LONGTEXT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action (action),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY entity_lookup (entity_type, entity_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Registra una acción en el log de auditoría
     *
     * @param string $action      Tipo de acción (ver ACTIONS)
     * @param string $entity_type Tipo de entidad (ver ENTITIES)
     * @param int    $entity_id   ID de la entidad
     * @param array  $data        Datos adicionales [old_value, new_value, details, entity_name]
     * @return int|false          ID del registro o false si falla
     */
    public static function log($action, $entity_type, $entity_id = null, $data = []) {
        global $wpdb;
        
        self::init();
        
        $actor_type = self::normalize_actor_type($data['actor_type'] ?? 'human');

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID ?: 0;

        if ($actor_type !== 'human') {
            $user_name = $actor_type === 'computer' ? 'computer' : $actor_type;
            $user_role = $actor_type;
        } else {
            $user_name = $current_user->display_name ?: 'Sistema';

            // Obtener rol Riverso del usuario
            $user_role = 'none';
            if ($user_id > 0 && class_exists('Riverso_POS_Permissions')) {
                $user_role = Riverso_POS_Permissions::get_riverso_role($user_id) ?: 'none';
            }
        }
        
        // Preparar valores
        $old_value = isset($data['old_value']) ? (is_array($data['old_value']) ? json_encode($data['old_value'], JSON_UNESCAPED_UNICODE) : $data['old_value']) : null;
        $new_value = isset($data['new_value']) ? (is_array($data['new_value']) ? json_encode($data['new_value'], JSON_UNESCAPED_UNICODE) : $data['new_value']) : null;
        
        $insert_data = [
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'entity_name' => isset($data['entity_name']) ? $data['entity_name'] : null,
            'user_id'     => $user_id,
            'user_name'   => $user_name,
            'user_role'   => $user_role,
            'actor_type'  => $actor_type,
            'old_value'   => $old_value,
            'new_value'   => $new_value,
            'details'     => isset($data['details']) ? $data['details'] : null,
            'ip_address'  => self::get_client_ip(),
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ];
        
        $result = $wpdb->insert(self::$table_name, $insert_data);
        
        if ($result === false) {
            error_log('Riverso Audit Error: ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }

    private static function normalize_actor_type($actor_type) {
        $actor_type = strtolower((string) $actor_type);
        if ($actor_type === 'user') {
            return 'human';
        }
        if ($actor_type === 'system') {
            return 'computer';
        }
        return in_array($actor_type, self::ACTOR_TYPES, true) ? $actor_type : 'human';
    }
    
    /**
     * Obtiene registros de auditoría con filtros
     *
     * @param array $filters  Filtros [action, entity_type, entity_id, user_id, date_from, date_to]
     * @param int   $page     Página actual
     * @param int   $per_page Registros por página
     * @return array          ['items' => [], 'total' => int, 'pages' => int]
     */
    public static function get_logs($filters = [], $page = 1, $per_page = 50) {
        global $wpdb;
        
        self::init();
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = %s';
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = %d';
            $params[] = $filters['entity_id'];
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(entity_name LIKE %s OR details LIKE %s OR user_name LIKE %s)';
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Contar total
        $count_sql = "SELECT COUNT(*) FROM " . self::$table_name . " WHERE $where_sql";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);
        
        // Obtener registros
        $offset = ($page - 1) * $per_page;
        $select_sql = "SELECT * FROM " . self::$table_name . " WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $items = $wpdb->get_results($wpdb->prepare($select_sql, $params));
        
        // Formatear items
        foreach ($items as &$item) {
            $item->action_label = isset(self::ACTIONS[$item->action]) ? self::ACTIONS[$item->action] : $item->action;
            $item->entity_label = isset(self::ENTITIES[$item->entity_type]) ? self::ENTITIES[$item->entity_type] : $item->entity_type;
            $item->old_value_decoded = $item->old_value ? json_decode($item->old_value, true) : null;
            $item->new_value_decoded = $item->new_value ? json_decode($item->new_value, true) : null;
        }
        
        return [
            'items' => $items,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'page'  => $page,
        ];
    }
    
    /**
     * Obtiene el historial de una entidad específica
     *
     * @param string $entity_type Tipo de entidad
     * @param int    $entity_id   ID de la entidad
     * @param int    $limit       Límite de registros
     * @return array
     */
    public static function get_entity_history($entity_type, $entity_id, $limit = 100) {
        global $wpdb;
        
        self::init();
        
        $sql = $wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " 
             WHERE entity_type = %s AND entity_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $entity_type,
            $entity_id,
            $limit
        );
        
        $items = $wpdb->get_results($sql);
        
        foreach ($items as &$item) {
            $item->action_label = isset(self::ACTIONS[$item->action]) ? self::ACTIONS[$item->action] : $item->action;
            $item->old_value_decoded = $item->old_value ? json_decode($item->old_value, true) : null;
            $item->new_value_decoded = $item->new_value ? json_decode($item->new_value, true) : null;
        }
        
        return $items;
    }
    
    /**
     * Obtiene estadísticas de auditoría
     *
     * @param int $days Días hacia atrás
     * @return array
     */
    public static function get_stats($days = 30) {
        global $wpdb;
        
        self::init();
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Total de acciones
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " WHERE created_at >= %s",
            $date_from
        ));
        
        // Por tipo de acción
        $by_action = $wpdb->get_results($wpdb->prepare(
            "SELECT action, COUNT(*) as count FROM " . self::$table_name . " 
             WHERE created_at >= %s GROUP BY action ORDER BY count DESC LIMIT 10",
            $date_from
        ));
        
        // Por usuario
        $by_user = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, user_name, COUNT(*) as count FROM " . self::$table_name . " 
             WHERE created_at >= %s AND user_id > 0 GROUP BY user_id, user_name ORDER BY count DESC LIMIT 10",
            $date_from
        ));
        
        // Por día
        $by_day = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as day, COUNT(*) as count FROM " . self::$table_name . " 
             WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day DESC",
            $date_from
        ));
        
        return [
            'total'     => (int) $total,
            'by_action' => $by_action,
            'by_user'   => $by_user,
            'by_day'    => $by_day,
            'days'      => $days,
        ];
    }
    
    /**
     * Limpia registros antiguos
     *
     * @param int $days Días a mantener
     * @return int Registros eliminados
     */
    public static function cleanup($days = 365) {
        global $wpdb;
        
        self::init();
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::$table_name . " WHERE created_at < %s",
            $date
        ));
    }
    
    /**
     * Obtiene la IP del cliente
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Si hay múltiples IPs, tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Helper: Log cambio de precio
     */
    public static function log_price_change($product_id, $old_price, $new_price, $product_name = null) {
        return self::log('price_changed', 'product', $product_id, [
            'entity_name' => $product_name,
            'old_value' => ['price' => $old_price],
            'new_value' => ['price' => $new_price],
            'details' => sprintf('Precio cambiado de $%s a $%s', number_format($old_price, 0, ',', '.'), number_format($new_price, 0, ',', '.')),
        ]);
    }
    
    /**
     * Helper: Log ajuste de stock
     */
    public static function log_stock_adjustment($product_id, $old_stock, $new_stock, $reason = '', $product_name = null) {
        $diff = $new_stock - $old_stock;
        $direction = $diff >= 0 ? '+' : '';
        
        return self::log('stock_adjusted', 'product', $product_id, [
            'entity_name' => $product_name,
            'old_value' => ['stock' => $old_stock],
            'new_value' => ['stock' => $new_stock],
            'details' => sprintf('Stock ajustado %s%d (de %d a %d). %s', $direction, $diff, $old_stock, $new_stock, $reason),
        ]);
    }
    
    /**
     * Helper: Log tarea
     */
    public static function log_task($action, $task_id, $task_title, $details = '') {
        return self::log($action, 'task', $task_id, [
            'entity_name' => $task_title,
            'details' => $details,
        ]);
    }

    /**
     * Helper: registra una acción ejecutada por el sistema (created_by=computer).
     *
     * Marca actor_type='system' y user_name='computer' para trazar acciones
     * automáticas (matching, importación, generación de precios/EAN13, etc.).
     *
     * @param string $action      Tipo de acción
     * @param string $entity_type Tipo de entidad
     * @param int    $entity_id   ID de la entidad
     * @param array  $data        Datos adicionales [old_value, new_value, details, entity_name]
     * @return int|false
     */
    public static function log_system($action, $entity_type, $entity_id = null, $data = []) {
        $data['actor_type'] = 'computer';
        return self::log($action, $entity_type, $entity_id, $data);
    }

    public static function log_import($action, $entity_type, $entity_id = null, $data = []) {
        $data['actor_type'] = 'import';
        return self::log($action, $entity_type, $entity_id, $data);
    }

    public static function log_migration($action, $entity_type, $entity_id = null, $data = []) {
        $data['actor_type'] = 'migration';
        return self::log($action, $entity_type, $entity_id, $data);
    }

    public static function log_api($action, $entity_type, $entity_id = null, $data = []) {
        $data['actor_type'] = 'api';
        return self::log($action, $entity_type, $entity_id, $data);
    }
}
