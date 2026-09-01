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

    const ENTITY_PRODUCT = 'producto';
    const ENTITY_BARCODE = 'barcode';

    const PRODUCT_HEADERS = [
        'Accion',
        'SKU',
        'Nombre',
        'Marca',
        'Proveedor',
        'CodigoProveedor',
        'Precio',
    ];

    const BARCODE_HEADERS = [
        'Accion',
        'SKU',
        'CodigoBarras',
    ];

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function preview(array $filters) {
        $built = $this->build_export_rows($filters);
        $products = $built['products'];
        $barcodes = $built['barcodes'];

        $create_p = 0;
        $update_p = 0;
        $delete_p = 0;
        foreach ($products as $row) {
            if ($row['_accion'] === self::ACTION_CREATE) {
                $create_p++;
            } elseif ($row['_accion'] === self::ACTION_UPDATE) {
                $update_p++;
            } elseif ($row['_accion'] === self::ACTION_DELETE) {
                $delete_p++;
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

        return [
            'total_productos'      => count($products),
            'total_barcodes'       => count($barcodes),
            'total'                => $total,
            'create_productos'     => $create_p,
            'update_productos'     => $update_p,
            'delete_productos'     => $delete_p,
            'create_barcodes'      => $create_b,
            'update_barcodes'      => $update_b,
            'delete_barcodes'      => $delete_b,
            'has_last_applied_batch' => $has_last_batch,
            'can_download'         => $total > 0,
            'empty_hint'           => $total > 0 ? '' : $this->build_empty_hint($filters, $has_last_batch),
            'sample_productos'     => array_slice(array_map([$this, 'format_sample_product'], $products), 0, 10),
            'sample_barcodes'      => array_slice(array_map([$this, 'format_sample_barcode'], $barcodes), 0, 10),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|WP_Error
     */
    public function generate_batch(array $filters) {
        $built = $this->build_export_rows($filters);
        $products = $built['products'];
        $barcodes = $built['barcodes'];

        if (empty($products) && empty($barcodes)) {
            return new WP_Error(
                'no_rows',
                'No hay filas para exportar. ' . $this->build_empty_hint($filters, !empty($built['has_last_applied_batch']))
            );
        }

        $product_sheet = [self::PRODUCT_HEADERS];
        foreach ($products as $row) {
            $product_sheet[] = [
                $row['_accion'],
                $row['SKU'],
                $row['Nombre'],
                $row['Marca'],
                $row['Proveedor'],
                $row['CodigoProveedor'],
                $row['Precio'],
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
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('tpv_export_batches')} ORDER BY id DESC LIMIT %d",
            max(1, min(100, absint($limit)))
        ), ARRAY_A) ?: [];

        return array_map(function ($batch) {
            return $this->format_batch_row($batch);
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

        $current_products = $this->load_current_products($sku_filter);
        $current_barcodes = $this->load_current_barcodes($sku_filter);
        $previous = $this->get_last_applied_snapshot();

        $has_last = !empty($previous['batch_id']);
        $product_rows = [];
        $barcode_rows = [];

        if (!$has_last) {
            foreach ($current_products as $sku => $row) {
                $row['_accion'] = self::ACTION_CREATE;
                $row['_row_hash'] = $this->compute_product_hash($row);
                $product_rows[] = $row;
            }
            foreach ($current_barcodes as $key => $row) {
                $row['_accion'] = self::ACTION_CREATE;
                $row['_row_hash'] = $this->compute_barcode_hash($row);
                $barcode_rows[] = $row;
            }
        } else {
            $prev_products = $previous['products'];
            $prev_barcodes = $previous['barcodes'];

            foreach ($current_products as $sku => $row) {
                $hash = $this->compute_product_hash($row);
                $row['_row_hash'] = $hash;
                if (!isset($prev_products[$sku])) {
                    $row['_accion'] = self::ACTION_CREATE;
                } elseif (($prev_products[$sku]['hash'] ?? '') !== $hash) {
                    $row['_accion'] = self::ACTION_UPDATE;
                } else {
                    $row['_accion'] = $only_changed ? null : self::ACTION_UPDATE;
                }
                if ($row['_accion'] !== null) {
                    $product_rows[] = $row;
                }
            }

            foreach ($prev_products as $sku => $prev) {
                if (!isset($current_products[$sku])) {
                    $product_rows[] = [
                        '_accion'         => self::ACTION_DELETE,
                        '_row_hash'       => (string) ($prev['hash'] ?? ''),
                        '_producto_base_id' => (int) ($prev['producto_base_id'] ?? 0),
                        '_entity_key'     => $sku,
                        'SKU'             => $sku,
                        'Nombre'          => (string) ($prev['payload']['Nombre'] ?? ''),
                        'Marca'           => (string) ($prev['payload']['Marca'] ?? ''),
                        'Proveedor'       => (string) ($prev['payload']['Proveedor'] ?? ''),
                        'CodigoProveedor' => (string) ($prev['payload']['CodigoProveedor'] ?? ''),
                        'Precio'          => $prev['payload']['Precio'] ?? '',
                    ];
                }
            }

            foreach ($current_barcodes as $key => $row) {
                $hash = $this->compute_barcode_hash($row);
                $row['_row_hash'] = $hash;
                if (!isset($prev_barcodes[$key])) {
                    $row['_accion'] = self::ACTION_CREATE;
                } elseif (($prev_barcodes[$key]['hash'] ?? '') !== $hash) {
                    $row['_accion'] = self::ACTION_UPDATE;
                } else {
                    $row['_accion'] = $only_changed ? null : self::ACTION_UPDATE;
                }
                if ($row['_accion'] !== null) {
                    $barcode_rows[] = $row;
                }
            }

            foreach ($prev_barcodes as $key => $prev) {
                if (!isset($current_barcodes[$key])) {
                    $barcode_rows[] = [
                        '_accion'         => self::ACTION_DELETE,
                        '_row_hash'       => (string) ($prev['hash'] ?? ''),
                        '_producto_base_id' => (int) ($prev['producto_base_id'] ?? 0),
                        '_entity_key'     => $key,
                        'SKU'             => (string) ($prev['payload']['SKU'] ?? ''),
                        'CodigoBarras'    => (string) ($prev['payload']['CodigoBarras'] ?? ''),
                    ];
                }
            }
        }

        return [
            'products'              => $product_rows,
            'barcodes'              => $barcode_rows,
            'has_last_applied_batch' => $has_last,
        ];
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
                pb.marca,
                pl.p_asignado,
                (
                    SELECT pp.nombre_proveedor
                    FROM {$prefix}producto_proveedor pp
                    WHERE pp.producto_base_id = pb.id AND pp.activo = 1
                    ORDER BY pp.es_preferido DESC, pp.id ASC
                    LIMIT 1
                ) AS proveedor_nombre,
                (
                    SELECT pp.codigo_proveedor
                    FROM {$prefix}producto_proveedor pp
                    WHERE pp.producto_base_id = pb.id AND pp.activo = 1
                    ORDER BY pp.es_preferido DESC, pp.id ASC
                    LIMIT 1
                ) AS codigo_proveedor
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
                '_producto_base_id' => (int) ($item['id'] ?? 0),
                '_entity_key'       => $sku,
                'SKU'               => $sku,
                'Nombre'            => trim((string) ($item['nombre_canonico'] ?? '')),
                'Marca'             => trim((string) ($item['marca'] ?? '')),
                'Proveedor'         => trim((string) ($item['proveedor_nombre'] ?? '')),
                'CodigoProveedor'   => trim((string) ($item['codigo_proveedor'] ?? '')),
                'Precio'            => $precio !== null && $precio !== '' ? round((float) $precio, 2) : '',
            ];
        }

        return $map;
    }

    /**
     * @param array<int, string> $sku_filter
     * @return array<string, array<string, mixed>>
     */
    private function load_current_barcodes(array $sku_filter) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = [
            'cb.activo = 1',
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
            SELECT cb.id, cb.codigo, cb.producto_base_id, pb.canonical_sku
            FROM {$prefix}codigo_barra cb
            INNER JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pb.canonical_sku ASC, cb.codigo ASC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $raw = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($raw as $item) {
            $sku = trim((string) ($item['canonical_sku'] ?? ''));
            $codigo = trim((string) ($item['codigo'] ?? ''));
            if ($sku === '' || $codigo === '') {
                continue;
            }
            $key = $sku . '|' . $codigo;
            $map[$key] = [
                '_producto_base_id' => (int) ($item['producto_base_id'] ?? 0),
                '_entity_key'       => $key,
                'SKU'               => $sku,
                'CodigoBarras'      => $codigo,
            ];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_last_applied_snapshot() {
        global $wpdb;
        $batch_table = $this->table('tpv_export_batches');
        $items_table = $this->table('tpv_export_items');

        $batch_id = (int) $wpdb->get_var(
            "SELECT id FROM {$batch_table} WHERE estado = 'aplicado' ORDER BY applied_at DESC, id DESC LIMIT 1"
        );
        if ($batch_id <= 0) {
            return ['batch_id' => 0, 'products' => [], 'barcodes' => []];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$items_table} WHERE batch_id = %d",
            $batch_id
        ), ARRAY_A) ?: [];

        $products = [];
        $barcodes = [];
        foreach ($rows as $row) {
            $type = (string) ($row['entity_type'] ?? '');
            $key = trim((string) ($row['entity_key'] ?? ''));
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $entry = [
                'hash'             => (string) ($row['row_hash'] ?? ''),
                'producto_base_id' => (int) ($row['producto_base_id'] ?? 0),
                'payload'          => $payload,
            ];
            if ($type === self::ENTITY_PRODUCT && $key !== '') {
                $products[$key] = $entry;
            } elseif ($type === self::ENTITY_BARCODE && $key !== '') {
                $barcodes[$key] = $entry;
            }
        }

        return [
            'batch_id' => $batch_id,
            'products' => $products,
            'barcodes' => $barcodes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function compute_product_hash(array $row) {
        $payload = [
            'SKU'             => $row['SKU'] ?? '',
            'Nombre'          => $row['Nombre'] ?? '',
            'Marca'           => $row['Marca'] ?? '',
            'Proveedor'       => $row['Proveedor'] ?? '',
            'CodigoProveedor' => $row['CodigoProveedor'] ?? '',
            'Precio'          => $row['Precio'] ?? '',
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
     */
    private function build_empty_hint(array $filters, $has_last_batch) {
        if (!empty($filters['only_changed']) && $has_last_batch) {
            return 'No hay cambios desde el último lote aplicado. Desmarca «Solo cambios» para exportar el catálogo completo.';
        }
        if (!empty($filters['sku'])) {
            return 'El filtro SKU no coincide con productos activos.';
        }
        return 'No hay productos activos con SKU para exportar.';
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
                'SKU'             => $row['SKU'] ?? '',
                'Nombre'          => $row['Nombre'] ?? '',
                'Marca'           => $row['Marca'] ?? '',
                'Proveedor'       => $row['Proveedor'] ?? '',
                'CodigoProveedor' => $row['CodigoProveedor'] ?? '',
                'Precio'          => $row['Precio'] ?? '',
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
     * @return array<string, mixed>
     */
    private function format_batch_row(array $batch) {
        return [
            'id'              => (int) ($batch['id'] ?? 0),
            'total_productos' => (int) ($batch['total_productos'] ?? 0),
            'total_barcodes'  => (int) ($batch['total_barcodes'] ?? 0),
            'estado'          => (string) ($batch['estado'] ?? ''),
            'created_at'      => (string) ($batch['created_at'] ?? ''),
            'applied_at'      => (string) ($batch['applied_at'] ?? ''),
            'can_mark_applied' => ($batch['estado'] ?? '') === 'generado',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_sample_product(array $row) {
        return [
            'accion' => (string) ($row['_accion'] ?? ''),
            'sku'    => (string) ($row['SKU'] ?? ''),
            'nombre' => (string) ($row['Nombre'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_sample_barcode(array $row) {
        return [
            'accion'  => (string) ($row['_accion'] ?? ''),
            'sku'     => (string) ($row['SKU'] ?? ''),
            'codigo'  => (string) ($row['CodigoBarras'] ?? ''),
        ];
    }

    private function build_filename() {
        return 'tpv-catalogo_' . gmdate('Y-m-d') . '.xlsx';
    }
}
