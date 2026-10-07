<?php
/**
 * Salida de stock por venta (documentos emitidos o boletas cerradas en Facturación).
 *
 * - Qué sale: cada línea trae su detalle de entrega (bolsas leídas por EAN13 interno + sueltas).
 *   Las bolsas registradas se marcan vendidas (sus unidades ya salieron al embolsar); las no
 *   registradas y las sueltas descuentan unidades.
 * - De dónde sale: cascada sin preguntar al usuario — Mesón/Vitrina → ubicación preferida →
 *   resto de la sala → Bodega externa → "Sin ubicar" (virtual, puede quedar negativo).
 * - Cada descuento queda en riverso_venta_stock para revertirlo exacto si se elimina el documento.
 * - Los conteos cuadran "Sin ubicar": unidades encontradas cancelan su negativo; un conteo de
 *   producto lo deja en 0.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Sale_Stock_Service {

    const REF_TIPO = 'venta_documento';
    const VIRTUAL_CODE = 'SIN-UBICAR';
    const EPS = 0.0001;

    /** Tipos de ubicación de punto de venta (salen primero) y de reserva (salen al final). */
    const POINT_OF_SALE_TYPES = ['meson', 'vitrina'];
    const STORAGE_TYPES = ['bodega_ext'];
    // Zona de llegada: lo recién recibido aún sin ordenar (se vende antes que la bodega externa).
    const ARRIVAL_TYPES = ['recepcion'];

    private static $instance = null;

    /** @var bool|null */
    private $ready = null;

    /** @var int|null */
    private $virtual_id = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ===================== Detalle de entrega ===================== */

    /**
     * Normaliza el detalle de entrega de una línea contra su cantidad.
     * Si las bolsas superan la cantidad se quitan desde la última; el resto son sueltas.
     * "assumed": las sueltas vienen de una cantidad escrita sin confirmar (o se desajustaron).
     *
     * @param mixed $raw      JSON o arreglo {bags:[{size,count,ean}], loose, assumed}
     * @param float $quantity
     * @return array{bags: array<int, array{size: float, count: int, ean: string}>, loose: float, assumed: bool}
     */
    public static function normalize_breakdown($raw, $quantity) {
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        $data = is_array($data) ? $data : [];
        $qty = max(0.0, round((float) $quantity, 4));

        $by_size = [];
        foreach ((array) ($data['bags'] ?? []) as $bag) {
            if (!is_array($bag)) {
                continue;
            }
            $size = round((float) ($bag['size'] ?? 0), 4);
            $count = (int) ($bag['count'] ?? 0);
            if ($size <= 0 || $count <= 0) {
                continue;
            }
            $key = (string) $size;
            if (!isset($by_size[$key])) {
                $by_size[$key] = ['size' => $size, 'count' => 0, 'ean' => ''];
            }
            $by_size[$key]['count'] += $count;
            if ($by_size[$key]['ean'] === '' && !empty($bag['ean'])) {
                $by_size[$key]['ean'] = substr(preg_replace('/\D+/', '', (string) $bag['ean']), 0, 20);
            }
        }
        $bags = array_values($by_size);

        $total = 0.0;
        foreach ($bags as $bag) {
            $total += $bag['size'] * $bag['count'];
        }
        while ($total > $qty + self::EPS && $bags) {
            $last = count($bags) - 1;
            $bags[$last]['count']--;
            $total -= $bags[$last]['size'];
            if ($bags[$last]['count'] <= 0) {
                array_pop($bags);
            }
        }

        $loose = round(max(0.0, $qty - $total), 4);
        $assumed = array_key_exists('assumed', $data) ? (bool) $data['assumed'] : true;
        $stored_loose = isset($data['loose']) ? round((float) $data['loose'], 4) : null;
        if ($loose > self::EPS && ($stored_loose === null || abs($stored_loose - $loose) > self::EPS)) {
            // La cantidad cambió fuera del detalle (p. ej. editada en Facturación).
            $assumed = true;
        }
        if ($loose <= self::EPS) {
            $assumed = false;
        }

        return ['bags' => $bags, 'loose' => $loose, 'assumed' => $assumed];
    }

    /**
     * @param mixed $raw
     * @param float $quantity
     * @return string
     */
    public static function breakdown_json($raw, $quantity) {
        return (string) wp_json_encode(self::normalize_breakdown($raw, $quantity));
    }

    /* ===================== Aplicar / revertir por documento ===================== */

    /**
     * Descuenta el stock de un documento de venta (borrador emitido o boleta cerrada). Idempotente.
     *
     * @param int $draft_id
     * @return array{applied: bool, message: string}
     */
    public function apply_document($draft_id) {
        global $wpdb;
        $draft_id = absint($draft_id);
        if ($draft_id <= 0 || !$this->ready()) {
            return ['applied' => false, 'message' => 'Salida de stock no disponible.'];
        }
        $lock = 'riverso_sale_stock_' . $draft_id;
        if (get_transient($lock)) {
            return ['applied' => false, 'message' => 'Salida de stock en curso.'];
        }
        set_transient($lock, 1, 60);

        try {
            if ($this->is_applied($draft_id)) {
                return ['applied' => false, 'message' => 'El stock de este documento ya se descontó.'];
            }
            $lines = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table('billing_draft_lines')} WHERE draft_id = %d ORDER BY position ASC, id ASC",
                $draft_id
            ), ARRAY_A) ?: [];

            $wpdb->query('START TRANSACTION');
            $applied_lines = 0;
            foreach ($lines as $line) {
                if ($this->apply_line($draft_id, $line)) {
                    $applied_lines++;
                }
            }
            $wpdb->query('COMMIT');

            return [
                'applied' => $applied_lines > 0,
                'message' => $applied_lines > 0
                    ? 'Stock descontado (' . $applied_lines . ' línea' . ($applied_lines === 1 ? '' : 's') . ').'
                    : 'Sin líneas con producto para descontar.',
            ];
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['applied' => false, 'message' => 'No se pudo descontar el stock: ' . $e->getMessage()];
        } finally {
            delete_transient($lock);
        }
    }

    /**
     * @param int   $draft_id
     * @param array $line Fila de billing_draft_lines.
     * @return bool
     */
    private function apply_line($draft_id, array $line) {
        global $wpdb;
        $qty = (float) ($line['quantity'] ?? 0);
        $pb = (int) ($line['producto_base_id'] ?? 0);
        if ($pb <= 0) {
            $pb = $this->resolve_producto_base((int) ($line['product_id'] ?? 0));
        }
        if ($qty <= self::EPS || $pb <= 0) {
            return false;
        }
        $line_id = (int) ($line['id'] ?? 0);
        $detail = self::normalize_breakdown($line['stock_breakdown'] ?? null, $qty);

        // Bolsas: las registradas se marcan vendidas; las no registradas se descuentan como sueltas.
        $unregistered = 0.0;
        foreach ($detail['bags'] as $bag) {
            for ($i = 0; $i < $bag['count']; $i++) {
                $bolsa_id = $this->claim_bag($pb, $bag['size'], $draft_id);
                if ($bolsa_id > 0) {
                    $this->log($draft_id, $line_id, $pb, 'bolsa', null, $bag['size'], null, $bolsa_id, 0, false);
                } else {
                    $unregistered += $bag['size'];
                }
            }
        }

        $loose = round($detail['loose'] + $unregistered, 4);
        if ($loose <= self::EPS) {
            return true;
        }

        // Las sueltas también salen del stock abierto (el que alimenta el embolsado).
        $open_taken = 0.0;
        $open = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT stock_abierto FROM {$this->table('producto_base')} WHERE id = %d",
            $pb
        ));
        if ($open > self::EPS) {
            $open_taken = round(min($open, $loose), 4);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('producto_base')} SET stock_abierto = GREATEST(0, stock_abierto - %f) WHERE id = %d",
                $open_taken,
                $pb
            ));
        }

        $first = true;
        foreach ($this->plan_cascade($pb, $loose) as $take) {
            $movement_id = Riverso_Movement::create('venta', $pb, $take['cantidad'], [
                'ubicacion_origen' => $take['ubicacion_id'],
                'referencia_tipo' => self::REF_TIPO,
                'referencia_id' => $draft_id,
                'notas' => 'Venta documento #' . $draft_id . ($take['virtual'] ? ' (sin ubicar)' : ''),
            ]);
            if (!$movement_id) {
                throw new Exception('falló el movimiento del producto #' . $pb);
            }
            $this->log(
                $draft_id,
                $line_id,
                $pb,
                'suelto',
                $take['ubicacion_id'],
                $take['cantidad'],
                (int) $movement_id,
                null,
                $first ? $open_taken : 0,
                $detail['assumed']
            );
            $first = false;
        }
        return true;
    }

    /**
     * Revierte exactamente lo descontado por un documento (p. ej. boleta cerrada eliminada).
     *
     * @param int $draft_id
     * @return int Filas revertidas.
     */
    public function revert_document($draft_id) {
        global $wpdb;
        $draft_id = absint($draft_id);
        if ($draft_id <= 0 || !$this->ready()) {
            return 0;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('venta_stock')} WHERE draft_id = %d AND estado = 'aplicado' ORDER BY id DESC",
            $draft_id
        ), ARRAY_A) ?: [];
        if (!$rows) {
            return 0;
        }
        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        foreach ($rows as $row) {
            $pb = (int) $row['producto_base_id'];
            if ($row['componente'] === 'bolsa' && !empty($row['bolsa_id'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('bolsas')}
                     SET estado = 'generada', venta_draft_id = NULL, vendida_en = NULL
                     WHERE id = %d AND estado = 'vendida'",
                    (int) $row['bolsa_id']
                ));
            } elseif ((float) $row['cantidad'] > self::EPS && !empty($row['ubicacion_id'])) {
                Riverso_Movement::create('reversa_venta', $pb, (float) $row['cantidad'], [
                    'ubicacion_destino' => (int) $row['ubicacion_id'],
                    'referencia_tipo' => self::REF_TIPO,
                    'referencia_id' => $draft_id,
                    'notas' => 'Reversa venta documento #' . $draft_id,
                ]);
            }
            if ((float) $row['abierto_descontado'] > self::EPS) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table('producto_base')} SET stock_abierto = stock_abierto + %f WHERE id = %d",
                    (float) $row['abierto_descontado'],
                    $pb
                ));
            }
            $wpdb->update(
                $this->table('venta_stock'),
                ['estado' => 'revertido', 'revertido_en' => $now],
                ['id' => (int) $row['id']]
            );
        }
        $wpdb->query('COMMIT');
        return count($rows);
    }

    /**
     * @param int $draft_id
     * @return bool
     */
    public function is_applied($draft_id) {
        global $wpdb;
        if (!$this->ready()) {
            return false;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('venta_stock')} WHERE draft_id = %d AND estado = 'aplicado'",
            absint($draft_id)
        )) > 0;
    }

    /* ===================== Cascada de ubicaciones ===================== */

    /**
     * Ubicaciones con saldo positivo en el orden en que se descuentan.
     *
     * @param int $producto_base_id
     * @return array<int, array<string, mixed>>
     */
    public function cascade_candidates($producto_base_id) {
        global $wpdb;
        if (!$this->ready()) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pu.ubicacion_id, pu.cantidad, pu.origen_cantidad, pu.contado_en,
                    u.codigo, u.nombre, u.tipo,
                    pp.es_preferido, pp.prioridad
             FROM {$this->table('producto_ubicacion')} pu
             INNER JOIN {$this->table('ubicaciones')} u ON u.id = pu.ubicacion_id
             LEFT JOIN {$this->table('producto_ubicacion_preferida')} pp
                    ON pp.producto_base_id = pu.product_id AND pp.ubicacion_id = pu.ubicacion_id
             WHERE pu.product_id = %d AND pu.cantidad > 0 AND COALESCE(u.tipo, '') <> 'virtual'",
            absint($producto_base_id)
        ), ARRAY_A) ?: [];

        usort($rows, function ($a, $b) {
            $ta = self::tier($a);
            $tb = self::tier($b);
            if ($ta !== $tb) {
                return $ta - $tb;
            }
            $pa = (int) !empty($a['es_preferido']);
            $pb = (int) !empty($b['es_preferido']);
            if ($pa !== $pb) {
                return $pb - $pa;
            }
            $ra = $a['prioridad'] === null ? 1000 : (int) $a['prioridad'];
            $rb = $b['prioridad'] === null ? 1000 : (int) $b['prioridad'];
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            // Conteo más reciente primero (su saldo es más confiable).
            $ca = (string) ($a['contado_en'] ?? '');
            $cb = (string) ($b['contado_en'] ?? '');
            if ($ca !== $cb) {
                return strcmp($cb, $ca);
            }
            return (int) $a['ubicacion_id'] - (int) $b['ubicacion_id'];
        });

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ubicacion_id' => (int) $row['ubicacion_id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) ($row['nombre'] ?: $row['codigo']),
                'tipo' => (string) $row['tipo'],
                'cantidad' => round((float) $row['cantidad'], 4),
                'estimado' => ($row['origen_cantidad'] ?? '') === 'estimado',
            ];
        }
        return $out;
    }

    /**
     * Reparto de una cantidad según la cascada; el sobrante va a "Sin ubicar".
     *
     * @param int   $producto_base_id
     * @param float $quantity
     * @return array<int, array{ubicacion_id: int, nombre: string, cantidad: float, virtual: bool}>
     */
    public function plan_cascade($producto_base_id, $quantity) {
        $remaining = round((float) $quantity, 4);
        $takes = [];
        foreach ($this->cascade_candidates($producto_base_id) as $row) {
            if ($remaining <= self::EPS) {
                break;
            }
            $take = round(min($row['cantidad'], $remaining), 4);
            $takes[] = [
                'ubicacion_id' => $row['ubicacion_id'],
                'nombre' => $row['nombre'],
                'cantidad' => $take,
                'virtual' => false,
            ];
            $remaining = round($remaining - $take, 4);
        }
        if ($remaining > self::EPS) {
            $takes[] = [
                'ubicacion_id' => $this->virtual_location_id(),
                'nombre' => 'Sin ubicar',
                'cantidad' => $remaining,
                'virtual' => true,
            ];
        }
        return $takes;
    }

    /**
     * 0 punto de venta · 1 preferida · 2 resto de la sala · 3 recepción · 4 bodega externa.
     *
     * @param array $row
     * @return int
     */
    private static function tier(array $row) {
        $tipo = strtolower(trim((string) ($row['tipo'] ?? '')));
        if (in_array($tipo, self::POINT_OF_SALE_TYPES, true)) {
            return 0;
        }
        if (in_array($tipo, self::ARRIVAL_TYPES, true)) {
            return 3;
        }
        if (in_array($tipo, self::STORAGE_TYPES, true)) {
            return 4;
        }
        return !empty($row['es_preferido']) ? 1 : 2;
    }

    /**
     * @return int
     */
    public function virtual_location_id() {
        global $wpdb;
        if ($this->virtual_id === null) {
            $this->virtual_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('ubicaciones')} WHERE codigo = %s",
                self::VIRTUAL_CODE
            ));
        }
        return $this->virtual_id;
    }

    /**
     * Saldo de "Sin ubicar" del producto (negativo = vendido sin lugar identificado).
     *
     * @param int $producto_base_id
     * @return float
     */
    public function unlocated_balance($producto_base_id) {
        global $wpdb;
        $virtual = $this->virtual_location_id();
        if ($virtual <= 0) {
            return 0.0;
        }
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT cantidad FROM {$this->table('producto_ubicacion')} WHERE product_id = %d AND ubicacion_id = %d",
            absint($producto_base_id),
            $virtual
        ));
    }

    /* ===================== Cuadratura con conteos ===================== */

    /**
     * Un conteo de lugar encontró más de lo que el sistema tenía: esas unidades explican
     * primero lo vendido "sin ubicar" (cancela su negativo).
     *
     * @param int   $producto_base_id
     * @param int   $ubicacion_id
     * @param float $gain
     * @param int   $conteo_id
     */
    public function reconcile_count_gain($producto_base_id, $ubicacion_id, $gain, $conteo_id) {
        if (!$this->ready() || $gain <= self::EPS) {
            return;
        }
        $virtual = $this->virtual_location_id();
        if ($virtual <= 0 || (int) $ubicacion_id === $virtual) {
            return;
        }
        $balance = $this->unlocated_balance($producto_base_id);
        if ($balance >= -self::EPS) {
            return;
        }
        $offset = round(min((float) $gain, -$balance), 4);
        Riverso_Movement::create('correccion', absint($producto_base_id), $offset, [
            'ubicacion_destino' => $virtual,
            'referencia_tipo' => 'conteo',
            'referencia_id' => absint($conteo_id),
            'notas' => 'Cuadratura Sin ubicar: unidades encontradas en conteo #' . absint($conteo_id),
        ]);
    }

    /**
     * Conteo del producto en todos sus lugares: "Sin ubicar" vuelve a 0.
     *
     * @param int $producto_base_id
     * @param int $conteo_id
     */
    public function reset_unlocated($producto_base_id, $conteo_id) {
        if (!$this->ready()) {
            return;
        }
        $virtual = $this->virtual_location_id();
        $balance = $this->unlocated_balance($producto_base_id);
        if ($virtual <= 0 || abs($balance) <= self::EPS) {
            return;
        }
        Riverso_Movement::create('correccion', absint($producto_base_id), -$balance, [
            'ubicacion_destino' => $virtual,
            'referencia_tipo' => 'conteo',
            'referencia_id' => absint($conteo_id),
            'notas' => 'Conteo de producto #' . absint($conteo_id) . ': Sin ubicar vuelve a 0',
        ]);
    }

    /* ===================== Bolsas ===================== */

    /**
     * Tamaños de bolsa registrados y disponibles del producto.
     *
     * @param int $producto_base_id
     * @return array<int, array{size: float, disponibles: int}>
     */
    public function bag_sizes($producto_base_id) {
        global $wpdb;
        if (!$this->ready()) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cantidad, COUNT(*) AS disponibles FROM {$this->table('bolsas')}
             WHERE producto_base_id = %d AND estado = 'generada'
             GROUP BY cantidad ORDER BY cantidad ASC",
            absint($producto_base_id)
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['size' => round((float) $row['cantidad'], 4), 'disponibles' => (int) $row['disponibles']];
        }
        return $out;
    }

    /**
     * Toma una bolsa registrada (la más antigua) del tamaño indicado.
     *
     * @return int id de la bolsa o 0 si no hay registrada.
     */
    private function claim_bag($producto_base_id, $size, $draft_id) {
        global $wpdb;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('bolsas')}
                 WHERE producto_base_id = %d AND estado = 'generada' AND ABS(cantidad - %f) < 0.0001
                 ORDER BY id ASC LIMIT 1",
                $producto_base_id,
                $size
            ));
            if ($id <= 0) {
                return 0;
            }
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('bolsas')}
                 SET estado = 'vendida', venta_draft_id = %d, vendida_en = %s
                 WHERE id = %d AND estado = 'generada'",
                $draft_id,
                current_time('mysql'),
                $id
            ));
            if ($updated === 1) {
                return $id;
            }
        }
        return 0;
    }

    /* ===================== Utilidades ===================== */

    private function log($draft_id, $line_id, $pb, $componente, $ubicacion_id, $cantidad, $movement_id, $bolsa_id, $open_taken, $assumed) {
        global $wpdb;
        $wpdb->insert($this->table('venta_stock'), [
            'draft_id' => $draft_id,
            'draft_line_id' => $line_id > 0 ? $line_id : null,
            'producto_base_id' => $pb,
            'componente' => $componente,
            'ubicacion_id' => $ubicacion_id ?: null,
            'cantidad' => round((float) $cantidad, 4),
            'movimiento_id' => $movement_id ?: null,
            'bolsa_id' => $bolsa_id ?: null,
            'abierto_descontado' => round((float) $open_taken, 4),
            'supuesto' => $assumed ? 1 : 0,
            'estado' => 'aplicado',
            'created_at' => current_time('mysql'),
        ]);
    }

    private function resolve_producto_base($wc_id) {
        global $wpdb;
        if ($wc_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table('producto_base')}
             WHERE deleted_at IS NULL AND (woocommerce_variation_id = %d OR woocommerce_product_id = %d)
             ORDER BY (woocommerce_variation_id = %d) DESC, id ASC LIMIT 1",
            $wc_id,
            $wc_id,
            $wc_id
        ));
    }

    /**
     * Schema de la fase 71 presente; si falta, intenta crearlo una vez.
     *
     * @return bool
     */
    private function ready() {
        if ($this->ready !== null) {
            return $this->ready;
        }
        global $wpdb;
        $check = function () use ($wpdb) {
            $table = $this->table('venta_stock');
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table('producto_ubicacion')}", 0);
            return is_array($cols) && in_array('origen_cantidad', $cols, true) && $this->virtual_location_id() > 0;
        };
        $ok = $check();
        if (!$ok && class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_sale_stock_exit')) {
            Riverso_POS_Activator::ensure_sale_stock_exit();
            $this->virtual_id = null;
            $ok = $check();
        }
        $this->ready = $ok && class_exists('Riverso_Movement');
        return $this->ready;
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }
}
