<?php
/**
 * Origen de ingreso (XML / escaneo) y adjuntos visualizables de facturas.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ¿Texto típico de XML rescatado del SII (sin detalle de ítems)?
 */
function riverso_is_sii_rescued_description($text) {
    $t = strtoupper(trim((string) $text));
    if ($t === '') {
        return false;
    }
    return (strpos($t, 'DOCUMENTO RESCATADO DEL SII') !== false)
        || (strpos($t, 'SIN INFORMACION DE DETALLE') !== false)
        || (strpos($t, 'SIN INFORMACIÓN DE DETALLE') !== false);
}

/**
 * ¿El payload parseado (XML o escaneo) es un stub sin detalle de líneas?
 *
 * @param array $factura_data Estructura tipo parse_dte_xml / normalize_to_factura
 */
function riverso_factura_data_is_sii_rescued_stub(array $factura_data) {
    $items = $factura_data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        return true;
    }
    $stub = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $desc = trim(($item['nombre'] ?? '') . ' ' . ($item['descripcion'] ?? ''));
        if (riverso_is_sii_rescued_description($desc)) {
            $stub++;
        }
    }
    return $stub > 0 && $stub === count($items);
}

/**
 * ¿La factura en BD tiene solo ítems stub del SII (sin detalle real)?
 */
function riverso_factura_db_is_sii_rescued_stub($factura_id) {
    global $wpdb;
    $factura_id = (int) $factura_id;
    if ($factura_id <= 0) {
        return false;
    }
    $prefix = $wpdb->prefix . 'riverso_';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT nombre, descripcion FROM {$prefix}factura_items WHERE factura_id = %d",
        $factura_id
    ), ARRAY_A);
    if (empty($rows)) {
        return true;
    }
    $stub = 0;
    foreach ($rows as $row) {
        $desc = trim(($row['nombre'] ?? '') . ' ' . ($row['descripcion'] ?? ''));
        if (riverso_is_sii_rescued_description($desc)) {
            $stub++;
        }
    }
    return $stub > 0 && $stub === count($rows);
}

/**
 * Etiqueta legible del origen de ingreso.
 */
function riverso_factura_origen_label($origen) {
    $map = [
        'xml'     => 'XML',
        'escaneo' => 'Escaneo',
        'ambos'   => 'XML + Escaneo',
        'facto'   => 'FACTO Inbox',
    ];
    return $map[$origen] ?? 'XML';
}

/**
 * Clases CSS para badge de origen.
 */
function riverso_factura_origen_badge_class($origen) {
    $map = [
        'xml'     => 'origen-badge-xml',
        'escaneo' => 'origen-badge-escaneo',
        'ambos'   => 'origen-badge-ambos',
        'facto'   => 'origen-badge-facto',
    ];
    return $map[$origen] ?? 'origen-badge-xml';
}

/**
 * Marca factura como ambos cuando se adjunta un escaneo a una ingresada por XML.
 */
function riverso_factura_mark_scan_attached($factura_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'riverso_facturas';
    $current = $wpdb->get_var($wpdb->prepare(
        "SELECT origen_ingreso FROM {$table} WHERE id = %d",
        (int) $factura_id
    ));
    if ($current === 'xml') {
        $wpdb->update($table, ['origen_ingreso' => 'ambos'], ['id' => (int) $factura_id]);
    } elseif (empty($current)) {
        $wpdb->update($table, ['origen_ingreso' => 'escaneo'], ['id' => (int) $factura_id]);
    }
}

/**
 * Marca factura como ambos cuando se adjunta un XML a una ingresada por escaneo.
 */
function riverso_factura_mark_xml_attached($factura_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'riverso_facturas';
    $current = $wpdb->get_var($wpdb->prepare(
        "SELECT origen_ingreso FROM {$table} WHERE id = %d",
        (int) $factura_id
    ));
    if ($current === 'escaneo' || empty($current)) {
        $wpdb->update($table, ['origen_ingreso' => 'ambos'], ['id' => (int) $factura_id]);
    }
}

