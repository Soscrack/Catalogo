<?php
/**
 * Template: Lista de Facturas
 */

if (!defined('ABSPATH')) {
    exit;
}

$default_intake_mode = 'solo_costos';
?>

<div class="wrap riverso-invoices">
    <h1>
        <span class="dashicons dashicons-media-spreadsheet"></span>
        Facturas DTE
        <?php if (current_user_can('riverso_process_invoices')): ?>
        <button type="button" class="page-title-action" id="btn-upload-invoice">
            <span class="dashicons dashicons-upload"></span> Subir XML
        </button>
        <?php endif; ?>
    </h1>

    <!-- Filtros -->
    <div class="riverso-filters">
        <select id="filter-estado">
            <option value="">Todos los estados</option>
            <option value="recibido">Recibido</option>
            <option value="parcial">Parcial</option>
            <option value="procesado">Procesado</option>
            <option value="rechazado">Rechazado</option>
            <option value="sin_vincular">Sin vincular (flete / NC)</option>
            <option value="vinculado">Flete vinculado</option>
        </select>
        
        <select id="filter-proveedor">
            <option value="">Todos los proveedores</option>
            <?php
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $proveedores = $wpdb->get_results("SELECT id, nombre FROM {$prefix}proveedores WHERE activo = 1 ORDER BY nombre");
            foreach ($proveedores as $prov) {
                echo '<option value="' . esc_attr($prov->id) . '">' . esc_html($prov->nombre) . '</option>';
            }
            ?>
        </select>
        
        <input type="date" id="filter-fecha-desde" placeholder="Desde">
        <input type="date" id="filter-fecha-hasta" placeholder="Hasta">
        
        <select id="filter-tipo-confirmado">
            <option value="">Todos los tipos</option>
            <option value="0">Tipo pendiente de confirmar</option>
            <option value="1">Tipos confirmados</option>
        </select>

        <input type="search" id="filter-search" class="invoices-search" placeholder="Buscar folio o monto…" autocomplete="off">
        
        <button type="button" class="button" id="btn-filter">
            <span class="dashicons dashicons-filter"></span> Filtrar
        </button>
    </div>

    <div class="riverso-filters invoices-list-controls">
        <label class="invoices-control">
            Mostrar
            <select id="invoices-per-page">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </label>
        <label class="invoices-control">
            Ordenar por
            <select id="invoices-orderby">
                <option value="created_at" selected>Fecha de ingreso</option>
                <option value="fecha_emision">Fecha del documento</option>
                <option value="folio">Folio</option>
                <option value="monto_total">Monto total</option>
                <option value="proveedor_nombre">Proveedor</option>
                <option value="estado">Estado</option>
                <option value="tipo_dte">Tipo DTE</option>
            </select>
        </label>
        <label class="invoices-control">
            Dirección
            <select id="invoices-order">
                <option value="DESC" selected>Descendente</option>
                <option value="ASC">Ascendente</option>
            </select>
        </label>
    </div>

    <!-- Tabla de facturas -->
    <table class="wp-list-table widefat striped riverso-data-table" id="invoices-table">
        <thead>
            <tr>
                <th style="width: 80px;">Folio</th>
                <th style="width: 80px;">Tipo</th>
                <th class="col-proveedor">Proveedor</th>
                <th style="width: 100px;">Fecha</th>
                <th style="width: 120px;">Total</th>
                <th style="width: 100px;">Items</th>
                <th style="width: 120px;" class="col-estado-help">
                    Estado
                    <button type="button" class="estado-help-btn" id="btn-estado-help" aria-expanded="false" aria-controls="estado-help-panel" title="Qué significa cada estado">
                        <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                        <span class="screen-reader-text">Ayuda: estados de factura</span>
                    </button>
                    <div id="estado-help-panel" class="estado-help-panel" hidden>
                        <p class="estado-help-title">Estados del documento</p>
                        <ul>
                            <li><strong>Recibido</strong> — XML cargado; aún sin vincular ítems a productos (o NC ya asociada a su origen).</li>
                            <li><strong>Parcial</strong> — Algunos ítems vinculados; faltan otros por asociar o rechazar.</li>
                            <li><strong>Procesado</strong> — Todos los ítems quedan vinculados o rechazados.</li>
                            <li><strong>Rechazado</strong> — Documento descartado / no se procesa.</li>
                            <li><strong>Sin vincular</strong> / <strong>NC sin folio</strong> — Flete o nota de crédito pendiente de asociar a su factura origen.</li>
                            <li><strong>Vinculado</strong> / <strong>Vinculada</strong> — Flete o NC ya ligada a la factura de productos correspondiente.</li>
                            <li><strong>Procesado</strong> (gastos) — Gasto operacional registrado; no requiere SKU ni inventario.</li>
                            <li><strong>Recibido / Parcial / Procesado</strong> (guía) — Guía de despacho: códigos y costos sin bodega.</li>
                        </ul>
                    </div>
                </th>
                <th style="width: 120px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="invoices-list">
            <tr class="loading-row">
                <td colspan="8" style="text-align: center; padding: 40px;">
                    <span class="spinner is-active" style="float: none;"></span>
                    Cargando facturas...
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Paginación -->
    <div class="tablenav bottom">
        <div class="tablenav-pages invoices-pagination" id="pagination-info">
            <button type="button" class="button" id="invoices-prev" style="display:none;">← Anterior</button>
            <span id="invoices-page-info"></span>
            <button type="button" class="button" id="invoices-next" style="display:none;">Siguiente →</button>
        </div>
    </div>
</div>

<!-- Modal: Subir factura -->
<div id="modal-upload-invoice" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" id="upload-modal-content">
        <div class="riverso-modal-header">
            <h2>Subir Factura XML</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-upload-invoice" enctype="multipart/form-data">
                <!-- Paso 1: seleccionar archivo -->
                <div id="upload-step-select">
                    <div class="upload-mode-toggle">
                        <label><input type="radio" name="upload_mode" value="single" checked> Un XML (con vista previa)</label>
                        <label><input type="radio" name="upload_mode" value="bulk"> Carga masiva</label>
                    </div>

                    <div id="upload-single-wrap">
                        <p class="description" style="margin-bottom: 12px;">
                            Suba <strong>un archivo XML</strong>. El sistema lo analizará y detectará si corresponde a
                            <strong>productos</strong> o a un <strong>transportista/flete</strong> antes de procesarlo.
                        </p>
                        <input type="file" id="xml-file-input" name="xml_file" accept=".xml" style="display: none;">
                        <div class="upload-area" id="upload-dropzone">
                            <span class="dashicons dashicons-upload" style="font-size: 48px; width: 48px; height: 48px;"></span>
                            <p>Arrastra el XML aquí</p>
                        </div>
                        <div class="upload-toolbar">
                            <button type="button" class="button button-primary" id="btn-browse-xml">
                                <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                            </button>
                        </div>
                        <p id="upload-file-name" class="description" style="margin-top:10px;text-align:center;"></p>
                    </div>

                    <div id="upload-bulk-wrap" style="display:none;">
                        <p class="description" style="margin-bottom: 12px;">
                            Seleccione <strong>varios XML</strong>. Se procesarán en secuencia con detección automática.
                            Los fletes quedan <strong>sin asignar</strong> hasta que los vincule manualmente.
                        </p>
                        <input type="file" id="xml-bulk-input" accept=".xml" multiple style="display: none;">
                        <div class="upload-area" id="bulk-dropzone">
                            <span class="dashicons dashicons-media-default" style="font-size: 48px; width: 48px; height: 48px;"></span>
                            <p>Arrastra varios XML aquí</p>
                        </div>
                        <div class="upload-toolbar">
                            <button type="button" class="button button-primary" id="btn-browse-xml-bulk">
                                <span class="dashicons dashicons-open-folder"></span> Buscar archivos XML
                            </button>
                            <button type="button" class="button button-primary" id="btn-start-bulk" disabled>
                                <span class="dashicons dashicons-controls-play"></span> Procesar todos
                            </button>
                        </div>
                        <div id="bulk-queue" class="bulk-queue" style="display:none;"></div>
                    </div>
                </div>

                <!-- Paso 2: preview y confirmación -->
                <div id="upload-step-confirm" style="display:none;">
                    <div id="intake-gaps-inline" style="display:none;margin-bottom:12px;padding:10px 12px;background:#fff8e5;border:1px solid #f0c36d;border-radius:6px;font-size:13px;"></div>
                    <div id="upload-xml-preview" style="padding:12px;background:#f0f6fc;border:1px solid #c3d9f0;border-radius:6px;margin-bottom:14px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span class="dashicons dashicons-visibility" style="color:#2271b1;"></span>
                            <strong>Vista previa — confirme antes de procesar</strong>
                        </div>
                        <div id="xml-preview-summary" style="font-size:13px;color:#1d2327;"></div>
                        <table class="wp-list-table widefat striped" id="xml-preview-items" style="margin-top:10px;font-size:12px;">
                            <thead><tr><th>#</th><th class="col-desc">Descripción</th><th>Tipo</th><th style="text-align:right;">Monto</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <h3 style="margin:0 0 8px;">Tipo de documento</h3>
                    <div id="tipo-sugerencia-box" style="display:none;margin-bottom:10px;padding:10px 12px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;">
                        <p id="detection-motivo" class="description" style="margin:0 0 8px;"></p>
                        <button type="button" class="button button-primary" id="btn-aceptar-tipo-sugerido">Aceptar sugerencia</button>
                    </div>
                    <input type="hidden" id="tipo-sugerido" value="productos">
                    <label style="display:block;margin-bottom:8px;padding:8px;background:#fef3c7;border-radius:4px;">
                        <input type="radio" name="documento_tipo" value="por_confirmar" checked>
                        <strong>Tipo por confirmar</strong>
                        <em class="description" style="display:block;margin:2px 0 0 22px;">Se guardará con el tipo sugerido y se creará una tarea para confirmarlo después</em>
                    </label>
                    <label class="tipo-radio-row" data-tipo="productos" style="display:block;margin-bottom:6px;">
                        <input type="radio" name="documento_tipo" value="productos">
                        <span id="label-tipo-productos">Factura de productos</span>
                    </label>
                    <label class="tipo-radio-row" data-tipo="envio" style="display:block;margin-bottom:6px;">
                        <input type="radio" name="documento_tipo" value="envio">
                        <span id="label-tipo-envio">Factura de transportista / flete</span>
                    </label>
                    <label class="tipo-radio-row" data-tipo="nota_credito" style="display:block;margin-bottom:6px;">
                        <input type="radio" name="documento_tipo" value="nota_credito">
                        <span id="label-tipo-nc">Nota de crédito</span>
                    </label>
                    <label class="tipo-radio-row" data-tipo="guia_despacho" style="display:block;margin-bottom:6px;">
                        <input type="radio" name="documento_tipo" value="guia_despacho">
                        <span id="label-tipo-guia">Guía de despacho</span>
                        <em class="description" style="display:block;margin:2px 0 0 22px;">TipoDTE 52 — registra códigos proveedor y costos, sin inventario</em>
                    </label>
                    <label class="tipo-radio-row" data-tipo="gastos" style="display:block;margin-bottom:12px;">
                        <input type="radio" name="documento_tipo" value="gastos">
                        <span id="label-tipo-gastos">Gastos operacionales</span>
                        <em class="description" style="display:block;margin:2px 0 0 22px;">Servicios / ítems que no se venden (luz, agua, etc.) — sin productos ni SKU</em>
                    </label>

                    <div id="link-factura-wrap" style="display:none;margin-bottom:14px;padding:10px;background:#fff8e5;border-radius:4px;">
                        <label><strong>Vincular flete a factura de productos</strong> <em>(opcional — puede asignar después)</em></label>
                        <select id="link-factura-productos-id" style="width:100%;margin-top:6px;">
                            <option value="">— Dejar sin asignar por ahora —</option>
                        </select>
                    </div>

                    <div id="credit-note-section" style="display:none;margin-bottom:14px;padding:12px;background:#f0f6fc;border-radius:6px;border-left:4px solid #2271b1;">
                        <h3 style="margin:0 0 8px;">Asociación de Nota de Crédito</h3>
                        <p id="credit-note-ref-info" class="description" style="margin-bottom:8px;"></p>
                        <p id="credit-note-resolution-msg" class="description" style="margin-bottom:10px;"></p>
                        <label style="display:block;margin-bottom:6px;">
                            <strong>Vincular a folio de productos o flete</strong>
                        </label>
                        <div class="folio-search-wrap" style="position:relative;max-width:520px;">
                            <input type="text" id="credit-note-folio-search" class="regular-text" style="width:100%;"
                                   placeholder="Buscar por folio, proveedor o RUT…" autocomplete="off">
                            <input type="hidden" id="credit-note-origin-factura-id" value="">
                            <div id="credit-note-folio-results" class="folio-search-results" style="display:none;"></div>
                        </div>
                        <p id="credit-note-selected-label" class="description" style="margin-top:6px;">Sin vincular — se guardará pendiente del folio.</p>
                        <button type="button" class="button button-small" id="credit-note-clear-origin" style="display:none;margin-top:4px;">Quitar vínculo / dejar pendiente</button>
                        <label style="display:block;margin-top:10px;">
                            <input type="checkbox" id="credit-note-reversa-inventario">
                            Aplicar reversa de inventario (opcional)
                        </label>
                    </div>

                    <div id="opciones-productos-wrap">
                        <h3 style="margin-bottom:8px;">Modo de ingreso</h3>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="radio" name="modo_ingreso" value="solo_costos" checked>
                            Solo registrar costos <em>(sin aumento de inventario)</em>
                        </label>
                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" name="modo_ingreso" value="recepcion">
                            Recepción completa <em>(aumenta inventario)</em>
                        </label>
                    </div>

                    <h3 style="margin-bottom:8px;">Proveedor / emisor</h3>
                    <div style="margin-bottom:10px;">
                        <label><input type="radio" name="proveedor_modo" value="xml" checked> Datos del XML</label><br>
                        <label><input type="radio" name="proveedor_modo" value="existente"> Proveedor existente</label><br>
                        <label><input type="radio" name="proveedor_modo" value="nuevo"> Editar manualmente</label>
                    </div>
                    <div id="proveedor-select-wrap" style="display:none;margin-bottom:10px;">
                        <select id="proveedor-existente-id" style="width:100%;max-width:400px;">
                            <option value="">— Seleccionar —</option>
                        </select>
                    </div>
                    <div id="proveedor-form-wrap" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:640px;">
                        <div><label>RUT</label><input type="text" id="prov-rut" class="regular-text" style="width:100%" readonly></div>
                        <div><label>Nombre</label><input type="text" id="prov-nombre" class="regular-text" style="width:100%"></div>
                        <div><label>Giro</label><input type="text" id="prov-giro" class="regular-text" style="width:100%"></div>
                        <div><label>Comuna</label><input type="text" id="prov-comuna" class="regular-text" style="width:100%"></div>
                        <div style="grid-column:1/-1;"><label>Dirección</label><input type="text" id="prov-direccion" class="regular-text" style="width:100%"></div>
                    </div>
                    <p id="proveedor-status" class="description" style="margin-top:8px;"></p>
                </div>

                <div id="upload-result" style="margin-top: 15px;"></div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-upload">Cancelar</button>
            <button type="button" class="button" id="btn-change-xml" style="display:none;">Cambiar archivo</button>
            <button type="button" class="button button-primary" id="btn-process-upload" disabled style="display:none;">
                <span class="dashicons dashicons-yes"></span> Confirmar y procesar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Completar datos faltantes -->
