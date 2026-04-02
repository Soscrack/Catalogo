<?php
/**
 * Módulo de Facturas - Procesamiento de DTE XML chilenos
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Invoice_Module {

    /**
     * Inicializar módulo
     */
    public function init() {
        add_action('wp_ajax_riverso_upload_invoice', [$this, 'ajax_upload_invoice']);
        add_action('wp_ajax_riverso_get_invoice', [$this, 'ajax_get_invoice']);
        add_action('wp_ajax_riverso_update_invoice_status', [$this, 'ajax_update_status']);
        add_action('wp_ajax_riverso_link_code', [$this, 'ajax_link_code']);
        add_action('wp_ajax_riverso_get_invoices_list', [$this, 'ajax_get_invoices_list']);
    }

    /**
     * Parsear XML de DTE chileno
     */
    public function parse_dte_xml($xml_content) {
        // Limpiar BOM y espacios
        $xml_content = preg_replace('/^\xEF\xBB\xBF/', '', $xml_content);
        
        // Detectar encoding
        if (preg_match('/encoding=["\']([^"\']+)["\']/', $xml_content, $matches)) {
            $encoding = strtoupper($matches[1]);
            if ($encoding === 'ISO-8859-1') {
                $xml_content = mb_convert_encoding($xml_content, 'UTF-8', 'ISO-8859-1');
                $xml_content = preg_replace('/encoding=["\']ISO-8859-1["\']/', 'encoding="UTF-8"', $xml_content);
            }
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        
        if (!$xml) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            return new WP_Error('xml_parse_error', 'Error parseando XML: ' . ($errors[0]->message ?? 'desconocido'));
        }

        // Registrar namespace SII
        $namespaces = $xml->getNamespaces(true);
        $ns = '';
        foreach ($namespaces as $prefix => $uri) {
            if (strpos($uri, 'SiiDte') !== false) {
                $ns = $prefix ?: 'sii';
                $xml->registerXPathNamespace($ns, $uri);
                break;
            }
        }

        // Buscar documento
        $doc_path = $ns ? "//{$ns}:Documento" : "//Documento";
        $docs = $xml->xpath($doc_path);
        
        if (empty($docs)) {
            // Intentar sin namespace
            $docs = $xml->xpath("//Documento");
        }
        
        if (empty($docs)) {
            return new WP_Error('no_document', 'No se encontró elemento Documento en el XML');
        }

        $doc = $docs[0];
        
        // Extraer encabezado
        $encabezado = $doc->Encabezado;
        $id_doc = $encabezado->IdDoc;
        $emisor = $encabezado->Emisor;
        $receptor = $encabezado->Receptor;
        $totales = $encabezado->Totales;

        $factura = [
            'tipo_dte' => (int) $id_doc->TipoDTE,
            'folio' => (int) $id_doc->Folio,
            'fecha_emision' => (string) $id_doc->FchEmis,
            'forma_pago' => (int) ($id_doc->FmaPago ?? 1),
            'emisor' => [
                'rut' => (string) $emisor->RUTEmisor,
                'razon_social' => (string) $emisor->RznSoc,
                'giro' => (string) ($emisor->GiroEmis ?? ''),
                'direccion' => (string) ($emisor->DirOrigen ?? ''),
                'comuna' => (string) ($emisor->CmnaOrigen ?? ''),
            ],
            'receptor' => [
                'rut' => (string) $receptor->RUTRecep,
                'razon_social' => (string) $receptor->RznSocRecep,
                'giro' => (string) ($receptor->GiroRecep ?? ''),
                'direccion' => (string) ($receptor->DirRecep ?? ''),
                'comuna' => (string) ($receptor->CmnaRecep ?? ''),
            ],
            'totales' => [
                'neto' => (float) ($totales->MntNeto ?? 0),
                'iva' => (float) ($totales->IVA ?? 0),
                'total' => (float) ($totales->MntTotal ?? 0),
            ],
            'items' => [],
        ];

        // Extraer items
        foreach ($doc->Detalle as $detalle) {
            $item = [
                'numero' => (int) $detalle->NroLinDet,
                'nombre' => (string) $detalle->NmbItem,
                'descripcion' => (string) ($detalle->DscItem ?? ''),
                'cantidad' => (float) $detalle->QtyItem,
                'unidad' => (string) ($detalle->UnmdItem ?? 'UN'),
                'precio' => (float) $detalle->PrcItem,
                'monto' => (float) $detalle->MontoItem,
                'codigos' => [],
            ];

            // Extraer códigos del item
            foreach ($detalle->CdgItem as $codigo) {
                $item['codigos'][] = [
                    'tipo' => (string) $codigo->TpoCodigo,
                    'valor' => (string) $codigo->VlrCodigo,
                ];
            }

            $factura['items'][] = $item;
        }

        return $factura;
    }

    /**
     * Guardar factura en BD
     */
    public function save_invoice($factura_data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Obtener o crear proveedor
        $proveedor_id = $this->get_or_create_proveedor($factura_data['emisor']);
        
        if (is_wp_error($proveedor_id)) {
            return $proveedor_id;
        }

        // Verificar si ya existe esta factura
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}facturas WHERE proveedor_id = %d AND tipo_dte = %d AND folio = %d",
            $proveedor_id,
            $factura_data['tipo_dte'],
            $factura_data['folio']
        ));

        if ($existing) {
            return new WP_Error('duplicate', 'Esta factura ya fue procesada', ['factura_id' => $existing]);
        }

        // Insertar factura
        $result = $wpdb->insert(
            "{$prefix}facturas",
            [
                'proveedor_id' => $proveedor_id,
                'tipo_dte' => $factura_data['tipo_dte'],
                'folio' => $factura_data['folio'],
                'fecha_emision' => $factura_data['fecha_emision'],
                'monto_neto' => $factura_data['totales']['neto'],
                'monto_iva' => $factura_data['totales']['iva'],
                'monto_total' => $factura_data['totales']['total'],
                'estado' => 'recibido',
                'usuario_id' => get_current_user_id(),
                'xml_original' => '', // Se puede guardar si se necesita
            ],
            ['%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%d', '%s']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error guardando factura: ' . $wpdb->last_error);
        }

        $factura_id = $wpdb->insert_id;

        // Insertar items
        foreach ($factura_data['items'] as $item) {
            $codigo_proveedor = '';
            foreach ($item['codigos'] as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    break;
                }
            }

            // Buscar si existe mapeo de código
            $codigo_local = null;
            if ($codigo_proveedor) {
                $mapping = $wpdb->get_row($wpdb->prepare(
                    "SELECT sku_local, product_id FROM {$prefix}codigos 
                     WHERE proveedor_id = %d AND codigo_proveedor = %s AND activo = 1",
                    $proveedor_id,
                    $codigo_proveedor
                ));
                if ($mapping) {
                    $codigo_local = $mapping->sku_local;
                }
            }

            $wpdb->insert(
                "{$prefix}factura_items",
                [
                    'factura_id' => $factura_id,
                    'linea' => $item['numero'],
                    'codigo_proveedor' => $codigo_proveedor,
                    'descripcion' => $item['nombre'],
                    'cantidad' => $item['cantidad'],
                    'unidad' => $item['unidad'],
                    'precio_unitario' => $item['precio'],
                    'monto_total' => $item['monto'],
                    'sku_local' => $codigo_local,
                    'estado' => $codigo_local ? 'vinculado' : 'pendiente',
                ],
                ['%d', '%d', '%s', '%s', '%f', '%s', '%f', '%f', '%s', '%s']
            );
        }

        // Actualizar estado de factura según items
        $this->update_invoice_status($factura_id);

        return $factura_id;
    }

    /**
     * Obtener o crear proveedor
     */
    private function get_or_create_proveedor($emisor) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $rut = preg_replace('/[^0-9kK]/', '', $emisor['rut']);
        
        $proveedor = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ));

        if ($proveedor) {
            return $proveedor->id;
        }

        // Crear nuevo proveedor
        $result = $wpdb->insert(
            "{$prefix}proveedores",
            [
                'rut' => $rut,
                'nombre' => $emisor['razon_social'],
                'giro' => $emisor['giro'],
                'direccion' => $emisor['direccion'],
                'comuna' => $emisor['comuna'],
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error creando proveedor');
        }

        return $wpdb->insert_id;
    }

    /**
     * Actualizar estado de factura según items
     */
    public function update_invoice_status($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'vinculado' THEN 1 ELSE 0 END) as vinculados,
                SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazados
             FROM {$prefix}factura_items 
             WHERE factura_id = %d",
            $factura_id
        ));

        $estado = 'recibido';
        if ($stats->total > 0) {
            if ($stats->vinculados == $stats->total) {
                $estado = 'procesado';
            } elseif ($stats->vinculados > 0 || $stats->rechazados > 0) {
                $estado = 'parcial';
            }
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => $estado],
            ['id' => $factura_id],
            ['%s'],
            ['%d']
        );

        return $estado;
    }

    /**
     * AJAX: Subir factura XML
     */
    public function ajax_upload_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        if (!isset($_FILES['xml_file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo']);
        }

        $file = $_FILES['xml_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Error subiendo archivo: ' . $file['error']]);
        }

        $xml_content = file_get_contents($file['tmp_name']);
        
        $factura = $this->parse_dte_xml($xml_content);
        
        if (is_wp_error($factura)) {
            wp_send_json_error(['message' => $factura->get_error_message()]);
        }

        $factura_id = $this->save_invoice($factura);
        
        if (is_wp_error($factura_id)) {
            $data = $factura_id->get_error_data();
            if ($factura_id->get_error_code() === 'duplicate' && isset($data['factura_id'])) {
                wp_send_json_error([
                    'message' => 'Factura duplicada',
                    'factura_id' => $data['factura_id'],
                ]);
            }
            wp_send_json_error(['message' => $factura_id->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Factura procesada correctamente',
            'factura_id' => $factura_id,
            'resumen' => [
                'proveedor' => $factura['emisor']['razon_social'],
                'folio' => $factura['folio'],
                'total' => $factura['totales']['total'],
                'items' => count($factura['items']),
            ],
        ]);
    }

    /**
     * AJAX: Obtener detalle de factura
     */
    public function ajax_get_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_id = intval($_POST['factura_id'] ?? 0);
        
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID de factura requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.nombre as proveedor_nombre, p.rut as proveedor_rut
             FROM {$prefix}facturas f
             JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
             WHERE f.id = %d",
            $factura_id
        ), ARRAY_A);

        if (!$factura) {
            wp_send_json_error(['message' => 'Factura no encontrada']);
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items WHERE factura_id = %d ORDER BY linea",
            $factura_id
        ), ARRAY_A);

        $factura['items'] = $items;

        wp_send_json_success($factura);
    }

    /**
     * AJAX: Actualizar estado de factura
     */
    public function ajax_update_status() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_id = intval($_POST['factura_id'] ?? 0);
        $estado = sanitize_text_field($_POST['estado'] ?? '');

        $estados_validos = ['recibido', 'parcial', 'procesado', 'rechazado'];
        
        if (!$factura_id || !in_array($estado, $estados_validos)) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => $estado],
            ['id' => $factura_id],
            ['%s'],
            ['%d']
        );

        wp_send_json_success(['message' => 'Estado actualizado']);
    }

    /**
     * AJAX: Vincular código proveedor con SKU local
     */
    public function ajax_link_code() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $item_id = intval($_POST['item_id'] ?? 0);
        $sku_local = sanitize_text_field($_POST['sku_local'] ?? '');
        $crear_mapeo = !empty($_POST['crear_mapeo']);

        if (!$item_id) {
            wp_send_json_error(['message' => 'ID de item requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Obtener item y factura
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT fi.*, f.proveedor_id 
             FROM {$prefix}factura_items fi
             JOIN {$prefix}facturas f ON fi.factura_id = f.id
             WHERE fi.id = %d",
            $item_id
        ));

        if (!$item) {
            wp_send_json_error(['message' => 'Item no encontrado']);
        }

        // Verificar que el SKU existe en WooCommerce
        $product_id = wc_get_product_id_by_sku($sku_local);
        if (!$product_id) {
            wp_send_json_error(['message' => 'SKU no encontrado en WooCommerce: ' . $sku_local]);
        }

        // Actualizar item
        $wpdb->update(
            "{$prefix}factura_items",
            [
                'sku_local' => $sku_local,
                'estado' => 'vinculado',
            ],
            ['id' => $item_id],
            ['%s', '%s'],
            ['%d']
        );

        // Crear mapeo permanente si se solicita
        if ($crear_mapeo && !empty($item->codigo_proveedor)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}codigos 
                 WHERE proveedor_id = %d AND codigo_proveedor = %s",
                $item->proveedor_id,
                $item->codigo_proveedor
            ));

            if (!$existing) {
                $wpdb->insert(
                    "{$prefix}codigos",
                    [
                        'proveedor_id' => $item->proveedor_id,
                        'codigo_proveedor' => $item->codigo_proveedor,
                        'sku_local' => $sku_local,
                        'product_id' => $product_id,
                        'descripcion_proveedor' => $item->descripcion,
                        'usuario_id' => get_current_user_id(),
                        'activo' => 1,
                    ],
                    ['%d', '%s', '%s', '%d', '%s', '%d', '%d']
                );
            }
        }

        // Actualizar estado de factura
        $this->update_invoice_status($item->factura_id);

        wp_send_json_success(['message' => 'Código vinculado correctamente']);
    }

    /**
     * AJAX: Listar facturas con filtros
     */
    public function ajax_get_invoices_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(10, intval($_POST['per_page'] ?? 20)));
        $estado = sanitize_text_field($_POST['estado'] ?? '');
        $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
        $fecha_desde = sanitize_text_field($_POST['fecha_desde'] ?? '');
        $fecha_hasta = sanitize_text_field($_POST['fecha_hasta'] ?? '');

        $where = ['1=1'];
        $params = [];

        if ($estado) {
            $where[] = 'f.estado = %s';
            $params[] = $estado;
        }

        if ($proveedor_id) {
            $where[] = 'f.proveedor_id = %d';
            $params[] = $proveedor_id;
        }

        if ($fecha_desde) {
            $where[] = 'f.fecha_emision >= %s';
            $params[] = $fecha_desde;
        }

        if ($fecha_hasta) {
            $where[] = 'f.fecha_emision <= %s';
            $params[] = $fecha_hasta;
        }

        $where_sql = implode(' AND ', $where);
        $offset = ($page - 1) * $per_page;

        // Contar total
        $count_sql = "SELECT COUNT(*) FROM {$prefix}facturas f WHERE {$where_sql}";
        $total = $wpdb->get_var($params ? $wpdb->prepare($count_sql, ...$params) : $count_sql);

        // Obtener facturas
        $sql = "SELECT f.*, p.nombre as proveedor_nombre,
                (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id) as total_items,
                (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id AND estado = 'vinculado') as items_vinculados
                FROM {$prefix}facturas f
                JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                WHERE {$where_sql}
                ORDER BY f.created_at DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;

        $facturas = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        wp_send_json_success([
            'facturas' => $facturas,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }
}