/**
 * RUT solo dígitos + K (para comparar sin formato).
 */
function riverso_factura_rut_digits($rut) {
    return strtoupper(preg_replace('/[^0-9K]/', '', (string) $rut));
}

/**
 * Busca factura existente por tipo DTE + folio + RUT emisor (RUT normalizado).
 *
 * @return array|null Fila de riverso_facturas o null
 */
function riverso_find_factura_by_dte($tipo_dte, $folio, $rut) {
    global $wpdb;
    $table = $wpdb->prefix . 'riverso_facturas';
    $tipo_dte = (int) $tipo_dte;
    $folio_raw = (string) $folio;
    $folio_norm = function_exists('riverso_normalize_folio')
        ? riverso_normalize_folio($folio_raw)
        : preg_replace('/[^0-9A-Za-z]/', '', $folio_raw);
    $rut_digits = riverso_factura_rut_digits($rut);

    if ($tipo_dte <= 0 || ($folio_raw === '' && $folio_norm === '') || $rut_digits === '') {
        return null;
    }

    $folios = array_values(array_unique(array_filter([$folio_raw, $folio_norm])));
    $placeholders = implode(',', array_fill(0, count($folios), '%s'));
    $params = array_merge([$tipo_dte], $folios);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE tipo_dte = %d AND folio IN ({$placeholders})
         ORDER BY id DESC
         LIMIT 20",
        ...$params
    ), ARRAY_A);

    if (empty($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        if (riverso_factura_rut_digits($row['rut_emisor'] ?? '') === $rut_digits) {
            return $row;
        }
    }
    return null;
}

/**
 * Adjunta meta de escaneo (PDF R2) a xml_path de la factura sin pisar xml_hash existente.
 *
 * @return bool
 */
function riverso_factura_attach_scan_meta($factura_id, $r2_key, array $doc = [], $archivo_hash = '') {
    global $wpdb;
    $factura_id = (int) $factura_id;
    if ($factura_id <= 0 || $r2_key === '') {
        return false;
    }

    $table = $wpdb->prefix . 'riverso_facturas';
    $scan_meta = [
        'archivo_hash'  => $archivo_hash,
        'r2_key'        => $r2_key,
        'pagina_inicio' => (int) ($doc['pagina_inicio'] ?? 1),
        'pagina_fin'    => (int) ($doc['pagina_fin'] ?? ($doc['pagina_inicio'] ?? 1)),
        'attached_at'   => current_time('mysql'),
    ];

    $existing_path = $wpdb->get_var($wpdb->prepare(
        "SELECT xml_path FROM {$table} WHERE id = %d",
        $factura_id
    ));
    $paths = [];
    if ($existing_path) {
        $decoded = json_decode($existing_path, true);
        if (is_array($decoded)) {
            $paths = $decoded;
        } else {
            $paths[] = ['legacy' => $existing_path];
        }
    }

    foreach ($paths as $p) {
        if (!empty($p['r2_key']) && $p['r2_key'] === $r2_key
            && (int) ($p['pagina_inicio'] ?? 1) === (int) $scan_meta['pagina_inicio']) {
            riverso_factura_mark_scan_attached($factura_id);
            return true;
        }
    }

    $paths[] = $scan_meta;
    $wpdb->update($table, [
        'xml_path' => wp_json_encode($paths),
    ], ['id' => $factura_id]);

    riverso_factura_mark_scan_attached($factura_id);
    return true;
}

/**
 * Vincula documentos escaneados del mismo DTE a una factura (pendientes o confirmados).
 *
 * @return array{linked:int,doc_ids:int[]}
 */
