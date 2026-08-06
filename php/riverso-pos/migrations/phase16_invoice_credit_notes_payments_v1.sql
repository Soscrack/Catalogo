-- ============================================================================
-- FASE 16: Notas de crédito, referencias DTE y pagos agrupados
-- ============================================================================
--
-- Objetivo:
--   - Soportar TipoDTE=61 (Nota de Crédito) como documentos asociados a facturas
--   - Guardar información de referencias DTE (<Referencia> nodes) con estado
--   - Implementar pagos agrupados (N:M) con tickets, documentos y comprobantes
--   - Mantener estado_pago separado de estado operativo de recepción
--   - Implementar reversas de inventario opcionales para NC
--
-- ============================================================================

-- PASO 0: Verificar columnas existentes en riverso_facturas
-- ============================================================================
-- Las siguientes columnas deben existir antes de agregar nuevas:
-- - id, tipo_dte, folio, rut_emisor, fecha_emision, monto_neto, monto_iva, monto_total
-- - estado (operativo), created_at
-- Se agregarán índices y columnas nuevas sin reescribir la tabla.

-- PASO 1: Agregar columna estado_pago a riverso_facturas (proyección sincronizada)
-- ============================================================================
-- Facilita filtros por estado de pago sin joins complejos.
-- Valores: 'no_pagada', 'parcialmente_pagada', 'pagada', 'cancelada'

ALTER TABLE {prefix}riverso_facturas 
    ADD COLUMN IF NOT EXISTS estado_pago VARCHAR(50) DEFAULT 'no_pagada' 
    COMMENT 'Estado de pago (no_pagada, parcialmente_pagada, pagada, cancelada)' AFTER estado;

ALTER TABLE {prefix}riverso_facturas 
    ADD KEY IF NOT EXISTS idx_estado_pago (estado_pago);

-- PASO 2: Agregar columnas de estado de recepción faltantes (si no existen)
-- ============================================================================
-- Para sincronizar con el runtime que usa estas columnas.

ALTER TABLE {prefix}riverso_facturas 
    ADD COLUMN IF NOT EXISTS reception_started_at DATETIME DEFAULT NULL 
    COMMENT 'Timestamp de inicio de recepción' AFTER estado_pago;

ALTER TABLE {prefix}riverso_facturas 
    ADD COLUMN IF NOT EXISTS reception_completed_at DATETIME DEFAULT NULL 
    COMMENT 'Timestamp de fin de recepción' AFTER reception_started_at;

ALTER TABLE {prefix}riverso_facturas 
    ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL 
    COMMENT 'Timestamp de aprobación' AFTER reception_completed_at;

-- PASO 3: Crear tabla factura_referencias (registra todos los nodos <Referencia>)
-- ============================================================================
-- Conserva información XML tal como viene + estado de resolución y metadatos de reversa.

CREATE TABLE IF NOT EXISTS {prefix}factura_referencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a riverso_facturas (la NC)',
    tipo_doc_ref INT NOT NULL COMMENT 'TpoDocRef: 33=Factura, 34=Boleta, 52=Guía, etc.',
    folio_ref VARCHAR(50) NOT NULL COMMENT 'FolioRef: puede ser 0 si global',
    ind_global TINYINT(1) DEFAULT 0 COMMENT 'IndGlobal: 1=afecta totalmente, otros=parcial',
    cod_ref INT DEFAULT NULL COMMENT 'CodRef: código de referencia del SII',
    razon_ref VARCHAR(255) DEFAULT NULL COMMENT 'RazonRef: motivo de la referencia',
    fecha_ref DATE DEFAULT NULL COMMENT 'FchRef: fecha de referencia',
    factura_origen_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK resuelto: factura que se descuenta',
    estado_resolucion VARCHAR(50) DEFAULT 'pendiente' COMMENT 'pendiente, resuelta_automatica, resuelta_manual, ambigua',
    estado_reversa_inventario VARCHAR(50) DEFAULT 'sin_reversa' COMMENT 'sin_reversa, pendiente_aplicar, aplicada, cancelada',
    reversa_aplicada_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id que ejecutó reversa',
    reversa_aplicada_at DATETIME DEFAULT NULL COMMENT 'Timestamp ejecución reversa',
    motivo_reversa TEXT DEFAULT NULL COMMENT 'Descripción si hay reversa',
    monto_descuento DECIMAL(12,2) DEFAULT 0 COMMENT 'abs(monto_total NC) para auditoría',
    notas TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_factura_ref (factura_id, tipo_doc_ref, folio_ref, cod_ref),
    KEY idx_factura_id (factura_id),
    KEY idx_factura_origen (factura_origen_id),
    KEY idx_estado_resolucion (estado_resolucion),
    KEY idx_estado_reversa (estado_reversa_inventario),
    CONSTRAINT fk_ref_factura FOREIGN KEY (factura_id) 
        REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_factura_origen FOREIGN KEY (factura_origen_id) 
        REFERENCES {prefix}riverso_facturas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 4: Crear tabla factura_pagos (cabecera del ticket de pago)
-- ============================================================================
-- Un ticket agrupa varias facturas (producto/transporte/NC) bajo un único pago.

