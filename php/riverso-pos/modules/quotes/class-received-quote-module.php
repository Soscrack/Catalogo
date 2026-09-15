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
        'pdf'      => 'PDF',
        'excel'    => 'Excel',
        'text'     => 'Texto',
        'manual'   => 'Manual',
        'email'    => 'Email',
        'whatsapp' => 'WhatsApp',
    ];

    const DOC_TYPES = [
        'cotizacion'          => 'Cotización',
        'posible_cotizacion'  => 'Posible cotización',
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
        add_action('wp_ajax_riverso_view_quote_document', [$this, 'ajax_view_quote_document']);
        add_action('wp_ajax_riverso_parse_quote', [$this, 'ajax_parse_quote']);
        add_action('wp_ajax_riverso_save_quote_item', [$this, 'ajax_save_item']);
        add_action('wp_ajax_riverso_delete_quote_item', [$this, 'ajax_delete_item']);
        add_action('wp_ajax_riverso_match_quote_item', [$this, 'ajax_match_item']);
        add_action('wp_ajax_riverso_match_all_items', [$this, 'ajax_match_all_items']);
        add_action('wp_ajax_riverso_set_item_decision', [$this, 'ajax_set_item_decision']);
        add_action('wp_ajax_riverso_approve_received_quote', [$this, 'ajax_approve_quote']);
        add_action('wp_ajax_riverso_reject_received_quote', [$this, 'ajax_reject_quote']);
        add_action('wp_ajax_riverso_set_received_quote_status', [$this, 'ajax_set_status']);
        add_action('wp_ajax_riverso_set_received_quote_tipo_doc', [$this, 'ajax_set_tipo_doc']);
        add_action('wp_ajax_riverso_confirm_received_quote_tipo', [$this, 'ajax_confirm_tipo_doc']);
        add_action('wp_ajax_riverso_parse_quote_text', [$this, 'ajax_parse_text']);
        add_action('wp_ajax_riverso_analyze_received_quote', [$this, 'ajax_analyze_quote']);
        add_action('wp_ajax_riverso_quote_claim_draft', [$this, 'ajax_claim_draft']);
        add_action('wp_ajax_riverso_convert_quote_to_expected', [$this, 'ajax_convert_to_expected']);
        add_action('wp_ajax_riverso_get_quote_comparison', [$this, 'ajax_get_comparison']);
        add_action('wp_ajax_riverso_search_quotes_for_version', [$this, 'ajax_search_quotes_for_version']);
        add_action('wp_ajax_riverso_link_quote_version', [$this, 'ajax_link_quote_version']);
        add_action('wp_ajax_riverso_unlink_quote_version', [$this, 'ajax_unlink_quote_version']);
        add_action('wp_ajax_riverso_compare_quote_versions', [$this, 'ajax_compare_quote_versions']);
        add_action('wp_ajax_riverso_reorder_quote_version', [$this, 'ajax_reorder_quote_version']);
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
            tipo_fuente ENUM('pdf','excel','text','manual','email','whatsapp') DEFAULT 'manual',
            archivo_path VARCHAR(500) NULL,
            archivo_original VARCHAR(255) NULL,
            archivo_hash CHAR(64) NULL,
            origen_mensaje_id BIGINT UNSIGNED NULL,
            origen_canal VARCHAR(20) NULL,
            tipo_doc VARCHAR(32) NOT NULL DEFAULT 'cotizacion',
            tipo_confirmado TINYINT(1) NOT NULL DEFAULT 1,
            estado VARCHAR(50) DEFAULT 'draft',
            moneda VARCHAR(10) DEFAULT 'CLP',
            subtotal DECIMAL(15,2) DEFAULT 0,
            impuesto DECIMAL(15,2) DEFAULT 0,
            tasa_iva DECIMAL(5,2) DEFAULT 19,
            descuento_pct DECIMAL(8,4) NULL,
            descuento_monto DECIMAL(15,4) NULL,
            condiciones_pago VARCHAR(255) NULL,
            fecha_validez DATE NULL,
            total DECIMAL(15,2) DEFAULT 0,
            notas TEXT NULL,
            datos_parseados LONGTEXT NULL,
            version_group_id BIGINT UNSIGNED NULL,
            version_n SMALLINT UNSIGNED NULL,
            version_orden VARCHAR(20) NOT NULL DEFAULT 'mensaje',
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_proveedor (proveedor_id),
            INDEX idx_estado (estado),
            INDEX idx_fecha (fecha_documento),
            INDEX idx_numero (numero_documento),
            INDEX idx_tipo_doc (tipo_doc, tipo_confirmado),
            INDEX idx_archivo_hash (archivo_hash),
            INDEX idx_version_group (version_group_id, version_n)
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
            precio_lista DECIMAL(15,4) NULL,
            descuento_pct DECIMAL(8,4) NULL,
            descuento_monto DECIMAL(15,4) NULL,
            tasa_iva DECIMAL(5,2) NULL,
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
        $this->ensure_version_columns();

        $estado = isset($_POST['estado']) ? sanitize_text_field($_POST['estado']) : '';
        $proveedor_id = isset($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : 0;
        $buscar = isset($_POST['buscar']) ? sanitize_text_field($_POST['buscar']) : '';
        $fecha_desde = isset($_POST['fecha_desde']) ? sanitize_text_field($_POST['fecha_desde']) : '';
        $fecha_hasta = isset($_POST['fecha_hasta']) ? sanitize_text_field($_POST['fecha_hasta']) : '';
        $tipo_fuente = isset($_POST['tipo_fuente']) ? sanitize_text_field($_POST['tipo_fuente']) : '';
        $tipo_doc = isset($_POST['tipo_doc']) ? sanitize_text_field(wp_unslash($_POST['tipo_doc'])) : 'cotizacion';

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

        if ($tipo_fuente && array_key_exists($tipo_fuente, self::SOURCE_TYPES)) {
            $where[] = "c.tipo_fuente = %s";
            $params[] = $tipo_fuente;
        }

        if ($tipo_doc === 'posible_cotizacion') {
            $where[] = "c.tipo_doc = %s";
            $params[] = 'posible_cotizacion';
        } elseif ($tipo_doc !== 'todo' && $tipo_doc !== '') {
            $where[] = "c.tipo_doc = %s";
            $params[] = 'cotizacion';
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

        $solo_final = !empty($_POST['solo_version_final']);
        $quotes = $this->decorate_version_meta($quotes ?: []);
        if ($solo_final) {
            $quotes = array_values(array_filter($quotes, static function ($q) {
                $q = (array) $q;
                $cnt = (int) ($q['version_count'] ?? 1);
                if ($cnt <= 1) {
                    return true;
                }
                return !empty($q['is_version_final']);
            }));
        }

        // Estadísticas
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'draft' THEN 1 ELSE 0 END) as borradores,
                SUM(CASE WHEN estado = 'under_review' THEN 1 ELSE 0 END) as en_revision,
                SUM(CASE WHEN estado = 'approved' THEN 1 ELSE 0 END) as aprobadas,
                SUM(CASE WHEN estado IN ('draft','uploaded','parsed','under_review') THEN 1 ELSE 0 END) as activas,
                SUM(CASE WHEN tipo_doc = 'posible_cotizacion' THEN 1 ELSE 0 END) as posibles,
                SUM(CASE WHEN tipo_confirmado = 0 THEN 1 ELSE 0 END) as por_confirmar
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
            'doc_types' => self::DOC_TYPES,
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

        $origen = null;
        $origen_mensaje_id = !empty($quote->origen_mensaje_id) ? (int) $quote->origen_mensaje_id : 0;
        if ($origen_mensaje_id > 0) {
            $msg = $wpdb->get_row($wpdb->prepare(
                "SELECT m.id, m.thread_id, m.subject, m.from_address, m.sent_at, m.direction,
                        t.canal, t.contacto_nombre, t.contacto_identificador
                 FROM {$prefix}messaging_messages m
                 LEFT JOIN {$prefix}messaging_threads t ON t.id = m.thread_id
                 WHERE m.id = %d",
                $origen_mensaje_id
            ), ARRAY_A);
            if ($msg) {
                $atts = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, filename, mime, size_bytes
                     FROM {$prefix}messaging_attachments WHERE message_id = %d ORDER BY id ASC",
                    $origen_mensaje_id
                ), ARRAY_A) ?: [];
                $inbox_admin = admin_url('admin.php?page=riverso-pos-inbox&thread=' . (int) $msg['thread_id']);
                $inbox_portal = home_url('/interno/inbox/?thread=' . (int) $msg['thread_id']);
                $origen = [
                    'mensaje_id' => (int) $msg['id'],
                    'thread_id' => (int) $msg['thread_id'],
                    'canal' => $msg['canal'] ?: ($quote->origen_canal ?: 'email'),
                    'subject' => $msg['subject'],
                    'from_address' => $msg['from_address'],
                    'sent_at' => $msg['sent_at'],
                    'contacto' => $msg['contacto_nombre'] ?: $msg['contacto_identificador'],
                    'inbox_url' => $inbox_admin,
                    'inbox_portal_url' => $inbox_portal,
                    'attachments' => $atts,
                ];
            }
        }

        // Proveedores para selector
        $proveedores = $wpdb->get_results("
            SELECT id, nombre, rut FROM {$prefix}proveedores WHERE estado = 'activo' ORDER BY nombre
        ");

        $siblings = $this->get_version_siblings($id);
        $quote_arr = (array) $quote;
        $decorated = $this->decorate_version_meta([$quote_arr]);
        $quote_meta = $decorated[0] ?? $quote_arr;
        foreach (['version_label', 'is_version_final', 'version_count'] as $k) {
            if (isset($quote_meta[$k])) {
                $quote->{$k} = $quote_meta[$k];
            }
        }

        wp_send_json_success([
            'quote' => $quote,
            'items' => $items,
            'origen' => $origen,
            'version_siblings' => $siblings,
            'proveedores' => $proveedores,
            'estados' => self::ESTADOS,
            'doc_types' => self::DOC_TYPES,
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
            'tipo_fuente'      => isset($_POST['tipo_fuente']) && array_key_exists($_POST['tipo_fuente'], self::SOURCE_TYPES)
                ? sanitize_text_field($_POST['tipo_fuente'])
                : 'manual',
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
            // Reenumerar si cambió la fecha y el grupo no es manual
            $gid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
                $id
            ));
            $orden = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT version_orden FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
                $id
            ));
            if ($gid > 0 && $orden !== 'manual') {
                $this->renumber_version_group($gid, $orden ?: 'mensaje');
            }
        } else {
            $data['created_by'] = get_current_user_id();
            $data['estado'] = 'draft';
            $data['tipo_doc'] = 'cotizacion';
            $data['tipo_confirmado'] = 1;
            $wpdb->insert("{$prefix}cotizaciones_recibidas", $data);
            $id = $wpdb->insert_id;
            $action = 'received_quote.created';
            if ($id) {
                $this->ensure_version_columns();
                $wpdb->update(
                    "{$prefix}cotizaciones_recibidas",
                    ['version_group_id' => (int) $id, 'version_n' => 1],
                    ['id' => (int) $id]
                );
            }
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

        $gid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));

        // Los ítems se eliminan por CASCADE
        $wpdb->delete("{$prefix}cotizaciones_recibidas", ['id' => $id]);

        if ($gid > 0 && $gid !== $id) {
            $left = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d",
                $gid
            ));
            if ($left === 1) {
                $only = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d LIMIT 1",
                    $gid
                ));
                if ($only) {
                    $wpdb->update(
                        "{$prefix}cotizaciones_recibidas",
                        ['version_group_id' => $only, 'version_n' => 1],
                        ['id' => $only]
                    );
                }
            } elseif ($left > 1) {
                $this->renumber_version_group($gid);
            }
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.deleted', 'received_quote', $id);
        }

        wp_send_json_success(['message' => 'Cotización eliminada']);
    }

    /**
     * AJAX: Subir archivo de cotización (sin Gemini; el parseo es una petición aparte).
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
        $replace = !empty($_POST['replace']);
        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            wp_send_json_error(['message' => 'Archivo temporal inválido']);
        }

        // Validar tipo de archivo (PDF/imagen como escaneos + Excel/CSV/TXT)
        $allowed = ['pdf', 'xlsx', 'xls', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            wp_send_json_error(['message' => 'Tipo de archivo no permitido. Use: ' . implode(', ', $allowed)]);
        }

        $hash = hash_file('sha256', $tmp);
        if (!$hash) {
            wp_send_json_error(['message' => 'No se pudo calcular el hash del archivo']);
        }

        $this->ensure_archivo_hash_column();

        // Documento ya registrado o adjunto a cotización existente → no Gemini
        $existing = $this->find_quote_by_file_hash($hash, $quote_id);
        if ($existing) {
            wp_send_json_success([
                'id'            => (int) $existing['id'],
                'quote_id'      => (int) $existing['id'],
                'duplicate'     => true,
                'reutilizado'   => true,
                'numero_documento' => $existing['numero_documento'] ?? null,
                'proveedor_nombre' => $existing['proveedor_nombre'] ?? null,
                'message'       => 'Este documento ya fue ingresado anteriormente (sin costo Gemini)',
            ]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Reemplazo solo con flag explícito; sin replace siempre INSERT (evita pisar cotización abierta).
        if ($quote_id > 0 && !$replace) {
            $quote_id = 0;
        }
        if ($quote_id > 0 && $replace) {
            $prev = $wpdb->get_row($wpdb->prepare(
                "SELECT id, archivo_hash FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
                $quote_id
            ), ARRAY_A);
            if (!$prev) {
                wp_send_json_error(['message' => 'Cotización no encontrada para reemplazar']);
            }
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

        if (!move_uploaded_file($tmp, $filepath)) {
            wp_send_json_error(['message' => 'Error al guardar archivo']);
        }

        // Determinar tipo de fuente
        $source_type = 'manual';
        if ($ext === 'pdf' || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'], true)) {
            $source_type = 'pdf';
        } elseif (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $source_type = 'excel';
        } elseif ($ext === 'txt') {
            $source_type = 'text';
        }

        $rel_path = str_replace($upload_dir['basedir'], '', $filepath);

        $row = [
            'archivo_path'     => $rel_path,
            'archivo_original' => $file['name'],
            'archivo_hash'     => $hash,
            'tipo_fuente'      => $source_type,
            'estado'           => 'uploaded',
            'updated_by'       => get_current_user_id(),
        ];

        $did_replace = false;
        if ($quote_id > 0 && $replace) {
            // Limpiar ítems previos para que el parseo posterior pueda reinsertar
            $wpdb->delete("{$prefix}cotizacion_items", ['cotizacion_id' => $quote_id]);
            $row['datos_parseados'] = null;
            $row['subtotal'] = 0;
            $row['impuesto'] = 0;
            $row['total'] = 0;
            $wpdb->update("{$prefix}cotizaciones_recibidas", $row, ['id' => $quote_id]);
            $did_replace = true;
        } else {
            // Crear nueva cotización
            $row['tipo_doc'] = 'cotizacion';
            $row['tipo_confirmado'] = 1;
            $row['created_by'] = get_current_user_id();
            $wpdb->insert("{$prefix}cotizaciones_recibidas", $row);
            $quote_id = (int) $wpdb->insert_id;
            if ($quote_id) {
                $this->auto_group_from_thread($quote_id);
            }
        }

        if (!$quote_id) {
            wp_send_json_error(['message' => 'No se pudo guardar la cotización']);
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.file_uploaded', 'received_quote', $quote_id, [
                'filename' => $file['name'],
                'type' => $source_type,
                'archivo_hash' => $hash,
                'replaced' => $did_replace,
            ]);
        }

        wp_send_json_success([
            'id'          => $quote_id,
            'quote_id'    => $quote_id,
            'filepath'    => $filepath,
            'source_type' => $source_type,
            'duplicate'   => false,
            'replaced'    => $did_replace,
            'needs_parse' => true,
            'message'     => $did_replace
                ? 'Archivo reemplazado. Procesando con Gemini…'
                : 'Archivo subido correctamente. Procesando con Gemini…',
        ]);
    }

    /**
     * AJAX: Abrir/descargar el archivo de una cotización (ingreso manual u otros).
     */
    public function ajax_view_quote_document() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('Sin permisos', 403);
        }

        $id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
        if (!$id) {
            wp_die('ID requerido', 400);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT id, archivo_path, archivo_original FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$quote || empty($quote['archivo_path'])) {
            wp_die('Documento no encontrado', 404);
        }

        $abs = $this->absolute_quote_path($quote['archivo_path']);
        if (!$abs || !is_file($abs) || !is_readable($abs)) {
            wp_die('Archivo no disponible en el servidor', 404);
        }

        $name = $quote['archivo_original'] ?: basename($abs);
        $mime = $this->mime_for_quote_file($abs, $name);

        $inline = in_array($mime, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/tiff',
            'text/plain',
        ], true);

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($abs));
        header(
            ($inline ? 'Content-Disposition: inline' : 'Content-Disposition: attachment')
            . '; filename="' . sanitize_file_name($name) . '"'
        );
        readfile($abs);
        exit;
    }

    /**
     * Asegura columna archivo_hash (idempotente, por si el activator aún no corrió).
     */
    private function ensure_archivo_hash_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_cotizaciones_recibidas';
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'archivo_hash'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN archivo_hash CHAR(64) NULL");
            $idx = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_archivo_hash'");
            if (empty($idx)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD KEY idx_archivo_hash (archivo_hash)");
            }
        }
    }

    /**
     * Busca cotización ya registrada con el mismo archivo (hash o bytes de path/adjunto).
     *
     * @param string $hash
     * @param int    $exclude_quote_id
     * @return array|null
     */
    private function find_quote_by_file_hash($hash, $exclude_quote_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $hash = strtolower(trim((string) $hash));
        if ($hash === '' || strlen($hash) !== 64) {
            return null;
        }

        $exclude_sql = $exclude_quote_id > 0 ? ' AND c.id <> %d' : '';
        $sql = "SELECT c.id, c.numero_documento, c.archivo_path, c.archivo_hash, c.estado,
                    p.nombre AS proveedor_nombre
             FROM {$prefix}cotizaciones_recibidas c
             LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.archivo_hash = %s
             {$exclude_sql}
             ORDER BY c.id ASC
             LIMIT 1";
        if ($exclude_quote_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare($sql, $hash, $exclude_quote_id), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare($sql, $hash), ARRAY_A);
        }

        if ($row) {
            return $row;
        }

        // Backfill: cotizaciones con archivo pero sin hash (manual / email / whatsapp)
        $candidates = $wpdb->get_results(
            "SELECT c.id, c.numero_documento, c.archivo_path, c.archivo_hash, c.estado,
                    c.origen_mensaje_id, p.nombre AS proveedor_nombre
             FROM {$prefix}cotizaciones_recibidas c
             LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.archivo_path IS NOT NULL AND c.archivo_path <> ''
               AND (c.archivo_hash IS NULL OR c.archivo_hash = '')
             ORDER BY c.id ASC
             LIMIT 200",
            ARRAY_A
        );

        if (is_array($candidates)) {
            foreach ($candidates as $cand) {
                if ($exclude_quote_id > 0 && (int) $cand['id'] === (int) $exclude_quote_id) {
                    continue;
                }
                $abs = $this->absolute_quote_path($cand['archivo_path'] ?? '');
                if (!$abs || !is_file($abs)) {
                    continue;
                }
                $cand_hash = hash_file('sha256', $abs);
                if (!$cand_hash) {
                    continue;
                }
                $wpdb->update(
                    "{$prefix}cotizaciones_recibidas",
                    ['archivo_hash' => $cand_hash],
                    ['id' => (int) $cand['id']]
                );
                if (strtolower($cand_hash) === $hash) {
                    $cand['archivo_hash'] = $cand_hash;
                    return $cand;
                }
            }
        }

        // Adjuntos de inbox ya ligados a una cotización
        $atts_table = $prefix . 'messaging_attachments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $atts_table)) === $atts_table) {
            $atts = $wpdb->get_results(
                "SELECT a.id, a.message_id, a.local_path, c.id AS quote_id, c.numero_documento,
                        p.nombre AS proveedor_nombre, c.estado
                 FROM {$atts_table} a
                 INNER JOIN {$prefix}cotizaciones_recibidas c ON c.origen_mensaje_id = a.message_id
                 LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
                 WHERE a.local_path IS NOT NULL AND a.local_path <> ''
                 ORDER BY c.id ASC
                 LIMIT 300",
                ARRAY_A
            );
            if (is_array($atts)) {
                foreach ($atts as $att) {
                    if ($exclude_quote_id > 0 && (int) $att['quote_id'] === (int) $exclude_quote_id) {
                        continue;
                    }
                    $abs = $this->absolute_quote_path($att['local_path'] ?? '');
                    if (!$abs || !is_file($abs)) {
                        continue;
                    }
                    $att_hash = hash_file('sha256', $abs);
                    if ($att_hash && strtolower($att_hash) === $hash) {
                        $wpdb->update(
                            "{$prefix}cotizaciones_recibidas",
                            ['archivo_hash' => $att_hash],
                            ['id' => (int) $att['quote_id']]
                        );
                        return [
                            'id' => (int) $att['quote_id'],
                            'numero_documento' => $att['numero_documento'] ?? null,
                            'proveedor_nombre' => $att['proveedor_nombre'] ?? null,
                            'estado' => $att['estado'] ?? null,
                            'archivo_hash' => $att_hash,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param string $path Relativo o absoluto bajo uploads
     * @return string
     */
    private function absolute_quote_path($path) {
        $path = (string) $path;
        if ($path === '') {
            return '';
        }
        if (is_file($path)) {
            return $path;
        }
        $upload = wp_upload_dir();
        $candidate = $upload['basedir'] . '/' . ltrim($path, '/');
        if (is_file($candidate)) {
            return $candidate;
        }
        // Windows / rutas con backslash
        $candidate2 = $upload['basedir'] . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return is_file($candidate2) ? $candidate2 : '';
    }

    /**
     * AJAX: Parsear cotización con Gemini.
     */
    public function ajax_parse_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        $result = $this->parse_quote_internal($id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_parse_text() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $text = isset($_POST['texto']) ? wp_unslash($_POST['texto']) : '';
        if (!$id || trim($text) === '') {
            wp_send_json_error(['message' => 'ID y texto requeridos']);
        }
        $result = $this->parse_quote_internal($id, $text);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * Crea cotización desde un mensaje de inbox y dispara parseo.
     *
     * @param array $args
     * @return int|WP_Error
     */
    public function create_from_message($args) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $canal = $args['canal'] ?? 'email';
        $tipo = $canal === 'whatsapp' ? 'whatsapp' : 'email';
        $path = $args['archivo_path'] ?? '';
        $original = $args['archivo_original'] ?? '';
        $rel = $path;
        $upload = wp_upload_dir();
        if ($path && strpos($path, $upload['basedir']) === 0) {
            $rel = str_replace($upload['basedir'], '', $path);
        }

        $mensaje_id = !empty($args['mensaje_id']) ? (int) $args['mensaje_id'] : 0;
        if ($mensaje_id > 0) {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}cotizaciones_recibidas WHERE origen_mensaje_id = %d LIMIT 1",
                $mensaje_id
            ));
            if ($existing > 0) {
                return $existing;
            }
        }

        $this->ensure_archivo_hash_column();
        $archivo_hash = null;
        if ($path) {
            $abs = $this->absolute_quote_path($path);
            if ($abs && is_file($abs)) {
                $archivo_hash = hash_file('sha256', $abs) ?: null;
            }
        }
        if ($archivo_hash) {
            $by_hash = $this->find_quote_by_file_hash($archivo_hash);
            if ($by_hash) {
                // Reutilizar cotización existente; no llamar Gemini de nuevo
                if ($mensaje_id > 0) {
                    $wpdb->update(
                        "{$prefix}cotizaciones_recibidas",
                        [
                            'origen_mensaje_id' => $mensaje_id,
                            'origen_canal' => $canal,
                            'updated_by' => get_current_user_id() ?: null,
                        ],
                        ['id' => (int) $by_hash['id']]
                    );
                }
                return (int) $by_hash['id'];
            }
        }

        $tipo_doc = isset($args['tipo_doc']) && array_key_exists($args['tipo_doc'], self::DOC_TYPES)
            ? $args['tipo_doc']
            : 'posible_cotizacion';
        $tipo_confirmado = !empty($args['tipo_confirmado']) ? 1 : 0;

        $insert = [
            'proveedor_id'     => !empty($args['proveedor_id']) ? (int) $args['proveedor_id'] : null,
            'numero_documento' => $args['numero_documento'] ?? null,
            'tipo_fuente'      => $tipo,
            'tipo_doc'         => $tipo_doc,
            'tipo_confirmado'  => $tipo_confirmado,
            'archivo_path'     => $rel ?: null,
            'archivo_original' => $original ?: null,
            'origen_mensaje_id'=> $mensaje_id ?: null,
            'origen_canal'     => $canal,
            'estado'           => $path ? 'uploaded' : 'draft',
            'created_by'       => get_current_user_id() ?: null,
            'updated_by'       => get_current_user_id() ?: null,
        ];
        if ($archivo_hash) {
            $insert['archivo_hash'] = $archivo_hash;
        }

        $wpdb->insert("{$prefix}cotizaciones_recibidas", $insert);
        $id = (int) $wpdb->insert_id;
        if (!$id) {
            return new WP_Error('quote_insert', 'No se pudo crear la cotización');
        }
        if ($path) {
            $this->parse_quote_internal($id);
        }
        $this->auto_group_from_thread($id);
        return $id;
    }

    /**
     * @param int         $id
     * @param string|null $text_override
     * @return array|WP_Error
     */
    public function parse_quote_internal($id, $text_override = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));
        if (!$quote) {
            return new WP_Error('not_found', 'Cotización no encontrada');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(200);
        }

        if (!class_exists('Riverso_Quote_Extractor')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/quotes/class-quote-extractor.php';
        }
        $extractor = new Riverso_Quote_Extractor();

        $abs = '';
        if (!empty($quote->archivo_path)) {
            $abs = $this->absolute_quote_path($quote->archivo_path);
        }

        $mime = $this->mime_for_quote_file(
            $abs,
            $quote->archivo_original ?: ($abs ? basename($abs) : '')
        );

        $parsed = $extractor->extract($abs && is_file($abs) ? $abs : '', $mime, $text_override);
        if (is_wp_error($parsed)) {
            $wpdb->update("{$prefix}cotizaciones_recibidas", [
                'estado' => 'uploaded',
                'datos_parseados' => wp_json_encode([
                    'error' => $parsed->get_error_message(),
                    'error_code' => $parsed->get_error_code(),
                    'parsed_at' => current_time('mysql'),
                ]),
                'updated_by' => get_current_user_id() ?: null,
            ], ['id' => $id]);
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('received_quote.parse_failed', 'received_quote', $id, [
                    'error' => $parsed->get_error_message(),
                    'error_code' => $parsed->get_error_code(),
                ]);
            }
            $this->apply_tipo_after_process($id);
            return $parsed;
        }

        $header = [
            'estado' => 'parsed',
            'datos_parseados' => wp_json_encode($parsed),
            'updated_by' => get_current_user_id() ?: null,
        ];
        if (!empty($parsed['folio'])) {
            $header['numero_documento'] = $parsed['folio'];
        }
        if (!empty($parsed['fecha_documento'])) {
            $header['fecha_documento'] = $parsed['fecha_documento'];
        }
        if (!empty($parsed['fecha_validez'])) {
            $header['fecha_validez'] = $parsed['fecha_validez'];
        }
        if (!empty($parsed['moneda'])) {
            $header['moneda'] = $parsed['moneda'];
        }
        if (isset($parsed['tasa_iva'])) {
            $header['tasa_iva'] = $parsed['tasa_iva'];
        }
        if (isset($parsed['descuento_pct'])) {
            $header['descuento_pct'] = $parsed['descuento_pct'];
        }
        if (isset($parsed['descuento_monto'])) {
            $header['descuento_monto'] = $parsed['descuento_monto'];
        }
        if (!empty($parsed['condiciones_pago'])) {
            $header['condiciones_pago'] = $parsed['condiciones_pago'];
        }
        if (!$quote->proveedor_id && !empty($parsed['proveedor_nombre'])) {
            $prov = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}proveedores WHERE nombre LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($parsed['proveedor_nombre']) . '%'
            ));
            if ($prov) {
                $header['proveedor_id'] = (int) $prov;
            }
        }

        $wpdb->update("{$prefix}cotizaciones_recibidas", $header, ['id' => $id]);

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d",
            $id
        ));
        if ($existing === 0) {
            foreach ($parsed['items'] as $item) {
                $wpdb->insert("{$prefix}cotizacion_items", [
                    'cotizacion_id'    => $id,
                    'linea'            => $item['linea'],
                    'codigo_proveedor' => $item['codigo_proveedor'] ?: null,
                    'descripcion'      => $item['descripcion'],
                    'cantidad'         => $item['cantidad'],
                    'unidad'           => $item['unidad'],
                    'precio_lista'     => $item['precio_lista'],
                    'descuento_pct'    => $item['descuento_pct'],
                    'descuento_monto'  => $item['descuento_monto'],
                    'tasa_iva'         => $item['tasa_iva'],
                    'costo_neto'       => $item['costo_neto'],
                    'costo_impuesto'   => $item['costo_impuesto'],
                    'costo_total'      => $item['costo_total'],
                    'created_by'       => get_current_user_id() ?: null,
                    'updated_by'       => get_current_user_id() ?: null,
                ]);
            }
        }

        $this->recalculate_quote_totals($id);
        $this->apply_tipo_after_process($id);
        $this->match_all_internal($id);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}cotizaciones_recibidas SET estado = 'under_review' WHERE id = %d AND estado = 'parsed'",
            $id
        ));

        $gid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));
        $orden = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT version_orden FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ));
        if ($gid > 0 && $orden !== 'manual') {
            $this->renumber_version_group($gid, $orden ?: 'mensaje');
        } elseif ($gid <= 0) {
            $this->auto_group_from_thread($id);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.parsed', 'received_quote', $id, [
                'items' => count($parsed['items']),
            ]);
        }

        return [
            'message' => 'Cotización parseada con Gemini',
            'estado' => 'under_review',
            'items' => count($parsed['items']),
            'confianza' => $parsed['confianza_global'] ?? null,
        ];
    }

    /**
     * MIME confiable para parseo/visualización (extensión gana sobre octet-stream).
     *
     * @param string $abs_path
     * @param string $original_name
     * @return string
     */
    private function mime_for_quote_file($abs_path, $original_name = '') {
        $name = $original_name !== '' ? $original_name : ($abs_path ? basename($abs_path) : '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if ($abs_path && is_file($abs_path) && function_exists('mime_content_type')) {
            $detected = mime_content_type($abs_path);
            if ($detected && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }
        return 'application/pdf';
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
            $this->apply_previous_cost($update_data, $item, $best);
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

        wp_send_json_success($this->match_all_internal($quote_id));
    }

    private function match_all_internal($quote_id) {
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
            'ambiguous' => 0,
            'message' => '',
        ];

        foreach ($items as $item) {
            $result = $this->find_product_match($item);
            $results['processed']++;

            $update_data = [
                'match_status'     => $result['status'],
                'match_confidence' => $result['confidence'],
                'updated_by'       => get_current_user_id() ?: null,
            ];

            if ($result['status'] === 'matched' && !empty($result['matches'])) {
                $best = $result['matches'][0];
                $update_data['producto_id'] = $best->product_id;
                $update_data['variacion_id'] = $best->variation_id ?: null;
                $update_data['sku_match'] = $best->sku;
                $this->apply_previous_cost($update_data, $item, $best);
                $results['matched']++;
            } else {
                $key = $result['status'];
                if (!isset($results[$key])) {
                    $results[$key] = 0;
                }
                $results[$key]++;
            }

            $wpdb->update("{$prefix}cotizacion_items", $update_data, ['id' => $item->id]);
        }

        $results['message'] = "Procesados: {$results['processed']}, Vinculados: {$results['matched']}";
        return $results;
    }

    private function apply_previous_cost(&$update_data, $item, $best = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT proveedor_id, fecha_documento FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $item->cotizacion_id
        ));
        $cost = null;
        if (class_exists('Riverso_Cost_Lookup_Service') === false) {
            $p = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($p)) {
                require_once $p;
            }
        }
        if (class_exists('Riverso_Cost_Lookup_Service') && $quote) {
            $svc = Riverso_Cost_Lookup_Service::get_instance();
            $code = $item->codigo_proveedor ?: '';
            $fecha = $quote->fecha_documento ?: current_time('Y-m-d');
            $prev = $svc->get_last_invoice_before_public((int) $quote->proveedor_id, $code, $fecha, 0)
                ?: $svc->get_last_approved_quote_before((int) $quote->proveedor_id, $code, $fecha);
            if ($prev && isset($prev['costo_unitario'])) {
                $cost = (float) $prev['costo_unitario'];
            }
        }
        if ($cost === null && $best && !empty($best->purchase_price)) {
            $cost = floatval($best->purchase_price);
        }
        if ($cost !== null) {
            $update_data['costo_anterior'] = $cost;
            $update_data['diferencia_costo'] = floatval($item->costo_neto) - $cost;
        }
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

        // 1b. Mapeo canónico (producto_proveedor / supplier links / codigos)
        if (empty($matches) && $item->codigo_proveedor && class_exists('Riverso_Supplier_Links_Module')) {
            $proveedor_id = 0;
            if (!empty($item->cotizacion_id)) {
                $proveedor_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT proveedor_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
                    (int) $item->cotizacion_id
                ));
            }
            $lookup = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code(
                $item->codigo_proveedor,
                $proveedor_id ?: null
            );
            if (!empty($lookup['found'])) {
                $sku = '';
                $product_id = 0;
                $variation_id = 0;
                $nombre = '';
                $domain = $lookup['domain'] ?? null;
                if (is_array($domain) && !empty($domain['canonical_sku'])) {
                    $sku = (string) $domain['canonical_sku'];
                    $product_id = absint($domain['woocommerce_product_id'] ?? 0);
                    $variation_id = absint($domain['woocommerce_variation_id'] ?? 0);
                    $nombre = (string) ($domain['nombre_canonico'] ?? '');
                }
                if ($sku === '' && !empty($lookup['product'])) {
                    $sku = (string) ($lookup['product']['sku'] ?? '');
                    $product_id = absint($lookup['product']['id'] ?? 0);
                    $nombre = (string) ($lookup['product']['name'] ?? '');
                }
                if ($sku === '' && !empty($lookup['legacy']['sku_local'])) {
                    $sku = (string) $lookup['legacy']['sku_local'];
                    $product_id = absint($lookup['legacy']['product_id'] ?? 0);
                    $variation_id = absint($lookup['legacy']['variation_id'] ?? 0);
                }
                if ($sku === '' && !empty($lookup['link']['internal_sku'])) {
                    $sku = (string) $lookup['link']['internal_sku'];
                    $product_id = absint($lookup['link']['product_id'] ?? 0);
                    $variation_id = absint($lookup['link']['variation_id'] ?? 0);
                }
                if ($sku !== '') {
                    if (!$product_id && function_exists('wc_get_product_id_by_sku')) {
                        $product_id = (int) wc_get_product_id_by_sku($sku);
                    }
                    $purchase_price = $product_id ? get_post_meta($product_id, '_purchase_price', true) : null;
                    $matches[] = (object) [
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'nombre' => $nombre ?: $sku,
                        'sku' => $sku,
                        'purchase_price' => $purchase_price,
                    ];
                    $match_status = 'matched';
                    $confidence = (!empty($lookup['source']) && $lookup['source'] === 'canonical_domain') ? 98 : 92;
                }
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

        if (!current_user_can('riverso_approve_received_quotes') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permisos para aprobar']);
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        $blocked = $this->block_if_not_confirmed_cotizacion($id);
        if ($blocked) {
            wp_send_json_error(['message' => $blocked]);
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
        $blocked = $this->block_if_not_confirmed_cotizacion($id);
        if ($blocked) {
            wp_send_json_error(['message' => $blocked]);
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

        $po = $this->create_purchase_order_from_quote($quote);
        if (is_wp_error($po)) {
            wp_send_json_error(['message' => $po->get_error_message()]);
        }

        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'estado' => 'converted_to_expected',
            'updated_by' => get_current_user_id()
        ], ['id' => $id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.converted', 'received_quote', $id, [
                'orden_compra_id' => $po['id'] ?? null,
            ]);
        }

        wp_send_json_success([
            'message' => 'Cotización convertida a OC ' . ($po['numero'] ?? ''),
            'orden_id' => $po['id'] ?? null,
            'numero' => $po['numero'] ?? null,
        ]);
    }

    private function create_purchase_order_from_quote($quote) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!class_exists('Riverso_Purchase_Order_Module')) {
            $f = RIVERSO_POS_PLUGIN_DIR . 'purchases/purchase_orders/class-purchase-order-module.php';
            if (file_exists($f)) {
                require_once $f;
            }
        }

        $numero = 'OC-' . gmdate('Ymd') . '-' . wp_generate_password(4, false, false);
        $inserted = $wpdb->insert("{$prefix}ordenes_compra", [
            'numero' => $numero,
            'proveedor_id' => $quote->proveedor_id ?: null,
            'cotizacion_id' => (int) $quote->id,
            'estado' => 'borrador',
            'fecha_emision' => current_time('Y-m-d'),
            'notas' => 'Desde cotización #' . $quote->id,
            'creado_por' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        if (!$inserted) {
            return new WP_Error('po_insert', 'No se pudo crear la orden de compra');
        }
        $orden_id = (int) $wpdb->insert_id;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d AND decision_status != 'rejected'",
            $quote->id
        ));
        foreach ($items as $item) {
            $wpdb->insert("{$prefix}ordenes_compra_items", [
                'orden_id' => $orden_id,
                'producto_base_id' => $item->producto_id ?: null,
                'descripcion' => $item->descripcion,
                'cantidad' => $item->cantidad,
                'unidad' => $item->unidad ?: 'unidad',
                'precio_unitario' => $item->costo_neto,
            ]);
        }
        return ['id' => $orden_id, 'numero' => $numero];
    }

    public function ajax_reject_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_approve_received_quotes') && !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $this->set_quote_status($id, 'rejected');
        wp_send_json_success(['message' => 'Cotización rechazada', 'estado' => 'rejected']);
    }

    public function ajax_set_status() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $estado = isset($_POST['estado']) ? sanitize_text_field($_POST['estado']) : '';
        if (!array_key_exists($estado, self::ESTADOS)) {
            wp_send_json_error(['message' => 'Estado inválido']);
        }
        $this->set_quote_status($id, $estado);
        wp_send_json_success(['message' => 'Estado actualizado', 'estado' => $estado]);
    }

    private function set_quote_status($id, $estado) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'estado' => $estado,
            'updated_by' => get_current_user_id(),
        ], ['id' => $id]);
    }

    public function ajax_analyze_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $blocked = $this->block_if_not_confirmed_cotizacion($id);
        if ($blocked) {
            wp_send_json_error(['message' => $blocked]);
        }
        $base = isset($_POST['compare_base']) ? sanitize_text_field(wp_unslash($_POST['compare_base'])) : 'auto';
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
        }
        $result = Riverso_Cost_Lookup_Service::get_instance()->analyze_quote($id, $base);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_claim_draft() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $blocked = $this->block_if_not_confirmed_cotizacion($id);
        if ($blocked) {
            wp_send_json_error(['message' => $blocked]);
        }
        $base = isset($_POST['compare_base']) ? sanitize_text_field(wp_unslash($_POST['compare_base'])) : 'auto';
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
        }
        $analysis = Riverso_Cost_Lookup_Service::get_instance()->analyze_quote($id, $base);
        if (is_wp_error($analysis)) {
            wp_send_json_error(['message' => $analysis->get_error_message()]);
        }
        if (!class_exists('Riverso_Quote_Extractor')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/quotes/class-quote-extractor.php';
        }
        $emails = (new Riverso_Quote_Extractor())->build_claim_emails($analysis);
        wp_send_json_success([
            'draft' => $emails['simple'],
            'subject' => $emails['subject'],
            'simple' => $emails['simple'],
            'complex' => $emails['complex'],
            'items' => $emails['items'],
            'analysis' => $analysis,
        ]);
    }

    public function ajax_set_tipo_doc() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $tipo = isset($_POST['tipo_doc']) ? sanitize_text_field(wp_unslash($_POST['tipo_doc'])) : '';
        if (!$id || !array_key_exists($tipo, self::DOC_TYPES)) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }
        $this->update_tipo_doc($id, $tipo, 1);
        wp_send_json_success([
            'message' => 'Tipo actualizado',
            'tipo_doc' => $tipo,
            'tipo_confirmado' => 1,
            'tipo_doc_label' => self::DOC_TYPES[$tipo],
        ]);
    }

    public function ajax_confirm_tipo_doc() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        $this->update_tipo_doc($id, 'cotizacion', 1);
        wp_send_json_success([
            'message' => 'Tipo confirmado como cotización',
            'tipo_doc' => 'cotizacion',
            'tipo_confirmado' => 1,
            'tipo_doc_label' => self::DOC_TYPES['cotizacion'],
        ]);
    }

    /**
     * @param int $id
     * @return string|null Mensaje de bloqueo o null si puede seguir.
     */
    private function block_if_not_confirmed_cotizacion($id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT tipo_doc, tipo_confirmado FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            (int) $id
        ), ARRAY_A);
        if (!$row) {
            return 'Documento no encontrado';
        }
        if (($row['tipo_doc'] ?? '') === 'posible_cotizacion' || (int) ($row['tipo_confirmado'] ?? 1) === 0) {
            return 'Confirme el tipo como cotización antes de continuar.';
        }
        return null;
    }

    /**
     * Tras parsear: total 0 → posible cotización por confirmar.
     */
    private function apply_tipo_after_process($id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT id, estado, total, tipo_doc, tipo_confirmado
             FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            (int) $id
        ));
        if (!$quote || in_array($quote->estado, ['approved', 'converted_to_expected'], true)) {
            return;
        }
        $total = (float) ($quote->total ?? 0);
        if ($total <= 0) {
            $this->update_tipo_doc((int) $id, 'posible_cotizacion', 0);
        }
    }

    private function update_tipo_doc($id, $tipo, $confirmado) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $wpdb->update("{$prefix}cotizaciones_recibidas", [
            'tipo_doc' => $tipo,
            'tipo_confirmado' => $confirmado ? 1 : 0,
            'updated_by' => get_current_user_id(),
        ], ['id' => (int) $id]);
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('received_quote.tipo_doc', 'received_quote', (int) $id, [
                'tipo_doc' => $tipo,
                'tipo_confirmado' => $confirmado ? 1 : 0,
            ]);
        }
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

    // ------------------------------------------------------------------
    // Versionamiento de cotizaciones
    // ------------------------------------------------------------------

    private function ensure_version_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_cotizaciones_recibidas';
        $cols = [
            'version_group_id' => 'BIGINT UNSIGNED NULL',
            'version_n' => 'SMALLINT UNSIGNED NULL',
            'version_orden' => "VARCHAR(20) NOT NULL DEFAULT 'mensaje'",
        ];
        foreach ($cols as $name => $def) {
            $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '{$name}'");
            if (empty($exists)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$name} {$def}");
            }
        }
        $idx = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_version_group'");
        if (empty($idx)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY idx_version_group (version_group_id, version_n)");
        }
    }

    /**
     * Decora filas con is_version_final y version_label.
     *
     * @param array|object $quotes
     * @return array
     */
    public function decorate_version_meta($quotes) {
        $this->ensure_version_columns();
        $rows = [];
        foreach ((array) $quotes as $q) {
            $rows[] = is_object($q) ? (array) $q : $q;
        }
        if (!$rows) {
            return $quotes;
        }
        $group_ids = [];
        foreach ($rows as $r) {
            $gid = (int) ($r['version_group_id'] ?? 0);
            if ($gid > 0) {
                $group_ids[$gid] = true;
            }
        }
        $max_by_group = [];
        if ($group_ids) {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $ids = array_map('intval', array_keys($group_ids));
            $in = implode(',', $ids);
            $max_rows = $wpdb->get_results(
                "SELECT version_group_id, MAX(version_n) AS max_n, COUNT(*) AS cnt
                 FROM {$prefix}cotizaciones_recibidas
                 WHERE version_group_id IN ({$in})
                 GROUP BY version_group_id",
                ARRAY_A
            ) ?: [];
            foreach ($max_rows as $mr) {
                $max_by_group[(int) $mr['version_group_id']] = [
                    'max_n' => (int) $mr['max_n'],
                    'cnt' => (int) $mr['cnt'],
                ];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $gid = (int) ($r['version_group_id'] ?? 0);
            $vn = isset($r['version_n']) ? (int) $r['version_n'] : 0;
            $meta = $gid > 0 ? ($max_by_group[$gid] ?? null) : null;
            $cnt = $meta ? (int) $meta['cnt'] : 1;
            $max_n = $meta ? (int) $meta['max_n'] : $vn;
            $is_final = $gid > 0 && $vn > 0 && $vn === $max_n;
            $r['version_count'] = $cnt;
            $r['is_version_final'] = $is_final && $cnt > 1;
            $r['version_label'] = '';
            if ($vn > 0 && $cnt > 1) {
                $r['version_label'] = 'v' . $vn . ($is_final ? ' (Versión final)' : '');
            } elseif ($vn > 0) {
                $r['version_label'] = 'v' . $vn;
            }
            $out[] = $r;
        }
        // Preserve object vs array based on first input
        $first = reset($quotes);
        if (is_object($first)) {
            return array_map(static function ($r) {
                return (object) $r;
            }, $out);
        }
        return $out;
    }

    /**
     * Hermanas de versión (mismo grupo), ordenadas por version_n.
     *
     * @param int $quote_id
     * @return array
     */
    public function get_version_siblings($quote_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $quote_id = (int) $quote_id;
        $gid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $quote_id
        ));
        if ($gid <= 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, numero_documento, fecha_documento, estado, version_group_id, version_n, total
                 FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
                $quote_id
            ), ARRAY_A);
            return $row ? [$row] : [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, numero_documento, fecha_documento, fecha_recepcion, estado, version_group_id, version_n, total, proveedor_id
             FROM {$prefix}cotizaciones_recibidas
             WHERE version_group_id = %d
             ORDER BY version_n ASC, id ASC",
            $gid
        ), ARRAY_A) ?: [];
        return $this->decorate_version_meta($rows);
    }

    /**
     * Reenumera un grupo según version_orden.
     * Default: cronología del mensaje del hilo (sent_at), no fecha_documento —
     * las fechas del PDF/correo suelen invertir v1/v2/v3 en negociaciones.
     *
     * @param int         $group_id
     * @param string|null $orden mensaje|fecha_recepcion|fecha_documento|manual
     * @return void
     */
    public function renumber_version_group($group_id, $orden = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $group_id = (int) $group_id;
        if ($group_id <= 0) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.fecha_documento, c.fecha_recepcion, c.created_at, c.version_n, c.version_orden,
                    c.origen_mensaje_id, m.sent_at AS mensaje_sent_at, m.id AS mensaje_id
             FROM {$prefix}cotizaciones_recibidas c
             LEFT JOIN {$prefix}messaging_messages m ON m.id = c.origen_mensaje_id
             WHERE c.version_group_id = %d",
            $group_id
        ), ARRAY_A) ?: [];
        if (!$rows) {
            return;
        }

        if ($orden === null) {
            $orden = (string) ($rows[0]['version_orden'] ?? 'mensaje');
            // Migrar criterio antiguo que invertía versiones por fechas de documento.
            if ($orden === 'fecha_documento') {
                $orden = 'mensaje';
            }
        }
        if (!in_array($orden, ['mensaje', 'fecha_recepcion', 'fecha_documento', 'manual'], true)) {
            $orden = 'mensaje';
        }

        if ($orden === 'manual') {
            usort($rows, static function ($a, $b) {
                $va = (int) ($a['version_n'] ?? 0);
                $vb = (int) ($b['version_n'] ?? 0);
                if ($va === $vb) {
                    return (int) $a['id'] <=> (int) $b['id'];
                }
                if ($va <= 0) {
                    return 1;
                }
                if ($vb <= 0) {
                    return -1;
                }
                return $va <=> $vb;
            });
        } else {
            usort($rows, static function ($a, $b) use ($orden) {
                $cmp_empty = static function ($da, $db) {
                    $a_empty = ($da === '' || $da === null);
                    $b_empty = ($db === '' || $db === null);
                    if ($a_empty && !$b_empty) {
                        return 1;
                    }
                    if (!$a_empty && $b_empty) {
                        return -1;
                    }
                    if ($da !== $db) {
                        return strcmp((string) $da, (string) $db);
                    }
                    return 0;
                };

                if ($orden === 'mensaje') {
                    $c = $cmp_empty($a['mensaje_sent_at'] ?? '', $b['mensaje_sent_at'] ?? '');
                    if ($c !== 0) {
                        return $c;
                    }
                    $ma = (int) ($a['mensaje_id'] ?? 0);
                    $mb = (int) ($b['mensaje_id'] ?? 0);
                    if ($ma !== $mb) {
                        // Sin mensaje: al final respecto a quien sí tiene origen.
                        if ($ma <= 0) {
                            return 1;
                        }
                        if ($mb <= 0) {
                            return -1;
                        }
                        return $ma <=> $mb;
                    }
                    return (int) $a['id'] <=> (int) $b['id'];
                }

                if ($orden === 'fecha_recepcion') {
                    $da = $a['fecha_recepcion'] ?: ($a['created_at'] ?? '');
                    $db = $b['fecha_recepcion'] ?: ($b['created_at'] ?? '');
                } else {
                    $da = $a['fecha_documento'] ?: '';
                    $db = $b['fecha_documento'] ?: '';
                }
                $c = $cmp_empty($da, $db);
                if ($c !== 0) {
                    return $c;
                }
                // Desempate: mensaje del hilo, luego id (no fecha_documento suelta).
                $c = $cmp_empty($a['mensaje_sent_at'] ?? '', $b['mensaje_sent_at'] ?? '');
                if ($c !== 0) {
                    return $c;
                }
                return (int) $a['id'] <=> (int) $b['id'];
            });
        }

        $n = 1;
        foreach ($rows as $row) {
            $wpdb->update(
                "{$prefix}cotizaciones_recibidas",
                [
                    'version_n' => $n,
                    'version_orden' => $orden,
                    'version_group_id' => $group_id,
                ],
                ['id' => (int) $row['id']]
            );
            $n++;
        }
    }

    /**
     * Une cotizaciones en un mismo grupo y reenumera.
     *
     * @param int[]  $quote_ids
     * @param string $orden
     * @return int|WP_Error group_id
     */
    public function link_quotes_as_versions(array $quote_ids, $orden = 'mensaje') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();

        $ids = array_values(array_unique(array_filter(array_map('intval', $quote_ids))));
        if (count($ids) < 2) {
            return new WP_Error('need_two', 'Se requieren al menos dos cotizaciones para vincular');
        }

        $in = implode(',', $ids);
        $rows = $wpdb->get_results(
            "SELECT id, version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id IN ({$in})",
            ARRAY_A
        ) ?: [];
        if (count($rows) < 2) {
            return new WP_Error('not_found', 'Cotizaciones no encontradas');
        }

        $group_id = 0;
        foreach ($rows as $r) {
            $gid = (int) ($r['version_group_id'] ?? 0);
            if ($gid > 0) {
                $group_id = $gid;
                break;
            }
        }
        if ($group_id <= 0) {
            $group_id = min($ids);
        }

        // Traer también miembros ya existentes del grupo destino
        $all_ids = $ids;
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d",
            $group_id
        )) ?: [];
        foreach ($existing as $eid) {
            $all_ids[] = (int) $eid;
        }
        // Si alguna tenía otro grupo, fusionar
        foreach ($rows as $r) {
            $gid = (int) ($r['version_group_id'] ?? 0);
            if ($gid > 0 && $gid !== $group_id) {
                $sibs = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d",
                    $gid
                )) ?: [];
                foreach ($sibs as $sid) {
                    $all_ids[] = (int) $sid;
                }
            }
        }
        $all_ids = array_values(array_unique($all_ids));

        foreach ($all_ids as $qid) {
            $wpdb->update(
                "{$prefix}cotizaciones_recibidas",
                [
                    'version_group_id' => $group_id,
                    'version_orden' => in_array($orden, ['mensaje', 'fecha_documento', 'fecha_recepcion', 'manual'], true)
                        ? $orden
                        : 'mensaje',
                ],
                ['id' => $qid]
            );
        }
        $this->renumber_version_group($group_id, $orden);
        return $group_id;
    }

    /**
     * Saca una cotización del grupo y reenumera el resto.
     *
     * @param int $quote_id
     * @return true|WP_Error
     */
    public function unlink_quote_version($quote_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $quote_id = (int) $quote_id;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, version_group_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $quote_id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Cotización no encontrada');
        }
        $gid = (int) ($row['version_group_id'] ?? 0);
        $wpdb->update(
            "{$prefix}cotizaciones_recibidas",
            [
                'version_group_id' => $quote_id,
                'version_n' => 1,
                'version_orden' => 'mensaje',
            ],
            ['id' => $quote_id]
        );
        if ($gid > 0 && $gid !== $quote_id) {
            $left = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d",
                $gid
            ));
            if ($left === 1) {
                $only = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}cotizaciones_recibidas WHERE version_group_id = %d LIMIT 1",
                    $gid
                ));
                if ($only > 0) {
                    $wpdb->update(
                        "{$prefix}cotizaciones_recibidas",
                        ['version_group_id' => $only, 'version_n' => 1],
                        ['id' => $only]
                    );
                }
            } elseif ($left > 1) {
                $this->renumber_version_group($gid);
            }
        }
        return true;
    }

    /**
     * Auto-agrupa con hermanas del mismo hilo de messaging.
     *
     * @param int $quote_id
     * @return void
     */
    public function auto_group_from_thread($quote_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $quote_id = (int) $quote_id;
        $msg_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT origen_mensaje_id FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $quote_id
        ));
        if ($msg_id <= 0) {
            // Cotización aislada: materializar v1
            $wpdb->update(
                "{$prefix}cotizaciones_recibidas",
                ['version_group_id' => $quote_id, 'version_n' => 1],
                ['id' => $quote_id]
            );
            return;
        }
        $thread_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM {$prefix}messaging_messages WHERE id = %d",
            $msg_id
        ));
        if ($thread_id <= 0) {
            $wpdb->update(
                "{$prefix}cotizaciones_recibidas",
                ['version_group_id' => $quote_id, 'version_n' => 1],
                ['id' => $quote_id]
            );
            return;
        }
        $sibling_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT c.id FROM {$prefix}cotizaciones_recibidas c
             INNER JOIN {$prefix}messaging_messages m ON m.id = c.origen_mensaje_id
             WHERE m.thread_id = %d
             ORDER BY c.id ASC",
            $thread_id
        )) ?: [];
        $sibling_ids = array_map('intval', $sibling_ids);
        if (count($sibling_ids) < 2) {
            $wpdb->update(
                "{$prefix}cotizaciones_recibidas",
                ['version_group_id' => $quote_id, 'version_n' => 1],
                ['id' => $quote_id]
            );
            return;
        }
        $this->link_quotes_as_versions($sibling_ids, 'mensaje');
    }

    /**
     * Backfill one-shot: hilos con N>1 cotizaciones.
     */
    public function backfill_versions_from_threads() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();

        $threads = $wpdb->get_col(
            "SELECT m.thread_id
             FROM {$prefix}cotizaciones_recibidas c
             INNER JOIN {$prefix}messaging_messages m ON m.id = c.origen_mensaje_id
             WHERE c.origen_mensaje_id IS NOT NULL
             GROUP BY m.thread_id
             HAVING COUNT(*) > 1"
        ) ?: [];

        foreach ($threads as $tid) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT c.id FROM {$prefix}cotizaciones_recibidas c
                 INNER JOIN {$prefix}messaging_messages m ON m.id = c.origen_mensaje_id
                 WHERE m.thread_id = %d
                 ORDER BY c.id ASC",
                (int) $tid
            )) ?: [];
            if (count($ids) > 1) {
                $this->link_quotes_as_versions($ids, 'mensaje');
            }
        }

        // Materializar v1 en cotizaciones sueltas sin grupo
        $wpdb->query(
            "UPDATE {$prefix}cotizaciones_recibidas
             SET version_group_id = id, version_n = 1
             WHERE version_group_id IS NULL OR version_group_id = 0"
        );
    }

    /**
     * Diff de ítems entre dos cotizaciones.
     *
     * @param int $id_a Versión anterior (base)
     * @param int $id_b Versión nueva
     * @return array|WP_Error
     */
    public function compare_quote_versions($id_a, $id_b) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id_a = (int) $id_a;
        $id_b = (int) $id_b;
        if ($id_a <= 0 || $id_b <= 0 || $id_a === $id_b) {
            return new WP_Error('invalid', 'Seleccione dos cotizaciones distintas');
        }

        $qa = $wpdb->get_row($wpdb->prepare(
            "SELECT id, numero_documento, fecha_documento, version_n, version_group_id, total
             FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id_a
        ), ARRAY_A);
        $qb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, numero_documento, fecha_documento, version_n, version_group_id, total
             FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id_b
        ), ARRAY_A);
        if (!$qa || !$qb) {
            return new WP_Error('not_found', 'Cotización no encontrada');
        }

        $items_a = $wpdb->get_results($wpdb->prepare(
            "SELECT id, linea, codigo_proveedor, descripcion, cantidad, precio_lista, costo_neto, costo_total
             FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d ORDER BY linea ASC",
            $id_a
        ), ARRAY_A) ?: [];
        $items_b = $wpdb->get_results($wpdb->prepare(
            "SELECT id, linea, codigo_proveedor, descripcion, cantidad, precio_lista, costo_neto, costo_total
             FROM {$prefix}cotizacion_items WHERE cotizacion_id = %d ORDER BY linea ASC",
            $id_b
        ), ARRAY_A) ?: [];

        $map_a = [];
        foreach ($items_a as $it) {
            $map_a[$this->version_item_key($it)] = $it;
        }
        $map_b = [];
        foreach ($items_b as $it) {
            $map_b[$this->version_item_key($it)] = $it;
        }

        $keys = array_unique(array_merge(array_keys($map_a), array_keys($map_b)));
        sort($keys);
        $rows = [];
        $stats = ['agregado' => 0, 'quitado' => 0, 'precio_cambio' => 0, 'igual' => 0];

        foreach ($keys as $key) {
            $a = $map_a[$key] ?? null;
            $b = $map_b[$key] ?? null;
            $cost_a = $a ? (float) ($a['costo_neto'] ?? 0) : null;
            $cost_b = $b ? (float) ($b['costo_neto'] ?? 0) : null;
            $lista_a = $a && $a['precio_lista'] !== null ? (float) $a['precio_lista'] : null;
            $lista_b = $b && $b['precio_lista'] !== null ? (float) $b['precio_lista'] : null;

            if ($a && !$b) {
                $status = 'quitado';
            } elseif (!$a && $b) {
                $status = 'agregado';
            } else {
                $delta = ($cost_b !== null && $cost_a !== null) ? ($cost_b - $cost_a) : 0;
                $delta_lista = ($lista_a !== null && $lista_b !== null) ? ($lista_b - $lista_a) : 0;
                if (abs($delta) >= 0.01 || abs($delta_lista) >= 0.01) {
                    $status = 'precio_cambio';
                } else {
                    $status = 'igual';
                }
            }
            $stats[$status]++;

            $rows[] = [
                'key' => $key,
                'status' => $status,
                'codigo' => $a['codigo_proveedor'] ?? ($b['codigo_proveedor'] ?? ''),
                'descripcion' => $a['descripcion'] ?? ($b['descripcion'] ?? ''),
                'cantidad_a' => $a ? (float) $a['cantidad'] : null,
                'cantidad_b' => $b ? (float) $b['cantidad'] : null,
                'costo_a' => $cost_a,
                'costo_b' => $cost_b,
                'precio_lista_a' => $lista_a,
                'precio_lista_b' => $lista_b,
                'delta_costo' => ($cost_a !== null && $cost_b !== null) ? round($cost_b - $cost_a, 4) : null,
            ];
        }

        return [
            'quote_a' => $qa,
            'quote_b' => $qb,
            'rows' => $rows,
            'stats' => $stats,
        ];
    }

    private function version_item_key(array $item) {
        $code = strtolower(trim((string) ($item['codigo_proveedor'] ?? '')));
        $code = str_replace(['-', '_', ' '], '', $code);
        if ($code !== '') {
            return 'c:' . $code;
        }
        $desc = strtolower(trim((string) ($item['descripcion'] ?? '')));
        $desc = preg_replace('/\s+/', ' ', $desc);
        return 'd:' . md5($desc);
    }

    public function ajax_search_quotes_for_version() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $exclude = isset($_POST['exclude_id']) ? intval($_POST['exclude_id']) : 0;
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $where = ['1=1'];
        $params = [];
        if ($exclude > 0) {
            $where[] = 'c.id <> %d';
            $params[] = $exclude;
        }
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where[] = '(c.id = %d OR c.numero_documento LIKE %s OR p.nombre LIKE %s)';
                $params[] = (int) $q;
                $params[] = '%' . $wpdb->esc_like($q) . '%';
                $params[] = '%' . $wpdb->esc_like($q) . '%';
            } else {
                $where[] = '(c.numero_documento LIKE %s OR p.nombre LIKE %s)';
                $params[] = '%' . $wpdb->esc_like($q) . '%';
                $params[] = '%' . $wpdb->esc_like($q) . '%';
            }
        }
        $sql = "SELECT c.id, c.numero_documento, c.fecha_documento, c.estado, c.version_group_id, c.version_n,
                       c.total, p.nombre AS proveedor_nombre
                FROM {$prefix}cotizaciones_recibidas c
                LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY c.id DESC LIMIT 30';
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        wp_send_json_success(['quotes' => $this->decorate_version_meta($rows ?: [])]);
    }

    public function ajax_link_quote_version() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $other = isset($_POST['other_id']) ? intval($_POST['other_id']) : 0;
        $orden = isset($_POST['orden']) ? sanitize_key(wp_unslash($_POST['orden'])) : 'mensaje';
        $result = $this->link_quotes_as_versions([$id, $other], $orden);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'message' => 'Versiones vinculadas',
            'version_group_id' => (int) $result,
            'siblings' => $this->get_version_siblings($id),
        ]);
    }

    public function ajax_unlink_quote_version() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $result = $this->unlink_quote_version($id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'message' => 'Cotización desvinculada del grupo de versiones',
            'siblings' => $this->get_version_siblings($id),
        ]);
    }

    public function ajax_compare_quote_versions() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id_a = isset($_POST['id_a']) ? intval($_POST['id_a']) : 0;
        $id_b = isset($_POST['id_b']) ? intval($_POST['id_b']) : 0;
        $result = $this->compare_quote_versions($id_a, $id_b);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_reorder_quote_version() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_version_columns();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $direction = isset($_POST['direction']) ? sanitize_key(wp_unslash($_POST['direction'])) : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, version_group_id, version_n FROM {$prefix}cotizaciones_recibidas WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row || empty($row['version_group_id'])) {
            wp_send_json_error(['message' => 'Sin grupo de versiones']);
        }
        $gid = (int) $row['version_group_id'];
        $vn = (int) $row['version_n'];
        $swap_n = $direction === 'up' ? $vn - 1 : $vn + 1;
        if ($swap_n < 1) {
            wp_send_json_error(['message' => 'Ya es la primera versión']);
        }
        $other = $wpdb->get_row($wpdb->prepare(
            "SELECT id, version_n FROM {$prefix}cotizaciones_recibidas
             WHERE version_group_id = %d AND version_n = %d",
            $gid,
            $swap_n
        ), ARRAY_A);
        if (!$other) {
            wp_send_json_error(['message' => 'No hay versión adyacente']);
        }
        $wpdb->update("{$prefix}cotizaciones_recibidas", ['version_orden' => 'manual', 'version_n' => $swap_n], ['id' => $id]);
        $wpdb->update("{$prefix}cotizaciones_recibidas", ['version_orden' => 'manual', 'version_n' => $vn], ['id' => (int) $other['id']]);
        // Asegurar modo manual en todo el grupo
        $wpdb->update(
            "{$prefix}cotizaciones_recibidas",
            ['version_orden' => 'manual'],
            ['version_group_id' => $gid]
        );
        wp_send_json_success([
            'message' => 'Orden actualizado',
            'siblings' => $this->get_version_siblings($id),
        ]);
    }
}
