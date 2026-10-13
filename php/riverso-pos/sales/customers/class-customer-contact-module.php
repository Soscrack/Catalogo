<?php
/**
 * Contactos de clientes.
 *
 * Un cliente (usuario WooCommerce) tiene varios contactos.
 * El correo usado al boletear o facturar se guarda aquí.
 * Un contacto inhabilitado no se reactiva solo: hay que eliminarlo
 * desde el buscador de contactos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Contact_Module {

    private static $instance = null;
    private static $booted = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::create_tables();
        self::ensure_caps();

        add_action('wp_ajax_riverso_search_customer_directory', [$this, 'ajax_search_customers']);
        add_action('wp_ajax_riverso_search_contacts', [$this, 'ajax_search_contacts']);
        add_action('wp_ajax_riverso_customer_contacts', [$this, 'ajax_customer_contacts']);
        add_action('wp_ajax_riverso_get_contact', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_save_contact', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_delete_contact', [$this, 'ajax_delete']);
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_contactos';
    }

    public static function create_tables() {
        if (get_option('riverso_contactos_schema') === '1') {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            primer_nombre VARCHAR(120) NOT NULL DEFAULT '',
            segundo_nombre VARCHAR(120) NOT NULL DEFAULT '',
            apellido_paterno VARCHAR(120) NOT NULL DEFAULT '',
            apellido_materno VARCHAR(120) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            fono_oficina VARCHAR(40) NOT NULL DEFAULT '',
            fono_movil VARCHAR(40) NOT NULL DEFAULT '',
            fono_hogar VARCHAR(40) NOT NULL DEFAULT '',
            cargo VARCHAR(120) NOT NULL DEFAULT '',
            departamento VARCHAR(120) NOT NULL DEFAULT '',
            numero_oficina VARCHAR(40) NOT NULL DEFAULT '',
            observaciones TEXT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            fecha_nac_dia TINYINT UNSIGNED NOT NULL DEFAULT 0,
            fecha_nac_mes TINYINT UNSIGNED NOT NULL DEFAULT 0,
            fecha_nac_anio SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            lab_calle VARCHAR(190) NOT NULL DEFAULT '',
            lab_numero VARCHAR(40) NOT NULL DEFAULT '',
            lab_depto VARCHAR(80) NOT NULL DEFAULT '',
            lab_ciudad VARCHAR(120) NOT NULL DEFAULT '',
            lab_comuna VARCHAR(120) NOT NULL DEFAULT '',
            lab_pais VARCHAR(120) NOT NULL DEFAULT '',
            lab_codigo_postal VARCHAR(20) NOT NULL DEFAULT '',
            part_calle VARCHAR(190) NOT NULL DEFAULT '',
            part_numero VARCHAR(40) NOT NULL DEFAULT '',
            part_depto VARCHAR(80) NOT NULL DEFAULT '',
            part_ciudad VARCHAR(120) NOT NULL DEFAULT '',
            part_comuna VARCHAR(120) NOT NULL DEFAULT '',
            part_pais VARCHAR(120) NOT NULL DEFAULT '',
            part_codigo_postal VARCHAR(20) NOT NULL DEFAULT '',
            origen VARCHAR(20) NOT NULL DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY email (email),
            KEY activo (activo)
        ) {$charset}";

        $wpdb->query($sql);
        if (!empty($wpdb->last_error)) {
            return;
        }
        update_option('riverso_contactos_schema', '1', false);
    }

    public static function ensure_caps() {
        $caps = ['riverso_view_customers', 'riverso_edit_customers'];
        foreach (['administrator', 'riverso_admin', 'riverso_ventas'] as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Guarda el correo de una boleta o factura como contacto del cliente.
     * Si ese correo ya está inhabilitado, no lo reactiva.
     *
     * @param int    $customer_id
     * @param string $email
     * @param array  $args nombre, telefono, origen (boleta|factura|venta|manual)
     * @return array{status:string,message:string}
     */
    public static function remember_billing_email($customer_id, $email, $args = []) {
        self::create_tables();

        $email = sanitize_email((string) $email);
        if ($email === '' || !is_email($email)) {
            return ['status' => 'skipped', 'message' => ''];
        }

        $customer_id = max(0, intval($customer_id));
        $origen = isset($args['origen']) ? sanitize_key($args['origen']) : 'venta';
        if (!in_array($origen, ['manual', 'boleta', 'factura', 'venta'], true)) {
            $origen = 'venta';
        }

        global $wpdb;
        $table = self::table_name();
        $blocked = 'El correo está inhabilitado. Para volver a usarlo hay que eliminarlo desde el buscador de Contactos.';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_id = %d AND email = %s ORDER BY id DESC LIMIT 1",
            $customer_id,
            $email
        ));

        if (!$row && $customer_id > 0) {
            $orphan = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE customer_id = 0 AND email = %s ORDER BY id DESC LIMIT 1",
                $email
            ));
            if ($orphan) {
                if (!(int) $orphan->activo) {
                    return ['status' => 'blocked', 'message' => $blocked];
                }
                $wpdb->update(
                    $table,
                    ['customer_id' => $customer_id],
                    ['id' => (int) $orphan->id],
                    ['%d'],
                    ['%d']
                );
                return ['status' => 'exists', 'message' => ''];
            }
        }

        if ($row) {
            if (!(int) $row->activo) {
                return ['status' => 'blocked', 'message' => $blocked];
            }
            $patch = [];
            $formats = [];
            $split = self::split_name($args['nombre'] ?? '');
            if ($row->primer_nombre === '' && $split['primer'] !== '') {
                $patch['primer_nombre'] = $split['primer'];
                $formats[] = '%s';
            }
            if ($row->apellido_paterno === '' && $split['apellido'] !== '') {
                $patch['apellido_paterno'] = $split['apellido'];
                $formats[] = '%s';
            }
            $phone = sanitize_text_field($args['telefono'] ?? '');
            if ($row->fono_movil === '' && $phone !== '') {
                $patch['fono_movil'] = $phone;
                $formats[] = '%s';
            }
            if ($patch) {
                $wpdb->update($table, $patch, ['id' => (int) $row->id], $formats, ['%d']);
            }
            return ['status' => 'exists', 'message' => ''];
        }

        $split = self::split_name($args['nombre'] ?? '');
        $wpdb->insert(
            $table,
            [
                'customer_id' => $customer_id,
                'primer_nombre' => $split['primer'],
                'apellido_paterno' => $split['apellido'],
                'email' => $email,
                'fono_movil' => sanitize_text_field($args['telefono'] ?? ''),
                'activo' => 1,
                'origen' => $origen,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        return ['status' => 'created', 'message' => ''];
    }

    public static function split_name($name) {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '' || strcasecmp($name, 'Cliente Anónimo') === 0) {
            return ['primer' => '', 'apellido' => ''];
        }
        $parts = explode(' ', $name, 2);
        return [
            'primer' => $parts[0],
            'apellido' => $parts[1] ?? '',
        ];
    }

    public function ajax_search_customers() {
        $this->guard_view();
        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = $this->per_page();
        wp_send_json_success($this->search_customers($term, $page, $per_page));
    }

    public function ajax_search_contacts() {
        $this->guard_view();
        wp_send_json_success($this->search_contacts($this->contact_filters(), $this->page(), $this->per_page()));
    }

    public function ajax_customer_contacts() {
        $this->guard_view();
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        if ($customer_id <= 0) {
            wp_send_json_error(['message' => 'Cliente inválido']);
        }
        $rows = $this->contacts_of($customer_id);
        wp_send_json_success([
            'customer_id' => $customer_id,
            'entidad' => self::entity_name($customer_id),
            'rows' => $rows,
        ]);
    }

    public function ajax_get() {
        $this->guard_view();
        $row = $this->find(isset($_POST['id']) ? intval($_POST['id']) : 0);
        if (!$row) {
            wp_send_json_error(['message' => 'Contacto no encontrado']);
        }
        wp_send_json_success($this->format_contact($row, true));
    }

    public function ajax_save() {
        $this->guard_edit();
        $data = $this->sanitize_payload($_POST);
        if ($data['primer_nombre'] === '' && $data['apellido_paterno'] === '' && $data['email'] === '') {
            wp_send_json_error(['message' => 'Indica al menos un nombre o un correo.']);
        }
        if ($data['customer_id'] > 0 && !get_userdata($data['customer_id'])) {
            wp_send_json_error(['message' => 'El cliente no existe.']);
        }

        $raw_email = isset($_POST['email']) ? trim((string) wp_unslash($_POST['email'])) : '';
        if ($raw_email !== '' && $data['email'] === '') {
            wp_send_json_error(['message' => 'El correo no es válido.']);
        }

        global $wpdb;
        $table = self::table_name();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($data['email'] !== '') {
            $other = $wpdb->get_row($wpdb->prepare(
                "SELECT id, activo FROM {$table} WHERE customer_id = %d AND email = %s AND id <> %d LIMIT 1",
                $data['customer_id'],
                $data['email'],
                $id
            ));
            if ($other) {
                if (!(int) $other->activo) {
                    wp_send_json_error(['message' => 'Ese correo está inhabilitado. Elimínalo desde el buscador de Contactos para volver a usarlo.']);
                }
                wp_send_json_error(['message' => 'Ese correo ya existe como contacto de este cliente.']);
            }
        }

        $formats = array_fill(0, count($data), '%s');
        $formats[0] = '%d';
        $int_keys = ['activo', 'fecha_nac_dia', 'fecha_nac_mes', 'fecha_nac_anio'];
        $i = 0;
        foreach (array_keys($data) as $key) {
            if (in_array($key, $int_keys, true)) {
                $formats[$i] = '%d';
            }
            $i++;
        }

        if ($id > 0) {
            $existing = $this->find($id);
            if (!$existing) {
                wp_send_json_error(['message' => 'Contacto no encontrado']);
            }
            $data['origen'] = $existing->origen;
            $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
        } else {
            $wpdb->insert($table, $data, $formats);
            $id = (int) $wpdb->insert_id;
        }

        if (!empty($wpdb->last_error) || $id <= 0) {
            wp_send_json_error(['message' => 'No se pudo guardar el contacto.']);
        }

        $saved = $this->find($id);
        $message = 'Contacto guardado.';
        if (!(int) $data['activo']) {
            $message = 'Contacto inhabilitado. Ya no aparece en la búsqueda de activos. Para quitarlo del buscador hay que eliminarlo en Contactos, con Activo desmarcado.';
        }
        wp_send_json_success([
            'contact' => $this->format_contact($saved, true),
            'message' => $message,
        ]);
    }

    public function ajax_delete() {
        $this->guard_edit();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $row = $this->find($id);
        if (!$row) {
            wp_send_json_error(['message' => 'Contacto no encontrado']);
        }
        global $wpdb;
        $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
        wp_send_json_success(['message' => 'Contacto eliminado del buscador.']);
    }

    private function guard_view() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
    }

    private function guard_edit() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit()) {
            wp_send_json_error(['message' => 'Sin permisos para editar contactos']);
        }
    }

    private function can_view() {
        return current_user_can('riverso_view_customers') || current_user_can('manage_options');
    }

    private function can_edit() {
        return current_user_can('riverso_edit_customers') || current_user_can('manage_options');
    }

    private function page() {
        return isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    }

    private function per_page() {
        $per = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        if (!in_array($per, [10, 25, 50, 100], true)) {
            $per = 10;
        }
        return $per;
    }

    private function search_customers($term, $page, $per_page) {
        global $wpdb;
        $cap_key = $wpdb->prefix . 'capabilities';
        $role_like = '%"customer"%';
        $offset = ($page - 1) * $per_page;

        if ($term === '') {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT u.ID FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} cap
                    ON cap.user_id = u.ID AND cap.meta_key = %s AND cap.meta_value LIKE %s
                 ORDER BY u.display_name ASC",
                $cap_key,
                $role_like
            ));
        } else {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT u.ID FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} cap
                    ON cap.user_id = u.ID AND cap.meta_key = %s AND cap.meta_value LIKE %s
                 LEFT JOIN {$wpdb->usermeta} co
                    ON co.user_id = u.ID AND co.meta_key = 'billing_company'
                 LEFT JOIN {$wpdb->usermeta} ph
                    ON ph.user_id = u.ID AND ph.meta_key = 'billing_phone'
                 WHERE u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s
                    OR co.meta_value LIKE %s OR ph.meta_value LIKE %s
                 ORDER BY u.display_name ASC",
                $cap_key,
                $role_like,
                $like,
                $like,
                $like,
                $like,
                $like
            ));
        }

        $ids = array_map('intval', $ids ?: []);
        $total = count($ids);
        $slice = array_slice($ids, $offset, $per_page);
        $counts = $this->contact_counts($slice);
        $rows = [];
        foreach ($slice as $id) {
            $user = get_userdata($id);
            if (!$user) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'phone' => (string) get_user_meta($id, 'billing_phone', true),
                'company' => (string) get_user_meta($id, 'billing_company', true),
                'contact_count' => $counts[$id] ?? 0,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    private function contact_counts(array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $table = self::table_name();
        $in = implode(',', $ids);
        $found = $wpdb->get_results("SELECT customer_id, COUNT(*) AS total FROM {$table} WHERE customer_id IN ({$in}) GROUP BY customer_id");
        $out = [];
        foreach ($found as $row) {
            $out[(int) $row->customer_id] = (int) $row->total;
        }
        return $out;
    }

    private function contact_filters() {
        $activo = isset($_POST['activo']) ? (string) wp_unslash($_POST['activo']) : '1';
        return [
            'nombre' => isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '',
            'segundo_nombre' => isset($_POST['segundo_nombre']) ? sanitize_text_field(wp_unslash($_POST['segundo_nombre'])) : '',
            'apellido_paterno' => isset($_POST['apellido_paterno']) ? sanitize_text_field(wp_unslash($_POST['apellido_paterno'])) : '',
            'apellido_materno' => isset($_POST['apellido_materno']) ? sanitize_text_field(wp_unslash($_POST['apellido_materno'])) : '',
            'email' => isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '',
            'solo_activos' => $activo === '1',
        ];
    }

    private function search_contacts(array $filters, $page, $per_page) {
        global $wpdb;
        $table = self::table_name();
        $where = ['1=1'];
        $params = [];

        $this->like_filter($where, $params, 'primer_nombre', $filters['nombre']);
        $this->like_filter($where, $params, 'segundo_nombre', $filters['segundo_nombre']);
        $this->like_filter($where, $params, 'apellido_paterno', $filters['apellido_paterno']);
        $this->like_filter($where, $params, 'apellido_materno', $filters['apellido_materno']);
        $this->like_filter($where, $params, 'email', $filters['email']);
        if (!empty($filters['solo_activos'])) {
            $where[] = 'activo = 1';
        }

        $sql_where = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $offset = ($page - 1) * $per_page;
        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$sql_where}
             ORDER BY activo DESC, apellido_paterno ASC, primer_nombre ASC
             LIMIT %d OFFSET %d",
            $list_params
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_contact($row, false);
        }

        return [
            'rows' => $out,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    private function contacts_of($customer_id) {
        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_id = %d
             ORDER BY activo DESC, apellido_paterno ASC, primer_nombre ASC",
            $customer_id
        ));
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_contact($row, false);
        }
        return $out;
    }

    private function like_filter(&$where, &$params, $column, $value) {
        global $wpdb;
        $allowed = ['primer_nombre', 'segundo_nombre', 'apellido_paterno', 'apellido_materno', 'email'];
        $value = trim((string) $value);
        if ($value === '' || !in_array($column, $allowed, true)) {
            return;
        }
        $where[] = "{$column} LIKE %s";
        $params[] = '%' . $wpdb->esc_like($value) . '%';
    }

    private function find($id) {
        $id = intval($id);
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    private function sanitize_payload($src) {
        $text = function ($key, $max) use ($src) {
            $value = isset($src[$key]) ? sanitize_text_field(wp_unslash($src[$key])) : '';
            return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
        };
        $day = isset($src['fecha_nac_dia']) ? intval($src['fecha_nac_dia']) : 0;
        $month = isset($src['fecha_nac_mes']) ? intval($src['fecha_nac_mes']) : 0;
        $year = isset($src['fecha_nac_anio']) ? intval($src['fecha_nac_anio']) : 0;
        $origen = isset($src['origen']) ? sanitize_key(wp_unslash($src['origen'])) : 'manual';
        if (!in_array($origen, ['manual', 'boleta', 'factura', 'venta'], true)) {
            $origen = 'manual';
        }

        return [
            'customer_id' => max(0, intval($src['customer_id'] ?? 0)),
            'primer_nombre' => $text('primer_nombre', 120),
            'segundo_nombre' => $text('segundo_nombre', 120),
            'apellido_paterno' => $text('apellido_paterno', 120),
            'apellido_materno' => $text('apellido_materno', 120),
            'email' => sanitize_email(isset($src['email']) ? wp_unslash($src['email']) : ''),
            'fono_oficina' => $text('fono_oficina', 40),
            'fono_movil' => $text('fono_movil', 40),
            'fono_hogar' => $text('fono_hogar', 40),
            'cargo' => $text('cargo', 120),
            'departamento' => $text('departamento', 120),
            'numero_oficina' => $text('numero_oficina', 40),
            'observaciones' => sanitize_textarea_field(isset($src['observaciones']) ? wp_unslash($src['observaciones']) : ''),
            'activo' => empty($src['activo']) ? 0 : 1,
            'fecha_nac_dia' => ($day >= 1 && $day <= 31) ? $day : 0,
            'fecha_nac_mes' => ($month >= 1 && $month <= 12) ? $month : 0,
            'fecha_nac_anio' => ($year >= 1900 && $year <= (int) gmdate('Y')) ? $year : 0,
            'lab_calle' => $text('lab_calle', 190),
            'lab_numero' => $text('lab_numero', 40),
            'lab_depto' => $text('lab_depto', 80),
            'lab_ciudad' => $text('lab_ciudad', 120),
            'lab_comuna' => $text('lab_comuna', 120),
            'lab_pais' => $text('lab_pais', 120),
            'lab_codigo_postal' => $text('lab_codigo_postal', 20),
            'part_calle' => $text('part_calle', 190),
            'part_numero' => $text('part_numero', 40),
            'part_depto' => $text('part_depto', 80),
            'part_ciudad' => $text('part_ciudad', 120),
            'part_comuna' => $text('part_comuna', 120),
            'part_pais' => $text('part_pais', 120),
            'part_codigo_postal' => $text('part_codigo_postal', 20),
            'origen' => $origen,
        ];
    }

    private function format_contact($row, $full) {
        $item = [
            'id' => (int) $row->id,
            'customer_id' => (int) $row->customer_id,
            'nombre' => self::contact_label($row),
            'activo' => (int) $row->activo,
            'entidad' => self::entity_name($row->customer_id),
            'cargo' => $row->cargo,
            'departamento' => $row->departamento,
            'numero_oficina' => $row->numero_oficina,
            'fono_oficina' => $row->fono_oficina,
            'fono_movil' => $row->fono_movil,
            'fono_hogar' => $row->fono_hogar,
            'email' => $row->email,
            'origen' => $row->origen,
        ];
        if (!$full) {
            return $item;
        }
        foreach ([
            'primer_nombre', 'segundo_nombre', 'apellido_paterno', 'apellido_materno',
            'observaciones', 'fecha_nac_dia', 'fecha_nac_mes', 'fecha_nac_anio',
            'lab_calle', 'lab_numero', 'lab_depto', 'lab_ciudad', 'lab_comuna', 'lab_pais', 'lab_codigo_postal',
            'part_calle', 'part_numero', 'part_depto', 'part_ciudad', 'part_comuna', 'part_pais', 'part_codigo_postal',
        ] as $key) {
            $item[$key] = $row->$key;
        }
        $item['fecha_nac_dia'] = (int) $row->fecha_nac_dia;
        $item['fecha_nac_mes'] = (int) $row->fecha_nac_mes;
        $item['fecha_nac_anio'] = (int) $row->fecha_nac_anio;
        return $item;
    }

    public static function contact_label($row) {
        $parts = array_filter([
            $row->primer_nombre,
            $row->segundo_nombre,
            $row->apellido_paterno,
            $row->apellido_materno,
        ], function ($part) {
            return $part !== null && $part !== '';
        });
        $name = trim(implode(' ', $parts));
        if ($name !== '') {
            return $name;
        }
        return $row->email !== '' ? $row->email : 'Sin nombre';
    }

    public static function entity_name($customer_id) {
        static $cache = [];
        $customer_id = (int) $customer_id;
        if (isset($cache[$customer_id])) {
            return $cache[$customer_id];
        }
        if ($customer_id <= 0) {
            return $cache[$customer_id] = 'Sin cliente';
        }
        $company = get_user_meta($customer_id, 'billing_company', true);
        if (is_string($company) && $company !== '') {
            return $cache[$customer_id] = $company;
        }
        $user = get_userdata($customer_id);
        return $cache[$customer_id] = ($user ? $user->display_name : ('Cliente #' . $customer_id));
    }
}
