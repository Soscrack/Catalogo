<?php
/**
 * Repositorio de cotizaciones de venta (wpdb).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Quote_Repository {
    private $table_quotes;
    private $table_items;
    /** @var array<string, bool>|null */
    private $item_columns = null;
    /** @var array<string, bool>|null */
    private $quote_columns = null;

    public function __construct() {
        global $wpdb;
        $this->table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $this->table_items = $wpdb->prefix . 'riverso_customer_quote_items';
    }

    public function list_quotes(array $filters = array()) {
        global $wpdb;
        $this->ensure_sale_schema();
        // Fecha de emisión = DATE(created_at) (no hay columna issue_date propia).
        $sql = "SELECT q.*, (SELECT COUNT(*) FROM {$this->table_items} i WHERE i.quote_id = q.id) AS line_count
                FROM {$this->table_quotes} q";
        $where = array();
        $params = array();
        if (!empty($filters['status'])) {
            $where[] = 'q.status = %s';
            $params[] = Riverso_Quote_Status::normalize_legacy((string) $filters['status']);
        }
        if (!empty($filters['quote_type'])) {
            $type = Riverso_Quote_Type::normalize((string) $filters['quote_type']);
            if ($type === Riverso_Quote_Type::VENTA) {
                // Filas legacy sin quote_type se tratan como venta.
                $where[] = "(q.quote_type = %s OR q.quote_type IS NULL OR q.quote_type = '')";
                $params[] = $type;
            } else {
                $where[] = 'q.quote_type = %s';
                $params[] = $type;
            }
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(q.created_at) >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(q.created_at) <= %s';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY q.updated_at DESC, q.id DESC';
        if ($params) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }
        if (!is_array($rows)) {
            return array();
        }
        return array_map(array($this, 'present_summary'), $rows);
    }

    public function find($id) {
        global $wpdb;
        $id = (int) $id;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_quotes} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE quote_id = %d ORDER BY sort_order ASC, id ASC",
            $id
        ), ARRAY_A);
        return $this->present($row, is_array($lines) ? $lines : array());
    }

    public function save(array $input) {
        global $wpdb;
        if (!isset($input['lines']) || !is_array($input['lines'])) {
            throw new Riverso_Quote_Exception('La cotización no incluye líneas.');
        }
        if (count($input['lines']) > 200) {
            throw new Riverso_Quote_Exception('La cotización admite hasta 200 líneas.');
        }

        $this->ensure_sale_schema();

        $id = isset($input['id']) && $input['id'] !== '' && $input['id'] !== null ? (int) $input['id'] : 0;
        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && $existing === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        if ($existing !== null && $existing['status'] === Riverso_Quote_Status::INVOICED) {
            throw new Riverso_Quote_Exception('Una cotización facturada no se puede editar en este corte.');
        }

        $customer_name = $this->clip((string) (isset($input['customer_name']) ? $input['customer_name'] : ''), 191);
        $customer_id = isset($input['customer_id']) ? $input['customer_id'] : null;
        $customer_id = ($customer_id === '' || $customer_id === null) ? null : (int) $customer_id;
        if ($customer_id !== null && $customer_id <= 0) {
            $customer_id = null;
        }
        $quote_type = Riverso_Quote_Type::normalize(isset($input['quote_type']) ? (string) $input['quote_type'] : null);
        $validity_days = $this->validity_days(isset($input['validity_days']) ? $input['validity_days'] : null);
        $validity_terms = $this->nullable_text(isset($input['validity_terms']) ? $input['validity_terms'] : null, 2000);
        $notes = $this->nullable_text(
            isset($input['notes']) ? $input['notes'] : (isset($existing['notes']) ? $existing['notes'] : null),
            5000
        );
        if ($notes === null) {
            $notes = '';
        }
        $channel = $this->normalize_channel(
            isset($input['channel']) ? $input['channel'] : (isset($existing['channel']) ? $existing['channel'] : 'local')
        );

        $lines = array();
        foreach ($input['lines'] as $line) {
            if (!is_array($line)) {
                throw new Riverso_Quote_Exception('Hay una línea de cotización inválida.');
            }
            $lines[] = $this->normalize_line($line);
        }
        $totals = Riverso_Quote_Totals::calculate($lines);
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');
        try {
            $header = array(
                'customer_id' => $customer_id,
                'customer_name' => $customer_name !== '' ? $customer_name : '',
                'quote_type' => $quote_type,
                'channel' => $channel,
                'validity_days' => $validity_days,
                'validity_terms' => $validity_terms,
                'notes' => $notes,
                'total' => $totals['net_total'],
                'net_total' => $totals['net_total'],
                'discount_total' => $totals['discount_total'],
                'margin_percent' => $totals['margin_percent'],
                'profit_total' => $totals['profit_total'],
                'subtotal' => $totals['net_total'] + $totals['discount_total'],
                'updated_at' => $now,
            );
            if ($validity_days !== null) {
                $header['valid_days'] = $validity_days;
                $header['valid_until'] = date('Y-m-d', strtotime('+' . $validity_days . ' days'));
            }
            $header = $this->filter_row_for_table($header, $this->quote_column_map());

            if ($existing === null) {
                $header['quote_number'] = $this->next_number();
                $header['status'] = Riverso_Quote_Status::DRAFT;
                $header['created_at'] = $now;
                $header['created_by'] = function_exists('get_current_user_id') ? get_current_user_id() : 0;
                $header = $this->filter_row_for_table($header, $this->quote_column_map());
                $ok = $wpdb->insert($this->table_quotes, $header);
                if (!$ok) {
                    throw new Riverso_Quote_Exception(
                        'Error guardando la cotización.' . $this->db_error_suffix()
                    );
                }
                $quote_id = (int) $wpdb->insert_id;
                if ($quote_id <= 0) {
                    throw new Riverso_Quote_Exception('Error guardando la cotización: id inválido.');
                }
            } else {
                $quote_id = (int) $existing['id'];
                // Separar NULL: update de wpdb con null omitido dejaría valores viejos;
                // con null→'' rompería DECIMAL/INT en strict mode.
                $null_cols = array();
                if ($customer_id === null && isset($this->quote_column_map()['customer_id'])) {
                    $null_cols[] = 'customer_id';
                }
                if ($validity_days === null && isset($this->quote_column_map()['validity_days'])) {
                    $null_cols[] = 'validity_days';
                }
                if ($validity_terms === null && isset($this->quote_column_map()['validity_terms'])) {
                    $null_cols[] = 'validity_terms';
                }
                if ($totals['margin_percent'] === null && isset($this->quote_column_map()['margin_percent'])) {
                    $null_cols[] = 'margin_percent';
                }
                if ($totals['profit_total'] === null && isset($this->quote_column_map()['profit_total'])) {
                    $null_cols[] = 'profit_total';
                }
                $updated = $wpdb->update($this->table_quotes, $header, array('id' => $quote_id));
                if ($updated === false) {
                    throw new Riverso_Quote_Exception(
                        'Error actualizando la cotización.' . $this->db_error_suffix()
                    );
                }
                if ($null_cols) {
                    $sets = array();
                    foreach (array_unique($null_cols) as $col) {
                        $sets[] = '`' . str_replace('`', '', $col) . '` = NULL';
                    }
                    $wpdb->query(
                        'UPDATE `' . str_replace('`', '', $this->table_quotes) . '` SET ' .
                        implode(', ', $sets) .
                        ' WHERE id = ' . $quote_id
                    );
                }
            }

            // Siempre reemplazar ítems (nuevo o update). Así no quedan huérfanos
            // ni se da por bueno un save de cabecera sin líneas.
            $this->replace_items($quote_id, $totals['lines']);

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof Riverso_Quote_Exception) {
                throw $e;
            }
            throw new Riverso_Quote_Exception('Error guardando la cotización.');
        }

        $quote = $this->find($quote_id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('No se pudo leer la cotización guardada.');
        }
        if (count($quote['lines']) !== count($totals['lines'])) {
            throw new Riverso_Quote_Exception(
                'La cotización se guardó sin todas las líneas (' .
                count($quote['lines']) . '/' . count($totals['lines']) . ').'
            );
        }
        return $quote;
    }

    /**
     * Borrador ↔ lista. La utilidad negativa o el margen bajo no impiden pasar a lista.
     */
    public function transition($id, $to) {
        global $wpdb;
        $quote = $this->find((int) $id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        $to = strtolower(trim((string) $to));
        if (!Riverso_Quote_Status::can_transition((string) $quote['status'], $to)) {
            throw new Riverso_Quote_Exception('Solo se puede pasar entre Borrador y Lista. Para facturar use Facturar.');
        }
        if ($quote['status'] !== $to) {
            $wpdb->update(
                $this->table_quotes,
                array(
                    'status' => $to,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => (int) $id),
                array('%s', '%s'),
                array('%d')
            );
        }
        $updated = $this->find((int) $id);
        if ($updated === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        return $updated;
    }


    /**
     * Marca cotización como facturada con order_id (idempotente / race-safe).
     * Solo aplica si sigue en listed y sin order_id.
     *
     * @param int $id
     * @param int $order_id
     * @return array Quote presentada
     */
    public function mark_invoiced($id, $order_id) {
        global $wpdb;
        $id = (int) $id;
        $order_id = (int) $order_id;
        if ($id <= 0 || $order_id <= 0) {
            throw new Riverso_Quote_Exception('Pedido u cotización inválidos al facturar.');
        }

        $quote = $this->find($id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        if ($quote['status'] === Riverso_Quote_Status::INVOICED && !empty($quote['order_id'])) {
            return $quote;
        }
        if ($quote['status'] !== Riverso_Quote_Status::LISTED) {
            throw new Riverso_Quote_Exception('Solo una cotización en Lista se puede facturar.');
        }

        $now = current_time('mysql');
        $table = str_replace('`', '', $this->table_quotes);
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = %s, order_id = %d, updated_at = %s
             WHERE id = %d AND status = %s AND (order_id IS NULL OR order_id = 0)",
            Riverso_Quote_Status::INVOICED,
            $order_id,
            $now,
            $id,
            Riverso_Quote_Status::LISTED
        ));
        if ($affected === false) {
            throw new Riverso_Quote_Exception('Error al marcar la cotización como facturada.' . $this->db_error_suffix());
        }
        if ((int) $affected === 0) {
            $again = $this->find($id);
            if ($again !== null && $again['status'] === Riverso_Quote_Status::INVOICED && !empty($again['order_id'])) {
                return $again;
            }
            throw new Riverso_Quote_Exception('No se pudo facturar: la cotización cambió de estado.');
        }

        $updated = $this->find($id);
        if ($updated === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada tras facturar.');
        }
        return $updated;
    }

    /**
     * Borra ítems previos e inserta el set actual. Falla ruidoso si un insert no escribe.
     *
     * @param int   $quote_id
     * @param array $lines Líneas ya normalizadas por Riverso_Quote_Totals::calculate().
     */
    private function replace_items($quote_id, array $lines) {
        global $wpdb;
        $quote_id = (int) $quote_id;
        $deleted = $wpdb->delete($this->table_items, array('quote_id' => $quote_id), array('%d'));
        if ($deleted === false) {
            throw new Riverso_Quote_Exception(
                'Error limpiando líneas de la cotización.' . $this->db_error_suffix()
            );
        }

        $columns = $this->item_column_map();
        foreach ($lines as $line) {
            $desc = (string) $line['description'];
            $line_net = (float) $line['line_net'];
            $discount = (float) $line['discount_amount'];
            $candidate = array(
                'quote_id' => $quote_id,
                'product_id' => $line['product_id'],
                'producto_base_id' => isset($line['producto_base_id']) ? $line['producto_base_id'] : null,
                'sku' => $line['sku'],
                'name' => $desc,
                'supplier_code' => $line['supplier_code'] !== '' ? $line['supplier_code'] : null,
                'barcode' => $line['barcode'] !== '' ? $line['barcode'] : null,
                'description' => $desc,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'],
                'price_discount' => isset($line['price_discount']) ? $line['price_discount'] : 0,
                'margin_discount' => isset($line['margin_discount']) ? $line['margin_discount'] : 0,
                'discount_amount' => $discount,
                'discount_percent' => 0,
                'subtotal' => $line_net + $discount,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'total' => $line_net,
                'line_total' => $line_net,
                'sort_order' => $line['sort_order'],
                'family_mode' => isset($line['family_mode']) ? $line['family_mode'] : null,
                'packaging' => isset($line['packaging']) ? $line['packaging'] : null,
                'units_per_pack' => isset($line['units_per_pack']) ? $line['units_per_pack'] : null,
            );

            // Schema legado: quantity INT. Evitar "1.000" si la columna no es decimal.
            if (isset($columns['quantity']) && !$this->column_is_decimal($columns['quantity'])) {
                $candidate['quantity'] = (int) round((float) $line['quantity']);
                if ($candidate['quantity'] <= 0) {
                    $candidate['quantity'] = 1;
                }
            }

            $row = $this->filter_row_for_table($candidate, $columns);

            // name NOT NULL en schema legado: garantizar valor si la columna existe.
            if (isset($columns['name']) && (!isset($row['name']) || $row['name'] === '')) {
                $row['name'] = $desc !== '' ? $desc : (string) $line['sku'];
            }
            if (isset($columns['sku']) && (!isset($row['sku']) || $row['sku'] === '')) {
                throw new Riverso_Quote_Exception('Cada línea necesita un SKU.');
            }

            $ok = $wpdb->insert($this->table_items, $row);
            if (!$ok) {
                throw new Riverso_Quote_Exception(
                    'Error guardando una línea de la cotización.' . $this->db_error_suffix()
                );
            }
        }
    }

    private function normalize_line(array $line) {
        $sku = $this->clip(trim((string) (isset($line['sku']) ? $line['sku'] : '')), 64);
        if ($sku === '') {
            throw new Riverso_Quote_Exception('Cada línea necesita un SKU.');
        }
        $quantity = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
        if ($quantity <= 0) {
            throw new Riverso_Quote_Exception('La cantidad debe ser mayor a cero.');
        }
        $price = round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 2);
        if ($price < 0) {
            throw new Riverso_Quote_Exception('El precio no puede ser negativo.');
        }
        $product_id = isset($line['product_id']) ? $line['product_id'] : null;
        $product_id = ($product_id === '' || $product_id === null) ? null : (int) $product_id;
        if ($product_id !== null && $product_id <= 0) {
            $product_id = null;
        }
        $description = $this->clip(trim((string) (isset($line['description']) ? $line['description'] : '')), 500);
        if ($description === '') {
            $description = $sku;
        }
        $unit_cost = isset($line['unit_cost']) ? $line['unit_cost'] : null;
        if ($unit_cost === '') {
            $unit_cost = null;
        }
        $pb = isset($line['producto_base_id']) ? $line['producto_base_id'] : null;
        $pb = ($pb === '' || $pb === null) ? null : (int) $pb;
        if ($pb !== null && $pb <= 0) {
            $pb = null;
        }
        $family_mode = isset($line['family_mode']) ? strtolower(trim((string) $line['family_mode'])) : '';
        if ($family_mode === '') {
            $family_mode = null;
        } elseif (!in_array($family_mode, array('unitaria', 'pack', 'kit'), true)) {
            $family_mode = $this->clip($family_mode, 32);
        }
        $packaging = isset($line['packaging']) ? $this->clip(trim((string) $line['packaging']), 64) : '';
        if ($packaging === '') {
            $packaging = null;
        }
        $upp = isset($line['units_per_pack']) ? $line['units_per_pack'] : null;
        if ($upp === '' || $upp === null) {
            $upp = null;
        } else {
            $upp = round((float) $upp, 4);
            if ($upp <= 0) {
                $upp = null;
            }
        }
        return array(
            'product_id' => $product_id,
            'producto_base_id' => $pb,
            'sku' => $sku,
            'supplier_code' => $this->clip(trim((string) (isset($line['supplier_code']) ? $line['supplier_code'] : '')), 64),
            'barcode' => $this->clip(trim((string) (isset($line['barcode']) ? $line['barcode'] : '')), 64),
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $price,
            'unit_cost' => $unit_cost === null ? null : round((float) $unit_cost, 2),
            'price_discount' => $this->rate(isset($line['price_discount']) ? $line['price_discount'] : 0),
            'margin_discount' => $this->rate(isset($line['margin_discount']) ? $line['margin_discount'] : 0),
            'discount_amount' => round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2),
            'family_mode' => $family_mode,
            'packaging' => $packaging,
            'units_per_pack' => $upp,
        );
    }

    private function rate($value) {
        $rate = round((float) $value, 2);
        if ($rate < 0) {
            return 0.0;
        }
        if ($rate > 100) {
            return 100.0;
        }
        return $rate;
    }

    private function validity_days($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new Riverso_Quote_Exception('La validez debe indicarse en días.');
        }
        $days = (int) $value;
        if ($days < 0) {
            throw new Riverso_Quote_Exception('La validez en días no puede ser negativa.');
        }
        if ($days > 3650) {
            throw new Riverso_Quote_Exception('La validez no puede superar 3650 días.');
        }
        return $days;
    }

    private function nullable_text($value, $max) {
        $text = trim((string) ($value === null ? '' : $value));
        if ($text === '') {
            return null;
        }
        return $this->clip($text, $max);
    }

    private function clip($value, $max) {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }

    private function next_number() {
        global $wpdb;
        $year = date('Y');
        $prefix = 'COT-' . $year . '-';
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT quote_number FROM {$this->table_quotes} WHERE quote_number LIKE %s ORDER BY id DESC LIMIT 1",
            $prefix . '%'
        ));
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', (string) $last, $matches)) {
            $seq = (int) $matches[1] + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function present_summary(array $row) {
        $lines = array();
        // Si el header no trae margen pero hay líneas con costo, recalcular para la lista.
        $margin = isset($row['margin_percent']) ? $row['margin_percent'] : null;
        if (($margin === null || $margin === '') && !empty($row['id'])) {
            global $wpdb;
            $lines = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE quote_id = %d ORDER BY sort_order ASC, id ASC",
                (int) $row['id']
            ), ARRAY_A);
            if (!is_array($lines)) {
                $lines = array();
            }
        }
        $presented = $this->present($row, $lines);
        unset($presented['lines']);
        $presented['line_count'] = (int) (isset($row['line_count']) ? $row['line_count'] : (is_array($lines) ? count($lines) : 0));
        return $presented;
    }

    private function present(array $row, array $lines) {
        $status = Riverso_Quote_Status::normalize_legacy((string) (isset($row['status']) ? $row['status'] : 'draft'));
        $type = (string) (isset($row['quote_type']) && $row['quote_type'] !== '' ? $row['quote_type'] : Riverso_Quote_Type::VENTA);
        $targets = Riverso_Quote_Status::allowed_targets($status);
        $net = isset($row['net_total']) && $row['net_total'] !== null && $row['net_total'] !== ''
            ? (float) $row['net_total']
            : (float) (isset($row['total']) ? $row['total'] : 0);
        $validity = isset($row['validity_days']) && $row['validity_days'] !== null && $row['validity_days'] !== ''
            ? (int) $row['validity_days']
            : (isset($row['valid_days']) && $row['valid_days'] !== null && $row['valid_days'] !== '' ? (int) $row['valid_days'] : null);
        $transitions = array();
        foreach ($targets as $target) {
            $transitions[] = array(
                'status' => $target,
                'label' => Riverso_Quote_Status::transition_label($target),
            );
        }
        $created_at = (string) (isset($row['created_at']) ? $row['created_at'] : '');
        $issue_date = $created_at;
        $seller_name = $this->resolve_seller_name(
            isset($row['created_by']) ? $row['created_by'] : null,
            isset($row['seller_name']) ? $row['seller_name'] : null
        );
        $is_expired = $this->is_expired($issue_date, $validity, isset($row['valid_until']) ? $row['valid_until'] : null);
        $margin_percent = $this->nullable_float(isset($row['margin_percent']) ? $row['margin_percent'] : null);
        $profit_total = $this->nullable_float(isset($row['profit_total']) ? $row['profit_total'] : null);
        $discount_total = round((float) (isset($row['discount_total']) ? $row['discount_total'] : 0), 2);
        $presented_lines = array_map(array($this, 'present_line'), $lines);
        // Recalcular utilidad/margen desde líneas con costo (evita «—» con costos reales
        // cuando el header quedó null por saves previos o schema tardío).
        if ($presented_lines) {
            $recalc_input = array();
            foreach ($presented_lines as $pl) {
                $recalc_input[] = array(
                    'quantity' => isset($pl['quantity']) ? $pl['quantity'] : 0,
                    'unit_price' => isset($pl['unit_price']) ? $pl['unit_price'] : 0,
                    'unit_cost' => array_key_exists('unit_cost', $pl) ? $pl['unit_cost'] : null,
                    'price_discount' => isset($pl['price_discount']) ? $pl['price_discount'] : 0,
                    'margin_discount' => isset($pl['margin_discount']) ? $pl['margin_discount'] : 0,
                    'discount_amount' => isset($pl['discount_amount']) ? $pl['discount_amount'] : 0,
                );
            }
            $recalc = Riverso_Quote_Totals::calculate($recalc_input);
            $net = (float) $recalc['net_total'];
            $discount_total = (float) $recalc['discount_total'];
            if ($recalc['margin_percent'] !== null) {
                $margin_percent = $recalc['margin_percent'];
            }
            if ($recalc['profit_total'] !== null) {
                $profit_total = $recalc['profit_total'];
            }
        }
        $channel = $this->normalize_channel(isset($row['channel']) ? $row['channel'] : 'local');
        return array(
            'id' => (int) $row['id'],
            'quote_number' => (string) $row['quote_number'],
            'customer_id' => $this->nullable_int(isset($row['customer_id']) ? $row['customer_id'] : null),
            'customer_name' => (string) (isset($row['customer_name']) ? $row['customer_name'] : ''),
            'quote_type' => $type,
            'quote_type_label' => Riverso_Quote_Type::label($type),
            'channel' => $channel,
            'status' => $status,
            'status_label' => Riverso_Quote_Status::label($status),
            'validity_days' => $validity,
            'validity_terms' => (string) (isset($row['validity_terms']) ? $row['validity_terms'] : ''),
            'net_total' => round($net, 2),
            'discount_total' => $discount_total,
            'margin_percent' => $margin_percent,
            'profit_total' => $profit_total,
            'notes' => (string) (isset($row['notes']) ? $row['notes'] : ''),
            'created_at' => $created_at,
            'issue_date' => $issue_date,
            'seller_name' => $seller_name,
            'is_expired' => $is_expired,
            'updated_at' => (string) (isset($row['updated_at']) ? $row['updated_at'] : ''),
            'editable' => $status !== Riverso_Quote_Status::INVOICED,
            'allowed_transitions' => $transitions,
            'order_id' => $this->nullable_int(isset($row['order_id']) ? $row['order_id'] : null),
            'order_url' => $this->resolve_order_url(isset($row['order_id']) ? $row['order_id'] : null),
            'can_invoice' => (
                $status === Riverso_Quote_Status::LISTED
                && $type === Riverso_Quote_Type::VENTA
                && empty($row['order_id'])
            ),
            'lines' => $presented_lines,
        );
    }

    /**
     * Vendedor: display_name del created_by; si no hay usuario, cadena vacía.
     */

    /**
     * URL de edición del pedido WC (HPOS-aware si existe).
     *
     * @param mixed $order_id
     * @return string
     */
    private function resolve_order_url($order_id) {
        $oid = $order_id === null || $order_id === '' ? 0 : (int) $order_id;
        if ($oid <= 0) {
            return '';
        }
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($oid);
            if ($order && is_object($order) && method_exists($order, 'get_edit_order_url')) {
                $url = $order->get_edit_order_url();
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }
        if (function_exists('admin_url')) {
            return admin_url('post.php?post=' . $oid . '&action=edit');
        }
        return '';
    }

    private function resolve_seller_name($created_by, $fallback = null) {
        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }
        $uid = $created_by === null || $created_by === '' ? 0 : (int) $created_by;
        if ($uid > 0 && function_exists('get_userdata')) {
            $user = get_userdata($uid);
            if ($user && !empty($user->display_name)) {
                return (string) $user->display_name;
            }
            if ($user && !empty($user->user_login)) {
                return (string) $user->user_login;
            }
        }
        return '';
    }

    /**
     * Vencida si fecha emisión + validity_days < hoy (zona WP).
     */
    private function is_expired($issue_date, $validity_days, $valid_until = null) {
        if ($validity_days === null || $validity_days === '') {
            return false;
        }
        $days = (int) $validity_days;
        if ($days < 0) {
            return false;
        }
        // Validez 0 días = vencida de inmediato (sin ventana útil).
        if ($days === 0) {
            return true;
        }
        $today = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
        $issue = '';
        if (is_string($issue_date) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $issue_date, $m)) {
            $issue = $m[1];
        }
        if ($issue === '') {
            if (is_string($valid_until) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $valid_until, $m2)) {
                return $m2[1] < $today;
            }
            return false;
        }
        $until_ts = strtotime($issue . ' +' . $days . ' days');
        if ($until_ts === false) {
            return false;
        }
        return date('Y-m-d', $until_ts) < $today;
    }

    private function present_line(array $line) {
        $desc = '';
        if (!empty($line['description'])) {
            $desc = (string) $line['description'];
        } elseif (!empty($line['name'])) {
            $desc = (string) $line['name'];
        }
        $for_calc = array(
            'quantity' => isset($line['quantity']) ? $line['quantity'] : 0,
            'unit_price' => isset($line['unit_price']) ? $line['unit_price'] : 0,
            'unit_cost' => isset($line['unit_cost']) ? $line['unit_cost'] : null,
            'price_discount' => isset($line['price_discount']) ? $line['price_discount'] : 0,
            'margin_discount' => isset($line['margin_discount']) ? $line['margin_discount'] : 0,
            'discount_amount' => isset($line['discount_amount']) ? $line['discount_amount'] : 0,
        );
        $calculated = Riverso_Quote_Totals::calculate(array($for_calc));
        $normalized = $calculated['lines'][0];
        return array(
            'id' => (int) (isset($line['id']) ? $line['id'] : 0),
            'product_id' => $this->nullable_int(isset($line['product_id']) ? $line['product_id'] : null),
            'producto_base_id' => $this->nullable_int(isset($line['producto_base_id']) ? $line['producto_base_id'] : null),
            'sku' => (string) (isset($line['sku']) ? $line['sku'] : ''),
            'supplier_code' => (string) (isset($line['supplier_code']) ? $line['supplier_code'] : ''),
            'barcode' => (string) (isset($line['barcode']) ? $line['barcode'] : ''),
            'description' => $desc,
            'quantity' => (float) $normalized['quantity'],
            'unit_price' => (float) $normalized['unit_price'],
            'unit_cost' => $normalized['unit_cost'],
            'price_discount' => (float) $normalized['price_discount'],
            'margin_discount' => (float) $normalized['margin_discount'],
            'discount_amount' => (float) $normalized['discount_amount'],
            'line_net' => (float) $normalized['line_net'],
            'line_profit' => $normalized['line_profit'],
            'line_margin_percent' => $normalized['line_margin_percent'],
            'family_mode' => isset($line['family_mode']) && $line['family_mode'] !== '' && $line['family_mode'] !== null
                ? (string) $line['family_mode'] : null,
            'packaging' => isset($line['packaging']) && $line['packaging'] !== '' && $line['packaging'] !== null
                ? (string) $line['packaging'] : null,
            'units_per_pack' => $this->nullable_float(isset($line['units_per_pack']) ? $line['units_per_pack'] : null),
            'local_only' => empty($line['product_id']),
        );
    }

    private function normalize_channel($value) {
        $value = strtolower(trim((string) $value));
        return $value === 'online' ? 'online' : 'local';
    }

    private function nullable_int($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullable_float($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }

    /**
     * Garantiza columnas P0+P1 (phase57) antes de escribir.
     * Idempotente; no toca prod remota desde aquí, solo el schema local del WP actual.
     */
    private function ensure_sale_schema() {
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_sale_fields')) {
            Riverso_POS_Activator::ensure_customer_quotes_sale_fields();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_advanced_discounts')) {
            Riverso_POS_Activator::ensure_customer_quotes_advanced_discounts();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_channel')) {
            Riverso_POS_Activator::ensure_customer_quotes_channel();
        }
        // Invalidar cache de columnas: phase57/58/59 pudieron agregar campos.
        $this->item_columns = null;
        $this->quote_columns = null;
    }

    /**
     * @return array<string, string> column => Type
     */
    private function item_column_map() {
        if ($this->item_columns === null) {
            $this->item_columns = $this->load_column_map($this->table_items);
        }
        return $this->item_columns;
    }

    /**
     * @return array<string, string> column => Type
     */
    private function quote_column_map() {
        if ($this->quote_columns === null) {
            $this->quote_columns = $this->load_column_map($this->table_quotes);
        }
        return $this->quote_columns;
    }

    /**
     * @param string $table
     * @return array<string, string>
     */
    private function load_column_map($table) {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['Field'])) {
                    $map[(string) $row['Field']] = isset($row['Type']) ? (string) $row['Type'] : '';
                }
            }
        }
        return $map;
    }

    /**
     * Filtra el row a columnas existentes y omite NULL.
     * Omitir NULL evita que WP antiguos conviertan null → '' y rompan
     * DECIMAL/BIGINT en modo strict (síntoma: cabecera OK, ítems 0).
     *
     * @param array               $row
     * @param array<string,string> $columns
     * @return array
     */
    private function filter_row_for_table(array $row, array $columns) {
        $out = array();
        foreach ($row as $key => $value) {
            if (!isset($columns[$key])) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function column_is_decimal($type) {
        $type = strtolower((string) $type);
        return strpos($type, 'decimal') !== false
            || strpos($type, 'float') !== false
            || strpos($type, 'double') !== false
            || strpos($type, 'numeric') !== false;
    }

    private function db_error_suffix() {
        global $wpdb;
        $err = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        return $err !== '' ? (' ' . $err) : '';
    }
}
