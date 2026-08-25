<?php
/**
 * Módulo de documentos escaneados — PDF/imagen con extracción Gemini.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-r2-client.php';
require_once __DIR__ . '/class-gemini-client.php';
require_once __DIR__ . '/class-scan-extractor.php';

class Riverso_Scan_Module {

    private static $instance = null;

    /** @var Riverso_Invoice_Module|null */
    private $invoice_module = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_scan_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_riverso_scan_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_scan_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_scan_update', [$this, 'ajax_update']);
        add_action('wp_ajax_riverso_scan_confirm', [$this, 'ajax_confirm']);
        add_action('wp_ajax_riverso_scan_discard', [$this, 'ajax_discard']);
        add_action('wp_ajax_riverso_scan_reprocess', [$this, 'ajax_reprocess']);
        add_action('wp_ajax_riverso_scan_file_url', [$this, 'ajax_file_url']);
        add_action('wp_ajax_riverso_scan_file_stream', [$this, 'ajax_file_stream']);
        add_action('wp_ajax_riverso_scan_usage', [$this, 'ajax_usage']);
        add_action('wp_ajax_riverso_save_scan_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_riverso_scan_archivo_status', [$this, 'ajax_archivo_status']);
    }

    private function user_can_process() {
        return current_user_can('riverso_process_scans')
            || current_user_can('riverso_process_invoices')
            || current_user_can('riverso_create_invoices');
    }

    private function user_can_view() {
        return current_user_can('riverso_view_invoices') || $this->user_can_process();
    }

    /**
     * URL del visor (siempre proxy WP autenticado; sirve copia local).
     */
    private function file_view_url($archivo_id, $page = 1) {
        return $this->file_stream_url($archivo_id, $page);
    }

    /**
     * URL interna para ver el archivo en el visor.
     */
    private function file_stream_url($archivo_id, $page = 1) {
        $url = add_query_arg([
            'action'     => 'riverso_scan_file_stream',
            'archivo_id' => (int) $archivo_id,
            'nonce'      => wp_create_nonce('riverso_pos_nonce'),
        ], admin_url('admin-ajax.php'));

        if ($page > 1) {
            $url .= '#page=' . (int) $page;
        }

        return $url;
    }

    private function invoices() {
        if (!$this->invoice_module) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-module.php';
            $this->invoice_module = new Riverso_Invoice_Module();
        }
        return $this->invoice_module;
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * AJAX: subir archivo (simple o masiva).
     * Responde de inmediato y procesa Gemini en segundo plano para evitar HTTP 504 del proxy.
     */
    public function ajax_upload() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_process()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        if (empty($_FILES['scan_file'])) {
            wp_send_json_error(['message' => 'No se recibió archivo']);
        }

        $file = $_FILES['scan_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Error subiendo archivo: ' . $file['error']]);
        }

        $skip_cache = !empty($_POST['force_reprocess']);
        $prepared = $this->prepare_upload($file['tmp_name'], $file['name'], $skip_cache);
        if (is_wp_error($prepared)) {
            wp_send_json_error(['message' => $prepared->get_error_message()]);
        }

        // Caché hit: respuesta inmediata completa
        if (!empty($prepared['reutilizado'])) {
            wp_send_json_success($prepared);
        }

        // Evitar 504: cerrar respuesta HTTP y seguir con Gemini
        $this->flush_json_success([
            'message'    => 'Archivo recibido. Procesando con IA en segundo plano…',
            'archivo_id' => (int) $prepared['archivo_id'],
            'async'      => true,
            'estado'     => 'procesando',
        ]);

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $this->run_extraction($prepared);

        // No wp_die: la respuesta ya se envió
        exit;
    }

    /**
     * AJAX: estado de un archivo en procesamiento (polling).
     */
    public function ajax_archivo_status() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $archivo_id = (int) ($_POST['archivo_id'] ?? 0);
        if ($archivo_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido']);
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, estado, error_mensaje, gemini_llamadas, gemini_tokens_in, gemini_tokens_out
             FROM {$this->table('documentos_archivos')} WHERE id = %d",
            $archivo_id
        ), ARRAY_A);

        if (!$row) {
            wp_send_json_error(['message' => 'Archivo no encontrado']);
        }

        $estado = $row['estado'];
        $payload = [
            'archivo_id'      => (int) $row['id'],
            'estado'          => $estado,
            'error_mensaje'   => $row['error_mensaje'] ?? '',
            'gemini_llamadas' => (int) ($row['gemini_llamadas'] ?? 0),
            'done'            => in_array($estado, ['procesado', 'parcial', 'error'], true),
        ];

        if (in_array($estado, ['procesado', 'parcial'], true)) {
            $docs = $this->load_documents_for_archivo($archivo_id);
            $payload['documentos'] = $docs;
            $payload['message'] = count($docs) . ' documento(s) extraído(s)';
        } elseif ($estado === 'error') {
            $payload['message'] = $row['error_mensaje'] ?: 'Error al procesar con IA';
        } else {
            $payload['message'] = 'Procesando con IA…';
        }

        wp_send_json_success($payload);
    }

    /**
     * Cierra la respuesta JSON al cliente y deja PHP seguir ejecutando.
     */
    private function flush_json_success(array $data) {
        $payload = wp_json_encode(['success' => true, 'data' => $data]);
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: application/json; charset=' . get_option('blog_charset', 'UTF-8'));
            header('X-Content-Type-Options: nosniff');
            header('Connection: close');
            header('Content-Length: ' . strlen($payload));
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo $payload;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
    }

    /**
     * Copia el upload a un path persistente (el tmp de PHP se borra al terminar la request).
     */
    private function persist_temp_upload($tmp_path, $hash, $ext) {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return $tmp_path;
        }
        $dir = trailingslashit($upload['basedir']) . 'riverso-scans';
        if (!wp_mkdir_p($dir)) {
            return $tmp_path;
        }
        riverso_scan_protect_uploads_dir();
        $dest = riverso_scan_local_archive_path($hash, $ext);
        if ($dest === '') {
            $dest = $dir . '/' . preg_replace('/[^a-f0-9]/', '', $hash) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        }
        if (@copy($tmp_path, $dest)) {
            return $dest;
        }
        return $tmp_path;
    }

    private function local_archive_path(array $archivo) {
        $ext = riverso_scan_ext_from_mime($archivo['mime'] ?? 'application/pdf');
        if (!empty($archivo['r2_key_original']) && preg_match('/\.([a-z0-9]+)$/i', $archivo['r2_key_original'], $m)) {
            $ext = strtolower($m[1]);
        }
        return riverso_scan_local_archive_path($archivo['archivo_hash'] ?? '', $ext);
    }

    /**
     * Descarga desde R2 a disco local (una vez) para servir vistas previas.
     *
     * @return string|WP_Error Ruta local
     */
    private function ensure_local_archive(array $archivo) {
        $path = $this->local_archive_path($archivo);
        if ($path === '') {
            return new WP_Error('local_path', 'No se pudo determinar ruta local');
        }
        if (is_readable($path)) {
            return $path;
        }

        riverso_scan_protect_uploads_dir();
        $dir = dirname($path);
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('local_mkdir', 'No se pudo crear carpeta de escaneos');
        }

        if (empty($archivo['r2_key_original'])) {
            return new WP_Error('no_r2', 'Sin copia en R2');
        }

        $r2 = new Riverso_R2_Client();
        if (!$r2->is_configured()) {
            return new WP_Error('r2_not_configured', 'R2 no configurado');
        }

        $tmp = $path . '.part';
        $saved = $r2->download_object($archivo['r2_key_original'], $tmp);
        if (is_wp_error($saved)) {
            @unlink($tmp);
            return $saved;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('local_save', 'No se pudo guardar copia local');
        }
        return $path;
    }

    private function verify_stream_request() {
        if (!$this->user_can_view()) {
            status_header(403);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Sin permisos';
            exit;
        }
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'riverso_pos_nonce')) {
            status_header(403);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Enlace expirado. Recargue la página e intente de nuevo.';
            exit;
        }
    }

    private function stream_local_file($path, $mime, $filename) {
        $size = filesize($path);
        if ($size === false) {
            return new WP_Error('local_read', 'No se pudo leer el archivo');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');

        $fp = fopen($path, 'rb');
        if (!$fp) {
            return new WP_Error('local_read', 'No se pudo abrir el archivo');
        }
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            if (function_exists('flush')) {
                flush();
            }
        }
        fclose($fp);
        return true;
    }

    /**
     * Prepara archivo (R2 + fila BD). Sin llamar a Gemini todavía.
     *
     * @return array|WP_Error
     */
    private function prepare_upload($tmp_path, $original_name, $skip_cache = false) {
        global $wpdb;

        $type = riverso_scan_detect_file_type($tmp_path, $original_name);
        if (is_wp_error($type)) {
            return $type;
        }

        $hash = hash_file('sha256', $tmp_path);
        $bytes = filesize($tmp_path);
        $paginas = $type['mime'] === 'application/pdf' ? riverso_pdf_page_count($tmp_path) : 1;

        $archivos_table = $this->table('documentos_archivos');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$archivos_table} WHERE archivo_hash = %s",
            $hash
        ));

        if ($existing && !$skip_cache && in_array($existing->estado, ['procesado', 'parcial'], true)) {
            $docs = $this->load_documents_for_archivo((int) $existing->id);
            $wpdb->update($archivos_table, ['gemini_reutilizado' => 1], ['id' => (int) $existing->id]);
            return [
                'message'         => 'Archivo ya procesado (sin costo Gemini)',
                'archivo_id'      => (int) $existing->id,
                'reutilizado'     => true,
                'async'           => false,
                'documentos'      => $docs,
                'gemini_llamadas' => 0,
            ];
        }

        // Si ya está procesando el mismo hash, no lanzar otro Gemini
        if ($existing && !$skip_cache && $existing->estado === 'procesando') {
            return [
                'message'    => 'Este archivo ya se está procesando…',
                'archivo_id' => (int) $existing->id,
                'async'      => true,
                'estado'     => 'procesando',
                'reutilizado'=> false,
                'already_running' => true,
            ];
        }

        $extractor = new Riverso_Scan_Extractor();
        $r2 = new Riverso_R2_Client();
        $r2_key = $extractor->r2_key_archivo($hash, $type['ext']);

        if ($skip_cache && $existing) {
            $doc_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->table('documentos_escaneados')} WHERE archivo_id = %d",
                (int) $existing->id
            ));
            foreach ($doc_ids as $doc_id) {
                $wpdb->delete($this->table('documento_items'), ['documento_id' => (int) $doc_id]);
                $wpdb->delete($this->table('documento_referencias'), ['documento_id' => (int) $doc_id]);
            }
            $wpdb->delete($this->table('documentos_escaneados'), ['archivo_id' => (int) $existing->id]);
        }

        if (!$existing || empty($existing->r2_key_original)) {
            if ($r2->is_configured()) {
                $body = file_get_contents($tmp_path);
                $put = $r2->put_object($r2_key, $body, $type['mime']);
                if (is_wp_error($put)) {
                    return $put;
                }
            }
        } else {
            $r2_key = $existing->r2_key_original;
        }

        $archivo_id = 0;
        if ($existing) {
            $archivo_id = (int) $existing->id;
            $wpdb->update($archivos_table, [
                'estado'        => 'procesando',
                'error_mensaje' => null,
                'updated_at'    => current_time('mysql'),
            ], ['id' => $archivo_id]);
        } else {
            $wpdb->insert($archivos_table, [
                'archivo_hash'    => $hash,
                'nombre_original' => sanitize_file_name($original_name),
                'mime'            => $type['mime'],
                'bytes'           => $bytes,
                'paginas'         => $paginas,
                'r2_key_original' => $r2_key,
                'estado'          => 'procesando',
                'subido_por'      => get_current_user_id(),
            ]);
            $archivo_id = (int) $wpdb->insert_id;
        }

        $local_path = $this->persist_temp_upload($tmp_path, $hash, $type['ext']);

        return [
            'archivo_id'    => $archivo_id,
            'local_path'    => $local_path,
            'type'          => $type,
            'hash'          => $hash,
            'r2_key'        => $r2_key,
            'paginas'       => $paginas,
            'original_name' => $original_name,
            'reutilizado'   => false,
            'async'         => true,
        ];
    }

    /**
     * Ejecuta Gemini + persistencia (tras haber respondido al cliente).
     */
    private function run_extraction(array $prepared) {
        global $wpdb;
        $archivo_id = (int) $prepared['archivo_id'];
        $archivos_table = $this->table('documentos_archivos');
        $tmp_path = $prepared['local_path'];
        $type = $prepared['type'];
        $hash = $prepared['hash'];
        $r2_key = $prepared['r2_key'];
        $paginas = (int) $prepared['paginas'];
        $original_name = $prepared['original_name'];

        if (!empty($prepared['already_running'])) {
            return;
        }

        $extractor = new Riverso_Scan_Extractor();
        $r2 = new Riverso_R2_Client();
        $gemini = new Riverso_Gemini_Client();

        if (!$gemini->is_configured()) {
            $wpdb->update($archivos_table, [
                'estado'        => 'error',
                'error_mensaje' => 'Gemini API no configurada',
            ], ['id' => $archivo_id]);
            $this->cleanup_temp_upload($tmp_path);
            return;
        }

        // Si el path local se perdió, bajar de R2
        if (!is_readable($tmp_path) && $r2->is_configured() && $r2_key) {
            $body = $r2->get_object($r2_key);
            if (!is_wp_error($body)) {
                $tmp_path = $this->persist_temp_upload(
                    $this->write_temp_bytes($body, $original_name),
                    $hash,
                    $type['ext']
                );
            }
        }

        if (!is_readable($tmp_path)) {
            $wpdb->update($archivos_table, [
                'estado'        => 'error',
                'error_mensaje' => 'No se pudo leer el archivo para Gemini',
            ], ['id' => $archivo_id]);
            return;
        }

        $prompt = $extractor->build_prompt();
        $schema = Riverso_Gemini_Client::extraction_schema();
        $raw = $gemini->extract_document($tmp_path, $type['mime'], $prompt, $schema);

        $gemini_calls = 1;
        $tokens_in = $gemini->get_last_usage()['tokens_in'];
        $tokens_out = $gemini->get_last_usage()['tokens_out'];

        if (is_wp_error($raw)) {
            $wpdb->update($archivos_table, [
                'estado'            => 'error',
                'error_mensaje'     => $raw->get_error_message(),
                'gemini_model'      => $gemini->get_model(),
                'gemini_tokens_in'  => $tokens_in,
                'gemini_tokens_out' => $tokens_out,
                'gemini_llamadas'   => $gemini_calls,
            ], ['id' => $archivo_id]);
            $this->cleanup_temp_upload($tmp_path);
            return;
        }

        $meta_key = $extractor->r2_key_archivo_meta($hash);
        $meta_payload = [
            'archivo_hash'    => $hash,
            'nombre_original' => sanitize_file_name($original_name),
            'mime'            => $type['mime'],
            'paginas'         => $paginas,
            'gemini_model'    => $gemini->get_model(),
            'gemini_raw'      => $raw,
            'processed_at'    => gmdate('c'),
        ];
        if ($r2->is_configured()) {
            $r2->put_object($meta_key, wp_json_encode($meta_payload, JSON_UNESCAPED_UNICODE), 'application/json');
        }

        $documentos = $raw['documentos'] ?? [];

        if ($extractor->needs_resegmentation($documentos, $paginas)) {
            $resegmented = $extractor->resegment_by_page($gemini, $tmp_path, $type['mime'], $paginas);
            $gemini_calls += $paginas;
            $tokens_in += $gemini->get_last_usage()['tokens_in'] * max(0, $paginas - 1);
            $tokens_out += $gemini->get_last_usage()['tokens_out'] * max(0, $paginas - 1);
            if (!empty($resegmented)) {
                $documentos = $resegmented;
                $meta_payload['gemini_resegmented'] = true;
            }
        } elseif (empty($documentos) && $paginas > 15) {
            for ($p = 1; $p <= $paginas; $p += 5) {
                $end = min($paginas, $p + 4);
                $hint_prompt = $extractor->build_prompt(['pagina_inicio' => $p, 'pagina_fin' => $end]);
                $partial = $gemini->extract_page_range($tmp_path, $type['mime'], $hint_prompt, $schema);
                $gemini_calls++;
                $tokens_in += $gemini->get_last_usage()['tokens_in'];
                $tokens_out += $gemini->get_last_usage()['tokens_out'];
                if (!is_wp_error($partial) && !empty($partial['documentos'])) {
                    $documentos = array_merge($documentos, $partial['documentos']);
                }
            }
            $documentos = $extractor->merge_continuation_pages($documentos);
        }

        $saved_docs = $this->persist_extracted_documents(
            $archivo_id,
            $hash,
            $r2_key,
            $type,
            $documentos,
            $extractor,
            $r2
        );

        $wpdb->update($archivos_table, [
            'estado'            => empty($saved_docs) ? 'error' : 'procesado',
            'r2_key_json'       => $meta_key,
            'gemini_model'      => $gemini->get_model(),
            'gemini_tokens_in'  => $tokens_in,
            'gemini_tokens_out' => $tokens_out,
            'gemini_llamadas'   => $gemini_calls,
            'gemini_reutilizado'=> 0,
            'error_mensaje'     => empty($saved_docs) ? 'No se detectaron documentos' : null,
        ], ['id' => $archivo_id]);

        $this->cleanup_temp_upload($tmp_path);
    }

    private function write_temp_bytes($body, $original_name) {
        $tmp = wp_tempnam($original_name);
        file_put_contents($tmp, $body);
        return $tmp;
    }

    private function cleanup_temp_upload($path) {
        if (!$path || !is_string($path)) {
            return;
        }
        $base = riverso_scan_uploads_dir();
        if ($base !== '' && strpos($path, trailingslashit($base)) === 0) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Procesa un archivo subido (síncrono; usado por reprocesar).
     *
     * @return array|WP_Error
     */
    public function process_upload($tmp_path, $original_name, $skip_cache = false) {
        $prepared = $this->prepare_upload($tmp_path, $original_name, $skip_cache);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        if (!empty($prepared['reutilizado'])) {
            return $prepared;
        }
        if (!empty($prepared['already_running'])) {
            return [
                'message'    => $prepared['message'] ?? 'Procesando…',
                'archivo_id' => (int) $prepared['archivo_id'],
                'async'      => true,
                'estado'     => 'procesando',
            ];
        }

        $this->run_extraction($prepared);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, error_mensaje, gemini_llamadas, gemini_tokens_in, gemini_tokens_out
             FROM {$this->table('documentos_archivos')} WHERE id = %d",
            (int) $prepared['archivo_id']
        ), ARRAY_A);

        if (!$row || $row['estado'] === 'error') {
            return new WP_Error(
                'gemini_failed',
                $row['error_mensaje'] ?? 'Error al procesar con IA'
            );
        }

        $docs = $this->load_documents_for_archivo((int) $prepared['archivo_id']);
        return [
            'message'         => count($docs) . ' documento(s) extraído(s)',
            'archivo_id'      => (int) $prepared['archivo_id'],
            'reutilizado'     => false,
            'documentos'      => $docs,
            'gemini_llamadas' => (int) ($row['gemini_llamadas'] ?? 0),
            'tokens_in'       => (int) ($row['gemini_tokens_in'] ?? 0),
            'tokens_out'      => (int) ($row['gemini_tokens_out'] ?? 0),
        ];
    }

    /**
     * Persiste documentos extraídos en BD y R2.
     */
    private function persist_extracted_documents($archivo_id, $archivo_hash, $r2_key_original, $type, array $documentos, Riverso_Scan_Extractor $extractor, Riverso_R2_Client $r2) {
        global $wpdb;
        $saved = [];
        $docs_table = $this->table('documentos_escaneados');

        foreach ($documentos as $doc) {
            $validacion = $extractor->validate_document($doc);
            $normalized = $extractor->normalize_to_factura($doc);
            $tipo_dte = (int) $normalized['tipo_dte'];
            $folio = (string) $normalized['folio'];
            $rut_emisor = $normalized['emisor']['rut'] ?? '';
            $doc_hash = riverso_scan_doc_hash($tipo_dte, $folio, $rut_emisor);

            $estado = 'pendiente';
            $factura_id = null;

            // Dedup capa 2: mismo doc en bandeja
            $dup_band = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$docs_table} WHERE doc_hash = %s AND estado_revision NOT IN ('descartado') LIMIT 1",
                $doc_hash
            ));

            // Dedup capa 3: ya existe en riverso_facturas (XML u origen escaneo)
            $factura_existente = null;
            if ($folio && $rut_emisor && $tipo_dte) {
                if (function_exists('riverso_find_factura_by_dte')) {
                    $found = riverso_find_factura_by_dte($tipo_dte, $folio, $rut_emisor);
                    $factura_existente = $found ? (int) $found['id'] : null;
                } else {
                    $factura_existente = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$this->table('facturas')} WHERE tipo_dte = %d AND folio = %s AND rut_emisor = %s LIMIT 1",
                        $tipo_dte,
                        $folio,
                        $rut_emisor
                    ));
                }
            }

            if ($dup_band) {
                $estado = 'duplicado';
            } elseif ($factura_existente) {
                $estado = 'duplicado';
                $factura_id = (int) $factura_existente;
                $this->attach_scan_to_factura($factura_id, $r2_key_original, $doc, $archivo_hash);
            }

            $confianza = (float) ($doc['confianza_global'] ?? 0);
            if (!$validacion['ok']) {
                $confianza = min($confianza, 0.6);
            }

            $payload = [
                'archivo_hash'    => $archivo_hash,
                'r2_key_original' => $r2_key_original,
                'mime'            => $type['mime'],
                'pagina_inicio'   => (int) ($doc['pagina_inicio'] ?? 1),
                'pagina_fin'      => (int) ($doc['pagina_fin'] ?? 1),
                'raw'             => $doc,
                'normalized'      => $normalized,
                'validacion'      => $validacion,
            ];

            $r2_doc_key = $extractor->r2_key_documento($rut_emisor, $tipo_dte, $folio, $doc_hash);
            if ($r2->is_configured()) {
                $r2->put_object($r2_doc_key, wp_json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json');
            }

            $wpdb->insert($docs_table, [
                'archivo_id'          => $archivo_id,
                'pagina_inicio'       => (int) ($doc['pagina_inicio'] ?? 1),
                'pagina_fin'          => (int) ($doc['pagina_fin'] ?? 1),
                'doc_hash'            => $doc_hash,
                'tipo_documento'      => sanitize_text_field($doc['tipo_documento'] ?? ''),
                'tipo_dte'            => $tipo_dte,
                'folio'               => $folio,
                'rut_emisor'          => $rut_emisor,
                'razon_social_emisor' => $normalized['emisor']['razon_social'] ?? '',
                'rut_receptor'        => $normalized['receptor']['rut'] ?? '',
                'fecha_emision'       => $normalized['fecha_emision'] ?: null,
                'fecha_vencimiento'   => !empty($doc['fecha_vencimiento'])
                    ? Riverso_Scan_Extractor::normalize_date_value($doc['fecha_vencimiento'])
                    : null,
                'monto_neto'          => (float) ($normalized['totales']['neto'] ?? 0),
                'monto_exento'        => (float) ($normalized['totales']['exento'] ?? 0),
                'monto_iva'           => (float) ($normalized['totales']['iva'] ?? 0),
                'monto_total'         => (float) ($normalized['totales']['total'] ?? 0),
                'confianza'           => $confianza,
                'validacion'          => wp_json_encode($validacion),
                'estado_revision'     => $estado,
                'factura_id'          => $factura_id,
                'r2_key_json'         => $r2_doc_key,
                'datos_json'          => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $doc_id = (int) $wpdb->insert_id;
            $this->save_documento_items($doc_id, $doc['items'] ?? []);
            $this->save_documento_referencias($doc_id, $doc['referencias'] ?? []);

            $saved[] = $this->format_documento_row($doc_id);
        }

        return $saved;
    }

    private function save_documento_items($documento_id, array $items) {
        global $wpdb;
        $table = $this->table('documento_items');
        foreach ($items as $item) {
            $wpdb->insert($table, [
                'documento_id'   => $documento_id,
                'numero_linea'   => (int) ($item['numero'] ?? 1),
                'codigo'         => sanitize_text_field($item['codigo'] ?? ''),
                'nombre'         => sanitize_text_field($item['descripcion'] ?? ''),
                'descripcion'    => sanitize_textarea_field($item['descripcion'] ?? ''),
                'cantidad'       => (float) ($item['cantidad'] ?? 0),
                'unidad'         => sanitize_text_field($item['unidad'] ?? ''),
                'precio_unitario'=> (float) ($item['precio_unitario'] ?? 0),
                'monto_total'    => (float) ($item['monto_total'] ?? 0),
                'confianza'      => (float) ($item['confianza'] ?? 0),
            ]);
        }
    }

    private function save_documento_referencias($documento_id, array $refs) {
        global $wpdb;
        $table = $this->table('documento_referencias');
        foreach ($refs as $ref) {
            $tipo_ref = sanitize_text_field($ref['tipo'] ?? '');
            $fecha = trim($ref['fecha'] ?? '');
            $fecha_sql = null;
            if ($fecha !== '') {
                $norm = (new Riverso_Scan_Extractor())->normalize_to_factura(['fecha_emision' => $fecha]);
                $fecha_sql = $norm['fecha_emision'];
            }
            $wpdb->insert($table, [
                'documento_id' => $documento_id,
                'tipo_ref'     => $tipo_ref,
                'tipo_doc_ref' => riverso_scan_ref_tipo_doc($tipo_ref),
                'folio_ref'    => riverso_normalize_folio($ref['folio'] ?? ''),
                'fecha_ref'    => $fecha_sql,
                'razon'        => sanitize_textarea_field($ref['razon'] ?? ''),
            ]);
        }
    }

    private function attach_scan_to_factura($factura_id, $r2_key, array $doc, $archivo_hash) {
        if (function_exists('riverso_factura_attach_scan_meta')) {
            riverso_factura_attach_scan_meta($factura_id, $r2_key, $doc, $archivo_hash);
            return;
        }
        // Fallback legacy si el helper no está cargado
        global $wpdb;
        $facturas_table = $this->table('facturas');
        $scan_meta = [
            'archivo_hash'  => $archivo_hash,
            'r2_key'        => $r2_key,
            'pagina_inicio' => (int) ($doc['pagina_inicio'] ?? 1),
            'pagina_fin'    => (int) ($doc['pagina_fin'] ?? 1),
            'attached_at'   => current_time('mysql'),
        ];
        $existing_path = $wpdb->get_var($wpdb->prepare(
            "SELECT xml_path FROM {$facturas_table} WHERE id = %d",
            $factura_id
        ));
        $paths = [];
        if ($existing_path) {
            $decoded = json_decode($existing_path, true);
            if (is_array($decoded)) {
                $paths = $decoded;
            } else {
                $paths[] = ['legacy' => $existing_path];
            }
        }
        $paths[] = $scan_meta;
        $wpdb->update($facturas_table, [
            'xml_path' => wp_json_encode($paths),
        ], ['id' => $factura_id]);

        if (function_exists('riverso_factura_mark_scan_attached')) {
            riverso_factura_mark_scan_attached($factura_id);
        }
    }

    private function load_documents_for_archivo($archivo_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table('documentos_escaneados')} WHERE archivo_id = %d ORDER BY id ASC",
            $archivo_id
        ));
        $out = [];
        foreach ($ids as $id) {
            $out[] = $this->format_documento_row((int) $id);
        }
        return $out;
    }

    private function format_documento_row($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.*, a.nombre_original, a.paginas AS archivo_paginas, a.archivo_hash,
                    f.origen_ingreso AS factura_origen, f.folio AS factura_folio
             FROM {$this->table('documentos_escaneados')} d
             JOIN {$this->table('documentos_archivos')} a ON a.id = d.archivo_id
             LEFT JOIN {$this->table('facturas')} f ON f.id = d.factura_id
             WHERE d.id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $validacion = json_decode($row['validacion'] ?? '{}', true) ?: [];
        $row['validacion'] = $validacion;
        $row['confianza_level'] = riverso_scan_confidence_level((float) $row['confianza'], $validacion);
        $row['tipo_label'] = riverso_scan_tipo_label((int) $row['tipo_dte'], $row['tipo_documento']);
        $row['paginas_label'] = $row['pagina_inicio'] === $row['pagina_fin']
            ? (string) $row['pagina_inicio']
            : $row['pagina_inicio'] . '-' . $row['pagina_fin'];
        if (!empty($row['factura_id']) && function_exists('riverso_factura_origen_label')) {
            $row['factura_origen_label'] = riverso_factura_origen_label($row['factura_origen'] ?? 'escaneo');
        }
        return $row;
    }

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $per_page = min(100, max(10, (int) ($_POST['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];

        if (!empty($_POST['estado'])) {
            $where[] = 'd.estado_revision = %s';
            $params[] = sanitize_text_field($_POST['estado']);
        }
        if (!empty($_POST['search'])) {
            $where[] = '(d.folio LIKE %s OR d.razon_social_emisor LIKE %s OR a.nombre_original LIKE %s)';
            $like = '%' . $wpdb->esc_like(sanitize_text_field($_POST['search'])) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($_POST['fecha_desde'])) {
            $where[] = 'd.fecha_emision >= %s';
            $params[] = sanitize_text_field($_POST['fecha_desde']);
        }
        if (!empty($_POST['fecha_hasta'])) {
            $where[] = 'd.fecha_emision <= %s';
            $params[] = sanitize_text_field($_POST['fecha_hasta']);
        }

        $orderby = sanitize_text_field($_POST['orderby'] ?? 'created_at');
        $allowed_order = ['created_at', 'fecha_emision', 'folio', 'monto_total', 'confianza', 'estado_revision'];
        if (!in_array($orderby, $allowed_order, true)) {
            $orderby = 'created_at';
        }
        $order = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where_sql = implode(' AND ', $where);
        $docs_table = $this->table('documentos_escaneados');
        $arch_table = $this->table('documentos_archivos');

        $count_sql = "SELECT COUNT(*) FROM {$docs_table} d JOIN {$arch_table} a ON a.id = d.archivo_id WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT d.id FROM {$docs_table} d JOIN {$arch_table} a ON a.id = d.archivo_id
                WHERE {$where_sql} ORDER BY d.{$orderby} {$order} LIMIT %d OFFSET %d";
        $list_params = array_merge($params, [$per_page, $offset]);
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$list_params));

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $this->format_documento_row((int) $id);
        }

        wp_send_json_success([
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ]);
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $doc = $this->format_documento_row($id);
        if (!$doc) {
            wp_send_json_error(['message' => 'Documento no encontrado']);
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('documento_items')} WHERE documento_id = %d ORDER BY numero_linea",
            $id
        ), ARRAY_A);

        $refs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('documento_referencias')} WHERE documento_id = %d ORDER BY id",
            $id
        ), ARRAY_A);

        $archivo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('documentos_archivos')} WHERE id = %d",
            (int) $doc['archivo_id']
        ), ARRAY_A);

        $payload = json_decode($doc['datos_json'] ?? '{}', true) ?: [];
        $file_url = '';
        if (!empty($archivo)) {
            $page = !empty($doc['pagina_inicio']) ? (int) $doc['pagina_inicio'] : 1;
            $file_url = $this->file_view_url((int) $archivo['id'], $page);
        }

        wp_send_json_success([
            'documento' => $doc,
            'items'     => $items,
            'referencias'=> $refs,
            'archivo'   => $archivo,
            'payload'   => $payload,
            'file_url'  => $file_url,
        ]);
    }

    public function ajax_update() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_process()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $data = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : null;
        if (!$id || !is_array($data)) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }

        global $wpdb;
        $extractor = new Riverso_Scan_Extractor();
        $validacion = $extractor->validate_document($data);
        $normalized = $extractor->normalize_to_factura($data);

        $tipo_dte = (int) $normalized['tipo_dte'];
        $folio = (string) $normalized['folio'];
        $rut_emisor = $normalized['emisor']['rut'] ?? '';
        $doc_hash = riverso_scan_doc_hash($tipo_dte, $folio, $rut_emisor);

        $payload = [
            'raw'        => $data,
            'normalized' => $normalized,
            'validacion' => $validacion,
            'updated_at' => current_time('mysql'),
        ];

        $wpdb->update($this->table('documentos_escaneados'), [
            'doc_hash'            => $doc_hash,
            'tipo_documento'      => sanitize_text_field($data['tipo_documento'] ?? ''),
            'tipo_dte'            => $tipo_dte,
            'folio'               => $folio,
            'rut_emisor'          => $rut_emisor,
            'razon_social_emisor' => $normalized['emisor']['razon_social'] ?? '',
            'rut_receptor'        => $normalized['receptor']['rut'] ?? '',
            'fecha_emision'       => $normalized['fecha_emision'],
            'monto_neto'          => (float) ($normalized['totales']['neto'] ?? 0),
            'monto_exento'        => (float) ($normalized['totales']['exento'] ?? 0),
            'monto_iva'           => (float) ($normalized['totales']['iva'] ?? 0),
            'monto_total'         => (float) ($normalized['totales']['total'] ?? 0),
            'confianza'           => (float) ($data['confianza_global'] ?? 0.8),
            'validacion'          => wp_json_encode($validacion),
            'estado_revision'     => 'revisado',
            'datos_json'          => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ], ['id' => $id]);

        $wpdb->delete($this->table('documento_items'), ['documento_id' => $id]);
        $wpdb->delete($this->table('documento_referencias'), ['documento_id' => $id]);
        $this->save_documento_items($id, $data['items'] ?? []);
        $this->save_documento_referencias($id, $data['referencias'] ?? []);

        wp_send_json_success([
            'message'   => 'Documento actualizado',
            'documento' => $this->format_documento_row($id),
        ]);
    }

    public function ajax_confirm() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_process()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $documento_tipo = sanitize_text_field($_POST['documento_tipo'] ?? 'productos');
        $modo_ingreso = sanitize_text_field($_POST['modo_ingreso'] ?? 'solo_costos');

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('documentos_escaneados')} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Documento no encontrado']);
        }

        if ($row['estado_revision'] === 'confirmado' && !empty($row['factura_id'])) {
            wp_send_json_success([
                'message'    => 'Ya confirmado',
                'factura_id' => (int) $row['factura_id'],
            ]);
        }

        $payload = json_decode($row['datos_json'] ?? '{}', true) ?: [];
        $factura = $payload['normalized'] ?? null;
        if (!$factura) {
            $extractor = new Riverso_Scan_Extractor();
            $raw = $payload['raw'] ?? json_decode($row['datos_json'], true)['raw'] ?? [];
            $factura = $extractor->normalize_to_factura(is_array($raw) ? $raw : []);
        }

        unset($factura['_scan_meta']);

        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-intake-service.php';
        Riverso_Invoice_Intake_Service::get_instance()->classify_factura_items($factura);
        Riverso_Invoice_Intake_Service::get_instance()->enrich_factura_items_costs($factura);

        $save_options = [
            'documento_subtipo' => $documento_tipo,
            'modo_ingreso'      => $modo_ingreso,
            'tipo_confirmado'   => 1,
            'proveedor_modo'    => 'xml',
            'origen_ingreso'    => 'escaneo',
        ];

        $factura_id = $this->invoices()->save_invoice($factura, $save_options);
        if (is_wp_error($factura_id)) {
            if ($factura_id->get_error_code() === 'duplicate') {
                $data = $factura_id->get_error_data();
                $existing_id = (int) ($data['factura_id'] ?? 0);
                if ($existing_id) {
                    $archivo = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$this->table('documentos_archivos')} WHERE id = %d",
                        (int) $row['archivo_id']
                    ), ARRAY_A);
                    $raw = $payload['raw'] ?? [];
                    if ($archivo) {
                        $this->attach_scan_to_factura($existing_id, $archivo['r2_key_original'], $raw, $archivo['archivo_hash']);
                    }

                    $merged_detail = false;
                    $merge_msg = 'Factura ya existía — escaneo adjuntado como respaldo';
                    if (function_exists('riverso_factura_db_is_sii_rescued_stub')
                        && riverso_factura_db_is_sii_rescued_stub($existing_id)
                        && !empty($factura['items'])
                        && !(function_exists('riverso_factura_data_is_sii_rescued_stub')
                            && riverso_factura_data_is_sii_rescued_stub($factura))) {
                        $merge = $this->invoices()->merge_scan_into_factura($existing_id, $factura, $save_options);
                        if (!is_wp_error($merge) && !empty($merge['items_updated'])) {
                            $merged_detail = true;
                            $merge_msg = $merge['message']
                                ?? 'Escaneo aplicado como detalle (XML SII sin líneas)';
                        }
                    }

                    $wpdb->update($this->table('documentos_escaneados'), [
                        'estado_revision' => $merged_detail ? 'confirmado' : 'duplicado',
                        'factura_id'      => $existing_id,
                    ], ['id' => $id]);
                    wp_send_json_success([
                        'message'    => $merge_msg,
                        'factura_id' => $existing_id,
                        'duplicado'  => !$merged_detail,
                        'scan_truth' => $merged_detail,
                    ]);
                }
            }
            wp_send_json_error(['message' => $factura_id->get_error_message(), 'data' => $factura_id->get_error_data()]);
        }

        $archivo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('documentos_archivos')} WHERE id = %d",
            (int) $row['archivo_id']
        ), ARRAY_A);
        if ($archivo) {
            $raw = $payload['raw'] ?? [];
            $this->attach_scan_to_factura((int) $factura_id, $archivo['r2_key_original'], $raw, $archivo['archivo_hash']);
        }

        $wpdb->update($this->table('documentos_escaneados'), [
            'estado_revision' => 'confirmado',
            'factura_id'      => (int) $factura_id,
        ], ['id' => $id]);

        wp_send_json_success([
            'message'    => 'Documento ingresado como factura',
            'factura_id' => (int) $factura_id,
        ]);
    }

    public function ajax_discard() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_process()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = (int) ($_POST['id'] ?? 0);
        global $wpdb;
        $wpdb->update($this->table('documentos_escaneados'), [
            'estado_revision' => 'descartado',
        ], ['id' => $id]);
        wp_send_json_success(['message' => 'Documento descartado']);
    }

    public function ajax_reprocess() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_process()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $archivo_id = (int) ($_POST['archivo_id'] ?? 0);
        global $wpdb;
        $archivo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('documentos_archivos')} WHERE id = %d",
            $archivo_id
        ), ARRAY_A);
        if (!$archivo) {
            wp_send_json_error(['message' => 'Archivo no encontrado']);
        }

        $r2 = new Riverso_R2_Client();
        if (!$r2->is_configured() || empty($archivo['r2_key_original'])) {
            wp_send_json_error(['message' => 'No hay copia en R2 para reprocesar']);
        }

        $body = $r2->get_object($archivo['r2_key_original']);
        if (is_wp_error($body)) {
            wp_send_json_error(['message' => $body->get_error_message()]);
        }

        $tmp = wp_tempnam($archivo['nombre_original']);
        file_put_contents($tmp, $body);
        $prepared = $this->prepare_upload($tmp, $archivo['nombre_original'], true);
        @unlink($tmp);

        if (is_wp_error($prepared)) {
            wp_send_json_error(['message' => $prepared->get_error_message()]);
        }

        $this->flush_json_success([
            'message'    => 'Reproceso iniciado. Procesando con IA…',
            'archivo_id' => (int) $prepared['archivo_id'],
            'async'      => true,
            'estado'     => 'procesando',
        ]);

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $this->run_extraction($prepared);
        exit;
    }

    public function ajax_file_url() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $archivo_id = (int) ($_POST['archivo_id'] ?? 0);
        $page = max(1, (int) ($_POST['page'] ?? 1));
        global $wpdb;
        $archivo = $wpdb->get_row($wpdb->prepare(
            "SELECT id, r2_key_original FROM {$this->table('documentos_archivos')} WHERE id = %d",
            $archivo_id
        ));
        if (!$archivo || empty($archivo->r2_key_original)) {
            wp_send_json_error(['message' => 'Archivo no disponible']);
        }
        wp_send_json_success(['url' => $this->file_view_url((int) $archivo->id, $page)]);
    }

    /**
     * Sirve el PDF/imagen desde copia local (o la baja de R2 una vez).
     */
    public function ajax_file_stream() {
        $this->verify_stream_request();

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        ignore_user_abort(true);

        $archivo_id = (int) ($_GET['archivo_id'] ?? $_POST['archivo_id'] ?? 0);
        if ($archivo_id <= 0) {
            status_header(400);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Archivo inválido';
            exit;
        }

        global $wpdb;
        $archivo = $wpdb->get_row($wpdb->prepare(
            "SELECT archivo_hash, r2_key_original, nombre_original, mime
             FROM {$this->table('documentos_archivos')} WHERE id = %d",
            $archivo_id
        ), ARRAY_A);

        if (!$archivo) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Archivo no encontrado';
            exit;
        }

        $local = $this->ensure_local_archive($archivo);
        if (is_wp_error($local)) {
            status_header(502);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo $local->get_error_message();
            exit;
        }

        $mime = !empty($archivo['mime']) ? $archivo['mime'] : 'application/pdf';
        $filename = sanitize_file_name($archivo['nombre_original'] ?: 'documento.pdf');
        $streamed = $this->stream_local_file($local, $mime, $filename);
        if (is_wp_error($streamed)) {
            status_header(500);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo $streamed->get_error_message();
        }
        exit;
    }

    public function ajax_usage() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $table = $this->table('documentos_archivos');
        $month_start = gmdate('Y-m-01');

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS archivos,
                SUM(gemini_llamadas) AS llamadas,
                SUM(gemini_tokens_in) AS tokens_in,
                SUM(gemini_tokens_out) AS tokens_out,
                SUM(CASE WHEN gemini_reutilizado = 1 THEN 1 ELSE 0 END) AS reutilizados
             FROM {$table}
             WHERE created_at >= %s",
            $month_start
        ), ARRAY_A);

        wp_send_json_success($stats ?: [
            'archivos' => 0, 'llamadas' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'reutilizados' => 0,
        ]);
    }

    public function ajax_save_settings() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_settings') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $keys = [
            'scan_gemini_api_key', 'scan_gemini_model', 'scan_r2_access_key_id',
            'scan_r2_secret_access_key', 'scan_r2_endpoint', 'scan_r2_bucket',
            'scan_r2_prefix', 'scan_expected_receptor_rut',
        ];
        $sensitive = [
            'scan_gemini_api_key',
            'scan_r2_access_key_id',
            'scan_r2_secret_access_key',
        ];
        foreach ($keys as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $val = sanitize_text_field(wp_unslash($_POST[$key]));
            if ($val === '') {
                continue;
            }
            if (in_array($key, $sensitive, true) && riverso_is_masked_secret($val)) {
                continue;
            }
            if (strpos($key, 'secret') !== false || strpos($key, 'api_key') !== false) {
                if (preg_match('/^\*+$/', $val)) {
                    continue;
                }
            }
            riverso_set_setting($key, $val);
        }

        wp_send_json_success(['message' => 'Configuración de escaneos guardada']);
    }
}
