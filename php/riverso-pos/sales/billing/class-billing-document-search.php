<?php
/**
 * Búsqueda local de documentos tributarios (borradores + DTE emitidos).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Billing_Document_Search {

    const LIMIT = 500;

    /** @return string */
    private function drafts_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_drafts';
    }

    /** @return string */
    private function issued_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_dte_issued';
    }

    /** @return string */
    private function payments_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_draft_payments';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{groups: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters) {
        $include_issued = !isset($filters['include_issued']) || !empty($filters['include_issued']);
        $include_drafts = !isset($filters['include_drafts']) || !empty($filters['include_drafts']);

        $rows = [];
        if ($include_drafts) {
            $rows = array_merge($rows, $this->fetch_drafts($filters));
        }
        if ($include_issued) {
            $rows = array_merge($rows, $this->fetch_issued($filters));
        }

        $rows = $this->apply_payment_filter($rows, (string) ($filters['payment_status'] ?? ''));
        usort($rows, static function ($a, $b) {
            $da = (string) ($a['issue_date'] ?? '');
            $db = (string) ($b['issue_date'] ?? '');
            if ($da !== $db) {
                return strcmp($db, $da);
            }
            return ((int) ($b['sort_id'] ?? 0)) - ((int) ($a['sort_id'] ?? 0));
        });

        if (count($rows) > self::LIMIT) {
            $rows = array_slice($rows, 0, self::LIMIT);
        }

        return [
            'groups' => $this->group_rows($rows),
            'total' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetch_drafts(array $filters) {
        global $wpdb;
        $drafts = $this->drafts_table();
        $payments = $this->payments_table();

        $where = ["d.status IN ('draft', 'closed_local')"];
        $params = [];

        $date_from = $this->sanitize_date($filters['date_from'] ?? '');
        $date_to = $this->sanitize_date($filters['date_to'] ?? '');
        if ($date_from) {
            $where[] = 'd.issue_date >= %s';
            $params[] = $date_from;
        }
        if ($date_to) {
            $where[] = 'd.issue_date <= %s';
            $params[] = $date_to;
        }

        $doc_status = (string) ($filters['document_status'] ?? '');
        if ($doc_status === 'draft') {
            $where[] = "d.status = 'draft'";
        } elseif ($doc_status === 'closed_local') {
            $where[] = "d.status = 'closed_local'";
        } elseif (in_array($doc_status, ['emitted', 'error'], true)) {
            return [];
        }

        $created_by = absint($filters['created_by'] ?? 0);
        if ($created_by > 0) {
            $where[] = 'd.created_by = %d';
            $params[] = $created_by;
        }

        $total_min = $this->nullable_float($filters['total_min'] ?? null);
        $total_max = $this->nullable_float($filters['total_max'] ?? null);
        if ($total_min !== null) {
            $where[] = 'd.total_amount >= %f';
            $params[] = $total_min;
        }
        if ($total_max !== null) {
            $where[] = 'd.total_amount <= %f';
            $params[] = $total_max;
        }

        // Folio / número de documento: borradores no tienen folio FACTO.
        $doc_number = trim((string) ($filters['document_number'] ?? ''));
        $folio_from = trim((string) ($filters['folio_from'] ?? ''));
        $folio_to = trim((string) ($filters['folio_to'] ?? ''));
        if ($doc_number !== '' || $folio_from !== '' || $folio_to !== '') {
            return [];
        }

        // Solo borradores de factura guardan receptor; boletas quedan fuera al filtrar por RUT/nombre.
        $rut = $this->normalize_rut_filter($filters['receiver_rut'] ?? '');
        if ($rut !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(UPPER(IFNULL(d.receiver_rut,'')), '.', ''), '-', ''), ' ', '') LIKE %s";
            $params[] = '%' . $wpdb->esc_like($rut) . '%';
        }
        $receiver_name = trim((string) ($filters['receiver_name'] ?? ''));
        if ($receiver_name !== '') {
            $where[] = 'd.receiver_legal_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($receiver_name) . '%';
        }

        $sql = "SELECT d.*,
                       COALESCE((
                           SELECT SUM(
                               CASE
                                   WHEN p.amount_applied > 0 THEN p.amount_applied
                                   ELSE GREATEST(0, p.amount_paid - p.change_amount)
                               END
                           )
                           FROM {$payments} p
                           WHERE p.draft_id = d.id
                       ), 0) AS paid_amount
                FROM {$drafts} d
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.issue_date DESC, d.id DESC
                LIMIT " . (int) self::LIMIT;

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $raw = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->present_draft_row($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetch_issued(array $filters) {
        global $wpdb;
        $issued = $this->issued_table();
        $drafts = $this->drafts_table();
        $payments = $this->payments_table();

        $where = ['i.facto_document_id IS NOT NULL'];
        $params = [];

        $date_from = $this->sanitize_date($filters['date_from'] ?? '');
        $date_to = $this->sanitize_date($filters['date_to'] ?? '');
        if ($date_from) {
            $where[] = 'i.issue_date >= %s';
            $params[] = $date_from;
        }
        if ($date_to) {
            $where[] = 'i.issue_date <= %s';
            $params[] = $date_to;
        }

        $doc_status = (string) ($filters['document_status'] ?? '');
        if (in_array($doc_status, ['draft', 'closed_local'], true)) {
            return [];
        }
        if ($doc_status === 'emitted') {
            $where[] = '(i.facto_status IS NULL OR i.facto_status IN (0, 2))';
        } elseif ($doc_status === 'error') {
            $where[] = '(i.facto_status IS NOT NULL AND i.facto_status NOT IN (0, 2))';
        }

        $sii_status = (string) ($filters['sii_status'] ?? '');
        if ($sii_status === 'ok') {
            $where[] = '(i.facto_status IS NULL OR i.facto_status IN (0, 2))';
        } elseif ($sii_status === 'error') {
            $where[] = '(i.facto_status IS NOT NULL AND i.facto_status NOT IN (0, 2))';
        } elseif ($sii_status === 'pending') {
            $where[] = 'i.facto_status = 1';
        }

        $created_by = absint($filters['created_by'] ?? 0);
        if ($created_by > 0) {
            $where[] = 'i.created_by = %d';
            $params[] = $created_by;
        }

        $total_min = $this->nullable_float($filters['total_min'] ?? null);
        $total_max = $this->nullable_float($filters['total_max'] ?? null);
        if ($total_min !== null) {
            $where[] = 'i.total_amount >= %f';
            $params[] = $total_min;
        }
        if ($total_max !== null) {
            $where[] = 'i.total_amount <= %f';
            $params[] = $total_max;
        }

        $rut = $this->normalize_rut_filter($filters['receiver_rut'] ?? '');
        if ($rut !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(UPPER(IFNULL(i.receiver_rut,'')), '.', ''), '-', ''), ' ', '') LIKE %s";
            $params[] = '%' . $wpdb->esc_like($rut) . '%';
        }

        $receiver_name = trim((string) ($filters['receiver_name'] ?? ''));
        if ($receiver_name !== '') {
            $where[] = 'i.receiver_legal_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($receiver_name) . '%';
        }

        $doc_number = trim((string) ($filters['document_number'] ?? ''));
        if ($doc_number !== '') {
            $where[] = 'i.folio = %s';
            $params[] = $doc_number;
        }

        $folio_from = trim((string) ($filters['folio_from'] ?? ''));
        $folio_to = trim((string) ($filters['folio_to'] ?? ''));
        if ($folio_from !== '' && ctype_digit($folio_from)) {
            $where[] = 'CAST(i.folio AS UNSIGNED) >= %d';
            $params[] = (int) $folio_from;
        }
        if ($folio_to !== '' && ctype_digit($folio_to)) {
            $where[] = 'CAST(i.folio AS UNSIGNED) <= %d';
            $params[] = (int) $folio_to;
        }

        $sql = "SELECT i.*,
                       d.id AS linked_draft_id,
                       d.sale_state AS draft_sale_state,
                       COALESCE((
                           SELECT SUM(
                               CASE
                                   WHEN p.amount_applied > 0 THEN p.amount_applied
                                   ELSE GREATEST(0, p.amount_paid - p.change_amount)
                               END
                           )
                           FROM {$payments} p
                           WHERE p.dte_id = i.id
                              OR (d.id IS NOT NULL AND p.draft_id = d.id)
                       ), 0) AS paid_amount
                FROM {$issued} i
                LEFT JOIN {$drafts} d ON d.dte_id = i.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY i.issue_date DESC, i.id DESC
                LIMIT " . (int) self::LIMIT;

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $raw = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->present_issued_row($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present_draft_row(array $row) {
        $total = round((float) ($row['total_amount'] ?? 0), 2);
        $paid = round((float) ($row['paid_amount'] ?? 0), 2);
        $unpaid = round(max(0, $total - $paid), 2);
        $status = (string) ($row['status'] ?? 'draft');
        $type_id = (int) ($row['document_type_id'] ?? 37);
        $recv_rut = trim((string) ($row['receiver_rut'] ?? ''));
        $recv_name = trim((string) ($row['receiver_legal_name'] ?? ''));
        return [
            'kind' => $status === 'closed_local' ? 'closed_local' : 'draft',
            'source' => 'draft',
            'id' => (int) $row['id'],
            'draft_id' => (int) $row['id'],
            'dte_id' => null,
            'facto_document_id' => null,
            'document_type_id' => $type_id,
            'document_type_label' => $this->type_label($type_id),
            'group_key' => $type_id . '_draft',
            'group_label' => $this->type_label($type_id) . ' borrador',
            'folio' => '',
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'receiver_rut' => $recv_rut,
            'receiver_legal_name' => $recv_name,
            'receiver_display' => $recv_rut !== ''
                ? trim($recv_name . ' · ' . $recv_rut, ' ·')
                : 'Registros internos o con boletas',
            'net_amount' => round((float) ($row['net_amount'] ?? 0), 2),
            'taxes_amount' => round((float) ($row['tax_amount'] ?? 0), 2),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'unpaid_amount' => $unpaid,
            'sale_state' => (string) ($row['sale_state'] ?? 'VENTA: Concretada'),
            'status' => $status,
            'facto_status' => null,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'sort_id' => (int) $row['id'],
            'can_edit' => true,
            'can_pdf' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present_issued_row(array $row) {
        $total = round((float) ($row['total_amount'] ?? 0), 2);
        $paid = round((float) ($row['paid_amount'] ?? 0), 2);
        $unpaid = round(max(0, $total - $paid), 2);
        $type_id = (int) ($row['document_type_id'] ?? 0);
        $label = trim((string) ($row['document_type_label'] ?? ''));
        if ($label === '') {
            $label = $this->type_label($type_id);
        }
        $rut = trim((string) ($row['receiver_rut'] ?? ''));
        $name = trim((string) ($row['receiver_legal_name'] ?? ''));
        $receiver_display = $name !== '' ? $name : ($rut !== '' ? $rut : 'Registros internos o con boletas');
        $sale_state = trim((string) ($row['draft_sale_state'] ?? ''));
        if ($sale_state === '') {
            $sale_state = 'VENTA: Concretada';
        }
        return [
            'kind' => 'issued',
            'source' => 'issued',
            'id' => (int) $row['id'],
            'draft_id' => !empty($row['linked_draft_id']) ? (int) $row['linked_draft_id'] : null,
            'dte_id' => (int) $row['id'],
            'facto_document_id' => !empty($row['facto_document_id']) ? (int) $row['facto_document_id'] : null,
            'document_type_id' => $type_id,
            'document_type_label' => $label,
            'group_key' => $type_id . '_issued',
            'group_label' => $label . (stripos($label, 'emitida') !== false || stripos($label, 'emitido') !== false ? '' : ' emitida'),
            'folio' => (string) ($row['folio'] ?? ''),
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'receiver_rut' => $rut,
            'receiver_legal_name' => $name,
            'receiver_display' => $receiver_display,
            'net_amount' => round((float) ($row['net_amount'] ?? 0), 2),
            'taxes_amount' => round((float) ($row['taxes_amount'] ?? 0), 2),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'unpaid_amount' => $unpaid,
            'sale_state' => $sale_state,
            'status' => 'emitted',
            'facto_status' => isset($row['facto_status']) ? (int) $row['facto_status'] : null,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'sort_id' => (int) $row['id'],
            'can_edit' => false,
            'can_pdf' => !empty($row['facto_document_id']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $payment_status
     * @return array<int, array<string, mixed>>
     */
    private function apply_payment_filter(array $rows, $payment_status) {
        $payment_status = (string) $payment_status;
        if ($payment_status === '' || $payment_status === 'all') {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $total = (float) ($row['total_amount'] ?? 0);
            $paid = (float) ($row['paid_amount'] ?? 0);
            $unpaid = (float) ($row['unpaid_amount'] ?? 0);
            if ($payment_status === 'paid' && $unpaid <= 0.009 && $total > 0) {
                $out[] = $row;
            } elseif ($payment_status === 'unpaid' && $paid <= 0.009) {
                $out[] = $row;
            } elseif ($payment_status === 'partial' && $paid > 0.009 && $unpaid > 0.009) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function group_rows(array $rows) {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['group_key'] ?? 'other');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => (string) ($row['group_label'] ?? 'Documentos'),
                    'document_type_id' => (int) ($row['document_type_id'] ?? 0),
                    'source' => (string) ($row['source'] ?? ''),
                    'rows' => [],
                ];
            }
            $groups[$key]['rows'][] = $row;
        }
        // Orden: boletas emitidas, boletas borrador, facturas, resto.
        $order = ['37_issued', '37_draft', '2_issued', '2_draft'];
        uksort($groups, static function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            if ($ia === false) {
                $ia = 100;
            }
            if ($ib === false) {
                $ib = 100;
            }
            if ($ia !== $ib) {
                return $ia - $ib;
            }
            return strcmp((string) $a, (string) $b);
        });
        return array_values($groups);
    }

    /**
     * @param int $type_id
     * @return string
     */
    private function type_label($type_id) {
        $map = [
            2 => 'Factura electrónica',
            37 => 'Boleta electrónica',
            41 => 'Boleta no afecta o exenta electrónica',
            54 => 'Guía de despacho electrónica',
            61 => 'Nota de crédito electrónica',
        ];
        return $map[(int) $type_id] ?? ('Documento tipo ' . (int) $type_id);
    }

    /**
     * @param mixed $date
     * @return string|null
     */
    private function sanitize_date($date) {
        $date = trim((string) $date);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        return $date;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function nullable_float($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }

    /**
     * @param mixed $rut
     * @return string
     */
    private function normalize_rut_filter($rut) {
        $rut = strtoupper(preg_replace('/[^0-9Kk]/', '', (string) $rut) ?: '');
        return $rut;
    }
}