function riverso_link_scans_to_factura($factura_id, $tipo_dte, $folio, $rut) {
    global $wpdb;
    $factura_id = (int) $factura_id;
    $result = ['linked' => 0, 'doc_ids' => []];
    if ($factura_id <= 0 || !function_exists('riverso_scan_doc_hash')) {
        return $result;
    }

    $prefix = $wpdb->prefix . 'riverso_';
    $doc_hash = riverso_scan_doc_hash($tipo_dte, $folio, $rut);
    $docs = $wpdb->get_results($wpdb->prepare(
        "SELECT d.*, a.r2_key_original, a.archivo_hash
         FROM {$prefix}documentos_escaneados d
         LEFT JOIN {$prefix}documentos_archivos a ON a.id = d.archivo_id
         WHERE d.doc_hash = %s
           AND d.estado_revision NOT IN ('descartado')
         ORDER BY d.id ASC",
        $doc_hash
    ), ARRAY_A);

    if (empty($docs)) {
        return $result;
    }

    foreach ($docs as $doc) {
        $doc_id = (int) $doc['id'];
        $estado = $doc['estado_revision'];
        $already = (int) ($doc['factura_id'] ?? 0);

        if ($already === $factura_id && in_array($estado, ['confirmado', 'duplicado'], true)) {
            if (!empty($doc['r2_key_original'])) {
                riverso_factura_attach_scan_meta(
                    $factura_id,
                    $doc['r2_key_original'],
                    $doc,
                    $doc['archivo_hash'] ?? ''
                );
            }
            continue;
        }

        $new_estado = ($estado === 'confirmado' && $already === $factura_id)
            ? 'confirmado'
            : 'duplicado';

        $wpdb->update("{$prefix}documentos_escaneados", [
            'factura_id'      => $factura_id,
            'estado_revision' => $new_estado,
        ], ['id' => $doc_id]);

        if (!empty($doc['r2_key_original'])) {
            $payload = json_decode($doc['datos_json'] ?? '{}', true) ?: [];
            $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : $doc;
            riverso_factura_attach_scan_meta(
                $factura_id,
                $doc['r2_key_original'],
                $raw,
                $doc['archivo_hash'] ?? ''
            );
        }

        $result['linked']++;
        $result['doc_ids'][] = $doc_id;
    }

    riverso_factura_mark_xml_attached($factura_id);
    riverso_factura_mark_scan_attached($factura_id);

    return $result;
}

/**
 * Obtiene adjuntos escaneados (PDF/imagen) de una factura con URLs prefirmadas.
 *
 * @return array{origen_ingreso:string,tiene_xml:bool,tiene_escaneo:bool,adjuntos:array}
 */
