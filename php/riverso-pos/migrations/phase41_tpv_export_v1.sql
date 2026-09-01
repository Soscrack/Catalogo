-- Fase 41: export catálogo TPV + facto_product_id nullable (migración idempotente en class-activator.php)

ALTER TABLE {prefix}facto_producto_map
    MODIFY facto_product_id BIGINT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE {prefix}tpv_export_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alcance LONGTEXT DEFAULT NULL,
    total_productos INT UNSIGNED NOT NULL DEFAULT 0,
    total_barcodes INT UNSIGNED NOT NULL DEFAULT 0,
    file_hash CHAR(64) DEFAULT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'generado',
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME DEFAULT NULL,
    notas TEXT DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE {prefix}tpv_export_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(20) NOT NULL,
    entity_key VARCHAR(200) NOT NULL,
    producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    sku VARCHAR(100) DEFAULT NULL,
    accion VARCHAR(20) DEFAULT NULL,
    row_hash CHAR(64) DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    PRIMARY KEY (id)
);
