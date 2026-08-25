<?php
/**
 * Importación de DTE recibidos desde Inbox FACTO hacia el pipeline de facturas Riverso.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Facto_Inbox_Import {

    /** @var Riverso_Facto_Client */
    private $client;

    /** @var Riverso_Invoice_Module|null */
    private $invoices;

    public function __construct(?Riverso_Facto_Client $client = null, ?Riverso_Invoice_Module $invoices = null) {
        $this->client = $client ?: new Riverso_Facto_Client();
        $this->invoices = $invoices;
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    private function invoice_module() {
        if ($this->invoices === null) {
            if (!class_exists('Riverso_Invoice_Module')) {
                require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-module.php';
            }
            $this->invoices = new Riverso_Invoice_Module();
        }
        return $this->invoices;
    }

    /**
     * Estima páginas y duración para un rango de fechas (document_date).
     *
     * @return array|WP_Error
     */
    public function estimate_range($fecha_desde, $fecha_hasta) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $from = $this->parse_date($fecha_desde);
        $to = $this->parse_date($fecha_hasta);
        if (!$from || !$to) {
            return new WP_Error('invalid_dates', 'Fechas inválidas.');
        }
        if ($from > $to) {
            return new WP_Error('invalid_range', 'La fecha desde no puede ser posterior a la fecha hasta.');
        }

        $meta = $this->fetch_list_meta();
        if (is_wp_error($meta)) {
            return $meta;
        }

        $page_count = (int) $meta['page_count'];
        if ($page_count <= 0) {
            return [
                'fecha_desde'    => $from,
                'fecha_hasta'    => $to,
                'page_from'      => 1,
                'page_to'        => 0,
                'pages'          => 0,
                'docs_estimate'  => 0,
                'months'         => $this->months_span($from, $to),
                'needs_warning'  => false,
                'eta_seconds'    => 0,
                'total_items'    => (int) $meta['total_items'],
                'overlapping_runs' => $this->find_overlapping_runs($from, $to),
                'message'        => 'No hay documentos en el Inbox FACTO.',
            ];
        }

        $page_from = $this->binary_search_page_from($from, $page_count);
        $page_to = $this->binary_search_page_to($to, $page_count, $page_from);

        if ($page_from > $page_to) {
            $docs_estimate = 0;
            $pages = 0;
        } else {
            $pages = $page_to - $page_from + 1;
            $docs_estimate = $pages * 25;
        }

        $months = $this->months_span($from, $to);
        $needs_warning = ($months > 3) || ($docs_estimate > 100);
        $eta_seconds = $pages * 7;

        return [
            'fecha_desde'      => $from,
            'fecha_hasta'      => $to,
            'page_from'        => $page_from,
            'page_to'          => $page_to,
            'pages'            => $pages,
            'docs_estimate'    => $docs_estimate,
            'months'           => $months,
            'needs_warning'    => $needs_warning,
            'eta_seconds'      => $eta_seconds,
            'total_items'      => (int) $meta['total_items'],
            'page_count'       => $page_count,
            'overlapping_runs' => $this->find_overlapping_runs($from, $to),
            'message'          => sprintf(
                'Rango estimado: páginas %d–%d (~%d documentos, ~%ds).',
                $page_from,
                $page_to,
                $docs_estimate,
                $eta_seconds
            ),
        ];
    }

    /**
     * Crea un registro de corrida de importación.
     */
    public function create_run($fecha_desde, $fecha_hasta, $page_from, $page_to) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($this->table('facto_inbox_runs'), [
            'fecha_desde'  => $fecha_desde,
            'fecha_hasta'  => $fecha_hasta,
            'state'        => 'running',
            'page_from'    => (int) $page_from,
            'page_to'      => (int) $page_to,
            'started_at'   => $now,
            'started_by'   => get_current_user_id(),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Procesa un lote de páginas del Inbox FACTO.
     */
    public function import_batch($run_id, $fecha_desde, $fecha_hasta, $start_page, $pages_per_batch = 3, $force_reprocess = false) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        global $wpdb;
        $run_id = absint($run_id);
        $from = $this->parse_date($fecha_desde);
        $to = $this->parse_date($fecha_hasta);
        if (!$from || !$to || $from > $to) {
            return new WP_Error('invalid_range', 'Rango de fechas inválido.');
        }

        $run = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('facto_inbox_runs')} WHERE id = %d",
            $run_id
        ), ARRAY_A);
        if (!$run) {
            return new WP_Error('run_missing', 'Corrida de importación no encontrada.');
        }

        if (in_array($run['state'], ['error', 'cancelled'], true)) {
            $wpdb->update($this->table('facto_inbox_runs'), [
                'state'       => 'running',
                'finished_at' => null,
            ], ['id' => $run_id]);
        }

        $page = max(1, absint($start_page));
        $pages_per_batch = max(1, min(10, absint($pages_per_batch)));
        $end_page = $page + $pages_per_batch - 1;
        if (!empty($run['page_to'])) {
            $end_page = min($end_page, (int) $run['page_to']);
        }

        $found = 0;
        $imported = 0;
        $merged = 0;
        $duplicate = 0;
        $skipped = 0;
        $errors = [];
        $error_count = 0;
        $page_count = null;
        $last_page = $page - 1;
        $done = false;
        $past_range = false;

        while ($page <= $end_page && !$past_range) {
            $resp = $this->client->list_inbox_documents(['page' => $page]);
            if (is_wp_error($resp)) {
                $errors[] = 'Página ' . $page . ': ' . $resp->get_error_message();
                break;
            }

            $items = Riverso_Facto_Client::embed_collection($resp, 'inbox_documents');
            $page_count = isset($resp['page_count']) ? (int) $resp['page_count'] : $page;

            if (empty($items)) {
                $done = true;
                $last_page = $page;
                break;
            }

            foreach ($items as $item) {
                $doc_date = $this->item_document_date($item);
                if ($doc_date === '') {
                    continue;
                }
                if ($doc_date < $from) {
                    continue;
                }
                if ($doc_date > $to) {
                    $past_range = true;
                    break;
                }

                $found++;
                $result = $this->import_single_document($item, $force_reprocess);
                if (is_wp_error($result)) {
                    $error_count++;
                    if (count($errors) < 20) {
                        $errors[] = $result->get_error_message();
                    }
                    continue;
                }

                switch ($result['state']) {
                    case 'imported':
                        $imported++;
                        break;
                    case 'merged':
                        $merged++;
                        break;
                    case 'duplicate':
                        $duplicate++;
                        break;
                    case 'skipped':
                        $skipped++;
                        break;
                    case 'error':
                        $error_count++;
                        if (!empty($result['message']) && count($errors) < 20) {
                            $errors[] = $result['message'];
                        }
                        break;
                }
            }

            $last_page = $page;
            if ($past_range) {
                $done = true;
                break;
            }
            if ($page >= $page_count) {
                $done = true;
                break;
            }
            if (!empty($run['page_to']) && $page >= (int) $run['page_to']) {
                $done = true;
                break;
            }
            $page++;
            usleep(50000);
        }

        $next_page = null;
        if (!$done && empty($errors)) {
            $next_page = $last_page + 1;
            if ($page_count !== null && $next_page > $page_count) {
                $done = true;
                $next_page = null;
            } elseif (!empty($run['page_to']) && $next_page > (int) $run['page_to']) {
                $done = true;
                $next_page = null;
            }
        }

        $this->accumulate_run($run_id, [
            'docs_found'     => $found,
            'docs_imported'  => $imported,
            'docs_merged'    => $merged,
            'docs_duplicate' => $duplicate,
            'docs_skipped'   => $skipped,
            'docs_error'     => $error_count,
            'pages_scanned'  => max(0, $last_page - ((int) ($run['pages_scanned'] ?? 0) > 0 ? 0 : 0)),
        ], $last_page, $done, $errors);

        // Re-read run for totals
        $run_after = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('facto_inbox_runs')} WHERE id = %d",
            $run_id
        ), ARRAY_A);

        return [
            'done'            => $done,
            'run_id'          => $run_id,
            'page'            => $last_page,
            'next_page'       => $next_page,
            'page_count'      => $page_count,
            'docs_found'      => $found,
            'docs_imported'   => $imported,
            'docs_merged'     => $merged,
            'docs_duplicate'  => $duplicate,
            'docs_skipped'    => $skipped,
            'docs_error'      => $error_count,
            'totals'          => [
                'docs_found'     => (int) ($run_after['docs_found'] ?? 0),
                'docs_imported'  => (int) ($run_after['docs_imported'] ?? 0),
                'docs_merged'    => (int) ($run_after['docs_merged'] ?? 0),
                'docs_duplicate' => (int) ($run_after['docs_duplicate'] ?? 0),
                'docs_skipped'   => (int) ($run_after['docs_skipped'] ?? 0),
                'docs_error'     => (int) ($run_after['docs_error'] ?? 0),
                'pages_scanned'  => (int) ($run_after['pages_scanned'] ?? 0),
            ],
            'errors'          => $errors,
            'message'         => $done
                ? sprintf(
                    'Importación completa. Nuevo: %d · fusionados: %d · duplicados: %d · omitidos: %d · errores: %d',
                    (int) ($run_after['docs_imported'] ?? 0),
                    (int) ($run_after['docs_merged'] ?? 0),
                    (int) ($run_after['docs_duplicate'] ?? 0),
                    (int) ($run_after['docs_skipped'] ?? 0),
                    (int) ($run_after['docs_error'] ?? 0)
                )
                : sprintf(
                    'Progreso página %d/%s. Lote: +%d importados, +%d fusionados.',
                    $last_page,
                    $page_count ?: '?',
                    $imported,
                    $merged
                ),
        ];
    }

    /**
     * Lista corridas recientes.
     */
    public function list_runs($limit = 30) {
        global $wpdb;
        $limit = max(1, min(100, absint($limit)));
        return $wpdb->get_results(
            "SELECT * FROM {$this->table('facto_inbox_runs')}
             ORDER BY id DESC LIMIT {$limit}",
            ARRAY_A
        );
    }

    /**
     * @return array|WP_Error
     */
    private function import_single_document(array $item, $force_reprocess = false) {
        global $wpdb;

        $inbox_id = isset($item['inbox_document_id']) ? (int) $item['inbox_document_id'] : 0;
        if ($inbox_id <= 0) {
            return new WP_Error('no_inbox_id', 'Documento sin inbox_document_id');
        }

        $existing_map = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('facto_inbox_map')} WHERE inbox_document_id = %d",
            $inbox_id
        ), ARRAY_A);

        if (!$force_reprocess && $existing_map && in_array($existing_map['state'], ['imported', 'merged', 'duplicate', 'skipped'], true)) {
            return ['state' => 'duplicate', 'factura_id' => (int) ($existing_map['factura_id'] ?? 0)];
        }

        $xml = isset($item['document_xml']) ? (string) $item['document_xml'] : '';
        if ($xml === '') {
            $this->upsert_map($inbox_id, null, 'error', 'Sin document_xml en respuesta FACTO', $item);
            return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': sin XML'];
        }

        $xml = $this->normalize_xml_encoding($xml);
        $parsed = $this->invoice_module()->parse_dte_xml($xml);
        if (is_wp_error($parsed)) {
            $this->upsert_map($inbox_id, null, 'error', $parsed->get_error_message(), $item);
            return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': ' . $parsed->get_error_message()];
        }

        if (!$this->receptor_matches_company($parsed)) {
            $this->upsert_map($inbox_id, null, 'skipped', 'Receptor no coincide con RUT de la empresa', $item, $parsed);
            return ['state' => 'skipped'];
        }

        $options = [
            'origen_ingreso'  => 'facto',
            'modo_ingreso'    => 'solo_costos',
            'tipo_confirmado' => 0,
        ];

        $tipo_dte = (int) ($parsed['tipo_dte'] ?? 0);
        $folio = (string) ($parsed['folio'] ?? '');
        $rut_emisor = sanitize_text_field($parsed['emisor']['rut'] ?? '');

        $existing_factura = function_exists('riverso_find_factura_by_dte')
            ? riverso_find_factura_by_dte($tipo_dte, $folio, $rut_emisor)
            : null;

        if ($existing_factura) {
            $existing_id = (int) ($existing_factura['id'] ?? 0);
            if ($existing_id <= 0) {
                $this->upsert_map($inbox_id, null, 'error', 'Factura existente sin id válido', $item, $parsed);
                return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': factura existente sin id'];
            }
            $merge = $this->invoice_module()->merge_xml_into_factura($existing_id, $parsed, $options);
            if (is_wp_error($merge)) {
                $this->upsert_map($inbox_id, $existing_id, 'error', $merge->get_error_message(), $item, $parsed);
                return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': ' . $merge->get_error_message()];
            }
            $this->upsert_map($inbox_id, $existing_id, 'merged', null, $item, $parsed);
            return ['state' => 'merged', 'factura_id' => $existing_id];
        }

        $saved = $this->invoice_module()->save_invoice($parsed, $options);
        if (is_wp_error($saved)) {
            if ($saved->get_error_code() === 'duplicate') {
                $fid = (int) ($saved->get_error_data()['factura_id'] ?? 0);
                if ($fid > 0) {
                    $merge = $this->invoice_module()->merge_xml_into_factura($fid, $parsed, $options);
                    if (is_wp_error($merge)) {
                        $this->upsert_map($inbox_id, $fid, 'error', $merge->get_error_message(), $item, $parsed);
                        return ['state' => 'error', 'message' => $merge->get_error_message()];
                    }
                    $this->upsert_map($inbox_id, $fid, 'merged', null, $item, $parsed);
                    return ['state' => 'merged', 'factura_id' => $fid];
                }
                $this->upsert_map($inbox_id, null, 'duplicate', 'Duplicado sin factura_id', $item, $parsed);
                return ['state' => 'duplicate'];
            }
            if ($saved->get_error_code() === 'missing_data') {
                $this->upsert_map($inbox_id, null, 'error', 'Datos incompletos en DTE', $item, $parsed);
                return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': datos incompletos'];
            }
            $this->upsert_map($inbox_id, null, 'error', $saved->get_error_message(), $item, $parsed);
            return ['state' => 'error', 'message' => 'Inbox #' . $inbox_id . ': ' . $saved->get_error_message()];
        }

        $this->upsert_map($inbox_id, (int) $saved, 'imported', null, $item, $parsed);
        return ['state' => 'imported', 'factura_id' => (int) $saved];
    }

    private function upsert_map($inbox_id, $factura_id, $state, $error, array $item, ?array $parsed = null) {
        global $wpdb;
        $now = current_time('mysql');
        $table = $this->table('facto_inbox_map');

        $data = [
            'factura_id'    => $factura_id ? absint($factura_id) : null,
            'tipo_dte'      => $parsed ? (int) ($parsed['tipo_dte'] ?? 0) : (int) ($item['document_type_taxbureau'] ?? 0),
            'folio'         => $parsed ? (string) ($parsed['folio'] ?? '') : (string) ($item['document_number'] ?? ''),
            'rut_emisor'    => $parsed ? sanitize_text_field($parsed['emisor']['rut'] ?? '') : '',
            'document_date' => $this->item_document_date($item) ?: null,
            'total_amount'  => isset($item['total_amount']) ? (float) $item['total_amount'] : null,
            'state'         => sanitize_key($state),
            'last_error'    => $error,
            'updated_at'    => $now,
        ];

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE inbox_document_id = %d",
            absint($inbox_id)
        ));

        if ($existing) {
            $wpdb->update($table, $data, ['inbox_document_id' => absint($inbox_id)]);
        } else {
            $data['inbox_document_id'] = absint($inbox_id);
            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
        }
    }

    private function accumulate_run($run_id, array $delta, $last_page, $done, array $errors) {
        global $wpdb;
        $table = $this->table('facto_inbox_runs');
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $run_id), ARRAY_A);
        if (!$run) {
            return;
        }

        $pages_this_batch = 0;
        if ($last_page >= (int) ($run['page_from'] ?? 1)) {
            $prev_scanned = (int) ($run['pages_scanned'] ?? 0);
            $new_scanned = max($prev_scanned, $last_page - (int) ($run['page_from'] ?? 1) + 1);
            $pages_this_batch = max(0, $new_scanned - $prev_scanned);
        }

        $update = [
            'docs_found'     => (int) $run['docs_found'] + (int) $delta['docs_found'],
            'docs_imported'  => (int) $run['docs_imported'] + (int) $delta['docs_imported'],
            'docs_merged'    => (int) $run['docs_merged'] + (int) $delta['docs_merged'],
            'docs_duplicate' => (int) $run['docs_duplicate'] + (int) $delta['docs_duplicate'],
            'docs_skipped'   => (int) $run['docs_skipped'] + (int) $delta['docs_skipped'],
            'docs_error'     => (int) $run['docs_error'] + (int) $delta['docs_error'],
            'pages_scanned'  => max((int) $run['pages_scanned'], $last_page - (int) ($run['page_from'] ?? 1) + 1),
        ];

        if ($done) {
            $update['state'] = empty($errors) ? 'done' : 'error';
            $update['finished_at'] = current_time('mysql');
        }
        if (!empty($errors)) {
            $update['last_error'] = implode('; ', array_slice($errors, 0, 5));
        }

        $wpdb->update($table, $update, ['id' => $run_id]);
    }

    private function find_overlapping_runs($from, $to) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, fecha_desde, fecha_hasta, state, docs_imported, docs_merged, started_at, finished_at
             FROM {$this->table('facto_inbox_runs')}
             WHERE state IN ('done', 'running', 'error')
               AND fecha_desde <= %s AND fecha_hasta >= %s
             ORDER BY id DESC LIMIT 10",
            $to,
            $from
        ), ARRAY_A);
    }

    private function fetch_list_meta() {
        $resp = $this->client->list_inbox_documents(['page' => 1]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        return [
            'page_count'  => (int) ($resp['page_count'] ?? 1),
            'total_items' => (int) ($resp['total_items'] ?? 0),
        ];
    }

    private function page_date_bounds($page) {
        $resp = $this->client->list_inbox_documents(['page' => $page]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $items = Riverso_Facto_Client::embed_collection($resp, 'inbox_documents');
        if (empty($items)) {
            return ['min' => null, 'max' => null];
        }
        $dates = [];
        foreach ($items as $item) {
            $d = $this->item_document_date($item);
            if ($d !== '') {
                $dates[] = $d;
            }
        }
        if (empty($dates)) {
            return ['min' => null, 'max' => null];
        }
        sort($dates);
        return ['min' => $dates[0], 'max' => $dates[count($dates) - 1]];
    }

    private function binary_search_page_from($from, $page_count) {
        $lo = 1;
        $hi = $page_count;
        $result = $page_count;
        while ($lo <= $hi) {
            $mid = (int) floor(($lo + $hi) / 2);
            $bounds = $this->page_date_bounds($mid);
            if (is_wp_error($bounds) || empty($bounds['max'])) {
                break;
            }
            if ($bounds['max'] < $from) {
                $lo = $mid + 1;
            } else {
                $result = $mid;
                $hi = $mid - 1;
            }
        }
        return $result;
    }

    private function binary_search_page_to($to, $page_count, $page_from) {
        $lo = max(1, $page_from);
        $hi = $page_count;
        $result = $page_from;
        while ($lo <= $hi) {
            $mid = (int) floor(($lo + $hi) / 2);
            $bounds = $this->page_date_bounds($mid);
            if (is_wp_error($bounds) || empty($bounds['min'])) {
                break;
            }
            if ($bounds['min'] > $to) {
                $hi = $mid - 1;
            } else {
                $result = $mid;
                $lo = $mid + 1;
            }
        }
        return $result;
    }

    private function normalize_xml_encoding($xml) {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        if (preg_match('/encoding=["\']([^"\']+)["\']/', $xml, $matches)) {
            $encoding = strtoupper($matches[1]);
            if ($encoding !== 'UTF-8') {
                $xml = preg_replace('/encoding=["\'][^"\']+["\']/i', 'encoding="UTF-8"', $xml, 1);
            }
        }
        return $xml;
    }

    private function receptor_matches_company(array $parsed) {
        $expected = '';
        if (function_exists('riverso_get_scan_config')) {
            $expected = (string) riverso_get_scan_config('expected_receptor_rut', '764438523');
        }
        $expected = strtoupper(preg_replace('/[^0-9K]/', '', $expected));
        if ($expected === '') {
            return true;
        }
        $receptor = strtoupper(preg_replace('/[^0-9K]/', '', $parsed['receptor']['rut'] ?? ''));
        return $receptor === $expected;
    }

    private function item_document_date(array $item) {
        $d = isset($item['document_date']) ? trim((string) $item['document_date']) : '';
        if ($d === '' && !empty($item['receive_date'])) {
            $d = substr((string) $item['receive_date'], 0, 10);
        }
        return $this->parse_date($d) ?: '';
    }

    private function parse_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    private function months_span($from, $to) {
        $a = new DateTime($from);
        $b = new DateTime($to);
        $months = ((int) $b->format('Y') - (int) $a->format('Y')) * 12;
        $months += (int) $b->format('m') - (int) $a->format('m');
        return max(0, $months + 1);
    }
}
