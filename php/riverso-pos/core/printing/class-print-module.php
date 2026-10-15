<?php
/**
 * Impresión directa ("Imprimir Ya!").
 *
 * El navegador (PC, celular) crea un trabajo en la cola; el hub local (Riverso Print Hub,
 * en el PC que tiene las impresoras) consulta la cola por HTTPS con su token, descarga el
 * PDF y lo imprime con el preset. Así no hay que abrir puertos ni instalar nada en los
 * celulares, y la emisión nunca depende de que la impresora esté disponible.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Print_Module {

    const CAP_MANAGE = 'riverso_manage_printing';
    const NONCE = 'riverso_print';
    const REST_NS = 'riverso/v1';

    /** Sin consultas del hub por más de esto, se considera desconectado. */
    const HUB_OFFLINE_AFTER = 25;
    /** Plazo para que un hub tome un trabajo; después vence y ya no se imprime. */
    const CLAIM_TIMEOUT = 20;
    /** Trabajo tomado sin noticias del hub por más de esto: se da por perdido. */
    const STUCK_AFTER = 150;
    /** Si el hub no reporta impresoras hace más de esto, su estado se ignora en el chequeo previo. */
    const PRINTERS_STALE_AFTER = 90;
    /** Intervalo de consulta que se indica al hub. */
    const POLL_MS = 2000;

    /** Primera versión del hub que sabe escanear. */
    const SCAN_MIN_HUB = '1.1.0';
    /** Primera versión del hub que convierte HTML a PDF (cotizaciones). */
    const HTML_MIN_HUB = '1.2.0';
    /**
     * Tipo de documento interno de las cotizaciones en el ruteo (no es un código SII; los DTE
     * usan los del SII, que no pasan de 2 dígitos).
     */
    const QUOTE_TYPE = 900;
    /** Primera versión del hub que genera un PDF y lo sube sin imprimir (correo de cotización). */
    const RENDER_MIN_HUB = '1.3.0';
    /** Tamaño máximo del PDF generado que se acepta. */
    const RENDER_MAX_BYTES = 15728640;
    /** El PDF generado se borra si no se usó en este plazo. */
    const RENDER_KEEP_SECONDS = 3600;
    /** Tope de páginas por escaneo: la lectura con IA procesa el archivo completo por página. */
    const SCAN_MAX_PAGES = 15;
    /** Tamaño máximo del PDF que acepta la lectura con IA. */
    const SCAN_MAX_BYTES = 15728640;
    /** El hub recomprime para no pasar de este tamaño (margen bajo SCAN_MAX_BYTES). */
    const SCAN_TARGET_BYTES = 12582912;
    const SCAN_DPI = 200;
    /** Escáneres de red que reporta cada hub: agent_id => [{name, adf, duplex, hojas}]. */
    const SCANNERS_OPTION = 'riverso_print_scanners';

    const MODES = ['driver', 'escpos'];
    const SCALE_MODES = ['ajustar', 'porcentaje', 'real'];
    const DUPLEX = ['no', 'largo', 'corto'];
    const ORIENTATIONS = ['auto', 'vertical', 'horizontal'];

    private static $instance = null;

    /** @var Riverso_Print_Repository */
    private $repo;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        require_once dirname(__FILE__) . '/class-print-repository.php';
        $this->repo = new Riverso_Print_Repository();
        $this->ensure_schema();
        $this->ensure_capability();
    }

    public function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_ajax_riverso_print_quick', [$this, 'ajax_quick']);
        add_action('wp_ajax_riverso_print_job', [$this, 'ajax_job']);
        add_action('wp_ajax_riverso_print_overview', [$this, 'ajax_overview']);
        add_action('wp_ajax_riverso_print_config', [$this, 'ajax_config']);
        add_action('wp_ajax_riverso_print_admin', [$this, 'ajax_admin']);
        add_action('wp_ajax_riverso_print_test', [$this, 'ajax_test']);
    }

    public function repo() {
        return $this->repo;
    }

    private function ensure_schema() {
        if (get_option('riverso_pos_phase73_printing') === '1') {
            return;
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_printing_schema')) {
            Riverso_POS_Activator::ensure_printing_schema();
        }
    }

    private function ensure_capability() {
        foreach (['administrator', 'riverso_admin'] as $role_key) {
            $role = get_role($role_key);
            if ($role && !$role->has_cap(self::CAP_MANAGE)) {
                $role->add_cap(self::CAP_MANAGE);
            }
        }
    }

    public function can_print() {
        return current_user_can('riverso_emit_dte')
            || current_user_can('manage_options')
            || current_user_can('manage_woocommerce');
    }

    public function can_manage() {
        return current_user_can(self::CAP_MANAGE) || current_user_can('manage_options');
    }

    /** Quien puede ver cotizaciones puede imprimirlas, aunque no emita documentos. */
    public function can_print_quotes() {
        return current_user_can('riverso_view_quotes')
            || current_user_can('riverso_create_quotes')
            || current_user_can('riverso_edit_quotes')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
    }

    /**
     * Configuración para el cliente JS (botón "Imprimir Ya!" y pantalla de configuración).
     *
     * @return array<string, mixed>
     */
    public function client_config() {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'configUrl' => home_url('/interno/facturacion/?vista=impresion'),
            'canManage' => $this->can_manage(),
            'quoteTypeId' => self::QUOTE_TYPE,
            'serverUrl' => untrailingslashit(home_url('/')),
            'actions' => [
                'quick' => 'riverso_print_quick',
                'job' => 'riverso_print_job',
                'overview' => 'riverso_print_overview',
                'config' => 'riverso_print_config',
                'admin' => 'riverso_print_admin',
                'test' => 'riverso_print_test',
            ],
        ];
    }

    // ───────────────────────── Encolar ─────────────────────────

    /**
     * Encola la impresión de un DTE emitido según el ruteo (o el preset indicado).
     * Nunca lanza: si algo falla devuelve un trabajo en estado error con el motivo.
     *
     * @param int   $dte_id
     * @param array $opts station_id, preset_id (forzar preset), origen.
     * @return array|null Trabajo presentado para la UI (null si el DTE no existe).
     */
    public function enqueue_dte($dte_id, array $opts = []) {
        $dte = $this->dte($dte_id);
        if (!$dte) {
            return null;
        }
        $type = (int) ($dte['document_type_id'] ?? 0);
        $titulo = $this->document_type_label($type) . ' N° ' . (string) ($dte['folio'] ?? '—');
        $job = $this->enqueue([
            'source_type' => 'dte',
            'source_id' => (int) $dte['id'],
            'document_type_id' => $type,
            'titulo' => $titulo,
            'station_id' => absint($opts['station_id'] ?? 0),
            'preset_id' => absint($opts['preset_id'] ?? 0),
            'origen' => $this->sanitize_origin($opts['origen'] ?? 'boton'),
        ]);
        return $job ? $this->present_job($job, true) : null;
    }

    /**
     * Encola una cotización de venta con la plantilla elegida (por familia o por producto, con o sin detalle).
     * El hub recibe el HTML del documento y lo convierte a PDF antes de imprimir.
     *
     * @param int    $quote_id
     * @param string $template family|product|family_detail|product_detail
     * @param array  $opts station_id, preset_id (forzar preset), origen.
     * @return array|null Trabajo presentado para la UI (null si la cotización no existe).
     */
    public function enqueue_quote($quote_id, $template, array $opts = []) {
        $quotes = $this->quote_module();
        $info = $quotes ? $quotes->print_info(absint($quote_id)) : null;
        if (!$info) {
            return null;
        }
        $job = $this->enqueue([
            'source_type' => 'cotizacion',
            'source_id' => (int) $info['id'],
            'document_type_id' => self::QUOTE_TYPE,
            'titulo' => $info['titulo'],
            'station_id' => absint($opts['station_id'] ?? 0),
            'preset_id' => absint($opts['preset_id'] ?? 0),
            'origen' => $this->sanitize_origin($opts['origen'] ?? 'boton'),
            'extra' => ['plantilla' => Riverso_Quote_Pdf::normalize_template($template)],
        ]);
        return $job ? $this->present_job($job, true) : null;
    }

    /**
     * @return Riverso_Customer_Quote_Module|null
     */
    private function quote_module() {
        if (!class_exists('Riverso_Customer_Quote_Module')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-module.php';
            if (!file_exists($path)) {
                return null;
            }
            require_once $path;
        }
        $module = Riverso_Customer_Quote_Module::get_instance();
        return method_exists($module, 'print_info') ? $module : null;
    }

    /**
     * @return array|null Fila del trabajo creado.
     */
    private function enqueue(array $req) {
        $station_id = absint($req['station_id'] ?? 0);
        if ($station_id > 0 && !$this->repo->get_station($station_id)) {
            $station_id = 0;
        }
        $preset = null;
        $fallback_id = null;
        if (!empty($req['preset_id'])) {
            $preset = $this->repo->get_preset($req['preset_id']);
            $code = $preset ? '' : 'preset_missing';
        } else {
            $route = $this->repo->find_route((int) $req['document_type_id'], $station_id);
            if ($route) {
                $preset = $this->repo->get_preset($route['preset_id']);
                $fallback_id = $route['fallback_preset_id'] ? (int) $route['fallback_preset_id'] : null;
                $code = $preset ? '' : 'preset_missing';
            } else {
                $code = 'no_route';
            }
        }

        $data = [
            'origen' => $req['origen'],
            'source_type' => $req['source_type'],
            'source_id' => (int) $req['source_id'],
            'document_type_id' => (int) $req['document_type_id'],
            'titulo' => mb_substr((string) $req['titulo'], 0, 150),
            'station_id' => $station_id,
            'preset_id' => $preset ? (int) $preset['id'] : null,
            'fallback_preset_id' => $fallback_id,
            'requested_by' => get_current_user_id() ?: null,
        ];

        if ($preset) {
            $health = $this->target_health($preset);
            $data['agent_id'] = $health['agent'] ? (int) $health['agent']['id'] : null;
            $data['printer_id'] = $health['printer'] ? (int) $health['printer']['id'] : null;
            if ($health['printer']) {
                $data['ajustes'] = wp_json_encode(array_merge($this->build_settings($preset, $health['printer']), $req['extra'] ?? []));
            }
            $code = $health['ok'] ? '' : $health['code'];
            if (!$health['ok']) {
                $data['error_msg'] = mb_substr((string) $health['detail'], 0, 255);
            }
        }

        if ($code === '') {
            $data['estado'] = 'pendiente';
            $data['expires_at'] = Riverso_Print_Repository::at(self::CLAIM_TIMEOUT);
        } else {
            $data['estado'] = 'error';
            $data['error_code'] = $code;
            $data['finished_at'] = Riverso_Print_Repository::now();
        }

        $id = $this->repo->create_job($data);
        return $id ? $this->repo->get_job($id) : null;
    }

    /**
     * ¿Se puede imprimir con este preset ahora? Revisa hub, impresora y su último estado.
     *
     * @return array{ok: bool, code: string, detail: string, agent: ?array, printer: ?array}
     */
    private function target_health(array $preset) {
        $out = ['ok' => false, 'code' => '', 'detail' => '', 'agent' => null, 'printer' => null];
        if ((int) $preset['activo'] !== 1) {
            $out['code'] = 'preset_disabled';
            return $out;
        }
        $printer = $this->repo->get_printer($preset['printer_id']);
        if (!$printer) {
            $out['code'] = 'printer_missing';
            return $out;
        }
        $out['printer'] = $printer;
        $agent = $this->repo->get_agent($printer['agent_id']);
        if (!$agent || $agent['estado'] !== 'activo') {
            $out['code'] = 'hub_missing';
            return $out;
        }
        $out['agent'] = $agent;
        if ((int) $printer['activo'] !== 1) {
            $out['code'] = 'printer_disabled';
            return $out;
        }
        if (!$this->agent_online($agent)) {
            $out['code'] = 'hub_offline';
            return $out;
        }
        $fresh = Riverso_Print_Repository::age($agent['printers_at']);
        if ($fresh !== null && $fresh <= self::PRINTERS_STALE_AFTER) {
            if ((int) $printer['presente'] !== 1) {
                $out['code'] = 'printer_missing';
                return $out;
            }
            if ($printer['estado'] === 'offline') {
                $out['code'] = 'printer_offline';
                $out['detail'] = (string) $printer['estado_detalle'];
                return $out;
            }
            if ($printer['estado'] === 'error') {
                $out['code'] = 'printer_error';
                $out['detail'] = (string) $printer['estado_detalle'];
                return $out;
            }
        }
        $out['ok'] = true;
        return $out;
    }

    private function agent_online(array $agent) {
        $age = Riverso_Print_Repository::age($agent['last_seen_at']);
        return $age !== null && $age <= self::HUB_OFFLINE_AFTER;
    }

    /**
     * Ajustes que viajan con el trabajo (copia del preset al momento de encolar).
     */
    private function build_settings(array $preset, array $printer) {
        $mode = in_array($preset['modo'], self::MODES, true) ? $preset['modo'] : 'driver';
        return [
            'printer' => (string) $printer['system_name'],
            'printer_label' => $this->printer_label($printer),
            'preset' => (string) $preset['nombre'],
            'modo' => $mode,
            'papel' => $preset['papel'] !== null && $preset['papel'] !== '' ? (string) $preset['papel'] : null,
            'escala_modo' => in_array($preset['escala_modo'], self::SCALE_MODES, true) ? $preset['escala_modo'] : 'ajustar',
            'escala_pct' => (int) $preset['escala_pct'],
            'copias' => max(1, (int) $preset['copias']),
            'color' => (int) $preset['color'] === 1,
            'duplex' => in_array($preset['duplex'], self::DUPLEX, true) ? $preset['duplex'] : 'no',
            'orientacion' => in_array($preset['orientacion'], self::ORIENTATIONS, true) ? $preset['orientacion'] : 'auto',
            'ancho_puntos' => (int) $preset['ancho_puntos'],
            'avance_mm' => (int) $preset['avance_mm'],
            'timeout_s' => $mode === 'escpos' ? 30 : 60,
        ];
    }

    // ───────────────────────── Presentación ─────────────────────────

    const JOB_STATES = [
        'pendiente' => 'En cola',
        'tomado' => 'Preparando',
        'imprimiendo' => 'Imprimiendo',
        'impreso' => 'Impreso',
        'error' => 'No se imprimió',
        'vencido' => 'No se imprimió',
        'cancelado' => 'Cancelado',
    ];

    /**
     * @param array $job
     * @param bool  $with_alternatives Incluir presets alternativos si falló.
     */
    public function present_job(array $job, $with_alternatives = false) {
        $estado = (string) $job['estado'];
        $final = in_array($estado, ['impreso', 'error', 'vencido', 'cancelado'], true);
        $preset = $job['preset_id'] ? $this->repo->get_preset($job['preset_id']) : null;
        $printer = $job['printer_id'] ? $this->repo->get_printer($job['printer_id']) : null;
        $agent = $job['agent_id'] ? $this->repo->get_agent($job['agent_id']) : null;
        $out = [
            'id' => (int) $job['id'],
            'estado' => $estado,
            'estado_label' => self::JOB_STATES[$estado] ?? $estado,
            'final' => $final,
            'ok' => $estado === 'impreso',
            'titulo' => (string) $job['titulo'],
            'origen' => (string) $job['origen'],
            'source_id' => (int) $job['source_id'],
            'document_type_id' => (int) $job['document_type_id'],
            'preset_id' => $job['preset_id'] ? (int) $job['preset_id'] : 0,
            'preset' => $preset ? (string) $preset['nombre'] : '',
            'printer' => $printer ? $this->printer_label($printer) : '',
            'hub' => $agent ? (string) $agent['nombre'] : '',
            'detalle' => (string) ($job['detalle'] ?? ''),
            'error_code' => (string) ($job['error_code'] ?? ''),
            'message' => '',
            'created_at' => $this->local_time($job['created_at']),
            'finished_at' => $this->local_time($job['finished_at']),
            'alternatives' => [],
        ];
        if ($estado === 'error' || $estado === 'vencido') {
            $out['message'] = $this->error_message($out['error_code'], [
                'doc' => $this->document_type_label((int) $job['document_type_id']),
                'printer' => $out['printer'],
                'hub' => $out['hub'],
                'hub_seen' => $agent ? $this->local_time($agent['last_seen_at']) : '',
                'hub_online' => $agent ? $this->agent_online($agent) : false,
                'detail' => (string) ($job['error_msg'] ?? ''),
            ]);
            if ($with_alternatives) {
                $out['alternatives'] = $this->alternatives($job);
            }
        }
        return $out;
    }

    /**
     * Presets disponibles para reintentar en otra impresora (la alternativa del ruteo primero).
     */
    private function alternatives(array $job) {
        $ids = [];
        if (!empty($job['fallback_preset_id'])) {
            $ids[] = (int) $job['fallback_preset_id'];
        }
        foreach ($this->repo->list_presets() as $p) {
            $ids[] = (int) $p['id'];
        }
        $out = [];
        foreach (array_unique($ids) as $id) {
            if ($id === (int) $job['preset_id']) {
                continue;
            }
            $preset = $this->repo->get_preset($id);
            if (!$preset || (int) $preset['activo'] !== 1) {
                continue;
            }
            $health = $this->target_health($preset);
            if (!$health['ok']) {
                continue;
            }
            $out[] = [
                'preset_id' => $id,
                'label' => (string) $preset['nombre'],
                'printer' => $this->printer_label($health['printer']),
                'recommended' => $id === (int) $job['fallback_preset_id'],
            ];
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    private function error_message($code, array $c) {
        $printer = $c['printer'] !== '' ? '«' . $c['printer'] . '»' : 'la impresora';
        $hub = $c['hub'] !== '' ? '«' . $c['hub'] . '»' : 'de impresión';
        $detail = trim((string) $c['detail']);
        // El hub manda el detalle con mayúscula inicial ("Sin papel"); dentro de una frase va en minúscula.
        $detail_lc = $detail !== '' ? mb_strtolower(mb_substr($detail, 0, 1)) . mb_substr($detail, 1) : '';
        switch ($code) {
            case 'no_route':
                return 'No hay impresora configurada para ' . $c['doc'] . '. Configúrala en Facturación → Impresión.';
            case 'preset_missing':
                return 'El preset de impresión ya no existe. Revisa Facturación → Impresión.';
            case 'preset_disabled':
                return 'El preset de impresión está desactivado.';
            case 'printer_disabled':
                return 'La impresora ' . $printer . ' está desactivada en la configuración.';
            case 'hub_missing':
                return 'El hub de impresión de este preset fue eliminado. Revisa Facturación → Impresión.';
            case 'printer_missing':
                return 'La impresora ' . $printer . ' ya no aparece en el hub ' . $hub . '. ¿Se desinstaló o se renombró en Windows?';
            case 'hub_offline':
                return 'El hub ' . $hub . ' no responde'
                    . ($c['hub_seen'] !== '' ? ' desde ' . $c['hub_seen'] : '')
                    . '. Revisa que el PC esté encendido, con internet y con Riverso Print Hub abierto (ícono junto al reloj).';
            case 'printer_offline':
                return 'La impresora ' . $printer . ' está apagada o desconectada' . ($detail !== '' ? ' (' . $detail_lc . ')' : '') . '.';
            case 'printer_error':
                return 'La impresora ' . $printer . ' tiene un problema' . ($detail !== '' ? ': ' . $detail_lc : '') . '.';
            case 'claim_timeout':
                if (!$c['hub_online']) {
                    return 'El hub ' . $hub . ' no responde'
                        . ($c['hub_seen'] !== '' ? ' desde ' . $c['hub_seen'] : '')
                        . '. Revisa que el PC esté encendido y con Riverso Print Hub abierto. El trabajo se descartó y no saldrá después.';
                }
                return 'El hub ' . $hub . ' no tomó el trabajo a tiempo. Se descartó y no saldrá después.';
            case 'hub_lost':
                return 'El hub ' . $hub . ' dejó de responder mientras imprimía. Revisa la impresora antes de reintentar: puede que el documento haya salido.';
            case 'print_timeout':
                return 'La impresora ' . $printer . ' no respondió (¿apagada, sin papel o con la tapa abierta?). El trabajo se canceló para que no salga después.';
            case 'pdf_unavailable':
                return 'FACTO todavía no entrega el PDF del documento' . ($detail !== '' ? ' (' . $detail . ')' : '') . '. Reintenta en unos segundos.';
            case 'paper_missing':
                return 'El papel ' . ($detail !== '' ? '«' . $detail . '» ' : '') . 'no existe en la impresora ' . $printer . '. Revisa el preset.';
            case 'source_missing':
                return 'El documento ya no existe.';
            case 'no_hub':
                return 'No hay un hub de impresión en línea para generar el PDF. Revisa que PC1 esté encendido y con Riverso Print Hub abierto.';
            case 'hub_old':
                return 'El hub ' . $hub . ' es una versión antigua y no puede generar el PDF. Actualízalo a la ' . self::RENDER_MIN_HUB . ' o superior.';
            case 'render_failed':
                return 'El hub no pudo generar el PDF del documento' . ($detail !== '' ? ': ' . $detail_lc : '') . '. Usa «Imprimir normal» mientras tanto.';
            case 'print_failed':
                return 'No se pudo imprimir en ' . $printer . ($detail !== '' ? ': ' . $detail : '.');
            default:
                return $detail !== '' ? $detail : 'No se pudo imprimir.';
        }
    }

    private function printer_label(array $printer) {
        $alias = trim((string) ($printer['alias'] ?? ''));
        return $alias !== '' ? $alias : (string) $printer['system_name'];
    }

    private function local_time($utc, $format = 'H:i:s') {
        if (!$utc) {
            return '';
        }
        $age = Riverso_Print_Repository::age($utc);
        if ($age !== null && $age > 20 * 3600) {
            $format = 'd-m-Y H:i';
        }
        return get_date_from_gmt($utc, $format);
    }

    /**
     * @return array<int, string>
     */
    public function document_types() {
        return [
            37 => 'Boleta electrónica',
            2 => 'Factura electrónica',
            41 => 'Boleta exenta electrónica',
            32 => 'Factura exenta electrónica',
            self::QUOTE_TYPE => 'Cotización',
        ];
    }

    private function document_type_label($type) {
        $types = $this->document_types();
        return $types[(int) $type] ?? ('Documento ' . (int) $type);
    }

    private function sanitize_origin($origin) {
        $origin = sanitize_key((string) $origin);
        return in_array($origin, ['boton', 'emision', 'reintento', 'alternativa', 'prueba'], true) ? $origin : 'boton';
    }

    /**
     * @return array|null Fila de riverso_dte_issued.
     */
    private function dte($dte_id) {
        if (!class_exists('Riverso_Dte_Issued_Repository')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'sales/billing/class-dte-issued-repository.php';
            if (!file_exists($path)) {
                return null;
            }
            require_once $path;
        }
        $repo = new Riverso_Dte_Issued_Repository();
        $row = $repo->get(absint($dte_id));
        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    // ───────────────────────── Estado / configuración ─────────────────────────

    /**
     * Estado de un preset para la UI.
     */
    private function preset_status(array $preset) {
        $health = $this->target_health($preset);
        return [
            'ok' => $health['ok'],
            'code' => $health['code'],
            'message' => $health['ok'] ? '' : $this->error_message($health['code'], [
                'doc' => '',
                'printer' => $health['printer'] ? $this->printer_label($health['printer']) : '',
                'hub' => $health['agent'] ? (string) $health['agent']['nombre'] : '',
                'hub_seen' => $health['agent'] ? $this->local_time($health['agent']['last_seen_at']) : '',
                'hub_online' => $health['agent'] ? $this->agent_online($health['agent']) : false,
                'detail' => $health['detail'],
            ]),
            'printer' => $health['printer'] ? $this->printer_label($health['printer']) : '',
        ];
    }

    /**
     * Qué pasará al presionar "Imprimir Ya!" para un tipo de documento en esta estación.
     */
    public function overview($document_type_id, $station_id) {
        $route = $this->repo->find_route((int) $document_type_id, absint($station_id));
        $presets = [];
        foreach ($this->repo->list_presets() as $p) {
            if ((int) $p['activo'] !== 1) {
                continue;
            }
            $presets[] = array_merge(['id' => (int) $p['id'], 'nombre' => (string) $p['nombre']], $this->preset_status($p));
        }
        $main = null;
        $fallback = null;
        if ($route) {
            $preset = $this->repo->get_preset($route['preset_id']);
            if ($preset) {
                $main = array_merge(['id' => (int) $preset['id'], 'nombre' => (string) $preset['nombre']], $this->preset_status($preset));
            }
            $fb = $route['fallback_preset_id'] ? $this->repo->get_preset($route['fallback_preset_id']) : null;
            if ($fb) {
                $fallback = array_merge(['id' => (int) $fb['id'], 'nombre' => (string) $fb['nombre']], $this->preset_status($fb));
            }
        }
        $station = $station_id ? $this->repo->get_station($station_id) : null;
        return [
            'route' => $main,
            'fallback' => $fallback,
            'presets' => $presets,
            'station' => $station ? ['id' => (int) $station['id'], 'nombre' => (string) $station['nombre']] : null,
            'noRouteMessage' => $route ? '' : $this->error_message('no_route', ['doc' => $this->document_type_label($document_type_id), 'printer' => '', 'hub' => '', 'hub_seen' => '', 'hub_online' => false, 'detail' => '']),
        ];
    }

    public function config_payload() {
        $this->repo->expire_jobs(self::STUCK_AFTER);
        $agents = [];
        $agent_names = [];
        foreach ($this->repo->list_agents() as $a) {
            $agent_names[(int) $a['id']] = (string) $a['nombre'];
            $agents[] = [
                'id' => (int) $a['id'],
                'nombre' => (string) $a['nombre'],
                'online' => $this->agent_online($a),
                'last_seen' => $this->local_time($a['last_seen_at'], 'd-m-Y H:i:s'),
                'seconds_ago' => Riverso_Print_Repository::age($a['last_seen_at']),
                'hostname' => (string) $a['hostname'],
                'version' => (string) $a['version'],
                'last_ip' => (string) $a['last_ip'],
                'token_hint' => (string) $a['token_hint'],
            ];
        }
        $printers = [];
        $printer_labels = [];
        foreach ($this->repo->list_printers() as $p) {
            $papers = json_decode((string) $p['papeles'], true);
            $printer_labels[(int) $p['id']] = $this->printer_label($p);
            $printers[] = [
                'id' => (int) $p['id'],
                'agent_id' => (int) $p['agent_id'],
                'agent' => $agent_names[(int) $p['agent_id']] ?? '',
                'system_name' => (string) $p['system_name'],
                'alias' => (string) $p['alias'],
                'label' => $this->printer_label($p),
                'activo' => (int) $p['activo'] === 1,
                'es_virtual' => (int) $p['es_virtual'] === 1,
                'presente' => (int) $p['presente'] === 1,
                'es_predeterminada' => (int) $p['es_predeterminada'] === 1,
                'driver_name' => (string) $p['driver_name'],
                'port_name' => (string) $p['port_name'],
                'host' => (string) $p['host'],
                'host_detectado' => (string) $p['host_detectado'],
                'estado' => (string) $p['estado'],
                'estado_detalle' => (string) $p['estado_detalle'],
                'papeles' => is_array($papers) ? $papers : [],
            ];
        }
        $presets = [];
        $preset_names = [];
        foreach ($this->repo->list_presets() as $p) {
            $preset_names[(int) $p['id']] = (string) $p['nombre'];
            $presets[] = array_merge([
                'id' => (int) $p['id'],
                'nombre' => (string) $p['nombre'],
                'printer_id' => (int) $p['printer_id'],
                'printer_label' => $printer_labels[(int) $p['printer_id']] ?? '(impresora eliminada)',
                'modo' => (string) $p['modo'],
                'papel' => (string) $p['papel'],
                'escala_modo' => (string) $p['escala_modo'],
                'escala_pct' => (int) $p['escala_pct'],
                'copias' => (int) $p['copias'],
                'color' => (int) $p['color'] === 1,
                'duplex' => (string) $p['duplex'],
                'orientacion' => (string) $p['orientacion'],
                'ancho_puntos' => (int) $p['ancho_puntos'],
                'avance_mm' => (int) $p['avance_mm'],
                'activo' => (int) $p['activo'] === 1,
            ], ['status' => $this->preset_status($p)]);
        }
        $stations = [];
        $station_names = [];
        foreach ($this->repo->list_stations() as $s) {
            $station_names[(int) $s['id']] = (string) $s['nombre'];
            $stations[] = ['id' => (int) $s['id'], 'nombre' => (string) $s['nombre']];
        }
        $routes = [];
        foreach ($this->repo->list_routes() as $r) {
            $routes[] = [
                'id' => (int) $r['id'],
                'document_type_id' => (int) $r['document_type_id'],
                'document_type' => $this->document_type_label((int) $r['document_type_id']),
                'station_id' => (int) $r['station_id'],
                'station' => (int) $r['station_id'] > 0 ? ($station_names[(int) $r['station_id']] ?? '(estación eliminada)') : 'Todas',
                'preset_id' => (int) $r['preset_id'],
                'preset' => $preset_names[(int) $r['preset_id']] ?? '(preset eliminado)',
                'fallback_preset_id' => $r['fallback_preset_id'] ? (int) $r['fallback_preset_id'] : 0,
                'fallback' => $r['fallback_preset_id'] ? ($preset_names[(int) $r['fallback_preset_id']] ?? '(preset eliminado)') : '',
            ];
        }
        $jobs = [];
        foreach ($this->repo->list_recent_jobs(25) as $j) {
            $jobs[] = $this->present_job($j, false);
        }
        $types = [];
        foreach ($this->document_types() as $id => $label) {
            $types[] = ['id' => $id, 'label' => $label];
        }
        return [
            'agents' => $agents,
            'printers' => $printers,
            'presets' => $presets,
            'stations' => $stations,
            'routes' => $routes,
            'jobs' => $jobs,
            'documentTypes' => $types,
            'canManage' => $this->can_manage(),
            'serverUrl' => untrailingslashit(home_url('/')),
        ];
    }

    // ───────────────────────── AJAX ─────────────────────────

    /**
     * @param bool $manage    Exige permiso de configuración.
     * @param bool $quotes_ok También basta con poder ver cotizaciones (imprimirlas y seguir el trabajo).
     */
    private function authorize($manage = false, $quotes_ok = false) {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_send_json_error(['message' => 'Sesión inválida. Recarga la página.'], 403);
        }
        if (!$this->can_print() && !$this->can_manage() && !($quotes_ok && $this->can_print_quotes())) {
            wp_send_json_error(['message' => 'Sin permiso para imprimir documentos.'], 403);
        }
        if ($manage && !$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permiso para configurar la impresión.'], 403);
        }
    }

    private function post_int($key) {
        return isset($_POST[$key]) ? absint($_POST[$key]) : 0;
    }

    private function post_text($key, $max = 191) {
        $v = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        return mb_substr(trim($v), 0, $max);
    }

    public function ajax_quick() {
        $this->authorize(false, true);
        $opts = [
            'station_id' => $this->post_int('station_id'),
            'preset_id' => $this->post_int('preset_id'),
            'origen' => $this->post_text('origen', 20),
        ];
        $quote_id = $this->post_int('quote_id');
        if ($quote_id > 0) {
            $job = $this->enqueue_quote($quote_id, $this->post_text('template', 20), $opts);
            if (!$job) {
                wp_send_json_error(['message' => 'Cotización no encontrada.']);
            }
            wp_send_json_success(['job' => $job]);
        }
        if (!$this->can_print() && !$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permiso para imprimir documentos.'], 403);
        }
        $job = $this->enqueue_dte($this->post_int('dte_id'), $opts);
        if (!$job) {
            wp_send_json_error(['message' => 'Documento no encontrado.']);
        }
        wp_send_json_success(['job' => $job]);
    }

    public function ajax_job() {
        $this->authorize(false, true);
        $this->repo->expire_jobs(self::STUCK_AFTER);
        $job = $this->repo->get_job($this->post_int('job_id'));
        if (!$job) {
            wp_send_json_error(['message' => 'Trabajo de impresión no encontrado.']);
        }
        wp_send_json_success(['job' => $this->present_job($job, true)]);
    }

    public function ajax_overview() {
        $this->authorize(false, true);
        wp_send_json_success($this->overview($this->post_int('document_type_id'), $this->post_int('station_id')));
    }

    public function ajax_config() {
        $this->authorize();
        wp_send_json_success($this->config_payload());
    }

    public function ajax_test() {
        $this->authorize(true);
        $preset = $this->repo->get_preset($this->post_int('preset_id'));
        if (!$preset) {
            wp_send_json_error(['message' => 'Preset no encontrado.']);
        }
        $job = $this->enqueue([
            'source_type' => 'prueba',
            'source_id' => 0,
            'document_type_id' => 0,
            'titulo' => 'Prueba · ' . $preset['nombre'],
            'station_id' => 0,
            'preset_id' => (int) $preset['id'],
            'origen' => 'prueba',
        ]);
        if (!$job) {
            wp_send_json_error(['message' => 'No se pudo crear el trabajo de prueba.']);
        }
        wp_send_json_success(['job' => $this->present_job($job, false)]);
    }

    /**
     * Operaciones de configuración. Responde siempre con la configuración actualizada.
     */
    public function ajax_admin() {
        $this->authorize(true);
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        $extra = [];
        switch ($op) {
            case 'agent_create':
                $nombre = $this->post_text('nombre', 100);
                if ($nombre === '') {
                    wp_send_json_error(['message' => 'Indica un nombre para el hub (ej: PC1 Caja).']);
                }
                $created = $this->repo->create_agent($nombre, get_current_user_id());
                if (!$created) {
                    wp_send_json_error(['message' => 'No se pudo crear el hub.']);
                }
                $extra['token'] = $created['token'];
                $extra['agent_id'] = (int) $created['agent']['id'];
                break;
            case 'agent_rename':
                $nombre = $this->post_text('nombre', 100);
                if ($nombre === '') {
                    wp_send_json_error(['message' => 'El nombre no puede quedar vacío.']);
                }
                $this->repo->rename_agent($this->post_int('id'), $nombre);
                break;
            case 'agent_token':
                $token = $this->repo->rotate_agent_token($this->post_int('id'));
                if (!$token) {
                    wp_send_json_error(['message' => 'No se pudo generar el token.']);
                }
                $extra['token'] = $token;
                $extra['agent_id'] = $this->post_int('id');
                break;
            case 'agent_revoke':
                $this->repo->revoke_agent($this->post_int('id'));
                break;
            case 'printer_save':
                $id = $this->post_int('id');
                if (!$this->repo->get_printer($id)) {
                    wp_send_json_error(['message' => 'Impresora no encontrada.']);
                }
                $host = $this->post_text('host', 100);
                if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) {
                    wp_send_json_error(['message' => 'IP o nombre de equipo no válido.']);
                }
                $this->repo->update_printer($id, [
                    'alias' => $this->post_text('alias', 100) ?: null,
                    'activo' => !empty($_POST['activo']) ? 1 : 0,
                    'host' => $host !== '' ? $host : null,
                ]);
                break;
            case 'preset_save':
                $data = $this->validate_preset();
                $id = $this->repo->save_preset($data);
                if (!$id) {
                    wp_send_json_error(['message' => 'No se pudo guardar el preset.']);
                }
                $extra['preset_id'] = $id;
                break;
            case 'preset_delete':
                $id = $this->post_int('id');
                if ($this->repo->preset_in_use($id)) {
                    wp_send_json_error(['message' => 'El preset se usa en el ruteo. Quítalo de las reglas antes de eliminarlo.']);
                }
                $this->repo->delete_preset($id);
                break;
            case 'station_save':
                $nombre = $this->post_text('nombre', 100);
                if ($nombre === '') {
                    wp_send_json_error(['message' => 'Indica un nombre para la estación (ej: Caja 1).']);
                }
                $extra['station_id'] = $this->repo->save_station($this->post_int('id'), $nombre);
                break;
            case 'station_delete':
                $this->repo->delete_station($this->post_int('id'));
                break;
            case 'route_save':
                $type = $this->post_int('document_type_id');
                if (!isset($this->document_types()[$type])) {
                    wp_send_json_error(['message' => 'Tipo de documento no soportado.']);
                }
                $station_id = $this->post_int('station_id');
                if ($station_id > 0 && !$this->repo->get_station($station_id)) {
                    wp_send_json_error(['message' => 'Estación no encontrada.']);
                }
                $preset_id = $this->post_int('preset_id');
                if (!$this->repo->get_preset($preset_id)) {
                    wp_send_json_error(['message' => 'Elige el preset principal.']);
                }
                $fallback = $this->post_int('fallback_preset_id');
                if ($fallback > 0 && ($fallback === $preset_id || !$this->repo->get_preset($fallback))) {
                    wp_send_json_error(['message' => 'La alternativa debe ser un preset distinto al principal.']);
                }
                $this->repo->save_route($type, $station_id, $preset_id, $fallback ?: null);
                break;
            case 'route_delete':
                $this->repo->delete_route($this->post_int('id'));
                break;
            default:
                wp_send_json_error(['message' => 'Operación no válida.']);
        }
        wp_send_json_success(array_merge($extra, ['config' => $this->config_payload()]));
    }

    private function validate_preset() {
        $nombre = $this->post_text('nombre', 100);
        if ($nombre === '') {
            wp_send_json_error(['message' => 'Indica un nombre para el preset (ej: Boleta térmica).']);
        }
        $printer_id = $this->post_int('printer_id');
        if (!$this->repo->get_printer($printer_id)) {
            wp_send_json_error(['message' => 'Elige una impresora.']);
        }
        $pick = function ($key, array $allowed, $default) {
            $v = sanitize_key((string) ($_POST[$key] ?? ''));
            return in_array($v, $allowed, true) ? $v : $default;
        };
        $clamp = function ($key, $min, $max, $default) {
            if (!isset($_POST[$key]) || $_POST[$key] === '') {
                return $default;
            }
            return max($min, min($max, (int) $_POST[$key]));
        };
        $papel = isset($_POST['papel']) ? trim(sanitize_text_field(wp_unslash($_POST['papel']))) : '';
        return [
            'id' => $this->post_int('id'),
            'nombre' => $nombre,
            'printer_id' => $printer_id,
            'modo' => $pick('modo', self::MODES, 'driver'),
            'papel' => $papel !== '' ? mb_substr($papel, 0, 191) : null,
            'escala_modo' => $pick('escala_modo', self::SCALE_MODES, 'ajustar'),
            'escala_pct' => $clamp('escala_pct', 10, 400, 100),
            'copias' => $clamp('copias', 1, 20, 1),
            'color' => !empty($_POST['color']) ? 1 : 0,
            'duplex' => $pick('duplex', self::DUPLEX, 'no'),
            'orientacion' => $pick('orientacion', self::ORIENTATIONS, 'auto'),
            'ancho_puntos' => $clamp('ancho_puntos', 128, 1024, 384),
            'avance_mm' => $clamp('avance_mm', 0, 100, 0),
            'activo' => !empty($_POST['activo']) ? 1 : 0,
        ];
    }

    // ───────────────────────── Escaneo ─────────────────────────
    // El hub ordena el escaneo a la multifuncional por la red local (eSCL), arma un PDF y lo
    // sube; desde ahí sigue el flujo de Escaneos (lectura con IA). Usa la misma cola que la
    // impresión: source_type «escaneo», y al terminar source_id queda con el archivo subido.

    private function hub_can_scan($version) {
        return version_compare((string) $version, self::SCAN_MIN_HUB, '>=');
    }

    private function hub_can_html($version) {
        return version_compare((string) $version, self::HTML_MIN_HUB, '>=');
    }

    private function hub_can_render($version) {
        return version_compare((string) $version, self::RENDER_MIN_HUB, '>=');
    }

    // ───────────────────────── PDF para correo ─────────────────────────
    // El servidor no sabe convertir HTML a PDF: un hub en línea genera el PDF de la cotización
    // (igual que «Imprimir Ya!») y lo sube; el correo lo adjunta. source_type «cotiz_pdf».

    /**
     * Hub que puede generar el PDF ahora (cualquiera en línea y actualizado).
     *
     * @return array{ok: bool, code: string, agent: ?array}
     */
    private function render_target() {
        $old = null;
        foreach ($this->repo->list_agents() as $agent) {
            if (!$this->agent_online($agent)) {
                continue;
            }
            if (!$this->hub_can_render($agent['version'])) {
                $old = $old ?: $agent;
                continue;
            }
            return ['ok' => true, 'code' => '', 'agent' => $agent];
        }
        return ['ok' => false, 'code' => $old ? 'hub_old' : 'no_hub', 'agent' => $old];
    }

    /**
     * ¿Se puede adjuntar el PDF? Para elegir entre adjunto y enlace al abrir el correo.
     *
     * @return array{ok: bool, message: string, hub: string}
     */
    public function render_overview() {
        $t = $this->render_target();
        $hub = $t['agent'] ? (string) $t['agent']['nombre'] : '';
        return [
            'ok' => $t['ok'],
            'message' => $t['ok'] ? '' : $this->error_message($t['code'], [
                'doc' => '', 'printer' => '', 'hub' => $hub, 'hub_seen' => '', 'hub_online' => true, 'detail' => '',
            ]),
            'hub' => $hub,
        ];
    }

    /**
     * Encola la generación del PDF de una cotización para adjuntarlo a un correo.
     *
     * @return array|null Trabajo presentado (null si la cotización no existe).
     */
    public function enqueue_quote_pdf($quote_id, $template) {
        $quotes = $this->quote_module();
        $info = $quotes ? $quotes->print_info(absint($quote_id)) : null;
        if (!$info) {
            return null;
        }
        $t = $this->render_target();
        $data = [
            'origen' => 'boton',
            'source_type' => 'cotiz_pdf',
            'source_id' => (int) $info['id'],
            'document_type_id' => self::QUOTE_TYPE,
            'titulo' => mb_substr($info['titulo'], 0, 150),
            'station_id' => 0,
            'agent_id' => $t['agent'] ? (int) $t['agent']['id'] : null,
            'ajustes' => wp_json_encode(['plantilla' => Riverso_Quote_Pdf::normalize_template($template)]),
            'requested_by' => get_current_user_id() ?: null,
        ];
        if ($t['ok']) {
            $data['estado'] = 'pendiente';
            $data['expires_at'] = Riverso_Print_Repository::at(self::CLAIM_TIMEOUT);
        } else {
            $data['estado'] = 'error';
            $data['error_code'] = $t['code'];
            $data['finished_at'] = Riverso_Print_Repository::now();
        }
        $id = $this->repo->create_job($data);
        $job = $id ? $this->repo->get_job($id) : null;
        return $job ? $this->present_job($job) : null;
    }

    /**
     * Archivo del PDF generado, solo para quien lo pidió y para esa cotización.
     *
     * @return string|null Ruta local.
     */
    public function render_file($job_id, $quote_id) {
        $job = $this->repo->get_job(absint($job_id));
        if (!$job || $job['source_type'] !== 'cotiz_pdf' || $job['estado'] !== 'impreso'
            || (int) $job['source_id'] !== (int) $quote_id
            || (int) $job['requested_by'] !== get_current_user_id()) {
            return null;
        }
        $settings = json_decode((string) $job['ajustes'], true);
        $name = is_array($settings) ? basename((string) ($settings['archivo'] ?? '')) : '';
        $path = $name !== '' ? $this->render_dir() . $name : '';
        return $path !== '' && is_file($path) ? $path : null;
    }

    /** Borra el PDF generado una vez enviado el correo. */
    public function release_render_file($job_id, $quote_id) {
        $path = $this->render_file($job_id, $quote_id);
        if ($path) {
            @unlink($path);
        }
    }

    /**
     * Carpeta privada de los PDF generados (se borran al enviar o a la hora).
     */
    private function render_dir() {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'riverso-tmp/cotizaciones-pdf/';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Con nginx delante (Plesk) el .htaccess puede no aplicarse: por eso el nombre del
            // archivo es aleatorio y el PDF dura poco.
            @file_put_contents($dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . 'index.php', "<?php\n// Silencio.\n");
        }
        return $dir;
    }

    private function clean_render_dir($dir) {
        foreach ((array) glob($dir . '*.pdf') as $file) {
            if (is_string($file) && is_file($file) && time() - (int) filemtime($file) > self::RENDER_KEEP_SECONDS) {
                @unlink($file);
            }
        }
    }

    public function rest_render_upload(WP_REST_Request $request) {
        $agent = $this->rest_agent($request);
        if ($agent instanceof WP_REST_Response) {
            return $agent;
        }
        $job = $this->rest_job($request, $agent);
        if ($job instanceof WP_REST_Response) {
            return $job;
        }
        $active = ['tomado', 'imprimiendo'];
        if ($job['source_type'] !== 'cotiz_pdf' || !in_array($job['estado'], $active, true)) {
            return new WP_REST_Response(['ok' => false, 'code' => 'job_closed', 'message' => 'El trabajo ya no está activo.'], 410);
        }
        $job_id = (int) $job['id'];
        $reject = function ($code, $message, $status) use ($job_id, $active) {
            $this->repo->transition_job($job_id, $active, [
                'estado' => 'error',
                'error_code' => $code,
                'error_msg' => mb_substr($message, 0, 255),
                'finished_at' => Riverso_Print_Repository::now(),
            ]);
            return new WP_REST_Response(['ok' => false, 'code' => $code, 'message' => $message], $status);
        };
        $pdf = (string) $request->get_body();
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return $reject('upload_failed', 'el archivo no llegó completo', 400);
        }
        if (strlen($pdf) > self::RENDER_MAX_BYTES) {
            return $reject('too_large', 'el PDF es demasiado grande', 413);
        }
        $dir = $this->render_dir();
        $this->clean_render_dir($dir);
        $name = 'job-' . $job_id . '-' . wp_generate_password(16, false, false) . '.pdf';
        if (file_put_contents($dir . $name, $pdf) === false) {
            return $reject('upload_failed', 'no se pudo guardar el archivo en el servidor', 500);
        }
        $settings = json_decode((string) $job['ajustes'], true);
        $settings = is_array($settings) ? $settings : [];
        $settings['archivo'] = $name;
        $this->repo->transition_job($job_id, $active, [
            'estado' => 'impreso',
            'detalle' => 'PDF listo',
            'ajustes' => wp_json_encode($settings),
            'error_code' => null,
            'finished_at' => Riverso_Print_Repository::now(),
        ]);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Guarda qué impresoras del hub son también escáner. Una que ya se conocía se mantiene
     * mientras siga instalada, aunque ahora esté apagada y el hub no pueda consultarla.
     */
    private function store_scanners($agent_id, array $reported) {
        $all = get_option(self::SCANNERS_OPTION, []);
        $all = is_array($all) ? $all : [];
        $known = [];
        foreach ((array) ($all[$agent_id] ?? []) as $s) {
            if (is_array($s) && !empty($s['name'])) {
                $known[(string) $s['name']] = $s;
            }
        }
        $list = [];
        foreach ($reported as $p) {
            $name = is_array($p) ? mb_substr(trim((string) ($p['name'] ?? '')), 0, 191) : '';
            if ($name === '') {
                continue;
            }
            if (isset($p['scanner']) && is_array($p['scanner'])) {
                $hojas = (string) ($p['adf_state'] ?? '');
                $list[] = [
                    'name' => $name,
                    'adf' => !empty($p['scanner']['adf']),
                    'duplex' => !empty($p['scanner']['duplex']),
                    // Hojas en el alimentador (hub 1.1.1+). Solo se escribe la opción cuando cambia.
                    'hojas' => in_array($hojas, ['con_hojas', 'vacio'], true) ? $hojas : '',
                ];
            } elseif (isset($known[$name])) {
                $list[] = array_merge($known[$name], ['hojas' => '']);
            }
        }
        if ($list === array_values($known)) {
            return;
        }
        if ($list) {
            $all[$agent_id] = $list;
        } else {
            unset($all[$agent_id]);
        }
        update_option(self::SCANNERS_OPTION, $all, false);
    }

    /**
     * Escáner a usar y si está disponible ahora.
     *
     * @return array{ok: bool, code: string, detail: string, agent: ?array, printer: ?array, scanner: ?array}
     */
    private function scan_target() {
        $out = ['ok' => false, 'code' => 'no_scanner', 'detail' => '', 'agent' => null, 'printer' => null, 'scanner' => null];
        $all = get_option(self::SCANNERS_OPTION, []);
        $all = is_array($all) ? $all : [];
        $old_hub = false;
        foreach ($this->repo->list_agents() as $agent) {
            if (!$this->hub_can_scan($agent['version'])) {
                $old_hub = true;
                continue;
            }
            foreach ((array) ($all[(int) $agent['id']] ?? []) as $scanner) {
                $printer = is_array($scanner) ? $this->repo->find_printer($agent['id'], $scanner['name'] ?? '') : null;
                if (!$printer || (int) $printer['activo'] !== 1) {
                    continue;
                }
                $cand = ['ok' => false, 'code' => '', 'detail' => '', 'agent' => $agent, 'printer' => $printer, 'scanner' => $scanner];
                $fresh = Riverso_Print_Repository::age($agent['printers_at']);
                if (!$this->agent_online($agent)) {
                    $cand['code'] = 'hub_offline';
                } elseif ($fresh !== null && $fresh <= self::PRINTERS_STALE_AFTER
                    && ((int) $printer['presente'] !== 1 || $printer['estado'] === 'offline')) {
                    $cand['code'] = 'scanner_offline';
                    $cand['detail'] = (string) $printer['estado_detalle'];
                } else {
                    $cand['ok'] = true;
                    return $cand;
                }
                if ($out['agent'] === null) {
                    $out = $cand;
                }
            }
        }
        if ($out['agent'] === null && $old_hub) {
            $out['code'] = 'hub_old';
        }
        return $out;
    }

    /**
     * Estado del escáner para el botón «Escanear» y su punto de color.
     * feeder: con_hojas | vacio | «» (hub antiguo o no se pudo saber); checkedAgo: segundos desde
     * el último reporte del hub, que es cuando revisó el alimentador.
     */
    public function scan_overview() {
        $t = $this->scan_target();
        $feeder = $t['ok'] ? (string) ($t['scanner']['hojas'] ?? '') : '';
        return [
            'available' => $t['printer'] !== null,
            'ready' => $t['ok'],
            'feeder' => $feeder,
            'checkedAgo' => $feeder !== '' ? Riverso_Print_Repository::age($t['agent']['printers_at']) : null,
            'label' => $t['printer'] ? $this->printer_label($t['printer']) : '',
            'duplex' => $t['scanner'] ? !empty($t['scanner']['duplex']) : false,
            'maxPages' => self::SCAN_MAX_PAGES,
            'message' => $t['ok'] ? '' : $this->scan_error_message($t['code'], $t),
        ];
    }

    /**
     * Encola un escaneo. Nunca lanza: si no se puede, devuelve el trabajo en error con el motivo.
     *
     * @param array $opts dos_caras (bool), papel (carta | oficio).
     * @return array|null Trabajo presentado para la UI.
     */
    public function enqueue_scan(array $opts = []) {
        $t = $this->scan_target();
        $code = $t['ok'] ? '' : $t['code'];
        if ($code === '' && function_exists('riverso_scan_gemini_configured') && !riverso_scan_gemini_configured()) {
            $code = 'ia_missing';
        }
        if ($code === '' && $this->repo->active_scan_job((int) $t['agent']['id'])) {
            $code = 'scanner_busy';
        }
        $data = [
            'origen' => 'recepcion',
            'source_type' => 'escaneo',
            'source_id' => 0,
            'document_type_id' => 0,
            'titulo' => 'Escaneo',
            'station_id' => 0,
            'agent_id' => $t['agent'] ? (int) $t['agent']['id'] : null,
            'printer_id' => $t['printer'] ? (int) $t['printer']['id'] : null,
            'requested_by' => get_current_user_id() ?: null,
        ];
        if ($code === '') {
            $data['ajustes'] = wp_json_encode([
                'printer' => (string) $t['printer']['system_name'],
                'printer_label' => $this->printer_label($t['printer']),
                'fuente' => 'auto',
                'dos_caras' => !empty($opts['dos_caras']) && !empty($t['scanner']['duplex']),
                'papel' => ($opts['papel'] ?? '') === 'oficio' ? 'oficio' : 'carta',
                'dpi' => self::SCAN_DPI,
                'color' => false,
                'max_paginas' => self::SCAN_MAX_PAGES,
                'max_bytes' => self::SCAN_TARGET_BYTES,
            ]);
            $data['estado'] = 'pendiente';
            $data['expires_at'] = Riverso_Print_Repository::at(self::CLAIM_TIMEOUT);
        } else {
            $data['estado'] = 'error';
            $data['error_code'] = $code;
            $data['error_msg'] = mb_substr((string) $t['detail'], 0, 255);
            $data['finished_at'] = Riverso_Print_Repository::now();
        }
        $id = $this->repo->create_job($data);
        $job = $id ? $this->repo->get_job($id) : null;
        return $job ? $this->present_scan_job($job) : null;
    }

    /**
     * @return array|null Escaneo presentado para la UI (solo de quien lo pidió).
     */
    public function scan_job($job_id) {
        $this->repo->expire_jobs(self::STUCK_AFTER);
        $job = $this->repo->get_job($job_id);
        if (!$job || $job['source_type'] !== 'escaneo' || (int) $job['requested_by'] !== get_current_user_id()) {
            return null;
        }
        return $this->present_scan_job($job);
    }

    private function present_scan_job(array $job) {
        $estado = (string) $job['estado'];
        $fases = ['pendiente' => 'cola', 'tomado' => 'preparando', 'imprimiendo' => 'escaneando', 'impreso' => 'listo'];
        $out = [
            'id' => (int) $job['id'],
            'fase' => $fases[$estado] ?? 'error',
            'final' => !in_array($estado, ['pendiente', 'tomado', 'imprimiendo'], true),
            'ok' => $estado === 'impreso',
            'detalle' => (string) ($job['detalle'] ?? ''),
            'archivo_id' => $estado === 'impreso' ? (int) $job['source_id'] : 0,
            'message' => '',
        ];
        if ($out['final'] && !$out['ok']) {
            $out['message'] = $this->scan_error_message((string) ($job['error_code'] ?? ''), [
                'agent' => $job['agent_id'] ? $this->repo->get_agent($job['agent_id']) : null,
                'printer' => $job['printer_id'] ? $this->repo->get_printer($job['printer_id']) : null,
                'detail' => (string) ($job['error_msg'] ?? ''),
            ]);
        }
        return $out;
    }

    private function scan_error_message($code, array $c) {
        $scanner = !empty($c['printer']) ? '«' . $this->printer_label($c['printer']) . '»' : 'el escáner';
        $hub = !empty($c['agent']) ? '«' . $c['agent']['nombre'] . '»' : 'de impresión';
        $detail = trim((string) ($c['detail'] ?? ''));
        switch ($code) {
            case 'no_scanner':
                return 'No hay un escáner de red disponible. En Facturación → Impresión, revisa que la multifuncional esté activa y tenga su IP.';
            case 'hub_old':
                return 'El hub de impresión es antiguo y no sabe escanear. Instala Riverso Print Hub ' . self::SCAN_MIN_HUB . ' o superior en el PC de las impresoras.';
            case 'hub_offline':
            case 'claim_timeout':
                return 'El hub ' . $hub . ' no responde. Revisa que el PC esté encendido, con internet y con Riverso Print Hub abierto (ícono junto al reloj).';
            case 'hub_lost':
                return 'El hub ' . $hub . ' dejó de responder mientras escaneaba. Retira las hojas del alimentador y vuelve a intentar.';
            case 'scanner_offline':
                return 'El escáner ' . $scanner . ' está apagado o sin red' . ($detail !== '' ? ' (' . $detail . ')' : '') . '.';
            case 'scanner_busy':
                return 'El escáner ' . $scanner . ' está ocupado. Espera a que termine y vuelve a intentar.';
            case 'scanner_error':
                return 'El escáner ' . $scanner . ' tiene un problema' . ($detail !== '' ? ': ' . $detail : '') . '. Revisa que no haya hojas atascadas ni tapas abiertas.';
            case 'scan_empty':
                return 'El escáner no entregó ninguna página. Pon las hojas en el alimentador o una hoja en el vidrio.';
            case 'too_large':
                return 'El escaneo pesa demasiado para leerlo con IA. Escanea menos hojas por vez.';
            case 'ia_missing':
                return 'La lectura con IA no está configurada (Gemini API Key en Configuración).';
            case 'upload_failed':
                return 'No se pudo subir el escaneo' . ($detail !== '' ? ': ' . $detail : '') . '. Vuelve a intentar.';
            default:
                return 'No se pudo escanear' . ($detail !== '' ? ': ' . $detail : '.');
        }
    }

    /**
     * El hub sube el PDF escaneado (cuerpo application/pdf). Responde apenas queda guardado y
     * sigue con la lectura de IA en segundo plano, igual que una carga manual en Escaneos.
     */
    public function rest_scan_upload(WP_REST_Request $request) {
        $agent = $this->rest_agent($request);
        if ($agent instanceof WP_REST_Response) {
            return $agent;
        }
        $job = $this->rest_job($request, $agent);
        if ($job instanceof WP_REST_Response) {
            return $job;
        }
        $active = ['tomado', 'imprimiendo'];
        if ($job['source_type'] !== 'escaneo' || !in_array($job['estado'], $active, true)) {
            return new WP_REST_Response(['ok' => false, 'code' => 'job_closed', 'message' => 'El escaneo ya no está activo.'], 410);
        }
        $job_id = (int) $job['id'];
        $reject = function ($code, $message, $status) use ($job_id, $active) {
            $this->repo->transition_job($job_id, $active, [
                'estado' => 'error',
                'error_code' => $code,
                'error_msg' => mb_substr($message, 0, 255),
                'finished_at' => Riverso_Print_Repository::now(),
            ]);
            return new WP_REST_Response(['ok' => false, 'code' => $code, 'message' => $message], $status);
        };
        $pdf = (string) $request->get_body();
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return $reject('upload_failed', 'el archivo no llegó completo', 400);
        }
        if (strlen($pdf) > self::SCAN_MAX_BYTES) {
            return $reject('too_large', '', 413);
        }
        if (!class_exists('Riverso_Scan_Module')) {
            return $reject('scan_failed', 'el módulo de Escaneos no está disponible', 500);
        }
        $name = 'escaneo-' . get_date_from_gmt(Riverso_Print_Repository::now(), 'Ymd-His') . '.pdf';
        $tmp = wp_tempnam($name);
        if (!$tmp || file_put_contents($tmp, $pdf) === false) {
            return $reject('upload_failed', 'no se pudo guardar el archivo en el servidor', 500);
        }
        unset($pdf);
        // El escaneo queda a nombre de quien presionó «Escanear», no del hub.
        wp_set_current_user((int) $job['requested_by']);
        $scans = Riverso_Scan_Module::get_instance();
        $prepared = $scans->prepare_external_upload($tmp, $name);
        if (is_wp_error($prepared)) {
            @unlink($tmp);
            return $reject('scan_failed', $prepared->get_error_message(), 422);
        }
        $pages = max(0, (int) $request->get_header('x-riverso-scan-pages'));
        $this->repo->transition_job($job_id, $active, [
            'estado' => 'impreso',
            'source_id' => (int) $prepared['archivo_id'],
            'detalle' => ($pages > 0 ? $pages . ($pages === 1 ? ' página escaneada' : ' páginas escaneadas') : 'Escaneo recibido')
                . ($request->get_header('x-riverso-scan-truncated') ? ' (tope alcanzado: quedaron hojas sin escanear)' : ''),
            'error_code' => null,
            'finished_at' => Riverso_Print_Repository::now(),
        ]);
        $response = ['ok' => true, 'archivo_id' => (int) $prepared['archivo_id']];
        // La lectura usa la copia persistente; el temporal solo se conserva si esa copia falló.
        if (($prepared['local_path'] ?? '') !== $tmp) {
            @unlink($tmp);
        }
        if (!empty($prepared['reutilizado']) || !empty($prepared['already_running'])) {
            return new WP_REST_Response($response, 200);
        }
        $scans->respond_and_extract($prepared, $response);
        exit;
    }

    // ───────────────────────── REST (hub) ─────────────────────────

    public function register_routes() {
        register_rest_route(self::REST_NS, '/print-hub/poll', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_poll'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::REST_NS, '/print-hub/jobs/(?P<id>\d+)/file', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_file'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::REST_NS, '/print-hub/jobs/(?P<id>\d+)/status', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::REST_NS, '/print-hub/jobs/(?P<id>\d+)/scan', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_scan_upload'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::REST_NS, '/print-hub/jobs/(?P<id>\d+)/pdf', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_render_upload'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * El hub se identifica con su token en X-Riverso-Hub-Token (no Authorization: algunos
     * proxies de Plesk la descartan antes de llegar a PHP).
     *
     * @return array|WP_REST_Response
     */
    private function rest_agent(WP_REST_Request $request) {
        $agent = $this->repo->find_agent_by_token((string) $request->get_header('x-riverso-hub-token'));
        if (!$agent) {
            return new WP_REST_Response(['ok' => false, 'code' => 'invalid_token', 'message' => 'Token de hub inválido o revocado.'], 401);
        }
        return $agent;
    }

    private function rest_job(WP_REST_Request $request, array $agent) {
        $job = $this->repo->get_job((int) $request['id']);
        if (!$job || (int) $job['agent_id'] !== (int) $agent['id']) {
            return new WP_REST_Response(['ok' => false, 'code' => 'job_not_found'], 404);
        }
        return $job;
    }

    public function rest_poll(WP_REST_Request $request) {
        $agent = $this->rest_agent($request);
        if ($agent instanceof WP_REST_Response) {
            return $agent;
        }
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $fields = [
            'hostname' => mb_substr(sanitize_text_field((string) ($body['hostname'] ?? '')), 0, 100),
            'version' => mb_substr(sanitize_text_field((string) ($body['version'] ?? '')), 0, 20),
            'last_ip' => mb_substr(sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
        ];
        if (isset($body['printers']) && is_array($body['printers'])) {
            $this->repo->sync_printers((int) $agent['id'], $body['printers'], !empty($body['printers_full']));
            $fields['printers_at'] = Riverso_Print_Repository::now();
            if (!empty($body['printers_full'])) {
                $this->store_scanners((int) $agent['id'], $body['printers']);
            }
        }
        $this->repo->touch_agent((int) $agent['id'], $fields);
        $this->repo->expire_jobs(self::STUCK_AFTER);

        // probe: el hub solo prueba la conexión (pantalla Configurar) y no debe tomar trabajos.
        // Un hub antiguo imprimiría un escaneo como documento y no sabe convertir HTML: no se le entregan.
        $skip = [];
        if (!$this->hub_can_scan($fields['version'])) {
            $skip[] = 'escaneo';
        }
        if (!$this->hub_can_html($fields['version'])) {
            $skip[] = 'cotizacion';
        }
        if (!$this->hub_can_render($fields['version'])) {
            $skip[] = 'cotiz_pdf';
        }
        $job = empty($body['probe']) ? $this->repo->claim_next((int) $agent['id'], $skip) : null;
        $payload = null;
        if ($job) {
            $settings = json_decode((string) $job['ajustes'], true);
            $kinds = ['prueba' => 'prueba', 'escaneo' => 'scan', 'cotizacion' => 'html', 'cotiz_pdf' => 'render'];
            $payload = [
                'id' => (int) $job['id'],
                'kind' => $kinds[$job['source_type']] ?? 'pdf',
                'titulo' => (string) $job['titulo'],
                'settings' => is_array($settings) ? $settings : null,
            ];
        }
        return new WP_REST_Response([
            'ok' => true,
            'agent' => ['id' => (int) $agent['id'], 'nombre' => (string) $agent['nombre']],
            'poll_ms' => self::POLL_MS,
            'hosts' => (object) $this->repo->manual_hosts((int) $agent['id']),
            'job' => $payload,
        ], 200);
    }

    public function rest_file(WP_REST_Request $request) {
        $agent = $this->rest_agent($request);
        if ($agent instanceof WP_REST_Response) {
            return $agent;
        }
        $job = $this->rest_job($request, $agent);
        if ($job instanceof WP_REST_Response) {
            return $job;
        }
        if (!in_array($job['estado'], ['tomado', 'imprimiendo'], true)) {
            return new WP_REST_Response(['ok' => false, 'code' => 'job_closed', 'message' => 'El trabajo ya no está activo.'], 410);
        }
        if ($job['source_type'] === 'cotizacion' || $job['source_type'] === 'cotiz_pdf') {
            return $this->rest_quote_file($job);
        }
        if ($job['source_type'] !== 'dte') {
            return new WP_REST_Response(['ok' => false, 'code' => 'no_file'], 400);
        }
        $dte = $this->dte((int) $job['source_id']);
        if (!$dte || empty($dte['facto_document_id'])) {
            return new WP_REST_Response(['ok' => false, 'code' => 'source_missing', 'message' => 'Documento no encontrado.'], 404);
        }
        if (!class_exists('Riverso_Facto_Client')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/integrations/facto/class-facto-client.php';
        }
        $this->repo->update_job((int) $job['id'], ['detalle' => 'Descargando PDF de FACTO']);
        $full = (new Riverso_Facto_Client())->get_document((int) $dte['facto_document_id']);
        if (is_wp_error($full)) {
            return new WP_REST_Response(['ok' => false, 'code' => 'pdf_unavailable', 'message' => $full->get_error_message()], 502);
        }
        $b64 = (string) ($full['electronic_document']['document_pdf'] ?? '');
        if ($b64 === '') {
            // Recién emitido: FACTO a veces tarda unos segundos en generar el PDF.
            return new WP_REST_Response(['ok' => false, 'code' => 'pdf_not_ready', 'message' => 'FACTO aún no genera el PDF.'], 409);
        }
        return new WP_REST_Response([
            'ok' => true,
            'pdf_base64' => $b64,
            'filename' => sanitize_file_name((string) $job['titulo']) . '.pdf',
        ], 200);
    }

    /**
     * HTML de la cotización, con CSS y logo incrustados para que el hub lo convierta sin
     * depender de la sesión ni de otras descargas.
     */
    private function rest_quote_file(array $job) {
        $settings = json_decode((string) $job['ajustes'], true);
        $template = is_array($settings) ? (string) ($settings['plantilla'] ?? '') : '';
        $quotes = $this->quote_module();
        $html = $quotes ? $quotes->document_html((int) $job['source_id'], $template, true) : null;
        if ($html === null) {
            return new WP_REST_Response(['ok' => false, 'code' => 'source_missing', 'message' => 'Cotización no encontrada.'], 404);
        }
        return new WP_REST_Response([
            'ok' => true,
            'html' => $html,
            'filename' => sanitize_file_name((string) $job['titulo']) . '.pdf',
        ], 200);
    }

    public function rest_status(WP_REST_Request $request) {
        $agent = $this->rest_agent($request);
        if ($agent instanceof WP_REST_Response) {
            return $agent;
        }
        $job = $this->rest_job($request, $agent);
        if ($job instanceof WP_REST_Response) {
            return $job;
        }
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $estado = sanitize_key((string) ($body['estado'] ?? ''));
        $detalle = mb_substr(sanitize_text_field((string) ($body['detalle'] ?? '')), 0, 255);
        $active = ['tomado', 'imprimiendo'];
        $now = Riverso_Print_Repository::now();
        switch ($estado) {
            case 'tomado':
            case 'imprimiendo':
                $applied = $this->repo->transition_job((int) $job['id'], $active, ['estado' => $estado, 'detalle' => $detalle]);
                break;
            case 'impreso':
                $applied = $this->repo->transition_job((int) $job['id'], $active, [
                    'estado' => 'impreso',
                    'detalle' => $detalle,
                    'error_code' => null,
                    'finished_at' => $now,
                ]);
                break;
            case 'error':
                $code = mb_substr(sanitize_key((string) ($body['error_code'] ?? 'print_failed')), 0, 32) ?: 'print_failed';
                $applied = $this->repo->transition_job((int) $job['id'], $active, [
                    'estado' => 'error',
                    'detalle' => $detalle,
                    'error_code' => $code,
                    'error_msg' => mb_substr(sanitize_text_field((string) ($body['error_msg'] ?? '')), 0, 255),
                    'finished_at' => $now,
                ]);
                break;
            default:
                return new WP_REST_Response(['ok' => false, 'code' => 'invalid_state'], 400);
        }
        $current = $this->repo->get_job((int) $job['id']);
        return new WP_REST_Response([
            'ok' => true,
            'applied' => $applied,
            'estado' => $current ? (string) $current['estado'] : '',
        ], 200);
    }
}