<div id="modal-intake-missing" class="riverso-modal riverso-modal-stacked" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 520px;">
        <div class="riverso-modal-header">
            <h2>Completar datos</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p id="intake-missing-intro" class="description" style="margin-bottom: 12px;">
                Faltan datos para registrar esta factura en el sistema. Complete los campos y confirme.
            </p>
            <div id="intake-missing-fields"></div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-intake-missing-cancel">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-intake-missing-save">
                Guardar y continuar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Detalle de factura -->
<div id="modal-invoice-detail" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content riverso-modal-large">
        <div class="riverso-modal-header">
            <h2>Detalle de Factura <span id="detail-folio"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="invoice-header-info">
                <div class="info-grid">
                    <div class="info-item">
                        <label>Proveedor:</label>
                        <span id="detail-proveedor"></span>
                    </div>
                    <div class="info-item">
                        <label>RUT:</label>
                        <span id="detail-rut"></span>
                    </div>
                    <div class="info-item">
                        <label>Fecha:</label>
                        <span id="detail-fecha"></span>
                    </div>
                    <div class="info-item">
                        <label>Total:</label>
                        <span id="detail-total" class="amount"></span>
                    </div>
                </div>
            </div>

            <div id="detail-tipo-confirm-section" style="margin-bottom:16px;padding:12px;background:#f8fafc;border-left:4px solid #64748b;border-radius:6px;">
                <h3 style="margin:0 0 8px;" id="detail-tipo-title">Tipo de documento</h3>
                <p class="description" id="detail-tipo-help" style="margin-bottom:10px;">Puede confirmar o cambiar el tipo de este documento.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <select id="detail-tipo-select" style="flex:1;min-width:200px;">
                        <option value="productos">Productos</option>
                        <option value="envio">Flete</option>
                        <option value="nota_credito">Nota de Crédito</option>
                        <option value="guia_despacho">Guía de Despacho</option>
                        <option value="gastos">Gastos</option>
                    </select>
                    <button type="button" class="button button-primary" id="btn-confirm-tipo">Guardar tipo</button>
                </div>
            </div>

            <div id="detail-shipping-section" style="display:none;margin-bottom:16px;padding:12px;background:#f0f6fc;border-radius:6px;">
                <h3 style="margin:0 0 10px;">Fletes vinculados</h3>
                <div id="detail-shipping-linked"></div>
                <div id="detail-shipping-assign" style="margin-top:12px;display:none;">
                    <label><strong>Asignar flete pendiente</strong></label>
                    <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
                        <select id="detail-assign-flete-id" style="flex:1;min-width:200px;"></select>
                        <button type="button" class="button button-primary" id="btn-assign-flete">Vincular flete</button>
                    </div>
                </div>
            </div>

            <div id="detail-envio-assign-section" style="display:none;margin-bottom:16px;padding:12px;background:#fff8e5;border-radius:6px;">
                <h3 style="margin:0 0 8px;">Vincular a facturas de productos</h3>
                <p class="description" style="margin-bottom:8px;">Un mismo flete puede repartirse entre varias facturas de productos.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="detail-envio-target-id" style="flex:1;min-width:200px;"></select>
                    <button type="button" class="button button-primary" id="btn-envio-assign">Vincular</button>
                </div>
                <div id="detail-envio-linked-info" style="margin-top:10px;display:none;"></div>
                <button type="button" class="button" id="btn-envio-unassign" style="margin-top:8px;display:none;">Desvincular todas</button>
            </div>

            <div id="detail-credit-note-section" style="display:none;margin-bottom:16px;padding:12px;background:#eef6ff;border-radius:6px;border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 8px;">Nota de crédito — vínculo a folio</h3>
                <div id="detail-nc-current" class="description" style="margin-bottom:10px;"></div>
                <label style="display:block;margin-bottom:6px;"><strong>Buscar folio de productos o flete</strong></label>
                <div class="folio-search-wrap" style="position:relative;max-width:520px;">
                    <input type="text" id="detail-nc-folio-search" class="regular-text" style="width:100%;"
                           placeholder="Buscar por folio, proveedor o RUT…" autocomplete="off">
                    <input type="hidden" id="detail-nc-origen-id" value="">
                    <div id="detail-nc-folio-results" class="folio-search-results" style="display:none;"></div>
                </div>
                <p id="detail-nc-selected-label" class="description" style="margin-top:6px;"></p>
                <button type="button" class="button button-primary" id="btn-detail-nc-link" style="margin-top:8px;">Vincular a folio seleccionado</button>
            </div>
            
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0 8px;">
                <h3 style="margin:0;">Items</h3>
                <label style="margin:0;white-space:nowrap;font-size:12px;">
                    <input type="checkbox" id="toggle-precio-decimales">
                    Precio unitario con decimales <em>(hasta 3)</em>
                </label>
            </div>
            <table class="wp-list-table widefat striped riverso-items-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th style="width: 100px;">Código Prov.</th>
                        <th class="col-desc">Descripción</th>
                        <th style="width: 50px;">Cant.</th>
                        <th style="width: 90px;">Precio</th>
                        <th style="width: 70px;">Dsc/Rec</th>
                        <th style="width: 90px;">Neto final</th>
                        <th style="width: 90px;">Bruto final</th>
                        <th style="width: 220px;">SKU Local</th>
                        <th style="width: 90px;">Estado</th>
                        <th style="width: 70px;">Acción</th>
                    </tr>
                </thead>
                <tbody id="detail-items">
                </tbody>
            </table>

            <div id="detail-audit-section" style="margin-top:18px;padding:12px;background:#f8fafc;border-radius:6px;border-left:4px solid #64748b;">
                <h3 style="margin:0 0 8px;">Auditoría</h3>
                <ul id="detail-audit-list" style="margin:0;padding-left:18px;font-size:12px;color:#3c434a;"></ul>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-detail">Cerrar</button>
        </div>
    </div>
</div>

<div id="modal-sku-history" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content" style="max-width:760px;">
        <div class="riverso-modal-header">
            <h2 id="sku-history-title">Historial de mapeo SKU</h2>
            <button type="button" class="riverso-modal-close" id="btn-close-sku-history">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p id="sku-history-owners" class="description"></p>
            <table class="sku-history-table">
                <thead>
                    <tr>
                        <th>Fecha modificación</th>
                        <th>Último documento</th>
                        <th>Acción</th>
                        <th>Usuario</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody id="sku-history-list"></tbody>
            </table>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-sku-history-2">Cerrar</button>
        </div>
    </div>
</div>

<style>
.riverso-invoices .riverso-filters {
    display: flex;
    gap: 10px;
    margin: 15px 0;
    flex-wrap: wrap;
}

.riverso-invoices .riverso-filters select,
.riverso-invoices .riverso-filters input[type="date"],
.riverso-invoices .riverso-filters input[type="search"] {
    min-width: 150px;
}

.riverso-invoices .invoices-search {
    min-width: 220px;
}

