<?php
/**
 * Mapeo SKU online Mamut / proveedor → SKU local de catálogo.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rutas candidatas para sku_mapping.json (online → local).
 */
function riverso_mamut_mapping_paths() {
    $paths = [
        RIVERSO_POS_PLUGIN_DIR . 'data/sku_mapping.json',
    ];
    $repo = realpath(RIVERSO_POS_PLUGIN_DIR . '../../data/sku_mapping.json');
    if ($repo) {
        $paths[] = $repo;
    }
    return $paths;
}

/**
 * Carga el mapa Mamut (código online → SKU local numérico).
 *
 * @return array<string, string>
 */
function riverso_load_mamut_sku_mapping() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach (riverso_mamut_mapping_paths() as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $json = json_decode(file_get_contents($path), true);
        if (is_array($json)) {
            foreach ($json as $online => $local) {
                $online = trim((string) $online);
                $local = trim((string) $local);
                if ($online !== '' && $local !== '') {
                    $cache[$online] = $local;
                    $cache[strtoupper($online)] = $local;
                }
            }
            break;
        }
    }

    return $cache;
}

/**
 * Convierte código online Mamut / proveedor a SKU local.
 */
function riverso_mamut_online_to_local_sku($online_code) {
    $code = trim((string) $online_code);
    if ($code === '') {
        return null;
    }

    $map = riverso_load_mamut_sku_mapping();
    $mapped = null;
    if (isset($map[$code])) {
        $mapped = $map[$code];
    } else {
        $mapped = $map[strtoupper($code)] ?? null;
    }

    // "04TRC": "04TRC" es un placeholder de código sin SKU local (sin usar).
    return riverso_usable_local_sku($mapped, $code);
}

/**
 * Indica si un valor parece ser el código proveedor/online y no el SKU local.
 */
function riverso_sku_equals_supplier_code($sku, $supplier_code) {
    if ($sku === null || $sku === '' || $supplier_code === null || $supplier_code === '') {
        return false;
    }
    return strcasecmp(trim((string) $sku), trim((string) $supplier_code)) === 0;
}

/**
 * SKU local usable para vincular: no vacío y distinto del código proveedor.
 */
function riverso_usable_local_sku($local_sku, $supplier_code = '') {
    $local = trim((string) ($local_sku ?? ''));
    if ($local === '') {
        return null;
    }
    if ($supplier_code !== '' && riverso_sku_equals_supplier_code($local, $supplier_code)) {
        return null;
    }
    return $local;
}

/**
 * ¿El vínculo de dominio fue confirmado (humano o match VERIFIED)?
 */
function riverso_supplier_mapping_is_verified($lookup) {
    if (!is_array($lookup)) {
        return false;
    }
    $domain = isset($lookup['domain']) && is_array($lookup['domain']) ? $lookup['domain'] : $lookup;
    $review = strtolower(trim((string) ($domain['human_product_review'] ?? '')));
    if ($review === 'approved') {
        return true;
    }
    $match = strtoupper(trim((string) ($domain['match_estado'] ?? '')));
    return $match === 'VERIFIED';
}

/**
 * ¿Es confiable usar este SKU local para un código proveedor?
 *
 * - Un mapeo VERIFIED (humano) es autoritativo, incluso si difiere de Mamut.
 * - Sin mapeo verificado, el JSON Mamut queda como sugerencia de catálogo.
 * - Un match UNMATCHED/REJECTED/HUMAN_REVIEW no autoriza SKU en facturas.
 * - Sin Mamut, un código numérico de proveedor no se toma como SKU local
 *   salvo revisión humana o match VERIFIED. Evita casos como Andina 120437 → 853.
 */
function riverso_is_trusted_supplier_local_sku($supplier_code, $local_sku, $lookup = null) {
    $code = trim((string) $supplier_code);
    $local = trim((string) $local_sku);
    if ($code === '' || $local === '') {
        return false;
    }
    if (riverso_sku_equals_supplier_code($local, $code)) {
        return false;
    }

    $domain = [];
    if (is_array($lookup)) {
        $domain = isset($lookup['domain']) && is_array($lookup['domain']) ? $lookup['domain'] : $lookup;
    }
    $match = strtoupper(trim((string) ($domain['match_estado'] ?? '')));
    $canonical = trim((string) ($domain['canonical_sku'] ?? ''));

    if (riverso_supplier_mapping_is_verified($lookup)) {
        return $canonical === '' || strcasecmp($canonical, $local) === 0;
    }

    $mamut = riverso_mamut_online_to_local_sku($code);
    if ($mamut !== null) {
        return strcasecmp($mamut, $local) === 0;
    }

    if (in_array($match, ['UNMATCHED', 'REJECTED', 'HUMAN_REVIEW'], true)) {
        return false;
    }

    // Código proveedor numérico ≠ SKU local numérico: exige confirmación.
    if (preg_match('/^\d+$/', $code) && preg_match('/^\d+$/', $local)) {
        return false;
    }

    if (preg_match('/[A-Za-z]/', $code) && preg_match('/^\d+$/', $local)) {
        return false;
    }

    return true;
}
