-- Fase 37: Export Excel a Facto (documentación; migración idempotente en class-activator.php)

ALTER TABLE {prefix}producto_base
    ADD COLUMN facto_iva_tipo VARCHAR(10) NOT NULL DEFAULT 'afecto',
    ADD COLUMN marca VARCHAR(120) NULL DEFAULT NULL,
    ADD COLUMN modelo VARCHAR(120) NULL DEFAULT NULL,
    ADD COLUMN facto_categoria VARCHAR(160) NULL DEFAULT NULL,
    ADD COLUMN stock_minimo DECIMAL(12,3) NULL DEFAULT NULL,
    ADD COLUMN descripcion_facto TEXT NULL DEFAULT NULL;

CREATE TABLE {prefix}facto_export_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    modo VARCHAR(32) NOT NULL,
    alcance LONGTEXT DEFAULT NULL,
    total_filas INT UNSIGNED NOT NULL DEFAULT 0,
    tanda INT UNSIGNED NOT NULL DEFAULT 1,
    tandas_total INT UNSIGNED NOT NULL DEFAULT 1,
    file_hash CHAR(64) DEFAULT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'generado',
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME DEFAULT NULL,
    notas TEXT DEFAULT NULL,
    superseded_by_batch_id BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE {prefix}facto_export_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(100) DEFAULT NULL,
    row_hash CHAR(64) DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    PRIMARY KEY (id)
);
