# Arquitectura: Notas de Crédito (NC) y Pagos Agrupados

## 1. Introducción

Este documento describe la arquitectura de los módulos de **Notas de Crédito (TipoDTE=61)** y **Pagos Agrupados (Tickets de Pago)** del sistema Riverso ERP.

**Propósito:**
- Gestionar notas de crédito como documentos asociados a facturas origen
- Calcular saldos efectivos considerando NC
- Crear tickets de pago agrupados con comprobantes digitales

**Alcance:**
- Backend (parseo XML, persistencia, lógica transaccional)
- Frontend (UI en admin y portal)
- BD (nuevas tablas y columnas en Phase 16)

---

## 2. Dominio de Negocio

### 2.1 Nota de Crédito

Una **Nota de Crédito (NC)** es un documento DTE de tipo **61** que:
- Referencia a una factura origen (Factura, Boleta, Guía, etc.)
- Reduce el monto adeudado por el cliente
- Contiene un nodo `<Referencia>` que apunta a la factura origen

**Flujo:**
1. Se recibe un XML con `TipoDTE=61`
2. Se intenta resolver automáticamente la factura origen desde:
   - `TpoDocRef` (tipo documento: 33, 34, 52, etc.)
   - `FolioRef` (folio de la factura)
   - RUT del emisor
3. Si `FolioRef=0` (global) o hay ambigüedad, requiere selección manual
4. Se vincula la NC con la factura origin en tabla `factura_referencias`
5. El saldo efectivo se calcula: `total_factura - abs(total_nc)`

### 2.2 Reversas de Inventario (Opcional)

Si la factura origen tuvo impacto en inventario (entrada de stock), la NC puede deshacer ese movimiento:
- Crear movimiento de **salida** opuesto
- Registrar ajuste de costo negativo en `factura_reversa_inventario`
- Requiere confirmación explícita del usuario (no automático)

### 2.3 Tickets de Pago Agrupado

Un **Ticket de Pago** es un documento administrativo que:
- Agrupa N facturas (producto, transporte, NC) bajo un único pago
- Tiene un comprobante asociado (imagen de transferencia)
- Congela los montos pagados por documento para auditoría
- Puede ser anulado (no eliminado) con razón de cancelación

**Flujo:**
1. Usuario selecciona múltiples facturas no pagadas
2. Sistema calcula saldo efectivo total (considerando NC)
3. Usuario sube comprobante (JPG/PNG/WebP, max 10 MB)
4. Se crea ticket con:
   - Número único (PAG-YYYY-NNNN)
   - Relación N:M en `factura_pago_documentos`
   - Comprobante almacenado con hash SHA256
5. Todos los documentos pasan a `estado_pago='pagada'`
6. Se registran eventos de auditoría

---

## 3. Diseño de Base de Datos (Phase 16)

### 3.1 Tabla: `factura_referencias`

Registra todos los nodos `<Referencia>` XML, con estado de resolución y metadatos de reversa.

```sql
CREATE TABLE {prefix}factura_referencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    
    -- Referencia XML
    factura_id BIGINT UNSIGNED NOT NULL,        -- FK a riverso_facturas (la NC)
    tipo_doc_ref INT NOT NULL,                  -- TpoDocRef (33, 34, 52, etc.)
    folio_ref VARCHAR(50) NOT NULL,             -- FolioRef (puede ser '0')
    ind_global TINYINT(1) DEFAULT 0,            -- IndGlobal (global vs. parcial)
    cod_ref INT DEFAULT NULL,                   -- CodRef (código SII)
    razon_ref VARCHAR(255) DEFAULT NULL,        -- RazonRef
    fecha_ref DATE DEFAULT NULL,                -- FchRef
    
    -- Resolución
    factura_origen_id BIGINT UNSIGNED,          -- FK resuelto
    estado_resolucion VARCHAR(50),              -- pendiente, resuelta_automatica, resuelta_manual, ambigua
    monto_descuento DECIMAL(12,2),              -- abs(total NC)
    
    -- Reversa de inventario
    estado_reversa_inventario VARCHAR(50),      -- sin_reversa, pendiente_aplicar, aplicada, cancelada
    reversa_aplicada_por BIGINT UNSIGNED,       -- user_id
    reversa_aplicada_at DATETIME,
    motivo_reversa TEXT,
    
    -- Auditoría
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY ux_factura_ref (factura_id, tipo_doc_ref, folio_ref, cod_ref),
    FOREIGN KEY fk_ref_factura (factura_id) REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE,
    FOREIGN KEY fk_ref_factura_origen (factura_origen_id) REFERENCES {prefix}riverso_facturas (id) ON DELETE SET NULL
);
```

