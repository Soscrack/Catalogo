<?php
/**
 * Repositorio de clientes comerciales (solo clientes).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Repository {

    /** @var string */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'riverso_clientes';
    }

    /**
     * @return string
     */
    public function table_name() {
        return $this->table;
    }

    /**
     * @param array<string, mixed> $filters
     * @param int                  $page
     * @param int                  $per_page
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function list_customers($filters = [], $page = 1, $per_page = 25) {
        global $wpdb;

        $page = max(1, (int) $page);
        $per_page = min(100, max(10, (int) $per_page));

        $where = ['1=1'];
        $params = [];

        $status = isset($filters['status']) ? (string) $filters['status'] : 'active';
        if ($status === 'active') {
            $where[] = 'activo = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'activo = 0';
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $where[] = '(nombre_fantasia LIKE %s OR rut LIKE %s OR razon_social LIKE %s OR contacto_email LIKE %s OR primer_nombre LIKE %s OR apellido_paterno LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        $offset = ($page - 1) * $per_page;
        $select_sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY nombre_fantasia ASC LIMIT %d OFFSET %d";
        $select_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($select_sql, $select_params), ARRAY_A) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->normalize_row($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $per_page) : 0,
            'page' => $page,
        ];
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        if (!$row) {
            return null;
        }
        return $this->normalize_row($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, message?: string, customer?: array<string, mixed>}
     */
    public function save($input) {
        global $wpdb;

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $nombre_fantasia = $this->clip((string) ($input['nombre_fantasia'] ?? ''), 255);
        if ($nombre_fantasia === '') {
            return ['ok' => false, 'message' => 'El nombre de fantasía es obligatorio'];
        }

        $has_contacto = !empty($input['has_contacto']) ? 1 : 0;
        $has_facturacion = !empty($input['has_facturacion']) ? 1 : 0;
        $has_datos_extra = !empty($input['has_datos_extra']) ? 1 : 0;

        $primer_nombre = $this->clip((string) ($input['primer_nombre'] ?? ''), 100);
        $apellido_paterno = $this->clip((string) ($input['apellido_paterno'] ?? ''), 100);

        if ($has_contacto) {
            if ($primer_nombre === '') {
                return ['ok' => false, 'message' => 'El primer nombre del contacto es obligatorio'];
            }
            if ($apellido_paterno === '') {
                return ['ok' => false, 'message' => 'El apellido paterno del contacto es obligatorio'];
            }
        }

        $rut_raw = trim((string) ($input['rut'] ?? ''));
        $rut = '';
        if ($rut_raw !== '') {
            $rut = $this->clean_rut($rut_raw);
            if ($rut === '' || (function_exists('riverso_validate_rut') && !riverso_validate_rut($rut))) {
                return ['ok' => false, 'message' => 'RUT inválido'];
            }
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE rut = %s AND id != %d",
                $rut,
                $id
            ));
            if ($dup) {
                return ['ok' => false, 'message' => 'Ya existe un cliente con este RUT'];
            }
        }

        $datos_extra = $this->normalize_datos_extra($input['datos_extra'] ?? []);

        $data = [
            'nombre_fantasia' => $nombre_fantasia,
            'has_contacto' => $has_contacto,
            'primer_nombre' => $has_contacto ? ($primer_nombre !== '' ? $primer_nombre : null) : null,
            'apellido_paterno' => $has_contacto ? ($apellido_paterno !== '' ? $apellido_paterno : null) : null,
            'contacto_telefono' => $has_contacto ? $this->nullable_clip($input['contacto_telefono'] ?? '', 50) : null,
            'contacto_email' => $has_contacto ? $this->nullable_email($input['contacto_email'] ?? '') : null,
            'has_facturacion' => $has_facturacion,
            'pais' => $has_facturacion ? $this->clip((string) ($input['pais'] ?? 'CHILE'), 50) : 'CHILE',
            'tipo_identificacion' => $has_facturacion
                ? $this->clip((string) ($input['tipo_identificacion'] ?? 'RUT_CLIENTE'), 50)
                : 'RUT_CLIENTE',
            'rut' => $has_facturacion && $rut !== '' ? $rut : ($rut !== '' ? $rut : null),
            'razon_social' => $has_facturacion ? $this->nullable_clip($input['razon_social'] ?? '', 255) : null,
            'direccion' => $has_facturacion ? $this->nullable_clip($input['direccion'] ?? '', 255) : null,
            'comuna' => $has_facturacion ? $this->nullable_clip($input['comuna'] ?? '', 100) : null,
            'ciudad' => $has_facturacion ? $this->nullable_clip($input['ciudad'] ?? '', 100) : null,
            'giro' => $has_facturacion ? $this->nullable_clip($input['giro'] ?? '', 255) : null,
            'facturacion_telefono' => $has_facturacion ? $this->nullable_clip($input['facturacion_telefono'] ?? '', 50) : null,
            'codigo_postal' => $has_facturacion
                ? $this->clip((string) (($input['codigo_postal'] ?? '') !== '' ? $input['codigo_postal'] : '0'), 20)
                : '0',
            'has_datos_extra' => $has_datos_extra,
            'datos_extra' => $has_datos_extra
                ? wp_json_encode($datos_extra, JSON_UNESCAPED_UNICODE)
                : wp_json_encode([], JSON_UNESCAPED_UNICODE),
            'activo' => isset($input['activo']) ? (int) !empty($input['activo']) : 1,
        ];

        // Conservar RUT aunque se desactive facturación si ya venía informado en el payload.
        if (!$has_facturacion && $rut !== '') {
            $data['rut'] = $rut;
        }

        if ($id > 0) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE id = %d", $id));
            if (!$exists) {
                return ['ok' => false, 'message' => 'Cliente no encontrado'];
            }
            $result = $wpdb->update($this->table, $data, ['id' => $id]);
            if ($result === false) {
                return ['ok' => false, 'message' => 'Error al actualizar: ' . $wpdb->last_error];
            }
            $customer = $this->get($id);
            return ['ok' => true, 'id' => $id, 'message' => 'Cliente actualizado', 'customer' => $customer];
        }

        $result = $wpdb->insert($this->table, $data);
        if (!$result) {
            return ['ok' => false, 'message' => 'Error al crear: ' . $wpdb->last_error];
        }
        $new_id = (int) $wpdb->insert_id;
        $customer = $this->get($new_id);
        return ['ok' => true, 'id' => $new_id, 'message' => 'Cliente creado', 'customer' => $customer];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_row($row) {
        $extra = [];
        if (!empty($row['datos_extra'])) {
            $decoded = json_decode((string) $row['datos_extra'], true);
            if (is_array($decoded)) {
                $extra = $this->normalize_datos_extra($decoded);
            }
        }

        $contacto_nombre = trim(
            trim((string) ($row['primer_nombre'] ?? '')) . ' ' . trim((string) ($row['apellido_paterno'] ?? ''))
        );

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nombre_fantasia' => (string) ($row['nombre_fantasia'] ?? ''),
            'has_contacto' => !empty($row['has_contacto']),
            'primer_nombre' => (string) ($row['primer_nombre'] ?? ''),
            'apellido_paterno' => (string) ($row['apellido_paterno'] ?? ''),
            'contacto_telefono' => (string) ($row['contacto_telefono'] ?? ''),
            'contacto_email' => (string) ($row['contacto_email'] ?? ''),
            'contacto_nombre' => $contacto_nombre,
            'has_facturacion' => !empty($row['has_facturacion']),
            'pais' => (string) (($row['pais'] ?? '') !== '' ? $row['pais'] : 'CHILE'),
            'tipo_identificacion' => (string) (($row['tipo_identificacion'] ?? '') !== '' ? $row['tipo_identificacion'] : 'RUT_CLIENTE'),
            'rut' => (string) ($row['rut'] ?? ''),
            'razon_social' => (string) ($row['razon_social'] ?? ''),
            'direccion' => (string) ($row['direccion'] ?? ''),
            'comuna' => (string) ($row['comuna'] ?? ''),
            'ciudad' => (string) ($row['ciudad'] ?? ''),
            'giro' => (string) ($row['giro'] ?? ''),
            'facturacion_telefono' => (string) ($row['facturacion_telefono'] ?? ''),
            'codigo_postal' => (string) (($row['codigo_postal'] ?? '') !== '' ? $row['codigo_postal'] : '0'),
            'has_datos_extra' => !empty($row['has_datos_extra']),
            'datos_extra' => $extra,
            'activo' => !empty($row['activo']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param mixed $raw
     * @return array<int, array{nombre: string, valor: string}>
     */
    private function normalize_datos_extra($raw) {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $item = isset($raw[$i]) && is_array($raw[$i]) ? $raw[$i] : [];
            $nombre = $this->clip((string) ($item['nombre'] ?? ''), 100);
            $valor = $this->clip((string) ($item['valor'] ?? ''), 255);
            if ($nombre === '' && $valor === '') {
                continue;
            }
            $out[] = ['nombre' => $nombre, 'valor' => $valor];
        }
        return $out;
    }

    /**
     * @param string $rut
     * @return string
     */
    private function clean_rut($rut) {
        $rut = preg_replace('/[^0-9kK]/', '', (string) $rut);
        if (!is_string($rut) || strlen($rut) < 2) {
            return '';
        }
        $dv = strtoupper(substr($rut, -1));
        $numero = substr($rut, 0, -1);
        return $numero . '-' . $dv;
    }

    /**
     * @param string $value
     * @param int    $max
     * @return string
     */
    private function clip($value, $max) {
        $value = sanitize_text_field($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    /**
     * @param mixed $value
     * @param int   $max
     * @return string|null
     */
    private function nullable_clip($value, $max) {
        $clipped = $this->clip((string) $value, $max);
        return $clipped === '' ? null : $clipped;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function nullable_email($value) {
        $email = sanitize_email((string) $value);
        return $email === '' ? null : $email;
    }
}
