# Integración de Notas de Crédito y Pagos en invoices.php

Este documento describe cómo integrar las nuevas secciones UI para notas de crédito y pagos agrupados en el template `invoices.php`.

## Archivos relacionados

- **`invoices.php`** (principal): Template de lista y detalle de facturas
- **`invoices-credit-notes-payments.php`** (complementario): Fragmentos HTML nuevos

## Cambios requeridos en invoices.php

### 1. Importar el archivo de fragmentos

**Ubicación**: Al inicio del archivo, después de las verificaciones de seguridad.

```php
<?php
// Después de: if (!defined('ABSPATH')) { exit; }

// Importar fragmentos de NC y pagos
require_once __DIR__ . '/invoices-credit-notes-payments.php';
?>
```

### 2. Sección de Notas de Crédito en Modal de Upload

**Ubicación**: En `#form-upload-invoice`, DESPUÉS de la sección "Proveedor / emisor" y ANTES del `<div style="margin-top:20px;display:flex;">` que contiene el botón Submit.

**Buscar esta línea:**
```html
                    <div id="proveedor-form-wrap" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:640px;">
```

**Después de la sección de proveedor, agregar:**
```html
                    <!-- Incluir fragmento: Credit Note Section -->
                    <?php include __DIR__ . '/invoices-credit-notes-payments.php'; ?>
                    <!-- (Solo la sección: SECCIÓN 1) -->
```

O mejor aún, copiar el HTML de `#credit-note-section` directamente en `invoices.php`.

### 3. Columnas nuevas en la tabla principal

**Ubicación**: En `<thead><tr>` de `#invoices-table`

**Agregar después de la columna "Estado":**
```html
                <th style="width: 100px;">Saldo Efectivo</th>
                <th style="width: 80px;">Pago</th>
```

### 4. Pestañas en Modal de Detalle de Factura

**Ubicación**: En el modal de detalle (típicamente alrededor de línea 800+), donde están las pestañas de "Recepción", "Aprobación", etc.

**Agregar nueva pestaña:**
```html
        <?php if (current_user_can('riverso_process_invoices')): ?>
        <li class="invoice-detail-tab" data-tab="credit-notes">
            <span class="dashicons dashicons-money"></span> Notas de Crédito
        </li>
        <?php endif; ?>
        
        <?php if (current_user_can('riverso_manage_invoice_payments')): ?>
        <li class="invoice-detail-tab" data-tab="pagos">
            <span class="dashicons dashicons-money-alt"></span> Pagos
        </li>
        <?php endif; ?>
```

### 5. Contenido de Pestañas

**Ubicación**: Donde están los divs de contenido de pestañas (p.ej., `#invoice-detail-recepcion`, `#invoice-detail-aprobacion`, etc.)

**Agregar:**
```html
    <!-- Saldo Efectivo (mostrar al inicio de detalle) -->
    <?php if (current_user_can('riverso_view_invoices')): ?>
        <div id="invoice-detail-saldo-efectivo"><!-- Ver fragmento SECCIÓN 3 --></div>
    <?php endif; ?>
    
    <!-- Pestaña: Notas de Crédito -->
    <div class="invoice-detail-tab-content" id="invoice-detail-content-credit-notes" style="display:none;">
        <!-- Ver fragmento SECCIÓN 2 -->
    </div>
    
    <!-- Pestaña: Pagos -->
    <div class="invoice-detail-tab-content" id="invoice-detail-content-pagos" style="display:none;">
        <!-- Ver fragmento SECCIÓN 4 -->
    </div>
```

### 6. Modales nuevos

**Ubicación**: Al final del archivo, antes del cierre de `</div>` de `.wrap`

**Agregar:**
```html
    <!-- Modal: Crear Ticket de Pago -->
    <!-- Ver fragmento SECCIÓN 5 -->
    
    <!-- Modal: Ver Detalle de Pago -->
    <!-- Ver fragmento SECCIÓN 6 -->
```

## JavaScript necesario

El archivo `invoices-credit-notes-payments.php` incluye HTML y estilos. Se requiere agregar JavaScript para:

1. **Cargar facturas pendientes NC** cuando se abre el formulario de upload
2. **Detectar NC automáticamente** y mostrar la sección con selección de factura origen
3. **Calcular saldo efectivo** en tiempo real
4. **Validar reversa de inventario** (opcional)
5. **CRUD de Pagos**: crear, anular, visualizar tickets

Ejemplo de handlers AJAX:
```javascript
// En wp_ajax_riverso_upload_invoice, cuando se detecta NC:
if (data.tipo_dte === 61) {
    // Mostrar #credit-note-section
    // Cargar lista de facturas candidatas en #credit-note-origin-factura-id
    // Calcular saldo efectivo dinámicamente
}

// AJAX calls:
jQuery.post(ajaxurl, {
    action: 'riverso_preview_payment_total',
    factura_ids: [1, 2, 3],
    nonce: riversoNonce
}, function(response) {
    if (response.success) {
        // Actualizar #payment-total-preview
    }
});

jQuery.post(ajaxurl, {
    action: 'riverso_create_payment_ticket',
    factura_ids: [...],
    comprobante: ...,
    fecha_pago: ...,
    nonce: riversoNonce
}, function(response) {
    if (response.success) {
        // Mostrar success y recargar detalles
    }
});
```

## Cambios en el archivo de configuración

No se requieren cambios, ya que los AJAX handlers están registrados en `class-invoice-module.php`.

## Consideraciones de permisos

Las nuevas secciones deben estar protegidas con:
- `riverso_view_invoices`: Ver NC y pagos
- `riverso_process_invoices`: Crear/editar NC (reversa)
- `riverso_manage_invoice_payments`: Crear/anular pagos

## Base de datos

Asegúrate de que la migración Phase 16 haya sido ejecutada (tablas:
- `factura_referencias`
- `factura_pagos`
- `factura_pago_documentos`
- `factura_reversa_inventario`
- Columnas: `estado_pago`, `reception_started_at`, etc. en `facturas`

## Testing

Después de integrar:

1. Subir un XML de NC (TipoDTE=61)
2. Verificar que se muestre la sección de NC con selector de factura origen
3. Crear un ticket de pago con imagen de comprobante
4. Verificar que el saldo efectivo se calcule correctamente
5. Anular un ticket y validar que se actualice el estado_pago

## Notas de implementación

- Los fragmentos en `invoices-credit-notes-payments.php` usan IDs HTML únicos. Evita duplicar estos IDs.
- Los estilos están inline. Considera mover a un CSS separado si crece mucho.
- El archivo complementario puede reemplazarse o actualizarse sin tocar `invoices.php` principal.