### 3.2 Tabla: `factura_pagos` (Cabecera de Ticket)

```sql
CREATE TABLE {prefix}factura_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    
    numero_ticket VARCHAR(50) NOT NULL UNIQUE, -- PAG-2026-0001
    estado VARCHAR(50) DEFAULT 'activo',       -- activo, cancelado, reversado
    monto_total DECIMAL(12,2),                 -- Lectura congelada al crear
    moneda VARCHAR(10) DEFAULT 'CLP',
    fecha_pago DATE,
    
    -- Comprobante
    comprobante_nombre_original VARCHAR(255),
    comprobante_ruta_relativa VARCHAR(255),   -- /riverso/pagos/hash.jpg
    comprobante_mime_type VARCHAR(50),        -- image/jpeg, image/png, image/webp
    comprobante_tamaño INT,
    comprobante_hash VARCHAR(64),             -- SHA256
    
    notas TEXT,
    creado_por BIGINT UNSIGNED,               -- user_id
    cancelado_por BIGINT UNSIGNED,
    cancelado_at DATETIME,
    razon_cancelacion TEXT,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_estado (estado),
    KEY idx_fecha_pago (fecha_pago)
);
```

### 3.3 Tabla: `factura_pago_documentos` (Relación N:M)

```sql
CREATE TABLE {prefix}factura_pago_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    
    pago_id BIGINT UNSIGNED NOT NULL,
    factura_id BIGINT UNSIGNED NOT NULL,
    monto_pagado DECIMAL(12,2) NOT NULL,      -- Congelado al crear
    tipo_aplicacion VARCHAR(50),               -- saldo_efectivo, monto_total, etc.
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY ux_pago_factura (pago_id, factura_id),
    FOREIGN KEY fk_pago_doc_pago (pago_id) REFERENCES {prefix}factura_pagos (id) ON DELETE CASCADE,
    FOREIGN KEY fk_pago_doc_factura (factura_id) REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE
);
```

### 3.4 Tabla: `factura_reversa_inventario` (Auditoría)

```sql
CREATE TABLE {prefix}factura_reversa_inventario (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    
    referencia_id BIGINT UNSIGNED NOT NULL,
    factura_id BIGINT UNSIGNED NOT NULL,      -- Factura origen
    factura_item_id BIGINT UNSIGNED,
    producto_id BIGINT UNSIGNED NOT NULL,
    movimiento_id BIGINT UNSIGNED,            -- FK a riverso_movimientos (si fue registrado)
    
    cantidad_revertida DECIMAL(12,4) NOT NULL,
    costo_unitario_original DECIMAL(12,4),
    estado VARCHAR(50),                        -- aplicada, anulada
    creado_por BIGINT UNSIGNED,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY fk_reversa_referencia (referencia_id) REFERENCES {prefix}factura_referencias (id) ON DELETE CASCADE,
    FOREIGN KEY fk_reversa_factura (factura_id) REFERENCES {prefix}riverso_facturas (id) ON DELETE CASCADE
);
```

### 3.5 Columnas Nuevas en `riverso_facturas`

```sql
ALTER TABLE {prefix}riverso_facturas ADD COLUMN (
    estado_pago VARCHAR(50) DEFAULT 'no_pagada',        -- no_pagada, parcialmente_pagada, pagada, cancelada
    reception_started_at DATETIME,
    reception_completed_at DATETIME,
    approved_at DATETIME
);
```

---

## 4. Servicios Backend (PHP)

