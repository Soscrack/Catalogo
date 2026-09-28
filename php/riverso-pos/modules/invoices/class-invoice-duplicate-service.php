<?php
/**
 * Detección y fusión de facturas duplicadas (mismo DTE + RUT + folio sin ceros).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Invoice_Duplicate_Service {

    private static $instance = null;

    /** @var Riverso_Invoice_Module|null */
    private $invoices = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function set_invoices($module) {
        $this->invoices = $module;
        return $this;
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Folio numérico sin ceros a la izquierda (para agrupar).
     */
    public function folio_key($folio) {
        if (function_exists('riverso_normalize_folio')) {
            return riverso_normalize_folio($folio);
        }
        $f = preg_replace('/[^0-9A-Za-z]/', '', (string) $folio);
        if ($f !== '' && ctype_digit($f)) {
            $f = ltrim($f, '0');
            if ($f === '') {
                $f = '0';
            }
        }
        return $f;
    }

    private function rut_digits($rut) {
        if (function_exists('riverso_factura_rut_digits')) {
            return riverso_factura_rut_digits($rut);
        }
        return strtoupper(preg_replace('/[^0-9K]/', '', (string) $rut));
    }

    /**
     * Lista pares duplicados con preview.
     *
     * @return array{pairs:array,count:int}
     */
    public function list_pairs() {
        $groups = $this->find_duplicate_groups();
        $pairs = [];
        foreach ($groups as $group) {
            $ids = $group['ids'];
            // Emparejar el primero (más reciente) con cada otro, o todos contra todos en pares.
            // Para N>2: generar pares (ids[0], ids[i]) con preview.
            $base = (int) $ids[0];
            for ($i = 1, $n = count($ids); $i < $n; $i++) {
                $preview = $this->preview_pair($base, (int) $ids[$i]);
                if (!empty($preview['error'])) {
                    continue;
                }
                $pairs[] = $preview;
            }
        }
        return [
            'pairs' => $pairs,
            'count' => count($pairs),
        ];
    }

    /**
     * Grupos de facturas con mismo tipo_dte + RUT + folio normalizado.
     *
     * @return array<int,array{tipo_dte:int,rut_digits:string,folio_key:string,ids:int[]}>
     */
    public function find_duplicate_groups() {
        global $wpdb;
        $prefix = $this->prefix();

        // Agrupar en SQL por folio sin ceros (solo numéricos); alfanuméricos se agrupan por folio exacto.
        $rows = $wpdb->get_results(
            "SELECT id, tipo_dte, folio, rut_emisor, origen_ingreso, estado, monto_total, created_at
             FROM {$prefix}facturas
             WHERE folio IS NOT NULL AND folio <> ''
               AND tipo_dte IS NOT NULL AND tipo_dte > 0
               AND rut_emisor IS NOT NULL AND rut_emisor <> ''
             ORDER BY id DESC
             LIMIT 20000",
            ARRAY_A
        ) ?: [];

        $buckets = [];
        foreach ($rows as $row) {
            $tipo = (int) $row['tipo_dte'];
            $rut = $this->rut_digits($row['rut_emisor'] ?? '');
            $fkey = $this->folio_key($row['folio'] ?? '');
            if ($tipo <= 0 || $rut === '' || $fkey === '') {
                continue;
            }
            $key = $tipo . '|' . $rut . '|' . $fkey;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'tipo_dte'   => $tipo,
                    'rut_digits' => $rut,
                    'folio_key'  => $fkey,
                    'ids'        => [],
                ];
            }
            $buckets[$key]['ids'][] = (int) $row['id'];
        }

        $groups = [];
        foreach ($buckets as $bucket) {
            if (count($bucket['ids']) > 1) {
                $groups[] = $bucket;
            }
        }
        return $groups;
    }

    /**
     * Resumen + sugerencia de sobreviviente + bloqueos.
     *
     * @return array
     */
    public function preview_pair($id_a, $id_b) {
        $id_a = (int) $id_a;
        $id_b = (int) $id_b;
        if ($id_a <= 0 || $id_b <= 0 || $id_a === $id_b) {
            return ['error' => 'IDs inválidos'];
        }

        $sum_a = $this->summarize_factura($id_a);
        $sum_b = $this->summarize_factura($id_b);
        if (!$sum_a || !$sum_b) {
            return ['error' => 'Factura no encontrada'];
        }

        // Misma clave DTE?
        if ((int) $sum_a['tipo_dte'] !== (int) $sum_b['tipo_dte']
            || $this->rut_digits($sum_a['rut_emisor']) !== $this->rut_digits($sum_b['rut_emisor'])
            || $this->folio_key($sum_a['folio']) !== $this->folio_key($sum_b['folio'])) {
            return ['error' => 'Las facturas no son el mismo DTE'];
        }

        $choice = $this->choose_survivor($sum_a, $sum_b);
        $hard = $this->hard_block_reasons($sum_a, $sum_b);
        $warn = $this->soft_warnings($sum_a, $sum_b);

        return [
            'folio_key'           => $this->folio_key($sum_a['folio']),
            'tipo_dte'            => (int) $sum_a['tipo_dte'],
            'rut_emisor'          => $sum_a['rut_emisor'],
            'razon_social'        => $sum_a['razon_social_emisor'] ?: $sum_b['razon_social_emisor'],
            'factura_a'           => $sum_a,
            'factura_b'           => $sum_b,
            'suggested_survivor'  => $choice['survivor_id'],
            'suggested_loser'     => $choice['loser_id'],
            'suggestion_reason'   => $choice['reason'],
            'blocked'             => !empty($hard),
            'block_reasons'       => $hard,
            'warnings'            => $warn,
        ];
    }

    /**
     * @return array|null
     */
    private function summarize_factura($factura_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $factura_id = (int) $factura_id;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }

        $items_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = %d",
            $factura_id
        ));
        $qty_recibida = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cantidad_recibida), 0) FROM {$prefix}factura_items WHERE factura_id = %d",
            $factura_id
        ));

        $is_stub = function_exists('riverso_factura_db_is_sii_rescued_stub')
            && riverso_factura_db_is_sii_rescued_stub($factura_id);

        $proceso = null;
        $proceso_table = $prefix . 'precio_folio_proceso';
        if ($this->table_exists($proceso_table)) {
            $proceso = $wpdb->get_row($wpdb->prepare(
                "SELECT estado, estado_manual, completed_at FROM {$proceso_table} WHERE factura_id = %d",
                $factura_id
            ), ARRAY_A);
        }

        $precio_hist = 0;
        $hist_table = $prefix . 'precio_historial';
        if ($this->table_exists($hist_table)) {
            $precio_hist = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$hist_table}
                 WHERE source_document_id = %d AND source_type IN ('folio','invoice')",
                $factura_id
            ));
        }

        $analisis = false;
        $analisis_table = $prefix . 'precio_folio_analisis';
        if ($this->table_exists($analisis_table)) {
            $analisis = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$analisis_table} WHERE factura_id = %d LIMIT 1",
                $factura_id
            ));
        }

        $cost_hist = 0;
        $cost_table = $prefix . 'cost_history';
        if ($this->table_exists($cost_table)) {
            $cost_hist = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$cost_table}
                 WHERE source_type = 'invoice' AND source_document_id = %d",
                $factura_id
            ));
        }

        $pagos = 0;
        $pago_table = $prefix . 'factura_pago_documentos';
        if ($this->table_exists($pago_table)) {
            $pagos = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$pago_table} WHERE factura_id = %d",
                $factura_id
            ));
        }

        $nc_refs = 0;
        $ref_table = $prefix . 'factura_referencias';
        if ($this->table_exists($ref_table)) {
            $nc_refs = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ref_table}
                 WHERE factura_id = %d OR factura_origen_id = %d",
                $factura_id,
                $factura_id
            ));
        }

        $flete = 0;
        $flete_table = $prefix . 'factura_flete_vinculos';
        if ($this->table_exists($flete_table)) {
            $flete = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$flete_table}
                 WHERE factura_envio_id = %d OR factura_productos_id = %d",
                $factura_id,
                $factura_id
            ));
        }

        $scans = 0;
        $scan_table = $prefix . 'documentos_escaneados';
        if ($this->table_exists($scan_table)) {
            $scans = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$scan_table}
                 WHERE factura_id = %d AND estado_revision NOT IN ('descartado')",
                $factura_id
            ));
        }

        $proceso_estado = $proceso['estado'] ?? null;
        $has_pricing = $precio_hist > 0
            || $analisis
            || ($proceso && !in_array($proceso_estado, [null, '', 'pendiente'], true));
        $pricing_completed = $proceso && in_array($proceso_estado, ['ingresada', 'ingresada_manual', 'anulada'], true);
        $has_reception = $qty_recibida > 0
            || in_array($row['estado'] ?? '', ['in_reception', 'reception_complete', 'pending_approval', 'approved'], true);

        return [
            'id'                  => $factura_id,
            'folio'               => (string) ($row['folio'] ?? ''),
            'tipo_dte'            => (int) ($row['tipo_dte'] ?? 0),
            'rut_emisor'          => (string) ($row['rut_emisor'] ?? ''),
            'razon_social_emisor' => (string) ($row['razon_social_emisor'] ?? ''),
            'origen_ingreso'      => (string) ($row['origen_ingreso'] ?? 'xml'),
            'origen_label'        => function_exists('riverso_factura_origen_label')
                ? riverso_factura_origen_label($row['origen_ingreso'] ?? 'xml')
                : (string) ($row['origen_ingreso'] ?? 'xml'),
            'estado'              => (string) ($row['estado'] ?? ''),
            'monto_total'         => (float) ($row['monto_total'] ?? 0),
            'fecha_emision'       => (string) ($row['fecha_emision'] ?? ''),
            'items_count'         => $items_count,
            'qty_recibida'        => $qty_recibida,
            'is_stub'             => (bool) $is_stub,
            'has_detail'          => $items_count > 0 && !$is_stub,
            'proceso_estado'      => $proceso_estado,
            'has_pricing'         => (bool) $has_pricing,
            'pricing_completed'   => (bool) $pricing_completed,
            'precio_historial'    => $precio_hist,
            'tiene_analisis'      => (bool) $analisis,
            'cost_history'        => $cost_hist,
            'pagos'               => $pagos,
            'nc_refs'             => $nc_refs,
            'flete_vinculos'      => $flete,
            'scans'               => $scans,
            'has_reception'       => (bool) $has_reception,
            'modo_ingreso'        => (string) ($row['modo_ingreso'] ?? ''),
        ];
    }

    /**
     * @return array{survivor_id:int,loser_id:int,reason:string}
     */
    private function choose_survivor(array $a, array $b) {
        // 1) Precios o recepción
        $score = function (array $s) {
            $n = 0;
            if (!empty($s['pricing_completed'])) {
                $n += 100;
            }
            if (!empty($s['has_pricing'])) {
                $n += 50;
            }
            if (!empty($s['has_reception'])) {
                $n += 40;
            }
            if ((float) ($s['qty_recibida'] ?? 0) > 0) {
                $n += 30;
            }
            if ((int) ($s['precio_historial'] ?? 0) > 0) {
                $n += 20;
            }
            if ((int) ($s['pagos'] ?? 0) > 0) {
                $n += 15;
            }
            $origen = $s['origen_ingreso'] ?? '';
            if (in_array($origen, ['xml', 'facto', 'ambos'], true)) {
                $n += 10;
            }
            if (!empty($s['has_detail'])) {
                $n += 5 + min(20, (int) ($s['items_count'] ?? 0));
            }
            // Preferir folio sin ceros (más "limpio")
            $folio = (string) ($s['folio'] ?? '');
            if ($folio !== '' && ctype_digit($folio) && $folio[0] !== '0') {
                $n += 2;
            }
            return $n;
        };

        $sa = $score($a);
        $sb = $score($b);
        if ($sa === $sb) {
            // Preferir XML/FACTO
            $oa = in_array($a['origen_ingreso'] ?? '', ['xml', 'facto', 'ambos'], true);
            $ob = in_array($b['origen_ingreso'] ?? '', ['xml', 'facto', 'ambos'], true);
            if ($oa && !$ob) {
                $sa++;
            } elseif ($ob && !$oa) {
                $sb++;
            } else {
                // Más ítems con detalle
                $ia = !empty($a['has_detail']) ? (int) $a['items_count'] : 0;
                $ib = !empty($b['has_detail']) ? (int) $b['items_count'] : 0;
                if ($ia !== $ib) {
                    if ($ia > $ib) {
                        $sa++;
                    } else {
                        $sb++;
                    }
                } else {
                    // ID menor (más antigua = suele ser la primera ingresada)
                    if ((int) $a['id'] < (int) $b['id']) {
                        $sa++;
                    } else {
                        $sb++;
                    }
                }
            }
        }

        if ($sa >= $sb) {
            $reason = 'Preferida por precios/recepción/origen XML';
            if (!empty($a['has_pricing'])) {
                $reason = 'Conserva datos de precios (Procesar folios / historial)';
            } elseif (!empty($a['has_reception'])) {
                $reason = 'Conserva recepción';
            } elseif (in_array($a['origen_ingreso'] ?? '', ['xml', 'facto', 'ambos'], true)) {
                $reason = 'Origen XML/FACTO preferido';
            }
            return [
                'survivor_id' => (int) $a['id'],
                'loser_id'    => (int) $b['id'],
                'reason'      => $reason,
            ];
        }

        $reason = 'Preferida por precios/recepción/origen XML';
        if (!empty($b['has_pricing'])) {
            $reason = 'Conserva datos de precios (Procesar folios / historial)';
        } elseif (!empty($b['has_reception'])) {
            $reason = 'Conserva recepción';
        } elseif (in_array($b['origen_ingreso'] ?? '', ['xml', 'facto', 'ambos'], true)) {
            $reason = 'Origen XML/FACTO preferido';
        }
        return [
            'survivor_id' => (int) $b['id'],
            'loser_id'    => (int) $a['id'],
            'reason'      => $reason,
        ];
    }

    /**
     * Bloqueos duros: no se muestra Unir.
     *
     * @return string[]
     */
    private function hard_block_reasons(array $a, array $b) {
        $reasons = [];

        if (!empty($a['pricing_completed']) && !empty($b['pricing_completed'])) {
            $reasons[] = 'Ambas tienen folio de precios ya ingresado.';
        }
        if (!empty($a['has_reception']) && !empty($b['has_reception'])) {
            $reasons[] = 'Ambas facturas tienen recepción; no se puede unir automáticamente.';
        }

        $ta = round((float) $a['monto_total'], 2);
        $tb = round((float) $b['monto_total'], 2);
        if ($ta > 0 && $tb > 0 && abs($ta - $tb) > 1.0) {
            $reasons[] = sprintf(
                'Totales distintos ($%s vs $%s).',
                number_format($ta, 0, ',', '.'),
                number_format($tb, 0, ',', '.')
            );
        }

        if ((int) $a['pagos'] > 0 && (int) $b['pagos'] > 0) {
            $reasons[] = 'Ambas facturas tienen pagos registrados.';
        }

        return $reasons;
    }

    /**
     * Advertencias: se muestra Unir, con confirmación reforzada.
     *
     * @return string[]
     */
    private function soft_warnings(array $a, array $b) {
        $warnings = [];

        // Proceso no terminado (p.ej. con_error) en ambas: avisar, no bloquear.
        if (!empty($a['has_pricing']) && !empty($b['has_pricing'])
            && (empty($a['pricing_completed']) || empty($b['pricing_completed']))) {
            $warnings[] = 'Ambas tienen sesión de precios (aún no ingresada). Se conserva la de la sobreviviente.';
        }

        return $warnings;
    }

    /**
     * @deprecated Usar hard_block_reasons
     * @return string[]
     */
    private function block_reasons(array $a, array $b) {
        return $this->hard_block_reasons($a, $b);
    }

    /**
     * Une loser dentro de survivor. Revalida bloqueos en servidor.
     *
     * @param int   $survivor_id
     * @param int   $loser_id
     * @param array $options {force?:bool}
     * @return array|WP_Error
     */
    public function merge_pair($survivor_id, $loser_id, array $options = []) {
        global $wpdb;
        $prefix = $this->prefix();
        $survivor_id = (int) $survivor_id;
        $loser_id = (int) $loser_id;

        if ($survivor_id <= 0 || $loser_id <= 0 || $survivor_id === $loser_id) {
            return new WP_Error('invalid', 'IDs de factura inválidos');
        }

        $preview = $this->preview_pair($survivor_id, $loser_id);
        if (!empty($preview['error'])) {
            return new WP_Error('preview', $preview['error']);
        }
        if (!empty($preview['blocked']) && empty($options['force'])) {
            return new WP_Error(
                'blocked',
                'Par bloqueado: ' . implode(' ', $preview['block_reasons'] ?? []),
                ['block_reasons' => $preview['block_reasons'] ?? []]
            );
        }

        // Asegurar que survivor/loser coinciden con el preview (o son el par invertido validado).
        $valid_ids = [(int) $preview['factura_a']['id'], (int) $preview['factura_b']['id']];
        if (!in_array($survivor_id, $valid_ids, true) || !in_array($loser_id, $valid_ids, true)) {
            return new WP_Error('mismatch', 'Las facturas no forman un par duplicado válido');
        }

        $sum_s = ($preview['factura_a']['id'] === $survivor_id) ? $preview['factura_a'] : $preview['factura_b'];
        $sum_l = ($preview['factura_a']['id'] === $loser_id) ? $preview['factura_a'] : $preview['factura_b'];

        // Nunca borrar la que tiene precios completados si la otra no es la sugerida con pricing
        if (!empty($sum_l['pricing_completed']) && empty($sum_s['pricing_completed'])) {
            return new WP_Error(
                'pricing_guard',
                'La factura a eliminar tiene precios ingresados. Invertí sobreviviente/eliminada.'
            );
        }

        $lock_ids = [$survivor_id, $loser_id];
        sort($lock_ids, SORT_NUMERIC);
        foreach ($lock_ids as $lid) {
            if (!$this->acquire_lock($lid)) {
                foreach ($lock_ids as $prev) {
                    if ($prev === $lid) {
                        break;
                    }
                    $this->release_lock($prev);
                }
                return new WP_Error('locked', 'Una de las facturas se está actualizando. Reintentá en unos segundos.');
            }
        }

        try {
            $survivor = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}facturas WHERE id = %d",
                $survivor_id
            ), ARRAY_A);
            $loser = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}facturas WHERE id = %d",
                $loser_id
            ), ARRAY_A);
            if (!$survivor || !$loser) {
                return new WP_Error('not_found', 'Factura no encontrada');
            }

            $wpdb->query('START TRANSACTION');

            // 1) Si survivor es stub y loser tiene detalle, y survivor no tiene precios completados:
            //    mover ítems del loser al survivor (tras borrar stub).
            $items_moved = false;
            $survivor_is_stub = function_exists('riverso_factura_db_is_sii_rescued_stub')
                && riverso_factura_db_is_sii_rescued_stub($survivor_id);
            $loser_is_stub = function_exists('riverso_factura_db_is_sii_rescued_stub')
                && riverso_factura_db_is_sii_rescued_stub($loser_id);

            if ($survivor_is_stub && !$loser_is_stub && empty($sum_s['pricing_completed'])) {
                $moved = $this->move_items_from_loser($survivor_id, $loser_id);
                if (is_wp_error($moved)) {
                    $wpdb->query('ROLLBACK');
                    return $moved;
                }
                $items_moved = !empty($moved['moved']);
            }

            // Mapa de ítems loser → survivor (por linea|codigo) para re-apuntar FKs
            $item_map = $this->build_item_id_map($loser_id, $survivor_id);

            // 2) Cabecera fiscal: si survivor es escaneo y loser es xml/facto, copiar campos fiscales del XML
            $this->maybe_copy_xml_header($survivor_id, $survivor, $loser);

            // 3) Re-apuntar dependencias
            $this->repoint_scans($survivor_id, $loser_id);
            $this->repoint_payments($survivor_id, $loser_id);
            $this->repoint_referencias($survivor_id, $loser_id);
            $this->repoint_flete($survivor_id, $loser_id);
            $this->repoint_facto($survivor_id, $loser_id);
            $this->repoint_tareas_factura($survivor_id, $loser_id);
            $this->repoint_producto_proveedor($survivor_id, $loser_id, $item_map);
            $this->repoint_tareas_items($item_map);
            $this->repoint_pricing($survivor_id, $loser_id, $sum_s);
            $this->repoint_factura_productos_id($survivor_id, $loser_id);

            // 4) Borrar cost_history del loser (evitar doble costo); no tocar precios
            $loser_item_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$prefix}factura_items WHERE factura_id = %d",
                $loser_id
            )) ?: [];
            $this->delete_loser_cost_history($loser_id, $loser_item_ids);

            // 5) Borrar ítems restantes del loser y la factura
            $wpdb->delete("{$prefix}factura_items", ['factura_id' => $loser_id], ['%d']);
            $loser_snapshot = $loser;
            $deleted = $wpdb->delete("{$prefix}facturas", ['id' => $loser_id], ['%d']);
            if ($deleted === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Error eliminando factura duplicada: ' . $wpdb->last_error);
            }

            // 6) Origen ambos + folio normalizado preferido
            $origen_s = $survivor['origen_ingreso'] ?? 'xml';
            $origen_l = $loser['origen_ingreso'] ?? 'xml';
            $has_xml_side = in_array($origen_s, ['xml', 'facto', 'ambos'], true)
                || in_array($origen_l, ['xml', 'facto', 'ambos'], true);
            $has_scan_side = in_array($origen_s, ['escaneo', 'ambos'], true)
                || in_array($origen_l, ['escaneo', 'ambos'], true)
                || (int) ($sum_s['scans'] ?? 0) > 0
                || (int) ($sum_l['scans'] ?? 0) > 0;
            if ($has_xml_side && $has_scan_side) {
                $new_origen = 'ambos';
            } elseif ($has_scan_side && !$has_xml_side) {
                $new_origen = 'escaneo';
            } elseif (in_array($origen_s, ['facto', 'xml'], true)) {
                $new_origen = $origen_s;
            } elseif (in_array($origen_l, ['facto', 'xml'], true)) {
                $new_origen = $origen_l;
            } else {
                $new_origen = $origen_s ?: 'xml';
            }

            $folio_pref = $this->prefer_folio($survivor['folio'] ?? '', $loser['folio'] ?? '');
            $wpdb->update("{$prefix}facturas", [
                'origen_ingreso' => $new_origen,
                'folio'          => $folio_pref,
            ], ['id' => $survivor_id]);

            if (function_exists('riverso_factura_mark_xml_attached')) {
                riverso_factura_mark_xml_attached($survivor_id);
            }
            if (function_exists('riverso_factura_mark_scan_attached')) {
                riverso_factura_mark_scan_attached($survivor_id);
            }

            $wpdb->query('COMMIT');

            if ($this->invoices && method_exists($this->invoices, 'update_invoice_status')) {
                $this->invoices->update_invoice_status($survivor_id);
            }

            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'invoice_duplicate_merged',
                    'invoice',
                    $survivor_id,
                    $loser_snapshot,
                    [
                        'survivor_id'  => $survivor_id,
                        'loser_id'     => $loser_id,
                        'folio'        => $folio_pref,
                        'items_moved'  => $items_moved,
                        'suggestion'   => $preview['suggestion_reason'] ?? '',
                    ],
                    sprintf(
                        'Duplicado unido: folio %s — eliminada #%d, sobrevive #%d',
                        $folio_pref,
                        $loser_id,
                        $survivor_id
                    )
                );
            }

            return [
                'survivor_id' => $survivor_id,
                'loser_id'    => $loser_id,
                'folio'       => $folio_pref,
                'items_moved' => $items_moved,
                'message'     => sprintf(
                    'Facturas unidas. Sobrevive folio %s (#%d); eliminada #%d.',
                    $folio_pref,
                    $survivor_id,
                    $loser_id
                ),
            ];
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('exception', $e->getMessage());
        } finally {
            foreach (array_reverse($lock_ids) as $lid) {
                $this->release_lock($lid);
            }
        }
    }

    private function acquire_lock($factura_id, $timeout = 20) {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            'riverso_factura_' . (int) $factura_id,
            (int) $timeout
        ));
        return (int) $got === 1;
    }

    private function release_lock($factura_id) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            'riverso_factura_' . (int) $factura_id
        ));
    }

    private function prefer_folio($a, $b) {
        $a = (string) $a;
        $b = (string) $b;
        $na = $this->folio_key($a);
        // Preferir el que ya está sin ceros a la izquierda
        if ($a !== '' && ctype_digit($a) && $a === $na) {
            return $a;
        }
        if ($b !== '' && ctype_digit($b) && $b === $this->folio_key($b)) {
            return $b;
        }
        return $na !== '' ? $na : ($a !== '' ? $a : $b);
    }

    /**
     * Borra ítems stub del survivor y mueve ítems del loser.
     *
     * @return array|WP_Error
     */
    private function move_items_from_loser($survivor_id, $loser_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $survivor_id = (int) $survivor_id;
        $loser_id = (int) $loser_id;

        $loser_items = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = %d",
            $loser_id
        ));
        if ($loser_items <= 0) {
            return ['moved' => false];
        }

        // Borrar tareas de ítems stub del survivor
        $stub_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}factura_items WHERE factura_id = %d",
            $survivor_id
        ));
        if ($stub_ids) {
            $in = implode(',', array_map('intval', $stub_ids));
            $wpdb->query(
                "DELETE FROM {$prefix}tareas
                 WHERE referencia_tipo = 'factura_item' AND referencia_id IN ({$in})"
            );
            // cost_history de ítems stub
            if ($this->table_exists($prefix . 'cost_history')) {
                $wpdb->query(
                    "DELETE FROM {$prefix}cost_history
                     WHERE source_type = 'invoice' AND source_item_id IN ({$in})"
                );
            }
        }
        $wpdb->delete("{$prefix}factura_items", ['factura_id' => $survivor_id], ['%d']);
        if ($this->table_exists($prefix . 'cost_history')) {
            $wpdb->delete("{$prefix}cost_history", [
                'source_type' => 'invoice',
                'source_document_id' => $survivor_id,
            ], ['%s', '%d']);
        }

        $wpdb->update(
            "{$prefix}factura_items",
            ['factura_id' => $survivor_id],
            ['factura_id' => $loser_id],
            ['%d'],
            ['%d']
        );

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = %d",
            $survivor_id
        ));
        $wpdb->update("{$prefix}facturas", [
            'items_total' => $count,
        ], ['id' => $survivor_id]);

        // cost_history del loser → survivor
        if ($this->table_exists($prefix . 'cost_history')) {
            $wpdb->update(
                "{$prefix}cost_history",
                ['source_document_id' => $survivor_id],
                ['source_type' => 'invoice', 'source_document_id' => $loser_id],
                ['%d'],
                ['%s', '%d']
            );
        }

        return ['moved' => true, 'items' => $count];
    }

    /**
     * @return array<int,int> loser_item_id => survivor_item_id
     */
    private function build_item_id_map($loser_id, $survivor_id) {
        global $wpdb;
        $prefix = $this->prefix();

        $loser_items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, numero_linea, codigo_proveedor FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $loser_id
        ), ARRAY_A) ?: [];
        $surv_items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, numero_linea, codigo_proveedor FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $survivor_id
        ), ARRAY_A) ?: [];

        // Si los ítems ya se movieron, loser estará vacío — mapa identidad no aplica
        $surv_by_key = [];
        foreach ($surv_items as $ni) {
            $key = ((int) ($ni['numero_linea'] ?? 0)) . '|' . strtoupper(trim((string) ($ni['codigo_proveedor'] ?? '')));
            $surv_by_key[$key] = (int) $ni['id'];
        }

        $map = [];
        foreach ($loser_items as $oi) {
            $key = ((int) ($oi['numero_linea'] ?? 0)) . '|' . strtoupper(trim((string) ($oi['codigo_proveedor'] ?? '')));
            if (isset($surv_by_key[$key])) {
                $map[(int) $oi['id']] = $surv_by_key[$key];
            }
        }
        return $map;
    }

    private function maybe_copy_xml_header($survivor_id, array $survivor, array $loser) {
        global $wpdb;
        $prefix = $this->prefix();
        $origen_s = $survivor['origen_ingreso'] ?? '';
        $origen_l = $loser['origen_ingreso'] ?? '';

        $loser_is_xml = in_array($origen_l, ['xml', 'facto', 'ambos'], true);
        $surv_is_scan = in_array($origen_s, ['escaneo', 'ambos'], true)
            || empty($survivor['xml_hash']);

        if (!$loser_is_xml) {
            return;
        }
        // Solo pisar cabecera si survivor parece escaneo-primary
        if ($origen_s === 'xml' || $origen_s === 'facto') {
            // Survivor ya es XML: opcionalmente copiar xml_hash si falta
            $update = [];
            if (empty($survivor['xml_hash']) && !empty($loser['xml_hash'])) {
                $update['xml_hash'] = $loser['xml_hash'];
            }
            if ($update) {
                $wpdb->update("{$prefix}facturas", $update, ['id' => (int) $survivor_id]);
            }
            return;
        }

        if (!$surv_is_scan && $origen_s !== 'escaneo') {
            return;
        }

        $update = [
            'tipo_dte'              => (int) ($loser['tipo_dte'] ?? $survivor['tipo_dte']),
            'folio'                 => $this->prefer_folio($loser['folio'] ?? '', $survivor['folio'] ?? ''),
            'rut_emisor'            => $loser['rut_emisor'] ?? $survivor['rut_emisor'],
            'razon_social_emisor'   => $loser['razon_social_emisor'] ?? $survivor['razon_social_emisor'],
            'fecha_emision'         => $loser['fecha_emision'] ?? $survivor['fecha_emision'],
            'monto_neto'            => $loser['monto_neto'] ?? $survivor['monto_neto'],
            'monto_iva'             => $loser['monto_iva'] ?? $survivor['monto_iva'],
            'monto_total'           => $loser['monto_total'] ?? $survivor['monto_total'],
            'tasa_iva'              => $loser['tasa_iva'] ?? $survivor['tasa_iva'],
        ];
        if (!empty($loser['xml_hash'])) {
            $update['xml_hash'] = $loser['xml_hash'];
        }
        if (!empty($loser['impuestos_adicionales'])) {
            $update['impuestos_adicionales'] = $loser['impuestos_adicionales'];
        }
        if (!empty($loser['dsc_rcg_global'])) {
            $update['dsc_rcg_global'] = $loser['dsc_rcg_global'];
            $update['dsc_rcg_global_ok'] = $loser['dsc_rcg_global_ok'] ?? 1;
        }
        if (!empty($loser['proveedor_id']) && empty($survivor['proveedor_id'])) {
            $update['proveedor_id'] = (int) $loser['proveedor_id'];
        }
        $wpdb->update("{$prefix}facturas", $update, ['id' => (int) $survivor_id]);
    }

    private function repoint_scans($survivor_id, $loser_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $table = $prefix . 'documentos_escaneados';
        if (!$this->table_exists($table)) {
            return;
        }

        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, a.r2_key_original, a.archivo_hash
             FROM {$table} d
             LEFT JOIN {$prefix}documentos_archivos a ON a.id = d.archivo_id
             WHERE d.factura_id = %d",
            (int) $loser_id
        ), ARRAY_A) ?: [];

        foreach ($docs as $doc) {
            $estado = $doc['estado_revision'] ?? 'duplicado';
            if (!in_array($estado, ['confirmado', 'duplicado', 'pendiente', 'revisado'], true)) {
                $estado = 'duplicado';
            } elseif ($estado === 'pendiente' || $estado === 'revisado') {
                $estado = 'duplicado';
            }
            $wpdb->update($table, [
                'factura_id'      => (int) $survivor_id,
                'estado_revision' => $estado,
            ], ['id' => (int) $doc['id']]);

            if (!empty($doc['r2_key_original']) && function_exists('riverso_factura_attach_scan_meta')) {
                riverso_factura_attach_scan_meta(
                    (int) $survivor_id,
                    $doc['r2_key_original'],
                    $doc,
                    $doc['archivo_hash'] ?? ''
                );
            }
        }

        // También vincular por doc_hash del survivor
        if (function_exists('riverso_link_scans_to_factura')) {
            $surv = $wpdb->get_row($wpdb->prepare(
                "SELECT tipo_dte, folio, rut_emisor FROM {$prefix}facturas WHERE id = %d",
                (int) $survivor_id
            ), ARRAY_A);
            if ($surv) {
                riverso_link_scans_to_factura(
                    (int) $survivor_id,
                    (int) $surv['tipo_dte'],
                    $surv['folio'],
                    $surv['rut_emisor']
                );
            }
        }
    }

    private function repoint_payments($survivor_id, $loser_id) {
        global $wpdb;
        $table = $this->prefix() . 'factura_pago_documentos';
        if (!$this->table_exists($table)) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, pago_id FROM {$table} WHERE factura_id = %d",
            (int) $loser_id
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE pago_id = %d AND factura_id = %d LIMIT 1",
                (int) $row['pago_id'],
                (int) $survivor_id
            ));
            if ($exists) {
                $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
            } else {
                $wpdb->update($table, ['factura_id' => (int) $survivor_id], ['id' => (int) $row['id']]);
            }
        }
    }

    private function repoint_referencias($survivor_id, $loser_id) {
        global $wpdb;
        $table = $this->prefix() . 'factura_referencias';
        if (!$this->table_exists($table)) {
            return;
        }
        // factura_origen_id
        $wpdb->update(
            $table,
            ['factura_origen_id' => (int) $survivor_id],
            ['factura_origen_id' => (int) $loser_id],
            ['%d'],
            ['%d']
        );
        // factura_id (NC): evitar unique collision
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE factura_id = %d",
            (int) $loser_id
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE factura_id = %d AND tipo_doc_ref = %d AND folio_ref = %s
                   AND (cod_ref <=> %s)
                 LIMIT 1",
                (int) $survivor_id,
                (int) $row['tipo_doc_ref'],
                (string) $row['folio_ref'],
                $row['cod_ref']
            ));
            if ($exists) {
                $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
            } else {
                $wpdb->update($table, ['factura_id' => (int) $survivor_id], ['id' => (int) $row['id']]);
            }
        }
    }

    private function repoint_flete($survivor_id, $loser_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $table = $prefix . 'factura_flete_vinculos';
        if ($this->table_exists($table)) {
            foreach (['factura_envio_id', 'factura_productos_id'] as $col) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE {$col} = %d",
                    (int) $loser_id
                ), ARRAY_A) ?: [];
                foreach ($rows as $row) {
                    $envio = $col === 'factura_envio_id' ? (int) $survivor_id : (int) $row['factura_envio_id'];
                    $prod = $col === 'factura_productos_id' ? (int) $survivor_id : (int) $row['factura_productos_id'];
                    if ($envio === $prod) {
                        $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
                        continue;
                    }
                    $exists = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table}
                         WHERE factura_envio_id = %d AND factura_productos_id = %d LIMIT 1",
                        $envio,
                        $prod
                    ));
                    if ($exists) {
                        $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
                    } else {
                        $wpdb->update($table, [$col => (int) $survivor_id], ['id' => (int) $row['id']]);
                    }
                }
            }
        }
        // Legacy column en facturas
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}facturas SET factura_productos_id = %d
             WHERE factura_productos_id = %d",
            (int) $survivor_id,
            (int) $loser_id
        ));
    }

    private function repoint_facto($survivor_id, $loser_id) {
        global $wpdb;
        $table = $this->prefix() . 'facto_inbox_map';
        if (!$this->table_exists($table)) {
            return;
        }
        $wpdb->update(
            $table,
            ['factura_id' => (int) $survivor_id],
            ['factura_id' => (int) $loser_id],
            ['%d'],
            ['%d']
        );
    }

    private function repoint_tareas_factura($survivor_id, $loser_id) {
        global $wpdb;
        $table = $this->prefix() . 'tareas';
        if (!$this->table_exists($table)) {
            return;
        }
        $wpdb->update(
            $table,
            ['referencia_id' => (int) $survivor_id],
            ['referencia_tipo' => 'factura', 'referencia_id' => (int) $loser_id],
            ['%d'],
            ['%s', '%d']
        );
    }

    private function repoint_producto_proveedor($survivor_id, $loser_id, array $item_map) {
        global $wpdb;
        $table = $this->prefix() . 'producto_proveedor';
        if (!$this->table_exists($table)) {
            return;
        }
        // Columna puede no existir en installs viejos
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'vinculo_factura_id'");
        if (empty($col)) {
            return;
        }
        $wpdb->update(
            $table,
            ['vinculo_factura_id' => (int) $survivor_id],
            ['vinculo_factura_id' => (int) $loser_id],
            ['%d'],
            ['%d']
        );
        if ($item_map) {
            foreach ($item_map as $old_id => $new_id) {
                if ((int) $old_id === (int) $new_id) {
                    continue;
                }
                $wpdb->update(
                    $table,
                    ['vinculo_factura_item_id' => (int) $new_id],
                    ['vinculo_factura_item_id' => (int) $old_id],
                    ['%d'],
                    ['%d']
                );
            }
        }
    }

    private function repoint_tareas_items(array $item_map) {
        global $wpdb;
        $table = $this->prefix() . 'tareas';
        if (!$this->table_exists($table) || !$item_map) {
            return;
        }
        foreach ($item_map as $old_id => $new_id) {
            if ((int) $old_id === (int) $new_id) {
                continue;
            }
            $wpdb->update(
                $table,
                ['referencia_id' => (int) $new_id],
                ['referencia_tipo' => 'factura_item', 'referencia_id' => (int) $old_id],
                ['%d'],
                ['%s', '%d']
            );
        }
    }

    /**
     * Mueve pricing del loser al survivor solo si survivor no tiene el suyo.
     * Nunca borra precio_historial ni proceso ingresada.
     */
    private function repoint_pricing($survivor_id, $loser_id, array $sum_survivor) {
        global $wpdb;
        $prefix = $this->prefix();
        $survivor_id = (int) $survivor_id;
        $loser_id = (int) $loser_id;

        $hist = $prefix . 'precio_historial';
        if ($this->table_exists($hist)) {
            // Siempre re-apuntar historial del loser → survivor (conservar datos)
            $wpdb->query($wpdb->prepare(
                "UPDATE {$hist}
                 SET source_document_id = %d
                 WHERE source_document_id = %d
                   AND source_type IN ('folio','invoice')",
                $survivor_id,
                $loser_id
            ));
        }

        $analisis = $prefix . 'precio_folio_analisis';
        if ($this->table_exists($analisis)) {
            $surv_has = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$analisis} WHERE factura_id = %d LIMIT 1",
                $survivor_id
            ));
            if ($surv_has) {
                // Conservar survivor; borrar análisis huérfano del loser
                $wpdb->delete($analisis, ['factura_id' => $loser_id], ['%d']);
            } else {
                $wpdb->update(
                    $analisis,
                    ['factura_id' => $survivor_id],
                    ['factura_id' => $loser_id],
                    ['%d'],
                    ['%d']
                );
            }
        }

        $proceso = $prefix . 'precio_folio_proceso';
        if ($this->table_exists($proceso)) {
            $surv_proc = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$proceso} WHERE factura_id = %d",
                $survivor_id
            ), ARRAY_A);
            $lose_proc = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$proceso} WHERE factura_id = %d",
                $loser_id
            ), ARRAY_A);

            if ($lose_proc) {
                $lose_completed = in_array($lose_proc['estado'] ?? '', ['ingresada', 'ingresada_manual', 'anulada'], true);
                if (!$surv_proc) {
                    $wpdb->update(
                        $proceso,
                        ['factura_id' => $survivor_id],
                        ['id' => (int) $lose_proc['id']],
                        ['%d'],
                        ['%d']
                    );
                } elseif ($lose_completed && empty($sum_survivor['pricing_completed'])) {
                    // Conservar el proceso completado del loser: borrar pendiente del survivor y mover
                    $wpdb->delete($proceso, ['id' => (int) $surv_proc['id']], ['%d']);
                    $wpdb->update(
                        $proceso,
                        ['factura_id' => $survivor_id],
                        ['id' => (int) $lose_proc['id']],
                        ['%d'],
                        ['%d']
                    );
                } else {
                    // Survivor ya tiene proceso — borrar el del loser solo si NO está completado
                    if (!$lose_completed) {
                        $wpdb->delete($proceso, ['id' => (int) $lose_proc['id']], ['%d']);
                    }
                    // Si ambos completados, no deberíamos llegar aquí (blocked); por seguridad no borrar
                }
            }
        }
    }

    private function repoint_factura_productos_id($survivor_id, $loser_id) {
        // ya cubierto en repoint_flete para columna legacy
    }

    private function delete_loser_cost_history($loser_id, array $item_ids = []) {
        global $wpdb;
        $table = $this->prefix() . 'cost_history';
        if (!$this->table_exists($table)) {
            return;
        }
        // Solo si aún quedan filas apuntando al loser (si se movieron ítems, ya se re-apuntaron)
        $wpdb->delete($table, [
            'source_type' => 'invoice',
            'source_document_id' => (int) $loser_id,
        ], ['%s', '%d']);
        if ($item_ids) {
            $in = implode(',', array_map('intval', $item_ids));
            $wpdb->query(
                "DELETE FROM {$table}
                 WHERE source_type = 'invoice' AND source_item_id IN ({$in})"
            );
        }
    }
}
