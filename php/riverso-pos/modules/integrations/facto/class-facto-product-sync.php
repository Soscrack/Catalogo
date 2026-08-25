<?php
/**
 * Sincronización productos Riverso → FACTO (outbox + payloads).
 *
 * Alcance v1 (PoC sandbox):
 * - CREATE: POST (sku, name, unit, cost/price/stock best-effort)
 * - UPDATE: PUT descriptivos (name, brand, model, details, status). SKU inmutable.
 * - ARCHIVE: PUT status=0
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Facto_Product_Sync {

    /** @var Riverso_Facto_Client */
    private $client;

    public function __construct(?Riverso_Facto_Client $client = null) {
        $this->client = $client ?: new Riverso_Facto_Client();
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    public function enqueue($producto_base_id, $operation, array $payload = []) {
        if (!riverso_facto_sync_enabled()) {
            return false;
        }
        global $wpdb;
        $producto_base_id = absint($producto_base_id);
        if ($producto_base_id <= 0) {
            return false;
        }
        $operation = sanitize_key($operation);
        if (!in_array($operation, ['create', 'update', 'archive'], true)) {
            return false;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('facto_sync_outbox')}
             WHERE producto_base_id = %d AND operation = %s AND state = 'pending'
             ORDER BY id DESC LIMIT 1",
            $producto_base_id,
            $operation
        ));

        $now = current_time('mysql');
        if ($existing) {
            $wpdb->update(
                $this->table('facto_sync_outbox'),
                [
                    'payload'         => wp_json_encode($payload),
                    'next_attempt_at' => $now,
                    'updated_at'      => $now,
                ],
                ['id' => (int) $existing]
            );
            return (int) $existing;
        }

        $wpdb->insert($this->table('facto_sync_outbox'), [
            'producto_base_id' => $producto_base_id,
            'operation'        => $operation,
            'payload'          => wp_json_encode($payload),
            'attempts'         => 0,
            'next_attempt_at'  => $now,
            'state'            => 'pending',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function process_outbox($limit = 20) {
        if (!riverso_facto_sync_enabled()) {
            return ['processed' => 0, 'skipped' => true];
        }
        global $wpdb;
        $limit = max(1, min(100, absint($limit)));
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('facto_sync_outbox')}
             WHERE state = 'pending' AND next_attempt_at <= %s
             ORDER BY id ASC LIMIT %d",
            $now,
            $limit
        ), ARRAY_A);

        $ok = 0;
        $fail = 0;
        foreach ((array) $rows as $row) {
            $result = $this->process_job($row);
            if (is_wp_error($result)) {
                $fail++;
            } else {
                $ok++;
            }
        }
        return ['processed' => $ok + $fail, 'ok' => $ok, 'fail' => $fail];
    }

    private function process_job(array $row) {
        global $wpdb;
        $id = (int) $row['id'];
        $producto_id = (int) $row['producto_base_id'];
        $operation = $row['operation'];

        $product = $this->load_product($producto_id);
        if (!$product && $operation !== 'archive') {
            $this->mark_job($id, 'failed', 'Producto local no encontrado');
            return new WP_Error('product_missing', 'Producto local no encontrado');
        }

        $map = $this->get_map($producto_id);

        if ($operation === 'create' || ($operation === 'update' && !$map)) {
            $result = $map ? $this->push_update($product, $map) : $this->push_create($product);
        } elseif ($operation === 'update') {
            $result = $this->push_update($product, $map);
        } elseif ($operation === 'archive') {
            $result = $this->push_archive($producto_id, $map, $product);
        } else {
            $result = new WP_Error('unknown_op', 'Operación desconocida');
        }

        if (is_wp_error($result)) {
            $attempts = (int) $row['attempts'] + 1;
            $retry_after = 30;
            $data = $result->get_error_data();
            if (is_array($data) && !empty($data['retry_after'])) {
                $retry_after = (int) $data['retry_after'];
            }
            $state = $attempts >= 8 ? 'failed' : 'pending';
            $next_local = date(
                'Y-m-d H:i:s',
                strtotime(current_time('mysql')) + ($retry_after * max(1, $attempts))
            );
            $wpdb->update(
                $this->table('facto_sync_outbox'),
                [
                    'attempts'        => $attempts,
                    'next_attempt_at' => $next_local,
                    'state'           => $state,
                    'last_error'      => $result->get_error_message(),
                    'updated_at'      => current_time('mysql'),
                ],
                ['id' => $id]
            );
            if ($map) {
                $this->touch_map_error($producto_id, $result->get_error_message());
            }
            return $result;
        }

        $this->mark_job($id, 'done', null);
        return true;
    }

    private function mark_job($id, $state, $error) {
        global $wpdb;
        $wpdb->update(
            $this->table('facto_sync_outbox'),
            [
                'state'      => $state,
                'last_error' => $error,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => absint($id)]
        );
    }

    private function load_product($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('producto_base')} WHERE id = %d",
            absint($id)
        ), ARRAY_A);
    }

    public function get_map($producto_base_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('facto_producto_map')} WHERE producto_base_id = %d",
            absint($producto_base_id)
        ), ARRAY_A);
    }

    private function push_create(array $product) {
        $sku = trim((string) ($product['canonical_sku'] ?? ''));
        if ($sku === '') {
            return new WP_Error('no_sku', 'Producto sin canonical_sku; no se crea en FACTO');
        }

        $existing = $this->client->list_products(['sku' => $sku]);
        if (!is_wp_error($existing)) {
            $items = Riverso_Facto_Client::embed_collection($existing, 'products');
            if (!empty($items[0]['product_id'])) {
                $this->upsert_map((int) $product['id'], (int) $items[0]['product_id'], $sku, 'linked', null);
                return $this->push_update($product, $this->get_map($product['id']));
            }
        }

        $payload = $this->build_create_payload($product);
        $created = $this->client->create_product($payload);
        if (is_wp_error($created)) {
            return $created;
        }

        $facto_id = isset($created['product_id']) ? (int) $created['product_id'] : 0;
        if ($facto_id <= 0) {
            return new WP_Error('facto_no_id', 'POST /products no devolvió product_id', ['body' => $created]);
        }

        $hash = sha1(wp_json_encode($payload));
        $this->upsert_map((int) $product['id'], $facto_id, $sku, 'synced', null, $hash);
        return true;
    }

    private function push_update(array $product, ?array $map) {
        if (!$map || empty($map['facto_product_id'])) {
            return $this->push_create($product);
        }

        $sku_local = trim((string) ($product['canonical_sku'] ?? ''));
        $sku_facto = trim((string) ($map['facto_sku'] ?? ''));
        $warning = null;
        if ($sku_local !== '' && $sku_facto !== '' && $sku_local !== $sku_facto) {
            $warning = 'SKU local cambió (' . $sku_facto . ' → ' . $sku_local . ') pero FACTO no permite renombrar SKU vía PUT';
        }

        $payload = $this->build_update_payload($product);
        $hash = sha1(wp_json_encode($payload));
        if (!empty($map['last_payload_hash']) && $map['last_payload_hash'] === $hash && !$warning) {
            return true;
        }

        $updated = $this->client->update_product((int) $map['facto_product_id'], $payload);
        if (is_wp_error($updated)) {
            return $updated;
        }

        $verify_err = $this->verify_remote_name(
            (int) $map['facto_product_id'],
            (string) ($product['nombre_canonico'] ?? ''),
            $sku_local
        );
        if (is_wp_error($verify_err)) {
            return $verify_err;
        }

        $this->upsert_map(
            (int) $product['id'],
            (int) $map['facto_product_id'],
            $sku_facto !== '' ? $sku_facto : $sku_local,
            $warning ? 'sku_drift' : 'synced',
            $warning,
            $hash
        );
        return true;
    }

    private function push_archive($producto_id, ?array $map, ?array $product) {
        if (!$map || empty($map['facto_product_id'])) {
            return true;
        }
        $name = $product['nombre_canonico'] ?? ('Producto ' . $producto_id);
        $updated = $this->client->update_product((int) $map['facto_product_id'], [
            'name'   => $name,
            'status' => 0,
        ]);
        if (is_wp_error($updated)) {
            return $updated;
        }
        $this->upsert_map($producto_id, (int) $map['facto_product_id'], $map['facto_sku'] ?? null, 'archived', null);
        return true;
    }

    private function build_create_payload(array $product) {
        $currency   = (int) riverso_get_facto_config('currency_id', 39);
        $tax_type   = (string) riverso_get_facto_config('tax_type_id', '387');
        $price_list = (int) riverso_get_facto_config('price_list_id', 1);
        $location   = (int) riverso_get_facto_config('location_id', 1);

        $name = (string) ($product['nombre_canonico'] ?? '');
        $sku  = (string) ($product['canonical_sku'] ?? '');
        $unit = $this->map_unit($product['unidad_base'] ?? 'UN');

        $price_total = $this->guess_local_price((int) $product['id']);
        $net = $price_total > 0 ? round($price_total / 1.19, 6) : 0;
        $tax = $price_total > 0 ? round($price_total - $net, 6) : 0;

        return [
            'name'                => $name,
            'invoicing_details'   => '',
            'additional_details'  => '',
            'model'               => '',
            'brand'               => '',
            'status'              => (($product['estado'] ?? '') === 'activo') ? '1' : '0',
            'sku'                 => $sku,
            'measure_unit'        => $unit,
            'type'                => '1',
            'favorite'            => 0,
            'product_category_id' => null,
            'cost' => [
                'currency_id' => $currency,
                'value'       => 0,
            ],
            'price' => [[
                'price_list_id' => (string) $price_list,
                'unit_net'      => $net,
                'unit_tax'      => $tax,
                'unit_total'    => $price_total,
                'currency_id'   => (string) $currency,
                'sales_commission_percentage' => null,
                'taxes' => [[
                    'tax_type_id'    => $tax_type,
                    'tax_percentage' => 19,
                    'tax_amount'     => $tax,
                ]],
            ]],
            'inventories' => [
                'details' => [[
                    'product_location_id' => $location,
                    'available_quantity'  => 0,
                ]],
                'total_available' => 0,
                'total_reserved'  => 0,
            ],
        ];
    }

    private function build_update_payload(array $product) {
        $name = (string) ($product['nombre_canonico'] ?? '');
        $sku  = (string) ($product['canonical_sku'] ?? '');

        // PUT documentado: solo name (+ sku). invoicing_details/additional_details
        // son "Descripción" en la UI de bodega FACTO — no duplicar el nombre ahí.
        return [
            'name' => $name,
            'sku'  => $sku,
        ];
    }

    /**
     * Confirma que FACTO persistió el campo name (UI "Nombre"), no solo descripción.
     *
     * @return true|WP_Error
     */
    private function verify_remote_name($facto_product_id, $expected_name, $sku) {
        $expected_name = trim($expected_name);
        if ($expected_name === '') {
            return true;
        }

        $remote = $this->client->get_product($facto_product_id);
        if (is_wp_error($remote)) {
            return $remote;
        }

        $remote_name = trim((string) ($remote['name'] ?? ''));
        if ($remote_name === $expected_name) {
            return true;
        }

        // Reintento con body mínimo documentado.
        $retry = $this->client->update_product($facto_product_id, [
            'name' => $expected_name,
            'sku'  => $sku,
        ]);
        if (is_wp_error($retry)) {
            return $retry;
        }

        $remote = $this->client->get_product($facto_product_id);
        if (is_wp_error($remote)) {
            return $remote;
        }

        $remote_name = trim((string) ($remote['name'] ?? ''));
        if ($remote_name !== $expected_name) {
            return new WP_Error(
                'facto_name_mismatch',
                'FACTO no persistió el nombre (UI Nombre). Remoto: "' . $remote_name . '"',
                ['expected' => $expected_name, 'remote' => $remote]
            );
        }

        return true;
    }

    private function map_unit($unit) {
        $unit = strtoupper(trim((string) $unit));
        $map = [
            'UNIDAD' => 'UN', 'UN' => 'UN', 'U' => 'UN',
            'KG' => 'KG', 'KILO' => 'KG',
            'M' => 'MTS', 'MT' => 'MTS', 'MTS' => 'MTS', 'METRO' => 'MTS',
        ];
        return $map[$unit] ?? 'UN';
    }

    private function guess_local_price($producto_base_id) {
        global $wpdb;
        $table = $this->table('precios');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return 0.0;
        }
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT p_asignado FROM {$table}
             WHERE producto_base_id = %d AND canal = 'local'
             ORDER BY id DESC LIMIT 1",
            $producto_base_id
        ));
        return $val !== null ? (float) $val : 0.0;
    }

    private function upsert_map($producto_base_id, $facto_product_id, $facto_sku, $state, $error = null, $hash = null) {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->get_map($producto_base_id);
        $data = [
            'facto_product_id' => absint($facto_product_id),
            'facto_sku'        => $facto_sku,
            'sync_state'       => $state,
            'last_error'       => $error,
            'last_synced_at'   => $now,
            'updated_at'       => $now,
        ];
        if ($hash !== null) {
            $data['last_payload_hash'] = $hash;
        }
        if ($existing) {
            $wpdb->update($this->table('facto_producto_map'), $data, ['producto_base_id' => absint($producto_base_id)]);
        } else {
            $data['producto_base_id'] = absint($producto_base_id);
            $data['created_at'] = $now;
            $wpdb->insert($this->table('facto_producto_map'), $data);
        }
    }

    private function touch_map_error($producto_base_id, $message) {
        global $wpdb;
        $wpdb->update(
            $this->table('facto_producto_map'),
            [
                'sync_state' => 'error',
                'last_error' => $message,
                'updated_at' => current_time('mysql'),
            ],
            ['producto_base_id' => absint($producto_base_id)]
        );
    }

    /**
     * Backfill por lotes: pagina FACTO y vincula por SKU.
     * Pensado para AJAX corto (evitar timeout de proxy/navegador).
     *
     * @param int $start_page       Página FACTO inicial (1-based).
     * @param int $pages_per_batch  Máximo de páginas a procesar en esta llamada.
     */
    public function reconcile_from_facto($start_page = 1, $pages_per_batch = 5) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $page = max(1, absint($start_page));
        $pages_per_batch = max(1, min(20, absint($pages_per_batch)));
        $end_page = $page + $pages_per_batch - 1;

        $linked = 0;
        $skipped = 0;
        $only_facto = [];
        $only_facto_count = 0;
        $errors = [];
        $page_count = null;
        $sku_index = $this->build_local_sku_index();
        $last_page = $page - 1;
        $done = false;

        while ($page <= $end_page) {
            $resp = $this->client->list_products(['page' => $page]);
            if (is_wp_error($resp)) {
                $errors[] = 'Página ' . $page . ': ' . $resp->get_error_message();
                break;
            }

            $items = Riverso_Facto_Client::embed_collection($resp, 'products');
            if (empty($items)) {
                $done = true;
                $last_page = $page;
                break;
            }

            foreach ($items as $item) {
                $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
                $fid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                if ($sku === '' || $fid <= 0) {
                    $skipped++;
                    continue;
                }

                $local_id = isset($sku_index[$sku]) ? (int) $sku_index[$sku] : 0;
                if ($local_id <= 0) {
                    $only_facto_count++;
                    if (count($only_facto) < 50) {
                        $only_facto[] = [
                            'sku'              => $sku,
                            'facto_product_id' => $fid,
                            'name'             => $item['name'] ?? '',
                        ];
                    }
                    continue;
                }

                $this->upsert_map($local_id, $fid, $sku, 'linked', null);
                $linked++;
            }

            $last_page = $page;
            $page_count = isset($resp['page_count']) ? (int) $resp['page_count'] : $page;
            if ($page >= $page_count) {
                $done = true;
                break;
            }
            $page++;
            usleep(50000);
        }

        $next_page = null;
        if (!$done && empty($errors) && $last_page > 0) {
            $next_page = $last_page + 1;
            if ($page_count !== null && $next_page > $page_count) {
                $done = true;
                $next_page = null;
            }
        }

        return [
            'done'              => $done,
            'page'              => $last_page,
            'next_page'         => $next_page,
            'page_count'        => $page_count,
            'linked'            => $linked,
            'skipped'           => $skipped,
            'only_facto_count'  => $only_facto_count,
            'only_facto_sample' => $only_facto,
            'errors'            => $errors,
            'message'           => $done
                ? sprintf(
                    'Reconciliación completa (página %d/%s). Vinculados en este lote: %d. Solo FACTO: %d.',
                    $last_page,
                    $page_count ?: '?',
                    $linked,
                    $only_facto_count
                )
                : sprintf(
                    'Progreso: página %d/%s. Vinculados en lote: %d. Solo FACTO: %d.',
                    $last_page,
                    $page_count ?: '?',
                    $linked,
                    $only_facto_count
                ),
        ];
    }

    /**
     * @return array<string,int> canonical_sku => producto_base.id
     */
    private function build_local_sku_index() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, canonical_sku FROM {$this->table('producto_base')}
             WHERE canonical_sku IS NOT NULL AND canonical_sku <> ''",
            ARRAY_A
        );
        $index = [];
        foreach ((array) $rows as $row) {
            $sku = trim((string) $row['canonical_sku']);
            if ($sku !== '' && !isset($index[$sku])) {
                $index[$sku] = (int) $row['id'];
            }
        }
        return $index;
    }
}
