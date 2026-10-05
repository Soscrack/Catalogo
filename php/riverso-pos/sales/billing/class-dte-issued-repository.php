<?php
/**
 * Persistencia de DTE emitidos vía FACTO.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Dte_Issued_Repository {

    /** @var string */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'riverso_dte_issued';
    }

    /**
     * @param int $quote_id
     * @return array|null
     */
    public function find_success_by_quote($quote_id) {
        global $wpdb;
        $quote_id = absint($quote_id);
        if ($quote_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE quote_id = %d
                   AND facto_document_id IS NOT NULL
                   AND (facto_status IS NULL OR facto_status IN (0, 2))
                 ORDER BY id DESC
                 LIMIT 1",
                $quote_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $this->present($row) : null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get($id) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return is_array($row) ? $this->present($row) : null;
    }

    /**
     * @param array $data
     * @return int|WP_Error
     */
    public function insert(array $data) {
        global $wpdb;
        $row = [
            'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
            'customer_id' => isset($data['customer_id']) ? absint($data['customer_id']) : null,
            'document_type_id' => absint($data['document_type_id'] ?? 0),
            'document_type_label' => substr((string) ($data['document_type_label'] ?? ''), 0, 64),
            'facto_document_id' => isset($data['facto_document_id']) ? absint($data['facto_document_id']) : null,
            'folio' => isset($data['folio']) ? substr((string) $data['folio'], 0, 32) : null,
            'issue_date' => isset($data['issue_date']) ? (string) $data['issue_date'] : null,
            'payment_conditions' => substr((string) ($data['payment_conditions'] ?? '0'), 0, 32),
            'receiver_rut' => isset($data['receiver_rut']) ? substr((string) $data['receiver_rut'], 0, 20) : null,
            'receiver_legal_name' => isset($data['receiver_legal_name']) ? substr((string) $data['receiver_legal_name'], 0, 255) : null,
            'net_amount' => round((float) ($data['net_amount'] ?? 0), 2),
            'taxes_amount' => round((float) ($data['taxes_amount'] ?? 0), 2),
            'total_amount' => round((float) ($data['total_amount'] ?? 0), 2),
            'facto_status' => array_key_exists('facto_status', $data) ? (int) $data['facto_status'] : null,
            'facto_error' => isset($data['facto_error']) ? (string) $data['facto_error'] : null,
            'response_json' => isset($data['response_json']) ? (string) $data['response_json'] : null,
            'created_by' => get_current_user_id() ?: null,
        ];
        if (empty($row['quote_id'])) {
            $row['quote_id'] = null;
        }
        if (empty($row['customer_id'])) {
            $row['customer_id'] = null;
        }
        if (empty($row['facto_document_id'])) {
            $row['facto_document_id'] = null;
        }
        $ok = $wpdb->insert($this->table, $row);
        if (!$ok) {
            return new WP_Error('dte_insert_failed', 'No se pudo guardar el DTE emitido.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array $row
     * @return array
     */
    private function present(array $row) {
        return [
            'id' => (int) $row['id'],
            'quote_id' => isset($row['quote_id']) ? (int) $row['quote_id'] : null,
            'customer_id' => isset($row['customer_id']) ? (int) $row['customer_id'] : null,
            'document_type_id' => (int) $row['document_type_id'],
            'document_type_label' => (string) $row['document_type_label'],
            'facto_document_id' => isset($row['facto_document_id']) ? (int) $row['facto_document_id'] : null,
            'folio' => (string) ($row['folio'] ?? ''),
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'net_amount' => round((float) $row['net_amount'], 2),
            'taxes_amount' => round((float) $row['taxes_amount'], 2),
            'total_amount' => round((float) $row['total_amount'], 2),
            'facto_status' => isset($row['facto_status']) ? (int) $row['facto_status'] : null,
            'facto_error' => (string) ($row['facto_error'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
