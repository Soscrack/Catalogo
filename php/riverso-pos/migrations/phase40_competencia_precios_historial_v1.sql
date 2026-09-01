-- Fase 40: precio vigente 1:1 + historial quincenal (días 01 y 16).
-- Sustituye {prefix} por el prefijo real (ej. nExLU_riverso_).

-- Historial mínimo: solo precio + fecha de snapshot.
CREATE TABLE IF NOT EXISTS `{prefix}competencia_precios_historial` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `snapshot_fecha` DATE NOT NULL,
    `precio` DECIMAL(18,6) DEFAULT NULL,
    `precio_lista` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_unitario` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_total` DECIMAL(18,6) DEFAULT NULL,
    `cantidad_min` INT UNSIGNED DEFAULT NULL,
    `iva` DECIMAL(8,4) DEFAULT NULL,
    `moneda` VARCHAR(10) DEFAULT 'CLP',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_snapshot` (`producto_id`, `snapshot_fecha`),
    KEY `idx_snapshot` (`snapshot_fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compactar vigente: dejar solo la fila más reciente por producto.
DELETE p FROM `{prefix}competencia_precios` p
INNER JOIN `{prefix}competencia_precios` newer
  ON newer.producto_id = p.producto_id
 AND (
       newer.snapshot_fecha > p.snapshot_fecha
    OR (newer.snapshot_fecha = p.snapshot_fecha AND newer.id > p.id)
 );

-- Unique 1 fila por producto (precio vigente).
ALTER TABLE `{prefix}competencia_precios`
  DROP INDEX `ux_producto_snapshot`;

ALTER TABLE `{prefix}competencia_precios`
  ADD UNIQUE KEY `ux_producto_id` (`producto_id`);

-- Fecha/hora de última actualización del vigente.
ALTER TABLE `{prefix}competencia_precios`
  ADD COLUMN `actualizado_at` DATETIME DEFAULT NULL AFTER `oculto`;

UPDATE `{prefix}competencia_precios`
SET `actualizado_at` = COALESCE(`actualizado_at`, `created_at`, NOW())
WHERE `actualizado_at` IS NULL;

-- Seed historial con el vigente actual (idempotente).
INSERT INTO `{prefix}competencia_precios_historial`
    (`producto_id`, `snapshot_fecha`, `precio`, `precio_lista`,
     `precio_bruto_unitario`, `precio_bruto_total`, `cantidad_min`, `iva`, `moneda`)
SELECT
    `producto_id`, `snapshot_fecha`, `precio`, `precio_lista`,
    `precio_bruto_unitario`, `precio_bruto_total`, `cantidad_min`, `iva`, `moneda`
FROM `{prefix}competencia_precios`
ON DUPLICATE KEY UPDATE
    `precio` = VALUES(`precio`),
    `precio_lista` = VALUES(`precio_lista`),
    `precio_bruto_unitario` = VALUES(`precio_bruto_unitario`),
    `precio_bruto_total` = VALUES(`precio_bruto_total`),
    `cantidad_min` = VALUES(`cantidad_min`),
    `iva` = VALUES(`iva`),
    `moneda` = VALUES(`moneda`);
