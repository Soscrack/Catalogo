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

    public function __construct() {
        global $wpdb;
        $this->table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $this->table_items = $wpdb->prefix . 'riverso_customer_quote_items';
    }

    public function list_quotes(array $filters = array()) {
        global $wpdb;
        $sql = "SELECT q.*, (SELECT COUNT(*) FROM {$this->table_items} i WHERE i.quote_id = q.id) AS line_count
                FROM {$this->table_quotes} q";
        $params = array();
        if (!empty($filters['status'])) {
            $sql .= ' WHERE q.status = %s';
            $params[] = Riverso_Quote_Status::normalize_legacy((string) $filters['status']);
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

            if ($existing === null) {
                $header['quote_number'] = $this->next_number();
                $header['status'] = Riverso_Quote_Status::DRAFT;
                $header['created_at'] = $now;
                $header['created_by'] = function_exists('get_current_user_id') ? get_current_user_id() : 0;
                $ok = $wpdb->insert($this->table_quotes, $header);
                if (!$ok) {
                    throw new Riverso_Quote_Exception('Error guardando la cotización.');
                }
                $quote_id = (int) $wpdb->insert_id;
            } else {
                $quote_id = (int) $existing['id'];
                $wpdb->update($this->table_quotes, $header, array('id' => $quote_id));
                $wpdb->delete($this->table_items, array('quote_id' => $quote_id), array('%d'));
            }

            foreach ($totals['lines'] as $line) {
                $desc = $line['description'];
                $wpdb->insert($this->table_items, array(
                    'quote_id' => $quote_id,
                    'product_id' => $line['product_id'],
                    'sku' => $line['sku'],
                    'name' => $desc,
                    'supplier_code' => $line['supplier_code'] !== '' ? $line['supplier_code'] : null,
                    'barcode' => $line['barcode'] !== '' ? $line['barcode'] : null,
                    'description' => $desc,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $line['unit_cost'],
                    'discount_amount' => $line['discount_amount'],
                    'discount_percent' => 0,
                    'subtotal' => $line['line_net'] + $line['discount_amount'],
                    'tax_percent' => 0,
                    'tax_amount' => 0,
                    'total' => $line['line_net'],
                    'line_total' => $line['line_net'],
                    'sort_order' => $line['sort_order'],
                ));
            }
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
        return $quote;
    }

    public function transition($id, $to) {
        global $wpdb;
        $quote = $this->find((int) $id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        $to = strtolower(trim((string) $to));
        if (!Riverso_Quote_Status::can_transition((string) $quote['status'], $to)) {
            throw new Riverso_Quote_Exception('Solo se puede pasar entre Borrador y Lista. Facturada queda para más adelante.');
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
        return array(
            'product_id' => $product_id,
            'sku' => $sku,
            'supplier_code' => $this->clip(trim((string) (isset($line['supplier_code']) ? $line['supplier_code'] : '')), 64),
            'barcode' => $this->clip(trim((string) (isset($line['barcode']) ? $line['barcode'] : '')), 64),
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $price,
            'unit_cost' => $unit_cost === null ? null : round((float) $unit_cost, 2),
            'discount_amount' => round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2),
        );
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
        $presented = $this->present($row, array());
        unset($presented['lines']);
        $presented['line_count'] = (int) (isset($row['line_count']) ? $row['line_count'] : 0);
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
        return array(
            'id' => (int) $row['id'],
            'quote_number' => (string) $row['quote_number'],
            'customer_id' => $this->nullable_int(isset($row['customer_id']) ? $row['customer_id'] : null),
            'customer_name' => (string) (isset($row['customer_name']) ? $row['customer_name'] : ''),
            'quote_type' => $type,
            'quote_type_label' => Riverso_Quote_Type::label($type),
            'status' => $status,
            'status_label' => Riverso_Quote_Status::label($status),
            'validity_days' => $validity,
            'validity_terms' => (string) (isset($row['validity_terms']) ? $row['validity_terms'] : ''),
            'net_total' => round($net, 2),
            'discount_total' => round((float) (isset($row['discount_total']) ? $row['discount_total'] : 0), 2),
            'margin_percent' => $this->nullable_float(isset($row['margin_percent']) ? $row['margin_percent'] : null),
            'profit_total' => $this->nullable_float(isset($row['profit_total']) ? $row['profit_total'] : null),
            'notes' => (string) (isset($row['notes']) ? $row['notes'] : ''),
            'created_at' => (string) (isset($row['created_at']) ? $row['created_at'] : ''),
            'updated_at' => (string) (isset($row['updated_at']) ? $row['updated_at'] : ''),
            'editable' => $status !== Riverso_Quote_Status::INVOICED,
            'allowed_transitions' => $transitions,
            'lines' => array_map(array($this, 'present_line'), $lines),
        );
    }

    private function present_line(array $line) {
        $line_net = isset($line['line_total']) && $line['line_total'] !== null && $line['line_total'] !== ''
            ? (float) $line['line_total']
            : (float) (isset($line['total']) ? $line['total'] : 0);
        $desc = '';
        if (!empty($line['description'])) {
            $desc = (string) $line['description'];
        } elseif (!empty($line['name'])) {
            $desc = (string) $line['name'];
        }
        return array(
            'id' => (int) (isset($line['id']) ? $line['id'] : 0),
            'product_id' => $this->nullable_int(isset($line['product_id']) ? $line['product_id'] : null),
            'sku' => (string) (isset($line['sku']) ? $line['sku'] : ''),
            'supplier_code' => (string) (isset($line['supplier_code']) ? $line['supplier_code'] : ''),
            'barcode' => (string) (isset($line['barcode']) ? $line['barcode'] : ''),
            'description' => $desc,
            'quantity' => round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3),
            'unit_price' => round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 2),
            'unit_cost' => $this->nullable_float(isset($line['unit_cost']) ? $line['unit_cost'] : null),
            'discount_amount' => round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2),
            'line_net' => round($line_net, 2),
        );
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
}