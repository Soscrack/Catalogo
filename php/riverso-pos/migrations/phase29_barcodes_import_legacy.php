<?php
/**
 * Importa barcodes legacy a riverso_codigo_barra como estado=propuesto.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

class Riverso_Barcode_Legacy_Importer {

    public static function run($auto_verify_unambiguous = false) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $now = current_time('mysql');

        $stats = [
            'tienda_local' => 0,
            'wp_barcodes' => 0,
            'skipped' => 0,
            'pending_sku' => 0,
            'conflicts_marked' => 0,
            'auto_verified' => 0,
        ];

        $sku_map = self::load_sku_mapping();

        if (self::table_exists($prefix . 'tienda_local_barcodes')) {
            $offset = 0;
            $batch = 500;
            while (true) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.sku, b.barcode, b.fecha, p.nombre
                     FROM {$prefix}tienda_local_barcodes b
                     LEFT JOIN {$prefix}tienda_local_productos p ON p.sku = b.sku
                     WHERE b.barcode IS NOT NULL AND TRIM(b.barcode) <> ''
                     ORDER BY b.id ASC
                     LIMIT %d OFFSET %d",
                    $batch,
                    $offset
                ), ARRAY_A);
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    $result = self::upsert_proposal($prefix, [
                        'codigo' => trim((string) $row['barcode']),
                        'sku_local' => trim((string) $row['sku']),
                        'origen_datos' => 'legacy_tienda_local',
                        'legacy_ref' => [
                            'fuente' => 'tienda_local_barcodes',
                            'sku_local' => $row['sku'],
                            'nombre' => $row['nombre'] ?? null,
                            'fecha' => $row['fecha'] ?? null,
                        ],
                        'sku_map' => $sku_map,
                    ], $now);
                    $stats[$result]++;
                }
                $offset += $batch;
            }
        }

        if (self::table_exists($prefix . 'barcodes')) {
            $offset = 0;
            $batch = 500;
            while (true) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.barcode, b.sku, b.product_id, b.variation_id, b.source
                     FROM {$prefix}barcodes b
                     WHERE b.barcode IS NOT NULL AND TRIM(b.barcode) <> ''
                       AND COALESCE(b.is_active, 1) = 1
                     ORDER BY b.id ASC
                     LIMIT %d OFFSET %d",
                    $batch,
                    $offset
                ), ARRAY_A);
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    $result = self::upsert_proposal($prefix, [
                        'codigo' => trim((string) $row['barcode']),
                        'sku_local' => trim((string) ($row['sku'] ?? '')),
                        'origen_datos' => 'legacy_wp_riverso_barcodes',
                        'woo_product_id' => intval($row['product_id'] ?? 0),
                        'woo_variation_id' => intval($row['variation_id'] ?? 0),
                        'legacy_ref' => [
                            'fuente' => 'riverso_barcodes',
                            'sku' => $row['sku'] ?? null,
                            'product_id' => $row['product_id'] ?? null,
                            'variation_id' => $row['variation_id'] ?? null,
                            'source' => $row['source'] ?? null,
                        ],
                        'sku_map' => $sku_map,
                    ], $now);
                    $stats[$result]++;
                }
                $offset += $batch;
            }
        }

        $stats['conflicts_marked'] = self::mark_conflicts($prefix);

        if ($auto_verify_unambiguous) {
            $stats['auto_verified'] = self::auto_verify_unambiguous($prefix);
        }

        update_option('riverso_pos_phase29_barcode_import', [
            'at' => $now,
            'stats' => $stats,
        ]);

        return $stats;
    }

    private static function upsert_proposal($prefix, $data, $now) {
        global $wpdb;

        $codigo = $data['codigo'];
        if ($codigo === '') {
            return 'skipped';
        }

        $sku_local = $data['sku_local'];
        $origen = $data['origen_datos'];
        $resolved = self::resolve_producto_base($prefix, $sku_local, $data);

        $existing_same = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}codigo_barra
             WHERE codigo = %s AND origen_datos = %s
               AND COALESCE(sku_local, '') = %s
               AND COALESCE(producto_base_id, 0) = %d
             LIMIT 1",
            $codigo,
            $origen,
            $sku_local,
            intval($resolved['producto_base_id'] ?? 0)
        ));
        if ($existing_same) {
            return 'skipped';
        }

        $verified = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id FROM {$prefix}codigo_barra
             WHERE codigo = %s AND estado = 'verificado' AND activo = 1
             LIMIT 1",
            $codigo
        ), ARRAY_A);

        if ($verified && intval($verified['producto_base_id']) === intval($resolved['producto_base_id'] ?? 0) && !empty($resolved['producto_base_id'])) {
            return 'skipped';
        }

        $conflicto = 0;
        if ($verified && !empty($resolved['producto_base_id']) && intval($verified['producto_base_id']) !== intval($resolved['producto_base_id'])) {
            $conflicto = 1;
        }

        $other = $wpdb->get_var($wpdb->prepare(
            "SELECT producto_base_id FROM {$prefix}codigo_barra
             WHERE codigo = %s AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$prefix}codigo_barra WHERE codigo = %s LIMIT 1
                ) t
             )
             AND estado IN ('propuesto', 'verificado')
             AND COALESCE(producto_base_id, 0) <> %d
             LIMIT 1",
            $codigo,
            $codigo,
            intval($resolved['producto_base_id'] ?? 0)
        ));
        if ($other) {
            $conflicto = 1;
        }

        $pending_sku = $resolved['producto_base_id'] ? null : ($resolved['pending_sku'] ?: ($sku_local ?: null));
        $motivo = $pending_sku
            ? ('esperando alta de producto ' . $pending_sku)
            : 'Importado desde legacy; requiere aprobación.';

        $insert = [
            'codigo' => $codigo,
            'tipo' => 'ean13',
            'producto_base_id' => $resolved['producto_base_id'] ?: null,
            'proveedor_id' => null,
            'cantidad' => 1,
            'unidad_medida' => $resolved['unidad_medida'] ?: 'unidad',
            'envase_id' => null,
            'factor_a_unidad_base' => 1,
            'activo' => 1,
            'estado' => 'propuesto',
            'motivo_estado' => $motivo,
            'estado_por' => get_current_user_id() ?: null,
            'estado_at' => $now,
            'origen_datos' => $origen,
            'requires_human_review' => 1,
            'sku_local' => $sku_local ?: null,
            'pending_sku' => $pending_sku,
            'legacy_ref' => wp_json_encode($data['legacy_ref']),
            'conflicto' => $conflicto,
            'migrado_de_tabla' => $origen,
            'created_at' => $now,
        ];

        $ok = $wpdb->insert("{$prefix}codigo_barra", $insert);
        if ($ok === false) {
            return 'skipped';
        }

        if ($pending_sku) {
            return 'pending_sku';
        }
        return $origen === 'legacy_tienda_local' ? 'tienda_local' : 'wp_barcodes';
    }

    private static function resolve_producto_base($prefix, $sku_local, $data) {
        global $wpdb;

        $candidates = [];
        if ($sku_local !== '') {
            $candidates[] = $sku_local;
            foreach ($data['sku_map'] as $canonical => $local) {
                if ((string) $local === (string) $sku_local) {
                    $candidates[] = (string) $canonical;
                }
                if (strcasecmp((string) $canonical, $sku_local) === 0) {
                    $candidates[] = (string) $local;
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static function ($v) {
            return $v !== '';
        })));

        if ($candidates) {
            $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, canonical_sku, unidad_base
                     FROM {$prefix}producto_base
                     WHERE canonical_sku IN ($placeholders)
                     ORDER BY CASE WHEN TRIM(COALESCE(canonical_sku,'')) <> '' THEN 0 ELSE 1 END
                     LIMIT 1",
                    $candidates
                ),
                ARRAY_A
            );
            if ($row) {
                return [
                    'producto_base_id' => intval($row['id']),
                    'unidad_medida' => $row['unidad_base'] ?: 'unidad',
                    'pending_sku' => null,
                ];
            }
        }

        $woo_id = intval($data['woo_product_id'] ?? 0);
        $woo_var = intval($data['woo_variation_id'] ?? 0);
        if ($woo_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, unidad_base
                 FROM {$prefix}producto_base
                 WHERE woocommerce_product_id = %d
                   AND (woocommerce_variation_id = %d OR %d = 0)
                 ORDER BY CASE WHEN TRIM(COALESCE(canonical_sku,'')) <> '' THEN 0 ELSE 1 END
                 LIMIT 1",
                $woo_id,
                $woo_var,
                $woo_var
            ), ARRAY_A);
            if ($row) {
                return [
                    'producto_base_id' => intval($row['id']),
                    'unidad_medida' => $row['unidad_base'] ?: 'unidad',
                    'pending_sku' => null,
                ];
            }
        }

        $pending = $sku_local !== '' ? $sku_local : null;
        return [
            'producto_base_id' => null,
            'unidad_medida' => 'unidad',
            'pending_sku' => $pending,
        ];
    }

    public static function mark_conflicts($prefix) {
        global $wpdb;

        $wpdb->query(
            "UPDATE {$prefix}codigo_barra SET conflicto = 0
             WHERE estado IN ('propuesto', 'verificado')"
        );

        $wpdb->query(
            "UPDATE {$prefix}codigo_barra cb
             INNER JOIN (
                SELECT codigo
                FROM {$prefix}codigo_barra
                WHERE estado IN ('propuesto', 'verificado') AND activo = 1
                GROUP BY codigo
                HAVING COUNT(DISTINCT CONCAT(COALESCE(producto_base_id, 0), ':', COALESCE(pending_sku, ''))) > 1
             ) d ON d.codigo = cb.codigo
             SET cb.conflicto = 1, cb.requires_human_review = 1
             WHERE cb.estado = 'propuesto'"
        );

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT codigo) FROM {$prefix}codigo_barra WHERE conflicto = 1 AND estado = 'propuesto'"
        );
    }

    public static function auto_verify_unambiguous($prefix) {
        global $wpdb;
        $now = current_time('mysql');

        $ids = $wpdb->get_col(
            "SELECT cb.id
             FROM {$prefix}codigo_barra cb
             WHERE cb.estado = 'propuesto'
               AND cb.activo = 1
               AND cb.conflicto = 0
               AND cb.producto_base_id IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}codigo_barra o
                    WHERE o.codigo = cb.codigo AND o.id <> cb.id
                      AND o.estado IN ('propuesto', 'verificado') AND o.activo = 1
               )
               AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}codigo_barra s
                    WHERE s.sku_local IS NOT NULL AND s.sku_local <> ''
                      AND s.sku_local = cb.sku_local
                      AND s.codigo <> cb.codigo
                      AND s.estado IN ('propuesto', 'verificado')
                      AND s.activo = 1
               )"
        );

        $count = 0;
        foreach ($ids as $id) {
            $updated = $wpdb->update(
                "{$prefix}codigo_barra",
                [
                    'estado' => 'verificado',
                    'requires_human_review' => 0,
                    'conflicto' => 0,
                    'motivo_estado' => 'Auto-verificado: propuesta única sin SKU compartido.',
                    'estado_por' => get_current_user_id() ?: null,
                    'estado_at' => $now,
                    'activo' => 1,
                ],
                ['id' => intval($id)],
                ['%s', '%d', '%d', '%s', '%d', '%s', '%d'],
                ['%d']
            );
            if ($updated !== false) {
                $count++;
            }
        }
        return $count;
    }

    private static function load_sku_mapping() {
        $paths = [
            RIVERSO_POS_PLUGIN_DIR . 'data/sku_mapping.json',
            dirname(RIVERSO_POS_PLUGIN_DIR, 2) . '/data/sku_mapping.json',
        ];
        foreach ($paths as $path) {
            if (is_readable($path)) {
                $json = json_decode((string) file_get_contents($path), true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        return [];
    }

    private static function table_exists($table) {
        global $wpdb;
        $like = $wpdb->esc_like($table);
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('riverso barcodes import-legacy', function ($args, $assoc_args) {
        $auto = !empty($assoc_args['auto-verify']);
        $stats = Riverso_Barcode_Legacy_Importer::run($auto);
        WP_CLI::success('Import legacy barcodes: ' . wp_json_encode($stats));
    });
}
