<?php
/**
 * Motor de evaluación de reglas de precio por tramos - Riverso POS.
 *
 * Evaluación pura (sin acceso a BD y sin eval) de reglas modeladas como tramos
 * de cantidad. Cada tramo define cómo derivar el precio unitario a partir del
 * precio asignado (p_asignado):
 *
 *   - multiplicador: precio = p_asignado * multiplicador
 *   - suma:          precio = p_asignado + addendo
 *   - rango:         precio = p_asignado * multiplicador (valor representativo)
 *
 * Redondeos soportados (whitelist):
 *   - techo_decena: ceil(precio / 10) * 10
 *   - ninguno:      sin redondeo
 *
 * total_minimo: piso de precio unitario para el tramo (ej. "si total < 30 usar 30").
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Price_Rule_Engine {

    const FORMULAS = ['multiplicador', 'suma', 'rango'];
    const REDONDEOS = ['ninguno', 'techo_decena'];

    /**
     * Redondea al techo de la decena superior.
     */
    public static function techo_decena($valor) {
        if ($valor <= 0) {
            return 0.0;
        }
        return (float) (ceil($valor / 10) * 10);
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
        $formula = isset($tier['formula_tipo']) ? $tier['formula_tipo'] : 'multiplicador';
        $multiplicador = isset($tier['multiplicador']) && $tier['multiplicador'] !== null
            ? (float) $tier['multiplicador'] : 1.0;
        $addendo = isset($tier['addendo']) && $tier['addendo'] !== null ? (float) $tier['addendo'] : 0.0;
        $redondeo = isset($tier['redondeo']) ? $tier['redondeo'] : 'ninguno';

        switch ($formula) {
            case 'suma':
                $precio = $p_asignado + $addendo;
                break;
            case 'rango':
                $precio = $p_asignado * $multiplicador;
                break;
            case 'multiplicador':
            default:
                $precio = $p_asignado * $multiplicador;
                break;
        }

        if ($redondeo === 'techo_decena') {
            $precio = self::techo_decena($precio);
        }

        // Piso de precio unitario para el tramo.
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
}
