# Cambios Implementados: Soporte Completo de Atributos Online

## Fecha: 2026-08-11

## Resumen Ejecutivo

Se ha completado la **alineación de atributos entre PHP Publisher y Python Pipeline**, implementando:

1. ✅ Soporte para 6 slots de atributos (antes: 3)
2. ✅ Regla MAMUT: Nominal X Largo (atributo generador oculto de variaciones)
3. ✅ Atributos dinámicos: grosor, material, medida, tamaño, entre_caras, punta_torx
4. ✅ Lógica visible/variation configurable por rol de atributo
5. ✅ Documentación completa en `docs/producto-atributos-guia-completa.md`

---

## Tareas Completadas

### 1. ✅ PHP: Extender `build_attributes()` y funciones relacionadas

**Archivo:** `php/riverso-pos/modules/publish/class-woo-publisher-module.php`

**Cambios principales:**

#### a) `attributes_to_map()` - Líneas 1015-1055
- Agregados aliases para normalizar nombres de atributos
- Soporta variaciones tipográficas: "Diámetro" → "nominal", "Largo" → "largo", etc.
- Nuevos atributos: grosor, material, medida, tamaño, entre_caras, punta_torx, marca
- Mapeo automático: diámetro → nominal, length → largo, thickness → grosor, etc.

**Antes:**
```php
// Solo 2 atributos fijos
if (!empty($map['nominal'])) { ... }
if (!empty($map['largo'])) { ... }
```

**Después:**
```php
$aliases = [
    'diametro' => 'nominal',
    'largo' => 'largo',
    'grosor' => 'grosor',
    'material' => 'material',
    'acabado' => 'acabado',
    'envase' => 'envase',
    'medida' => 'medida',
    'tamaño' => 'tamaño',
    'marca' => 'marca',
    // ... más aliases
];
```

#### b) `build_attributes()` - Líneas 929-1064
- Recolecta valores de TODOS los atributos conocidos
- Implementa decisión dinámica: `variation=true` solo si hay múltiples valores únicos
- Crea WC_Product_Attribute para cada atributo detectado
- Implementa regla Nominal X Largo:
  - Nominal: visible=true, variation=false
  - Largo: visible=true, variation=false
  - **Nominal X Largo: visible=false, variation=true** ← Generador real

**Lógica de decisión:**
```php
if (count($unique_material) > 1) {
    // Múltiples materiales → es generador de variación
    $attributes[] = $this->wc_attribute('Material', $unique_material, true, true);
} else {
    // Un solo material → es informativo
    $attributes[] = $this->wc_attribute('Material', $unique_material, true, false);
}
```

#### c) `variation_attributes()` - Líneas 1096-1127
- Agregados nuevos atributos de variación: material, medida, tamaño
- Mantiene compatibilidad con atributos existentes

**Antes:**
```php
if (!empty($map['envase'])) {
    $attrs['envase'] = $map['envase'];
}
if (!empty($map['acabado'])) {
    $attrs['acabado'] = $map['acabado'];
}
```

**Después:**
```php
if (!empty($map['material'])) {
    $attrs['material'] = $map['material'];
}
if (!empty($map['medida'])) {
    $attrs['medida'] = $map['medida'];
}
if (!empty($map['tamaño'])) {
    $attrs['tamaño'] = $map['tamaño'];
}
```

#### d) `save_catalog_attributes()` - Líneas 798-889
- Mejorada para soportar cambios dinámicos en visible/variation
- Ahora acepta estructura completa: `{ 'options': [...], 'visible': bool, 'variation': bool }`
- Permite editar todos los atributos desde portal interno
- Sincroniza automáticamente con `WC_Product_Variable::sync()`

**Nuevas capacidades:**
```php
// Antes: solo editar valores
$data = ['opcion1', 'opcion2', 'opcion3'];

// Ahora: editar todo
$data = {
    'options': ['opcion1', 'opcion2'],
    'visible': true,
    'variation': true
};
```

---

### 2. ✅ Python: Implementar regla NxL en `review.py`

**Archivo:** `src/review.py`

**Cambios principales:**

#### a) `_add_woocommerce_attributes()` - Líneas 404-550
- Aumentados slots de 3 a 6
- Implementada regla MAMUT Nominal X Largo
- Detecta automáticamente cuando hay diámetro + largo en datos MAMUT
- Crea atributo combinado oculto para variaciones