### 4.1 `Riverso_Credit_Note_Service`

**Responsabilidades:**
- Detectar si una factura es NC
- Resolver automáticamente factura origen
- Vincular/desvin cular NC
- Calcular saldo efectivo
- Gestionar reversas de inventario

**Métodos clave:**
```php
is_credit_note($tipo_dte);                  // bool

resolve_origen_factura($factura, $rut);     // ['factura_id' => int, 'estado' => string, ...]

link_credit_note(                           // Insertar en factura_referencias
    $factura_nc_id,
    $factura_origen_id,
    $referencia,
    ['reversa_inventario' => bool, 'user_id' => int]
);

calculate_saldo_efectivo($factura_id);      // float: monto - abs(NC)

unlink_credit_note($referencia_id, $options); // Desvinculación
```

### 4.2 `Riverso_Payment_Service`

**Responsabilidades:**
- Crear tickets de pago
- Validar y subir comprobantes
- Anular tickets
- Gestionar descarga de comprobantes

**Métodos clave:**
```php
generate_ticket_number();                   // string: "PAG-2026-0001"

validate_and_upload_comprobante($file);     // ['ruta', 'mime', 'tamaño', 'hash', ...]

create_payment_ticket(                      // Crear ticket N:M
    $factura_ids,
    $comprobante,
    ['fecha_pago' => string, 'user_id' => int]
);

cancel_payment_ticket($pago_id, $options);  // Marcar como cancelado

get_payment_ticket($pago_id);               // Datos + documentos relacionados

get_comprobante_path($pago_id);             // Validación segura de ruta
```

---

## 5. Flujos de Integración

### 5.1 Flujo: Subir Nota de Crédito

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Usuario sube XML con TipoDTE=61                          │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. parse_dte_xml(): Extraer <Referencia> y datos básicos   │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Detectar is_credit_note() = true                         │
└──────────────────┬──────────────────────────────────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
  ┌─────────────┐      ┌─────────────────┐
  │ FolioRef≠0  │      │ FolioRef=0      │
  │ (Resolver)  │      │ (Ambiguo)       │
  └──────┬──────┘      └────────┬────────┘
         │                      │
         ▼                      ▼
   resolve_origen_   ┌──────────────────────┐
   factura()         │ Solicitar selección  │
         │           │ manual del usuario   │
         │           └──────────┬───────────┘
         │                      │
         └──────────┬───────────┘
                    │
                    ▼
         ┌──────────────────────┐
         │ Guardar factura NC   │
         └──────────┬───────────┘
                    │
                    ▼
         ┌──────────────────────┐
         │ link_credit_note()   │
         │ → Insertar en        │
         │   factura_referencias│
         └──────────┬───────────┘
                    │
        ┌───────────┴───────────┐
        │                       │
        ▼                       ▼
   ┌─────────────┐    ┌────────────────┐
   │ Sin reversa │    │ Con reversa    │
   │ (estado:    │    │ (estado:       │
   │  sin_reversa)    │  pendiente_    │
   │             │    │   aplicar)     │
   └─────────────┘    └────────────────┘
