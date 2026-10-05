<?php
/**
 * Repositorio de cajas / cuentas bancarias y movimientos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Cash_Repository {

    const TIPOS = [
        'fisica' => 'Caja física',
        'bancaria_manual' => 'Bancaria manual',
        'redelcom' => 'Redelcom',
        'bancaria_conciliada' => 'Bancaria conciliada',
    ];

    const PERM_KEYS = ['ver_saldo', 'pagar', 'borrar_pago', 'transferir', 'abrir_cerrar'];

    /** @return string */
    private function cajas_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_cajas';
    }

    /** @return string */
    private function permisos_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_caja_permisos';
    }

    /** @return string */
    private function arqueos_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_caja_arqueos';
    }

    /** @return string */
    private function movimientos_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_caja_movimientos';
    }

    /**
     * @param int $id
     * @param bool $only_active
     * @return array<string, mixed>|null
     */
    public function get($id, $only_active = true) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }
        $sql = "SELECT * FROM {$this->cajas_table()} WHERE id = %d";
        if ($only_active) {
            $sql .= ' AND activo = 1';
        }
        $row = $wpdb->get_row($wpdb->prepare($sql . ' LIMIT 1', $id), ARRAY_A);
        return $row ? $this->present_caja($row) : null;
    }

    /**
     * @param bool $only_active
     * @return array<int, array<string, mixed>>
     */
    public function list_all($only_active = true) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->cajas_table()}";
        if ($only_active) {
            $sql .= ' WHERE activo = 1';
        }
        $sql .= ' ORDER BY id ASC';
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present_caja($row);
        }
        return $out;
    }

    /**
     * @param string $nombre
     * @param int    $exclude_id
     * @return bool
     */
    public function nombre_exists($nombre, $exclude_id = 0) {
        global $wpdb;
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return false;
        }
        $exclude_id = absint($exclude_id);
        if ($exclude_id > 0) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->cajas_table()} WHERE nombre = %s AND activo = 1 AND id != %d LIMIT 1",
                $nombre,
                $exclude_id
            ));
        } else {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->cajas_table()} WHERE nombre = %s AND activo = 1 LIMIT 1",
                $nombre
            ));
        }
        return !empty($found);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function create(array $data) {
        global $wpdb;
        $nombre = trim((string) ($data['nombre'] ?? ''));
        $tipo = (string) ($data['tipo'] ?? 'fisica');
        if ($nombre === '') {
            return ['ok' => false, 'message' => 'El nombre es obligatorio.'];
        }
        if (!isset(self::TIPOS[$tipo])) {
            return ['ok' => false, 'message' => 'Tipo de caja no válido.'];
        }
        if ($this->nombre_exists($nombre)) {
            return ['ok' => false, 'message' => 'Ya existe una caja con ese nombre.'];
        }
        $now = current_time('mysql');
        $ok = $wpdb->insert($this->cajas_table(), [
            'nombre' => substr($nombre, 0, 128),
            'tipo' => $tipo,
            'estado' => 'cerrada',
            'saldo_efectivo' => 0,
            'activo' => 1,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo crear la caja.'];
        }
        $id = (int) $wpdb->insert_id;
        $this->grant_full_perms_to_admins($id);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return array{ok:bool,message?:string}
     */
    public function update($id, array $data) {
        global $wpdb;
        $caja = $this->get($id);
        if (!$caja) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        $nombre = trim((string) ($data['nombre'] ?? $caja['nombre']));
        $tipo = (string) ($data['tipo'] ?? $caja['tipo']);
        if ($nombre === '') {
            return ['ok' => false, 'message' => 'El nombre es obligatorio.'];
        }
        if (!isset(self::TIPOS[$tipo])) {
            return ['ok' => false, 'message' => 'Tipo de caja no válido.'];
        }
        if ($this->nombre_exists($nombre, $id)) {
            return ['ok' => false, 'message' => 'Ya existe una caja con ese nombre.'];
        }
        $ok = $wpdb->update(
            $this->cajas_table(),
            [
                'nombre' => substr($nombre, 0, 128),
                'tipo' => $tipo,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => absint($id)]
        );
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo actualizar la caja.'];
        }
        return ['ok' => true];
    }

    /**
     * Borrado lógico. Bloquea si está abierta o tiene saldo ≠ 0.
     *
     * @param int $id
     * @return array{ok:bool,message?:string}
     */
    public function soft_delete($id) {
        global $wpdb;
        $caja = $this->get($id);
        if (!$caja) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        if (($caja['estado'] ?? '') === 'abierta') {
            return ['ok' => false, 'message' => 'No se puede eliminar una caja abierta. Ciérrala primero.'];
        }
        $saldo = $this->compute_saldo($id);
        if (abs($saldo) > 0.009) {
            return ['ok' => false, 'message' => 'No se puede eliminar una caja con saldo distinto de cero.'];
        }
        $ok = $wpdb->update(
            $this->cajas_table(),
            ['activo' => 0, 'updated_at' => current_time('mysql')],
            ['id' => absint($id)]
        );
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo eliminar la caja.'];
        }
        return ['ok' => true];
    }

    /**
     * @param int $caja_id
     * @return float
     */
    public function compute_saldo($caja_id) {
        global $wpdb;
        $caja_id = absint($caja_id);
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto), 0) FROM {$this->movimientos_table()} WHERE caja_id = %d",
            $caja_id
        ));
        return round((float) $sum, 2);
    }

    /**
     * Actualiza caché saldo_efectivo.
     *
     * @param int $caja_id
     * @return float
     */
    public function refresh_saldo_cache($caja_id) {
        global $wpdb;
        $saldo = $this->compute_saldo($caja_id);
        $wpdb->update(
            $this->cajas_table(),
            ['saldo_efectivo' => $saldo, 'updated_at' => current_time('mysql')],
            ['id' => absint($caja_id)]
        );
        return $saldo;
    }

    /**
     * @param int    $caja_id
     * @param int    $user_id
     * @param string $perm
     * @return bool
     */
    public function user_has_perm($caja_id, $user_id, $perm) {
        if (!in_array($perm, self::PERM_KEYS, true)) {
            return false;
        }
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'riverso_manage_cash_accounts')) {
            return true;
        }
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT {$perm} FROM {$this->permisos_table()} WHERE caja_id = %d AND user_id = %d LIMIT 1",
            absint($caja_id),
            absint($user_id)
        ));
        return !empty($val);
    }

    /**
     * ¿El usuario tiene al menos un permiso en la caja?
     *
     * @param int $caja_id
     * @param int $user_id
     * @return bool
     */
    public function user_has_any_perm($caja_id, $user_id) {
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'riverso_manage_cash_accounts')) {
            return true;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ver_saldo, pagar, borrar_pago, transferir, abrir_cerrar
             FROM {$this->permisos_table()} WHERE caja_id = %d AND user_id = %d LIMIT 1",
            absint($caja_id),
            absint($user_id)
        ), ARRAY_A);
        if (!$row) {
            return false;
        }
        foreach (self::PERM_KEYS as $k) {
            if (!empty($row[$k])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $caja_id
     * @return array<int, array<string, mixed>>
     */
    public function get_permisos($caja_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->permisos_table()} WHERE caja_id = %d",
            absint($caja_id)
        ), ARRAY_A) ?: [];
        $by_user = [];
        foreach ($rows as $row) {
            $by_user[(int) $row['user_id']] = [
                'user_id' => (int) $row['user_id'],
                'ver_saldo' => !empty($row['ver_saldo']),
                'pagar' => !empty($row['pagar']),
                'borrar_pago' => !empty($row['borrar_pago']),
                'transferir' => !empty($row['transferir']),
                'abrir_cerrar' => !empty($row['abrir_cerrar']),
            ];
        }
        return $by_user;
    }

    /**
     * @param int                  $caja_id
     * @param int                  $user_id
     * @param array<string, mixed> $flags
     * @return array{ok:bool,message?:string}
     */
    public function set_permiso($caja_id, $user_id, array $flags) {
        global $wpdb;
        $caja_id = absint($caja_id);
        $user_id = absint($user_id);
        if ($caja_id <= 0 || $user_id <= 0) {
            return ['ok' => false, 'message' => 'Datos inválidos.'];
        }
        if (!$this->get($caja_id)) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        $data = [
            'caja_id' => $caja_id,
            'user_id' => $user_id,
        ];
        foreach (self::PERM_KEYS as $k) {
            $data[$k] = !empty($flags[$k]) ? 1 : 0;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->permisos_table()} WHERE caja_id = %d AND user_id = %d LIMIT 1",
            $caja_id,
            $user_id
        ));
        if ($existing) {
            $ok = $wpdb->update($this->permisos_table(), $data, ['id' => (int) $existing]);
        } else {
            $ok = $wpdb->insert($this->permisos_table(), $data);
        }
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo guardar el permiso.'];
        }
        return ['ok' => true];
    }

    /**
     * Otorga todos los permisos al creador y a admins.
     *
     * @param int $caja_id
     */
    public function grant_full_perms_to_admins($caja_id) {
        $full = array_fill_keys(self::PERM_KEYS, 1);
        $creator = get_current_user_id();
        if ($creator) {
            $this->set_permiso($caja_id, $creator, $full);
        }
        $admins = get_users(['role__in' => ['administrator', 'riverso_admin'], 'fields' => 'ID']);
        foreach ($admins as $uid) {
            $this->set_permiso($caja_id, (int) $uid, $full);
        }
    }

    /**
     * Usuarios con permiso abrir_cerrar en la caja (para selector certificador).
     *
     * @param int $caja_id
     * @return array<int, array{id:int,name:string}>
     */
    public function list_certificadores($caja_id) {
        global $wpdb;
        $caja_id = absint($caja_id);
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$this->permisos_table()} WHERE caja_id = %d AND abrir_cerrar = 1",
            $caja_id
        )) ?: [];
        // Incluir admins
        $admins = get_users(['role__in' => ['administrator', 'riverso_admin'], 'fields' => ['ID', 'display_name']]);
        $seen = [];
        $out = [];
        foreach ($admins as $u) {
            $seen[(int) $u->ID] = true;
            $out[] = ['id' => (int) $u->ID, 'name' => $u->display_name];
        }
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            if (isset($seen[$uid])) {
                continue;
            }
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }
            $seen[$uid] = true;
            $out[] = ['id' => $uid, 'name' => $user->display_name];
        }
        usort($out, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Arqueo pendiente de la caja (si existe).
     *
     * @param int $caja_id
     * @return array<string, mixed>|null
     */
    public function get_pending_arqueo($caja_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->arqueos_table()}
             WHERE caja_id = %d AND estado = 'pendiente'
             ORDER BY id DESC LIMIT 1",
            absint($caja_id)
        ), ARRAY_A);
        return $row ? $this->present_arqueo($row) : null;
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get_arqueo($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->arqueos_table()} WHERE id = %d LIMIT 1",
            absint($id)
        ), ARRAY_A);
        return $row ? $this->present_arqueo($row) : null;
    }

    /**
     * Arqueos pendientes asignados al certificador.
     *
     * @param int $user_id
     * @return array<int, array<string, mixed>>
     */
    public function list_pending_for_certifier($user_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, c.nombre AS caja_nombre
             FROM {$this->arqueos_table()} a
             INNER JOIN {$this->cajas_table()} c ON c.id = a.caja_id
             WHERE a.estado = 'pendiente' AND a.certificador_id = %d
             ORDER BY a.id DESC",
            absint($user_id)
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $item = $this->present_arqueo($row);
            $item['caja_nombre'] = (string) ($row['caja_nombre'] ?? '');
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Crea un arqueo. Si certificador = yo → aprueba y aplica; si no → pendiente.
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool,id?:int,estado?:string,message?:string}
     */
    public function create_arqueo(array $data) {
        global $wpdb;
        $caja_id = absint($data['caja_id'] ?? 0);
        $caja = $this->get($caja_id);
        if (!$caja) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        $tipo = (string) ($data['tipo'] ?? '');
        if (!in_array($tipo, ['apertura', 'cierre'], true)) {
            return ['ok' => false, 'message' => 'Tipo de arqueo inválido.'];
        }
        if ($tipo === 'apertura' && ($caja['estado'] ?? '') === 'abierta') {
            return ['ok' => false, 'message' => 'La caja ya está abierta.'];
        }
        if ($tipo === 'cierre' && ($caja['estado'] ?? '') !== 'abierta') {
            return ['ok' => false, 'message' => 'La caja ya está cerrada.'];
        }
        $pending = $this->get_pending_arqueo($caja_id);
        if ($pending) {
            return ['ok' => false, 'message' => 'Ya hay un arqueo pendiente de certificación.'];
        }

        $monto = round((float) ($data['monto_efectivo'] ?? 0), 2);
        if ($monto < 0) {
            return ['ok' => false, 'message' => 'El monto de efectivo no puede ser negativo.'];
        }
        $docs_rec = round((float) ($data['docs_recibidos'] ?? 0), 2);
        $docs_emi = round((float) ($data['docs_emitidos'] ?? 0), 2);
        $total = round($monto + $docs_rec - $docs_emi, 2);
        $saldo_sistema = $this->compute_saldo($caja_id);
        $diferencia = round($monto - $saldo_sistema, 2);

        $usuario_id = get_current_user_id() ?: 0;
        $certificador_id = absint($data['certificador_id'] ?? $usuario_id);
        if ($certificador_id <= 0) {
            $certificador_id = $usuario_id;
        }
        $self_cert = ($certificador_id === $usuario_id);
        $estado = $self_cert ? 'aprobado' : 'pendiente';

        $fecha = (string) ($data['fecha'] ?? '');
        if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}/', $fecha)) {
            $fecha = current_time('mysql');
        } else {
            $fecha = str_replace('T', ' ', $fecha);
            if (strlen($fecha) === 16) {
                $fecha .= ':00';
            }
        }

        $ok = $wpdb->insert($this->arqueos_table(), [
            'caja_id' => $caja_id,
            'tipo' => $tipo,
            'fecha' => $fecha,
            'monto_efectivo' => $monto,
            'docs_recibidos' => $docs_rec,
            'docs_emitidos' => $docs_emi,
            'total' => $total,
            'saldo_sistema' => $saldo_sistema,
            'diferencia' => $diferencia,
            'usuario_id' => $usuario_id ?: null,
            'certificador_id' => $certificador_id ?: null,
            'estado' => $estado,
            'aprobado_por' => $self_cert ? ($usuario_id ?: null) : null,
            'aprobado_at' => $self_cert ? current_time('mysql') : null,
            'created_at' => current_time('mysql'),
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo registrar el arqueo.'];
        }
        $arqueo_id = (int) $wpdb->insert_id;

        if ($self_cert) {
            $apply = $this->apply_arqueo($arqueo_id);
            if (empty($apply['ok'])) {
                return $apply;
            }
        }

        return ['ok' => true, 'id' => $arqueo_id, 'estado' => $estado];
    }

    /**
     * Aplica un arqueo aprobado: movimiento de ajuste + cambio de estado.
     *
     * @param int $arqueo_id
     * @return array{ok:bool,message?:string}
     */
    public function apply_arqueo($arqueo_id) {
        global $wpdb;
        $arqueo = $this->get_arqueo($arqueo_id);
        if (!$arqueo) {
            return ['ok' => false, 'message' => 'Arqueo no encontrado.'];
        }
        if (($arqueo['estado'] ?? '') !== 'aprobado') {
            return ['ok' => false, 'message' => 'El arqueo no está aprobado.'];
        }
        $caja_id = (int) $arqueo['caja_id'];
        $saldo_sistema = $this->compute_saldo($caja_id);
        $ajuste = round((float) $arqueo['monto_efectivo'] - $saldo_sistema, 2);

        if (abs($ajuste) > 0.009) {
            $this->add_movimiento([
                'caja_id' => $caja_id,
                'tipo' => 'ajuste_arqueo',
                'monto' => $ajuste,
                'origen' => 'arqueo',
                'origen_id' => $arqueo_id,
                'detalle' => ($arqueo['tipo'] === 'apertura' ? 'Apertura' : 'Cierre')
                    . ' de caja (ajuste)',
                'fecha' => $arqueo['fecha'],
                'usuario_id' => $arqueo['usuario_id'],
            ]);
        } elseif (($arqueo['tipo'] ?? '') === 'apertura' && abs((float) $arqueo['monto_efectivo']) < 0.009) {
            // Apertura en 0: registrar movimiento 0 para trazabilidad opcional — omitir.
        }

        $nuevo_estado = ($arqueo['tipo'] ?? '') === 'apertura' ? 'abierta' : 'cerrada';
        $wpdb->update(
            $this->cajas_table(),
            [
                'estado' => $nuevo_estado,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $caja_id]
        );
        $this->refresh_saldo_cache($caja_id);
        return ['ok' => true];
    }

    /**
     * @param int $arqueo_id
     * @param int $approver_id
     * @return array{ok:bool,message?:string}
     */
    public function approve_arqueo($arqueo_id, $approver_id) {
        global $wpdb;
        $arqueo = $this->get_arqueo($arqueo_id);
        if (!$arqueo) {
            return ['ok' => false, 'message' => 'Arqueo no encontrado.'];
        }
        if (($arqueo['estado'] ?? '') !== 'pendiente') {
            return ['ok' => false, 'message' => 'El arqueo no está pendiente.'];
        }
        if ((int) ($arqueo['certificador_id'] ?? 0) !== (int) $approver_id
            && !user_can($approver_id, 'manage_options')
            && !user_can($approver_id, 'riverso_manage_cash_accounts')) {
            return ['ok' => false, 'message' => 'No eres el certificador de este arqueo.'];
        }
        $wpdb->update(
            $this->arqueos_table(),
            [
                'estado' => 'aprobado',
                'aprobado_por' => absint($approver_id),
                'aprobado_at' => current_time('mysql'),
            ],
            ['id' => absint($arqueo_id)]
        );
        return $this->apply_arqueo($arqueo_id);
    }

    /**
     * @param int $arqueo_id
     * @param int $rejector_id
     * @return array{ok:bool,message?:string}
     */
    public function reject_arqueo($arqueo_id, $rejector_id) {
        global $wpdb;
        $arqueo = $this->get_arqueo($arqueo_id);
        if (!$arqueo) {
            return ['ok' => false, 'message' => 'Arqueo no encontrado.'];
        }
        if (($arqueo['estado'] ?? '') !== 'pendiente') {
            return ['ok' => false, 'message' => 'El arqueo no está pendiente.'];
        }
        if ((int) ($arqueo['certificador_id'] ?? 0) !== (int) $rejector_id
            && !user_can($rejector_id, 'manage_options')
            && !user_can($rejector_id, 'riverso_manage_cash_accounts')) {
            return ['ok' => false, 'message' => 'No eres el certificador de este arqueo.'];
        }
        $ok = $wpdb->update(
            $this->arqueos_table(),
            [
                'estado' => 'rechazado',
                'aprobado_por' => absint($rejector_id),
                'aprobado_at' => current_time('mysql'),
            ],
            ['id' => absint($arqueo_id)]
        );
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo rechazar el arqueo.'];
        }
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function add_movimiento(array $data) {
        global $wpdb;
        $caja_id = absint($data['caja_id'] ?? 0);
        if ($caja_id <= 0 || !$this->get($caja_id)) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        $fecha = (string) ($data['fecha'] ?? current_time('mysql'));
        if ($fecha === '') {
            $fecha = current_time('mysql');
        }
        $ok = $wpdb->insert($this->movimientos_table(), [
            'caja_id' => $caja_id,
            'tipo' => substr((string) ($data['tipo'] ?? 'ajuste_arqueo'), 0, 32),
            'monto' => round((float) ($data['monto'] ?? 0), 2),
            'origen' => substr((string) ($data['origen'] ?? ''), 0, 32),
            'origen_id' => !empty($data['origen_id']) ? absint($data['origen_id']) : null,
            'detalle' => substr((string) ($data['detalle'] ?? ''), 0, 255),
            'fecha' => $fecha,
            'usuario_id' => !empty($data['usuario_id']) ? absint($data['usuario_id']) : (get_current_user_id() ?: null),
            'created_at' => current_time('mysql'),
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo registrar el movimiento.'];
        }
        $this->refresh_saldo_cache($caja_id);
        return ['ok' => true, 'id' => (int) $wpdb->insert_id];
    }

    /**
     * Registra ingreso por pago de facturación.
     *
     * @param int    $caja_id
     * @param float  $monto
     * @param int    $payment_id
     * @param string $detalle
     * @return array{ok:bool,id?:int,message?:string}
     */
    public function register_payment_ingreso($caja_id, $monto, $payment_id, $detalle = '') {
        $caja = $this->get($caja_id);
        if (!$caja) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }
        if (($caja['estado'] ?? '') !== 'abierta') {
            return ['ok' => false, 'message' => 'La caja no está abierta.'];
        }
        $monto = round((float) $monto, 2);
        if ($monto <= 0) {
            return ['ok' => false, 'message' => 'El monto del ingreso debe ser positivo.'];
        }
        return $this->add_movimiento([
            'caja_id' => $caja_id,
            'tipo' => 'ingreso_pago',
            'monto' => $monto,
            'origen' => 'billing_payment',
            'origen_id' => absint($payment_id),
            'detalle' => $detalle !== '' ? $detalle : 'Pago facturación',
            'fecha' => current_time('mysql'),
            'usuario_id' => get_current_user_id(),
        ]);
    }

    /**
     * @param int $caja_id
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function list_movimientos($caja_id, $limit = 200) {
        global $wpdb;
        $caja_id = absint($caja_id);
        $limit = max(1, min(500, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->movimientos_table()}
             WHERE caja_id = %d
             ORDER BY fecha ASC, id ASC
             LIMIT %d",
            $caja_id,
            $limit
        ), ARRAY_A) ?: [];
        $running = 0.0;
        $out = [];
        foreach ($rows as $row) {
            $running = round($running + (float) $row['monto'], 2);
            $user_name = '';
            if (!empty($row['usuario_id'])) {
                $u = get_userdata((int) $row['usuario_id']);
                $user_name = $u ? $u->display_name : '';
            }
            $out[] = [
                'id' => (int) $row['id'],
                'caja_id' => (int) $row['caja_id'],
                'tipo' => (string) $row['tipo'],
                'monto' => round((float) $row['monto'], 2),
                'origen' => (string) $row['origen'],
                'origen_id' => $row['origen_id'] !== null ? (int) $row['origen_id'] : null,
                'detalle' => (string) $row['detalle'],
                'fecha' => (string) $row['fecha'],
                'usuario_id' => $row['usuario_id'] !== null ? (int) $row['usuario_id'] : null,
                'usuario_nombre' => $user_name,
                'saldo_acumulado' => $running,
            ];
        }
        return array_reverse($out);
    }

    /**
     * Cajas abiertas donde el usuario puede pagar.
     *
     * @param int $user_id
     * @return array<int, array{id:int,nombre:string}>
     */
    public function list_payable_open($user_id) {
        $all = $this->list_all(true);
        $out = [];
        foreach ($all as $caja) {
            if (($caja['estado'] ?? '') !== 'abierta') {
                continue;
            }
            if (!$this->user_has_perm((int) $caja['id'], $user_id, 'pagar')) {
                continue;
            }
            $out[] = [
                'id' => (int) $caja['id'],
                'nombre' => (string) $caja['nombre'],
            ];
        }
        return $out;
    }

    /**
     * Filas para la tabla Informacion de cajas (con permisos del viewer).
     *
     * @param int $user_id
     * @return array<int, array<string, mixed>>
     */
    public function list_for_manejo($user_id) {
        $manage = user_can($user_id, 'manage_options') || user_can($user_id, 'riverso_manage_cash_accounts');
        $all = $this->list_all(true);
        $out = [];
        foreach ($all as $caja) {
            $id = (int) $caja['id'];
            if (!$manage && !$this->user_has_any_perm($id, $user_id)) {
                continue;
            }
            $can_ver = $this->user_has_perm($id, $user_id, 'ver_saldo');
            $saldo = $this->compute_saldo($id);
            $pending = $this->get_pending_arqueo($id);
            $docs_rec = 0.0;
            $docs_emi = 0.0;
            $transfer_pend = 0.0;
            $total = round($saldo + $docs_rec - $docs_emi, 2);
            $out[] = [
                'id' => $id,
                'nombre' => $caja['nombre'],
                'tipo' => $caja['tipo'],
                'tipo_label' => $caja['tipo_label'],
                'estado' => $caja['estado'],
                'saldo_efectivo' => $can_ver ? $saldo : null,
                'docs_recibidos' => $can_ver ? $docs_rec : null,
                'docs_emitidos' => $can_ver ? $docs_emi : null,
                'total' => $can_ver ? $total : null,
                'transferencia_pendiente' => $can_ver ? $transfer_pend : null,
                'can_ver_saldo' => $can_ver,
                'can_abrir_cerrar' => $this->user_has_perm($id, $user_id, 'abrir_cerrar'),
                'can_pagar' => $this->user_has_perm($id, $user_id, 'pagar'),
                'can_transferir' => $this->user_has_perm($id, $user_id, 'transferir'),
                'pending_arqueo' => $pending,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present_caja(array $row) {
        $tipo = (string) ($row['tipo'] ?? 'fisica');
        return [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'tipo' => $tipo,
            'tipo_label' => self::TIPOS[$tipo] ?? $tipo,
            'estado' => (string) ($row['estado'] ?? 'cerrada'),
            'saldo_efectivo' => round((float) ($row['saldo_efectivo'] ?? 0), 2),
            'activo' => !empty($row['activo']),
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present_arqueo(array $row) {
        return [
            'id' => (int) $row['id'],
            'caja_id' => (int) $row['caja_id'],
            'tipo' => (string) $row['tipo'],
            'fecha' => (string) $row['fecha'],
            'monto_efectivo' => round((float) $row['monto_efectivo'], 2),
            'docs_recibidos' => round((float) $row['docs_recibidos'], 2),
            'docs_emitidos' => round((float) $row['docs_emitidos'], 2),
            'total' => round((float) $row['total'], 2),
            'saldo_sistema' => round((float) $row['saldo_sistema'], 2),
            'diferencia' => round((float) $row['diferencia'], 2),
            'usuario_id' => $row['usuario_id'] !== null ? (int) $row['usuario_id'] : null,
            'certificador_id' => $row['certificador_id'] !== null ? (int) $row['certificador_id'] : null,
            'estado' => (string) $row['estado'],
            'aprobado_por' => $row['aprobado_por'] !== null ? (int) $row['aprobado_por'] : null,
            'aprobado_at' => $row['aprobado_at'] ?? null,
        ];
    }
}
