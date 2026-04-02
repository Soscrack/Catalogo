<?php
/**
 * Manejador de peticiones AJAX
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Ajax {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Facturas
        add_action('wp_ajax_riverso_upload_invoice', [$this, 'upload_invoice']);
        add_action('wp_ajax_riverso_process_invoice', [$this, 'process_invoice']);
        add_action('wp_ajax_riverso_get_invoice', [$this, 'get_invoice']);
        
        // Códigos
        add_action('wp_ajax_riverso_link_code', [$this, 'link_code']);
        add_action('wp_ajax_riverso_search_products', [$this, 'search_products']);
        add_action('wp_ajax_riverso_verify_code', [$this, 'verify_code']);
        
        // Tareas
        add_action('wp_ajax_riverso_get_tasks', [$this, 'get_tasks']);
        add_action('wp_ajax_riverso_update_task', [$this, 'update_task']);
        add_action('wp_ajax_riverso_create_task', [$this, 'create_task']);
        
        // Dashboard
        add_action('wp_ajax_riverso_get_stats', [$this, 'get_stats']);
    }
    
    /**
     * Verifica nonce y permisos
     */
    private function verify_request($capability, $nonce_action = 'riverso_pos_nonce') {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido'], 403);
        }
        
        if (!current_user_can($capability)) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }
    
    /**
     * Sube una factura XML
     */
    public function upload_invoice() {
        $this->verify_request('riverso_process_invoices');
        
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo']);
        }
        
        $file = $_FILES['file'];
        
        // Validar tipo
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xml') {
            wp_send_json_error(['message' => 'Solo se permiten archivos XML']);
        }
        
        // Leer contenido
        $xml_content = file_get_contents($file['tmp_name']);
        
        // Parsear XML (simplificado - usar el parser Python para producción)
        $result = $this->parse_xml_invoice($xml_content);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Parsea un XML de factura DTE
     */
    private function parse_xml_invoice($xml_content) {
        try {
            $xml = new SimpleXMLElement($xml_content);
            $xml->registerXPathNamespace('sii', 'http://www.sii.cl/SiiDte');
            
            // Buscar documento
            $doc = $xml->xpath('//sii:Documento') ?: $xml->xpath('//Documento');
            if (empty($doc)) {
                return new WP_Error('parse_error', 'No se encontró el documento DTE');
            }
            
            $doc = $doc[0];
            
            // Extraer datos básicos
            $encabezado = $doc->Encabezado;
            $id_doc = $encabezado->IdDoc;
            $emisor = $encabezado->Emisor;
            $totales = $encabezado->Totales;
            
            $result = [
                'tipo_dte' => (int) $id_doc->TipoDTE,
                'folio' => (string) $id_doc->Folio,
                'fecha_emision' => (string) $id_doc->FchEmis,
                'emisor' => [
                    'rut' => (string) $emisor->RUTEmisor,
                    'razon_social' => (string) $emisor->RznSoc,
                ],
                'totales' => [
                    'neto' => (float) $totales->MntNeto,
                    'iva' => (float) $totales->IVA,
                    'total' => (float) $totales->MntTotal,
                ],
                'items' => []
            ];
            
            // Extraer items
            foreach ($doc->Detalle as $detalle) {
                $codigo = null;
                if (isset($detalle->CdgItem)) {
                    $codigo = (string) $detalle->CdgItem->VlrCodigo;
                }
                
                $result['items'][] = [
                    'linea' => (int) $detalle->NroLinDet,
                    'codigo' => $codigo,
                    'nombre' => (string) $detalle->NmbItem,
                    'cantidad' => (float) $detalle->QtyItem,
                    'precio' => (float) $detalle->PrcItem,
                    'monto' => (float) $detalle->MontoItem,
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return new WP_Error('parse_error', $e->getMessage());
        }
    }
    
    /**
     * Procesa una factura (guardar en BD)
     */
    public function process_invoice() {
        $this->verify_request('riverso_process_invoices');
        
        $data = json_decode(stripslashes($_POST['data']), true);
        
        if (empty($data)) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Verificar si ya existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}facturas WHERE tipo_dte = %d AND folio = %s AND rut_emisor = %s",
            $data['tipo_dte'],
            $data['folio'],
            $data['emisor']['rut']
        ));
        
        if ($exists) {
            wp_send_json_error(['message' => 'Esta factura ya fue procesada', 'id' => $exists]);
        }
        
        // Obtener o crear proveedor
        $proveedor_id = $this->get_or_create_proveedor($data['emisor']);
        
        // Insertar factura
        $wpdb->insert("{$prefix}facturas", [
            'tipo_dte' => $data['tipo_dte'],
            'folio' => $data['folio'],
            'proveedor_id' => $proveedor_id,
            'rut_emisor' => $data['emisor']['rut'],
            'razon_social_emisor' => $data['emisor']['razon_social'],
            'fecha_emision' => $data['fecha_emision'],
            'monto_neto' => $data['totales']['neto'],
            'monto_iva' => $data['totales']['iva'],
            'monto_total' => $data['totales']['total'],
            'items_total' => count($data['items']),
            'procesado_por' => get_current_user_id(),
            'procesado_at' => current_time('mysql'),
        ]);
        
        $factura_id = $wpdb->insert_id;
        
        // Insertar items
        $items_vinculados = 0;
        foreach ($data['items'] as $item) {
            // Buscar código existente
            $codigo_id = null;
            $product_id = null;
            
            if (!empty($item['codigo'])) {
                $codigo = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, product_id FROM {$prefix}codigos WHERE codigo_proveedor = %s AND proveedor_id = %d",
                    $item['codigo'],
                    $proveedor_id
                ));
                
                if ($codigo) {
                    $codigo_id = $codigo->id;
                    $product_id = $codigo->product_id;
                    $items_vinculados++;
                }
            }
            
            $wpdb->insert("{$prefix}factura_items", [
                'factura_id' => $factura_id,
                'numero_linea' => $item['linea'],
                'codigo_proveedor' => $item['codigo'],
                'nombre' => $item['nombre'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio'],
                'monto_total' => $item['monto'],
                'codigo_id' => $codigo_id,
                'product_id' => $product_id,
                'estado' => $codigo_id ? 'vinculado' : 'pendiente',
            ]);
        }
        
        // Actualizar contador
        $wpdb->update("{$prefix}facturas", 
            ['items_vinculados' => $items_vinculados],
            ['id' => $factura_id]
        );
        
        wp_send_json_success([
            'id' => $factura_id,
            'items_total' => count($data['items']),
            'items_vinculados' => $items_vinculados,
        ]);
    }
    
    /**
     * Obtiene o crea un proveedor
     */
    private function get_or_create_proveedor($emisor) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_proveedores';
        
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE rut = %s",
            $emisor['rut']
        ));
        
        if ($id) {
            return $id;
        }
        
        $wpdb->insert($table, [
            'rut' => $emisor['rut'],
            'razon_social' => $emisor['razon_social'],
        ]);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Vincula un código de proveedor con un producto
     */
    public function link_code() {
        $this->verify_request('riverso_manage_codes');
        
        $codigo_id = intval($_POST['codigo_id']);
        $product_id = intval($_POST['product_id']);
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_codigos';
        
        // Obtener datos anteriores para historial
        $anterior = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $codigo_id
        ));
        
        // Actualizar
        $wpdb->update($table, [
            'product_id' => $product_id,
            'sku_local' => $sku,
            'verificado' => 1,
            'verificado_por' => get_current_user_id(),
            'verificado_at' => current_time('mysql'),
        ], ['id' => $codigo_id]);
        
        // Registrar en historial
        $wpdb->insert($wpdb->prefix . 'riverso_codigos_historial', [
            'codigo_id' => $codigo_id,
            'accion' => 'verificar',
            'campo_modificado' => 'product_id',
            'valor_anterior' => $anterior->product_id,
            'valor_nuevo' => $product_id,
            'usuario_id' => get_current_user_id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        
        wp_send_json_success(['message' => 'Código vinculado correctamente']);
    }
    
    /**
     * Busca productos WooCommerce
     */
    public function search_products() {
        $this->verify_request('riverso_view_products');
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        if (strlen($search) < 2) {
            wp_send_json_success([]);
        }
        
        $products = wc_get_products([
            's' => $search,
            'limit' => 20,
            'status' => 'publish',
        ]);
        
        $results = [];
        foreach ($products as $product) {
            $results[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'type' => $product->get_type(),
            ];
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Obtiene estadísticas para el dashboard
     */
    public function get_stats() {
        $this->verify_request('riverso_view_products');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $stats = [
            'facturas_pendientes' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pendiente'"
            ),
            'codigos_sin_vincular' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}codigos WHERE verificado = 0 AND product_id IS NULL"
            ),
            'tareas_pendientes' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}tareas WHERE estado IN ('pendiente', 'en_progreso')"
            ),
            'tareas_urgentes' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}tareas WHERE estado = 'pendiente' AND prioridad = 'urgente'"
            ),
        ];
        
        wp_send_json_success($stats);
    }
    
    /**
     * Obtiene tareas
     */
    public function get_tasks() {
        $this->verify_request('riverso_view_tasks');
        
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_tareas';
        
        $estado = sanitize_text_field($_POST['estado'] ?? 'pendiente');
        $tipo = sanitize_text_field($_POST['tipo'] ?? '');
        $asignado = intval($_POST['asignado'] ?? 0);
        
        $where = ["estado = %s"];
        $params = [$estado];
        
        if ($tipo) {
            $where[] = "tipo = %s";
            $params[] = $tipo;
        }
        
        if ($asignado) {
            $where[] = "asignado_a = %d";
            $params[] = $asignado;
        }
        
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY 
                FIELD(prioridad, 'urgente', 'alta', 'normal', 'baja'), created_at DESC LIMIT 50";
        
        $tasks = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        wp_send_json_success($tasks);
    }
    
    /**
     * Actualiza una tarea
     */
    public function update_task() {
        $this->verify_request('riverso_complete_tasks');
        
        $task_id = intval($_POST['task_id']);
        $estado = sanitize_text_field($_POST['estado']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_tareas';
        
        $data = ['estado' => $estado];
        
        if ($estado === 'completada') {
            $data['completado_por'] = get_current_user_id();
            $data['completado_at'] = current_time('mysql');
        }
        
        $wpdb->update($table, $data, ['id' => $task_id]);
        
        wp_send_json_success(['message' => 'Tarea actualizada']);
    }
    
    /**
     * Crea una tarea
     */
    public function create_task() {
        $this->verify_request('riverso_create_tasks');
        
        $data = [
            'tipo' => sanitize_text_field($_POST['tipo']),
            'titulo' => sanitize_text_field($_POST['titulo']),
            'descripcion' => sanitize_textarea_field($_POST['descripcion'] ?? ''),
            'prioridad' => sanitize_text_field($_POST['prioridad'] ?? 'normal'),
            'asignado_a' => intval($_POST['asignado_a'] ?? 0) ?: null,
            'creado_por' => get_current_user_id(),
        ];
        
        if (!empty($_POST['fecha_limite'])) {
            $data['fecha_limite'] = sanitize_text_field($_POST['fecha_limite']);
        }
        
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'riverso_tareas', $data);
        
        wp_send_json_success([
            'id' => $wpdb->insert_id,
            'message' => 'Tarea creada'
        ]);
    }

    // ============ CÓDIGOS Y PROVEEDORES ============

    /**
     * Obtiene estadísticas de códigos
     */
    public function get_codes_stats() {
        $this->verify_request('riverso_view_invoices');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        wp_send_json_success([
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigos"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}factura_items WHERE estado = 'pendiente'"),
            'linked' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigos WHERE activo = 1"),
            'providers' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}proveedores WHERE activo = 1"),
        ]);
    }

    /**
     * Obtiene códigos pendientes de vincular
     */
    public function get_pending_codes() {
        $this->verify_request('riverso_view_invoices');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $items = $wpdb->get_results(
            "SELECT fi.*, f.folio, p.nombre as proveedor_nombre
             FROM {$prefix}factura_items fi
             JOIN {$prefix}facturas f ON fi.factura_id = f.id
             JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
             WHERE fi.estado = 'pendiente' AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''
             ORDER BY f.created_at DESC
             LIMIT 100",
            ARRAY_A
        );
        
        wp_send_json_success(['items' => $items]);
    }

    /**
     * Obtiene todos los códigos
     */
    public function get_all_codes() {
        $this->verify_request('riverso_manage_codes');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($_POST['proveedor_id'])) {
            $where[] = 'c.proveedor_id = %d';
            $params[] = intval($_POST['proveedor_id']);
        }
        
        if (!empty($_POST['search'])) {
            $where[] = '(c.codigo_proveedor LIKE %s OR c.sku_local LIKE %s)';
            $search = '%' . $wpdb->esc_like($_POST['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql = "SELECT c.*, p.nombre as proveedor_nombre
                FROM {$prefix}codigos c
                JOIN {$prefix}proveedores p ON c.proveedor_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.created_at DESC
                LIMIT 200";
        
        $codes = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        
        wp_send_json_success(['codes' => $codes]);
    }

    /**
     * Obtiene proveedores
     */
    public function get_providers() {
        $this->verify_request('riverso_view_invoices');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $providers = $wpdb->get_results(
            "SELECT p.*, (SELECT COUNT(*) FROM {$prefix}codigos WHERE proveedor_id = p.id) as codigos_count
             FROM {$prefix}proveedores p
             ORDER BY p.nombre",
            ARRAY_A
        );
        
        wp_send_json_success(['providers' => $providers]);
    }

    /**
     * Crea proveedor
     */
    public function create_provider() {
        $this->verify_request('riverso_manage_codes');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $rut = preg_replace('/[^0-9kK]/', '', $_POST['rut'] ?? '');
        
        if (empty($rut) || empty($_POST['nombre'])) {
            wp_send_json_error(['message' => 'RUT y nombre requeridos']);
        }
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ));
        
        if ($exists) {
            wp_send_json_error(['message' => 'Ya existe un proveedor con este RUT']);
        }
        
        $result = $wpdb->insert(
            "{$prefix}proveedores",
            [
                'rut' => $rut,
                'nombre' => sanitize_text_field($_POST['nombre']),
                'giro' => sanitize_text_field($_POST['giro'] ?? ''),
                'contacto' => sanitize_text_field($_POST['contacto'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'telefono' => sanitize_text_field($_POST['telefono'] ?? ''),
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
        
        if (!$result) {
            wp_send_json_error(['message' => 'Error creando proveedor']);
        }
        
        wp_send_json_success(['message' => 'Proveedor creado', 'id' => $wpdb->insert_id]);
    }
}

// Inicializar
new Riverso_POS_Ajax();

// Registrar acciones adicionales
add_action('wp_ajax_riverso_get_codes_stats', [new Riverso_POS_Ajax(), 'get_codes_stats']);
add_action('wp_ajax_riverso_get_pending_codes', [new Riverso_POS_Ajax(), 'get_pending_codes']);
add_action('wp_ajax_riverso_get_all_codes', [new Riverso_POS_Ajax(), 'get_all_codes']);
add_action('wp_ajax_riverso_get_providers', [new Riverso_POS_Ajax(), 'get_providers']);
add_action('wp_ajax_riverso_create_provider', [new Riverso_POS_Ajax(), 'create_provider']);