```

### 5.2 Flujo: Crear Ticket de Pago

```
┌────────────────────────────────────────────┐
│ 1. Usuario selecciona facturas no pagadas  │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 2. AJAX: ajax_preview_payment_total()      │
│    - Calcular saldo_efectivo por factura  │
│    - Validar sin pagos previos             │
│    - Mostrar total a usuario               │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 3. Usuario sube comprobante + confirma     │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 4. AJAX: ajax_create_payment_ticket()      │
│    - Validar archivo (JPG/PNG/WebP)        │
│    - Subir y hash SHA256                   │
│    - Iniciar transacción DB                │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 5. Crear registro en factura_pagos         │
│    - Generar número_ticket (PAG-...)       │
│    - Guardar ruta_relativa comprobante     │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 6. Crear relaciones N:M                    │
│    - Insertar en factura_pago_documentos   │
│    - Congelar monto_pagado x documento    │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 7. Actualizar estado_pago → 'pagada'       │
│    en todas las facturas                   │
└──────────────────┬───────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────────┐
│ 8. Auditoría: Registrar evento             │
│    'invoice.payment_ticket_created'        │
└────────────────────────────────────────────┘
```

---

## 6. Validaciones y Reglas de Negocio

### 6.1 NC (Notas de Crédito)

| Validación | Regla |
|-----------|-------|
| **Factura origen** | Debe existir o debe seleccionarse manualmente |
| **Saldo efectivo** | `factura - abs(NC) ≥ 0` (no puede ser negativo) |
| **FolioRef=0** | Requiere selección manual (estado: ambigua) |
| **Múltiples NC** | Se suman todos los abs(total) para calcular descuento |
| **Reversas** | Opcional; requiere confirmación explícita |
| **Eliminación** | Factura con NC activa NO se puede eliminar |

### 6.2 Pagos Agrupados

| Validación | Regla |
|-----------|-------|
| **Documentos únicos** | Una factura no puede estar en 2 tickets activos |
| **Saldo > 0** | Total debe ser positivo |
| **Comprobante** | JPG/PNG/WebP, max 10 MB, SHA256 |
| **Número de ticket** | Único, formato: PAG-YYYY-NNNN |
| **Anulación** | Marca como `cancelado`, no elimina |
| **Reversión** | Cancelar ticket restaura `estado_pago='no_pagada'` |

---

## 7. Seguridad

### 7.1 Acceso

- **Crear NC**: `riverso_process_invoices`
- **Ver NC**: `riverso_view_invoices`
- **Crear pagos**: `riverso_manage_invoice_payments`
- **Ver pagos**: `riverso_view_invoices`

### 7.2 Archivo (Comprobante)

- **Ubicación**: `wp-content/uploads/riverso/pagos/`
- **Nombre**: Hash aleatorio (bin2hex) + extensión original
- **MIME check**: `finfo_file()` en servidor
- **Descarga**: Ruta validada, sin traversal (`../`)
- **Hash**: SHA256 almacenado, puede verificarse

### 7.3 Datos Sensibles

- `monto_pagado` congelado en `factura_pago_documentos`
- `razon_cancelacion` registrada pero inmutable (auditoría)
- Reversas de inventario no eliminan historial, solo marcan estado

---

## 8. Pruebas

### 8.1 Unit Tests

```bash
cd tests/
python -m pytest test_credit_notes_integration.py::TestCreditNoteParsing -v
python -m pytest test_credit_notes_integration.py::TestCreditNoteBusinessLogic -v
python -m pytest test_credit_notes_integration.py::TestCreditNoteDetection -v
```

### 8.2 Smoke Test

```bash
python tests/test_credit_notes_integration.py
```

Verifica:
- Parseo de todos los fixtures XML
- Cálculos de saldo efectivo
- Detección de NC global vs. folio válido

### 8.3 Integration Test (WordPress)

```bash
# 1. Subir factura regular (TipoDTE=33)
# 2. Subir NC (TipoDTE=61) con FolioRef válido
# 3. Verificar que se resolvió automáticamente
# 4. Crear ticket de pago con ambos documentos
# 5. Verificar estado_pago='pagada'
# 6. Anular ticket y verificar reversión
```

---

## 9. Futuras Mejoras

1. **Reversas parciales**: Permitir reversa de solo algunos ítems de la NC
2. **Reportes**: Dashboard de NC no procesadas, pagos pendientes
3. **Automatización**: Crear tickets por proveedor/fecha automáticamente
4. **Integración bancaria**: Importar comprobantes de extracto bancario
5. **Notificaciones**: Alertar cuando NC agota el saldo de factura

---

## 10. Referencias

- `php/riverso-pos/modules/invoices/class-credit-note-service.php`
- `php/riverso-pos/modules/invoices/class-payment-service.php`
- `php/riverso-pos/modules/invoices/class-invoice-module.php`
- `php/riverso-pos/migrations/phase16_invoice_credit_notes_payments_v1.sql`
- `src/xml_invoice_parser.py` (DTEParser contrato)
- `tests/test_credit_notes_integration.py`
