# Guía de Migraciones SQL - Arquitectura de Productos

**Versión:** 1.0  
**Fecha:** Julio 2026  
**Orden de Ejecución:** Phase 12 → Phase 13 → Activator

---

## Phase 12: Consolidación de Familia

**Archivo:** `php/riverso-pos/migrations/phase12_family_consolidation_v1.sql`

**Acción:** Unifica dos esquemas de familia

```sql
-- Migra datos de equivalencia_grupo → equivalence_groups
INSERT IGNORE INTO riverso_equivalence_groups (codigo_grupo, nombre, ...)
SELECT CONCAT('LEGACY_', id), nombre, ...
FROM riverso_equivalencia_grupo eg
WHERE NOT EXISTS (SELECT 1 FROM riverso_equivalence_groups eg2 WHERE ...);

-- Migra datos de equivalencia_miembro → equivalence_members
INSERT IGNORE INTO riverso_equivalence_members (grupo_id, producto_base_id, ...)
SELECT eg_canon.id, em.producto_base_id, ...
FROM riverso_equivalencia_miembro em
...

-- Marca legacy como deprecated
ALTER TABLE riverso_equivalencia_grupo ADD COLUMN deprecated_at DATETIME;
ALTER TABLE riverso_equivalencia_miembro ADD COLUMN deprecated_at DATETIME;
```

**Tiempo estimado:** 1-5 segundos  
**Rollback:** Eliminar `deprecated_at` (datos quedan intactos)

---

## Phase 13: Asignación Proveedor → Familia

**Archivo:** `php/riverso-pos/migrations/phase13_supplier_to_family_v1.sql`

**Acción:** Agrega columnas para vincular `producto_proveedor` a familia

```sql
-- Obligatorio: permitir NULL en producto_base_id
ALTER TABLE riverso_producto_proveedor 
  MODIFY COLUMN producto_base_id BIGINT UNSIGNED DEFAULT NULL;

-- Nueva columna en producto_proveedor
ALTER TABLE riverso_producto_proveedor 
  ADD COLUMN grupo_id BIGINT UNSIGNED DEFAULT NULL;

-- Índice
ALTER TABLE riverso_producto_proveedor 
  ADD KEY idx_grupo_id (grupo_id);

-- FK constraint
ALTER TABLE riverso_producto_proveedor 
  ADD CONSTRAINT fk_pp_grupo_id 
  FOREIGN KEY (grupo_id) REFERENCES riverso_equivalence_groups(id) ON DELETE SET NULL;

-- Columnas de auditoría
ALTER TABLE riverso_producto_proveedor 
  ADD COLUMN assigned_to_family_at DATETIME DEFAULT NULL;
ALTER TABLE riverso_producto_proveedor 
  ADD COLUMN assigned_to_family_by BIGINT UNSIGNED DEFAULT NULL;

-- CHECK constraint (opcional; puede fallar en MySQL < 8.0.16)
ALTER TABLE riverso_producto_proveedor 
  ADD CONSTRAINT chk_producto_o_familia 
  CHECK ((producto_base_id IS NOT NULL AND grupo_id IS NULL) 
         OR (producto_base_id IS NULL AND grupo_id IS NOT NULL));
```

**Tiempo estimado:** 1-10 segundos  
**Rollback:** Reversible eliminando columnas (DROP COLUMN grupo_id, etc.)  
**Nota:** CHECK constraint puede no soportarse en todos los servidores; error será silencioso

---

## Activator: Funciones de Migración (Automático)

**Archivo:** `php/riverso-pos/includes/class-activator.php`

Se ejecutan automáticamente al activar el plugin o actualizar.

### Función: `consolidate_family_schema($prefix)`

Replica la migración SQL Phase 12 via PHP:

```php
// Inserta datos de equivalencia_grupo al esquema canónico
$wpdb->query("INSERT IGNORE INTO {$prefix}equivalence_groups ...");

// Marca legacy como deprecado
$wpdb->query("UPDATE {$prefix}equivalencia_grupo SET deprecated_at = NOW() ...");
```

**Idempotente:** Sí (usa `INSERT IGNORE`, `IF NOT EXISTS`)

### Función: `add_family_assignment_support($prefix)`

Replica la migración SQL Phase 13 via PHP:

```php
// Agrega columnas si no existen
$wpdb->query("ALTER TABLE {$prefix}producto_proveedor ADD COLUMN IF NOT EXISTS grupo_id ...");

// Crea FK
$wpdb->query("ALTER TABLE {$prefix}producto_proveedor 
  ADD CONSTRAINT IF NOT EXISTS fk_pp_grupo_id ...");
```

**Idempotente:** Sí (usa `IF NOT EXISTS`)

### Función: `integrate_local_store_products($prefix)`

Migra productos `tienda_local_productos` al dominio:

```php
// Lee productos sin vincular
$local_products = $wpdb->get_results("SELECT ... FROM {$prefix}tienda_local_productos ...");

// Para cada producto: matchea o crea nuevo producto_base
foreach ($local_products as $local_prod) {
  // Intenta matchear por barcode
  // Si no hay match: crea nuevo producto_base con origen_datos='tienda_local_legacy'
  // Crea entries en codigo_barra
  // Crea precio local
}

// Marca como integrados
$wpdb->query("UPDATE {$prefix}tienda_local_productos SET integrated_at = NOW() ...");
```

