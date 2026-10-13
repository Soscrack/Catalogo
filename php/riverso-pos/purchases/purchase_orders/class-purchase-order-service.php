<?php
/**
 * Pedidos de compra: lo que Riverso le pide a un proveedor, desde que se arma hasta que entra.
 *
 * El pedido no es la cotización del proveedor, ni la factura, ni el stock. Toma avisos y
 * alertas, se concilia contra la cotización recibida y deja lo aceptado como lo que
 * Recepción espera. No mueve stock: eso lo hacen Recepción y ventas.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Order_Service {

    const STATES = [
        'borrador' => 'Borrador',
        'enviada' => 'Enviado',
        'en_cotizacion' => 'En cotización',
        'en_revision' => 'En revisión',
        'aceptada' => 'Aceptado',
        'recibida_parcial' => 'En recepción',
        'recibida' => 'Recibido',
        'cancelada' => 'Cancelado',
    ];

    /** Un pedido abierto todavía puede traer mercadería: su línea cuenta como "en camino". */
    const OPEN_STATES = ['borrador', 'enviada', 'en_cotizacion', 'en_revision', 'aceptada', 'recibida_parcial'];

    const LINE_STATES = [
        'pendiente' => 'Pendiente',
        'aceptada' => 'Aceptada',
        'no_comprada' => 'No se compra',
        'cerrada' => 'Recibida',
    ];

    const ORIGINS = [
        'aviso' => 'Aviso',
        'alerta' => 'Alerta de stock',
        'manual' => 'Agregada a mano',
        'cotizacion' => 'Vino en la cotización',
        'resto' => 'Resto de otro pedido',
        'legado' => 'OC anterior',
    ];

    const EPS = 0.0005;

    protected static $instance = null;

    /** @var bool|null */
    protected $ready = null;

    /** @var array<string, bool> */
    protected $tables = [];

    /** @var array<string, bool> */
    protected $columns = [];

    public static function get_instance() {
        if (null === static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /* ===================== Base ===================== */

    /**
     * @param string $name
     * @return string
     */
    protected function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * @param string $name
     * @return bool
     */
    protected function table_exists($name) {
        global $wpdb;
        if (!isset($this->tables[$name])) {
            $table = $this->table($name);
            $this->tables[$name] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        }
        return $this->tables[$name];
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    protected function has_column($table, $column) {
        global $wpdb;
        $key = $table . '.' . $column;
        if (!isset($this->columns[$key])) {
            $this->columns[$key] = $this->table_exists($table)
                && (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->table($table)} LIKE %s", $column));
        }
        return $this->columns[$key];
    }

    /**
     * @return Riverso_Purchase_Notice_Service
     */
    protected function notices() {
        if (!class_exists('Riverso_Purchase_Notice_Service')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'purchases/notices/class-purchase-notice-service.php';
        }
        return Riverso_Purchase_Notice_Service::get_instance();
    }

    /**
     * @return bool
     */
    public function ready() {
        if ($this->ready !== null) {
            return $this->ready;
        }
        $ok = $this->schema_ok();
        if (!$ok && class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_purchase_orders')) {
            Riverso_POS_Activator::ensure_purchase_orders();
            $this->tables = [];
            $this->columns = [];
            $ok = $this->schema_ok();
        }
        $this->ready = $ok && $this->notices()->ready();
        return $this->ready;
    }

    /**
     * @return bool
     */
    private function schema_ok() {
        return $this->table_exists('ordenes_compra')
            && $this->table_exists('orden_compra_documentos')
            && $this->has_column('ordenes_compra', 'revision_enviada_en')
            && $this->has_column('ordenes_compra_items', 'cotizacion_item_id');
    }

    /**
     * @param string|float|null $raw
     * @return float|null Null si viene vacío.
     * @throws Exception
     */
    protected function number($raw, $label = 'El número') {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (!is_numeric(str_replace(',', '.', $raw))) {
            throw new Exception($label . ' no es válido.');
        }
        $value = (float) str_replace(',', '.', $raw);
        if ($value < 0) {
            throw new Exception($label . ' no puede ser negativo.');
        }
        return $value;
    }

    /**
     * Unidad escrita por un proveedor ("UN", "CJ", "Caja") llevada a las unidades del portal.
     *
     * @param string $raw
     * @return string Clave de unidad, o '' si no se reconoce.
     */
    public static function unit_key($raw) {
        $raw = strtolower(trim((string) $raw));
        $raw = rtrim(preg_replace('/[^a-záéíóúñ\/]/u', '', $raw), 's');
        $map = [
            'un' => 'unidad', 'und' => 'unidad', 'unid' => 'unidad', 'unidad' => 'unidad', 'unidade' => 'unidad',
            'u' => 'unidad', 'c/u' => 'unidad', 'pz' => 'unidad', 'pza' => 'unidad', 'pieza' => 'unidad',
            'cj' => 'caja', 'cja' => 'caja', 'caja' => 'caja', 'box' => 'caja',
            'bol' => 'bolsa', 'bolsa' => 'bolsa',
            'paq' => 'paquete', 'paquete' => 'paquete', 'pack' => 'paquete',
            'blister' => 'blister', 'blíster' => 'blister',
            'bulto' => 'bulto', 'rollo' => 'rollo', 'rll' => 'rollo',
            'kg' => 'kg', 'kilo' => 'kg', 'mt' => 'metro', 'm' => 'metro', 'metro' => 'metro',
            'lt' => 'litro', 'l' => 'litro', 'litro' => 'litro',
        ];
        return $map[$raw] ?? '';
    }

    /**
     * Cantidad en unidades de stock. Solo hay equivalencia cuando el factor dice algo (mayor que 1).
     *
     * @param float      $cantidad
     * @param string     $unidad
     * @param float|null $factor
     * @return float|null Null si no se puede saber.
     */
    protected function base_quantity($cantidad, $unidad, $factor) {
        if ($unidad === 'unidad') {
            return (float) $cantidad;
        }
        if ($factor !== null && (float) $factor > 1.0001) {
            return (float) $cantidad * (float) $factor;
        }
        return null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    protected function supplier($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre FROM {$this->table('proveedores')} WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * @param int $id
     * @return array|null
     */
    protected function order_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, p.nombre AS proveedor_nombre
             FROM {$this->table('ordenes_compra')} o
             LEFT JOIN {$this->table('proveedores')} p ON p.id = o.proveedor_id
             WHERE o.id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * @param int $id
     * @return array
     * @throws Exception
     */
    protected function require_order($id) {
        $order = $this->order_row($id);
        if (!$order) {
            throw new Exception('Pedido no encontrado.');
        }
        return $order;
    }

    /**
     * @param int $id
     * @return array|null
     */
    protected function line_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('ordenes_compra_items')} WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * @param int $order_id
     * @return array
     */
    protected function line_rows($order_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$this->table('ordenes_compra_items')} i
             LEFT JOIN {$this->table('producto_base')} pb ON pb.id = i.producto_base_id
             WHERE i.orden_id = %d ORDER BY i.id ASC",
            (int) $order_id
        ), ARRAY_A) ?: [];
    }

    /**
     * @param array $order
     * @return string
     */
    protected function order_label(array $order) {
        $numero = trim((string) ($order['numero'] ?? ''));
        return 'N° ' . ($numero !== '' ? $numero : $order['id']);
    }

    /**
     * @param int   $order_id
     * @param array $data
     */
    protected function touch_order($order_id, array $data = []) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table('ordenes_compra'), $data, ['id' => (int) $order_id]);
    }

    /* ===================== Lista ===================== */

    /**
     * @param array $filters estado, buscar
     * @return array{pedidos: array, counts: array<string, int>}
     */
    public function list_orders(array $filters = []) {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        $estado = (string) ($filters['estado'] ?? '');
        if (isset(self::STATES[$estado])) {
            $where[] = 'o.estado = %s';
            $params[] = $estado;
        }
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(o.numero LIKE %s OR p.nombre LIKE %s)';
            array_push($params, $like, $like);
        }
        $sql = "SELECT o.*, p.nombre AS proveedor_nombre,
                       (SELECT COUNT(*) FROM {$this->table('ordenes_compra_items')} i WHERE i.orden_id = o.id) AS lineas,
                       (SELECT COUNT(*) FROM {$this->table('ordenes_compra_items')} i
                         WHERE i.orden_id = o.id AND COALESCE(i.origen, 'legado') NOT IN ('cotizacion', 'legado')) AS lineas_pedidas
                FROM {$this->table('ordenes_compra')} o
                LEFT JOIN {$this->table('proveedores')} p ON p.id = o.proveedor_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY o.id DESC LIMIT 100';
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $counts = array_fill_keys(array_keys(self::STATES), 0);
        $state_rows = $wpdb->get_results(
            "SELECT estado, COUNT(*) AS n FROM {$this->table('ordenes_compra')} GROUP BY estado",
            ARRAY_A
        ) ?: [];
        foreach ($state_rows as $row) {
            if (isset($counts[$row['estado']])) {
                $counts[$row['estado']] = (int) $row['n'];
            }
        }
        return ['pedidos' => array_map([$this, 'present_order'], $rows ?: []), 'counts' => $counts];
    }

    /**
     * @param array $row
     * @return array
     */
    public function present_order(array $row) {
        $estado = (string) $row['estado'];
        return [
            'id' => (int) $row['id'],
            'numero' => $this->order_label($row),
            'proveedor_id' => (int) ($row['proveedor_id'] ?? 0),
            'proveedor_nombre' => (string) ($row['proveedor_nombre'] ?? '') ?: 'Sin proveedor',
            'estado' => $estado,
            'estado_label' => self::STATES[$estado] ?? $estado,
            'fecha' => substr((string) ($row['enviado_en'] ?? '') ?: (string) ($row['created_at'] ?? ''), 0, 10),
            'enviado_en' => (string) ($row['enviado_en'] ?? ''),
            'revision_enviada_en' => (string) ($row['revision_enviada_en'] ?? ''),
            'cotizacion_id' => (int) ($row['cotizacion_id'] ?? 0),
            'notas' => (string) ($row['notas'] ?? ''),
            'lineas' => isset($row['lineas']) ? (int) $row['lineas'] : null,
            // Nació de una cotización que llegó sin pedido: ninguna línea la pedimos nosotros.
            'sin_pedido_previo' => !empty($row['cotizacion_id']) && isset($row['lineas_pedidas']) && (int) $row['lineas_pedidas'] === 0,
        ];
    }

    /* ===================== Ficha ===================== */

    /**
     * @param int $id
     * @return array|null
     */
    public function get_order($id) {
        $row = $this->order_row($id);
        if (!$row) {
            return null;
        }
        if (in_array($row['estado'], ['enviada', 'en_cotizacion', 'en_revision'], true) && !empty($row['cotizacion_id'])) {
            $this->sync_quote_version($row);
            $row = $this->order_row($id);
        }
        if (in_array($row['estado'], ['enviada', 'en_cotizacion', 'en_revision', 'aceptada', 'recibida_parcial'], true)) {
            if ($this->auto_link_documents($row)) {
                $this->recalc_received((int) $row['id']);
                $row = $this->order_row($id);
            }
        }

        $rows = $this->line_rows($id);
        $notices = $this->notices()->for_order_items(array_column($rows, 'id'));
        $lines = [];
        foreach ($rows as $line) {
            $lines[] = $this->present_line($line, $notices[(int) $line['id']] ?? []);
        }

        $order = $this->present_order($row + ['lineas' => count($rows)]);
        $order['lineas'] = $lines;
        $order['sin_pedido_previo'] = !empty($row['cotizacion_id']) && $rows && !array_filter($rows, function ($line) {
            return !in_array($line['origen'] ?? 'legado', ['cotizacion', 'legado'], true);
        });
        $estado = $row['estado'];
        $order['puede'] = [
            'editar' => $estado === 'borrador',
            'enviar' => $estado === 'borrador' && count($rows) > 0,
            'cancelar' => in_array($estado, ['borrador', 'enviada', 'en_cotizacion', 'en_revision', 'aceptada'], true),
            'cotizar' => in_array($estado, ['enviada', 'en_cotizacion', 'en_revision'], true),
            'recibir' => in_array($estado, ['enviada', 'en_cotizacion', 'en_revision', 'aceptada', 'recibida_parcial', 'recibida'], true),
        ];
        $order['cotizacion'] = $this->quote_zone($row, $rows);
        $order['recepcion'] = $this->reception_zone($row, $rows);
        return $order;
    }

    /**
     * @param array $line    Fila de ordenes_compra_items con canonical_sku y nombre_canonico.
     * @param array $notices Avisos que apuntan a la línea.
     * @return array
     */
    protected function present_line(array $line, array $notices = []) {
        $unidad = (string) ($line['unidad'] ?? '');
        $units = Riverso_Purchase_Notice_Service::UNITS;
        $factor = $line['factor'] !== null ? (float) $line['factor'] : null;
        $cantidad = (float) $line['cantidad'];
        $estado = (string) ($line['estado'] ?? 'pendiente');
        $foto = '';
        foreach ($notices as $notice) {
            if ($notice['foto'] !== '') {
                $foto = $notice['foto'];
                break;
            }
        }
        $equivalencia = null;
        if ($cantidad > 0 && $unidad !== 'unidad' && $factor !== null && $factor > 1.0001) {
            $equivalencia = round($cantidad * $factor, 3);
        }
        return [
            'id' => (int) $line['id'],
            'orden_id' => (int) $line['orden_id'],
            'producto_base_id' => (int) ($line['producto_base_id'] ?? 0),
            'producto_proveedor_id' => (int) ($line['producto_proveedor_id'] ?? 0),
            'identificado' => !empty($line['producto_base_id']),
            'sku' => (string) ($line['canonical_sku'] ?? ''),
            'titulo' => (string) (($line['nombre_canonico'] ?? '') ?: ($line['descripcion'] ?? '') ?: 'Sin identificar'),
            'codigo_proveedor' => (string) ($line['codigo_proveedor'] ?? ''),
            'cantidad' => $cantidad > 0 ? $cantidad : null,
            'unidad' => $unidad,
            'unidad_label' => $units[$unidad] ?? $unidad,
            'factor' => $factor,
            'equivalencia' => $equivalencia,
            'precio' => $line['precio_unitario'] !== null ? (float) $line['precio_unitario'] : null,
            'origen' => (string) ($line['origen'] ?? 'legado'),
            'origen_label' => self::ORIGINS[$line['origen'] ?? 'legado'] ?? '',
            'origen_item_id' => (int) ($line['origen_item_id'] ?? 0),
            'emparejamiento_id' => (int) ($line['emparejamiento_id'] ?? 0),
            'grupo_id' => (int) ($line['grupo_id'] ?? 0),
            'nivel' => !empty($line['emparejamiento_id']) ? 'emparejamiento' : (!empty($line['grupo_id']) ? 'familia' : 'producto'),
            'estado' => $estado,
            'estado_label' => self::LINE_STATES[$estado] ?? $estado,
            'cotizacion_item_id' => (int) ($line['cotizacion_item_id'] ?? 0),
            'cantidad_aceptada' => $line['cantidad_aceptada'] !== null ? (float) $line['cantidad_aceptada'] : null,
            'precio_aceptado' => $line['precio_aceptado'] !== null ? (float) $line['precio_aceptado'] : null,
            'cantidad_recibida' => (float) $line['cantidad_recibida'],
            'foto' => $foto,
            'avisos' => array_map(function ($notice) {
                return [
                    'id' => $notice['id'],
                    'creado_nombre' => $notice['creado_nombre'],
                    'hace' => $notice['hace'],
                    'cantidad' => $notice['cantidad'],
                    'unidad_label' => $notice['unidad_label'],
                    'nota' => $notice['nota'],
                    'foto' => $notice['foto'],
                ];
            }, $notices),
        ];
    }

    /* ===================== Armar: bandeja de necesidades ===================== */

    /**
     * Necesidades abiertas (avisos y alertas) agrupadas por proveedor. El sistema propone;
     * nada entra a un borrador hasta que una persona confirma.
     *
     * @return array{grupos: array, total: int}
     */
    public function tray() {
        $needs = $this->needs();
        $drafts = $this->drafts_by_supplier();

        $groups = [];
        foreach ($needs as $need) {
            $key = (int) $need['proveedor_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'proveedor_id' => $key,
                    'nombre' => $key ? $need['proveedor_nombre'] : 'Sin proveedor',
                    'borrador' => $drafts[$key] ?? null,
                    'necesidades' => [],
                ];
            }
            $groups[$key]['necesidades'][] = $need;
        }
        // Un borrador sin necesidades pendientes igual se muestra: es el pedido que se está armando.
        foreach ($drafts as $supplier_id => $draft) {
            if (!isset($groups[$supplier_id])) {
                $groups[$supplier_id] = [
                    'proveedor_id' => $supplier_id,
                    'nombre' => $draft['proveedor_nombre'],
                    'borrador' => $draft,
                    'necesidades' => [],
                ];
            }
        }
        uasort($groups, function ($a, $b) {
            if (($a['proveedor_id'] === 0) !== ($b['proveedor_id'] === 0)) {
                return $a['proveedor_id'] === 0 ? 1 : -1;
            }
            return strcasecmp($a['nombre'], $b['nombre']);
        });
        return ['grupos' => array_values($groups), 'total' => count($needs)];
    }

    /**
     * @return array<int, array> Borrador abierto por proveedor.
     */
    protected function drafts_by_supplier() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT o.id, o.numero, o.proveedor_id, p.nombre AS proveedor_nombre,
                    (SELECT COUNT(*) FROM {$this->table('ordenes_compra_items')} i WHERE i.orden_id = o.id) AS lineas
             FROM {$this->table('ordenes_compra')} o
             LEFT JOIN {$this->table('proveedores')} p ON p.id = o.proveedor_id
             WHERE o.estado = 'borrador' AND o.cotizacion_id IS NULL
             ORDER BY o.id ASC",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            // Un borrador abierto por proveedor: si hay más de uno, se completa el más antiguo.
            $supplier_id = (int) $row['proveedor_id'];
            if (!isset($out[$supplier_id])) {
                $out[$supplier_id] = [
                    'id' => (int) $row['id'],
                    'numero' => $this->order_label($row),
                    'proveedor_nombre' => (string) $row['proveedor_nombre'],
                    'lineas' => (int) $row['lineas'],
                ];
            }
        }
        return $out;
    }

    /**
     * Una necesidad por producto, familia o emparejamiento, aunque la avisen varias personas.
     *
     * @return array
     */
    protected function needs() {
        $notice_service = $this->notices();
        $notices = [];
        foreach ($notice_service->list_notices(['estado' => 'abiertos'])['grupos'] as $group) {
            foreach ($group['avisos'] as $notice) {
                $notices[] = $notice;
            }
        }
        usort($notices, function ($a, $b) {
            return $a['id'] - $b['id'];
        });

        $needs = [];
        foreach ($notices as $notice) {
            $pb = (int) $notice['producto_base_id'];
            $scope = $pb > 0 ? $notice_service->need_scope($pb) : null;
            $key = $scope ? $scope['clave'] : 'a' . $notice['id'];
            if (!isset($needs[$key])) {
                $needs[$key] = $this->blank_need($key, $scope, $pb, $notice['titulo'], $notice['sku']);
            }
            $needs[$key]['avisos'][] = $notice;
        }

        foreach ($this->alerts() as $alert) {
            $key = $alert['clave'];
            if (!isset($needs[$key])) {
                $needs[$key] = $this->blank_need($key, $alert['scope'], $alert['producto_base_id'], $alert['titulo'], $alert['sku']);
                $needs[$key]['origen'] = 'alerta';
            }
            $needs[$key]['alerta'] = $alert['alerta'];
        }

        $open_lines = $this->open_lines_by_product();
        $notice_ids = [];
        foreach ($needs as $need) {
            foreach ($need['avisos'] as $notice) {
                $notice_ids[] = $notice['id'];
            }
        }
        $marks = $this->reopen_marks($notice_ids);

        $out = [];
        foreach ($needs as $need) {
            // En camino: la alerta no se ofrece mientras haya una línea abierta para esa necesidad.
            $line = null;
            foreach ($need['productos'] as $product_id) {
                if (isset($open_lines[$product_id])) {
                    $line = $open_lines[$product_id];
                    break;
                }
            }
            if ($line && !$need['avisos']) {
                continue;
            }
            $need['en_pedido'] = $line;
            $out[] = $this->finish_need($need, $marks);
        }
        return $out;
    }

    /**
     * @param string     $key
     * @param array|null $scope
     * @param int        $pb
     * @param string     $titulo
     * @param string     $sku
     * @return array
     */
    private function blank_need($key, $scope, $pb, $titulo, $sku) {
        return [
            'clave' => $key,
            'origen' => 'aviso',
            'nivel' => $scope ? ($scope['emparejamiento_id'] ? 'emparejamiento' : ($scope['grupo_id'] ? 'familia' : 'producto')) : 'sin_identificar',
            'emparejamiento_id' => $scope ? (int) $scope['emparejamiento_id'] : 0,
            'grupo_id' => $scope ? (int) $scope['grupo_id'] : 0,
            'producto_base_id' => (int) $pb,
            'productos' => $scope ? $scope['productos'] : [],
            'titulo' => (string) $titulo,
            'sku' => (string) $sku,
            'avisos' => [],
            'alerta' => null,
        ];
    }

    /**
     * Completa la propuesta: cantidad, proveedores posibles y proveedor sugerido.
     *
     * @param array $need
     * @param array $marks
     * @return array
     */
    private function finish_need(array $need, array $marks) {
        $notices = $need['avisos'];
        $matched_pp = 0;
        $supplier_id = 0;
        $supplier_name = '';
        foreach ($notices as $notice) {
            // El último aviso que trae proveedor manda: alguien pudo corregirlo.
            if ($notice['proveedor_id'] > 0) {
                $supplier_id = $notice['proveedor_id'];
                $supplier_name = $notice['proveedor_nombre'];
                $matched_pp = $notice['producto_proveedor_id'];
            }
        }

        $suppliers = $need['producto_base_id'] > 0
            ? $this->notices()->suppliers_for($need['producto_base_id'], $matched_pp)
            : [];
        if ($supplier_id <= 0) {
            $pick = $this->notices()->default_supplier($suppliers, $need['producto_base_id']);
            if ($pick) {
                $supplier_id = $pick['proveedor_id'];
                $supplier_name = $pick['nombre'];
            }
        }
        // Ya está en un pedido abierto: la necesidad se queda con ese proveedor.
        if ($need['en_pedido']) {
            $supplier_id = $need['en_pedido']['proveedor_id'];
            $supplier_name = $need['en_pedido']['proveedor_nombre'];
        }

        $proposal = $this->proposed_quantity($notices);
        if ($proposal['cantidad'] === null && $need['alerta'] && !$need['alerta']['dudosa'] && $supplier_id > 0) {
            $last = $this->last_purchase($need['productos'], $supplier_id);
            if ($last) {
                $proposal = ['cantidad' => $last['cantidad'], 'unidad' => $last['unidad'], 'confirmada' => false, 'mezcladas' => false, 'fuente' => 'ultima_compra'];
            }
        }

        $mark = '';
        foreach ($notices as $notice) {
            if (isset($marks[$notice['id']])) {
                $mark = $marks[$notice['id']];
            }
        }

        $units = Riverso_Purchase_Notice_Service::UNITS;
        unset($need['productos']);
        return $need + [
            'grupo_nombre' => $need['emparejamiento_id'] > 0 ? $this->pairing_name($need['emparejamiento_id']) : '',
            'proveedor_id' => $supplier_id,
            'proveedor_nombre' => $supplier_name,
            'proveedores' => $suppliers,
            'cantidad' => $proposal['cantidad'],
            'unidad' => $proposal['unidad'],
            'unidad_label' => $units[$proposal['unidad']] ?? (string) $proposal['unidad'],
            'cantidad_confirmada' => $proposal['confirmada'],
            'unidades_mezcladas' => $proposal['mezcladas'],
            'cantidad_fuente' => $proposal['fuente'],
            'sin_stock' => (bool) array_filter(array_column($notices, 'sin_stock')),
            'marca' => $mark,
            'foto' => (string) (array_values(array_filter(array_column($notices, 'foto')))[0] ?? ''),
        ];
    }

    /**
     * @param int $pairing_id
     * @return string
     */
    protected function pairing_name($pairing_id) {
        global $wpdb;
        if (!$this->table_exists('emparejamientos')) {
            return '';
        }
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT nombre FROM {$this->table('emparejamientos')} WHERE id = %d",
            (int) $pairing_id
        ));
    }

    /**
     * La cantidad no se suma: dos personas que avisan lo mismo vieron la misma falta.
     * Se propone la que confirmó compras; si no hay, la mayor. Unidades distintas no se
     * convierten solas: sin factor que las relacione, no se propone ninguna.
     *
     * @param array $notices
     * @return array{cantidad: float|null, unidad: string, confirmada: bool, mezcladas: bool, fuente: string}
     */
    protected function proposed_quantity(array $notices) {
        $none = ['cantidad' => null, 'unidad' => '', 'confirmada' => false, 'mezcladas' => false, 'fuente' => ''];
        $with_qty = array_values(array_filter($notices, function ($notice) {
            return $notice['cantidad'] !== null;
        }));
        if (!$with_qty) {
            return $none;
        }
        $confirmed = array_values(array_filter($with_qty, function ($notice) {
            return $notice['cantidad_confirmada'];
        }));
        if ($confirmed) {
            $pick = end($confirmed);
            return ['cantidad' => (float) $pick['cantidad'], 'unidad' => $pick['unidad'], 'confirmada' => true, 'mezcladas' => false, 'fuente' => 'confirmada'];
        }

        $units = array_unique(array_column($with_qty, 'unidad'));
        $pick = null;
        if (count($units) === 1) {
            foreach ($with_qty as $notice) {
                if (!$pick || $notice['cantidad'] > $pick['cantidad']) {
                    $pick = $notice;
                }
            }
        } else {
            $best = -1.0;
            foreach ($with_qty as $notice) {
                $base = $this->base_quantity($notice['cantidad'], $notice['unidad'], $notice['factor']);
                if ($base === null) {
                    return ['mezcladas' => true] + $none;
                }
                if ($base > $best) {
                    $best = $base;
                    $pick = $notice;
                }
            }
        }
        return [
            'cantidad' => (float) $pick['cantidad'],
            'unidad' => $pick['unidad'],
            'confirmada' => false,
            'mezcladas' => count($units) > 1,
            'fuente' => 'aviso',
        ];
    }

    /**
     * Líneas de pedidos abiertos, por producto: lo que ya está pedido o por pedirse.
     *
     * @return array<int, array>
     */
    protected function open_lines_by_product() {
        global $wpdb;
        $states = "'" . implode("','", self::OPEN_STATES) . "'";
        $rows = $wpdb->get_results(
            "SELECT i.id AS item_id, i.producto_base_id, i.cantidad, i.unidad, o.id AS orden_id, o.numero, o.estado,
                    o.proveedor_id, p.nombre AS proveedor_nombre
             FROM {$this->table('ordenes_compra_items')} i
             INNER JOIN {$this->table('ordenes_compra')} o ON o.id = i.orden_id
             LEFT JOIN {$this->table('proveedores')} p ON p.id = o.proveedor_id
             WHERE o.estado IN ({$states}) AND i.estado IN ('pendiente', 'aceptada')
               AND i.producto_base_id IS NOT NULL AND COALESCE(i.origen, 'legado') <> 'legado'
             ORDER BY i.id ASC",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $pb = (int) $row['producto_base_id'];
            if (isset($out[$pb])) {
                continue;
            }
            $out[$pb] = [
                'item_id' => (int) $row['item_id'],
                'orden_id' => (int) $row['orden_id'],
                'numero' => $this->order_label(['id' => $row['orden_id'], 'numero' => $row['numero']]),
                'estado' => (string) $row['estado'],
                'estado_label' => self::STATES[$row['estado']] ?? (string) $row['estado'],
                'proveedor_id' => (int) $row['proveedor_id'],
                'proveedor_nombre' => (string) $row['proveedor_nombre'],
                'cantidad' => (float) $row['cantidad'],
                'unidad' => (string) $row['unidad'],
            ];
        }
        return $out;
    }

    /**
     * Línea abierta que ya cubre la necesidad de un producto, en cualquier pedido.
     *
     * @param int[] $product_ids Productos que comparten la necesidad.
     * @return array|null
     */
    protected function open_line_for(array $product_ids) {
        $open = $this->open_lines_by_product();
        foreach ($product_ids as $product_id) {
            if (isset($open[(int) $product_id])) {
                return $open[(int) $product_id];
            }
        }
        return null;
    }

    /**
     * Por qué volvió un aviso a la bandeja: el pedido en que estaba no lo cubrió.
     *
     * @param int[] $notice_ids
     * @return array<int, string>
     */
    protected function reopen_marks(array $notice_ids) {
        global $wpdb;
        $notice_ids = array_values(array_filter(array_map('intval', $notice_ids)));
        if (!$notice_ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            "SELECT e.aviso_id, e.tipo, e.detalle FROM {$this->table('aviso_compra_eventos')} e
             WHERE e.aviso_id IN (" . implode(',', $notice_ids) . ") AND e.tipo IN ('reabrir', 'ingresar')
             ORDER BY e.id ASC",
            ARRAY_A
        ) ?: [];
        $last = [];
        foreach ($rows as $row) {
            $last[(int) $row['aviso_id']] = $row;
        }
        $out = [];
        $names = [];
        foreach ($last as $notice_id => $row) {
            $detail = json_decode((string) $row['detalle'], true);
            if ($row['tipo'] !== 'reabrir' || !is_array($detail) || empty($detail['motivo'])) {
                continue;
            }
            $supplier_id = (int) ($detail['proveedor_id'] ?? 0);
            if ($supplier_id > 0 && !isset($names[$supplier_id])) {
                $supplier = $this->supplier($supplier_id);
                $names[$supplier_id] = $supplier ? (string) $supplier['nombre'] : '';
            }
            $name = $names[$supplier_id] ?? '';
            if ($detail['motivo'] === 'no_cotizado') {
                $out[$notice_id] = $name !== '' ? 'No lo cotizó ' . $name : 'No se compró';
            } elseif ($detail['motivo'] === 'cancelado') {
                $out[$notice_id] = $name !== '' ? 'Pedido cancelado (' . $name . ')' : 'Pedido cancelado';
            }
        }
        return $out;
    }

    /* ===================== Armar: confirmar ===================== */

    /**
     * Borrador abierto del proveedor; lo crea si no existe.
     *
     * @param int $supplier_id
     * @return array Fila de ordenes_compra.
     * @throws Exception
     */
    protected function draft_for($supplier_id) {
        global $wpdb;
        $supplier = $this->supplier($supplier_id);
        if (!$supplier) {
            throw new Exception('Elige un proveedor para armar el pedido.');
        }
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('ordenes_compra')}
             WHERE proveedor_id = %d AND estado = 'borrador' AND cotizacion_id IS NULL ORDER BY id ASC LIMIT 1",
            (int) $supplier_id
        ));
        if ($id <= 0) {
            $now = current_time('mysql');
            $inserted = $wpdb->insert($this->table('ordenes_compra'), [
                'proveedor_id' => (int) $supplier_id,
                'estado' => 'borrador',
                'creado_por' => get_current_user_id() ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $wpdb->insert_id;
            if (!$inserted || $id <= 0) {
                throw new Exception('No se pudo crear el pedido.');
            }
            // Número corto y correlativo: el proveedor lo cita en la factura y así se encuentran.
            $wpdb->update($this->table('ordenes_compra'), ['numero' => (string) $id], ['id' => $id]);
        }
        return $this->order_row($id);
    }

    /**
     * Crea un borrador vacío para un proveedor (o devuelve el que ya tiene abierto).
     *
     * @param int $supplier_id
     * @return array Pedido.
     * @throws Exception
     */
    public function create_draft($supplier_id) {
        $draft = $this->draft_for($supplier_id);
        return $this->get_order((int) $draft['id']);
    }

    /**
     * Confirma necesidades de la bandeja en el borrador de un proveedor. Cada aviso queda
     * `ingresado` apuntando a su línea. Una línea que no se puede tomar se rechaza sola;
     * las demás entran.
     *
     * @param int   $supplier_id
     * @param array $lines [{aviso_ids: int[], producto_base_id, pp_id, cantidad, unidad}]
     * @return array{pedido: array, rechazadas: array}
     * @throws Exception
     */
    public function confirm($supplier_id, array $lines) {
        global $wpdb;
        if (!$lines) {
            throw new Exception('Marca al menos una línea.');
        }
        if (!$this->supplier($supplier_id)) {
            throw new Exception('Elige un proveedor para armar el pedido.');
        }
        $rejected = [];
        $order_id = 0;
        foreach ($lines as $line) {
            $wpdb->query('START TRANSACTION');
            try {
                $order_id = $this->take_need((int) $supplier_id, is_array($line) ? $line : []);
                $wpdb->query('COMMIT');
                $this->touch_order($order_id);
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                $rejected[] = ['clave' => (string) ($line['clave'] ?? ''), 'motivo' => $e->getMessage()];
            }
        }
        if ($order_id <= 0) {
            $drafts = $this->drafts_by_supplier();
            $order_id = isset($drafts[(int) $supplier_id]) ? $drafts[(int) $supplier_id]['id'] : 0;
        }
        return ['pedido' => $order_id > 0 ? $this->get_order($order_id) : null, 'rechazadas' => $rejected];
    }

    /**
     * @param int   $supplier_id
     * @param array $input
     * @return int Pedido donde quedó la necesidad.
     * @throws Exception
     */
    private function take_need($supplier_id, array $input) {
        global $wpdb;
        $notice_service = $this->notices();

        $notices = [];
        foreach (array_unique(array_map('intval', (array) ($input['aviso_ids'] ?? []))) as $notice_id) {
            $notice = $notice_service->get($notice_id);
            if (!$notice) {
                throw new Exception('Aviso no encontrado.');
            }
            if ($notice['estado'] !== 'abierto') {
                throw new Exception($notice['estado'] === 'ingresado'
                    ? 'Ya lo ingresó ' . ($notice['ingresado_nombre'] ?: 'otra persona') . '.'
                    : 'El aviso ya no está abierto.');
            }
            $notices[] = $notice;
        }

        $pb = (int) ($input['producto_base_id'] ?? 0);
        foreach ($notices as $notice) {
            if ($pb <= 0 && $notice['producto_base_id'] > 0) {
                $pb = $notice['producto_base_id'];
            }
        }
        if (!$notices && $pb <= 0) {
            throw new Exception('La línea no tiene producto ni aviso.');
        }

        $scope = $pb > 0 ? $notice_service->need_scope($pb) : null;
        $link = $this->supplier_link($supplier_id, (int) ($input['pp_id'] ?? 0), $pb, $scope);
        if ($link && $link['producto_base_id'] > 0) {
            // Con emparejado o familia, se pide el producto del vínculo elegido.
            $pb = $link['producto_base_id'];
        }

        $quantity = $this->line_quantity($input, $link);
        $existing = $scope ? $this->open_line_for($scope['productos']) : null;
        if ($existing && $existing['proveedor_id'] !== $supplier_id) {
            throw new Exception('Ya está en el pedido ' . $existing['numero'] . ' de ' . $existing['proveedor_nombre'] . '.');
        }

        if ($existing) {
            // Otro aviso de lo mismo: se suma a la línea que ya está pedida a este proveedor.
            if (!$notices) {
                throw new Exception('Ya está en el pedido ' . $existing['numero'] . '.');
            }
            $order_id = $existing['orden_id'];
            $item_id = $existing['item_id'];
            if ($quantity['cantidad'] > 0 && $existing['estado'] === 'borrador') {
                $wpdb->update($this->table('ordenes_compra_items'), [
                    'cantidad' => $quantity['cantidad'],
                    'unidad' => $quantity['unidad'],
                ], ['id' => $item_id]);
            }
        } else {
            $draft = $this->draft_for($supplier_id);
            $order_id = (int) $draft['id'];
            $title = '';
            foreach ($notices as $notice) {
                $title = $title !== '' ? $title : $notice['titulo'];
            }
            $item_id = $this->insert_line($order_id, [
                'producto_base_id' => $pb ?: null,
                'producto_proveedor_id' => $link ? $link['pp_id'] : null,
                'descripcion' => $this->clip($title !== '' ? $title : (string) ($input['descripcion'] ?? ''), 255),
                'cantidad' => $quantity['cantidad'],
                'unidad' => $quantity['unidad'],
                'codigo_proveedor' => $link ? $link['codigo'] : null,
                'factor' => $link ? $link['factor'] : null,
                'origen' => $notices ? 'aviso' : 'alerta',
                'emparejamiento_id' => $scope && $scope['emparejamiento_id'] ? $scope['emparejamiento_id'] : null,
                'grupo_id' => $scope && $scope['grupo_id'] ? $scope['grupo_id'] : null,
            ]);
        }

        foreach ($notices as $notice) {
            $notice_service->update($notice['id'], 'ingresar', [
                'orden_compra_id' => $order_id,
                'orden_compra_item_id' => $item_id,
            ], true);
        }
        return $order_id;
    }

    /**
     * Vínculo con el proveedor para una línea: el elegido, o el que ese proveedor tiene
     * para el producto, su familia o su emparejamiento.
     *
     * @param int        $supplier_id
     * @param int        $pp_id
     * @param int        $pb
     * @param array|null $scope
     * @return array{pp_id: int, producto_base_id: int, codigo: string, unidad: string, factor: float|null}|null
     * @throws Exception
     */
    protected function supplier_link($supplier_id, $pp_id, $pb, $scope) {
        global $wpdb;
        $row = null;
        if ($pp_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, proveedor_id, producto_base_id, grupo_id, codigo_proveedor, unidad_compra, factor_conversion
                 FROM {$this->table('producto_proveedor')} WHERE id = %d AND activo = 1",
                (int) $pp_id
            ), ARRAY_A);
            if (!$row || (int) $row['proveedor_id'] !== (int) $supplier_id) {
                throw new Exception('Ese código no es de este proveedor.');
            }
            $link_pb = (int) $row['producto_base_id'];
            $in_scope = !$scope || $link_pb === 0 || in_array($link_pb, $scope['productos'], true)
                || in_array((int) $row['grupo_id'], $scope['grupos'], true);
            if (!$in_scope) {
                throw new Exception('Ese código es de otro producto.');
            }
        } elseif ($pb > 0) {
            foreach ($this->notices()->suppliers_for($pb) as $candidate) {
                if ($candidate['proveedor_id'] === (int) $supplier_id) {
                    return [
                        'pp_id' => $candidate['pp_id'],
                        'producto_base_id' => $candidate['producto_base_id'],
                        'codigo' => $candidate['codigo'],
                        'unidad' => self::unit_key($candidate['unidad']),
                        'factor' => $candidate['factor'],
                    ];
                }
            }
        }
        if (!$row) {
            return null;
        }
        $factor = (float) $row['factor_conversion'];
        return [
            'pp_id' => (int) $row['id'],
            'producto_base_id' => (int) $row['producto_base_id'],
            'codigo' => (string) $row['codigo_proveedor'],
            'unidad' => self::unit_key($row['unidad_compra']),
            // factor_conversion nace en 1: solo un valor mayor dice algo.
            'factor' => $factor > 1.0001 ? $factor : null,
        ];
    }

    /**
     * Cantidad y unidad de una línea. En el borrador puede ir sin cantidad; con cantidad, la
     * unidad es obligatoria.
     *
     * @param array      $input
     * @param array|null $link
     * @return array{cantidad: float, unidad: string}
     * @throws Exception
     */
    protected function line_quantity(array $input, $link) {
        $cantidad = $this->number($input['cantidad'] ?? '', 'La cantidad');
        $unidad = sanitize_key((string) ($input['unidad'] ?? ''));
        $units = Riverso_Purchase_Notice_Service::UNITS;
        if (!isset($units[$unidad])) {
            $unidad = '';
        }
        if ($cantidad === null || $cantidad <= 0) {
            return ['cantidad' => 0.0, 'unidad' => $unidad !== '' ? $unidad : (($link ? $link['unidad'] : '') ?: 'unidad')];
        }
        if ($unidad === '') {
            // Sin unidad, "300" puede ser 300 tornillos o 300 cajas.
            throw new Exception('Indica en qué unidad va la cantidad (cajas, unidades…).');
        }
        return ['cantidad' => round($cantidad, 3), 'unidad' => $unidad];
    }

    /**
     * @param int   $order_id
     * @param array $data
     * @return int
     * @throws Exception
     */
    protected function insert_line($order_id, array $data) {
        global $wpdb;
        $inserted = $wpdb->insert($this->table('ordenes_compra_items'), $data + [
            'orden_id' => (int) $order_id,
            'estado' => 'pendiente',
            'created_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id;
        if (!$inserted || $id <= 0) {
            throw new Exception('No se pudo guardar la línea.');
        }
        return $id;
    }

    /* ===================== Borrador: líneas ===================== */

    /**
     * @param int $item_id
     * @return array{0: array, 1: array} Línea y pedido.
     * @throws Exception
     */
    protected function draft_line($item_id) {
        $line = $this->line_row($item_id);
        if (!$line) {
            throw new Exception('Línea no encontrada.');
        }
        $order = $this->require_order((int) $line['orden_id']);
        if ($order['estado'] !== 'borrador') {
            throw new Exception('El pedido ya se envió: las líneas pedidas no se reescriben.');
        }
        return [$line, $order];
    }

    /**
     * Agrega a mano una línea al borrador.
     *
     * @param int   $order_id
     * @param array $input producto_base_id o descripcion, pp_id, cantidad, unidad, precio
     * @return array Pedido.
     * @throws Exception
     */
    public function add_line($order_id, array $input) {
        $order = $this->require_order($order_id);
        if ($order['estado'] !== 'borrador') {
            throw new Exception('El pedido ya se envió: las líneas pedidas no se reescriben.');
        }
        $pb = (int) ($input['producto_base_id'] ?? 0);
        $description = $this->clip((string) ($input['descripcion'] ?? ''), 255);
        if ($pb <= 0 && $description === '') {
            throw new Exception('Elige un producto o describe qué se pide.');
        }
        $scope = $pb > 0 ? $this->notices()->need_scope($pb) : null;
        $link = $this->supplier_link((int) $order['proveedor_id'], (int) ($input['pp_id'] ?? 0), $pb, $scope);
        if ($link && $link['producto_base_id'] > 0) {
            $pb = $link['producto_base_id'];
        }
        $existing = $scope ? $this->open_line_for($scope['productos']) : null;
        if ($existing) {
            throw new Exception($existing['orden_id'] === (int) $order['id']
                ? 'Ya está en este pedido.'
                : 'Ya está en el pedido ' . $existing['numero'] . ' de ' . $existing['proveedor_nombre'] . '.');
        }
        $quantity = $this->line_quantity($input, $link);
        $price = $this->number($input['precio'] ?? '', 'El precio');
        if ($description === '' && $pb > 0) {
            $description = $this->clip($this->product_name($pb), 255);
        }
        $this->insert_line((int) $order['id'], [
            'producto_base_id' => $pb ?: null,
            'producto_proveedor_id' => $link ? $link['pp_id'] : null,
            'descripcion' => $description,
            'cantidad' => $quantity['cantidad'],
            'unidad' => $quantity['unidad'],
            'precio_unitario' => $price,
            'codigo_proveedor' => $link ? $link['codigo'] : null,
            'factor' => $link ? $link['factor'] : null,
            'origen' => 'manual',
            'emparejamiento_id' => $scope && $scope['emparejamiento_id'] ? $scope['emparejamiento_id'] : null,
            'grupo_id' => $scope && $scope['grupo_id'] ? $scope['grupo_id'] : null,
        ]);
        $this->touch_order((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * @param int   $item_id
     * @param array $input cantidad, unidad, precio, descripcion
     * @return array Pedido.
     * @throws Exception
     */
    public function update_line($item_id, array $input) {
        global $wpdb;
        list($line, $order) = $this->draft_line($item_id);
        $update = [];
        if (array_key_exists('cantidad', $input)) {
            $link = ['unidad' => (string) $line['unidad']];
            $update += $this->line_quantity($input + ['unidad' => $line['unidad']], $link);
        }
        if (array_key_exists('precio', $input)) {
            $update['precio_unitario'] = $this->number($input['precio'], 'El precio');
        }
        if (array_key_exists('descripcion', $input) && empty($line['producto_base_id'])) {
            $description = $this->clip((string) $input['descripcion'], 255);
            if ($description !== '') {
                $update['descripcion'] = $description;
            }
        }
        if ($update) {
            $wpdb->update($this->table('ordenes_compra_items'), $update, ['id' => (int) $line['id']]);
            $this->touch_order((int) $order['id']);
        }
        return $this->get_order((int) $order['id']);
    }

    /**
     * Quita una línea del borrador. Sus avisos vuelven a `abierto` y la alerta se ofrece de nuevo.
     *
     * @param int $item_id
     * @return array Pedido.
     * @throws Exception
     */
    public function remove_line($item_id) {
        global $wpdb;
        list($line, $order) = $this->draft_line($item_id);
        $wpdb->query('START TRANSACTION');
        try {
            $this->reopen_notices((int) $line['id']);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table('ordenes_compra_items')} WHERE id = %d",
                (int) $line['id']
            ));
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $this->touch_order((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Mueve una línea al borrador de otro proveedor. Solo mientras el pedido de origen no se envió.
     *
     * @param int $item_id
     * @param int $supplier_id
     * @param int $pp_id Vínculo elegido en el proveedor de destino (opcional).
     * @return array Pedido de destino.
     * @throws Exception
     */
    public function move_line($item_id, $supplier_id, $pp_id = 0) {
        global $wpdb;
        $line = $this->line_row($item_id);
        if (!$line) {
            throw new Exception('Línea no encontrada.');
        }
        $source = $this->require_order((int) $line['orden_id']);
        if ($source['estado'] !== 'borrador') {
            throw new Exception('Ese pedido ya se envió: déjala sin comprar allá y la necesidad vuelve a la bandeja.');
        }
        if ((int) $source['proveedor_id'] === (int) $supplier_id) {
            throw new Exception('Ya está en el pedido de ese proveedor.');
        }
        $pb = (int) $line['producto_base_id'];
        $scope = $pb > 0 ? $this->notices()->need_scope($pb) : null;
        $link = $this->supplier_link((int) $supplier_id, (int) $pp_id, $pb, $scope);

        $wpdb->query('START TRANSACTION');
        try {
            $target = $this->draft_for($supplier_id);
            $unit = (string) $line['unidad'];
            $wpdb->update($this->table('ordenes_compra_items'), [
                'orden_id' => (int) $target['id'],
                'producto_base_id' => ($link && $link['producto_base_id'] > 0) ? $link['producto_base_id'] : ($pb ?: null),
                'producto_proveedor_id' => $link ? $link['pp_id'] : null,
                'codigo_proveedor' => $link ? $link['codigo'] : null,
                'factor' => $link ? $link['factor'] : null,
                // La cantidad venía en la unidad del proveedor anterior: se conserva y la persona la revisa.
                'unidad' => $unit,
                'precio_unitario' => null,
            ], ['id' => (int) $line['id']]);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('avisos_compra')} SET orden_compra_id = %d WHERE orden_compra_item_id = %d",
                (int) $target['id'],
                (int) $line['id']
            ));
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $this->touch_order((int) $source['id']);
        $this->touch_order((int) $target['id']);
        return $this->get_order((int) $target['id']);
    }

    /**
     * Devuelve a la bandeja los avisos de una línea.
     *
     * @param int    $item_id
     * @param string $reason      '' al deshacer; `no_cotizado` o `cancelado` cuando el pedido no cubrió.
     * @param int    $supplier_id
     */
    protected function reopen_notices($item_id, $reason = '', $supplier_id = 0) {
        $notices = $this->notices()->for_order_items([(int) $item_id]);
        foreach ($notices[(int) $item_id] ?? [] as $notice) {
            if ($notice['estado'] !== 'ingresado') {
                continue;
            }
            $this->notices()->update($notice['id'], 'reabrir', $reason !== ''
                ? ['motivo' => $reason, 'proveedor_id' => (int) $supplier_id]
                : [], true);
        }
    }

    /**
     * @param int $pb
     * @return string
     */
    protected function product_name($pb) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT nombre_canonico FROM {$this->table('producto_base')} WHERE id = %d",
            (int) $pb
        ));
    }

    /* ===================== Enviar y cancelar ===================== */

    /**
     * El pedido sale: desde aquí las líneas pedidas no se reescriben. El sistema no lo manda;
     * deja el texto para copiarlo al canal de siempre.
     *
     * @param int $order_id
     * @return array Pedido, con `texto`.
     * @throws Exception
     */
    public function send($order_id) {
        $order = $this->require_order($order_id);
        if ($order['estado'] !== 'borrador') {
            throw new Exception('Este pedido ya se envió.');
        }
        $lines = $this->line_rows($order_id);
        if (!$lines) {
            throw new Exception('El pedido no tiene líneas.');
        }
        foreach ($lines as $line) {
            if ((float) $line['cantidad'] <= 0) {
                throw new Exception('Falta la cantidad de «' . (($line['nombre_canonico'] ?? '') ?: $line['descripcion']) . '».');
            }
        }
        $now = current_time('mysql');
        $this->touch_order((int) $order['id'], [
            'estado' => 'enviada',
            'enviado_en' => $now,
            'fecha_emision' => substr($now, 0, 10),
        ]);
        return $this->get_order((int) $order['id']) + ['texto' => $this->order_text((int) $order['id'])];
    }

    /**
     * Texto del pedido para pegar en el correo, el portal o el WhatsApp del proveedor.
     *
     * @param int $order_id
     * @return string
     * @throws Exception
     */
    public function order_text($order_id) {
        $order = $this->require_order($order_id);
        $units = Riverso_Purchase_Notice_Service::UNITS;
        $out = ['Pedido ' . $this->order_label($order) . ' — ' . ($order['proveedor_nombre'] ?: 'Sin proveedor'), ''];
        foreach ($this->line_rows($order_id) as $line) {
            if (($line['estado'] ?? '') === 'no_comprada') {
                continue;
            }
            $parts = [];
            if (trim((string) $line['codigo_proveedor']) !== '') {
                $parts[] = trim((string) $line['codigo_proveedor']);
            }
            $parts[] = (string) (($line['nombre_canonico'] ?? '') ?: $line['descripcion']);
            $qty = (float) $line['cantidad'];
            $parts[] = $qty > 0
                ? rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',') . ' ' . ($units[$line['unidad']] ?? $line['unidad'])
                : 'cantidad por definir';
            $out[] = implode(' — ', $parts);
        }
        $out[] = '';
        $out[] = 'Por favor citar el pedido ' . $this->order_label($order) . ' como orden de compra en la factura.';
        return implode("\n", $out);
    }

    /**
     * No se compra. Los avisos de sus líneas vuelven a `abierto`.
     *
     * @param int $order_id
     * @return array Pedido.
     * @throws Exception
     */
    public function cancel($order_id) {
        global $wpdb;
        $order = $this->require_order($order_id);
        if (!in_array($order['estado'], ['borrador', 'enviada', 'en_cotizacion', 'en_revision', 'aceptada'], true)) {
            throw new Exception('Este pedido ya no se puede cancelar (' . (self::STATES[$order['estado']] ?? $order['estado']) . ').');
        }
        $lines = $this->line_rows($order_id);
        foreach ($lines as $line) {
            if ((float) $line['cantidad_recibida'] > self::EPS) {
                throw new Exception('Ya se recibió parte de este pedido: no se puede cancelar.');
            }
        }
        $sent = $order['estado'] !== 'borrador';
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($lines as $line) {
                $this->reopen_notices((int) $line['id'], $sent ? 'cancelado' : '', (int) $order['proveedor_id']);
            }
            $this->touch_order((int) $order['id'], ['estado' => 'cancelada']);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return $this->get_order((int) $order['id']);
    }

    /**
     * Una línea ya enviada que no se va a comprar. No se borra: queda constancia, y la
     * necesidad vuelve a la bandeja.
     *
     * @param int $item_id
     * @return array Pedido.
     * @throws Exception
     */
    public function drop_line($item_id) {
        $line = $this->line_row($item_id);
        if (!$line) {
            throw new Exception('Línea no encontrada.');
        }
        $order = $this->require_order((int) $line['orden_id']);
        if (!in_array($order['estado'], ['enviada', 'en_cotizacion', 'en_revision'], true)) {
            throw new Exception($order['estado'] === 'borrador'
                ? 'En el borrador la línea se quita.'
                : 'Este pedido ya no admite cambios en sus líneas.');
        }
        $this->mark_not_bought($line, $order);
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * @param array $line
     * @param array $order
     */
    protected function mark_not_bought(array $line, array $order) {
        global $wpdb;
        if (($line['estado'] ?? '') === 'no_comprada') {
            return;
        }
        $wpdb->update($this->table('ordenes_compra_items'), [
            'estado' => 'no_comprada',
            'cantidad_aceptada' => null,
            'precio_aceptado' => null,
        ], ['id' => (int) $line['id']]);
        $this->reopen_notices((int) $line['id'], 'no_cotizado', (int) $order['proveedor_id']);
    }

    /**
     * @param mixed $value
     * @param int   $max
     * @return string
     */
    protected function clip($value, $max) {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    /* ===================== Cotización ===================== */

    const DECISIONS = [
        'pending' => 'Pendiente',
        'accepted' => 'Aceptado',
        'accepted_increase' => 'Alza aceptada',
        'claim' => 'Pedir precio anterior',
        'fix_qty' => 'Corregir cantidad',
        'remove' => 'Quitar',
        'add' => 'Pedir que la agreguen',
    ];

    /** Decisiones que esperan respuesta del proveedor: con una de estas el pedido no queda aceptado. */
    const AWAITING = ['claim', 'add', 'fix_qty'];

    /** Lo que va en el correo de revisión de precios. */
    const REVISION = ['claim', 'add', 'fix_qty', 'remove'];

    /** Alza que cuenta: la misma de Cotizaciones Proveedores (DELTA_SIGNIFICATIVO). */
    const PRICE_DELTA = 0.01;

    /**
     * @return bool
     */
    protected function quotes_ready() {
        return $this->table_exists('cotizaciones_recibidas') && $this->table_exists('cotizacion_items');
    }

    /**
     * @param int $id
     * @return array|null
     */
    protected function quote_row($id) {
        global $wpdb;
        if ($id <= 0 || !$this->quotes_ready()) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, p.nombre AS proveedor_nombre
             FROM {$this->table('cotizaciones_recibidas')} c
             LEFT JOIN {$this->table('proveedores')} p ON p.id = c.proveedor_id
             WHERE c.id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * Versiones de una cotización, de la primera a la última. El proveedor manda otra después
     * de una revisión; el pedido trabaja siempre contra la final.
     *
     * @param array $quote
     * @return array
     */
    protected function quote_versions(array $quote) {
        global $wpdb;
        $group = $this->has_column('cotizaciones_recibidas', 'version_group_id') ? (int) ($quote['version_group_id'] ?? 0) : 0;
        if ($group <= 0) {
            return [$quote];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.nombre AS proveedor_nombre
             FROM {$this->table('cotizaciones_recibidas')} c
             LEFT JOIN {$this->table('proveedores')} p ON p.id = c.proveedor_id
             WHERE c.version_group_id = %d
             ORDER BY COALESCE(c.version_n, 0) ASC, c.id ASC",
            $group
        ), ARRAY_A) ?: [];
        return $rows ?: [$quote];
    }

    /**
     * @param int $quote_id
     * @return array<int, array> Ítems por id.
     */
    protected function quote_items($quote_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('cotizacion_items')} WHERE cotizacion_id = %d ORDER BY linea ASC, id ASC",
            (int) $quote_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $this->with_cost_reference((int) $quote_id, $out);
    }

    /**
     * Costo anterior y diferencia con el mismo criterio de "Evaluar costos" de Cotizaciones
     * Proveedores. Lo guardado en el ítem solo se llena al calzar y puede estar vacío.
     *
     * @param int   $quote_id
     * @param array $items
     * @return array
     */
    protected function with_cost_reference($quote_id, array $items) {
        if (!$items || !function_exists('add_action')) {
            return $items;
        }
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            $file = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            return $items;
        }
        $analysis = Riverso_Cost_Lookup_Service::get_instance()->analyze_quote($quote_id, 'auto');
        if (is_wp_error($analysis) || empty($analysis['rows'])) {
            return $items;
        }
        foreach ($analysis['rows'] as $row) {
            $id = (int) ($row['item_id'] ?? 0);
            if (!isset($items[$id]) || !isset($row['reference_cost']) || !is_numeric($row['reference_cost'])) {
                continue;
            }
            $items[$id]['costo_anterior'] = (float) $row['reference_cost'];
            if (isset($row['delta']) && is_numeric($row['delta'])) {
                $items[$id]['diferencia_costo'] = (float) $row['delta'];
            }
        }
        return $items;
    }

    /**
     * Cotizaciones del proveedor que todavía no tienen pedido: las que se pueden vincular.
     *
     * @param array $order
     * @return array
     */
    protected function quote_candidates(array $order) {
        global $wpdb;
        if (!$this->quotes_ready()) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.numero_documento, c.fecha_documento, c.estado, c.total,
                    (SELECT COUNT(*) FROM {$this->table('cotizacion_items')} i WHERE i.cotizacion_id = c.id) AS items
             FROM {$this->table('cotizaciones_recibidas')} c
             WHERE c.proveedor_id = %d
               AND c.estado NOT IN ('rejected', 'archived', 'converted_to_expected')
               AND c.id NOT IN (SELECT o.cotizacion_id FROM {$this->table('ordenes_compra')} o
                                WHERE o.cotizacion_id IS NOT NULL AND o.estado <> 'cancelada')
             ORDER BY c.id DESC LIMIT 15",
            (int) $order['proveedor_id']
        ), ARRAY_A) ?: [];
        $out = [];
        $seen_groups = [];
        foreach ($rows as $row) {
            $quote = $this->quote_row((int) $row['id']);
            $versions = $this->quote_versions($quote);
            $final = end($versions);
            $group = (int) $final['id'];
            // De un grupo de versiones se ofrece una sola: la final.
            if (isset($seen_groups[$group]) || $this->order_of_quote_group($versions)) {
                continue;
            }
            $seen_groups[$group] = true;
            $out[] = [
                'id' => (int) $final['id'],
                'numero' => (string) ($final['numero_documento'] ?? '') ?: '#' . $final['id'],
                'fecha' => (string) ($final['fecha_documento'] ?? ''),
                'versiones' => count($versions),
                'items' => (int) $row['items'],
                'total' => (float) ($final['total'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array $versions
     * @return int Pedido no cancelado que ya tiene alguna versión de esta cotización. 0 si ninguno.
     */
    protected function order_of_quote_group(array $versions) {
        global $wpdb;
        $ids = array_map('intval', array_column($versions, 'id'));
        if (!$ids) {
            return 0;
        }
        return (int) $wpdb->get_var(
            "SELECT id FROM {$this->table('ordenes_compra')}
             WHERE cotizacion_id IN (" . implode(',', $ids) . ") AND estado <> 'cancelada' ORDER BY id ASC LIMIT 1"
        );
    }

    /**
     * Pedido que ya tiene esta cotización (en cualquiera de sus versiones).
     *
     * @param int $quote_id
     * @return array|null Fila de ordenes_compra.
     */
    public function order_for_quote($quote_id) {
        $quote = $this->quote_row((int) $quote_id);
        if (!$quote) {
            return null;
        }
        $order_id = $this->order_of_quote_group($this->quote_versions($quote));
        return $order_id > 0 ? $this->order_row($order_id) : null;
    }

    /**
     * Adjunta la cotización recibida a este pedido.
     *
     * @param int $order_id
     * @param int $quote_id
     * @return array Pedido.
     * @throws Exception
     */
    public function link_quote($order_id, $quote_id) {
        $order = $this->require_order($order_id);
        if (!in_array($order['estado'], ['enviada', 'en_cotizacion', 'en_revision'], true)) {
            throw new Exception($order['estado'] === 'borrador'
                ? 'Envía el pedido antes de vincular la cotización.'
                : 'Este pedido ya no admite otra cotización.');
        }
        $quote = $this->quote_row((int) $quote_id);
        if (!$quote) {
            throw new Exception('Cotización no encontrada.');
        }
        if (!empty($quote['proveedor_id']) && (int) $quote['proveedor_id'] !== (int) $order['proveedor_id']) {
            throw new Exception('Esa cotización es de otro proveedor (' . $quote['proveedor_nombre'] . ').');
        }
        $versions = $this->quote_versions($quote);
        $other = $this->order_of_quote_group($versions);
        if ($other > 0 && $other !== (int) $order['id']) {
            throw new Exception('Esa cotización ya está en el pedido ' . $this->order_label($this->order_row($other)) . '.');
        }
        $final = end($versions);
        $this->clear_matches((int) $order['id']);
        $this->touch_order((int) $order['id'], ['cotizacion_id' => (int) $final['id'], 'revision_enviada_en' => null]);
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * @param int $order_id
     * @return array Pedido.
     * @throws Exception
     */
    public function unlink_quote($order_id) {
        $order = $this->require_order($order_id);
        if (!in_array($order['estado'], ['en_cotizacion', 'en_revision'], true)) {
            throw new Exception('Este pedido ya no admite cambiar la cotización.');
        }
        $this->clear_matches((int) $order['id']);
        $this->touch_order((int) $order['id'], ['cotizacion_id' => null, 'revision_enviada_en' => null]);
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * @param int $order_id
     */
    protected function clear_matches($order_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('ordenes_compra_items')}
             SET cotizacion_item_id = NULL, cantidad_aceptada = NULL, precio_aceptado = NULL
             WHERE orden_id = %d",
            (int) $order_id
        ));
    }

    /**
     * Llegó una versión nueva de la cotización: el diff se rehace contra ella. Lo que habíamos
     * pedido quitar y sigue viniendo se marca de nuevo como quitar; el resto lo decide otra vez
     * la auto-decisión de Cotizaciones Proveedores.
     *
     * @param array $order
     */
    protected function sync_quote_version(array $order) {
        global $wpdb;
        $quote = $this->quote_row((int) $order['cotizacion_id']);
        if (!$quote) {
            return;
        }
        $versions = $this->quote_versions($quote);
        $final = end($versions);
        if ((int) $final['id'] === (int) $quote['id']) {
            return;
        }
        $removed = [];
        foreach ($this->quote_items((int) $quote['id']) as $item) {
            $code = Riverso_Purchase_Notice_Service::normalize_code($item['codigo_proveedor'] ?? '');
            if ($code !== '' && ($item['decision_status'] ?? '') === 'remove') {
                $removed[$code] = true;
            }
        }
        foreach ($this->quote_items((int) $final['id']) as $item) {
            $code = Riverso_Purchase_Notice_Service::normalize_code($item['codigo_proveedor'] ?? '');
            if (isset($removed[$code]) && in_array($item['decision_status'] ?? 'pending', ['pending', 'accepted', 'accepted_increase'], true)) {
                $wpdb->update($this->table('cotizacion_items'), ['decision_status' => 'remove'], ['id' => (int) $item['id']]);
            }
        }
        $this->clear_matches((int) $order['id']);
        $this->touch_order((int) $order['id'], ['cotizacion_id' => (int) $final['id'], 'revision_enviada_en' => null]);
        $this->recalc_state((int) $order['id']);
    }

    /**
     * Calza cada línea del pedido con una de la cotización: lo guardado, después el código de
     * proveedor, después el SKU. `cotizacion_items.producto_id` es un ID de WooCommerce y no
     * se compara con `producto_base_id`.
     *
     * @param array $lines Filas del pedido.
     * @param array $items Ítems de la cotización por id.
     * @return array<int, int> Ítem de cotización por línea.
     */
    protected function match_lines(array $lines, array $items) {
        global $wpdb;
        $match = [];
        $used = [];
        foreach ($lines as $line) {
            $stored = (int) ($line['cotizacion_item_id'] ?? 0);
            if ($stored > 0 && isset($items[$stored]) && !isset($used[$stored])) {
                $match[(int) $line['id']] = $stored;
                $used[$stored] = true;
            }
        }

        $by_code = [];
        $by_sku = [];
        foreach ($items as $id => $item) {
            if (isset($used[$id])) {
                continue;
            }
            $code = Riverso_Purchase_Notice_Service::normalize_code($item['codigo_proveedor'] ?? '');
            if ($code !== '') {
                $by_code[$code][] = $id;
            }
            $sku = trim((string) ($item['sku_match'] ?? ''));
            if ($sku !== '') {
                $by_sku[$sku][] = $id;
            }
        }
        $take = function (array &$index, $key) use (&$used) {
            foreach ($index[$key] ?? [] as $id) {
                if (!isset($used[$id])) {
                    $used[$id] = true;
                    return $id;
                }
            }
            return 0;
        };

        foreach ([['codigo_proveedor', true], ['canonical_sku', false]] as $pass) {
            foreach ($lines as $line) {
                $line_id = (int) $line['id'];
                if (isset($match[$line_id])) {
                    continue;
                }
                $value = trim((string) ($line[$pass[0]] ?? ''));
                if ($value === '') {
                    continue;
                }
                $found = $pass[1]
                    ? $take($by_code, Riverso_Purchase_Notice_Service::normalize_code($value))
                    : $take($by_sku, $value);
                if ($found > 0) {
                    $match[$line_id] = $found;
                }
            }
        }

        foreach ($lines as $line) {
            $line_id = (int) $line['id'];
            $now = $match[$line_id] ?? 0;
            if ($now !== (int) ($line['cotizacion_item_id'] ?? 0)) {
                $wpdb->update($this->table('ordenes_compra_items'), ['cotizacion_item_id' => $now ?: null], ['id' => $line_id]);
            }
        }
        return $match;
    }

    /**
     * Calce a mano. Una línea sin producto toma el de la línea de cotización.
     *
     * @param int $item_id      Línea del pedido.
     * @param int $quote_item_id 0 para soltar el calce.
     * @return array Pedido.
     * @throws Exception
     */
    public function set_match($item_id, $quote_item_id) {
        global $wpdb;
        $line = $this->line_row($item_id);
        if (!$line) {
            throw new Exception('Línea no encontrada.');
        }
        $order = $this->quote_order((int) $line['orden_id']);
        $update = ['cotizacion_item_id' => null, 'cantidad_aceptada' => null, 'precio_aceptado' => null];
        if ($quote_item_id > 0) {
            $items = $this->quote_items((int) $order['cotizacion_id']);
            if (!isset($items[(int) $quote_item_id])) {
                throw new Exception('Esa línea no es de la cotización vigente.');
            }
            $taken = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('ordenes_compra_items')} WHERE orden_id = %d AND cotizacion_item_id = %d AND id <> %d",
                (int) $order['id'],
                (int) $quote_item_id,
                (int) $line['id']
            ));
            if ($taken > 0) {
                throw new Exception('Esa línea de la cotización ya calza con otra del pedido.');
            }
            $update['cotizacion_item_id'] = (int) $quote_item_id;
            if (empty($line['producto_base_id'])) {
                $update += $this->product_from_quote_item($items[(int) $quote_item_id]);
            }
            if (trim((string) $line['codigo_proveedor']) === '') {
                $update['codigo_proveedor'] = $this->clip($items[(int) $quote_item_id]['codigo_proveedor'] ?? '', 100) ?: null;
            }
        }
        $wpdb->update($this->table('ordenes_compra_items'), $update, ['id' => (int) $line['id']]);
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Producto Riverso de una línea de cotización, por su SKU. Sin SKU reconocido, nada.
     *
     * @param array $item
     * @return array Columnas de producto para una línea de pedido.
     */
    protected function product_from_quote_item(array $item) {
        global $wpdb;
        $sku = trim((string) ($item['sku_match'] ?? ''));
        if ($sku === '') {
            return [];
        }
        $pb = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('producto_base')} WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
        if ($pb <= 0) {
            return [];
        }
        $scope = $this->notices()->need_scope($pb);
        return [
            'producto_base_id' => $pb,
            'emparejamiento_id' => $scope['emparejamiento_id'] ?: null,
            'grupo_id' => $scope['grupo_id'] ?: null,
        ];
    }

    /**
     * @param int $order_id
     * @return array Pedido con cotización vinculada y todavía conciliable.
     * @throws Exception
     */
    protected function quote_order($order_id) {
        $order = $this->require_order($order_id);
        if (empty($order['cotizacion_id'])) {
            throw new Exception('El pedido no tiene cotización vinculada.');
        }
        if (!in_array($order['estado'], ['en_cotizacion', 'en_revision'], true)) {
            throw new Exception('Este pedido ya no está en cotización (' . (self::STATES[$order['estado']] ?? $order['estado']) . ').');
        }
        return $order;
    }

    /**
     * Zona Cotización de la ficha: el diff línea a línea contra la versión final.
     *
     * @param array $order
     * @param array $lines
     * @return array
     */
    protected function quote_zone(array $order, array $lines) {
        $can_quote = in_array($order['estado'], ['enviada', 'en_cotizacion', 'en_revision'], true);
        $quote = !empty($order['cotizacion_id']) ? $this->quote_row((int) $order['cotizacion_id']) : null;
        if (!$quote) {
            return [
                'vinculada' => false,
                'candidatas' => $can_quote ? $this->quote_candidates($order) : [],
            ];
        }
        $versions = $this->quote_versions($quote);
        $items = $this->quote_items((int) $quote['id']);
        $rows = $this->diff_rows($order, $lines, $items);

        $pending = 0;
        $awaiting = 0;
        $revision = 0;
        foreach ($rows as $row) {
            if (!$row['decidida']) {
                $pending++;
            }
            if (in_array($row['decision'], self::AWAITING, true)) {
                $awaiting++;
            }
            if (in_array($row['decision'], self::REVISION, true)) {
                $revision++;
            }
        }
        $sent = (string) ($order['revision_enviada_en'] ?? '');
        $open = in_array($order['estado'], ['en_cotizacion', 'en_revision'], true);
        $block = '';
        if ($pending > 0) {
            $block = $pending === 1 ? 'Queda 1 diferencia sin decidir.' : 'Quedan ' . $pending . ' diferencias sin decidir.';
        } elseif ($awaiting > 0) {
            $block = $awaiting === 1
                ? 'Hay 1 línea esperando respuesta del proveedor.'
                : 'Hay ' . $awaiting . ' líneas esperando respuesta del proveedor.';
        }
        return [
            'vinculada' => true,
            'id' => (int) $quote['id'],
            'numero' => (string) ($quote['numero_documento'] ?? '') ?: '#' . $quote['id'],
            'fecha' => (string) ($quote['fecha_documento'] ?? ''),
            'estado' => (string) $quote['estado'],
            'versiones' => count($versions),
            'version' => (int) ($quote['version_n'] ?? 0) ?: count($versions),
            'filas' => $rows,
            'pendientes' => $pending,
            'esperando' => $awaiting,
            'para_revision' => $revision,
            'revision_enviada_en' => $sent,
            // Corresponde mandar la revisión: hay algo que pedirle al proveedor y todavía no se mandó.
            'revision_por_mandar' => $open && $revision > 0 && $pending === 0 && $sent === '',
            'puede_aceptar' => $open && $pending === 0 && $awaiting === 0,
            'bloqueo' => $open ? $block : '',
            'abierta' => $open,
        ];
    }

    /**
     * @param array $order
     * @param array $lines
     * @param array $items Ítems de la cotización por id.
     * @return array
     */
    protected function diff_rows(array $order, array $lines, array $items) {
        $match = $this->match_lines($lines, $items);
        $matched_items = array_flip($match);
        $units = Riverso_Purchase_Notice_Service::UNITS;
        $rows = [];

        foreach ($lines as $line) {
            $line_id = (int) $line['id'];
            $item = isset($match[$line_id]) ? $items[$match[$line_id]] : null;
            $line_view = [
                'id' => $line_id,
                'titulo' => (string) (($line['nombre_canonico'] ?? '') ?: ($line['descripcion'] ?? '') ?: 'Sin identificar'),
                'sku' => (string) ($line['canonical_sku'] ?? ''),
                'identificado' => !empty($line['producto_base_id']),
                'codigo' => (string) ($line['codigo_proveedor'] ?? ''),
                'cantidad' => (float) $line['cantidad'],
                'unidad_label' => $units[$line['unidad']] ?? (string) $line['unidad'],
                'estado' => (string) $line['estado'],
            ];
            if (!$item) {
                $dropped = $line['estado'] === 'no_comprada';
                $rows[] = [
                    'clave' => 'l' . $line_id,
                    'tipo' => 'solo_pedido',
                    'marcas' => ['solo_pedido'],
                    'linea' => $line_view,
                    'cot' => null,
                    'decision' => $dropped ? 'remove' : 'pending',
                    'decision_label' => $dropped ? 'No se compra' : self::DECISIONS['pending'],
                    'decidida' => $dropped,
                    'opciones' => [
                        ['valor' => 'add', 'label' => 'Pedir que la agreguen'],
                        ['valor' => 'remove', 'label' => 'No se compra'],
                    ],
                ];
                continue;
            }

            $decision = (string) ($item['decision_status'] ?? 'pending') ?: 'pending';
            $delta = $this->price_delta($item);
            $marks = [];
            if ($decision === 'add') {
                $marks[] = 'solo_pedido';
            } else {
                if ($delta !== null && $delta > self::PRICE_DELTA) {
                    $marks[] = 'precio';
                }
                if ($this->quantity_differs($line, $item)) {
                    $marks[] = 'cantidad';
                }
            }
            $accepted = in_array($decision, ['accepted', 'accepted_increase'], true);
            // Con otra cantidad, "aceptado" vale solo si una persona aceptó esa cantidad en la ficha.
            // Lo que espera al proveedor (claim, add, corregir cantidad) ya está decidido: falta la respuesta.
            $decided = in_array($decision, self::REVISION, true)
                || ($accepted && (!in_array('cantidad', $marks, true) || $line['cantidad_aceptada'] !== null));
            if ($line['estado'] === 'no_comprada') {
                $decision = 'remove';
                $decided = true;
            }

            $options = [];
            if ($decision === 'add') {
                $options[] = ['valor' => 'remove', 'label' => 'No se compra'];
            } else {
                $options[] = in_array('precio', $marks, true)
                    ? ['valor' => 'accepted_increase', 'label' => 'Aceptar el alza']
                    : ['valor' => 'accepted', 'label' => in_array('cantidad', $marks, true) ? 'Aceptar la cantidad cotizada' : 'Aceptar'];
                if (in_array('precio', $marks, true)) {
                    $options[] = ['valor' => 'claim', 'label' => 'Pedir el precio anterior'];
                }
                if (in_array('cantidad', $marks, true)) {
                    $options[] = ['valor' => 'fix_qty', 'label' => 'Pedir que corrijan la cantidad'];
                }
                $options[] = ['valor' => 'remove', 'label' => 'No se compra'];
            }

            $rows[] = [
                'clave' => 'l' . $line_id,
                'tipo' => $marks ? $marks[0] : 'igual',
                'marcas' => $marks ?: ['igual'],
                'linea' => $line_view,
                'cot' => $this->present_quote_item($item, $delta),
                'decision' => $decision,
                'decision_label' => $line['estado'] === 'no_comprada' ? 'No se compra' : (self::DECISIONS[$decision] ?? $decision),
                'decidida' => $decided,
                'opciones' => $options,
            ];
        }

        foreach ($items as $id => $item) {
            if (isset($matched_items[$id])) {
                continue;
            }
            $decision = (string) ($item['decision_status'] ?? 'pending') ?: 'pending';
            // Vino sin pedirla: que la cotización la tenga "aceptada" no es una decisión del pedido.
            $decided = $decision === 'remove';
            $rows[] = [
                'clave' => 'c' . $id,
                'tipo' => 'solo_cotizacion',
                'marcas' => ['solo_cotizacion'],
                'linea' => null,
                'cot' => $this->present_quote_item($item, $this->price_delta($item)),
                'decision' => $decided ? 'remove' : ($decision === 'add' ? 'add' : 'pending'),
                'decision_label' => $decided ? 'Pedir que la quiten' : ($decision === 'add' ? self::DECISIONS['add'] : self::DECISIONS['pending']),
                'decidida' => $decided,
                'opciones' => [
                    ['valor' => 'accepted', 'label' => 'Aceptar: agregar al pedido'],
                    ['valor' => 'remove', 'label' => 'Pedir que la quiten'],
                ],
            ];
        }
        return $rows;
    }

    /**
     * @param array $item
     * @return float|null Cuánto sube (o baja) el costo contra el anterior. Null sin costo anterior.
     */
    protected function price_delta(array $item) {
        if (!isset($item['costo_anterior']) || !is_numeric($item['costo_anterior'])) {
            return null;
        }
        if (isset($item['diferencia_costo']) && is_numeric($item['diferencia_costo'])) {
            return (float) $item['diferencia_costo'];
        }
        return (float) $item['costo_neto'] - (float) $item['costo_anterior'];
    }

    /**
     * Pidieron una cantidad y cotizan otra. Si las unidades son distintas se compara en
     * unidades de stock; sin factor que las relacione, se muestra como distinta.
     *
     * @param array $line
     * @param array $item
     * @return bool
     */
    protected function quantity_differs(array $line, array $item) {
        $line_unit = (string) $line['unidad'];
        $quote_unit = self::unit_key($item['unidad'] ?? '') ?: $line_unit;
        $ordered = (float) $line['cantidad'];
        $quoted = (float) $item['cantidad'];
        if ($quote_unit === $line_unit) {
            return abs($ordered - $quoted) > self::EPS;
        }
        $factor = $line['factor'] !== null ? (float) $line['factor'] : null;
        $a = $this->base_quantity($ordered, $line_unit, $factor);
        $b = $this->base_quantity($quoted, $quote_unit, $factor);
        return $a === null || $b === null || abs($a - $b) > self::EPS;
    }

    /**
     * @param array      $item
     * @param float|null $delta
     * @return array
     */
    protected function present_quote_item(array $item, $delta) {
        return [
            'id' => (int) $item['id'],
            'codigo' => (string) ($item['codigo_proveedor'] ?? ''),
            'descripcion' => (string) ($item['descripcion'] ?? ''),
            'cantidad' => (float) $item['cantidad'],
            'unidad' => (string) ($item['unidad'] ?? ''),
            'costo' => (float) $item['costo_neto'],
            'costo_anterior' => isset($item['costo_anterior']) && is_numeric($item['costo_anterior']) ? (float) $item['costo_anterior'] : null,
            'diferencia' => $delta,
            'sube' => $delta !== null && $delta > self::PRICE_DELTA,
            'baja' => $delta !== null && $delta < -self::PRICE_DELTA,
        ];
    }

    /**
     * Decide una diferencia. La decisión se guarda donde la cotización recibida ya la guarda.
     *
     * @param int   $order_id
     * @param array $input linea_id, cot_item_id, decision
     * @return array Pedido.
     * @throws Exception
     */
    public function decide($order_id, array $input) {
        global $wpdb;
        $order = $this->quote_order($order_id);
        $decision = sanitize_key((string) ($input['decision'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) {
            throw new Exception('Decisión no reconocida.');
        }
        $items = $this->quote_items((int) $order['cotizacion_id']);
        $line = !empty($input['linea_id']) ? $this->line_row((int) $input['linea_id']) : null;
        if ($line && (int) $line['orden_id'] !== (int) $order['id']) {
            throw new Exception('La línea no es de este pedido.');
        }
        $item_id = $line ? (int) ($line['cotizacion_item_id'] ?? 0) : (int) ($input['cot_item_id'] ?? 0);
        $item = $item_id > 0 ? ($items[$item_id] ?? null) : null;
        if (!$line && !$item) {
            throw new Exception('Línea no encontrada.');
        }

        $wpdb->query('START TRANSACTION');
        try {
            if ($line && !$item) {
                $this->decide_unquoted($order, $line, $decision);
            } elseif (!$line) {
                $this->decide_unordered($order, $item, $decision);
            } else {
                $this->decide_matched($order, $line, $item, $decision);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Pedimos algo que no vino en la cotización.
     */
    private function decide_unquoted(array $order, array $line, $decision) {
        global $wpdb;
        if ($decision === 'add') {
            if ($line['estado'] === 'no_comprada') {
                $wpdb->update($this->table('ordenes_compra_items'), ['estado' => 'pendiente'], ['id' => (int) $line['id']]);
            }
            $units = Riverso_Purchase_Notice_Service::UNITS;
            $next = 1 + (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(linea), 0) FROM {$this->table('cotizacion_items')} WHERE cotizacion_id = %d",
                (int) $order['cotizacion_id']
            ));
            $sku = !empty($line['producto_base_id']) ? (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_sku FROM {$this->table('producto_base')} WHERE id = %d",
                (int) $line['producto_base_id']
            )) : '';
            $wpdb->insert($this->table('cotizacion_items'), [
                'cotizacion_id' => (int) $order['cotizacion_id'],
                'linea' => $next,
                'codigo_proveedor' => trim((string) $line['codigo_proveedor']) ?: null,
                'descripcion' => $this->line_title($line),
                'cantidad' => (float) $line['cantidad'],
                'unidad' => $this->clip($units[$line['unidad']] ?? (string) $line['unidad'], 20),
                'costo_neto' => 0,
                'sku_match' => $sku !== '' ? $sku : null,
                'decision_status' => 'add',
                'created_by' => get_current_user_id() ?: null,
                'updated_by' => get_current_user_id() ?: null,
            ]);
            $wpdb->update($this->table('ordenes_compra_items'), ['cotizacion_item_id' => (int) $wpdb->insert_id], ['id' => (int) $line['id']]);
            return;
        }
        if ($decision === 'remove') {
            $this->mark_not_bought($line, $order);
            return;
        }
        if ($decision === 'pending') {
            $wpdb->update($this->table('ordenes_compra_items'), ['estado' => 'pendiente'], ['id' => (int) $line['id']]);
            return;
        }
        throw new Exception('Esa línea no vino en la cotización: pide que la agreguen o déjala sin comprar.');
    }

    /**
     * Vino algo que no pedimos.
     */
    private function decide_unordered(array $order, array $item, $decision) {
        global $wpdb;
        if ($decision === 'remove' || $decision === 'pending') {
            $this->set_quote_decision((int) $item['id'], $decision);
            return;
        }
        if (!in_array($decision, ['accepted', 'accepted_increase'], true)) {
            throw new Exception('Esa línea no la pedimos: acéptala o pide que la quiten.');
        }
        $product = $this->product_from_quote_item($item);
        $pb = (int) ($product['producto_base_id'] ?? 0);
        $link = null;
        $code = Riverso_Purchase_Notice_Service::normalize_code($item['codigo_proveedor'] ?? '');
        if ($pb > 0) {
            $link = $this->supplier_link((int) $order['proveedor_id'], 0, $pb, $this->notices()->need_scope($pb));
        }
        $unit = self::unit_key($item['unidad'] ?? '') ?: (($link ? $link['unidad'] : '') ?: 'unidad');
        $this->insert_line((int) $order['id'], $product + [
            'producto_proveedor_id' => $link ? $link['pp_id'] : null,
            'descripcion' => $this->clip((string) ($item['descripcion'] ?? ''), 255),
            'cantidad' => round((float) $item['cantidad'], 3),
            'unidad' => $unit,
            'precio_unitario' => (float) $item['costo_neto'],
            'codigo_proveedor' => $code !== '' ? $this->clip($item['codigo_proveedor'], 100) : null,
            'factor' => $link ? $link['factor'] : null,
            'origen' => 'cotizacion',
            'cotizacion_item_id' => (int) $item['id'],
            'cantidad_aceptada' => round((float) $item['cantidad'], 3),
            'precio_aceptado' => (float) $item['costo_neto'],
        ]);
        $this->set_quote_decision((int) $item['id'], $this->accept_decision($item));
    }

    /**
     * Línea pedida que vino en la cotización.
     */
    private function decide_matched(array $order, array $line, array $item, $decision) {
        global $wpdb;
        $ours = ($item['decision_status'] ?? '') === 'add';
        $clear = ['cantidad_aceptada' => null, 'precio_aceptado' => null];

        if ($decision === 'remove') {
            if ($ours) {
                // La línea de "agregar" la creamos nosotros: ya no hay nada que pedirle al proveedor.
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->table('cotizacion_items')} WHERE id = %d", (int) $item['id']));
                $wpdb->update($this->table('ordenes_compra_items'), ['cotizacion_item_id' => null], ['id' => (int) $line['id']]);
            } else {
                $this->set_quote_decision((int) $item['id'], 'remove');
            }
            $this->mark_not_bought($line, $order);
            return;
        }
        if ($ours) {
            if ($decision !== 'add') {
                throw new Exception('Esa línea está pedida al proveedor: espera su cotización o déjala sin comprar.');
            }
            return;
        }
        if ($decision === 'add') {
            throw new Exception('Esa línea ya vino en la cotización.');
        }

        $update = ['estado' => 'pendiente'] + $clear;
        if (in_array($decision, ['accepted', 'accepted_increase'], true)) {
            $decision = $this->accept_decision($item);
            $update['cantidad_aceptada'] = round((float) $item['cantidad'], 3);
            $update['precio_aceptado'] = (float) $item['costo_neto'];
        } elseif ($decision === 'claim') {
            $delta = $this->price_delta($item);
            if ($delta === null || $delta <= self::PRICE_DELTA) {
                throw new Exception('El costo no subió: no hay precio anterior que pedir.');
            }
        } elseif ($decision === 'fix_qty' && !$this->quantity_differs($line, $item)) {
            throw new Exception('La cantidad cotizada es la pedida.');
        }
        $this->set_quote_decision((int) $item['id'], $decision);
        $wpdb->update($this->table('ordenes_compra_items'), $update, ['id' => (int) $line['id']]);
    }

    /**
     * Aceptar con alza significativa queda como "alza aceptada", igual que en Cotizaciones Proveedores.
     *
     * @param array $item
     * @return string
     */
    protected function accept_decision(array $item) {
        $delta = $this->price_delta($item);
        return ($delta !== null && $delta > self::PRICE_DELTA) ? 'accepted_increase' : 'accepted';
    }

    /**
     * @param int    $quote_item_id
     * @param string $decision
     */
    protected function set_quote_decision($quote_item_id, $decision) {
        global $wpdb;
        $wpdb->update($this->table('cotizacion_items'), [
            'decision_status' => $decision,
            'updated_by' => get_current_user_id() ?: null,
        ], ['id' => (int) $quote_item_id]);
    }

    /**
     * @param array $line
     * @return string
     */
    protected function line_title(array $line) {
        $name = !empty($line['producto_base_id']) ? $this->product_name((int) $line['producto_base_id']) : '';
        return $name !== '' ? $name : (string) ($line['descripcion'] ?? '');
    }

    /**
     * La revisión de precios se mandó al proveedor. El correo se copia desde Cotizaciones
     * Proveedores; el sistema no lo envía, por eso se marca a mano.
     *
     * @param int $order_id
     * @return array Pedido.
     * @throws Exception
     */
    public function mark_revision_sent($order_id) {
        $order = $this->quote_order($order_id);
        $zone = $this->quote_zone($order, $this->line_rows((int) $order['id']));
        if ($zone['pendientes'] > 0) {
            throw new Exception($zone['bloqueo']);
        }
        if ($zone['para_revision'] <= 0) {
            throw new Exception('No hay nada que revisar con el proveedor: todas las líneas quedaron aceptadas.');
        }
        $this->touch_order((int) $order['id'], ['revision_enviada_en' => current_time('mysql')]);
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Lo que se pidió por cada línea de una cotización: para la parte "cantidades" del correo de revisión.
     *
     * @param int $quote_id
     * @return array<int, array{cantidad: float, unidad: string}> Por ítem de cotización.
     */
    public function requested_quantities($quote_id) {
        global $wpdb;
        if (!$this->has_column('ordenes_compra_items', 'cotizacion_item_id')) {
            return [];
        }
        $units = Riverso_Purchase_Notice_Service::UNITS;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.cotizacion_item_id, i.cantidad, i.unidad
             FROM {$this->table('ordenes_compra_items')} i
             INNER JOIN {$this->table('cotizacion_items')} c ON c.id = i.cotizacion_item_id
             WHERE c.cotizacion_id = %d",
            (int) $quote_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['cotizacion_item_id']] = [
                'cantidad' => (float) $row['cantidad'],
                'unidad' => $units[$row['unidad']] ?? (string) $row['unidad'],
            ];
        }
        return $out;
    }

    /**
     * Acepta la cotización: lo aceptado queda como lo que Recepción espera.
     *
     * @param int $order_id
     * @return array Pedido.
     * @throws Exception
     */
    public function accept($order_id) {
        global $wpdb;
        $order = $this->quote_order($order_id);
        $lines = $this->line_rows((int) $order['id']);
        $zone = $this->quote_zone($order, $lines);
        if (!$zone['puede_aceptar']) {
            throw new Exception($zone['bloqueo'] ?: 'La cotización todavía no se puede aceptar.');
        }
        // quote_zone puede haber guardado calces nuevos.
        $lines = $this->line_rows((int) $order['id']);
        $items = $this->quote_items((int) $order['cotizacion_id']);

        $accepted = 0;
        $total = 0.0;
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($lines as $line) {
                if ($line['estado'] === 'no_comprada') {
                    continue;
                }
                $item = $items[(int) ($line['cotizacion_item_id'] ?? 0)] ?? null;
                if (!$item) {
                    throw new Exception('«' . $this->line_title($line) . '» no vino en la cotización y sigue sin decidir.');
                }
                if (($item['decision_status'] ?? '') === 'remove') {
                    // Se pidió quitar desde Cotizaciones Proveedores: tampoco se compra aquí.
                    $this->mark_not_bought($line, $order);
                    continue;
                }
                if (empty($line['producto_base_id'])) {
                    throw new Exception('Falta identificar «' . $this->line_title($line) . '»: sin producto, Recepción no sabe qué recibir.');
                }
                $qty = $line['cantidad_aceptada'] !== null ? (float) $line['cantidad_aceptada'] : round((float) $item['cantidad'], 3);
                $price = (float) $item['costo_neto'];
                $update = ['cantidad_aceptada' => $qty, 'precio_aceptado' => $price, 'estado' => 'aceptada'];
                // Cotizaron en otra unidad: lo aceptado queda en la unidad de la cotización.
                $quote_unit = self::unit_key($item['unidad'] ?? '');
                if ($quote_unit !== '' && $quote_unit !== $line['unidad']) {
                    $update['unidad'] = $quote_unit;
                }
                $wpdb->update($this->table('ordenes_compra_items'), $update, ['id' => (int) $line['id']]);
                $accepted++;
                $total += $qty * $price;
            }
            if ($accepted === 0) {
                throw new Exception('No queda ninguna línea por comprar: cancela el pedido.');
            }
            if (in_array($zone['estado'], ['draft', 'uploaded', 'parsed', 'under_review'], true)) {
                $wpdb->update($this->table('cotizaciones_recibidas'), [
                    'estado' => 'approved',
                    'approved_by' => get_current_user_id() ?: null,
                    'approved_at' => current_time('mysql'),
                    'updated_by' => get_current_user_id() ?: null,
                ], ['id' => (int) $order['cotizacion_id']]);
            }
            $this->touch_order((int) $order['id'], ['total' => round($total, 2)]);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $this->recalc_state((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Cotización aprobada que llegó sin pedido previo ("Convertir a llegada esperada" en
     * Cotizaciones Proveedores): nace un pedido ya aceptado, con sus líneas como llegada esperada.
     * El producto sale del SKU de la línea; `producto_id` de la cotización es de WooCommerce.
     *
     * @param int $quote_id
     * @return array Pedido.
     * @throws Exception
     */
    public function create_from_quote($quote_id) {
        global $wpdb;
        $quote = $this->quote_row((int) $quote_id);
        if (!$quote) {
            throw new Exception('Cotización no encontrada.');
        }
        if (empty($quote['proveedor_id'])) {
            throw new Exception('La cotización no tiene proveedor: asígnalo antes de convertirla.');
        }
        $existing = $this->order_of_quote_group($this->quote_versions($quote));
        if ($existing > 0) {
            throw new Exception('Esta cotización ya está en el pedido ' . $this->order_label($this->order_row($existing)) . '.');
        }
        $items = array_filter($this->quote_items((int) $quote['id']), function ($item) {
            return !in_array($item['decision_status'] ?? 'pending', ['remove', 'rejected'], true);
        });
        if (!$items) {
            throw new Exception('La cotización no tiene líneas por comprar.');
        }

        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert($this->table('ordenes_compra'), [
                'proveedor_id' => (int) $quote['proveedor_id'],
                'cotizacion_id' => (int) $quote['id'],
                'estado' => 'aceptada',
                'fecha_emision' => substr($now, 0, 10),
                'notas' => 'Desde cotización ' . ((string) ($quote['numero_documento'] ?? '') ?: '#' . $quote['id']),
                'creado_por' => get_current_user_id() ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order_id = (int) $wpdb->insert_id;
            if (!$inserted || $order_id <= 0) {
                throw new Exception('No se pudo crear el pedido.');
            }
            $total = 0.0;
            foreach ($items as $item) {
                $product = $this->product_from_quote_item($item);
                $pb = (int) ($product['producto_base_id'] ?? 0);
                $link = $pb > 0
                    ? $this->supplier_link((int) $quote['proveedor_id'], 0, $pb, $this->notices()->need_scope($pb))
                    : null;
                $qty = round((float) $item['cantidad'], 3);
                $price = (float) $item['costo_neto'];
                $this->insert_line($order_id, $product + [
                    'producto_proveedor_id' => $link ? $link['pp_id'] : null,
                    'descripcion' => $this->clip((string) ($item['descripcion'] ?? ''), 255),
                    'cantidad' => $qty,
                    'unidad' => self::unit_key($item['unidad'] ?? '') ?: (($link ? $link['unidad'] : '') ?: 'unidad'),
                    'precio_unitario' => $price,
                    'codigo_proveedor' => $this->clip((string) ($item['codigo_proveedor'] ?? ''), 100) ?: null,
                    'factor' => $link ? $link['factor'] : null,
                    'origen' => 'cotizacion',
                    'cotizacion_item_id' => (int) $item['id'],
                    'cantidad_aceptada' => $qty,
                    'precio_aceptado' => $price,
                    'estado' => 'aceptada',
                ]);
                $total += $qty * $price;
            }
            $wpdb->update($this->table('ordenes_compra'), ['numero' => (string) $order_id, 'total' => round($total, 2)], ['id' => $order_id]);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return $this->get_order($order_id);
    }

    /* ===================== Estado ===================== */

    /**
     * El estado del pedido se calcula desde sus líneas, la cotización y lo recibido. A mano
     * solo se envía, se marca la revisión enviada y se cancela.
     *
     * @param int $order_id
     * @return string
     */
    public function recalc_state($order_id) {
        $order = $this->order_row($order_id);
        if (!$order) {
            return '';
        }
        $estado = (string) $order['estado'];
        if (in_array($estado, ['borrador', 'cancelada'], true)) {
            return $estado;
        }
        $live = array_values(array_filter($this->line_rows($order_id), function ($line) {
            return $line['estado'] !== 'no_comprada';
        }));
        $states = array_column($live, 'estado');
        $received = in_array('cerrada', $states, true) || (bool) array_filter($live, function ($line) {
            return (float) $line['cantidad_recibida'] > self::EPS;
        });

        if ($received) {
            $next = count(array_unique($states)) === 1 && $states[0] === 'cerrada' ? 'recibida' : 'recibida_parcial';
        } elseif ($states && !in_array('pendiente', $states, true)) {
            $next = 'aceptada';
        } elseif (!empty($order['cotizacion_id'])) {
            $next = !empty($order['revision_enviada_en']) ? 'en_revision' : 'en_cotizacion';
        } else {
            $next = 'enviada';
        }
        if ($next !== $estado) {
            $this->touch_order((int) $order['id'], ['estado' => $next]);
        }
        return $next;
    }

    /* ===================== Recepción ===================== */

    /** Facturas (33, 34, 46) y guías de despacho (52): lo mismo que recibe Recepción. */
    const DOC_TYPES = [33, 34, 46, 52];

    /**
     * @return bool
     */
    protected function reception_ready() {
        return $this->table_exists('facturas') && $this->table_exists('recepciones') && $this->table_exists('recepcion_lineas');
    }

    /**
     * @param int $tipo_dte
     * @return string
     */
    protected function doc_label($tipo_dte) {
        $labels = [33 => 'Factura', 34 => 'Factura exenta', 46 => 'Factura de compra', 52 => 'Guía'];
        return $labels[(int) $tipo_dte] ?? 'Documento';
    }

    /**
     * Documentos (factura o guía) que cierran este pedido, con su estado en Recepción.
     *
     * @param int $order_id
     * @return array
     */
    protected function linked_documents($order_id) {
        global $wpdb;
        if (!$this->reception_ready()) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.factura_id, d.origen, f.tipo_dte, f.folio, f.fecha_emision, f.monto_total, r.estado AS recepcion_estado
             FROM {$this->table('orden_compra_documentos')} d
             INNER JOIN {$this->table('facturas')} f ON f.id = d.factura_id
             LEFT JOIN {$this->table('recepciones')} r ON r.factura_id = f.id
             WHERE d.orden_id = %d
             ORDER BY f.fecha_emision ASC, f.id ASC",
            (int) $order_id
        ), ARRAY_A) ?: [];
        $states = ['pendiente' => 'Por recibir', 'parcial' => 'Recibida en parte', 'recibida' => 'Recibida', 'cubierta' => 'Cubierta por otro documento', 'ignorada' => 'Ignorada'];
        $origins = ['referencia' => 'La factura cita el pedido', 'propuesto' => 'Propuesto y confirmado', 'manual' => 'Vinculado a mano'];
        $out = [];
        foreach ($rows as $row) {
            $estado = (string) ($row['recepcion_estado'] ?: 'pendiente');
            $out[] = [
                'id' => (int) $row['factura_id'],
                'label' => $this->doc_label($row['tipo_dte']) . ' N° ' . $row['folio'],
                'fecha' => (string) $row['fecha_emision'],
                'monto_total' => (float) $row['monto_total'],
                'estado' => $estado,
                'estado_label' => $states[$estado] ?? $estado,
                'cerrado' => $estado === 'recibida',
                'origen' => (string) $row['origen'],
                'origen_label' => $origins[$row['origen']] ?? '',
            ];
        }
        return $out;
    }

    /**
     * La factura cita el número del pedido como orden de compra (referencia 801): se vincula sola.
     *
     * @param array $order
     * @return bool Si vinculó algún documento nuevo.
     */
    protected function auto_link_documents(array $order) {
        global $wpdb;
        $numero = ltrim(trim((string) ($order['numero'] ?? '')), '0');
        if ($numero === '' || !$this->reception_ready() || empty($order['proveedor_id'])) {
            return false;
        }
        $queries = [];
        if ($this->table_exists('factura_referencias')) {
            $queries[] = $wpdb->prepare(
                "SELECT f.id FROM {$this->table('facturas')} f
                 INNER JOIN {$this->table('factura_referencias')} fr ON fr.factura_id = f.id AND fr.tipo_doc_ref = 801
                 WHERE f.proveedor_id = %d AND TRIM(LEADING '0' FROM TRIM(fr.folio_ref)) = %s",
                (int) $order['proveedor_id'],
                $numero
            );
        }
        if ($this->table_exists('documentos_escaneados') && $this->table_exists('documento_referencias')) {
            $queries[] = $wpdb->prepare(
                "SELECT f.id FROM {$this->table('facturas')} f
                 INNER JOIN {$this->table('documentos_escaneados')} ds ON ds.factura_id = f.id
                 INNER JOIN {$this->table('documento_referencias')} dr ON dr.documento_id = ds.id AND dr.tipo_doc_ref = 801
                 WHERE f.proveedor_id = %d AND TRIM(LEADING '0' FROM TRIM(dr.folio_ref)) = %s",
                (int) $order['proveedor_id'],
                $numero
            );
        }
        if (!$queries) {
            return false;
        }
        $linked = false;
        foreach (array_unique(array_map('intval', $wpdb->get_col(implode(' UNION ', $queries)) ?: [])) as $factura_id) {
            $linked = $this->insert_document_link((int) $order['id'], $factura_id, 'referencia') || $linked;
        }
        return $linked;
    }

    /**
     * @param int    $order_id
     * @param int    $factura_id
     * @param string $origin
     * @return bool Si el vínculo es nuevo.
     */
    protected function insert_document_link($order_id, $factura_id, $origin) {
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('orden_compra_documentos')} WHERE orden_id = %d AND factura_id = %d",
            (int) $order_id,
            (int) $factura_id
        ));
        if ($exists > 0) {
            return false;
        }
        return (bool) $wpdb->insert($this->table('orden_compra_documentos'), [
            'orden_id' => (int) $order_id,
            'factura_id' => (int) $factura_id,
            'origen' => $origin,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Documentos del proveedor sin pedido, ordenados por cuántos códigos del pedido traen.
     *
     * @param array $order
     * @param array $lines
     * @return array
     */
    protected function document_proposals(array $order, array $lines) {
        global $wpdb;
        if (!$this->reception_ready() || empty($order['proveedor_id']) || !$this->table_exists('factura_items')) {
            return [];
        }
        $since = substr((string) ($order['enviado_en'] ?: $order['created_at']), 0, 10);
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.tipo_dte, f.folio, f.fecha_emision, f.monto_total
             FROM {$this->table('facturas')} f
             WHERE f.proveedor_id = %d AND f.tipo_dte IN (" . implode(',', self::DOC_TYPES) . ")
               AND f.fecha_emision >= %s
               AND f.id NOT IN (SELECT d.factura_id FROM {$this->table('orden_compra_documentos')} d)
             ORDER BY f.fecha_emision DESC, f.id DESC LIMIT 20",
            (int) $order['proveedor_id'],
            $since !== '' ? $since : '2000-01-01'
        ), ARRAY_A) ?: [];
        if (!$docs) {
            return [];
        }
        $codes = [];
        foreach ($lines as $line) {
            $code = Riverso_Purchase_Notice_Service::normalize_code($line['codigo_proveedor'] ?? '');
            if ($code !== '' && $line['estado'] !== 'no_comprada') {
                $codes[$code] = true;
            }
        }
        $ids = array_map('intval', array_column($docs, 'id'));
        $hits = array_fill_keys($ids, 0);
        $item_rows = $wpdb->get_results(
            "SELECT factura_id, codigo_proveedor FROM {$this->table('factura_items')} WHERE factura_id IN (" . implode(',', $ids) . ')',
            ARRAY_A
        ) ?: [];
        foreach ($item_rows as $row) {
            if (isset($codes[Riverso_Purchase_Notice_Service::normalize_code($row['codigo_proveedor'] ?? '')])) {
                $hits[(int) $row['factura_id']]++;
            }
        }
        $out = [];
        foreach ($docs as $doc) {
            $out[] = [
                'id' => (int) $doc['id'],
                'label' => $this->doc_label($doc['tipo_dte']) . ' N° ' . $doc['folio'],
                'fecha' => (string) $doc['fecha_emision'],
                'monto_total' => (float) $doc['monto_total'],
                'calzan' => $hits[(int) $doc['id']],
            ];
        }
        usort($out, function ($a, $b) {
            return $b['calzan'] - $a['calzan'];
        });
        return $out;
    }

    /**
     * Vincula un documento a mano o confirma uno propuesto.
     *
     * @param int    $order_id
     * @param int    $factura_id
     * @param string $origin manual|propuesto
     * @return array Pedido.
     * @throws Exception
     */
    public function link_document($order_id, $factura_id, $origin = 'manual') {
        global $wpdb;
        $order = $this->require_order($order_id);
        if (in_array($order['estado'], ['borrador', 'cancelada'], true)) {
            throw new Exception('Este pedido no espera mercadería.');
        }
        if (!$this->reception_ready()) {
            throw new Exception('Recepción no está disponible.');
        }
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, proveedor_id, tipo_dte FROM {$this->table('facturas')} WHERE id = %d",
            (int) $factura_id
        ), ARRAY_A);
        if (!$doc || !in_array((int) $doc['tipo_dte'], self::DOC_TYPES, true)) {
            throw new Exception('Documento no encontrado.');
        }
        if (!empty($doc['proveedor_id']) && (int) $doc['proveedor_id'] !== (int) $order['proveedor_id']) {
            throw new Exception('Ese documento es de otro proveedor.');
        }
        $this->insert_document_link((int) $order['id'], (int) $doc['id'], $origin === 'propuesto' ? 'propuesto' : 'manual');
        $this->recalc_received((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * @param int $order_id
     * @param int $factura_id
     * @return array Pedido.
     * @throws Exception
     */
    public function unlink_document($order_id, $factura_id) {
        global $wpdb;
        $order = $this->require_order($order_id);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table('orden_compra_documentos')} WHERE orden_id = %d AND factura_id = %d",
            (int) $order['id'],
            (int) $factura_id
        ));
        $this->recalc_received((int) $order['id']);
        return $this->get_order((int) $order['id']);
    }

    /**
     * Recepción recibió, anuló o reclamó en un documento: se actualizan sus pedidos.
     *
     * @param int $factura_id
     */
    public function recalc_for_document($factura_id) {
        global $wpdb;
        if (!$this->ready() || !$this->reception_ready()) {
            return;
        }
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, proveedor_id FROM {$this->table('facturas')} WHERE id = %d",
            (int) $factura_id
        ), ARRAY_A);
        if (!$doc) {
            return;
        }
        // Pedidos abiertos del proveedor: puede que este documento cite a alguno.
        if (!empty($doc['proveedor_id'])) {
            $open = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table('ordenes_compra')}
                 WHERE proveedor_id = %d AND estado IN ('enviada', 'en_cotizacion', 'en_revision', 'aceptada', 'recibida_parcial')",
                (int) $doc['proveedor_id']
            ), ARRAY_A) ?: [];
            foreach ($open as $order) {
                $this->auto_link_documents($order);
            }
        }
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT orden_id FROM {$this->table('orden_compra_documentos')} WHERE factura_id = %d ORDER BY orden_id ASC",
            (int) $factura_id
        )) ?: [];
        foreach ($order_ids as $order_id) {
            $this->recalc_received((int) $order_id);
        }
    }

    /**
     * Lo recibido no se digita en el pedido: se recalcula desde Recepción, por producto y en
     * unidades de stock, contra lo aceptado por su factor. Si un documento cubre dos pedidos
     * con el mismo producto, se completa primero el más antiguo.
     *
     * @param int $order_id
     * @return string Estado del pedido.
     */
    public function recalc_received($order_id) {
        global $wpdb;
        $order = $this->order_row($order_id);
        if (!$order || in_array($order['estado'], ['borrador', 'cancelada'], true) || !$this->reception_ready()) {
            return $order ? (string) $order['estado'] : '';
        }
        $doc_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT factura_id FROM {$this->table('orden_compra_documentos')} WHERE orden_id = %d",
            (int) $order['id']
        )) ?: []);

        $received = [];
        $closed = [];
        if ($doc_ids) {
            $in = implode(',', $doc_ids);
            $rows = $wpdb->get_results(
                "SELECT l.producto_base_id, r.estado, SUM(l.cantidad_recibida - l.cantidad_reclamada) AS neto
                 FROM {$this->table('recepciones')} r
                 INNER JOIN {$this->table('recepcion_lineas')} l ON l.recepcion_id = r.id
                 WHERE r.factura_id IN ({$in})
                 GROUP BY l.producto_base_id, r.estado",
                ARRAY_A
            ) ?: [];
            foreach ($rows as $row) {
                $pb = (int) $row['producto_base_id'];
                $received[$pb] = ($received[$pb] ?? 0.0) + max(0.0, (float) $row['neto']);
                if ($row['estado'] === 'recibida') {
                    $closed[$pb] = true;
                }
            }
            // Un faltante reclamado también dice que ese documento ya no trae más de ese producto.
            if ($this->table_exists('reclamos_proveedor') && $this->table_exists('reclamo_lineas')) {
                $missing = $wpdb->get_col(
                    "SELECT DISTINCT rl.producto_base_id
                     FROM {$this->table('reclamos_proveedor')} rc
                     INNER JOIN {$this->table('reclamo_lineas')} rl ON rl.reclamo_id = rc.id
                     WHERE rc.factura_id IN ({$in}) AND rl.motivo = 'faltante' AND rl.producto_base_id IS NOT NULL"
                ) ?: [];
                foreach ($missing as $pb) {
                    $closed[(int) $pb] = true;
                }
            }
            // Lo que necesitan los pedidos más antiguos que comparten estos documentos va primero.
            $older = $wpdb->get_results($wpdb->prepare(
                "SELECT i.producto_base_id, i.cantidad, i.cantidad_aceptada, i.unidad, i.factor
                 FROM {$this->table('ordenes_compra_items')} i
                 WHERE i.estado <> 'no_comprada' AND i.producto_base_id IS NOT NULL AND i.orden_id < %d
                   AND i.orden_id IN (SELECT d.orden_id FROM {$this->table('orden_compra_documentos')} d WHERE d.factura_id IN ({$in}))",
                (int) $order['id']
            ), ARRAY_A) ?: [];
            foreach ($older as $line) {
                $pb = (int) $line['producto_base_id'];
                if (isset($received[$pb])) {
                    $received[$pb] = max(0.0, $received[$pb] - $this->expected_base($line));
                }
            }
        }

        foreach ($this->line_rows((int) $order['id']) as $line) {
            if ($line['estado'] === 'no_comprada') {
                continue;
            }
            $pb = (int) ($line['producto_base_id'] ?? 0);
            $target = $this->expected_base($line);
            $available = $pb > 0 ? ($received[$pb] ?? 0.0) : 0.0;
            // Dos líneas del mismo producto: la primera toma lo suyo; la última se queda con el resto.
            $got = $this->later_line_exists($order, $line) ? min($available, $target) : $available;
            if ($pb > 0) {
                $received[$pb] = $available - $got;
            }
            $covered = $target > 0 && $got >= $target - self::EPS;
            if ($covered || ($pb > 0 && isset($closed[$pb]))) {
                // Llegó todo, o el documento se cerró sin que llegara: de ese documento no viene más.
                $estado = 'cerrada';
            } elseif ($line['estado'] === 'cerrada') {
                // Recepción anuló: la línea vuelve a esperar.
                $estado = $line['precio_aceptado'] !== null ? 'aceptada' : 'pendiente';
            } else {
                $estado = (string) $line['estado'];
            }
            $wpdb->update($this->table('ordenes_compra_items'), [
                'cantidad_recibida' => round($got / $this->line_factor($line), 3),
                'estado' => $estado,
            ], ['id' => (int) $line['id']]);
        }
        return $this->recalc_state((int) $order['id']);
    }

    /**
     * @param array $order
     * @param array $line
     * @return bool Si el pedido tiene otra línea posterior del mismo producto.
     */
    private function later_line_exists(array $order, array $line) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table('ordenes_compra_items')}
             WHERE orden_id = %d AND producto_base_id = %d AND id > %d AND estado <> 'no_comprada' LIMIT 1",
            (int) $order['id'],
            (int) $line['producto_base_id'],
            (int) $line['id']
        ));
    }

    /**
     * @param array $line
     * @return float Por cuánto se multiplica la cantidad de la línea para llevarla a unidades de stock.
     */
    protected function line_factor(array $line) {
        $factor = $line['factor'] !== null ? (float) $line['factor'] : 0.0;
        return ($line['unidad'] !== 'unidad' && $factor > 1.0001) ? $factor : 1.0;
    }

    /**
     * Lo que se espera de una línea, en unidades de stock: lo aceptado; sin cotización, lo pedido.
     *
     * @param array $line
     * @return float
     */
    protected function expected_base(array $line) {
        $qty = $line['cantidad_aceptada'] !== null ? (float) $line['cantidad_aceptada'] : (float) $line['cantidad'];
        return $qty * $this->line_factor($line);
    }

    /**
     * Zona Recepción de la ficha: pedido / cotizado / aceptado / recibido por línea.
     *
     * @param array $order
     * @param array $lines
     * @return array
     */
    protected function reception_zone(array $order, array $lines) {
        global $wpdb;
        $expects = !in_array($order['estado'], ['borrador', 'cancelada'], true);
        $docs = $expects ? $this->linked_documents((int) $order['id']) : [];
        $all_closed = $docs && !array_filter($docs, function ($doc) {
            return !$doc['cerrado'];
        });

        $quoted = [];
        $item_ids = array_values(array_filter(array_map('intval', array_column($lines, 'cotizacion_item_id'))));
        if ($item_ids && $this->quotes_ready()) {
            $quote_rows = $wpdb->get_results(
                "SELECT id, cantidad, decision_status FROM {$this->table('cotizacion_items')} WHERE id IN (" . implode(',', $item_ids) . ')',
                ARRAY_A
            ) ?: [];
            foreach ($quote_rows as $row) {
                if ($row['decision_status'] !== 'add') {
                    $quoted[(int) $row['id']] = (float) $row['cantidad'];
                }
            }
        }

        $units = Riverso_Purchase_Notice_Service::UNITS;
        $rows = [];
        foreach ($lines as $line) {
            $accepted = $line['cantidad_aceptada'] !== null ? (float) $line['cantidad_aceptada'] : null;
            $ordered = (float) $line['cantidad'];
            $got = (float) $line['cantidad_recibida'];
            $target = $accepted !== null ? $accepted : $ordered;
            $short_reception = $line['estado'] === 'cerrada' ? max(0.0, $target - $got) : 0.0;
            // Se aceptó menos de lo pedido, en la misma unidad en que se pidió.
            $short_quote = ($accepted !== null && $line['estado'] !== 'no_comprada' && ($line['origen'] ?? '') !== 'cotizacion')
                ? max(0.0, $ordered - $accepted) : 0.0;
            $rest = round($short_reception + $short_quote, 3);
            $rows[] = [
                'linea_id' => (int) $line['id'],
                'titulo' => (string) (($line['nombre_canonico'] ?? '') ?: ($line['descripcion'] ?? '') ?: 'Sin identificar'),
                'codigo' => (string) ($line['codigo_proveedor'] ?? ''),
                'unidad_label' => $units[$line['unidad']] ?? (string) $line['unidad'],
                'pedido' => ($line['origen'] ?? '') === 'cotizacion' ? null : $ordered,
                'cotizado' => $quoted[(int) ($line['cotizacion_item_id'] ?? 0)] ?? null,
                'aceptado' => $accepted,
                'recibido' => $got,
                'estado' => (string) $line['estado'],
                'estado_label' => $line['estado'] === 'cerrada' && $short_reception > self::EPS
                    ? 'Cerrada con faltante'
                    : (self::LINE_STATES[$line['estado']] ?? (string) $line['estado']),
                'resto' => $rest > self::EPS ? $rest : 0.0,
                'resto_pedido' => $rest > self::EPS && $this->rest_line_exists((int) $line['id']),
            ];
        }
        return [
            'espera' => $expects,
            'documentos' => $docs,
            'propuestos' => ($expects && !in_array($order['estado'], ['recibida'], true)) ? $this->document_proposals($order, $lines) : [],
            // Sin documento no hay nada que recibir: Recepción trabaja por factura o guía.
            'esperando_documento' => $expects && !$docs,
            'documentos_cerrados' => $all_closed,
            'filas' => $rows,
        ];
    }

    /**
     * @param int $item_id
     * @return bool Si ya se pidió el resto de esta línea en un pedido que sigue en pie.
     */
    protected function rest_line_exists($item_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table('ordenes_compra_items')} i
             INNER JOIN {$this->table('ordenes_compra')} o ON o.id = i.orden_id
             WHERE i.origen = 'resto' AND i.origen_item_id = %d AND o.estado <> 'cancelada' AND i.estado <> 'no_comprada' LIMIT 1",
            (int) $item_id
        ));
    }

    /**
     * Parte sin cubrir de una línea (se aceptó menos, o Recepción cerró con faltante): deja una
     * línea nueva en el borrador del proveedor que se elija, apuntando a la original.
     *
     * @param int $item_id
     * @param int $supplier_id
     * @param int $pp_id
     * @return array Pedido donde quedó el resto.
     * @throws Exception
     */
    public function request_rest($item_id, $supplier_id, $pp_id = 0) {
        $line = $this->line_row($item_id);
        if (!$line) {
            throw new Exception('Línea no encontrada.');
        }
        $order = $this->require_order((int) $line['orden_id']);
        $zone = $this->reception_zone($order, $this->line_rows((int) $order['id']));
        $rest = 0.0;
        foreach ($zone['filas'] as $row) {
            if ($row['linea_id'] === (int) $line['id']) {
                $rest = (float) $row['resto'];
            }
        }
        if ($rest <= self::EPS) {
            throw new Exception('No queda resto por pedir en esa línea.');
        }
        if ($this->rest_line_exists((int) $line['id'])) {
            throw new Exception('El resto de esa línea ya está pedido.');
        }
        $pb = (int) ($line['producto_base_id'] ?? 0);
        $scope = $pb > 0 ? $this->notices()->need_scope($pb) : null;
        $link = $this->supplier_link((int) $supplier_id, (int) $pp_id, $pb, $scope);
        if ($link && $link['producto_base_id'] > 0) {
            $pb = $link['producto_base_id'];
        }
        $draft = $this->draft_for($supplier_id);
        $this->insert_line((int) $draft['id'], [
            'producto_base_id' => $pb ?: null,
            'producto_proveedor_id' => $link ? $link['pp_id'] : null,
            'descripcion' => $this->clip($this->line_title($line), 255),
            'cantidad' => $rest,
            'unidad' => (string) $line['unidad'],
            'codigo_proveedor' => $link ? $link['codigo'] : null,
            'factor' => $link ? $link['factor'] : null,
            'origen' => 'resto',
            'origen_item_id' => (int) $line['id'],
            'emparejamiento_id' => $scope && $scope['emparejamiento_id'] ? $scope['emparejamiento_id'] : null,
            'grupo_id' => $scope && $scope['grupo_id'] ? $scope['grupo_id'] : null,
        ]);
        $this->touch_order((int) $draft['id']);
        return $this->get_order((int) $draft['id']);
    }

    /* ===================== Alertas de bajo inventario ===================== */

    /**
     * Alertas que ya calcula Bodega, llevadas a la bandeja como sugerencia. Una necesidad
     * aparece en un solo nivel: emparejamiento, si no familia, si no producto.
     *
     * @return array [{clave, scope, producto_base_id, titulo, sku, alerta}]
     */
    protected function alerts() {
        $out = [];
        foreach ($this->pairing_alerts() as $alert) {
            $out[$alert['clave']] = $alert;
        }
        foreach ($this->product_alerts() as $alert) {
            if (!isset($out[$alert['clave']])) {
                $out[$alert['clave']] = $alert;
            }
        }
        return array_values($out);
    }

    /**
     * @return array
     */
    protected function pairing_alerts() {
        if (!class_exists('Riverso_Emparejamiento_Module')) {
            return [];
        }
        $module = Riverso_Emparejamiento_Module::get_instance();
        $out = [];
        foreach ($module->list_stock_alerts() as $row) {
            if (empty($row['alerta']) && empty($row['critico'])) {
                continue;
            }
            $members = array_map('intval', array_column($row['detalle'] ?? [], 'producto_base_id'));
            if (!$members) {
                continue;
            }
            $scope = $this->notices()->need_scope($members[0]);
            if ((int) $scope['emparejamiento_id'] !== (int) $row['id']) {
                continue;
            }
            $confidence = 'confiable';
            foreach ($members as $member) {
                $member_confidence = $this->stock_confidence($member);
                if ($member_confidence !== 'confiable') {
                    $confidence = $member_confidence;
                    break;
                }
            }
            $first = $row['detalle'][0];
            $out[] = [
                'clave' => $scope['clave'],
                'scope' => $scope,
                'producto_base_id' => $members[0],
                'titulo' => (string) $row['nombre'],
                'sku' => (string) ($first['sku'] ?? ''),
                'alerta' => $this->alert_view('emparejamiento', (float) $row['stock_unidades'], $row['stock_minimo'], !empty($row['critico']), $confidence),
            ];
        }
        return $out;
    }

    /**
     * Productos bajo su mínimo. La familia no tiene mínimo propio: se usa el de su producto
     * unitario contra el stock de familia en unidades.
     *
     * @return array
     */
    protected function product_alerts() {
        global $wpdb;
        if (!$this->table_exists('producto_stock_config') || !$this->table_exists('producto_ubicacion')) {
            return [];
        }
        $stock_sql = "(SELECT COALESCE(SUM(pu.cantidad), 0) FROM {$this->table('producto_ubicacion')} pu WHERE pu.product_id = pb.id)";
        $rows = $wpdb->get_results(
            "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, cfg.stock_minimo, cfg.stock_critico, {$stock_sql} AS stock_total
             FROM {$this->table('producto_stock_config')} cfg
             INNER JOIN {$this->table('producto_base')} pb ON pb.id = cfg.producto_base_id
             WHERE cfg.stock_minimo IS NOT NULL AND pb.deleted_at IS NULL AND {$stock_sql} <= cfg.stock_minimo
             ORDER BY pb.nombre_canonico ASC LIMIT 300",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $pb = (int) $row['id'];
            $scope = $this->notices()->need_scope($pb);
            if ($scope['emparejamiento_id'] > 0) {
                continue;
            }
            $stock = (float) $row['stock_total'];
            $level = 'producto';
            if ($scope['grupo_id'] > 0) {
                $family = $this->family_stock((int) $scope['grupo_id'], $pb);
                if ($family === null) {
                    continue;
                }
                $stock = $family;
                $level = 'familia';
                if ($stock > (float) $row['stock_minimo']) {
                    continue;
                }
            }
            $critical = $row['stock_critico'] !== null && $stock <= (float) $row['stock_critico'];
            $out[] = [
                'clave' => $scope['clave'],
                'scope' => $scope,
                'producto_base_id' => $pb,
                'titulo' => (string) $row['nombre_canonico'],
                'sku' => (string) $row['canonical_sku'],
                'alerta' => $this->alert_view($level, $stock, $row['stock_minimo'], $critical, $this->stock_confidence($pb)),
            ];
        }
        return $out;
    }

    /**
     * @param string     $level
     * @param float      $stock
     * @param mixed      $minimum
     * @param bool       $critical
     * @param string     $confidence confiable|poco_confiable|dudoso|desconocido
     * @return array
     */
    private function alert_view($level, $stock, $minimum, $critical, $confidence) {
        return [
            'nivel' => $level,
            'stock' => $stock,
            'minimo' => $minimum !== null ? (float) $minimum : null,
            'critico' => (bool) $critical,
            'confianza' => $confidence,
            // Sin un conteo en el que se confíe, la alerta no propone cantidad (D11).
            'dudosa' => $confidence !== 'confiable',
        ];
    }

    /**
     * Stock de la familia en unidades, visto desde su producto unitario.
     *
     * @param int $grupo_id
     * @param int $pb
     * @return float|null Null si el producto no es el unitario: su mínimo no manda.
     */
    protected function family_stock($grupo_id, $pb) {
        global $wpdb;
        if ($this->has_column('equivalence_groups', 'unit_producto_base_id')) {
            $unit = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT unit_producto_base_id FROM {$this->table('equivalence_groups')} WHERE id = %d",
                (int) $grupo_id
            ));
            if ($unit > 0 && $unit !== (int) $pb) {
                return null;
            }
        }
        if (!class_exists('Riverso_Family_Module')) {
            return null;
        }
        $family = Riverso_Family_Module::get_instance()->compute_family_stock((int) $grupo_id);
        return isset($family['stock_unidades']) ? (float) $family['stock_unidades'] : null;
    }

    /**
     * Confianza del stock según el estado de stock de Bodega.
     *
     * @param int $pb
     * @return string
     */
    protected function stock_confidence($pb) {
        if (!class_exists('Riverso_Inventory_Count_Module')) {
            return 'desconocido';
        }
        $status = Riverso_Inventory_Count_Module::get_instance()->get_stock_status_for_product((int) $pb);
        return is_array($status) && !empty($status['estado_confianza']) ? (string) $status['estado_confianza'] : 'desconocido';
    }

    /**
     * Última cantidad comprada a un proveedor, en su unidad de compra: lo que propone una
     * alerta. No hay stock objetivo.
     *
     * @param int[] $product_ids Productos que comparten la necesidad.
     * @param int   $supplier_id
     * @return array{cantidad: float, unidad: string}|null
     */
    protected function last_purchase(array $product_ids, $supplier_id) {
        global $wpdb;
        $product_ids = array_values(array_filter(array_map('intval', $product_ids)));
        if (!$product_ids || $supplier_id <= 0) {
            return null;
        }
        $in = implode(',', $product_ids);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(i.cantidad_aceptada, i.cantidad) AS cantidad, i.unidad
             FROM {$this->table('ordenes_compra_items')} i
             INNER JOIN {$this->table('ordenes_compra')} o ON o.id = i.orden_id
             WHERE o.proveedor_id = %d AND o.estado IN ('aceptada', 'recibida_parcial', 'recibida')
               AND i.estado <> 'no_comprada' AND i.producto_base_id IN ({$in})
             ORDER BY i.id DESC LIMIT 1",
            (int) $supplier_id
        ), ARRAY_A);
        if ($row && (float) $row['cantidad'] > 0) {
            return ['cantidad' => (float) $row['cantidad'], 'unidad' => (string) $row['unidad']];
        }
        if (!$this->table_exists('facturas') || !$this->table_exists('factura_items')) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT fi.cantidad, fi.unidad, pp.unidad_compra
             FROM {$this->table('factura_items')} fi
             INNER JOIN {$this->table('facturas')} f ON f.id = fi.factura_id
             INNER JOIN {$this->table('producto_proveedor')} pp
                     ON pp.proveedor_id = f.proveedor_id AND pp.codigo_proveedor = fi.codigo_proveedor AND pp.activo = 1
             WHERE f.proveedor_id = %d AND f.tipo_dte IN (33, 34, 46) AND pp.producto_base_id IN ({$in})
             ORDER BY f.fecha_emision DESC, fi.id DESC LIMIT 1",
            (int) $supplier_id
        ), ARRAY_A);
        if (!$row || (float) $row['cantidad'] <= 0) {
            return null;
        }
        $unit = self::unit_key($row['unidad']) ?: (self::unit_key($row['unidad_compra']) ?: 'unidad');
        return ['cantidad' => round((float) $row['cantidad'], 3), 'unidad' => $unit];
    }
}