.riverso-invoices .invoices-list-controls {
    margin-top: 0;
}

.riverso-invoices .invoices-control {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #50575e;
}

.riverso-invoices .invoices-pagination {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.riverso-invoices #invoices-page-info {
    color: #646970;
}

.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.riverso-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 620px;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.riverso-modal-large {
    max-width: 900px;
}

#modal-invoice-detail .riverso-modal-content {
    width: 96%;
    max-width: min(1480px, 96vw);
}

.riverso-modal-stacked {
    z-index: 100001;
}

#upload-modal-content.upload-modal-wide {
    max-width: 820px;
}

#upload-xml-preview {
    max-height: 280px;
    overflow-y: auto;
}

.folio-search-results {
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    top: 100%;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    max-height: 220px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.folio-search-results .folio-result-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 8px 10px;
    border: 0;
    background: #fff;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
}
.folio-search-results .folio-result-item:hover {
    background: #f0f6fc;
}
.folio-search-results .folio-result-empty {
    padding: 10px;
    color: #666;
    font-size: 13px;
}

.riverso-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    background: #f5f5f5;
}

.riverso-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.riverso-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #666;
    padding: 0;
    line-height: 1;
}

.riverso-modal-body {
    padding: 20px;
}

.riverso-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.upload-area {
    border: 2px dashed #ccc;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}

.upload-area:hover,
.upload-area.dragover {
    border-color: #2271b1;
    background: #f0f7fc;
}

.upload-area .dashicons {
    color: #999;
}

.upload-area:hover .dashicons {
    color: #2271b1;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-item label {
    display: block;
    font-weight: 600;
    color: #666;
    font-size: 12px;
    margin-bottom: 3px;
}

.info-item span {
    font-size: 14px;
}

.info-item .amount {
    font-weight: 600;
    color: #2e7d32;
}

.col-estado-help {
    position: relative;
    white-space: nowrap;
}

.estado-help-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    margin: 0 0 0 2px;
    padding: 0;
    border: none;
    background: transparent;
    color: #646970;
    cursor: help;
    vertical-align: middle;
    line-height: 1;
}

.estado-help-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.estado-help-btn:hover,
.estado-help-btn[aria-expanded="true"] {
    color: #2271b1;
}

.estado-help-panel {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 20;
    width: 320px;
    max-width: min(320px, 90vw);
    padding: 12px 14px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    text-align: left;
    white-space: normal;
    font-weight: 400;
    text-transform: none;
    color: #1d2327;
}

.estado-help-panel[hidden] {
    display: none !important;
}

.estado-help-title {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 600;
    color: #1d2327;
}

.estado-help-panel ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.estado-help-panel li {
    margin: 0 0 8px;
    font-size: 12px;
    line-height: 1.4;
    color: #50575e;
}

.estado-help-panel li:last-child {
    margin-bottom: 0;
}

