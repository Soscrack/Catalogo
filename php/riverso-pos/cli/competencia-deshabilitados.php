<?php
/**
 * Refrescos nocturnos (Sande, DIMAFI): URL deshabilitadas para descuentos (fase 82).
 *
 * El refresco sigue leyendo su precio; si cambia respecto al congelado al deshabilitar
 * (deshabilitado_precio), deja precio_cambio_at para que alguien revise y la reactive.
 */

/**
 * @param mysqli $db
 * @param string $prefix    Prefijo riverso (wp_riverso_).
 * @param int    $fuente_id
 * @param string $now       Fecha y hora local (America/Santiago).
 * @return int Vínculos marcados hoy.
 */
function riverso_cli_mark_disabled_price_changes(mysqli $db, $prefix, $fuente_id, $now) {
    $col = $db->query("SHOW COLUMNS FROM `{$prefix}competencia_productos` LIKE 'precio_cambio_at'");
    if (!$col || $col->num_rows === 0) {
        return 0;
    }
    $fuente_id = (int) $fuente_id;
    $db->query("UPDATE `{$prefix}competencia_productos` cp
        INNER JOIN `{$prefix}competencia_precios` pr ON pr.producto_id = cp.id
        SET cp.precio_cambio_at = '" . $db->real_escape_string($now) . "'
        WHERE cp.fuente_id = {$fuente_id}
          AND cp.deshabilitado = 1
          AND cp.precio_cambio_at IS NULL
          AND cp.deshabilitado_precio IS NOT NULL
          AND pr.precio_bruto_unitario IS NOT NULL
          AND ABS(pr.precio_bruto_unitario - cp.deshabilitado_precio) > 0.005");
    return (int) $db->affected_rows;
}
