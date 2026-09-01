<?php
/**
 * Generación de Excel compatible con importación FACTO (CRUD por planilla).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-xlsx-writer.php';

class Riverso_Facto_Export_Service {

    const CHUNK_SIZE = 2000;

    const MODE_UPDATE_ONLY = 'update_only';
    const MODE_UPSERT      = 'upsert';
    const MODE_REPLACE     = 'replace';

    /** Unidades permitidas por plantilla FACTO. */
    const FACTO_UNITS = [
        'CAJA', 'CM', 'CM2', 'GR', 'KG', 'KWH', 'LT', 'LTS', 'MTS', 'MT2', 'MT3',
        'PAR', 'PACK', 'PLG', 'TON', 'UF', 'UN', 'BOX', 'PLT', 'CONT', 'PCS', 'PART',
        'TRAY', 'SET', 'RM', 'RMI', 'PLIE', 'HRS', 'BOLS',
    ];

    /** Orden fijo de columnas de stock en plantilla Excel FACTO (import CRUD). */
    private const FACTO_EXPORT_WAREHOUSE_CANONICAL = [
        'Bodega de Cajas',
        'Bodega general',
        'Otros productos',
    ];

    /** @var Riverso_Facto_Client|null */
    private $client;

    public function __construct(?Riverso_Facto_Client $client = null) {
        $this->client = $client;
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * @return array{headers: string[], locations: array<int, array{name: string, id: string}>}
     */
    public function get_export_schema($include_stock_values = false) {
        $headers = $this->base_headers();
        $locations = $this->resolve_warehouse_locations();
        foreach ($locations as $loc) {
            $headers[] = 'Disponibilidad en: ' . $loc['name'];
        }
        return [
            'headers'               => $headers,
            'locations'             => $locations,
            'include_stock_values'  => (bool) $include_stock_values,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function preview(array $filters) {
        $modo = $this->normalize_mode($filters['modo'] ?? self::MODE_UPSERT);
        $rows = $this->build_rows($filters);
        $validation = $this->validate_rows($rows, $modo);
        $total = count($rows);
        $tandas = $total > 0 ? max(1, (int) ceil($total / self::CHUNK_SIZE)) : 0;
        $pending = $this->get_pending_crud_summary(15);

        $replace_blocked = false;
        $replace_reason = '';
        if ($modo === self::MODE_REPLACE && $total > self::CHUNK_SIZE) {
            $replace_blocked = true;
            $replace_reason = sprintf(
                'Reemplazar requiere un solo archivo con todo el catálogo a conservar (máx. %d filas). Tienes %d productos; usa Solo actualizar o Agregar y actualizar por tandas, o elimina productos en FACTO manualmente.',
                self::CHUNK_SIZE,
                $total
            );
        }

        return [
            'modo'              => $modo,
            'total'             => $total,
            'tandas'            => $tandas,
            'chunk_size'        => self::CHUNK_SIZE,
            'validation'        => $validation,
            'replace_blocked'   => $replace_blocked,
            'replace_reason'    => $replace_reason,
            'can_download'      => $total > 0 && $validation['ok'] && !$replace_blocked,
            'mapped_count'      => $this->count_mapped_skus($rows),
            'sample_errors'     => array_slice($validation['errors'], 0, 20),
            'pending'           => $pending,
            'empty_hint'        => $total > 0 ? '' : $this->build_empty_hint($filters, $pending),
            'hydrated_count'    => count(array_filter($rows, static function ($row) {
                return !empty($row['_hydrated_from_facto']);
            })),
            'include_stock'     => $this->resolve_include_stock_flag($filters, $rows),
        ];
    }

    /**
     * Mensaje cuando la vista previa no devuelve filas.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $pending
     */
    private function build_empty_hint(array $filters, array $pending) {
        $hints = [];
        $modo = $this->normalize_mode($filters['modo'] ?? self::MODE_UPSERT);

        if (!empty($filters['pending_only'])) {
            if (($pending['pending_total'] ?? 0) <= 0) {
                $hints[] = 'Ningún producto está marcado como pendiente de export. Guarda de nuevo el producto en Productos (Datos FACTO) o desmarca «Solo pendientes».';
            } elseif ($modo === self::MODE_UPDATE_ONLY && ($pending['pending_mapped'] ?? 0) <= 0) {
                $hints[] = 'Hay pendientes pero ninguno tiene mapa FACTO. Usa modo «Agregar y actualizar» o vincula el SKU vía sync API.';
            } elseif (!empty($filters['sku'])) {
                $hints[] = 'El filtro SKU no coincide con los pendientes. Limpia el campo SKU o usa «Exportar solo pendientes».';
            } elseif (!empty($filters['only_changed'])) {
                $hints[] = 'Desmarca «Solo filas cambiadas» para exportar todos los pendientes.';
            } else {
                $hints[] = 'Ningún pendiente coincide con los filtros actuales.';
            }
        } elseif (!empty($filters['only_changed'])) {
            $hints[] = '«Solo filas cambiadas» excluye productos ya exportados sin cambios.';
        }

        if (!empty($filters['sku']) && empty($filters['pending_only'])) {
            $hints[] = 'Revisa el filtro SKU (debe coincidir con canonical_sku).';
        }

        if ($modo === self::MODE_UPDATE_ONLY && empty($filters['pending_only'])) {
            $hints[] = 'Modo «Solo actualizar» solo incluye SKUs ya vinculados en FACTO.';
        }

        if (empty($hints)) {
            $hints[] = 'No hay productos que cumplan los filtros actuales.';
        }

        return implode(' ', $hints);
    }

    /**
     * Productos con cambios locales aún no exportados/aplicados en FACTO.
     *
     * @return array<string, mixed>
     */
    public function get_pending_crud_summary($sample_limit = 25) {
        global $wpdb;

        $map_table = $this->table('facto_producto_map');
        $pb_table = $this->table('producto_base');

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$map_table} fm
             INNER JOIN {$pb_table} pb ON pb.id = fm.producto_base_id
             WHERE fm.sync_state = 'pendiente_excel'
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL
               AND pb.canonical_sku <> ''"
        );

        $pending_mapped = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$map_table} fm
             INNER JOIN {$pb_table} pb ON pb.id = fm.producto_base_id
             WHERE fm.sync_state = 'pendiente_excel'
               AND fm.facto_product_id IS NOT NULL
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL
               AND pb.canonical_sku <> ''"
        );

        $pending_create = max(0, $total - $pending_mapped);

        $sample_limit = max(1, min(50, absint($sample_limit)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT fm.facto_sku, fm.facto_product_id, pb.canonical_sku, pb.nombre_canonico, pb.marca, fm.updated_at
             FROM {$map_table} fm
             INNER JOIN {$pb_table} pb ON pb.id = fm.producto_base_id
             WHERE fm.sync_state = 'pendiente_excel'
               AND pb.deleted_at IS NULL
               AND pb.canonical_sku IS NOT NULL
               AND pb.canonical_sku <> ''
             ORDER BY fm.updated_at DESC
             LIMIT %d",
            $sample_limit
        ), ARRAY_A) ?: [];

        $samples = [];
        foreach ($rows as $row) {
            $samples[] = [
                'sku'     => trim((string) ($row['facto_sku'] ?: $row['canonical_sku'] ?? '')),
                'nombre'  => (string) ($row['nombre_canonico'] ?? ''),
                'marca'   => (string) ($row['marca'] ?? ''),
                'updated' => (string) ($row['updated_at'] ?? ''),
                'accion'  => !empty($row['facto_product_id']) ? 'EDITAR' : 'CREAR',
            ];
        }

        if ($pending_create > 0) {
            $recommended_modo = self::MODE_UPSERT;
        } elseif ($pending_mapped > 0) {
            $recommended_modo = self::MODE_UPDATE_ONLY;
        } else {
            $recommended_modo = self::MODE_UPSERT;
        }

        return [
            'pending_total'    => $total,
            'pending_mapped'   => $pending_mapped,
            'pending_create'   => $pending_create,
            'recommended_modo' => $recommended_modo,
            'samples'          => $samples,
            'message'        => $total > 0
                ? sprintf(
                    '%d producto(s) pendientes de export a FACTO (%d CREAR, %d EDITAR).',
                    $total,
                    $pending_create,
                    $pending_mapped
                )
                : 'No hay productos marcados como pendientes de export.',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|WP_Error
     */
    public function generate_batch(array $filters) {
        $modo = $this->normalize_mode($filters['modo'] ?? self::MODE_UPSERT);
        $tanda = max(1, absint($filters['tanda'] ?? 1));

        $rows = $this->build_rows($filters);
        if (empty($rows)) {
            $pending = $this->get_pending_crud_summary(5);
            return new WP_Error(
                'no_rows',
                'No hay filas para exportar. ' . $this->build_empty_hint($filters, $pending)
            );
        }

        $include_stock = $this->resolve_include_stock_flag($filters, $rows);

        $validation = $this->validate_rows($rows, $modo);
        if (!$validation['ok']) {
            return new WP_Error('validation_failed', 'Hay errores de validación en el export', $validation);
        }

        if ($modo === self::MODE_REPLACE && count($rows) > self::CHUNK_SIZE) {
            return new WP_Error('replace_blocked', 'Modo Reemplazar bloqueado: el universo supera 2000 filas.');
        }

        $chunks = array_chunk($rows, self::CHUNK_SIZE);
        $tandas_total = count($chunks);
        if ($tanda > $tandas_total) {
            return new WP_Error('invalid_tanda', 'Número de tanda fuera de rango.');
        }

        $chunk = $chunks[$tanda - 1];
        $schema = $this->get_export_schema($include_stock);
        $sheet_rows = [$schema['headers']];
        foreach ($chunk as $row) {
            $sheet_rows[] = $this->row_to_sheet_values($row, $schema);
        }

        $writer = new Riverso_Xlsx_Writer('Datos de producto');
        $writer->set_rows($sheet_rows);
        $binary = $writer->to_string();
        if ($binary === false) {
            return new WP_Error('xlsx_failed', 'No se pudo generar el archivo Excel (ZipArchive).');
        }

        $file_hash = hash('sha256', $binary);
        $batch_id = $this->record_batch($modo, $filters, count($chunk), $tanda, $tandas_total, $file_hash, $chunk);

        return [
            'batch_id'      => $batch_id,
            'filename'      => $this->build_filename($modo, $tanda, $tandas_total),
            'binary'        => $binary,
            'file_hash'     => $file_hash,
            'rows_in_file'  => count($chunk),
            'tanda'         => $tanda,
            'tandas_total'  => $tandas_total,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function build_rows(array $filters) {
        global $wpdb;

        $prefix = $wpdb->prefix . 'riverso_';
        $modo = $this->normalize_mode($filters['modo'] ?? self::MODE_UPSERT);
        $include_archived = !empty($filters['include_archived']);
        $sku_filter = $this->parse_sku_filter($filters['sku'] ?? '');
        $only_changed = !empty($filters['only_changed']);
        $pending_only = !empty($filters['pending_only']);
        $hydrate_from_facto = array_key_exists('hydrate_from_facto', $filters)
            ? !empty($filters['hydrate_from_facto'])
            : ($modo === self::MODE_UPDATE_ONLY);

        $category_map = $hydrate_from_facto ? $this->resolve_facto_category_map() : [];

        $where = [
            "pb.canonical_sku IS NOT NULL",
            "pb.canonical_sku <> ''",
            "pb.deleted_at IS NULL",
        ];
        if (!$include_archived) {
            $where[] = 'pb.archived_at IS NULL';
        }

        $params = [];
        if (!empty($sku_filter)) {
            $placeholders = implode(',', array_fill(0, count($sku_filter), '%s'));
            $where[] = "pb.canonical_sku IN ($placeholders)";
            $params = array_merge($params, $sku_filter);
        }

        if ($modo === self::MODE_UPDATE_ONLY) {
            $where[] = 'fm.facto_product_id IS NOT NULL';
        }

        if ($pending_only) {
            $where[] = "fm.sync_state = 'pendiente_excel'";
        }

        $join_fm = ($pending_only || $modo === self::MODE_UPDATE_ONLY)
            ? "INNER JOIN {$prefix}facto_producto_map fm ON fm.producto_base_id = pb.id"
            : "LEFT JOIN {$prefix}facto_producto_map fm ON fm.producto_base_id = pb.id";

        $sql = "
            SELECT
                pb.*,
                pl.c_ref,
                pl.p_asignado,
                fm.facto_product_id,
                fm.sync_state AS facto_sync_state,
                psc.stock_minimo AS stock_minimo_config,
                lpr_min.stock_minimo AS legacy_stock_minimo,
                cb.codigo AS barcode_codigo,
                (
                    SELECT MAX(l.costo_unitario)
                    FROM {$prefix}lotes l
                    INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
                    WHERE pp.producto_base_id = pb.id
                      AND l.costo_unitario IS NOT NULL
                      AND l.estado <> 'bloqueado'
                ) AS costo_lote_max,
                (
                    SELECT COALESCE(SUM(pu.cantidad), 0)
                    FROM {$prefix}producto_ubicacion pu
                    WHERE pu.product_id = pb.id
                ) AS stock_total_local
            FROM {$prefix}producto_base pb
            LEFT JOIN {$prefix}precios pl
                ON pl.producto_base_id = pb.id
               AND pl.canal = 'local'
               AND pl.woocommerce_variation_id = 0
            {$join_fm}
            LEFT JOIN {$prefix}producto_stock_config psc
                ON psc.producto_base_id = pb.id
            LEFT JOIN (
                SELECT sku, MAX(stock_minimo) AS stock_minimo
                FROM {$prefix}legacy_precio_ref
                WHERE stock_minimo IS NOT NULL
                GROUP BY sku
            ) lpr_min ON lpr_min.sku = pb.canonical_sku
            LEFT JOIN (
                SELECT producto_base_id, MIN(codigo) AS codigo
                FROM {$prefix}codigo_barra
                WHERE activo = 1 AND tipo = 'ean13' AND factor_a_unidad_base = 1
                GROUP BY producto_base_id
            ) cb ON cb.producto_base_id = pb.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pb.canonical_sku ASC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $raw = $wpdb->get_results($sql, ARRAY_A);
        if (!$raw) {
            return [];
        }

        $last_hashes = $only_changed ? $this->get_last_applied_row_hashes() : [];
        $rows = [];
        foreach ($raw as $item) {
            $row = $this->normalize_row($item);
            if ($hydrate_from_facto && !empty($row['_facto_product_id'])) {
                $row = $this->hydrate_row_from_facto($row, $category_map);
            }
            $row = $this->finalize_row_stock($row, $filters, $item);
            $row = $this->apply_facto_numeric_defaults($row);
            if ($only_changed) {
                $sku = $row['SKU'] ?? '';
                $hash = $this->compute_row_hash($row);
                if (isset($last_hashes[$sku]) && $last_hashes[$sku] === $hash) {
                    continue;
                }
                $row['_row_hash'] = $hash;
            } else {
                $row['_row_hash'] = $this->compute_row_hash($row);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function normalize_row(array $item) {
        $iva_tipo = strtolower(trim((string) ($item['facto_iva_tipo'] ?? 'afecto')));
        if (!in_array($iva_tipo, ['afecto', 'exento'], true)) {
            $iva_tipo = 'afecto';
        }

        $precio_total = $this->resolve_precio_total($item);
        $pricing = $this->split_price_iva($precio_total, $iva_tipo);

        $costo = $item['c_ref'] ?? null;
        if ($costo === null || $costo === '') {
            $costo = $item['costo_lote_max'] ?? 0;
        }
        $costo = round((float) $costo, 6);

        $categoria = trim((string) ($item['facto_categoria'] ?? ''));
        if ($categoria === '') {
            $categoria = $this->derive_categoria_from_woo((int) ($item['woocommerce_product_id'] ?? 0));
        }

        $stock_min_val = $this->resolve_stock_minimo_value($item);

        $desc_ecommerce = $this->derive_ecommerce_description($item);
        $descripcion = trim((string) ($item['descripcion_facto'] ?? ''));

        return [
            'Categoria'                          => $categoria,
            'Nombre'                             => $this->sanitize_facto_name($item['nombre_canonico'] ?? ''),
            'SKU'                                => trim((string) ($item['canonical_sku'] ?? '')),
            'Marca'                              => trim((string) ($item['marca'] ?? '')),
            'Modelo'                             => trim((string) ($item['modelo'] ?? '')),
            'Unidad'                             => $this->map_unit($item['unidad_base'] ?? 'unidad'),
            'Código de barras'                   => trim((string) ($item['barcode_codigo'] ?? '')),
            'Producto / Servicio'                => 'producto',
            'Costo neto'                         => $costo,
            'Venta: Precio neto'                 => $pricing['neto'],
            'Venta: afecto/exento de IVA'        => $iva_tipo,
            'Venta: Monto IVA'                   => $pricing['iva'],
            'Venta: Código Impuesto específico'  => '',
            'Venta: Monto impuesto específico'   => '',
            'Venta: Precio total'                => $pricing['total'],
            'Stock mínimo'                       => $stock_min_val,
            'Descripción'                        => $descripcion,
            'Descripción ecommerce'              => $desc_ecommerce,
            'Seriales'                           => '',
            'Información Adicional 1'            => '',
            'Información Adicional 2'            => '',
            'Información Adicional 3'            => '',
            '_producto_base_id'                  => (int) ($item['id'] ?? 0),
            '_facto_product_id'                  => (int) ($item['facto_product_id'] ?? 0),
            '_facto_sync_state'                  => (string) ($item['facto_sync_state'] ?? ''),
            '_stock_local_total'                 => $this->resolve_local_stock_total_from_item($item),
            '_stock_by_location'                 => [],
            '_local_has_price'                   => ($precio_total !== '' && $precio_total !== null),
        ];
    }

    /**
     * Completa la fila con el estado actual en FACTO y aplica encima los valores locales no vacíos.
     *
     * @param array<string, mixed> $local_row
     * @param array<string, string> $category_map
     * @return array<string, mixed>
     */
    private function hydrate_row_from_facto(array $local_row, array $category_map) {
        $fid = (int) ($local_row['_facto_product_id'] ?? 0);
        if ($fid <= 0) {
            return $local_row;
        }

        $remote = $this->fetch_facto_product($fid);
        if (!$remote) {
            return $local_row;
        }

        $base = $this->map_facto_api_to_export_row($remote, $category_map);
        $merged = $this->merge_export_rows($base, $local_row, !empty($local_row['_local_has_price']));

        $location_map = $this->resolve_facto_location_id_map();
        $remote_stock = $this->map_facto_inventories_to_stock($remote, $location_map);
        if (!empty($remote_stock)) {
            $merged['_stock_by_location'] = $remote_stock;
            $merged['_hydrated_stock'] = true;
        }

        $merged['_hydrated_from_facto'] = true;
        unset($merged['_local_has_price']);
        return $merged;
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string, string> $category_map
     * @return array<string, mixed>
     */
    private function map_facto_api_to_export_row(array $remote, array $category_map) {
        $price_entry = null;
        if (!empty($remote['price']) && is_array($remote['price'])) {
            $price_entry = $remote['price'][0] ?? null;
        }

        $total = 0.0;
        $neto = 0.0;
        $iva = 0.0;
        $iva_tipo = 'afecto';
        if (is_array($price_entry)) {
            $total = round((float) ($price_entry['unit_total'] ?? 0), 6);
            $neto = round((float) ($price_entry['unit_net'] ?? 0), 6);
            $iva = round((float) ($price_entry['unit_tax'] ?? 0), 6);
            $iva_tipo = ($iva > 0.000001) ? 'afecto' : 'exento';
        }

        $cat_id = trim((string) ($remote['product_category_id'] ?? ''));
        $categoria = ($cat_id !== '' && isset($category_map[$cat_id])) ? $category_map[$cat_id] : '';

        $cost = 0.0;
        if (!empty($remote['cost']) && is_array($remote['cost']) && isset($remote['cost']['value'])) {
            $cost = round((float) $remote['cost']['value'], 6);
        }

        $unit = strtoupper(trim((string) ($remote['measure_unit'] ?? 'UN')));
        if (!in_array($unit, self::FACTO_UNITS, true)) {
            $unit = 'UN';
        }

        return [
            'Categoria'                          => $categoria,
            'Nombre'                             => $this->sanitize_facto_name($remote['name'] ?? ''),
            'SKU'                                => trim((string) ($remote['sku'] ?? '')),
            'Marca'                              => trim((string) ($remote['brand'] ?? '')),
            'Modelo'                             => trim((string) ($remote['model'] ?? '')),
            'Unidad'                             => $unit,
            'Código de barras'                   => trim((string) ($remote['embedded_quantity_barcode'] ?? '')),
            'Producto / Servicio'                => 'producto',
            'Costo neto'                         => $cost,
            'Venta: Precio neto'                 => $neto,
            'Venta: afecto/exento de IVA'        => $iva_tipo,
            'Venta: Monto IVA'                   => $iva,
            'Venta: Código Impuesto específico'  => '',
            'Venta: Monto impuesto específico'   => '',
            'Venta: Precio total'                => $total,
            'Stock mínimo'                       => '',
            'Descripción'                        => trim((string) ($remote['invoicing_details'] ?? '')),
            'Descripción ecommerce'              => trim((string) ($remote['additional_details'] ?? '')),
            'Seriales'                           => '',
            'Información Adicional 1'            => trim((string) ($remote['additional_data_1'] ?? '')),
            'Información Adicional 2'            => trim((string) ($remote['additional_data_2'] ?? '')),
            'Información Adicional 3'            => trim((string) ($remote['additional_data_3'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $local
     */
    private function merge_export_rows(array $base, array $local, $local_has_price) {
        $merged = $base;
        foreach ($this->base_headers() as $header) {
            if ($this->local_overrides_facto_field($header, $local[$header] ?? null)) {
                $merged[$header] = $local[$header];
            }
        }

        if ($local_has_price) {
            foreach ([
                'Venta: Precio neto',
                'Venta: Monto IVA',
                'Venta: Precio total',
                'Venta: afecto/exento de IVA',
            ] as $price_field) {
                if (array_key_exists($price_field, $local)) {
                    $merged[$price_field] = $local[$price_field];
                }
            }
        }

        foreach (['_producto_base_id', '_facto_product_id', '_facto_sync_state', '_stock_by_location', '_hydrated_stock'] as $meta) {
            if (array_key_exists($meta, $local)) {
                $merged[$meta] = $local[$meta];
            }
        }

        return $merged;
    }

    /**
     * @param mixed $value
     */
    private function local_overrides_facto_field($field, $value) {
        if (in_array($field, [
            'Venta: Precio neto',
            'Venta: Monto IVA',
            'Venta: Precio total',
            'Venta: afecto/exento de IVA',
        ], true)) {
            return false;
        }

        if ($field === 'Costo neto') {
            return is_numeric($value) && (float) $value > 0;
        }

        if ($field === 'Stock mínimo') {
            return $value !== '' && $value !== null;
        }

        return trim((string) ($value ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch_facto_product($facto_product_id) {
        static $cache = [];
        $facto_product_id = absint($facto_product_id);
        if ($facto_product_id <= 0) {
            return null;
        }
        if (array_key_exists($facto_product_id, $cache)) {
            return $cache[$facto_product_id];
        }

        if ($this->client === null && class_exists('Riverso_Facto_Client')) {
            $this->client = new Riverso_Facto_Client();
        }
        if (!$this->client) {
            $cache[$facto_product_id] = null;
            return null;
        }

        $resp = $this->client->get_product($facto_product_id);
        if (is_wp_error($resp) || !is_array($resp)) {
            $cache[$facto_product_id] = null;
            return null;
        }

        $cache[$facto_product_id] = $resp;
        return $resp;
    }

    /**
     * @return array<string, string> product_category_id => name
     */
    private function resolve_facto_category_map() {
        $cached = get_transient('riverso_facto_category_map');
        if (is_array($cached)) {
            return $cached;
        }

        $map = [];
        if ($this->client === null && class_exists('Riverso_Facto_Client')) {
            $this->client = new Riverso_Facto_Client();
        }
        if ($this->client) {
            $resp = $this->client->request('GET', 'product_categories');
            if (!is_wp_error($resp)) {
                $items = Riverso_Facto_Client::embed_collection($resp, 'product_categories');
                foreach ((array) $items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $id = trim((string) ($item['product_category_id'] ?? $item['id'] ?? ''));
                    $name = trim((string) ($item['name'] ?? ''));
                    if ($id !== '' && $name !== '') {
                        $map[$id] = $name;
                    }
                }
            }
        }

        set_transient('riverso_facto_category_map', $map, HOUR_IN_SECONDS);
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, string>}
     */
    public function validate_rows(array $rows, $modo) {
        $errors = [];
        $warnings = [];
        $seen_sku = [];

        foreach ($rows as $idx => $row) {
            $line = $idx + 1;
            $sku = trim((string) ($row['SKU'] ?? ''));
            $nombre = trim((string) ($row['Nombre'] ?? ''));

            if ($sku === '') {
                $errors[] = ['line' => (string) $line, 'sku' => '', 'message' => 'SKU vacío'];
                continue;
            }
            if (isset($seen_sku[$sku])) {
                $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => 'SKU duplicado en el export'];
            }
            $seen_sku[$sku] = true;

            if ($nombre === '') {
                $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => 'Nombre vacío'];
            }

            $iva = strtolower((string) ($row['Venta: afecto/exento de IVA'] ?? ''));
            if (!in_array($iva, ['afecto', 'exento'], true)) {
                $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => 'IVA debe ser afecto o exento'];
            }

            $total = $row['Venta: Precio total'];
            if ($total === '' || $total === null) {
                if ($modo !== self::MODE_UPDATE_ONLY) {
                    $warnings[] = "SKU $sku: sin precio total (puede fallar import en FACTO)";
                }
            } else {
                $neto = (float) ($row['Venta: Precio neto'] ?? 0);
                $iva_m = (float) ($row['Venta: Monto IVA'] ?? 0);
                $sum = round($neto + $iva_m, 6);
                if (abs($sum - (float) $total) > 0.000001) {
                    $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => 'Precio total no cuadra con neto + IVA'];
                }
            }

            $unit = strtoupper(trim((string) ($row['Unidad'] ?? '')));
            if ($unit !== '' && !in_array($unit, self::FACTO_UNITS, true)) {
                $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => "Unidad no permitida: $unit"];
            }

            if ($modo === self::MODE_UPDATE_ONLY && empty($row['_facto_product_id'])) {
                $errors[] = ['line' => (string) $line, 'sku' => $sku, 'message' => 'Sin mapa FACTO (no aplica a Solo actualizar)'];
            }
        }

        return [
            'ok'       => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    public function mark_batch_applied($batch_id, $notas = '') {
        global $wpdb;
        $batch_id = absint($batch_id);
        $table = $this->table('facto_export_batches');
        $now = current_time('mysql');

        $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $batch_id), ARRAY_A);
        if (!$batch) {
            return new WP_Error('batch_not_found', 'Lote no encontrado');
        }
        if (($batch['estado'] ?? '') !== 'generado') {
            return new WP_Error('batch_not_markable', 'Solo se pueden marcar lotes en estado generado');
        }

        $wpdb->update($table, [
            'estado'                 => 'aplicado',
            'applied_at'             => $now,
            'notas'                  => sanitize_textarea_field($notas),
            'superseded_by_batch_id' => null,
        ], ['id' => $batch_id]);

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT producto_base_id, sku, row_hash FROM {$this->table('facto_export_items')} WHERE batch_id = %d",
            $batch_id
        ), ARRAY_A);

        $map_table = $this->table('facto_producto_map');
        foreach ($items as $item) {
            $pid = (int) ($item['producto_base_id'] ?? 0);
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($pid <= 0) {
                continue;
            }

            $updated = $wpdb->update(
                $map_table,
                [
                    'sync_state'     => 'synced',
                    'last_synced_at' => $now,
                    'last_error'     => null,
                    'updated_at'     => $now,
                ],
                ['producto_base_id' => $pid]
            );

            if ($updated === 0) {
                if ($sku === '') {
                    $sku = (string) $wpdb->get_var($wpdb->prepare(
                        "SELECT canonical_sku FROM {$this->table('producto_base')} WHERE id = %d",
                        $pid
                    ));
                    $sku = trim($sku);
                }
                if ($sku === '') {
                    continue;
                }
                $wpdb->insert(
                    $map_table,
                    [
                        'producto_base_id' => $pid,
                        'facto_product_id' => null,
                        'facto_sku'        => $sku,
                        'sync_state'       => 'synced',
                        'last_synced_at'   => $now,
                        'last_error'       => null,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]
                );
            }
        }

        $this->recompute_superseded_batches();

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('facto_export.applied', 'facto_export_batch', $batch_id, [
                'actor_type' => 'human',
                'details'    => ['items' => count($items), 'notas' => $notas],
            ]);
        }

        return true;
    }

    /**
     * Revierte un lote marcado como aplicado por error.
     *
     * @return true|WP_Error
     */
    public function unmark_batch_applied($batch_id) {
        global $wpdb;
        $batch_id = absint($batch_id);
        $table = $this->table('facto_export_batches');
        $now = current_time('mysql');

        $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $batch_id), ARRAY_A);
        if (!$batch) {
            return new WP_Error('batch_not_found', 'Lote no encontrado');
        }
        if (($batch['estado'] ?? '') !== 'aplicado') {
            return new WP_Error('batch_not_applied', 'Solo se puede desmarcar un lote aplicado');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT producto_base_id, sku FROM {$this->table('facto_export_items')} WHERE batch_id = %d",
            $batch_id
        ), ARRAY_A);

        $wpdb->update($table, [
            'estado'                 => 'generado',
            'applied_at'             => null,
            'superseded_by_batch_id' => null,
        ], ['id' => $batch_id]);

        $covered_elsewhere = $this->get_skus_in_applied_batches_except($batch_id);

        foreach ($items as $item) {
            $pid = (int) ($item['producto_base_id'] ?? 0);
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($pid <= 0) {
                continue;
            }
            if ($sku !== '' && isset($covered_elsewhere[$sku])) {
                continue;
            }
            $wpdb->update(
                $this->table('facto_producto_map'),
                [
                    'sync_state' => 'pendiente_excel',
                    'updated_at' => $now,
                ],
                ['producto_base_id' => $pid]
            );
        }

        $this->recompute_superseded_batches();

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('facto_export.unapplied', 'facto_export_batch', $batch_id, [
                'actor_type' => 'human',
                'details'    => ['items' => count($items)],
            ]);
        }

        return true;
    }

    /**
     * Recalcula lotes generado/supersedido según lotes aplicados posteriores.
     */
    public function recompute_superseded_batches() {
        global $wpdb;
        $batch_table = $this->table('facto_export_batches');
        $items_table = $this->table('facto_export_items');

        $batches = $wpdb->get_results(
            "SELECT id, estado FROM {$batch_table} ORDER BY id ASC",
            ARRAY_A
        );
        if (!$batches) {
            return;
        }

        $sku_map = [];
        $rows = $wpdb->get_results(
            "SELECT batch_id, sku FROM {$items_table} WHERE sku IS NOT NULL AND sku <> ''",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $bid = (int) ($row['batch_id'] ?? 0);
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($bid <= 0 || $sku === '') {
                continue;
            }
            if (!isset($sku_map[$bid])) {
                $sku_map[$bid] = [];
            }
            $sku_map[$bid][$sku] = true;
        }

        $applied = [];
        foreach ($batches as $batch) {
            if (($batch['estado'] ?? '') === 'aplicado') {
                $applied[(int) $batch['id']] = $sku_map[(int) $batch['id']] ?? [];
            }
        }

        foreach ($batches as $batch) {
            $bid = (int) ($batch['id'] ?? 0);
            $estado = (string) ($batch['estado'] ?? '');
            if ($bid <= 0 || $estado === 'aplicado') {
                continue;
            }

            $batch_skus = $sku_map[$bid] ?? [];
            if (empty($batch_skus)) {
                if ($estado === 'supersedido') {
                    $wpdb->update($batch_table, [
                        'estado'                 => 'generado',
                        'superseded_by_batch_id' => null,
                    ], ['id' => $bid]);
                }
                continue;
            }

            $superseder = 0;
            foreach ($applied as $applied_id => $applied_skus) {
                if ($applied_id <= $bid || empty($applied_skus)) {
                    continue;
                }
                foreach ($batch_skus as $sku => $_) {
                    if (isset($applied_skus[$sku])) {
                        if ($applied_id > $superseder) {
                            $superseder = $applied_id;
                        }
                        break;
                    }
                }
            }

            if ($superseder > 0) {
                $wpdb->update($batch_table, [
                    'estado'                 => 'supersedido',
                    'superseded_by_batch_id' => $superseder,
                ], ['id' => $bid]);
            } elseif ($estado === 'supersedido') {
                $wpdb->update($batch_table, [
                    'estado'                 => 'generado',
                    'superseded_by_batch_id' => null,
                ], ['id' => $bid]);
            }
        }
    }

    /**
     * @return array<string, true> sku => true
     */
    private function get_skus_in_applied_batches_except($exclude_batch_id) {
        global $wpdb;
        $exclude_batch_id = absint($exclude_batch_id);
        $batch_table = $this->table('facto_export_batches');
        $items_table = $this->table('facto_export_items');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.sku
             FROM {$items_table} i
             INNER JOIN {$batch_table} b ON b.id = i.batch_id
             WHERE b.estado = 'aplicado'
               AND b.id <> %d
               AND i.sku IS NOT NULL
               AND i.sku <> ''",
            $exclude_batch_id
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $out[$sku] = true;
            }
        }
        return $out;
    }

    /**
     * Detalle de un lote: ítems, diffs vs lote aplicado previo, info de supersedido.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_batch_diff($batch_id) {
        global $wpdb;
        $batch_id = absint($batch_id);
        $batch_table = $this->table('facto_export_batches');
        $items_table = $this->table('facto_export_items');

        $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$batch_table} WHERE id = %d", $batch_id), ARRAY_A);
        if (!$batch) {
            return new WP_Error('batch_not_found', 'Lote no encontrado');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT sku, row_hash, payload_json FROM {$items_table} WHERE batch_id = %d ORDER BY sku ASC",
            $batch_id
        ), ARRAY_A);

        $overlap_skus = [];
        $superseded_by = (int) ($batch['superseded_by_batch_id'] ?? 0);
        if ($superseded_by > 0) {
            $current_skus = [];
            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku !== '') {
                    $current_skus[$sku] = true;
                }
            }
            $other_items = $wpdb->get_results($wpdb->prepare(
                "SELECT sku FROM {$items_table} WHERE batch_id = %d",
                $superseded_by
            ), ARRAY_A);
            foreach ($other_items as $oi) {
                $sku = trim((string) ($oi['sku'] ?? ''));
                if ($sku !== '' && isset($current_skus[$sku])) {
                    $overlap_skus[] = $sku;
                }
            }
        }

        $prev_payload_by_sku = $this->get_previous_applied_payloads_for_batch($batch_id);

        $diff_fields = [
            'Categoria',
            'Nombre',
            'Marca',
            'Modelo',
            'Venta: Precio total',
            'Stock mínimo',
            'Disponibilidad en: Bodega general',
        ];

        $rows_out = [];
        $changed_count = 0;
        $has_payload = false;

        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $payload = $this->decode_payload_json($item['payload_json'] ?? null);
            if (!empty($payload)) {
                $has_payload = true;
            }

            $row_out = [
                'sku'      => $sku,
                'nombre'   => (string) ($payload['Nombre'] ?? ''),
                'row_hash' => (string) ($item['row_hash'] ?? ''),
                'fields'   => [],
                'diffs'    => [],
            ];

            foreach ($diff_fields as $field) {
                $val = $payload[$field] ?? '';
                if ($val !== '' && $val !== null) {
                    $row_out['fields'][$field] = $val;
                }
            }

            if ($sku !== '' && isset($prev_payload_by_sku[$sku])) {
                $prev = $prev_payload_by_sku[$sku];
                $diffs = $this->diff_payloads($prev, $payload, $diff_fields);
                if (!empty($diffs)) {
                    $row_out['diffs'] = $diffs;
                    $changed_count++;
                }
            }

            $rows_out[] = $row_out;
        }

        return [
            'batch'           => $this->format_batch_row($batch),
            'items'           => $rows_out,
            'total_items'     => count($rows_out),
            'changed_count'   => $changed_count,
            'has_payload'     => $has_payload,
            'overlap_skus'    => $overlap_skus,
            'superseded_by'   => $superseded_by > 0 ? $superseded_by : null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_previous_applied_payloads_for_batch($batch_id) {
        global $wpdb;
        $batch_id = absint($batch_id);
        $batch_table = $this->table('facto_export_batches');
        $items_table = $this->table('facto_export_items');

        $prev_batches = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$batch_table}
             WHERE estado = 'aplicado' AND id < %d
             ORDER BY applied_at DESC, id DESC",
            $batch_id
        ));
        if (!$prev_batches) {
            return [];
        }

        $out = [];
        foreach ($prev_batches as $prev_id) {
            $prev_id = (int) $prev_id;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT sku, payload_json FROM {$items_table} WHERE batch_id = %d",
                $prev_id
            ), ARRAY_A);
            foreach ($rows as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '' || isset($out[$sku])) {
                    continue;
                }
                $payload = $this->decode_payload_json($row['payload_json'] ?? null);
                if (!empty($payload)) {
                    $out[$sku] = $payload;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function decode_payload_json($raw) {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param string[] $fields
     * @return array<int, array{field: string, before: mixed, after: mixed}>
     */
    private function diff_payloads(array $before, array $after, array $fields) {
        $diffs = [];
        foreach ($fields as $field) {
            $b = $this->normalize_diff_value($before[$field] ?? '');
            $a = $this->normalize_diff_value($after[$field] ?? '');
            if ($b !== $a) {
                $diffs[] = [
                    'field'  => $field,
                    'before' => $before[$field] ?? '',
                    'after'  => $after[$field] ?? '',
                ];
            }
        }
        return $diffs;
    }

    /**
     * @param mixed $value
     */
    private function normalize_diff_value($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) round((float) $value, 6);
        }
        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function build_row_snapshot(array $row) {
        $snapshot = [];
        foreach ($this->base_headers() as $header) {
            $snapshot[$header] = $row[$header] ?? '';
        }
        $stock = $row['_stock_by_location'] ?? [];
        if (is_array($stock)) {
            foreach ($stock as $loc_name => $qty) {
                $snapshot['Disponibilidad en: ' . $loc_name] = $qty;
            }
        }
        return $snapshot;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function format_batch_row(array $batch) {
        return [
            'id'                     => (int) ($batch['id'] ?? 0),
            'modo'                   => (string) ($batch['modo'] ?? ''),
            'total_filas'            => (int) ($batch['total_filas'] ?? 0),
            'tanda'                  => (int) ($batch['tanda'] ?? 1),
            'tandas_total'           => (int) ($batch['tandas_total'] ?? 1),
            'estado'                 => (string) ($batch['estado'] ?? ''),
            'created_at'             => (string) ($batch['created_at'] ?? ''),
            'applied_at'             => (string) ($batch['applied_at'] ?? ''),
            'notas'                  => (string) ($batch['notas'] ?? ''),
            'superseded_by_batch_id' => (int) ($batch['superseded_by_batch_id'] ?? 0) ?: null,
            'can_mark_applied'       => ($batch['estado'] ?? '') === 'generado',
            'can_unmark_applied'     => ($batch['estado'] ?? '') === 'aplicado',
        ];
    }

    /**
     * @return array<int, array{name: string, id: string}>
     */
    public function resolve_warehouse_locations() {
        $cached = get_transient('riverso_facto_export_locations_v2');
        if (is_array($cached)) {
            return $cached;
        }

        $locations = [];
        if ($this->client === null && class_exists('Riverso_Facto_Client')) {
            $this->client = new Riverso_Facto_Client();
        }
        if ($this->client) {
            $resp = $this->client->list_product_locations(['page' => 1]);
            if (!is_wp_error($resp)) {
                $items = Riverso_Facto_Client::embed_collection($resp, 'product_locations');
                foreach ($items as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    $id = (string) ($item['product_location_id'] ?? $item['id'] ?? '');
                    if ($name !== '') {
                        $locations[] = ['name' => $name, 'id' => $id];
                    }
                }
            }
        }

        $locations = $this->normalize_facto_export_locations($locations);

        set_transient('riverso_facto_export_locations_v2', $locations, HOUR_IN_SECONDS);
        return $locations;
    }

    /**
     * Alinea bodegas al encabezado Excel FACTO: Cajas → general → Otros (no orden API).
     *
     * @param array<int, array{name: string, id: string}> $locations
     * @return array<int, array{name: string, id: string}>
     */
    private function normalize_facto_export_locations(array $locations) {
        $by_norm = [];
        foreach ($locations as $loc) {
            $name = trim((string) ($loc['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $by_norm[$this->normalize_location_name($name)] = $loc;
        }

        $out = [];
        foreach (self::FACTO_EXPORT_WAREHOUSE_CANONICAL as $canonical) {
            $norm = $this->normalize_location_name($canonical);
            if (isset($by_norm[$norm])) {
                $matched = $by_norm[$norm];
                $out[] = [
                    'name' => $canonical,
                    'id' => (string) ($matched['id'] ?? ''),
                ];
            } else {
                $out[] = ['name' => $canonical, 'id' => ''];
            }
        }

        return $out;
    }

    public function list_recent_batches($limit = 20) {
        $this->recompute_superseded_batches();

        global $wpdb;
        $table = $this->table('facto_export_batches');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            max(1, min(100, absint($limit)))
        ), ARRAY_A) ?: [];

        return array_map(function ($batch) {
            return $this->format_batch_row($batch);
        }, $rows);
    }

    private function base_headers() {
        return [
            'Categoria',
            'Nombre',
            'SKU',
            'Marca',
            'Modelo',
            'Unidad',
            'Código de barras',
            'Producto / Servicio',
            'Costo neto',
            'Venta: Precio neto',
            'Venta: afecto/exento de IVA',
            'Venta: Monto IVA',
            'Venta: Código Impuesto específico',
            'Venta: Monto impuesto específico',
            'Venta: Precio total',
            'Stock mínimo',
            'Descripción',
            'Descripción ecommerce',
            'Seriales',
            'Información Adicional 1',
            'Información Adicional 2',
            'Información Adicional 3',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{headers: string[], locations: array} $schema
     * @return array<int, mixed>
     */
    private function row_to_sheet_values(array $row, array $schema) {
        $values = [];
        foreach ($this->base_headers() as $h) {
            $values[] = $row[$h] ?? '';
        }
        foreach ($schema['locations'] as $loc) {
            if ($this->should_emit_stock_values($row, $schema)) {
                $values[] = $this->stock_qty_for_location($row['_stock_by_location'] ?? [], $loc['name']);
            } else {
                $values[] = '';
            }
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{include_stock_values?: bool} $schema
     */
    private function should_emit_stock_values(array $row, array $schema) {
        if (!empty($schema['include_stock_values'])) {
            return true;
        }
        return !empty($row['_local_stock_bodega']) || (!empty($row['_hydrated_stock']) && !empty($row['_stock_by_location']));
    }

    /**
     * Stock total Riverso → columna «Bodega general» cuando el export incluye stock.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function finalize_row_stock(array $row, array $filters, array $item = []) {
        if (empty($filters['include_stock']) && empty($filters['hydrate_from_facto'])) {
            return $row;
        }

        $general_name = $this->resolve_general_bodega_location_name();
        if ($general_name === '') {
            return $row;
        }

        $total = $row['_stock_local_total'] ?? $this->resolve_local_stock_total_from_item($item);
        if (!is_array($row['_stock_by_location'] ?? null)) {
            $row['_stock_by_location'] = [];
        }
        $row['_stock_by_location'][$general_name] = round((float) $total, 3);
        $row['_local_stock_bodega'] = true;
        return $row;
    }

    /**
     * Nombre exacto de la bodega FACTO equivalente a «Bodega general».
     */
    private function resolve_general_bodega_location_name() {
        foreach ($this->resolve_warehouse_locations() as $loc) {
            $name = trim((string) ($loc['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($this->normalize_location_name($name) === $this->normalize_location_name('Bodega general')) {
                return $name;
            }
        }
        return 'Bodega general';
    }

    /**
     * Stock físico total del SKU en Riverso (suma producto_ubicacion).
     *
     * @param array<string, mixed> $item
     */
    private function resolve_local_stock_total_from_item(array $item) {
        if (array_key_exists('stock_total_local', $item) && $item['stock_total_local'] !== null && $item['stock_total_local'] !== '') {
            return (float) $item['stock_total_local'];
        }

        $producto_id = (int) ($item['id'] ?? 0);
        if ($producto_id <= 0) {
            return 0.0;
        }

        if (class_exists('Riverso_Stock_Service')) {
            return Riverso_Stock_Service::get_instance()->get_balance($producto_id);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'riverso_producto_ubicacion';
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cantidad), 0) FROM {$table} WHERE product_id = %d",
            $producto_id
        ));

        return (float) ($balance ?? 0);
    }

    /**
     * @param array<string, float|string> $stock_map
     */
    private function stock_qty_for_location(array $stock_map, $location_name) {
        if (isset($stock_map[$location_name])) {
            return $stock_map[$location_name];
        }
        $target = $this->normalize_location_name($location_name);
        foreach ($stock_map as $name => $qty) {
            if ($this->normalize_location_name($name) === $target) {
                return $qty;
            }
        }
        return 0;
    }

    /**
     * Campos numéricos FACTO: vacío → 0 (import rechaza celdas sin número).
     *
     * @return string[]
     */
    private function facto_numeric_zero_fields() {
        return [
            'Costo neto',
            'Venta: Precio neto',
            'Venta: Monto IVA',
            'Venta: Monto impuesto específico',
            'Venta: Precio total',
            'Stock mínimo',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function apply_facto_numeric_defaults(array $row) {
        foreach ($this->facto_numeric_zero_fields() as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) {
                $row[$field] = 0;
            } else {
                $row[$field] = round((float) $row[$field], 6);
            }
        }
        return $row;
    }

    private function normalize_location_name($name) {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');
        return str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $name);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array<string, mixed>> $rows
     */
    private function resolve_include_stock_flag(array $filters, array $rows) {
        if (!empty($filters['include_stock']) || !empty($filters['hydrate_from_facto'])) {
            return true;
        }
        foreach ($rows as $row) {
            if (!empty($row['_local_stock_bodega']) || !empty($row['_hydrated_stock'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return float|string
     */
    private function resolve_stock_minimo_value(array $item) {
        foreach (['stock_minimo', 'stock_minimo_config', 'legacy_stock_minimo'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            $value = $item[$key];
            if ($value !== null && $value !== '') {
                return round((float) $value, 3);
            }
        }
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function resolve_facto_location_id_map() {
        $map = [];
        foreach ($this->resolve_warehouse_locations() as $loc) {
            $id = trim((string) ($loc['id'] ?? ''));
            $name = trim((string) ($loc['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $map[$id] = $name;
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string, string> $location_id_map
     * @return array<string, float>
     */
    private function map_facto_inventories_to_stock(array $remote, array $location_id_map) {
        $out = [];
        $details = $remote['inventories']['details'] ?? [];
        if (!is_array($details)) {
            return $out;
        }
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $loc_id = trim((string) ($detail['product_location_id'] ?? ''));
            $name = $location_id_map[$loc_id] ?? '';
            if ($name === '' || !isset($detail['available_quantity'])) {
                continue;
            }
            $out[$name] = round((float) $detail['available_quantity'], 3);
        }
        return $out;
    }

    private function normalize_mode($modo) {
        $modo = sanitize_key((string) $modo);
        $map = [
            'update'        => self::MODE_UPDATE_ONLY,
            'update_only'   => self::MODE_UPDATE_ONLY,
            'upsert'        => self::MODE_UPSERT,
            'add_update'    => self::MODE_UPSERT,
            'replace'       => self::MODE_REPLACE,
        ];
        return $map[$modo] ?? self::MODE_UPSERT;
    }

    /**
     * @return array<int, string>
     */
    private function parse_sku_filter($raw) {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return [];
            }
            $parts = preg_split('/[\s,;]+/', $raw);
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    private function resolve_precio_total(array $item) {
        $p = $item['p_asignado'] ?? null;
        if ($p !== null && $p !== '' && (float) $p > 0) {
            return round((float) $p, 6);
        }
        $sku = trim((string) ($item['canonical_sku'] ?? ''));
        if ($sku !== '') {
            global $wpdb;
            $legacy = $wpdb->get_row($wpdb->prepare(
                "SELECT precio_total FROM {$this->table('legacy_precio_ref')} WHERE sku = %s LIMIT 1",
                $sku
            ), ARRAY_A);
            if ($legacy && $legacy['precio_total'] !== null && (float) $legacy['precio_total'] > 0) {
                return round((float) $legacy['precio_total'], 6);
            }
        }
        return '';
    }

    /**
     * @return array{neto: float|string, iva: float|string, total: float|string}
     */
    private function split_price_iva($total, $iva_tipo) {
        if ($total === '' || $total === null) {
            return ['neto' => 0, 'iva' => 0, 'total' => 0];
        }
        $total = round((float) $total, 6);
        if ($iva_tipo === 'exento') {
            return ['neto' => $total, 'iva' => 0, 'total' => $total];
        }
        $neto = round($total / 1.19, 6);
        $iva = round($total - $neto, 6);
        return ['neto' => $neto, 'iva' => $iva, 'total' => $total];
    }

    public function map_unit($unit) {
        $unit = strtolower(trim((string) $unit));
        $map = [
            'unidad'    => 'UN',
            'un'        => 'UN',
            'u'         => 'UN',
            'caja'      => 'CAJA',
            'docena'    => 'PAR',
            'kilogramo' => 'KG',
            'kilo'      => 'KG',
            'kg'        => 'KG',
            'litro'     => 'LT',
            'lts'       => 'LTS',
            'metro'     => 'MTS',
            'm'         => 'MTS',
            'mt'        => 'MTS',
            'mts'       => 'MTS',
        ];
        $key = $map[$unit] ?? strtoupper($unit);
        if (!in_array($key, self::FACTO_UNITS, true)) {
            return 'UN';
        }
        return $key;
    }

    private function sanitize_facto_name($name) {
        $name = wp_strip_all_tags((string) $name);
        return trim(preg_replace('/[^\p{L}\p{N}\s\.\,\-\_\/\(\)\+\'\"&]/u', '', $name));
    }

    private function derive_categoria_from_woo($woo_id) {
        if ($woo_id <= 0 || !function_exists('wp_get_post_terms')) {
            return '';
        }
        $terms = wp_get_post_terms($woo_id, 'product_cat', ['orderby' => 'parent', 'order' => 'ASC']);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }
        $names = [];
        foreach ($terms as $term) {
            if (!empty($term->name)) {
                $names[] = $term->name;
            }
        }
        return implode(' > ', array_slice($names, 0, 2));
    }

    private function derive_ecommerce_description(array $item) {
        $woo_id = (int) ($item['woocommerce_product_id'] ?? 0);
        if ($woo_id <= 0) {
            return '';
        }
        $post = get_post($woo_id);
        if (!$post) {
            return '';
        }
        $desc = $post->post_excerpt ?: $post->post_content;
        return trim(wp_strip_all_tags((string) $desc));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function count_mapped_skus(array $rows) {
        $n = 0;
        foreach ($rows as $row) {
            if (!empty($row['_facto_product_id'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function compute_row_hash(array $row) {
        $payload = $row;
        unset($payload['_producto_base_id'], $payload['_facto_product_id'], $payload['_stock_by_location'], $payload['_stock_local_total'], $payload['_local_stock_bodega'], $payload['_row_hash']);
        return hash('sha256', wp_json_encode($payload));
    }

    /**
     * @return array<string, string> sku => row_hash
     */
    private function get_last_applied_row_hashes() {
        global $wpdb;
        $batch_table = $this->table('facto_export_batches');
        $items_table = $this->table('facto_export_items');
        $last_id = (int) $wpdb->get_var(
            "SELECT id FROM {$batch_table} WHERE estado = 'aplicado' ORDER BY applied_at DESC, id DESC LIMIT 1"
        );
        if ($last_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sku, row_hash FROM {$items_table} WHERE batch_id = %d",
            $last_id
        ), ARRAY_A);
        $map = [];
        foreach ($rows as $r) {
            $sku = trim((string) ($r['sku'] ?? ''));
            if ($sku !== '') {
                $map[$sku] = (string) ($r['row_hash'] ?? '');
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function record_batch($modo, array $filters, $row_count, $tanda, $tandas_total, $file_hash, array $chunk) {
        global $wpdb;
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $wpdb->insert($this->table('facto_export_batches'), [
            'modo'         => $modo,
            'alcance'      => wp_json_encode($filters),
            'total_filas'  => $row_count,
            'tanda'        => $tanda,
            'tandas_total' => $tandas_total,
            'file_hash'    => $file_hash,
            'estado'       => 'generado',
            'created_by'   => $user_id ?: null,
            'created_at'   => $now,
        ]);
        $batch_id = (int) $wpdb->insert_id;

        foreach ($chunk as $row) {
            $snapshot = $this->build_row_snapshot($row);
            $wpdb->insert($this->table('facto_export_items'), [
                'batch_id'         => $batch_id,
                'producto_base_id' => (int) ($row['_producto_base_id'] ?? 0),
                'sku'              => $row['SKU'] ?? '',
                'row_hash'         => $row['_row_hash'] ?? $this->compute_row_hash($row),
                'payload_json'     => wp_json_encode($snapshot),
            ]);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('facto_export.generated', 'facto_export_batch', $batch_id, [
                'actor_type' => 'human',
                'details'    => [
                    'modo'   => $modo,
                    'tanda'  => $tanda,
                    'filas'  => $row_count,
                    'hash'   => $file_hash,
                ],
            ]);
        }

        return $batch_id;
    }

    private function build_filename($modo, $tanda, $tandas_total) {
        $date = gmdate('Y-m-d');
        $suffix = $tandas_total > 1 ? "_t{$tanda}-de-{$tandas_total}" : '';
        return "facto-export_{$modo}_{$date}{$suffix}.xlsx";
    }
}
