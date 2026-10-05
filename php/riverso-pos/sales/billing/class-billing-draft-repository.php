<?php
/**
 * Borradores de boleta electrónica (locales, sin FACTO).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Billing_Draft_Repository {

    /** @return string */
    private function drafts_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_drafts';
    }

    /** @return string */
    private function lines_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_draft_lines';
    }

    /** @return string */
    private function refs_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_draft_refs';
    }

    /** @return string */
    private function payments_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_draft_payments';
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get($id) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->drafts_table()} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        return $this->present_full($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function save(array $data) {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $user_id = get_current_user_id() ?: null;
        $now = current_time('mysql');

        $header = [
            'document_type_id' => 37,
            'status' => in_array(($data['status'] ?? 'draft'), ['draft', 'emitted', 'closed_local'], true)
                ? (string) $data['status']
                : 'draft',
            'issue_date' => $this->sanitize_date($data['issue_date'] ?? ''),
            'due_date' => $this->sanitize_date($data['due_date'] ?? '', true),
            'payment_conditions' => substr((string) ($data['payment_conditions'] ?? '0'), 0, 32),
            'sale_state' => substr((string) ($data['sale_state'] ?? 'VENTA: Concretada'), 0, 64),
            'quote_id' => !empty($data['quote_id']) ? absint($data['quote_id']) : null,
            'net_amount' => round((float) ($data['net_amount'] ?? 0), 2),
            'exempt_amount' => round((float) ($data['exempt_amount'] ?? 0), 2),
            'tax_amount' => round((float) ($data['tax_amount'] ?? 0), 2),
            'total_amount' => round((float) ($data['total_amount'] ?? 0), 2),
            'updated_by' => $user_id,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$this->drafts_table()} WHERE id = %d",
                $id
            ), ARRAY_A);
            if (!$existing) {
                return ['ok' => false, 'message' => 'Borrador no encontrado.'];
            }
            $current_status = (string) ($existing['status'] ?? 'draft');
            if (in_array($current_status, ['emitted', 'closed_local'], true)
                && ($header['status'] === 'draft')) {
                $header['status'] = $current_status;
            }
            $ok = $wpdb->update($this->drafts_table(), $header, ['id' => $id]);
            if ($ok === false) {
                return ['ok' => false, 'message' => 'No se pudo actualizar el borrador.'];
            }
        } else {
            $header['created_by'] = $user_id;
            $header['created_at'] = $now;
            $ok = $wpdb->insert($this->drafts_table(), $header);
            if (!$ok) {
                return ['ok' => false, 'message' => 'No se pudo crear el borrador.'];
            }
            $id = (int) $wpdb->insert_id;
        }

        $this->replace_lines($id, isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : []);
        $this->replace_refs($id, isset($data['refs']) && is_array($data['refs']) ? $data['refs'] : []);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param int $draft_id
     * @param array<int, array<string, mixed>> $lines
     */
    private function replace_lines($draft_id, array $lines) {
        global $wpdb;
        $draft_id = absint($draft_id);
        $wpdb->delete($this->lines_table(), ['draft_id' => $draft_id]);
        $pos = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 0);
            // Preferir unit_price del cliente (por unidad); unit_price_bruto puede ser bruto/envase.
            $price = (float) ($line['unit_price'] ?? $line['unit_price_bruto'] ?? 0);
            if ($qty <= 0 && trim((string) ($line['description'] ?? '')) === '' && trim((string) ($line['sku'] ?? '')) === '') {
                continue;
            }
            $pos++;
            $afecto = 1;
            if (array_key_exists('afecto', $line)) {
                $afecto = !empty($line['afecto']) ? 1 : 0;
            }
            $price_mode = strtolower(trim((string) ($line['price_mode'] ?? 'auto')));
            if (!in_array($price_mode, ['auto', 'ref', 'manual', 'std'], true)) {
                $price_mode = 'auto';
            }
            $upp = (float) ($line['units_per_pack'] ?? 1);
            if ($upp <= 0) {
                $upp = 1.0;
            }
            $unit_cost = null;
            if (array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== '') {
                $unit_cost = round((float) $line['unit_cost'], 4);
            }
            $price_ref = null;
            if ($price_mode === 'ref' && isset($line['price_ref']) && $line['price_ref'] !== null && $line['price_ref'] !== '') {
                $price_ref = round((float) $line['price_ref'], 6);
            }
            $price_total = null;
            if ($price_mode === 'manual' && isset($line['price_total']) && $line['price_total'] !== null && $line['price_total'] !== '') {
                $price_total = round((float) $line['price_total'], 2);
            }
            $rule_total = null;
            if (isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
                $rule_total = round((float) $line['rule_total'], 2);
            }
            $wpdb->insert($this->lines_table(), [
                'draft_id' => $draft_id,
                'position' => $pos,
                'sku' => substr((string) ($line['sku'] ?? ''), 0, 64),
                'description' => substr((string) ($line['description'] ?? ''), 0, 255),
                'quantity' => round($qty > 0 ? $qty : 1, 3),
                'unit_price_bruto' => round($price, 6),
                'afecto' => $afecto,
                'line_total_bruto' => round((float) ($line['line_total_bruto'] ?? ($qty * $price)), 2),
                'product_id' => !empty($line['product_id']) ? absint($line['product_id']) : null,
                'producto_base_id' => !empty($line['producto_base_id']) ? absint($line['producto_base_id']) : null,
                'units_per_pack' => round($upp, 4),
                'family_mode' => substr((string) ($line['family_mode'] ?? ''), 0, 32),
                'packaging' => substr((string) ($line['packaging'] ?? ''), 0, 64),
                'grupo_id' => !empty($line['grupo_id']) ? absint($line['grupo_id']) : (
                    !empty($line['_family']['grupo_id']) ? absint($line['_family']['grupo_id']) : null
                ),
                'unit_cost' => $unit_cost,
                'price_mode' => $price_mode,
                'price_ref' => $price_ref,
                'price_total' => $price_total,
                'price_discount' => round((float) ($line['price_discount'] ?? 0), 4),
                'margin_discount' => round((float) ($line['margin_discount'] ?? 0), 4),
                'discount_amount' => round((float) ($line['discount_amount'] ?? 0), 2),
                'rule_total' => $rule_total,
                'rule_adjusted' => !empty($line['rule_adjusted']) || !empty($line['_rule_adjusted']) ? 1 : 0,
            ]);
        }
    }

    /**
     * @param int $draft_id
     * @param array<int, array<string, mixed>> $refs
     */
    private function replace_refs($draft_id, array $refs) {
        global $wpdb;
        $draft_id = absint($draft_id);
        $wpdb->delete($this->refs_table(), ['draft_id' => $draft_id]);
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $type = substr((string) ($ref['ref_type'] ?? 'other'), 0, 32);
            $wpdb->insert($this->refs_table(), [
                'draft_id' => $draft_id,
                'ref_type' => $type !== '' ? $type : 'other',
                'quote_id' => !empty($ref['quote_id']) ? absint($ref['quote_id']) : null,
                'ref_doc_type' => substr((string) ($ref['ref_doc_type'] ?? ''), 0, 64),
                'ref_folio' => substr((string) ($ref['ref_folio'] ?? ''), 0, 64),
                'ref_label' => substr((string) ($ref['ref_label'] ?? ''), 0, 255),
            ]);
        }
    }

    /**
     * @param int                  $draft_id
     * @param array<string, mixed> $payment
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function add_payment($draft_id, array $payment) {
        return $this->add_document_payment(array_merge($payment, [
            'draft_id' => absint($draft_id),
        ]));
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function add_document_payment(array $payment) {
        global $wpdb;
        $draft_id = !empty($payment['draft_id']) ? absint($payment['draft_id']) : 0;
        $dte_id = !empty($payment['dte_id']) ? absint($payment['dte_id']) : 0;
        $draft = $draft_id > 0 ? $this->get($draft_id) : null;
        if ($draft_id > 0 && !$draft) {
            return ['ok' => false, 'message' => 'Documento no encontrado.'];
        }
        if ($draft && !in_array(($draft['status'] ?? ''), ['emitted', 'closed_local'], true)) {
            return ['ok' => false, 'message' => 'Cierra el documento antes de ingresar pagos.'];
        }
        if ($dte_id <= 0 && $draft) {
            $dte_id = (int) ($draft['dte_id'] ?? 0);
        }
        if ($draft_id <= 0 && $dte_id <= 0) {
            return ['ok' => false, 'message' => 'Indica el documento a pagar.'];
        }

        $amount_paid = round((float) ($payment['amount_paid'] ?? 0), 2);
        $change_amount = round((float) ($payment['change_amount'] ?? 0), 2);
        $applied = isset($payment['amount_applied'])
            ? round((float) $payment['amount_applied'], 2)
            : round(max(0, $amount_paid - $change_amount), 2);
        if ($applied <= 0) {
            return ['ok' => false, 'message' => 'El monto aplicado debe ser mayor a 0.'];
        }

        $total = 0.0;
        if ($draft) {
            $total = (float) ($draft['total_amount'] ?? 0);
        } elseif ($dte_id > 0 && class_exists('Riverso_Dte_Issued_Repository')) {
            $issued = new Riverso_Dte_Issued_Repository();
            $dte = $issued->get($dte_id);
            $total = $dte ? (float) ($dte['total_amount'] ?? 0) : 0.0;
        }
        $paid_so_far = $this->sum_applied($draft_id, $dte_id);
        if (round($paid_so_far + $applied, 2) > round($total + 0.009, 2)) {
            return ['ok' => false, 'message' => 'El pago supera el saldo pendiente.'];
        }

        $caja_id = !empty($payment['caja_id']) ? absint($payment['caja_id']) : null;
        $sync = (string) ($payment['facto_sync_status'] ?? 'pending');
        if (!in_array($sync, ['off', 'pending', 'sending', 'ok', 'error', 'unknown'], true)) {
            $sync = 'pending';
        }
        $ok = $wpdb->insert($this->payments_table(), [
            'draft_id' => $draft_id > 0 ? $draft_id : null,
            'dte_id' => $dte_id > 0 ? $dte_id : null,
            'pay_date' => $this->sanitize_date($payment['pay_date'] ?? current_time('Y-m-d')),
            'caja' => substr((string) ($payment['caja'] ?? 'Efectivo'), 0, 64),
            'caja_id' => $caja_id,
            'method' => substr((string) ($payment['method'] ?? 'Efectivo'), 0, 64),
            'method_id' => !empty($payment['method_id']) ? absint($payment['method_id']) : null,
            'amount_due' => round((float) ($payment['amount_due'] ?? 0), 2),
            'amount_paid' => $amount_paid,
            'change_amount' => $change_amount,
            'amount_applied' => $applied,
            'notes' => substr((string) ($payment['notes'] ?? ''), 0, 500),
            'charge_code' => substr((string) ($payment['charge_code'] ?? ''), 0, 32),
            'cheque_numero' => substr((string) ($payment['cheque_numero'] ?? ''), 0, 64),
            'cheque_titular' => substr((string) ($payment['cheque_titular'] ?? ''), 0, 128),
            'cheque_banco' => substr((string) ($payment['cheque_banco'] ?? ''), 0, 128),
            'facto_sync_status' => $sync,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo registrar el pago.'];
        }
        return ['ok' => true, 'id' => (int) $wpdb->insert_id];
    }

    /**
     * @param int $draft_id
     * @param int $dte_id
     * @return float
     */
    public function sum_applied($draft_id = 0, $dte_id = 0) {
        $sum = 0.0;
        foreach ($this->list_document_payments($draft_id, $dte_id) as $p) {
            $applied = (float) ($p['amount_applied'] ?? 0);
            if ($applied <= 0) {
                $applied = max(0, (float) ($p['amount_paid'] ?? 0) - (float) ($p['change_amount'] ?? 0));
            }
            $sum += $applied;
        }
        return round($sum, 2);
    }

    /**
     * @param int $draft_id
     * @param int $dte_id
     */
    public function mark_emitted($draft_id, $dte_id) {
        global $wpdb;
        $draft_id = absint($draft_id);
        $dte_id = absint($dte_id);
        if ($draft_id <= 0) {
            return;
        }
        $wpdb->update($this->drafts_table(), [
            'status' => 'emitted',
            'dte_id' => $dte_id > 0 ? $dte_id : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $draft_id]);
        if ($dte_id > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->payments_table()}
                 SET dte_id = %d,
                     facto_sync_status = CASE
                        WHEN facto_sync_status IN ('off','') THEN 'pending'
                        ELSE facto_sync_status
                     END
                 WHERE draft_id = %d AND (dte_id IS NULL OR dte_id = 0)",
                $dte_id,
                $draft_id
            ));
        }
    }

    /**
     * @param int $draft_id
     */
    public function mark_closed_local($draft_id) {
        global $wpdb;
        $draft_id = absint($draft_id);
        if ($draft_id <= 0) {
            return;
        }
        $wpdb->update($this->drafts_table(), [
            'status' => 'closed_local',
            'updated_at' => current_time('mysql'),
        ], ['id' => $draft_id]);
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get_payment($id) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->payments_table()} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        return $row ? $this->present_payment($row) : null;
    }

    /**
     * @param int $id
     * @return array{ok:bool,payment?:array,message?:string}
     */
    public function delete_payment($id) {
        global $wpdb;
        $pay = $this->get_payment($id);
        if (!$pay) {
            return ['ok' => false, 'message' => 'Pago no encontrado.'];
        }
        $ok = $wpdb->delete($this->payments_table(), ['id' => absint($id)]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo borrar el pago.'];
        }
        return ['ok' => true, 'payment' => $pay];
    }

    /**
     * @param int $draft_id
     * @param int $dte_id
     * @return array<int, array<string, mixed>>
     */
    public function list_document_payments($draft_id = 0, $dte_id = 0) {
        global $wpdb;
        $draft_id = absint($draft_id);
        $dte_id = absint($dte_id);
        if ($draft_id <= 0 && $dte_id <= 0) {
            return [];
        }
        if ($draft_id > 0 && $dte_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->payments_table()}
                 WHERE draft_id = %d OR dte_id = %d
                 ORDER BY id ASC",
                $draft_id,
                $dte_id
            ), ARRAY_A) ?: [];
        } elseif ($draft_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->payments_table()} WHERE draft_id = %d ORDER BY id ASC",
                $draft_id
            ), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->payments_table()} WHERE dte_id = %d ORDER BY id ASC",
                $dte_id
            ), ARRAY_A) ?: [];
        }
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $this->present_payment($r);
        }
        return $out;
    }

    /**
     * @param int $draft_id
     * @return array<int, array<string, mixed>>
     */
    public function list_pending_sync($draft_id) {
        $out = [];
        foreach ($this->list_payments($draft_id) as $p) {
            if (in_array(($p['facto_sync_status'] ?? ''), ['pending', 'error'], true) && empty($p['facto_payment_id'])) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present_full(array $row) {
        $id = (int) $row['id'];
        return [
            'id' => $id,
            'document_type_id' => (int) ($row['document_type_id'] ?? 37),
            'status' => (string) ($row['status'] ?? 'draft'),
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'payment_conditions' => (string) ($row['payment_conditions'] ?? '0'),
            'sale_state' => (string) ($row['sale_state'] ?? 'VENTA: Concretada'),
            'quote_id' => isset($row['quote_id']) ? (int) $row['quote_id'] : null,
            'dte_id' => !empty($row['dte_id']) ? (int) $row['dte_id'] : null,
            'net_amount' => round((float) ($row['net_amount'] ?? 0), 2),
            'exempt_amount' => round((float) ($row['exempt_amount'] ?? 0), 2),
            'tax_amount' => round((float) ($row['tax_amount'] ?? 0), 2),
            'total_amount' => round((float) ($row['total_amount'] ?? 0), 2),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'lines' => $this->list_lines($id),
            'refs' => $this->list_refs($id),
            'payments' => $this->list_document_payments($id, !empty($row['dte_id']) ? (int) $row['dte_id'] : 0),
        ];
    }

    /**
     * @param int $draft_id
     * @return array<int, array<string, mixed>>
     */
    private function list_lines($draft_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->lines_table()} WHERE draft_id = %d ORDER BY position ASC, id ASC",
            $draft_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $unit_price = (float) ($r['unit_price_bruto'] ?? 0);
            $out[] = [
                'id' => (int) $r['id'],
                'sku' => (string) ($r['sku'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'quantity' => (float) ($r['quantity'] ?? 1),
                'unit_price' => $unit_price,
                'unit_price_bruto' => $unit_price,
                'afecto' => !empty($r['afecto']),
                'line_total_bruto' => (float) ($r['line_total_bruto'] ?? 0),
                'product_id' => isset($r['product_id']) && $r['product_id'] !== null ? (int) $r['product_id'] : null,
                'producto_base_id' => isset($r['producto_base_id']) && $r['producto_base_id'] !== null ? (int) $r['producto_base_id'] : null,
                'units_per_pack' => (float) ($r['units_per_pack'] ?? 1),
                'family_mode' => (string) ($r['family_mode'] ?? ''),
                'packaging' => (string) ($r['packaging'] ?? ''),
                'grupo_id' => isset($r['grupo_id']) && $r['grupo_id'] !== null ? (int) $r['grupo_id'] : null,
                'unit_cost' => isset($r['unit_cost']) && $r['unit_cost'] !== null ? (float) $r['unit_cost'] : null,
                'price_mode' => (string) ($r['price_mode'] ?? 'auto'),
                'price_ref' => isset($r['price_ref']) && $r['price_ref'] !== null ? (float) $r['price_ref'] : null,
                'price_total' => isset($r['price_total']) && $r['price_total'] !== null ? (float) $r['price_total'] : null,
                'price_discount' => (float) ($r['price_discount'] ?? 0),
                'margin_discount' => (float) ($r['margin_discount'] ?? 0),
                'discount_amount' => (float) ($r['discount_amount'] ?? 0),
                'rule_total' => isset($r['rule_total']) && $r['rule_total'] !== null ? (float) $r['rule_total'] : null,
                'rule_adjusted' => !empty($r['rule_adjusted']),
            ];
        }
        return $out;
    }

    /**
     * @param int $draft_id
     * @return array<int, array<string, mixed>>
     */
    private function list_refs($draft_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->refs_table()} WHERE draft_id = %d ORDER BY id ASC",
            $draft_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'ref_type' => (string) ($r['ref_type'] ?? ''),
                'quote_id' => isset($r['quote_id']) ? (int) $r['quote_id'] : null,
                'ref_doc_type' => (string) ($r['ref_doc_type'] ?? ''),
                'ref_folio' => (string) ($r['ref_folio'] ?? ''),
                'ref_label' => (string) ($r['ref_label'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param int $draft_id
     * @return array<int, array<string, mixed>>
     */
    private function list_payments($draft_id) {
        return $this->list_document_payments($draft_id, 0);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present_payment(array $r) {
        $applied = (float) ($r['amount_applied'] ?? 0);
        if ($applied <= 0) {
            $applied = max(0, (float) ($r['amount_paid'] ?? 0) - (float) ($r['change_amount'] ?? 0));
        }
        return [
            'id' => (int) $r['id'],
            'draft_id' => !empty($r['draft_id']) ? (int) $r['draft_id'] : null,
            'dte_id' => !empty($r['dte_id']) ? (int) $r['dte_id'] : null,
            'pay_date' => (string) ($r['pay_date'] ?? ''),
            'caja' => (string) ($r['caja'] ?? ''),
            'caja_id' => !empty($r['caja_id']) ? (int) $r['caja_id'] : null,
            'method' => (string) ($r['method'] ?? ''),
            'method_id' => !empty($r['method_id']) ? (int) $r['method_id'] : null,
            'amount_due' => (float) ($r['amount_due'] ?? 0),
            'amount_paid' => (float) ($r['amount_paid'] ?? 0),
            'change_amount' => (float) ($r['change_amount'] ?? 0),
            'amount_applied' => $applied,
            'notes' => (string) ($r['notes'] ?? ''),
            'charge_code' => (string) ($r['charge_code'] ?? ''),
            'cheque_numero' => (string) ($r['cheque_numero'] ?? ''),
            'cheque_titular' => (string) ($r['cheque_titular'] ?? ''),
            'cheque_banco' => (string) ($r['cheque_banco'] ?? ''),
            'facto_payment_id' => (string) ($r['facto_payment_id'] ?? ''),
            'facto_sync_status' => (string) ($r['facto_sync_status'] ?? 'off'),
            'facto_sync_error' => (string) ($r['facto_sync_error'] ?? ''),
            'facto_synced_at' => (string) ($r['facto_synced_at'] ?? ''),
        ];
    }

    /**
     * @param string $date
     * @param bool   $allow_empty
     * @return string|null
     */
    private function sanitize_date($date, $allow_empty = false) {
        $date = trim((string) $date);
        if ($date === '') {
            return $allow_empty ? null : current_time('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $allow_empty ? null : current_time('Y-m-d');
        }
        return $date;
    }
}
