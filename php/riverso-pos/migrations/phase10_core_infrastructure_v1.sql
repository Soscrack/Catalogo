-- Fase 1: Infraestructura Core - Auditoría extendida y Empleados
-- Esta fase añade soporte para mejor auditoría y modelo de empleados como actor ERP

-- Extiende tabla de auditoría con employee_id y module
ALTER TABLE {PREFIX}riverso_audit_log 
ADD COLUMN IF NOT EXISTS employee_id BIGINT UNSIGNED NULL AFTER user_id,
ADD COLUMN IF NOT EXISTS module VARCHAR(50) NULL AFTER action,
ADD FOREIGN KEY IF NOT EXISTS fk_audit_employee (employee_id) REFERENCES {PREFIX}riverso_empleados(id) ON DELETE SET NULL;

-- Tabla de historial de tareas (transiciones de estado)
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_tarea_historial (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tarea_id BIGINT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(50) DEFAULT NULL,
    estado_nuevo VARCHAR(50) NOT NULL,
    cambio_por BIGINT UNSIGNED NOT NULL,
    cambio_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    razon TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_tarea (tarea_id),
    KEY idx_cambio_en (cambio_en),
    FOREIGN KEY (tarea_id) REFERENCES {PREFIX}riverso_tareas(id) ON DELETE CASCADE,
    FOREIGN KEY (cambio_por) REFERENCES {PREFIX}wp_users(ID) ON DELETE RESTRICT
) COLLATE utf8mb4_unicode_ci;

-- Extiende tabla de tareas con employee_id
ALTER TABLE {PREFIX}riverso_tareas 
ADD COLUMN IF NOT EXISTS employee_id BIGINT UNSIGNED NULL AFTER asignado_a,
ADD COLUMN IF NOT EXISTS comentario_completo TEXT DEFAULT NULL AFTER notas,
ADD FOREIGN KEY IF NOT EXISTS fk_task_employee (employee_id) REFERENCES {PREFIX}riverso_empleados(id) ON DELETE SET NULL;

-- Tabla de permiso overrides (opcional, para permisos contextuales futuros)
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_permission_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(100) NOT NULL,
    grant_or_revoke ENUM('grant', 'revoke') DEFAULT 'grant',
    scope_type VARCHAR(50) DEFAULT NULL,
    scope_id BIGINT UNSIGNED DEFAULT NULL,
    granted_by BIGINT UNSIGNED DEFAULT NULL,
    granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_override (user_id, capability, scope_type, scope_id),
    KEY idx_capability (capability),
    KEY idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES {PREFIX}wp_users(ID) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES {PREFIX}wp_users(ID) ON DELETE SET NULL
) COLLATE utf8mb4_unicode_ci;

-- Log de cambios de permisos (auditoría)
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_permission_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(100) NOT NULL,
    old_role VARCHAR(50) DEFAULT NULL,
    new_role VARCHAR(50) DEFAULT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_changed_at (changed_at),
    FOREIGN KEY (user_id) REFERENCES {PREFIX}wp_users(ID) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES {PREFIX}wp_users(ID) ON DELETE RESTRICT
) COLLATE utf8mb4_unicode_ci;

-- Log de sync WooCommerce (para Fase 4, pero creado aquí para disponibilidad)
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_woo_sync_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    status ENUM('pending', 'success', 'failed') DEFAULT 'pending',
    woo_product_id BIGINT UNSIGNED DEFAULT NULL,
    woo_variation_id BIGINT UNSIGNED DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    attempts INT DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_status (status),
    KEY idx_synced_at (synced_at)
) COLLATE utf8mb4_unicode_ci;

-- Índices para auditoría mejorada
CREATE INDEX IF NOT EXISTS idx_audit_employee ON {PREFIX}riverso_audit_log(employee_id);
CREATE INDEX IF NOT EXISTS idx_audit_module ON {PREFIX}riverso_audit_log(module);
CREATE INDEX IF NOT EXISTS idx_audit_action_entity ON {PREFIX}riverso_audit_log(action, entity_type);
