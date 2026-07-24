<?php
/**
 * Script de migración: códigos legacy → modelo unificado (Fase 2)
 * 
 * Migra datos desde riverso_barcodes y riverso_codigos al nuevo modelo.
 * Mantiene compatibilidad backward mediante dual-read.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

function riverso_migrate_barcodes_phase2() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Migrar riverso_barcodes (EAN13)
    $barcodes = $wpdb->get_results(
        "SELECT id, ean13, product_id, product_base_id FROM {$prefix}riverso_barcodes WHERE ean13 IS NOT NULL"
    );

    foreach ($barcodes as $bc) {
        // Evitar duplicados
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}riverso_codigo_barra WHERE codigo = %s",
                $bc->ean13
            )
        );

        if ($existing) {
            continue;
        }

        $wpdb->insert(
            "{$prefix}riverso_codigo_barra",
            [
                'codigo' => $bc->ean13,
                'tipo' => 'ean13',
                'producto_base_id' => $bc->product_base_id ?: 0,
                'cantidad' => 1,
                'unidad_medida' => 'unidad',
                'factor_a_unidad_base' => 1,
                'activo' => 1,
                'migrado_de_tabla' => 'riverso_barcodes',
                'created_at' => current_time('mysql'),
            ]
        );

        if ($wpdb->insert_id) {
            $wpdb->insert(
                "{$prefix}riverso_codigo_migracion_log",
                [
                    'codigo_barra_id' => $wpdb->insert_id,
                    'tabla_origen' => 'riverso_barcodes',
                    'id_origen' => $bc->id,
                    'notas' => 'Migración EAN13 legacy',
                ]
            );
        }
    }

    echo "Migrados EAN13.\n";
}

function riverso_migrate_supplier_codes_phase2() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Migrar riverso_codigos (códigos proveedor)
    $codes = $wpdb->get_results(
        "SELECT id, codigo_proveedor, product_base_id, proveedor_id, factor_conversion 
         FROM {$prefix}riverso_codigos 
         WHERE codigo_proveedor IS NOT NULL AND activo = 1"
    );

    foreach ($codes as $cd) {
        // Evitar duplicados
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}riverso_codigo_barra 
                 WHERE codigo = %s AND proveedor_id = %d",
                $cd->codigo_proveedor,
                $cd->proveedor_id ?: 0
            )
        );

        if ($existing) {
            continue;
        }

        $wpdb->insert(
            "{$prefix}riverso_codigo_barra",
            [
                'codigo' => $cd->codigo_proveedor,
                'tipo' => 'supplier',
                'producto_base_id' => $cd->product_base_id ?: 0,
                'proveedor_id' => $cd->proveedor_id,
                'cantidad' => 1,
                'unidad_medida' => 'unidad',
                'factor_a_unidad_base' => $cd->factor_conversion ?: 1,
                'activo' => 1,
                'migrado_de_tabla' => 'riverso_codigos',
                'created_at' => current_time('mysql'),
            ]
        );

        if ($wpdb->insert_id) {
            $wpdb->insert(
                "{$prefix}riverso_codigo_migracion_log",
                [
                    'codigo_barra_id' => $wpdb->insert_id,
                    'tabla_origen' => 'riverso_codigos',
                    'id_origen' => $cd->id,
                    'notas' => 'Migración código proveedor legacy',
                ]
            );
        }
    }

    echo "Migrados códigos proveedor.\n";
}

function riverso_migrate_packaging_codes_phase2() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Migrar envases con sus códigos de barra asociados
    // Un envase puede tener múltiples códigos (ej: caja de 50, caja de 100)
    // Se vinculan mediante la tabla codigo_barra

    // 1. Para cada envase con código de barra, crear entrada en codigo_barra
    $envases = $wpdb->get_results(
        "SELECT e.id, e.producto_base_id, e.sku_envase, e.cantidad_unidades, e.woocommerce_variation_id 
         FROM {$prefix}riverso_envases e
         WHERE e.id NOT IN (
             SELECT DISTINCT envase_id FROM {$prefix}riverso_codigo_barra WHERE envase_id IS NOT NULL
         )
         LIMIT 100"
    );

    foreach ($envases as $envase) {
        // Si el envase tiene asociado un EAN13 (bolsa con código interno), crear en codigo_barra
        // Buscamos en riverso_bolsas o en history si existe
        $bolsa = $wpdb->get_row($wpdb->prepare(
            "SELECT ean13 FROM {$prefix}riverso_bolsas 
             WHERE producto_base_id = %d AND cantidad = %f LIMIT 1",
            $envase->producto_base_id,
            $envase->cantidad_unidades
        ));

        if ($bolsa && $bolsa->ean13) {
            // Crear entrada con envase vinculado
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}riverso_codigo_barra WHERE codigo = %s AND envase_id = %d",
                $bolsa->ean13,
                $envase->id
            ));

            if (!$existing) {
                $wpdb->insert(
                    "{$prefix}riverso_codigo_barra",
                    [
                        'codigo' => $bolsa->ean13,
                        'tipo' => 'internal',
                        'producto_base_id' => $envase->producto_base_id,
                        'cantidad' => $envase->cantidad_unidades,
                        'unidad_medida' => 'unidad', // O leer de contexto
                        'envase_id' => $envase->id,
                        'factor_a_unidad_base' => 1,
                        'activo' => 1,
                        'migrado_de_tabla' => 'riverso_envases',
                        'created_at' => current_time('mysql'),
                    ]
                );

                if ($wpdb->insert_id) {
                    $wpdb->insert(
                        "{$prefix}riverso_codigo_migracion_log",
                        [
                            'codigo_barra_id' => $wpdb->insert_id,
                            'tabla_origen' => 'riverso_envases',
                            'id_origen' => $envase->id,
                            'notas' => 'Migración envase con código internal',
                        ]
                    );
                }
            }
        } else {
            // Crear entrada para el envase sin código específico (cantidad genérica)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}riverso_codigo_barra WHERE envase_id = %d LIMIT 1",
                $envase->id
            ));

            if (!$existing && $envase->sku_envase) {
                $wpdb->insert(
                    "{$prefix}riverso_codigo_barra",
                    [
                        'codigo' => $envase->sku_envase,
                        'tipo' => 'internal',
                        'producto_base_id' => $envase->producto_base_id,
                        'cantidad' => $envase->cantidad_unidades,
                        'unidad_medida' => 'unidad',
                        'envase_id' => $envase->id,
                        'factor_a_unidad_base' => 1,
                        'activo' => 1,
                        'migrado_de_tabla' => 'riverso_envases',
                        'created_at' => current_time('mysql'),
                    ]
                );

                if ($wpdb->insert_id) {
                    $wpdb->insert(
                        "{$prefix}riverso_codigo_migracion_log",
                        [
                            'codigo_barra_id' => $wpdb->insert_id,
                            'tabla_origen' => 'riverso_envases',
                            'id_origen' => $envase->id,
                            'notas' => 'Migración envase por SKU',
                        ]
                    );
                }
            }
        }
    }

    // 2. Migrar códigos de facturas que incluyen envases/cantidades
    $factura_items = $wpdb->get_results(
        "SELECT DISTINCT fi.id, fi.codigo_proveedor, fi.codigo_barra, fi.producto_base_id, 
                fi.cantidad, fi.unidad_medida, fi.proveedor_id
         FROM {$prefix}riverso_factura_items fi
         WHERE fi.codigo_proveedor IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM {$prefix}riverso_codigo_barra cb 
               WHERE cb.codigo = fi.codigo_proveedor 
                 AND cb.proveedor_id = fi.proveedor_id
           )
         LIMIT 100"
    );

    foreach ($factura_items as $item) {
        // Si la factura incluye cantidad y unidad, usarlas
        $cantidad = $item->cantidad ?? 1;
        $unidad = $item->unidad_medida ?? 'unidad';

        $wpdb->insert(
            "{$prefix}riverso_codigo_barra",
            [
                'codigo' => $item->codigo_proveedor,
                'tipo' => 'supplier',
                'producto_base_id' => $item->producto_base_id ?: 0,
                'proveedor_id' => $item->proveedor_id,
                'cantidad' => $cantidad,
                'unidad_medida' => $unidad,
                'factor_a_unidad_base' => 1,
                'activo' => 1,
                'migrado_de_tabla' => 'riverso_factura_items',
                'created_at' => current_time('mysql'),
            ]
        );

        if ($wpdb->insert_id) {
            $wpdb->insert(
                "{$prefix}riverso_codigo_migracion_log",
                [
                    'codigo_barra_id' => $wpdb->insert_id,
                    'tabla_origen' => 'riverso_factura_items',
                    'id_origen' => $item->id,
                    'notas' => 'Migración de código factura con cantidad/unidad',
                ]
            );
        }
    }

    echo "Migrados códigos de envases y facturas.\n";
}

// Ejecutar migraciones si no se han completado
if (!get_option('riverso_pos_phase2_codes_migration_completed')) {
    riverso_migrate_barcodes_phase2();
    riverso_migrate_supplier_codes_phase2();
    riverso_migrate_packaging_codes_phase2();

    update_option('riverso_pos_phase2_codes_migration_completed', 1);
    echo "Migración Fase 2 (códigos) completada.\n";
} else {
    echo "Migración Fase 2 ya estaba completada.\n";
}