function riverso_factura_get_adjuntos($factura_id, $factura_row = null) {
    global $wpdb;
    $prefix = $wpdb->prefix . 'riverso_';

    if (!$factura_row) {
        $factura_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, folio, origen_ingreso, xml_path, xml_hash FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ), ARRAY_A);
    }

    if (!$factura_row) {
        return [
            'origen_ingreso' => 'xml',
            'tiene_xml'      => true,
            'tiene_escaneo'  => false,
            'adjuntos'       => [],
        ];
    }

    $origen = sanitize_text_field($factura_row['origen_ingreso'] ?? 'xml');
    if (!in_array($origen, ['xml', 'escaneo', 'ambos'], true)) {
        $origen = 'xml';
    }

    $adjuntos = [];
    $seen_keys = [];

    $push_adjunto = function ($entry) use (&$adjuntos, &$seen_keys) {
        $key = ($entry['r2_key'] ?? '') . '|' . ($entry['pagina_inicio'] ?? 1);
        if ($key === '|1' || isset($seen_keys[$key])) {
            return;
        }
        $seen_keys[$key] = true;
        $adjuntos[] = $entry;
    };

    // Desde xml_path (JSON de escaneos adjuntos)
    $xml_path = $factura_row['xml_path'] ?? '';
    if ($xml_path !== '') {
        $decoded = json_decode($xml_path, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (empty($item['r2_key'])) {
                    continue;
                }
                $push_adjunto([
                    'r2_key'        => $item['r2_key'],
                    'archivo_hash'  => $item['archivo_hash'] ?? '',
                    'pagina_inicio' => (int) ($item['pagina_inicio'] ?? 1),
                    'pagina_fin'    => (int) ($item['pagina_fin'] ?? ($item['pagina_inicio'] ?? 1)),
                    'fuente'        => 'xml_path',
                ]);
            }
        }
    }

    // Desde bandeja de escaneos vinculados
    $docs = $wpdb->get_results($wpdb->prepare(
        "SELECT d.pagina_inicio, d.pagina_fin, d.folio AS doc_folio,
                a.r2_key_original, a.nombre_original, a.mime, a.archivo_hash
         FROM {$prefix}documentos_escaneados d
         INNER JOIN {$prefix}documentos_archivos a ON a.id = d.archivo_id
         WHERE d.factura_id = %d
           AND d.estado_revision IN ('confirmado', 'duplicado')
         ORDER BY d.id ASC",
        (int) $factura_id
    ), ARRAY_A);

    foreach ($docs as $doc) {
        if (empty($doc['r2_key_original'])) {
            continue;
        }
        $push_adjunto([
            'r2_key'          => $doc['r2_key_original'],
            'archivo_hash'    => $doc['archivo_hash'] ?? '',
            'pagina_inicio'   => (int) ($doc['pagina_inicio'] ?? 1),
            'pagina_fin'      => (int) ($doc['pagina_fin'] ?? 1),
            'nombre_original' => $doc['nombre_original'] ?? '',
            'mime'            => $doc['mime'] ?? 'application/pdf',
            'doc_folio'       => $doc['doc_folio'] ?? '',
            'fuente'          => 'documento_escaneado',
        ]);
    }

    // Enriquecer con URLs prefirmadas
    if (!class_exists('Riverso_R2_Client')) {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-r2-client.php';
    }
    $r2 = new Riverso_R2_Client();
    $r2_ok = $r2->is_configured();

    foreach ($adjuntos as &$adj) {
        $mime = $adj['mime'] ?? 'application/pdf';
        $adj['tipo'] = strpos($mime, 'pdf') !== false ? 'pdf' : 'imagen';
        $adj['label'] = $adj['nombre_original'] ?? ('Escaneo pág. ' . ($adj['pagina_inicio'] ?? 1));
        if (!empty($adj['doc_folio'])) {
            $adj['label'] .= ' (folio ' . $adj['doc_folio'] . ')';
        }
        $adj['url'] = '';
        if ($r2_ok && !empty($adj['r2_key'])) {
            $url = $r2->presigned_url($adj['r2_key'], 3600);
            if ($url && !empty($adj['pagina_inicio']) && $adj['tipo'] === 'pdf') {
                $url .= '#page=' . (int) $adj['pagina_inicio'];
            }
            $adj['url'] = $url;
        }
    }
    unset($adj);

    $tiene_escaneo = !empty($adjuntos) || in_array($origen, ['escaneo', 'ambos'], true);
    $tiene_xml = in_array($origen, ['xml', 'ambos'], true);

    return [
        'origen_ingreso' => $origen,
        'origen_label'   => riverso_factura_origen_label($origen),
        'tiene_xml'      => $tiene_xml,
        'tiene_escaneo'  => $tiene_escaneo,
        'adjuntos'       => $adjuntos,
    ];
}

/**
 * Resumen compacto para listado de facturas.
 */
function riverso_factura_origen_summary($factura_row) {
    $origen = sanitize_text_field($factura_row['origen_ingreso'] ?? 'xml');
    $adj_count = (int) ($factura_row['adjuntos_count'] ?? 0);
    return [
        'origen_ingreso' => $origen,
        'origen_label'   => riverso_factura_origen_label($origen),
        'badge_class'    => riverso_factura_origen_badge_class($origen),
        'tiene_escaneo'  => in_array($origen, ['escaneo', 'ambos'], true) || $adj_count > 0,
        'tiene_xml'      => in_array($origen, ['xml', 'ambos'], true),
        'adjuntos_count' => $adj_count,
    ];
}
