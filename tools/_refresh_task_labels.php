<?php
require_once '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$wpdb->query('SET SESSION wait_timeout = 600');

if (!class_exists('Riverso_Task_Module') || !method_exists('Riverso_Task_Module', 'refresh_open_product_task_labels')) {
    fwrite(STDERR, "Falta refresh_open_product_task_labels\n");
    exit(1);
}

$stats = [];

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.titulo = LEFT(CONCAT('¿Necesita familia \"', pb.nombre_canonico, '\"?'), 255),
         t.descripcion = CONCAT('Indica si el producto \"', pb.nombre_canonico, '\" debe pertenecer a una familia de equivalencia o queda solo.')
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'preguntar_familia'
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['preguntar_familia'] = (int) $wpdb->rows_affected;

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.titulo = LEFT(CONCAT('Asignar familia a \"', pb.nombre_canonico, '\"'), 255),
         t.descripcion = CONCAT('El producto \"', pb.nombre_canonico, '\" requiere familia. Asignarlo como miembro o producto unitario.')
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'asignar_familia'
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['asignar_familia'] = (int) $wpdb->rows_affected;

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.titulo = LEFT(CONCAT('Crear o asignar contraparte online para \"', pb.nombre_canonico, '\"'), 255),
         t.descripcion = CONCAT(
            'Producto local \"', pb.nombre_canonico, '\" (SKU ',
            IF(TRIM(IFNULL(pb.canonical_sku,'')) = '', '—', pb.canonical_sku),
            ') sin vínculo WooCommerce. Crear nuevo producto online o asignar uno existente.'
         )
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'crear_contraparte_online'
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['crear_contraparte_online'] = (int) $wpdb->rows_affected;

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.titulo = LEFT(CONCAT('Asignar código proveedor a \"', pb.nombre_canonico, '\"'), 255),
         t.descripcion = CONCAT(
            'Producto \"', pb.nombre_canonico, '\" (SKU ',
            IF(TRIM(IFNULL(pb.canonical_sku,'')) = '', '—', pb.canonical_sku),
            ') ya tiene contraparte online, pero falta código proveedor.'
         )
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'relacionar_producto_proveedor'
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['relacionar_producto_proveedor'] = (int) $wpdb->rows_affected;

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.descripcion = CONCAT(
            'El producto \\'', pb.nombre_canonico, '\\' (SKU: ',
            IF(TRIM(IFNULL(pb.canonical_sku,'')) = '', '—', pb.canonical_sku),
            ') no tiene código de barra asignado.\\n\\nEscanear código de barra del producto y vincularlo.'
         )
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'barcode_faltante'
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['barcode_faltante'] = (int) $wpdb->rows_affected;

$wpdb->query(
    "UPDATE {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = t.referencia_id
      AND t.referencia_tipo IN ('producto_base','producto')
     SET t.datos_extra = JSON_SET(t.datos_extra, '$.product_name', pb.nombre_canonico)
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.datos_extra IS NOT NULL
       AND JSON_EXTRACT(t.datos_extra, '$.product_name') IS NOT NULL
       AND JSON_UNQUOTE(JSON_EXTRACT(t.datos_extra, '$.product_name')) <> pb.nombre_canonico
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''"
);
$stats['datos_extra_product_name'] = (int) $wpdb->rows_affected;

$legacy = $wpdb->get_results(
    "SELECT t.id, t.descripcion, t.datos_extra, pb.nombre_canonico
     FROM {$p}tareas t
     INNER JOIN {$p}producto_base pb
       ON pb.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(t.datos_extra, '$.producto_base_id')) AS UNSIGNED)
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.tipo = 'confirmar_barcode_legacy'
       AND t.datos_extra IS NOT NULL
       AND JSON_EXTRACT(t.datos_extra, '$.producto_base_id') IS NOT NULL
       AND TRIM(IFNULL(pb.nombre_canonico,'')) <> ''",
    ARRAY_A
) ?: [];

$legacy_updated = 0;
foreach ($legacy as $row) {
    $label = (string) $row['nombre_canonico'];
    $patch = [];
    $desc = (string) ($row['descripcion'] ?? '');
    if ($desc !== '' && strpos($desc, $label) === false) {
        $patched = preg_replace(
            '/del producto "[^"]*"/u',
            'del producto "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $label) . '"',
            $desc,
            1
        );
        if (is_string($patched) && $patched !== $desc) {
            $patch['descripcion'] = $patched;
        }
    }
    $decoded = json_decode((string) ($row['datos_extra'] ?? ''), true);
    if (is_array($decoded) && isset($decoded['product_name']) && $decoded['product_name'] !== $label) {
        $decoded['product_name'] = $label;
        $patch['datos_extra'] = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
    if (!$patch) {
        continue;
    }
    $ok = $wpdb->update("{$p}tareas", $patch, ['id' => (int) $row['id']]);
    if ($ok !== false) {
        $legacy_updated++;
    }
}
$stats['confirmar_barcode_legacy'] = $legacy_updated;

$sku20693 = $wpdb->get_results(
    "SELECT t.id, t.tipo, t.titulo, t.estado, pb.canonical_sku, pb.nombre_canonico
     FROM {$p}tareas t
     INNER JOIN {$p}producto_base pb ON pb.id = t.referencia_id
     WHERE t.referencia_tipo IN ('producto_base','producto')
       AND pb.canonical_sku = '20693'
     ORDER BY t.id DESC
     LIMIT 10",
    ARRAY_A
);

$stale = $wpdb->get_results(
    "SELECT t.id, t.tipo, t.titulo, pb.canonical_sku, pb.nombre_canonico
     FROM {$p}tareas t
     INNER JOIN {$p}producto_base pb ON pb.id = t.referencia_id
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.referencia_tipo IN ('producto_base','producto')
       AND pb.nombre_canonico IS NOT NULL AND pb.nombre_canonico <> ''
       AND t.titulo NOT LIKE CONCAT('%', REPLACE(REPLACE(pb.nombre_canonico, '%', '\\%'), '_', '\\_'), '%')
       AND t.tipo IN ('preguntar_familia','asignar_familia','crear_contraparte_online','relacionar_producto_proveedor')
     LIMIT 20",
    ARRAY_A
);

$old_half = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}tareas t
     INNER JOIN {$p}producto_base pb ON pb.id = t.referencia_id
     WHERE t.estado NOT IN ('completada','cancelada')
       AND t.referencia_tipo IN ('producto_base','producto')
       AND (t.titulo LIKE '%2.1/2 (STEEL)%' OR t.descripcion LIKE '%2.1/2 (STEEL)%')
       AND pb.nombre_canonico NOT LIKE '%2.1/2 (STEEL)%'"
);

echo json_encode([
    'stats' => $stats,
    'open_tasks_obsolete_2.1/2_steel' => $old_half,
    'sku_20693_tasks' => $sku20693,
    'stale_sample' => $stale,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