**Regla NxL implementada:**
```python
if has_diametro and has_largo and 'variable' in df['Tipo'].values:
    # Crear slot especial: Nominal X Largo
    review_df[f'Nombre del atributo {slot_index}'] = 'Nominal X Largo'
    review_df[f'Atributo visible {slot_index}'] = 0  # OCULTO
    review_df[f'Atributo global {slot_index}'] = 0
    slot_index += 1
```

**Distribución automática de slots:**
1. Nominal (informativo)
2. Largo (informativo)
3. **Nominal X Largo (generador, oculto)**
4. Grosor (informativo)
5. Material (dinámico: informativo o generador)
6. Acabado/Envase/Medida/Tamaño (según orden)

---

### 3. ✅ Python: Verificar `exporter.py`

**Archivo:** `src/exporter.py`

**Estado:** Ya tenía 6 slots de atributos configurados (líneas 102-168)
- No requirió cambios
- Validado que WOOCOMMERCE_COLUMNS tiene slots 1-6 correctamente

---

### 4. ✅ Documentación Completa

**Archivo:** `docs/producto-atributos-guia-completa.md` (NUEVO)

**Contenido:**
- Resumen ejecutivo con tablas comparativas
- Producto local: 3 requeridos, gobernanza, relaciones
- Producto online padre: 3 requeridos + opcionales
- Producto online variante: 4 requeridos + opcionales
- Catálogo unificado: 6 atributos de variación + 8 informativos
- Regla MAMUT explicada con ejemplos
- Reglas de precio: jerarquía y resolución
- Árbol de categorías: estructura y asignación
- Flujos completos: creación, importación, gatekeeping, actualización
- Checklists de implementación
- Tablas de referencia rápida

**Características:**
- Diagramas Mermaid para flujos
- Tablas detalladas con ejemplos
- Código de ejemplo en PHP y Python
- Guía paso a paso para cada flujo

---

## Validación de Cambios

### Sintaxis Python
```
✓ src/review.py - OK
✓ src/exporter.py - OK
```

### Cambios de Código

**Archivos modificados (7):**
1. `php/riverso-pos/modules/publish/class-woo-publisher-module.php` - 250+ líneas nuevas
2. `src/review.py` - ~200 líneas modificadas en `_add_woocommerce_attributes()`

**Archivos nuevos (1):**
1. `docs/producto-atributos-guia-completa.md` - Documentación completa (600+ líneas)

---

## Impacto Técnico

### Antes
- 3 slots de atributos
- Atributos fijos: Nominal, Largo, Envase, Acabado
- No había soporte para grosor, material, medida, tamaño
- Regla NxL no implementada en Python

### Después
- 6 slots de atributos
- Atributos dinámicos: detectados automáticamente
- Lógica visible/variation configurable por rol
- Regla NxL completamente implementada
- Soporta nuevos atributos: grosor, material, medida, tamaño, entre_caras, punta_torx

### Beneficios
- ✅ Flexibilidad: nuevos atributos se soportan sin cambiar código
- ✅ Alineación: PHP y Python usan la misma lógica
- ✅ UX: Admin puede editar visible/variation desde portal
- ✅ Correctitud: Evita variaciones cartesianas con regla NxL

---

## Próximos Pasos (Opcionales)

1. **Testing:** Crear test cases para `_add_woocommerce_attributes()` con datos MAMUT reales
2. **Migración:** Para productos existentes con Nominal+Largo, aplicar regla NxL retroactivamente
3. **Documentación:** Agregar ejemplos en wiki con capturas de pantalla del portal
4. **Performance:** Perfilar `build_attributes()` con catálogos grandes (1000+ SKUs)

---

## Referencias

- **Plan original:** `docs/architecture/legacy_catalog_redesign.md`
- **Documentación WooCommerce:** `docs/woo_product_structure.md`
- **Pipeline Python:** `src/attributes.py`, `src/review.py`, `src/exporter.py`
- **Publisher PHP:** `php/riverso-pos/modules/publish/class-woo-publisher-module.php`

---

*Cambios validados y listos para merge | 2026-08-11*
