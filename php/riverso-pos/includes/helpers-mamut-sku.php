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
    if (isset($map[$code])) {
        return $map[$code];
    }
    $upper = strtoupper($code);
    return $map[$upper] ?? null;
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
 * ¿Es confiable usar este SKU local para un código proveedor?
 *
 * - Mamut mapping manda cuando existe.
 * - Sin Mamut, no se confía en SKU solo numérico para códigos alfanuméricos
 *   salvo revisión humana aprobada en dominio.
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

    $mamut = riverso_mamut_online_to_local_sku($code);
    if ($mamut !== null) {
        return strcasecmp($mamut, $local) === 0;
    }

    if (preg_match('/[A-Za-z]/', $code) && preg_match('/^\d+$/', $local)) {
        $review = null;
        if (is_array($lookup) && !empty($lookup['domain']['human_product_review'])) {
            $review = $lookup['domain']['human_product_review'];
        }
        return $review === 'approved';
    }

    return true;
}
