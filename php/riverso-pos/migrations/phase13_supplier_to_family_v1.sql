-- ============================================================================
-- FASE 13: Asignación proveedor → producto O familia
-- ============================================================================
-- 
-- Objetivo:
--   - Extender producto_proveedor para permitir asignación a familia (grupo_id)
--   - Mantener backward compatibility: producto_base_id sigue siendo FK
--   - Nueva regla: exactamente uno de (producto_base_id, grupo_id) debe ser NOT NULL
--   - Actualizar matching/supplier_product_links para soportar destino familia
--
-- ============================================================================

-- PASO 0: producto_base_id debe aceptar NULL (destino familia)
-- ============================================================================
-- Obligatorio: sin esto assign_to_family() falla por NOT NULL.

ALTER TABLE {prefix}producto_proveedor 
    MODIFY COLUMN producto_base_id BIGINT UNSIGNED DEFAULT NULL 
    COMMENT 'FK producto_base; NULL si asignado a familia (grupo_id)';

-- PASO 1: Agregar columna grupo_id a producto_proveedor
-- ============================================================================

ALTER TABLE {prefix}producto_proveedor 
    ADD COLUMN IF NOT EXISTS grupo_id BIGINT UNSIGNED DEFAULT NULL 
    COMMENT 'FK a equivalence_groups: si es familia, producto_base_id debe ser NULL' AFTER producto_base_id;

-- Crear índice para búsquedas rápidas por familia
ALTER TABLE {prefix}producto_proveedor 
    ADD KEY IF NOT EXISTS idx_grupo_id (grupo_id);

-- Crear FK de grupo_id a equivalence_groups
ALTER TABLE {prefix}producto_proveedor 
    ADD CONSTRAINT IF NOT EXISTS fk_pp_grupo_id 
    FOREIGN KEY (grupo_id) REFERENCES {prefix}equivalence_groups(id) ON DELETE SET NULL;

-- PASO 2: Agregar validación check (uno y solo uno de producto_base_id o grupo_id)
-- ============================================================================
-- Nota: MySQL 8.0.16+ soporta CHECK constraints con expresiones complejas
-- Para versiones < 8.0.16, usar trigger (ver PASO 3)

-- Intentar agregar CHECK constraint si la versión lo soporta
ALTER TABLE {prefix}producto_proveedor 
    ADD CONSTRAINT chk_producto_o_familia 
    CHECK (
        (producto_base_id IS NOT NULL AND grupo_id IS NULL) OR
        (producto_base_id IS NULL AND grupo_id IS NOT NULL)
    );

-- PASO 3: Índice compuesto para búsquedas por (proveedor, codigo_proveedor)
-- ============================================================================
-- (sin cambios, ya existe ux_proveedor_codigo)

-- PASO 4: Columnas de auditoría para la asignación a familia
-- ============================================================================

ALTER TABLE {prefix}producto_proveedor 
    ADD COLUMN IF NOT EXISTS assigned_to_family_at DATETIME DEFAULT NULL 
    COMMENT 'Timestamp de asignación a familia (group_id)' AFTER grupo_id;

ALTER TABLE {prefix}producto_proveedor 
    ADD COLUMN IF NOT EXISTS assigned_to_family_by BIGINT UNSIGNED DEFAULT NULL 
    COMMENT 'user_id que asignó a familia' AFTER assigned_to_family_at;

-- PASO 5: Migración: convertir producto_base_id → grupo_id si la base está asignada a una familia
-- ============================================================================
-- Detectar producto_base asociados a una familia y hacer la conversión
-- Esto es selectivo: solo si ya hay una clara intención de familia

-- Ejemplo (comentado): si existe lógica de negocio que indique que ciertos 
-- producto_base siempre son "familias", descomenta:
-- 
-- UPDATE {prefix}producto_proveedor pp
-- SET 
--     grupo_id = (
--         SELECT eg.id FROM {prefix}equivalence_groups eg
--         INNER JOIN {prefix}equivalence_members em ON em.grupo_id = eg.id
--         WHERE em.producto_base_id = pp.producto_base_id
--         LIMIT 1
--     ),
--     producto_base_id = NULL,
--     assigned_to_family_at = NOW(),
--     assigned_to_family_by = 0
-- WHERE producto_base_id IS NOT NULL
--   AND EXISTS (
--       SELECT 1 FROM {prefix}equivalence_groups eg
--       INNER JOIN {prefix}equivalence_members em ON em.grupo_id = eg.id
--       WHERE em.producto_base_id = pp.producto_base_id
--   );

-- PASO 6: Auditoría
-- ============================================================================

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
    'schema.producto_proveedor_family_support',
    'producto_proveedor',
    0,
    'catalog',
    'info',
    'Fase 13: Agregado soporte para asignación proveedor → familia (grupo_id)',
    '127.0.0.1',
    NOW()
) ON DUPLICATE KEY UPDATE created_at = NOW();

-- PASO 7: Verificación de integridad
-- ============================================================================

-- Los siguientes SELECT pueden ejecutarse para verificar:
-- SELECT COUNT(*) FROM {prefix}producto_proveedor WHERE producto_base_id IS NOT NULL AND grupo_id IS NOT NULL;
-- SELECT COUNT(*) FROM {prefix}producto_proveedor WHERE producto_base_id IS NULL AND grupo_id IS NULL;
-- SELECT COUNT(*) FROM {prefix}producto_proveedor WHERE grupo_id IS NOT NULL;
