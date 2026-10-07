<?php
/**
 * Repositorio de cotizaciones de venta (wpdb).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Quote_Repository {
    const RESERVATION_REF = 'customer_quote';

    private $table_quotes;
    private $table_items;
    /** @var array<string, bool>|null */
    private $item_columns = null;
    /** @var array<string, bool>|null */
    private $quote_columns = null;
    /** @var array<string, bool> */
    private $known_tables = array();

    public function __construct() {
        global $wpdb;
        $this->table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $this->table_items = $wpdb->prefix . 'riverso_customer_quote_items';
    }

    public function list_quotes(array $filters = array()) {
        global $wpdb;
        $this->ensure_sale_schema();
        $this->sync_documented_quotes();
        // Filtro por fecha de emisión (issue_date; respaldo DATE(created_at)).
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
        if (!empty($filters['quote_number'])) {
            $where[] = 'q.quote_number LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $filters['quote_number']) . '%';
        }
        if (!empty($filters['customer_name'])) {
            $where[] = 'q.customer_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $filters['customer_name']) . '%';
        }
        if (!empty($filters['created_by'])) {
            $where[] = 'q.created_by = %d';
            $params[] = (int) $filters['created_by'];
        }
        $issue_expr = 'COALESCE(q.issue_date, DATE(q.created_at))';
        if (!empty($filters['date_from'])) {
            $where[] = "{$issue_expr} >= %s";
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "{$issue_expr} <= %s";
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $order_by = isset($filters['order_by']) ? (string) $filters['order_by'] : 'date';
        $order_dir = isset($filters['order_dir']) ? strtoupper((string) $filters['order_dir']) : 'DESC';
        if ($order_dir !== 'ASC' && $order_dir !== 'DESC') {
            $order_dir = 'DESC';
        }
        $order_map = array(
            'date' => $issue_expr,
            'number' => 'q.quote_number',
            'customer' => 'q.customer_name',
            'amount' => 'COALESCE(q.net_total, q.total, 0)',
            'status' => 'q.status',
        );
        if (!isset($order_map[$order_by])) {
            $order_by = 'date';
        }
        $sql .= ' ORDER BY ' . $order_map[$order_by] . ' ' . $order_dir . ', q.id DESC';
        if ($params) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }
        if (!is_array($rows)) {
            return array();
        }
        $out = array_map(array($this, 'present_summary'), $rows);
        $ids = array();
        foreach ($out as $q) {
            $ids[] = (int) $q['id'];
        }
        $sales = $this->attach_comparisons($this->sale_summaries($ids), false);
        foreach ($out as $i => $q) {
            $sale = isset($sales[$q['id']]) ? $sales[$q['id']] : $this->empty_sale_summary();
            unset($sale['document_draft_ids']);
            $out[$i]['sale'] = $sale;
            if ($sale['has_documents']) {
                $out[$i]['is_expired'] = false;
            }
            $out[$i]['status_label'] = self::status_label_with_changes($q['status'], $q['status_label'], $sale);
        }
        return $out;
    }

    /**
     * «Facturada con cambios» cuando el documento difiere de lo cotizado.
     *
     * @param string     $status
     * @param string     $label
     * @param array|null $sale
     * @return string
     */
    public static function status_label_with_changes($status, $label, $sale) {
        if ($status === Riverso_Quote_Status::INVOICED && !empty($sale['comparison']['has_changes'])) {
            return 'Facturada con cambios';
        }
        return $label;
    }

    /**
     * Usuarios que han creado al menos una cotización (para filtro Responsable).
     *
     * @return array<int, array{id:int,name:string}>
     */
    public function list_responsables() {
        global $wpdb;
        $this->ensure_sale_schema();
        $ids = $wpdb->get_col(
            "SELECT DISTINCT created_by FROM {$this->table_quotes}
             WHERE created_by IS NOT NULL AND created_by > 0
             ORDER BY created_by ASC"
        );
        if (!is_array($ids) || !$ids) {
            return array();
        }
        $out = array();
        foreach ($ids as $raw_id) {
            $uid = (int) $raw_id;
            if ($uid <= 0) {
                continue;
            }
            $name = $this->resolve_seller_name($uid, null);
            if ($name === '') {
                $name = 'Usuario #' . $uid;
            }
            $out[] = array(
                'id' => $uid,
                'name' => $name,
            );
        }
        usort($out, static function ($a, $b) {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return $out;
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
        if ($existing !== null && !Riverso_Quote_Status::is_editable($existing['status'])) {
            throw new Riverso_Quote_Exception(
                'La cotización está ' . $existing['status_label'] . ' y no se puede editar. Vuelva a Borrador para modificarla.'
            );
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
        $issue_date = $this->normalize_issue_date(
            isset($input['issue_date']) ? $input['issue_date'] : null,
            $existing
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
                'issue_date' => $issue_date,
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
                $header['valid_until'] = date('Y-m-d', strtotime($issue_date . ' +' . $validity_days . ' days'));
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
     * Cambio manual de estado (ver Riverso_Quote_Status::allowed_targets).
     * Con documento de venta asociado el estado lo maneja la facturación.
     * Aprobar reserva stock (aunque quede negativo); salir de Aprobada lo libera.
     */
    public function transition($id, $to) {
        $quote = $this->find((int) $id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        $to = strtolower(trim((string) $to));
        if (!Riverso_Quote_Status::can_transition((string) $quote['status'], $to)) {
            throw new Riverso_Quote_Exception(
                'No se puede pasar de ' . $quote['status_label'] . ' a ' . Riverso_Quote_Status::label($to) . '.'
            );
        }
        if ($quote['status'] !== $to && $this->has_associated_document((int) $id)) {
            throw new Riverso_Quote_Exception('La cotización tiene un documento de venta asociado; su estado lo define la facturación.');
        }
        if ($to === Riverso_Quote_Status::LISTED && empty($quote['lines'])) {
            throw new Riverso_Quote_Exception('Agregue al menos un producto antes de aprobar.');
        }
        if ($quote['status'] !== $to) {
            $this->apply_status($quote, $to);
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
            throw new Riverso_Quote_Exception('Solo una cotización Aprobada se puede facturar.');
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

        $this->release_stock($id);
        $updated = $this->find($id);
        if ($updated === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada tras facturar.');
        }
        return $updated;
    }

    /**
     * Compatibilidad: antes la facturación dejaba la cotización en Lista/Aprobada.
     *
     * @param int $id
     */
    public function mark_listed($id) {
        $this->sync_document_status($id);
    }

    /**
     * Ajusta el estado según los documentos de venta asociados:
     * - documento emitido o boleta cerrada → Facturada (libera la reserva de stock);
     * - solo borrador de documento y la cotización en Borrador → Aprobada (reserva stock).
     * Rechazada/Anulada sin venta no se tocan.
     *
     * @param int $id
     * @return bool true si cambió el estado.
     */
    public function sync_document_status($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        $quote = $this->find($id);
        if ($quote === null) {
            return false;
        }
        $summaries = $this->sale_summaries(array($id));
        $sale = isset($summaries[$id]) ? $summaries[$id] : $this->empty_sale_summary();
        $target = null;
        if ($sale['sold']) {
            $target = Riverso_Quote_Status::INVOICED;
        } elseif ($sale['has_documents'] && $quote['status'] === Riverso_Quote_Status::DRAFT) {
            $target = Riverso_Quote_Status::LISTED;
        } elseif ($quote['status'] === Riverso_Quote_Status::INVOICED && empty($quote['order_id'])) {
            // Se eliminó el documento que la facturó (p. ej. boleta cerrada sin SII): vuelve a Aprobada.
            $target = Riverso_Quote_Status::LISTED;
        }
        if ($target === null || $target === $quote['status']) {
            return false;
        }
        $this->apply_status($quote, $target);
        return true;
    }

    /**
     * Facturar implica que el cliente aceptó: un Borrador con productos pasa a Aprobada
     * al cargarse en Facturación. Si luego no se emite, sigue Aprobada (con reserva).
     *
     * @param int $id
     * @return bool true si cambió el estado.
     */
    public function approve_for_billing($id) {
        $quote = $this->find((int) $id);
        if ($quote === null || $quote['status'] !== Riverso_Quote_Status::DRAFT || empty($quote['lines'])) {
            return false;
        }
        $this->apply_status($quote, Riverso_Quote_Status::LISTED);
        return true;
    }

    /**
     * Escribe el estado y ajusta reservas: Aprobada reserva, cualquier otro estado libera.
     *
     * @param array  $quote Cotización presentada (con líneas).
     * @param string $status
     */
    private function apply_status(array $quote, $status) {
        global $wpdb;
        $id = (int) $quote['id'];
        $wpdb->update(
            $this->table_quotes,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        if ($status === Riverso_Quote_Status::LISTED) {
            $this->reserve_stock($quote);
        } else {
            $this->release_stock($id);
        }
    }

    /**
     * Red de seguridad al listar: corrige estados de cotizaciones con documentos
     * (p. ej. emitidas antes de existir el estado Facturada).
     */
    public function sync_documented_quotes() {
        global $wpdb;
        $drafts = $wpdb->prefix . 'riverso_billing_drafts';
        $issued = $wpdb->prefix . 'riverso_dte_issued';
        $sold = array();
        $any = array();
        if ($this->table_exists($issued)) {
            $exists_dte = "EXISTS (SELECT 1 FROM {$issued} i WHERE i.quote_id = q.id
                AND i.facto_document_id IS NOT NULL AND (i.facto_status IS NULL OR i.facto_status IN (0, 2)))";
            $sold[] = $exists_dte;
            $any[] = $exists_dte;
        }
        if ($this->table_exists($drafts)) {
            $sold[] = "EXISTS (SELECT 1 FROM {$drafts} d WHERE d.quote_id = q.id AND d.status IN ('closed_local', 'emitted'))";
            $any[] = "EXISTS (SELECT 1 FROM {$drafts} d2 WHERE d2.quote_id = q.id)";
        }
        if (!$any) {
            return;
        }
        // Solo candidatas a cambiar: vendidas sin Facturada, o borradores con documento.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT q.id FROM {$this->table_quotes} q
             WHERE (q.status <> %s AND (" . implode(' OR ', $sold ?: array('0')) . "))
                OR (q.status = %s AND (" . implode(' OR ', $any) . '))
             LIMIT 200',
            Riverso_Quote_Status::INVOICED,
            Riverso_Quote_Status::DRAFT
        ));
        foreach ((array) $ids as $qid) {
            $this->sync_document_status((int) $qid);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function empty_sale_summary() {
        return array(
            'has_documents' => false,
            'sold' => false,
            'total' => 0.0,
            'paid' => 0.0,
            'status' => 'none',
            'status_label' => 'Sin documento',
            'folios' => array(),
            // Borradores de Facturación que respaldan la venta (para comparar líneas).
            'document_draft_ids' => array(),
        );
    }

    /**
     * Estado de venta derivado de borradores, DTE emitidos y pagos de facturación.
     * none: sin documento · billing: borrador en facturación · unpaid: vendida sin pago ·
     * partial: pago parcial · paid: pagada.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function sale_summaries(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = array();
        foreach ($ids as $id) {
            $out[$id] = $this->empty_sale_summary();
        }
        if (!$ids) {
            return $out;
        }
        $drafts_t = $wpdb->prefix . 'riverso_billing_drafts';
        $issued_t = $wpdb->prefix . 'riverso_dte_issued';
        $payments_t = $wpdb->prefix . 'riverso_billing_draft_payments';
        $in = implode(',', $ids);

        $issued = $this->table_exists($issued_t) ? $wpdb->get_results(
            "SELECT id, quote_id, document_type_id, folio, total_amount FROM {$issued_t}
             WHERE quote_id IN ({$in})
               AND facto_document_id IS NOT NULL
               AND (facto_status IS NULL OR facto_status IN (0, 2))",
            ARRAY_A
        ) : array();
        $drafts = $this->table_exists($drafts_t) ? $wpdb->get_results(
            "SELECT * FROM {$drafts_t} WHERE quote_id IN ({$in})",
            ARRAY_A
        ) : array();

        $sold_dte = array();     // dte_id => quote_id
        $sold_draft = array();   // draft_id => quote_id
        foreach ((array) $issued as $row) {
            $qid = (int) $row['quote_id'];
            if (!isset($out[$qid])) {
                continue;
            }
            $sold_dte[(int) $row['id']] = $qid;
            $out[$qid]['has_documents'] = true;
            $out[$qid]['sold'] = true;
            $out[$qid]['total'] += (float) $row['total_amount'];
            $out[$qid]['folios'][] = $this->short_doc_label((int) $row['document_type_id'], (string) $row['folio']);
        }
        foreach ((array) $drafts as $row) {
            $qid = (int) $row['quote_id'];
            if (!isset($out[$qid])) {
                continue;
            }
            $out[$qid]['has_documents'] = true;
            $dte_id = !empty($row['dte_id']) ? (int) $row['dte_id'] : 0;
            if ($dte_id > 0 && isset($sold_dte[$dte_id])) {
                $sold_draft[(int) $row['id']] = $qid;
                $out[$qid]['document_draft_ids'][] = (int) $row['id'];
            } elseif (in_array((string) $row['status'], array('closed_local', 'emitted'), true)) {
                // Boleta cerrada sin SII, o emitido cuyo DTE no quedó ligado a la cotización.
                $sold_draft[(int) $row['id']] = $qid;
                $out[$qid]['document_draft_ids'][] = (int) $row['id'];
                if ($dte_id > 0) {
                    $sold_dte[$dte_id] = $qid;
                }
                $out[$qid]['sold'] = true;
                $out[$qid]['total'] += (float) $row['total_amount'];
                $out[$qid]['folios'][] = $this->short_doc_label((int) $row['document_type_id'], '')
                    . ((string) $row['status'] === 'closed_local' ? ' cerrada' : ' emitida');
            }
        }

        if (($sold_dte || $sold_draft) && $this->table_exists($payments_t)) {
            $conds = array();
            if ($sold_draft) {
                $conds[] = 'draft_id IN (' . implode(',', array_keys($sold_draft)) . ')';
            }
            if ($sold_dte) {
                $conds[] = 'dte_id IN (' . implode(',', array_keys($sold_dte)) . ')';
            }
            $payments = $wpdb->get_results(
                "SELECT id, draft_id, dte_id,
                        CASE WHEN amount_applied > 0 THEN amount_applied
                             ELSE GREATEST(0, amount_paid - change_amount) END AS applied
                 FROM {$payments_t} WHERE " . implode(' OR ', $conds),
                ARRAY_A
            );
            foreach ((array) $payments as $p) {
                $did = (int) $p['draft_id'];
                $tid = (int) $p['dte_id'];
                $qid = isset($sold_draft[$did]) ? $sold_draft[$did] : (isset($sold_dte[$tid]) ? $sold_dte[$tid] : 0);
                if ($qid > 0) {
                    $out[$qid]['paid'] += (float) $p['applied'];
                }
            }
        }

        foreach ($out as $qid => $sale) {
            $sale['total'] = round($sale['total'], 2);
            $sale['paid'] = round($sale['paid'], 2);
            if (!$sale['has_documents']) {
                $sale['status'] = 'none';
                $sale['status_label'] = 'Sin documento';
            } elseif (!$sale['sold']) {
                $sale['status'] = 'billing';
                $sale['status_label'] = 'En facturación';
            } elseif ($sale['paid'] <= 0.009) {
                $sale['status'] = 'unpaid';
                $sale['status_label'] = 'Por cobrar';
            } elseif ($sale['total'] - $sale['paid'] > 0.009) {
                $sale['status'] = 'partial';
                $sale['status_label'] = 'Pago parcial';
            } else {
                $sale['status'] = 'paid';
                $sale['status_label'] = 'Pagada';
            }
            $out[$qid] = $sale;
        }
        return $out;
    }

    /**
     * Agrega a cada resumen de venta la comparación cotizado vs. documento emitido/cerrado.
     * La cotización no se modifica: queda como lo ofrecido y el documento manda.
     *
     * @param array<int, array<string, mixed>> $sales quote_id => resumen (sale_summaries)
     * @param bool $with_lines false: solo indicadores (listado)
     * @return array<int, array<string, mixed>>
     */
    public function attach_comparisons(array $sales, $with_lines = true) {
        global $wpdb;
        $draft_to_quote = array();
        foreach ($sales as $qid => $sale) {
            $sales[$qid]['comparison'] = null;
            if (empty($sale['sold'])) {
                continue;
            }
            foreach ((array) $sale['document_draft_ids'] as $did) {
                $draft_to_quote[(int) $did] = (int) $qid;
            }
            $sales[$qid]['comparison'] = array(
                'available' => false,
                'has_changes' => false,
                'quote_total' => 0.0,
                'document_total' => (float) $sale['total'],
                'added' => 0,
                'removed' => 0,
                'changed' => 0,
                'lines' => array(),
            );
        }
        $lines_t = $wpdb->prefix . 'riverso_billing_draft_lines';
        if (!$draft_to_quote || !$this->table_exists($lines_t)) {
            return $sales;
        }
        $doc_rows = $wpdb->get_results(
            "SELECT draft_id, sku, description, quantity, line_total_bruto, product_id, producto_base_id
             FROM {$lines_t} WHERE draft_id IN (" . implode(',', array_keys($draft_to_quote)) . ')
             ORDER BY draft_id ASC, position ASC, id ASC',
            ARRAY_A
        );
        $doc = array();
        foreach ((array) $doc_rows as $row) {
            $qid = $draft_to_quote[(int) $row['draft_id']];
            $doc[$qid][] = array(
                'producto_base_id' => (int) $row['producto_base_id'],
                'product_id' => (int) $row['product_id'],
                'sku' => (string) $row['sku'],
                'description' => (string) $row['description'],
                'quantity' => (float) $row['quantity'],
                'total' => (float) $row['line_total_bruto'],
            );
        }
        $quote_ids = array_keys($doc);
        if (!$quote_ids) {
            return $sales;
        }
        $item_rows = $wpdb->get_results(
            "SELECT * FROM {$this->table_items} WHERE quote_id IN (" . implode(',', array_map('intval', $quote_ids)) . ')
             ORDER BY quote_id ASC, sort_order ASC, id ASC',
            ARRAY_A
        );
        $offered = array();
        foreach ((array) $item_rows as $row) {
            $line = $this->present_line($row);
            $offered[(int) $row['quote_id']][] = array(
                'producto_base_id' => (int) $line['producto_base_id'],
                'product_id' => (int) $line['product_id'],
                'sku' => $line['sku'],
                'description' => $line['description'],
                'quantity' => (float) $line['quantity'],
                'total' => (float) $line['line_net'],
            );
        }
        $headers = $wpdb->get_results(
            "SELECT id, COALESCE(net_total, total, 0) AS quote_total FROM {$this->table_quotes}
             WHERE id IN (" . implode(',', array_map('intval', $quote_ids)) . ')',
            ARRAY_A
        );
        $quote_totals = array();
        foreach ((array) $headers as $h) {
            $quote_totals[(int) $h['id']] = (float) $h['quote_total'];
        }
        foreach ($quote_ids as $qid) {
            $cmp = $this->compare_lines(
                isset($offered[$qid]) ? $offered[$qid] : array(),
                $doc[$qid]
            );
            $quote_total = isset($quote_totals[$qid]) ? round($quote_totals[$qid], 2) : 0.0;
            $document_total = (float) $sales[$qid]['total'];
            // Tolerancia de redondeo: los documentos se emiten en pesos enteros.
            $tolerance = max(2.0, (float) count($doc[$qid]));
            $total_differs = abs($quote_total - $document_total) > $tolerance;
            $sales[$qid]['comparison'] = array(
                'available' => true,
                'has_changes' => $cmp['lines'] || $total_differs,
                'quote_total' => $quote_total,
                'document_total' => $document_total,
                'added' => $cmp['added'],
                'removed' => $cmp['removed'],
                'changed' => $cmp['changed'],
                'lines' => $with_lines ? $cmp['lines'] : array(),
            );
        }
        return $sales;
    }

    /**
     * @param array $offered Líneas cotizadas normalizadas.
     * @param array $billed  Líneas del documento normalizadas.
     * @return array{lines: array, added: int, removed: int, changed: int}
     */
    private function compare_lines(array $offered, array $billed) {
        $a = $this->aggregate_compare_lines($offered);
        $b = $this->aggregate_compare_lines($billed);
        $out = array('lines' => array(), 'added' => 0, 'removed' => 0, 'changed' => 0);
        foreach ($a as $key => $q) {
            if (!isset($b[$key])) {
                $out['removed']++;
                $out['lines'][] = $this->compare_entry('removed', $q, null);
                continue;
            }
            $d = $b[$key];
            if (abs($q['quantity'] - $d['quantity']) > 0.0005 || abs($q['total'] - $d['total']) > 1.0) {
                $out['changed']++;
                $out['lines'][] = $this->compare_entry('changed', $q, $d);
            }
        }
        foreach ($b as $key => $d) {
            if (!isset($a[$key])) {
                $out['added']++;
                $out['lines'][] = $this->compare_entry('added', null, $d);
            }
        }
        return $out;
    }

    /**
     * Agrupa por producto (producto_base, producto WC, SKU o descripción) sumando cantidad y total.
     *
     * @param array $lines
     * @return array<string, array>
     */
    private function aggregate_compare_lines(array $lines) {
        $out = array();
        foreach ($lines as $line) {
            if ($line['producto_base_id'] > 0) {
                $key = 'pb:' . $line['producto_base_id'];
            } elseif ($line['product_id'] > 0) {
                $key = 'wc:' . $line['product_id'];
            } elseif (trim($line['sku']) !== '') {
                $key = 'sku:' . strtolower(trim($line['sku']));
            } else {
                $key = 'tx:' . strtolower(trim(preg_replace('/\s+/', ' ', $line['description'])));
            }
            if (!isset($out[$key])) {
                $out[$key] = array(
                    'sku' => $line['sku'],
                    'description' => $line['description'],
                    'quantity' => 0.0,
                    'total' => 0.0,
                );
            }
            $out[$key]['quantity'] += $line['quantity'];
            $out[$key]['total'] += $line['total'];
        }
        return $out;
    }

    /**
     * @param string     $type added|removed|changed
     * @param array|null $quoted
     * @param array|null $billed
     * @return array<string, mixed>
     */
    private function compare_entry($type, $quoted, $billed) {
        $ref = $billed !== null ? $billed : $quoted;
        return array(
            'type' => $type,
            'sku' => (string) $ref['sku'],
            'description' => (string) ($quoted !== null && $quoted['description'] !== '' ? $quoted['description'] : $ref['description']),
            'quote_quantity' => $quoted !== null ? round($quoted['quantity'], 3) : null,
            'quote_total' => $quoted !== null ? round($quoted['total'], 2) : null,
            'document_quantity' => $billed !== null ? round($billed['quantity'], 3) : null,
            'document_total' => $billed !== null ? round($billed['total'], 2) : null,
        );
    }

    /**
     * @param int    $type_id
     * @param string $folio
     * @return string
     */
    private function short_doc_label($type_id, $folio) {
        if ($type_id === 2 || $type_id === 33) {
            $label = 'Factura';
        } elseif ($type_id === 37 || $type_id === 39) {
            $label = 'Boleta';
        } else {
            $label = 'Doc.';
        }
        $folio = trim((string) $folio);
        return $folio !== '' ? $label . ' N°' . $folio : $label;
    }

    /**
     * Reserva stock de las líneas con producto (cantidad en unidades de la presentación cotizada).
     * Permite dejar el disponible en negativo. Vence con la validez de la cotización, si la tiene.
     *
     * @param array $quote Cotización presentada (con líneas).
     */
    public function reserve_stock(array $quote) {
        if (!class_exists('Riverso_Reservation_Service') || empty($quote['id'])) {
            return;
        }
        $quote_id = (int) $quote['id'];
        $this->release_stock($quote_id);
        $expires_at = null;
        $days = isset($quote['validity_days']) ? (int) $quote['validity_days'] : 0;
        if ($days > 0 && !empty($quote['issue_date'])) {
            $until = strtotime($quote['issue_date'] . ' +' . $days . ' days');
            if ($until !== false) {
                $expires_at = date('Y-m-d', $until) . ' 23:59:59';
            }
        }
        $service = Riverso_Reservation_Service::get_instance();
        foreach ((array) (isset($quote['lines']) ? $quote['lines'] : array()) as $line) {
            $qty = isset($line['quantity']) ? (float) $line['quantity'] : 0.0;
            $base_id = $this->line_producto_base_id($line);
            if ($qty <= 0 || $base_id <= 0) {
                continue;
            }
            $service->reserve($base_id, $qty, array(
                'origen' => 'cotizacion',
                'referencia_tipo' => self::RESERVATION_REF,
                'referencia_id' => $quote_id,
                'expires_at' => $expires_at,
                'allow_negative' => true,
            ));
        }
    }

    /**
     * Libera las reservas activas de la cotización.
     *
     * @param int $quote_id
     */
    public function release_stock($quote_id) {
        global $wpdb;
        $quote_id = (int) $quote_id;
        $table = $wpdb->prefix . 'riverso_reservas';
        if ($quote_id <= 0 || !$this->table_exists($table)) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET estado = 'liberada', released_at = %s
             WHERE referencia_tipo = %s AND referencia_id = %d AND estado = 'activa'",
            current_time('mysql'),
            self::RESERVATION_REF,
            $quote_id
        ));
    }

    /**
     * producto_base de la línea; si falta, se resuelve por el producto WC (mismo criterio que el stock).
     *
     * @param array $line
     * @return int
     */
    private function line_producto_base_id(array $line) {
        $base_id = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
        if ($base_id > 0) {
            return $base_id;
        }
        $wc_id = isset($line['product_id']) ? (int) $line['product_id'] : 0;
        if ($wc_id <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_producto_base';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE deleted_at IS NULL AND (woocommerce_variation_id = %d OR woocommerce_product_id = %d)
             ORDER BY (woocommerce_variation_id = %d) DESC, id ASC LIMIT 1",
            $wc_id,
            $wc_id,
            $wc_id
        ));
    }

    /**
     * @param int $id
     * @return bool
     */
    public function has_associated_document($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        $drafts = $wpdb->prefix . 'riverso_billing_drafts';
        $issued = $wpdb->prefix . 'riverso_dte_issued';
        if ($this->table_exists($drafts)) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$drafts} WHERE quote_id = %d LIMIT 1",
                $id
            ));
            if ($found > 0) {
                return true;
            }
        }
        if ($this->table_exists($issued)) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$issued} WHERE quote_id = %d LIMIT 1",
                $id
            ));
            if ($found > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Borra la cotización y sus líneas. Rechaza si hay documento asociado.
     *
     * @param int $id
     */
    public function delete_quote($id) {
        global $wpdb;
        $id = (int) $id;
        $quote = $this->find($id);
        if ($quote === null) {
            throw new Riverso_Quote_Exception('Cotización no encontrada.');
        }
        if ($this->has_associated_document($id)) {
            throw new Riverso_Quote_Exception('No se puede borrar: la cotización tiene un documento asociado.');
        }
        $deleted_items = $wpdb->delete($this->table_items, array('quote_id' => $id), array('%d'));
        if ($deleted_items === false) {
            throw new Riverso_Quote_Exception('No se pudieron borrar las líneas de la cotización.' . $this->db_error_suffix());
        }
        $deleted = $wpdb->delete($this->table_quotes, array('id' => $id), array('%d'));
        if (!$deleted) {
            throw new Riverso_Quote_Exception('No se pudo borrar la cotización.' . $this->db_error_suffix());
        }
        $this->release_stock($id);
    }

    /**
     * Borra la cotización solo si es un borrador sin líneas ni documentos asociados.
     * La condición va en el mismo DELETE para no borrar algo que se llenó entre medio.
     *
     * @param int $id
     * @return bool true si se borró.
     */
    public function discard_if_empty($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0 || $this->has_associated_document($id)) {
            return false;
        }
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_quotes}
             WHERE id = %d
               AND (status IS NULL OR status IN ('', %s, 'borrador'))
               AND NOT EXISTS (SELECT 1 FROM {$this->table_items} i WHERE i.quote_id = %d)",
            $id,
            Riverso_Quote_Status::DRAFT,
            $id
        ));
        return is_int($deleted) && $deleted > 0;
    }

    /**
     * Respaldo del descarte desde el navegador (beacon perdido, pestaña cerrada a la fuerza):
     * borra borradores vacíos sin cambios hace más de $max_age_hours.
     *
     * @param int $max_age_hours
     * @return int Cotizaciones borradas.
     */
    public function sweep_empty_drafts($max_age_hours = 12) {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', (int) current_time('timestamp') - max(1, (int) $max_age_hours) * HOUR_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT q.id FROM {$this->table_quotes} q
             WHERE (q.status IS NULL OR q.status IN ('', %s, 'borrador'))
               AND COALESCE(q.updated_at, q.created_at) < %s
               AND NOT EXISTS (SELECT 1 FROM {$this->table_items} i WHERE i.quote_id = q.id)
             LIMIT 50",
            Riverso_Quote_Status::DRAFT,
            $cutoff
        ));
        $count = 0;
        foreach ((array) $ids as $id) {
            if ($this->discard_if_empty((int) $id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param string $table
     * @return bool
     */
    private function table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if ($table === '') {
            return false;
        }
        if (!isset($this->known_tables)) {
            $this->known_tables = array();
        }
        if (array_key_exists($table, $this->known_tables)) {
            return $this->known_tables[$table];
        }
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        $this->known_tables[$table] = is_string($found) && $found === $table;
        return $this->known_tables[$table];
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
                'price_mode' => isset($line['price_mode']) ? $line['price_mode'] : null,
                'price_ref' => isset($line['price_ref']) ? $line['price_ref'] : null,
                'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
                'stock_breakdown' => isset($line['stock_breakdown']) ? $line['stock_breakdown'] : null,
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
                if ($desc === '') {
                    throw new Riverso_Quote_Exception('Cada línea necesita un SKU o una descripción.');
                }
                $row['sku'] = '';
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
        $description = $this->clip(trim((string) (isset($line['description']) ? $line['description'] : '')), 500);
        if ($sku === '' && $description === '') {
            throw new Riverso_Quote_Exception('Cada línea necesita un SKU o una descripción.');
        }
        $quantity = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
        if ($quantity <= 0) {
            throw new Riverso_Quote_Exception('La cantidad debe ser mayor a cero.');
        }
        $rule_adjusted = !empty($line['rule_adjusted']);
        $price_raw = (float) (isset($line['unit_price']) ? $line['unit_price'] : 0);
        $price = $rule_adjusted ? round($price_raw, 4) : round($price_raw, 2);
        if ($price < 0) {
            throw new Riverso_Quote_Exception('El precio no puede ser negativo.');
        }
        $product_id = isset($line['product_id']) ? $line['product_id'] : null;
        $product_id = ($product_id === '' || $product_id === null) ? null : (int) $product_id;
        if ($product_id !== null && $product_id <= 0) {
            $product_id = null;
        }
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
        $grupo_id = 0;
        if (isset($line['grupo_id']) && $line['grupo_id'] !== null && $line['grupo_id'] !== '') {
            $grupo_id = (int) $line['grupo_id'];
        } elseif (isset($line['_family']) && is_array($line['_family']) && !empty($line['_family']['grupo_id'])) {
            $grupo_id = (int) $line['_family']['grupo_id'];
        }
        $rule_total = null;
        if ($rule_adjusted && isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
            $rule_total = round((float) $line['rule_total'], 2);
        } else {
            $rule_adjusted = false;
        }
        $price_mode = isset($line['price_mode']) ? strtolower(trim((string) $line['price_mode'])) : '';
        if (!in_array($price_mode, array('auto', 'manual', 'ref', 'std'), true)) {
            $price_mode = 'auto';
        }
        $price_ref = null;
        if ($price_mode === 'ref' && isset($line['price_ref']) && $line['price_ref'] !== null && $line['price_ref'] !== '') {
            $price_ref = round((float) $line['price_ref'], 4);
            if ($price_ref < 0) {
                $price_ref = null;
                $price_mode = 'auto';
            }
        }
        $price_total = null;
        if ($price_mode === 'manual' && isset($line['price_total']) && $line['price_total'] !== null && $line['price_total'] !== '') {
            $price_total = round((float) $line['price_total'], 2);
            if ($price_total < 0) {
                $price_total = null;
            }
        }
        if ($price_mode === 'auto' || $price_mode === 'std') {
            $price_ref = null;
            $price_total = null;
        } elseif ($price_mode === 'manual') {
            $price_ref = null;
        } elseif ($price_mode === 'ref') {
            $price_total = null;
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
            'grupo_id' => $grupo_id > 0 ? $grupo_id : null,
            'rule_total' => $rule_total,
            'rule_adjusted' => $rule_adjusted,
            'price_mode' => ($price_mode === 'auto') ? null : $price_mode,
            'price_ref' => $price_ref,
            'price_total' => $price_total,
            // Detalle de entrega (bolsas leídas / sueltas) para la salida de stock al facturar.
            'stock_breakdown' => class_exists('Riverso_Sale_Stock_Service')
                ? Riverso_Sale_Stock_Service::breakdown_json(isset($line['stock_breakdown']) ? $line['stock_breakdown'] : null, $quantity)
                : null,
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

    /**
     * Normaliza issue_date YYYY-MM-DD.
     * Si el input no trae fecha válida: conserva la existente; en alta usa hoy (zona WP).
     *
     * @param mixed      $value
     * @param array|null $existing
     * @return string
     */
    private function normalize_issue_date($value, $existing = null) {
        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m)) {
            return $m[1];
        }
        if (is_array($existing)) {
            if (!empty($existing['issue_date']) && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $existing['issue_date'], $m2)) {
                return $m2[1];
            }
            if (!empty($existing['created_at']) && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $existing['created_at'], $m3)) {
                return $m3[1];
            }
        }
        return function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
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
        // validity_days NULL = sin validez. Solo filas sin esa columna usan valid_days legado
        // (que tiene DEFAULT 3 y no refleja lo que el usuario dejó vacío).
        if (array_key_exists('validity_days', $row)) {
            $validity = $row['validity_days'] !== null && $row['validity_days'] !== '' ? (int) $row['validity_days'] : null;
        } else {
            $validity = isset($row['valid_days']) && $row['valid_days'] !== null && $row['valid_days'] !== '' ? (int) $row['valid_days'] : null;
        }
        $transitions = array();
        foreach ($targets as $target) {
            $transitions[] = array(
                'status' => $target,
                'label' => Riverso_Quote_Status::transition_label($target, $status),
            );
        }
        $created_at = (string) (isset($row['created_at']) ? $row['created_at'] : '');
        $issue_date = '';
        if (!empty($row['issue_date']) && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $row['issue_date'], $m_issue)) {
            $issue_date = $m_issue[1];
        } elseif ($created_at !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $created_at, $m_created)) {
            $issue_date = $m_created[1];
        }
        $seller_name = $this->resolve_seller_name(
            isset($row['created_by']) ? $row['created_by'] : null,
            isset($row['seller_name']) ? $row['seller_name'] : null
        );
        // Vencida solo aplica a cotizaciones abiertas (Borrador/Aprobada).
        $is_expired = in_array($status, array(Riverso_Quote_Status::DRAFT, Riverso_Quote_Status::LISTED), true)
            && $this->is_expired($issue_date, $validity, isset($row['valid_until']) ? $row['valid_until'] : null);
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
                    'units_per_pack' => isset($pl['units_per_pack']) ? $pl['units_per_pack'] : null,
                );
            }
            $recalc = Riverso_Quote_Totals::calculate($recalc_input);
            // Conservar net_total del header (incluye T_final prorrateado al guardar).
            // Solo refrescar utilidad/margen cuando el recalc los conoce.
            if ($recalc['margin_percent'] !== null) {
                $margin_percent = $recalc['margin_percent'];
            }
            if ($recalc['profit_total'] !== null) {
                $profit_total = $recalc['profit_total'];
            }
            if ($recalc['discount_total'] !== null) {
                $discount_total = (float) $recalc['discount_total'];
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
            'editable' => Riverso_Quote_Status::is_editable($status),
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
        // Sin validez o 0 días = sin vencimiento.
        if ($days <= 0) {
            return false;
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
            'units_per_pack' => isset($line['units_per_pack']) ? $line['units_per_pack'] : null,
            'price_mode' => isset($line['price_mode']) ? $line['price_mode'] : null,
            'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
        );
        $calculated = Riverso_Quote_Totals::calculate(array($for_calc));
        $normalized = $calculated['lines'][0];
        $stored_total = null;
        if (isset($line['line_total']) && $line['line_total'] !== null && $line['line_total'] !== '') {
            $stored_total = (float) $line['line_total'];
        } elseif (isset($line['total']) && $line['total'] !== null && $line['total'] !== '') {
            $stored_total = (float) $line['total'];
        }
        $line_net = $stored_total !== null ? round($stored_total, 2) : (float) $normalized['line_net'];
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
            'line_net' => $line_net,
            'line_profit' => $normalized['line_profit'],
            'line_margin_percent' => $normalized['line_margin_percent'],
            'family_mode' => isset($line['family_mode']) && $line['family_mode'] !== '' && $line['family_mode'] !== null
                ? (string) $line['family_mode'] : null,
            'packaging' => isset($line['packaging']) && $line['packaging'] !== '' && $line['packaging'] !== null
                ? (string) $line['packaging'] : null,
            'units_per_pack' => $this->nullable_float(isset($line['units_per_pack']) ? $line['units_per_pack'] : null),
            'price_mode' => $this->normalize_price_mode_present(isset($line['price_mode']) ? $line['price_mode'] : null),
            'price_ref' => $this->nullable_float4(isset($line['price_ref']) ? $line['price_ref'] : null),
            'price_total' => $this->nullable_float(isset($line['price_total']) ? $line['price_total'] : null),
            'local_only' => empty($line['product_id']),
            'stock_breakdown' => !empty($line['stock_breakdown']) ? json_decode((string) $line['stock_breakdown'], true) : null,
        );
    }

    private function normalize_price_mode_present($value) {
        $mode = strtolower(trim((string) $value));
        if (in_array($mode, array('manual', 'ref', 'std'), true)) {
            return $mode;
        }
        return 'auto';
    }

    private function nullable_float4($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 4);
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
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_price_mode')) {
            Riverso_POS_Activator::ensure_customer_quotes_price_mode();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_issue_date')) {
            Riverso_POS_Activator::ensure_customer_quotes_issue_date();
        }
        // Invalidar cache de columnas: phase57/58/59/60/62 pudieron agregar campos.
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
