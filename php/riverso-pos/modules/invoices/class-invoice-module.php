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
require_once __DIR__ . '/class-credit-note-service.php';
require_once __DIR__ . '/class-payment-service.php';

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
        add_action('wp_ajax_riverso_get_sku_mapping_history', [$this, 'ajax_get_sku_mapping_history']);
        add_action('wp_ajax_riverso_search_sku_catalog', [$this, 'ajax_search_sku_catalog']);
        add_action('wp_ajax_riverso_get_invoices_list', [$this, 'ajax_get_invoices_list']);
        
        // Nuevos handlers para recepción física
        add_action('wp_ajax_riverso_start_reception', [$this, 'ajax_start_reception']);
        add_action('wp_ajax_riverso_update_item_reception', [$this, 'ajax_update_item_reception']);
        add_action('wp_ajax_riverso_complete_reception', [$this, 'ajax_complete_reception']);
        add_action('wp_ajax_riverso_approve_invoice', [$this, 'ajax_approve_invoice']);
        add_action('wp_ajax_riverso_search_invoice', [$this, 'ajax_search_invoice']);
        add_action('wp_ajax_riverso_get_reception_stats', [$this, 'ajax_get_reception_stats']);
        add_action('wp_ajax_riverso_invoices_tab_counts', [$this, 'ajax_tab_counts']);
        add_action('wp_ajax_riverso_link_shipping_invoice', [$this, 'ajax_link_shipping_invoice']);
        add_action('wp_ajax_riverso_assign_shipping_invoice', [$this, 'ajax_assign_shipping_invoice']);
        add_action('wp_ajax_riverso_unassign_shipping_invoice', [$this, 'ajax_unassign_shipping_invoice']);
        add_action('wp_ajax_riverso_save_invoice_settings', [$this, 'ajax_save_invoice_settings']);
        add_action('wp_ajax_riverso_preview_invoice_xml', [$this, 'ajax_preview_invoice_xml']);
        add_action('wp_ajax_riverso_lookup_supplier_rut', [$this, 'ajax_lookup_supplier_rut']);
        add_action('wp_ajax_riverso_repair_invoice_skus', [$this, 'ajax_repair_invoice_skus']);
        add_action('wp_ajax_riverso_delete_invoice', [$this, 'ajax_delete_invoice']);
        add_action('wp_ajax_riverso_update_document_type', [$this, 'ajax_update_document_type']);
        
        // Handlers para pagos agrupados
        add_action('wp_ajax_riverso_create_payment_ticket', [$this, 'ajax_create_payment_ticket']);
        add_action('wp_ajax_riverso_cancel_payment_ticket', [$this, 'ajax_cancel_payment_ticket']);
        add_action('wp_ajax_riverso_get_payment_ticket', [$this, 'ajax_get_payment_ticket']);
        add_action('wp_ajax_riverso_preview_payment_total', [$this, 'ajax_preview_payment_total']);
        add_action('wp_ajax_riverso_download_payment_comprobante', [$this, 'ajax_download_payment_comprobante']);
        add_action('wp_ajax_riverso_search_invoice_folios', [$this, 'ajax_search_invoice_folios']);
        add_action('wp_ajax_riverso_link_credit_note_origin', [$this, 'ajax_link_credit_note_origin']);
        add_action('wp_ajax_riverso_invoice_adjuntos', [$this, 'ajax_invoice_adjuntos']);
    }

    /**
     * Servicio de ingreso XML (envío, códigos, lotes).
     */
    private function intake() {
        return Riverso_Invoice_Intake_Service::get_instance();
    }

    /**
     * Servicio de notas de crédito
     */
    private function credit_notes() {
        return Riverso_Credit_Note_Service::get_instance();
    }

    /**
     * Servicio de pagos agrupados
     */
    private function payments() {
        return Riverso_Payment_Service::get_instance();
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
                'tasa_iva' => (float) ($totales->TasaIVA ?? 19),
                'impuestos_adicionales' => [],
            ],
            'items' => [],
            'referencias' => [],
        ];

        if (isset($totales->ImptoReten)) {
            foreach ($totales->ImptoReten as $imp) {
                $factura['totales']['impuestos_adicionales'][] = [
                    'tipo_imp' => (int) ($imp->TipoImp ?? 0),
                    'tasa_imp' => (float) ($imp->TasaImp ?? 0),
                    'monto_imp' => (float) ($imp->MontoImp ?? 0),
                ];
            }
        }

        // Extraer items (SimpleXML: foreach sobre Detalle itera todos los hermanos)
        if (isset($doc->Detalle)) {
            foreach ($doc->Detalle as $detalle) {
                // Monto es obligatorio; si no existe, saltar
                $monto = (float) ($detalle->MontoItem ?? 0);
                if ($monto == 0) {
                    continue; // Ignorar líneas sin monto
                }

                $cantidad = (float) ($detalle->QtyItem ?? 1);
                $precio = (float) ($detalle->PrcItem ?? 0);
                
                // Si no hay precio unitario pero hay monto y cantidad, calcular
                if ($precio == 0 && $cantidad > 0) {
                    $precio = $monto / $cantidad;
                }

                $dsc_raw = (string) ($detalle->DscItem ?? '');
                $labels = $this->intake()->normalize_item_labels(
                    (string) ($detalle->NmbItem ?? ''),
                    $dsc_raw
                );

                $item = [
                    'numero' => (int) ($detalle->NroLinDet ?? 1),
                    'nombre' => $labels['nombre'],
                    'descripcion' => $labels['descripcion'],
                    'descripcion_raw' => $dsc_raw,
                    'cantidad' => $cantidad,
                    'unidad' => (string) ($detalle->UnmdItem ?? 'UN'),
                    'precio' => $precio,
                    'monto' => $monto,
                    'descuento_porcentaje' => isset($detalle->DescuentoPct) ? (float) $detalle->DescuentoPct : null,
                    'descuento_monto' => isset($detalle->DescuentoMonto) ? (float) $detalle->DescuentoMonto : null,
                    'recargo_porcentaje' => isset($detalle->RecargoPct) ? (float) $detalle->RecargoPct : null,
                    'recargo_monto' => isset($detalle->RecargoMonto) ? (float) $detalle->RecargoMonto : null,
                    'cod_imp_adic' => isset($detalle->CodImpAdic) ? (string) $detalle->CodImpAdic : null,
                    'codigos' => [],
                ];

                // Extraer códigos del item (puede no haber)
                if (isset($detalle->CdgItem)) {
                    foreach ($detalle->CdgItem as $codigo) {
                        $item['codigos'][] = [
                            'tipo' => (string) ($codigo->TpoCodigo ?? 'INT1'),
                            'valor' => (string) ($codigo->VlrCodigo ?? ''),
                        ];
                    }
                }

                $factura['items'][] = $item;
            }
        }

        // Extraer referencias (para notas de crédito y débito)
        if (isset($doc->Referencia)) {
            foreach ($doc->Referencia as $ref) {
                $factura['referencias'][] = [
                    'numero_linea' => (int) ($ref->NroLinRef ?? 1),
                    'tipo_doc_ref' => (int) ($ref->TpoDocRef ?? 0),
                    'folio_ref' => (string) ($ref->FolioRef ?? '0'),
                    'ind_global' => (int) ($ref->IndGlobal ?? 0),
                    'cod_ref' => (int) ($ref->CodRef ?? null),
                    'razon_ref' => (string) ($ref->RazonRef ?? ''),
                    'fecha_ref' => (string) ($ref->FchRef ?? ''),
                ];
            }
        }

        $this->intake()->classify_factura_items($factura);
        $this->intake()->enrich_factura_items_costs($factura);

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

        // Detectar si es Nota de Crédito (TipoDTE=61)
        $is_credit_note = $this->credit_notes()->is_credit_note($factura_data['tipo_dte'] ?? 0);
        
        // Resolver factura origen (automática, manual o pendiente — nunca bloquea el guardado)
        if ($is_credit_note) {
            $cn_options = [];
            $resolucion = $this->credit_notes()->resolve_origen_factura(
                $factura_data,
                $factura_data['emisor']['rut'] ?? '',
                'automatica'
            );
            $factura_origen_manual = intval($options['factura_origen_id'] ?? 0);

            if ($factura_origen_manual > 0) {
                $cn_options['factura_origen_id'] = $factura_origen_manual;
                $cn_options['estado_resolucion'] = 'resuelta_manual';
            } elseif ($resolucion['estado'] === 'resuelta_automatica' && !empty($resolucion['factura_id'])) {
                $cn_options['factura_origen_id'] = (int) $resolucion['factura_id'];
                $cn_options['estado_resolucion'] = 'resuelta_automatica';
            } else {
                $cn_options['factura_origen_id'] = 0;
                $cn_options['estado_resolucion'] = $resolucion['estado'] === 'ambigua' ? 'ambigua' : 'pendiente';
                $cn_options['resolucion_mensaje'] = $resolucion['mensaje'] ?? '';
            }
            $cn_options['resolucion'] = $resolucion;
            $options['_credit_note_options'] = $cn_options;
        }

        $this->intake()->classify_factura_items($factura_data);
        if (empty($factura_data['items'][0]['costo_neto_final'] ?? null)) {
            $this->intake()->enrich_factura_items_costs($factura_data);
        }

        $force_subtipo = sanitize_text_field($options['documento_subtipo'] ?? '');
        $link_to_factura_id = intval($options['link_to_factura_id'] ?? 0);
        $modo_ingreso = sanitize_text_field($options['modo_ingreso'] ?? riverso_get_setting('default_intake_mode', 'solo_costos'));
        if (!in_array($modo_ingreso, ['recepcion', 'solo_costos'], true)) {
            $modo_ingreso = 'solo_costos';
        }

        $is_guia_despacho = ((int) ($factura_data['tipo_dte'] ?? 0) === 52)
            || $force_subtipo === 'guia_despacho';

        // Si es Nota de Crédito (TipoDTE=61), forzar subtipo
        if ($is_credit_note) {
            $documento_subtipo = 'nota_credito';
            $modo_ingreso = 'solo_costos'; // Las NC siempre son solo costos
        } elseif ($is_guia_despacho) {
            // Guía de despacho (TipoDTE=52): códigos + costos, sin bodega
            $documento_subtipo = 'guia_despacho';
            $modo_ingreso = 'solo_costos';
        } else {
            $product_items = array_filter($factura_data['items'], function ($item) {
                return !in_array(($item['item_tipo'] ?? 'producto'), ['envio', 'gasto'], true);
            });
            $all_shipping = count($product_items) === 0 && !empty($factura_data['items'])
                && count(array_filter($factura_data['items'], function ($item) {
                    return ($item['item_tipo'] ?? '') === 'envio';
                })) === count($factura_data['items']);
            $documento_subtipo = $force_subtipo ?: ($all_shipping ? 'envio' : 'productos');
            if (!in_array($documento_subtipo, ['productos', 'envio', 'nota_credito', 'gastos', 'guia_despacho'], true)) {
                $documento_subtipo = 'productos';
            }
        }

        if ($documento_subtipo === 'gastos') {
            $this->intake()->force_expense_items($factura_data);
            $modo_ingreso = 'solo_costos';
        }
        if ($documento_subtipo === 'guia_despacho') {
            $modo_ingreso = 'solo_costos';
        }

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

        // Determinar estado inicial según tipo de documento
        if ($documento_subtipo === 'nota_credito') {
            $cn_opts = $options['_credit_note_options'] ?? [];
            $estado_inicial = !empty($cn_opts['factura_origen_id']) ? 'recibido' : 'sin_vincular';
        } elseif ($documento_subtipo === 'envio') {
            $estado_inicial = 'sin_vincular';
        } elseif ($documento_subtipo === 'gastos') {
            $estado_inicial = 'procesado';
        } else {
            $estado_inicial = 'recibido';
        }

        $tasa_iva = (float) ($factura_data['totales']['tasa_iva'] ?? 19);
        $impuestos_json = !empty($factura_data['totales']['impuestos_adicionales'])
            ? wp_json_encode($factura_data['totales']['impuestos_adicionales'])
            : null;

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
                'tasa_iva' => $tasa_iva,
                'impuestos_adicionales' => $impuestos_json,
                'items_total' => count($factura_data['items']),
                'estado' => $estado_inicial,
                'procesado_por' => get_current_user_id(),
                'procesado_at' => current_time('mysql'),
                'documento_subtipo' => $documento_subtipo,
                'factura_productos_id' => null,
                'costo_envio_total' => $documento_subtipo === 'envio' ? 0 : $costo_envio_inline,
                'modo_ingreso' => in_array($documento_subtipo, ['productos', 'guia_despacho'], true)
                    ? ($documento_subtipo === 'guia_despacho' ? 'solo_costos' : $modo_ingreso)
                    : 'solo_costos',
                'tipo_confirmado' => !empty($options['tipo_confirmado']) ? 1 : 0,
                'origen_ingreso'  => sanitize_text_field($options['origen_ingreso'] ?? 'xml'),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%d', '%s']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error guardando factura: ' . $wpdb->last_error);
        }

        $factura_id = $wpdb->insert_id;

        // Insertar items
        foreach ($factura_data['items'] as $item) {
            $item_tipo = $item['item_tipo'] ?? 'producto';
            if ($documento_subtipo === 'gastos') {
                $item_tipo = 'gasto';
            }
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
            // Gastos operacionales: no mapear a productos ni asignar SKU
            if ($item_tipo === 'producto' && $codigo_proveedor) {
                $mapping = $this->intake()->lookup_product_mapping(
                    $proveedor_id,
                    $codigo_proveedor,
                    $item['codigos'] ?? []
                );
                $codigo_local = riverso_usable_local_sku($mapping['sku_local'] ?? null, $codigo_proveedor);
                if ($codigo_local) {
                    $conflict = $this->intake()->get_sku_assignment_conflict(
                        $codigo_local,
                        $proveedor_id,
                        $codigo_proveedor
                    );
                    if ($conflict && ($conflict['code'] ?? '') === 'sku_owned_elsewhere') {
                        $codigo_local = null;
                    }
                }
                if ($codigo_local) {
                    $product_id = $mapping['product_id']
                        ?? $this->intake()->resolve_product_id_for_local_sku($codigo_local, $codigo_proveedor);
                }
            }

            $item_nombre = trim($item['nombre'] ?? '') ?: trim($item['descripcion'] ?? '') ?: 'Sin descripción';
            $labels = $this->intake()->normalize_item_labels($item_nombre, $item['descripcion'] ?? '');
            $item_nombre = $labels['nombre'];
            $item_descripcion = $labels['descripcion'];

            if ($item_tipo === 'envio') {
                $item_estado = 'envio';
            } elseif ($item_tipo === 'gasto') {
                $item_estado = 'gasto';
            } else {
                $item_estado = $codigo_local ? 'vinculado' : 'pendiente';
            }

            $costs = $this->intake()->compute_item_cost_breakdown($item, $tasa_iva);
            $landed_unit = $item_tipo === 'producto'
                ? ($costs['costo_unitario_neto_final'] ?? $item['precio'])
                : null;

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
                    'descuento_porcentaje' => $costs['descuento_porcentaje'],
                    'descuento_monto' => $costs['descuento_monto'],
                    'recargo_porcentaje' => $costs['recargo_porcentaje'],
                    'recargo_monto' => $costs['recargo_monto'],
                    'cod_imp_adic' => $item['cod_imp_adic'] ?? null,
                    'impuesto_especifico_tasa' => $costs['impuesto_especifico_tasa'],
                    'impuesto_especifico_monto' => $costs['impuesto_especifico_monto'],
                    'costo_neto_base' => $costs['costo_neto_base'],
                    'costo_bruto_base' => $costs['costo_bruto_base'],
                    'costo_neto_final' => $costs['costo_neto_final'],
                    'costo_bruto_final' => $costs['costo_bruto_final'],
                    'monto_total' => $item['monto'],
                    'product_id' => $product_id,
                    'sku_local' => $codigo_local,
                    'estado' => $item_estado,
                    'item_tipo' => $item_tipo,
                    'costo_landed_unitario' => $landed_unit,
                ],
                [
                    '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%f',
                    '%f', '%f', '%f', '%f', '%s', '%f', '%f',
                    '%f', '%f', '%f', '%f', '%f',
                    '%d', '%s', '%s', '%s', '%f',
                ]
            );
        }

        // Si es Nota de Crédito, registrar referencia (vinculada o pendiente de folio)
        $cn_link_meta = null;
        if ($is_credit_note) {
            $cn_options = $options['_credit_note_options'] ?? [];
            $factura_origen_id = intval($cn_options['factura_origen_id'] ?? 0);
            $referencias = $factura_data['referencias'] ?? [];
            $referencia = !empty($referencias) ? $referencias[0] : [
                'tipo_doc_ref' => 33,
                'folio_ref' => '0',
            ];

            $cn_link_meta = $this->credit_notes()->register_reference(
                $factura_id,
                $referencia,
                $factura_origen_id > 0 ? $factura_origen_id : null,
                [
                    'user_id' => get_current_user_id(),
                    'reversa_inventario' => !empty($options['reversa_inventario']),
                    'estado_resolucion' => $cn_options['estado_resolucion'] ?? ($factura_origen_id ? 'resuelta_manual' : 'pendiente'),
                    'notas' => $cn_options['resolucion_mensaje'] ?? '',
                ]
            );

            if (is_wp_error($cn_link_meta)) {
                // NC ya guardada: no revertir; devolver advertencia con ID
                return new WP_Error('warning', 'NC guardada pero no se pudo registrar la referencia: ' . $cn_link_meta->get_error_message(), [
                    'factura_id' => $factura_id,
                    'error_detalles' => $cn_link_meta->get_error_data(),
                ]);
            }
        }

        if ($documento_subtipo === 'nota_credito') {
            // Las NC no generan recepción ni lotes por defecto
        } elseif ($documento_subtipo === 'gastos') {
            // Gastos operacionales: solo registro documental, sin productos/SKU/bodega
        } elseif ($link_to_factura_id && $documento_subtipo === 'envio') {
            $link_result = $this->intake()->link_shipping_invoice($link_to_factura_id, $factura_id);
            if (is_wp_error($link_result)) {
                $wpdb->delete("{$prefix}factura_items", ['factura_id' => $factura_id], ['%d']);
                $wpdb->delete("{$prefix}facturas", ['id' => $factura_id], ['%d']);
                return $link_result;
            }
        } elseif (in_array($documento_subtipo, ['productos', 'guia_despacho'], true)) {
            // Productos o guía: códigos + costos; guía siempre sin bodega (solo_costos)
            $this->intake()->after_invoice_saved(
                $factura_id,
                $proveedor_id,
                $factura_data['items'],
                $documento_subtipo === 'guia_despacho' ? 'solo_costos' : $modo_ingreso
            );
        }

        // Tarea de confirmar tipo: todos los XML no confirmados (incluye carga masiva y flete/NC/gastos).
        if (empty($options['tipo_confirmado'])) {
            $this->intake()->create_document_type_confirmation_task((int) $factura_id);
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
                    'modo_ingreso' => in_array($documento_subtipo, ['productos', 'guia_despacho'], true)
                        ? ($documento_subtipo === 'guia_despacho' ? 'solo_costos' : $modo_ingreso)
                        : 'solo_costos',
                    'monto_total' => $factura_data['totales']['total'],
                ],
                sprintf('Factura folio %s ingresada por XML', $folio)
            );
        }

        return $factura_id;
    }

    /**
     * Une un XML a una factura existente (p. ej. creada por escaneo).
     * El XML manda en cabecera; ítems solo si no hay recepción.
     * Excepción: XML rescatado del SII (sin detalle) — el escaneo manda en ítems.
     *
     * @return array|WP_Error {factura_id, merged, items_updated, warning?, scans_linked}
     */
    public function merge_xml_into_factura($factura_id, array $factura_data, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_id = (int) $factura_id;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $this->intake()->classify_factura_items($factura_data);
        if (empty($factura_data['items'][0]['costo_neto_final'] ?? null)) {
            $this->intake()->enrich_factura_items_costs($factura_data);
        }

        $force_subtipo = sanitize_text_field($options['documento_subtipo'] ?? '');
        $modo_ingreso = sanitize_text_field($options['modo_ingreso'] ?? ($row['modo_ingreso'] ?? 'solo_costos'));
        if (!in_array($modo_ingreso, ['recepcion', 'solo_costos'], true)) {
            $modo_ingreso = 'solo_costos';
        }

        $is_credit_note = $this->credit_notes()->is_credit_note($factura_data['tipo_dte'] ?? 0);
        $is_guia = ((int) ($factura_data['tipo_dte'] ?? 0) === 52) || $force_subtipo === 'guia_despacho';

        if ($is_credit_note) {
            $documento_subtipo = 'nota_credito';
            $modo_ingreso = 'solo_costos';
        } elseif ($is_guia) {
            $documento_subtipo = 'guia_despacho';
            $modo_ingreso = 'solo_costos';
        } elseif ($force_subtipo && in_array($force_subtipo, ['productos', 'envio', 'gastos', 'guia_despacho', 'nota_credito'], true)) {
            $documento_subtipo = $force_subtipo;
        } else {
            $documento_subtipo = $row['documento_subtipo'] ?: 'productos';
        }
        if ($documento_subtipo === 'gastos') {
            $this->intake()->force_expense_items($factura_data);
            $modo_ingreso = 'solo_costos';
        }

        $proveedor_id = $this->resolve_proveedor_for_upload($factura_data['emisor'], $options);
        if (is_wp_error($proveedor_id)) {
            return $proveedor_id;
        }

        $rut_emisor = sanitize_text_field($factura_data['emisor']['rut'] ?? '');
        $folio = (string) $factura_data['folio'];
        $tasa_iva = (float) ($factura_data['totales']['tasa_iva'] ?? 19);
        $impuestos_json = !empty($factura_data['totales']['impuestos_adicionales'])
            ? wp_json_encode($factura_data['totales']['impuestos_adicionales'])
            : null;
        $costo_envio_inline = (float) ($factura_data['costo_envio_inline'] ?? 0);

        $wpdb->update("{$prefix}facturas", [
            'tipo_dte'            => (int) $factura_data['tipo_dte'],
            'folio'               => $folio,
            'proveedor_id'        => $proveedor_id,
            'rut_emisor'          => $rut_emisor,
            'razon_social_emisor' => sanitize_text_field($factura_data['emisor']['razon_social'] ?? ''),
            'fecha_emision'       => $factura_data['fecha_emision'],
            'monto_neto'          => $factura_data['totales']['neto'],
            'monto_iva'           => $factura_data['totales']['iva'],
            'monto_total'         => $factura_data['totales']['total'],
            'tasa_iva'            => $tasa_iva,
            'impuestos_adicionales' => $impuestos_json,
            'documento_subtipo'   => $documento_subtipo,
            'costo_envio_total'   => $documento_subtipo === 'envio'
                ? (float) ($row['costo_envio_total'] ?? 0)
                : $costo_envio_inline,
            'tipo_confirmado'     => !empty($options['tipo_confirmado']) ? 1 : (int) ($row['tipo_confirmado'] ?? 0),
        ], ['id' => $factura_id]);

        riverso_factura_mark_xml_attached($factura_id);

        $items_updated = false;
        $warning = null;
        $xml_is_sii_stub = function_exists('riverso_factura_data_is_sii_rescued_stub')
            && riverso_factura_data_is_sii_rescued_stub($factura_data);
        $origen_prev = sanitize_text_field($row['origen_ingreso'] ?? 'xml');
        $scan_is_truth = $xml_is_sii_stub && (
            in_array($origen_prev, ['escaneo', 'ambos'], true)
            || !(function_exists('riverso_factura_db_is_sii_rescued_stub')
                && riverso_factura_db_is_sii_rescued_stub($factura_id))
        );

        $can_replace = $this->factura_safe_to_replace_items($factura_id, $row);
        if ($can_replace && $scan_is_truth) {
            $can_replace = false;
            $warning = 'XML rescatado del SII (sin detalle): se mantiene el detalle del escaneo.';
        }

        if ($can_replace) {
            $this->replace_factura_items_from_data(
                $factura_id,
                $proveedor_id,
                $factura_data['items'] ?? [],
                $documento_subtipo,
                $tasa_iva,
                $modo_ingreso
            );
            $items_updated = true;
        } elseif ($warning === null) {
            $warning = 'XML unido, pero no se actualizaron ítems porque la factura ya tiene recepción o no está en solo costos.';
        }

        $link = function_exists('riverso_link_scans_to_factura')
            ? riverso_link_scans_to_factura($factura_id, $factura_data['tipo_dte'], $folio, $rut_emisor)
            : ['linked' => 0, 'doc_ids' => []];

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_xml_merged',
                'invoice',
                $factura_id,
                null,
                [
                    'folio'          => $folio,
                    'items_updated'  => $items_updated,
                    'scans_linked'   => $link['linked'] ?? 0,
                    'origen_prev'    => $row['origen_ingreso'] ?? '',
                    'sii_stub_xml'   => $xml_is_sii_stub,
                    'scan_is_truth'  => $scan_is_truth,
                ],
                sprintf('XML unido a factura folio %s', $folio)
            );
        }

        // Si el XML es stub SII y hay escaneos con detalle, aplicar el escaneo como verdad de ítems
        if ($xml_is_sii_stub && !empty($link['doc_ids'])) {
            $applied = $this->try_apply_linked_scan_detail($factura_id, $link['doc_ids'], $options);
            if (!empty($applied['items_updated'])) {
                $items_updated = true;
                $warning = $applied['message'] ?? 'Detalle tomado del escaneo (XML SII sin líneas).';
            }
        }

        return [
            'factura_id'     => $factura_id,
            'merged'         => true,
            'items_updated'  => $items_updated,
            'warning'        => $warning,
            'scans_linked'   => (int) ($link['linked'] ?? 0),
            'sii_stub_xml'   => $xml_is_sii_stub,
            'scan_is_truth'  => $scan_is_truth,
            'folio'          => $folio,
            'proveedor'      => $factura_data['emisor']['razon_social'] ?? ($row['razon_social_emisor'] ?? ''),
            'total'          => $factura_data['totales']['total'] ?? $row['monto_total'],
            'documento_tipo' => $documento_subtipo,
        ];
    }

    /**
     * Une un escaneo con detalle a una factura cuyo XML es stub SII (sin líneas).
     *
     * @return array|WP_Error
     */
    public function merge_scan_into_factura($factura_id, array $factura_data, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_id = (int) $factura_id;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        if (!function_exists('riverso_factura_db_is_sii_rescued_stub')
            || !riverso_factura_db_is_sii_rescued_stub($factura_id)) {
            return new WP_Error(
                'not_sii_stub',
                'La factura ya tiene detalle; el escaneo solo se adjunta como respaldo.'
            );
        }

        if (!$this->factura_safe_to_replace_items($factura_id, $row)) {
            return new WP_Error(
                'unsafe_replace',
                'No se puede reemplazar el detalle: la factura tiene recepción o no está en solo costos.'
            );
        }

        $this->intake()->classify_factura_items($factura_data);
        if (empty($factura_data['items'][0]['costo_neto_final'] ?? null)) {
            $this->intake()->enrich_factura_items_costs($factura_data);
        }

        $documento_subtipo = sanitize_text_field(
            $options['documento_subtipo'] ?? ($row['documento_subtipo'] ?: 'productos')
        );
        $modo_ingreso = sanitize_text_field(
            $options['modo_ingreso'] ?? ($row['modo_ingreso'] ?? 'solo_costos')
        );
        if (!in_array($modo_ingreso, ['recepcion', 'solo_costos'], true)) {
            $modo_ingreso = 'solo_costos';
        }
        $tasa_iva = (float) ($factura_data['totales']['tasa_iva'] ?? ($row['tasa_iva'] ?? 19));
        $proveedor_id = (int) ($row['proveedor_id'] ?? 0);
        if ($proveedor_id <= 0) {
            $proveedor_id = $this->resolve_proveedor_for_upload($factura_data['emisor'] ?? [], $options);
            if (is_wp_error($proveedor_id)) {
                return $proveedor_id;
            }
        }

        $this->replace_factura_items_from_data(
            $factura_id,
            $proveedor_id,
            $factura_data['items'] ?? [],
            $documento_subtipo,
            $tasa_iva,
            $modo_ingreso
        );

        // Cabecera fiscal del XML se conserva; solo se refrescan montos si el escaneo trae totales útiles
        $totales = $factura_data['totales'] ?? [];
        $update = [];
        if (!empty($totales['neto']) || !empty($totales['total'])) {
            if (isset($totales['neto'])) {
                $update['monto_neto'] = (float) $totales['neto'];
            }
            if (isset($totales['iva'])) {
                $update['monto_iva'] = (float) $totales['iva'];
            }
            if (isset($totales['total'])) {
                $update['monto_total'] = (float) $totales['total'];
            }
        }
        if ($update) {
            $wpdb->update("{$prefix}facturas", $update, ['id' => $factura_id]);
        }

        riverso_factura_mark_scan_attached($factura_id);

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_scan_merged_over_sii_stub',
                'invoice',
                $factura_id,
                null,
                [
                    'folio'         => $row['folio'] ?? '',
                    'items_count'   => count($factura_data['items'] ?? []),
                    'origen_prev'   => $row['origen_ingreso'] ?? '',
                ],
                sprintf('Detalle de escaneo aplicado sobre XML SII stub folio %s', $row['folio'] ?? '')
            );
        }

        return [
            'factura_id'    => $factura_id,
            'merged'        => true,
            'items_updated' => true,
            'message'       => 'Detalle del escaneo aplicado (XML SII sin líneas de detalle).',
        ];
    }

    /**
     * Si hay documentos escaneados vinculados con JSON normalizado, aplica su detalle.
     */
    private function try_apply_linked_scan_detail($factura_id, array $doc_ids, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        if (!function_exists('riverso_factura_db_is_sii_rescued_stub')
            || !riverso_factura_db_is_sii_rescued_stub($factura_id)) {
            return ['items_updated' => false];
        }

        foreach ($doc_ids as $doc_id) {
            $doc = $wpdb->get_row($wpdb->prepare(
                "SELECT datos_json FROM {$prefix}documentos_escaneados WHERE id = %d",
                (int) $doc_id
            ), ARRAY_A);
            if (!$doc) {
                continue;
            }
            $payload = json_decode($doc['datos_json'] ?? '{}', true) ?: [];
            $factura = $payload['normalized'] ?? null;
            if (!$factura || empty($factura['items']) || !is_array($factura['items'])) {
                continue;
            }
            if (function_exists('riverso_factura_data_is_sii_rescued_stub')
                && riverso_factura_data_is_sii_rescued_stub($factura)) {
                continue;
            }
            $merge = $this->merge_scan_into_factura($factura_id, $factura, $options);
            if (!is_wp_error($merge) && !empty($merge['items_updated'])) {
                return $merge;
            }
        }
        return ['items_updated' => false];
    }

    /**
     * Reemplaza ítems de una factura (borra + inserta + after_invoice_saved).
     */
    private function replace_factura_items_from_data(
        $factura_id,
        $proveedor_id,
        array $items,
        $documento_subtipo,
        $tasa_iva,
        $modo_ingreso
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $item_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}factura_items WHERE factura_id = %d",
            $factura_id
        ));
        if ($item_ids) {
            $in = implode(',', array_map('intval', $item_ids));
            $wpdb->query(
                "DELETE FROM {$prefix}tareas
                 WHERE referencia_tipo = 'factura_item' AND referencia_id IN ({$in})"
            );
        }
        $wpdb->delete("{$prefix}cost_history", [
            'source_type' => 'invoice',
            'source_document_id' => $factura_id,
        ], ['%s', '%d']);
        $wpdb->delete("{$prefix}factura_items", ['factura_id' => $factura_id], ['%d']);

        $this->insert_factura_items_rows(
            $factura_id,
            $proveedor_id,
            $items,
            $documento_subtipo,
            $tasa_iva
        );

        $wpdb->update("{$prefix}facturas", [
            'items_total'  => count($items),
            'modo_ingreso' => in_array($documento_subtipo, ['productos', 'guia_despacho'], true)
                ? ($documento_subtipo === 'guia_despacho' ? 'solo_costos' : $modo_ingreso)
                : 'solo_costos',
        ], ['id' => $factura_id]);

        if (in_array($documento_subtipo, ['productos', 'guia_despacho'], true)) {
            $this->intake()->after_invoice_saved(
                $factura_id,
                $proveedor_id,
                $items,
                $documento_subtipo === 'guia_despacho' ? 'solo_costos' : $modo_ingreso
            );
        }
        $this->update_invoice_status($factura_id);
    }

    /**
     * ¿Se pueden reemplazar ítems con datos del XML sin romper recepción?
     */
    private function factura_safe_to_replace_items($factura_id, array $row) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $estado = $row['estado'] ?? '';
        if (!in_array($estado, ['recibido', 'sin_vincular'], true)) {
            return false;
        }
        if (($row['modo_ingreso'] ?? '') !== 'solo_costos') {
            return false;
        }
        $received = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cantidad_recibida), 0) FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $factura_id
        ));
        return $received <= 0;
    }

    /**
     * Inserta filas de factura_items (misma lógica que save_invoice).
     */
    private function insert_factura_items_rows($factura_id, $proveedor_id, array $items, $documento_subtipo, $tasa_iva) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        foreach ($items as $item) {
            $item_tipo = $item['item_tipo'] ?? 'producto';
            if ($documento_subtipo === 'gastos') {
                $item_tipo = 'gasto';
            }
            $codigo_proveedor = '';
            $codigo_tipo = 'INT1';
            foreach (($item['codigos'] ?? []) as $codigo) {
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
                $codigo_local = riverso_usable_local_sku($mapping['sku_local'] ?? null, $codigo_proveedor);
                if ($codigo_local) {
                    $conflict = $this->intake()->get_sku_assignment_conflict(
                        $codigo_local,
                        $proveedor_id,
                        $codigo_proveedor
                    );
                    if ($conflict && ($conflict['code'] ?? '') === 'sku_owned_elsewhere') {
                        $codigo_local = null;
                    }
                }
                if ($codigo_local) {
                    $product_id = $mapping['product_id']
                        ?? $this->intake()->resolve_product_id_for_local_sku($codigo_local, $codigo_proveedor);
                }
            }

            $item_nombre = trim($item['nombre'] ?? '') ?: trim($item['descripcion'] ?? '') ?: 'Sin descripción';
            $labels = $this->intake()->normalize_item_labels($item_nombre, $item['descripcion'] ?? '');
            $item_nombre = $labels['nombre'];
            $item_descripcion = $labels['descripcion'];

            if ($item_tipo === 'envio') {
                $item_estado = 'envio';
            } elseif ($item_tipo === 'gasto') {
                $item_estado = 'gasto';
            } else {
                $item_estado = $codigo_local ? 'vinculado' : 'pendiente';
            }

            $costs = $this->intake()->compute_item_cost_breakdown($item, $tasa_iva);
            $landed_unit = $item_tipo === 'producto'
                ? ($costs['costo_unitario_neto_final'] ?? $item['precio'])
                : null;

            $wpdb->insert(
                "{$prefix}factura_items",
                [
                    'factura_id' => $factura_id,
                    'numero_linea' => $item['numero'] ?? 1,
                    'codigo_proveedor' => $codigo_proveedor,
                    'codigo_tipo' => $codigo_tipo,
                    'nombre' => $item_nombre,
                    'descripcion' => $item_descripcion,
                    'cantidad' => $item['cantidad'] ?? 0,
                    'unidad' => $item['unidad'] ?? '',
                    'precio_unitario' => $item['precio'] ?? 0,
                    'descuento_porcentaje' => $costs['descuento_porcentaje'],
                    'descuento_monto' => $costs['descuento_monto'],
                    'recargo_porcentaje' => $costs['recargo_porcentaje'],
                    'recargo_monto' => $costs['recargo_monto'],
                    'cod_imp_adic' => $item['cod_imp_adic'] ?? null,
                    'impuesto_especifico_tasa' => $costs['impuesto_especifico_tasa'],
                    'impuesto_especifico_monto' => $costs['impuesto_especifico_monto'],
                    'costo_neto_base' => $costs['costo_neto_base'],
                    'costo_bruto_base' => $costs['costo_bruto_base'],
                    'costo_neto_final' => $costs['costo_neto_final'],
                    'costo_bruto_final' => $costs['costo_bruto_final'],
                    'monto_total' => $item['monto'] ?? 0,
                    'product_id' => $product_id,
                    'sku_local' => $codigo_local,
                    'estado' => $item_estado,
                    'item_tipo' => $item_tipo,
                    'costo_landed_unitario' => $landed_unit,
                ]
            );
        }
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

        // Las NC: vinculado al folio origen o pendiente
        if ($factura && ($factura->documento_subtipo ?? '') === 'nota_credito') {
            $has_origin = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}factura_referencias
                 WHERE factura_id = %d AND factura_origen_id IS NOT NULL AND factura_origen_id > 0",
                (int) $factura_id
            ));
            $estado_nc = $has_origin > 0 ? 'recibido' : 'sin_vincular';
            $wpdb->update(
                "{$prefix}facturas",
                ['estado' => $estado_nc],
                ['id' => (int) $factura_id],
                ['%s'],
                ['%d']
            );
            return $estado_nc;
        }

        // Gastos operacionales quedan procesados (sin flujo de SKU)
        if ($factura && ($factura->documento_subtipo ?? '') === 'gastos') {
            $wpdb->update(
                "{$prefix}facturas",
                ['estado' => 'procesado'],
                ['id' => (int) $factura_id],
                ['%s'],
                ['%d']
            );
            return 'procesado';
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

        $documento_tipo = sanitize_text_field($_POST['documento_tipo'] ?? 'por_confirmar');
        $upload_mode = sanitize_text_field($_POST['upload_mode'] ?? 'single');
        $is_bulk = $upload_mode === 'bulk';

        $tipos_validos = ['productos', 'envio', 'nota_credito', 'gastos', 'guia_despacho'];
        $tipo_confirmado = 0;
        if ($documento_tipo === 'por_confirmar' || $documento_tipo === '') {
            $sugerido = sanitize_text_field($_POST['tipo_sugerido'] ?? 'productos');
            $documento_tipo = in_array($sugerido, $tipos_validos, true) ? $sugerido : 'productos';
            $tipo_confirmado = 0;
        } elseif (!in_array($documento_tipo, $tipos_validos, true)) {
            $documento_tipo = 'productos';
            $tipo_confirmado = 0;
        } else {
            // Carga individual: eligió un tipo concreto. Carga masiva: siempre queda pendiente.
            $tipo_confirmado = $is_bulk ? 0 : 1;
        }

        if (isset($_POST['tipo_confirmado']) && $_POST['tipo_confirmado'] !== '') {
            $tipo_confirmado = $is_bulk ? 0 : (intval($_POST['tipo_confirmado']) ? 1 : 0);
        }

        $link_to_factura_id = intval($_POST['link_to_factura_id'] ?? 0);
        $factura_origen_id = intval($_POST['factura_origen_id'] ?? 0);
        
        // Default: sin aumento de inventario (solo costos), en carga individual y masiva
        $modo_ingreso = sanitize_text_field($_POST['modo_ingreso'] ?? 'solo_costos');
        if (!in_array($modo_ingreso, ['recepcion', 'solo_costos'], true)) {
            $modo_ingreso = 'solo_costos';
        }
        
        // Forzar solo_costos para envíos, NC, gastos y guías de despacho
        if (in_array($documento_tipo, ['envio', 'nota_credito', 'gastos', 'guia_despacho'], true)) {
            $modo_ingreso = 'solo_costos';
        }

        $save_options = [
            'link_to_factura_id' => $documento_tipo === 'envio' ? $link_to_factura_id : 0,
            'factura_origen_id' => $documento_tipo === 'nota_credito' ? $factura_origen_id : 0,
            'reversa_inventario' => $documento_tipo === 'nota_credito' && !empty($_POST['reversa_inventario']),
            'documento_subtipo' => $documento_tipo,
            'tipo_confirmado' => $tipo_confirmado,
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

        $save_options['origen_ingreso'] = 'xml';

        // Si ya existe factura (p. ej. creada por escaneo), unir XML en vez de error duplicado
        $existing_row = function_exists('riverso_find_factura_by_dte')
            ? riverso_find_factura_by_dte(
                $factura['tipo_dte'] ?? 0,
                $factura['folio'] ?? '',
                $factura['emisor']['rut'] ?? ''
            )
            : null;

        if ($existing_row) {
            $merge = $this->merge_xml_into_factura((int) $existing_row['id'], $factura, $save_options);
            if (is_wp_error($merge)) {
                $data = $merge->get_error_data();
                if (!empty($data['needs_input'])) {
                    wp_send_json_error([
                        'message' => $merge->get_error_message(),
                        'needs_input' => true,
                        'gaps' => $data['gaps'] ?? [],
                    ]);
                }
                wp_send_json_error(['message' => $merge->get_error_message()]);
            }

            $msg = sprintf(
                'XML unido a factura de escaneo #%s',
                $merge['folio'] ?? ($existing_row['folio'] ?? $existing_row['id'])
            );
            if (!empty($merge['warning'])) {
                $msg .= ' — ' . $merge['warning'];
            } elseif (!empty($merge['items_updated'])) {
                $msg .= ' — ítems y montos actualizados desde XML';
            }

            wp_send_json_success([
                'message'      => $msg,
                'factura_id'   => (int) $merge['factura_id'],
                'merged'       => true,
                'items_updated'=> !empty($merge['items_updated']),
                'warning'      => $merge['warning'] ?? null,
                'modo_ingreso' => $modo_ingreso,
                'resumen'      => [
                    'proveedor'      => $merge['proveedor'] ?? ($factura['emisor']['razon_social'] ?? ''),
                    'folio'          => $merge['folio'] ?? $factura['folio'],
                    'total'          => $merge['total'] ?? ($factura['totales']['total'] ?? 0),
                    'documento_tipo' => $merge['documento_tipo'] ?? $documento_tipo,
                    'items'          => count(array_filter($factura['items'] ?? [], function ($i) {
                        return ($i['item_tipo'] ?? 'producto') !== 'envio';
                    })),
                    'items_envio'    => count(array_filter($factura['items'] ?? [], function ($i) {
                        return ($i['item_tipo'] ?? '') === 'envio';
                    })),
                    'scans_linked'   => (int) ($merge['scans_linked'] ?? 0),
                    'merged'         => true,
                ],
            ]);
        }

        $factura_id = $this->save_invoice($factura, $save_options);
        if (is_wp_error($factura_id)) {
            $data = $factura_id->get_error_data();
            if ($factura_id->get_error_code() === 'duplicate' && isset($data['factura_id'])) {
                // Carrera: alguien creó la factura entre find y save — intentar merge
                $merge = $this->merge_xml_into_factura((int) $data['factura_id'], $factura, $save_options);
                if (!is_wp_error($merge)) {
                    wp_send_json_success([
                        'message'    => sprintf('XML unido a factura existente #%s', $merge['folio'] ?? $data['factura_id']),
                        'factura_id' => (int) $merge['factura_id'],
                        'merged'     => true,
                        'modo_ingreso' => $modo_ingreso,
                        'resumen'    => [
                            'proveedor' => $merge['proveedor'] ?? '',
                            'folio'     => $merge['folio'] ?? '',
                            'total'     => $merge['total'] ?? 0,
                            'documento_tipo' => $merge['documento_tipo'] ?? $documento_tipo,
                            'merged'    => true,
                        ],
                    ]);
                }
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

        // Recoger escaneos pendientes del mismo folio
        $scan_link = ['linked' => 0, 'doc_ids' => []];
        if (function_exists('riverso_link_scans_to_factura')) {
            $scan_link = riverso_link_scans_to_factura(
                (int) $factura_id,
                $factura['tipo_dte'] ?? 0,
                $factura['folio'] ?? '',
                $factura['emisor']['rut'] ?? ''
            );
        }

        $scan_truth_applied = false;
        if (function_exists('riverso_factura_data_is_sii_rescued_stub')
            && riverso_factura_data_is_sii_rescued_stub($factura)
            && !empty($scan_link['doc_ids'])) {
            $applied = $this->try_apply_linked_scan_detail((int) $factura_id, $scan_link['doc_ids'], $save_options);
            $scan_truth_applied = !empty($applied['items_updated']);
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
            'scans_linked' => (int) ($scan_link['linked'] ?? 0),
            'scan_truth_applied' => $scan_truth_applied,
        ];

        if ($documento_tipo === 'nota_credito') {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $ref = $wpdb->get_row($wpdb->prepare(
                "SELECT folio_ref, factura_origen_id, estado_resolucion, notas
                 FROM {$prefix}factura_referencias WHERE factura_id = %d ORDER BY id DESC LIMIT 1",
                $factura_id
            ));
            $resumen['nc_folio_ref'] = $ref->folio_ref ?? ($factura['referencias'][0]['folio_ref'] ?? '');
            $resumen['nc_factura_origen_id'] = $ref ? (int) $ref->factura_origen_id : 0;
            $resumen['nc_estado_resolucion'] = $ref->estado_resolucion ?? 'pendiente';
            $resumen['nc_pendiente'] = empty($resumen['nc_factura_origen_id']);
            $resumen['vinculado_a_factura'] = $resumen['nc_factura_origen_id'] ?: null;
        }

        if ($modo_ingreso === 'solo_costos' || $documento_tipo === 'envio') {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            if (in_array($documento_tipo, ['productos', 'guia_despacho'], true)) {
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

        if ($documento_tipo === 'nota_credito') {
            if (!empty($resumen['nc_pendiente'])) {
                $folio_ref = $resumen['nc_folio_ref'] ?: '—';
                $message = "Nota de crédito registrada — pendiente del folio origen {$folio_ref}";
            } else {
                $message = 'Nota de crédito registrada y vinculada a factura origen';
            }
        } elseif ($documento_tipo === 'envio') {
            $message = $link_to_factura_id
                ? 'Flete de transportista registrado y vinculado'
                : 'Flete registrado — pendiente de asignar a factura de productos';
        } elseif ($documento_tipo === 'gastos') {
            $message = 'Gasto operacional registrado (sin productos ni SKU)';
        } elseif ($documento_tipo === 'guia_despacho') {
            $message = 'Guía de despacho registrada — códigos y costos (sin inventario)';
        } else {
            $message = $modo_ingreso === 'solo_costos'
                ? 'Costos y códigos registrados (sin actualizar bodega)'
                : 'Factura procesada correctamente';
        }
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

        $referencias = $factura['referencias'] ?? [];
        $credit_note_resolution = null;
        $facturas_origen = [];
        if ((int) ($factura['tipo_dte'] ?? 0) === 61 || ($detection['tipo'] ?? '') === 'nota_credito') {
            $credit_note_resolution = $this->credit_notes()->resolve_origen_factura(
                $factura,
                $factura['emisor']['rut'] ?? '',
                'automatica'
            );
            $rut_emisor = sanitize_text_field($factura['emisor']['rut'] ?? '');
            $facturas_origen = $wpdb->get_results($wpdb->prepare(
                "SELECT f.id, f.tipo_dte, f.folio, f.fecha_emision, f.monto_total, f.estado,
                        f.documento_subtipo, p.nombre AS proveedor_nombre
                 FROM {$prefix}facturas f
                 LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
                 WHERE f.rut_emisor = %s
                   AND f.tipo_dte IN (33, 34, 52)
                   AND (f.documento_subtipo IS NULL OR f.documento_subtipo IN ('productos', 'envio'))
                 ORDER BY f.fecha_emision DESC, f.id DESC
                 LIMIT 60",
                $rut_emisor
            ), ARRAY_A);
        }

        $preview_subtipo = $detection['tipo'] === 'nota_credito'
            ? 'nota_credito'
            : ($detection['tipo'] === 'envio'
                ? 'envio'
                : ($detection['tipo'] === 'gastos'
                    ? 'gastos'
                    : ($detection['tipo'] === 'guia_despacho' ? 'guia_despacho' : 'productos')));

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
            'referencias' => $referencias,
            'credit_note_resolution' => $credit_note_resolution,
            'facturas_origen' => $facturas_origen,
            'rut_limpio' => $rut,
            'missing_gaps' => $this->detect_intake_gaps($factura, [
                'documento_subtipo' => $preview_subtipo,
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

        $factura['tipo_confirmado'] = (int) ($factura['tipo_confirmado'] ?? 0);

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
            $as_array = is_object($enriched) ? (array) $enriched : $enriched;
            $sku = trim((string) ($as_array['sku_local'] ?? ''));
            $code = trim((string) ($as_array['codigo_proveedor'] ?? ''));
            $as_array['sku_conflict'] = null;
            if ($sku !== '' && $code !== '') {
                $as_array['sku_conflict'] = $this->intake()->get_sku_assignment_conflict(
                    $sku,
                    $proveedor_id,
                    $code
                );
            }
            return $as_array;
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

        // Referencias de nota de crédito (esta factura como NC o como origen)
        $factura['credit_note_refs'] = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, fo.folio AS folio_origen, fo.tipo_dte AS tipo_dte_origen,
                    fo.documento_subtipo AS subtipo_origen, fo.monto_total AS monto_origen,
                    po.nombre AS proveedor_origen
             FROM {$prefix}factura_referencias r
             LEFT JOIN {$prefix}facturas fo ON fo.id = r.factura_origen_id
             LEFT JOIN {$prefix}proveedores po ON po.id = fo.proveedor_id
             WHERE r.factura_id = %d
             ORDER BY r.id DESC",
            (int) $factura_id
        ), ARRAY_A);

        $factura['notas_credito_aplicadas'] = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, nc.folio AS folio_nc, nc.monto_total AS monto_nc, nc.fecha_emision
             FROM {$prefix}factura_referencias r
             INNER JOIN {$prefix}facturas nc ON nc.id = r.factura_id AND nc.tipo_dte = 61
             WHERE r.factura_origen_id = %d
             ORDER BY r.id DESC",
            (int) $factura_id
        ), ARRAY_A);

        $factura['auditoria'] = [];
        if (class_exists('Riverso_POS_Audit')) {
            $logs = Riverso_POS_Audit::get_entity_history('invoice', (int) $factura_id, 30);
            foreach ($logs ?: [] as $row) {
                $factura['auditoria'][] = [
                    'action' => $row->action,
                    'action_label' => $row->action_label ?? $row->action,
                    'user_name' => $row->user_name,
                    'created_at' => $row->created_at,
                    'details' => $row->details,
                    'old_value' => $row->old_value_decoded,
                    'new_value' => $row->new_value_decoded,
                ];
            }
        }

        if (function_exists('riverso_factura_get_adjuntos')) {
            $factura['adjuntos_info'] = riverso_factura_get_adjuntos((int) $factura_id, $factura);
        }

        wp_send_json_success($factura);
    }

    /**
     * AJAX: URLs de adjuntos escaneados (PDF/imagen) de una factura.
     */
    public function ajax_invoice_adjuntos() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $factura_id = (int) ($_POST['factura_id'] ?? 0);
        if (!$factura_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        if (!function_exists('riverso_factura_get_adjuntos')) {
            wp_send_json_error(['message' => 'Módulo de adjuntos no disponible']);
        }
        wp_send_json_success(riverso_factura_get_adjuntos($factura_id));
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
     * AJAX: Actualizar tipo de documento y marcar como confirmado
     */
    public function ajax_update_document_type() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $factura_id = intval($_POST['factura_id'] ?? 0);
        $documento_subtipo = sanitize_text_field($_POST['documento_subtipo'] ?? '');

        $tipos_validos = ['productos', 'envio', 'nota_credito', 'guia_despacho', 'gastos'];
        
        if (!$factura_id || !in_array($documento_subtipo, $tipos_validos, true)) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        $result = $this->apply_document_type($factura_id, $documento_subtipo);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Tipo de documento actualizado y confirmado',
            'tipo' => $result['tipo'],
            'estado' => $result['estado'],
            'modo_ingreso' => $result['modo_ingreso'],
        ]);
    }

    /**
     * Aplica un tipo de documento: ítems, modo de ingreso, estado y auditoría.
     */
    public function apply_document_type($factura_id, $documento_subtipo) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_id = (int) $factura_id;

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT id, folio, documento_subtipo, tipo_confirmado, modo_ingreso, estado
             FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ));

        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $tipo_anterior = $factura->documento_subtipo ?: 'productos';
        $estado_anterior = $factura->estado;
        $modo_anterior = $factura->modo_ingreso ?: 'solo_costos';
        $ya_confirmado = (int) $factura->tipo_confirmado === 1;

        $modo_ingreso = 'solo_costos';

        $this->intake()->apply_item_tipos_for_subtipo($factura_id, $documento_subtipo);

        $wpdb->update(
            "{$prefix}facturas",
            [
                'documento_subtipo' => $documento_subtipo,
                'tipo_confirmado' => 1,
                'modo_ingreso' => $modo_ingreso,
            ],
            ['id' => $factura_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        $estado = $this->update_invoice_status($factura_id);

        $task_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}tareas
             WHERE tipo = 'confirmar_tipo_documento'
               AND referencia_tipo = 'factura'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')
             LIMIT 1",
            $factura_id
        ));

        if ($task_id) {
            $wpdb->update(
                "{$prefix}tareas",
                ['estado' => 'completada', 'completado_en' => current_time('mysql')],
                ['id' => $task_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        $labels = [
            'productos' => 'Productos',
            'envio' => 'Flete',
            'nota_credito' => 'Nota de Crédito',
            'guia_despacho' => 'Guía de Despacho',
            'gastos' => 'Gastos',
        ];

        if (class_exists('Riverso_Audit_Module')) {
            $action = ($tipo_anterior !== $documento_subtipo)
                ? 'invoice_type_changed'
                : 'invoice_type_confirmed';
            Riverso_Audit_Module::get_instance()->log(
                $action,
                'invoice',
                $factura_id,
                [
                    'documento_subtipo' => $tipo_anterior,
                    'estado' => $estado_anterior,
                    'modo_ingreso' => $modo_anterior,
                    'tipo_confirmado' => $ya_confirmado ? 1 : 0,
                ],
                [
                    'documento_subtipo' => $documento_subtipo,
                    'estado' => $estado,
                    'modo_ingreso' => $modo_ingreso,
                    'tipo_confirmado' => 1,
                ],
                sprintf(
                    'Folio %s: %s → %s (estado %s → %s)',
                    $factura->folio,
                    $labels[$tipo_anterior] ?? $tipo_anterior,
                    $labels[$documento_subtipo] ?? $documento_subtipo,
                    $estado_anterior,
                    $estado
                )
            );
        }

        return [
            'tipo' => $documento_subtipo,
            'estado' => $estado,
            'modo_ingreso' => $modo_ingreso,
        ];
    }

    /**
     * AJAX: Vincular / editar SKU local de un ítem de factura.
     */
    public function ajax_link_code() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_codes') && !current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $item_id = intval($_POST['item_id'] ?? 0);
        $sku_local = sanitize_text_field($_POST['sku_local'] ?? '');
        $force = !empty($_POST['force']);
        $clear = !empty($_POST['clear']);

        if (!$item_id) {
            wp_send_json_error(['message' => 'ID de item requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT fi.*, f.proveedor_id, f.id AS factura_id, f.fecha_emision, f.folio
             FROM {$prefix}factura_items fi
             JOIN {$prefix}facturas f ON fi.factura_id = f.id
             WHERE fi.id = %d",
            $item_id
        ));

        if (!$item) {
            wp_send_json_error(['message' => 'Item no encontrado']);
        }

        $item = $this->enrich_factura_item_row($item);
        $codigo_proveedor = trim((string) ($item->codigo_proveedor ?? ''));
        if ($codigo_proveedor === '') {
            wp_send_json_error(['message' => 'El ítem no tiene código de proveedor']);
        }

        if (!$clear && riverso_sku_equals_supplier_code($sku_local, $codigo_proveedor)) {
            wp_send_json_error(['message' => 'El SKU local no puede ser el mismo código de proveedor. Ese código está sin usar.']);
        }

        if (!$clear && $sku_local !== '') {
            $base_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base
                 WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
                $sku_local
            ));
            $product_id = $this->intake()->resolve_product_id_for_local_sku($sku_local, $codigo_proveedor);
            if (!$base_id && !$product_id) {
                wp_send_json_error(['message' => 'SKU local no encontrado en catálogo: ' . $sku_local]);
            }
        }

        $result = $this->intake()->assign_local_sku_mapping(
            (int) $item->proveedor_id,
            $codigo_proveedor,
            $clear ? '' : $sku_local,
            [
                'force' => $force,
                'clear' => $clear,
                'descripcion' => $item->descripcion ?? $item->nombre ?? '',
                'factura_item_id' => $item_id,
                'actor_type' => 'human',
                'document_date' => $item->fecha_emision ?? null,
            ]
        );

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error(array_merge([
                'message' => $result->get_error_message(),
                'conflict' => $result->get_error_code() === 'sku_conflict',
            ], is_array($data) ? $data : []));
        }

        $new_sku = $clear ? null : $sku_local;
        $product_id = $new_sku
            ? $this->intake()->resolve_product_id_for_local_sku($new_sku, $codigo_proveedor)
            : null;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}factura_items
             SET sku_local = NULLIF(%s, ''),
                 product_id = NULLIF(%d, 0),
                 estado = %s
             WHERE id = %d",
            $new_sku ?: '',
            (int) $product_id,
            $new_sku ? 'vinculado' : 'pendiente',
            $item_id
        ));

        $this->update_invoice_status($item->factura_id);

        $wpdb->update(
            "{$prefix}cost_history",
            [
                'product_id' => $product_id ?: 0,
                'pendiente_vinculacion' => $new_sku ? 0 : 1,
            ],
            [
                'source_type' => 'invoice',
                'source_item_id' => $item_id,
            ],
            ['%d', '%d'],
            ['%s', '%d']
        );

        if ($new_sku && class_exists('Riverso_Task_Module')) {
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

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                $clear ? 'sku_mapping_cleared' : 'sku_mapping_changed',
                'invoice',
                (int) $item->factura_id,
                [
                    'entity_name' => $item->folio ?? '',
                    'old_value' => ['sku_local' => $item->sku_local ?? null, 'item_id' => $item_id],
                    'new_value' => ['sku_local' => $new_sku, 'item_id' => $item_id, 'codigo_proveedor' => $codigo_proveedor],
                    'details' => sprintf(
                        'Ítem %s: SKU %s → %s',
                        $codigo_proveedor,
                        $item->sku_local ?: '—',
                        $new_sku ?: '—'
                    ),
                ]
            );
        }

        $applied = is_array($result) ? ($result['applied'] ?? []) : [];
        $applied_items = (int) ($applied['items'] ?? 0);
        $base_message = $clear ? 'SKU desvinculado' : 'SKU actualizado';
        if ($applied_items > 1) {
            $base_message .= sprintf(' · aplicado a %d ítems posteriores a este documento', $applied_items);
        }

        wp_send_json_success([
            'message' => $base_message,
            'sku_local' => $new_sku,
            'estado' => $new_sku ? 'vinculado' : 'pendiente',
            'result' => $result,
        ]);
    }

    /**
     * AJAX: Historial de mapeos de un SKU o código proveedor.
     */
    public function ajax_get_sku_mapping_history() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices') && !current_user_can('riverso_manage_codes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $sku = sanitize_text_field($_POST['sku_local'] ?? '');
        $codigo = sanitize_text_field($_POST['codigo_proveedor'] ?? '');
        $logs = $this->intake()->get_sku_mapping_history($sku, $codigo);
        $owners = $sku !== '' ? $this->intake()->find_sku_owners($sku) : [];

        global $wpdb;
        $dates = null;
        if ($codigo !== '') {
            $dates = $wpdb->get_row($wpdb->prepare(
                "SELECT last_seen_document_date, sku_mapped_at
                 FROM {$wpdb->prefix}riverso_codigos
                 WHERE codigo_proveedor = %s AND activo = 1
                 ORDER BY last_seen_document_date DESC, sku_mapped_at DESC
                 LIMIT 1",
                $codigo
            ), ARRAY_A);
        }

        wp_send_json_success([
            'sku_local' => $sku,
            'codigo_proveedor' => $codigo,
            'owners' => $owners,
            'last_seen_document_date' => $dates['last_seen_document_date'] ?? null,
            'sku_mapped_at' => $dates['sku_mapped_at'] ?? null,
            'history' => array_map(function ($row) {
                $new_value = $row->new_value_decoded;
                return [
                    'action' => $row->action,
                    'action_label' => $row->action_label ?? $row->action,
                    'user_name' => $row->user_name,
                    'created_at' => $row->created_at,
                    'modified_at' => is_array($new_value) ? ($new_value['modified_at'] ?? $row->created_at) : $row->created_at,
                    'document_date' => is_array($new_value) ? ($new_value['document_date'] ?? null) : null,
                    'last_seen_document_date' => is_array($new_value) ? ($new_value['last_seen_document_date'] ?? null) : null,
                    'details' => $row->details,
                    'old_value' => $row->old_value_decoded,
                    'new_value' => $new_value,
                ];
            }, $logs),
        ]);
    }

    /**
     * AJAX: sugerencias de SKU local desde el catálogo (producto_base).
     */
    public function ajax_search_sku_catalog() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_process_invoices')
            && !current_user_can('riverso_manage_codes')
            && !current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $search = sanitize_text_field($_POST['search'] ?? '');
        if (strlen($search) < 1) {
            wp_send_json_success(['products' => []]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $prefix_like = $wpdb->esc_like($search) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$prefix}producto_base
             WHERE deleted_at IS NULL
               AND (canonical_sku LIKE %s OR nombre_canonico LIKE %s)
             ORDER BY
                CASE
                    WHEN canonical_sku = %s THEN 0
                    WHEN canonical_sku LIKE %s THEN 1
                    ELSE 2
                END ASC,
                canonical_sku ASC
             LIMIT 10",
            $like,
            $like,
            $search,
            $prefix_like
        ), ARRAY_A);

        wp_send_json_success(['products' => $rows ?: []]);
    }

    /**
     * Parsea búsqueda de listado: folio (parcial) y/o monto.
     */
    private function parse_invoice_list_search($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $folio = ltrim($raw, "# \t");
        $normalized = preg_replace('/[\s\$]/', '', $folio);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $normalized)) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        $amount = is_numeric($normalized) ? (float) $normalized : null;

        return [
            'folio' => $folio,
            'amount' => $amount,
        ];
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
        $tipo_confirmado_raw = sanitize_text_field($_POST['tipo_confirmado'] ?? '');
        $tipo_confirmado_filter = ($tipo_confirmado_raw === '') ? -1 : intval($tipo_confirmado_raw);
        $search = $this->parse_invoice_list_search(sanitize_text_field($_POST['search'] ?? ''));
        $origen_ingreso = sanitize_text_field($_POST['origen_ingreso'] ?? '');

        $orderby_map = [
            'fecha_emision' => 'f.fecha_emision',
            'created_at' => 'f.created_at',
            'monto_total' => 'f.monto_total',
            'folio' => 'f.folio',
            'proveedor_nombre' => 'p.nombre',
            'estado' => 'f.estado',
            'tipo_dte' => 'f.tipo_dte',
        ];
        $orderby_raw = sanitize_key($_POST['orderby'] ?? 'created_at');
        $orderby_key = isset($orderby_map[$orderby_raw]) ? $orderby_raw : 'created_at';
        $orderby_sql = $orderby_map[$orderby_key];
        $order_raw = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC'));
        $order_sql = ($order_raw === 'ASC') ? 'ASC' : 'DESC';

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

        // Filtro de tipo confirmado: -1 = sin filtro, 0 = pendientes, 1 = confirmados
        if ($tipo_confirmado_filter >= 0) {
            $where[] = 'f.tipo_confirmado = %d';
            $params[] = $tipo_confirmado_filter;
        }

        if ($search) {
            $search_sql = ['CAST(f.folio AS CHAR) LIKE %s'];
            $params[] = '%' . $wpdb->esc_like($search['folio']) . '%';
            if ($search['amount'] !== null) {
                $search_sql[] = 'f.monto_total = %f';
                $params[] = $search['amount'];
                $search_sql[] = 'ROUND(f.monto_total) = %d';
                $params[] = (int) round($search['amount']);
            }
            $where[] = '(' . implode(' OR ', $search_sql) . ')';
        }

        if ($origen_ingreso && in_array($origen_ingreso, ['xml', 'escaneo', 'ambos', 'facto'], true)) {
            $where[] = 'f.origen_ingreso = %s';
            $params[] = $origen_ingreso;
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
                (SELECT COUNT(*) FROM {$prefix}factura_flete_vinculos fv WHERE fv.factura_envio_id = f.id) as facturas_vinculadas,
                (SELECT COUNT(*) FROM {$prefix}documentos_escaneados de WHERE de.factura_id = f.id AND de.estado_revision IN ('confirmado','duplicado')) as adjuntos_count
                FROM {$prefix}facturas f
                LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                WHERE {$where_sql}
                ORDER BY {$orderby_sql} {$order_sql}, f.id DESC
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
            $f['tipo_confirmado'] = (int) ($f['tipo_confirmado'] ?? 0);
            if (function_exists('riverso_factura_origen_summary')) {
                $f['origen'] = riverso_factura_origen_summary($f);
            }
        }
        unset($f);

        wp_send_json_success([
            'facturas' => $facturas,
            'can_delete_invoices' => $can_delete,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
            'orderby' => $orderby_key,
            'order' => $order_sql,
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
     * Contadores para badges de pestañas (tipo por confirmar / escaneos por revisar).
     */
    public function ajax_tab_counts() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $xml_pendientes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}facturas WHERE IFNULL(tipo_confirmado, 0) = 0"
        );

        $scans_pendientes = 0;
        $scans_table = $prefix . 'documentos_escaneados';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $scans_table)) === $scans_table) {
            $scans_pendientes = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$scans_table}
                 WHERE estado_revision IN ('pendiente', 'revisado')"
            );
        }

        wp_send_json_success([
            'xml_pendientes'   => $xml_pendientes,
            'scans_pendientes' => $scans_pendientes,
        ]);
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

    /**
     * AJAX: Crear ticket de pago agrupado
     */
    public function ajax_create_payment_ticket() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_invoice_payments')) {
            wp_send_json_error(['message' => 'Sin permisos para crear pagos']);
        }

        $factura_ids = array_map('intval', (array) ($_POST['factura_ids'] ?? []));
        $fecha_pago = sanitize_text_field($_POST['fecha_pago'] ?? date('Y-m-d'));
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        $comprobante = null;
        if (!empty($_FILES['comprobante'])) {
            $comprobante = $_FILES['comprobante'];
        }

        $result = $this->payments()->create_payment_ticket(
            $factura_ids,
            $comprobante,
            [
                'fecha_pago' => $fecha_pago,
                'notas' => $notas,
                'user_id' => get_current_user_id(),
            ]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Cancelar ticket de pago
     */
    public function ajax_cancel_payment_ticket() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_invoice_payments')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $pago_id = intval($_POST['pago_id'] ?? 0);
        $razon = sanitize_textarea_field($_POST['razon_cancelacion'] ?? '');

        if (!$pago_id) {
            wp_send_json_error(['message' => 'ID de pago requerido']);
        }

        $result = $this->payments()->cancel_payment_ticket(
            $pago_id,
            ['user_id' => get_current_user_id(), 'razon_cancelacion' => $razon]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener detalles de ticket de pago
     */
    public function ajax_get_payment_ticket() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $pago_id = intval($_POST['pago_id'] ?? 0);

        if (!$pago_id) {
            wp_send_json_error(['message' => 'ID de pago requerido']);
        }

        $result = $this->payments()->get_payment_ticket($pago_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Previsualizar total de pago
     */
    public function ajax_preview_payment_total() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura_ids = array_map('intval', (array) ($_POST['factura_ids'] ?? []));

        if (empty($factura_ids)) {
            wp_send_json_error(['message' => 'Debe seleccionar al menos una factura']);
        }

        $cn_service = new Riverso_Credit_Note_Service();
        $total = 0;
        $facturas_data = [];

        foreach ($factura_ids as $fid) {
            $saldo = $cn_service->calculate_saldo_efectivo($fid);
            $f = $wpdb->get_row($wpdb->prepare(
                "SELECT tipo_dte, folio FROM {$prefix}facturas WHERE id = %d",
                $fid
            ));
            if ($f) {
                $total += $saldo;
                $facturas_data[] = [
                    'factura_id' => $fid,
                    'tipo_dte' => $f->tipo_dte,
                    'folio' => $f->folio,
                    'saldo_efectivo' => $saldo,
                ];
            }
        }

        wp_send_json_success([
            'total_monto' => $total,
            'cantidad_documentos' => count($facturas_data),
            'facturas' => $facturas_data,
        ]);
    }

    /**
     * AJAX: Descargar comprobante de pago
     */
    public function ajax_download_payment_comprobante() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $pago_id = intval($_POST['pago_id'] ?? 0);

        if (!$pago_id) {
            wp_send_json_error(['message' => 'ID de pago requerido']);
        }

        $ruta = $this->payments()->get_comprobante_path($pago_id);

        if (is_wp_error($ruta)) {
            wp_send_json_error(['message' => $ruta->get_error_message()]);
        }

        // Construir ruta completa
        $upload_base = wp_upload_dir();
        $file_path = $upload_base['basedir'] . $ruta;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            wp_send_json_error(['message' => 'Archivo no encontrado o no accessible']);
        }

        // Enviar archivo
        header('Content-Type: ' . mime_content_type($file_path));
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    /**
     * AJAX: Buscar folios de facturas de productos o flete para vincular NC.
     */
    public function ajax_search_invoice_folios() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices() && !current_user_can('riverso_view_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $q = sanitize_text_field($_POST['q'] ?? '');
        $rut_emisor = sanitize_text_field($_POST['rut_emisor'] ?? '');
        $exclude_id = intval($_POST['exclude_id'] ?? 0);

        if (strlen($q) < 1) {
            wp_send_json_success(['results' => []]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($q) . '%';

        $where = [
            "(f.documento_subtipo IN ('productos', 'envio') OR f.documento_subtipo IS NULL)",
            'f.tipo_dte <> 61',
            "f.estado NOT IN ('rejected', 'archived')",
            '(f.folio LIKE %s OR CAST(f.folio AS CHAR) LIKE %s OR p.nombre LIKE %s OR p.rut LIKE %s OR f.rut_emisor LIKE %s)',
        ];
        $params = [$like, $like, $like, $like, $like];

        if ($rut_emisor !== '') {
            $where[] = 'f.rut_emisor = %s';
            $params[] = $rut_emisor;
        }
        if ($exclude_id > 0) {
            $where[] = 'f.id <> %d';
            $params[] = $exclude_id;
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT f.id, f.folio, f.tipo_dte, f.fecha_emision, f.monto_total, f.estado,
                       COALESCE(f.documento_subtipo, 'productos') AS documento_subtipo,
                       f.rut_emisor, p.nombre AS proveedor_nombre
                FROM {$prefix}facturas f
                LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
                WHERE {$where_sql}
                ORDER BY
                    CASE WHEN CAST(f.folio AS CHAR) = %s THEN 0 WHEN CAST(f.folio AS CHAR) LIKE %s THEN 1 ELSE 2 END,
                    f.fecha_emision DESC, f.id DESC
                LIMIT 25";
        $params[] = $q;
        $params[] = $wpdb->esc_like($q) . '%';

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        wp_send_json_success(['results' => $results ?: []]);
    }

    /**
     * AJAX: Vincular NC existente a folio de productos/flete.
     */
    public function ajax_link_credit_note_origin() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!$this->user_can_intake_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $nc_id = intval($_POST['factura_nc_id'] ?? 0);
        $origen_id = intval($_POST['factura_origen_id'] ?? 0);

        $result = $this->credit_notes()->assign_origen_to_credit_note($nc_id, $origen_id, [
            'user_id' => get_current_user_id(),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }
}
