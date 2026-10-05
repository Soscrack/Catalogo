<?php
/**
 * Sincroniza un pago de Riverso con POST /payments de FACTO.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Billing_Payment_Sync {

    /**
     * @param int $payment_id
     * @return array{ok:bool,status:string,message:string,facto_payment_id?:string}
     */
    public static function sync($payment_id) {
        global $wpdb;
        $payment_id = absint($payment_id);
        if ($payment_id <= 0) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Pago inválido.'];
        }

        $table = $wpdb->prefix . 'riverso_billing_draft_payments';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $payment_id), ARRAY_A);
        if (!$row) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Pago no encontrado.'];
        }
        if (!empty($row['facto_payment_id'])) {
            return [
                'ok' => true,
                'status' => 'ok',
                'message' => 'Ya sincronizado.',
                'facto_payment_id' => (string) $row['facto_payment_id'],
            ];
        }
        if (get_option('riverso_facto_payments_sync', '1') !== '1') {
            self::update_status($payment_id, 'off', 'Sincronización FACTO desactivada.');
            return ['ok' => true, 'status' => 'off', 'message' => 'Sincronización FACTO desactivada.'];
        }

        $lock_key = 'riverso_pay_sync_' . $payment_id;
        if (get_transient($lock_key)) {
            return ['ok' => false, 'status' => 'sending', 'message' => 'Sincronización en curso.'];
        }
        set_transient($lock_key, 1, 60);

        try {
            $drafts = new Riverso_Billing_Draft_Repository();
            $dte_id = !empty($row['dte_id']) ? (int) $row['dte_id'] : 0;
            if ($dte_id <= 0 && !empty($row['draft_id'])) {
                $draft = $drafts->get((int) $row['draft_id']);
                $dte_id = (int) ($draft['dte_id'] ?? 0);
            }
            if ($dte_id <= 0) {
                self::update_status($payment_id, 'pending', 'El documento aún no tiene DTE emitido.');
                return ['ok' => false, 'status' => 'pending', 'message' => 'El documento aún no tiene DTE emitido.'];
            }

            $issued = new Riverso_Dte_Issued_Repository();
            $dte = $issued->get($dte_id);
            $facto_doc = $dte ? (int) ($dte['facto_document_id'] ?? 0) : 0;
            if ($facto_doc <= 0) {
                self::update_status($payment_id, 'pending', 'El DTE no tiene document_id de FACTO.');
                return ['ok' => false, 'status' => 'pending', 'message' => 'El DTE no tiene document_id de FACTO.'];
            }

            $cash_file = RIVERSO_POS_PLUGIN_DIR . 'sales/cash/class-cash-repository.php';
            if (!class_exists('Riverso_Cash_Repository') && file_exists($cash_file)) {
                require_once $cash_file;
            }
            $cash = class_exists('Riverso_Cash_Repository') ? new Riverso_Cash_Repository() : null;
            $caja = $cash && !empty($row['caja_id']) ? $cash->get((int) $row['caja_id']) : null;
            $cash_account_id = $caja ? trim((string) ($caja['facto_cash_account_id'] ?? '')) : '';
            if ($cash_account_id === '') {
                self::update_status($payment_id, 'error', 'Falta el ID de caja FACTO. Asígnalo en Cuentas bancarias y efectivo.');
                self::create_sync_task($payment_id, $dte, 'Falta ID de caja FACTO');
                return ['ok' => false, 'status' => 'error', 'message' => 'Falta el ID de caja FACTO.'];
            }

            if (!class_exists('Riverso_Payment_Method_Repository')) {
                require_once dirname(__FILE__) . '/class-payment-method-repository.php';
            }
            $methods = new Riverso_Payment_Method_Repository();
            $method = !empty($row['method_id']) ? $methods->get((int) $row['method_id']) : null;
            $type_id = $method ? trim((string) ($method['facto_payment_type_id'] ?? '')) : '';
            if ($type_id === '') {
                self::update_status($payment_id, 'error', 'Falta el ID de método FACTO.');
                self::create_sync_task($payment_id, $dte, 'Falta ID de método FACTO');
                return ['ok' => false, 'status' => 'error', 'message' => 'Falta el ID de método FACTO.'];
            }

            $applied = (float) ($row['amount_applied'] ?? 0);
            if ($applied <= 0) {
                $applied = max(0, (float) ($row['amount_paid'] ?? 0) - (float) ($row['change_amount'] ?? 0));
            }
            if ($applied <= 0) {
                self::update_status($payment_id, 'error', 'El monto aplicado es 0.');
                return ['ok' => false, 'status' => 'error', 'message' => 'El monto aplicado es 0.'];
            }

            $detail = trim(implode(' ', array_filter([
                (string) ($row['method'] ?? ''),
                (string) ($row['notes'] ?? ''),
                !empty($row['cheque_numero']) ? 'Cheque ' . $row['cheque_numero'] : '',
                (string) ($row['cheque_titular'] ?? ''),
                (string) ($row['cheque_banco'] ?? ''),
            ])));

            self::update_status($payment_id, 'sending', '');
            if (!class_exists('Riverso_Facto_Client')) {
                require_once RIVERSO_POS_PLUGIN_DIR . 'modules/integrations/facto/class-facto-client.php';
            }
            $client = new Riverso_Facto_Client();
            $resp = $client->create_payment([
                'payment_date' => (string) ($row['pay_date'] ?: current_time('Y-m-d')),
                'document_id' => (string) $facto_doc,
                'payment_type_id' => $type_id,
                'payment_amount' => $applied,
                'payment_details' => $detail,
                'cash_account_id' => $cash_account_id,
            ]);

            if (is_wp_error($resp)) {
                $code = $resp->get_error_code();
                $msg = $resp->get_error_message();
                $status = in_array($code, ['facto_timeout', 'http_request_failed'], true) ? 'unknown' : 'error';
                $data = $resp->get_error_data();
                if (is_array($data) && empty($data['status']) && $code === 'http_request_failed') {
                    $status = 'unknown';
                }
                self::update_status($payment_id, $status, $msg);
                self::audit($payment_id, $status, $msg, $dte);
                self::create_sync_task($payment_id, $dte, $msg);
                return ['ok' => false, 'status' => $status, 'message' => $msg];
            }

            $facto_id = '';
            if (!empty($resp['payment_id'])) {
                $facto_id = (string) $resp['payment_id'];
            } elseif (!empty($resp['id'])) {
                $facto_id = (string) $resp['id'];
            }
            if ($facto_id === '') {
                self::update_status($payment_id, 'unknown', 'FACTO no devolvió payment_id. Revisar en FACTO antes de reintentar.');
                self::audit($payment_id, 'unknown', 'Respuesta sin payment_id', $dte);
                self::create_sync_task($payment_id, $dte, 'Respuesta sin payment_id');
                return ['ok' => false, 'status' => 'unknown', 'message' => 'FACTO no devolvió payment_id.'];
            }

            $wpdb->update($table, [
                'facto_payment_id' => substr($facto_id, 0, 32),
                'facto_sync_status' => 'ok',
                'facto_sync_error' => null,
                'facto_synced_at' => current_time('mysql'),
            ], ['id' => $payment_id]);
            self::audit($payment_id, 'ok', 'Pago sincronizado ' . $facto_id, $dte);
            return [
                'ok' => true,
                'status' => 'ok',
                'message' => 'Pago sincronizado.',
                'facto_payment_id' => $facto_id,
            ];
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * @param int    $payment_id
     * @param string $status
     * @param string $error
     */
    private static function update_status($payment_id, $status, $error) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'riverso_billing_draft_payments',
            [
                'facto_sync_status' => substr($status, 0, 16),
                'facto_sync_error' => $error !== '' ? $error : null,
                'facto_synced_at' => in_array($status, ['ok', 'error', 'unknown'], true) ? current_time('mysql') : null,
            ],
            ['id' => absint($payment_id)]
        );
    }

    /**
     * @param int         $payment_id
     * @param string      $status
     * @param string      $message
     * @param array|null  $dte
     */
    private static function audit($payment_id, $status, $message, $dte) {
        if (!class_exists('Riverso_Audit_Module')) {
            return;
        }
        Riverso_Audit_Module::get_instance()->log(
            'billing.payment_facto_sync',
            'billing_payment',
            (int) $payment_id,
            [],
            [
                'status' => $status,
                'message' => $message,
                'dte_id' => $dte['id'] ?? null,
                'folio' => $dte['folio'] ?? null,
            ],
            'Sync pago FACTO ' . $status
        );
    }

    /**
     * @param int        $payment_id
     * @param array|null $dte
     * @param string     $message
     */
    private static function create_sync_task($payment_id, $dte, $message) {
        if (!function_exists('riverso_create_task')) {
            return;
        }
        $folio = $dte['folio'] ?? '';
        riverso_create_task('sincronizar_pago_facto', 'Revisar sync de pago #' . $payment_id . ($folio ? ' folio ' . $folio : ''), [
            'prioridad' => 'alta',
            'descripcion' => $message,
            'datos_extra' => [
                'payment_id' => $payment_id,
                'dte_id' => $dte['id'] ?? null,
                'folio' => $folio,
                'facto_document_id' => $dte['facto_document_id'] ?? null,
            ],
        ]);
    }
}
