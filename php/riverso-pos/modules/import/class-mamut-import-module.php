<?php
/**
 * Módulo de Importación MAMUT - Riverso POS.
 *
 * Lee el catálogo MAMUT (data/catalogo_mamut_2025_spatial.json), que organiza
 * los SKU por categoría / subcategoría / producto, y:
 *   - crea/asegura un producto_proveedor para el proveedor MAMUT (codigo=SKU),
 *   - crea producto_base mínimo cuando el SKU no existe (gobernanza computer),
 *   - dispara el matching progresivo (Fase 3),
 *   - encola tareas validar_categoria para productos sin relación WooCommerce.
 *
 * Procesa por lotes (offset/limit) para soportar catálogos grandes (~5k SKU).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Mamut_Import_Module {

    private static $instance = null;

    const SUPPLIER_NAME = 'MAMUT';
    const SUPPLIER_RUT  = 'MAMUT';
    const CATALOG_NAME  = 'Catálogo Mamut 2025';
    const CATALOG_ALIAS = 'mamut';
    const CATALOG_VERSION = '2025';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_mamut_import', [$this, 'ajax_import']);
        add_action('wp_ajax_riverso_mamut_count', [$this, 'ajax_count']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('riverso-mamut import', [$this, 'cli_import']);
        }
    }

    /**
     * Ruta por defecto del catálogo MAMUT.
     * Prioriza data/ dentro del plugin (servidor); fallback monorepo local.
     */
    public function default_path() {
        if (class_exists('Riverso_Family_Suggestion_Service')) {
            return Riverso_Family_Suggestion_Service::default_catalog_path();
        }
        $candidates = [
            RIVERSO_POS_PLUGIN_DIR . 'data/catalogo_mamut_2025_spatial.json',
            RIVERSO_POS_PLUGIN_DIR . '../../data/catalogo_mamut_2025_spatial.json',
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real && is_readable($real)) {
                return $real;
            }
        }
        return $candidates[0];
    }

    /**
     * Aplana la estructura jerárquica del catálogo en una lista de entradas:
     * [sku, categoria, subcategoria, producto].
     *
     * @param array $node
     * @param array $path
     * @param array $out
     */
    private function flatten($node, $path, &$out) {
        if (!is_array($node)) {
            return;
        }
        // Nodo hoja con lista de SKUs.
        if (isset($node['skus']) && is_array($node['skus'])) {
            foreach ($node['skus'] as $sku) {
                $out[] = [
                    'sku' => (string) $sku,
                    'categoria' => $path[0] ?? '',
                    'subcategoria' => $path[1] ?? '',
                    'producto' => $path[2] ?? ($path[1] ?? ''),
                ];
            }
        }
        foreach ($node as $key => $child) {
            if ($key !== 'skus' && is_array($child)) {
                $next = $path;
                $next[] = $key;
                $this->flatten($child, $next, $out);
            }
        }
    }

    /**
     * Devuelve la lista aplanada de SKUs del catálogo.
     *
     * @param string $path
     * @return array|WP_Error
     */
    public function load_entries($path = '') {
        $path = $path ?: $this->default_path();
        if (!file_exists($path)) {
            return new WP_Error('not_found', 'Archivo MAMUT no encontrado: ' . $path);
        }
        $json = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'JSON inválido: ' . json_last_error_msg());
        }
        $root = isset($json['structure']) ? $json['structure'] : $json;
        $out = [];
        $this->flatten($root, [], $out);
        $products = isset($json['products']) && is_array($json['products']) ? $json['products'] : [];
        foreach ($out as &$entry) {
            $detail = $products[$entry['sku']] ?? [];
            if (!empty($detail['nombre_producto'])) {
                $entry['nombre_producto'] = $detail['nombre_producto'];
            }
            if (!empty($detail['category_path']) && is_array($detail['category_path'])) {
                $entry['category_path'] = $detail['category_path'];
                $entry['categoria'] = $detail['category_path'][0] ?? $entry['categoria'];
                $entry['subcategoria'] = $detail['category_path'][1] ?? $entry['subcategoria'];
                $entry['producto'] = $detail['category_path'][2] ?? $entry['producto'];
            }
            $entry['attributes'] = !empty($detail['attributes']) && is_array($detail['attributes'])
                ? $detail['attributes']
                : [];
        }
        return $out;
    }

    /**
     * Obtiene (o crea) el proveedor MAMUT.
     */
    /**
     * Obtiene (o resuelve) el proveedor del catálogo Mamut = Tecbolt SA.
     * Ya no crea un proveedor "MAMUT"; usa Tecbolt o el dueño del catálogo mamut.
     */
    public function get_or_create_supplier() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_proveedores';
        $prefix = $wpdb->prefix . 'riverso_';

        // 1) Dueño actual del catálogo mamut
        $from_catalog = (int) $wpdb->get_var(
            "SELECT proveedor_id FROM {$prefix}catalogos
             WHERE alias = 'mamut' OR (nombre LIKE '%Mamut%' AND version = '2025')
             ORDER BY id ASC LIMIT 1"
        );
        if ($from_catalog > 0) {
            return $from_catalog;
        }

        // 2) Tecbolt por nombre/apodo
        $tecbolt = (int) $wpdb->get_var(
            "SELECT p.id FROM {$table} p
             LEFT JOIN {$prefix}proveedor_apodos a ON a.proveedor_id = p.id
             WHERE p.nombre LIKE '%Tecbolt%'
                OR a.apodo LIKE '%Mamut%'
                OR a.apodo LIKE '%MAMUT%'
             ORDER BY p.id ASC LIMIT 1"
        );
        if ($tecbolt > 0) {
            return $tecbolt;
        }

        // 3) Legacy: aún existe fila MAMUT
        $mamut = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE rut = %s OR nombre = %s LIMIT 1",
            self::SUPPLIER_RUT,
            self::SUPPLIER_NAME
        ));
        if ($mamut > 0) {
            return $mamut;
        }

        // 4) Último recurso: crear Tecbolt SA (nunca MAMUT)
        $wpdb->insert($table, [
            'rut' => '76.000.000-0',
            'nombre' => 'Tecbolt SA',
            'activo' => 1,
        ], ['%s', '%s', '%d']);
        $id = (int) $wpdb->insert_id;
        if ($id > 0) {
            $wpdb->insert("{$prefix}proveedor_apodos", [
                'proveedor_id' => $id,
                'apodo' => 'Mamut',
            ], ['%d', '%s']);
        }
        return $id;
    }

    /**
     * Obtiene (o crea) el catálogo MAMUT.
     */
    public function get_or_create_catalog($supplier_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}catalogos WHERE proveedor_id = %d AND alias = %s LIMIT 1",
            $supplier_id,
            self::CATALOG_ALIAS
        ));
        if ($id) {
            return intval($id);
        }

        $wpdb->insert("{$prefix}catalogos", [
            'proveedor_id' => $supplier_id,
            'nombre' => self::CATALOG_NAME,
            'alias' => self::CATALOG_ALIAS,
            'version' => self::CATALOG_VERSION,
            'activo' => 1,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%d', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Asegura un producto_base para un código de catálogo MAMUT.
     * El código Mamut NO es SKU Local: solo vive en producto_proveedor.
     *
     * @param string $sku Código catálogo/proveedor Mamut
     * @param string $nombre
     * @param int    $supplier_id
     * @return array|WP_Error [id, created]
     */
    private function ensure_base($sku, $nombre, $supplier_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $supplier_id = absint($supplier_id);

        // 1) Preferir vínculo existente por código proveedor
        if ($supplier_id > 0) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT producto_base_id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s AND producto_base_id IS NOT NULL
                 LIMIT 1",
                $supplier_id,
                $sku
            ));
            if ($id) {
                return ['id' => intval($id), 'created' => false];
            }
        }

        // 2) Legacy: bases donde se copió el código Mamut a canonical_sku
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
        if ($id) {
            return ['id' => intval($id), 'created' => false];
        }

        // SKU Local queda vacío hasta tarea humana (crear_contraparte_local)
        $inserted = $wpdb->insert("{$prefix}producto_base", [
            'woocommerce_product_id' => null,
            'woocommerce_variation_id' => null,
            'nombre_canonico' => $nombre ?: ('Mamut ' . $sku),
            'unidad_base' => 'unidad',
            'permite_ean13_personalizado' => 1,
            'estado' => 'activo',
            'created_by_system' => 1,
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
        ], ['%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s']);

        if ($inserted === false) {
            return new WP_Error('base_insert_failed', 'No se pudo crear producto_base para código ' . $sku . ': ' . $wpdb->last_error);
        }

        $base_id = (int) $wpdb->insert_id;
        if (function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'crear_contraparte_local',
                'Asignar SKU Local para código catálogo ' . $sku,
                'producto_base',
                $base_id,
                [
                    'prioridad' => 'normal',
                    'datos_extra' => [
                        'codigo_catalogo' => $sku,
                        'codigo_proveedor' => $sku,
                        'origen' => 'mamut_import',
                    ],
                ]
            );
        }

        return ['id' => $base_id, 'created' => true];
    }

    /**
     * Asegura un producto_proveedor MAMUT para un producto_base.
     *
     * @return int pp_id
     */
    private function ensure_pp($base_id, $supplier_id, $sku, $nombre, $catalog_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pp_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_proveedor WHERE proveedor_id = %d AND codigo_proveedor = %s",
            $supplier_id,
            $sku
        ));
        if ($pp_id) {
            // Si el catálogo se pasó y el PP no tiene, actualizar
            if ($catalog_id && !$wpdb->get_var($wpdb->prepare(
                "SELECT catalogo_id FROM {$prefix}producto_proveedor WHERE id = %d",
                $pp_id
            ))) {
                $wpdb->update(
                    "{$prefix}producto_proveedor",
                    ['catalogo_id' => $catalog_id],
                    ['id' => $pp_id],
                    ['%d'],
                    ['%d']
                );
            }
            return intval($pp_id);
        }

        $insert_data = [
            'producto_base_id' => $base_id,
            'proveedor_id' => $supplier_id,
            'codigo_proveedor' => $sku,
            'nombre_proveedor' => $nombre,
            'origen_datos' => 'catalogo',
            'activo' => 1,
            'created_by_system' => 1,
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
            'match_estado' => 'UNMATCHED',
        ];
        $insert_formats = ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s'];

        if ($catalog_id) {
            $insert_data['catalogo_id'] = $catalog_id;
            $insert_formats[] = '%d';
        }

        $wpdb->insert("{$prefix}producto_proveedor", $insert_data, $insert_formats);
        $pp_id = (int) $wpdb->insert_id;

        // Si el base ya tiene SKU local (match legacy/mapping), dejar por confirmar.
        if ($pp_id > 0 && $base_id > 0) {
            $has_local = $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_sku FROM {$prefix}producto_base
                 WHERE id = %d AND canonical_sku IS NOT NULL AND canonical_sku <> ''",
                $base_id
            ));
            if ($has_local && class_exists('Riverso_Supplier_Links_Module')) {
                Riverso_Supplier_Links_Module::get_instance()->ensure_codigo_proveedor_review_tasks(
                    $base_id,
                    [[
                        'id' => $pp_id,
                        'codigo_proveedor' => $sku,
                        'proveedor_id' => $supplier_id,
                        'producto_base_id' => $base_id,
                        'match_estado' => 'UNMATCHED',
                        'requires_human_review' => 1,
                        'review_status' => 'pendiente',
                    ]]
                );
            }
        }

        return $pp_id;
    }

    /**
     * Importa un lote de entradas MAMUT.
     *
     * @param int    $offset
     * @param int    $limit
     * @param string $path
     * @return array|WP_Error
     */
    public function import_batch($offset = 0, $limit = 200, $path = '') {
        $entries = $this->load_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $total = count($entries);
        $offset = max(0, intval($offset));
        $limit = max(1, intval($limit));
        $slice = array_slice($entries, $offset, $limit);

        $supplier_id = $this->get_or_create_supplier();
        $catalog_id = $this->get_or_create_catalog($supplier_id);
        $created_bases = 0;
        $created_pps = 0;
        $processed = 0;

        foreach ($slice as $entry) {
            $sku = trim($entry['sku']);
            if ($sku === '') {
                continue;
            }
            $nombre = trim($entry['nombre_producto'] ?? '');
            if ($nombre === '') {
                $nombre = trim($entry['producto'] . ' ' . $entry['subcategoria']);
            }
            $nombre = $nombre ?: $sku;

            $base = $this->ensure_base($sku, $nombre, $supplier_id);
            if (is_wp_error($base)) {
                return $base;
            }
            if ($base['created']) {
                $created_bases++;
            }

            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $pp_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor WHERE proveedor_id = %d AND codigo_proveedor = %s",
                $supplier_id,
                $sku
            ));

            $pp_id = $this->ensure_pp($base['id'], $supplier_id, $sku, $nombre, $catalog_id);
            if (!$pp_exists) {
                $created_pps++;
            }

            // Disparar matching (Fase 3).
            if (class_exists('Riverso_Matching_Module')) {
                Riverso_Matching_Module::get_instance()->run_match($pp_id);
            }

            // Sin SKU Local => tarea de asignación (además de validar online si falta Woo)
            $local_sku = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_sku FROM {$prefix}producto_base WHERE id = %d",
                $base['id']
            ));
            if ($local_sku === '' && function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'crear_contraparte_local',
                    'Asignar SKU Local para código catálogo ' . $sku,
                    'producto_base',
                    $base['id'],
                    [
                        'prioridad' => 'normal',
                        'datos_extra' => [
                            'codigo_catalogo' => $sku,
                            'codigo_proveedor' => $sku,
                            'pp_id' => $pp_id,
                            'origen' => 'mamut_import',
                        ],
                    ]
                );
            }

            // Producto sin relación WooCommerce => tarea validar_categoria.
            $wc_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT woocommerce_product_id FROM {$prefix}producto_base WHERE id = %d",
                $base['id']
            ));
            if (!$wc_id && function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'validar_categoria',
                    'Validar categoría/relación de código catálogo MAMUT ' . $sku,
                    'producto_base',
                    $base['id'],
                    [
                        'prioridad' => 'normal',
                        'datos_extra' => [
                            'codigo_catalogo' => $sku,
                            'categoria' => $entry['categoria'],
                            'subcategoria' => $entry['subcategoria'],
                        ],
                    ]
                );
            }

            $processed++;
        }

        $next_offset = $offset + $limit;
        $done = $next_offset >= $total;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('mamut_import_batch', 'import', 0, [
                'new_value' => [
                    'offset' => $offset,
                    'processed' => $processed,
                    'created_bases' => $created_bases,
                    'created_pps' => $created_pps,
                ],
            ]);
        }

        return [
            'total' => $total,
            'offset' => $offset,
            'processed' => $processed,
            'created_bases' => $created_bases,
            'created_pps' => $created_pps,
            'next_offset' => $done ? null : $next_offset,
            'done' => $done,
        ];
    }

    /* ===================== AJAX ===================== */

    public function ajax_count() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $entries = $this->load_entries(sanitize_text_field($_POST['path'] ?? ''));
        if (is_wp_error($entries)) {
            wp_send_json_error(['message' => $entries->get_error_message()]);
        }
        wp_send_json_success(['total' => count($entries)]);
    }

    public function ajax_import() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->import_batch(
            intval($_POST['offset'] ?? 0),
            intval($_POST['limit'] ?? 200),
            sanitize_text_field($_POST['path'] ?? '')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /* ===================== WP-CLI ===================== */

    /**
     * wp riverso-mamut import [--limit=<n>] [--offset=<n>] [--once] [--json-path=<json>]
     */
    public function cli_import($args, $assoc) {
        $limit = isset($assoc['limit']) ? intval($assoc['limit']) : 500;
        $path = $assoc['json-path'] ?? ($assoc['catalog-path'] ?? '');
        $offset = isset($assoc['offset']) ? intval($assoc['offset']) : 0;
        $once = isset($assoc['once']);
        do {
            $res = $this->import_batch($offset, $limit, $path);
            if (is_wp_error($res)) {
                WP_CLI::error($res->get_error_message());
                return;
            }
            WP_CLI::log(sprintf(
                'Offset %d: procesados %d (bases nuevas %d, pp nuevos %d)',
                $res['offset'], $res['processed'], $res['created_bases'], $res['created_pps']
            ));
            $offset = $res['next_offset'];
        } while (!$once && $offset !== null);

        if ($once && $res['next_offset'] !== null) {
            WP_CLI::success('Lote MAMUT completado. Siguiente offset: ' . $res['next_offset']);
            return;
        }

        WP_CLI::success('Importación MAMUT completada (' . $res['total'] . ' SKUs).');
    }
}
