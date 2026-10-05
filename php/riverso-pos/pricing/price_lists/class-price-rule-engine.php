<?php
/**
 * Motor de evaluación de reglas de precio por tramos - Riverso POS.
 *
 * Pipeline por tramo:
 *   1. unitario0 = fórmula(P) + piso unitario (total_minimo)
 *   2. T0 = unitario0 × Q
 *   3. T0 = min(T0, P × Q + máx ΔT) si hay tope de alza (máx ΔT = fórmula en P)
 *   4. T1 = fórmula_total(T) si existe (T = total de línea antes del ajuste)
 *   5. T_final = max(T1, piso_total) si hay piso de total
 *   6. unitario = T_final / Q (hasta 4 decimales si hubo ajuste de total)
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Price_Rule_Engine {

    const FORMULAS = ['multiplicador', 'suma', 'rango', 'formula'];
    const REDONDEOS = ['ninguno', 'techo_decena', 'techo_cincuentena', 'techo_centena', 'techo_centana'];
    const FORMULA_MAX_LEN = 500;
    const FORMULA_MAX_TOKENS = 200;
    const FORMULA_MAX_DEPTH = 32;

    const FUNCS = [
        't10' => ['arity' => 1, 'alias_of' => 't10'],
        't50' => ['arity' => 1, 'alias_of' => 't50'],
        't100' => ['arity' => 1, 'alias_of' => 't100'],
        'techo_decena' => ['arity' => 1, 'alias_of' => 't10'],
        'techo_cincuentena' => ['arity' => 1, 'alias_of' => 't50'],
        'techo_centena' => ['arity' => 1, 'alias_of' => 't100'],
        'techo_centana' => ['arity' => 1, 'alias_of' => 't100'],
        'max' => ['arity' => 0, 'alias_of' => 'max'],
        'min' => ['arity' => 0, 'alias_of' => 'min'],
    ];

    /** @var bool */
    private static $allow_total_var = false;

    public static function techo_decena($valor) {
        return self::techo_multiplo($valor, 10);
    }

    public static function techo_cincuentena($valor) {
        return self::techo_multiplo($valor, 50);
    }

    public static function techo_centena($valor) {
        return self::techo_multiplo($valor, 100);
    }

    public static function techo_centana($valor) {
        return self::techo_centena($valor);
    }

    public static function techo_multiplo($valor, $multiplo) {
        $valor = (float) $valor;
        $multiplo = (float) $multiplo;
        if ($valor <= 0 || $multiplo <= 0) {
            return 0.0;
        }
        return (float) (ceil($valor / $multiplo) * $multiplo);
    }

    public static function sanitize_formula($formula) {
        $formula = wp_strip_all_tags((string) $formula);
        $formula = str_replace(["\r", "\n", "\t"], '', $formula);
        return trim($formula);
    }

    /**
     * Valida fórmula de precio unitario (solo P).
     *
     * @return true|WP_Error
     */
    public static function validate_formula($formula) {
        return self::validate_formula_mode($formula, 'unit');
    }

    /**
     * Valida fórmula sobre total de línea (permite T).
     *
     * @return true|WP_Error
     */
    public static function validate_formula_total($formula) {
        return self::validate_formula_mode($formula, 'total');
    }

    /**
     * @param string $mode 'unit'|'total'
     * @return true|WP_Error
     */
    private static function validate_formula_mode($formula, $mode) {
        $formula = self::sanitize_formula($formula);
        if ($formula === '') {
            return true;
        }
        if (strlen($formula) > self::FORMULA_MAX_LEN) {
            return new WP_Error('too_long', 'La fórmula no puede superar ' . self::FORMULA_MAX_LEN . ' caracteres');
        }
        try {
            if ($mode === 'total') {
                self::evaluate_formula($formula, 10.0, 100.0, true);
            } else {
                self::evaluate_formula($formula, 10.0, null, false);
            }
            return true;
        } catch (Exception $e) {
            return new WP_Error('invalid_formula', $e->getMessage());
        }
    }

    public static function formula_from_tier(array $tier) {
        $existing = isset($tier['formula']) ? self::sanitize_formula($tier['formula']) : '';
        if ($existing !== '') {
            return $existing;
        }

        $tipo = isset($tier['formula_tipo']) ? $tier['formula_tipo'] : 'multiplicador';
        $mult = (isset($tier['multiplicador']) && $tier['multiplicador'] !== null && $tier['multiplicador'] !== '')
            ? (float) $tier['multiplicador'] : null;
        $add = (isset($tier['addendo']) && $tier['addendo'] !== null && $tier['addendo'] !== '')
            ? (float) $tier['addendo'] : null;

        if ($tipo === 'suma') {
            $n = $add ?? 0.0;
            $expr = $n < 0 ? ('P' . self::format_num($n)) : ('P+' . self::format_num($n));
        } elseif ($mult !== null && abs($mult - 1.0) > 0.0000001) {
            $expr = 'P*' . self::format_num($mult);
        } else {
            $expr = 'P';
        }

        $round = isset($tier['redondeo']) ? $tier['redondeo'] : 'ninguno';
        switch ($round) {
            case 'techo_decena':
                $expr = 'T10(' . $expr . ')';
                break;
            case 'techo_cincuentena':
                $expr = 'T50(' . $expr . ')';
                break;
            case 'techo_centena':
            case 'techo_centana':
                $expr = 'T100(' . $expr . ')';
                break;
        }

        return $expr;
    }

    public static function select_tier(array $tiers, $qty) {
        $qty = (float) $qty;
        foreach ($tiers as $tier) {
            $min = isset($tier['qty_min']) ? (float) $tier['qty_min'] : 0;
            $max = isset($tier['qty_max']) && $tier['qty_max'] !== null && $tier['qty_max'] !== ''
                ? (float) $tier['qty_max']
                : null;

            if ($qty >= $min && ($max === null || $qty <= $max)) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * Calcula desglose completo del tramo.
     *
     * @return array{unitario0:float,t0:float,t_after_formula:float|null,t_final:float,unitario:float,qty:float,adjusted:bool}
     */
    public static function explain_tier(array $tier, $p_asignado, $qty) {
        return self::compute_tier($tier, $p_asignado, $qty);
    }

    /**
     * @param array $tier
     * @param float $p_asignado
     * @param float $qty
     * @return float Precio unitario resultante
     */
    public static function apply_tier(array $tier, $p_asignado, $qty = 1.0) {
        $result = self::compute_tier($tier, $p_asignado, $qty);
        return $result['unitario'];
    }

    public static function evaluate(array $tiers, $p_asignado, $qty) {
        $tier = self::select_tier($tiers, $qty);
        if ($tier === null) {
            return null;
        }
        return self::apply_tier($tier, $p_asignado, $qty);
    }

    /**
     * Evalúa regla y devuelve unitario + total de línea (T_final).
     *
     * @return array{price:float|null,total:float|null,breakdown:array|null}
     */
    public static function evaluate_with_total(array $tiers, $p_asignado, $qty) {
        $tier = self::select_tier($tiers, $qty);
        if ($tier === null) {
            return ['price' => null, 'total' => null, 'breakdown' => null];
        }
        $breakdown = self::compute_tier($tier, $p_asignado, $qty);
        return [
            'price' => $breakdown['unitario'],
            'total' => $breakdown['t_final'],
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Cantidad entera (o múltiplos de $step) para un monto total deseado.
     *
     * Dentro de cada tramo el total es no decreciente, así que se busca
     * por binaria. Entre tramos puede bajar: se evalúa cada tramo aparte.
     * Con $step > 1 solo se consideran cantidades múltiplo de step (paquetes).
     *
     * @param int $step Unidades por paquete (>= 1). Resultado qty = k * step.
     * @return array{
     *   debajo: array{qty:int,packs:int,total:float,unitario:float}|null,
     *   arriba: array{qty:int,packs:int,total:float,unitario:float}|null,
     *   exacto: bool,
     *   minimo: array{qty:int,packs:int,total:float,unitario:float}|null
     * }
     */
    public static function qty_for_total(array $tiers, $p_asignado, $monto, $step = 1) {
        $p_asignado = (float) $p_asignado;
        $monto = (float) $monto;
        $step = max(1, (int) $step);
        $empty = [
            'debajo' => null,
            'arriba' => null,
            'exacto' => false,
            'minimo' => null,
        ];
        if ($monto <= 0 || empty($tiers)) {
            return $empty;
        }

        $debajo = null;
        $arriba = null;
        $minimo = null;

        foreach ($tiers as $tier) {
            $tier_lo = max(1, (int) ceil(isset($tier['qty_min']) ? (float) $tier['qty_min'] : 1));
            $has_max = isset($tier['qty_max']) && $tier['qty_max'] !== null && $tier['qty_max'] !== '';
            if ($has_max) {
                $tier_hi = (int) floor((float) $tier['qty_max']);
            } else {
                $tier_hi = self::qty_upper_bound_for_tier($tier, $p_asignado, $monto);
            }
            if ($tier_hi < $tier_lo) {
                continue;
            }

            // Alinear a múltiplos de step dentro del tramo.
            $k_lo = (int) ceil($tier_lo / $step);
            $k_hi = (int) floor($tier_hi / $step);
            if ($k_hi < $k_lo) {
                continue;
            }

            $lo = $k_lo * $step;
            $eval_lo = self::compute_tier($tier, $p_asignado, (float) $lo);
            $cand_min = self::qty_pack_row($lo, $step, (float) $eval_lo['t_final'], (float) $eval_lo['unitario']);
            if ($minimo === null || $cand_min['total'] < $minimo['total']
                || (abs($cand_min['total'] - $minimo['total']) < 0.001 && $cand_min['qty'] < $minimo['qty'])) {
                $minimo = $cand_min;
            }

            // Mayor k en el tramo con total <= monto.
            $best_leq = null;
            $left = $k_lo;
            $right = $k_hi;
            while ($left <= $right) {
                $mid_k = (int) floor(($left + $right) / 2);
                $qty = $mid_k * $step;
                $eval = self::compute_tier($tier, $p_asignado, (float) $qty);
                $total = (float) $eval['t_final'];
                if ($total <= $monto + 0.001) {
                    $best_leq = self::qty_pack_row($qty, $step, $total, (float) $eval['unitario']);
                    $left = $mid_k + 1;
                } else {
                    $right = $mid_k - 1;
                }
            }
            if ($best_leq !== null) {
                if ($debajo === null || $best_leq['qty'] > $debajo['qty']) {
                    $debajo = $best_leq;
                }
            }

            // Menor k en el tramo con total > monto.
            $best_gt = null;
            $left = $k_lo;
            $right = $k_hi;
            while ($left <= $right) {
                $mid_k = (int) floor(($left + $right) / 2);
                $qty = $mid_k * $step;
                $eval = self::compute_tier($tier, $p_asignado, (float) $qty);
                $total = (float) $eval['t_final'];
                if ($total > $monto + 0.001) {
                    $best_gt = self::qty_pack_row($qty, $step, $total, (float) $eval['unitario']);
                    $right = $mid_k - 1;
                } else {
                    $left = $mid_k + 1;
                }
            }
            if ($best_gt !== null) {
                if ($arriba === null
                    || $best_gt['total'] < $arriba['total'] - 0.001
                    || (abs($best_gt['total'] - $arriba['total']) < 0.001 && $best_gt['qty'] < $arriba['qty'])) {
                    $arriba = $best_gt;
                }
            }
        }

        if ($debajo !== null) {
            $exacto = abs($debajo['total'] - $monto) < 0.01;
            if (!$exacto) {
                $next_q = $debajo['qty'] + $step;
                $eval_next = self::evaluate_with_total($tiers, $p_asignado, (float) $next_q);
                if ($eval_next['total'] !== null && (float) $eval_next['total'] > $monto + 0.001) {
                    $cand = self::qty_pack_row($next_q, $step, (float) $eval_next['total'], (float) $eval_next['price']);
                    if ($arriba === null
                        || $cand['total'] < $arriba['total'] - 0.001
                        || (abs($cand['total'] - $arriba['total']) < 0.001 && $cand['qty'] < $arriba['qty'])) {
                        $arriba = $cand;
                    }
                }
            } else {
                $arriba = null;
            }
            return [
                'debajo' => $debajo,
                'arriba' => $exacto ? null : $arriba,
                'exacto' => $exacto,
                'minimo' => $minimo,
            ];
        }

        return [
            'debajo' => null,
            'arriba' => $arriba,
            'exacto' => false,
            'minimo' => $minimo,
        ];
    }

    /**
     * Cantidad de paquetes para que la participación de esta línea ≈ monto.
     * T_linea = T(others + k·step) × (k·step) / (others + k·step).
     *
     * @return array{debajo:?array,arriba:?array,exacto:bool,minimo:?array}
     */
    public static function qty_for_line_share(array $tiers, $p_asignado, $monto, $step = 1, $others_units = 0) {
        $p_asignado = (float) $p_asignado;
        $monto = (float) $monto;
        $step = max(1, (int) $step);
        $others = max(0.0, (float) $others_units);
        $empty = [
            'debajo' => null,
            'arriba' => null,
            'exacto' => false,
            'minimo' => null,
        ];
        if ($monto <= 0 || empty($tiers)) {
            return $empty;
        }
        if ($others <= 0.0001) {
            return self::qty_for_total($tiers, $p_asignado, $monto, $step);
        }

        $debajo = null;
        $arriba = null;
        $minimo = null;
        $max_k = 20000;

        for ($k = 1; $k <= $max_k; $k++) {
            $line_units = $k * $step;
            $family_qty = $others + $line_units;
            $eval = self::evaluate_with_total($tiers, $p_asignado, $family_qty);
            if ($eval['total'] === null) {
                continue;
            }
            $t_family = (float) $eval['total'];
            $t_line = round($t_family * $line_units / $family_qty, 2);
            $unitario = $line_units > 0 ? round($t_line / $line_units, 4) : 0.0;
            $row = self::qty_pack_row($line_units, $step, $t_line, $unitario);

            if ($minimo === null) {
                $minimo = $row;
            }

            if ($t_line <= $monto + 0.001) {
                $debajo = $row;
                continue;
            }
            if ($arriba === null) {
                $arriba = $row;
            }
            // Una vez que se pasó el monto, seguir un poco por si hay tramos que bajen.
            if ($debajo !== null && $k > $debajo['packs'] + 500) {
                break;
            }
        }

        if ($debajo !== null) {
            $exacto = abs($debajo['total'] - $monto) < 0.01;
            return [
                'debajo' => $debajo,
                'arriba' => $exacto ? null : $arriba,
                'exacto' => $exacto,
                'minimo' => $minimo,
            ];
        }

        return [
            'debajo' => null,
            'arriba' => $arriba,
            'exacto' => false,
            'minimo' => $minimo,
        ];
    }

    private static function qty_pack_row($qty_units, $step, $total, $unitario) {
        $qty = (int) $qty_units;
        $step = max(1, (int) $step);
        return [
            'qty' => $qty,
            'packs' => (int) floor($qty / $step),
            'total' => (float) $total,
            'unitario' => (float) $unitario,
        ];
    }

    /**
     * Límite superior de búsqueda para tramos abiertos (sin qty_max).
     */
    private static function qty_upper_bound_for_tier(array $tier, $p_asignado, $monto) {
        $p_asignado = (float) $p_asignado;
        $monto = (float) $monto;
        $lo = max(1, (int) ceil(isset($tier['qty_min']) ? (float) $tier['qty_min'] : 1));

        // Estimación: unitario mínimo plausible ≈ max(0.01, P) o resultado a Q=lo.
        $eval_lo = self::compute_tier($tier, $p_asignado, (float) $lo);
        $unit = max(0.01, (float) $eval_lo['unitario']);
        $est = (int) ceil($monto / $unit) + 50;
        $hi = min(1000000, max($lo, $est));

        // Ampliar si aún cabe en el monto (tope / redondeos pueden bajar el unitario).
        $guard = 0;
        while ($guard < 20) {
            $guard++;
            $eval = self::compute_tier($tier, $p_asignado, (float) $hi);
            if ((float) $eval['t_final'] > $monto + 0.001) {
                break;
            }
            $next = min(1000000, $hi * 2);
            if ($next <= $hi) {
                break;
            }
            $hi = $next;
        }
        return $hi;
    }

    /**
     * @param string $formula
     * @param float  $p_asignado
     * @param float|null $t_total
     * @param bool   $allow_total_var
     */
    public static function evaluate_formula($formula, $p_asignado, $t_total = null, $allow_total_var = false) {
        $formula = self::sanitize_formula($formula);
        if ($formula === '') {
            throw new InvalidArgumentException('Fórmula vacía');
        }
        if (strlen($formula) > self::FORMULA_MAX_LEN) {
            throw new InvalidArgumentException('Fórmula demasiado larga');
        }

        self::$allow_total_var = (bool) $allow_total_var;

        $tokens = self::tokenize($formula);
        if (count($tokens) > self::FORMULA_MAX_TOKENS) {
            throw new InvalidArgumentException('Fórmula demasiado compleja');
        }

        $pos = 0;
        $ast = self::parse_expr($tokens, $pos, 0);
        if (!isset($tokens[$pos]) || $tokens[$pos]['type'] !== 'eof') {
            $got = isset($tokens[$pos]) ? $tokens[$pos]['value'] : '';
            throw new InvalidArgumentException('Fórmula incompleta o carácter sobrante' . ($got !== '' && $got !== null ? ': ' . $got : ''));
        }

        $ctx = [
            'p' => (float) $p_asignado,
            't' => $t_total !== null ? (float) $t_total : 0.0,
        ];

        return (float) self::eval_ast($ast, $ctx);
    }

    private static function compute_tier(array $tier, $p_asignado, $qty) {
        $p_asignado = (float) $p_asignado;
        $qty = (float) $qty;
        if ($qty <= 0) {
            $qty = 1.0;
        }

        $unitario0 = self::compute_unitario0($tier, $p_asignado);
        if (isset($tier['total_minimo']) && $tier['total_minimo'] !== null && $tier['total_minimo'] !== '') {
            $unitario0 = max($unitario0, (float) $tier['total_minimo']);
        }

        $t0 = round($unitario0 * $qty, 2);
        $formula_total = isset($tier['formula_total']) ? self::sanitize_formula($tier['formula_total']) : '';
        $piso_total = (isset($tier['piso_total']) && $tier['piso_total'] !== null && $tier['piso_total'] !== '')
            ? (float) $tier['piso_total'] : null;
        $delta_max = self::compute_delta_max($tier, $p_asignado);

        $has_total_stage = ($formula_total !== '') || ($piso_total !== null) || ($delta_max !== null);

        if (!$has_total_stage) {
            $unitario = round($unitario0, 2);
            return [
                'unitario0' => round($unitario0, 4),
                't0' => round($unitario * $qty, 2),
                'base_sin_cambios' => null,
                'delta_max' => null,
                't_tope' => null,
                'topado' => false,
                't_after_formula' => null,
                't_final' => round($unitario * $qty, 2),
                'unitario' => $unitario,
                'qty' => $qty,
                'adjusted' => false,
            ];
        }

        $t_work = $t0;
        $base_sin_cambios = null;
        $t_tope = null;
        $topado = false;
        if ($delta_max !== null) {
            $base_sin_cambios = round($p_asignado * $qty, 2);
            $t_tope = round($base_sin_cambios + $delta_max, 2);
            if ($t0 > $t_tope) {
                $t_work = $t_tope;
                $topado = true;
            }
        }

        $t_after_formula = null;
        if ($formula_total !== '') {
            try {
                $t_after_formula = self::evaluate_formula($formula_total, $p_asignado, $t_work, true);
                $t_work = round($t_after_formula, 2);
            } catch (Exception $e) {
                $t_after_formula = null;
            }
        }

        $t_final = $t_work;
        if ($piso_total !== null) {
            $t_final = max($t_work, $piso_total);
        }
        $t_final = round($t_final, 2);

        $unitario = round($t_final / $qty, 4);

        return [
            'unitario0' => round($unitario0, 4),
            't0' => $t0,
            'base_sin_cambios' => $base_sin_cambios,
            'delta_max' => $delta_max !== null ? round($delta_max, 2) : null,
            't_tope' => $t_tope,
            'topado' => $topado,
            't_after_formula' => $t_after_formula !== null ? round($t_after_formula, 2) : null,
            't_final' => $t_final,
            'unitario' => $unitario,
            'qty' => $qty,
            'adjusted' => true,
        ];
    }

    /**
     * Alza máxima permitida del total sobre P × Q (fórmula en P o número fijo).
     *
     * @return float|null null si el tramo no tiene tope
     */
    private static function compute_delta_max(array $tier, $p_asignado) {
        $txt = isset($tier['max_delta_t']) ? self::sanitize_formula($tier['max_delta_t']) : '';
        if ($txt === '') {
            return null;
        }
        try {
            return max(0.0, (float) self::evaluate_formula($txt, $p_asignado, null, false));
        } catch (Exception $e) {
            return null;
        }
    }

    private static function compute_unitario0(array $tier, $p_asignado) {
        $formula_txt = isset($tier['formula']) ? self::sanitize_formula($tier['formula']) : '';

        if ($formula_txt !== '') {
            try {
                return (float) self::evaluate_formula($formula_txt, $p_asignado, null, false);
            } catch (Exception $e) {
                return self::apply_structured_tier($tier, $p_asignado);
            }
        }

        return self::apply_structured_tier($tier, $p_asignado);
    }

    private static function apply_structured_tier(array $tier, $p_asignado) {
        $formula = isset($tier['formula_tipo']) ? $tier['formula_tipo'] : 'multiplicador';
        $multiplicador = isset($tier['multiplicador']) && $tier['multiplicador'] !== null && $tier['multiplicador'] !== ''
            ? (float) $tier['multiplicador'] : 1.0;
        $addendo = isset($tier['addendo']) && $tier['addendo'] !== null && $tier['addendo'] !== ''
            ? (float) $tier['addendo'] : 0.0;
        $redondeo = isset($tier['redondeo']) ? $tier['redondeo'] : 'ninguno';

        switch ($formula) {
            case 'suma':
                $precio = $p_asignado + $addendo;
                break;
            case 'rango':
            case 'multiplicador':
            case 'formula':
            default:
                $precio = $p_asignado * $multiplicador;
                break;
        }

        switch ($redondeo) {
            case 'techo_decena':
                $precio = self::techo_decena($precio);
                break;
            case 'techo_cincuentena':
                $precio = self::techo_cincuentena($precio);
                break;
            case 'techo_centena':
            case 'techo_centana':
                $precio = self::techo_centena($precio);
                break;
        }

        return $precio;
    }

    private static function format_num($n) {
        $n = (float) $n;
        if (floor($n) == $n) {
            return (string) (int) $n;
        }
        $s = rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * @return array<int, array{type:string,value:mixed}>
     */
    private static function tokenize($formula) {
        $tokens = [];
        $len = strlen($formula);
        $i = 0;

        while ($i < $len) {
            $c = $formula[$i];
            if ($c === ' ' || $c === "\t") {
                $i++;
                continue;
            }

            if ($c === '.' || ctype_digit($c)) {
                if (preg_match('/\d+(?:\.\d+)?|\.\d+/', $formula, $m, 0, $i)) {
                    $tokens[] = ['type' => 'num', 'value' => (float) $m[0]];
                    $i += strlen($m[0]);
                    continue;
                }
            }

            if (ctype_alpha($c) || $c === '_') {
                if (preg_match('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $m, 0, $i)) {
                    $name = $m[0];
                    $i += strlen($name);
                    $j = $i;
                    while ($j < $len && ($formula[$j] === ' ' || $formula[$j] === "\t")) {
                        $j++;
                    }
                    if ($j < $len && $formula[$j] === '(') {
                        $tokens[] = ['type' => 'func', 'value' => strtolower($name)];
                    } else {
                        $tokens[] = ['type' => 'id', 'value' => strtolower($name)];
                    }
                    continue;
                }
            }

            if (strpos('+-*/(),', $c) !== false) {
                $tokens[] = ['type' => $c, 'value' => $c];
                $i++;
                continue;
            }

            throw new InvalidArgumentException('Carácter no permitido en la fórmula: ' . $c);
        }

        $tokens[] = ['type' => 'eof', 'value' => null];
        return $tokens;
    }

    private static function parse_expr(array $tokens, &$pos, $depth) {
        self::assert_depth($depth);
        $left = self::parse_term($tokens, $pos, $depth);
        while (self::tok($tokens, $pos, '+') || self::tok($tokens, $pos, '-')) {
            $op = $tokens[$pos]['type'];
            $pos++;
            $right = self::parse_term($tokens, $pos, $depth);
            $left = ['kind' => 'bin', 'op' => $op, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private static function parse_term(array $tokens, &$pos, $depth) {
        $left = self::parse_unary($tokens, $pos, $depth);
        while (self::tok($tokens, $pos, '*') || self::tok($tokens, $pos, '/')) {
            $op = $tokens[$pos]['type'];
            $pos++;
            $right = self::parse_unary($tokens, $pos, $depth);
            $left = ['kind' => 'bin', 'op' => $op, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private static function parse_unary(array $tokens, &$pos, $depth) {
        self::assert_depth($depth);
        if (self::tok($tokens, $pos, '+')) {
            $pos++;
            return self::parse_unary($tokens, $pos, $depth + 1);
        }
        if (self::tok($tokens, $pos, '-')) {
            $pos++;
            return ['kind' => 'neg', 'arg' => self::parse_unary($tokens, $pos, $depth + 1)];
        }
        return self::parse_primary($tokens, $pos, $depth);
    }

    private static function parse_primary(array $tokens, &$pos, $depth) {
        $tok = $tokens[$pos] ?? ['type' => 'eof', 'value' => null];

        if ($tok['type'] === 'num') {
            $pos++;
            return ['kind' => 'num', 'value' => (float) $tok['value']];
        }

        if ($tok['type'] === 'id') {
            $pos++;
            $name = (string) $tok['value'];
            if (in_array($name, ['p', 'p_asignado', 'precio'], true)) {
                return ['kind' => 'p'];
            }
            if (in_array($name, ['t', 'total'], true)) {
                if (!self::$allow_total_var) {
                    throw new InvalidArgumentException('T (total de línea) solo se usa en la fórmula de total, no en la de unitario');
                }
                return ['kind' => 't'];
            }
            throw new InvalidArgumentException('Identificador no permitido: ' . $name);
        }

        if ($tok['type'] === 'func') {
            $fname = (string) $tok['value'];
            if (!isset(self::FUNCS[$fname])) {
                throw new InvalidArgumentException('Función no permitida: ' . $fname . '()');
            }
            $pos++;
            if (!self::tok($tokens, $pos, '(')) {
                throw new InvalidArgumentException('Se esperaba ( tras ' . $fname);
            }
            $pos++;
            $args = [];
            if (!self::tok($tokens, $pos, ')')) {
                $args[] = self::parse_expr($tokens, $pos, $depth + 1);
                while (self::tok($tokens, $pos, ',')) {
                    $pos++;
                    $args[] = self::parse_expr($tokens, $pos, $depth + 1);
                }
            }
            if (!self::tok($tokens, $pos, ')')) {
                throw new InvalidArgumentException('Falta ) en ' . $fname . '()');
            }
            $pos++;

            $meta = self::FUNCS[$fname];
            $arity = (int) $meta['arity'];
            if ($arity === 1 && count($args) !== 1) {
                throw new InvalidArgumentException($fname . '() requiere 1 argumento');
            }
            if ($arity === 0 && count($args) < 2) {
                throw new InvalidArgumentException($fname . '() requiere al menos 2 argumentos');
            }

            return ['kind' => 'call', 'fn' => $meta['alias_of'], 'args' => $args];
        }

        if ($tok['type'] === '(') {
            $pos++;
            $inner = self::parse_expr($tokens, $pos, $depth + 1);
            if (!self::tok($tokens, $pos, ')')) {
                throw new InvalidArgumentException('Falta ) de cierre');
            }
            $pos++;
            return $inner;
        }

        throw new InvalidArgumentException('Expresión inválida cerca de: ' . (string) ($tok['value'] ?? 'fin'));
    }

    private static function tok(array $tokens, $pos, $type) {
        return isset($tokens[$pos]) && $tokens[$pos]['type'] === $type;
    }

    private static function assert_depth($depth) {
        if ($depth > self::FORMULA_MAX_DEPTH) {
            throw new InvalidArgumentException('Fórmula demasiado anidada');
        }
    }

    private static function eval_ast(array $node, array $ctx) {
        $kind = $node['kind'] ?? '';
        switch ($kind) {
            case 'num':
                return (float) $node['value'];
            case 'p':
                return (float) $ctx['p'];
            case 't':
                return (float) $ctx['t'];
            case 'neg':
                return -1 * self::eval_ast($node['arg'], $ctx);
            case 'bin':
                $left = self::eval_ast($node['left'], $ctx);
                $right = self::eval_ast($node['right'], $ctx);
                if ($node['op'] === '+') {
                    return $left + $right;
                }
                if ($node['op'] === '-') {
                    return $left - $right;
                }
                if ($node['op'] === '*') {
                    return $left * $right;
                }
                if (abs($right) < 1e-12) {
                    throw new InvalidArgumentException('División por cero');
                }
                return $left / $right;
            case 'call':
                $vals = [];
                foreach ($node['args'] as $arg) {
                    $vals[] = self::eval_ast($arg, $ctx);
                }
                $fn = $node['fn'];
                if ($fn === 't10') {
                    return self::techo_decena($vals[0]);
                }
                if ($fn === 't50') {
                    return self::techo_cincuentena($vals[0]);
                }
                if ($fn === 't100') {
                    return self::techo_centena($vals[0]);
                }
                if ($fn === 'max') {
                    return max($vals);
                }
                if ($fn === 'min') {
                    return min($vals);
                }
                throw new InvalidArgumentException('Función no soportada');
            default:
                throw new InvalidArgumentException('Nodo de fórmula inválido');
        }
    }
}
