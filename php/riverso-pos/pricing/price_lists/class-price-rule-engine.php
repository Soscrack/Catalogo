<?php
/**
 * Motor de evaluación de reglas de precio por tramos - Riverso POS.
 *
 * Pipeline por tramo:
 *   1. unitario0 = fórmula(P) + piso unitario (total_minimo)
 *   2. T0 = unitario0 × Q
 *   3. T1 = fórmula_total(T) si existe (T = total de línea antes del ajuste)
 *   4. T_final = max(T1, piso_total) si hay piso de total
 *   5. unitario = T_final / Q (hasta 4 decimales si hubo ajuste de total)
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

        $has_total_stage = ($formula_total !== '') || ($piso_total !== null);

        if (!$has_total_stage) {
            $unitario = round($unitario0, 2);
            return [
                'unitario0' => round($unitario0, 4),
                't0' => round($unitario * $qty, 2),
                't_after_formula' => null,
                't_final' => round($unitario * $qty, 2),
                'unitario' => $unitario,
                'qty' => $qty,
                'adjusted' => false,
            ];
        }

        $t_work = $t0;
        $t_after_formula = null;
        if ($formula_total !== '') {
            try {
                $t_after_formula = self::evaluate_formula($formula_total, $p_asignado, $t0, true);
                $t_work = round($t_after_formula, 2);
            } catch (Exception $e) {
                $t_work = $t0;
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
            't_after_formula' => $t_after_formula !== null ? round($t_after_formula, 2) : null,
            't_final' => $t_final,
            'unitario' => $unitario,
            'qty' => $qty,
            'adjusted' => true,
        ];
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