.estado-help-panel strong {
    color: #1d2327;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-recibido { background: #e3f2fd; color: #1565c0; }
.status-parcial { background: #fff3e0; color: #ef6c00; }
.status-procesado { background: #e8f5e9; color: #2e7d32; }
.status-rechazado { background: #ffebee; color: #c62828; }
.status-pendiente { background: #fafafa; color: #666; }
.status-vinculado { background: #e8f5e9; color: #2e7d32; }
.status-sin_vincular { background: #fff3e0; color: #e65100; }
.status-gasto { background: #f3e8ff; color: #6b21a8; }

.tipo-pending-badge {
    display: inline-block;
    background: #fbbf24;
    color: #78350f;
    padding: 1px 6px;
    border-radius: 3px;
    font-weight: 600;
    font-size: 10px;
    letter-spacing: 0.02em;
}
.tipo-radio-row.is-sugerido {
    padding: 6px 8px;
    background: #ecfdf5;
    border: 1px solid #6ee7b7;
    border-radius: 4px;
}
.tipo-sugerido-tag {
    display: inline-block;
    margin-left: 6px;
    background: #059669;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 3px;
    vertical-align: middle;
}

.link-sku-input {
    display: flex;
    gap: 5px;
}

.link-sku-input input,
.sku-input {
    width: 90px;
    padding: 3px 5px;
    font-size: 12px;
}

.link-sku-input button {
    padding: 2px 6px;
    font-size: 11px;
}
.sku-edit-wrap { display: flex; flex-direction: column; gap: 4px; min-width: 160px; position: relative; }
.sku-edit-row { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.sku-conflict-warn { color: #b45309; font-size: 11px; line-height: 1.3; }
.sku-input[readonly] {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: #111827;
    cursor: default;
}
.sku-history-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
.sku-history-table th, .sku-history-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
.sku-history-table th { color: #64748b; font-weight: 600; }
.sku-history-date { white-space: nowrap; font-variant-numeric: tabular-nums; color: #334155; }
.sku-suggest { color: #1d4ed8; font-size: 11px; line-height: 1.3; }
.sku-suggest button { margin-left: 4px; }
.sku-suggest-list {
    position: absolute;
    z-index: 30;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
    min-width: 220px;
    max-height: 220px;
    overflow: auto;
    margin-top: 2px;
}
.sku-suggest-item { padding: 6px 8px; cursor: pointer; font-size: 12px; }
.sku-suggest-item:hover, .sku-suggest-item.is-active { background: #eff6ff; }

</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    const canDeleteInvoices = <?php echo (current_user_can('riverso_process_invoices') || current_user_can('riverso_create_invoices')) ? 'true' : 'false'; ?>;
    const canEditTipo = <?php echo current_user_can('riverso_process_invoices') ? 'true' : 'false'; ?>;
    const canEditSku = <?php echo (current_user_can('riverso_manage_codes') || current_user_can('riverso_process_invoices')) ? 'true' : 'false'; ?>;

    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function escAttr(value) {
        return escHtml(value).replace(/'/g, '&#39;');
    }

    function isTipoPendiente(factura) {
        return Number(factura && factura.tipo_confirmado) === 0;
    }

    $('#btn-estado-help').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const open = $(this).attr('aria-expanded') === 'true';
        $(this).attr('aria-expanded', open ? 'false' : 'true');
        $('#estado-help-panel').prop('hidden', open);
    });
    $(document).on('click.estadoHelp', function(e) {
        if ($(e.target).closest('.col-estado-help').length) return;
        $('#btn-estado-help').attr('aria-expanded', 'false');
        $('#estado-help-panel').prop('hidden', true);
    });
    
    let currentPage = 1;

    // Cargar facturas
    function loadInvoices(page) {
        if (page === undefined || page === null || page === '') {
            page = currentPage;
        }
        currentPage = Math.max(1, parseInt(page, 10) || 1);
        const filters = {
            action: 'riverso_get_invoices_list',
            nonce: nonce,
            page: currentPage,
            per_page: $('#invoices-per-page').val() || 20,
            orderby: $('#invoices-orderby').val() || 'created_at',
            order: $('#invoices-order').val() || 'DESC',
            estado: $('#filter-estado').val(),
            proveedor_id: $('#filter-proveedor').val(),
            fecha_desde: $('#filter-fecha-desde').val(),
            fecha_hasta: $('#filter-fecha-hasta').val(),
            tipo_confirmado: $('#filter-tipo-confirmado').val(),
            search: $('#filter-search').val()
        };
        
        $.post(ajaxurl, filters, function(response) {
            if (response.success) {
                const totalPages = Math.max(1, parseInt(response.data.total_pages || 1, 10));
                const pageNum = parseInt(response.data.page || 1, 10);
                if (pageNum > totalPages) {
                    loadInvoices(totalPages);
                    return;
                }
                renderInvoices(response.data);
            } else {
                alert(response.data.message || 'Error cargando facturas');
            }
        });
    }

    function renderPagination(data) {
        const total = parseInt(data.total || 0, 10);
        const page = parseInt(data.page || 1, 10);
        const totalPages = Math.max(1, parseInt(data.total_pages || 1, 10));
        currentPage = page;
        $('#invoices-page-info').text(
            total === 0
                ? 'Sin facturas'
                : `Página ${page} de ${totalPages} (${total} facturas)`
        );
        if (page > 1) {
            $('#invoices-prev').show();
        } else {
            $('#invoices-prev').hide();
        }
        if (page < totalPages && total > 0) {
            $('#invoices-next').show();
        } else {
            $('#invoices-next').hide();
        }
    }
    
    function renderInvoices(data) {
        const tbody = $('#invoices-list');
        tbody.empty();
        
        if (!data.facturas.length) {
            tbody.html('<tr><td colspan="8" style="text-align: center; padding: 40px;">No hay facturas</td></tr>');
            renderPagination(data);
            return;
        }
        
        const tiposDTE = {33: 'Factura', 34: 'F.Exenta', 52: 'Guía', 61: 'N.Crédito'};
        
        data.facturas.forEach(function(f) {
            const deleteBtn = (canDeleteInvoices && f.can_delete)
                ? `<button class="button button-small btn-delete-invoice" data-id="${f.id}" data-folio="${f.folio}" title="Eliminar subida" style="color:#b32d2e;">
                        <span class="dashicons dashicons-trash"></span>
                   </button>`
                : '';
            const isEnvio = f.documento_subtipo === 'envio';
            const isNc = f.documento_subtipo === 'nota_credito' || Number(f.tipo_dte) === 61;
            const isGuia = f.documento_subtipo === 'guia_despacho' || Number(f.tipo_dte) === 52;
            const isGastos = f.documento_subtipo === 'gastos';
            let tipoLabel = isNc
                ? '<span style="color:#1d4ed8;font-weight:600;">N. Crédito</span>'
                : (isEnvio
                    ? '<span style="color:#b45309;font-weight:600;">Flete</span>'
                    : (isGuia
                        ? '<span style="color:#0e7490;font-weight:600;">Guía</span>'
                        : (isGastos
                            ? '<span style="color:#6b21a8;font-weight:600;">Gastos</span>'
                            : '<span style="color:#15803d;">Productos</span>')));
            if (isTipoPendiente(f)) {
                tipoLabel = `<span class="tipo-pending-badge">POR CONFIRMAR</span><br><small>${tipoLabel}</small>`;
            }
            
            const vinculadas = parseInt(f.facturas_vinculadas || 0, 10);
            const itemsCol = isNc
                ? (f.estado === 'sin_vincular' ? 'Folio origen pendiente' : 'Vinculada')
                : (isEnvio
                    ? (vinculadas > 0 ? `${vinculadas} factura(s)` : 'Sin asignar')
                    : (isGastos
                        ? 'Sin inventario'
                        : (isGuia
                            ? `${f.items_vinculados}/${f.total_items} · Solo costos`
                            : `${f.items_vinculados}/${f.total_items}` +
                              (parseInt(f.fletes_vinculados) > 0 ? ` · ${f.fletes_vinculados} flete(s)` : ''))));
            const estadoLabel = isNc && f.estado === 'sin_vincular'
                ? 'NC sin folio'
                : (f.estado || '').replace(/_/g, ' ');
            const linkBtn = isEnvio
                ? `<button class="button button-small button-primary btn-view-invoice" data-id="${f.id}" title="Vincular a facturas de productos">Vincular</button>`
                : '';
            const row = $('<tr>');
            row.html(`
                <td><strong>${f.folio}</strong></td>
                <td>${tipoLabel}</td>
                <td class="col-proveedor">${f.proveedor_nombre}</td>
                <td>${f.fecha_emision}</td>
                <td style="text-align: right;">$${parseInt(f.monto_total).toLocaleString('es-CL')}</td>
                <td>${itemsCol}</td>
                <td><span class="status-badge status-${f.estado}">${estadoLabel}</span></td>
                <td>
                    <button class="button button-small btn-view-invoice" data-id="${f.id}">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                    ${linkBtn}
                    ${deleteBtn}
                </td>
            `);
            tbody.append(row);
        });
        
        renderPagination(data);
    }
    
    // Event handlers
    $('#btn-filter').on('click', function() {
        loadInvoices(1);
    });

    $('#filter-search').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadInvoices(1);
        }
    });
    let searchTimeout = null;
    $('#filter-search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadInvoices(1);
        }, 400);
    });

    $('#invoices-per-page, #invoices-orderby, #invoices-order').on('change', function() {
        loadInvoices(1);
    });

    $('#invoices-prev').on('click', function() {
        loadInvoices(currentPage - 1);
    });

    $('#invoices-next').on('click', function() {
        loadInvoices(currentPage + 1);
    });
    
    // Upload modal
    function showRiversoModal($el) {
        $el.css('display', 'flex');
    }

    function hideRiversoModal($el) {
        $el.css('display', 'none');
    }

    function setInputFiles(input, fileList) {
        if (!input || !fileList || !fileList.length) return false;
        const dt = new DataTransfer();
        Array.from(fileList).forEach(f => dt.items.add(f));
        input.files = dt.files;
        return input.files.length > 0;
    }

    function resetUploadModal() {
        previewData = null;
        bulkFiles = [];
        fileInput.val('');
        $('#xml-bulk-input').val('');
        $('#bulk-queue').hide().empty();
        $('#btn-start-bulk').prop('disabled', true);
        $('input[name="upload_mode"][value="single"]').prop('checked', true);
        $('#upload-single-wrap').show();
        $('#upload-bulk-wrap').hide();
        $('#upload-step-select').show();
        $('#upload-step-confirm').hide();
        $('#upload-modal-content').removeClass('upload-modal-wide');
        $('#intake-gaps-inline').hide().empty();
        $('#btn-change-xml, #btn-process-upload').hide();
        $('#btn-process-upload').prop('disabled', true);
        $('#upload-file-name, #upload-result').empty();
        $('#xml-preview-items tbody').empty();
        $('#tipo-sugerencia-box').hide();
        $('.tipo-radio-row').removeClass('is-sugerido').find('.tipo-sugerido-tag').remove();
        $('input[name="documento_tipo"][value="por_confirmar"]').prop('checked', true);
    }

    $('#btn-upload-invoice').on('click', function() {
        resetUploadModal();
        showRiversoModal($('#modal-upload-invoice'));
    });
    
    $('.riverso-modal-close, #btn-cancel-upload').on('click', function() {
        hideRiversoModal($(this).closest('.riverso-modal'));
    });

    $('#btn-change-xml').on('click', function() {
        resetUploadModal();
    });
    
    const dropzone = $('#upload-dropzone');
    const bulkDropzone = $('#bulk-dropzone');
    const fileInput = $('#xml-file-input');
    const bulkInput = $('#xml-bulk-input');
    let bulkFiles = [];

    $('input[name="upload_mode"]').on('change', function() {
        const isBulk = $(this).val() === 'bulk';
        $('#upload-single-wrap').toggle(!isBulk);
        $('#upload-bulk-wrap').toggle(isBulk);
        $('#upload-step-confirm').hide();
        $('#btn-change-xml, #btn-process-upload').hide();
        $('#upload-result').empty();
    });

    $('#btn-browse-xml').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        fileInput.val('');
        fileInput[0].click();
    });

    $('#btn-browse-xml-bulk').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        bulkInput.val('');
        bulkInput[0].click();
    });

    dropzone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
    dropzone.on('dragleave', function() { $(this).removeClass('dragover'); });
    dropzone.on('click', function(e) {
        if ($(e.target).closest('#btn-browse-xml').length) return;
        e.preventDefault();
        fileInput.val('');
        fileInput[0].click();
    });
    dropzone.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length && setInputFiles(fileInput[0], files)) {
            fileInput.trigger('change');
        }
    });

    bulkDropzone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
    bulkDropzone.on('dragleave', function() { $(this).removeClass('dragover'); });
    bulkDropzone.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) setBulkFiles(files);
    });

    function setBulkFiles(fileList) {
        bulkFiles = Array.from(fileList).filter(f => /\.xml$/i.test(f.name));
        const $q = $('#bulk-queue').empty().show();
        if (!bulkFiles.length) {
            $q.hide();
            $('#btn-start-bulk').prop('disabled', true);
            return;
        }
        bulkFiles.forEach((f, i) => {
            $q.append(`<div class="bulk-queue-item" data-idx="${i}"><span>${f.name}</span><span class="bulk-status">Pendiente</span></div>`);
        });
        $('#btn-start-bulk').prop('disabled', false);
    }

    bulkInput.on('change', function() {
        if (this.files.length) setBulkFiles(this.files);
    });

    function uploadOneFile(file, extraFields, uploadMode) {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'riverso_upload_invoice');
            formData.append('nonce', nonce);
            formData.append('xml_file', file);
            formData.append('upload_mode', uploadMode || 'single');
            Object.entries(extraFields || {}).forEach(([k, v]) => formData.append(k, v ?? ''));
            $.ajax({
                url: ajaxurl, type: 'POST', data: formData, processData: false, contentType: false,
                success: res => resolve(res),
                error: () => resolve({ success: false, data: { message: 'Error de conexión' } })
            });
        });
    }

    function previewOneFile(file) {
        return new Promise((resolve) => {
            const fd = new FormData();
            fd.append('action', 'riverso_preview_invoice_xml');
            fd.append('nonce', nonce);
            fd.append('xml_file', file);
            $.ajax({
                url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
                success: res => resolve(res),
                error: () => resolve({ success: false, data: { message: 'Error de conexión' } })
            });
        });
    }

    $('#btn-start-bulk').on('click', async function() {
        if (!bulkFiles.length) return;
        const btn = $(this);
        btn.prop('disabled', true);
        $('#upload-result').html('<div class="notice notice-info" style="padding:10px;">Procesando carga masiva…</div>');
        let ok = 0, err = 0;

        for (let i = 0; i < bulkFiles.length; i++) {
            const file = bulkFiles[i];
            const $row = $(`.bulk-queue-item[data-idx="${i}"]`).addClass('run');
            $row.find('.bulk-status').text('Analizando…');

            const preview = await previewOneFile(file);
            if (!preview.success) {
                $row.removeClass('run').addClass('err');
                $row.find('.bulk-status').text(preview.data?.message || 'Error preview');
                err++;
                continue;
            }

            const det = preview.data.detection || {};
            // Detectar tipo: NC / flete / guía / gastos / productos
            const tipo = det.tipo === 'nota_credito' ? 'nota_credito'
                : (det.tipo === 'envio' ? 'envio'
                    : (det.tipo === 'guia_despacho' ? 'guia_despacho'
                        : (det.tipo === 'gastos' ? 'gastos' : 'productos')));

            const emisor = preview.data.emisor || {};
            $row.find('.bulk-status').text('Subiendo…');
            const upload = await uploadOneFile(file, {
                documento_tipo: tipo,
                tipo_sugerido: tipo,
                tipo_confirmado: '0',
                modo_ingreso: 'solo_costos',
                proveedor_modo: 'xml',
                proveedor_nombre: emisor.razon_social || '',
                proveedor_rut: emisor.rut || '',
                link_to_factura_id: ''
            }, 'bulk');

            if (upload.success) {
                $row.removeClass('run').addClass('ok');
                const note = tipo === 'envio' ? ' (sin asignar)'
                    : (tipo === 'gastos' ? ' (gasto)'
                        : (tipo === 'guia_despacho' ? ' (guía)' : ''));
                $row.find('.bulk-status').text('✓ Folio ' + (upload.data?.resumen?.folio || '') + note);
                ok++;
            } else {
                $row.removeClass('run').addClass('err');
                $row.find('.bulk-status').text(upload.data?.message || 'Error');
                err++;
            }
        }

        $('#upload-result').html(`<div class="notice notice-success" style="padding:10px;"><strong>Carga masiva terminada:</strong> ${ok} OK, ${err} con error/omitidos.</div>`);
        loadInvoices(1);
        btn.prop('disabled', false);
    });

    let previewData = null;

    function fillProveedorForm(emisor, existing) {
        const data = existing || {};
        $('#prov-rut').val(data.rut || emisor?.rut || '');
        $('#prov-nombre').val(data.nombre || emisor?.razon_social || '');
        $('#prov-giro').val(data.giro || emisor?.giro || '');
        $('#prov-comuna').val(data.comuna || emisor?.comuna || '');
        $('#prov-direccion').val(data.direccion || emisor?.direccion || '');
    }

    function setProveedorUiMode() {
        const modo = $('input[name="proveedor_modo"]:checked').val();
        $('#proveedor-select-wrap').toggle(modo === 'existente');
        $('#proveedor-form-wrap').toggle(modo !== 'existente');
        if (modo === 'xml' && previewData) {
            fillProveedorForm(previewData.emisor, previewData.proveedor_existente);
            $('#proveedor-status').text(
                previewData.proveedor_existente
                    ? '✓ Proveedor encontrado — se actualizará con datos del XML'
                    : 'Proveedor nuevo — se creará al confirmar'
            );
        }
        if (modo === 'nuevo') {
            $('#prov-rut').prop('readonly', false);
        } else {
            $('#prov-rut').prop('readonly', true);
        }
    }

    $('input[name="proveedor_modo"]').on('change', setProveedorUiMode);

    function updateTipoUi() {
        const tipo = $('input[name="documento_tipo"]:checked').val();
        const isEnvio = tipo === 'envio';
        const isNc = tipo === 'nota_credito';
        const isGuia = tipo === 'guia_despacho';
        const isGastos = tipo === 'gastos';
        $('#link-factura-wrap').toggle(isEnvio);
        $('#credit-note-section').toggle(isNc);
        $('#opciones-productos-wrap').toggle(!isEnvio && !isNc && !isGastos && !isGuia);
        const sugerido = $('#tipo-sugerido').val();
        const aceptada = tipo && tipo === sugerido;
        $('#btn-aceptar-tipo-sugerido')
            .prop('disabled', aceptada)
            .text(aceptada ? 'Sugerencia aceptada' : 'Aceptar sugerencia');
    }

    function aceptarTipoSugerido() {
        const tipoSugerido = $('#tipo-sugerido').val() || 'productos';
        const $radio = $(`input[name="documento_tipo"][value="${tipoSugerido}"]`);
        if ($radio.length) {
            $radio.prop('checked', true);
        } else {
            $('input[name="documento_tipo"][value="productos"]').prop('checked', true);
        }
        updateTipoUi();
    }

    $('#btn-aceptar-tipo-sugerido').on('click', function() {
        aceptarTipoSugerido();
    });

    function formatFolioResultLabel(f) {
        const sub = f.documento_subtipo === 'envio' ? 'Flete' : 'Productos';
        return `Folio ${f.folio} · ${sub} · T${f.tipo_dte} · $${Number(f.monto_total || 0).toLocaleString('es-CL')} · ${f.proveedor_nombre || f.rut_emisor || ''}`;
    }

    function setCreditNoteOrigin(f) {
        if (!f || !f.id) {
            $('#credit-note-origin-factura-id').val('');
            $('#credit-note-folio-search').val('');
            $('#credit-note-selected-label').text('Sin vincular — se guardará pendiente del folio.');
            $('#credit-note-clear-origin').hide();
            return;
        }
        $('#credit-note-origin-factura-id').val(String(f.id));
        $('#credit-note-folio-search').val(`Folio ${f.folio}`);
        $('#credit-note-selected-label').html('Seleccionada: <strong>' + formatFolioResultLabel(f) + '</strong>');
        $('#credit-note-clear-origin').show();
        $('#credit-note-folio-results').hide().empty();
    }

    function bindFolioSearcher($input, $results, $hidden, opts) {
        let timer = null;
        const options = opts || {};
        $input.off('input.folioSearch focus.folioSearch').on('input.folioSearch focus.folioSearch', function() {
            const q = $(this).val().trim();
            clearTimeout(timer);
            if (q.length < 1) {
                $results.hide().empty();
                return;
            }
            timer = setTimeout(function() {
                $.post(ajaxurl, {
                    action: 'riverso_search_invoice_folios',
                    nonce: nonce,
                    q: q,
                    rut_emisor: options.rutEmisor || '',
                    exclude_id: options.excludeId || 0,
                    tipos: options.tipos || 'productos,envio'
                }, function(res) {
                    if (!res.success) {
                        $results.html('<div class="folio-result-empty">Error al buscar</div>').show();
                        return;
                    }
                    const rows = res.data.results || [];
                    if (!rows.length) {
                        $results.html('<div class="folio-result-empty">Sin resultados para "' + q + '"</div>').show();
                        return;
                    }
                    $results.empty();
                    rows.forEach(function(f) {
                        const $btn = $('<button type="button" class="folio-result-item"></button>')
                            .text(formatFolioResultLabel(f))
                            .on('click', function() {
                                if (typeof options.onSelect === 'function') {
                                    options.onSelect(f);
                                } else {
                                    $hidden.val(String(f.id));
                                    $input.val('Folio ' + f.folio);
                                    $results.hide().empty();
                                }
                            });
                        $results.append($btn);
                    });
                    $results.show();
                });
            }, 250);
        });
        $(document).off('click.folioSearchClose').on('click.folioSearchClose', function(e) {
            if (!$(e.target).closest('.folio-search-wrap').length) {
                $('.folio-search-results').hide();
            }
        });
    }

    function fillCreditNoteSection(d) {
        const refs = d.referencias || [];
        const ref = refs[0] || {};
        const folioRef = String(ref.folio_ref ?? '');
        const tipoRef = ref.tipo_doc_ref || '—';
        const resol = d.credit_note_resolution || {};

        if (folioRef && folioRef !== '0') {
            $('#credit-note-ref-info').html(
                `Referencia XML: tipo <strong>${tipoRef}</strong>, folio <strong>${folioRef}</strong>` +
                (ref.razon_ref ? ` · ${ref.razon_ref}` : '')
            );
        } else {
            $('#credit-note-ref-info').html(
                'La NC no trae un folio de referencia usable (FolioRef=0 o vacío). Puede asociarla manualmente o dejarla pendiente.'
            );
        }

        let msg = resol.mensaje || '';
        if (resol.estado === 'pendiente' && folioRef && folioRef !== '0') {
            msg = `No está en el sistema el folio origen <strong>${folioRef}</strong>. Búsquelo o déjelo pendiente hasta subirlo.`;
        } else if (resol.estado === 'resuelta_automatica') {
            msg = resol.mensaje || 'Factura origen encontrada automáticamente.';
        } else if (resol.estado === 'ambigua') {
            msg = resol.mensaje || 'Hay varias coincidencias; busque y seleccione la factura correcta.';
        }
        $('#credit-note-resolution-msg').html(msg);

        setCreditNoteOrigin(null);
        const origenes = d.facturas_origen || [];
        if (resol.factura_id) {
            const match = origenes.find(f => String(f.id) === String(resol.factura_id));
            if (match) {
                setCreditNoteOrigin(match);
            } else {
                setCreditNoteOrigin({ id: resol.factura_id, folio: folioRef || resol.factura_id, documento_subtipo: 'productos', tipo_dte: tipoRef, monto_total: 0 });
            }
        } else if (folioRef && folioRef !== '0') {
            $('#credit-note-folio-search').val(folioRef);
        }

        bindFolioSearcher(
            $('#credit-note-folio-search'),
            $('#credit-note-folio-results'),
            $('#credit-note-origin-factura-id'),
            {
                rutEmisor: d.emisor?.rut || '',
                onSelect: setCreditNoteOrigin
            }
        );
        $('#credit-note-reversa-inventario').prop('checked', false);
    }

    $('#credit-note-clear-origin').on('click', function() {
        setCreditNoteOrigin(null);
    });

    $('input[name="documento_tipo"]').on('change', updateTipoUi);

    function renderInlineGaps(d) {
        const gaps = d.missing_gaps || [];
        const $banner = $('#intake-gaps-inline');
        if (!gaps.length) {
            $banner.hide().empty();
            return;
        }
        gaps.forEach(applyGapToForm);
        const items = gaps.map(g => `<li>${g.message || g.label || ''}</li>`).join('');
        $banner.html(
            `<strong style="color:#9a6700;">Complete estos datos antes de confirmar:</strong><ul style="margin:6px 0 0 18px;">${items}</ul>`
        ).show();
    }

    function showConfirmStep(d) {
        previewData = d;
        const det = d.detection || {};
        const tipoSugerido = det.tipo === 'mixto' ? 'productos' : (det.tipo || 'productos');

        $('#xml-preview-summary').html(
            `<strong>${d.emisor?.razon_social || '—'}</strong> · RUT ${d.emisor?.rut || '—'}<br>` +
            `Folio <strong>${d.folio}</strong> · Fecha ${d.fecha_emision || '—'} · Total <strong>$${Number(d.total || 0).toLocaleString('es-CL')}</strong>`
        );

        const $tbody = $('#xml-preview-items tbody').empty();
        const items = d.items_preview || [];
        if (!items.length) {
            $tbody.append('<tr><td colspan="4" style="text-align:center;color:#666;">Sin líneas de detalle en el XML</td></tr>');
        } else {
            items.slice(0, 15).forEach(it => {
                let badge;
                if (it.tipo === 'envio') {
                    badge = '<span style="color:#b45309;">Flete</span>';
                } else if (it.tipo === 'gasto' || tipoSugerido === 'gastos') {
                    badge = '<span style="color:#6b21a8;">Gasto</span>';
                } else if (Number(d.tipo_dte) === 61 || tipoSugerido === 'nota_credito') {
                    badge = '<span style="color:#1d4ed8;">NC</span>';
                } else if (Number(d.tipo_dte) === 52 || tipoSugerido === 'guia_despacho') {
                    badge = '<span style="color:#0e7490;">Guía</span>';
                } else {
                    badge = '<span style="color:#15803d;">Producto</span>';
                }
                const desc = (it.nombre || it.descripcion || '—').toString();
                $tbody.append(`<tr>
                    <td>${it.linea}</td>
                    <td class="col-desc">${$('<div>').text(desc).html()}</td>
                    <td>${badge}</td>
                    <td style="text-align:right;">$${Number(it.monto || 0).toLocaleString('es-CL')}</td>
                </tr>`);
            });
            if (items.length > 15) {
                $tbody.append(`<tr><td colspan="4" style="text-align:center;color:#666;">… y ${items.length > 15 ? items.length - 15 : 0} líneas más</td></tr>`);
            }
        }

        $('#tipo-sugerido').val(tipoSugerido);
        const tipoLabels = {
            productos: 'Factura de productos',
            envio: 'Factura de transportista / flete',
            nota_credito: 'Nota de crédito',
            guia_despacho: 'Guía de despacho',
            gastos: 'Gastos operacionales'
        };
        const labelSugerido = tipoLabels[tipoSugerido] || tipoSugerido;
        $('#tipo-sugerencia-box').show();
        $('#detection-motivo').html(
            `<span class="dashicons dashicons-lightbulb" style="color:#dba617;"></span> ` +
            `<strong>Sugerencia (${det.confianza || '—'}):</strong> ${labelSugerido}` +
            (det.motivo ? `<br><span style="color:#50575e;">${det.motivo}</span>` : '')
        );
        $('.tipo-radio-row').removeClass('is-sugerido').find('.tipo-sugerido-tag').remove();
        const $sugerida = $(`.tipo-radio-row[data-tipo="${tipoSugerido}"]`);
        if ($sugerida.length) {
            $sugerida.addClass('is-sugerido');
            $sugerida.children('span').first().after('<span class="tipo-sugerido-tag">Sugerido</span>');
        }
        $('input[name="documento_tipo"][value="por_confirmar"]').prop('checked', true);
        updateTipoUi();
        if (tipoSugerido === 'nota_credito' || Number(d.tipo_dte) === 61) {
            fillCreditNoteSection(d);
        }

        const $link = $('#link-factura-productos-id').empty()
            .append('<option value="">— Dejar sin asignar por ahora —</option>');
        (d.facturas_productos || []).forEach(f => {
            $link.append(`<option value="${f.id}">Folio ${f.folio} — ${f.proveedor_nombre || 'Sin prov.'} — $${Number(f.monto_total || 0).toLocaleString('es-CL')}</option>`);
        });

        const $sel = $('#proveedor-existente-id').empty().append('<option value="">— Seleccionar —</option>');
        (d.proveedores || []).forEach(p => {
            $sel.append(`<option value="${p.id}">${p.nombre} (${p.rut})</option>`);
        });
        if (d.proveedor_existente) $sel.val(d.proveedor_existente.id);

        fillProveedorForm(d.emisor, d.proveedor_existente);
        setProveedorUiMode();

        if ((d.missing_gaps || []).some(g => g.field === 'nombre' && !d.proveedor_existente)) {
            $('input[name="proveedor_modo"][value="nuevo"]').prop('checked', true);
            setProveedorUiMode();
        }

        renderInlineGaps(d);

        $('#upload-step-select').hide();
        $('#upload-step-confirm').show();
        $('#upload-modal-content').addClass('upload-modal-wide');
        $('#btn-change-xml, #btn-process-upload').show();
        $('#btn-process-upload').prop('disabled', false);

        const $modalContent = $('#upload-modal-content');
        if ($modalContent.length) {
            $modalContent.scrollTop(0);
        }
    }

    function previewXmlFile() {
        if (!fileInput[0].files.length) return;
        const fd = new FormData();
        fd.append('action', 'riverso_preview_invoice_xml');
        fd.append('nonce', nonce);
        fd.append('xml_file', fileInput[0].files[0]);

        $('#upload-file-name').html('<span class="spinner is-active" style="float:none;margin-right:6px;"></span> Analizando: ' + fileInput[0].files[0].name + '…');
        $('#btn-process-upload').prop('disabled', true);

        $.ajax({
            url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
            success: function(res) {
                if (!res.success) {
                    $('#upload-file-name').html('<span style="color:#d63638;">' + (res.data?.message || 'Error al leer XML') + '</span>');
                    return;
                }
                previewData = res.data;
                $('#upload-file-name').text('Archivo: ' + fileInput[0].files[0].name);
                showConfirmStep(res.data);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.data?.message || 'Error de conexión al analizar XML';
                $('#upload-file-name').html('<span style="color:#d63638;">' + msg + '</span>');
            }
        });
    }
    
    fileInput.on('change', function() {
        if (this.files.length) previewXmlFile();
    });
    
    function appendProveedorFields(formData) {
        const provModo = $('input[name="proveedor_modo"]:checked').val();
        formData.append('proveedor_modo', provModo || 'xml');
        if (provModo === 'existente') {
            formData.append('proveedor_id', $('#proveedor-existente-id').val() || '');
        } else {
            formData.append('proveedor_nombre', $('#prov-nombre').val());
            formData.append('proveedor_giro', $('#prov-giro').val());
            formData.append('proveedor_direccion', $('#prov-direccion').val());
            formData.append('proveedor_comuna', $('#prov-comuna').val());
            formData.append('proveedor_rut', $('#prov-rut').val());
        }
    }

    let pendingUploadAfterGaps = false;

    function applyGapToForm(gap) {
        if (gap.type === 'supplier') {
            if (gap.field === 'proveedor_id') {
                $('input[name="proveedor_modo"][value="existente"]').prop('checked', true);
            } else {
                $('input[name="proveedor_modo"][value="nuevo"]').prop('checked', true);
            }
            setProveedorUiMode();
            if (gap.field === 'nombre') $('#prov-nombre').focus();
            if (gap.field === 'rut') {
                $('#prov-rut').prop('readonly', false).focus();
            }
        }
        if (gap.type === 'link_factura') {
            $('input[name="documento_tipo"][value="envio"]').prop('checked', true);
            updateTipoUi();
            $('#link-factura-productos-id').focus();
        }
    }

    function showMissingDataModal(payload, retryUpload) {
        pendingUploadAfterGaps = !!retryUpload;
        const gaps = payload.gaps || [];
        const $fields = $('#intake-missing-fields').empty();

        gaps.forEach(gap => {
            $fields.append(`<div class="intake-gap-block" data-type="${gap.type}" data-field="${gap.field}" style="margin-bottom:14px;padding:10px;background:#fff8e5;border-radius:4px;">
                <strong>${gap.label || gap.field}</strong>
                <p class="description" style="margin:4px 0 8px;">${gap.message || ''}</p>
            </div>`);
        });

        gaps.forEach(applyGapToForm);

        if (!$('#upload-step-confirm').is(':visible') && previewData) {
            showConfirmStep(previewData);
        } else if (previewData) {
            renderInlineGaps({ missing_gaps: gaps });
        }

        showRiversoModal($('#modal-intake-missing'));
    }

    $('#btn-intake-missing-cancel, #modal-intake-missing .riverso-modal-close').on('click', function() {
        hideRiversoModal($('#modal-intake-missing'));
        pendingUploadAfterGaps = false;
    });

    $('#btn-intake-missing-save').on('click', function() {
        hideRiversoModal($('#modal-intake-missing'));
        if (pendingUploadAfterGaps) {
            pendingUploadAfterGaps = false;
            $('#btn-process-upload').trigger('click');
        }
    });

    function handleIntakeGapsIfAny(d, retryUpload) {
        if (d.missing_gaps && d.missing_gaps.length) {
            showMissingDataModal({ gaps: d.missing_gaps }, retryUpload);
            return true;
        }
        return false;
    }
    
    $('#btn-process-upload').on('click', function() {
        const tipoRaw = $('input[name="documento_tipo"]:checked').val();
        const tipoSugerido = $('#tipo-sugerido').val() || 'productos';
        const pendiente = !tipoRaw || tipoRaw === 'por_confirmar';
        const tipo = pendiente ? tipoSugerido : tipoRaw;

        const btn = $(this);
        btn.prop('disabled', true).text('Procesando...');
        
        const formData = new FormData();
        formData.append('action', 'riverso_upload_invoice');
        formData.append('nonce', nonce);
        formData.append('documento_tipo', pendiente ? 'por_confirmar' : tipo);
        formData.append('tipo_sugerido', tipoSugerido);
        formData.append('tipo_confirmado', pendiente ? '0' : '1');
        formData.append('upload_mode', 'single');
        formData.append('modo_ingreso', (tipo === 'nota_credito' || tipo === 'envio' || tipo === 'gastos' || tipo === 'guia_despacho')
            ? 'solo_costos'
            : ($('input[name="modo_ingreso"]:checked').val() || 'solo_costos'));
        formData.append('link_to_factura_id', $('#link-factura-productos-id').val() || '');
        formData.append('factura_origen_id', tipo === 'nota_credito' ? ($('#credit-note-origin-factura-id').val() || '') : '');
        if (tipo === 'nota_credito' && $('#credit-note-reversa-inventario').is(':checked')) {
            formData.append('reversa_inventario', '1');
        }
        formData.append('xml_file', fileInput[0].files[0]);
        appendProveedorFields(formData);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                const result = $('#upload-result');
                if (response.success) {
                    result.html(`
                        <div class="notice notice-success" style="padding: 10px;">
                            <strong>✓ ${response.data.message}</strong><br>
                            Proveedor: ${response.data.resumen.proveedor}<br>
                            Folio: ${response.data.resumen.folio}
                            ${response.data.resumen.documento_tipo === 'envio'
                                ? (response.data.resumen.vinculado_a_factura
                                    ? '<br>✓ Flete vinculado a factura de productos'
                                    : '<br>⏳ Flete guardado sin asignar — vincúlelo desde el detalle')
                                : ''}
                            ${response.data.resumen.documento_tipo === 'nota_credito'
                                ? (response.data.resumen.nc_pendiente
                                    ? `<br>⏳ Pendiente del folio origen <strong>${response.data.resumen.nc_folio_ref || '—'}</strong> — súbalo o vincúlelo después`
                                    : '<br>✓ Vinculada a factura origen')
                                : ''}
                            ${response.data.resumen.documento_tipo === 'gastos'
                                ? '<br>✓ Registrado como gasto operacional (sin productos ni SKU)'
                                : ''}
                            ${response.data.resumen.documento_tipo === 'guia_despacho'
                                ? '<br>✓ Guía de despacho: códigos y costos (sin inventario)'
                                : ''}
                            ${response.data.resumen.items ? '<br>Productos: ' + response.data.resumen.items : ''}
                            ${response.data.resumen.items_envio ? ' · Líneas flete: ' + response.data.resumen.items_envio : ''}
                            ${response.data.modo_ingreso === 'solo_costos' && response.data.resumen.documento_tipo !== 'envio' && response.data.resumen.documento_tipo !== 'nota_credito' && response.data.resumen.documento_tipo !== 'gastos' ? `<br>Costos: ${response.data.resumen.costos_registrados || 0} · Pendientes: ${response.data.resumen.costos_pendientes || 0}` : ''}
                        </div>
                    `);
                    loadInvoices(1);
                    setTimeout(() => hideRiversoModal($('#modal-upload-invoice')), 2000);
                } else {
                    if (response.data?.needs_input) {
                        showMissingDataModal(response.data, true);
                        result.html(`<div class="notice notice-warning" style="padding: 10px;">${response.data.message}</div>`);
                    } else {
                        result.html(`<div class="notice notice-error" style="padding: 10px;">${response.data.message}</div>`);
                    }
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Confirmar y procesar');
            }
        });
    });
    
    // Eliminar subida
    $(document).on('click', '.btn-delete-invoice', function() {
        const btn = $(this);
        const id = btn.data('id');
        const folio = btn.data('folio');
        if (!confirm(`¿Eliminar la factura folio ${folio}?\n\nSe revertirá la subida, ítems, costos y tareas asociadas. Esta acción quedará registrada en auditoría.`)) {
            return;
        }
        btn.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'riverso_delete_invoice',
            nonce: nonce,
            factura_id: id
        }, function(response) {
            if (response.success) {
                loadInvoices();
            } else {
                alert(response.data?.message || 'Error al eliminar');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            alert('Error de conexión');
            btn.prop('disabled', false);
        });
    });

    // Ver detalle
    $(document).on('click', '.btn-view-invoice', function() {
        const id = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: id
        }, function(response) {
            if (response.success) {
                showInvoiceDetail(response.data);
            }
        });
    });
    
    let currentDetailFacturaId = null;
    let currentDetailFactura = null;
    let showPrecioDecimales = false;

    /** Precio unitario: entero, o hasta 3 decimales (sin ceros finales). */
    function formatUnitPrice(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '—';
        if (!showPrecioDecimales) {
            return '$' + Math.round(n).toLocaleString('es-CL');
        }
        const rounded = Math.round(n * 1000) / 1000;
        const fixed = rounded.toFixed(3).replace(/\.?0+$/, '');
        const parts = fixed.split('.');
        const intPart = Number(parts[0]).toLocaleString('es-CL');
        return parts.length > 1 ? ('$' + intPart + ',' + parts[1]) : ('$' + intPart);
    }

    function renderDetailItems(factura) {
        const isGastos = factura.documento_subtipo === 'gastos';
        const tbody = $('#detail-items');
        tbody.empty();

        (factura.items || []).forEach(function(item) {
            const isGastoItem = isGastos || item.item_tipo === 'gasto' || item.estado === 'gasto';
            const conflict = item.sku_conflict;
            let skuCell;
            if (isGastoItem) {
                skuCell = '<span class="description">N/A (gasto)</span>';
            } else {
                const currentSku = item.sku_local || '';
                const suggestedSku = item.sku_sugerido || '';
                const hasMapping = !!item.has_mapping;
                const warn = conflict
                    ? `<div class="sku-conflict-warn" title="${escAttr(conflict.message || '')}">⚠ ${escHtml(conflict.message || 'Conflicto de SKU')}</div>`
                    : '';
                const suggest = (!hasMapping && suggestedSku && suggestedSku !== currentSku)
                    ? `<div class="sku-suggest">Sugerencia catálogo: <code>${escHtml(suggestedSku)}</code>
                        ${canEditSku ? `<button type="button" class="button-link btn-apply-sku-suggest" data-sku="${escAttr(suggestedSku)}">Usar</button>` : ''}
                       </div>`
                    : (!hasMapping && currentSku ? '<div class="sku-suggest">Sugerencia de catálogo (sin mapeo guardado)</div>' : '');
                if (canEditSku) {
                    skuCell = `<div class="sku-edit-wrap" data-item="${item.id}" data-code="${escAttr(item.codigo_proveedor || '')}" data-original="${escAttr(currentSku)}" data-suggest="${escAttr(suggestedSku)}">
                        <div class="sku-edit-row">
                            <input type="text" class="sku-input" value="${escAttr(currentSku)}" placeholder="${suggestedSku ? escAttr('Sugerido: ' + suggestedSku) : 'Sin SKU'}" readonly>
                            <button type="button" class="button button-small btn-edit-sku">Editar</button>
                            <button type="button" class="button button-small button-primary btn-link-sku" data-item="${item.id}" style="display:none;">Guardar</button>
                            <button type="button" class="button button-small btn-clear-sku" data-item="${item.id}" style="display:none;">Quitar</button>
                            <button type="button" class="button button-small btn-cancel-sku" style="display:none;">Cancelar</button>
                            <button type="button" class="button button-small btn-sku-history" data-sku="${escAttr(currentSku || suggestedSku)}" data-code="${escAttr(item.codigo_proveedor || '')}" title="Historial de mapeos">Historial</button>
                        </div>
                        <div class="sku-suggest-list" style="display:none;"></div>
                        ${suggest}
                        ${warn}
                    </div>`;
                } else {
                    skuCell = (currentSku ? `<code>${escHtml(currentSku)}</code>` : '—') + suggest + warn;
                }
            }
            const actionsCell = (isGastoItem || item.estado === 'vinculado')
                ? ''
                : `<button class="button button-small btn-reject-item" data-item="${item.id}" title="Rechazar">
                        <span class="dashicons dashicons-no"></span>
                   </button>`;
            const dsc = Number(item.descuento_monto || 0);
            const rec = Number(item.recargo_monto || 0);
            const dscRec = (dsc || rec)
                ? `-${dsc.toLocaleString('es-CL')}${rec ? ' / +' + rec.toLocaleString('es-CL') : ''}`
                : '—';
            const netoFinal = Number(item.costo_neto_final != null ? item.costo_neto_final : item.monto_total || 0);
            const brutoFinal = Number(item.costo_bruto_final != null ? item.costo_bruto_final : Math.round(netoFinal * 1.19));
            const row = $('<tr>');
            row.html(`
                <td>${item.linea || item.numero_linea || ''}</td>
                <td><code>${item.codigo_proveedor || '-'}</code></td>
                <td class="col-desc">${item.nombre || item.descripcion || '—'}${item.impuesto_especifico_monto ? '<br><span class="description">Imp.esp: $' + Number(item.impuesto_especifico_monto).toLocaleString('es-CL') + '</span>' : ''}</td>
                <td style="text-align: right;">${item.cantidad}</td>
                <td style="text-align: right;" class="col-precio-unit">${formatUnitPrice(item.precio_unitario)}</td>
                <td style="text-align: right;font-size:11px;">${dscRec}</td>
                <td style="text-align: right;">$${Math.round(netoFinal).toLocaleString('es-CL')}</td>
                <td style="text-align: right;">$${Math.round(brutoFinal).toLocaleString('es-CL')}</td>
                <td>${skuCell}</td>
                <td><span class="status-badge status-${item.estado}">${item.estado}</span></td>
                <td>${actionsCell}</td>
            `);
            tbody.append(row);
        });
    }

    $('#toggle-precio-decimales').on('change', function() {
        showPrecioDecimales = $(this).is(':checked');
        if (currentDetailFactura) {
            renderDetailItems(currentDetailFactura);
        }
    });

    function renderDetailAudit(factura) {
        const $list = $('#detail-audit-list').empty();
        const logs = factura.auditoria || [];
        if (!logs.length) {
            $list.append('<li class="description">Sin eventos de auditoría todavía.</li>');
            return;
        }
        logs.forEach(function(log) {
            const when = (log.created_at || '').replace(' ', ' · ');
            const who = log.user_name || 'Sistema';
            const detail = log.details ? ' — ' + log.details : '';
            $list.append(`<li><strong>${log.action_label || log.action}</strong> · ${who} · ${when}${detail}</li>`);
        });
    }

    function showInvoiceDetail(factura) {
        currentDetailFacturaId = factura.id;
        currentDetailFactura = factura;
        const isEnvio = factura.documento_subtipo === 'envio';
        const isNc = factura.documento_subtipo === 'nota_credito' || Number(factura.tipo_dte) === 61;
        const isGuia = factura.documento_subtipo === 'guia_despacho' || Number(factura.tipo_dte) === 52;
        const isGastos = factura.documento_subtipo === 'gastos';

        const estadoTxt = (factura.estado || '').replace(/_/g, ' ');
        $('#detail-folio').text(
            '#' + factura.folio +
            (isGuia ? ' · Guía (solo costos)' : (isGastos ? ' · Gastos' : '')) +
            (estadoTxt ? ' · ' + estadoTxt : '')
        );
        $('#detail-proveedor').text(factura.proveedor_nombre);
        $('#detail-rut').text(factura.proveedor_rut);
        $('#detail-fecha').text(factura.fecha_emision);
        $('#detail-total').text('$' + parseInt(factura.monto_total).toLocaleString('es-CL'));
        $('#toggle-precio-decimales').prop('checked', showPrecioDecimales);

        const $tipoConfirmSection = $('#detail-tipo-confirm-section');
        if (!canEditTipo) {
            $tipoConfirmSection.hide();
        } else {
            $tipoConfirmSection.show();
            $('#detail-tipo-select').val(factura.documento_subtipo || 'productos');
            if (isTipoPendiente(factura)) {
                $tipoConfirmSection.css({background:'#fef3c7', borderLeftColor:'#f59e0b'});
                $('#detail-tipo-title').text('Confirmar tipo de documento').css('color', '#92400e');
                $('#detail-tipo-help').text('Este documento está pendiente. Confirme el tipo sugerido o cámbielo.');
                $('#btn-confirm-tipo').text('Confirmar tipo');
            } else {
                $tipoConfirmSection.css({background:'#f8fafc', borderLeftColor:'#64748b'});
                $('#detail-tipo-title').text('Tipo de documento').css('color', '');
                $('#detail-tipo-help').text('Puede cambiar el tipo de este documento si quedó mal clasificado.');
                $('#btn-confirm-tipo').text('Guardar tipo');
            }
        }

        const $shippingSection = $('#detail-shipping-section');
        const $envioSection = $('#detail-envio-assign-section');
        const $ncSection = $('#detail-credit-note-section');

        $shippingSection.hide();
        $envioSection.hide();
        $ncSection.hide();

        if (isNc) {
            $ncSection.show();
            const refs = factura.credit_note_refs || [];
            const ref = refs[0];
            if (ref && ref.factura_origen_id) {
                const sub = ref.subtipo_origen === 'envio' ? 'Flete' : 'Productos';
                $('#detail-nc-current').html(
                    `Vinculada a <strong>${sub} folio ${ref.folio_origen}</strong>` +
                    ` · $${Number(ref.monto_origen || 0).toLocaleString('es-CL')}` +
                    (ref.proveedor_origen ? ` · ${ref.proveedor_origen}` : '') +
                    ` <span class="description">(${ref.estado_resolucion || ''})</span>`
                );
            } else {
                const folioPend = ref?.folio_ref && ref.folio_ref !== '0' ? ref.folio_ref : '—';
                $('#detail-nc-current').html(
                    `Pendiente de vínculo. Folio referencia XML: <strong>${folioPend}</strong>. Busque una factura de productos o un flete.`
                );
            }
            $('#detail-nc-origen-id').val('');
            $('#detail-nc-folio-search').val('');
            $('#detail-nc-selected-label').text('');
            bindFolioSearcher(
                $('#detail-nc-folio-search'),
                $('#detail-nc-folio-results'),
                $('#detail-nc-origen-id'),
                {
                    excludeId: factura.id,
                    onSelect: function(f) {
                        $('#detail-nc-origen-id').val(String(f.id));
                        $('#detail-nc-folio-search').val('Folio ' + f.folio);
                        $('#detail-nc-selected-label').html('Seleccionada: <strong>' + formatFolioResultLabel(f) + '</strong>');
                        $('#detail-nc-folio-results').hide().empty();
                    }
                }
            );
        } else if (isEnvio) {
            $envioSection.show();
            const vinculadas = factura.facturas_productos_vinculadas || [];
            const $target = $('#detail-envio-target-id').empty()
                .append('<option value="">— Seleccionar factura de productos —</option>');
            (factura.facturas_productos_disponibles || []).forEach(f => {
                if (!vinculadas.some(v => String(v.id) === String(f.id))) {
                    $target.append(`<option value="${f.id}">Folio ${f.folio} — ${f.proveedor_nombre || ''} — $${Number(f.monto_total || 0).toLocaleString('es-CL')}</option>`);
                }
            });

            if (vinculadas.length) {
                let html = '<ul style="margin:0;padding-left:18px;">';
                vinculadas.forEach(fp => {
                    html += `<li>Folio <strong>${fp.folio}</strong> — ${fp.proveedor_nombre || ''} — $${Number(fp.monto_total || 0).toLocaleString('es-CL')}`;
                    if (fp.monto_asignado) {
                        html += ` <span class="description">(flete asignado: $${Number(fp.monto_asignado).toLocaleString('es-CL')})</span>`;
                    }
                    html += `<button type="button" class="button button-small btn-unassign-producto" data-productos-id="${fp.id}" style="margin-left:8px;">Desvincular</button></li>`;
                });
                html += '</ul>';
                $('#detail-envio-linked-info').html(html).show();
                $('#btn-envio-unassign').toggle(vinculadas.length > 1).show();
            } else {
                $('#detail-envio-linked-info').hide().empty();
                $('#btn-envio-unassign').hide();
            }
            $('#detail-envio-target-id').closest('div').show();
            $('#btn-envio-assign').show();
        } else {
            const fletes = factura.fletes_vinculados || [];
            if (fletes.length || (factura.fletes_sin_vincular || []).length) {
                $shippingSection.show();
                let html = '';
                if (fletes.length) {
                    html += '<ul style="margin:0;padding-left:18px;">';
                    fletes.forEach(fl => {
                        html += `<li>Folio <strong>${fl.folio}</strong> — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}
                            <button type="button" class="button button-small btn-unassign-flete" data-envio-id="${fl.id}" style="margin-left:8px;">Desvincular</button></li>`;
                    });
                    html += '</ul>';
                    if (factura.costo_envio_vinculado) {
                        html += `<p class="description" style="margin:8px 0 0;">Total fletes vinculados: <strong>$${Number(factura.costo_envio_vinculado).toLocaleString('es-CL')}</strong></p>`;
                    }
                } else {
                    html = '<p class="description" style="margin:0;">Sin fletes vinculados.</p>';
                }
                $('#detail-shipping-linked').html(html);

                const pendientes = factura.fletes_sin_vincular || [];
                const $assignWrap = $('#detail-shipping-assign');
                const $sel = $('#detail-assign-flete-id').empty();
                if (pendientes.length) {
                    $assignWrap.show();
                    $sel.append('<option value="">— Seleccionar flete pendiente —</option>');
                    pendientes.forEach(fl => {
                        $sel.append(`<option value="${fl.id}">Folio ${fl.folio} — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}</option>`);
                    });
                } else {
                    $assignWrap.hide();
                }
            }
        }

        renderDetailItems(factura);
        renderDetailAudit(factura);
        $('#modal-invoice-detail').css('display', 'flex');
    }

    function reloadInvoiceDetail() {
        if (!currentDetailFacturaId) return;
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: currentDetailFacturaId
        }, function(response) {
            if (response.success) showInvoiceDetail(response.data);
        });
    }

    function saveDocumentType(facturaId, nuevoTipo, done) {
        if (!facturaId || !nuevoTipo) {
            alert('Seleccione un tipo de documento');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_update_document_type',
            nonce: nonce,
            factura_id: facturaId,
            documento_subtipo: nuevoTipo
        }, function(res) {
            if (res.success) {
                if (typeof done === 'function') done(res);
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al guardar tipo');
            }
        });
    }

    $('#btn-confirm-tipo').on('click', function() {
        const nuevoTipo = $('#detail-tipo-select').val();
        saveDocumentType(currentDetailFacturaId, nuevoTipo, function() {
            reloadInvoiceDetail();
        });
    });

    $('#btn-detail-nc-link').on('click', function() {
        const origenId = $('#detail-nc-origen-id').val();
        if (!origenId) {
            alert('Busque y seleccione un folio de productos o flete');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_link_credit_note_origin',
            nonce: nonce,
            factura_nc_id: currentDetailFacturaId,
            factura_origen_id: origenId
        }, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al vincular NC');
            }
        });
    });

    $('#btn-assign-flete').on('click', function() {
        const envioId = $('#detail-assign-flete-id').val();
        if (!envioId) { alert('Seleccione un flete'); return; }
        $.post(ajaxurl, {
            action: 'riverso_assign_shipping_invoice',
            nonce: nonce,
            factura_productos_id: currentDetailFacturaId,
            factura_envio_id: envioId
        }, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al vincular');
            }
        });
    });

    $('#btn-envio-assign').on('click', function() {
        const targetId = $('#detail-envio-target-id').val();
        if (!targetId) { alert('Seleccione la factura de productos'); return; }
        $.post(ajaxurl, {
            action: 'riverso_assign_shipping_invoice',
            nonce: nonce,
            factura_productos_id: targetId,
            factura_envio_id: currentDetailFacturaId
        }, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al vincular');
            }
        });
    });

    $(document).on('click', '.btn-unassign-flete, #btn-envio-unassign, .btn-unassign-producto', function() {
        const envioId = $(this).data('envio-id') || currentDetailFacturaId;
        const productosId = $(this).data('productos-id') || null;
        const isEnvioDetail = $('#detail-envio-assign-section').is(':visible');
        const msg = productosId
            ? '¿Desvincular esta factura de productos del flete?'
            : (isEnvioDetail ? '¿Desvincular este flete de TODAS las facturas de productos?' : '¿Desvincular este flete de la factura?');
        if (!confirm(msg)) return;
        const payload = {
            action: 'riverso_unassign_shipping_invoice',
            nonce: nonce,
            factura_envio_id: envioId
        };
        if (productosId) {
            payload.factura_productos_id = productosId;
        } else if (!isEnvioDetail && currentDetailFacturaId) {
            payload.factura_productos_id = currentDetailFacturaId;
        }
        $.post(ajaxurl, payload, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error');
            }
        });
    });
    
    $('#btn-close-detail').on('click', function() {
        $('#modal-invoice-detail').hide();
        currentDetailFacturaId = null;
    });
    
    // Vincular / editar SKU
    function postSkuLink(itemId, sku, extra) {
        extra = extra || {};
        return $.post(ajaxurl, $.extend({
            action: 'riverso_link_code',
            nonce: nonce,
            item_id: itemId,
            sku_local: sku,
            crear_mapeo: true
        }, extra));
    }

    function reloadInvoiceDetail() {
        if (!currentDetailFacturaId) return;
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: currentDetailFacturaId
        }, function(response) {
            if (response.success) showInvoiceDetail(response.data);
        });
    }

    function handleSkuLinkResponse(response, retry) {
        if (response.success) {
            const msg = response.data && response.data.message;
            if (msg && /ítems posteriores/.test(msg)) {
                alert(msg);
            }
            reloadInvoiceDetail();
            loadInvoices();
            return;
        }
        const data = response.data || {};
        if (data.conflict && retry) {
            if (confirm((data.message || 'Conflicto de SKU') + '\n\n¿Reasignar de todas formas? El dueño anterior perderá este SKU.')) {
                retry();
            }
            return;
        }
        alert(data.message || 'Error actualizando SKU');
    }

    function setSkuEditMode($wrap, editing) {
        const $input = $wrap.find('.sku-input');
        $wrap.find('.btn-edit-sku').toggle(!editing);
        $wrap.find('.btn-link-sku, .btn-clear-sku, .btn-cancel-sku').toggle(!!editing);
        $input.prop('readonly', !editing);
        $wrap.find('.sku-suggest-list').hide().empty();
        if (editing) {
            $input.trigger('focus').trigger('select');
        } else {
            $input.val($wrap.data('original') || '');
        }
    }

    $(document).on('click', '.btn-edit-sku', function() {
        setSkuEditMode($(this).closest('.sku-edit-wrap'), true);
    });

    $(document).on('click', '.btn-cancel-sku', function() {
        setSkuEditMode($(this).closest('.sku-edit-wrap'), false);
    });

    $(document).on('click', '.btn-link-sku', function() {
        const btn = $(this);
        const itemId = btn.data('item');
        const sku = btn.closest('.sku-edit-wrap').find('.sku-input').val().trim();
        if (!sku) {
            alert('Ingresa un SKU');
            return;
        }
        postSkuLink(itemId, sku).done(function(response) {
            handleSkuLinkResponse(response, function() {
                postSkuLink(itemId, sku, {force: 1}).done(function(res) {
                    handleSkuLinkResponse(res);
                });
            });
        });
    });

    $(document).on('click', '.btn-clear-sku', function() {
        const itemId = $(this).data('item');
        if (!confirm('¿Quitar el SKU de este ítem?')) return;
        postSkuLink(itemId, '', {clear: 1}).done(function(response) {
            handleSkuLinkResponse(response);
        });
    });

    function formatSkuDate(value, withTime) {
        if (!value) return '—';
        const text = String(value).trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(text) && !withTime) {
            const d = new Date(text + 'T00:00:00');
            if (!Number.isNaN(d.getTime())) {
                return d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' });
            }
        }
        const d = new Date(text.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return text;
        const date = d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' });
        if (withTime === false || (/^\d{4}-\d{2}-\d{2}$/.test(text))) {
            return date;
        }
        const time = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
        return date + ' ' + time;
    }

    function openSkuHistory(sku, code) {
        $('#sku-history-title').text('Historial de mapeo' + (sku ? ' SKU ' + sku : (code ? ' código ' + code : '')));
        $('#sku-history-owners').text('Cargando…');
        $('#sku-history-list').empty();
        $('#modal-sku-history').show();
        $.post(ajaxurl, {
            action: 'riverso_get_sku_mapping_history',
            nonce: nonce,
            sku_local: sku || '',
            codigo_proveedor: code || ''
        }, function(response) {
            if (!response.success) {
                $('#sku-history-owners').text(response.data?.message || 'No se pudo cargar el historial');
                return;
            }
            const owners = response.data.owners || [];
            const lastSeen = response.data.last_seen_document_date;
            const mappedAt = response.data.sku_mapped_at;
            let header = '';
            if (owners.length) {
                const labels = owners.map(function(o) {
                    return (o.proveedor_nombre || ('Proveedor #' + o.proveedor_id)) + ' / ' + o.codigo_proveedor;
                });
                header = 'Dueño actual: ' + labels.join(', ');
            } else {
                header = sku ? 'Este SKU no tiene un dueño único asignado.' : 'Sin dueño actual.';
            }
            const extras = [];
            if (mappedAt) extras.push('Modificado: ' + formatSkuDate(mappedAt));
            if (lastSeen) extras.push('Último documento visto: ' + formatSkuDate(lastSeen, false));
            if (extras.length) header += ' · ' + extras.join(' · ');
            $('#sku-history-owners').text(header);
            const logs = response.data.history || [];
            const $list = $('#sku-history-list');
            if (!logs.length) {
                $list.append('<tr><td colspan="5" class="description">Sin cambios de mapeo todavía.</td></tr>');
                return;
            }
            logs.forEach(function(log) {
                const lastDoc = log.last_seen_document_date || log.document_date || '';
                $list.append(
                    '<tr>' +
                    '<td class="sku-history-date">' + escHtml(formatSkuDate(log.modified_at || log.created_at)) + '</td>' +
                    '<td class="sku-history-date">' + escHtml(lastDoc ? formatSkuDate(lastDoc, false) : '—') + '</td>' +
                    '<td><strong>' + escHtml(log.action_label || log.action) + '</strong></td>' +
                    '<td>' + escHtml(log.user_name || 'Sistema') + '</td>' +
                    '<td>' + escHtml(log.details || '—') + '</td>' +
                    '</tr>'
                );
            });
        });
    }

    $(document).on('click', '.btn-apply-sku-suggest', function() {
        const $wrap = $(this).closest('.sku-edit-wrap');
        const sku = String($(this).data('sku') || $wrap.data('suggest') || '');
        if (!sku) return;
        $wrap.find('.sku-input').val(sku);
        setSkuEditMode($wrap, true);
        $wrap.find('.btn-link-sku').trigger('click');
    });

    let skuSuggestTimer = null;
    $(document).on('input', '.sku-input', function() {
        const $input = $(this);
        if ($input.prop('readonly')) return;
        const $wrap = $input.closest('.sku-edit-wrap');
        const $list = $wrap.find('.sku-suggest-list');
        const term = $input.val().trim();
        clearTimeout(skuSuggestTimer);
        if (term.length < 1) {
            $list.hide().empty();
            return;
        }
        skuSuggestTimer = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'riverso_search_sku_catalog',
                nonce: nonce,
                search: term
            }, function(r) {
                if (!r.success) {
                    $list.hide().empty();
                    return;
                }
                const products = r.data.products || [];
                if (!products.length) {
                    $list.html('<div class="sku-suggest-item">Sin sugerencias de catálogo</div>').show();
                    return;
                }
                let html = '';
                products.forEach(function(p) {
                    const sku = p.canonical_sku || '';
                    const name = p.nombre_canonico || '';
                    html += `<div class="sku-suggest-item" data-sku="${escAttr(sku)}"><strong>${escHtml(sku)}</strong>${name ? '<br><small>' + escHtml(name) + '</small>' : ''}</div>`;
                });
                $list.html(html).show();
            });
        }, 250);
    });

    $(document).on('click', '.sku-suggest-item', function() {
        const sku = String($(this).data('sku') || '');
        if (!sku) return;
        const $wrap = $(this).closest('.sku-edit-wrap');
        $wrap.find('.sku-input').val(sku);
        $wrap.find('.sku-suggest-list').hide().empty();
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.sku-edit-wrap').length) {
            $('.sku-suggest-list').hide();
        }
    });

    $(document).on('click', '.btn-sku-history', function() {
        openSkuHistory($(this).data('sku') || '', $(this).data('code') || '');
    });

    $('#btn-close-sku-history, #btn-close-sku-history-2').on('click', function() {
        $('#modal-sku-history').hide();
    });
    
    // Cargar al inicio
    loadInvoices(1);
    const openFacturaId = new URLSearchParams(window.location.search).get('factura');
    if (openFacturaId) {
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: openFacturaId
        }, function(response) {
            if (response.success) showInvoiceDetail(response.data);
        });
    }
});
</script>
