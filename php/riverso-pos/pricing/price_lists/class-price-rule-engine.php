<?php
/**
 * Motor de evaluación de reglas de precio por tramos - Riverso POS.
 *
 * Evaluación pura (sin acceso a BD y sin eval) de reglas modeladas como tramos
 * de cantidad. Cada tramo deriva el precio unitario a partir del precio
 * asignado (P / p_asignado).
 *
 * Modo fórmula (preferido):
 *   Expresión tipo calculadora, p.ej. T10(P*3), T50(P+4), MAX(T100(P*1.7), 30)
 *
 *   Atajos visuales:
 *     T10()  = techo_decena      ceil(x / 10)  * 10
 *     T50()  = techo_cincuentena ceil(x / 50)  * 50
 *     T100() = techo_centena     ceil(x / 100) * 100
 *
 *   También se aceptan los nombres largos: techo_decena(), techo_cincuentena(),
 *   techo_centena() y el alias techo_centana().
 *
 * Modo estructurado (legado):
 *   - multiplicador: precio = P * multiplicador
 *   - suma:          precio = P + addendo
 *   - rango:         precio = P * multiplicador
 *
 * total_minimo: piso de precio unitario para el tramo (después de la fórmula).
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

    /**
     * Funciones permitidas (nombre canónico => metadatos).
     * Las claves son minúsculas.
     */
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

    /**
     * Redondea al techo de la decena superior.
     */
    public static function techo_decena($valor) {
        return self::techo_multiplo($valor, 10);
    }

    /**
     * Redondea al techo de la cincuentena superior.
     */
    public static function techo_cincuentena($valor) {
        return self::techo_multiplo($valor, 50);
    }

    /**
     * Redondea al techo de la centena superior.
     */
    public static function techo_centena($valor) {
        return self::techo_multiplo($valor, 100);
    }

    /**
     * Alias de techo_centena (grafía frecuente).
     */
    public static function techo_centana($valor) {
        return self::techo_centena($valor);
    }

    /**
     * Techo al múltiplo superior indicado (10 / 50 / 100).
     */
    public static function techo_multiplo($valor, $multiplo) {
        $valor = (float) $valor;
        $multiplo = (float) $multiplo;
        if ($valor <= 0 || $multiplo <= 0) {
            return 0.0;
        }
        return (float) (ceil($valor / $multiplo) * $multiplo);
    }

    /**
     * Limpia una fórmula para almacenamiento (sin HTML, recortada).
     */
    public static function sanitize_formula($formula) {
        $formula = wp_strip_all_tags((string) $formula);
        $formula = str_replace(["\r", "\n", "\t"], '', $formula);
        return trim($formula);
    }

    /**
     * Valida una fórmula. Cadena vacía es válida (se usa el modo legado).
     *
     * @return true|WP_Error
     */
    public static function validate_formula($formula) {
        $formula = self::sanitize_formula($formula);
        if ($formula === '') {
            return true;
        }
        if (strlen($formula) > self::FORMULA_MAX_LEN) {
            return new WP_Error('too_long', 'La fórmula no puede superar ' . self::FORMULA_MAX_LEN . ' caracteres');
        }
        try {
            self::evaluate_formula($formula, 10.0);
            return true;
        } catch (Exception $e) {
            return new WP_Error('invalid_formula', $e->getMessage());
        }
    }

    /**
     * Reconstruye una fórmula visual a partir de un tramo legado
     * (multiplicador / suma / redondeo) si aún no hay texto de fórmula.
     */
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

    /**
     * Selecciona el tramo aplicable según la cantidad.
     *
     * @param array $tiers Lista de tramos (cada uno: qty_min, qty_max, ...)
     * @param float $qty
     * @return array|null
     */
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
     * Aplica un tramo concreto a un precio asignado.
     *
     * @param array $tier
     * @param float $p_asignado
     * @return float Precio unitario resultante
     */
    public static function apply_tier(array $tier, $p_asignado) {
        $p_asignado = (float) $p_asignado;
        $formula_txt = isset($tier['formula']) ? self::sanitize_formula($tier['formula']) : '';

        if ($formula_txt !== '') {
            try {
                $precio = self::evaluate_formula($formula_txt, $p_asignado);
            } catch (Exception $e) {
                $precio = self::apply_structured_tier($tier, $p_asignado);
            }
        } else {
            $precio = self::apply_structured_tier($tier, $p_asignado);
        }

        if (isset($tier['total_minimo']) && $tier['total_minimo'] !== null && $tier['total_minimo'] !== '') {
            $precio = max($precio, (float) $tier['total_minimo']);
        }

        return round($precio, 2);
    }

    /**
     * Evalúa una regla completa: selecciona el tramo por cantidad y aplica la fórmula.
     *
     * @param array $tiers      Tramos ordenados por 'orden'
     * @param float $p_asignado Precio asignado base
     * @param float $qty        Cantidad (puede ser agregada de lotes equivalentes)
     * @return float|null       Precio unitario o null si no hay tramo aplicable
     */
    public static function evaluate(array $tiers, $p_asignado, $qty) {
        $tier = self::select_tier($tiers, $qty);
        if ($tier === null) {
            return null;
        }
        return self::apply_tier($tier, $p_asignado);
    }

    /**
     * Evalúa una fórmula tipo calculadora. Sin eval()/create_function().
     *
     * @param string $formula
     * @param float  $p_asignado
     * @return float
     * @throws InvalidArgumentException
     */
    public static function evaluate_formula($formula, $p_asignado) {
        $formula = self::sanitize_formula($formula);
        if ($formula === '') {
            throw new InvalidArgumentException('Fórmula vacía');
        }
        if (strlen($formula) > self::FORMULA_MAX_LEN) {
            throw new InvalidArgumentException('Fórmula demasiado larga');
        }

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

        return (float) self::eval_ast($ast, (float) $p_asignado);
    }

    /**
     * Modo legado: multiplicador / suma / rango + redondeo de columna.
     */
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
            if (!in_array($name, ['p', 'p_asignado', 'precio'], true)) {
                throw new InvalidArgumentException('Identificador no permitido: ' . $name . ' (usa P = precio asignado)');
            }
            return ['kind' => 'p'];
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

    private static function eval_ast(array $node, $p_asignado) {
        $kind = $node['kind'] ?? '';
        switch ($kind) {
            case 'num':
                return (float) $node['value'];
            case 'p':
                return (float) $p_asignado;
            case 'neg':
                return -1 * self::eval_ast($node['arg'], $p_asignado);
            case 'bin':
                $left = self::eval_ast($node['left'], $p_asignado);
                $right = self::eval_ast($node['right'], $p_asignado);
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
                    $vals[] = self::eval_ast($arg, $p_asignado);
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
