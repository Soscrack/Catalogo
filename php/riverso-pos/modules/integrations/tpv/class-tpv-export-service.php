<?php
/**
 * Export Excel de catálogo para TPV local (CRUD por planilla).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-xlsx-writer.php';

class Riverso_Tpv_Export_Service {

    const ACTION_CREATE = 'CREAR';
    const ACTION_UPDATE = 'EDITAR';
    const ACTION_DELETE = 'ELIMINAR';
    const ACTION_CHANGE_SKU = 'CAMBIAR_SKU';

    const ENTITY_PRODUCT = 'producto';
    const ENTITY_BARCODE = 'barcode';

    const PRODUCT_HEADERS = [
        'Accion',
        'SKU',
        'SKU_Anterior',
        'Nombre',
        'Precio',
    ];

    const BARCODE_HEADERS = [
        'Accion',
        'SKU',
        'CodigoBarras',
    ];

    const PREVIEW_ROW_LIMIT = 250;

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function preview(array $filters) {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $built = $this->build_export_rows($filters);
        $products = $built['products'];
        $barcodes = $built['barcodes'];

        $create_p = 0;
        $update_p = 0;
        $delete_p = 0;
        $change_sku_p = 0;
        foreach ($products as $row) {
            if ($row['_accion'] === self::ACTION_CREATE) {
                $create_p++;
            } elseif ($row['_accion'] === self::ACTION_UPDATE) {
                $update_p++;
            } elseif ($row['_accion'] === self::ACTION_DELETE) {
                $delete_p++;
            } elseif ($row['_accion'] === self::ACTION_CHANGE_SKU) {
                $change_sku_p++;
            }
        }

        $create_b = 0;
        $update_b = 0;
        $delete_b = 0;
        foreach ($barcodes as $row) {
            if ($row['_accion'] === self::ACTION_CREATE) {
                $create_b++;
            } elseif ($row['_accion'] === self::ACTION_UPDATE) {
                $update_b++;
            } elseif ($row['_accion'] === self::ACTION_DELETE) {
                $delete_b++;
            }
        }

        $total = count($products) + count($barcodes);
        $has_last_batch = !empty($built['has_last_applied_batch']);
        $baseline = (string) ($built['baseline'] ?? 'none');

        $changed_products = array_values(array_filter($products, static function ($row) {
            return !empty($row['_changed']);
        }));
        $changed_barcodes = array_values(array_filter($barcodes, static function ($row) {
            return !empty($row['_changed']);
        }));

        $preview_products = array_slice($changed_products, 0, self::PREVIEW_ROW_LIMIT);
        $preview_barcodes = array_slice($changed_barcodes, 0, self::PREVIEW_ROW_LIMIT);

        return [
            'total_productos'              => count($products),
            'total_barcodes'               => count($barcodes),
            'total'                        => $total,
            'create_productos'             => $create_p,
            'update_productos'             => $update_p,
            'delete_productos'             => $delete_p,
            'change_sku_productos'         => $change_sku_p,
            'create_barcodes'              => $create_b,
            'update_barcodes'              => $update_b,
            'delete_barcodes'              => $delete_b,
            'changed_productos'            => count($changed_products),
            'changed_barcodes'             => count($changed_barcodes),
            'unchanged_productos'          => count($products) - count($changed_products),
            'unchanged_barcodes'           => count($barcodes) - count($changed_barcodes),
            'has_last_applied_batch'       => $has_last_batch,
            'baseline'                     => $baseline,
            'legacy_product_count'         => (int) ($built['legacy_product_count'] ?? 0),
            'can_download'                 => $total > 0,
            'empty_hint'                   => $this->build_empty_hint(
                $filters,
                $has_last_batch,
                $baseline,
                (int) ($built['skipped_family_pending'] ?? 0),
                (int) ($built['skipped_family_children'] ?? 0),
                is_array($built['withheld_family_pending'] ?? null) ? $built['withheld_family_pending'] : [],
                $total
            ),
            'preview_limit'                => self::PREVIEW_ROW_LIMIT,
            'preview_productos'            => array_map([$this, 'format_preview_product'], $preview_products),
            'preview_barcodes'             => array_map([$this, 'format_preview_barcode'], $preview_barcodes),
            'preview_productos_truncated'  => count($changed_products) > self::PREVIEW_ROW_LIMIT,
            'preview_barcodes_truncated'   => count($changed_barcodes) > self::PREVIEW_ROW_LIMIT,
            'skipped_family_pending'       => (int) ($built['skipped_family_pending'] ?? 0),
            'skipped_family_children'      => (int) ($built['skipped_family_children'] ?? 0),
            'withheld_family_pending'      => is_array($built['withheld_family_pending'] ?? null)
                ? $built['withheld_family_pending']
                : [],
            'barcode_create_only'          => true,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|WP_Error
     */
    public function generate_batch(array $filters) {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $built = $this->build_export_rows($filters);
        $products = $built['products'];
        $barcodes = $built['barcodes'];

        if (empty($products) && empty($barcodes)) {
            return new WP_Error(
                'no_rows',
                'No hay filas para exportar. ' . $this->build_empty_hint(
                    $filters,
                    !empty($built['has_last_applied_batch']),
                    (string) ($built['baseline'] ?? 'none'),
                    (int) ($built['skipped_family_pending'] ?? 0),
                    (int) ($built['skipped_family_children'] ?? 0),
                    is_array($built['withheld_family_pending'] ?? null) ? $built['withheld_family_pending'] : [],
                    0
                )
            );
        }

        $product_sheet = [self::PRODUCT_HEADERS];
        foreach ($products as $row) {
            $product_sheet[] = [
                $row['_accion'],
                $row['SKU'],
                $row['SKU_Anterior'] ?? '',
                $row['Nombre'],
                $this->format_price_cell($row['Precio'] ?? ''),
            ];
        }

        $barcode_sheet = [self::BARCODE_HEADERS];
        foreach ($barcodes as $row) {
            $barcode_sheet[] = [
                $row['_accion'],
                $row['SKU'],
                $row['CodigoBarras'],
            ];
        }

        $writer = new Riverso_Xlsx_Writer();
        $writer->set_sheets([
            ['name' => 'Productos', 'rows' => $product_sheet],
            ['name' => 'CodigosBarra', 'rows' => $barcode_sheet],
        ]);

        $binary = $writer->to_string();
        if ($binary === false) {
            return new WP_Error('xlsx_failed', 'No se pudo generar el archivo Excel (ZipArchive).');
        }

        $file_hash = hash('sha256', $binary);
        $batch_id = $this->record_batch($filters, count($products), count($barcodes), $file_hash, $products, $barcodes);

        return [
            'batch_id'     => $batch_id,
            'filename'     => $this->build_filename(),
            'binary'       => $binary,
            'file_hash'    => $file_hash,
            'product_rows' => count($products),
            'barcode_rows' => count($barcodes),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_recent_batches($limit = 30) {
        global $wpdb;
        $table = $this->table('tpv_export_batches');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            max(1, min(100, absint($limit)))
        ), ARRAY_A) ?: [];

        $max_applied_id = (int) $wpdb->get_var(
            "SELECT MAX(id) FROM {$table} WHERE estado = 'aplicado'"
        );

        return array_map(function ($batch) use ($max_applied_id) {
            return $this->format_batch_row($batch, $max_applied_id);
        }, $rows);
    }

    /**
     * @return true|WP_Error
     */
    public function mark_batch_applied($batch_id, $notas = '') {
        global $wpdb;
        $batch_id = absint($batch_id);
        $table = $this->table('tpv_export_batches');
        $now = current_time('mysql');

        $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $batch_id), ARRAY_A);
        if (!$batch) {
            return new WP_Error('batch_not_found', 'Lote no encontrado');
        }
        if (($batch['estado'] ?? '') !== 'generado') {
            return new WP_Error('batch_not_markable', 'Solo se pueden marcar lotes en estado generado');
        }

        $newer_applied = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE estado = 'aplicado' AND id > %d
             ORDER BY id DESC LIMIT 1",
            $batch_id
        ));
        if ($newer_applied > 0) {
            return new WP_Error(
                'batch_superseded',
                'No se puede marcar el lote #' . $batch_id . ': ya hay un lote posterior aplicado (#' . $newer_applied . ').'
            );
        }

        $wpdb->update($table, [
            'estado'     => 'aplicado',
            'applied_at' => $now,
            'notas'      => sanitize_textarea_field($notas),
        ], ['id' => $batch_id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('tpv_export.applied', 'tpv_export_batch', $batch_id, [
                'actor_type' => 'human',
                'details'    => ['notas' => $notas],
            ]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function build_export_rows(array $filters) {
        $only_changed = !empty($filters['only_changed']);
        $sku_filter = $this->parse_sku_filter($filters['sku'] ?? '');

        $catalog = $this->load_tpv_catalog($sku_filter);
        $current_products = $catalog['products'];
        $withheld_products = $catalog['withheld_products'] ?? [];
        $current_barcodes = $catalog['barcodes'];
        $withheld_skus = $catalog['withheld_skus'];
        $previous = $this->get_last_applied_snapshot();

        $has_last = !empty($previous['batch_id']);
        $baseline = 'none';
        $legacy_count = 0;

        if ($has_last) {
            $prev_products = $previous['products'];
            $prev_barcodes = $previous['barcodes'];
            $baseline = 'batch';
        } else {
            $legacy = $this->load_legacy_product_baseline($sku_filter);
            $prev_products = $legacy['products'];
            $prev_barcodes = [];
            $legacy_count = count($prev_products);
            $baseline = $legacy_count > 0 ? 'legacy' : 'none';
        }

        $sku_allow = [];
        foreach ($sku_filter as $sku) {
            $sku_allow[$sku] = true;
        }

        $product_rows = [];
        $barcode_rows = [];
        $remap_handled_old = []; // sku_anterior ya convertido a CAMBIAR_SKU
        $remap_by_old = $this->load_sku_remap_by_old();
        $prev_sku_by_pid = [];
        foreach ($prev_products as $prev_sku => $prev) {
            $pid = (int) ($prev['producto_base_id'] ?? 0);
            if ($pid > 0) {
                $prev_sku_by_pid[$pid] = $prev_sku;
            }
        }

        if ($baseline === 'none') {
            foreach ($current_products as $sku => $row) {
                $row['SKU_Anterior'] = '';
                $row['_accion'] = self::ACTION_CREATE;
                $row['_changed'] = true;
                $row['_diffs'] = [];
                foreach (['Nombre', 'Precio'] as $field) {
                    $to = $this->field_display_value($field, $row[$field] ?? '');
                    if ($to !== '') {
                        $row['_diffs'][] = [
                            'campo'   => $field,
                            'antes'   => '',
                            'despues' => $to,
                        ];
                    }
                }
                $row['_row_hash'] = $this->compute_product_hash($row);
                $product_rows[] = $row;
            }
        } else {
            foreach ($current_products as $sku => $row) {
                $pid = (int) ($row['_producto_base_id'] ?? 0);
                $old_sku = '';
                if ($pid > 0 && isset($prev_sku_by_pid[$pid]) && $prev_sku_by_pid[$pid] !== $sku) {
                    $old_sku = $prev_sku_by_pid[$pid];
                } elseif (!isset($prev_products[$sku])) {
                    // Remap vía mapa FACTO (sku_anterior → sku_nuevo) si el viejo está en baseline.
                    foreach ($remap_by_old as $map_old => $map_info) {
                        if (($map_info['sku_nuevo'] ?? '') === $sku && isset($prev_products[$map_old])) {
                            $old_sku = $map_old;
                            break;
                        }
                    }
                }

                $hash = $this->compute_product_hash($row);
                $row['_row_hash'] = $hash;
                $row['SKU_Anterior'] = '';

                if ($old_sku !== '') {
                    $row['_accion'] = self::ACTION_CHANGE_SKU;
                    $row['SKU_Anterior'] = $old_sku;
                    $row['_changed'] = true;
                    $row['_diffs'] = [[
                        'campo'   => 'SKU',
                        'antes'   => $old_sku,
                        'despues' => $sku,
                    ]];
                    $remap_handled_old[$old_sku] = true;
                    $product_rows[] = $row;
                    continue;
                }

                if (!isset($prev_products[$sku])) {
                    $row['_accion'] = self::ACTION_CREATE;
                    $row['_changed'] = true;
                    $row['_diffs'] = [];
                    foreach (['Nombre', 'Precio'] as $field) {
                        $to = $this->field_display_value($field, $row[$field] ?? '');
                        if ($to !== '') {
                            $row['_diffs'][] = [
                                'campo'   => $field,
                                'antes'   => '',
                                'despues' => $to,
                            ];
                        }
                    }
                } elseif (($prev_products[$sku]['hash'] ?? '') !== $hash) {
                    $row['_accion'] = self::ACTION_UPDATE;
                    $row['_changed'] = true;
                    $row['_diffs'] = $this->diff_product_fields($prev_products[$sku]['payload'] ?? [], $row);
                } else {
                    $row['_accion'] = $only_changed ? null : self::ACTION_UPDATE;
                    $row['_changed'] = false;
                    $row['_diffs'] = [];
                }
                if ($row['_accion'] !== null) {
                    $product_rows[] = $row;
                }
            }

            // Ya están en el lote TPV aplicado pero hoy se omiten (familia pendiente/hijo):
            // igual permitir EDITAR (p. ej. rename de espacios dobles) para no dejar el TPV desfasado.
            foreach ($withheld_products as $sku => $row) {
                if (!isset($prev_products[$sku]) || isset($remap_handled_old[$sku])) {
                    continue;
                }
                if ($sku_allow && !isset($sku_allow[$sku])) {
                    continue;
                }
                $hash = $this->compute_product_hash($row);
                $row['_row_hash'] = $hash;
                $row['SKU_Anterior'] = '';
                if (($prev_products[$sku]['hash'] ?? '') === $hash) {
                    if (!$only_changed) {
                        $row['_accion'] = self::ACTION_UPDATE;
                        $row['_changed'] = false;
                        $row['_diffs'] = [];
                        $product_rows[] = $row;
                    }
                    continue;
                }
                $row['_accion'] = self::ACTION_UPDATE;
                $row['_changed'] = true;
                $row['_diffs'] = $this->diff_product_fields($prev_products[$sku]['payload'] ?? [], $row);
                $product_rows[] = $row;
            }

            foreach ($prev_products as $sku => $prev) {
                if (isset($current_products[$sku]) || isset($withheld_skus[$sku]) || isset($remap_handled_old[$sku])) {
                    continue;
                }
                if ($sku_allow && !isset($sku_allow[$sku])) {
                    continue;
                }
                // Si el SKU viejo tiene remap a un SKU nuevo ya presente, no ELIMINAR (ya salió CAMBIAR_SKU).
                if (isset($remap_by_old[$sku])) {
                    $new_sku = $remap_by_old[$sku]['sku_nuevo'] ?? '';
                    if ($new_sku !== '' && isset($current_products[$new_sku])) {
                        continue;
                    }
                }
                $product_rows[] = [
                    '_accion'           => self::ACTION_DELETE,
                    '_changed'          => true,
                    '_diffs'            => [],
                    '_row_hash'         => (string) ($prev['hash'] ?? ''),
                    '_producto_base_id' => (int) ($prev['producto_base_id'] ?? 0),
                    '_entity_key'       => $sku,
                    'SKU'               => $sku,
                    'SKU_Anterior'      => '',
                    'Nombre'            => (string) ($prev['payload']['Nombre'] ?? ''),
                    'Precio'            => $prev['payload']['Precio'] ?? '',
                ];
            }
        }

        // Códigos de barra: solo CREAR. Sin EDITAR ni ELIMINAR.
        foreach ($current_barcodes as $key => $row) {
            if ($baseline === 'batch' && isset($prev_barcodes[$key])) {
                continue;
            }
            $codigo = trim((string) ($row['CodigoBarras'] ?? ''));
            $row['_accion'] = self::ACTION_CREATE;
            $row['_changed'] = true;
            $row['_diffs'] = [[
                'campo'   => 'CodigoBarras',
                'antes'   => '',
                'despues' => $codigo,
            ]];
            $row['_row_hash'] = $this->compute_barcode_hash($row);
            $barcode_rows[] = $row;
        }

        return [
            'products'               => $product_rows,
            'barcodes'               => $barcode_rows,
            'has_last_applied_batch' => $has_last,
            'baseline'               => $baseline,
            'legacy_product_count'   => $legacy_count,
            'skipped_family_pending' => (int) $catalog['skipped_family_pending'],
            'skipped_family_children'=> (int) $catalog['skipped_family_children'],
            'withheld_family_pending'=> is_array($catalog['withheld_family_pending'] ?? null)
                ? $catalog['withheld_family_pending']
                : [],
        ];
    }

    /**
     * Baseline del TPV legacy (productos_tpv.xlsx → productos_legacy.csv).
     *
     * @param array<int, string> $sku_filter
     * @return array{products: array<string, array<string, mixed>>}
     */
    private function load_legacy_product_baseline(array $sku_filter) {
        $path = RIVERSO_POS_PLUGIN_DIR . 'data/tpv/productos_legacy.csv';
        if (!is_readable($path)) {
            return ['products' => []];
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return ['products' => []];
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return ['products' => []];
        }
        $header = array_map(static function ($h) {
            return strtolower(trim((string) $h));
        }, $header);
        $idx_sku = array_search('sku', $header, true);
        $idx_nombre = array_search('nombre', $header, true);
        $idx_precio = array_search('precio', $header, true);
        if ($idx_sku === false) {
            fclose($handle);
            return ['products' => []];
        }

        $sku_allow = [];
        foreach ($sku_filter as $sku) {
            $sku_allow[$sku] = true;
        }

        $products = [];
        while (($cols = fgetcsv($handle)) !== false) {
            $sku = trim((string) ($cols[$idx_sku] ?? ''));
            if ($sku === '') {
                continue;
            }
            if ($sku_allow && !isset($sku_allow[$sku])) {
                continue;
            }
            $nombre = $idx_nombre !== false ? trim((string) ($cols[$idx_nombre] ?? '')) : '';
            $precio_raw = $idx_precio !== false ? ($cols[$idx_precio] ?? '') : '';
            $precio = $this->normalize_legacy_price($precio_raw);
            $payload = [
                'SKU'    => $sku,
                'Nombre' => $nombre,
                'Precio' => $precio,
            ];
            $products[$sku] = [
                'hash'             => $this->compute_product_hash($payload),
                'producto_base_id' => 0,
                'payload'          => $payload,
            ];
        }
        fclose($handle);

        return ['products' => $products];
    }

    /**
     * @param mixed $value
     * @return float|string
     */
    private function normalize_legacy_price($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 3);
        }
        $s = trim((string) $value);
        $s = str_replace([' ', "\xc2\xa0"], '', $s);
        if ($s === '') {
            return '';
        }
        // 1.450,00 → 1450.000 ; 21,50 → 21.500 ; 21.5 → 21.500
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }
        if (!is_numeric($s)) {
            return '';
        }
        return round((float) $s, 3);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function format_price_cell($value) {
        if ($value === null || $value === '') {
            return '';
        }
        return number_format((float) $value, 3, '.', '');
    }

    /**
     * FACTO no admite dos espacios seguidos; TPV legacy a menudo los tiene.
     * Exportamos el nombre colapsado para forzar EDITAR/rename en el programa TPV.
     */
    private function normalize_product_name($name) {
        $name = preg_replace('/\s+/u', ' ', (string) $name);
        return trim((string) $name);
    }

    /**
     * Catálogo TPV: unitarios de familia (no hijos), sin tareas de familia pendientes.
     *
     * @param array<int, string> $sku_filter
     * @return array<string, mixed>
     */
    private function load_tpv_catalog(array $sku_filter) {
        $family_index = $this->load_family_index();
        $pending_tasks = $this->load_pending_family_tasks();
        $pending_ids = [];
        foreach ($pending_tasks as $pid => $_task) {
            $pending_ids[(int) $pid] = true;
        }
        $sku_filter = $this->expand_sku_filter($sku_filter, $family_index);
        $raw_products = $this->load_current_products($sku_filter);

        $products = [];
        $withheld_products = [];
        $eligible_ids = [];
        $withheld_skus = [];
        $skipped_pending = 0;
        $skipped_children = 0;
        $withheld_pending_samples = [];

        foreach ($raw_products as $sku => $row) {
            $id = (int) ($row['_producto_base_id'] ?? 0);
            $fam = $id > 0 ? ($family_index[$id] ?? null) : null;
            $in_family = is_array($fam);
            $is_child = $in_family && !empty($fam['is_child']);
            $decision = (string) ($row['_familia_decision'] ?? '');
            $unresolved = $this->is_family_unresolved($id, $decision, $in_family, $pending_ids);

            if ($unresolved && !$is_child) {
                $withheld_skus[$sku] = true;
                unset($row['_familia_decision']);
                $task = $pending_tasks[$id] ?? null;
                $task_tipo = is_array($task) ? (string) ($task['tipo'] ?? 'preguntar_familia') : 'preguntar_familia';
                $task_label = $this->family_task_label($task_tipo, $decision);
                $task_url = '';
                if ($id > 0 && function_exists('riverso_build_task_product_hub_url')) {
                    $task_url = (string) riverso_build_task_product_hub_url($id, $task_tipo, 'admin');
                }
                if ($task_url === '' && $id > 0) {
                    $task_url = admin_url('admin.php?page=riverso-pos-products&action=detail&id=' . $id . '&tab=local');
                }
                $row['_withhold_reason'] = 'family_pending';
                $row['_task_tipo'] = $task_tipo;
                $row['_task_id'] = is_array($task) ? (int) ($task['id'] ?? 0) : 0;
                $row['_task_label'] = $task_label;
                $row['_task_url'] = $task_url;
                $withheld_products[$sku] = $row;
                $skipped_pending++;
                if (count($withheld_pending_samples) < 25) {
                    $withheld_pending_samples[] = [
                        'sku'      => $sku,
                        'nombre'   => (string) ($row['Nombre'] ?? ''),
                        'motivo'   => $task_label,
                        'task_id'  => is_array($task) ? (int) ($task['id'] ?? 0) : 0,
                        'task_tipo'=> $task_tipo,
                        'url'      => $task_url,
                        'producto_base_id' => $id,
                    ];
                }
                continue;
            }
            if ($is_child) {
                $withheld_skus[$sku] = true;
                unset($row['_familia_decision']);
                $row['_withhold_reason'] = 'family_child';
                $withheld_products[$sku] = $row;
                $skipped_children++;
                continue;
            }
            unset($row['_familia_decision']);
            $products[$sku] = $row;
            if ($id > 0) {
                $eligible_ids[$id] = $sku;
            }
        }

        $barcodes = $this->load_current_barcodes($eligible_ids, $family_index, $pending_ids);

        return [
            'products'                   => $products,
            'withheld_products'          => $withheld_products,
            'barcodes'                   => $barcodes,
            'withheld_skus'              => $withheld_skus,
            'skipped_family_pending'     => $skipped_pending,
            'skipped_family_children'    => $skipped_children,
            'withheld_family_pending'    => $withheld_pending_samples,
        ];
    }

    /**
     * @return string
     */
    private function family_task_label($task_tipo, $decision = '') {
        $task_tipo = (string) $task_tipo;
        if ($task_tipo === 'asignar_familia' || trim((string) $decision) === 'requiere') {
            return 'Asignar familia';
        }
        return '¿Necesita familia?';
    }

    /**
     * @param array<int, string> $sku_filter
     * @param array<int, array<string, mixed>> $family_index
     * @return array<int, string>
     */
    private function expand_sku_filter(array $sku_filter, array $family_index) {
        if (!$sku_filter) {
            return [];
        }
        $allow = [];
        foreach ($sku_filter as $sku) {
            $allow[$sku] = true;
        }
        foreach ($family_index as $fam) {
            $own = (string) ($fam['sku'] ?? '');
            $unit = (string) ($fam['unit_sku'] ?? '');
            if ($own !== '' && isset($allow[$own]) && $unit !== '') {
                $allow[$unit] = true;
            }
        }
        return array_keys($allow);
    }

    /**
     * Índice de familia por producto_base_id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function load_family_index() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $sql = "
            SELECT
                em.producto_base_id AS producto_id,
                pb.canonical_sku AS sku,
                g.id AS grupo_id,
                g.es_producto_unitario,
                g.unit_producto_base_id,
                ub.canonical_sku AS unit_sku
            FROM {$prefix}equivalence_members em
            INNER JOIN {$prefix}equivalence_groups g
                ON g.id = em.grupo_id AND g.activo = 1
            INNER JOIN {$prefix}producto_base pb
                ON pb.id = em.producto_base_id
            LEFT JOIN {$prefix}producto_base ub
                ON ub.id = g.unit_producto_base_id
            WHERE em.activo = 1

            UNION

            SELECT
                g.unit_producto_base_id AS producto_id,
                ub.canonical_sku AS sku,
                g.id AS grupo_id,
                g.es_producto_unitario,
                g.unit_producto_base_id,
                ub.canonical_sku AS unit_sku
            FROM {$prefix}equivalence_groups g
            INNER JOIN {$prefix}producto_base ub
                ON ub.id = g.unit_producto_base_id
            WHERE g.activo = 1
              AND g.es_producto_unitario = 1
              AND g.unit_producto_base_id IS NOT NULL
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $index = [];
        foreach ($rows as $row) {
            $id = (int) ($row['producto_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $unit_id = (int) ($row['unit_producto_base_id'] ?? 0);
            $is_unitaria = !empty($row['es_producto_unitario']) && $unit_id > 0;
            $index[$id] = [
                'grupo_id'  => (int) ($row['grupo_id'] ?? 0),
                'sku'       => trim((string) ($row['sku'] ?? '')),
                'unit_id'   => $unit_id,
                'unit_sku'  => trim((string) ($row['unit_sku'] ?? '')),
                'is_child'  => $is_unitaria && $id !== $unit_id,
            ];
        }

        $unit_of = $wpdb->get_results(
            "SELECT pb.id, pb.canonical_sku, pb.unit_of_grupo_id, g.es_producto_unitario, g.unit_producto_base_id, ub.canonical_sku AS unit_sku
             FROM {$prefix}producto_base pb
             INNER JOIN {$prefix}equivalence_groups g ON g.id = pb.unit_of_grupo_id AND g.activo = 1
             LEFT JOIN {$prefix}producto_base ub ON ub.id = g.unit_producto_base_id
             WHERE pb.unit_of_grupo_id IS NOT NULL AND pb.deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        foreach ($unit_of as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($index[$id])) {
                continue;
            }
            $unit_id = (int) ($row['unit_producto_base_id'] ?? 0);
            $is_unitaria = !empty($row['es_producto_unitario']) && $unit_id > 0;
            $index[$id] = [
                'grupo_id' => (int) ($row['unit_of_grupo_id'] ?? 0),
                'sku'      => trim((string) ($row['canonical_sku'] ?? '')),
                'unit_id'  => $unit_id,
                'unit_sku' => trim((string) ($row['unit_sku'] ?? '')),
                'is_child' => $is_unitaria && $id !== $unit_id,
            ];
        }

        return $index;
    }

    /**
     * Tareas de familia abiertas indexadas por producto_base_id.
     *
     * @return array<int, array{id: int, tipo: string, titulo: string, estado: string}>
     */
    private function load_pending_family_tasks() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT id, referencia_id, tipo, titulo, estado
             FROM {$prefix}tareas
             WHERE tipo IN ('preguntar_familia', 'asignar_familia')
               AND referencia_tipo = 'producto_base'
               AND estado NOT IN ('completada', 'cancelada')
               AND referencia_id > 0
             ORDER BY FIELD(tipo, 'preguntar_familia', 'asignar_familia'), id ASC",
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['referencia_id'] ?? 0);
            if ($id <= 0 || isset($map[$id])) {
                continue;
            }
            $map[$id] = [
                'id'     => (int) ($row['id'] ?? 0),
                'tipo'   => (string) ($row['tipo'] ?? ''),
                'titulo' => (string) ($row['titulo'] ?? ''),
                'estado' => (string) ($row['estado'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @return array<int, true>
     */
    private function load_pending_family_product_ids() {
        $map = [];
        foreach ($this->load_pending_family_tasks() as $id => $_task) {
            $map[(int) $id] = true;
        }
        return $map;
    }

    /**
     * @param array<int, true> $pending_ids
     */
    private function is_family_unresolved($product_id, $decision, $in_family, array $pending_ids) {
        $product_id = (int) $product_id;
        if ($product_id > 0 && isset($pending_ids[$product_id])) {
            return true;
        }
        if ($in_family) {
            return false;
        }
        return trim((string) $decision) !== 'no_requiere';
    }

    /**
     * @param array<int, string> $sku_filter
     * @return array<string, array<string, mixed>>
     */
    private function load_current_products(array $sku_filter) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = [
            "pb.canonical_sku IS NOT NULL",
            "pb.canonical_sku <> ''",
            'pb.deleted_at IS NULL',
            'pb.archived_at IS NULL',
        ];
        $params = [];

        if (!empty($sku_filter)) {
            $placeholders = implode(',', array_fill(0, count($sku_filter), '%s'));
            $where[] = "pb.canonical_sku IN ($placeholders)";
            $params = array_merge($params, $sku_filter);
        }

        $sql = "
            SELECT
                pb.id,
                pb.canonical_sku,
                pb.nombre_canonico,
                pb.familia_decision,
                pl.p_asignado
            FROM {$prefix}producto_base pb
            LEFT JOIN {$prefix}precios pl
                ON pl.producto_base_id = pb.id
               AND pl.canal = 'local'
               AND pl.woocommerce_variation_id = 0
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pb.canonical_sku ASC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $raw = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($raw as $item) {
            $sku = trim((string) ($item['canonical_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $precio = $item['p_asignado'];
            $map[$sku] = [
                '_producto_base_id'  => (int) ($item['id'] ?? 0),
                '_entity_key'        => $sku,
                '_familia_decision'  => (string) ($item['familia_decision'] ?? ''),
                'SKU'                => $sku,
                'SKU_Anterior'       => '',
                // Colapsar espacios: FACTO no admite dobles; TPV legacy a menudo sí los tiene.
                'Nombre'             => $this->normalize_product_name($item['nombre_canonico'] ?? ''),
                'Precio'             => $precio !== null && $precio !== '' ? round((float) $precio, 3) : '',
            ];
        }

        return $map;
    }

    /**
     * @param array<int, string> $eligible_ids id => sku TPV
     * @param array<int, array<string, mixed>> $family_index
     * @param array<int, true> $pending_ids
     * @return array<string, array<string, mixed>>
     */
    private function load_current_barcodes(array $eligible_ids, array $family_index, array $pending_ids) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $owner_ids = array_map('intval', array_keys($eligible_ids));
        foreach ($family_index as $pid => $fam) {
            if (!empty($fam['is_child']) && isset($eligible_ids[(int) ($fam['unit_id'] ?? 0)])) {
                $owner_ids[] = (int) $pid;
            }
        }
        $owner_ids = array_values(array_unique(array_filter($owner_ids)));
        if (!$owner_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($owner_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT cb.id, cb.codigo, cb.producto_base_id
             FROM {$prefix}codigo_barra cb
             WHERE cb.activo = 1 AND cb.producto_base_id IN ($placeholders)
             ORDER BY cb.codigo ASC",
            ...$owner_ids
        );

        $raw = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($raw as $item) {
            $pid = (int) ($item['producto_base_id'] ?? 0);
            $codigo = trim((string) ($item['codigo'] ?? ''));
            if ($pid <= 0 || $codigo === '') {
                continue;
            }

            $fam = $family_index[$pid] ?? null;
            $is_child = is_array($fam) && !empty($fam['is_child']);
            if ($is_child) {
                $unit_id = (int) ($fam['unit_id'] ?? 0);
                $unit_sku = trim((string) ($fam['unit_sku'] ?? ''));
                if ($unit_id <= 0 || $unit_sku === '' || !isset($eligible_ids[$unit_id])) {
                    continue;
                }
                $sku = $unit_sku;
                $dest_id = $unit_id;
            } else {
                if (isset($pending_ids[$pid]) || !isset($eligible_ids[$pid])) {
                    continue;
                }
                $sku = $eligible_ids[$pid];
                $dest_id = $pid;
            }

            $key = $sku . '|' . $codigo;
            $map[$key] = [
                '_producto_base_id' => $dest_id,
                '_entity_key'       => $key,
                'SKU'               => $sku,
                'CodigoBarras'      => $codigo,
            ];
        }

        return $map;
    }

    /**
     * Baseline TPV = fusión de TODOS los lotes aplicados (orden cronológico).
     * Usar solo el último lote rompe el delta: tras aplicar un Excel incremental
     * pequeño, el resto del catálogo vuelve a salir como CREAR.
     *
     * @return array{batch_id: int, products: array<string, array<string, mixed>>, barcodes: array<string, true>}
     */
    private function get_last_applied_snapshot() {
        global $wpdb;
        $batch_table = $this->table('tpv_export_batches');
        $items_table = $this->table('tpv_export_items');

        $batch_ids = $wpdb->get_col(
            "SELECT id FROM {$batch_table}
             WHERE estado = 'aplicado'
             ORDER BY applied_at ASC, id ASC"
        );
        if (!$batch_ids) {
            return ['batch_id' => 0, 'products' => [], 'barcodes' => []];
        }

        $products = [];
        $barcodes = [];
        $last_batch_id = 0;

        foreach ($batch_ids as $batch_id) {
            $batch_id = (int) $batch_id;
            if ($batch_id <= 0) {
                continue;
            }
            $last_batch_id = $batch_id;
            $offset = 0;
            $chunk = 2000;
            while (true) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT entity_type, entity_key, producto_base_id, accion, payload_json
                     FROM {$items_table}
                     WHERE batch_id = %d
                     ORDER BY id ASC
                     LIMIT %d OFFSET %d",
                    $batch_id,
                    $chunk,
                    $offset
                ), ARRAY_A) ?: [];
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $type = (string) ($row['entity_type'] ?? '');
                    $key = trim((string) ($row['entity_key'] ?? ''));
                    if ($key === '') {
                        continue;
                    }
                    $accion = strtoupper(trim((string) ($row['accion'] ?? '')));

                    if ($type === self::ENTITY_BARCODE) {
                        if ($accion === self::ACTION_DELETE) {
                            unset($barcodes[$key]);
                        } else {
                            $barcodes[$key] = true;
                        }
                        continue;
                    }
                    if ($type !== self::ENTITY_PRODUCT) {
                        continue;
                    }

                    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                    if (!is_array($payload)) {
                        $payload = [];
                    }

                    // CAMBIAR_SKU: el SKU anterior deja de existir en el baseline.
                    $sku_anterior = trim((string) ($payload['SKU_Anterior'] ?? ''));
                    if ($accion === self::ACTION_CHANGE_SKU && $sku_anterior !== '') {
                        unset($products[$sku_anterior]);
                    }

                    if ($accion === self::ACTION_DELETE) {
                        unset($products[$key]);
                        continue;
                    }

                    $products[$key] = [
                        'hash'             => $this->compute_product_hash($payload),
                        'producto_base_id' => (int) ($row['producto_base_id'] ?? 0),
                        'payload'          => $payload,
                    ];
                }
                $offset += count($rows);
                if (count($rows) < $chunk) {
                    break;
                }
            }
        }

        return [
            'batch_id' => $last_batch_id,
            'products' => $products,
            'barcodes' => $barcodes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function compute_product_hash(array $row) {
        $payload = [
            'SKU'          => $row['SKU'] ?? '',
            'SKU_Anterior' => $row['SKU_Anterior'] ?? '',
            'Nombre'       => $row['Nombre'] ?? '',
            'Precio'       => $this->format_price_cell($row['Precio'] ?? ''),
        ];
        return hash('sha256', wp_json_encode($payload));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function compute_barcode_hash(array $row) {
        $payload = [
            'SKU'          => $row['SKU'] ?? '',
            'CodigoBarras' => $row['CodigoBarras'] ?? '',
        ];
        return hash('sha256', wp_json_encode($payload));
    }

    /**
     * @param array<string, mixed> $filters
     * @param bool                 $has_last_batch
     * @param string               $baseline
     * @param array<int, array<string, mixed>> $withheld_pending
     */
    private function build_empty_hint(array $filters, $has_last_batch, $baseline = 'none', $skipped_pending = 0, $skipped_children = 0, array $withheld_pending = [], $export_total = 0) {
        $skipped_pending = (int) $skipped_pending;
        $skipped_children = (int) $skipped_children;

        // Prioridad: familia retenida (altas locales nuevas) sobre «sin cambios».
        if ($skipped_pending > 0) {
            $sample = $withheld_pending[0] ?? null;
            $sku = is_array($sample) ? trim((string) ($sample['sku'] ?? '')) : '';
            if ($sku !== '') {
                return sprintf(
                    'El SKU %s se creó en Riverso, pero no se exporta a TPV hasta resolver si es familia (%s). Abrí la tarea en el Hub de productos.',
                    $sku,
                    (string) ($sample['motivo'] ?? '¿Necesita familia?')
                );
            }
            return sprintf(
                'Hay %d producto(s) creados pero retenidos: falta resolver si son familia. Completá la tarea en el Hub antes de exportar a TPV.',
                $skipped_pending
            );
        }
        if ($skipped_children > 0 && (int) $export_total <= 0) {
            return 'No hay productos exportables a TPV. Hay ' . $skipped_children . ' hijos de familia unitaria (solo se exporta el unitario).';
        }
        if ((int) $export_total > 0) {
            return '';
        }
        if (!empty($filters['only_changed']) && $baseline !== 'none') {
            if ($has_last_batch || $baseline === 'batch') {
                return 'No hay cambios desde el último lote aplicado. Desmarca «Solo cambios» para exportar el catálogo completo.';
            }
            return 'No hay cambios respecto al catálogo TPV legacy. Desmarca «Solo cambios» para exportar el catálogo completo.';
        }
        if (!empty($filters['sku'])) {
            return 'El filtro SKU no coincide con productos activos exportables a TPV.';
        }
        return 'No hay productos exportables a TPV.';
    }

    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $current
     * @return array<int, array{campo: string, antes: string, despues: string}>
     */
    private function diff_product_fields(array $prev, array $current) {
        $diffs = [];
        foreach (['Nombre', 'Precio'] as $field) {
            $from = $this->field_display_value($field, $prev[$field] ?? '');
            $to = $this->field_display_value($field, $current[$field] ?? '');
            if ($from !== $to) {
                $diffs[] = [
                    'campo'    => $field,
                    'antes'    => $from,
                    'despues'  => $to,
                ];
            }
        }
        return $diffs;
    }

    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $current
     * @return array<int, array{campo: string, antes: string, despues: string}>
     */
    private function diff_barcode_fields(array $prev, array $current) {
        $from = trim((string) ($prev['CodigoBarras'] ?? ''));
        $to = trim((string) ($current['CodigoBarras'] ?? ''));
        if ($from === $to) {
            return [];
        }
        return [[
            'campo'   => 'CodigoBarras',
            'antes'   => $from,
            'despues' => $to,
        ]];
    }

    /**
     * @param mixed $value
     */
    private function field_display_value($field, $value) {
        if ($field === 'Precio') {
            return $this->format_price_cell($value);
        }
        return trim((string) $value);
    }

    /**
     * @param string $sku
     * @return array<int, string>
     */
    private function parse_sku_filter($sku) {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $sku);
        $out = [];
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $barcodes
     */
    private function record_batch(array $filters, $product_count, $barcode_count, $file_hash, array $products, array $barcodes) {
        global $wpdb;
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $wpdb->insert($this->table('tpv_export_batches'), [
            'alcance'          => wp_json_encode($filters),
            'total_productos'  => $product_count,
            'total_barcodes'   => $barcode_count,
            'file_hash'        => $file_hash,
            'estado'           => 'generado',
            'created_by'       => $user_id ?: null,
            'created_at'       => $now,
        ]);
        $batch_id = (int) $wpdb->insert_id;

        foreach ($products as $row) {
            $this->insert_batch_item($batch_id, self::ENTITY_PRODUCT, $row);
        }
        foreach ($barcodes as $row) {
            $this->insert_batch_item($batch_id, self::ENTITY_BARCODE, $row);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('tpv_export.generated', 'tpv_export_batch', $batch_id, [
                'actor_type' => 'human',
                'details'    => [
                    'productos' => $product_count,
                    'barcodes'  => $barcode_count,
                    'hash'      => $file_hash,
                ],
            ]);
        }

        return $batch_id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert_batch_item($batch_id, $entity_type, array $row) {
        global $wpdb;

        if ($entity_type === self::ENTITY_PRODUCT) {
            $payload = [
                'SKU'          => $row['SKU'] ?? '',
                'SKU_Anterior' => $row['SKU_Anterior'] ?? '',
                'Nombre'       => $row['Nombre'] ?? '',
                'Precio'       => $row['Precio'] ?? '',
            ];
            $entity_key = (string) ($row['SKU'] ?? '');
        } else {
            $payload = [
                'SKU'          => $row['SKU'] ?? '',
                'CodigoBarras' => $row['CodigoBarras'] ?? '',
            ];
            $entity_key = (string) ($row['_entity_key'] ?? (($row['SKU'] ?? '') . '|' . ($row['CodigoBarras'] ?? '')));
        }

        $wpdb->insert($this->table('tpv_export_items'), [
            'batch_id'         => $batch_id,
            'entity_type'      => $entity_type,
            'entity_key'       => $entity_key,
            'producto_base_id' => (int) ($row['_producto_base_id'] ?? 0),
            'sku'              => (string) ($row['SKU'] ?? ''),
            'accion'           => (string) ($row['_accion'] ?? ''),
            'row_hash'         => (string) ($row['_row_hash'] ?? ''),
            'payload_json'     => wp_json_encode($payload),
        ]);
    }

    /**
     * @param array<string, mixed> $batch
     * @param int                  $max_applied_id ID del lote aplicado más reciente (0 si no hay)
     * @return array<string, mixed>
     */
    private function format_batch_row(array $batch, $max_applied_id = 0) {
        $id = (int) ($batch['id'] ?? 0);
        $estado = (string) ($batch['estado'] ?? '');
        $max_applied_id = (int) $max_applied_id;
        // No marcar como aplicado un lote viejo si ya hay uno posterior aplicado.
        $can_mark = $estado === 'generado' && ($max_applied_id <= 0 || $id > $max_applied_id);

        return [
            'id'               => $id,
            'total_productos'  => (int) ($batch['total_productos'] ?? 0),
            'total_barcodes'   => (int) ($batch['total_barcodes'] ?? 0),
            'estado'           => $estado,
            'created_at'       => (string) ($batch['created_at'] ?? ''),
            'applied_at'       => (string) ($batch['applied_at'] ?? ''),
            'can_mark_applied' => $can_mark,
            'blocked_by_newer' => $estado === 'generado' && $max_applied_id > 0 && $id < $max_applied_id,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_preview_product(array $row) {
        return [
            'accion'       => (string) ($row['_accion'] ?? ''),
            'sku'          => (string) ($row['SKU'] ?? ''),
            'sku_anterior' => (string) ($row['SKU_Anterior'] ?? ''),
            'nombre'       => (string) ($row['Nombre'] ?? ''),
            'precio'       => $this->format_price_cell($row['Precio'] ?? ''),
            'diffs'        => is_array($row['_diffs'] ?? null) ? $row['_diffs'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_preview_barcode(array $row) {
        return [
            'accion' => (string) ($row['_accion'] ?? ''),
            'sku'    => (string) ($row['SKU'] ?? ''),
            'codigo' => (string) ($row['CodigoBarras'] ?? ''),
            'diffs'  => is_array($row['_diffs'] ?? null) ? $row['_diffs'] : [],
        ];
    }

    /**
     * Remapes SKU anterior → nuevo (desde drift FACTO).
     *
     * @return array<string, array{sku_nuevo: string, producto_base_id: int, nombre: string}>
     */
    private function load_sku_remap_by_old() {
        if (!class_exists('Riverso_Facto_Export_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/integrations/facto/class-facto-export-service.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Facto_Export_Service')) {
            return [];
        }
        $facto = new Riverso_Facto_Export_Service();
        return $facto->get_sku_remap_index();
    }

    private function build_filename() {
        return 'tpv-catalogo_' . gmdate('Y-m-d') . '.xlsx';
    }
}
