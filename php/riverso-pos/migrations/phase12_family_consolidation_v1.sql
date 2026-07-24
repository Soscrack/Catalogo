-- ============================================================================
-- FASE 12: Consolidación del esquema de familia
-- ============================================================================
-- 
-- Objetivo:
--   - Unificar dos esquemas de familia que coexisten: equivalence_groups/members
--     (activo en código) vs equivalencia_grupo/miembro (phase11, sin uso)
--   - Mantener equivalence_groups/members como esquema canónico
--   - Migrar cualquier dato de equivalencia_grupo/miembro al esquema canónico
--   - Marcar las tablas phase11 como obsoletas para eventual deprecación
--
-- ============================================================================

-- PASO 1: Migrar datos de equivalencia_grupo → equivalence_groups (si existen)
-- ============================================================================

INSERT IGNORE INTO {prefix}equivalence_groups (
    codigo_grupo,
    nombre,
    tipo_sustitucion,
    activo,
    notas,
    created_at,
    updated_at
)
SELECT 
    CONCAT('LEGACY_', eg.id) AS codigo_grupo,
    eg.nombre,
    'compatible' AS tipo_sustitucion,
    eg.activo,
    CONCAT('Migrado de phase11 equivalencia_grupo id=', eg.id) AS notas,
    eg.created_at,
    eg.updated_at
FROM {prefix}equivalencia_grupo eg
WHERE NOT EXISTS (
    SELECT 1 FROM {prefix}equivalence_groups eg2 
    WHERE eg2.codigo_grupo = CONCAT('LEGACY_', eg.id)
);

-- PASO 2: Migrar datos de equivalencia_miembro → equivalence_members (si existen)
-- ============================================================================

INSERT IGNORE INTO {prefix}equivalence_members (
    grupo_id,
    producto_base_id,
    prioridad,
    es_reemplazo_preferido,
    activo,
    created_at,
    updated_at
)
SELECT 
    eg_canon.id AS grupo_id,
    em.producto_base_id,
    em.prioridad,
    0 AS es_reemplazo_preferido,
    1 AS activo,
    em.created_at,
    NOW() AS updated_at
FROM {prefix}equivalencia_miembro em
INNER JOIN {prefix}equivalencia_grupo eg ON eg.id = em.grupo_id
LEFT JOIN {prefix}equivalence_groups eg_canon ON eg_canon.codigo_grupo = CONCAT('LEGACY_', eg.id)
WHERE eg_canon.id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM {prefix}equivalence_members em2
        WHERE em2.grupo_id = eg_canon.id 
            AND em2.producto_base_id = em.producto_base_id
    );

-- PASO 3: Añadir columnas de auditoría y deprecación a las tablas phase11
-- ============================================================================

-- Marcar las tablas legacy con columnas de fecha de deprecación (para posterior eliminación)
ALTER TABLE {prefix}equivalencia_grupo 
    ADD COLUMN IF NOT EXISTS deprecated_at DATETIME DEFAULT NULL COMMENT 'Marca de deprecación: phase12+ usa equivalence_groups';

ALTER TABLE {prefix}equivalencia_miembro 
    ADD COLUMN IF NOT EXISTS deprecated_at DATETIME DEFAULT NULL COMMENT 'Marca de deprecación: phase12+ usa equivalence_members';

-- Marcar como deprecadas las filas que ya fueron migradas
UPDATE {prefix}equivalencia_grupo 
SET deprecated_at = NOW() 
WHERE deprecated_at IS NULL 
    AND EXISTS (
        SELECT 1 FROM {prefix}equivalence_groups eg2 
        WHERE eg2.codigo_grupo = CONCAT('LEGACY_', {prefix}equivalencia_grupo.id)
    );

-- PASO 4: Auditoría
-- ============================================================================

-- Si existe tabla de auditoría, registrar consolidación
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
    'consolidation.family_schema',
    'equivalence_groups',
    0,
    'catalog',
    'info',
    'Fase 12: Consolidación de esquema de familia (equivalencia_grupo → equivalence_groups)',
    '127.0.0.1',
    NOW()
) ON DUPLICATE KEY UPDATE created_at = NOW();

-- PASO 5: Verificación de integridad
-- ============================================================================

-- Los siguientes SELECT pueden ejecutarse para verificar la consolidación:
-- SELECT COUNT(*) FROM {prefix}equivalence_groups;
-- SELECT COUNT(*) FROM {prefix}equivalence_members;
-- SELECT COUNT(*) FROM {prefix}equivalencia_grupo WHERE deprecated_at IS NULL;
-- SELECT COUNT(*) FROM {prefix}equivalencia_miembro WHERE deprecated_at IS NULL;
