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
                $data['ajustes'] = wp_json_encode($this->build_settings($preset, $health['printer']));
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

    private function authorize($manage = false) {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_send_json_error(['message' => 'Sesión inválida. Recarga la página.'], 403);
        }
        if (!$this->can_print() && !$this->can_manage()) {
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
        $this->authorize();
        $dte_id = $this->post_int('dte_id');
        $job = $this->enqueue_dte($dte_id, [
            'station_id' => $this->post_int('station_id'),
            'preset_id' => $this->post_int('preset_id'),
            'origen' => $this->post_text('origen', 20),
        ]);
        if (!$job) {
            wp_send_json_error(['message' => 'Documento no encontrado.']);
        }
        wp_send_json_success(['job' => $job]);
    }

    public function ajax_job() {
        $this->authorize();
        $this->repo->expire_jobs(self::STUCK_AFTER);
        $job = $this->repo->get_job($this->post_int('job_id'));
        if (!$job) {
            wp_send_json_error(['message' => 'Trabajo de impresión no encontrado.']);
        }
        wp_send_json_success(['job' => $this->present_job($job, true)]);
    }

    public function ajax_overview() {
        $this->authorize();
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
        }
        $this->repo->touch_agent((int) $agent['id'], $fields);
        $this->repo->expire_jobs(self::STUCK_AFTER);

        // probe: el hub solo prueba la conexión (pantalla Configurar) y no debe tomar trabajos.
        $job = empty($body['probe']) ? $this->repo->claim_next((int) $agent['id']) : null;
        $payload = null;
        if ($job) {
            $settings = json_decode((string) $job['ajustes'], true);
            $payload = [
                'id' => (int) $job['id'],
                'kind' => $job['source_type'] === 'prueba' ? 'prueba' : 'pdf',
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
