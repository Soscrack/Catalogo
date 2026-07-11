<?php
/**
 * Received Quotes Module - Cotizaciones Recibidas de Proveedores
 * 
 * Maneja documentos de cotización entrantes (PDF, Excel, texto, manual)
 * con flujo de estados, parsing y comparación de costos.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Received_Quote_Module {

    // Estados de cotización
    const ESTADOS = [
        'draft'                      => 'Borrador',
        'uploaded'                   => 'Subida',
        'parsed'                     => 'Parseada',
        'under_review'               => 'En Revisión',
        'approved'                   => 'Aprobada',
        'rejected'                   => 'Rechazada',
        'converted_to_expected'      => 'Convertida a Llegada',
        'archived'                   => 'Archivada'
    ];

    // Estados de match de ítems
    const MATCH_STATUS = [
        'pending'      => 'Pendiente',
        'matched'      => 'Vinculado',
        'not_found'    => 'No Encontrado',
        'ambiguous'    => 'Ambiguo',
        'manual'       => 'Manual'
    ];

    // Estados de decisión de ítems
    const DECISION_STATUS = [
        'pending'   => 'Pendiente',
        'accepted'  => 'Aceptado',
        'modified'  => 'Modificado',
        'rejected'  => 'Rechazado'
    ];

    // Tipos de fuente
    const SOURCE_TYPES = [
        'pdf'     => 'PDF',
        'excel'   => 'Excel',
        'text'    => 'Texto',
        'manual'  => 'Manual',
        'email'   => 'Email'
    ];

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_riverso_get_received_quotes', [$this, 'ajax_get_quotes']);
        add_action('wp_ajax_riverso_get_received_quote', [$this, 'ajax_get_quote']);
        add_action('wp_ajax_riverso_save_received_quote', [$this, 'ajax_save_quote']);
        add_action('wp_ajax_riverso_delete_received_quote', [$this, 'ajax_delete_quote']);
        add_action('wp_ajax_riverso_upload_quote_file', [$this, 'ajax_upload_file']);
        add_action('wp_ajax_riverso_parse_quote', [$this, 'ajax_parse_quote']);
        add_action('wp_ajax_riverso_save_quote_item', [$this, 'ajax_save_item']);
        add_action('wp_ajax_riverso_delete_quote_item', [$this, 'ajax_delete_item']);
        add_action('wp_ajax_riverso_match_quote_item', [$this, 'ajax_match_item']);
        add_action('wp_ajax_riverso_match_all_items', [$this, 'ajax_match_all_items']);
        add_action('wp_ajax_riverso_set_item_decision', [$this, 'ajax_set_item_decision']);
        add_action('wp_ajax_riverso_approve_received_quote', [$this, 'ajax_approve_quote']);
        add_action('wp_ajax_riverso_convert_quote_to_expected', [$this, 'ajax_convert_to_expected']);
        add_action('wp_ajax_riverso_get_quote_comparison', [$this, 'ajax_get_comparison']);
    }

    /**
     * Crear tablas del módulo
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';

        // Tabla principal de cotizaciones recibidas
        $sql_quotes = "CREATE TABLE IF NOT EXISTS {$prefix}cotizaciones_recibidas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            proveedor_id BIGINT UNSIGNED NULL,
            numero_documento VARCHAR(100) NULL,
            fecha_documento DATE NULL,
            fecha_recepcion DATETIME DEFAULT CURRENT_TIMESTAMP,
            tipo_fuente ENUM('pdf','excel','text','manual','email') DEFAULT 'manual',
            archivo_path VARCHAR(500) NULL,
            archivo_original VARCHAR(255) NULL,
            estado VARCHAR(50) DEFAULT 'draft',
            moneda VARCHAR(10) DEFAULT 'CLP',
            subtotal DECIMAL(15,2) DEFAULT 0,
            impuesto DECIMAL(15,2) DEFAULT 0,
            total DECIMAL(15,2) DEFAULT 0,
            notas TEXT NULL,
            datos_parseados LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_proveedor (proveedor_id),
            INDEX idx_estado (estado),
            INDEX idx_fecha (fecha_documento),
            INDEX idx_numero (numero_documento)
        ) $charset_collate;";

        // Tabla de ítems de cotización
        $sql_items = "CREATE TABLE IF NOT EXISTS {$prefix}cotizacion_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cotizacion_id BIGINT UNSIGNED NOT NULL,
            linea INT UNSIGNED DEFAULT 1,
            codigo_proveedor VARCHAR(100) NULL,
            codigo_barras VARCHAR(100) NULL,
            descripcion TEXT NULL,
            cantidad DECIMAL(15,4) DEFAULT 1,
            unidad VARCHAR(20) DEFAULT 'UN',
            costo_neto DECIMAL(15,4) DEFAULT 0,
            costo_impuesto DECIMAL(15,4) DEFAULT 0,
            costo_total DECIMAL(15,4) DEFAULT 0,
            producto_id BIGINT UNSIGNED NULL,
            variacion_id BIGINT UNSIGNED NULL,
            sku_match VARCHAR(100) NULL,
            match_status ENUM('pending','matched','not_found','ambiguous','manual') DEFAULT 'pending',
            match_confidence INT DEFAULT 0,
            decision_status ENUM('pending','accepted','modified','rejected') DEFAULT 'pending',
            decision_notas TEXT NULL,
            costo_anterior DECIMAL(15,4) NULL,
            diferencia_costo DECIMAL(15,4) NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cotizacion (cotizacion_id),
            INDEX idx_producto (producto_id),
            INDEX idx_match (match_status),
            INDEX idx_decision (decision_status),
            FOREIGN KEY (cotizacion_id) REFERENCES {$prefix}cotizaciones_recibidas(id) ON DELETE CASCADE
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_quotes);
        dbDelta($sql_items);

        return true;
    }

    /**
     * AJAX: Obtener lista de cotizaciones
     */
    public function ajax_get_quotes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $estado = isset($_POST['estado']) ? sanitize_text_field($_POST['estado']) : '';
        $proveedor_id = isset($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : 0;
        $buscar = isset($_POST['buscar']) ? sanitize_text_field($_POST['buscar']) : '';
        $fecha_desde = isset($_POST['fecha_desde']) ? sanitize_text_field($_POST['fecha_desde']) : '';
        $fecha_hasta = isset($_POST['fecha_hasta']) ? sanitize_text_field($_POST['fecha_hasta']) : '';

        $where = ["1=1"];
        $params = [];

        if ($estado) {
            $where[] = "c.estado = %s";
            $params[] = $estado;
        }

        if ($proveedor_id) {
            $where[] = "c.proveedor_id = %d";
            $params[] = $proveedor_id;
        }

        if ($buscar) {
            $where[] = "(c.numero_documento LIKE %s OR p.nombre LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($buscar) . '%';
            $params[] = '%' . $wpdb->esc_like($buscar) . '%';
        }

        if ($fecha_desde) {
            $where[] = "c.fecha_documento >= %s";
            $params[] = $fecha_desde;
        }

        if ($fecha_hasta) {
            $where[] = "c.fecha_documento <= %s";
            $params[] = $fecha_hasta;
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT c.*, 
                       p.nombre as proveedor_nombre,
                       p.rut as proveedor_rut,
                       u.display_name as creado_por_nombre,
                       (SELECT COUNT(*) FROM {$prefix}cotizacion_items WHERE cotizacion_id = c.id) as total_items,
                       (SELECT COUNT(*) FROM {$prefix}cotizacion_items WHERE cotizacion_id = c.id AND match_status = 'matched') as items_matched,
                       (SELECT COUNT(*) FROM {$prefix}cotizacion_items WHERE cotizacion_id = c.id AND decision_status = 'pending') as items_pending
                FROM {$prefix}cotizaciones_recibidas c
                LEFT JOIN {$prefix}proveedores p ON c.proveedor_id = p.id
                LEFT JOIN {$wpdb->users} u ON c.created_by = u.ID
                WHERE $where_sql
                ORDER BY c.created_at DESC
                LIMIT 100";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $quotes = $wpdb->get_results($sql);

        // Estadísticas
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'draft' THEN 1 ELSE 0 END) as borradores,
                SUM(CASE WHEN estado = 'under_review' THEN 1 ELSE 0 END) as en_revision,
                SUM(CASE WHEN estado = 'approved' THEN 1 ELSE 0 END) as aprobadas,
                SUM(CASE WHEN estado IN ('draft','uploaded','parsed','under_review') THEN 1 ELSE 0 END) as activas
            FROM {$prefix}cotizaciones_recibidas
        ");

        // Proveedores para filtro
        $proveedores = $wpdb->get_results("
            SELECT id, nombre FROM {$prefix}proveedores WHERE estado = 'activo' ORDER BY nombre
        ");

        wp_send_json_success([
            'quotes' => $quotes,
            'stats'  => $stats,
            'estados' => self::ESTADOS,
            'proveedores' => $proveedores
        ]);
    }

    /**
     * AJAX: Obtener cotización con ítems
     */
    public function ajax_get_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $quote = $wpdb->get_row($wpdb->prepare("
            SELECT c.*, 
                   p.nombre as proveedor_nombre,
                   p.rut as proveedor_rut,
                   p.email as proveedor_email
            FROM {$prefix}cotizaciones_recibidas c
            LEFT JOIN {$prefix}proveedores p ON c.proveedor_id = p.id
            WHERE c.id = %d
        ", $id));

        if (!$quote) {
            wp_send_json_error(['message' => 'Cotización no encontrada']);
        }

        // Obtener ítems con info de producto WooCommerce
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT i.*,
                   pm_sku.meta_value as woo_sku,
                   pm_price.meta_value as woo_price,
                   p.post_title as producto_nombre
            FROM {$prefix}cotizacion_items i
            LEFT JOIN {$wpdb->posts} p ON COALESCE(i.variacion_id, i.producto_id) = p.ID
            LEFT JOIN {$wpdb->postmeta} pm_sku ON COALESCE(i.variacion_id, i.producto_id) = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_price ON COALESCE(i.variacion_id, i.producto_id) = pm_price.post_id AND pm_price.meta_key = '_regular_price'
            WHERE i.cotizacion_id = %d
            ORDER BY i.linea ASC
        ", $id));

        // Calcular diferencias de costo
        foreach ($items as &$item) {
            if ($item->costo_anterior && $item->costo_neto) {
                $item->diferencia_porcentaje = round(
                    (($item->costo_neto - $item->costo_anterior) / $item->costo_anterior) * 100, 
                    2
                );
            }
        }

        // Proveedores para selector
        $proveedores = $wpdb->get_results("
            SELECT id, nombre, rut FROM {$prefix}proveedores WHERE estado = 'activo' ORDER BY nombre
        ");

        wp_send_json_success([
            'quote' => $quote,
            'items' => $items,
            'proveedores' => $proveedores,
            'estados' => self::ESTADOS,
            'match_status' => self::MATCH_STATUS,
            'decision_status' => self::DECISION_STATUS
        ]);
    }

    /**
     * AJAX: Guardar cotización
     */
    public function ajax_save_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $data = [
            'proveedor_id'     => isset($_POST['proveedor_id']) && $_POST['proveedor_id'] ? intval($_POST['proveedor_id']) : null,
            'numero_documento' => isset($_POST['numero_documento']) ? sanitize_text_field($_POST['numero_documento']) : null,
            'fecha_documento'  => isset($_POST['fecha_documento']) && $_POST['fecha_documento'] ? sanitize_text_field($_POST['fecha_documento']) : null,
            'tipo_fuente'      => isset($_POST['tipo_fuente']) ? sanitize_text_field($_POST['tipo_fuente']) : 'manual',
            'moneda'           => isset($_POST['moneda']) ? sanitize_text_field($_POST['moneda']) : 'CLP',
            'notas'            => isset($_POST['notas']) ? sanitize_textarea_field($_POST['notas']) : null,
            'updated_by'       => get_current_user_id()
        ];

        // Estado solo si viene y es válido
        if (isset($_POST['estado']) && array_key_exists($_POST['estado'], self::ESTADOS)) {
            $data['estado'] = $_POST['estado'];
        }

        if ($id) {
            $wpdb->update("{$prefix}cotizaciones_recibidas", $data, ['id' => $id]);
            $action = 'received_quote.updated';
        } else {
            $data['created_by'] = get_current_user_id();
            $data['estado'] = 'draft';
            $wpdb->insert("{$prefix}cotizaciones_recibidas", $data);
            $id = $wpdb->insert_id;
            $action = 'received_quote.created';
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($action, 'received_quote', $id, $data);
        }

        wp_send_json_success([
            'id' => $id,
            'message' => $id ? 'Cotización guardada' : 'Cotización creada'
        ]);
    }

    /**
     * AJAX: Eliminar cotización
     */
    public function ajax_delete_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('delete_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Los ítems se eliminan por CASCADE
        $wpdb->delete("{$prefix}cotizaciones_recibidas", ['id' => $id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.deleted', 'received_quote', $id);
        }

        wp_send_json_success(['message' => 'Cotización eliminada']);
    }

    /**
     * AJAX: Subir archivo de cotización
     */
    public function ajax_upload_file() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo']);
        }

        $file = $_FILES['file'];
        $quote_id = isset($_POST['quote_id']) ? intval($_POST['quote_id']) : 0;

        // Validar tipo de archivo
        $allowed = ['pdf', 'xlsx', 'xls', 'csv', 'txt'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            wp_send_json_error(['message' => 'Tipo de archivo no permitido. Use: ' . implode(', ', $allowed)]);
        }

        // Crear directorio de uploads
        $upload_dir = wp_upload_dir();
        $quotes_dir = $upload_dir['basedir'] . '/riverso-quotes/' . date('Y/m');
        
        if (!file_exists($quotes_dir)) {
            wp_mkdir_p($quotes_dir);
        }

        // Nombre único
        $filename = sanitize_file_name($file['name']);
        $filename = wp_unique_filename($quotes_dir, $filename);
        $filepath = $quotes_dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            wp_send_json_error(['message' => 'Error al guardar archivo']);
        }

        // Determinar tipo de fuente
        $source_type = 'manual';
        if ($ext === 'pdf') $source_type = 'pdf';
        elseif (in_array($ext, ['xlsx', 'xls', 'csv'])) $source_type = 'excel';
        elseif ($ext === 'txt') $source_type = 'text';

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Si hay quote_id, actualizar
        if ($quote_id) {
            $wpdb->update("{$prefix}cotizaciones_recibidas", [
                'archivo_path'     => str_replace($upload_dir['basedir'], '', $filepath),
                'archivo_original' => $file['name'],
                'tipo_fuente'      => $source_type,
                'estado'           => 'uploaded',
                'updated_by'       => get_current_user_id()
            ], ['id' => $quote_id]);
        } else {
            // Crear nueva cotización
            $wpdb->insert("{$prefix}cotizaciones_recibidas", [
                'archivo_path'     => str_replace($upload_dir['basedir'], '', $filepath),
                'archivo_original' => $file['name'],
                'tipo_fuente'      => $source_type,
                'estado'           => 'uploaded',
                'created_by'       => get_current_user_id(),
                'updated_by'       => get_current_user_id()
            ]);
            $quote_id = $wpdb->insert_id;
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.file_uploaded', 'received_quote', $quote_id, [
                'filename' => $file['name'],
                'type' => $source_type
            ]);
        }

        wp_send_json_success([
            'id'          => $quote_id,
            'filepath'    => $filepath,
            'source_type' => $source_type,
            'message'     => 'Archivo subido correctamente'
        ]);
    }

    /**
     * AJAX: Parsear cotización (básico - extraer ítems)
     */
    public function ajax_parse_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));

        if (!$quote) {
            wp_send_json_error(['message' => 'Cotización no encontrada']);
        }

        // Por ahora, solo marcar como parseada y crear estructura vacía
        // La lógica de parsing real se puede agregar después
        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'estado' => 'parsed',
            'datos_parseados' => json_encode(['parsed_at' => current_time('mysql')]),
            'updated_by' => get_current_user_id()
        ], ['id' => $id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.parsed', 'received_quote', $id);
        }

        wp_send_json_success([
            'message' => 'Cotización lista para ingreso manual de ítems',
            'estado' => 'parsed'
        ]);
    }

    /**
     * AJAX: Guardar ítem de cotización
     */
    public function ajax_save_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $cotizacion_id = isset($_POST['cotizacion_id']) ? intval($_POST['cotizacion_id']) : 0;

        if (!$cotizacion_id) {
            wp_send_json_error(['message' => 'cotizacion_id requerido']);
        }

        // Obtener siguiente número de línea si es nuevo
        $linea = isset($_POST['linea']) ? intval($_POST['linea']) : 0;
        if (!$linea && !$id) {
            $linea = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(linea), 0) + 1 FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d",
                $cotizacion_id
            ));
        }

        $data = [
            'cotizacion_id'    => $cotizacion_id,
            'linea'            => $linea ?: 1,
            'codigo_proveedor' => isset($_POST['codigo_proveedor']) ? sanitize_text_field($_POST['codigo_proveedor']) : null,
            'codigo_barras'    => isset($_POST['codigo_barras']) ? sanitize_text_field($_POST['codigo_barras']) : null,
            'descripcion'      => isset($_POST['descripcion']) ? sanitize_textarea_field($_POST['descripcion']) : null,
            'cantidad'         => isset($_POST['cantidad']) ? floatval($_POST['cantidad']) : 1,
            'unidad'           => isset($_POST['unidad']) ? sanitize_text_field($_POST['unidad']) : 'UN',
            'costo_neto'       => isset($_POST['costo_neto']) ? floatval($_POST['costo_neto']) : 0,
            'costo_impuesto'   => isset($_POST['costo_impuesto']) ? floatval($_POST['costo_impuesto']) : 0,
            'costo_total'      => isset($_POST['costo_total']) ? floatval($_POST['costo_total']) : 0,
            'updated_by'       => get_current_user_id()
        ];

        // Calcular total si no viene
        if (!$data['costo_total'] && $data['costo_neto']) {
            $data['costo_total'] = $data['costo_neto'] + $data['costo_impuesto'];
        }

        if ($id) {
            $wpdb->update("{$prefix}cotizacion_items", $data, ['id' => $id]);
        } else {
            $data['created_by'] = get_current_user_id();
            $wpdb->insert("{$prefix}cotizacion_items", $data);
            $id = $wpdb->insert_id;
        }

        // Recalcular totales de cotización
        $this->recalculate_quote_totals($cotizacion_id);

        // Marcar cotización en revisión si está en draft/parsed
        $wpdb->query($wpdb->prepare("
            UPDATE {$prefix}cotizaciones_recibidas 
            SET estado = 'under_review' 
            WHERE id = %d AND estado IN ('draft', 'parsed', 'uploaded')
        ", $cotizacion_id));

        wp_send_json_success([
            'id' => $id,
            'message' => 'Ítem guardado'
        ]);
    }

    /**
     * AJAX: Eliminar ítem
     */
    public function ajax_delete_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        if (!$item_id) {
            wp_send_json_error(['message' => 'item_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT cotizacion_id FROM {$prefix}cotizacion_items WHERE id = %d",
            $item_id
        ));

        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }

        $wpdb->delete("{$prefix}cotizacion_items", ['id' => $item_id]);
        $this->recalculate_quote_totals($item->cotizacion_id);

        wp_send_json_success(['message' => 'Ítem eliminado']);
    }

    /**
     * AJAX: Buscar match para ítem
     */
    public function ajax_match_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        if (!$item_id) {
            wp_send_json_error(['message' => 'item_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizacion_items WHERE id = %d",
            $item_id
        ));

        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }

        $result = $this->find_product_match($item);

        // Actualizar ítem con resultado
        $update_data = [
            'match_status'     => $result['status'],
            'match_confidence' => $result['confidence'],
            'updated_by'       => get_current_user_id()
        ];

        if ($result['status'] === 'matched' && !empty($result['matches'])) {
            $best = $result['matches'][0];
            $update_data['producto_id'] = $best->product_id;
            $update_data['variacion_id'] = $best->variation_id ?: null;
            $update_data['sku_match'] = $best->sku;
            $update_data['costo_anterior'] = $best->purchase_price ?: null;
            
            if ($best->purchase_price) {
                $update_data['diferencia_costo'] = $item->costo_neto - floatval($best->purchase_price);
            }
        }

        $wpdb->update("{$prefix}cotizacion_items", $update_data, ['id' => $item_id]);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Match automático de todos los ítems pendientes
     */
    public function ajax_match_all_items() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $quote_id = isset($_POST['quote_id']) ? intval($_POST['quote_id']) : 0;
        if (!$quote_id) {
            wp_send_json_error(['message' => 'quote_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d AND match_status = 'pending'",
            $quote_id
        ));

        $results = [
            'processed' => 0,
            'matched' => 0,
            'not_found' => 0,
            'ambiguous' => 0
        ];

        foreach ($items as $item) {
            $result = $this->find_product_match($item);
            $results['processed']++;

            $update_data = [
                'match_status'     => $result['status'],
                'match_confidence' => $result['confidence'],
                'updated_by'       => get_current_user_id()
            ];

            if ($result['status'] === 'matched' && !empty($result['matches'])) {
                $best = $result['matches'][0];
                $update_data['producto_id'] = $best->product_id;
                $update_data['variacion_id'] = $best->variation_id ?: null;
                $update_data['sku_match'] = $best->sku;
                $update_data['costo_anterior'] = $best->purchase_price ?: null;
                
                if ($best->purchase_price) {
                    $update_data['diferencia_costo'] = $item->costo_neto - floatval($best->purchase_price);
                }
                $results['matched']++;
            } else {
                $results[$result['status']]++;
            }

            $wpdb->update("{$prefix}cotizacion_items", $update_data, ['id' => $item->id]);
        }

        wp_send_json_success([
            'message' => "Procesados: {$results['processed']}, Vinculados: {$results['matched']}, No encontrados: {$results['not_found']}",
            'results' => $results
        ]);
    }

    /**
     * Buscar producto que coincida con ítem
     */
    private function find_product_match($item) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $matches = [];
        $match_status = 'not_found';
        $confidence = 0;

        // 1. Buscar por código de barras
        if ($item->codigo_barras) {
            $barcode_match = $wpdb->get_row($wpdb->prepare("
                SELECT c.product_id, c.variation_id, c.codigo,
                       p.post_title as nombre,
                       pm_sku.meta_value as sku,
                       pm_cost.meta_value as purchase_price
                FROM {$prefix}codigos c
                JOIN {$wpdb->posts} p ON COALESCE(c.variation_id, c.product_id) = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_sku ON COALESCE(c.variation_id, c.product_id) = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_cost ON COALESCE(c.variation_id, c.product_id) = pm_cost.post_id AND pm_cost.meta_key = '_purchase_price'
                WHERE c.codigo = %s
                LIMIT 1
            ", $item->codigo_barras));

            if ($barcode_match) {
                $matches[] = $barcode_match;
                $match_status = 'matched';
                $confidence = 100;
            }
        }

        // 2. Buscar por código proveedor en enlaces existentes
        if (empty($matches) && $item->codigo_proveedor) {
            $code_match = $wpdb->get_row($wpdb->prepare("
                SELECT p.ID as product_id, 0 as variation_id,
                       p.post_title as nombre,
                       pm_sku.meta_value as sku,
                       pm_cost.meta_value as purchase_price
                FROM {$wpdb->postmeta} pm_supplier
                JOIN {$wpdb->posts} p ON pm_supplier.post_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_cost ON p.ID = pm_cost.post_id AND pm_cost.meta_key = '_purchase_price'
                WHERE pm_supplier.meta_key = '_supplier_sku' 
                AND pm_supplier.meta_value = %s
                AND p.post_status = 'publish'
                LIMIT 1
            ", $item->codigo_proveedor));

            if ($code_match) {
                $matches[] = $code_match;
                $match_status = 'matched';
                $confidence = 90;
            }
        }

        // 3. Buscar por SKU exacto
        if (empty($matches) && $item->codigo_proveedor) {
            $sku_exact = $wpdb->get_row($wpdb->prepare("
                SELECT p.ID as product_id, 
                       CASE WHEN p.post_type = 'product_variation' THEN p.ID ELSE 0 END as variation_id,
                       p.post_title as nombre,
                       pm.meta_value as sku,
                       pm_cost.meta_value as purchase_price
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_cost ON p.ID = pm_cost.post_id AND pm_cost.meta_key = '_purchase_price'
                WHERE pm.meta_key = '_sku' 
                AND pm.meta_value = %s
                AND p.post_type IN ('product', 'product_variation')
                AND p.post_status = 'publish'
                LIMIT 1
            ", $item->codigo_proveedor));

            if ($sku_exact) {
                $matches[] = $sku_exact;
                $match_status = 'matched';
                $confidence = 95;
            }
        }

        // 4. Buscar por SKU similar
        if (empty($matches) && $item->codigo_proveedor) {
            $sku_matches = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID as product_id, 
                       CASE WHEN p.post_type = 'product_variation' THEN p.ID ELSE 0 END as variation_id,
                       p.post_title as nombre,
                       pm.meta_value as sku,
                       pm_cost.meta_value as purchase_price
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_cost ON p.ID = pm_cost.post_id AND pm_cost.meta_key = '_purchase_price'
                WHERE pm.meta_key = '_sku' 
                AND pm.meta_value LIKE %s
                AND p.post_type IN ('product', 'product_variation')
                AND p.post_status = 'publish'
                LIMIT 5
            ", '%' . $wpdb->esc_like($item->codigo_proveedor) . '%'));

            if ($sku_matches) {
                $matches = array_merge($matches, $sku_matches);
                $match_status = count($sku_matches) > 1 ? 'ambiguous' : 'matched';
                $confidence = 70;
            }
        }

        return [
            'matches'    => $matches,
            'status'     => $match_status,
            'confidence' => $confidence
        ];
    }

    /**
     * AJAX: Establecer decisión de ítem
     */
    public function ajax_set_item_decision() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $decision = isset($_POST['decision']) ? sanitize_text_field($_POST['decision']) : '';
        $notas = isset($_POST['notas']) ? sanitize_textarea_field($_POST['notas']) : '';

        if (!$item_id || !array_key_exists($decision, self::DECISION_STATUS)) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Si es vinculación manual
        $update_data = [
            'decision_status' => $decision,
            'decision_notas'  => $notas,
            'updated_by'      => get_current_user_id()
        ];

        // Si viene producto_id manual
        if (isset($_POST['producto_id']) && intval($_POST['producto_id'])) {
            $producto_id = intval($_POST['producto_id']);
            $update_data['producto_id'] = $producto_id;
            $update_data['match_status'] = 'manual';
            $update_data['match_confidence'] = 100;

            // Buscar costo anterior
            $costo_anterior = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_purchase_price'",
                $producto_id
            ));
            if ($costo_anterior) {
                $update_data['costo_anterior'] = $costo_anterior;
                $item = $wpdb->get_row($wpdb->prepare(
                    "SELECT costo_neto FROM {$prefix}cotizacion_items WHERE id = %d",
                    $item_id
                ));
                $update_data['diferencia_costo'] = $item->costo_neto - floatval($costo_anterior);
            }
        }

        $wpdb->update("{$prefix}cotizacion_items", $update_data, ['id' => $item_id]);

        wp_send_json_success(['message' => 'Decisión guardada']);
    }

    /**
     * AJAX: Aprobar cotización
     */
    public function ajax_approve_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permisos para aprobar']);
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Verificar que todos los ítems tengan decisión
        $pending = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$prefix}cotizacion_items 
            WHERE cotizacion_id = %d AND decision_status = 'pending'
        ", $id));

        if ($pending > 0) {
            wp_send_json_error([
                'message' => "Hay $pending ítem(s) sin decisión. Revise todos antes de aprobar."
            ]);
        }

        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'estado'      => 'approved',
            'approved_by' => get_current_user_id(),
            'approved_at' => current_time('mysql'),
            'updated_by'  => get_current_user_id()
        ], ['id' => $id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.approved', 'received_quote', $id);
        }

        wp_send_json_success(['message' => 'Cotización aprobada']);
    }

    /**
     * AJAX: Obtener comparación de costos
     */
    public function ajax_get_comparison() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Ítems con diferencia de costo
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT i.*, 
                   p.post_title as producto_nombre,
                   CASE 
                       WHEN i.costo_anterior > 0 THEN 
                           ROUND(((i.costo_neto - i.costo_anterior) / i.costo_anterior) * 100, 2)
                       ELSE NULL 
                   END as diferencia_porcentaje
            FROM {$prefix}cotizacion_items i
            LEFT JOIN {$wpdb->posts} p ON COALESCE(i.variacion_id, i.producto_id) = p.ID
            WHERE i.cotizacion_id = %d
            AND i.costo_anterior IS NOT NULL
            AND i.costo_anterior != i.costo_neto
            ORDER BY ABS(i.diferencia_costo) DESC
        ", $id));

        // Resumen
        $summary = [
            'total_items' => count($items),
            'aumentos' => 0,
            'disminuciones' => 0,
            'mayor_aumento' => null,
            'mayor_disminucion' => null
        ];

        foreach ($items as $item) {
            if ($item->diferencia_costo > 0) {
                $summary['aumentos']++;
                if (!$summary['mayor_aumento'] || $item->diferencia_porcentaje > $summary['mayor_aumento']) {
                    $summary['mayor_aumento'] = $item->diferencia_porcentaje;
                }
            } else {
                $summary['disminuciones']++;
                if (!$summary['mayor_disminucion'] || $item->diferencia_porcentaje < $summary['mayor_disminucion']) {
                    $summary['mayor_disminucion'] = $item->diferencia_porcentaje;
                }
            }
        }

        wp_send_json_success([
            'items' => $items,
            'summary' => $summary
        ]);
    }

    /**
     * AJAX: Convertir a llegada esperada
     */
    public function ajax_convert_to_expected() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));

        if ($quote->estado !== 'approved') {
            wp_send_json_error(['message' => 'La cotización debe estar aprobada']);
        }

        // Marcar como convertida
        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'estado' => 'converted_to_expected',
            'updated_by' => get_current_user_id()
        ], ['id' => $id]);

        // TODO: Crear registro de "llegada esperada" cuando exista ese módulo

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.converted', 'received_quote', $id);
        }

        wp_send_json_success(['message' => 'Cotización convertida a llegada esperada']);
    }

    /**
     * Recalcular totales de cotización
     */
    private function recalculate_quote_totals($quote_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $totals = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(costo_neto * cantidad) as subtotal,
                SUM(costo_impuesto * cantidad) as impuesto,
                SUM(costo_total * cantidad) as total
            FROM {$prefix}cotizacion_items
            WHERE cotizacion_id = %d
        ", $quote_id));

        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'subtotal'  => $totals->subtotal ?: 0,
            'impuesto'  => $totals->impuesto ?: 0,
            'total'     => $totals->total ?: 0
        ], ['id' => $quote_id]);
    }

    /**
     * Obtener cotización por ID
     */
    public function get_quote($id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));
    }

    /**
     * Obtener ítems de cotización
     */
    public function get_quote_items($quote_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d ORDER BY linea",
            $quote_id
        ));
    }
}
