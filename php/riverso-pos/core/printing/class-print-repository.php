<?php
/**
 * Impresión directa · acceso a datos (hubs, impresoras, presets, ruteo y cola).
 *
 * Las fechas se guardan en UTC desde PHP (gmdate) para comparar plazos sin depender
 * de la zona horaria de MySQL.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Print_Repository {

    /** @var string */
    private $agents;
    /** @var string */
    private $printers;
    /** @var string */
    private $presets;
    /** @var string */
    private $stations;
    /** @var string */
    private $routes;
    /** @var string */
    private $jobs;

    public function __construct() {
        global $wpdb;
        $p = $wpdb->prefix . 'riverso_';
        $this->agents = $p . 'print_agents';
        $this->printers = $p . 'print_printers';
        $this->presets = $p . 'print_presets';
        $this->stations = $p . 'print_stations';
        $this->routes = $p . 'print_routes';
        $this->jobs = $p . 'print_jobs';
    }

    public static function now() {
        return gmdate('Y-m-d H:i:s');
    }

    public static function at($offset_seconds) {
        return gmdate('Y-m-d H:i:s', time() + (int) $offset_seconds);
    }

    /**
     * Segundos transcurridos desde una fecha UTC (null si no hay fecha).
     *
     * @param string|null $utc
     * @return int|null
     */
    public static function age($utc) {
        if (!$utc) {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        return $ts ? max(0, time() - $ts) : null;
    }

    // ───────────────────────── Hubs ─────────────────────────

    public function list_agents() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->agents} WHERE estado = 'activo' ORDER BY nombre ASC, id ASC", ARRAY_A) ?: [];
    }

    public function get_agent($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->agents} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function find_agent_by_token($token) {
        global $wpdb;
        $token = trim((string) $token);
        if (strlen($token) < 20) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->agents} WHERE token_hash = %s AND estado = 'activo' LIMIT 1",
            hash('sha256', $token)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{agent: array, token: string}|null
     */
    public function create_agent($nombre, $user_id) {
        global $wpdb;
        $token = $this->new_token();
        $ok = $wpdb->insert($this->agents, [
            'nombre' => $nombre,
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, -4),
            'estado' => 'activo',
            'created_by' => $user_id ?: null,
            'created_at' => self::now(),
        ]);
        if (!$ok) {
            return null;
        }
        return ['agent' => $this->get_agent((int) $wpdb->insert_id), 'token' => $token];
    }

    public function rename_agent($id, $nombre) {
        global $wpdb;
        return false !== $wpdb->update($this->agents, ['nombre' => $nombre], ['id' => absint($id)]);
    }

    /**
     * Genera un token nuevo; el anterior deja de funcionar de inmediato.
     *
     * @return string|null
     */
    public function rotate_agent_token($id) {
        global $wpdb;
        $token = $this->new_token();
        $ok = $wpdb->update($this->agents, [
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, -4),
        ], ['id' => absint($id), 'estado' => 'activo']);
        return $ok ? $token : null;
    }

    /**
     * Revoca el hub: el token deja de funcionar y sus impresoras quedan fuera de servicio.
     */
    public function revoke_agent($id) {
        global $wpdb;
        $id = absint($id);
        $wpdb->update($this->agents, [
            'estado' => 'revocado',
            'token_hash' => hash('sha256', $this->new_token()),
        ], ['id' => $id]);
        $wpdb->update($this->printers, ['activo' => 0], ['agent_id' => $id]);
        return true;
    }

    public function touch_agent($id, array $fields) {
        global $wpdb;
        $fields['last_seen_at'] = self::now();
        $wpdb->update($this->agents, $fields, ['id' => absint($id)]);
    }

    private function new_token() {
        return wp_generate_password(40, false, false);
    }

    // ───────────────────────── Impresoras ─────────────────────────

    public function list_printers() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT p.* FROM {$this->printers} p
             INNER JOIN {$this->agents} a ON a.id = p.agent_id AND a.estado = 'activo'
             ORDER BY p.agent_id ASC, p.es_virtual ASC, p.system_name ASC",
            ARRAY_A
        ) ?: [];
    }

    public function get_printer($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->printers} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Impresoras activas de un hub con IP manual (para que el hub chequee si responden en la red).
     *
     * @return array<string, string> system_name => host
     */
    public function manual_hosts($agent_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT system_name, host FROM {$this->printers} WHERE agent_id = %d AND host IS NOT NULL AND host <> ''",
            absint($agent_id)
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['system_name']] = (string) $r['host'];
        }
        return $out;
    }

    /**
     * Inserta/actualiza las impresoras reportadas por un hub.
     *
     * @param int   $agent_id
     * @param array $reported Lista de {name, driver, port, status, detail, papers?, is_default, is_virtual, host}.
     * @param bool  $full     true si el reporte trae todas las impresoras del equipo (marca ausentes).
     */
    public function sync_printers($agent_id, array $reported, $full) {
        global $wpdb;
        $agent_id = absint($agent_id);
        $existing = [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->printers} WHERE agent_id = %d", $agent_id), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $existing[(string) $r['system_name']] = $r;
        }
        $seen = [];
        $now = self::now();
        foreach ($reported as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 191);
            if ($name === '') {
                continue;
            }
            $seen[$name] = true;
            $estado = in_array(($p['status'] ?? ''), ['listo', 'offline', 'error', 'desconocido'], true) ? $p['status'] : 'desconocido';
            $fields = [
                'driver_name' => mb_substr((string) ($p['driver'] ?? ''), 0, 191),
                'port_name' => mb_substr((string) ($p['port'] ?? ''), 0, 191),
                'estado' => $estado,
                'estado_detalle' => mb_substr((string) ($p['detail'] ?? ''), 0, 255),
                'es_predeterminada' => !empty($p['is_default']) ? 1 : 0,
                'es_virtual' => !empty($p['is_virtual']) ? 1 : 0,
                'host_detectado' => mb_substr((string) ($p['host'] ?? ''), 0, 100),
                'presente' => 1,
            ];
            if (isset($p['papers']) && is_array($p['papers'])) {
                $papers = array_values(array_unique(array_filter(array_map(function ($x) {
                    return mb_substr(trim((string) $x), 0, 120);
                }, $p['papers']))));
                $fields['papeles'] = wp_json_encode(array_slice($papers, 0, 300));
            }
            if (isset($existing[$name])) {
                $row = $existing[$name];
                $changed = [];
                foreach ($fields as $k => $v) {
                    if ((string) $row[$k] !== (string) $v) {
                        $changed[$k] = $v;
                    }
                }
                if ($changed) {
                    if (isset($changed['estado']) || isset($changed['estado_detalle'])) {
                        $changed['estado_at'] = $now;
                    }
                    $wpdb->update($this->printers, $changed, ['id' => (int) $row['id']]);
                }
            } else {
                $fields['agent_id'] = $agent_id;
                $fields['system_name'] = $name;
                // Las virtuales (PDF, XPS, OneNote, Fax) quedan apagadas por defecto.
                $fields['activo'] = $fields['es_virtual'] ? 0 : 1;
                $fields['estado_at'] = $now;
                $fields['created_at'] = $now;
                $wpdb->insert($this->printers, $fields);
            }
        }
        if ($full) {
            foreach ($existing as $name => $row) {
                if (!isset($seen[$name]) && (int) $row['presente'] === 1) {
                    $wpdb->update($this->printers, ['presente' => 0, 'estado_at' => $now], ['id' => (int) $row['id']]);
                }
            }
        }
    }

    public function update_printer($id, array $fields) {
        global $wpdb;
        $allowed = array_intersect_key($fields, array_flip(['alias', 'activo', 'host']));
        if (!$allowed) {
            return true;
        }
        return false !== $wpdb->update($this->printers, $allowed, ['id' => absint($id)]);
    }

    // ───────────────────────── Presets ─────────────────────────

    public function list_presets() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->presets} ORDER BY nombre ASC, id ASC", ARRAY_A) ?: [];
    }

    public function get_preset($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->presets} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return int ID del preset guardado (0 si falla).
     */
    public function save_preset(array $data) {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        unset($data['id']);
        $data['updated_at'] = self::now();
        if ($id > 0) {
            return false !== $wpdb->update($this->presets, $data, ['id' => $id]) ? $id : 0;
        }
        $data['created_at'] = $data['updated_at'];
        return $wpdb->insert($this->presets, $data) ? (int) $wpdb->insert_id : 0;
    }

    public function preset_in_use($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->routes} WHERE preset_id = %d OR fallback_preset_id = %d",
            absint($id),
            absint($id)
        )) > 0;
    }

    public function delete_preset($id) {
        global $wpdb;
        return (bool) $wpdb->delete($this->presets, ['id' => absint($id)]);
    }

    // ───────────────────────── Estaciones ─────────────────────────

    public function list_stations() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->stations} ORDER BY nombre ASC, id ASC", ARRAY_A) ?: [];
    }

    public function get_station($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->stations} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function save_station($id, $nombre) {
        global $wpdb;
        $id = absint($id);
        if ($id > 0) {
            return false !== $wpdb->update($this->stations, ['nombre' => $nombre], ['id' => $id]) ? $id : 0;
        }
        return $wpdb->insert($this->stations, ['nombre' => $nombre, 'created_at' => self::now()]) ? (int) $wpdb->insert_id : 0;
    }

    public function delete_station($id) {
        global $wpdb;
        $id = absint($id);
        $wpdb->delete($this->routes, ['station_id' => $id]);
        return (bool) $wpdb->delete($this->stations, ['id' => $id]);
    }

    // ───────────────────────── Ruteo ─────────────────────────

    public function list_routes() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->routes} ORDER BY document_type_id ASC, station_id ASC", ARRAY_A) ?: [];
    }

    /**
     * Regla para el tipo de documento: primero la de la estación, luego la general.
     */
    public function find_route($document_type_id, $station_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->routes}
             WHERE document_type_id = %d AND station_id IN (0, %d)
             ORDER BY station_id DESC LIMIT 1",
            (int) $document_type_id,
            absint($station_id)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Guarda la regla (tipo de documento + estación son únicos).
     */
    public function save_route($document_type_id, $station_id, $preset_id, $fallback_preset_id) {
        global $wpdb;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->routes} WHERE document_type_id = %d AND station_id = %d",
            (int) $document_type_id,
            absint($station_id)
        ));
        $data = [
            'preset_id' => absint($preset_id),
            'fallback_preset_id' => $fallback_preset_id ? absint($fallback_preset_id) : null,
        ];
        if ($existing > 0) {
            return false !== $wpdb->update($this->routes, $data, ['id' => $existing]) ? $existing : 0;
        }
        $data['document_type_id'] = (int) $document_type_id;
        $data['station_id'] = absint($station_id);
        $data['created_at'] = self::now();
        return $wpdb->insert($this->routes, $data) ? (int) $wpdb->insert_id : 0;
    }

    public function delete_route($id) {
        global $wpdb;
        return (bool) $wpdb->delete($this->routes, ['id' => absint($id)]);
    }

    // ───────────────────────── Cola ─────────────────────────

    public function create_job(array $data) {
        global $wpdb;
        $now = self::now();
        $data['created_at'] = $now;
        $data['touched_at'] = $now;
        return $wpdb->insert($this->jobs, $data) ? (int) $wpdb->insert_id : 0;
    }

    public function get_job($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function update_job($id, array $fields) {
        global $wpdb;
        $fields['touched_at'] = self::now();
        return false !== $wpdb->update($this->jobs, $fields, ['id' => absint($id)]);
    }

    /**
     * Cambia de estado solo si el trabajo sigue en uno de los estados esperados
     * (evita que un reporte tardío del hub pise un vencimiento o una cancelación).
     */
    public function transition_job($id, array $from_states, array $fields) {
        global $wpdb;
        $fields['touched_at'] = self::now();
        $sets = [];
        $args = [];
        foreach ($fields as $k => $v) {
            if ($v === null) {
                $sets[] = "`{$k}` = NULL";
            } else {
                $sets[] = "`{$k}` = %s";
                $args[] = (string) $v;
            }
        }
        $in = implode(',', array_fill(0, count($from_states), '%s'));
        $args[] = absint($id);
        $args = array_merge($args, $from_states);
        $sql = "UPDATE {$this->jobs} SET " . implode(', ', $sets) . " WHERE id = %d AND estado IN ({$in})";
        return (int) $wpdb->query($wpdb->prepare($sql, $args)) > 0;
    }

    /**
     * Vence trabajos que ningún hub tomó a tiempo y marca como perdidos los que el hub
     * tomó pero dejó de reportar.
     */
    public function expire_jobs($stuck_after_seconds) {
        global $wpdb;
        $now = self::now();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->jobs}
             SET estado = 'vencido', error_code = 'claim_timeout', finished_at = %s, touched_at = %s
             WHERE estado = 'pendiente' AND expires_at < %s",
            $now,
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->jobs}
             SET estado = 'error', error_code = 'hub_lost', finished_at = %s, touched_at = %s
             WHERE estado IN ('tomado', 'imprimiendo') AND touched_at < %s",
            $now,
            $now,
            self::at(-1 * (int) $stuck_after_seconds)
        ));
    }

    /**
     * Toma el trabajo pendiente más antiguo del hub (atómico entre consultas concurrentes).
     */
    public function claim_next($agent_id) {
        global $wpdb;
        $agent_id = absint($agent_id);
        for ($i = 0; $i < 3; $i++) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->jobs} WHERE agent_id = %d AND estado = 'pendiente' ORDER BY id ASC LIMIT 1",
                $agent_id
            ));
            if ($id <= 0) {
                return null;
            }
            $now = self::now();
            if ($this->transition_job($id, ['pendiente'], ['estado' => 'tomado', 'claimed_at' => $now])) {
                return $this->get_job($id);
            }
        }
        return null;
    }

    public function list_recent_jobs($limit = 25) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->jobs} ORDER BY id DESC LIMIT %d",
            max(1, min(200, (int) $limit))
        ), ARRAY_A) ?: [];
    }
}