CREATE TABLE IF NOT EXISTS {prefix}factura_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero_ticket VARCHAR(50) NOT NULL COMMENT 'Identificador único del ticket (ej: PAG-2026-0001)',
    estado VARCHAR(50) DEFAULT 'activo' COMMENT 'activo, cancelado, reversado',
    monto_total DECIMAL(12,2) DEFAULT 0 COMMENT 'Suma de saldos efectivos (lectura única)',
    moneda VARCHAR(10) DEFAULT 'CLP',
    fecha_pago DATE DEFAULT NULL COMMENT 'Fecha registrada del pago',
    comprobante_nombre_original VARCHAR(255) DEFAULT NULL COMMENT 'Nombre archivo subido',
    comprobante_ruta_relativa VARCHAR(255) DEFAULT NULL COMMENT 'Ruta relativa almacenada (ej: /uploads/facturas/pago_xyz.jpg)',
    comprobante_mime_type VARCHAR(50) DEFAULT NULL COMMENT 'image/jpeg, image/png, image/webp, etc.',
    comprobante_tamaño INT DEFAULT NULL COMMENT 'Tamaño en bytes',
    comprobante_hash VARCHAR(64) DEFAULT NULL COMMENT 'SHA256 del archivo',
    notas TEXT DEFAULT NULL,
    creado_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id WordPress',
    cancelado_por BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id si fue cancelado',
    cancelado_at DATETIME DEFAULT NULL,
    razon_cancelacion TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_numero_ticket (numero_ticket),
    KEY idx_estado (estado),
    KEY idx_fecha_pago (fecha_pago),
    KEY idx_creado_por (creado_por),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 5: Crear tabla factura_pago_documentos (relación N:M)
-- ============================================================================
-- Cada fila registra una factura asociada a un ticket, con monto congelado.

CREATE TABLE IF NOT EXISTS {prefix}factura_pago_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pago_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_pagos',
    factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a riverso_facturas',
    monto_pagado DECIMAL(12,2) NOT NULL COMMENT 'Monto de esta factura en el ticket (puede ser parcial)',
    tipo_aplicacion VARCHAR(50) DEFAULT 'saldo_efectivo' COMMENT 'saldo_efectivo, monto_total, otro',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_pago_factura (pago_id, factura_id),
    KEY idx_pago_id (pago_id),
    KEY idx_factura_id (factura_id),
    CONSTRAINT fk_pago_doc_pago FOREIGN KEY (pago_id) 
        REFERENCES {prefix}factura_pagos (id) ON DELETE CASCADE,
    CONSTRAINT fk_pago_doc_factura FOREIGN KEY (factura_id) 
        REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 6: Crear tabla factura_reversa_inventario (auditoría de reversas)
-- ============================================================================
-- Registra cada operación de reversa de inventario (entrada/salida).

CREATE TABLE IF NOT EXISTS {prefix}factura_reversa_inventario (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referencia_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_referencias',
    factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a factura_origen (redundante pero útil)',
    factura_item_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a factura_items (si es específico)',
    producto_id BIGINT UNSIGNED NOT NULL COMMENT 'producto_base en catálogo',
    movimiento_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a riverso_movimientos (si fue registrado)',
    cantidad_revertida DECIMAL(12,4) NOT NULL COMMENT 'Cantidad que se deshace',
    costo_unitario_original DECIMAL(12,4) DEFAULT NULL COMMENT 'Costo capturado en momento reversa',
    costo_total_reversa DECIMAL(12,2) GENERATED ALWAYS AS (
        CASE 
            WHEN costo_unitario_original IS NOT NULL 
            THEN ABS(cantidad_revertida * costo_unitario_original)
            ELSE 0
        END
    ) STORED COMMENT 'Impacto financiero negativo',
    estado VARCHAR(50) DEFAULT 'aplicada' COMMENT 'aplicada, anulada',
    creado_por BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_referencia_id (referencia_id),
    KEY idx_factura_id (factura_id),
    KEY idx_movimiento_id (movimiento_id),
    KEY idx_estado (estado),
    CONSTRAINT fk_reversa_referencia FOREIGN KEY (referencia_id) 
        REFERENCES {prefix}factura_referencias (id) ON DELETE CASCADE,
    CONSTRAINT fk_reversa_factura FOREIGN KEY (factura_id) 
        REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 7: Auditoría de operaciones (agregar eventos nuevos)
-- ============================================================================
-- Los nuevos event types se registran en auditoría existente.
-- Ejemplos: 'invoice.credit_note_linked', 'invoice.inventory_reversal_applied', 
--           'invoice.payment_ticket_created', 'invoice.payment_ticket_cancelled'

INSERT INTO {prefix}audit_log (
    user_id,
    action_key,
    entity_type,
    entity_id,
    module,
    severity,
    message,
    ip_address,
    created_at
) VALUES (
    0,
    'schema.phase16_credit_notes_payments',
    'riverso_facturas',
    0,
    'invoices',
    'info',
    'Fase 16: Agregado soporte para notas de crédito, referencias DTE, pagos agrupados y reversas de inventario',
    '127.0.0.1',
    NOW()
) ON DUPLICATE KEY UPDATE created_at = NOW();

-- PASO 8: Verificación de integridad
-- ============================================================================
-- Los siguientes SELECT pueden ejecutarse para verificar:
-- SELECT COUNT(*) FROM {prefix}factura_referencias WHERE factura_origen_id IS NULL AND estado_resolucion = 'resuelta_manual';
-- SELECT COUNT(*) FROM {prefix}factura_pagos WHERE estado = 'activo';
-- SELECT COUNT(*) FROM {prefix}factura_pago_documentos;
-- SELECT COUNT(*) FROM {prefix}factura_reversa_inventario WHERE estado = 'aplicada';
