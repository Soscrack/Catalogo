<?php
/**
 * Contactos de un cliente comercial (riverso_clientes), no de usuarios de WordPress.
 *
 * Un correo inhabilitado no se reactiva: hay que eliminarlo para volver a guardarlo.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Cliente_Contact_Repository {

    /** @var string */
    private $table;

    /** @var string */
    private $clientes;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'riverso_cliente_contactos';
        $this->clientes = $wpdb->prefix . 'riverso_clientes';
    }

    public static function ensure_schema() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_cliente_contactos';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cliente_id BIGINT UNSIGNED NOT NULL,
            primer_nombre VARCHAR(100) NOT NULL DEFAULT '',
            segundo_nombre VARCHAR(100) NOT NULL DEFAULT '',
            apellido_paterno VARCHAR(100) NOT NULL DEFAULT '',
            apellido_materno VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            fono_oficina VARCHAR(50) NOT NULL DEFAULT '',
            fono_movil VARCHAR(50) NOT NULL DEFAULT '',
            observaciones TEXT NULL,
            fecha_nacimiento DATE NULL DEFAULT NULL,
            lab_calle VARCHAR(180) NOT NULL DEFAULT '',
            lab_numero VARCHAR(30) NOT NULL DEFAULT '',
            lab_depto VARCHAR(30) NOT NULL DEFAULT '',
            lab_ciudad VARCHAR(100) NOT NULL DEFAULT '',
            lab_comuna VARCHAR(100) NOT NULL DEFAULT '',
            lab_pais VARCHAR(80) NOT NULL DEFAULT '',
            lab_codigo_postal VARCHAR(20) NOT NULL DEFAULT '',
            part_calle VARCHAR(180) NOT NULL DEFAULT '',
            part_numero VARCHAR(30) NOT NULL DEFAULT '',
            part_depto VARCHAR(30) NOT NULL DEFAULT '',
            part_ciudad VARCHAR(100) NOT NULL DEFAULT '',
            part_comuna VARCHAR(100) NOT NULL DEFAULT '',
            part_pais VARCHAR(80) NOT NULL DEFAULT '',
            part_codigo_postal VARCHAR(20) NOT NULL DEFAULT '',
            es_primario TINYINT(1) NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            origen VARCHAR(20) NOT NULL DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ctc_cliente (cliente_id),
            KEY idx_ctc_email (cliente_id, email),
            KEY idx_ctc_activo (activo),
            KEY idx_ctc_paterno (apellido_paterno, primer_nombre)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function search($filters = []) {
        global $wpdb;
        $this->ensure_ready();

        $where = ['1=1'];
        $args = [];

        $cliente_id = isset($filters['cliente_id']) ? (int) $filters['cliente_id'] : 0;
        if ($cliente_id > 0) {
            $where[] = 'c.cliente_id = %d';
            $args[] = $cliente_id;
        }

        if (!empty($filters['solo_activos'])) {
            $where[] = 'c.activo = 1';
        }

        $like_map = [
            'primer_nombre' => 'primer_nombre',
            'segundo_nombre' => 'segundo_nombre',
            'apellido_paterno' => 'apellido_paterno',
            'apellido_materno' => 'apellido_materno',
            'email' => 'email',
        ];
        foreach ($like_map as $key => $column) {
            $value = isset($filters[$key]) ? trim((string) $filters[$key]) : '';
            if ($value === '') {
                continue;
            }
            $where[] = "c.{$column} LIKE %s";
            $args[] = '%' . $wpdb->esc_like($value) . '%';
        }

        $sql = "SELECT c.*, cl.nombre_fantasia AS cliente_nombre
            FROM {$this->table} c
            INNER JOIN {$this->clientes} cl ON cl.id = c.cliente_id
            WHERE " . implode(' AND ', $where) . '
            ORDER BY c.apellido_paterno ASC, c.primer_nombre ASC, c.id ASC
            LIMIT 100';

        if ($args) {
            $sql = $wpdb->prepare($sql, $args);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get($id) {
        $row = $this->get_raw((int) $id);
        return $row ? $this->present($row) : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, message?: string, status?: string, contact?: array<string, mixed>}
     */
    public function save($input) {
        global $wpdb;
        $this->ensure_ready();

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $cliente_id = isset($input['cliente_id']) ? (int) $input['cliente_id'] : 0;
        if ($cliente_id <= 0 || !$this->cliente_exists($cliente_id)) {
            return ['ok' => false, 'message' => 'Elige el cliente de este contacto'];
        }

        $primer = $this->clip((string) ($input['primer_nombre'] ?? ''), 100);
        $paterno = $this->clip((string) ($input['apellido_paterno'] ?? ''), 100);
        if ($primer === '' || $paterno === '') {
            return ['ok' => false, 'message' => 'El primer nombre y el apellido paterno son obligatorios'];
        }

        $email = $this->normalize_email((string) ($input['email'] ?? ''));
        if ($email === false) {
            return ['ok' => false, 'message' => 'El correo no es válido'];
        }

        $birth = $this->birth_date($input);
        if ($birth === false) {
            return ['ok' => false, 'message' => 'La fecha de nacimiento está incompleta o no es válida'];
        }

        $activo = !empty($input['activo']) ? 1 : 0;
        $current = null;
        if ($id > 0) {
            $current = $this->get_raw($id);
            if (!$current || (int) $current['cliente_id'] !== $cliente_id) {
                return ['ok' => false, 'message' => 'Contacto no encontrado'];
            }
            if (!(int) $current['activo'] && $activo === 1) {
                return [
                    'ok' => false,
                    'status' => 'blocked',
                    'message' => $this->blocked_message($email !== '' ? $email : (string) $current['email']),
                ];
            }
        }

        if ($email !== '') {
            $other = $this->find_by_email($cliente_id, $email);
            if ($other && (int) $other['id'] !== $id) {
                if (!(int) $other['activo']) {
                    return [
                        'ok' => false,
                        'status' => 'blocked',
                        'message' => $this->blocked_message($email),
                    ];
                }
                return ['ok' => false, 'message' => 'Ese correo ya pertenece a otro contacto de este cliente'];
            }
        }

        $row = [
            'cliente_id' => $cliente_id,
            'primer_nombre' => $primer,
            'segundo_nombre' => $this->clip((string) ($input['segundo_nombre'] ?? ''), 100),
            'apellido_paterno' => $paterno,
            'apellido_materno' => $this->clip((string) ($input['apellido_materno'] ?? ''), 100),
            'email' => $email,
            'fono_oficina' => $this->clip((string) ($input['fono_oficina'] ?? ''), 50),
            'fono_movil' => $this->clip((string) ($input['fono_movil'] ?? ''), 50),
            'observaciones' => $this->clip_block((string) ($input['observaciones'] ?? ''), 2000),
            'fecha_nacimiento' => $birth,
            'lab_calle' => $this->clip((string) ($input['lab_calle'] ?? ''), 180),
            'lab_numero' => $this->clip((string) ($input['lab_numero'] ?? ''), 30),
            'lab_depto' => $this->clip((string) ($input['lab_depto'] ?? ''), 30),
            'lab_ciudad' => $this->clip((string) ($input['lab_ciudad'] ?? ''), 100),
            'lab_comuna' => $this->clip((string) ($input['lab_comuna'] ?? ''), 100),
            'lab_pais' => $this->clip((string) ($input['lab_pais'] ?? ''), 80),
            'lab_codigo_postal' => $this->clip((string) ($input['lab_codigo_postal'] ?? ''), 20),
            'part_calle' => $this->clip((string) ($input['part_calle'] ?? ''), 180),
            'part_numero' => $this->clip((string) ($input['part_numero'] ?? ''), 30),
            'part_depto' => $this->clip((string) ($input['part_depto'] ?? ''), 30),
            'part_ciudad' => $this->clip((string) ($input['part_ciudad'] ?? ''), 100),
            'part_comuna' => $this->clip((string) ($input['part_comuna'] ?? ''), 100),
            'part_pais' => $this->clip((string) ($input['part_pais'] ?? ''), 80),
            'part_codigo_postal' => $this->clip((string) ($input['part_codigo_postal'] ?? ''), 20),
            'es_primario' => !empty($input['es_primario']) ? 1 : 0,
            'activo' => $activo,
            'origen' => $this->origen((string) ($input['origen'] ?? 'manual')),
        ];

        if ($id > 0) {
            $updated = $wpdb->update($this->table, $row, ['id' => $id]);
            if ($updated === false) {
                return ['ok' => false, 'message' => 'No se pudo actualizar el contacto'];
            }
        } else {
            $inserted = $wpdb->insert($this->table, $row);
            if (!$inserted) {
                return ['ok' => false, 'message' => 'No se pudo crear el contacto'];
            }
            $id = (int) $wpdb->insert_id;
        }

        if ($row['es_primario']) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table} SET es_primario = 0 WHERE cliente_id = %d AND id <> %d",
                    $cliente_id,
                    $id
                )
            );
        }

        $contact = $this->get($id);
        $message = $activo ? 'Contacto guardado' : 'Contacto inhabilitado. Para volver a usar su correo hay que eliminarlo en Contactos.';
        return [
            'ok' => true,
            'id' => $id,
            'message' => $message,
            'contact' => $contact,
        ];
    }

    /**
     * @param int $id
     * @return array{ok: bool, message?: string}
     */
    public function delete($id) {
        global $wpdb;
        $this->ensure_ready();
        $id = (int) $id;
        $row = $this->get_raw($id);
        if (!$row) {
            return ['ok' => false, 'message' => 'Contacto no encontrado'];
        }
        $deleted = $wpdb->delete($this->table, ['id' => $id]);
        if (!$deleted) {
            return ['ok' => false, 'message' => 'No se pudo eliminar el contacto'];
        }
        return [
            'ok' => true,
            'message' => 'Contacto eliminado. Su correo ya no se usa al facturar.',
            'email' => (string) ($row['email'] ?? ''),
            'cliente_id' => (int) ($row['cliente_id'] ?? 0),
        ];
    }

    /**
     * Deja el contacto fuera del envío automático. No borra la ficha.
     *
     * @param int $id
     * @return array{ok: bool, message?: string, contact?: array<string, mixed>}
     */
    public function disable($id) {
        global $wpdb;
        $this->ensure_ready();
        $id = (int) $id;
        $row = $this->get_raw($id);
        if (!$row) {
            return ['ok' => false, 'message' => 'Contacto no encontrado'];
        }
        if (!(int) $row['activo']) {
            return [
                'ok' => true,
                'message' => 'El contacto ya estaba inhabilitado',
                'contact' => $this->present($row),
            ];
        }
        $updated = $wpdb->update($this->table, ['activo' => 0], ['id' => $id]);
        if ($updated === false) {
            return ['ok' => false, 'message' => 'No se pudo inhabilitar el contacto'];
        }
        $contact = $this->get($id);
        return [
            'ok' => true,
            'message' => 'Contacto inhabilitado. Su correo ya no se usa al facturar. Para volver a usarlo hay que eliminarlo en Contactos.',
            'contact' => $contact,
        ];
    }

    /**
     * Inhabilita el contacto de un cliente que usa este correo, si existe.
     *
     * @param int    $cliente_id
     * @param string $email
     * @return array{ok: bool, status: string, id?: int}
     */
    public function disable_email($cliente_id, $email) {
        global $wpdb;
        $this->ensure_ready();
        $cliente_id = (int) $cliente_id;
        $email = $this->normalize_email($email);
        if ($cliente_id <= 0 || $email === false || $email === '') {
            return ['ok' => true, 'status' => 'skipped'];
        }
        $row = $this->find_by_email($cliente_id, $email);
        if (!$row) {
            return ['ok' => true, 'status' => 'missing'];
        }
        if (!(int) $row['activo']) {
            return ['ok' => true, 'status' => 'already', 'id' => (int) $row['id']];
        }
        $updated = $wpdb->update($this->table, ['activo' => 0], ['id' => (int) $row['id']]);
        if ($updated === false) {
            return ['ok' => false, 'status' => 'error'];
        }
        return ['ok' => true, 'status' => 'disabled', 'id' => (int) $row['id']];
    }

    /**
     * Guarda el correo usado al facturar o boletear. No reactiva un contacto inhabilitado.
     *
     * @param int    $cliente_id
     * @param string $email
     * @param string $origen
     * @param string $phone
     * @return array{ok: bool, status?: string, message?: string, id?: int}
     */
    /**
     * @param int    $cliente_id
     * @param string $email
     * @return bool
     */
    public function inactive_email($cliente_id, $email) {
        $this->ensure_ready();
        $email = $this->normalize_email($email);
        if ($email === false || $email === '' || (int) $cliente_id <= 0) {
            return false;
        }
        $row = $this->find_by_email((int) $cliente_id, $email);
        return $row && !(int) $row['activo'];
    }

    /**
     * Correos inhabilitados de un cliente, en una sola lectura.
     * No revisa el esquema: esa tabla ya existe al facturar.
     *
     * @param int $cliente_id
     * @return array<string, true>
     */
    public function inactive_emails($cliente_id) {
        global $wpdb;
        $cliente_id = (int) $cliente_id;
        if ($cliente_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT email FROM {$this->table}
             WHERE cliente_id = %d AND activo = 0 AND email <> ''",
            $cliente_id
        ));
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $email) {
            $email = $this->normalize_email((string) $email);
            if ($email === false || $email === '') {
                continue;
            }
            $out[$email] = true;
        }
        return $out;
    }

    /**
     * Correos activos de la libreta, para ofrecerlos al emitir un DTE.
     * El primario va primero.
     *
     * @param int $cliente_id
     * @return array<int, array{id:int,email:string}>
     */
    public function billing_emails($cliente_id) {
        global $wpdb;
        $cliente_id = (int) $cliente_id;
        if ($cliente_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, email FROM {$this->table}
                 WHERE cliente_id = %d AND activo = 1 AND email <> ''
                 ORDER BY es_primario DESC, apellido_paterno ASC, primer_nombre ASC, id ASC",
                $cliente_id
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $email = $this->normalize_email((string) ($row['email'] ?? ''));
            if ($email === false || $email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
            ];
        }
        return $out;
    }

    /**
     * Correos activos con el nombre del contacto, para elegir destinatarios (cotizaciones).
     * El primario va primero.
     *
     * @param int $cliente_id
     * @return array<int, array{id:int,email:string,nombre:string,primario:bool}>
     */
    public function email_book($cliente_id) {
        global $wpdb;
        $cliente_id = (int) $cliente_id;
        if ($cliente_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, email, primer_nombre, apellido_paterno, es_primario FROM {$this->table}
                 WHERE cliente_id = %d AND activo = 1 AND email <> ''
                 ORDER BY es_primario DESC, apellido_paterno ASC, primer_nombre ASC, id ASC",
                $cliente_id
            ),
            ARRAY_A
        );
        $out = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $email = $this->normalize_email((string) ($row['email'] ?? ''));
            if ($email === false || $email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $nombre = trim((string) ($row['primer_nombre'] ?? '') . ' ' . (string) ($row['apellido_paterno'] ?? ''));
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
                // «Contacto Contacto» es el nombre de relleno de los contactos creados desde un correo.
                'nombre' => $nombre === 'Contacto Contacto' ? '' : $nombre,
                'primario' => (int) ($row['es_primario'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    public function remember($cliente_id, $email, $origen = 'facturacion', $phone = '') {
        $this->ensure_ready();
        $cliente_id = (int) $cliente_id;
        $email = $this->normalize_email($email);
        if ($cliente_id <= 0 || $email === false || $email === '') {
            return ['ok' => true, 'status' => 'skipped'];
        }
        if (!$this->cliente_exists($cliente_id)) {
            return ['ok' => true, 'status' => 'skipped'];
        }

        $existing = $this->find_by_email($cliente_id, $email);
        if ($existing && !(int) $existing['activo']) {
            return [
                'ok' => false,
                'status' => 'blocked',
                'message' => $this->blocked_message($email),
            ];
        }
        if ($existing) {
            $phone = $this->clip($phone, 50);
            if ($phone !== '' && (string) $existing['fono_movil'] === '') {
                global $wpdb;
                $wpdb->update($this->table, ['fono_movil' => $phone], ['id' => (int) $existing['id']]);
            }
            return ['ok' => true, 'status' => 'exists', 'id' => (int) $existing['id']];
        }

        $name = $this->name_from_email($email);
        return $this->save([
            'cliente_id' => $cliente_id,
            'primer_nombre' => $name['primer_nombre'],
            'segundo_nombre' => $name['segundo_nombre'],
            'apellido_paterno' => $name['apellido_paterno'],
            'apellido_materno' => $name['apellido_materno'],
            'email' => $email,
            'fono_movil' => $phone,
            'activo' => 1,
            'origen' => $origen,
            'lab_pais' => 'CHILE',
            'part_pais' => 'CHILE',
        ]);
    }

    /**
     * Copia a contactos los correos que ya estaban guardados para facturar.
     * Se ejecuta una vez. No reactiva un contacto inhabilitado.
     */
    public function import_saved_emails() {
        if (get_option('riverso_cliente_contactos_import_v1') === '1') {
            return;
        }
        $this->ensure_ready();
        global $wpdb;
        $emails_table = $wpdb->prefix . 'riverso_billing_receiver_emails';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $emails_table));
        if ($found !== $emails_table) {
            return;
        }

        $rows = $wpdb->get_results("SELECT rut, email FROM {$emails_table}", ARRAY_A);
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $cliente_id = $this->cliente_id_by_rut((string) ($row['rut'] ?? ''));
            if ($cliente_id <= 0) {
                continue;
            }
            $this->remember($cliente_id, (string) ($row['email'] ?? ''), 'facturacion');
        }

        $primaries = $wpdb->get_results(
            "SELECT id, has_contacto, primer_nombre, apellido_paterno, contacto_telefono, contacto_email
             FROM {$this->clientes}
             WHERE contacto_email IS NOT NULL AND contacto_email <> ''",
            ARRAY_A
        );
        if (is_array($primaries)) {
            foreach ($primaries as $row) {
                $cliente_id = (int) $row['id'];
                $email = (string) $row['contacto_email'];
                if ($row['primer_nombre'] !== '' && $row['apellido_paterno'] !== '') {
                    $this->sync_primary([
                        'id' => $cliente_id,
                        'has_contacto' => true,
                        'primer_nombre' => (string) $row['primer_nombre'],
                        'apellido_paterno' => (string) $row['apellido_paterno'],
                        'contacto_telefono' => (string) $row['contacto_telefono'],
                        'contacto_email' => $email,
                    ]);
                    continue;
                }
                $this->remember($cliente_id, $email, 'primario', (string) $row['contacto_telefono']);
            }
        }

        update_option('riverso_cliente_contactos_import_v1', '1', false);
    }

    /**
     * Copia el contacto primario del cliente a la tabla de contactos.
     *
     * @param array<string, mixed> $customer
     * @return array{ok: bool, status?: string, message?: string}
     */
    public function sync_primary($customer) {
        $cliente_id = isset($customer['id']) ? (int) $customer['id'] : 0;
        if ($cliente_id <= 0 || empty($customer['has_contacto'])) {
            return ['ok' => true, 'status' => 'skipped'];
        }

        $email = $this->normalize_email((string) ($customer['contacto_email'] ?? ''));
        if ($email === false) {
            $email = '';
        }

        $base = [
            'cliente_id' => $cliente_id,
            'primer_nombre' => (string) ($customer['primer_nombre'] ?? ''),
            'apellido_paterno' => (string) ($customer['apellido_paterno'] ?? ''),
            'fono_movil' => (string) ($customer['contacto_telefono'] ?? ''),
            'email' => $email,
            'es_primario' => 1,
            'activo' => 1,
            'origen' => 'primario',
        ];
        if ($base['primer_nombre'] === '' || $base['apellido_paterno'] === '') {
            return ['ok' => true, 'status' => 'skipped'];
        }

        if ($email !== '') {
            $existing = $this->find_by_email($cliente_id, $email);
            if ($existing && !(int) $existing['activo']) {
                return [
                    'ok' => false,
                    'status' => 'blocked',
                    'message' => $this->blocked_message($email),
                ];
            }
            if ($existing) {
                $merged = $this->input_from_row($existing);
                $merged['primer_nombre'] = $base['primer_nombre'];
                $merged['apellido_paterno'] = $base['apellido_paterno'];
                $merged['fono_movil'] = $base['fono_movil'] !== '' ? $base['fono_movil'] : $merged['fono_movil'];
                $merged['email'] = $email;
                $merged['es_primario'] = 1;
                $merged['activo'] = 1;
                return $this->save($merged);
            }
        } else {
            $primary = $this->find_primary($cliente_id);
            if ($primary) {
                $merged = $this->input_from_row($primary);
                $merged['primer_nombre'] = $base['primer_nombre'];
                $merged['apellido_paterno'] = $base['apellido_paterno'];
                $merged['fono_movil'] = $base['fono_movil'];
                $merged['email'] = '';
                $merged['es_primario'] = 1;
                return $this->save($merged);
            }
        }

        return $this->save($base);
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    private function get_raw($id) {
        global $wpdb;
        $this->ensure_ready();
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, cl.nombre_fantasia AS cliente_nombre
                 FROM {$this->table} c
                 INNER JOIN {$this->clientes} cl ON cl.id = c.cliente_id
                 WHERE c.id = %d",
                $id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @param int    $cliente_id
     * @param string $email
     * @return array<string, mixed>|null
     */
    private function find_by_email($cliente_id, $email) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE cliente_id = %d AND email = %s ORDER BY id ASC LIMIT 1",
                (int) $cliente_id,
                $email
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @param int $cliente_id
     * @return array<string, mixed>|null
     */
    private function find_primary($cliente_id) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE cliente_id = %d AND es_primario = 1 ORDER BY id DESC LIMIT 1",
                (int) $cliente_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @param int $cliente_id
     * @return bool
     */
    /**
     * @param string $rut
     * @return int
     */
    private function cliente_id_by_rut($rut) {
        global $wpdb;
        $clean = strtoupper((string) preg_replace('/[^0-9kK]/', '', (string) $rut));
        if (strlen($clean) < 2) {
            return 0;
        }
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->clientes}
                 WHERE REPLACE(REPLACE(UPPER(rut), '.', ''), '-', '') = %s
                 ORDER BY activo DESC, id ASC
                 LIMIT 1",
                $clean
            )
        );
        return (int) $id;
    }

    /**
     * @param string $email
     * @return array{primer_nombre: string, segundo_nombre: string, apellido_paterno: string, apellido_materno: string}
     */
    private function name_from_email($email) {
        $empty = [
            'primer_nombre' => 'Contacto',
            'segundo_nombre' => '',
            'apellido_paterno' => 'Contacto',
            'apellido_materno' => '',
        ];
        $local = strstr($email, '@', true);
        if (!is_string($local) || $local === '') {
            return $empty;
        }
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $parts = preg_split('/\s+/', trim($local));
        if (!is_array($parts)) {
            return $empty;
        }
        $parts = array_values(array_filter($parts, 'strlen'));
        if (!$parts) {
            return $empty;
        }
        $parts = array_map(function ($part) {
            $part = strtolower($part);
            if (function_exists('mb_convert_case')) {
                return mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
            }
            return ucfirst($part);
        }, $parts);
        if (count($parts) === 1) {
            $empty['primer_nombre'] = $parts[0];
            return $empty;
        }
        $materno = count($parts) >= 3 ? $parts[count($parts) - 1] : '';
        $paterno = $parts[count($parts) - (count($parts) >= 3 ? 2 : 1)];
        return [
            'primer_nombre' => $parts[0],
            'segundo_nombre' => count($parts) > 3 ? $parts[1] : '',
            'apellido_paterno' => $paterno,
            'apellido_materno' => $materno,
        ];
    }

    private function cliente_exists($cliente_id) {
        global $wpdb;
        $found = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->clientes} WHERE id = %d", (int) $cliente_id)
        );
        return (int) $found > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function input_from_row($row) {
        $birth = $this->split_birth(isset($row['fecha_nacimiento']) ? (string) $row['fecha_nacimiento'] : '');
        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'primer_nombre' => (string) $row['primer_nombre'],
            'segundo_nombre' => (string) $row['segundo_nombre'],
            'apellido_paterno' => (string) $row['apellido_paterno'],
            'apellido_materno' => (string) $row['apellido_materno'],
            'email' => (string) $row['email'],
            'fono_oficina' => (string) $row['fono_oficina'],
            'fono_movil' => (string) $row['fono_movil'],
            'observaciones' => (string) $row['observaciones'],
            'nacimiento_dia' => $birth['dia'],
            'nacimiento_mes' => $birth['mes'],
            'nacimiento_anio' => $birth['anio'],
            'lab_calle' => (string) $row['lab_calle'],
            'lab_numero' => (string) $row['lab_numero'],
            'lab_depto' => (string) $row['lab_depto'],
            'lab_ciudad' => (string) $row['lab_ciudad'],
            'lab_comuna' => (string) $row['lab_comuna'],
            'lab_pais' => (string) $row['lab_pais'],
            'lab_codigo_postal' => (string) $row['lab_codigo_postal'],
            'part_calle' => (string) $row['part_calle'],
            'part_numero' => (string) $row['part_numero'],
            'part_depto' => (string) $row['part_depto'],
            'part_ciudad' => (string) $row['part_ciudad'],
            'part_comuna' => (string) $row['part_comuna'],
            'part_pais' => (string) $row['part_pais'],
            'part_codigo_postal' => (string) $row['part_codigo_postal'],
            'es_primario' => (int) $row['es_primario'],
            'activo' => (int) $row['activo'],
            'origen' => (string) $row['origen'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present($row) {
        $birth = $this->split_birth(isset($row['fecha_nacimiento']) ? (string) $row['fecha_nacimiento'] : '');
        $nombre = trim(implode(' ', array_filter([
            (string) ($row['primer_nombre'] ?? ''),
            (string) ($row['segundo_nombre'] ?? ''),
            (string) ($row['apellido_paterno'] ?? ''),
            (string) ($row['apellido_materno'] ?? ''),
        ], 'strlen')));
        $fono = (string) ($row['fono_movil'] ?? '');
        if ($fono === '') {
            $fono = (string) ($row['fono_oficina'] ?? '');
        }
        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'cliente_nombre' => (string) ($row['cliente_nombre'] ?? ''),
            'primer_nombre' => (string) ($row['primer_nombre'] ?? ''),
            'segundo_nombre' => (string) ($row['segundo_nombre'] ?? ''),
            'apellido_paterno' => (string) ($row['apellido_paterno'] ?? ''),
            'apellido_materno' => (string) ($row['apellido_materno'] ?? ''),
            'nombre' => $nombre,
            'email' => (string) ($row['email'] ?? ''),
            'fono_oficina' => (string) ($row['fono_oficina'] ?? ''),
            'fono_movil' => (string) ($row['fono_movil'] ?? ''),
            'telefono' => $fono,
            'observaciones' => (string) ($row['observaciones'] ?? ''),
            'fecha_nacimiento' => $birth['iso'],
            'nacimiento_dia' => $birth['dia'],
            'nacimiento_mes' => $birth['mes'],
            'nacimiento_anio' => $birth['anio'],
            'lab_calle' => (string) ($row['lab_calle'] ?? ''),
            'lab_numero' => (string) ($row['lab_numero'] ?? ''),
            'lab_depto' => (string) ($row['lab_depto'] ?? ''),
            'lab_ciudad' => (string) ($row['lab_ciudad'] ?? ''),
            'lab_comuna' => (string) ($row['lab_comuna'] ?? ''),
            'lab_pais' => (string) ($row['lab_pais'] ?? ''),
            'lab_codigo_postal' => (string) ($row['lab_codigo_postal'] ?? ''),
            'part_calle' => (string) ($row['part_calle'] ?? ''),
            'part_numero' => (string) ($row['part_numero'] ?? ''),
            'part_depto' => (string) ($row['part_depto'] ?? ''),
            'part_ciudad' => (string) ($row['part_ciudad'] ?? ''),
            'part_comuna' => (string) ($row['part_comuna'] ?? ''),
            'part_pais' => (string) ($row['part_pais'] ?? ''),
            'part_codigo_postal' => (string) ($row['part_codigo_postal'] ?? ''),
            'es_primario' => (int) ($row['es_primario'] ?? 0) === 1,
            'activo' => (int) ($row['activo'] ?? 0) === 1,
            'origen' => (string) ($row['origen'] ?? 'manual'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return string|null|false
     */
    private function birth_date($input) {
        $dia = isset($input['nacimiento_dia']) ? (int) $input['nacimiento_dia'] : 0;
        $mes = isset($input['nacimiento_mes']) ? (int) $input['nacimiento_mes'] : 0;
        $anio = isset($input['nacimiento_anio']) ? (int) $input['nacimiento_anio'] : 0;
        if ($dia === 0 && $mes === 0 && $anio === 0) {
            return null;
        }
        if ($dia < 1 || $mes < 1 || $mes > 12 || $anio < 1900 || $anio > (int) gmdate('Y')) {
            return false;
        }
        if (!checkdate($mes, $dia, $anio)) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /**
     * @param string $value
     * @return array{iso: string, dia: int, mes: int, anio: int}
     */
    private function split_birth($value) {
        $empty = ['iso' => '', 'dia' => 0, 'mes' => 0, 'anio' => 0];
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $empty;
        }
        if ($m[1] === '0000') {
            return $empty;
        }
        return [
            'iso' => $m[1] . '-' . $m[2] . '-' . $m[3],
            'dia' => (int) $m[3],
            'mes' => (int) $m[2],
            'anio' => (int) $m[1],
        ];
    }

    /**
     * @param string $email
     * @return string|false
     */
    private function normalize_email($email) {
        $email = strtolower(trim(sanitize_email($email)));
        if ($email === '') {
            return '';
        }
        if (!is_email($email)) {
            return false;
        }
        return $email;
    }

    /**
     * @param string $email
     * @return string
     */
    private function blocked_message($email) {
        $email = $email !== '' ? $email : 'Este correo';
        return $email . ' está inhabilitado. Elimínalo en Contactos (desmarca Activo y pulsa Eliminar) antes de volver a guardarlo.';
    }

    /**
     * @param string $origen
     * @return string
     */
    private function origen($origen) {
        $origen = sanitize_key($origen);
        $allowed = ['manual', 'primario', 'factura', 'boleta', 'facturacion'];
        return in_array($origen, $allowed, true) ? $origen : 'manual';
    }

    /**
     * @param string $value
     * @param int    $max
     * @return string
     */
    private function clip($value, $max) {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    /**
     * @param string $value
     * @param int    $max
     * @return string
     */
    private function clip_block($value, $max) {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function ensure_ready() {
        static $ready = false;
        if ($ready) {
            return;
        }
        self::ensure_schema();
        $ready = true;
    }
}
