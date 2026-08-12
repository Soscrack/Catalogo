<?php
/**
 * Servicio de Pagos Agrupados (Tickets de Pago)
 *
 * Responsabilidades:
 *   - Crear tickets de pago que agrupan múltiples facturas
 *   - Validar y procesar comprobantes de transferencia (imágenes)
 *   - Calcular totales y montos congelados por documento
 *   - Anular/revertir pagos con auditoría
 *   - Proteger eliminación de facturas con pagos activos
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Payment_Service {

    private static $instance = null;

    // Configuración
    private $upload_dir = 'riverso/pagos';
    private $max_file_size = 10 * 1024 * 1024; // 10 MB
    private $allowed_mimes = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Genera el siguiente número de ticket
     *
     * @return string Número de ticket (ej: PAG-2026-0001)
     */
    public function generate_ticket_number() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $year = date('Y');
        $last = $wpdb->get_var(
            "SELECT MAX(CAST(SUBSTRING(numero_ticket, -4) AS UNSIGNED)) 
             FROM {$prefix}factura_pagos 
             WHERE numero_ticket LIKE %s",
            "PAG-{$year}-%"
        );

        $next_seq = ($last ?? 0) + 1;
        return sprintf("PAG-%d-%04d", $year, $next_seq);
    }

    /**
     * Valida y procesa un archivo de comprobante
     *
     * @param mixed $file_data Datos del archivo (de $_FILES)
     * @return array|WP_Error ['ruta' => string, 'mime' => string, 'tamaño' => int, 'hash' => string, 'nombre_original' => string]
     */
    public function validate_and_upload_comprobante($file_data) {
        if (empty($file_data['tmp_name'])) {
            return new WP_Error('no_file', 'No se proporcionó archivo');
        }

        // Validar tamaño
        $file_size = filesize($file_data['tmp_name']);
        if ($file_size > $this->max_file_size) {
            return new WP_Error('file_too_large', "Archivo demasiado grande (máx {$this->max_file_size} bytes)");
        }

        // Validar MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file_data['tmp_name']);
        finfo_close($finfo);

        if (!isset($this->allowed_mimes[$mime])) {
            return new WP_Error('invalid_mime', "Tipo de archivo no permitido: {$mime}. Acepta: JPG, PNG, WebP");
        }

        // Crear directorio si no existe
        $upload_base = wp_upload_dir();
        $upload_path = $upload_base['basedir'] . '/' . $this->upload_dir;
        if (!is_dir($upload_path)) {
            wp_mkdir_p($upload_path);
        }

        // Generar nombre aleatorio preservando extensión
        $extensions = $this->allowed_mimes[$mime];
        $ext = !empty($extensions) ? '.' . $extensions[0] : '.jpg';
        $filename = bin2hex(random_bytes(16)) . $ext;
        $full_path = $upload_path . '/' . $filename;

        // Calcular hash
        $hash = hash_file('sha256', $file_data['tmp_name']);

        // Mover archivo
        if (!move_uploaded_file($file_data['tmp_name'], $full_path)) {
            return new WP_Error('upload_failed', 'Error al subir el archivo');
        }

        return [
            'ruta' => '/' . $this->upload_dir . '/' . $filename,
            'mime' => $mime,
            'tamaño' => $file_size,
            'hash' => $hash,
            'nombre_original' => sanitize_file_name($file_data['name'] ?? 'comprobante'),
        ];
    }

    /**
     * Crea un nuevo ticket de pago
     *
     * @param array  $factura_ids Array de IDs de facturas a incluir
     * @param array  $comprobante Datos de comprobante ['tmp_name' => ..., 'name' => ..., 'type' => ...]
     * @param array  $options ['fecha_pago' => string, 'notas' => string, 'user_id' => int]
     * @return array|WP_Error ['pago_id' => int, 'numero_ticket' => string, 'monto_total' => float]
     */
    public function create_payment_ticket(array $factura_ids, $comprobante, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (empty($factura_ids)) {
            return new WP_Error('no_facturas', 'Debe proporcionar al menos una factura');
        }

        // Validar comprobante
        if (!empty($comprobante)) {
            $comprobante_result = $this->validate_and_upload_comprobante($comprobante);
            if (is_wp_error($comprobante_result)) {
                return $comprobante_result;
            }
            $comprobante_data = $comprobante_result;
        } else {
            $comprobante_data = null;
        }

        // Obtener datos de todas las facturas
        $factura_ids_safe = array_map('intval', $factura_ids);
        $factura_ids_placeholders = implode(',', array_fill(0, count($factura_ids_safe), '%d'));
        
        $facturas = $wpdb->get_results(
            "SELECT id, tipo_dte, folio, monto_total 
             FROM {$prefix}facturas 
             WHERE id IN ({$factura_ids_placeholders})",
            array_merge([], $factura_ids_safe)
        );

        if (count($facturas) !== count($factura_ids_safe)) {
            return new WP_Error('invalid_facturas', 'Una o más facturas no existen o fueron eliminadas');
        }

        // Validar que no haya facturas ya pagadas
        foreach ($facturas as $f) {
            $pago_check = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}factura_pago_documentos WHERE factura_id = %d",
                $f->id
            );
            if ($pago_check > 0) {
                return new WP_Error('already_paid', "Factura {$f->tipo_dte} #{$f->folio} ya tiene un pago asociado");
            }
        }

        // Calcular saldo efectivo total (considerando NC)
        $total_monto = 0;
        $detalles_factura = [];
        
        foreach ($facturas as $f) {
            $credit_notes = new Riverso_Credit_Note_Service();
            $saldo = $credit_notes->calculate_saldo_efectivo($f->id);
            $total_monto += $saldo;
            $detalles_factura[] = [
                'id' => $f->id,
                'tipo_dte' => $f->tipo_dte,
                'folio' => $f->folio,
                'saldo' => $saldo,
            ];
        }

        if ($total_monto <= 0) {
            return new WP_Error('invalid_amount', 'El saldo total resultante es negativo o cero');
        }

        // Crear ticket
        $numero_ticket = $this->generate_ticket_number();
        $fecha_pago = $options['fecha_pago'] ?? date('Y-m-d');
        $user_id = $options['user_id'] ?? get_current_user_id();
        $notas = $options['notas'] ?? '';

        $wpdb->insert(
            "{$prefix}factura_pagos",
            [
                'numero_ticket' => $numero_ticket,
                'estado' => 'activo',
                'monto_total' => $total_monto,
                'moneda' => 'CLP',
                'fecha_pago' => $fecha_pago,
                'comprobante_nombre_original' => $comprobante_data['nombre_original'] ?? null,
                'comprobante_ruta_relativa' => $comprobante_data['ruta'] ?? null,
                'comprobante_mime_type' => $comprobante_data['mime'] ?? null,
                'comprobante_tamaño' => $comprobante_data['tamaño'] ?? null,
                'comprobante_hash' => $comprobante_data['hash'] ?? null,
                'notas' => sanitize_textarea_field($notas),
                'creado_por' => $user_id,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s']
        );

        if (!$wpdb->insert_id) {
            // Limpiar comprobante si hay error
            if ($comprobante_data) {
                @unlink(WP_CONTENT_DIR . '/../wp-content' . $comprobante_data['ruta']);
            }
            return new WP_Error('db_error', 'Error creando ticket: ' . $wpdb->last_error);
        }

        $pago_id = $wpdb->insert_id;

        // Crear relaciones N:M con montos congelados
        foreach ($detalles_factura as $detalle) {
            $wpdb->insert(
                "{$prefix}factura_pago_documentos",
                [
                    'pago_id' => $pago_id,
                    'factura_id' => $detalle['id'],
                    'monto_pagado' => $detalle['saldo'],
                    'tipo_aplicacion' => 'saldo_efectivo',
                ],
                ['%d', '%d', '%f', '%s']
            );
        }

        // Actualizar estado_pago en facturas
        foreach ($factura_ids_safe as $fid) {
            $wpdb->update(
                "{$prefix}facturas",
                ['estado_pago' => 'pagada'],
                ['id' => $fid],
                ['%s'],
                ['%d']
            );
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.payment_ticket_created',
                'factura_pagos',
                $pago_id,
                'info',
                "Ticket {$numero_ticket} creado. Monto total: \${$total_monto}. Documentos: " . count($facturas),
                $user_id
            );
        }

        return [
            'pago_id' => $pago_id,
            'numero_ticket' => $numero_ticket,
            'monto_total' => $total_monto,
            'facturas_incluidas' => count($facturas),
        ];
    }

    /**
     * Anula un ticket de pago (no lo elimina, lo marca como cancelado)
     *
     * @param int   $pago_id
     * @param array $options ['user_id' => int, 'razon_cancelacion' => string]
     * @return array|WP_Error
     */
    public function cancel_payment_ticket($pago_id, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pago = $wpdb->get_row($wpdb->prepare(
            "SELECT id, numero_ticket, estado FROM {$prefix}factura_pagos WHERE id = %d",
            $pago_id
        ));

        if (!$pago) {
            return new WP_Error('not_found', 'Ticket de pago no encontrado');
        }

        if ($pago->estado !== 'activo') {
            return new WP_Error('invalid_state', "No se puede cancelar un ticket en estado: {$pago->estado}");
        }

        $user_id = $options['user_id'] ?? get_current_user_id();
        $razon = $options['razon_cancelacion'] ?? 'Sin especificar';

        // Marcar como cancelado
        $result = $wpdb->update(
            "{$prefix}factura_pagos",
            [
                'estado' => 'cancelado',
                'cancelado_por' => $user_id,
                'cancelado_at' => current_time('mysql'),
                'razon_cancelacion' => sanitize_textarea_field($razon),
            ],
            ['id' => $pago_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error cancelando ticket: ' . $wpdb->last_error);
        }

        // Restaurar estado_pago en facturas a 'no_pagada'
        $factura_ids = $wpdb->get_col(
            "SELECT factura_id FROM {$prefix}factura_pago_documentos WHERE pago_id = %d",
            $pago_id
        );

        foreach ((array) $factura_ids as $fid) {
            $wpdb->update(
                "{$prefix}facturas",
                ['estado_pago' => 'no_pagada'],
                ['id' => intval($fid)],
                ['%s'],
                ['%d']
            );
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.payment_ticket_cancelled',
                'factura_pagos',
                $pago_id,
                'info',
                "Ticket {$pago->numero_ticket} anulado. Razón: {$razon}",
                $user_id
            );
        }

        return [
            'success' => true,
            'pago_id' => $pago_id,
            'numero_ticket' => $pago->numero_ticket,
        ];
    }

    /**
     * Obtiene datos de un ticket para visualización/descarga
     *
     * @param int $pago_id
     * @return array|WP_Error
     */
    public function get_payment_ticket($pago_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pago = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_pagos WHERE id = %d",
            $pago_id
        ));

        if (!$pago) {
            return new WP_Error('not_found', 'Ticket de pago no encontrado');
        }

        // Obtener documentos relacionados
        $documentos = $wpdb->get_results(
            "SELECT fpd.*, f.tipo_dte, f.folio, f.rut_emisor
             FROM {$prefix}factura_pago_documentos fpd
             JOIN {$prefix}facturas f ON f.id = fpd.factura_id
             WHERE fpd.pago_id = %d",
            $pago_id
        );

        return [
            'pago' => $pago,
            'documentos' => $documentos,
        ];
    }

    /**
     * Valida si se puede descargar el comprobante y retorna ruta segura
     *
     * @param int $pago_id
     * @return string|WP_Error Ruta relativa del comprobante
     */
    public function get_comprobante_path($pago_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pago = $wpdb->get_row($wpdb->prepare(
            "SELECT comprobante_ruta_relativa FROM {$prefix}factura_pagos WHERE id = %d",
            $pago_id
        ));

        if (!$pago || !$pago->comprobante_ruta_relativa) {
            return new WP_Error('no_file', 'Comprobante no disponible');
        }

        // Validar que la ruta no tenga traversal
        $ruta = $pago->comprobante_ruta_relativa;
        if (strpos($ruta, '..') !== false || strpos($ruta, '//') !== false) {
            return new WP_Error('invalid_path', 'Ruta de archivo inválida');
        }

        return $ruta;
    }
}
