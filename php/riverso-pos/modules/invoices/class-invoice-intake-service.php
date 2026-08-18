<?php
/**
 * Servicio de ingreso de facturas XML: clasificación envío/producto,
 * persistencia de códigos proveedor, prorrateo y lotes para precios baseline.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Invoice_Intake_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Palabras clave para detectar líneas de flete/envío en el XML.
     */
    public function get_shipping_keywords() {
        $custom = riverso_get_setting('shipping_keywords', []);
        $defaults = [
            'flete', 'envio', 'envío', 'transporte', 'shipping', 'freight',
            'despacho', 'courier', 'logistica', 'logística', 'transportista',
            'carga', 'tarifa despacho', 'costo despacho', 'servicio de envio',
            'servicio de envío', 'flete terrestre', 'flete aereo', 'flete aéreo',
        ];
        return array_unique(array_merge($defaults, array_filter((array) $custom)));
    }

    /**
     * Determina si DscItem es descripción legible o datos técnicos del emisor.
     * Ej. Andina: "000000000000006337|000000000000000000|..."
     */
    public function is_technical_item_description($descripcion) {
        $text = trim((string) $descripcion);
        if ($text === '') {
            return true;
        }
        if (strpos($text, '|') !== false) {
            return true;
        }
        // Sin letras: solo dígitos/símbolos (códigos internos)
        if (!preg_match('/\p{L}/u', $text)) {
            return true;
        }
        return false;
    }

    /**
     * ¿NmbItem parece código de proveedor (p.ej. "0617000400") y no etiqueta humana?
     */
    public function looks_like_product_code($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        // Sin letras: solo dígitos/símbolos
        if (!preg_match('/\p{L}/u', $text)) {
            return true;
        }
        // Token corto sin espacios (SKU / código interno)
        if (!preg_match('/\s/', $text)
            && strlen($text) <= 40
            && preg_match('/^[A-Z0-9][A-Z0-9.\-\/_]{2,}$/i', $text)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Normaliza nombre/descripción de línea DTE.
     * Si NmbItem es código y DscItem es legible (guías Wurth), usa la descripción como nombre.
     *
     * @return array{nombre:string,descripcion:string}
     */
    public function normalize_item_labels($nombre, $descripcion = '') {
        $nombre = trim((string) $nombre);
        $descripcion = trim((string) $descripcion);
        // Guía/Wurth: NmbItem = código, DscItem = nombre legible
        if ($this->looks_like_product_code($nombre)
            && $descripcion !== ''
            && !$this->is_technical_item_description($descripcion)
            && preg_match('/\p{L}/u', $descripcion)
        ) {
            return [
                'nombre' => $descripcion,
                'descripcion' => $descripcion,
            ];
        }
        if ($nombre === '') {
            $nombre = $descripcion !== '' && !$this->is_technical_item_description($descripcion)
                ? $descripcion
                : 'Item sin descripción';
        }
        if ($descripcion === '' || $this->is_technical_item_description($descripcion)) {
            $descripcion = $nombre;
        }
        return [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * Extrae montos técnicos del DscItem estilo Andina:
     * precio|impuesto_especifico|bruto_aprox|...
     *
     * @return array{impuesto_especifico:?float}|null
     */
    public function parse_technical_dsc_amounts($descripcion) {
        $text = trim((string) $descripcion);
        if ($text === '' || strpos($text, '|') === false) {
            return null;
        }
        $parts = explode('|', $text);
        if (count($parts) < 2) {
            return null;
        }
        return [
            'precio_ref' => (float) $parts[0],
            'impuesto_especifico' => (float) $parts[1],
            'bruto_ref' => isset($parts[2]) ? (float) $parts[2] : null,
        ];
    }

    /**
     * Calcula costos neto/bruto base y final de una línea DTE.
     *
     * - costo_neto_base: Qty × Prc (antes de descuentos/recargos)
     * - costo_neto_final: MontoItem (después de descuentos/recargos)
     * - bruto: neto × (1+IVA/100) + impuesto específico de la línea
     *
     * @param array $item Keys: cantidad, precio, monto, descuento_*, recargo_*, impuesto_especifico_*
     * @param float $tasa_iva
     * @return array
     */
    public function compute_item_cost_breakdown(array $item, $tasa_iva = 19.0) {
        $qty = (float) ($item['cantidad'] ?? 0);
        $precio = (float) ($item['precio'] ?? $item['precio_unitario'] ?? 0);
        $monto = (float) ($item['monto'] ?? $item['monto_total'] ?? 0);
        $tasa_iva = (float) $tasa_iva;
        if ($tasa_iva <= 0) {
            $tasa_iva = 19.0;
        }

        $descuento_pct = isset($item['descuento_porcentaje']) && $item['descuento_porcentaje'] !== '' && $item['descuento_porcentaje'] !== null
            ? (float) $item['descuento_porcentaje']
            : (isset($item['descuento_pct']) ? (float) $item['descuento_pct'] : null);
        $descuento_monto = isset($item['descuento_monto']) && $item['descuento_monto'] !== '' && $item['descuento_monto'] !== null
            ? (float) $item['descuento_monto']
            : null;
        $recargo_pct = isset($item['recargo_porcentaje']) && $item['recargo_porcentaje'] !== '' && $item['recargo_porcentaje'] !== null
            ? (float) $item['recargo_porcentaje']
            : (isset($item['recargo_pct']) ? (float) $item['recargo_pct'] : null);
        $recargo_monto = isset($item['recargo_monto']) && $item['recargo_monto'] !== '' && $item['recargo_monto'] !== null
            ? (float) $item['recargo_monto']
            : null;

        $neto_base = round($qty * $precio, 4);
        if ($descuento_monto === null && $descuento_pct !== null && $descuento_pct > 0) {
            $descuento_monto = round($neto_base * $descuento_pct / 100, 0);
        }
        $descuento_monto = (float) ($descuento_monto ?? 0);

        $neto_post_dsc = round($neto_base - $descuento_monto, 4);
        if ($recargo_monto === null && $recargo_pct !== null && $recargo_pct > 0) {
            $recargo_monto = round($neto_post_dsc * $recargo_pct / 100, 0);
        }
        $recargo_monto = (float) ($recargo_monto ?? 0);

        $neto_final = $monto > 0
            ? round($monto, 4)
            : round($neto_post_dsc + $recargo_monto, 4);

        $esp_monto = isset($item['impuesto_especifico_monto']) && $item['impuesto_especifico_monto'] !== '' && $item['impuesto_especifico_monto'] !== null
            ? (float) $item['impuesto_especifico_monto']
            : null;
        $esp_tasa = isset($item['impuesto_especifico_tasa']) && $item['impuesto_especifico_tasa'] !== '' && $item['impuesto_especifico_tasa'] !== null
            ? (float) $item['impuesto_especifico_tasa']
            : null;

        // Impuesto específico SII suele aplicar sobre neto post-descuento (antes de recargo)
        if ($esp_monto === null && $esp_tasa !== null && $esp_tasa > 0) {
            $esp_monto = round($neto_post_dsc * $esp_tasa / 100, 0);
        }
        $esp_monto = (float) ($esp_monto ?? 0);

        // Bruto = neto + IVA redondeado (CLP) + impuesto específico
        $esp_base = ($esp_tasa !== null && $esp_tasa > 0)
            ? round($neto_base * $esp_tasa / 100, 0)
            : $esp_monto;
        $iva_base = round($neto_base * $tasa_iva / 100, 0);
        $iva_final = round($neto_final * $tasa_iva / 100, 0);
        $bruto_base = round($neto_base + $iva_base + $esp_base, 4);
        $bruto_final = round($neto_final + $iva_final + $esp_monto, 4);

        $unit_final = $qty > 0 ? round($neto_final / $qty, 4) : $precio;

        return [
            'descuento_porcentaje' => $descuento_pct,
            'descuento_monto' => $descuento_monto > 0 || $descuento_pct ? $descuento_monto : null,
            'recargo_porcentaje' => $recargo_pct,
            'recargo_monto' => $recargo_monto > 0 || $recargo_pct ? $recargo_monto : null,
            'impuesto_especifico_tasa' => $esp_tasa,
            'impuesto_especifico_monto' => $esp_monto > 0 || ($esp_tasa !== null && $esp_tasa > 0) ? $esp_monto : null,
            'costo_neto_base' => $neto_base,
            'costo_bruto_base' => $bruto_base,
            'costo_neto_final' => $neto_final,
            'costo_bruto_final' => $bruto_final,
            'costo_unitario_neto_final' => $unit_final,
            'tasa_iva' => $tasa_iva,
        ];
    }

    /**
     * Enriquece ítems parseados con descuentos/recargos/costos usando totales del DTE.
     */
    public function enrich_factura_items_costs(array &$factura_data) {
        $tasa_iva = (float) ($factura_data['totales']['tasa_iva'] ?? 19);
        $impuestos = $factura_data['totales']['impuestos_adicionales'] ?? [];
        $tasas_por_codigo = [];
        foreach ((array) $impuestos as $imp) {
            $tipo = (string) ($imp['tipo_imp'] ?? '');
            if ($tipo !== '') {
                $tasas_por_codigo[$tipo] = (float) ($imp['tasa_imp'] ?? 0);
            }
        }

        foreach ($factura_data['items'] as &$item) {
            $cod_imp = isset($item['cod_imp_adic']) ? (string) $item['cod_imp_adic'] : '';
            if ($cod_imp !== '' && empty($item['impuesto_especifico_tasa']) && isset($tasas_por_codigo[$cod_imp])) {
                $item['impuesto_especifico_tasa'] = $tasas_por_codigo[$cod_imp];
            }

            // Andina: impuesto específico embebido en DscItem técnico
            if (empty($item['impuesto_especifico_monto'])) {
                $raw_dsc = $item['descripcion_raw'] ?? $item['descripcion'] ?? '';
                $parsed = $this->parse_technical_dsc_amounts($raw_dsc);
                if ($parsed && ($parsed['impuesto_especifico'] ?? 0) > 0) {
                    $item['impuesto_especifico_monto'] = $parsed['impuesto_especifico'];
                }
            }

            $costs = $this->compute_item_cost_breakdown($item, $tasa_iva);
            $item = array_merge($item, $costs);
        }
        unset($item);

        return $factura_data;
    }

    /**
     * Determina si una línea del DTE corresponde a costo de envío/flete.
     */
    public function is_shipping_line($nombre, $descripcion = '') {
        $text = mb_strtolower(trim($nombre . ' ' . $descripcion));
        foreach ($this->get_shipping_keywords() as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword !== '' && strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Palabras clave para gastos operacionales (servicios / no inventariables).
     */
    public function get_expense_keywords() {
        $custom = riverso_get_setting('expense_keywords', []);
        $defaults = [
            'luz', 'electricidad', 'electrico', 'eléctrico', 'energia', 'energía',
            'agua potable', 'consumo de agua', 'servicio de agua', 'agua',
            'gas natural', 'consumo de gas', 'servicio de gas',
            'arriendo', 'arrendamiento', 'canon de arriendo',
            'internet', 'telefonia', 'telefonía', 'telefono', 'teléfono',
            'aseo', 'recoleccion de basura', 'recolección de basura',
            'patente comercial', 'permiso municipal', 'contribuciones',
            'seguro', 'prima de seguro', 'mantencion', 'mantención', 'mantenimiento',
            'servicio basico', 'servicio básico', 'servicios basicos', 'servicios básicos',
            'cge', 'enel', 'chilectra', 'saesa', 'frontel',
            'essbio', 'essal', 'aguas andinas', 'esval', 'metrogas', 'gasco',
            'entel', 'movistar', 'vtr', 'claro', 'gtd', 'wom',
        ];
        return array_unique(array_merge($defaults, array_filter((array) $custom)));
    }

    /**
     * Determina si una línea parece gasto operacional (no se vende / sin SKU).
     */
    public function is_expense_line($nombre, $descripcion = '') {
        $text = mb_strtolower(trim($nombre . ' ' . $descripcion));
        foreach ($this->get_expense_keywords() as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword !== '' && strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Palabras clave en emisor/giro que sugieren factura de gasto operacional.
     */
    public function get_expense_emisor_keywords() {
        return [
            'electricidad', 'electrico', 'eléctrico', 'energia', 'energía',
            'sanitari', 'agua potabl', 'aguas ', 'gas natural', 'distribuidora de gas',
            'telecomunic', 'internet', 'telefonia', 'telefonía',
            'aseo', 'reciclaje', 'arrendamiento', 'inmobiliaria',
            'cge', 'enel', 'chilectra', 'essbio', 'essal', 'aguas andinas',
            'metrogas', 'gasco', 'entel', 'movistar', 'vtr', 'claro',
        ];
    }

    /**
     * Clasifica ítems del XML parseado como producto, envío o gasto.
     */
    public function classify_factura_items(array &$factura_data) {
        $shipping_total = 0.0;
        $product_count = 0;
        $expense_count = 0;

        foreach ($factura_data['items'] as &$item) {
            $nombre = $item['nombre'] ?? '';
            $descripcion = $item['descripcion'] ?? '';
            if ($this->is_shipping_line($nombre, $descripcion)) {
                $item['item_tipo'] = 'envio';
                $shipping_total += (float) ($item['monto'] ?? 0);
            } elseif ($this->is_expense_line($nombre, $descripcion)) {
                $item['item_tipo'] = 'gasto';
                $expense_count++;
            } else {
                $item['item_tipo'] = 'producto';
                $product_count++;
            }
        }
        unset($item);

        $factura_data['costo_envio_inline'] = $shipping_total;
        $factura_data['items_producto'] = $product_count;
        $factura_data['items_gasto'] = $expense_count;

        return $factura_data;
    }

    /**
     * Fuerza todas las líneas como gasto (documento marcado como gastos operacionales).
     */
    public function force_expense_items(array &$factura_data) {
        foreach ($factura_data['items'] as &$item) {
            $item['item_tipo'] = 'gasto';
        }
        unset($item);
        $factura_data['costo_envio_inline'] = 0;
        $factura_data['items_producto'] = 0;
        $factura_data['items_gasto'] = count($factura_data['items'] ?? []);
        return $factura_data;
    }

    /**
     * Palabras clave en emisor/giro que sugieren factura de transportista.
     */
    public function get_carrier_emisor_keywords() {
        return [
            'transporte', 'transportes', 'logistica', 'logística', 'courier',
            'flete', 'fletes', 'envio', 'envío', 'cargo', 'express', 'delivery',
            'chilexpress', 'starken', 'bluex', 'correos', 'transitaria',
        ];
    }

    /**
     * Detecta si el XML completo es de productos, transportista (envío), guía, gastos, NC o mixto.
     */
    public function detect_document_type(array $factura_data) {
        $items = $factura_data['items'] ?? [];
        $product_count = 0;
        $shipping_count = 0;
        $expense_count = 0;
        $items_preview = [];

        foreach ($items as $item) {
            $nombre = trim((string) ($item['nombre'] ?? ''));
            $descripcion = trim((string) ($item['descripcion'] ?? ''));
            $labels = $this->normalize_item_labels($nombre, $descripcion);
            $nombre = $labels['nombre'];
            $descripcion = $labels['descripcion'];
            if (!empty($item['item_tipo'])) {
                $tipo = $item['item_tipo'];
            } elseif ($this->is_shipping_line($nombre, $descripcion)) {
                $tipo = 'envio';
            } elseif ($this->is_expense_line($nombre, $descripcion)) {
                $tipo = 'gasto';
            } else {
                $tipo = 'producto';
            }

            if ($tipo === 'envio') {
                $shipping_count++;
            } elseif ($tipo === 'gasto') {
                $expense_count++;
            } else {
                $product_count++;
            }

            $items_preview[] = [
                'linea' => $item['numero'] ?? 0,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'tipo' => $tipo,
                'cantidad' => $item['cantidad'] ?? 0,
                'monto' => $item['monto'] ?? 0,
            ];
        }

        $tipo_dte = (int) ($factura_data['tipo_dte'] ?? 0);
        if ($tipo_dte === 61) {
            return [
                'tipo' => 'nota_credito',
                'label' => 'Nota de Crédito',
                'confianza' => 'alta',
                'motivo' => 'El documento es una Nota de Crédito (TipoDTE=61). Requiere asociación con factura origen.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'items_preview' => $items_preview,
                'tipo_dte' => $tipo_dte,
            ];
        }

        // Guía de despacho (TipoDTE=52): códigos + costos, sin inventario
        if ($tipo_dte === 52) {
            return [
                'tipo' => 'guia_despacho',
                'label' => 'Guía de despacho',
                'confianza' => 'alta',
                'motivo' => 'El documento es una Guía de Despacho (TipoDTE=52). Se registran códigos y costos sin actualizar bodega.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'items_preview' => $items_preview,
                'tipo_dte' => $tipo_dte,
            ];
        }

        $emisor = $factura_data['emisor'] ?? [];
        $emisor_text = mb_strtolower(trim(
            ($emisor['razon_social'] ?? '') . ' ' . ($emisor['giro'] ?? '')
        ));
        $emisor_is_carrier = false;
        foreach ($this->get_carrier_emisor_keywords() as $keyword) {
            if (strpos($emisor_text, $keyword) !== false) {
                $emisor_is_carrier = true;
                break;
            }
        }
        $emisor_is_expense = false;
        foreach ($this->get_expense_emisor_keywords() as $keyword) {
            if (strpos($emisor_text, mb_strtolower($keyword)) !== false) {
                $emisor_is_expense = true;
                break;
            }
        }

        if ($product_count === 0 && $shipping_count > 0) {
            return [
                'tipo' => 'envio',
                'label' => 'Transportista / flete',
                'confianza' => 'alta',
                'motivo' => 'Todas las líneas del XML corresponden a flete o envío.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_transportista' => $emisor_is_carrier,
                'items_preview' => $items_preview,
            ];
        }

        if ($shipping_count > 0 && $product_count > 0) {
            return [
                'tipo' => 'mixto',
                'label' => 'Productos con flete incluido',
                'confianza' => 'alta',
                'motivo' => "El XML incluye {$product_count} línea(s) de producto y {$shipping_count} de envío/flete.",
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_transportista' => $emisor_is_carrier,
                'items_preview' => $items_preview,
            ];
        }

        if ($emisor_is_carrier && $product_count === 0 && $expense_count === 0) {
            return [
                'tipo' => 'envio',
                'label' => 'Transportista / flete',
                'confianza' => 'media',
                'motivo' => 'El emisor del DTE parece ser una empresa de transporte o logística.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_transportista' => true,
                'items_preview' => $items_preview,
            ];
        }

        $total_lines = $product_count + $shipping_count + $expense_count;
        if ($expense_count > 0 && $product_count === 0 && $shipping_count === 0) {
            return [
                'tipo' => 'gastos',
                'label' => 'Gastos operacionales',
                'confianza' => 'alta',
                'motivo' => 'Las líneas parecen servicios o gastos (luz, agua, arriendo, etc.): no se agregan como productos ni SKU.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_gasto' => $emisor_is_expense,
                'items_preview' => $items_preview,
            ];
        }

        if ($emisor_is_expense && $product_count === 0) {
            return [
                'tipo' => 'gastos',
                'label' => 'Gastos operacionales',
                'confianza' => 'media',
                'motivo' => 'El emisor parece un proveedor de servicios básicos u operacionales (sin inventario).',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_gasto' => true,
                'items_preview' => $items_preview,
            ];
        }

        if ($expense_count > 0 && $expense_count >= $product_count && $total_lines > 0) {
            return [
                'tipo' => 'gastos',
                'label' => 'Gastos operacionales',
                'confianza' => 'media',
                'motivo' => "Predominan líneas de gasto operacional ({$expense_count} de {$total_lines}). Confirme si no debe inventariarse.",
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'items_gasto' => $expense_count,
                'emisor_es_gasto' => $emisor_is_expense,
                'items_preview' => $items_preview,
            ];
        }

        return [
            'tipo' => 'productos',
            'label' => 'Factura de productos',
            'confianza' => 'alta',
            'motivo' => $shipping_count > 0
                ? 'Factura de compra de productos.'
                : "Se detectaron {$product_count} línea(s) de producto.",
            'items_producto' => $product_count,
            'items_envio' => $shipping_count,
            'items_gasto' => $expense_count,
            'emisor_es_transportista' => $emisor_is_carrier,
            'items_preview' => $items_preview,
        ];
    }

    /**
     * Resuelve SKU local desde lookup; nunca devuelve el código proveedor/online como SKU local.
     */
    public function resolve_local_sku($codigo_proveedor, $lookup = null, $proveedor_id = null) {
        $code = trim((string) $codigo_proveedor);
        if ($code === '') {
            return null;
        }

        // Mapeo Mamut (online → local) es autoritativo cuando existe.
        $mamut_local = riverso_mamut_online_to_local_sku($code);
        if ($mamut_local) {
            return $mamut_local;
        }

        if ($lookup === null && class_exists('Riverso_Supplier_Links_Module')) {
            $links = Riverso_Supplier_Links_Module::get_instance();
            $lookup = $links->lookup_by_code($code, $proveedor_id ? (int) $proveedor_id : null);
        }

        if (is_array($lookup) && !empty($lookup['found'])) {
            $candidates = [];
            if (!empty($lookup['domain']['canonical_sku'])) {
                $candidates[] = trim((string) $lookup['domain']['canonical_sku']);
            }
            if (!empty($lookup['legacy']['sku_local'])) {
                $candidates[] = trim((string) $lookup['legacy']['sku_local']);
            }
            if (!empty($lookup['link']['internal_sku'])) {
                $candidates[] = trim((string) $lookup['link']['internal_sku']);
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && riverso_is_trusted_supplier_local_sku($code, $candidate, $lookup)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Obtiene product_id WooCommerce para un SKU local (producto_base o WC).
     */
    public function resolve_product_id_for_local_sku($local_sku, $supplier_code = '') {
        $local_sku = trim((string) $local_sku);
        if ($local_sku === '') {
            return null;
        }
        if ($supplier_code && riverso_sku_equals_supplier_code($local_sku, $supplier_code)) {
            return null;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base
             WHERE canonical_sku = %s
             LIMIT 1",
            $local_sku
        ));

        if ($base) {
            $ref = (int) ($base->woocommerce_variation_id ?: $base->woocommerce_product_id);
            if ($ref > 0) {
                return $ref;
            }
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $wc_id = wc_get_product_id_by_sku($local_sku);
            if ($wc_id) {
                return (int) $wc_id;
            }
        }

        return null;
    }

    /**
     * SKU online = SKU del producto en WooCommerce (no confundir con SKU local ni código proveedor).
     */
    public function resolve_sku_online($codigo_proveedor, $lookup = null, $product_id = null, $local_sku = null) {
        $supplier_code = trim((string) $codigo_proveedor);
        $local_sku = trim((string) ($local_sku ?? ''));

        $try_product_sku = function ($pid) use ($local_sku) {
            if (!$pid || !function_exists('wc_get_product')) {
                return null;
            }
            $product = wc_get_product((int) $pid);
            if (!$product) {
                return null;
            }
            $woo_sku = trim((string) $product->get_sku());
            if ($woo_sku === '') {
                return null;
            }
            // El SKU online no debe ser el SKU local del catálogo.
            if ($local_sku !== '' && strcasecmp($woo_sku, $local_sku) === 0) {
                return null;
            }
            return $woo_sku;
        };

        if ($sku = $try_product_sku($product_id)) {
            return $sku;
        }

        if (is_array($lookup) && !empty($lookup['product']['id'])) {
            if ($sku = $try_product_sku((int) $lookup['product']['id'])) {
                return $sku;
            }
        }

        if ($local_sku !== '') {
            $pid = $this->resolve_product_id_for_local_sku($local_sku, $supplier_code);
            if ($sku = $try_product_sku($pid)) {
                return $sku;
            }
        }

        if ($supplier_code !== '' && function_exists('wc_get_product_id_by_sku')) {
            $pid = wc_get_product_id_by_sku($supplier_code);
            if ($sku = $try_product_sku($pid)) {
                return $sku;
            }
        }

        // Mamut: a menudo coincide con código proveedor; otros proveedores pueden diferir.
        return $supplier_code !== '' ? $supplier_code : null;
    }

    /**
     * Normaliza fila de ítem de factura (alias columnas + SKUs local/online).
     */
    public function enrich_factura_item_row($item) {
        if (!$item) {
            return $item;
        }
        if (is_array($item)) {
            $item = (object) $item;
        }
        if (!isset($item->linea)) {
            $item->linea = $item->numero_linea ?? 0;
        }
        if (empty($item->descripcion) || $this->is_technical_item_description($item->descripcion ?? '')) {
            $item->descripcion = $item->nombre ?? '';
        }
        if (empty($item->nombre)) {
            $item->nombre = $item->descripcion ?? '';
        }

        $supplier_code = trim((string) ($item->codigo_proveedor ?? ''));
        $proveedor_id = isset($item->proveedor_id) ? (int) $item->proveedor_id : 0;
        $mapping = ($supplier_code !== '' && $proveedor_id)
            ? $this->lookup_product_mapping($proveedor_id, $supplier_code)
            : [
                'sku_local' => null,
                'sku_sugerido' => $supplier_code ? riverso_mamut_online_to_local_sku($supplier_code) : null,
                'has_mapping' => false,
            ];

        $item->has_mapping = !empty($mapping['has_mapping']);
        $item->sku_sugerido = $mapping['sku_sugerido'] ?? null;
        $item->last_seen_document_date = $mapping['last_seen_document_date'] ?? null;
        $item->sku_mapped_at = $mapping['sku_mapped_at'] ?? null;
        $item->mapping_source = $mapping['source'] ?? null;

        if (!empty($mapping['has_mapping']) && !empty($mapping['sku_local'])) {
            $stored = trim((string) ($item->sku_local ?? ''));
            if ($stored === '' || riverso_sku_equals_supplier_code($stored, $supplier_code)) {
                $item->sku_local = $mapping['sku_local'];
            }
        } elseif ($supplier_code !== '' && riverso_sku_equals_supplier_code($item->sku_local ?? '', $supplier_code)) {
            $item->sku_local = null;
        }

        if (empty($item->sku_sugerido) && empty($mapping['has_mapping'])) {
            $item->sku_sugerido = $mapping['sku_local'] ?? riverso_mamut_online_to_local_sku($supplier_code);
        }

        $lookup = null;
        if ($supplier_code !== '' && class_exists('Riverso_Supplier_Links_Module')) {
            $lookup = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code($supplier_code, $proveedor_id ?: null);
        }

        $item->sku_online = $this->resolve_sku_online(
            $supplier_code,
            $lookup,
            $item->product_id ?? null,
            $item->sku_local ?? null
        );

        return $item;
    }

    /**
     * Actualiza estado agregado de factura según ítems vinculados.
     */
    public function sync_factura_item_status($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'vinculado' THEN 1 ELSE 0 END) AS vinculados,
                    SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) AS rechazados
             FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $estado = 'recibido';
        if ($stats && (int) $stats->total > 0) {
            if ((int) $stats->vinculados === (int) $stats->total) {
                $estado = 'procesado';
            } elseif ((int) $stats->vinculados > 0 || (int) $stats->rechazados > 0) {
                $estado = 'parcial';
            }
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => $estado],
            ['id' => (int) $factura_id],
            ['%s'],
            ['%d']
        );

        return $estado;
    }

    /**
     * Mapeo verificado (humano o VERIFIED) para un par proveedor+código.
     * No incluye sugerencias de catálogo / Mamut.
     */
    public function get_verified_sku_mapping($proveedor_id, $codigo_proveedor) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $proveedor_id = (int) $proveedor_id;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if (!$proveedor_id || $codigo_proveedor === '') {
            return null;
        }

        $domain = $wpdb->get_row($wpdb->prepare(
            "SELECT pp.id AS producto_proveedor_id, pp.match_estado, pp.producto_base_id,
                    pb.canonical_sku, pb.woocommerce_product_id, pb.woocommerce_variation_id
             FROM {$prefix}producto_proveedor pp
             INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
             WHERE pp.proveedor_id = %d AND pp.codigo_proveedor = %s AND pp.activo = 1
               AND pp.match_estado = 'VERIFIED'
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku != ''
             LIMIT 1",
            $proveedor_id,
            $codigo_proveedor
        ), ARRAY_A);

        $codigo = $wpdb->get_row($wpdb->prepare(
            "SELECT sku_local, product_id, product_base_id, supplier_product_id,
                    last_seen_document_date, sku_mapped_at, verificado
             FROM {$prefix}codigos
             WHERE proveedor_id = %d AND codigo_proveedor = %s AND activo = 1
             LIMIT 1",
            $proveedor_id,
            $codigo_proveedor
        ), ARRAY_A);

        $sku = '';
        $product_id = null;
        $producto_base_id = null;
        $producto_proveedor_id = null;
        if ($domain && !empty($domain['canonical_sku'])) {
            $sku = trim((string) $domain['canonical_sku']);
            $producto_base_id = (int) ($domain['producto_base_id'] ?: 0) ?: null;
            $producto_proveedor_id = (int) ($domain['producto_proveedor_id'] ?: 0) ?: null;
            $product_id = (int) ($domain['woocommerce_variation_id'] ?: $domain['woocommerce_product_id'] ?: 0) ?: null;
        } elseif ($codigo && !empty($codigo['sku_local'])) {
            $sku = trim((string) $codigo['sku_local']);
            $product_id = (int) ($codigo['product_id'] ?: 0) ?: null;
            $producto_base_id = (int) ($codigo['product_base_id'] ?: 0) ?: null;
            $producto_proveedor_id = (int) ($codigo['supplier_product_id'] ?: 0) ?: null;
        }

        if ($sku === '' || riverso_sku_equals_supplier_code($sku, $codigo_proveedor)) {
            return null;
        }

        if (!$product_id) {
            $product_id = $this->resolve_product_id_for_local_sku($sku, $codigo_proveedor);
        }

        return [
            'sku_local' => $sku,
            'product_id' => $product_id,
            'producto_base_id' => $producto_base_id,
            'producto_proveedor_id' => $producto_proveedor_id,
            'last_seen_document_date' => $codigo['last_seen_document_date'] ?? null,
            'sku_mapped_at' => $codigo['sku_mapped_at'] ?? null,
            'source' => 'verified_mapping',
            'has_mapping' => true,
        ];
    }

    public function lookup_product_mapping($proveedor_id, $codigo_proveedor, $codigos = []) {
        $result = [
            'sku_local' => null,
            'sku_sugerido' => null,
            'sku_online' => null,
            'product_id' => null,
            'producto_base_id' => null,
            'producto_proveedor_id' => null,
            'last_seen_document_date' => null,
            'sku_mapped_at' => null,
            'source' => null,
            'has_mapping' => false,
        ];

        if (empty($codigo_proveedor)) {
            foreach ($codigos as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    break;
                }
            }
        }

        $codigo_proveedor = trim((string) $codigo_proveedor);
        if ($codigo_proveedor === '') {
            return $result;
        }

        $verified = $this->get_verified_sku_mapping((int) $proveedor_id, $codigo_proveedor);
        $mamut = riverso_mamut_online_to_local_sku($codigo_proveedor);

        $lookup = null;
        if (class_exists('Riverso_Supplier_Links_Module')) {
            $links = Riverso_Supplier_Links_Module::get_instance();
            $lookup = $links->lookup_by_code($codigo_proveedor, (int) $proveedor_id);
        }

        if ($verified) {
            $result = array_merge($result, $verified);
            $result['sku_sugerido'] = ($mamut && strcasecmp($mamut, $verified['sku_local']) !== 0) ? $mamut : null;
        } else {
            $suggested = riverso_usable_local_sku(
                $this->resolve_local_sku($codigo_proveedor, $lookup, (int) $proveedor_id),
                $codigo_proveedor
            );
            $result['sku_sugerido'] = $suggested;
            $result['sku_local'] = $suggested;
            $result['has_mapping'] = false;
            if ($suggested) {
                $result['source'] = $mamut && strcasecmp($mamut, $suggested) === 0
                    ? 'catalog_suggestion'
                    : (is_array($lookup) && !empty($lookup['source']) ? $lookup['source'] : 'catalog_suggestion');
                $result['product_id'] = $this->resolve_product_id_for_local_sku($suggested, $codigo_proveedor);
                if (is_array($lookup) && !empty($lookup['domain'])) {
                    $result['producto_base_id'] = (int) ($lookup['domain']['producto_base_id'] ?? 0) ?: null;
                    $result['producto_proveedor_id'] = (int) ($lookup['domain']['id'] ?? 0) ?: null;
                }
            }
        }

        $result['sku_online'] = $this->resolve_sku_online(
            $codigo_proveedor,
            $lookup,
            $result['product_id'],
            $result['sku_local']
        );

        return $result;
    }

    /**
     * Dueños actuales de un SKU local: un SKU solo puede tener un par proveedor+código.
     */
    public function find_sku_owners($sku_local) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $sku_local = trim((string) $sku_local);
        if ($sku_local === '') {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.proveedor_id, pp.codigo_proveedor, p.nombre AS proveedor_nombre,
                    pb.id AS producto_base_id, pb.canonical_sku, pb.nombre_canonico, 'domain' AS source
             FROM {$prefix}producto_proveedor pp
             INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
             LEFT JOIN {$prefix}proveedores p ON p.id = pp.proveedor_id
             WHERE pp.activo = 1
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku = %s
               AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != ''",
            $sku_local
        ), ARRAY_A);

        $legacy = $wpdb->get_results($wpdb->prepare(
            "SELECT c.proveedor_id, c.codigo_proveedor, p.nombre AS proveedor_nombre,
                    c.product_base_id AS producto_base_id, c.sku_local AS canonical_sku,
                    c.nombre_proveedor AS nombre_canonico, 'codigos' AS source
             FROM {$prefix}codigos c
             LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.activo = 1 AND c.sku_local = %s
               AND c.codigo_proveedor IS NOT NULL AND c.codigo_proveedor != ''",
            $sku_local
        ), ARRAY_A);

        $owners = [];
        foreach (array_merge($rows ?: [], $legacy ?: []) as $row) {
            $pid = (int) ($row['proveedor_id'] ?? 0);
            $code = trim((string) ($row['codigo_proveedor'] ?? ''));
            if ($pid <= 0 || $code === '') {
                continue;
            }
            $key = $pid . "\0" . strtoupper($code);
            if (!isset($owners[$key])) {
                $owners[$key] = [
                    'proveedor_id' => $pid,
                    'codigo_proveedor' => $code,
                    'proveedor_nombre' => $row['proveedor_nombre'] ?? '',
                    'producto_base_id' => (int) ($row['producto_base_id'] ?? 0) ?: null,
                    'sku_local' => $sku_local,
                    'nombre_canonico' => $row['nombre_canonico'] ?? '',
                    'source' => $row['source'] ?? '',
                ];
            }
        }

        return array_values($owners);
    }

    /**
     * SKU local actualmente mapeado a un código de proveedor.
     */
    public function get_code_current_sku($proveedor_id, $codigo_proveedor) {
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if (!(int) $proveedor_id || $codigo_proveedor === '') {
            return null;
        }

        $verified = $this->get_verified_sku_mapping((int) $proveedor_id, $codigo_proveedor);
        return $verified['sku_local'] ?? null;
    }

    public function format_sku_owner_label(array $owner) {
        $name = trim((string) ($owner['proveedor_nombre'] ?? ''));
        if ($name === '') {
            $name = 'Proveedor #' . (int) ($owner['proveedor_id'] ?? 0);
        }
        return $name . ' / ' . ($owner['codigo_proveedor'] ?? '');
    }

    /**
     * Conflicto si el SKU ya pertenece a otro par, o el código ya tiene otro SKU.
     */
    public function get_sku_assignment_conflict($sku_local, $proveedor_id, $codigo_proveedor) {
        $sku_local = trim((string) $sku_local);
        $codigo_proveedor = trim((string) $codigo_proveedor);
        $proveedor_id = (int) $proveedor_id;
        if ($sku_local === '' || !$proveedor_id || $codigo_proveedor === '') {
            return null;
        }

        $owners = $this->find_sku_owners($sku_local);
        $others = array_values(array_filter($owners, function ($owner) use ($proveedor_id, $codigo_proveedor) {
            return (int) $owner['proveedor_id'] !== $proveedor_id
                || strcasecmp((string) $owner['codigo_proveedor'], $codigo_proveedor) !== 0;
        }));

        $current = $this->get_code_current_sku($proveedor_id, $codigo_proveedor);
        if ($current && strcasecmp($current, $sku_local) === 0 && empty($others)) {
            return null;
        }

        if ($others) {
            $labels = array_map([$this, 'format_sku_owner_label'], $others);
            return [
                'code' => 'sku_owned_elsewhere',
                'sku_local' => $sku_local,
                'current_sku' => $current,
                'owners' => $others,
                'message' => sprintf(
                    'El SKU %s ya está asignado a %s. Cada SKU local solo puede tener un proveedor y un código.',
                    $sku_local,
                    implode(', ', $labels)
                ),
            ];
        }

        if ($current && strcasecmp($current, $sku_local) !== 0) {
            return [
                'code' => 'code_has_other_sku',
                'sku_local' => $sku_local,
                'current_sku' => $current,
                'owners' => [],
                'message' => sprintf(
                    'El código %s ya está mapeado al SKU %s. ¿Cambiarlo a %s?',
                    $codigo_proveedor,
                    $current,
                    $sku_local
                ),
            ];
        }

        return null;
    }

    /**
     * Quita un SKU de un par proveedor+código (dominio + tabla códigos).
     */
    public function unlink_sku_from_code($proveedor_id, $codigo_proveedor, $sku_local = null, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $proveedor_id = (int) $proveedor_id;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if (!$proveedor_id || $codigo_proveedor === '') {
            return;
        }

        $old_sku = $sku_local ?: $this->get_code_current_sku($proveedor_id, $codigo_proveedor);
        $document_date = $this->normalize_document_date($opts['document_date'] ?? null);
        $modified_at = current_time('mysql');
        $last_seen = $this->resolve_last_seen_document_date($proveedor_id, $codigo_proveedor, $document_date);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_proveedor
             SET producto_base_id = NULL, match_estado = 'UNMATCHED', updated_at = %s
             WHERE proveedor_id = %d AND codigo_proveedor = %s AND activo = 1",
            $modified_at,
            $proveedor_id,
            $codigo_proveedor
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}codigos
             SET sku_local = NULL, product_id = NULL, product_base_id = NULL,
                 sku_mapped_at = %s,
                 last_seen_document_date = COALESCE(NULLIF(%s, ''), last_seen_document_date),
                 updated_at = %s
             WHERE proveedor_id = %d AND codigo_proveedor = %s",
            $modified_at,
            $last_seen ?: '',
            $modified_at,
            $proveedor_id,
            $codigo_proveedor
        ));

        if ($old_sku && class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('sku_mapping_cleared', 'sku_mapping', 0, [
                'actor_type' => $opts['actor_type'] ?? 'computer',
                'entity_name' => $old_sku,
                'old_value' => [
                    'sku_local' => $old_sku,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo_proveedor,
                    'document_date' => $document_date,
                    'last_seen_document_date' => $last_seen,
                ],
                'new_value' => [
                    'sku_local' => null,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo_proveedor,
                    'document_date' => $document_date,
                    'last_seen_document_date' => $last_seen,
                    'modified_at' => $modified_at,
                ],
                'details' => sprintf(
                    'Se liberó el SKU %s de %s%s',
                    $old_sku,
                    $codigo_proveedor,
                    $document_date ? " (doc. {$document_date})" : ''
                ),
            ]);
        }
    }

    /**
     * Asigna SKU local a un par proveedor+código. $force=true reasigna robando el SKU a otros dueños.
     *
     * @return array|WP_Error
     */
    public function assign_local_sku_mapping($proveedor_id, $codigo_proveedor, $sku_local, array $opts = []) {
        $proveedor_id = (int) $proveedor_id;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        $sku_local = trim((string) $sku_local);
        $force = !empty($opts['force']);
        $clear = !empty($opts['clear']) || $sku_local === '';

        if (!$proveedor_id || $codigo_proveedor === '') {
            return new WP_Error('invalid_params', 'Proveedor y código son obligatorios');
        }

        $old_sku = $this->get_code_current_sku($proveedor_id, $codigo_proveedor);
        $document_date = $this->normalize_document_date($opts['document_date'] ?? null);
        $modified_at = current_time('mysql');

        if ($clear) {
            $this->unlink_sku_from_code($proveedor_id, $codigo_proveedor, $old_sku, [
                'document_date' => $document_date,
                'actor_type' => $opts['actor_type'] ?? 'human',
            ]);
            $applied = $this->apply_mapping_to_later_invoices(
                $proveedor_id,
                $codigo_proveedor,
                '',
                $document_date
            );
            return [
                'sku_local' => null,
                'old_sku' => $old_sku,
                'cleared' => true,
                'document_date' => $document_date,
                'last_seen_document_date' => $this->resolve_last_seen_document_date($proveedor_id, $codigo_proveedor, $document_date),
                'modified_at' => $modified_at,
                'applied' => $applied,
            ];
        }

        $conflict = $this->get_sku_assignment_conflict($sku_local, $proveedor_id, $codigo_proveedor);
        if ($conflict && !$force) {
            return new WP_Error('sku_conflict', $conflict['message'], $conflict);
        }

        if ($conflict && !empty($conflict['owners'])) {
            foreach ($conflict['owners'] as $owner) {
                $this->unlink_sku_from_code(
                    (int) $owner['proveedor_id'],
                    $owner['codigo_proveedor'],
                    $sku_local,
                    ['document_date' => $document_date, 'actor_type' => $opts['actor_type'] ?? 'human']
                );
            }
        }

        $this->persist_supplier_code(
            $proveedor_id,
            $codigo_proveedor,
            $opts['descripcion'] ?? '',
            $opts['codigos'] ?? [],
            $sku_local,
            true,
            $document_date
        );

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE canonical_sku = %s AND deleted_at IS NULL
             LIMIT 1",
            $sku_local
        ));
        if ($base_id) {
            $pp_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                $proveedor_id,
                $codigo_proveedor
            ));
            if ($pp_id) {
                $wpdb->update(
                    "{$prefix}producto_proveedor",
                    [
                        'producto_base_id' => $base_id,
                        'activo' => 1,
                        'match_estado' => 'VERIFIED',
                        'updated_at' => current_time('mysql'),
                    ],
                    ['id' => (int) $pp_id],
                    ['%d', '%d', '%s', '%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert("{$prefix}producto_proveedor", [
                    'producto_base_id' => $base_id,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo_proveedor,
                    'nombre_proveedor' => $opts['descripcion'] ?? null,
                    'activo' => 1,
                    'match_estado' => 'VERIFIED',
                    'origen_datos' => 'manual',
                    'created_at' => current_time('mysql'),
                ]);
            }
        }

        $this->touch_mapping_dates($proveedor_id, $codigo_proveedor, $modified_at, $document_date, true);
        $product_id = $this->resolve_product_id_for_local_sku($sku_local, $codigo_proveedor);
        $applied = $this->apply_mapping_to_later_invoices(
            $proveedor_id,
            $codigo_proveedor,
            $sku_local,
            $document_date,
            $product_id
        );
        $last_seen = $this->resolve_last_seen_document_date($proveedor_id, $codigo_proveedor, $document_date);

        $action = ($old_sku && strcasecmp($old_sku, $sku_local) !== 0)
            ? 'sku_mapping_changed'
            : 'sku_mapping_assigned';
        if (class_exists('Riverso_POS_Audit')) {
            $base_id = (int) ($this->lookup_product_mapping($proveedor_id, $codigo_proveedor)['producto_base_id'] ?? 0);
            Riverso_POS_Audit::log($action, 'sku_mapping', $base_id ?: 0, [
                'actor_type' => $opts['actor_type'] ?? 'human',
                'entity_name' => $sku_local,
                'old_value' => [
                    'sku_local' => $old_sku,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo_proveedor,
                    'document_date' => $document_date,
                ],
                'new_value' => [
                    'sku_local' => $sku_local,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo_proveedor,
                    'factura_item_id' => $opts['factura_item_id'] ?? null,
                    'forced' => $force,
                    'document_date' => $document_date,
                    'last_seen_document_date' => $last_seen,
                    'modified_at' => $modified_at,
                    'items_updated' => $applied['items'] ?? 0,
                ],
                'details' => sprintf(
                    'SKU %s ← %s%s%s',
                    $sku_local,
                    $codigo_proveedor,
                    $old_sku ? " (antes {$old_sku})" : '',
                    $document_date ? " · doc. {$document_date}" : ''
                ),
            ]);
        }

        return [
            'sku_local' => $sku_local,
            'old_sku' => $old_sku,
            'forced' => $force,
            'conflict' => $conflict,
            'document_date' => $document_date,
            'last_seen_document_date' => $last_seen,
            'modified_at' => $modified_at,
            'applied' => $applied,
        ];
    }

    /**
     * Normaliza una fecha de documento a Y-m-d.
     */
    public function normalize_document_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Última fecha de emisión vista para un código proveedor.
     */
    public function resolve_last_seen_document_date($proveedor_id, $codigo_proveedor, $candidate_date = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT last_seen_document_date FROM {$prefix}codigos
             WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
            (int) $proveedor_id,
            $codigo_proveedor
        ));
        $from_invoices = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(f.fecha_emision)
             FROM {$prefix}factura_items fi
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             WHERE f.proveedor_id = %d AND fi.codigo_proveedor = %s
               AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)",
            (int) $proveedor_id,
            $codigo_proveedor
        ));

        $dates = array_filter([
            $this->normalize_document_date($stored),
            $this->normalize_document_date($from_invoices),
            $this->normalize_document_date($candidate_date),
        ]);
        if (!$dates) {
            return null;
        }
        sort($dates);
        return end($dates);
    }

    /**
     * Actualiza fecha de modificación y último documento visto del mapeo.
     */
    public function touch_mapping_dates($proveedor_id, $codigo_proveedor, $modified_at = null, $document_date = null, $mapping_changed = false) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $last_seen = $this->resolve_last_seen_document_date($proveedor_id, $codigo_proveedor, $document_date);
        $fields = ['updated_at' => $modified_at ?: current_time('mysql')];
        $formats = ['%s'];
        if ($last_seen) {
            $fields['last_seen_document_date'] = $last_seen;
            $formats[] = '%s';
        }
        if ($mapping_changed) {
            $fields['sku_mapped_at'] = $modified_at ?: current_time('mysql');
            $formats[] = '%s';
        }
        $wpdb->update(
            "{$prefix}codigos",
            $fields,
            [
                'proveedor_id' => (int) $proveedor_id,
                'codigo_proveedor' => $codigo_proveedor,
            ],
            $formats,
            ['%d', '%s']
        );
        return $last_seen;
    }

    /**
     * Aplica el mapeo a ítems y costos de facturas con fecha >= el documento editado.
     */
    public function apply_mapping_to_later_invoices($proveedor_id, $codigo_proveedor, $sku_local, $from_date, $product_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $proveedor_id = (int) $proveedor_id;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        $from_date = $this->normalize_document_date($from_date);
        $result = ['items' => 0, 'invoices' => 0, 'costs' => 0];
        if (!$proveedor_id || $codigo_proveedor === '' || !$from_date) {
            return $result;
        }

        $sku_local = riverso_usable_local_sku($sku_local, $codigo_proveedor) ?: '';
        $new_sku = $sku_local !== '' ? $sku_local : '';
        $estado = $new_sku !== '' ? 'vinculado' : 'pendiente';
        $product_id = $new_sku !== '' ? (int) $product_id : 0;

        $factura_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT f.id
             FROM {$prefix}factura_items fi
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             WHERE f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)
               AND f.fecha_emision >= %s",
            $proveedor_id,
            $codigo_proveedor,
            $from_date
        ));

        $result['items'] = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}factura_items fi
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             SET fi.sku_local = NULLIF(%s, ''),
                 fi.product_id = NULLIF(%d, 0),
                 fi.estado = %s
             WHERE f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)
               AND f.fecha_emision >= %s",
            $new_sku,
            $product_id,
            $estado,
            $proveedor_id,
            $codigo_proveedor,
            $from_date
        ));

        $result['costs'] = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}cost_history ch
             INNER JOIN {$prefix}factura_items fi ON fi.id = ch.source_item_id AND ch.source_type = 'invoice'
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             SET ch.product_id = %d,
                 ch.pendiente_vinculacion = %d
             WHERE f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               AND ch.document_date >= %s",
            $product_id,
            $new_sku !== '' ? 0 : 1,
            $proveedor_id,
            $codigo_proveedor,
            $from_date
        ));

        foreach ($factura_ids ?: [] as $factura_id) {
            $this->sync_factura_item_status((int) $factura_id);
        }
        $result['invoices'] = count($factura_ids ?: []);

        if ($new_sku !== '' && class_exists('Riverso_Task_Module') && $factura_ids) {
            $ids = array_map('intval', $factura_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}tareas t
                 INNER JOIN {$prefix}factura_items fi ON fi.id = t.referencia_id
                 SET t.estado = 'completada', t.completado_en = %s
                 WHERE t.tipo = 'codigo_faltante'
                   AND t.referencia_tipo = 'factura_item'
                   AND t.estado = 'pendiente'
                   AND fi.factura_id IN ($placeholders)
                   AND fi.codigo_proveedor = %s",
                ...array_merge([current_time('mysql')], $ids, [$codigo_proveedor])
            ));
        }

        return $result;
    }

    /**
     * Historial de mapeos de un SKU o de un código proveedor.
     */
    public function get_sku_mapping_history($sku_local = '', $codigo_proveedor = '') {
        if (!class_exists('Riverso_POS_Audit')) {
            return [];
        }
        Riverso_POS_Audit::init();
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_audit_log';
        $sku_local = trim((string) $sku_local);
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if ($sku_local === '' && $codigo_proveedor === '') {
            return [];
        }

        $actions = ['sku_mapping_assigned', 'sku_mapping_changed', 'sku_mapping_cleared'];
        $placeholders = implode(',', array_fill(0, count($actions), '%s'));
        $sql = "SELECT * FROM {$table}
                WHERE action IN ($placeholders)
                  AND entity_type = 'sku_mapping'";
        $params = $actions;
        $likes = [];
        if ($sku_local !== '') {
            $likes[] = '(new_value LIKE %s OR old_value LIKE %s OR entity_name = %s)';
            $like = '%' . $wpdb->esc_like($sku_local) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $sku_local;
        }
        if ($codigo_proveedor !== '') {
            $likes[] = '(new_value LIKE %s OR old_value LIKE %s OR details LIKE %s)';
            $like = '%' . $wpdb->esc_like($codigo_proveedor) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($likes) {
            $sql .= ' AND (' . implode(' OR ', $likes) . ')';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 50';

        $items = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        foreach ($items ?: [] as &$item) {
            $item->action_label = Riverso_POS_Audit::ACTIONS[$item->action] ?? $item->action;
            $item->old_value_decoded = $item->old_value ? json_decode($item->old_value, true) : null;
            $item->new_value_decoded = $item->new_value ? json_decode($item->new_value, true) : null;
        }
        return $items ?: [];
    }

    /**
     * Corrige ítems de factura vinculados con SKU online en lugar de SKU local.
     */
    public function repair_mislinked_invoice_items($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $scoped = !empty($args['factura_id']) || !empty($args['folio']);
        $where = [
            "(fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)",
            "fi.codigo_proveedor IS NOT NULL",
            "fi.codigo_proveedor != ''",
        ];
        if (!$scoped) {
            $where[] = "(fi.sku_local IS NULL OR fi.sku_local = '' OR fi.sku_local = fi.codigo_proveedor)";
        }
        $params = [];

        if (!empty($args['factura_id'])) {
            $where[] = 'f.id = %d';
            $params[] = (int) $args['factura_id'];
        }
        if (!empty($args['folio'])) {
            $where[] = 'f.folio = %s';
            $params[] = (string) $args['folio'];
        }

        $sql = "SELECT fi.*, f.proveedor_id, f.folio, f.id AS factura_id
                FROM {$prefix}factura_items fi
                INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
                WHERE " . implode(' AND ', $where);

        $items = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $fixed = 0;
        $cleared = 0;
        $factura_ids = [];

        foreach ($items as $item) {
            $mapping = $this->lookup_product_mapping(
                (int) $item->proveedor_id,
                $item->codigo_proveedor
            );
            $new_sku = riverso_usable_local_sku($mapping['sku_local'] ?? null, $item->codigo_proveedor);
            $current = trim((string) ($item->sku_local ?? ''));

            if ($new_sku && !riverso_sku_equals_supplier_code($new_sku, $item->codigo_proveedor)) {
                if (strcasecmp($new_sku, $current) === 0) {
                    continue;
                }

                $product_id = $mapping['product_id']
                    ?? $this->resolve_product_id_for_local_sku($new_sku, $item->codigo_proveedor);

                $wpdb->update(
                    "{$prefix}factura_items",
                    [
                        'sku_local' => $new_sku,
                        'product_id' => $product_id,
                        'estado' => 'vinculado',
                    ],
                    ['id' => (int) $item->id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );

                $this->persist_supplier_code(
                    (int) $item->proveedor_id,
                    $item->codigo_proveedor,
                    $item->descripcion ?: $item->nombre,
                    [],
                    $new_sku
                );

                $factura_ids[(int) $item->factura_id] = true;
                $fixed++;
                continue;
            }

            if ($current !== '' && !riverso_is_trusted_supplier_local_sku($item->codigo_proveedor, $current)) {
                $wpdb->update(
                    "{$prefix}factura_items",
                    [
                        'sku_local' => null,
                        'product_id' => null,
                        'estado' => 'pendiente',
                    ],
                    ['id' => (int) $item->id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
                $factura_ids[(int) $item->factura_id] = true;
                $cleared++;
            }
        }

        $proveedor_id = !empty($args['proveedor_id']) ? (int) $args['proveedor_id'] : null;
        if (!$proveedor_id && $scoped && !empty($items[0]->proveedor_id)) {
            $proveedor_id = (int) $items[0]->proveedor_id;
        }

        $codigos_fixed = $this->repair_mislinked_codigos_table($proveedor_id);
        $domain_deactivated = $this->repair_corrupted_domain_mappings($proveedor_id);

        if (!empty($factura_ids)) {
            foreach (array_keys($factura_ids) as $fid) {
                $this->sync_factura_item_status($fid);
            }
        }

        return [
            'items_fixed' => $fixed,
            'items_cleared' => $cleared,
            'items_checked' => count($items),
            'codigos_fixed' => $codigos_fixed,
            'domain_deactivated' => $domain_deactivated,
        ];
    }

    /**
     * Desvincula ítems cuyo SKU local es el código proveedor (placeholder Mamut sin usar).
     */
    public function repair_identity_mapped_invoice_items($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = [
            "(fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)",
            "fi.sku_local IS NOT NULL",
            "fi.sku_local != ''",
            "LOWER(fi.sku_local) = LOWER(fi.codigo_proveedor)",
        ];
        $params = [];
        if (!empty($args['factura_id'])) {
            $where[] = 'f.id = %d';
            $params[] = (int) $args['factura_id'];
        }
        if (!empty($args['folio'])) {
            $where[] = 'f.folio = %s';
            $params[] = (string) $args['folio'];
        }

        $sql = "SELECT fi.id, fi.factura_id, fi.codigo_proveedor, fi.sku_local
                FROM {$prefix}factura_items fi
                INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
                WHERE " . implode(' AND ', $where);
        $items = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $cleared = 0;
        $factura_ids = [];
        foreach ($items ?: [] as $item) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}factura_items
                 SET sku_local = NULL, product_id = NULL, estado = 'pendiente'
                 WHERE id = %d",
                (int) $item->id
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}cost_history
                 SET product_id = 0, pendiente_vinculacion = 1
                 WHERE source_type = 'invoice' AND source_item_id = %d",
                (int) $item->id
            ));
            $factura_ids[(int) $item->factura_id] = true;
            $cleared++;
        }

        $codigos_cleared = (int) $wpdb->query(
            "UPDATE {$prefix}codigos
             SET sku_local = NULL, product_id = NULL, product_base_id = NULL
             WHERE sku_local IS NOT NULL AND sku_local != ''
               AND LOWER(sku_local) = LOWER(codigo_proveedor)"
        );

        foreach (array_keys($factura_ids) as $fid) {
            $this->sync_factura_item_status($fid);
            $this->create_supplier_link_tasks($fid);
        }

        return [
            'items_cleared' => $cleared,
            'codigos_cleared' => $codigos_cleared,
            'facturas' => array_map('intval', array_keys($factura_ids)),
        ];
    }

    /**
     * Desactiva vínculos de dominio que apuntan a un SKU local no confiable.
     */
    public function repair_corrupted_domain_mappings($proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = 'pp.activo = 1 AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != \'\'';
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND pp.proveedor_id = %d';
            $params[] = (int) $proveedor_id;
        }

        $sql = "SELECT pp.id, pp.codigo_proveedor, pp.proveedor_id, pp.match_estado,
                       pb.human_product_review, pb.canonical_sku
                FROM {$prefix}producto_proveedor pp
                INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                WHERE {$where}";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        $deactivated = 0;

        foreach ($rows as $row) {
            $canonical = trim((string) ($row->canonical_sku ?? ''));
            if ($canonical === '') {
                continue;
            }
            $code = trim((string) $row->codigo_proveedor);
            $lookup = [
                'domain' => [
                    'human_product_review' => $row->human_product_review ?? 'pending',
                    'match_estado' => $row->match_estado ?? 'UNMATCHED',
                ],
            ];
            $mamut = riverso_mamut_online_to_local_sku($code);
            $mamut_disagrees = ($mamut !== null && strcasecmp($mamut, $canonical) !== 0);
            $alpha_to_numeric = (bool) preg_match('/[A-Za-z]/', $code) && (bool) preg_match('/^\d+$/', $canonical);
            if (!$mamut_disagrees && !($alpha_to_numeric && !riverso_supplier_mapping_is_verified($lookup))) {
                continue;
            }
            $wpdb->update(
                "{$prefix}producto_proveedor",
                ['activo' => 0],
                ['id' => (int) $row->id],
                ['%d'],
                ['%d']
            );
            $deactivated++;
        }

        return $deactivated;
    }

    /**
     * Desvincula códigos proveedor pegados a un SKU local no confiable
     * (p.ej. Andina 120437 → tornillo 853) y limpia facturas afectadas.
     */
    public function repair_untrusted_supplier_sku_links($proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = 'pp.activo = 1 AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != \'\' AND pp.producto_base_id IS NOT NULL';
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND pp.proveedor_id = %d';
            $params[] = (int) $proveedor_id;
        }

        $sql = "SELECT pp.id, pp.codigo_proveedor, pp.proveedor_id, pp.producto_base_id, pp.match_estado,
                       pb.canonical_sku, pb.human_product_review
                FROM {$prefix}producto_proveedor pp
                INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                WHERE {$where}";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        $unlinked = 0;
        $cleared_codes = 0;
        $cleared_items = 0;
        $factura_ids = [];

        foreach ($rows ?: [] as $row) {
            $canonical = trim((string) ($row->canonical_sku ?? ''));
            if ($canonical === '') {
                continue;
            }
            $lookup = [
                'domain' => [
                    'human_product_review' => $row->human_product_review ?? 'pending',
                    'match_estado' => $row->match_estado ?? 'UNMATCHED',
                ],
            ];
            if (riverso_is_trusted_supplier_local_sku($row->codigo_proveedor, $canonical, $lookup)) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_proveedor
                 SET producto_base_id = NULL, match_estado = 'UNMATCHED', updated_at = %s
                 WHERE id = %d",
                current_time('mysql'),
                (int) $row->id
            ));
            $unlinked++;

            $cleared_codes += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}codigos
                 SET sku_local = NULL, product_id = NULL, product_base_id = NULL, updated_at = %s
                 WHERE proveedor_id = %d AND codigo_proveedor = %s",
                current_time('mysql'),
                (int) $row->proveedor_id,
                $row->codigo_proveedor
            ));
        }

        $item_sql = "SELECT fi.id, fi.factura_id, fi.codigo_proveedor, fi.sku_local, f.proveedor_id
                     FROM {$prefix}factura_items fi
                     INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
                     WHERE (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)
                       AND fi.sku_local IS NOT NULL AND fi.sku_local != ''";
        $item_params = [];
        if ($proveedor_id) {
            $item_sql .= ' AND f.proveedor_id = %d';
            $item_params[] = (int) $proveedor_id;
        }
        $items = $item_params
            ? $wpdb->get_results($wpdb->prepare($item_sql, ...$item_params))
            : $wpdb->get_results($item_sql);

        foreach ($items ?: [] as $item) {
            $lookup = null;
            if (class_exists('Riverso_Supplier_Links_Module')) {
                $lookup = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code(
                    $item->codigo_proveedor,
                    (int) $item->proveedor_id
                );
            }
            if (riverso_is_trusted_supplier_local_sku($item->codigo_proveedor, $item->sku_local, $lookup)) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}factura_items
                 SET sku_local = NULL, product_id = NULL, estado = 'pendiente'
                 WHERE id = %d",
                (int) $item->id
            ));
            $factura_ids[(int) $item->factura_id] = true;
            $cleared_items++;
        }

        foreach (array_keys($factura_ids) as $fid) {
            $this->sync_factura_item_status($fid);
            $this->create_supplier_link_tasks($fid);
        }

        return [
            'pp_unlinked' => $unlinked,
            'codigos_cleared' => $cleared_codes,
            'items_cleared' => $cleared_items,
            'facturas' => array_map('intval', array_keys($factura_ids)),
        ];
    }

    /**
     * Corrige tabla codigos donde sku_local = codigo_proveedor pero existe mapeo Mamut.
     */
    public function repair_mislinked_codigos_table($proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = "activo = 1 AND codigo_proveedor IS NOT NULL AND codigo_proveedor != ''";
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND proveedor_id = %d';
            $params[] = $proveedor_id;
        }

        $sql = "SELECT id, proveedor_id, codigo_proveedor, nombre_proveedor, sku_local
                FROM {$prefix}codigos WHERE {$where}";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        $fixed = 0;
        foreach ($rows as $row) {
            $current = trim((string) ($row->sku_local ?? ''));
            $local = $this->resolve_local_sku($row->codigo_proveedor, null, (int) $row->proveedor_id);

            if ($local && !riverso_sku_equals_supplier_code($local, $row->codigo_proveedor)) {
                if (strcasecmp($local, $current) === 0) {
                    continue;
                }
                $product_id = $this->resolve_product_id_for_local_sku($local, $row->codigo_proveedor);
                $wpdb->update(
                    "{$prefix}codigos",
                    [
                        'sku_local' => $local,
                        'product_id' => $product_id,
                    ],
                    ['id' => (int) $row->id],
                    ['%s', '%d'],
                    ['%d']
                );
                $fixed++;
                continue;
            }

            if ($current !== '' && !riverso_is_trusted_supplier_local_sku($row->codigo_proveedor, $current)) {
                $wpdb->update(
                    "{$prefix}codigos",
                    [
                        'sku_local' => null,
                        'product_id' => null,
                    ],
                    ['id' => (int) $row->id],
                    ['%s', '%d'],
                    ['%d']
                );
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Persiste código interno del proveedor y sincroniza dominio canónico si hay SKU.
     */
    public function persist_supplier_code($proveedor_id, $codigo_proveedor, $descripcion, $codigos = [], $sku_local = null, $allow_untrusted = false, $document_date = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (empty($codigo_proveedor)) {
            return null;
        }

        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigos WHERE proveedor_id = %d AND codigo_proveedor = %s",
            (int) $proveedor_id,
            $codigo_proveedor
        ));
        $existing = $existing_row ? (int) $existing_row->id : 0;

        if (!$allow_untrusted) {
            // No convertir sugerencias de catálogo en mapeo; conservar el registro humano.
            $sku_local = ($existing_row && !empty($existing_row->sku_local))
                ? trim((string) $existing_row->sku_local)
                : null;
        }

        $sku_local = riverso_usable_local_sku($sku_local, $codigo_proveedor);

        if ($sku_local && $allow_untrusted) {
            $conflict = $this->get_sku_assignment_conflict($sku_local, (int) $proveedor_id, $codigo_proveedor);
            if ($conflict && ($conflict['code'] ?? '') === 'sku_owned_elsewhere') {
                $sku_local = null;
            }
        }

        $codigo_tipo = 'INT1';
        $codigo_barras = null;
        foreach ($codigos as $codigo) {
            if (!empty($codigo['tipo'])) {
                $codigo_tipo = sanitize_text_field($codigo['tipo']);
            }
            $tipo_upper = strtoupper($codigo['tipo'] ?? '');
            if (in_array($tipo_upper, ['EAN13', 'EAN', 'GTIN', 'BARCODE'], true) && !empty($codigo['valor'])) {
                $codigo_barras = sanitize_text_field($codigo['valor']);
            }
        }

        $product_id = $sku_local ? $this->resolve_product_id_for_local_sku($sku_local, $codigo_proveedor) : null;
        $product_base_id = null;
        $supplier_product_id = null;

        if ($sku_local && $product_id && class_exists('Riverso_Supplier_Links_Module')) {
            $link_data = [
                'supplier_id' => (int) $proveedor_id,
                'supplier_code' => $codigo_proveedor,
                'supplier_description' => $descripcion,
                'supplier_barcode' => $codigo_barras,
                'product_id' => (int) $product_id,
                'is_active' => 1,
                'match_confidence' => 100,
                'notes' => 'Auto-registrado desde factura XML',
            ];

            $links_module = Riverso_Supplier_Links_Module::get_instance();
            $existing_link = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}supplier_product_links
                 WHERE supplier_id = %d AND supplier_code = %s LIMIT 1",
                (int) $proveedor_id,
                $codigo_proveedor
            ));

            if ($existing_link) {
                $links_module->update_link((int) $existing_link, $link_data, 'Actualizado desde factura XML');
            } else {
                $created = $links_module->create_link($link_data);
                if (is_wp_error($created) && $created->get_error_code() === 'duplicate') {
                    $existing_link = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$prefix}supplier_product_links
                         WHERE supplier_id = %d AND supplier_code = %s LIMIT 1",
                        (int) $proveedor_id,
                        $codigo_proveedor
                    ));
                    if ($existing_link) {
                        $links_module->update_link((int) $existing_link, $link_data, 'Actualizado desde factura XML');
                    }
                }
            }

            $mapping = $this->lookup_product_mapping($proveedor_id, $codigo_proveedor);
            $product_base_id = $mapping['producto_base_id'];
            $supplier_product_id = $mapping['producto_proveedor_id'];
        }

        $codigo_payload = [
            'proveedor_id' => (int) $proveedor_id,
            'codigo_proveedor' => $codigo_proveedor,
            'codigo_tipo' => $codigo_tipo,
            'codigo_barras' => $codigo_barras,
            'nombre_proveedor' => $descripcion,
            'sku_local' => $sku_local,
            'product_id' => $product_id,
            'product_base_id' => $product_base_id,
            'supplier_product_id' => $supplier_product_id,
            'activo' => 1,
        ];
        $last_seen = $this->resolve_last_seen_document_date((int) $proveedor_id, $codigo_proveedor, $document_date);
        if ($last_seen) {
            $codigo_payload['last_seen_document_date'] = $last_seen;
        }
        if ($allow_untrusted) {
            $codigo_payload['sku_mapped_at'] = current_time('mysql');
        }

        if ($existing) {
            $wpdb->update(
                "{$prefix}codigos",
                array_merge($codigo_payload, ['updated_at' => current_time('mysql')]),
                ['id' => (int) $existing]
            );
        } else {
            $wpdb->insert("{$prefix}codigos", $codigo_payload);
        }

        if (!$supplier_product_id && $sku_local && $product_base_id) {
            $supplier_product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                (int) $proveedor_id,
                $codigo_proveedor
            ));
        }

        return [
            'codigo_id' => $existing ? (int) $existing : (int) $wpdb->insert_id,
            'producto_proveedor_id' => $supplier_product_id ? (int) $supplier_product_id : null,
            'producto_base_id' => $product_base_id ? (int) $product_base_id : null,
        ];
    }

    /**
     * Tabla de vínculos flete ↔ facturas de productos.
     */
    private function flete_vinculos_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_factura_flete_vinculos';
    }

    /**
     * Monto total de una factura de flete/transportista.
     */
    public function get_shipping_invoice_amount($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $from_items = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
             WHERE factura_id = %d AND item_tipo = 'envio'",
            (int) $factura_envio_id
        ));
        if ($from_items > 0) {
            return $from_items;
        }

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(monto_total, 0) FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
    }

    /**
     * Recalcula reparto del flete entre todas las facturas de productos vinculadas.
     */
    public function recalculate_flete_allocations($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.factura_productos_id, fp.monto_total
             FROM {$vinculos_table} v
             INNER JOIN {$prefix}facturas fp ON fp.id = v.factura_productos_id
             WHERE v.factura_envio_id = %d",
            (int) $factura_envio_id
        ));

        $shipping_total = $this->get_shipping_invoice_amount((int) $factura_envio_id);
        $base_total = 0.0;
        foreach ($links as $link) {
            $base_total += (float) ($link->monto_total ?? 0);
        }

        $affected_product_ids = [];
        foreach ($links as $link) {
            $share = 0.0;
            if ($shipping_total > 0) {
                if ($base_total > 0) {
                    $share = $shipping_total * ((float) $link->monto_total / $base_total);
                } else {
                    $share = $shipping_total / max(1, count($links));
                }
            }
            $wpdb->update(
                $vinculos_table,
                ['monto_asignado' => round($share, 2)],
                ['id' => (int) $link->id],
                ['%f'],
                ['%d']
            );
            $affected_product_ids[(int) $link->factura_productos_id] = true;
        }

        foreach (array_keys($affected_product_ids) as $producto_id) {
            $assigned = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(monto_asignado), 0) FROM {$vinculos_table} WHERE factura_productos_id = %d",
                (int) $producto_id
            ));
            $inline = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
                 WHERE factura_id = %d AND item_tipo = 'envio'",
                (int) $producto_id
            ));
            $wpdb->update(
                "{$prefix}facturas",
                ['costo_envio_total' => round($assigned + $inline, 2)],
                ['id' => (int) $producto_id],
                ['%f'],
                ['%d']
            );
            $this->prorate_shipping_costs((int) $producto_id);
        }

        return [
            'envio_id' => (int) $factura_envio_id,
            'shipping_total' => $shipping_total,
            'linked_invoices' => count($links),
            'affected_products' => array_keys($affected_product_ids),
        ];
    }

    /**
     * Sincroniza estado legacy de factura de flete según vínculos N:M.
     */
    public function sync_envio_link_state($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $first_product = $wpdb->get_var($wpdb->prepare(
            "SELECT factura_productos_id FROM {$vinculos_table}
             WHERE factura_envio_id = %d ORDER BY id ASC LIMIT 1",
            (int) $factura_envio_id
        ));
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$vinculos_table} WHERE factura_envio_id = %d",
            (int) $factura_envio_id
        ));

        $wpdb->update(
            "{$prefix}facturas",
            [
                'factura_productos_id' => $first_product ? (int) $first_product : null,
                'estado' => $count > 0 ? 'vinculado' : 'sin_vincular',
            ],
            ['id' => (int) $factura_envio_id],
            ['%d', '%s'],
            ['%d']
        );

        return $count;
    }

    /**
     * Vincula factura de envío (transportista) a factura de productos.
     */
    public function link_shipping_invoice($factura_productos_id, $factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $producto = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_productos_id
        ));
        if (!$producto) {
            return new WP_Error('not_found', 'Factura de productos no encontrada');
        }
        if (($producto->documento_subtipo ?? 'productos') === 'envio') {
            return new WP_Error('invalid_target', 'La factura destino debe ser de productos, no de flete');
        }

        $envio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
        if (!$envio) {
            return new WP_Error('not_found', 'Factura de envío no encontrada');
        }
        if (($envio->documento_subtipo ?? '') !== 'envio') {
            return new WP_Error('invalid_source', 'La factura origen debe ser de transportista / flete');
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$vinculos_table}
             WHERE factura_envio_id = %d AND factura_productos_id = %d",
            (int) $factura_envio_id,
            (int) $factura_productos_id
        ));
        if ($existing) {
            return new WP_Error('already_linked', 'Este flete ya está vinculado a esa factura de productos');
        }

        $wpdb->insert(
            $vinculos_table,
            [
                'factura_envio_id' => (int) $factura_envio_id,
                'factura_productos_id' => (int) $factura_productos_id,
                'monto_asignado' => 0,
                'created_by' => get_current_user_id() ?: null,
            ],
            ['%d', '%d', '%f', '%d']
        );

        $wpdb->update(
            "{$prefix}facturas",
            ['documento_subtipo' => 'envio'],
            ['id' => (int) $factura_envio_id],
            ['%s'],
            ['%d']
        );

        $result = $this->recalculate_flete_allocations((int) $factura_envio_id);
        $this->sync_envio_link_state((int) $factura_envio_id);

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_processed',
                'invoice',
                (int) $factura_envio_id,
                null,
                [
                    'action' => 'flete_vinculado',
                    'factura_productos_id' => (int) $factura_productos_id,
                    'linked_invoices' => $result['linked_invoices'] ?? 1,
                ],
                sprintf(
                    'Flete folio %s vinculado a factura productos folio %s',
                    $envio->folio ?? $factura_envio_id,
                    $producto->folio ?? $factura_productos_id
                )
            );
        }

        return $result;
    }

    /**
     * Desvincula factura de flete de una o todas las facturas de productos.
     */
    public function unlink_shipping_invoice($factura_envio_id, $factura_productos_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $envio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
        if (!$envio) {
            return new WP_Error('not_found', 'Factura de flete no encontrada');
        }

        $where = ['factura_envio_id = %d'];
        $params = [(int) $factura_envio_id];
        if ($factura_productos_id) {
            $where[] = 'factura_productos_id = %d';
            $params[] = (int) $factura_productos_id;
        }

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT factura_productos_id FROM {$vinculos_table} WHERE " . implode(' AND ', $where),
            ...$params
        ));

        if (!$links) {
            // Fallback legacy: un solo vínculo en factura_productos_id.
            if (!empty($envio->factura_productos_id)) {
                if ($factura_productos_id && (int) $envio->factura_productos_id !== (int) $factura_productos_id) {
                    return new WP_Error('not_linked', 'Este flete no está vinculado a esa factura');
                }
                $links = [(object) ['factura_productos_id' => (int) $envio->factura_productos_id]];
            } else {
                return new WP_Error('not_linked', 'Este flete no está vinculado a ninguna factura');
            }
        }

        $affected_products = [];
        foreach ($links as $link) {
            $affected_products[(int) $link->factura_productos_id] = true;
        }

        if ($factura_productos_id) {
            $wpdb->delete(
                $vinculos_table,
                [
                    'factura_envio_id' => (int) $factura_envio_id,
                    'factura_productos_id' => (int) $factura_productos_id,
                ],
                ['%d', '%d']
            );
        } else {
            $wpdb->delete($vinculos_table, ['factura_envio_id' => (int) $factura_envio_id], ['%d']);
        }

        $this->recalculate_flete_allocations((int) $factura_envio_id);
        foreach (array_keys($affected_products) as $producto_id) {
            if ((int) $producto_id !== (int) $factura_envio_id) {
                $assigned = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(monto_asignado), 0) FROM {$vinculos_table} WHERE factura_productos_id = %d",
                    (int) $producto_id
                ));
                $inline = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
                     WHERE factura_id = %d AND item_tipo = 'envio'",
                    (int) $producto_id
                ));
                $wpdb->update(
                    "{$prefix}facturas",
                    ['costo_envio_total' => round($assigned + $inline, 2)],
                    ['id' => (int) $producto_id],
                    ['%f'],
                    ['%d']
                );
                $this->prorate_shipping_costs((int) $producto_id);
            }
        }

        $this->sync_envio_link_state((int) $factura_envio_id);

        return true;
    }

    /**
     * Prorratea costo de envío entre ítems de producto (por valor neto de línea).
     */
    public function prorate_shipping_costs($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $inline_shipping = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
             WHERE factura_id = %d AND item_tipo = 'envio'",
            (int) $factura_id
        ));

        $linked_shipping = (float) ($factura->costo_envio_total ?? 0);
        $total_shipping = $inline_shipping + $linked_shipping;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $product_items = array_filter($items, function ($item) {
            return ($item->item_tipo ?? 'producto') !== 'envio';
        });

        $base_total = 0.0;
        foreach ($product_items as $item) {
            $base_total += (float) $item->monto_total;
        }

        foreach ($product_items as $item) {
            $qty = (float) ($item->qty_received ?: $item->cantidad ?: 1);
            if ($qty <= 0) {
                $qty = 1;
            }

            $product_cost_unit = (float) $item->precio_unitario;
            $shipping_share = 0.0;

            if ($total_shipping > 0 && $base_total > 0) {
                $shipping_share = $total_shipping * ((float) $item->monto_total / $base_total);
            }

            $shipping_per_unit = $shipping_share / $qty;
            $landed_unit = $product_cost_unit + $shipping_per_unit;

            $wpdb->update(
                "{$prefix}factura_items",
                [
                    'costo_envio_prorrateado' => round($shipping_share, 4),
                    'costo_landed_unitario' => round($landed_unit, 4),
                ],
                ['id' => (int) $item->id]
            );
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['envio_prorrateado' => $total_shipping > 0 ? 1 : 0],
            ['id' => (int) $factura_id]
        );

        return [
            'total_shipping' => $total_shipping,
            'items_updated' => count($product_items),
        ];
    }

    /**
     * Crea lote de inventario desde ítem de factura aprobado.
     */
    public function create_lote_from_approved_item($factura, $item) {
        if (!$this->should_update_warehouse($factura)) {
            return null;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (empty($item->sku_local) || ($item->item_tipo ?? 'producto') === 'envio') {
            return null;
        }

        $qty = (float) ($item->qty_received ?: $item->cantidad);
        if ($qty <= 0) {
            return null;
        }

        $landed_unit = (float) ($item->costo_landed_unitario ?: $item->precio_unitario);
        $shipping_unit = 0.0;
        if ($qty > 0 && !empty($item->costo_envio_prorrateado)) {
            $shipping_unit = (float) $item->costo_envio_prorrateado / $qty;
        }

        $persisted = $this->persist_supplier_code(
            (int) $factura->proveedor_id,
            $item->codigo_proveedor,
            $item->descripcion,
            [],
            $item->sku_local
        );

        $producto_proveedor_id = $persisted['producto_proveedor_id'] ?? null;
        if (!$producto_proveedor_id) {
            $producto_proveedor_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                (int) $factura->proveedor_id,
                $item->codigo_proveedor
            ));
        }

        if (!$producto_proveedor_id) {
            return new WP_Error('no_supplier_product', 'No se pudo resolver producto_proveedor para el lote');
        }

        $product_id = wc_get_product_id_by_sku($item->sku_local);
        $wc_product = $product_id ? wc_get_product($product_id) : null;
        $variation_id = 0;
        $parent_id = $product_id;
        if ($wc_product && $wc_product->is_type('variation')) {
            $variation_id = $product_id;
            $parent_id = $wc_product->get_parent_id();
        }

        $lote_codigo = sprintf('FAC-%d-%d', (int) $factura->id, (int) $item->id);

        $existing_lote = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}lotes WHERE lote_codigo = %s LIMIT 1",
            $lote_codigo
        ));
        if ($existing_lote) {
            return (int) $existing_lote;
        }

        $wpdb->insert(
            "{$prefix}lotes",
            [
                'producto_proveedor_id' => (int) $producto_proveedor_id,
                'product_id' => $parent_id,
                'variation_id' => $variation_id ?: null,
                'lote_codigo' => $lote_codigo,
                'fecha_recepcion' => current_time('mysql'),
                'cantidad_inicial' => $qty,
                'cantidad_disponible' => $qty,
                'costo_total' => round($landed_unit * $qty, 2),
                'costo_unitario' => round($landed_unit, 4),
                'costo_envio_unitario' => round($shipping_unit, 4),
                'moneda' => 'CLP',
                'estado' => 'abierto',
                'documento_tipo' => 'factura',
                'documento_id' => (int) $factura->id,
                'documento_item_id' => (int) $item->id,
                'origen_datos' => 'invoice_approval',
                'notas' => sprintf(
                    'Lote desde factura folio %s. Costo producto: %s, envío/u: %s',
                    $factura->folio ?? $factura->id,
                    number_format((float) $item->precio_unitario, 2, ',', '.'),
                    number_format($shipping_unit, 2, ',', '.')
                ),
            ]
        );

        $lote_id = (int) $wpdb->insert_id;
        $producto_base_id = $persisted['producto_base_id'] ?? $wpdb->get_var($wpdb->prepare(
            "SELECT producto_base_id FROM {$prefix}producto_proveedor WHERE id = %d",
            (int) $producto_proveedor_id
        ));

        if ($producto_base_id) {
            do_action('riverso_pos_lote_registrado', (int) $producto_base_id);
        }

        return $lote_id;
    }

    /**
     * Registra entrada de inventario en WooCommerce si está habilitado.
     */
    public function auto_inventory_entry($factura, $item, $lote_id = null) {
        if (!$this->should_update_warehouse($factura)) {
            return null;
        }
        if (!riverso_get_setting('auto_inventory_on_approve', true)) {
            return null;
        }

        if (empty($item->sku_local) || ($item->item_tipo ?? 'producto') === 'envio') {
            return null;
        }

        $qty = (float) ($item->qty_received ?: $item->cantidad);
        if ($qty <= 0) {
            return null;
        }

        $product_id = wc_get_product_id_by_sku($item->sku_local);
        if (!$product_id || !class_exists('Riverso_Warehouse_Module')) {
            return null;
        }

        $warehouse = Riverso_Warehouse_Module::get_instance();
        return $warehouse->record_movement([
            'tipo' => 'entrada',
            'product_id' => $product_id,
            'cantidad' => $qty,
            'referencia_tipo' => 'factura',
            'referencia_id' => (int) $factura->id,
            'notas' => sprintf(
                'Entrada automática factura #%s, ítem %d%s',
                $factura->folio ?? $factura->id,
                (int) $item->id,
                $lote_id ? ", lote {$lote_id}" : ''
            ),
        ]);
    }

    /**
     * Registra historial de costos de todos los ítems de una factura.
     */
    public function record_factura_cost_history($factura_id) {
        if (!class_exists('Riverso_Cost_History_Module')) {
            return ['recorded' => 0, 'pending' => 0];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.id AS supplier_id FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        if (riverso_get_setting('prorate_shipping_to_products', true)) {
            $this->prorate_shipping_costs((int) $factura_id);
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $cost_module = Riverso_Cost_History_Module::get_instance();
        $recorded = 0;
        $pending = 0;

        foreach ($items as $item) {
            $qty = (float) ($item->qty_received ?: $item->cantidad ?: 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $product_unit = (!empty($item->costo_neto_final) && $qty > 0)
                ? round(((float) $item->costo_neto_final) / $qty, 4)
                : (float) $item->precio_unitario;
            $landed_unit = (float) ($item->costo_landed_unitario ?: $product_unit);
            $landed_total = $landed_unit * $qty;
            if ($landed_total <= 0) {
                continue;
            }

            $product_id = null;
            $variation_id = null;
            if (!empty($item->sku_local)) {
                $product_id = wc_get_product_id_by_sku($item->sku_local);
                if ($product_id) {
                    $wc = wc_get_product($product_id);
                    if ($wc && $wc->is_type('variation')) {
                        $variation_id = $product_id;
                    }
                }
            }

            if (!$product_id && empty($item->codigo_proveedor)) {
                continue;
            }

            $result = $cost_module->record_cost([
                'product_id' => $product_id ?: 0,
                'variation_id' => $variation_id,
                'supplier_id' => (int) $factura->supplier_id,
                'source_type' => 'invoice',
                'source_document_id' => (int) $factura_id,
                'source_item_id' => (int) $item->id,
                'supplier_code' => $item->codigo_proveedor,
                'descripcion_proveedor' => $item->descripcion,
                'cost' => $landed_total,
                'quantity' => $qty,
                'costo_producto_unitario' => $product_unit,
                'costo_envio_prorrateado' => (float) ($item->costo_envio_prorrateado ?? 0),
                'document_date' => $factura->fecha_emision,
                'notes' => $product_id
                    ? sprintf('Costo landed desde factura folio %s', $factura->folio ?? $factura_id)
                    : sprintf('Costo pendiente de vincular — factura folio %s', $factura->folio ?? $factura_id),
            ]);

            if (!is_wp_error($result)) {
                if ($product_id) {
                    $recorded++;
                } else {
                    $pending++;
                }
            }
        }

        return ['recorded' => $recorded, 'pending' => $pending];
    }

    /**
     * Crea tareas para vincular códigos proveedor sin SKU local.
     */
    public function create_supplier_link_tasks($factura_id) {
        if (!class_exists('Riverso_Task_Module')) {
            return [];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.id, f.folio, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return [];
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, codigo_proveedor, descripcion, sku_local
             FROM {$prefix}factura_items
             WHERE factura_id = %d
               AND (item_tipo = 'producto' OR item_tipo IS NULL)
               AND (sku_local IS NULL OR sku_local = '' OR LOWER(sku_local) = LOWER(codigo_proveedor))
               AND codigo_proveedor IS NOT NULL AND codigo_proveedor != ''",
            (int) $factura_id
        ));

        $task_module = Riverso_Task_Module::get_instance();
        $created = [];
        foreach ($items as $item) {
            $task_id = $task_module->create_missing_code_task(
                (int) $item->id,
                $item->codigo_proveedor,
                $item->descripcion,
                $factura->proveedor_nombre ?? 'Proveedor'
            );
            if ($task_id && !is_wp_error($task_id)) {
                $created[] = (int) $task_id;
            }
        }
        return $created;
    }

    /**
     * Procesa factura en modo solo costos: historial + códigos, sin bodega.
     */
    public function process_cost_only_invoice($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $factura_id
        ), ARRAY_A);

        foreach ($items as $item) {
            if (in_array(($item['item_tipo'] ?? 'producto'), ['envio', 'gasto'], true)) {
                continue;
            }
            $codigos = [];
            if (!empty($item['codigo_proveedor'])) {
                $codigos[] = ['tipo' => $item['codigo_tipo'] ?? 'INT1', 'valor' => $item['codigo_proveedor']];
            }
            $mapping = $this->lookup_product_mapping(
                (int) $factura->proveedor_id,
                $item['codigo_proveedor'] ?? '',
                $codigos
            );
            $this->persist_supplier_code(
                (int) $factura->proveedor_id,
                $item['codigo_proveedor'] ?? '',
                $item['descripcion'] ?? '',
                $codigos,
                $mapping['sku_local']
            );
        }

        $costs = $this->record_factura_cost_history((int) $factura_id);
        $tasks = $this->create_supplier_link_tasks((int) $factura_id);

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => 'costos_registrados'],
            ['id' => (int) $factura_id]
        );

        return [
            'costs' => $costs,
            'link_tasks' => count($tasks),
        ];
    }

    /**
     * ¿Debe actualizarse bodega/inventario para esta factura?
     */
    public function should_update_warehouse($factura) {
        $modo = is_object($factura) ? ($factura->modo_ingreso ?? 'recepcion') : ($factura['modo_ingreso'] ?? 'recepcion');
        if ($modo === 'solo_costos') {
            return false;
        }
        return (bool) riverso_get_setting('auto_inventory_on_approve', true);
    }

    /**
     * Procesa ítems al guardar factura: códigos y clasificación.
     */
    public function after_invoice_saved($factura_id, $proveedor_id, array $items, $modo_ingreso = 'recepcion') {
        global $wpdb;
        $fecha_emision = $wpdb->get_var($wpdb->prepare(
            "SELECT fecha_emision FROM {$wpdb->prefix}riverso_facturas WHERE id = %d",
            (int) $factura_id
        ));

        foreach ($items as $item) {
            if (in_array(($item['item_tipo'] ?? 'producto'), ['envio', 'gasto'], true)) {
                continue;
            }

            $codigo_proveedor = '';
            foreach ($item['codigos'] ?? [] as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    break;
                }
            }

            if (!$codigo_proveedor) {
                continue;
            }

            $mapping = $this->lookup_product_mapping($proveedor_id, $codigo_proveedor, $item['codigos'] ?? []);
            $sku_to_persist = !empty($mapping['has_mapping']) ? ($mapping['sku_local'] ?? null) : null;
            $this->persist_supplier_code(
                $proveedor_id,
                $codigo_proveedor,
                $item['nombre'] ?? '',
                $item['codigos'] ?? [],
                $sku_to_persist,
                false,
                $fecha_emision
            );
        }

        $this->prorate_shipping_costs((int) $factura_id);

        if ($modo_ingreso === 'solo_costos') {
            return $this->process_cost_only_invoice((int) $factura_id);
        }

        if (riverso_get_setting('create_link_task_on_upload', true)) {
            $this->create_supplier_link_tasks((int) $factura_id);
        }

        if (riverso_get_setting('create_reception_task_on_upload', true)
            && class_exists('Riverso_Task_Module')) {
            $task_module = Riverso_Task_Module::get_instance();
            $task_module->create_reception_task((int) $factura_id);
        }

        return null;
    }

    /**
     * Crea (o reutiliza) la tarea de confirmar tipo de documento.
     */
    public function create_document_type_confirmation_task($factura_id) {
        if (!class_exists('Riverso_Task_Module')) {
            return 0;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_id = (int) $factura_id;

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT folio, documento_subtipo, tipo_confirmado FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ));

        if (!$factura || (int) $factura->tipo_confirmado === 1) {
            return 0;
        }

        $tipo_label = [
            'productos' => 'Productos',
            'envio' => 'Flete',
            'nota_credito' => 'Nota de Crédito',
            'guia_despacho' => 'Guía de Despacho',
            'gastos' => 'Gastos',
        ][$factura->documento_subtipo] ?? ($factura->documento_subtipo ?: 'sin clasificar');

        return Riverso_Task_Module::get_instance()->create_review_task(
            'confirmar_tipo_documento',
            sprintf('Confirmar tipo de documento - Folio %s (Sugerido: %s)', $factura->folio, $tipo_label),
            'factura',
            $factura_id,
            [
                'descripcion' => sprintf(
                    'Se sugiere que esta factura (folio %s) sea de tipo "%s". Confirme o cambie el tipo desde Facturas DTE.',
                    $factura->folio,
                    $tipo_label
                ),
                'prioridad' => 'alta',
            ]
        );
    }

    /**
     * Re-clasifica ítems según el tipo de documento confirmado.
     */
    public function apply_item_tipos_for_subtipo($factura_id, $documento_subtipo) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_id = (int) $factura_id;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, nombre, descripcion, sku_local, estado, codigo_proveedor FROM {$prefix}factura_items WHERE factura_id = %d",
            $factura_id
        ));

        foreach ($items ?: [] as $item) {
            if ($documento_subtipo === 'gastos') {
                $item_tipo = 'gasto';
                $item_estado = 'gasto';
            } elseif ($documento_subtipo === 'envio') {
                $item_tipo = 'envio';
                $item_estado = 'envio';
            } else {
                $tmp = [
                    'items' => [[
                        'nombre' => $item->nombre ?? '',
                        'descripcion' => $item->descripcion ?? '',
                        'monto' => 0,
                    ]],
                ];
                $this->classify_factura_items($tmp);
                $item_tipo = $tmp['items'][0]['item_tipo'] ?? 'producto';
                if ($item_tipo === 'envio') {
                    $item_estado = 'envio';
                } elseif ($item_tipo === 'gasto') {
                    $item_estado = 'gasto';
                } else {
                    $item_estado = riverso_usable_local_sku($item->sku_local ?? '', $item->codigo_proveedor ?? '')
                        ? 'vinculado'
                        : 'pendiente';
                }
            }

            $wpdb->update(
                "{$prefix}factura_items",
                [
                    'item_tipo' => $item_tipo,
                    'estado' => $item_estado,
                ],
                ['id' => (int) $item->id],
                ['%s', '%s'],
                ['%d']
            );
        }

        return true;
    }

    /**
     * Re-clasificar todos los ítems de una factura existente como gastos.
     */
    public function force_expense_items_for_factura($factura_id) {
        return $this->apply_item_tipos_for_subtipo($factura_id, 'gastos');
    }
}
