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
        // Facturas: manejadas por class-invoice-module.php
        add_action('wp_ajax_riverso_process_invoice', [$this, 'process_invoice']);
        add_action('wp_ajax_riverso_search_products', [$this, 'search_products']);
        add_action('wp_ajax_riverso_verify_code', [$this, 'verify_code']);
        
        // Tareas - manejados por class-task-module.php
        // add_action('wp_ajax_riverso_get_tasks', [$this, 'get_tasks']);
        // add_action('wp_ajax_riverso_update_task', [$this, 'update_task']);
        // add_action('wp_ajax_riverso_create_task', [$this, 'create_task']);
        
        // Dashboard
        add_action('wp_ajax_riverso_get_stats', [$this, 'get_stats']);
    }
    
    /**
     * Verifica nonce y permisos
     */
    /**
     * @param string|array $capability Capability requerida, o lista de la que
     *                                 basta cumplir una.
     */
    private function verify_request($capability, $nonce_action = 'riverso_pos_nonce') {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido'], 403);
        }

        $allowed = false;
        foreach ((array) $capability as $cap) {
            if (current_user_can($cap)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
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
        
        if (strlen($search) < 1) {
            wp_send_json_success([]);
        }

        if (class_exists('Riverso_Barcode_Model') && method_exists('Riverso_Barcode_Model', 'lookup_for_search')) {
            $lookup = Riverso_Barcode_Model::lookup_for_search($search, ['limit' => 20]);
            $results = [];
            foreach ($lookup['hits'] as $hit) {
                $wc_id = intval($hit['wc_id'] ?? 0);
                $price = 0;
                $type = 'local';
                $name = $hit['nombre_canonico'] ?: ($hit['canonical_sku'] ?: '');
                $sku = $hit['canonical_sku'] ?? '';
                if ($wc_id && function_exists('wc_get_product')) {
                    $product = wc_get_product($wc_id);
                    if ($product) {
                        $price = floatval($product->get_price());
                        $type = $product->get_type();
                        $name = $product->get_name() ?: $name;
                        $sku = $product->get_sku() ?: $sku;
                    }
                }
                $results[] = [
                    'id' => $wc_id ?: intval($hit['producto_base_id']),
                    'name' => $name,
                    'sku' => $sku,
                    'price' => $price,
                    'type' => $type,
                    'producto_base_id' => intval($hit['producto_base_id']),
                ];
            }
            wp_send_json_success($results);
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
        $this->verify_request(['riverso_manage_codes', 'riverso_view_invoices']);
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        wp_send_json_success([
            'total' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor WHERE activo = 1"
            ),
            'pending' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}factura_items WHERE estado = 'pendiente'"
            ),
            'por_confirmar' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor pp
                 INNER JOIN {$prefix}producto_base pb
                   ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                 WHERE pp.activo = 1
                   AND pp.producto_base_id IS NOT NULL
                   AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
                   AND pp.requires_human_review = 1
                   AND COALESCE(pp.review_status, '') = 'pendiente'
                   AND pb.canonical_sku IS NOT NULL
                   AND pb.canonical_sku <> ''"
            ),
            'linked' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor
                 WHERE activo = 1 AND producto_base_id IS NOT NULL"
            ),
            'providers' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}proveedores WHERE activo = 1"
            ),
        ]);
    }

    /**
     * Obtiene códigos pendientes de vincular
     */
    public function get_pending_codes() {
        $this->verify_request(['riverso_manage_codes', 'riverso_view_invoices']);
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(200, max(10, absint($_POST['per_page'] ?? 50)));
        $offset = ($page - 1) * $per_page;
        
        $where = [
            "fi.estado = 'pendiente'",
            'fi.codigo_proveedor IS NOT NULL',
            "fi.codigo_proveedor != ''",
        ];
        $params = [];
        
        if (!empty($_POST['proveedor_id'])) {
            $where[] = 'f.proveedor_id = %d';
            $params[] = intval($_POST['proveedor_id']);
        }
        
        if (!empty($_POST['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($_POST['search'])) . '%';
            $where[] = "(fi.codigo_proveedor LIKE %s
                         OR fi.descripcion LIKE %s
                         OR CAST(f.folio AS CHAR) LIKE %s
                         OR p.nombre LIKE %s
                         OR EXISTS (
                             SELECT 1 FROM {$prefix}proveedor_apodos a
                             WHERE a.proveedor_id = p.id AND a.apodo LIKE %s
                         ))";
            array_push($params, $search, $search, $search, $search, $search);
        }
        
        $from = "FROM {$prefix}factura_items fi
                 JOIN {$prefix}facturas f ON fi.factura_id = f.id
                 JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                 WHERE " . implode(' AND ', $where);
        
        $count_sql = "SELECT COUNT(*) {$from}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : $wpdb->get_var($count_sql));
        
        $sql = "SELECT fi.*, f.folio, p.nombre as proveedor_nombre
                {$from}
                ORDER BY f.created_at DESC
                LIMIT %d OFFSET %d";
        
        $page_params = array_merge($params, [$per_page, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, ...$page_params), ARRAY_A);
        
        wp_send_json_success([
            'items' => $items ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ]);
    }

    /**
     * Obtiene todos los códigos
     */
    public function get_all_codes() {
        $this->verify_request('riverso_manage_codes');
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(200, max(10, absint($_POST['per_page'] ?? 50)));
        $offset = ($page - 1) * $per_page;
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($_POST['proveedor_id'])) {
            $where[] = 'pp.proveedor_id = %d';
            $params[] = intval($_POST['proveedor_id']);
        }
        
        if (!empty($_POST['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($_POST['search'])) . '%';
            $where[] = "(pp.codigo_proveedor LIKE %s
                         OR pp.nombre_proveedor LIKE %s
                         OR pb.canonical_sku LIKE %s
                         OR pb.nombre_canonico LIKE %s
                         OR prov.nombre LIKE %s
                         OR EXISTS (
                             SELECT 1 FROM {$prefix}proveedor_apodos a
                             WHERE a.proveedor_id = prov.id AND a.apodo LIKE %s
                         ))";
            array_push($params, $search, $search, $search, $search, $search, $search);
        }
        
        $estado = sanitize_key($_POST['estado'] ?? '');
        if ($estado === 'vinculado') {
            $where[] = 'pp.producto_base_id IS NOT NULL';
        } elseif ($estado === 'pendiente') {
            $where[] = 'pp.producto_base_id IS NULL';
        } elseif ($estado === 'por_confirmar') {
            $where[] = "pp.producto_base_id IS NOT NULL
                        AND pp.activo = 1
                        AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
                        AND pp.requires_human_review = 1
                        AND COALESCE(pp.review_status, '') = 'pendiente'
                        AND pb.canonical_sku IS NOT NULL
                        AND pb.canonical_sku <> ''";
        }

        $origen = sanitize_key($_POST['origen'] ?? '');
        if ($origen !== '') {
            if ($origen === 'catalogo') {
                $where[] = "(pp.origen_datos IN ('catalogo','computer','mamut_import') OR pp.catalogo_id IS NOT NULL)";
            } elseif ($origen === 'legacy') {
                $where[] = "pp.origen_datos IN ('legacy','riverso_codigos')";
            } elseif ($origen === 'factura') {
                $where[] = "pp.origen_datos IN ('factura','factura_intake')";
            } else {
                $where[] = 'pp.origen_datos = %s';
                $params[] = $origen;
            }
        }
        
        // LEFT JOIN en proveedores y producto_base: un código sin proveedor
        // asignado o sin SKU local sigue siendo un código que hay que ver.
        $from = "FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}proveedores prov ON prov.id = pp.proveedor_id
                 LEFT JOIN {$prefix}producto_base pb
                        ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                 LEFT JOIN {$prefix}catalogos cat ON cat.id = pp.catalogo_id
                 WHERE " . implode(' AND ', $where);
        
        $count_sql = "SELECT COUNT(*) {$from}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : $wpdb->get_var($count_sql));
        
        $sql = "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor AS descripcion_proveedor,
                       pp.proveedor_id, pp.producto_base_id, pp.activo, pp.created_at,
                       pp.origen_datos, pp.catalogo_id, pp.review_status, pp.requires_human_review,
                       pp.match_estado, pp.match_origen,
                       prov.nombre AS proveedor_nombre,
                       cat.nombre AS catalogo_nombre,
                       pb.canonical_sku AS sku_local, pb.nombre_canonico
                {$from}
                ORDER BY pp.created_at DESC
                LIMIT %d OFFSET %d";
        
        $page_params = array_merge($params, [$per_page, $offset]);
        $codes = $wpdb->get_results($wpdb->prepare($sql, ...$page_params), ARRAY_A);

        foreach ($codes as &$code) {
            $code['fecha_ingreso'] = $code['created_at'] ?? null;
            $code['origen_label'] = function_exists('riverso_pp_origen_label')
                ? riverso_pp_origen_label($code)
                : ($code['origen_datos'] ?? '');
            $code['needs_confirm'] = function_exists('riverso_pp_needs_human_confirm')
                ? riverso_pp_needs_human_confirm($code)
                : false;
        }
        unset($code);
        
        wp_send_json_success([
            'codes' => $codes,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ]);
    }

    /**
     * Obtiene proveedores
     */
    public function get_providers() {
        $this->verify_request(['riverso_manage_codes', 'riverso_view_invoices']);
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($_POST['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($_POST['search'])) . '%';
            $where[] = "(p.nombre LIKE %s OR p.rut LIKE %s OR EXISTS (
                SELECT 1 FROM {$prefix}proveedor_apodos a
                WHERE a.proveedor_id = p.id AND a.apodo LIKE %s
            ))";
            array_push($params, $search, $search, $search);
        }
        
        $where_sql = implode(' AND ', $where);
        $sql = "SELECT p.*,
                       (SELECT COUNT(*) FROM {$prefix}producto_proveedor
                        WHERE proveedor_id = p.id AND activo = 1) as codigos_count,
                       (SELECT GROUP_CONCAT(a.apodo ORDER BY a.apodo SEPARATOR '||')
                        FROM {$prefix}proveedor_apodos a
                        WHERE a.proveedor_id = p.id) as apodos_raw
                FROM {$prefix}proveedores p
                WHERE {$where_sql}
                ORDER BY p.nombre";
        
        $providers = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        
        foreach ($providers as &$provider) {
            $raw = $provider['apodos_raw'] ?? '';
            $provider['apodos'] = $raw !== '' && $raw !== null
                ? array_values(array_filter(explode('||', $raw)))
                : [];
            unset($provider['apodos_raw']);
        }
        unset($provider);
        
        wp_send_json_success(['providers' => $providers ?: []]);
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
