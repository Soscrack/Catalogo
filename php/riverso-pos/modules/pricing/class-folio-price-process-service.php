<?php
/**
 * Workflow Procesar folios — asignación de precios desde factura de compra.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Folio_Price_Process_Service {

    const STATE_ERROR = 'con_error';
    const STATE_PENDING = 'pendiente';
    const STATE_INGRESANDO = 'ingresando';
    const STATE_INGRESADA = 'ingresada';
    const STATE_INGRESADA_MANUAL = 'ingresada_manual';
    const STATE_ANULADA = 'anulada';

    const COST_TOLERANCE = 0.005;

    private static $instance = null;

    /** @var array<string,array<int,true>> cache factura_id|canal → set de target_id confirmados */
    private $confirmed_cache = [];

    /** @var array<int,array{factura_id:int,folio:string,fecha:string,url:string}|null> */
    private $newest_folio_cache = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    private function table_proceso() {
        return $this->prefix() . 'precio_folio_proceso';
    }

    public static function estado_labels() {
        return [
            self::STATE_PENDING => 'Pendiente de ingreso',
            self::STATE_INGRESANDO => 'Ingresando',
            self::STATE_INGRESADA => 'Ingresada',
            self::STATE_INGRESADA_MANUAL => 'Ingresada anteriormente/manual',
            self::STATE_ANULADA => 'Anulada',
            self::STATE_ERROR => 'Con Error',
        ];
    }

    private function pricing() {
        return class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()
            : null;
    }

    private function unit_service() {
        if (!class_exists('Riverso_Unit_Product_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Riverso_Unit_Product_Service')
            ? Riverso_Unit_Product_Service::get_instance()
            : null;
    }

    private function rules() {
        if (!class_exists('Riverso_Price_Rules_Module')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'pricing/price_lists/class-price-rules-module.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Riverso_Price_Rules_Module')
            ? Riverso_Price_Rules_Module::get_instance()
            : null;
    }

    private function product_module() {
        if (!class_exists('Riverso_Product_Module')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Riverso_Product_Module')
            ? Riverso_Product_Module::get_instance()
            : null;
    }

    private function load_factura_product_items($factura_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $factura_id = absint($factura_id);

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            $factura_id
        ), ARRAY_A);

        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $subtipo = (string) ($factura['documento_subtipo'] ?? 'productos');
        if ($subtipo !== '' && $subtipo !== 'productos') {
            return new WP_Error('invalid_type', 'Solo facturas de productos');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items
             WHERE factura_id = %d
               AND (item_tipo = 'producto' OR item_tipo IS NULL OR item_tipo = '')
             ORDER BY numero_linea ASC",
            $factura_id
        ), ARRAY_A) ?: [];

        return ['factura' => $factura, 'items' => $items];
    }

    private function resolve_item_product(array $item, $proveedor_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $code = trim((string) ($item['codigo_proveedor'] ?? ''));
        $sku_local = trim((string) ($item['sku_local'] ?? ''));

        if ($sku_local !== '' && function_exists('riverso_sku_equals_supplier_code')
            && riverso_sku_equals_supplier_code($sku_local, $code)) {
            $sku_local = '';
        }

        $pb = null;
        if ($sku_local !== '') {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico, familia_decision, woocommerce_product_id
                 FROM {$prefix}producto_base
                 WHERE canonical_sku = %s AND deleted_at IS NULL
                 LIMIT 1",
                $sku_local
            ), ARRAY_A);
        }

        if (!$pb && $proveedor_id > 0 && $code !== '') {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, pb.familia_decision, pb.woocommerce_product_id
                 FROM {$prefix}producto_proveedor pp
                 INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                 WHERE pp.proveedor_id = %d AND pp.codigo_proveedor = %s AND pp.activo = 1
                 ORDER BY pp.es_preferido DESC, pp.id ASC
                 LIMIT 1",
                (int) $proveedor_id,
                $code
            ), ARRAY_A);
        }

        if (!$pb) {
            return null;
        }

        $sku = trim((string) ($pb['canonical_sku'] ?? ''));
        if ($sku === '' || (function_exists('riverso_sku_equals_supplier_code')
            && riverso_sku_equals_supplier_code($sku, $code))) {
            return null;
        }

        return [
            'producto_base_id' => (int) $pb['id'],
            'sku' => $sku,
            'nombre' => (string) ($pb['nombre_canonico'] ?? ''),
            'familia_decision' => (string) ($pb['familia_decision'] ?? ''),
            'woo_id' => (int) ($pb['woocommerce_product_id'] ?? 0),
        ];
    }

    private function resolve_price_target($producto_base_id) {
        $unit = $this->unit_service();
        $ctx = $unit ? $unit->resolve_family_unit_for_base($producto_base_id) : null;
        if (!$ctx || empty($ctx['unit_producto_base_id'])) {
            return [
                'target_id' => (int) $producto_base_id,
                'grupo_id' => 0,
                'is_child' => false,
                'unit_sku' => '',
                'es_familia_unitaria' => false,
            ];
        }
        $unit_id = (int) $ctx['unit_producto_base_id'];
        $is_child = $unit_id > 0 && $unit_id !== (int) $producto_base_id;
        $unit_sku = '';
        if ($unit_id > 0) {
            global $wpdb;
            $unit_sku = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_sku FROM {$this->prefix()}producto_base WHERE id = %d",
                $unit_id
            ));
        }
        return [
            'target_id' => $unit_id ?: (int) $producto_base_id,
            'grupo_id' => (int) ($ctx['grupo_id'] ?? 0),
            'is_child' => $is_child,
            'unit_sku' => $unit_sku,
            'es_familia_unitaria' => true,
        ];
    }

    /**
     * URL admin con from=precio-folio (+ args extra) para avisos en destino.
     */
    private function folio_guide_url($page, array $args = []) {
        $args = array_merge(['page' => $page, 'from' => 'precio-folio'], $args);
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Paso de playbook: label + url + hint (+ action in-page opcional).
     */
    private function playbook_step($label, $url, $hint = '', $action = '') {
        $step = [
            'label' => $label,
            'url' => $url,
            'hint' => $hint,
        ];
        if ($action !== '') {
            $step['action'] = $action;
        }
        return $step;
    }

    /**
     * Bloqueo SKU sin producto: cadena buscar → vincular → crear.
     */
    private function build_sku_blocker($factura_id, array $item, $code) {
        $item_id = (int) ($item['id'] ?? 0);
        $nombre = (string) ($item['nombre'] ?? $item['descripcion'] ?? '');
        $search = $code !== '' ? $code : $nombre;

        $search_url = $this->folio_guide_url('riverso-pos-products', [
            'need' => 'sku',
            'search' => $search,
            'codigo' => $code,
        ]);
        $invoice_url = $this->folio_guide_url('riverso-pos-invoices', [
            'factura' => absint($factura_id),
            'need' => 'sku',
            'codigo' => $code,
        ]);
        $create_local_url = $this->folio_guide_url('riverso-pos-products', [
            'need' => 'sku',
            'create' => 'local',
            'search' => $search,
            'codigo' => $code,
        ]);
        $create_online_url = $this->folio_guide_url('riverso-pos-products', [
            'need' => 'sku',
            'create' => 'online',
            'search' => $search,
            'codigo' => $code,
        ]);
        $codes_args = ['need' => 'sku', 'codigo' => $code];
        if ($item_id > 0) {
            $codes_args['factura_item'] = $item_id;
        }
        $codes_url = $this->folio_guide_url('riverso-pos-codes', $codes_args);

        $pasos = [
            $this->playbook_step(
                '1. Buscar y vincular producto existente',
                $search_url,
                'Buscá aquí por código proveedor, barcode o nombre y vinculá al folio. Atajo Hub disponible.',
                'search_link'
            ),
            $this->playbook_step(
                '2. Alternativa: vincular en la factura',
                $invoice_url,
                'Si preferís, abrí el folio en Facturas y asigná el SKU local al ítem.'
            ),
            $this->playbook_step(
                '3a. Si no existe y es solo tienda física: Nuevo producto local',
                $create_local_url,
                'Creá el producto local aquí mismo: se genera SKU numérico y se vincula al código. Atajo Hub disponible.',
                'create_local'
            ),
            $this->playbook_step(
                '3b. Si ya está (o debe estar) en Woo: Crear/Vincular online',
                $create_online_url,
                'Botón «Crear/Vincular online»: buscá Woo existente o creá Local+Online juntos.'
            ),
            $this->playbook_step(
                '4. Alternativa: bandeja Códigos',
                $codes_url,
                'Pendientes de código proveedor ↔ SKU (tarea codigo_faltante).'
            ),
        ];

        return [
            'tipo' => 'sku',
            'item_id' => $item_id,
            'codigo_proveedor' => $code,
            'nombre' => $nombre,
            'message' => 'Sin SKU asociado al código de proveedor'
                . ($code !== '' ? ' «' . $code . '»' : '')
                . ($nombre !== '' ? ' — ' . $nombre : ''),
            'url' => $search_url,
            'pasos' => $pasos,
        ];
    }

    /**
     * Bloqueo familia / tarea de familia.
     */
    private function build_familia_blocker($tipo, $factura_id, array $item, array $resolved, array $task = []) {
        $pb_id = (int) $resolved['producto_base_id'];
        $sku = (string) ($resolved['sku'] ?? '');
        $nombre = (string) ($resolved['nombre'] ?? '');
        $task_tipo = (string) ($task['tipo'] ?? 'preguntar_familia');
        $can_answer = ($task_tipo !== 'asignar_familia');

        $hub_url = function_exists('riverso_build_task_product_hub_url')
            ? riverso_build_task_product_hub_url($pb_id, $task_tipo, 'admin')
            : $this->folio_guide_url('riverso-pos-products', [
                'action' => 'detail',
                'id' => $pb_id,
                'tab' => 'local',
            ]);
        $hub_url = add_query_arg([
            'from' => 'precio-folio',
            'need' => 'familia',
            'codigo' => $sku,
        ], $hub_url);

        $tasks_url = $this->folio_guide_url('riverso-pos-tasks', [
            'need' => 'familia',
        ]);

        $message = !empty($task['titulo'])
            ? (string) $task['titulo']
            : ($tipo === 'familia_task'
                ? ('Tarea pendiente: ' . $task_tipo)
                : 'Falta decidir/asignar familia (no_requiere o familia)');

        $pasos = [];
        $step_n = 1;
        if ($can_answer) {
            $pasos[] = $this->playbook_step(
                $step_n++ . '. Responder aquí',
                '',
                'Respondé «¿Necesita familia?» sin salir de Procesar folios.',
                'answer_family'
            );
        }
        $pasos[] = $this->playbook_step(
            $step_n++ . '. Buscar familia existente',
            '',
            'Asigná el producto a una familia ya creada sin salir de Procesar folios.',
            'search_family'
        );
        $pasos[] = $this->playbook_step(
            $step_n++ . '. Crear familia nueva',
            '',
            'Creá la familia, asigná este producto y aplicá la regla R-1 por defecto.',
            'create_family'
        );
        $pasos[] = $this->playbook_step(
            $step_n++ . '. Abrir ficha del producto (tab Local)',
            $hub_url,
            $can_answer
                ? 'Atajo al Hub si preferís responder allá o gestionar packs/unitario.'
                : 'Atajo al Hub si preferís asignar familia allá o gestionar packs/unitario.'
        );
        $pasos[] = $this->playbook_step(
            $step_n++ . '. Ver tarea en la bandeja',
            $tasks_url,
            'Las tareas preguntar_familia / asignar_familia deben quedar completadas.'
        );
        $pasos[] = $this->playbook_step(
            $step_n++ . '. Volver a Procesar folios y actualizar',
            $this->folio_guide_url('riverso-pos-pricing', []),
            'Pulsá «Ya resolví — actualizar» para que salga el ticket de error.'
        );

        $blocker = [
            'tipo' => $tipo,
            'item_id' => (int) ($item['id'] ?? 0),
            'producto_base_id' => $pb_id,
            'sku' => $sku,
            'nombre' => $nombre,
            'can_answer_family' => $can_answer,
            'can_assign_family' => true,
            'message' => $message,
            'url' => $hub_url,
            'pasos' => $pasos,
        ];
        if (!empty($task['id'])) {
            $blocker['task_id'] = (int) $task['id'];
            $blocker['task_tipo'] = $task_tipo;
        }
        return $blocker;
    }

    public function evaluate_gates($factura_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return [
                'ok' => false,
                'blockers' => [[
                    'tipo' => 'factura',
                    'message' => $loaded->get_error_message(),
                    'url' => $this->folio_guide_url('riverso-pos-invoices', [
                        'factura' => absint($factura_id),
                    ]),
                    'pasos' => [],
                ]],
                'products' => [],
            ];
        }

        $factura = $loaded['factura'];
        $proveedor_id = (int) ($factura['proveedor_id'] ?? 0);
        $blockers = [];
        $products = [];
        $seen_targets = [];
        $omitted = $this->omitted_item_ids($factura_id);

        foreach ($loaded['items'] as $item) {
            $item_id = (int) ($item['id'] ?? 0);
            if ($item_id > 0 && isset($omitted[$item_id])) {
                continue;
            }

            $code = trim((string) ($item['codigo_proveedor'] ?? ''));
            $resolved = $this->resolve_item_product($item, $proveedor_id);
            if (!$resolved) {
                $blockers[] = $this->build_sku_blocker($factura_id, $item, $code);
                continue;
            }

            $pb_id = $resolved['producto_base_id'];
            $target = $this->resolve_price_target($pb_id);
            $target_id = (int) $target['target_id'];

            $pm = $this->product_module();
            $has_family = $pm ? $pm->product_has_family($pb_id) : false;
            $decision = $resolved['familia_decision'];

            $open_tasks = $wpdb->get_results($wpdb->prepare(
                "SELECT id, tipo, titulo FROM {$prefix}tareas
                 WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
                   AND tipo IN ('preguntar_familia', 'asignar_familia')
                   AND estado NOT IN ('completada', 'cancelada')",
                $pb_id
            ), ARRAY_A) ?: [];

            if ($target_id !== $pb_id) {
                $more = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, tipo, titulo FROM {$prefix}tareas
                     WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
                       AND tipo IN ('preguntar_familia', 'asignar_familia')
                       AND estado NOT IN ('completada', 'cancelada')",
                    $target_id
                ), ARRAY_A) ?: [];
                $open_tasks = array_merge($open_tasks, $more);
            }

            if ($open_tasks) {
                foreach ($open_tasks as $t) {
                    $blockers[] = $this->build_familia_blocker('familia_task', $factura_id, $item, $resolved, $t);
                }
            } elseif (!$has_family && $decision !== 'no_requiere') {
                $blockers[] = $this->build_familia_blocker('familia', $factura_id, $item, $resolved);
            }

            if (!isset($seen_targets[$target_id])) {
                $seen_targets[$target_id] = true;
                $products[] = [
                    'item_id' => (int) $item['id'],
                    'producto_base_id' => $pb_id,
                    'target_id' => $target_id,
                    'sku' => $resolved['sku'],
                    'nombre' => $resolved['nombre'],
                    'woo_id' => $resolved['woo_id'],
                    'grupo_id' => $target['grupo_id'],
                    'es_familia_unitaria' => !empty($target['es_familia_unitaria']),
                    'is_child' => !empty($target['is_child']),
                    'unit_sku' => $target['unit_sku'],
                ];
            }
        }

        return [
            'ok' => empty($blockers),
            'blockers' => $blockers,
            'products' => $products,
            'omitted_count' => count($omitted),
        ];
    }

    /**
     * IDs de ítems marcados como ya ingresados (ingreso híbrido).
     *
     * @return array<int, true> mapa item_id => true
     */
    private function omitted_item_ids($factura_id) {
        $proceso = $this->get_proceso_row($factura_id);
        if (!$proceso || !array_key_exists('items_omitidos_json', $proceso)
            || $proceso['items_omitidos_json'] === null
            || $proceso['items_omitidos_json'] === ''
        ) {
            return [];
        }
        $decoded = json_decode((string) $proceso['items_omitidos_json'], true);
        if (!is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $id) {
            $id = absint($id);
            if ($id > 0) {
                $map[$id] = true;
            }
        }
        return $map;
    }

    /**
     * True si el folio está en modo ingreso híbrido (JSON presente, aunque sea []).
     */
    private function is_hybrid_mode($factura_id) {
        $proceso = $this->get_proceso_row($factura_id);
        if (!$proceso || !array_key_exists('items_omitidos_json', $proceso)) {
            return false;
        }
        return $proceso['items_omitidos_json'] !== null && $proceso['items_omitidos_json'] !== '';
    }

    /**
     * Normaliza y valida IDs de ítems pertenecientes al folio.
     *
     * @param int[] $item_ids
     * @return int[]|WP_Error
     */
    private function sanitize_omit_item_ids($factura_id, array $item_ids) {
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return $loaded;
        }
        $valid = [];
        foreach ($loaded['items'] as $item) {
            $valid[(int) $item['id']] = true;
        }
        $out = [];
        foreach ($item_ids as $id) {
            $id = absint($id);
            if ($id > 0 && isset($valid[$id])) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * Actualiza el conjunto de ítems ya ingresados (híbrido) y recalcula la sesión.
     * No completa el folio automáticamente: el usuario confirma con «Marcar ingresada».
     *
     * @param int[] $item_ids_ingresados
     * @return array|WP_Error
     */
    public function update_hybrid_omitidos($factura_id, array $item_ids_ingresados) {
        $factura_id = absint($factura_id);

        $sanitized = $this->sanitize_omit_item_ids($factura_id, $item_ids_ingresados);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $this->upsert_proceso($factura_id, [
            'estado_manual' => null,
            'items_omitidos_json' => wp_json_encode($sanitized),
            'completed_at' => null,
        ]);

        $resolved = $this->resolve_estado($factura_id);
        if (!empty($resolved['can_process'])
            && in_array($resolved['estado'], [self::STATE_PENDING, self::STATE_INGRESANDO], true)
        ) {
            $this->upsert_proceso($factura_id, [
                'estado' => self::STATE_INGRESANDO,
                'estado_manual' => null,
            ]);
        }

        $session = $this->get_session($factura_id);
        if (is_wp_error($session)) {
            return $session;
        }

        return ['session' => $session];
    }

    /**
     * True si A es más reciente que B (fecha_emision, empate por factura_id).
     *
     * @param string $fecha_a
     * @param int    $id_a
     * @param string $fecha_b
     * @param int    $id_b
     * @return bool
     */
    private function folio_is_newer($fecha_a, $id_a, $fecha_b, $id_b) {
        $fa = substr(trim((string) $fecha_a), 0, 10);
        $fb = substr(trim((string) $fecha_b), 0, 10);
        if ($fa !== '' && $fb !== '' && $fa !== $fb) {
            return $fa > $fb;
        }
        if ($fa !== '' && $fb === '') {
            return true;
        }
        if ($fa === '' && $fb !== '') {
            return false;
        }
        return (int) $id_a > (int) $id_b;
    }

    /**
     * Targets confirmados desde cada folio (historial source_type=folio).
     *
     * @param int[]  $factura_ids
     * @param string $canal
     * @return array<int,array<int,true>> factura_id => set target_id
     */
    private function confirmed_targets_by_folios(array $factura_ids, $canal = 'local') {
        global $wpdb;
        $canal = $canal === 'online' ? 'online' : 'local';
        $ids = [];
        $out = [];
        foreach ($factura_ids as $fid) {
            $fid = absint($fid);
            if ($fid <= 0) {
                continue;
            }
            $cache_key = $fid . '|' . $canal;
            if (isset($this->confirmed_cache[$cache_key])) {
                $out[$fid] = $this->confirmed_cache[$cache_key];
                continue;
            }
            $ids[$fid] = $fid;
            $out[$fid] = [];
        }
        if (!$ids) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', array_values($ids)));
        $prefix = $this->prefix();
        $rows = $wpdb->get_results(
            "SELECT DISTINCT source_document_id AS factura_id, producto_base_id AS target_id
             FROM {$prefix}precio_historial
             WHERE source_type = 'folio'
               AND canal = '{$canal}'
               AND source_document_id IN ({$id_list})
               AND p_asignado_nuevo IS NOT NULL",
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $fid = (int) ($row['factura_id'] ?? 0);
            $tid = (int) ($row['target_id'] ?? 0);
            if ($fid > 0 && $tid > 0) {
                $out[$fid][$tid] = true;
            }
        }

        foreach ($ids as $fid) {
            $this->confirmed_cache[$fid . '|' . $canal] = $out[$fid] ?? [];
        }

        return $out;
    }

    /**
     * Folio confirmado más reciente por target (fecha_emision DESC, factura_id DESC).
     *
     * @param int[]  $target_ids
     * @param string $canal
     * @return array<int,array{factura_id:int,folio:string,fecha:string,url:string}>
     */
    private function newest_applied_folio_by_targets(array $target_ids, $canal = 'local') {
        global $wpdb;
        $canal = $canal === 'online' ? 'online' : 'local';
        $ids = [];
        $out = [];
        foreach ($target_ids as $tid) {
            $tid = absint($tid);
            if ($tid <= 0) {
                continue;
            }
            if (array_key_exists($tid, $this->newest_folio_cache)) {
                if ($this->newest_folio_cache[$tid] !== null) {
                    $out[$tid] = $this->newest_folio_cache[$tid];
                }
                continue;
            }
            $ids[$tid] = $tid;
        }
        if (!$ids) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', array_values($ids)));
        $prefix = $this->prefix();
        $rows = $wpdb->get_results(
            "SELECT h.producto_base_id AS target_id, h.source_document_id AS factura_id,
                    f.folio, f.fecha_emision
             FROM {$prefix}precio_historial h
             INNER JOIN {$prefix}facturas f ON f.id = h.source_document_id
             WHERE h.source_type = 'folio'
               AND h.canal = '{$canal}'
               AND h.producto_base_id IN ({$id_list})
               AND h.source_document_id IS NOT NULL
               AND h.p_asignado_nuevo IS NOT NULL
             ORDER BY h.producto_base_id ASC, f.fecha_emision DESC, f.id DESC, h.id DESC",
            ARRAY_A
        ) ?: [];

        $seen = [];
        foreach ($rows as $row) {
            $tid = (int) ($row['target_id'] ?? 0);
            if ($tid <= 0 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $fid = (int) ($row['factura_id'] ?? 0);
            $pack = [
                'factura_id' => $fid,
                'folio' => (string) ($row['folio'] ?? ''),
                'fecha' => substr((string) ($row['fecha_emision'] ?? ''), 0, 10),
                'url' => $fid > 0 ? $this->prior_folio_url($fid) : '',
            ];
            $out[$tid] = $pack;
            $this->newest_folio_cache[$tid] = $pack;
        }
        foreach ($ids as $tid) {
            if (!isset($seen[$tid])) {
                $this->newest_folio_cache[$tid] = null;
            }
        }

        return $out;
    }

    /**
     * Invalida caches de confirmación/recencia tras un save.
     *
     * @param int $factura_id
     * @param int $target_id
     */
    private function invalidate_confirmation_caches($factura_id, $target_id = 0) {
        $factura_id = absint($factura_id);
        $target_id = absint($target_id);
        unset(
            $this->confirmed_cache[$factura_id . '|local'],
            $this->confirmed_cache[$factura_id . '|online']
        );
        if ($target_id > 0) {
            unset($this->newest_folio_cache[$target_id]);
        } else {
            $this->newest_folio_cache = [];
        }
    }

    /**
     * Progreso de confirmación de precios por filas de producto del folio.
     * N = ítems producto; n = filas confirmadas desde este folio (o híbrido omitido).
     *
     * @param int        $factura_id
     * @param array|null $gates resultado opcional de evaluate_gates (evita trabajo extra)
     * @return array{saved:int,total:int,status:string}
     */
    public function count_folio_price_progress($factura_id, $gates = null) {
        $factura_id = absint($factura_id);
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return ['saved' => 0, 'total' => 0, 'status' => 'ninguno'];
        }

        $omitted = $this->omitted_item_ids($factura_id);
        $proveedor_id = (int) ($loaded['factura']['proveedor_id'] ?? 0);
        $confirmed_map = $this->confirmed_targets_by_folios([$factura_id], 'local');
        $confirmed = $confirmed_map[$factura_id] ?? [];

        // Targets de gates (ya resueltos) → confirmados en este folio.
        $confirmed_targets = [];
        if (is_array($gates) && !empty($gates['products'])) {
            foreach ($gates['products'] as $p) {
                $tid = (int) ($p['target_id'] ?? 0);
                if ($tid > 0) {
                    $confirmed_targets[$tid] = isset($confirmed[$tid]);
                }
            }
        }

        $total = 0;
        $saved = 0;
        foreach ($loaded['items'] as $item) {
            $item_id = (int) ($item['id'] ?? 0);
            $total++;

            // Híbrido: fila marcada como ya ingresada cuenta como confirmada.
            if ($item_id > 0 && isset($omitted[$item_id])) {
                $saved++;
                continue;
            }

            $resolved = $this->resolve_item_product($item, $proveedor_id);
            if (!$resolved) {
                continue;
            }
            $pb_id = (int) $resolved['producto_base_id'];
            $target = $this->resolve_price_target($pb_id);
            $target_id = (int) ($target['target_id'] ?? 0);
            if ($target_id <= 0) {
                continue;
            }

            if (array_key_exists($target_id, $confirmed_targets)) {
                if ($confirmed_targets[$target_id]) {
                    $saved++;
                }
                continue;
            }
            $has = isset($confirmed[$target_id]);
            $confirmed_targets[$target_id] = $has;
            if ($has) {
                $saved++;
            }
        }

        if ($total <= 0) {
            return ['saved' => 0, 'total' => 0, 'status' => 'ninguno'];
        }
        if ($saved <= 0) {
            $status = 'ninguno';
        } elseif ($saved >= $total) {
            $status = 'completo';
        } else {
            $status = 'parcial';
        }
        return [
            'saved' => $saved,
            'total' => $total,
            'status' => $status,
        ];
    }

    /**
     * @deprecated Preferir count_folio_price_progress; se mantiene por compatibilidad.
     * @param array $products targets únicos de evaluate_gates
     * @return array{saved:int,total:int,status:string}
     */
    public function count_price_progress(array $products) {
        $total = count($products);
        if ($total === 0) {
            return ['saved' => 0, 'total' => 0, 'status' => 'ninguno'];
        }
        $pricing = $this->pricing();
        if (!$pricing) {
            return ['saved' => 0, 'total' => $total, 'status' => 'ninguno'];
        }
        $saved = 0;
        foreach ($products as $p) {
            $target_id = (int) ($p['target_id'] ?? 0);
            if ($target_id <= 0) {
                continue;
            }
            $local = $pricing->get_local_price($target_id);
            if ($local && $local['p_asignado'] !== null && $local['p_asignado'] !== '') {
                $saved++;
            }
        }
        if ($saved <= 0) {
            $status = 'ninguno';
        } elseif ($saved >= $total) {
            $status = 'completo';
        } else {
            $status = 'parcial';
        }
        return [
            'saved' => $saved,
            'total' => $total,
            'status' => $status,
        ];
    }

    /**
     * True si todos los targets pendientes tienen confirmación de precio desde este folio.
     *
     * @param array $products targets únicos de evaluate_gates
     * @param int   $factura_id
     * @return bool
     */
    private function prices_complete(array $products, $factura_id) {
        $factura_id = absint($factura_id);
        // Sin targets pendientes (p. ej. todos omitidos en híbrido) → completo.
        if (empty($products)) {
            return true;
        }
        $local_map = $this->confirmed_targets_by_folios([$factura_id], 'local');
        $online_map = $this->confirmed_targets_by_folios([$factura_id], 'online');
        $confirmed_local = $local_map[$factura_id] ?? [];
        $confirmed_online = $online_map[$factura_id] ?? [];

        global $wpdb;
        foreach ($products as $p) {
            $target_id = (int) ($p['target_id'] ?? 0);
            if ($target_id <= 0) {
                return false;
            }
            if (empty($confirmed_local[$target_id])) {
                return false;
            }
            $woo = (int) ($p['woo_id'] ?? 0);
            if ($woo <= 0) {
                $woo = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT woocommerce_product_id FROM {$this->prefix()}producto_base WHERE id = %d",
                    $target_id
                ));
            }
            if ($woo > 0 && empty($confirmed_online[$target_id])) {
                return false;
            }
        }
        return true;
    }

    private function get_proceso_row($factura_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_proceso()} WHERE factura_id = %d",
            absint($factura_id)
        ), ARRAY_A);
    }

    private function upsert_proceso($factura_id, array $data) {
        global $wpdb;
        $factura_id = absint($factura_id);
        $existing = $this->get_proceso_row($factura_id);
        if ($existing) {
            $wpdb->update($this->table_proceso(), $data, ['factura_id' => $factura_id]);
            return (int) $existing['id'];
        }
        $data['factura_id'] = $factura_id;
        if (empty($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        $wpdb->insert($this->table_proceso(), $data);
        return (int) $wpdb->insert_id;
    }

    public function resolve_estado($factura_id) {
        $factura_id = absint($factura_id);
        $proceso = $this->get_proceso_row($factura_id);
        $manual = $proceso['estado_manual'] ?? null;
        $is_archived = !empty($proceso['archived_at']);
        $gates = $this->evaluate_gates($factura_id);
        $progress = $this->count_folio_price_progress($factura_id, $gates);

        if ($manual === self::STATE_ANULADA || $manual === self::STATE_INGRESADA_MANUAL) {
            $labels = self::estado_labels();
            return [
                'estado' => $manual,
                'label' => $labels[$manual] ?? $manual,
                'auto' => '',
                'blockers' => [],
                'can_process' => false,
                'is_manual' => true,
                'is_archived' => $is_archived,
                'archived_at' => $proceso['archived_at'] ?? null,
                'progress' => $progress,
            ];
        }

        global $wpdb;
        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT id, estado, tipo_dte, documento_subtipo FROM {$this->prefix()}facturas WHERE id = %d",
            $factura_id
        ), ARRAY_A);

        $hybrid = $this->is_hybrid_mode($factura_id);
        $prices_ok = $this->prices_complete($gates['products'], $factura_id);

        if (!$gates['ok']) {
            $auto = self::STATE_ERROR;
        } elseif ($hybrid) {
            // Híbrido: permanece en ingresando hasta confirmación explícita (botón Completar).
            $auto = self::STATE_INGRESANDO;
        } elseif ($proceso && ($proceso['estado'] ?? '') === self::STATE_INGRESANDO) {
            $auto = $prices_ok ? self::STATE_INGRESADA : self::STATE_INGRESANDO;
        } elseif ($prices_ok) {
            $auto = self::STATE_INGRESADA;
        } else {
            $auto = self::STATE_PENDING;
        }

        $this->upsert_proceso($factura_id, [
            'estado' => $auto,
            'blockers_json' => wp_json_encode($gates['blockers']),
            'completed_at' => $auto === self::STATE_INGRESADA ? current_time('mysql') : null,
        ]);

        $labels = self::estado_labels();
        return [
            'estado' => $auto,
            'label' => $labels[$auto] ?? $auto,
            'auto' => $auto,
            'blockers' => $gates['blockers'],
            'can_process' => !$is_archived && in_array($auto, [self::STATE_PENDING, self::STATE_INGRESANDO], true),
            'is_manual' => false,
            'is_archived' => $is_archived,
            'archived_at' => $proceso['archived_at'] ?? null,
            'progress' => $progress,
            'factura_estado' => $factura['estado'] ?? '',
            'suggest_anulada' => in_array(($factura['estado'] ?? ''), ['rechazado', 'rejected'], true)
                || (int) ($factura['tipo_dte'] ?? 0) === 61,
        ];
    }

    public function list_folios(array $args = []) {
        global $wpdb;
        $prefix = $this->prefix();
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(10, min(100, (int) ($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        $estado_filter = sanitize_key($args['estado'] ?? '');
        $completitud_filter = sanitize_key($args['completitud'] ?? '');
        if (!in_array($completitud_filter, ['', 'completo', 'parcial', 'ninguno'], true)) {
            $completitud_filter = '';
        }
        $search = trim((string) ($args['search'] ?? ''));
        $producto = trim((string) ($args['producto'] ?? ''));
        $proveedor_id = absint($args['proveedor_id'] ?? 0);
        $fecha_folio_desde = $this->sanitize_ymd($args['fecha_folio_desde'] ?? '');
        $fecha_folio_hasta = $this->sanitize_ymd($args['fecha_folio_hasta'] ?? '');
        $fecha_ingreso_desde = $this->sanitize_ymd($args['fecha_ingreso_desde'] ?? '');
        $fecha_ingreso_hasta = $this->sanitize_ymd($args['fecha_ingreso_hasta'] ?? '');
        $vista = sanitize_key($args['vista'] ?? 'activos');
        if (!in_array($vista, ['activos', 'archivados'], true)) {
            $vista = 'activos';
        }
        $orderby = sanitize_key($args['orderby'] ?? 'fecha_folio');
        if (!in_array($orderby, ['fecha_folio', 'fecha_ingreso'], true)) {
            $orderby = 'fecha_folio';
        }
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = [
            'f.tipo_dte IN (33, 34)',
            "(f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')",
        ];
        $params = [];

        if ($vista === 'archivados') {
            $where[] = 'pr.archived_at IS NOT NULL';
        } else {
            $where[] = '(pr.archived_at IS NULL OR pr.id IS NULL)';
        }

        if ($proveedor_id > 0) {
            $where[] = 'f.proveedor_id = %d';
            $params[] = $proveedor_id;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(f.folio LIKE %s OR p.nombre LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($fecha_folio_desde !== '') {
            $where[] = 'DATE(f.fecha_emision) >= %s';
            $params[] = $fecha_folio_desde;
        }
        if ($fecha_folio_hasta !== '') {
            $where[] = 'DATE(f.fecha_emision) <= %s';
            $params[] = $fecha_folio_hasta;
        }
        if ($fecha_ingreso_desde !== '') {
            $where[] = 'DATE(f.created_at) >= %s';
            $params[] = $fecha_ingreso_desde;
        }
        if ($fecha_ingreso_hasta !== '') {
            $where[] = 'DATE(f.created_at) <= %s';
            $params[] = $fecha_ingreso_hasta;
        }
        if ($producto !== '') {
            $plike = '%' . $wpdb->esc_like($producto) . '%';
            $where[] = "EXISTS (
                SELECT 1
                FROM {$prefix}factura_items fi
                LEFT JOIN {$prefix}producto_base pb_sku
                    ON pb_sku.canonical_sku = fi.sku_local AND pb_sku.deleted_at IS NULL
                LEFT JOIN {$prefix}producto_proveedor pp
                    ON pp.proveedor_id = f.proveedor_id
                   AND pp.codigo_proveedor = fi.codigo_proveedor
                   AND pp.activo = 1
                LEFT JOIN {$prefix}producto_base pb_pp
                    ON pb_pp.id = pp.producto_base_id AND pb_pp.deleted_at IS NULL
                WHERE fi.factura_id = f.id
                  AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL OR fi.item_tipo = '')
                  AND (
                    fi.sku_local LIKE %s
                    OR fi.codigo_proveedor LIKE %s
                    OR fi.nombre LIKE %s
                    OR fi.descripcion LIKE %s
                    OR pb_sku.canonical_sku LIKE %s
                    OR pb_sku.nombre_canonico LIKE %s
                    OR pb_pp.canonical_sku LIKE %s
                    OR pb_pp.nombre_canonico LIKE %s
                    OR EXISTS (
                        SELECT 1 FROM {$prefix}codigo_barra cb
                        WHERE cb.activo = 1
                          AND cb.estado = 'verificado'
                          AND cb.codigo LIKE %s
                          AND (
                            (pb_sku.id IS NOT NULL AND cb.producto_base_id = pb_sku.id)
                            OR (pb_pp.id IS NOT NULL AND cb.producto_base_id = pb_pp.id)
                          )
                    )
                  )
            )";
            for ($i = 0; $i < 9; $i++) {
                $params[] = $plike;
            }
        }

        $order_sql = ($orderby === 'fecha_ingreso')
            ? "f.created_at {$order}, f.id DESC"
            : "f.fecha_emision {$order}, f.id DESC";

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT f.id, f.folio, f.fecha_emision, f.proveedor_id, f.estado AS factura_estado,
                       f.monto_total, f.created_at, p.nombre AS proveedor_nombre,
                       pr.estado AS proceso_estado, pr.estado_manual, pr.started_at, pr.completed_at,
                       pr.items_omitidos_json, pr.archived_at, pr.archived_by
                FROM {$prefix}facturas f
                LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
                LEFT JOIN {$this->table_proceso()} pr ON pr.factura_id = f.id
                WHERE {$where_sql}
                ORDER BY {$order_sql}
                LIMIT 200";
        $candidates = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        $candidates = $candidates ?: [];

        // Precarga batch de confirmaciones (evita N+1 en resolve_estado / progress).
        $candidate_ids = [];
        foreach ($candidates as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid > 0) {
                $candidate_ids[] = $cid;
            }
        }
        if ($candidate_ids) {
            $this->confirmed_targets_by_folios($candidate_ids, 'local');
            $this->confirmed_targets_by_folios($candidate_ids, 'online');
        }

        $rows = [];
        $counts = array_fill_keys(array_keys(self::estado_labels()), 0);

        foreach ($candidates as $c) {
            $resolved = $this->resolve_estado((int) $c['id']);
            $estado = $resolved['estado'];
            if (isset($counts[$estado])) {
                $counts[$estado]++;
            }
            if ($estado_filter !== '' && $estado !== $estado_filter) {
                continue;
            }
            $omitidos = [];
            if (!empty($c['items_omitidos_json'])) {
                $decoded = json_decode((string) $c['items_omitidos_json'], true);
                if (is_array($decoded)) {
                    $omitidos = array_values(array_filter(array_map('absint', $decoded)));
                }
            }
            $progress = $resolved['progress'] ?? ['saved' => 0, 'total' => 0, 'status' => 'ninguno'];
            $progress_status = (string) ($progress['status'] ?? 'ninguno');
            if ($completitud_filter !== '' && $progress_status !== $completitud_filter) {
                continue;
            }
            $rows[] = [
                'factura_id' => (int) $c['id'],
                'folio' => $c['folio'],
                'fecha_emision' => $c['fecha_emision'],
                'created_at' => $c['created_at'],
                'proveedor_id' => (int) $c['proveedor_id'],
                'proveedor_nombre' => $c['proveedor_nombre'],
                'monto_total' => $c['monto_total'] !== null ? (float) $c['monto_total'] : null,
                'factura_estado' => $c['factura_estado'],
                'estado' => $estado,
                'label' => $resolved['label'],
                'blockers_count' => count($resolved['blockers']),
                'can_process' => !empty($resolved['can_process']),
                'is_manual' => !empty($resolved['is_manual']),
                'is_archived' => !empty($resolved['is_archived']),
                'archived_at' => $resolved['archived_at'] ?? ($c['archived_at'] ?? null),
                'is_hybrid' => $this->is_hybrid_mode((int) $c['id']),
                'omitted_count' => count($omitidos),
                'suggest_anulada' => !empty($resolved['suggest_anulada']),
                'started_at' => $c['started_at'],
                'completed_at' => $c['completed_at'],
                'progress_saved' => (int) ($progress['saved'] ?? 0),
                'progress_total' => (int) ($progress['total'] ?? 0),
                'progress_status' => $progress_status,
            ];
        }

        $total = count($rows);
        $page_rows = array_slice($rows, $offset, $per_page);

        $archived_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$this->table_proceso()} pr
             INNER JOIN {$prefix}facturas f ON f.id = pr.factura_id
             WHERE pr.archived_at IS NOT NULL
               AND f.tipo_dte IN (33, 34)
               AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')"
        );

        return [
            'rows' => $page_rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => max(1, (int) ceil($total / $per_page)),
            'counts' => $counts,
            'labels' => self::estado_labels(),
            'vista' => $vista,
            'archived_count' => $archived_count,
            'completitud' => $completitud_filter,
            'orderby' => $orderby,
            'order' => $order,
        ];
    }

    /**
     * @param mixed $value
     * @return string Y-m-d o ''
     */
    private function sanitize_ymd($value) {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        return $value;
    }

    public function start_process($factura_id) {
        $factura_id = absint($factura_id);
        $resolved = $this->resolve_estado($factura_id);
        if (!empty($resolved['is_archived'])) {
            return new WP_Error('archived', 'El folio está archivado. Desarchívalo para continuar.');
        }
        if ($resolved['estado'] === self::STATE_ERROR) {
            return new WP_Error('blocked', 'El folio tiene errores (SKU/familia). Resuélvelos antes de procesar.', [
                'blockers' => $resolved['blockers'],
            ]);
        }
        if (in_array($resolved['estado'], [self::STATE_ANULADA, self::STATE_INGRESADA_MANUAL], true)) {
            return new WP_Error('manual', 'El folio está marcado manualmente y no se puede procesar.');
        }
        if ($resolved['estado'] === self::STATE_INGRESADA) {
            return new WP_Error('done', 'El folio ya está ingresado.');
        }

        $this->upsert_proceso($factura_id, [
            'estado' => self::STATE_INGRESANDO,
            'estado_manual' => null,
            'started_by' => get_current_user_id() ?: null,
            'started_at' => current_time('mysql'),
            'completed_at' => null,
            'blockers_json' => wp_json_encode([]),
        ]);

        return true;
    }

    /**
     * Archiva un folio ingresado (automático o manual).
     *
     * @return true|WP_Error
     */
    public function archive_folio($factura_id) {
        $factura_id = absint($factura_id);
        $resolved = $this->resolve_estado($factura_id);
        if (!empty($resolved['is_archived'])) {
            return new WP_Error('already', 'El folio ya está archivado.');
        }
        if (!in_array($resolved['estado'], [self::STATE_INGRESADA, self::STATE_INGRESADA_MANUAL], true)) {
            return new WP_Error('invalid', 'Solo se pueden archivar folios Ingresada o Ingresada manual.');
        }

        $this->upsert_proceso($factura_id, [
            'archived_at' => current_time('mysql'),
            'archived_by' => get_current_user_id() ?: null,
        ]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('folio_archive', 'factura', $factura_id, [
                'actor_type' => 'human',
                'estado' => $resolved['estado'],
            ]);
        }

        return true;
    }

    /**
     * Quita el archivo; el folio vuelve a la lista activa con su estado.
     *
     * @return true|WP_Error
     */
    public function unarchive_folio($factura_id) {
        $factura_id = absint($factura_id);
        $proceso = $this->get_proceso_row($factura_id);
        if (!$proceso || empty($proceso['archived_at'])) {
            return new WP_Error('not_archived', 'El folio no está archivado.');
        }

        $this->upsert_proceso($factura_id, [
            'archived_at' => null,
            'archived_by' => null,
        ]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('folio_unarchive', 'factura', $factura_id, [
                'actor_type' => 'human',
            ]);
        }

        return true;
    }

    public function set_estado_manual($factura_id, $estado, $notas = '') {
        $factura_id = absint($factura_id);
        $allowed = [self::STATE_INGRESADA_MANUAL, self::STATE_ANULADA, ''];
        if (!in_array($estado, $allowed, true)) {
            return new WP_Error('invalid', 'Estado manual no permitido');
        }

        if ($estado === '') {
            $this->upsert_proceso($factura_id, [
                'estado_manual' => null,
                'items_omitidos_json' => null,
                'notas' => sanitize_textarea_field($notas),
            ]);
            $this->resolve_estado($factura_id);
            return true;
        }

        $this->upsert_proceso($factura_id, [
            'estado' => $estado,
            'estado_manual' => $estado,
            'items_omitidos_json' => null,
            'notas' => sanitize_textarea_field($notas),
            'completed_at' => current_time('mysql'),
        ]);
        return true;
    }

    /**
     * Líneas livianas del folio para el modal de ingreso híbrido.
     */
    public function get_hybrid_items($factura_id) {
        $factura_id = absint($factura_id);
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return $loaded;
        }
        $omitted = $this->omitted_item_ids($factura_id);
        $lines = [];
        foreach ($loaded['items'] as $item) {
            $item_id = (int) $item['id'];
            $lines[] = [
                'item_id' => $item_id,
                'numero_linea' => (int) ($item['numero_linea'] ?? 0),
                'codigo_proveedor' => trim((string) ($item['codigo_proveedor'] ?? '')),
                'descripcion' => (string) ($item['nombre'] ?? $item['descripcion'] ?? ''),
                'cantidad' => (float) ($item['cantidad'] ?? 0),
                'ya_ingresada' => isset($omitted[$item_id]),
            ];
        }
        return [
            'factura_id' => $factura_id,
            'folio' => $loaded['factura']['folio'] ?? '',
            'proveedor_nombre' => $loaded['factura']['proveedor_nombre'] ?? '',
            'lines' => $lines,
        ];
    }

    /**
     * Inicio de ingreso híbrido: marca ítems ya ingresados y abre sesión filtrada.
     *
     * @param int[] $item_ids_ingresados
     * @return array|WP_Error sesión o error
     */
    public function start_hybrid($factura_id, array $item_ids_ingresados) {
        $factura_id = absint($factura_id);
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return $loaded;
        }

        $sanitized = $this->sanitize_omit_item_ids($factura_id, $item_ids_ingresados);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $all_ids = [];
        foreach ($loaded['items'] as $item) {
            $all_ids[] = (int) $item['id'];
        }
        if (empty($all_ids)) {
            return new WP_Error('empty', 'El folio no tiene ítems de producto');
        }

        // Todas las filas marcadas → folio completamente procesado.
        if (count($sanitized) >= count($all_ids)) {
            $this->set_estado_manual($factura_id, self::STATE_INGRESADA_MANUAL, 'Ingreso híbrido: todas las filas ya ingresadas');
            return [
                'completed_manual' => true,
                'session' => null,
            ];
        }

        $this->upsert_proceso($factura_id, [
            'estado_manual' => null,
            'items_omitidos_json' => wp_json_encode($sanitized),
            'completed_at' => null,
            'started_by' => get_current_user_id() ?: null,
            'started_at' => current_time('mysql'),
        ]);

        $resolved = $this->resolve_estado($factura_id);
        if (!empty($resolved['can_process'])
            && in_array($resolved['estado'], [self::STATE_PENDING, self::STATE_INGRESANDO], true)
        ) {
            $this->upsert_proceso($factura_id, [
                'estado' => self::STATE_INGRESANDO,
                'estado_manual' => null,
                'blockers_json' => wp_json_encode([]),
            ]);
        }

        $session = $this->get_session($factura_id);
        if (is_wp_error($session)) {
            return $session;
        }

        return [
            'completed_manual' => false,
            'session' => $session,
        ];
    }

    private function unit_cost_from_item(array $item) {
        if (class_exists('Riverso_Cost_Lookup_Service') && method_exists('Riverso_Cost_Lookup_Service', 'unit_cost_from_item')) {
            return Riverso_Cost_Lookup_Service::unit_cost_from_item($item);
        }
        if (isset($item['costo_landed_unitario']) && $item['costo_landed_unitario'] !== null && $item['costo_landed_unitario'] !== '') {
            return round((float) $item['costo_landed_unitario'], 4);
        }
        if (isset($item['costo_neto_final']) && $item['costo_neto_final'] !== null && $item['costo_neto_final'] !== '') {
            $qty = (float) ($item['cantidad'] ?? 0);
            if ($qty > 0) {
                return round((float) $item['costo_neto_final'] / $qty, 4);
            }
        }
        if (isset($item['precio_unitario']) && $item['precio_unitario'] !== null) {
            return round((float) $item['precio_unitario'], 4);
        }
        return null;
    }

    /**
     * Bases de costo unitario neto: referencia, tras D/R fila, tras D/R folio, tras D/R+flete.
     *
     * @return array{referencia:?float,tras_dr:?float,tras_dr_folio:?float,tras_dr_flete:?float,flete_ok:bool}
     */
    private function unit_cost_bases_from_item(array $item, $flete_ok = false) {
        $qty = (float) ($item['cantidad'] ?? 0);
        if ($qty <= 0) {
            $qty = 1;
        }
        $precio = isset($item['precio_unitario']) && $item['precio_unitario'] !== null && $item['precio_unitario'] !== ''
            ? (float) $item['precio_unitario']
            : null;

        $referencia = null;
        if (isset($item['costo_neto_base']) && $item['costo_neto_base'] !== null && $item['costo_neto_base'] !== '') {
            $referencia = round((float) $item['costo_neto_base'] / $qty, 4);
        } elseif ($precio !== null) {
            $referencia = round($precio, 4);
        }

        $tras_dr = null;
        if (isset($item['costo_neto_final']) && $item['costo_neto_final'] !== null && $item['costo_neto_final'] !== '') {
            $tras_dr = round((float) $item['costo_neto_final'] / $qty, 4);
        } elseif ($precio !== null) {
            $tras_dr = round($precio, 4);
        } elseif ($referencia !== null) {
            $tras_dr = $referencia;
        }

        $tras_dr_folio = null;
        if (isset($item['costo_neto_folio']) && $item['costo_neto_folio'] !== null && $item['costo_neto_folio'] !== ''
            && (float) $item['costo_neto_folio'] > 0) {
            $tras_dr_folio = round((float) $item['costo_neto_folio'] / $qty, 4);
        } elseif ($tras_dr !== null) {
            $tras_dr_folio = $tras_dr;
        }

        $flete_ok = (bool) $flete_ok;
        $tras_dr_flete = null;
        if ($flete_ok) {
            if (isset($item['costo_landed_unitario']) && $item['costo_landed_unitario'] !== null && $item['costo_landed_unitario'] !== ''
                && (float) $item['costo_landed_unitario'] > 0) {
                $tras_dr_flete = round((float) $item['costo_landed_unitario'], 4);
            } else {
                $tras_dr_flete = $tras_dr_folio;
            }
        }

        return [
            'referencia' => $referencia,
            'tras_dr' => $tras_dr,
            'tras_dr_folio' => $tras_dr_folio,
            'tras_dr_flete' => $tras_dr_flete,
            'flete_ok' => $flete_ok,
        ];
    }

    /**
     * ¿La factura de productos tiene flete resuelto (gratis, manual o vínculo)?
     */
    private function factura_flete_ok($factura_id) {
        $factura_id = absint($factura_id);
        if ($factura_id <= 0) {
            return false;
        }
        if (class_exists('Riverso_Invoice_Intake_Service')
            && method_exists('Riverso_Invoice_Intake_Service', 'is_flete_resolved')) {
            return (bool) Riverso_Invoice_Intake_Service::get_instance()->is_flete_resolved($factura_id);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(flete_gratuito, 0) AS flete_gratuito,
                    COALESCE(costo_envio_manual, 0) AS costo_envio_manual
             FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ));
        if (!$row) {
            return false;
        }
        if ((int) $row->flete_gratuito === 1 || (float) $row->costo_envio_manual > 0) {
            return true;
        }
        $vinculos = $prefix . 'factura_flete_vinculos';
        $linked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$vinculos} WHERE factura_productos_id = %d",
            $factura_id
        ));
        return $linked > 0;
    }

    /**
     * Batch flete_ok por IDs de factura.
     *
     * @param int[] $factura_ids
     * @return array<int,bool>
     */
    private function facturas_flete_ok_map(array $factura_ids) {
        $map = [];
        $ids = [];
        foreach ($factura_ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return $map;
        }
        foreach ($ids as $id) {
            $map[$id] = $this->factura_flete_ok($id);
        }
        return $map;
    }

    private function pct_delta($from, $to) {
        if ($from === null || $to === null || (float) $from == 0.0) {
            return null;
        }
        return round((((float) $to - (float) $from) / abs((float) $from)) * 100, 2);
    }

    private function abs_delta($from, $to) {
        if ($from === null || $to === null) {
            return null;
        }
        return round((float) $to - (float) $from, 4);
    }

    private function cost_unchanged($prev, $next) {
        if ($prev === null || $next === null) {
            return false;
        }
        $prev = (float) $prev;
        $next = (float) $next;
        if ($prev == 0.0) {
            return abs($next) < 0.0001;
        }
        return abs(($next - $prev) / $prev) <= self::COST_TOLERANCE;
    }

    private function margin_pair($p_bruto, $c_neto, $iva_tipo = 'afecto') {
        if ($p_bruto === null || $c_neto === null || !class_exists('Riverso_Pricing_Module')) {
            return ['neto' => null, 'margen' => null, 'pct' => null];
        }
        $neto = Riverso_Pricing_Module::net_from_gross((float) $p_bruto, $iva_tipo);
        $margen = round($neto - (float) $c_neto, 3);
        $pct = ((float) $c_neto != 0.0) ? round(($margen / (float) $c_neto) * 100, 1) : null;
        return ['neto' => $neto, 'margen' => $margen, 'pct' => $pct];
    }

    /**
     * Motivo de bloqueo de familia para un producto resuelto, o '' si puede guardar precio.
     *
     * @param array $product Resultado de resolve_item_product
     * @return string
     */
    private function item_family_block_reason(array $product) {
        global $wpdb;
        $prefix = $this->prefix();
        $pb_id = (int) ($product['producto_base_id'] ?? 0);
        if ($pb_id <= 0) {
            return 'Producto inválido';
        }

        $target = $this->resolve_price_target($pb_id);
        $target_id = (int) ($target['target_id'] ?? $pb_id);

        $pm = $this->product_module();
        $has_family = $pm ? $pm->product_has_family($pb_id) : false;
        $decision = (string) ($product['familia_decision'] ?? '');

        $open_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tipo, titulo FROM {$prefix}tareas
             WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
               AND tipo IN ('preguntar_familia', 'asignar_familia')
               AND estado NOT IN ('completada', 'cancelada')",
            $pb_id
        ), ARRAY_A) ?: [];

        if ($target_id !== $pb_id) {
            $more = $wpdb->get_results($wpdb->prepare(
                "SELECT id, tipo, titulo FROM {$prefix}tareas
                 WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
                   AND tipo IN ('preguntar_familia', 'asignar_familia')
                   AND estado NOT IN ('completada', 'cancelada')",
                $target_id
            ), ARRAY_A) ?: [];
            $open_tasks = array_merge($open_tasks, $more);
        }

        if ($open_tasks) {
            $t = $open_tasks[0];
            return !empty($t['titulo'])
                ? (string) $t['titulo']
                : ('Tarea pendiente: ' . ($t['tipo'] ?? 'familia'));
        }

        if (!$has_family && $decision !== 'no_requiere') {
            return 'Falta decidir/asignar familia (no_requiere o familia)';
        }

        return '';
    }

    /**
     * Origen vacío para Costo ant. / P ant.
     *
     * @return array{key:string,label:string,fecha:string,folio:string,factura_id:?int,url:string}
     */
    private function empty_prior_origin() {
        return [
            'key' => '',
            'label' => '—',
            'fecha' => '',
            'folio' => '',
            'factura_id' => null,
            'url' => '',
        ];
    }

    /**
     * URL admin a Procesar folios abriendo una factura concreta.
     *
     * @param int $factura_id
     * @return string
     */
    private function prior_folio_url($factura_id) {
        $factura_id = absint($factura_id);
        if ($factura_id <= 0) {
            return '';
        }
        return admin_url('admin.php?page=riverso-pos-pricing&tab=process&factura_id=' . $factura_id);
    }

    /**
     * @param string $mysql_date Fecha o datetime
     * @return string d/m/Y o ''
     */
    private function format_prior_date($mysql_date) {
        $mysql_date = trim((string) $mysql_date);
        if ($mysql_date === '') {
            return '';
        }
        $ts = strtotime($mysql_date);
        if (!$ts) {
            return $mysql_date;
        }
        return date('d/m/Y', $ts);
    }

    /**
     * Empaqueta origen de un evento de historial (as-of folio).
     *
     * @param array  $evt
     * @param string $kind price|cost
     * @return array
     */
    private function build_prior_origin(array $evt, $kind = 'price') {
        $st = sanitize_key((string) ($evt['source_type'] ?? ''));
        $factura_id = !empty($evt['source_document_id']) ? (int) $evt['source_document_id'] : null;
        $folio = trim((string) ($evt['folio'] ?? ''));
        $eff = (string) ($evt['_effective_date'] ?? '');
        if ($eff === '' && !empty($evt['factura_fecha'])) {
            $eff = substr((string) $evt['factura_fecha'], 0, 10);
        }
        if ($eff === '' && !empty($evt['created_at'])) {
            $eff = substr((string) $evt['created_at'], 0, 10);
        }
        $fecha_label = $this->format_prior_date($eff);

        $key = $st;
        if ($kind === 'cost' && in_array($st, ['folio', 'recalc'], true)) {
            $key = ($st === 'folio') ? 'folio' : 'costo';
        }

        $base = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::source_type_label($key !== '' ? $key : 'manual')
            : ($key !== '' ? $key : '—');

        if (($key === 'folio' || $st === 'folio') && $folio !== '') {
            $label = 'Revisión de folio #' . $folio;
            if ($fecha_label !== '') {
                $label .= ' · ' . $fecha_label;
            }
        } elseif ($fecha_label !== '') {
            $label = $base . ' · ' . $fecha_label;
        } else {
            $label = $base;
        }

        return [
            'key' => $key !== '' ? $key : $st,
            'label' => $label,
            'fecha' => $fecha_label,
            'folio' => $folio,
            'factura_id' => $factura_id,
            'url' => $factura_id ? $this->prior_folio_url($factura_id) : '',
        ];
    }

    /**
     * Precio/costo anteriores vigentes a la fecha del folio, sin el folio actual ni posteriores.
     *
     * @param int[]  $target_ids
     * @param int    $factura_id
     * @param string $fecha_emision Y-m-d o datetime
     * @return array<int,array{precio:?float,costo:?float,precio_origen:array,costo_origen:array}>
     */
    private function lookup_prior_refs(array $target_ids, $factura_id, $fecha_emision) {
        global $wpdb;
        $prefix = $this->prefix();
        $factura_id = absint($factura_id);
        $as_of = $this->normalize_as_of_date($fecha_emision);

        $out = [];
        $ids = [];
        foreach ($target_ids as $id) {
            $id = absint($id);
            if ($id <= 0) {
                continue;
            }
            $ids[$id] = $id;
            $out[$id] = [
                'precio' => null,
                'costo' => null,
                'precio_origen' => $this->empty_prior_origin(),
                'costo_origen' => $this->empty_prior_origin(),
            ];
        }
        if (!$ids) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', array_values($ids)));
        $rows = $wpdb->get_results(
            "SELECT h.id, h.producto_base_id, h.c_ref, h.p_asignado_nuevo, h.source_type,
                    h.source_document_id, h.created_at,
                    f.folio, f.fecha_emision AS factura_fecha
             FROM {$prefix}precio_historial h
             LEFT JOIN {$prefix}facturas f ON f.id = h.source_document_id
             WHERE h.producto_base_id IN ({$id_list})
               AND h.canal = 'local'
             ORDER BY h.producto_base_id ASC, h.id ASC",
            ARRAY_A
        ) ?: [];

        $by_pb = [];
        foreach ($rows as $row) {
            $pb = (int) $row['producto_base_id'];
            $doc = !empty($row['source_document_id']) ? (int) $row['source_document_id'] : 0;
            if ($factura_id > 0 && $doc === $factura_id) {
                continue;
            }

            $st = sanitize_key((string) ($row['source_type'] ?? ''));
            $factura_fecha = !empty($row['factura_fecha']) ? substr((string) $row['factura_fecha'], 0, 10) : '';
            $created = !empty($row['created_at']) ? substr((string) $row['created_at'], 0, 10) : '';
            if ($st === 'folio' && $factura_fecha !== '') {
                $eff = $factura_fecha;
            } else {
                $eff = $created;
            }
            if ($eff === '' || $eff > $as_of) {
                continue;
            }
            $row['_effective_date'] = $eff;
            $by_pb[$pb][] = $row;
        }

        foreach ($by_pb as $pb => $events) {
            // Orden cronológico por fecha de documento (no por id de inserción).
            usort($events, static function ($a, $b) {
                $da = (string) ($a['_effective_date'] ?? '');
                $db = (string) ($b['_effective_date'] ?? '');
                if ($da !== $db) {
                    return $da <=> $db;
                }
                $fa = (int) ($a['source_document_id'] ?? 0);
                $fb = (int) ($b['source_document_id'] ?? 0);
                if ($fa !== $fb) {
                    return $fa <=> $fb;
                }
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });

            $price_evt = null;
            foreach ($events as $row) {
                if ($row['p_asignado_nuevo'] === null || $row['p_asignado_nuevo'] === '') {
                    continue;
                }
                if ($price_evt === null) {
                    $price_evt = $row;
                    continue;
                }
                // Gana el de mayor fecha efectiva / factura_id / id.
                $better = false;
                $da = (string) ($row['_effective_date'] ?? '');
                $db = (string) ($price_evt['_effective_date'] ?? '');
                if ($da > $db) {
                    $better = true;
                } elseif ($da === $db) {
                    $fa = (int) ($row['source_document_id'] ?? 0);
                    $fb = (int) ($price_evt['source_document_id'] ?? 0);
                    if ($fa > $fb || ($fa === $fb && (int) $row['id'] > (int) $price_evt['id'])) {
                        $better = true;
                    }
                }
                if ($better) {
                    $price_evt = $row;
                }
            }
            if ($price_evt) {
                $out[$pb]['precio'] = (float) $price_evt['p_asignado_nuevo'];
                $out[$pb]['precio_origen'] = $this->build_prior_origin($price_evt, 'price');
            }

            $prev_c = null;
            $has_prev = false;
            $cost_evt = null;
            $last_c_row = null;
            foreach ($events as $row) {
                if ($row['c_ref'] === null || $row['c_ref'] === '') {
                    continue;
                }
                $c = (float) $row['c_ref'];
                $st = sanitize_key((string) ($row['source_type'] ?? ''));
                $changed = $has_prev && abs($c - (float) $prev_c) > self::COST_TOLERANCE;
                $force_cost_src = in_array($st, ['folio', 'recalc', 'import'], true);
                if ($changed || $force_cost_src) {
                    $cost_evt = $row;
                }
                $last_c_row = $row;
                if (!$has_prev || $changed) {
                    $prev_c = $c;
                    $has_prev = true;
                }
            }
            // Sin evento de cambio forzado: usar el último c_ref as-of (p. ej. save manual).
            if (!$cost_evt && $last_c_row) {
                $cost_evt = $last_c_row;
            }
            if ($cost_evt) {
                $out[$pb]['costo'] = (float) $cost_evt['c_ref'];
                $out[$pb]['costo_origen'] = $this->build_prior_origin($cost_evt, 'cost');
            }
        }

        return $out;
    }

    /**
     * Origen desde una factura de compra anterior (sin pasar por precio_historial).
     *
     * @param array $inv {factura_id, folio, fecha_emision, costo}
     * @return array
     */
    private function invoice_prior_origin(array $inv) {
        $factura_id = !empty($inv['factura_id']) ? (int) $inv['factura_id'] : null;
        $folio = trim((string) ($inv['folio'] ?? ''));
        $fecha_label = $this->format_prior_date($inv['fecha_emision'] ?? '');
        $label = $folio !== '' ? ('Folio #' . $folio) : 'Folio anterior';
        if ($fecha_label !== '') {
            $label .= ' · ' . $fecha_label;
        }
        return [
            'key' => 'folio',
            'label' => $label,
            'fecha' => $fecha_label,
            'folio' => $folio,
            'factura_id' => $factura_id,
            'url' => $factura_id ? $this->prior_folio_url($factura_id) : '',
        ];
    }

    /**
     * Normaliza as-of Y-m-d desde fecha_emision.
     *
     * @param string $fecha_emision
     * @return string
     */
    private function normalize_as_of_date($fecha_emision) {
        $as_of = substr(trim((string) $fecha_emision), 0, 10);
        if ($as_of === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) {
            return current_time('Y-m-d');
        }
        return $as_of;
    }

    /**
     * Último costo de factura (mismo proveedor + código) antes del folio actual.
     *
     * @param int      $proveedor_id
     * @param string[] $codes
     * @param int      $factura_id
     * @param string   $fecha_emision
     * @return array<string,array{costo:float,factura_id:int,folio:string,fecha_emision:string}>
     */
    private function lookup_prior_invoice_by_codes($proveedor_id, array $codes, $factura_id, $fecha_emision) {
        global $wpdb;
        $prefix = $this->prefix();
        $proveedor_id = absint($proveedor_id);
        $factura_id = absint($factura_id);
        $as_of = $this->normalize_as_of_date($fecha_emision);
        $out = [];

        $clean = [];
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $clean[$code] = $code;
            }
        }
        if ($proveedor_id <= 0 || !$clean) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($clean), '%s'));
        $params = array_merge(
            [(int) $proveedor_id],
            array_values($clean),
            [(int) $factura_id, $as_of, $as_of, (int) $factura_id]
        );
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id AS factura_id, f.folio, f.fecha_emision, fi.codigo_proveedor,
                    fi.cantidad, fi.precio_unitario, fi.costo_neto_base, fi.costo_neto_final, fi.costo_neto_folio, fi.costo_landed_unitario
             FROM {$prefix}factura_items fi
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             WHERE (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL OR fi.item_tipo = '')
               AND f.proveedor_id = %d
               AND fi.codigo_proveedor IN ({$placeholders})
               AND f.tipo_dte IN (33, 34)
               AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
               AND f.id <> %d
               AND (
                    f.fecha_emision < %s
                    OR (f.fecha_emision = %s AND f.id < %d)
               )
             ORDER BY fi.codigo_proveedor ASC, f.fecha_emision DESC, f.id DESC",
            $params
        ), ARRAY_A) ?: [];

        $flete_ids = [];
        foreach ($rows as $row) {
            $fid = (int) ($row['factura_id'] ?? 0);
            if ($fid > 0) {
                $flete_ids[$fid] = $fid;
            }
        }
        $flete_map = $this->facturas_flete_ok_map(array_values($flete_ids));

        foreach ($rows as $row) {
            $code = trim((string) ($row['codigo_proveedor'] ?? ''));
            if ($code === '' || isset($out[$code])) {
                continue;
            }
            $fid = (int) ($row['factura_id'] ?? 0);
            $flete_ok = !empty($flete_map[$fid]);
            $bases = $this->unit_cost_bases_from_item($row, $flete_ok);
            $cost = $this->unit_cost_from_item($row);
            if ($cost === null && $bases['tras_dr'] === null && $bases['referencia'] === null) {
                continue;
            }
            $out[$code] = [
                'costo' => $cost !== null ? $cost : ($bases['tras_dr'] ?? $bases['referencia']),
                'costo_bases' => $bases,
                'flete_ok' => $flete_ok,
                'factura_id' => $fid,
                'folio' => (string) ($row['folio'] ?? ''),
                'fecha_emision' => (string) ($row['fecha_emision'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Último costo de factura ligado al producto (cualquier proveedor) antes del folio.
     *
     * @param int[]  $producto_base_ids
     * @param int    $factura_id
     * @param string $fecha_emision
     * @return array<int,array{costo:float,factura_id:int,folio:string,fecha_emision:string}>
     */
    private function lookup_prior_invoice_by_products(array $producto_base_ids, $factura_id, $fecha_emision) {
        global $wpdb;
        $prefix = $this->prefix();
        $factura_id = absint($factura_id);
        $as_of = $this->normalize_as_of_date($fecha_emision);
        $out = [];

        $ids = [];
        foreach ($producto_base_ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', array_values($ids)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.producto_base_id, f.id AS factura_id, f.folio, f.fecha_emision,
                    fi.cantidad, fi.precio_unitario, fi.costo_neto_base, fi.costo_neto_final, fi.costo_neto_folio, fi.costo_landed_unitario
             FROM {$prefix}factura_items fi
             INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
             INNER JOIN {$prefix}producto_proveedor pp
                ON pp.proveedor_id = f.proveedor_id
               AND pp.codigo_proveedor = fi.codigo_proveedor
               AND pp.activo = 1
             WHERE (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL OR fi.item_tipo = '')
               AND pp.producto_base_id IN ({$id_list})
               AND f.tipo_dte IN (33, 34)
               AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
               AND f.id <> %d
               AND (
                    f.fecha_emision < %s
                    OR (f.fecha_emision = %s AND f.id < %d)
               )
             ORDER BY pp.producto_base_id ASC, f.fecha_emision DESC, f.id DESC",
            (int) $factura_id,
            $as_of,
            $as_of,
            (int) $factura_id
        ), ARRAY_A) ?: [];

        $flete_ids = [];
        foreach ($rows as $row) {
            $fid = (int) ($row['factura_id'] ?? 0);
            if ($fid > 0) {
                $flete_ids[$fid] = $fid;
            }
        }
        $flete_map = $this->facturas_flete_ok_map(array_values($flete_ids));

        foreach ($rows as $row) {
            $pb = (int) ($row['producto_base_id'] ?? 0);
            if ($pb <= 0 || isset($out[$pb])) {
                continue;
            }
            $fid = (int) ($row['factura_id'] ?? 0);
            $flete_ok = !empty($flete_map[$fid]);
            $bases = $this->unit_cost_bases_from_item($row, $flete_ok);
            $cost = $this->unit_cost_from_item($row);
            if ($cost === null && $bases['tras_dr'] === null && $bases['referencia'] === null) {
                continue;
            }
            $out[$pb] = [
                'costo' => $cost !== null ? $cost : ($bases['tras_dr'] ?? $bases['referencia']),
                'costo_bases' => $bases,
                'flete_ok' => $flete_ok,
                'factura_id' => $fid,
                'folio' => (string) ($row['folio'] ?? ''),
                'fecha_emision' => (string) ($row['fecha_emision'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Origen legacy cuando no hay historial ni factura anterior.
     *
     * @return array
     */
    private function legacy_prior_origin() {
        return [
            'key' => 'legacy',
            'label' => class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::source_type_label('legacy')
                : 'Legacy',
            'fecha' => '',
            'folio' => '',
            'factura_id' => null,
            'url' => '',
        ];
    }

    public function get_session($factura_id) {
        $factura_id = absint($factura_id);
        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return $loaded;
        }

        $resolved = $this->resolve_estado($factura_id);
        $gates = $this->evaluate_gates($factura_id);
        $pricing = $this->pricing();
        $unit_svc = $this->unit_service();
        $proveedor_id = (int) ($loaded['factura']['proveedor_id'] ?? 0);
        $omitted = $this->omitted_item_ids($factura_id);
        $fecha_emision = (string) ($loaded['factura']['fecha_emision'] ?? '');

        // Pre-resolver targets y cargar P/C anteriores as-of (una query batch).
        $item_ctx = [];
        $target_ids = [];
        foreach ($loaded['items'] as $item) {
            $item_id = (int) $item['id'];
            if (isset($omitted[$item_id])) {
                continue;
            }
            $product = $this->resolve_item_product($item, $proveedor_id);
            if (!$product) {
                continue;
            }
            $target = $this->resolve_price_target($product['producto_base_id']);
            $target_id = (int) $target['target_id'];
            $item_ctx[$item_id] = [
                'product' => $product,
                'target' => $target,
                'target_id' => $target_id,
            ];
            $target_ids[$target_id] = $target_id;
        }
        $prior_by_target = $this->lookup_prior_refs(array_values($target_ids), $factura_id, $fecha_emision);
        $confirmed_local_map = $this->confirmed_targets_by_folios([$factura_id], 'local');
        $confirmed_online_map = $this->confirmed_targets_by_folios([$factura_id], 'online');
        $confirmed_local = $confirmed_local_map[$factura_id] ?? [];
        $confirmed_online = $confirmed_online_map[$factura_id] ?? [];
        $newest_by_target = $this->newest_applied_folio_by_targets(array_values($target_ids), 'local');

        // Fallback costo: facturas anteriores (mismo código / producto) antes de legacy.
        $codes_for_prior = [];
        $pb_for_prior = [];
        foreach ($item_ctx as $ctx) {
            $pb_for_prior[(int) $ctx['product']['producto_base_id']] = (int) $ctx['product']['producto_base_id'];
            $pb_for_prior[(int) $ctx['target_id']] = (int) $ctx['target_id'];
        }
        foreach ($loaded['items'] as $item) {
            if (isset($omitted[(int) $item['id']])) {
                continue;
            }
            $code = trim((string) ($item['codigo_proveedor'] ?? ''));
            if ($code !== '') {
                $codes_for_prior[$code] = $code;
            }
        }
        $invoice_prior_by_code = $this->lookup_prior_invoice_by_codes(
            $proveedor_id,
            array_values($codes_for_prior),
            $factura_id,
            $fecha_emision
        );
        $invoice_prior_by_pb = $this->lookup_prior_invoice_by_products(
            array_values($pb_for_prior),
            $factura_id,
            $fecha_emision
        );

        $current_flete_ok = $this->factura_flete_ok($factura_id);

        $lines = [];
        $by_target = [];

        foreach ($loaded['items'] as $item) {
            $code = trim((string) ($item['codigo_proveedor'] ?? ''));
            $item_id = (int) $item['id'];
            $is_omitted = isset($omitted[$item_id]);
            $product = isset($item_ctx[$item_id])
                ? $item_ctx[$item_id]['product']
                : ($is_omitted ? $this->resolve_item_product($item, $proveedor_id) : null);
            $costo_folio = $this->unit_cost_from_item($item);
            $costo_folio_bases = $this->unit_cost_bases_from_item($item, $current_flete_ok);

            $line = [
                'item_id' => $item_id,
                'numero_linea' => (int) ($item['numero_linea'] ?? 0),
                'codigo_proveedor' => $code,
                'descripcion' => (string) ($item['nombre'] ?? $item['descripcion'] ?? ''),
                'cantidad' => (float) ($item['cantidad'] ?? 0),
                'costo_folio' => $costo_folio,
                'costo_folio_bases' => $costo_folio_bases,
                'flete_ok' => $current_flete_ok,
                'blocked' => false,
                'block_reason' => '',
                'hybrid_omitted' => $is_omitted,
            ];

            if ($is_omitted) {
                $line['blocked'] = true;
                $line['block_reason'] = 'Ya ingresada (híbrido)';
                if ($product) {
                    $line['producto_base_id'] = (int) $product['producto_base_id'];
                    $line['sku'] = $product['sku'];
                    $line['nombre'] = $product['nombre'];
                }
                $lines[] = $line;
                continue;
            }

            if (!$product || !isset($item_ctx[$item_id])) {
                $line['blocked'] = true;
                $line['block_reason'] = 'Sin SKU';
                $lines[] = $line;
                continue;
            }

            $target = $item_ctx[$item_id]['target'];
            $target_id = (int) $item_ctx[$item_id]['target_id'];
            $local = $pricing ? $pricing->get_local_price($target_id) : null;
            $online = ($pricing && method_exists($pricing, 'get_online_price_row'))
                ? $pricing->get_online_price_row($target_id, 0)
                : null;
            $iva = $pricing ? $pricing->get_iva_tipo_for_product($target_id) : 'afecto';

            $prior = $prior_by_target[$target_id] ?? [
                'precio' => null,
                'costo' => null,
                'precio_origen' => $this->empty_prior_origin(),
                'costo_origen' => $this->empty_prior_origin(),
            ];
            $costo_anterior = $prior['costo'];
            $precio_anterior = $prior['precio'];
            $costo_origen = $prior['costo_origen'];
            $precio_origen = $prior['precio_origen'];

            // Historial → factura anterior → legacy (solo si no hay otra referencia de costo).
            $costo_anterior_bases = null;
            $prior_flete_ok = null;
            $prior_factura_id = !empty($costo_origen['factura_id']) ? (int) $costo_origen['factura_id'] : 0;
            if ($costo_anterior === null) {
                $inv_prior = null;
                if ($code !== '' && isset($invoice_prior_by_code[$code])) {
                    $inv_prior = $invoice_prior_by_code[$code];
                } elseif (isset($invoice_prior_by_pb[(int) $product['producto_base_id']])) {
                    $inv_prior = $invoice_prior_by_pb[(int) $product['producto_base_id']];
                } elseif (isset($invoice_prior_by_pb[$target_id])) {
                    $inv_prior = $invoice_prior_by_pb[$target_id];
                }
                if ($inv_prior) {
                    $costo_anterior = (float) $inv_prior['costo'];
                    $costo_origen = $this->invoice_prior_origin($inv_prior);
                    $costo_anterior_bases = $inv_prior['costo_bases'] ?? null;
                    $prior_flete_ok = isset($inv_prior['flete_ok']) ? (bool) $inv_prior['flete_ok'] : null;
                    $prior_factura_id = (int) ($inv_prior['factura_id'] ?? 0);
                }
            } elseif ($prior_factura_id > 0) {
                $prior_flete_ok = $this->factura_flete_ok($prior_factura_id);
            }

            if ($costo_anterior_bases === null && $costo_anterior !== null) {
                // Historial / legacy: misma cifra en las tres bases (sin desglose D/R).
                $costo_anterior_bases = [
                    'referencia' => (float) $costo_anterior,
                    'tras_dr' => (float) $costo_anterior,
                    'tras_dr_folio' => (float) $costo_anterior,
                    'tras_dr_flete' => ($prior_flete_ok === true) ? (float) $costo_anterior : null,
                    'flete_ok' => $prior_flete_ok === true,
                ];
            }

            $legacy = null;
            $legacy_used = false;
            if (($precio_anterior === null || $costo_anterior === null) && $unit_svc) {
                $legacy = $unit_svc->get_legacy_ref($target['unit_sku'] ?: $product['sku']);
                if ($legacy) {
                    if ($costo_anterior === null && !empty($legacy['costo_neto']) && empty($legacy['costo_sin_dato'])) {
                        // legacy_precio_ref.costo_neto suele venir del Excel FACTO en bruto
                        // pese al nombre de columna; el pipeline de folio trabaja en neto.
                        $legacy_cost_raw = (float) $legacy['costo_neto'];
                        $costo_anterior = class_exists('Riverso_Pricing_Module')
                            ? Riverso_Pricing_Module::net_from_gross($legacy_cost_raw, $iva)
                            : round($legacy_cost_raw / 1.19, 4);
                        $costo_origen = $this->legacy_prior_origin();
                        $legacy_used = true;
                        $prior_flete_ok = null;
                        $prior_factura_id = 0;
                        $costo_anterior_bases = [
                            'referencia' => (float) $costo_anterior,
                            'tras_dr' => (float) $costo_anterior,
                            'tras_dr_folio' => (float) $costo_anterior,
                            'tras_dr_flete' => null,
                            'flete_ok' => false,
                        ];
                    }
                    if ($precio_anterior === null) {
                        if (!empty($legacy['precio_total'])) {
                            $precio_anterior = (float) $legacy['precio_total'];
                            $precio_origen = $this->legacy_prior_origin();
                            $legacy_used = true;
                        } elseif (!empty($legacy['precio_neto'])) {
                            $precio_anterior = (float) $legacy['precio_neto'];
                            $precio_origen = $this->legacy_prior_origin();
                            $legacy_used = true;
                        }
                    }
                }
            }

            $proposed = $precio_anterior;
            // Si ya hay P local vigente, usarlo como propuesto SOLO si este folio aplica
            // (no en historial-only: no copiar el precio del folio más reciente).
            $has_local = $local && $local['p_asignado'] !== null && $local['p_asignado'] !== '';
            $confirmed_from_folio = isset($confirmed_local[$target_id]);
            $confirmed_online_from_folio = isset($confirmed_online[$target_id]);
            $newest = $newest_by_target[$target_id] ?? null;
            $apply_mode = 'apply';
            $newer_folio = null;
            if ($newest
                && (int) ($newest['factura_id'] ?? 0) !== $factura_id
                && $this->folio_is_newer(
                    $newest['fecha'] ?? '',
                    (int) ($newest['factura_id'] ?? 0),
                    $fecha_emision,
                    $factura_id
                )
            ) {
                $apply_mode = 'historial_only';
                $newer_folio = $newest;
            }
            if ($has_local && $apply_mode === 'apply') {
                $proposed = (float) $local['p_asignado'];
            }
            $costo_cmp = $costo_folio !== null ? $costo_folio : $costo_anterior;
            $margen_antes = $this->margin_pair($precio_anterior, $costo_anterior, $iva);
            $margen_despues = $this->margin_pair($proposed, $costo_cmp, $iva);

            $woo_id = (int) $product['woo_id'];
            if ($woo_id <= 0 && $target_id !== (int) $product['producto_base_id']) {
                global $wpdb;
                $woo_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT woocommerce_product_id FROM {$this->prefix()}producto_base WHERE id = %d",
                    $target_id
                ));
            }

            $rule = null;
            if (!empty($target['grupo_id'])) {
                $rules = $this->rules();
                $rule_id = $rules ? $rules->get_assigned_rule_id('familia', (int) $target['grupo_id']) : null;
                if ($rule_id) {
                    $rule = $rules->get_rule_with_tiers($rule_id);
                }
            }

            $sku_pair = null;
            if (!empty($target['es_familia_unitaria']) && $unit_svc) {
                $sku_pair = $unit_svc->resolve_folio_sku_pair(
                    (int) $product['producto_base_id'],
                    $code,
                    (int) $target['grupo_id']
                );
            }

            // Vigente (no “anterior”): indica si ya hay P local guardado.
            $line = array_merge($line, [
                'producto_base_id' => (int) $product['producto_base_id'],
                'target_id' => $target_id,
                'sku' => $product['sku'],
                'nombre' => $product['nombre'],
                'is_child' => !empty($target['is_child']),
                'unit_sku' => $target['unit_sku'],
                'unit_qty' => $sku_pair['unit_qty'] ?? null,
                'pack_sku' => $sku_pair['pack_sku'] ?? null,
                'pack_qty' => $sku_pair['pack_qty'] ?? null,
                'pack_producto_base_id' => $sku_pair['pack_producto_base_id'] ?? null,
                'grupo_id' => (int) $target['grupo_id'],
                'es_familia_unitaria' => !empty($target['es_familia_unitaria']),
                'requires_online' => $woo_id > 0,
                'woo_id' => $woo_id,
                'costo_anterior' => $costo_anterior,
                'costo_anterior_origen' => $costo_origen,
                'costo_anterior_bases' => $costo_anterior_bases,
                'prior_flete_ok' => $prior_flete_ok,
                'prior_factura_id' => $prior_factura_id > 0 ? $prior_factura_id : null,
                'costo_delta' => $this->abs_delta($costo_anterior, $costo_folio),
                'costo_delta_pct' => $this->pct_delta($costo_anterior, $costo_folio),
                'costo_unchanged' => $this->cost_unchanged($costo_anterior, $costo_folio),
                'precio_anterior' => $precio_anterior,
                'precio_anterior_origen' => $precio_origen,
                'precio_propuesto' => $proposed,
                'precio_vigente' => $has_local ? (float) $local['p_asignado'] : null,
                'precio_delta' => $this->abs_delta($precio_anterior, $proposed),
                'precio_delta_pct' => $this->pct_delta($precio_anterior, $proposed),
                'margen_anterior' => $margen_antes['margen'],
                'margen_anterior_pct' => $margen_antes['pct'],
                'margen_actual' => $margen_despues['margen'],
                'margen_actual_pct' => $margen_despues['pct'],
                'precio_online_actual' => ($online && $online['p_asignado'] !== null) ? (float) $online['p_asignado'] : null,
                'precio_online_propuesto' => ($woo_id > 0) ? $proposed : null,
                'has_local_price' => (bool) $has_local,
                'has_online_price' => $online && $online['p_asignado'] !== null && $online['p_asignado'] !== '',
                'confirmed_from_folio' => (bool) $confirmed_from_folio,
                'confirmed_online_from_folio' => (bool) $confirmed_online_from_folio,
                'apply_mode' => $apply_mode,
                'newer_folio' => $newer_folio,
                'legacy_used' => $legacy_used,
                'iva_tipo' => $iva,
                'family_rule' => $rule ? [
                    'id' => (int) $rule['id'],
                    'codigo' => $rule['codigo'] ?? '',
                    'nombre' => $rule['nombre'] ?? '',
                    'tiers' => array_map(static function ($t) {
                        return [
                            'desde' => $t['cantidad_desde'] ?? null,
                            'hasta' => $t['cantidad_hasta'] ?? null,
                            'formula' => $t['formula'] ?? '',
                        ];
                    }, $rule['tiers'] ?? []),
                ] : null,
            ]);

            foreach ($gates['blockers'] as $b) {
                if ((int) ($b['item_id'] ?? 0) === (int) $item['id']
                    || (int) ($b['producto_base_id'] ?? 0) === (int) $product['producto_base_id']) {
                    $line['blocked'] = true;
                    if ($line['block_reason'] === '') {
                        $line['block_reason'] = $b['message'];
                    }
                    if (!empty($b['can_answer_family'])) {
                        $line['can_answer_family'] = true;
                    }
                    if (!empty($b['can_assign_family'])) {
                        $line['can_assign_family'] = true;
                    }
                }
            }

            $line['duplicate_target'] = isset($by_target[$target_id]);
            $by_target[$target_id] = true;
            $lines[] = $line;
        }

        $pending_count = 0;
        $unique_targets = [];
        foreach ($lines as $ln) {
            if (!empty($ln['hybrid_omitted']) || !empty($ln['blocked']) || empty($ln['target_id'])) {
                continue;
            }
            $tid = (int) $ln['target_id'];
            if (isset($unique_targets[$tid])) {
                continue;
            }
            $unique_targets[$tid] = true;
            $need_local = empty($ln['confirmed_from_folio']);
            $need_online = !empty($ln['requires_online']) && empty($ln['confirmed_online_from_folio']);
            if ($need_local || $need_online) {
                $pending_count++;
            }
        }

        $adjuntos = [];
        if (function_exists('riverso_factura_get_adjuntos')) {
            $adj_info = riverso_factura_get_adjuntos($factura_id, $loaded['factura']);
            $adjuntos = $adj_info['adjuntos'] ?? [];
        }

        return [
            'invoice' => [
                'id' => (int) $loaded['factura']['id'],
                'folio' => $loaded['factura']['folio'],
                'fecha_emision' => $loaded['factura']['fecha_emision'],
                'proveedor_id' => $proveedor_id,
                'proveedor_nombre' => $loaded['factura']['proveedor_nombre'] ?? '',
                'monto_total' => isset($loaded['factura']['monto_total']) ? (float) $loaded['factura']['monto_total'] : null,
                'estado_factura' => $loaded['factura']['estado'] ?? '',
                'flete_ok' => $current_flete_ok,
                'flete_gratuito' => (int) ($loaded['factura']['flete_gratuito'] ?? 0),
                'costo_envio_manual' => (float) ($loaded['factura']['costo_envio_manual'] ?? 0),
                'adjuntos' => $adjuntos,
                'url_factura' => add_query_arg(
                    ['page' => 'riverso-pos-invoices', 'factura' => $factura_id],
                    admin_url('admin.php')
                ),
            ],
            'proceso' => $resolved,
            'gates_ok' => $gates['ok'],
            'blockers' => $gates['blockers'],
            'lines' => $lines,
            'pending_targets' => $pending_count,
            'can_complete' => $gates['ok'] && $pending_count === 0 && empty($resolved['is_archived']),
            'is_hybrid' => $this->is_hybrid_mode($factura_id),
            'omitted_count' => count($omitted),
            'is_archived' => !empty($resolved['is_archived']),
            'can_archive' => empty($resolved['is_archived'])
                && in_array($resolved['estado'] ?? '', [self::STATE_INGRESADA, self::STATE_INGRESADA_MANUAL], true),
            'can_unarchive' => !empty($resolved['is_archived']),
        ];
    }

    public function preview_family($grupo_id, $p_asignado = null) {
        $unit = $this->unit_service();
        if (!$unit) {
            return new WP_Error('no_unit', 'Servicio de familia no disponible');
        }
        $snapshot = $unit->get_unit_snapshot(absint($grupo_id));
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $preview = $unit->preview_member_prices(absint($grupo_id), $p_asignado !== null ? (float) $p_asignado : null);
        if (is_wp_error($preview)) {
            $preview = [
                'error' => $preview->get_error_message(),
                'p_asignado' => $p_asignado,
                'members' => [],
            ];
        }
        $rules = $this->rules();
        $rule_id = $rules ? $rules->get_assigned_rule_id('familia', absint($grupo_id)) : null;
        $rule = ($rules && $rule_id) ? $rules->get_rule_with_tiers($rule_id) : null;
        $visual = !empty($snapshot['es_producto_unitario'])
            ? $unit->build_family_rule_visual(absint($grupo_id), $p_asignado, $preview, $rule)
            : null;
        $codes = !empty($snapshot['es_producto_unitario'])
            ? $unit->get_family_member_codes(absint($grupo_id))
            : null;

        return [
            'snapshot' => $snapshot,
            'preview' => $preview,
            'rule' => $rule,
            'visual' => $visual,
            'codes' => $codes,
            'pack_conflicts' => is_array($codes) ? ($codes['pack_conflicts'] ?? []) : [],
            'es_producto_unitario' => !empty($snapshot['es_producto_unitario']),
        ];
    }

    /**
     * Mueve o desvincula un código entre integrantes (desde Centro de Precios).
     *
     * @param int    $grupo_id
     * @param string $code_tipo
     * @param int    $code_id
     * @param string $accion
     * @param int    $destino_producto_base_id
     * @return array|WP_Error
     */
    public function map_family_code($grupo_id, $code_tipo, $code_id, $accion, $destino_producto_base_id = 0) {
        $unit = $this->unit_service();
        if (!$unit) {
            return new WP_Error('no_unit', 'Servicio de familia no disponible');
        }
        $result = $unit->map_family_member_code(
            absint($grupo_id),
            $code_tipo,
            absint($code_id),
            $accion,
            absint($destino_producto_base_id),
            ['motivo' => 'Desde centro de precios — Ver familia']
        );
        if (is_wp_error($result)) {
            return $result;
        }
        $refresh = $this->preview_family(absint($grupo_id), null);
        if (is_wp_error($refresh)) {
            return array_merge(is_array($result) ? $result : [], ['refresh_error' => $refresh->get_error_message()]);
        }
        return array_merge(is_array($result) ? $result : [], [
            'family' => $refresh,
        ]);
    }

    public function save_line($factura_id, $item_id, $p_asignado, $p_online = null, $amount_mode = 'bruto') {
        $factura_id = absint($factura_id);
        $item_id = absint($item_id);
        $p_asignado = (float) $p_asignado;
        $amount_mode = ($amount_mode === 'neto') ? 'neto' : 'bruto';
        if ($p_asignado <= 0) {
            return new WP_Error('invalid', 'Precio local inválido');
        }

        $resolved = $this->resolve_estado($factura_id);
        $estado = (string) ($resolved['estado'] ?? '');

        if (in_array($estado, [self::STATE_ANULADA, self::STATE_INGRESADA_MANUAL], true)) {
            return new WP_Error('manual', 'El folio está marcado manualmente y no se puede procesar.');
        }
        if ($estado === self::STATE_INGRESADA) {
            return new WP_Error('done', 'El folio ya está ingresado.');
        }

        // Permitir guardar filas válidas aunque el folio tenga errores en otras líneas.
        // Solo iniciar sesión formal si no hay blockers globales.
        if ($estado === self::STATE_PENDING && empty($resolved['blockers'])) {
            $start = $this->start_process($factura_id);
            if (is_wp_error($start)) {
                return $start;
            }
        } elseif ($estado === self::STATE_INGRESANDO) {
            // ok
        } elseif ($estado === self::STATE_ERROR) {
            // ok: se valida solo esta fila más abajo
        } elseif ($estado === self::STATE_PENDING) {
            // pendiente con blockers ajenos: guardar fila válida sin start_process
        } else {
            return new WP_Error('blocked', 'Estado de folio no permite guardar');
        }

        $loaded = $this->load_factura_product_items($factura_id);
        if (is_wp_error($loaded)) {
            return $loaded;
        }

        $item = null;
        foreach ($loaded['items'] as $it) {
            if ((int) $it['id'] === $item_id) {
                $item = $it;
                break;
            }
        }
        if (!$item) {
            return new WP_Error('not_found', 'Ítem no encontrado en el folio');
        }

        $omitted = $this->omitted_item_ids($factura_id);
        if (isset($omitted[$item_id])) {
            return new WP_Error('hybrid_omitted', 'Esta fila ya está marcada como ingresada (híbrido)');
        }

        $product = $this->resolve_item_product($item, (int) ($loaded['factura']['proveedor_id'] ?? 0));
        if (!$product) {
            return new WP_Error('no_sku', 'Ítem sin SKU');
        }

        $family_block = $this->item_family_block_reason($product);
        if ($family_block !== '') {
            return new WP_Error('familia', $family_block);
        }

        $target = $this->resolve_price_target($product['producto_base_id']);
        $target_id = (int) $target['target_id'];
        $pricing = $this->pricing();
        if (!$pricing) {
            return new WP_Error('no_pricing', 'Módulo de precios no disponible');
        }

        $iva = method_exists($pricing, 'get_iva_tipo_for_product')
            ? $pricing->get_iva_tipo_for_product($target_id)
            : 'afecto';
        if ($amount_mode === 'neto' && class_exists('Riverso_Pricing_Module')) {
            $converted = Riverso_Pricing_Module::gross_from_net($p_asignado, $iva);
            if ($converted === null || (float) $converted <= 0) {
                return new WP_Error('invalid', 'Precio local inválido');
            }
            $p_asignado = (float) $converted;
            if ($p_online !== null && $p_online !== '') {
                $online_conv = Riverso_Pricing_Module::gross_from_net((float) $p_online, $iva);
                $p_online = $online_conv !== null ? (float) $online_conv : null;
            }
        }

        $costo = $this->unit_cost_from_item($item);
        $folio_label = (string) ($loaded['factura']['folio'] ?? $factura_id);
        $fecha_emision = (string) ($loaded['factura']['fecha_emision'] ?? '');
        $meta = [
            'source_type' => 'folio',
            'source_document_id' => $factura_id,
            'notas' => 'Procesar folio #' . $folio_label,
        ];

        $newest_map = $this->newest_applied_folio_by_targets([$target_id], 'local');
        $newest = $newest_map[$target_id] ?? null;
        $historial_only = false;
        if ($newest
            && (int) ($newest['factura_id'] ?? 0) !== $factura_id
            && $this->folio_is_newer(
                $newest['fecha'] ?? '',
                (int) ($newest['factura_id'] ?? 0),
                $fecha_emision,
                $factura_id
            )
        ) {
            $historial_only = true;
            $newer_label = trim((string) ($newest['folio'] ?? ''));
            if ($newer_label === '') {
                $newer_label = (string) ($newest['factura_id'] ?? '');
            }
            $newer_fecha = (string) ($newest['fecha'] ?? '');
            $meta['notas'] = 'Procesar folio #' . $folio_label
                . ' (no aplicado: vigente folio #' . $newer_label
                . ($newer_fecha !== '' ? ' · ' . $newer_fecha : '')
                . ')';
        }

        $result = null;
        $online_result = null;
        $applied = false;
        global $wpdb;

        $local_row = $pricing->get_local_price($target_id);
        $prev_local = ($local_row && $local_row['p_asignado'] !== null && $local_row['p_asignado'] !== '')
            ? (float) $local_row['p_asignado']
            : null;

        if ($historial_only) {
            // Solo constancia en historial: no pisar precios.p_asignado ni c_ref.
            if (method_exists($pricing, 'record_price_change')) {
                $pricing->record_price_change([
                    'producto_base_id' => $target_id,
                    'canal' => 'local',
                    'woocommerce_variation_id' => 0,
                    'c_ref' => $costo,
                    'precio_sugerido' => $local_row['p_ref'] ?? null,
                    'p_asignado_anterior' => $prev_local,
                    'p_asignado_nuevo' => $p_asignado,
                    'source_type' => 'folio',
                    'source_document_id' => $factura_id,
                    'notas' => $meta['notas'],
                ]);
            }
            $result = $local_row ?: ['producto_base_id' => $target_id, 'p_asignado' => $prev_local];
            $applied = false;
        } else {
            if ($costo !== null && method_exists($pricing, 'recalc_price')) {
                $pricing->recalc_price($target_id, 'local');
                $wpdb->update(
                    $this->prefix() . 'precios',
                    ['c_ref' => $costo],
                    [
                        'producto_base_id' => $target_id,
                        'canal' => 'local',
                        'woocommerce_variation_id' => 0,
                    ],
                    ['%f'],
                    ['%d', '%s', '%d']
                );
            }

            $result = $pricing->upsert_assigned_price($target_id, 'local', $p_asignado, 0, $meta);
            if (is_wp_error($result)) {
                return $result;
            }
            $applied = true;
        }

        $woo_id = (int) $product['woo_id'];
        if ($woo_id <= 0) {
            $woo_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT woocommerce_product_id FROM {$this->prefix()}producto_base WHERE id = %d",
                $target_id
            ));
        }
        if ($woo_id > 0) {
            $online_price = $p_online !== null && $p_online !== '' ? (float) $p_online : $p_asignado;
            if ($online_price > 0) {
                if ($historial_only) {
                    $online_row = method_exists($pricing, 'get_online_price_row')
                        ? $pricing->get_online_price_row($target_id, 0)
                        : null;
                    $prev_online = ($online_row && $online_row['p_asignado'] !== null && $online_row['p_asignado'] !== '')
                        ? (float) $online_row['p_asignado']
                        : null;
                    if (method_exists($pricing, 'record_price_change')) {
                        $pricing->record_price_change([
                            'producto_base_id' => $target_id,
                            'canal' => 'online',
                            'woocommerce_variation_id' => 0,
                            'c_ref' => $costo,
                            'precio_sugerido' => $online_row['p_ref'] ?? null,
                            'p_asignado_anterior' => $prev_online,
                            'p_asignado_nuevo' => $online_price,
                            'source_type' => 'folio',
                            'source_document_id' => $factura_id,
                            'notas' => $meta['notas'],
                        ]);
                    }
                    $online_result = $online_row;
                } else {
                    $online_meta = $meta;
                    $existing_en_uso = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT en_uso FROM {$this->prefix()}precios
                         WHERE producto_base_id = %d AND canal = 'online' AND woocommerce_variation_id = 0
                         LIMIT 1",
                        $target_id
                    ));
                    // Solo desactivar si aún no estaba activo online.
                    $online_meta['en_uso'] = $existing_en_uso === 1 ? 1 : 0;
                    $online_result = $pricing->upsert_assigned_price($target_id, 'online', $online_price, 0, $online_meta);
                }
            }
        }

        $this->invalidate_confirmation_caches($factura_id, $target_id);

        $complete = $this->try_complete($factura_id);
        $ready = is_array($complete) && !empty($complete['ready']);
        $is_hybrid = is_array($complete) && !empty($complete['is_hybrid']);
        // En modo normal, auto-completar al guardar la última línea.
        // En híbrido, solo señalar que está listo (botón + confirmación).
        $auto_done = false;
        if ($ready && !$is_hybrid) {
            $auto_done = $this->complete($factura_id) === true;
        }

        return [
            'local' => $result,
            'online' => $online_result,
            'target_id' => $target_id,
            'applied' => $applied,
            'historial_only' => $historial_only,
            'completed' => $auto_done,
            'ready_to_complete' => $ready,
            'session' => $this->get_session($factura_id),
        ];
    }

    public function try_complete($factura_id) {
        $factura_id = absint($factura_id);
        $gates = $this->evaluate_gates($factura_id);
        if (!$gates['ok']) {
            return false;
        }
        if (!$this->prices_complete($gates['products'], $factura_id)) {
            return false;
        }
        $is_hybrid = $this->is_hybrid_mode($factura_id);
        // En híbrido no auto-completar: el usuario confirma con el botón.
        // (save_line llama try_complete; complete() pasa $force=true).
        return [
            'ready' => true,
            'is_hybrid' => $is_hybrid,
        ];
    }

    /**
     * Cierra el folio cuando gates OK y precios (o omitidos híbridos) completos.
     */
    public function complete($factura_id) {
        $factura_id = absint($factura_id);
        $gates = $this->evaluate_gates($factura_id);
        if (!$gates['ok']) {
            return new WP_Error('incomplete', 'Aún hay bloqueos por resolver');
        }
        if (!$this->prices_complete($gates['products'], $factura_id)) {
            return new WP_Error('incomplete', 'Aún faltan precios confirmados desde este folio (u online si aplica)');
        }

        $omitted = $this->omitted_item_ids($factura_id);
        $is_hybrid = $this->is_hybrid_mode($factura_id);

        // Si en híbrido todas las filas quedaron como ya ingresadas → ingresada_manual.
        $loaded = $this->load_factura_product_items($factura_id);
        $all_omitted = false;
        if (!is_wp_error($loaded) && $is_hybrid && !empty($loaded['items'])) {
            $all_omitted = true;
            foreach ($loaded['items'] as $item) {
                if (!isset($omitted[(int) $item['id']])) {
                    $all_omitted = false;
                    break;
                }
            }
        }

        if ($all_omitted) {
            $this->set_estado_manual(
                $factura_id,
                self::STATE_INGRESADA_MANUAL,
                'Ingreso híbrido: todas las filas ya ingresadas'
            );
            return true;
        }

        $this->upsert_proceso($factura_id, [
            'estado' => self::STATE_INGRESADA,
            'estado_manual' => null,
            // Al completar se sale del modo híbrido para no volver a «ingresando».
            'items_omitidos_json' => null,
            'completed_at' => current_time('mysql'),
            'blockers_json' => wp_json_encode([]),
        ]);
        return true;
    }

    /**
     * Opción 3a: crear producto local (SKU numérico) y vincular al ítem del folio.
     *
     * @param int    $factura_id
     * @param int    $item_id
     * @param string $nombre_canonico
     * @return array|WP_Error
     */
    public function create_local_and_link($factura_id, $item_id, $nombre_canonico) {
        global $wpdb;
        $prefix = $this->prefix();
        $factura_id = absint($factura_id);
        $item_id = absint($item_id);
        $nombre_canonico = trim(preg_replace('/\s+/u', ' ', sanitize_text_field($nombre_canonico)));

        if ($factura_id <= 0 || $item_id <= 0) {
            return new WP_Error('invalid_params', 'Factura e ítem son obligatorios');
        }
        if ($nombre_canonico === '') {
            return new WP_Error('missing_name', 'Nombre canónico requerido');
        }
        if (!class_exists('Riverso_Product_Module')) {
            return new WP_Error('missing_module', 'Módulo de productos no disponible');
        }
        if (!class_exists('Riverso_Invoice_Intake_Service')) {
            return new WP_Error('missing_module', 'Servicio de facturas no disponible');
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT fi.*, f.proveedor_id, f.id AS factura_id, f.fecha_emision, f.folio
             FROM {$prefix}factura_items fi
             JOIN {$prefix}facturas f ON fi.factura_id = f.id
             WHERE fi.id = %d AND fi.factura_id = %d",
            $item_id,
            $factura_id
        ), ARRAY_A);

        if (!$item) {
            return new WP_Error('not_found', 'Ítem no encontrado en este folio');
        }

        $codigo = trim((string) ($item['codigo_proveedor'] ?? ''));
        if ($codigo === '') {
            return new WP_Error('missing_code', 'El ítem no tiene código de proveedor');
        }

        $proveedor_id = (int) ($item['proveedor_id'] ?? 0);
        if ($proveedor_id <= 0) {
            return new WP_Error('missing_supplier', 'La factura no tiene proveedor');
        }

        $already = $this->resolve_item_product($item, $proveedor_id);
        if ($already) {
            return new WP_Error(
                'already_linked',
                'Este ítem ya tiene SKU usable: ' . ($already['sku'] ?? '')
            );
        }

        $sku = $this->allocate_next_local_sku();
        if (is_wp_error($sku)) {
            return $sku;
        }

        if (function_exists('riverso_sku_equals_supplier_code')
            && riverso_sku_equals_supplier_code($sku, $codigo)) {
            return new WP_Error(
                'sku_equals_code',
                'El SKU local no puede ser el mismo código de proveedor'
            );
        }

        $product = Riverso_Product_Module::get_instance()->save_product([
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre_canonico,
            'unidad_base' => 'unidad',
        ]);
        if (is_wp_error($product)) {
            return $product;
        }

        $sku = (string) ($product['canonical_sku'] ?? $sku);
        $intake = Riverso_Invoice_Intake_Service::get_instance();
        $descripcion = (string) ($item['descripcion'] ?? $item['nombre'] ?? $nombre_canonico);

        $map = $intake->assign_local_sku_mapping(
            $proveedor_id,
            $codigo,
            $sku,
            [
                'force' => false,
                'descripcion' => $descripcion,
                'factura_item_id' => $item_id,
                'actor_type' => 'human',
                'document_date' => $item['fecha_emision'] ?? null,
            ]
        );

        if (is_wp_error($map)) {
            // Producto ya creado: no revertimos; el usuario puede vincular después.
            return new WP_Error(
                $map->get_error_code(),
                'Producto creado (SKU ' . $sku . ') pero no se pudo vincular: ' . $map->get_error_message(),
                array_merge(
                    is_array($map->get_error_data()) ? $map->get_error_data() : [],
                    [
                        'product' => $product,
                        'sku' => $sku,
                        'conflict' => $map->get_error_code() === 'sku_conflict',
                    ]
                )
            );
        }

        $product_id = (int) ($intake->resolve_product_id_for_local_sku($sku, $codigo) ?: ($product['id'] ?? 0));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}factura_items
             SET sku_local = %s,
                 product_id = NULLIF(%d, 0),
                 estado = 'vinculado'
             WHERE id = %d",
            $sku,
            $product_id,
            $item_id
        ));

        if (class_exists('Riverso_Invoice_Module')) {
            $invoice_mod = new Riverso_Invoice_Module();
            if (method_exists($invoice_mod, 'update_invoice_status')) {
                $invoice_mod->update_invoice_status($factura_id);
            }
        }

        $wpdb->update(
            "{$prefix}cost_history",
            [
                'product_id' => $product_id ?: 0,
                'pendiente_vinculacion' => 0,
            ],
            [
                'source_type' => 'invoice',
                'source_item_id' => $item_id,
            ],
            ['%d', '%d'],
            ['%s', '%d']
        );

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}tareas
             SET estado = 'completada', completado_en = %s
             WHERE tipo = 'codigo_faltante'
               AND referencia_tipo = 'factura_item'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            current_time('mysql'),
            $item_id
        ));

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'folio_create_local_and_link',
                'invoice',
                $factura_id,
                [
                    'entity_name' => $item['folio'] ?? '',
                    'new_value' => [
                        'sku_local' => $sku,
                        'item_id' => $item_id,
                        'codigo_proveedor' => $codigo,
                        'nombre_canonico' => $nombre_canonico,
                        'producto_base_id' => $product_id,
                    ],
                    'details' => sprintf(
                        '3a folio: creado SKU %s «%s» y vinculado a código %s',
                        $sku,
                        $nombre_canonico,
                        $codigo
                    ),
                ]
            );
        }

        return [
            'product' => $product,
            'sku' => $sku,
            'producto_base_id' => $product_id,
            'item_id' => $item_id,
            'codigo_proveedor' => $codigo,
            'nombre_canonico' => $nombre_canonico,
            'mapping' => $map,
            'message' => sprintf('Producto local %s creado y vinculado', $sku),
        ];
    }

    /**
     * Siguiente SKU local numérico disponible (máx. 6 dígitos).
     *
     * @return string|WP_Error
     */
    private function allocate_next_local_sku() {
        global $wpdb;
        $prefix = $this->prefix();

        $max_sku = $wpdb->get_var(
            "SELECT MAX(CAST(canonical_sku AS UNSIGNED))
             FROM {$prefix}producto_base
             WHERE canonical_sku REGEXP '^[0-9]+$'
               AND CHAR_LENGTH(canonical_sku) <= 6"
        );
        $next = $max_sku ? ((int) $max_sku + 1) : 1;
        if ($next > 999999) {
            return new WP_Error('sku_exhausted', 'No hay SKUs locales disponibles');
        }

        for ($i = $next; $i <= 999999; $i++) {
            $taken = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
                (string) $i
            ));
            if (!$taken) {
                return (string) $i;
            }
        }

        return new WP_Error('sku_exhausted', 'No hay SKUs locales disponibles');
    }
}
