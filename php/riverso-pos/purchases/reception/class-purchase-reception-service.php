<?php
/**
 * Recepción de compras (fase 72).
 *
 * Flujo:
 * 1. Recibir: lo recibido de una factura o guía entra a la zona "Recepción" (se asume que llegó todo;
 *    se puede recibir por partes y cerrar con faltante).
 * 2. Ordenar: traslado de Recepción a su lugar. Si al ordenar falta algo o viene en mal estado,
 *    se reclama: baja del stock y queda una orden de reclamo al proveedor (espera nota de crédito).
 * 3. Guía y factura del mismo despacho: lo recibido con una cubre a la otra (no se suma dos veces).
 *
 * El estado de recepción es independiente del flujo de costos de la factura (modo_ingreso).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Reception_Service {

    const LOCATION_CODE = 'RECEPCION';
    const REF_TIPO = 'recepcion_compra';
    const CLAIM_REF = 'reclamo_proveedor';
    const EPS = 0.0001;

    /** Facturas (33, 34, 46) y guías de despacho (52). */
    const DOC_TYPES = [33, 34, 46, 52];
    const SKIP_SUBTYPES = ['envio', 'gastos', 'nota_credito'];

    const FILTERS = ['pendientes', 'parciales', 'por_ordenar', 'recibidas', 'ignoradas', 'todas'];

    const MOTIVOS = [
        'faltante' => 'Faltante',
        'mal_estado' => 'Mal estado',
        'producto_distinto' => 'Producto distinto',
    ];

    const CLAIM_STATES = [
        'por_enviar' => 'Por enviar',
        'enviado' => 'Enviado',
        'resuelto' => 'Resuelto',
        'descartado' => 'Descartado',
    ];

    private static $instance = null;

    /** @var bool|null */
    private $ready = null;

    /** @var int|null */
    private $location_id = null;

    /** @var array<int, array{int, int}>|null Pares [factura_id, guia_id] del mismo despacho. */
    private $pairs = null;

    /** @var array<string, bool> */
    private $columns = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ===================== Listado ===================== */

    /**
     * Documentos de compra con su estado de recepción.
     *
     * @param array{filtro?: string, buscar?: string, pagina?: int, por_pagina?: int} $args
     * @return array{items: array, total: int, counts: array<string, int>}
     */
    public function list_documents(array $args) {
        global $wpdb;
        if (!$this->ready()) {
            return ['items' => [], 'total' => 0, 'counts' => []];
        }
        $this->sync_coverage();

        $filter = in_array($args['filtro'] ?? '', self::FILTERS, true) ? $args['filtro'] : 'pendientes';
        $search = trim((string) ($args['buscar'] ?? ''));
        $page = max(1, (int) ($args['pagina'] ?? 1));
        $per_page = min(100, max(10, (int) ($args['por_pagina'] ?? 25)));

        list($base_where, $base_params) = $this->base_where($search);

        $counts = [];
        foreach (self::FILTERS as $key) {
            $sql = "SELECT COUNT(*) FROM {$this->table('facturas')} f
                    LEFT JOIN {$this->table('recepciones')} r ON r.factura_id = f.id
                    LEFT JOIN {$this->table('proveedores')} p ON p.id = f.proveedor_id
                    WHERE {$base_where} AND " . $this->filter_sql($key);
            $counts[$key] = (int) $wpdb->get_var($base_params ? $wpdb->prepare($sql, ...$base_params) : $sql);
        }

        $sql = "SELECT f.id, f.tipo_dte, f.folio, f.fecha_emision, f.rut_emisor, f.razon_social_emisor,
                       f.proveedor_id, f.monto_total, f.modo_ingreso,
                       p.nombre AS proveedor_nombre,
                       r.id AS recepcion_id, r.estado AS recepcion_estado, r.cubierta_por, r.motivo,
                       r.completada_en, r.updated_at AS recepcion_actualizada,
                       c.tipo_dte AS cubierta_tipo, c.folio AS cubierta_folio,
                       (SELECT COUNT(*) FROM {$this->table('factura_items')} i
                         WHERE i.factura_id = f.id AND COALESCE(i.item_tipo, 'producto') = 'producto') AS items_count,
                       (SELECT COALESCE(SUM(GREATEST(l.cantidad_recibida - l.cantidad_ordenada - l.cantidad_reclamada, 0)), 0)
                          FROM {$this->table('recepcion_lineas')} l WHERE l.recepcion_id = r.id) AS por_ordenar
                FROM {$this->table('facturas')} f
                LEFT JOIN {$this->table('recepciones')} r ON r.factura_id = f.id
                LEFT JOIN {$this->table('facturas')} c ON c.id = r.cubierta_por
                LEFT JOIN {$this->table('proveedores')} p ON p.id = f.proveedor_id
                WHERE {$base_where} AND " . $this->filter_sql($filter) . "
                ORDER BY f.fecha_emision DESC, f.id DESC
                LIMIT %d OFFSET %d";
        $params = array_merge($base_params, [$per_page, ($page - 1) * $per_page]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->present_list_row($row);
        }

        return [
            'items' => $items,
            'total' => $counts[$filter] ?? 0,
            'counts' => $counts,
            'claims_open' => $this->count_open_claims(),
        ];
    }

    /**
     * @param string $search
     * @return array{0: string, 1: array}
     */
    private function base_where($search) {
        global $wpdb;
        $types = implode(',', array_map('intval', self::DOC_TYPES));
        $skip = "'" . implode("','", array_map('esc_sql', self::SKIP_SUBTYPES)) . "'";
        $where = "f.tipo_dte IN ({$types})
                  AND COALESCE(f.documento_subtipo, '') NOT IN ({$skip})
                  AND EXISTS (SELECT 1 FROM {$this->table('factura_items')} ix
                              WHERE ix.factura_id = f.id AND COALESCE(ix.item_tipo, 'producto') = 'producto')";
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (f.folio LIKE %s OR f.razon_social_emisor LIKE %s OR p.nombre LIKE %s OR f.rut_emisor LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        return [$where, $params];
    }

    /**
     * @param string $filter
     * @return string
     */
    private function filter_sql($filter) {
        $pending_order = "EXISTS (SELECT 1 FROM {$this->table('recepcion_lineas')} lx
                          WHERE lx.recepcion_id = r.id
                            AND lx.cantidad_recibida - lx.cantidad_ordenada - lx.cantidad_reclamada > " . self::EPS . ')';
        switch ($filter) {
            case 'pendientes':
                return 'r.id IS NULL';
            case 'parciales':
                return "r.estado = 'parcial'";
            case 'por_ordenar':
                return "r.estado IN ('parcial', 'recibida') AND {$pending_order}";
            case 'recibidas':
                return "r.estado IN ('recibida', 'cubierta')";
            case 'ignoradas':
                return "r.estado = 'ignorada'";
            default:
                return '1=1';
        }
    }

    /**
     * @param array $row
     * @return array
     */
    private function present_list_row(array $row) {
        $estado = $row['recepcion_estado'] ?: 'pendiente';
        return [
            'id' => (int) $row['id'],
            'tipo_dte' => (int) $row['tipo_dte'],
            'tipo_label' => self::doc_label((int) $row['tipo_dte']),
            'folio' => (string) $row['folio'],
            'fecha' => (string) $row['fecha_emision'],
            'proveedor' => (string) ($row['proveedor_nombre'] ?: $row['razon_social_emisor']),
            'rut' => (string) $row['rut_emisor'],
            'monto_total' => (float) $row['monto_total'],
            'items' => (int) $row['items_count'],
            'estado' => $estado,
            'estado_label' => self::state_label($estado),
            'motivo' => (string) ($row['motivo'] ?? ''),
            'cubierta_por' => $row['cubierta_por'] ? [
                'id' => (int) $row['cubierta_por'],
                'label' => self::doc_label((int) $row['cubierta_tipo']) . ' N° ' . $row['cubierta_folio'],
            ] : null,
            'por_ordenar' => round((float) $row['por_ordenar'], 4),
            'completada_en' => $row['completada_en'],
        ];
    }

    /* ===================== Documento ===================== */

    /**
     * Documento con sus líneas esperadas, lo recibido y lo pendiente de ordenar.
     *
     * @param int $factura_id
     * @return array|null
     */
    public function get_document($factura_id) {
        global $wpdb;
        if (!$this->ready()) {
            return null;
        }
        $factura = $this->factura($factura_id);
        if (!$factura) {
            return null;
        }
        $this->sync_coverage();
        $rec = $this->reception_for($factura_id);

        $items = $this->product_items($factura_id);
        $rx_lines = $rec ? $this->reception_lines((int) $rec['id']) : [];
        $by_item = [];
        $extras = [];
        foreach ($rx_lines as $rl) {
            if (!empty($rl['factura_item_id'])) {
                $by_item[(int) $rl['factura_item_id']] = $rl;
            } else {
                $extras[] = $rl;
            }
        }

        $lines = [];
        foreach ($items as $item) {
            $rl = $by_item[(int) $item['id']] ?? null;
            if ($rl) {
                $pb = (int) $rl['producto_base_id'];
                $factor = (float) $rl['factor'];
                $expected = (float) $rl['cantidad_esperada'];
            } else {
                $resolved = $this->resolve_item($factura, $item);
                $pb = $resolved['producto_base_id'];
                $factor = $resolved['factor'];
                $expected = round((float) $item['cantidad'] * $factor, 4);
            }
            $lines[] = $this->present_line($item, $rl, $pb, $factor, $expected);
        }
        foreach ($extras as $rl) {
            $lines[] = $this->present_line(null, $rl, (int) $rl['producto_base_id'], 1.0, 0.0);
        }
        $this->hydrate_products($lines);

        $related = [];
        foreach ($this->related_ids($factura_id) as $other_id) {
            $other = $this->factura($other_id);
            if (!$other) {
                continue;
            }
            $other_rec = $this->reception_for($other_id);
            $related[] = [
                'id' => $other_id,
                'label' => self::doc_label((int) $other['tipo_dte']) . ' N° ' . $other['folio'],
                'estado' => $other_rec ? $other_rec['estado'] : 'pendiente',
                'estado_label' => self::state_label($other_rec ? $other_rec['estado'] : 'pendiente'),
            ];
        }

        $estado = $rec ? $rec['estado'] : 'pendiente';
        $cubierta = null;
        if ($rec && !empty($rec['cubierta_por'])) {
            $other = $this->factura((int) $rec['cubierta_por']);
            if ($other) {
                $cubierta = [
                    'id' => (int) $other['id'],
                    'label' => self::doc_label((int) $other['tipo_dte']) . ' N° ' . $other['folio'],
                ];
            }
        }

        return [
            'id' => (int) $factura['id'],
            'tipo_dte' => (int) $factura['tipo_dte'],
            'tipo_label' => self::doc_label((int) $factura['tipo_dte']),
            'folio' => (string) $factura['folio'],
            'fecha' => (string) $factura['fecha_emision'],
            'proveedor' => (string) ($factura['proveedor_nombre'] ?: $factura['razon_social_emisor']),
            'proveedor_id' => (int) $factura['proveedor_id'],
            'rut' => (string) $factura['rut_emisor'],
            'monto_total' => (float) $factura['monto_total'],
            'recepcion_id' => $rec ? (int) $rec['id'] : 0,
            'estado' => $estado,
            'estado_label' => self::state_label($estado),
            'motivo' => $rec ? (string) ($rec['motivo'] ?? '') : '',
            'cubierta_por' => $cubierta,
            'completada_en' => $rec ? $rec['completada_en'] : null,
            'can_receive' => in_array($estado, ['pendiente', 'parcial'], true),
            'can_cancel' => $rec && $this->can_cancel($rec),
            'lines' => $lines,
            'related' => $related,
            'claims' => $this->list_claims(['factura_id' => $factura_id, 'estado' => 'todos']),
        ];
    }

    /**
     * @param array|null $item Fila de factura_items (null = producto extra).
     * @param array|null $rl   Fila de recepcion_lineas.
     * @param int        $pb
     * @param float      $factor
     * @param float      $expected
     * @return array
     */
    private function present_line($item, $rl, $pb, $factor, $expected) {
        $received = $rl ? (float) $rl['cantidad_recibida'] : 0.0;
        $ordered = $rl ? (float) $rl['cantidad_ordenada'] : 0.0;
        $claimed = $rl ? (float) $rl['cantidad_reclamada'] : 0.0;
        return [
            'key' => $item ? 'i' . (int) $item['id'] : 'l' . (int) $rl['id'],
            'factura_item_id' => $item ? (int) $item['id'] : 0,
            'linea_id' => $rl ? (int) $rl['id'] : 0,
            'numero_linea' => $item ? (int) $item['numero_linea'] : 0,
            'codigo_proveedor' => $item ? (string) $item['codigo_proveedor'] : (string) ($rl['codigo_proveedor'] ?? ''),
            'nombre_doc' => $item ? (string) $item['nombre'] : '',
            'cantidad_doc' => $item ? round((float) $item['cantidad'], 4) : 0,
            'unidad_doc' => $item ? (string) $item['unidad'] : '',
            'factor' => round($factor, 4),
            'producto_base_id' => $pb,
            'asignado_manual' => $rl ? !empty($rl['asignado_manual']) : false,
            'extra' => $item === null,
            'esperada' => round($expected, 4),
            'recibida' => round($received, 4),
            'ordenada' => round($ordered, 4),
            'reclamada' => round($claimed, 4),
            'en_recepcion' => round(max(0, $received - $ordered - $claimed), 4),
            'por_recibir' => round(max(0, $expected - $received), 4),
        ];
    }

    /**
     * Agrega nombre, SKU, códigos y lugar sugerido para ordenar.
     *
     * @param array $lines
     */
    private function hydrate_products(array &$lines) {
        global $wpdb;
        $ids = [];
        foreach ($lines as $line) {
            if ($line['producto_base_id'] > 0) {
                $ids[$line['producto_base_id']] = true;
            }
        }
        $products = [];
        $suggest = [];
        $zone = [];
        if ($ids) {
            $list = implode(',', array_map('intval', array_keys($ids)));
            foreach ($wpdb->get_results(
                "SELECT id, nombre_canonico, canonical_sku FROM {$this->table('producto_base')} WHERE id IN ({$list})",
                ARRAY_A
            ) ?: [] as $row) {
                $products[(int) $row['id']] = $row;
            }
            $suggest = $this->suggested_locations(array_keys($ids));
            $loc = $this->location_id();
            if ($loc > 0) {
                foreach ($wpdb->get_results($wpdb->prepare(
                    "SELECT product_id, cantidad FROM {$this->table('producto_ubicacion')}
                     WHERE ubicacion_id = %d AND product_id IN ({$list})",
                    $loc
                ), ARRAY_A) ?: [] as $row) {
                    $zone[(int) $row['product_id']] = (float) $row['cantidad'];
                }
            }
        }
        foreach ($lines as &$line) {
            $pb = $line['producto_base_id'];
            $product = $products[$pb] ?? null;
            $line['producto'] = $product ? (string) $product['nombre_canonico'] : '';
            $line['sku'] = $product ? (string) $product['canonical_sku'] : '';
            $line['sugerencia'] = $suggest[$pb] ?? null;
            $line['saldo_zona'] = isset($zone[$pb]) ? round($zone[$pb], 4) : 0;
        }
        unset($line);
    }

    /**
     * Lugar sugerido para ordenar: preferido; si no, donde más hay (sin contar Recepción ni virtuales).
     *
     * @param int[] $pb_ids
     * @return array<int, array{id: int, label: string}>
     */
    private function suggested_locations(array $pb_ids) {
        global $wpdb;
        $out = [];
        if (!$pb_ids) {
            return $out;
        }
        $list = implode(',', array_map('intval', $pb_ids));
        if ($this->table_exists('producto_ubicacion_preferida')) {
            foreach ($wpdb->get_results(
                "SELECT pp.producto_base_id, u.id, u.codigo, u.nombre
                 FROM {$this->table('producto_ubicacion_preferida')} pp
                 INNER JOIN {$this->table('ubicaciones')} u ON u.id = pp.ubicacion_id AND u.activo = 1
                 WHERE pp.producto_base_id IN ({$list})
                 ORDER BY pp.es_preferido DESC, pp.prioridad ASC, pp.id ASC",
                ARRAY_A
            ) ?: [] as $row) {
                $pb = (int) $row['producto_base_id'];
                if (!isset($out[$pb])) {
                    $out[$pb] = ['id' => (int) $row['id'], 'label' => self::location_label($row)];
                }
            }
        }
        foreach ($wpdb->get_results(
            "SELECT pu.product_id, u.id, u.codigo, u.nombre
             FROM {$this->table('producto_ubicacion')} pu
             INNER JOIN {$this->table('ubicaciones')} u ON u.id = pu.ubicacion_id AND u.activo = 1
             WHERE pu.product_id IN ({$list}) AND pu.cantidad > 0
               AND COALESCE(u.tipo, '') NOT IN ('virtual', 'recepcion')
             ORDER BY pu.cantidad DESC",
            ARRAY_A
        ) ?: [] as $row) {
            $pb = (int) $row['product_id'];
            if (!isset($out[$pb])) {
                $out[$pb] = ['id' => (int) $row['id'], 'label' => self::location_label($row)];
            }
        }
        return $out;
    }

    /* ===================== Recibir ===================== */

    /**
     * Registra lo recibido: entra a la zona Recepción.
     *
     * @param int   $factura_id
     * @param array $input  [{factura_item_id?, linea_id?, producto_base_id?, cantidad}] en unidades de stock.
     * @param bool  $close  Cerrar aunque falte (lo no recibido queda como reclamo por faltante).
     * @return array Documento actualizado.
     * @throws Exception
     */
    public function receive($factura_id, array $input, $close = false) {
        global $wpdb;
        if (!$this->ready()) {
            throw new Exception('Recepción no disponible.');
        }
        $factura = $this->factura($factura_id);
        if (!$factura) {
            throw new Exception('Documento no encontrado.');
        }
        $this->sync_coverage();
        $rec = $this->reception_for($factura_id);
        if ($rec && !in_array($rec['estado'], ['parcial'], true)) {
            throw new Exception('Este documento ya no admite recepción (' . self::state_label($rec['estado']) . ').');
        }
        $location = $this->location_id();
        if ($location <= 0) {
            throw new Exception('No existe la ubicación Recepción.');
        }

        $items = [];
        foreach ($this->product_items($factura_id) as $item) {
            $items[(int) $item['id']] = $item;
        }

        $entries = [];
        foreach ($input as $row) {
            $qty = round((float) ($row['cantidad'] ?? 0), 4);
            if ($qty <= self::EPS) {
                continue;
            }
            $entries[] = [
                'factura_item_id' => (int) ($row['factura_item_id'] ?? 0),
                'linea_id' => (int) ($row['linea_id'] ?? 0),
                'producto_base_id' => (int) ($row['producto_base_id'] ?? 0),
                'cantidad' => $qty,
            ];
        }
        if (!$entries && !$close) {
            throw new Exception('No hay cantidades para recibir.');
        }

        $now = current_time('mysql');
        $doc_label = self::doc_label((int) $factura['tipo_dte']) . ' N° ' . $factura['folio'];

        $wpdb->query('START TRANSACTION');
        try {
            if (!$rec) {
                $wpdb->insert($this->table('recepciones'), [
                    'factura_id' => (int) $factura_id,
                    'proveedor_id' => (int) $factura['proveedor_id'] ?: null,
                    'estado' => 'parcial',
                    'ubicacion_id' => $location,
                    'created_by' => get_current_user_id() ?: null,
                    'created_at' => $now,
                ]);
                $rec_id = (int) $wpdb->insert_id;
                if ($rec_id <= 0) {
                    throw new Exception('No se pudo crear la recepción.');
                }
                $this->touch_factura($factura_id, 'reception_started_at', $now);
            } else {
                $rec_id = (int) $rec['id'];
            }

            foreach ($entries as $entry) {
                $line = $this->ensure_line($rec_id, $factura, $items, $entry);
                $movement = Riverso_Movement::create('recepcion', (int) $line['producto_base_id'], $entry['cantidad'], [
                    'ubicacion_destino' => $location,
                    'referencia_tipo' => self::REF_TIPO,
                    'referencia_id' => $rec_id,
                    'notas' => 'Recepción ' . $doc_label,
                ]);
                if (!$movement) {
                    throw new Exception('Falló la entrada del producto #' . (int) $line['producto_base_id'] . '.');
                }
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('recepcion_lineas')} SET cantidad_recibida = cantidad_recibida + %f WHERE id = %d",
                    $entry['cantidad'],
                    (int) $line['id']
                ));
            }

            // ¿Completa? Cada ítem de producto recibido por completo (sin vincular = no recibido).
            $lines_by_item = [];
            foreach ($this->reception_lines($rec_id) as $rl) {
                if (!empty($rl['factura_item_id'])) {
                    $lines_by_item[(int) $rl['factura_item_id']] = $rl;
                }
            }
            $missing = [];
            foreach ($items as $item_id => $item) {
                $rl = $lines_by_item[$item_id] ?? null;
                if ($rl) {
                    $left = (float) $rl['cantidad_esperada'] - (float) $rl['cantidad_recibida'];
                    $pb = (int) $rl['producto_base_id'];
                    $factor = (float) $rl['factor'];
                } else {
                    $resolved = $this->resolve_item($factura, $item);
                    $pb = $resolved['producto_base_id'];
                    $factor = $resolved['factor'];
                    $left = (float) $item['cantidad'] * $factor;
                }
                if ($left > self::EPS) {
                    $missing[] = ['item' => $item, 'linea' => $rl, 'producto_base_id' => $pb, 'factor' => $factor, 'cantidad' => round($left, 4)];
                }
            }

            $complete = !$missing;
            if ($close && $missing) {
                $claim_id = $this->open_claim($factura, $rec_id);
                foreach ($missing as $miss) {
                    $this->add_claim_line($claim_id, [
                        'recepcion_linea_id' => $miss['linea'] ? (int) $miss['linea']['id'] : null,
                        'factura_item_id' => (int) $miss['item']['id'],
                        'producto_base_id' => $miss['producto_base_id'] ?: null,
                        'nombre' => (string) $miss['item']['nombre'],
                        'motivo' => 'faltante',
                        'cantidad' => $miss['cantidad'],
                        'costo_unitario' => $this->unit_cost($miss['item'], $miss['factor']),
                        'movimiento_id' => null,
                        'notas' => 'No llegó (recepción cerrada con faltante).',
                    ]);
                }
                $complete = true;
            }

            $update = ['estado' => $complete ? 'recibida' : 'parcial'];
            if ($complete) {
                $update['completada_en'] = $now;
            }
            $wpdb->update($this->table('recepciones'), $update, ['id' => $rec_id]);

            if ($complete) {
                $this->touch_factura($factura_id, 'reception_completed_at', $now, true);
                // Tareas de recepción antiguas de este documento quedan cumplidas.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('tareas')} SET estado = 'completada', completado_en = %s
                     WHERE tipo = 'recepcion' AND referencia_tipo = 'factura' AND referencia_id = %d
                       AND estado NOT IN ('completada', 'cancelada')",
                    $now,
                    (int) $factura_id
                ));
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->pairs = null;
        $this->sync_coverage();
        return $this->get_document($factura_id);
    }

    /**
     * Línea de recepción para una entrada (la crea si no existe).
     *
     * @param int   $rec_id
     * @param array $factura
     * @param array $items  factura_items por id.
     * @param array $entry
     * @return array
     * @throws Exception
     */
    private function ensure_line($rec_id, array $factura, array $items, array $entry) {
        global $wpdb;
        if ($entry['linea_id'] > 0) {
            $line = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table('recepcion_lineas')} WHERE id = %d AND recepcion_id = %d",
                $entry['linea_id'],
                $rec_id
            ), ARRAY_A);
            if (!$line) {
                throw new Exception('Línea de recepción no encontrada.');
            }
            return $line;
        }

        $item_id = $entry['factura_item_id'];
        if ($item_id > 0) {
            if (!isset($items[$item_id])) {
                throw new Exception('El ítem no pertenece a este documento.');
            }
            $item = $items[$item_id];
            $line = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table('recepcion_lineas')} WHERE recepcion_id = %d AND factura_item_id = %d",
                $rec_id,
                $item_id
            ), ARRAY_A);
            if ($line) {
                return $line;
            }
            $resolved = $this->resolve_item($factura, $item);
            $pb = $resolved['producto_base_id'];
            $manual = false;
            if ($entry['producto_base_id'] > 0 && $entry['producto_base_id'] !== $pb) {
                $pb = $this->valid_product($entry['producto_base_id']);
                $manual = true;
            }
            if ($pb <= 0) {
                throw new Exception('La línea "' . $item['nombre'] . '" no tiene producto: asígnalo antes de recibir.');
            }
            // Un producto asignado a mano no hereda el factor del código de proveedor.
            $factor = $manual ? 1.0 : $resolved['factor'];
            $wpdb->insert($this->table('recepcion_lineas'), [
                'recepcion_id' => $rec_id,
                'factura_item_id' => $item_id,
                'producto_base_id' => $pb,
                'asignado_manual' => $manual ? 1 : 0,
                'nombre' => (string) $item['nombre'],
                'codigo_proveedor' => (string) $item['codigo_proveedor'],
                'factor' => $factor,
                'cantidad_esperada' => round((float) $item['cantidad'] * $factor, 4),
                'costo_unitario' => $this->unit_cost($item, $factor),
            ]);
        } else {
            $pb = $this->valid_product($entry['producto_base_id']);
            if ($pb <= 0) {
                throw new Exception('Producto no válido.');
            }
            $line = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table('recepcion_lineas')}
                 WHERE recepcion_id = %d AND factura_item_id IS NULL AND producto_base_id = %d",
                $rec_id,
                $pb
            ), ARRAY_A);
            if ($line) {
                return $line;
            }
            $wpdb->insert($this->table('recepcion_lineas'), [
                'recepcion_id' => $rec_id,
                'factura_item_id' => null,
                'producto_base_id' => $pb,
                'asignado_manual' => 1,
                'nombre' => 'Producto no incluido en el documento',
                'factor' => 1,
                'cantidad_esperada' => 0,
            ]);
        }
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            throw new Exception('No se pudo registrar la línea.');
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('recepcion_lineas')} WHERE id = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * Anula una recepción que aún no se ordena ni reclama: devuelve el stock y vuelve a pendiente.
     *
     * @param int $factura_id
     * @throws Exception
     */
    public function cancel($factura_id) {
        global $wpdb;
        $rec = $this->reception_for($factura_id);
        if (!$rec || !in_array($rec['estado'], ['parcial', 'recibida'], true)) {
            throw new Exception('No hay una recepción para anular.');
        }
        if (!$this->can_cancel($rec)) {
            throw new Exception('Ya se ordenó o reclamó parte de lo recibido: no se puede anular.');
        }
        $factura = $this->factura($factura_id);
        $label = $factura ? self::doc_label((int) $factura['tipo_dte']) . ' N° ' . $factura['folio'] : '#' . $factura_id;
        $location = (int) ($rec['ubicacion_id'] ?: $this->location_id());

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($this->reception_lines((int) $rec['id']) as $line) {
                $qty = (float) $line['cantidad_recibida'];
                if ($qty > self::EPS) {
                    Riverso_Movement::create('reversa_recepcion', (int) $line['producto_base_id'], $qty, [
                        'ubicacion_origen' => $location,
                        'referencia_tipo' => self::REF_TIPO,
                        'referencia_id' => (int) $rec['id'],
                        'notas' => 'Anulación recepción ' . $label,
                    ]);
                }
            }
            $wpdb->delete($this->table('recepcion_lineas'), ['recepcion_id' => (int) $rec['id']]);
            $wpdb->delete($this->table('recepciones'), ['id' => (int) $rec['id']]);
            // Lo que esta recepción cubría vuelve a quedar pendiente.
            $wpdb->delete($this->table('recepciones'), ['cubierta_por' => (int) $factura_id, 'estado' => 'cubierta']);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $this->pairs = null;
    }

    /**
     * @param array $rec
     * @return bool
     */
    private function can_cancel(array $rec) {
        global $wpdb;
        if (!in_array($rec['estado'], ['parcial', 'recibida'], true)) {
            return false;
        }
        $moved = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cantidad_ordenada + cantidad_reclamada), 0)
             FROM {$this->table('recepcion_lineas')} WHERE recepcion_id = %d",
            (int) $rec['id']
        ));
        if ($moved > self::EPS) {
            return false;
        }
        $claims = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('reclamos_proveedor')} WHERE recepcion_id = %d AND estado <> 'descartado'",
            (int) $rec['id']
        ));
        return $claims === 0;
    }

    /* ===================== Ignorar ===================== */

    /**
     * Marca documentos pendientes como ignorados (p. ej. recepciones muy antiguas).
     *
     * @param int[]  $factura_ids
     * @param string $motivo
     * @return int Documentos ignorados.
     */
    public function ignore(array $factura_ids, $motivo = '') {
        global $wpdb;
        if (!$this->ready()) {
            return 0;
        }
        $count = 0;
        $now = current_time('mysql');
        foreach (array_unique(array_map('absint', $factura_ids)) as $id) {
            if ($id <= 0 || $this->reception_for($id)) {
                continue;
            }
            $factura = $this->factura($id);
            if (!$factura) {
                continue;
            }
            $ok = $wpdb->insert($this->table('recepciones'), [
                'factura_id' => $id,
                'proveedor_id' => (int) $factura['proveedor_id'] ?: null,
                'estado' => 'ignorada',
                'motivo' => $motivo !== '' ? $motivo : null,
                'created_by' => get_current_user_id() ?: null,
                'created_at' => $now,
            ]);
            if ($ok) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Ignorado → vuelve a pendiente. Cubierto por otro documento → queda abierto para recibirlo igual
     * (si solo se borrara, la guía/factura relacionada lo volvería a cubrir).
     *
     * @param int $factura_id
     * @return bool
     */
    public function restore($factura_id) {
        global $wpdb;
        $rec = $this->reception_for($factura_id);
        if (!$rec) {
            return false;
        }
        if ($rec['estado'] === 'ignorada') {
            return (bool) $wpdb->delete($this->table('recepciones'), ['id' => (int) $rec['id']]);
        }
        if ($rec['estado'] === 'cubierta') {
            return false !== $wpdb->update(
                $this->table('recepciones'),
                ['estado' => 'parcial', 'cubierta_por' => null, 'ubicacion_id' => $this->location_id()],
                ['id' => (int) $rec['id']]
            );
        }
        return false;
    }

    /* ===================== Ordenar y reclamar ===================== */

    /**
     * Traslada de Recepción a su lugar.
     *
     * @param int   $factura_id
     * @param array $moves [{linea_id, ubicacion_id, cantidad}]
     * @return array Documento actualizado.
     * @throws Exception
     */
    public function order($factura_id, array $moves) {
        global $wpdb;
        $rec = $this->reception_for($factura_id);
        if (!$rec || !in_array($rec['estado'], ['parcial', 'recibida'], true)) {
            throw new Exception('Este documento no tiene una recepción para ordenar.');
        }
        $from = (int) ($rec['ubicacion_id'] ?: $this->location_id());
        $factura = $this->factura($factura_id);
        $label = self::doc_label((int) $factura['tipo_dte']) . ' N° ' . $factura['folio'];

        $wpdb->query('START TRANSACTION');
        try {
            $done = 0;
            foreach ($moves as $move) {
                $qty = round((float) ($move['cantidad'] ?? 0), 4);
                if ($qty <= self::EPS) {
                    continue;
                }
                $line = $this->line_for_update((int) $rec['id'], (int) ($move['linea_id'] ?? 0));
                $pending = (float) $line['cantidad_recibida'] - (float) $line['cantidad_ordenada'] - (float) $line['cantidad_reclamada'];
                if ($qty > $pending + self::EPS) {
                    throw new Exception('Se intenta ordenar más de lo que queda en Recepción.');
                }
                $to = $this->valid_destination((int) ($move['ubicacion_id'] ?? 0));
                $movement = Riverso_Movement::create('traslado', (int) $line['producto_base_id'], $qty, [
                    'ubicacion_origen' => $from,
                    'ubicacion_destino' => $to,
                    'referencia_tipo' => self::REF_TIPO,
                    'referencia_id' => (int) $rec['id'],
                    'notas' => 'Ordenar recepción ' . $label,
                ]);
                if (!$movement) {
                    throw new Exception('Falló el traslado del producto #' . (int) $line['producto_base_id'] . '.');
                }
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('recepcion_lineas')} SET cantidad_ordenada = cantidad_ordenada + %f WHERE id = %d",
                    $qty,
                    (int) $line['id']
                ));
                $done++;
            }
            if ($done === 0) {
                throw new Exception('No hay cantidades para ordenar.');
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return $this->get_document($factura_id);
    }

    /**
     * Faltante, mal estado o producto distinto detectado al ordenar: baja de Recepción y reclamo al proveedor.
     *
     * @param int   $factura_id
     * @param array $claims [{linea_id, motivo, cantidad, notas}]
     * @return array Documento actualizado.
     * @throws Exception
     */
    public function claim($factura_id, array $claims) {
        global $wpdb;
        $rec = $this->reception_for($factura_id);
        if (!$rec || !in_array($rec['estado'], ['parcial', 'recibida'], true)) {
            throw new Exception('Este documento no tiene una recepción.');
        }
        $from = (int) ($rec['ubicacion_id'] ?: $this->location_id());
        $factura = $this->factura($factura_id);
        $label = self::doc_label((int) $factura['tipo_dte']) . ' N° ' . $factura['folio'];
        $items = [];
        foreach ($this->product_items($factura_id) as $item) {
            $items[(int) $item['id']] = $item;
        }

        $wpdb->query('START TRANSACTION');
        try {
            $claim_id = 0;
            foreach ($claims as $row) {
                $qty = round((float) ($row['cantidad'] ?? 0), 4);
                if ($qty <= self::EPS) {
                    continue;
                }
                $motivo = isset(self::MOTIVOS[$row['motivo'] ?? '']) ? $row['motivo'] : 'faltante';
                $line = $this->line_for_update((int) $rec['id'], (int) ($row['linea_id'] ?? 0));
                $pending = (float) $line['cantidad_recibida'] - (float) $line['cantidad_ordenada'] - (float) $line['cantidad_reclamada'];
                if ($qty > $pending + self::EPS) {
                    throw new Exception('Se intenta reclamar más de lo que queda en Recepción.');
                }
                $movement = Riverso_Movement::create('reclamo', (int) $line['producto_base_id'], $qty, [
                    'ubicacion_origen' => $from,
                    'referencia_tipo' => self::REF_TIPO,
                    'referencia_id' => (int) $rec['id'],
                    'notas' => self::MOTIVOS[$motivo] . ' · reclamo ' . $label,
                ]);
                if (!$movement) {
                    throw new Exception('Falló la baja del producto #' . (int) $line['producto_base_id'] . '.');
                }
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('recepcion_lineas')} SET cantidad_reclamada = cantidad_reclamada + %f WHERE id = %d",
                    $qty,
                    (int) $line['id']
                ));
                if ($claim_id <= 0) {
                    $claim_id = $this->open_claim($factura, (int) $rec['id']);
                }
                $item = !empty($line['factura_item_id']) ? ($items[(int) $line['factura_item_id']] ?? null) : null;
                $this->add_claim_line($claim_id, [
                    'recepcion_linea_id' => (int) $line['id'],
                    'factura_item_id' => $item ? (int) $item['id'] : null,
                    'producto_base_id' => (int) $line['producto_base_id'],
                    'nombre' => (string) ($item ? $item['nombre'] : $line['nombre']),
                    'motivo' => $motivo,
                    'cantidad' => $qty,
                    'costo_unitario' => $line['costo_unitario'] !== null ? (float) $line['costo_unitario'] : null,
                    'movimiento_id' => (int) $movement,
                    'notas' => sanitize_textarea_field((string) ($row['notas'] ?? '')),
                ]);
            }
            if ($claim_id <= 0) {
                throw new Exception('No hay cantidades para reclamar.');
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return $this->get_document($factura_id);
    }

    /**
     * @param int $rec_id
     * @param int $linea_id
     * @return array
     * @throws Exception
     */
    private function line_for_update($rec_id, $linea_id) {
        global $wpdb;
        $line = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('recepcion_lineas')} WHERE id = %d AND recepcion_id = %d FOR UPDATE",
            $linea_id,
            $rec_id
        ), ARRAY_A);
        if (!$line) {
            throw new Exception('Línea de recepción no encontrada.');
        }
        return $line;
    }

    /**
     * @param int $id
     * @return int
     * @throws Exception
     */
    private function valid_destination($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, tipo, activo FROM {$this->table('ubicaciones')} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row || empty($row['activo']) || in_array($row['tipo'], ['virtual', 'recepcion'], true)) {
            throw new Exception('Elige un lugar de destino válido.');
        }
        return (int) $row['id'];
    }

    /**
     * Lugares a los que se puede ordenar.
     *
     * @return array<int, array{id: int, codigo: string, nombre: string, tipo: string, barcode: string}>
     */
    public function destinations() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, codigo, nombre, tipo, barcode, zona FROM {$this->table('ubicaciones')}
             WHERE activo = 1 AND COALESCE(tipo, '') NOT IN ('virtual', 'recepcion')
             ORDER BY tipo, codigo",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
                'tipo' => (string) $row['tipo'],
                'barcode' => (string) ($row['barcode'] ?? ''),
                'label' => self::location_label($row),
            ];
        }
        return $out;
    }

    /* ===================== Reclamos ===================== */

    /**
     * Reclamo abierto (por enviar) del documento; si no hay, lo crea con su tarea.
     *
     * @param array $factura
     * @param int   $rec_id
     * @return int
     * @throws Exception
     */
    private function open_claim(array $factura, $rec_id) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('reclamos_proveedor')} WHERE factura_id = %d AND estado = 'por_enviar' ORDER BY id DESC LIMIT 1",
            (int) $factura['id']
        ));
        if ($id > 0) {
            return $id;
        }
        $wpdb->insert($this->table('reclamos_proveedor'), [
            'factura_id' => (int) $factura['id'],
            'recepcion_id' => (int) $rec_id ?: null,
            'proveedor_id' => (int) $factura['proveedor_id'] ?: null,
            'estado' => 'por_enviar',
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            throw new Exception('No se pudo crear el reclamo.');
        }

        // Orden de enviar el reclamo al proveedor (para que llegue la nota de crédito).
        if (class_exists('Riverso_Task_Module')) {
            $proveedor = (string) ($factura['proveedor_nombre'] ?: $factura['razon_social_emisor']);
            $task_id = Riverso_Task_Module::get_instance()->create_task([
                'tipo' => 'reclamo_proveedor',
                'titulo' => sprintf(
                    'Enviar reclamo a %s - %s N° %s',
                    $proveedor,
                    self::doc_label((int) $factura['tipo_dte']),
                    $factura['folio']
                ),
                'descripcion' => "Faltante o mal estado detectado en la recepción.\n\n"
                    . "1. Enviar el reclamo al proveedor\n2. Marcarlo como enviado\n3. Asociar la nota de crédito cuando llegue",
                'prioridad' => 'alta',
                'referencia_tipo' => self::CLAIM_REF,
                'referencia_id' => $id,
                'datos_extra' => [
                    'factura_id' => (int) $factura['id'],
                    'proveedor' => $proveedor,
                    'folio' => $factura['folio'],
                ],
            ]);
            if (!is_wp_error($task_id) && (int) $task_id > 0) {
                $wpdb->update($this->table('reclamos_proveedor'), ['tarea_id' => (int) $task_id], ['id' => $id]);
            }
        }
        return $id;
    }

    /**
     * @param int   $claim_id
     * @param array $data
     */
    private function add_claim_line($claim_id, array $data) {
        global $wpdb;
        $wpdb->insert($this->table('reclamo_lineas'), [
            'reclamo_id' => (int) $claim_id,
            'recepcion_linea_id' => $data['recepcion_linea_id'] ?: null,
            'factura_item_id' => $data['factura_item_id'] ?: null,
            'producto_base_id' => $data['producto_base_id'] ?: null,
            'nombre' => $data['nombre'],
            'motivo' => $data['motivo'],
            'cantidad' => $data['cantidad'],
            'costo_unitario' => $data['costo_unitario'],
            'movimiento_id' => $data['movimiento_id'] ?: null,
            'notas' => $data['notas'] !== '' ? $data['notas'] : null,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * @param array{estado?: string, factura_id?: int, id?: int} $args
     * @return array
     */
    public function list_claims(array $args = []) {
        global $wpdb;
        if (!$this->ready()) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        $estado = (string) ($args['estado'] ?? 'abiertos');
        if ($estado === 'abiertos') {
            $where[] = "rc.estado IN ('por_enviar', 'enviado')";
        } elseif (isset(self::CLAIM_STATES[$estado])) {
            $where[] = 'rc.estado = %s';
            $params[] = $estado;
        }
        if (!empty($args['factura_id'])) {
            $where[] = 'rc.factura_id = %d';
            $params[] = (int) $args['factura_id'];
        }
        if (!empty($args['id'])) {
            $where[] = 'rc.id = %d';
            $params[] = (int) $args['id'];
        }
        $sql = "SELECT rc.*, f.tipo_dte, f.folio, f.fecha_emision, f.rut_emisor, f.razon_social_emisor,
                       p.nombre AS proveedor_nombre, p.email AS proveedor_email, p.telefono AS proveedor_telefono,
                       nc.folio AS nc_folio
                FROM {$this->table('reclamos_proveedor')} rc
                INNER JOIN {$this->table('facturas')} f ON f.id = rc.factura_id
                LEFT JOIN {$this->table('proveedores')} p ON p.id = rc.proveedor_id
                LEFT JOIN {$this->table('facturas')} nc ON nc.id = rc.nota_credito_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY FIELD(rc.estado, \'por_enviar\', \'enviado\', \'resuelto\', \'descartado\'), rc.id DESC
                LIMIT 200';
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, ...$params) : $sql, ARRAY_A) ?: [];
        if (!$rows) {
            return [];
        }

        $ids = implode(',', array_map(function ($r) {
            return (int) $r['id'];
        }, $rows));
        $lines = [];
        foreach ($wpdb->get_results(
            "SELECT rl.*, pb.canonical_sku, i.codigo_proveedor
             FROM {$this->table('reclamo_lineas')} rl
             LEFT JOIN {$this->table('producto_base')} pb ON pb.id = rl.producto_base_id
             LEFT JOIN {$this->table('factura_items')} i ON i.id = rl.factura_item_id
             WHERE rl.reclamo_id IN ({$ids}) ORDER BY rl.id ASC",
            ARRAY_A
        ) ?: [] as $line) {
            $lines[(int) $line['reclamo_id']][] = [
                'id' => (int) $line['id'],
                'nombre' => (string) $line['nombre'],
                'sku' => (string) ($line['canonical_sku'] ?? ''),
                'codigo_proveedor' => (string) ($line['codigo_proveedor'] ?? ''),
                'motivo' => (string) $line['motivo'],
                'motivo_label' => self::MOTIVOS[$line['motivo']] ?? $line['motivo'],
                'cantidad' => round((float) $line['cantidad'], 4),
                'costo_unitario' => $line['costo_unitario'] !== null ? (float) $line['costo_unitario'] : null,
                'monto' => $line['costo_unitario'] !== null ? round((float) $line['cantidad'] * (float) $line['costo_unitario']) : null,
                'notas' => (string) ($line['notas'] ?? ''),
                'creado' => (string) $line['created_at'],
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            $claim_lines = $lines[(int) $row['id']] ?? [];
            $total = 0;
            foreach ($claim_lines as $line) {
                $total += (float) ($line['monto'] ?? 0);
            }
            $out[] = [
                'id' => (int) $row['id'],
                'factura_id' => (int) $row['factura_id'],
                'documento' => self::doc_label((int) $row['tipo_dte']) . ' N° ' . $row['folio'],
                'fecha_documento' => (string) $row['fecha_emision'],
                'proveedor' => (string) ($row['proveedor_nombre'] ?: $row['razon_social_emisor']),
                'proveedor_email' => (string) ($row['proveedor_email'] ?? ''),
                'proveedor_telefono' => (string) ($row['proveedor_telefono'] ?? ''),
                'rut' => (string) $row['rut_emisor'],
                'estado' => (string) $row['estado'],
                'estado_label' => self::CLAIM_STATES[$row['estado']] ?? $row['estado'],
                'nota_credito_id' => (int) $row['nota_credito_id'],
                'nota_credito' => $row['nc_folio'] ? 'Nota de crédito N° ' . $row['nc_folio'] : '',
                'notas' => (string) ($row['notas'] ?? ''),
                'creado' => (string) $row['created_at'],
                'enviado_en' => $row['enviado_en'],
                'resuelto_en' => $row['resuelto_en'],
                'lineas' => $claim_lines,
                'total_neto' => round($total),
            ];
        }
        return $out;
    }

    /**
     * Notas de crédito del mismo proveedor (las que referencian el documento, primero).
     *
     * @param int $claim_id
     * @return array
     */
    public function credit_note_candidates($claim_id) {
        global $wpdb;
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT rc.factura_id, f.rut_emisor, f.folio FROM {$this->table('reclamos_proveedor')} rc
             INNER JOIN {$this->table('facturas')} f ON f.id = rc.factura_id WHERE rc.id = %d",
            (int) $claim_id
        ), ARRAY_A);
        if (!$claim) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT nc.id, nc.folio, nc.fecha_emision, nc.monto_total,
                    (SELECT COUNT(*) FROM {$this->table('factura_referencias')} fr
                      WHERE fr.factura_id = nc.id
                        AND (fr.factura_origen_id = %d OR TRIM(LEADING '0' FROM fr.folio_ref) = TRIM(LEADING '0' FROM %s))) AS refiere
             FROM {$this->table('facturas')} nc
             WHERE nc.tipo_dte = 61 AND nc.rut_emisor = %s
             ORDER BY refiere DESC, nc.fecha_emision DESC, nc.id DESC
             LIMIT 30",
            (int) $claim['factura_id'],
            (string) $claim['folio'],
            (string) $claim['rut_emisor']
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'label' => 'N° ' . $row['folio'] . ' · ' . $row['fecha_emision'] . ' · $' . number_format((float) $row['monto_total'], 0, ',', '.')
                    . ((int) $row['refiere'] > 0 ? ' · refiere este documento' : ''),
                'refiere' => (int) $row['refiere'] > 0,
            ];
        }
        return $out;
    }

    /**
     * Avanza el reclamo: enviar → resolver (con nota de crédito) / descartar.
     *
     * @param int    $claim_id
     * @param string $action enviar|resolver|descartar|reabrir|notas
     * @param array  $data   nota_credito_id, notas
     * @return array Reclamo actualizado.
     * @throws Exception
     */
    public function update_claim($claim_id, $action, array $data = []) {
        global $wpdb;
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('reclamos_proveedor')} WHERE id = %d",
            (int) $claim_id
        ), ARRAY_A);
        if (!$claim) {
            throw new Exception('Reclamo no encontrado.');
        }
        $now = current_time('mysql');
        $estado = $claim['estado'];
        $update = [];
        $close_task = false;

        switch ($action) {
            case 'enviar':
                if ($estado !== 'por_enviar') {
                    throw new Exception('El reclamo ya fue enviado.');
                }
                $update = ['estado' => 'enviado', 'enviado_en' => $now];
                $close_task = true;
                break;
            case 'resolver':
                if (!in_array($estado, ['por_enviar', 'enviado'], true)) {
                    throw new Exception('El reclamo ya está cerrado.');
                }
                $nc = (int) ($data['nota_credito_id'] ?? 0);
                if ($nc > 0) {
                    $is_nc = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->table('facturas')} WHERE id = %d AND tipo_dte = 61",
                        $nc
                    ));
                    if (!$is_nc) {
                        throw new Exception('La nota de crédito no es válida.');
                    }
                }
                $update = ['estado' => 'resuelto', 'resuelto_en' => $now, 'nota_credito_id' => $nc ?: null];
                if (empty($claim['enviado_en'])) {
                    $update['enviado_en'] = $now;
                }
                $close_task = true;
                break;
            case 'descartar':
                if (!in_array($estado, ['por_enviar', 'enviado'], true)) {
                    throw new Exception('El reclamo ya está cerrado.');
                }
                $update = ['estado' => 'descartado', 'resuelto_en' => $now];
                $close_task = true;
                break;
            case 'reabrir':
                if (!in_array($estado, ['resuelto', 'descartado'], true)) {
                    throw new Exception('El reclamo está abierto.');
                }
                $update = [
                    'estado' => !empty($claim['enviado_en']) ? 'enviado' : 'por_enviar',
                    'resuelto_en' => null,
                    'nota_credito_id' => null,
                ];
                break;
            case 'notas':
                break;
            default:
                throw new Exception('Acción no válida.');
        }
        if (array_key_exists('notas', $data)) {
            $update['notas'] = sanitize_textarea_field((string) $data['notas']);
        }
        if ($update) {
            $wpdb->update($this->table('reclamos_proveedor'), $update, ['id' => (int) $claim_id]);
        }
        if ($close_task && !empty($claim['tarea_id'])) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('tareas')} SET estado = 'completada', completado_en = %s
                 WHERE id = %d AND estado NOT IN ('completada', 'cancelada')",
                $now,
                (int) $claim['tarea_id']
            ));
        }
        $list = $this->list_claims(['id' => (int) $claim_id, 'estado' => 'todos']);
        return $list ? $list[0] : [];
    }

    /**
     * @return int
     */
    public function count_open_claims() {
        global $wpdb;
        if (!$this->ready()) {
            return 0;
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table('reclamos_proveedor')} WHERE estado IN ('por_enviar', 'enviado')"
        );
    }

    /* ===================== Bandeja de escaneos ===================== */

    /**
     * Documentos escaneados que aún no se ingresan como factura (para ingresarlos desde Recepción
     * sin volver a subir el archivo ni gastar Gemini).
     *
     * @param string $search
     * @return array{items: array, total: int}
     */
    public function scan_inbox($search = '') {
        global $wpdb;
        if (!$this->table_exists('documentos_escaneados') || !$this->table_exists('documentos_archivos')) {
            return ['items' => [], 'total' => 0];
        }
        $where = "d.estado_revision IN ('pendiente', 'revisado') AND COALESCE(d.tipo_dte, 0) <> 61";
        $params = [];
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (d.folio LIKE %s OR d.razon_social_emisor LIKE %s OR d.rut_emisor LIKE %s OR a.nombre_original LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        $from = "FROM {$this->table('documentos_escaneados')} d
                 INNER JOIN {$this->table('documentos_archivos')} a ON a.id = d.archivo_id
                 WHERE {$where}";
        $count_sql = "SELECT COUNT(*) {$from}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, ...$params) : $count_sql);
        $sql = "SELECT d.id, d.tipo_dte, d.tipo_documento, d.folio, d.rut_emisor, d.razon_social_emisor,
                       d.fecha_emision, d.monto_total, d.confianza, d.validacion, d.estado_revision,
                       d.factura_id, d.created_at, a.nombre_original,
                       (SELECT COUNT(*) FROM {$this->table('documento_items')} di WHERE di.documento_id = d.id) AS items
                {$from}
                ORDER BY d.created_at DESC, d.id DESC
                LIMIT 20";
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, ...$params) : $sql, ARRAY_A) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::present_scan_row($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array $row Fila de documentos_escaneados (con items y nombre_original).
     * @return array
     */
    public static function present_scan_row(array $row) {
        $validacion = is_array($row['validacion'] ?? null)
            ? $row['validacion']
            : (json_decode((string) ($row['validacion'] ?? ''), true) ?: []);
        $tipo = (int) ($row['tipo_dte'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'tipo_dte' => $tipo,
            'tipo_label' => function_exists('riverso_scan_tipo_label')
                ? riverso_scan_tipo_label($tipo, (string) ($row['tipo_documento'] ?? ''))
                : self::doc_label($tipo),
            'folio' => (string) ($row['folio'] ?? ''),
            'proveedor' => (string) ($row['razon_social_emisor'] ?? ''),
            'rut' => (string) ($row['rut_emisor'] ?? ''),
            'fecha' => (string) ($row['fecha_emision'] ?? ''),
            'monto_total' => (float) ($row['monto_total'] ?? 0),
            'items' => (int) ($row['items'] ?? 0),
            'confianza' => function_exists('riverso_scan_confidence_level')
                ? riverso_scan_confidence_level((float) ($row['confianza'] ?? 0), $validacion)
                : '',
            'validacion_ok' => !isset($validacion['ok']) || !empty($validacion['ok']),
            'estado' => (string) ($row['estado_revision'] ?? ''),
            'factura_id' => (int) ($row['factura_id'] ?? 0),
            'archivo' => (string) ($row['nombre_original'] ?? ''),
            'subido' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /* ===================== Códigos y productos ===================== */

    /**
     * Resuelve un código leído (EAN, SKU, código de proveedor) contra las líneas del documento.
     *
     * @param int    $factura_id
     * @param string $code
     * @return array{lineas: array, productos: array}
     */
    public function resolve_scan($factura_id, $code) {
        global $wpdb;
        $code = trim((string) $code);
        $out = ['lineas' => [], 'productos' => [], 'factor_caja' => 1];
        if ($code === '') {
            return $out;
        }
        $factura = $this->factura($factura_id);
        if (!$factura) {
            return $out;
        }

        // 1) Código de proveedor o de barra de la caja en el propio documento.
        $items = $this->product_items($factura_id);
        foreach ($items as $item) {
            if ($item['codigo_proveedor'] !== null && strcasecmp(trim((string) $item['codigo_proveedor']), $code) === 0) {
                $out['lineas'][] = ['factura_item_id' => (int) $item['id'], 'por_caja' => true];
            }
        }
        if ($out['lineas']) {
            return $out;
        }
        if (!empty($factura['proveedor_id'])) {
            $box_codes = $wpdb->get_col($wpdb->prepare(
                "SELECT codigo_proveedor FROM {$this->table('producto_proveedor')}
                 WHERE proveedor_id = %d AND activo = 1 AND codigo_barras_proveedor = %s",
                (int) $factura['proveedor_id'],
                $code
            )) ?: [];
            foreach ($items as $item) {
                foreach ($box_codes as $box) {
                    if (strcasecmp(trim((string) $item['codigo_proveedor']), trim((string) $box)) === 0) {
                        $out['lineas'][] = ['factura_item_id' => (int) $item['id'], 'por_caja' => true];
                    }
                }
            }
            if ($out['lineas']) {
                return $out;
            }
        }

        // 2) Producto (EAN / SKU / códigos) y se cruza con los productos del documento.
        $pb_ids = $this->lookup_products($code);
        $out['productos'] = $this->product_summaries($pb_ids);
        return $out;
    }

    /**
     * Búsqueda libre de productos (asignar línea sin vincular o agregar un producto extra).
     *
     * @param string $term
     * @return array
     */
    public function search_products($term) {
        $term = trim((string) $term);
        if ($term === '') {
            return [];
        }
        $ids = $this->lookup_products($term);
        if (!$ids && class_exists('Riverso_Product_Quick_View_Service')) {
            $ids = Riverso_Product_Quick_View_Service::get_instance()->search_ids_for_quotes($term, 'todos', 20);
        }
        return $this->product_summaries($ids);
    }

    /**
     * @param string $code
     * @return int[]
     */
    private function lookup_products($code) {
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-quick-view-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            return [];
        }
        $rows = Riverso_Product_Quick_View_Service::get_instance()->lookup_for_quotes($code, 10);
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!empty($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $ids
     * @return array
     */
    private function product_summaries(array $ids) {
        global $wpdb;
        $ids = array_filter(array_map('absint', $ids));
        if (!$ids) {
            return [];
        }
        $list = implode(',', $ids);
        $rows = $wpdb->get_results(
            "SELECT id, nombre_canonico, canonical_sku FROM {$this->table('producto_base')}
             WHERE id IN ({$list}) AND deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        $by_id = [];
        foreach ($rows as $row) {
            $by_id[(int) $row['id']] = [
                'producto_base_id' => (int) $row['id'],
                'nombre' => (string) $row['nombre_canonico'],
                'sku' => (string) $row['canonical_sku'],
            ];
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($by_id[$id])) {
                $out[] = $by_id[$id];
            }
        }
        return $out;
    }

    /**
     * Producto del ítem: vínculo proveedor+código, SKU local o producto WooCommerce; y su factor caja → unidades.
     *
     * @param array $factura
     * @param array $item
     * @return array{producto_base_id: int, factor: float}
     */
    private function resolve_item(array $factura, array $item) {
        global $wpdb;
        $pb = 0;
        $code = trim((string) ($item['codigo_proveedor'] ?? ''));
        $proveedor_id = (int) ($factura['proveedor_id'] ?? 0);

        if ($code !== '' && $proveedor_id > 0) {
            $pb = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pp.producto_base_id FROM {$this->table('producto_proveedor')} pp
                 INNER JOIN {$this->table('producto_base')} pb ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                 WHERE pp.proveedor_id = %d AND pp.codigo_proveedor = %s AND pp.activo = 1
                 ORDER BY (pp.match_estado = 'VERIFIED') DESC, pp.es_preferido DESC, pp.id ASC
                 LIMIT 1",
                $proveedor_id,
                $code
            ));
        }
        $sku = trim((string) ($item['sku_local'] ?? ''));
        if ($pb <= 0 && $sku !== '') {
            $pb = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('producto_base')} WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
                $sku
            ));
        }
        $wc = (int) ($item['product_id'] ?? 0);
        if ($pb <= 0 && $wc > 0) {
            $pb = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('producto_base')}
                 WHERE (woocommerce_variation_id = %d OR woocommerce_product_id = %d) AND deleted_at IS NULL
                 ORDER BY (woocommerce_variation_id = %d) DESC LIMIT 1",
                $wc,
                $wc,
                $wc
            ));
        }

        $factor = 1.0;
        if ($pb > 0 && $code !== '') {
            if (!class_exists('Riverso_Unit_Product_Service')) {
                $path = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }
            if (class_exists('Riverso_Unit_Product_Service')
                && method_exists('Riverso_Unit_Product_Service', 'resolve_purchase_units')
            ) {
                $units = Riverso_Unit_Product_Service::get_instance()->resolve_purchase_units($pb, $code, $proveedor_id);
                if (!empty($units['apply_factor']) && (float) $units['factor'] > 1.0001) {
                    $factor = (float) $units['factor'];
                }
            }
        }
        return ['producto_base_id' => $pb, 'factor' => $factor];
    }

    /**
     * Costo neto por unidad de stock (para valorizar el reclamo).
     *
     * @param array $item
     * @param float $factor
     * @return float|null
     */
    private function unit_cost(array $item, $factor) {
        $qty = (float) ($item['cantidad'] ?? 0);
        $factor = $factor > 0 ? (float) $factor : 1.0;
        if ($qty <= self::EPS) {
            return null;
        }
        if (isset($item['costo_neto_final']) && $item['costo_neto_final'] !== null && (float) $item['costo_neto_final'] > 0) {
            return round((float) $item['costo_neto_final'] / $qty / $factor, 4);
        }
        if ((float) ($item['monto_total'] ?? 0) > 0) {
            return round((float) $item['monto_total'] / $qty / $factor, 4);
        }
        return round((float) ($item['precio_unitario'] ?? 0) / $factor, 4);
    }

    /**
     * @param int $id
     * @return int 0 si no existe.
     */
    private function valid_product($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('producto_base')} WHERE id = %d AND deleted_at IS NULL",
            absint($id)
        ));
    }

    /* ===================== Guía ↔ factura ===================== */

    /**
     * Lo recibido con un documento cubre a los del mismo despacho (guía ↔ factura que la referencia).
     */
    private function sync_coverage() {
        global $wpdb;
        $pairs = $this->pairs();
        if (!$pairs) {
            return;
        }
        $ids = [];
        foreach ($pairs as $pair) {
            $ids[$pair[0]] = true;
            $ids[$pair[1]] = true;
        }
        $list = implode(',', array_map('intval', array_keys($ids)));
        $states = [];
        foreach ($wpdb->get_results(
            "SELECT factura_id, estado FROM {$this->table('recepciones')} WHERE factura_id IN ({$list})",
            ARRAY_A
        ) ?: [] as $row) {
            $states[(int) $row['factura_id']] = $row['estado'];
        }
        $now = current_time('mysql');
        foreach ($pairs as $pair) {
            foreach ([[$pair[0], $pair[1]], [$pair[1], $pair[0]]] as $dir) {
                list($target, $source) = $dir;
                $source_state = $states[$source] ?? null;
                if (isset($states[$target]) || !in_array($source_state, ['parcial', 'recibida'], true)) {
                    continue;
                }
                $factura = $this->factura($target);
                $ok = $wpdb->insert($this->table('recepciones'), [
                    'factura_id' => $target,
                    'proveedor_id' => $factura ? ((int) $factura['proveedor_id'] ?: null) : null,
                    'estado' => 'cubierta',
                    'cubierta_por' => $source,
                    'created_at' => $now,
                ]);
                if ($ok) {
                    $states[$target] = 'cubierta';
                }
            }
        }
    }

    /**
     * @param int $factura_id
     * @return int[]
     */
    private function related_ids($factura_id) {
        $out = [];
        foreach ($this->pairs() as $pair) {
            if ($pair[0] === (int) $factura_id) {
                $out[] = $pair[1];
            } elseif ($pair[1] === (int) $factura_id) {
                $out[] = $pair[0];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Pares [factura, guía]: referencias del XML y de los documentos escaneados.
     *
     * @return array<int, array{int, int}>
     */
    private function pairs() {
        global $wpdb;
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $this->pairs = [];
        $queries = [];
        if ($this->table_exists('factura_referencias')) {
            $queries[] = "SELECT f.id AS factura_id, g.id AS guia_id
                FROM {$this->table('facturas')} f
                INNER JOIN {$this->table('factura_referencias')} fr ON fr.factura_id = f.id AND fr.tipo_doc_ref = 52
                INNER JOIN {$this->table('facturas')} g ON g.tipo_dte = 52 AND g.rut_emisor = f.rut_emisor
                       AND TRIM(LEADING '0' FROM g.folio) = TRIM(LEADING '0' FROM fr.folio_ref)
                WHERE f.tipo_dte <> 52";
        }
        if ($this->table_exists('documentos_escaneados') && $this->table_exists('documento_referencias')) {
            $queries[] = "SELECT f.id AS factura_id, g.id AS guia_id
                FROM {$this->table('facturas')} f
                INNER JOIN {$this->table('documentos_escaneados')} ds ON ds.factura_id = f.id
                INNER JOIN {$this->table('documento_referencias')} dr ON dr.documento_id = ds.id AND dr.tipo_doc_ref = 52
                INNER JOIN {$this->table('facturas')} g ON g.tipo_dte = 52 AND g.rut_emisor = f.rut_emisor
                       AND TRIM(LEADING '0' FROM g.folio) = TRIM(LEADING '0' FROM dr.folio_ref)
                WHERE f.tipo_dte <> 52";
        }
        if (!$queries) {
            return $this->pairs;
        }
        $seen = [];
        foreach ($wpdb->get_results(implode(' UNION ', $queries), ARRAY_A) ?: [] as $row) {
            $key = (int) $row['factura_id'] . ':' . (int) $row['guia_id'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $this->pairs[] = [(int) $row['factura_id'], (int) $row['guia_id']];
            }
        }
        return $this->pairs;
    }

    /* ===================== Datos ===================== */

    /**
     * @param int $factura_id
     * @return array|null
     */
    private function factura($factura_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.nombre AS proveedor_nombre FROM {$this->table('facturas')} f
             LEFT JOIN {$this->table('proveedores')} p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            absint($factura_id)
        ), ARRAY_A) ?: null;
    }

    /**
     * @param int $factura_id
     * @return array|null
     */
    private function reception_for($factura_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('recepciones')} WHERE factura_id = %d",
            absint($factura_id)
        ), ARRAY_A) ?: null;
    }

    /**
     * @param int $factura_id
     * @return array
     */
    private function product_items($factura_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('factura_items')}
             WHERE factura_id = %d AND COALESCE(item_tipo, 'producto') = 'producto'
             ORDER BY numero_linea ASC, id ASC",
            absint($factura_id)
        ), ARRAY_A) ?: [];
    }

    /**
     * @param int $rec_id
     * @return array
     */
    private function reception_lines($rec_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('recepcion_lineas')} WHERE recepcion_id = %d ORDER BY id ASC",
            absint($rec_id)
        ), ARRAY_A) ?: [];
    }

    /**
     * Marca de fecha en la factura (columnas del flujo antiguo de recepción, si existen).
     *
     * @param int    $factura_id
     * @param string $column
     * @param string $value
     * @param bool   $overwrite
     */
    private function touch_factura($factura_id, $column, $value, $overwrite = false) {
        global $wpdb;
        if (!$this->has_column('facturas', $column)) {
            return;
        }
        $sql = "UPDATE {$this->table('facturas')} SET `{$column}` = %s WHERE id = %d";
        if (!$overwrite) {
            $sql .= " AND `{$column}` IS NULL";
        }
        $wpdb->query($wpdb->prepare($sql, $value, absint($factura_id)));
    }

    /**
     * @return int
     */
    public function location_id() {
        global $wpdb;
        if ($this->location_id === null) {
            $this->location_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('ubicaciones')} WHERE codigo = %s",
                self::LOCATION_CODE
            ));
        }
        return $this->location_id;
    }

    /**
     * @return bool
     */
    public function ready() {
        if ($this->ready !== null) {
            return $this->ready;
        }
        if (!class_exists('Riverso_Movement')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'inventory/movements/class-movement.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        $ok = $this->table_exists('recepciones') && $this->table_exists('reclamo_lineas') && $this->location_id() > 0;
        if (!$ok && class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_purchase_reception')) {
            Riverso_POS_Activator::ensure_purchase_reception();
            $this->location_id = null;
            $ok = $this->table_exists('recepciones') && $this->table_exists('reclamo_lineas') && $this->location_id() > 0;
        }
        $this->ready = $ok && class_exists('Riverso_Movement');
        return $this->ready;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function table_exists($name) {
        global $wpdb;
        $table = $this->table($name);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    private function has_column($table, $column) {
        global $wpdb;
        $key = $table . '.' . $column;
        if (!isset($this->columns[$key])) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table($table)}", 0);
            $this->columns[$key] = is_array($cols) && in_array($column, $cols, true);
        }
        return $this->columns[$key];
    }

    /**
     * @param string $name
     * @return string
     */
    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /* ===================== Etiquetas ===================== */

    /**
     * @param int $tipo_dte
     * @return string
     */
    public static function doc_label($tipo_dte) {
        switch ((int) $tipo_dte) {
            case 52:
                return 'Guía';
            case 34:
                return 'Factura exenta';
            case 46:
                return 'Factura de compra';
            case 61:
                return 'Nota de crédito';
            default:
                return 'Factura';
        }
    }

    /**
     * @param string $estado
     * @return string
     */
    public static function state_label($estado) {
        $labels = [
            'pendiente' => 'Pendiente',
            'parcial' => 'Parcial',
            'recibida' => 'Recibida',
            'cubierta' => 'Recibida con otro documento',
            'ignorada' => 'Ignorada',
        ];
        return $labels[$estado] ?? $estado;
    }

    /**
     * @param array $row
     * @return string
     */
    private static function location_label(array $row) {
        $codigo = (string) ($row['codigo'] ?? '');
        $nombre = (string) ($row['nombre'] ?? '');
        if ($nombre === '' || $nombre === $codigo) {
            return $codigo;
        }
        return $codigo . ' · ' . $nombre;
    }
}
