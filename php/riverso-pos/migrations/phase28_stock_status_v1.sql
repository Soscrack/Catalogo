-- Fase 28: Estado de stock + ubicacion especial "?"
-- Migracion incremental no destructiva para Riverso POS.
-- Aplicada via class-activator.php (create_phase28_stock_status)
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}producto_stock_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `stock_minimo` INT DEFAULT NULL,
    `stock_critico` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto` (`producto_base_id`),
    KEY `idx_stock_minimo` (`stock_minimo`),
    KEY `idx_stock_critico` (`stock_critico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserta la ubicacion especial '?' si no existe.
INSERT INTO `{prefix}ubicaciones` (
    `codigo`,
    `nombre`,
    `tipo`,
    `capacidad`,
    `activo`,
    `created_at`,
    `updated_at`
)
SELECT
    '?',
    'Desconocido',
    'bodega_ext',
    0,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1 FROM `{prefix}ubicaciones` u WHERE u.codigo = '?'
);

