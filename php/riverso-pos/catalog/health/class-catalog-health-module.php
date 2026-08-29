<?php
/**
 * Salud del catálogo y detección idempotente de brechas.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Catalog_Health_Module {

    const CRON_HOOK = 'riverso_pos_catalog_health_scan';
    const LOCK_KEY = 'riverso_catalog_health_scan_lock';

    private static $instance = null;
    private $initialized = false;
    private $active_scan_token = null;
    private $active_scan_detected = 0;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        add_action(self::CRON_HOOK, [$this, 'run_scheduled_scan']);
        add_action('admin_post_riverso_catalog_health_scan', [$this, 'handle_manual_scan']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public function run_scheduled_scan() {
        $this->scan();
    }

    public function handle_manual_scan() {
        if (!current_user_can('riverso_manage_codes')) {
            wp_die(esc_html__('No tienes permisos para ejecutar esta revisión.', 'riverso-pos'));
        }

        check_admin_referer('riverso_catalog_health_scan');
        $result = $this->scan();
        $args = ['page' => 'riverso-pos-catalog-health'];

        if (is_wp_error($result)) {
            $args['scan_error'] = $result->get_error_code();
        } else {
            $args['scan_ok'] = 1;
            $args['detected'] = intval($result['detected']);
            $args['resolved'] = intval($result['resolved']);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Ejecuta todas las reglas y sincroniza riverso_data_gaps.
     *
     * @return array|WP_Error
     */
    public function scan() {
        if (get_transient(self::LOCK_KEY)) {
            return new WP_Error('scan_locked', 'Ya existe una revisión del catálogo en ejecución.');
        }

        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $this->active_scan_token = wp_generate_uuid4();
            $this->active_scan_detected = 0;
            $this->collect_candidates();
            $result = $this->finish_streamed_scan();
            $this->create_grouped_tasks();
            update_option('riverso_catalog_health_last_scan', current_time('mysql'));
            update_option('riverso_catalog_health_last_result', $result);
            return $result;
        } catch (Throwable $error) {
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log_system('catalog_health_scan_failed', 'catalog', 0, [
                    'details' => $error->getMessage(),
                ]);
            }
            return new WP_Error('scan_failed', $error->getMessage());
        } finally {
            $this->active_scan_token = null;
            $this->active_scan_detected = 0;
            delete_transient(self::LOCK_KEY);
        }
    }

    /**
     * Resumen para dashboard y pantalla administrativa.
     */
    public static function get_summary() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';

        if (!self::table_exists($table)) {
            return [
                'total' => 0,
                'criticos' => 0,
                'altos' => 0,
                'coverage' => 100.0,
                'dimensions' => [],
                'by_rule' => [],
                'last_scan' => null,
            ];
        }

        $rows = $wpdb->get_results(
            "SELECT regla, severidad, COUNT(*) AS total
             FROM {$table}
             WHERE estado = 'abierto'
             GROUP BY regla, severidad
             ORDER BY total DESC",
            ARRAY_A
        );

        $summary = [
            'total' => 0,
            'criticos' => 0,
            'altos' => 0,
            'coverage' => 100.0,
            'dimensions' => [],
            'by_rule' => $rows,
            'last_scan' => get_option('riverso_catalog_health_last_scan'),
        ];

        foreach ($rows as $row) {
            $count = intval($row['total']);
            $summary['total'] += $count;
            if ($row['severidad'] === 'critica') {
                $summary['criticos'] += $count;
            } elseif ($row['severidad'] === 'alta') {
                $summary['altos'] += $count;
            }
        }

        $prefix = $wpdb->prefix . 'riverso_';
        $active = "pb.estado = 'activo' AND pb.deleted_at IS NULL AND pb.archived_at IS NULL";
        $products = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}producto_base pb WHERE {$active}"
        ));

        if ($products > 0) {
            $covered = [
                'presentaciones' => intval($wpdb->get_var(
                    "SELECT COUNT(DISTINCT pb.id)
                     FROM {$prefix}producto_base pb
                     INNER JOIN {$prefix}envases e
                       ON e.producto_base_id = pb.id AND e.activo = 1 AND e.cantidad_unidades > 0
                     WHERE {$active}"
                )),
                'codigos' => intval($wpdb->get_var(
                    "SELECT COUNT(*)
                     FROM {$prefix}producto_base pb
                     WHERE {$active}
                       AND (
                           EXISTS (
                               SELECT 1 FROM {$prefix}codigo_barra cb
                               WHERE cb.producto_base_id = pb.id
                                 AND cb.activo = 1 AND cb.estado = 'verificado'
                           )
                           OR EXISTS (
                               SELECT 1 FROM {$prefix}barcodes b
                               WHERE b.is_active = 1
                                 AND (b.product_id = pb.woocommerce_product_id OR b.sku = pb.canonical_sku)
                           )
                       )"
                )),
                'ubicaciones' => intval($wpdb->get_var(
                    "SELECT COUNT(*)
                     FROM {$prefix}producto_base pb
                     WHERE {$active}
                       AND EXISTS (
                           SELECT 1 FROM {$prefix}producto_ubicacion pu
                           WHERE pu.es_principal = 1
                             AND (pu.product_id = pb.id OR pu.product_id = pb.woocommerce_product_id)
                       )"
                )),
                'precios' => intval($wpdb->get_var(
                    "SELECT COUNT(*)
                     FROM {$prefix}producto_base pb
                     WHERE {$active}
                       AND EXISTS (
                           SELECT 1 FROM {$prefix}precios p
                           WHERE p.producto_base_id = pb.id
                             AND p.canal = 'local'
                             AND p.estado_aprobacion = 'aprobado'
                             AND p.p_asignado > 0
                       )"
                )),
            ];

            foreach ($covered as $dimension => $count) {
                $summary['dimensions'][$dimension] = round($count * 100 / $products, 1);
            }
            $summary['coverage'] = round(
                array_sum($summary['dimensions']) / count($summary['dimensions']),
                1
            );
        }

        return $summary;
    }

    public static function get_gaps($filters = [], $limit = 200) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';

        if (!self::table_exists($table)) {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['estado'])) {
            $where[] = 'estado = %s';
            $params[] = sanitize_key($filters['estado']);
        } else {
            $where[] = "estado = 'abierto'";
        }
        if (!empty($filters['regla'])) {
            $where[] = 'regla = %s';
            $params[] = sanitize_key($filters['regla']);
        }
        if (!empty($filters['severidad'])) {
            $where[] = 'severidad = %s';
            $params[] = sanitize_key($filters['severidad']);
        }

        $sql = "SELECT * FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(severidad, 'critica', 'alta', 'media', 'baja'), detectado_at ASC
                LIMIT %d";
        $params[] = min(500, max(1, intval($limit)));

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    private function collect_candidates() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $candidates = [];

        $this->append_query_results(
            $candidates,
            'producto_sin_presentacion',
            'producto_base',
            'media',
            "SELECT pb.id AS entidad_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
             WHERE pb.estado = 'activo'
               AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
               AND e.id IS NULL"
        );

        $this->append_query_results(
            $candidates,
            'presentacion_sin_cantidad',
            'presentacion',
            'alta',
            "SELECT e.id AS entidad_id, e.producto_base_id, e.sku_envase, e.codigo_proveedor,
                    e.tipo_envase, e.cantidad_unidades
             FROM {$prefix}envases e
             WHERE e.activo = 1 AND (e.cantidad_unidades IS NULL OR e.cantidad_unidades <= 0)"
        );

        $this->append_query_results(
            $candidates,
            'codigo_proveedor_sin_cantidad',
            'producto_proveedor',
            'alta',
            "SELECT pp.id AS entidad_id, pp.producto_base_id, pp.proveedor_id,
                    pp.codigo_proveedor, pp.factor_conversion
             FROM {$prefix}producto_proveedor pp
             WHERE pp.activo = 1
               AND COALESCE(pp.factor_conversion, 1) = 1
               AND (
                    pp.codigo_proveedor LIKE '%-S'
                    OR pp.codigo_proveedor LIKE '%-J'
                    OR pp.codigo_proveedor REGEXP '^K[[:alnum:]]+'
               )"
        );

        $this->append_query_results(
            $candidates,
            'codigo_barra_sin_proveedor',
            'codigo_barra',
            'alta',
            "SELECT cb.id AS entidad_id, cb.codigo, cb.producto_base_id, cb.tipo
             FROM {$prefix}codigo_barra cb
             WHERE cb.activo = 1 AND cb.estado <> 'en_desuso'
               AND cb.tipo = 'supplier' AND cb.proveedor_id IS NULL"
        );

        $this->append_query_results(
            $candidates,
            'codigo_barra_sin_cantidad',
            'codigo_barra',
            'alta',
            "SELECT cb.id AS entidad_id, cb.codigo, cb.producto_base_id,
                    cb.cantidad, cb.factor_a_unidad_base
             FROM {$prefix}codigo_barra cb
             WHERE cb.activo = 1 AND cb.estado <> 'en_desuso'
               AND (cb.cantidad <= 0 OR cb.factor_a_unidad_base <= 0)"
        );

        $this->append_query_results(
            $candidates,
            'codigo_proveedor_como_barcode',
            'codigo_barra',
            'media',
            "SELECT cb.id AS entidad_id, cb.codigo, cb.producto_base_id, cb.tipo
             FROM {$prefix}codigo_barra cb
             WHERE cb.activo = 1 AND cb.estado <> 'en_desuso'
               AND cb.tipo = 'ean13' AND cb.codigo REGEXP '[^0-9]'"
        );

        $this->append_query_results(
            $candidates,
            'producto_sin_barcode',
            'producto_base',
            'baja',
            "SELECT pb.id AS entidad_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}codigo_barra cb
               ON cb.producto_base_id = pb.id AND cb.activo = 1 AND cb.estado = 'verificado'
             WHERE pb.estado = 'activo'
               AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
               AND cb.id IS NULL
               AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}barcodes b
                    WHERE b.is_active = 1
                      AND (b.product_id = pb.woocommerce_product_id OR b.sku = pb.canonical_sku)
               )"
        );

        $this->append_query_results(
            $candidates,
            'barcode_duplicado',
            'barcode_legacy',
            'critica',
            "SELECT MIN(b.id) AS entidad_id, b.barcode, COUNT(*) AS coincidencias
             FROM {$prefix}barcodes b
             WHERE b.is_active = 1
             GROUP BY b.barcode
             HAVING COUNT(*) > 1"
        );

        $this->append_query_results(
            $candidates,
            'producto_sin_ubicacion',
            'producto_base',
            'media',
            "SELECT pb.id AS entidad_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}producto_ubicacion pu
               ON pu.es_principal = 1
              AND (pu.product_id = pb.id OR pu.product_id = pb.woocommerce_product_id)
             WHERE pb.estado = 'activo'
               AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
               AND pu.id IS NULL"
        );

        $this->append_query_results(
            $candidates,
            'producto_sin_precio_aprobado',
            'producto_base',
            'critica',
            "SELECT pb.id AS entidad_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}precios p
               ON p.producto_base_id = pb.id
              AND p.canal = 'local'
              AND p.estado_aprobacion = 'aprobado'
              AND p.p_asignado > 0
             WHERE pb.estado = 'activo'
               AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
               AND p.id IS NULL"
        );

        $this->append_query_results(
            $candidates,
            'producto_sin_familia',
            'producto_base',
            'baja',
            "SELECT pb.id AS entidad_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}equivalence_members em
               ON em.producto_base_id = pb.id AND em.activo = 1
              LEFT JOIN {$prefix}equivalence_groups eg
                ON eg.id = em.grupo_id AND eg.activo = 1
             WHERE pb.estado = 'activo'
               AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
               AND pb.familia_decision = 'requiere'
               AND em.id IS NULL
               AND pb.unit_of_grupo_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}equivalence_groups eg3
                   WHERE eg3.unit_producto_base_id = pb.id AND eg3.activo = 1
               )"
        );

        $codes = $wpdb->get_results(
            "SELECT id, codigo, producto_base_id, tipo
             FROM {$prefix}codigo_barra
             WHERE activo = 1 AND estado <> 'en_desuso' AND tipo IN ('ean13', 'internal')
             LIMIT 10000",
            ARRAY_A
        );
        foreach ($codes as $code) {
            if (strlen($code['codigo']) === 13 && !$this->is_valid_ean13($code['codigo'])) {
                $this->add_candidate($candidates, 'ean13_invalido', 'codigo_barra', $code['id'], 'alta', $code);
            }
        }

        $products = $wpdb->get_results(
            "SELECT id, canonical_sku
             FROM {$prefix}producto_base
             WHERE estado = 'activo'
               AND deleted_at IS NULL AND archived_at IS NULL
               AND permite_ean13_personalizado = 1",
            ARRAY_A
        );
        foreach ($products as $product) {
            $sku = (string) $product['canonical_sku'];
            if (!preg_match('/^\d{1,6}$/', $sku)) {
                $alias = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}ean_aliases WHERE producto_base_id = %d AND activo = 1",
                    $product['id']
                ));
                if (!$alias) {
                    $this->add_candidate(
                        $candidates,
                        'sku_ean_no_representable',
                        'producto_base',
                        $product['id'],
                        'alta',
                        ['canonical_sku' => $sku]
                    );
                }
            }
        }

        return $candidates;
    }

    private function append_query_results(&$candidates, $rule, $entity_type, $severity, $sql) {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $this->add_candidate($candidates, $rule, $entity_type, $row['entidad_id'], $severity, $row);
        }
    }

    private function add_candidate(&$candidates, $rule, $entity_type, $entity_id, $severity, $detail) {
        unset($detail['entidad_id']);
        ksort($detail);
        $fingerprint = hash('sha256', wp_json_encode($detail));
        $key = implode('|', [$rule, $entity_type, intval($entity_id), $fingerprint]);

        if ($this->active_scan_token) {
            $this->upsert_streamed_candidate([
                'regla' => $rule,
                'entidad_tipo' => $entity_type,
                'entidad_id' => intval($entity_id),
                'fingerprint' => $fingerprint,
                'severidad' => $severity,
                'detalle_json' => wp_json_encode($detail),
            ]);
            return;
        }

        $candidates[$key] = [
            'regla' => $rule,
            'entidad_tipo' => $entity_type,
            'entidad_id' => intval($entity_id),
            'fingerprint' => $fingerprint,
            'severidad' => $severity,
            'detalle_json' => wp_json_encode($detail),
        ];
    }

    private function upsert_streamed_candidate($candidate) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (regla, entidad_tipo, entidad_id, fingerprint, severidad, estado,
                 detalle_json, origen, detectado_at, visto_ultima_vez_at, scan_token)
             VALUES (%s, %s, %d, %s, %s, 'abierto', %s, 'scanner', %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                severidad = VALUES(severidad),
                estado = IF(estado = 'ignorado' AND (ignorado_hasta IS NULL OR ignorado_hasta > VALUES(visto_ultima_vez_at)), 'ignorado', 'abierto'),
                detalle_json = VALUES(detalle_json),
                visto_ultima_vez_at = VALUES(visto_ultima_vez_at),
                scan_token = VALUES(scan_token),
                resuelto_at = NULL",
            $candidate['regla'],
            $candidate['entidad_tipo'],
            $candidate['entidad_id'],
            $candidate['fingerprint'],
            $candidate['severidad'],
            $candidate['detalle_json'],
            $now,
            $now,
            $this->active_scan_token
        ));
        if ($result === false) {
            throw new RuntimeException($wpdb->last_error ?: 'No se pudo guardar una brecha.');
        }
        $this->active_scan_detected++;
    }

    private function finish_streamed_scan() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';
        $now = current_time('mysql');
        $resolved = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET estado = 'resuelto', resuelto_at = %s
             WHERE origen = 'scanner' AND estado = 'abierto'
               AND (scan_token IS NULL OR scan_token <> %s)",
            $now,
            $this->active_scan_token
        ));
        if ($resolved === false) {
            throw new RuntimeException($wpdb->last_error ?: 'No se pudieron cerrar brechas resueltas.');
        }

        return [
            'detected' => $this->active_scan_detected,
            'resolved' => intval($resolved),
            'finished_at' => $now,
        ];
    }

    private function sync_candidates($candidates) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';
        $now = current_time('mysql');
        $existing = $wpdb->get_results(
            "SELECT id, regla, entidad_tipo, entidad_id, fingerprint, estado
             FROM {$table}
             WHERE origen = 'scanner' AND estado IN ('abierto', 'ignorado')",
            ARRAY_A
        );
        $existing_by_key = [];
        foreach ($existing as $row) {
            $key = implode('|', [$row['regla'], $row['entidad_tipo'], intval($row['entidad_id']), $row['fingerprint']]);
            $existing_by_key[$key] = $row;
        }

        $detected = 0;
        foreach ($candidates as $key => $candidate) {
            $state = 'abierto';
            if (isset($existing_by_key[$key]) && $existing_by_key[$key]['estado'] === 'ignorado') {
                $state = 'ignorado';
            }

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (regla, entidad_tipo, entidad_id, fingerprint, severidad, estado,
                     detalle_json, origen, detectado_at, visto_ultima_vez_at)
                 VALUES (%s, %s, %d, %s, %s, %s, %s, 'scanner', %s, %s)
                 ON DUPLICATE KEY UPDATE
                    severidad = VALUES(severidad),
                    estado = IF(estado = 'ignorado' AND (ignorado_hasta IS NULL OR ignorado_hasta > VALUES(visto_ultima_vez_at)), 'ignorado', 'abierto'),
                    detalle_json = VALUES(detalle_json),
                    visto_ultima_vez_at = VALUES(visto_ultima_vez_at),
                    resuelto_at = NULL",
                $candidate['regla'],
                $candidate['entidad_tipo'],
                $candidate['entidad_id'],
                $candidate['fingerprint'],
                $candidate['severidad'],
                $state,
                $candidate['detalle_json'],
                $now,
                $now
            ));
            $detected++;
            unset($existing_by_key[$key]);
        }

        $resolved = 0;
        foreach ($existing_by_key as $stale) {
            if ($stale['estado'] !== 'abierto') {
                continue;
            }
            $updated = $wpdb->update(
                $table,
                ['estado' => 'resuelto', 'resuelto_at' => $now],
                ['id' => intval($stale['id'])],
                ['%s', '%s'],
                ['%d']
            );
            if ($updated) {
                $resolved++;
            }
        }

        return ['detected' => $detected, 'resolved' => $resolved, 'finished_at' => $now];
    }

    private function create_grouped_tasks() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!class_exists('Riverso_Task_Service')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/tasks/class-task-service.php';
        }

        $rules = $wpdb->get_results(
            "SELECT regla, MAX(severidad = 'critica') AS has_critical,
                    SUM(severidad = 'alta') AS high_count, COUNT(*) AS total
             FROM {$prefix}data_gaps
             WHERE estado = 'abierto' AND severidad IN ('critica', 'alta')
             GROUP BY regla",
            ARRAY_A
        );

        foreach ($rules as $rule) {
            $reference_id = intval(sprintf('%u', crc32($rule['regla'])));
            $task_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}tareas
                 WHERE referencia_tipo = 'data_gap_rule'
                   AND referencia_id = %d
                   AND estado IN ('pendiente', 'en_progreso')
                 LIMIT 1",
                $reference_id
            ));

            if (!$task_id) {
                $priority = intval($rule['has_critical']) ? 'urgente' : 'alta';
                $created = Riverso_Task_Service::request(
                    'revisar_calidad_catalogo',
                    sprintf('Revisar catálogo: %s (%d pendientes)', $rule['regla'], $rule['total']),
                    [
                        'descripcion' => 'Brechas agrupadas detectadas por el scanner de salud del catálogo.',
                        'prioridad' => $priority,
                        'referencia_tipo' => 'data_gap_rule',
                        'referencia_id' => $reference_id,
                        'created_by_system' => 1,
                        'requires_human_review' => 1,
                        'datos_extra' => [
                            'regla' => $rule['regla'],
                            'gap_count' => intval($rule['total']),
                        ],
                    ]
                );
                if (!is_wp_error($created) && $created) {
                    $task_id = intval($created);
                }
            }

            if ($task_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$prefix}data_gaps
                     SET tarea_id = %d
                     WHERE regla = %s AND estado = 'abierto' AND tarea_id IS NULL",
                    $task_id,
                    $rule['regla']
                ));
            }
        }
    }

    private function is_valid_ean13($code) {
        if (!preg_match('/^\d{13}$/', (string) $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($code[$i]) * ($i % 2 === 0 ? 1 : 3);
        }
        return intval($code[12]) === (10 - ($sum % 10)) % 10;
    }

    private static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