**Idempotente:** Sí (usa `IF NOT EXISTS` en ALTER, marca con `integrated_at`)

---

## Phase 11 (Mejorado): Migración de Envases

**Archivo:** `php/riverso-pos/migrations/phase11_catalog_codes_migrate.php`

**Función:** `riverso_migrate_packaging_codes_phase2()`

Migra envases con cantidades:

```php
// Migra riverso_envases → codigo_barra (con cantidad + unidad)
$envases = $wpdb->get_results("SELECT ... FROM {$prefix}riverso_envases ...");
foreach ($envases as $envase) {
  $wpdb->insert("{$prefix}codigo_barra", [
    'codigo' => $envase->sku_envase,
    'tipo' => 'internal',
    'cantidad' => $envase->cantidad_unidades,
    'envase_id' => $envase->id,
    ...
  ]);
}

// Migra riverso_factura_items con cantidad/unidad
$factura_items = $wpdb->get_results("SELECT ... FROM {$prefix}riverso_factura_items ...");
foreach ($factura_items as $item) {
  $wpdb->insert("{$prefix}codigo_barra", [
    'codigo' => $item->codigo_proveedor,
    'cantidad' => $item->cantidad, // <-- Ahora migrado
    'unidad_medida' => $item->unidad_medida, // <-- Ahora migrado
    ...
  ]);
}
```

**Idempotente:** Sí (usa `INSERT IGNORE`)  
**Ejecutada por:** Activator automáticamente (o script manual)

---

## Orden de Ejecución Recomendado

### Opción A: Automática (Recomendado)

1. Copiar archivos PHP actualizados a servidor
2. Activar plugin o actualizar si ya estaba activo
3. Activator ejecutará:
   - `consolidate_family_schema()` (Phase 12)
   - `add_family_assignment_support()` (Phase 13)
   - `integrate_local_store_products()` (Phase 14)
   - `migrate_legacy_barcodes()` (Phase 11 - ya existente)

### Opción B: Manual (Si necesitas control granular)

```bash
# En WP-CLI o phpmyadmin:

# 1. Phase 12
mysql -u root -p dbname < phase12_family_consolidation_v1.sql

# 2. Phase 13
mysql -u root -p dbname < phase13_supplier_to_family_v1.sql

# 3. Activar plugin (ejecuta Activator)
wp plugin activate riverso-pos
```

---

## Verificación Post-Migración

```sql
-- Verificar consolidación de familia
SELECT COUNT(*) as total_grupos FROM riverso_equivalence_groups;
SELECT COUNT(*) as total_miembros FROM riverso_equivalence_members;
SELECT COUNT(*) as deprecated_grupos FROM riverso_equivalencia_grupo WHERE deprecated_at IS NOT NULL;

-- Verificar Phase 13
SELECT COUNT(*) FROM riverso_producto_proveedor WHERE grupo_id IS NOT NULL;
SELECT COUNT(*) FROM riverso_producto_proveedor 
  WHERE (producto_base_id IS NULL AND grupo_id IS NULL) OR (producto_base_id IS NOT NULL AND grupo_id IS NOT NULL);

-- Verificar códigos de barra unificados
SELECT COUNT(*) FROM riverso_codigo_barra;
SELECT COUNT(*) FROM riverso_codigo_barra WHERE envase_id IS NOT NULL;

-- Verificar tienda local integrada
SELECT COUNT(*) FROM riverso_producto_base WHERE origen_datos = 'tienda_local_legacy';
SELECT COUNT(*) FROM riverso_tienda_local_productos WHERE integrated_at IS NOT NULL;
```

---

## Rollback (Si es necesario)

### Rollback Phase 13 (Más seguro)

```sql
-- Eliminar FK
ALTER TABLE riverso_producto_proveedor DROP CONSTRAINT fk_pp_grupo_id;

-- Eliminar CHECK constraint
ALTER TABLE riverso_producto_proveedor DROP CONSTRAINT chk_producto_o_familia;

-- Eliminar índice
ALTER TABLE riverso_producto_proveedor DROP INDEX idx_grupo_id;

-- Eliminar columnas
ALTER TABLE riverso_producto_proveedor 
  DROP COLUMN grupo_id,
  DROP COLUMN assigned_to_family_at,
  DROP COLUMN assigned_to_family_by;
```

### Rollback Phase 12

No reversible de forma limpia (requeriría restaurar desde backup de tablas legacy). Mejor: dejar como está, con las tablas deprecadas.

---

## Monitoreo en Producción

Después de migración:

1. Verificar no hay errores en logs de PHP
2. Probar escaneo de código → búsqueda en POS
3. Probar asignación de proveedor a familia (UI)
4. Probar cambio canal online/local
5. Probar cantidad agregada en regla de precio (ejemplo 350 uds)

---

**Responsable:** Equipo de Operaciones  
**Contacto:** [Usuario]  
**Aprobado:** [Pendiente]
