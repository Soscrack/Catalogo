<?php
/**
 * Módulo de Facturas - Procesamiento de DTE XML chilenos
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-invoice-intake-service.php';

class Riverso_Invoice_Module {

    /**
     * Estados de factura
     */
    const INVOICE_STATES = [
        'uploaded' => 'Cargada',
        'pending_reception' => 'Pendiente Recepción',
        'in_reception' => 'En Recepción',
        'reception_complete' => 'Recepción Completa',
        'pending_approval' => 'Pendiente Aprobación',
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'archived' => 'Archivada'
    ];
    
    /**
     * Estados de ítem
     */
    const ITEM_STATES = [
        'pending' => 'Pendiente',
        'received_ok' => 'Recibido OK',
        'modified' => 'Modificado',
        'missing' => 'Faltante',
        'extra' => 'Sobrante',
        'rejected' => 'Rechazado',
        'approved' => 'Aprobado'
    ];
    
    /**
     * Inicializar módulo
     */
    public function init() {
        add_action('wp_ajax_riverso_upload_invoice', [$this, 'ajax_upload_invoice']);
        add_action('wp_ajax_riverso_get_invoice', [$this, 'ajax_get_invoice']);
        add_action('wp_ajax_riverso_update_invoice_status', [$this, 'ajax_update_status']);
        add_action('wp_ajax_riverso_link_code', [$this, 'ajax_link_code']);
        add_action('wp_ajax_riverso_get_invoices_list', [$this, 'ajax_get_invoices_list']);
        
        // Nuevos handlers para recepción física
        add_action('wp_ajax_riverso_start_reception', [$this, 'ajax_start_reception']);
        add_action('wp_ajax_riverso_update_item_reception', [$this, 'ajax_update_item_reception']);
        add_action('wp_ajax_riverso_complete_reception', [$this, 'ajax_complete_reception']);
        add_action('wp_ajax_riverso_approve_invoice', [$this, 'ajax_approve_invoice']);
        add_action('wp_ajax_riverso_search_invoice', [$this, 'ajax_search_invoice']);
        add_action('wp_ajax_riverso_get_reception_stats', [$this, 'ajax_get_reception_stats']);
        add_action('wp_ajax_riverso_link_shipping_invoice', [$this, 'ajax_link_shipping_invoice']);
        add_action('wp_ajax_riverso_assign_shipping_invoice', [$this, 'ajax_assign_shipping_invoice']);
        add_action('wp_ajax_riverso_unassign_shipping_invoice', [$this, 'ajax_unassign_shipping_invoice']);
        add_action('wp_ajax_riverso_save_invoice_settings', [$this, 'ajax_save_invoice_settings']);
        add_action('wp_ajax_riverso_preview_invoice_xml', [$this, 'ajax_preview_invoice_xml']);
        add_action('wp_ajax_riverso_lookup_supplier_rut', [$this, 'ajax_lookup_supplier_rut']);
        add_action('wp_ajax_riverso_repair_invoice_skus', [$this, 'ajax_repair_invoice_skus']);
        add_action('wp_ajax_riverso_delete_invoice', [$this, 'ajax_delete_invoice']);
    }

    /**
     * Servicio de ingreso XML (envío, códigos, lotes).
     */
    private function intake() {
        return Riverso_Invoice_Intake_Service::get_instance();
    }

    private function user_can_intake_invoices() {
        return current_user_can('riverso_process_invoices') || current_user_can('riverso_create_invoices');
    }

    private function ensure_flete_vinculos_table() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
        Riverso_POS_Activator::ensure_flete_vinculos_table();
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

        $this->intake()->classify_factura_items($factura);

        return $factura;
    }

    /**
     * Normaliza fila de ítem de factura (alias de columnas legacy).
     */
    public function enrich_factura_item_row($item) {
        return $this->intake()->enrich_factura_item_row($item);
    }

    /**
     * Detecta datos faltantes antes de guardar una factura.
     */
    public function detect_intake_gaps(array $factura, array $options = []) {
        $gaps = [];
        $emisor = $factura['emisor'] ?? [];
        $rut = preg_replace('/[^0-9kK]/', '', $emisor['rut'] ?? '');
        $proveedor_modo = sanitize_text_field($options['proveedor_modo'] ?? 'xml');

        if ($proveedor_modo !== 'existente') {
            $nombre = trim($options['proveedor_nombre'] ?? $emisor['razon_social'] ?? '');
            if ($nombre === '') {
                $gaps[] = [
                    'type' => 'supplier',
                    'field' => 'nombre',
                    'label' => 'Nombre / razón social del proveedor',
                    'message' => 'El XML no trae razón social del emisor. Ingrese el nombre del proveedor.',
                ];
            }
            if ($rut === '' && $proveedor_modo !== 'existente') {
                $gaps[] = [
                    'type' => 'supplier',
                    'field' => 'rut',
                    'label' => 'RUT del proveedor',
                    'message' => 'El XML no trae RUT del emisor. Ingrese el RUT para registrar el proveedor.',
                ];
            }
        } elseif (empty($options['proveedor_id'])) {
            $gaps[] = [
                'type' => 'supplier',
                'field' => 'proveedor_id',
                'label' => 'Proveedor existente',
                'message' => 'Seleccione un proveedor de la lista.',
            ];
        }

        return $gaps;
    }

    /**
     * Guardar factura en BD
     */
    public function save_invoice($factura_data, $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $this->intake()->classify_factura_items($factura_data);

        $force_subtipo = sanitize_text_field($options['documento_subtipo'] ?? '');
        $link_to_factura_id = intval($options['link_to_factura_id'] ?? 0);
        $modo_ingreso = sanitize_text_field($options['modo_ingreso'] ?? riverso_get_setting('default_intake_mode', 'recepcion'));
        if (!in_array($modo_ingreso, ['recepcion', 'solo_costos'], true)) {
            $modo_ingreso = 'recepcion';
        }

        $product_items = array_filter($factura_data['items'], function ($item) {
            return ($item['item_tipo'] ?? 'producto') !== 'envio';
        });
        $all_shipping = count($product_items) === 0 && !empty($factura_data['items']);
        $documento_subtipo = $force_subtipo ?: ($all_shipping ? 'envio' : 'productos');

        $gaps = $this->detect_intake_gaps($factura_data, array_merge($options, [
            'documento_subtipo' => $documento_subtipo,
            'link_to_factura_id' => $link_to_factura_id,
        ]));
        if (!empty($gaps)) {
            return new WP_Error('missing_data', 'Faltan datos para completar el ingreso', [
                'needs_input' => true,
                'gaps' => $gaps,
            ]);
        }

        // Obtener o crear proveedor (con datos precargados del formulario si aplica)
        $proveedor_id = $this->resolve_proveedor_for_upload($factura_data['emisor'], $options);
        
        if (is_wp_error($proveedor_id)) {
            return $proveedor_id;
        }

        $rut_emisor = sanitize_text_field($factura_data['emisor']['rut'] ?? '');
        $folio = (string) $factura_data['folio'];

        // Verificar si ya existe esta factura
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}facturas WHERE tipo_dte = %d AND folio = %s AND rut_emisor = %s",
            $factura_data['tipo_dte'],
            $folio,
            $rut_emisor
        ));

        if ($existing) {
            return new WP_Error('duplicate', 'Esta factura ya fue procesada', ['factura_id' => $existing]);
        }

        $costo_envio_inline = (float) ($factura_data['costo_envio_inline'] ?? 0);

        $estado_inicial = 'recibido';
        if ($documento_subtipo === 'envio') {
            $estado_inicial = 'sin_vincular';
        }

        // Insertar factura
        $result = $wpdb->insert(
            "{$prefix}facturas",
            [
                'tipo_dte' => $factura_data['tipo_dte'],
                'folio' => $folio,
                'proveedor_id' => $proveedor_id,
                'rut_emisor' => $rut_emisor,
                'razon_social_emisor' => sanitize_text_field($factura_data['emisor']['razon_social'] ?? ''),
                'fecha_emision' => $factura_data['fecha_emision'],
                'monto_neto' => $factura_data['totales']['neto'],
                'monto_iva' => $factura_data['totales']['iva'],
                'monto_total' => $factura_data['totales']['total'],
                'items_total' => count($factura_data['items']),
                'estado' => $estado_inicial,
                'procesado_por' => get_current_user_id(),
                'procesado_at' => current_time('mysql'),
                'documento_subtipo' => $documento_subtipo,
                'factura_productos_id' => null,
                'costo_envio_total' => $documento_subtipo === 'envio' ? 0 : $costo_envio_inline,
                'modo_ingreso' => $documento_subtipo === 'productos' ? $modo_ingreso : 'solo_costos',
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error guardando factura: ' . $wpdb->last_error);
        }

        $factura_id = $wpdb->insert_id;

        // Insertar items
        foreach ($factura_data['items'] as $item) {
            $item_tipo = $item['item_tipo'] ?? 'producto';
            $codigo_proveedor = '';
            $codigo_tipo = 'INT1';
            foreach ($item['codigos'] as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    $codigo_tipo = $codigo['tipo'] ?? 'INT1';
                    break;
                }
            }

            $codigo_local = null;
            $product_id = null;
            if ($item_tipo === 'producto' && $codigo_proveedor) {
                $mapping = $this->intake()->lookup_product_mapping(
                    $proveedor_id,
                    $codigo_proveedor,
                    $item['codigos'] ?? []
                );
                $codigo_local = $mapping['sku_local'] ?? null;
                if ($codigo_local) {
                    $product_id = $mapping['product_id']
                        ?? $this->intake()->resolve_product_id_for_local_sku($codigo_local, $codigo_proveedor);
                }
            }

            $item_nombre = trim($item['nombre'] ?? '') ?: trim($item['descripcion'] ?? '') ?: 'Sin descripción';
            $item_descripcion = trim($item['descripcion'] ?? '') ?: $item_nombre;

            $wpdb->insert(
                "{$prefix}factura_items",
                [
                    'factura_id' => $factura_id,
                    'numero_linea' => $item['numero'],
                    'codigo_proveedor' => $codigo_proveedor,
                    'codigo_tipo' => $codigo_tipo,
                    'nombre' => $item_nombre,
                    'descripcion' => $item_descripcion,
                    'cantidad' => $item['cantidad'],
                    'unidad' => $item['unidad'],
                    'precio_unitario' => $item['precio'],
                    'monto_total' => $item['monto'],
                    'product_id' => $product_id,
                    'sku_local' => $codigo_local,
                    'estado' => ($item_tipo === 'envio') ? 'envio' : ($codigo_local ? 'vinculado' : 'pendiente'),
                    'item_tipo' => $item_tipo,
                    'costo_landed_unitario' => $item_tipo === 'producto' ? $item['precio'] : null,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%f']
            );
        }

        if ($link_to_factura_id && $documento_subtipo === 'envio') {
            $link_result = $this->intake()->link_shipping_invoice($link_to_factura_id, $factura_id);
            if (is_wp_error($link_result)) {
                $wpdb->delete("{$prefix}factura_items", ['factura_id' => $factura_id], ['%d']);
                $wpdb->delete("{$prefix}facturas", ['id' => $factura_id], ['%d']);
                return $link_result;
            }
        } elseif ($documento_subtipo === 'productos') {
            $this->intake()->after_invoice_saved($factura_id, $proveedor_id, $factura_data['items'], $modo_ingreso);
        }

        // Actualizar estado de factura según items (solo productos)
        $this->update_invoice_status($factura_id);

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_created',
                'invoice',
                $factura_id,
                null,
                [
                    'folio' => $folio,
                    'tipo_dte' => $factura_data['tipo_dte'],
                    'proveedor_id' => $proveedor_id,
                    'documento_subtipo' => $documento_subtipo,
                    'modo_ingreso' => $documento_subtipo === 'productos' ? $modo_ingreso : 'solo_costos',
                    'monto_total' => $factura_data['totales']['total'],
                ],
                sprintf('Factura folio %s ingresada por XML', $folio)
            );
        }

        return $factura_id;
    }

    /**
     * Estados en los que no se permite revertir la subida.
     */
    private function invoice_delete_blocked_states() {
        return ['approved', 'in_reception', 'pending_approval', 'reception_complete'];
    }

    /**
     * ¿Se puede eliminar esta factura (revertir subida)?
     */
    public function invoice_can_be_deleted($factura) {
        if (is_array($factura)) {
            $factura = (object) $factura;
        }
        if (in_array($factura->estado ?? '', $this->invoice_delete_blocked_states(), true)) {
            return false;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $linked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_flete_vinculos WHERE factura_productos_id = %d",
            (int) $factura->id
        ));
        return $linked === 0;
    }

    /**
     * Elimina una factura subida y sus datos derivados (ítems, costos, tareas).
     */
    public function delete_invoice($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ), ARRAY_A);

        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        if (!$this->invoice_can_be_deleted($factura)) {
            if (in_array($factura['estado'], $this->invoice_delete_blocked_states(), true)) {
                return new WP_Error(
                    'blocked_state',
                    'No se puede eliminar una factura en estado «' . $factura['estado'] . '»'
                );
            }
            return new WP_Error(
                'has_shipping',
                'Elimine primero las facturas de flete vinculadas a esta factura'
            );
        }

        $item_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $factura_id
        ));

        // Revertir vínculos de flete (N:M)
        if (($factura['documento_subtipo'] ?? '') === 'envio') {
            $this->intake()->unlink_shipping_invoice((int) $factura_id);
        } else {
            $wpdb->delete(
                "{$prefix}factura_flete_vinculos",
                ['factura_productos_id' => (int) $factura_id],
                ['%d']
            );
        }

        $wpdb->delete(
            "{$prefix}cost_history",
            ['source_type' => 'invoice', 'source_document_id' => (int) $factura_id],
            ['%s', '%d']
        );

        if ($item_ids) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$prefix}cost_history
                 WHERE source_type = 'invoice' AND source_item_id IN ($placeholders)",
                ...array_map('intval', $item_ids)
            ));
        }

        $wpdb->delete(
            "{$prefix}tareas",
            ['referencia_tipo' => 'factura', 'referencia_id' => (int) $factura_id],
            ['%s', '%d']
        );

        if ($item_ids) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$prefix}tareas
                 WHERE referencia_tipo = 'factura_item' AND referencia_id IN ($placeholders)",
                ...array_map('intval', $item_ids)
            ));
        }

        $wpdb->delete("{$prefix}factura_items", ['factura_id' => (int) $factura_id], ['%d']);

        if (!empty($factura['xml_path']) && is_string($factura['xml_path']) && file_exists($factura['xml_path'])) {
            @unlink($factura['xml_path']);
        }

        $deleted = $wpdb->delete("{$prefix}facturas", ['id' => (int) $factura_id], ['%d']);
        if ($deleted === false) {
            return new WP_Error('db_error', 'Error eliminando factura: ' . $wpdb->last_error);
        }

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_deleted',
                'invoice',
                (int) $factura_id,
                $factura,
                null,
                sprintf(
                    'Subida revertida — folio %s, proveedor RUT %s, total $%s',
                    $factura['folio'],
                    $factura['rut_emisor'],
                    $factura['monto_total']
                )
            );
        }

        return true;
    }

    /**
     * AJAX: Eliminar factura (revertir subida XML).
     */
    public function ajax_delete_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos para eliminar facturas']);
        }

        $factura_id = intval($_POST['factura_id'] ?? 0);
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID de factura requerido']);
        }

        $result = $this->delete_invoice($factura_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Factura eliminada correctamente',
            'factura_id' => $factura_id,
        ]);
    }

    /**
     * Resuelve proveedor: existente, nuevo con datos del formulario, o auto desde XML.
     */
    private function resolve_proveedor_for_upload($emisor, $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!empty($options['proveedor_id'])) {
            $id = (int) $options['proveedor_id'];
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}proveedores WHERE id = %d AND activo = 1",
                $id
            ));
            if ($exists) {
                return $id;
            }
        }

        $rut = preg_replace('/[^0-9kK]/', '', $emisor['rut'] ?? '');
        $proveedor_data = [
            'rut' => $rut ?: preg_replace('/[^0-9kK]/', '', $options['proveedor_rut'] ?? ''),
            'nombre' => sanitize_text_field($options['proveedor_nombre'] ?? $emisor['razon_social'] ?? ''),
            'giro' => sanitize_text_field($options['proveedor_giro'] ?? $emisor['giro'] ?? ''),
            'direccion' => sanitize_text_field($options['proveedor_direccion'] ?? $emisor['direccion'] ?? ''),
            'comuna' => sanitize_text_field($options['proveedor_comuna'] ?? $emisor['comuna'] ?? ''),
            'ciudad' => sanitize_text_field($options['proveedor_ciudad'] ?? ''),
            'telefono' => sanitize_text_field($options['proveedor_telefono'] ?? ''),
            'email' => sanitize_email($options['proveedor_email'] ?? ''),
            'contacto' => sanitize_text_field($options['proveedor_contacto'] ?? ''),
            'activo' => 1,
        ];

        if (empty($proveedor_data['nombre'])) {
            return new WP_Error('missing_supplier_data', 'Nombre de proveedor requerido', [
                'needs_input' => true,
                'gaps' => [[
                    'type' => 'supplier',
                    'field' => 'nombre',
                    'label' => 'Nombre / razón social del proveedor',
                    'message' => 'Ingrese el nombre del proveedor para registrarlo en el sistema.',
                ]],
            ]);
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ));

        if ($existing) {
            $update = [];
            foreach (['nombre', 'giro', 'direccion', 'comuna', 'ciudad', 'telefono', 'email', 'contacto'] as $field) {
                if (!empty($proveedor_data[$field])) {
                    $update[$field] = $proveedor_data[$field];
                }
            }
            if (!empty($update)) {
                $wpdb->update("{$prefix}proveedores", $update, ['id' => (int) $existing->id]);
            }
            return (int) $existing->id;
        }

        $result = $wpdb->insert("{$prefix}proveedores", $proveedor_data);
        if (!$result) {
            return new WP_Error('db_error', 'Error creando proveedor');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Obtener o crear proveedor (legacy / uso interno).
     */
    private function get_or_create_proveedor($emisor) {
        return $this->resolve_proveedor_for_upload($emisor, []);
    }

    /**
     * Actualizar estado de factura según items
     */
    public function update_invoice_status($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT documento_subtipo, factura_productos_id FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ));

        if ($factura && ($factura->documento_subtipo ?? '') === 'envio') {
            $this->intake()->sync_envio_link_state((int) $factura_id);
            return $wpdb->get_var($wpdb->prepare(
                "SELECT estado FROM {$prefix}facturas WHERE id = %d",
                (int) $factura_id
            ));
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'vinculado' THEN 1 ELSE 0 END) as vinculados,
                SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazados
             FROM {$prefix}factura_items 
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
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

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        if (!isset($_FILES['xml_file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo XML']);
        }

        $documento_tipo = sanitize_text_field($_POST['documento_tipo'] ?? 'productos');
        if (!in_array($documento_tipo, ['productos', 'envio'], true)) {
            $documento_tipo = 'productos';
        }

        $link_to_factura_id = intval($_POST['link_to_factura_id'] ?? 0);
        $modo_ingreso = sanitize_text_field($_POST['modo_ingreso'] ?? riverso_get_setting('default_intake_mode', 'recepcion'));
        if ($documento_tipo === 'envio') {
            $modo_ingreso = 'solo_costos';
        }

        $save_options = [
            'link_to_factura_id' => $documento_tipo === 'envio' ? $link_to_factura_id : 0,
            'documento_subtipo' => $documento_tipo,
            'modo_ingreso' => $modo_ingreso,
            'proveedor_modo' => sanitize_text_field($_POST['proveedor_modo'] ?? 'xml'),
            'proveedor_id' => intval($_POST['proveedor_id'] ?? 0),
            'proveedor_nombre' => sanitize_text_field($_POST['proveedor_nombre'] ?? ''),
            'proveedor_rut' => sanitize_text_field($_POST['proveedor_rut'] ?? ''),
            'proveedor_giro' => sanitize_text_field($_POST['proveedor_giro'] ?? ''),
            'proveedor_direccion' => sanitize_text_field($_POST['proveedor_direccion'] ?? ''),
            'proveedor_comuna' => sanitize_text_field($_POST['proveedor_comuna'] ?? ''),
            'proveedor_ciudad' => sanitize_text_field($_POST['proveedor_ciudad'] ?? ''),
            'proveedor_telefono' => sanitize_text_field($_POST['proveedor_telefono'] ?? ''),
            'proveedor_email' => sanitize_email($_POST['proveedor_email'] ?? ''),
            'proveedor_contacto' => sanitize_text_field($_POST['proveedor_contacto'] ?? ''),
        ];

        $file = $_FILES['xml_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Error subiendo archivo: ' . $file['error']]);
        }

        $xml_content = file_get_contents($file['tmp_name']);
        $factura = $this->parse_dte_xml($xml_content);
        if (is_wp_error($factura)) {
            wp_send_json_error(['message' => $factura->get_error_message()]);
        }

        $factura_id = $this->save_invoice($factura, $save_options);
        if (is_wp_error($factura_id)) {
            $data = $factura_id->get_error_data();
            if ($factura_id->get_error_code() === 'duplicate' && isset($data['factura_id'])) {
                wp_send_json_error([
                    'message' => 'Factura duplicada',
                    'factura_id' => $data['factura_id'],
                ]);
            }
            if (!empty($data['needs_input'])) {
                wp_send_json_error([
                    'message' => $factura_id->get_error_message(),
                    'needs_input' => true,
                    'gaps' => $data['gaps'] ?? [],
                ]);
            }
            wp_send_json_error(['message' => $factura_id->get_error_message()]);
        }

        $product_items = count(array_filter($factura['items'], function ($i) {
            return ($i['item_tipo'] ?? 'producto') !== 'envio';
        }));
        $shipping_items = count($factura['items']) - $product_items;

        $resumen = [
            'proveedor' => $factura['emisor']['razon_social'],
            'folio' => $factura['folio'],
            'total' => $factura['totales']['total'],
            'documento_tipo' => $documento_tipo,
            'items' => $product_items,
            'items_envio' => $shipping_items,
            'costo_envio_inline' => $factura['costo_envio_inline'] ?? 0,
            'vinculado_a_factura' => $documento_tipo === 'envio' ? $link_to_factura_id : null,
        ];

        if ($modo_ingreso === 'solo_costos' || $documento_tipo === 'envio') {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            if ($documento_tipo === 'productos') {
                $resumen['costos_registrados'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}cost_history
                     WHERE source_type = 'invoice' AND source_document_id = %d AND pendiente_vinculacion = 0",
                    $factura_id
                ));
                $resumen['costos_pendientes'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}cost_history
                     WHERE source_type = 'invoice' AND source_document_id = %d AND pendiente_vinculacion = 1",
                    $factura_id
                ));
                $resumen['tareas_vinculacion'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}tareas
                     WHERE tipo = 'codigo_faltante' AND referencia_tipo = 'factura_item'
                       AND referencia_id IN (SELECT id FROM {$prefix}factura_items WHERE factura_id = %d)",
                    $factura_id
                ));
            }
        }

        $message = $documento_tipo === 'envio'
            ? ($link_to_factura_id
                ? 'Flete de transportista registrado y vinculado'
                : 'Flete registrado — pendiente de asignar a factura de productos')
            : ($modo_ingreso === 'solo_costos'
                ? 'Costos y códigos registrados (sin actualizar bodega)'
                : 'Factura procesada correctamente');

        wp_send_json_success([
            'message' => $message,
            'factura_id' => $factura_id,
            'modo_ingreso' => $modo_ingreso,
            'resumen' => $resumen,
        ]);
    }

    /**
     * AJAX: Vista previa del XML — precarga datos del emisor/proveedor.
     */
    public function ajax_preview_invoice_xml() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        if (empty($_FILES['xml_file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo XML']);
        }

        $file = $_FILES['xml_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Error leyendo archivo']);
        }

        $factura = $this->parse_dte_xml(file_get_contents($file['tmp_name']));
        if (is_wp_error($factura)) {
            wp_send_json_error(['message' => $factura->get_error_message()]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rut = preg_replace('/[^0-9kK]/', '', $factura['emisor']['rut'] ?? '');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ), ARRAY_A);

        $suppliers = $wpdb->get_results(
            "SELECT id, rut, nombre FROM {$prefix}proveedores WHERE activo = 1 ORDER BY nombre ASC LIMIT 200",
            ARRAY_A
        );

        $detection = $this->intake()->detect_document_type($factura);

        $facturas_productos = $wpdb->get_results(
            "SELECT f.id, f.folio, f.fecha_emision, f.monto_total, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL)
               AND f.estado NOT IN ('rejected', 'archived', 'approved')
             ORDER BY f.created_at DESC
             LIMIT 40",
            ARRAY_A
        );

        wp_send_json_success([
            'emisor' => $factura['emisor'],
            'folio' => $factura['folio'],
            'fecha_emision' => $factura['fecha_emision'],
            'tipo_dte' => $factura['tipo_dte'],
            'total' => $factura['totales']['total'],
            'neto' => $factura['totales']['neto'],
            'items_count' => $detection['items_producto'],
            'items_envio_count' => $detection['items_envio'],
            'costo_envio_inline' => $factura['costo_envio_inline'] ?? 0,
            'detection' => $detection,
            'items_preview' => $detection['items_preview'],
            'proveedor_existente' => $existing,
            'proveedores' => $suppliers,
            'facturas_productos' => $facturas_productos,
            'rut_limpio' => $rut,
            'missing_gaps' => $this->detect_intake_gaps($factura, [
                'documento_subtipo' => $detection['tipo'] === 'envio' ? 'envio' : 'productos',
            ]),
        ]);
    }

    /**
     * AJAX: Buscar proveedor por RUT para precarga.
     */
    public function ajax_lookup_supplier_rut() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rut = preg_replace('/[^0-9kK]/', '', sanitize_text_field($_POST['rut'] ?? ''));

        if (!$rut) {
            wp_send_json_error(['message' => 'RUT requerido']);
        }

        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ), ARRAY_A);

        wp_send_json_success(['supplier' => $supplier, 'found' => (bool) $supplier]);
    }

    /**
     * AJAX: Reparar vínculos SKU local incorrectos (online → local Mamut).
     */
    public function ajax_repair_invoice_skus() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $args = [];
        if (!empty($_POST['factura_id'])) {
            $args['factura_id'] = intval($_POST['factura_id']);
        }
        if (!empty($_POST['folio'])) {
            $args['folio'] = sanitize_text_field($_POST['folio']);
        }

        $result = $this->intake()->repair_mislinked_invoice_items($args);
        wp_send_json_success([
            'message' => sprintf(
                'Reparación completada: %d ítems corregidos, %d limpiados, %d códigos, %d dominio desactivados',
                $result['items_fixed'],
                $result['items_cleared'] ?? 0,
                $result['codigos_fixed'],
                $result['domain_deactivated'] ?? 0
            ),
            'result' => $result,
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

        $this->ensure_flete_vinculos_table();

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
            "SELECT * FROM {$prefix}factura_items WHERE factura_id = %d ORDER BY numero_linea",
            $factura_id
        ), ARRAY_A);

        $proveedor_id = (int) ($factura['proveedor_id'] ?? 0);

        $factura['items'] = array_map(function ($row) use ($proveedor_id) {
            if (is_array($row)) {
                $row['proveedor_id'] = $proveedor_id;
            }
            $enriched = $this->enrich_factura_item_row($row);
            return is_object($enriched) ? (array) $enriched : $enriched;
        }, $items);

        $subtipo = $factura['documento_subtipo'] ?? 'productos';
        $vinculos_table = $prefix . 'factura_flete_vinculos';

        if ($subtipo === 'envio') {
            $factura['facturas_productos_vinculadas'] = $wpdb->get_results($wpdb->prepare(
                "SELECT fp.id, fp.folio, fp.fecha_emision, fp.monto_total, fp.estado,
                        p.nombre AS proveedor_nombre, v.monto_asignado
                 FROM {$vinculos_table} v
                 INNER JOIN {$prefix}facturas fp ON fp.id = v.factura_productos_id
                 LEFT JOIN {$prefix}proveedores p ON p.id = fp.proveedor_id
                 WHERE v.factura_envio_id = %d
                 ORDER BY fp.fecha_emision DESC, fp.id DESC",
                (int) $factura_id
            ), ARRAY_A);
            // Compatibilidad UI legacy (primer vínculo).
            if (!empty($factura['facturas_productos_vinculadas'][0])) {
                $factura['factura_productos'] = $factura['facturas_productos_vinculadas'][0];
            }
        } else {
            $factura['fletes_vinculados'] = $wpdb->get_results($wpdb->prepare(
                "SELECT fe.id, fe.folio, fe.fecha_emision, fe.monto_total, fe.estado,
                        p.nombre AS proveedor_nombre, v.monto_asignado
                 FROM {$vinculos_table} v
                 INNER JOIN {$prefix}facturas fe ON fe.id = v.factura_envio_id
                 LEFT JOIN {$prefix}proveedores p ON p.id = fe.proveedor_id
                 WHERE v.factura_productos_id = %d
                 ORDER BY fe.fecha_emision DESC, fe.id DESC",
                (int) $factura_id
            ), ARRAY_A);
            $factura['costo_envio_vinculado'] = (float) ($factura['costo_envio_total'] ?? 0);
        }

        $factura['fletes_sin_vincular'] = $wpdb->get_results(
            "SELECT f.id, f.folio, f.fecha_emision, f.monto_total, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.documento_subtipo = 'envio'
               AND NOT EXISTS (
                   SELECT 1 FROM {$vinculos_table} v WHERE v.factura_envio_id = f.id
               )
               AND f.estado NOT IN ('rejected', 'archived')
             ORDER BY f.created_at DESC
             LIMIT 100",
            ARRAY_A
        );

        $factura['facturas_productos_disponibles'] = $wpdb->get_results(
            "SELECT f.id, f.folio, f.fecha_emision, f.monto_total, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL)
               AND f.estado NOT IN ('rejected', 'archived', 'approved')
             ORDER BY f.created_at DESC
             LIMIT 100",
            ARRAY_A
        );

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

        $item = $this->enrich_factura_item_row($item);

        // Verificar que el SKU local existe (producto_base o WooCommerce)
        $product_id = $this->intake()->resolve_product_id_for_local_sku($sku_local, $item->codigo_proveedor);
        if (!$product_id) {
            wp_send_json_error(['message' => 'SKU local no encontrado en catálogo: ' . $sku_local]);
        }

        // Actualizar item
        $wpdb->update(
            "{$prefix}factura_items",
            [
                'sku_local' => $sku_local,
                'product_id' => $product_id,
                'estado' => 'vinculado',
            ],
            ['id' => $item_id],
            ['%s', '%d', '%s'],
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

            $item = $this->enrich_factura_item_row($item);

            if (!$existing) {
                $wpdb->insert(
                    "{$prefix}codigos",
                    [
                        'proveedor_id' => $item->proveedor_id,
                        'codigo_proveedor' => $item->codigo_proveedor,
                        'sku_local' => $sku_local,
                        'product_id' => $product_id,
                        'nombre_proveedor' => $item->descripcion,
                        'activo' => 1,
                    ],
                    ['%d', '%s', '%s', '%d', '%s', '%d']
                );
            }
        }

        // Actualizar estado de factura
        $this->update_invoice_status($item->factura_id);

        $this->intake()->persist_supplier_code(
            (int) $item->proveedor_id,
            $item->codigo_proveedor,
            $item->descripcion,
            [],
            $sku_local
        );

        $wpdb->update(
            "{$prefix}cost_history",
            [
                'product_id' => $product_id,
                'pendiente_vinculacion' => 0,
            ],
            [
                'source_type' => 'invoice',
                'source_item_id' => $item_id,
            ],
            ['%d', '%d'],
            ['%s', '%d']
        );

        if (class_exists('Riverso_Task_Module')) {
            $wpdb->update(
                "{$prefix}tareas",
                ['estado' => 'completada', 'completado_en' => current_time('mysql')],
                [
                    'tipo' => 'codigo_faltante',
                    'referencia_tipo' => 'factura_item',
                    'referencia_id' => $item_id,
                    'estado' => 'pendiente',
                ],
                ['%s', '%s'],
                ['%s', '%s', '%d', '%s']
            );
        }

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

        $this->ensure_flete_vinculos_table();

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
                (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id AND estado = 'vinculado') as items_vinculados,
                (SELECT COUNT(*) FROM {$prefix}factura_flete_vinculos fv WHERE fv.factura_productos_id = f.id) as fletes_vinculados,
                (SELECT COUNT(*) FROM {$prefix}factura_flete_vinculos fv WHERE fv.factura_envio_id = f.id) as facturas_vinculadas
                FROM {$prefix}facturas f
                LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                WHERE {$where_sql}
                ORDER BY f.created_at DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;

        $facturas = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if ($facturas === null) {
            wp_send_json_error(['message' => 'Error al cargar facturas: ' . ($wpdb->last_error ?: 'consulta fallida')]);
        }
        $can_delete = $this->user_can_intake_invoices();

        foreach ($facturas as &$f) {
            $f['can_delete'] = $can_delete && $this->invoice_can_be_deleted($f);
        }
        unset($f);

        wp_send_json_success([
            'facturas' => $facturas,
            'can_delete_invoices' => $can_delete,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }
    
    // ==================== RECEPCIÓN FÍSICA ====================
    
    /**
     * AJAX: Buscar factura por número para iniciar recepción
     */
    public function ajax_search_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_receive_items')) {
            wp_send_json_error(['message' => 'Sin permisos para recepción']);
        }
        
        $folio = sanitize_text_field($_POST['folio'] ?? '');
        $proveedor_search = sanitize_text_field($_POST['proveedor'] ?? '');
        
        if (empty($folio) && empty($proveedor_search)) {
            wp_send_json_error(['message' => 'Ingrese folio o proveedor']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($folio)) {
            $where[] = 'f.folio = %d';
            $params[] = intval($folio);
        }
        
        if (!empty($proveedor_search)) {
            $where[] = '(p.nombre LIKE %s OR p.rut LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($proveedor_search) . '%';
            $params[] = '%' . $wpdb->esc_like($proveedor_search) . '%';
        }
        
        $where_sql = implode(' AND ', $where);
        
        $sql = "SELECT f.*, p.nombre as proveedor_nombre, p.rut as proveedor_rut,
                (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id) as total_items,
                (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id AND item_status = 'pending') as pending_items
                FROM {$prefix}facturas f
                JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                WHERE {$where_sql}
                ORDER BY f.created_at DESC
                LIMIT 20";
        
        $invoices = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        
        wp_send_json_success(['invoices' => $invoices]);
    }
    
    /**
     * AJAX: Iniciar proceso de recepción física
     */
    public function ajax_start_reception() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_receive_items')) {
            wp_send_json_error(['message' => 'Sin permisos para recepción']);
        }
        
        $factura_id = intval($_POST['factura_id'] ?? 0);
        
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID de factura requerido']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Verificar que la factura existe y puede ser recibida
        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ));
        
        if (!$factura) {
            wp_send_json_error(['message' => 'Factura no encontrada']);
        }
        
        $valid_states = ['uploaded', 'pending_reception', 'recibido'];
        if (!in_array($factura->estado, $valid_states)) {
            wp_send_json_error(['message' => 'Esta factura ya está en proceso de recepción o fue aprobada']);
        }
        
        // Actualizar estado de factura
        $wpdb->update(
            "{$prefix}facturas",
            [
                'estado' => 'in_reception',
                'reception_started_at' => current_time('mysql'),
                'reception_started_by' => get_current_user_id()
            ],
            ['id' => $factura_id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        
        // Asegurar que todos los ítems tengan item_status
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}factura_items 
             SET item_status = 'pending', qty_received = 0 
             WHERE factura_id = %d AND (item_status IS NULL OR item_status = '')",
            $factura_id
        ));
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'reception_started',
                'invoice',
                $factura_id,
                ['estado' => $factura->estado],
                ['estado' => 'in_reception']
            );
        }
        
        wp_send_json_success(['message' => 'Recepción iniciada', 'factura_id' => $factura_id]);
    }
    
    /**
     * AJAX: Actualizar recepción de un ítem
     */
    public function ajax_update_item_reception() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_receive_items')) {
            wp_send_json_error(['message' => 'Sin permisos para recepción']);
        }
        
        $item_id = intval($_POST['item_id'] ?? 0);
        $qty_received = floatval($_POST['qty_received'] ?? 0);
        $item_status = sanitize_text_field($_POST['item_status'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        if (!$item_id) {
            wp_send_json_error(['message' => 'ID de ítem requerido']);
        }
        
        $valid_statuses = array_keys(self::ITEM_STATES);
        if (!in_array($item_status, $valid_statuses)) {
            wp_send_json_error(['message' => 'Estado de ítem inválido']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Obtener ítem actual
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT fi.*, f.estado as invoice_status 
             FROM {$prefix}factura_items fi
             JOIN {$prefix}facturas f ON fi.factura_id = f.id
             WHERE fi.id = %d",
            $item_id
        ));
        
        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }
        
        if ($item->invoice_status !== 'in_reception') {
            wp_send_json_error(['message' => 'La factura no está en proceso de recepción']);
        }
        
        $old_data = [
            'qty_received' => $item->qty_received,
            'item_status' => $item->item_status
        ];
        
        // Actualizar ítem
        $wpdb->update(
            "{$prefix}factura_items",
            [
                'qty_received' => $qty_received,
                'item_status' => $item_status,
                'item_notes' => $notes,
                'received_by' => get_current_user_id(),
                'received_at' => current_time('mysql')
            ],
            ['id' => $item_id],
            ['%f', '%s', '%s', '%d', '%s'],
            ['%d']
        );
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'item_received',
                'invoice_item',
                $item_id,
                $old_data,
                ['qty_received' => $qty_received, 'item_status' => $item_status]
            );
        }
        
        // Verificar si todos los ítems fueron procesados
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_items 
             WHERE factura_id = %d AND item_status = 'pending'",
            $item->factura_id
        ));
        
        $all_done = $pending == 0;
        
        wp_send_json_success([
            'message' => 'Ítem actualizado',
            'all_items_processed' => $all_done
        ]);
    }
    
    /**
     * AJAX: Completar proceso de recepción
     */
    public function ajax_complete_reception() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_receive_items')) {
            wp_send_json_error(['message' => 'Sin permisos para recepción']);
        }
        
        $factura_id = intval($_POST['factura_id'] ?? 0);
        
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID de factura requerido']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Verificar que todos los ítems fueron procesados
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_items 
             WHERE factura_id = %d AND item_status = 'pending'",
            $factura_id
        ));
        
        if ($pending > 0) {
            wp_send_json_error(['message' => "Aún hay {$pending} ítems pendientes de revisar"]);
        }
        
        // Actualizar estado de factura
        $wpdb->update(
            "{$prefix}facturas",
            [
                'estado' => 'pending_approval',
                'reception_completed_at' => current_time('mysql'),
                'reception_completed_by' => get_current_user_id()
            ],
            ['id' => $factura_id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'reception_completed',
                'invoice',
                $factura_id,
                null,
                ['estado' => 'pending_approval']
            );
        }
        
        wp_send_json_success(['message' => 'Recepción completada, pendiente aprobación']);
    }
    
    /**
     * AJAX: Aprobar factura (después de recepción)
     */
    public function ajax_approve_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_approve_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos para aprobar']);
        }
        
        $factura_id = intval($_POST['factura_id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID de factura requerido']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Verificar que la factura puede ser aprobada
        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.id as supplier_id FROM {$prefix}facturas f
             JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
             WHERE f.id = %d",
            $factura_id
        ));
        
        if (!$factura) {
            wp_send_json_error(['message' => 'Factura no encontrada']);
        }
        
        $valid_states = ['pending_approval', 'reception_complete', 'procesado'];
        if (!in_array($factura->estado, $valid_states)) {
            wp_send_json_error(['message' => 'Esta factura no puede ser aprobada en su estado actual']);
        }
        
        // Obtener ítems aprobados (solo productos)
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items 
             WHERE factura_id = %d 
             AND item_status IN ('received_ok', 'modified', 'approved')
             AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            $factura_id
        ));
        
        if (riverso_get_setting('prorate_shipping_to_products', true)) {
            $this->intake()->prorate_shipping_costs($factura_id);
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}factura_items 
                 WHERE factura_id = %d 
                 AND item_status IN ('received_ok', 'modified', 'approved')
                 AND (item_tipo = 'producto' OR item_tipo IS NULL)",
                $factura_id
            ));
        }
        
        // Actualizar estado de factura
        $wpdb->update(
            "{$prefix}facturas",
            [
                'estado' => 'approved',
                'approved_at' => current_time('mysql'),
                'approved_by' => get_current_user_id(),
                'approval_notes' => $notes
            ],
            ['id' => $factura_id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
        
        // Procesar efectos de aprobación
        $tasks_created = [];
        $costs_recorded = 0;
        $lotes_created = 0;
        $inventory_entries = 0;
        $update_warehouse = $this->intake()->should_update_warehouse($factura);

        // Historial de costos completo (vinculados y pendientes)
        $cost_result = $this->intake()->record_factura_cost_history($factura_id);
        if (is_array($cost_result)) {
            $costs_recorded = ($cost_result['recorded'] ?? 0) + ($cost_result['pending'] ?? 0);
        }
        
        foreach ($items as $item) {
            $qty = (float) ($item->qty_received ?: $item->cantidad);

            if ($item->codigo_proveedor) {
                $this->intake()->persist_supplier_code(
                    (int) $factura->proveedor_id,
                    $item->codigo_proveedor,
                    $item->descripcion,
                    [],
                    $item->sku_local
                );
            }

            if ($update_warehouse) {
                $lote_id = $this->intake()->create_lote_from_approved_item($factura, $item);
                if ($lote_id && !is_wp_error($lote_id)) {
                    $lotes_created++;
                    $movement_id = $this->intake()->auto_inventory_entry($factura, $item, $lote_id);
                    if ($movement_id && !is_wp_error($movement_id)) {
                        $inventory_entries++;
                    }
                }
            }
            
            $wpdb->update(
                "{$prefix}factura_items",
                [
                    'item_status' => 'approved',
                    'approved_by' => get_current_user_id(),
                    'approved_at' => current_time('mysql')
                ],
                ['id' => $item->id],
                ['%s', '%d', '%s'],
                ['%d']
            );
        }

        // Tareas agrupadas (sin bodegaje si no hay inventario)
        if (class_exists('Riverso_Task_Module') && $update_warehouse) {
            $task_module = Riverso_Task_Module::get_instance();
            $grouped = $task_module->create_tasks_from_approved_invoice($factura_id);
            if (!is_wp_error($grouped)) {
                $tasks_created = array_values($grouped);
            }
        } elseif (class_exists('Riverso_Task_Module')) {
            $tasks_created = $this->intake()->create_supplier_link_tasks($factura_id);
        }
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_approved',
                'invoice',
                $factura_id,
                null,
                [
                    'items_approved' => count($items),
                    'costs_recorded' => $costs_recorded,
                    'lotes_created' => $lotes_created,
                    'inventory_entries' => $inventory_entries,
                    'tasks_created' => count($tasks_created)
                ]
            );
        }
        
        // Disparar recálculo de costos de referencia / precios (Fase 1 precios).
        do_action('riverso_pos_invoice_approved', $factura_id);

        wp_send_json_success([
            'message' => 'Factura aprobada correctamente',
            'items_processed' => count($items),
            'costs_recorded' => $costs_recorded,
            'lotes_created' => $lotes_created,
            'inventory_entries' => $inventory_entries,
            'tasks_created' => count($tasks_created)
        ]);
    }

    /**
     * AJAX: Vincular XML de envío a factura de productos existente.
     */
    public function ajax_link_shipping_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_productos_id = intval($_POST['factura_productos_id'] ?? 0);
        if (!$factura_productos_id) {
            wp_send_json_error(['message' => 'ID de factura de productos requerido']);
        }

        if (empty($_FILES['xml_envio_file'])) {
            wp_send_json_error(['message' => 'Debe subir el XML del transportista']);
        }

        $file = $_FILES['xml_envio_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Error subiendo XML de envío']);
        }

        $factura_envio = $this->parse_dte_xml(file_get_contents($file['tmp_name']));
        if (is_wp_error($factura_envio)) {
            wp_send_json_error(['message' => $factura_envio->get_error_message()]);
        }

        $envio_id = $this->save_invoice($factura_envio, [
            'documento_subtipo' => 'envio',
            'link_to_factura_id' => $factura_productos_id,
        ]);

        if (is_wp_error($envio_id)) {
            wp_send_json_error(['message' => $envio_id->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'XML de envío vinculado y costos prorrateados',
            'factura_envio_id' => $envio_id,
        ]);
    }

    /**
     * AJAX: Vincular factura de flete existente a factura de productos.
     */
    public function ajax_assign_shipping_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_productos_id = intval($_POST['factura_productos_id'] ?? 0);
        $factura_envio_id = intval($_POST['factura_envio_id'] ?? 0);

        if (!$factura_productos_id || !$factura_envio_id) {
            wp_send_json_error(['message' => 'Seleccione factura de productos y flete']);
        }

        if ($factura_productos_id === $factura_envio_id) {
            wp_send_json_error(['message' => 'No puede vincular una factura consigo misma']);
        }

        $result = $this->intake()->link_shipping_invoice($factura_productos_id, $factura_envio_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $this->update_invoice_status($factura_envio_id);

        wp_send_json_success([
            'message' => 'Flete vinculado correctamente',
            'proration' => $result,
        ]);
    }

    /**
     * AJAX: Desvincular factura de flete de su factura de productos.
     */
    public function ajax_unassign_shipping_invoice() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_envio_id = intval($_POST['factura_envio_id'] ?? 0);
        $factura_productos_id = intval($_POST['factura_productos_id'] ?? 0);
        if (!$factura_envio_id) {
            wp_send_json_error(['message' => 'ID de flete requerido']);
        }

        $result = $this->intake()->unlink_shipping_invoice(
            $factura_envio_id,
            $factura_productos_id ?: null
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Flete desvinculado — queda pendiente de asignar']);
    }

    /**
     * AJAX: Guardar opciones de ingreso de facturas (desde configuración).
     */
    public function ajax_save_invoice_settings() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_settings')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        riverso_set_setting('auto_inventory_on_approve', !empty($_POST['auto_inventory_on_approve']));
        riverso_set_setting('create_reception_task_on_upload', !empty($_POST['create_reception_task_on_upload']));
        riverso_set_setting('prorate_shipping_to_products', !empty($_POST['prorate_shipping_to_products']));
        riverso_set_setting('create_link_task_on_upload', !empty($_POST['create_link_task_on_upload']));
        $default_mode = sanitize_text_field($_POST['default_intake_mode'] ?? 'recepcion');
        riverso_set_setting('default_intake_mode', in_array($default_mode, ['recepcion', 'solo_costos'], true) ? $default_mode : 'recepcion');

        wp_send_json_success(['message' => 'Configuración guardada']);
    }
    
    /**
     * AJAX: Obtener estadísticas de recepción
     */
    public function ajax_get_reception_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $stats = [];
        
        // Facturas pendientes de recepción
        $stats['pending_reception'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}facturas 
             WHERE estado IN ('uploaded', 'pending_reception', 'recibido')"
        );
        
        // Facturas en recepción
        $stats['in_reception'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'in_reception'"
        );
        
        // Facturas pendientes de aprobación
        $stats['pending_approval'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pending_approval'"
        );
        
        // Facturas aprobadas este mes
        $stats['approved_this_month'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}facturas 
             WHERE estado = 'approved' 
             AND MONTH(approved_at) = MONTH(CURRENT_DATE()) 
             AND YEAR(approved_at) = YEAR(CURRENT_DATE())"
        );
        
        // Ítems con discrepancias (missing, extra, modified)
        $stats['items_with_issues'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}factura_items 
             WHERE item_status IN ('missing', 'extra', 'modified', 'rejected')"
        );
        
        wp_send_json_success($stats);
    }
}
