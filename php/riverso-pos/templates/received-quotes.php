<?php
/**
 * Template: Cotizaciones Recibidas
 * Gestión de cotizaciones de proveedores con comparación de costos
 */

if (!defined('ABSPATH')) {
    exit;
}

$estados = Riverso_POS_Received_Quote_Module::ESTADOS;
$match_status = Riverso_POS_Received_Quote_Module::MATCH_STATUS;
$decision_status = Riverso_POS_Received_Quote_Module::DECISION_STATUS;
$source_types = Riverso_POS_Received_Quote_Module::SOURCE_TYPES;
$doc_types = Riverso_POS_Received_Quote_Module::DOC_TYPES;
?>

<div class="wrap riverso-pos-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-media-document"></span>
        Cotizaciones Recibidas
    </h1>
    <button type="button" class="page-title-action" id="btn-nueva-cotizacion">
        <span class="dashicons dashicons-plus-alt2"></span> Nueva Cotización
    </button>
    <button type="button" class="page-title-action" id="btn-subir-archivo">
        <span class="dashicons dashicons-upload"></span> Subir Archivo
    </button>
    <hr class="wp-header-end">

    <!-- Vista Lista -->
    <div id="vista-lista">
        <!-- Stats Cards -->
        <div class="riverso-stats-grid" id="stats-cards">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><span class="dashicons dashicons-list-view"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-total">0</span>
                    <span class="stat-label">Total</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><span class="dashicons dashicons-edit"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-activas">0</span>
                    <span class="stat-label">Activas</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-yellow"><span class="dashicons dashicons-visibility"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-revision">0</span>
                    <span class="stat-label">En Revisión</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-aprobadas">0</span>
                    <span class="stat-label">Aprobadas</span>
                </div>
            </div>
            <div class="stat-card stat-card-clickable" id="stat-card-confirmar" title="Ver posibles cotizaciones por confirmar">
                <div class="stat-icon bg-yellow"><span class="dashicons dashicons-warning"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-por-confirmar">0</span>
                    <span class="stat-label">Por confirmar</span>
                </div>
            </div>
        </div>
        <div id="aviso-posibles-pendientes" class="notice notice-warning" style="display:none;margin:0 0 16px;">
            <p></p>
        </div>

        <!-- Filtros -->
        <div class="riverso-filters">
            <div class="filter-row">
                <input type="text" id="filtro-buscar" placeholder="Buscar por número o proveedor..." class="regular-text">
                <select id="filtro-estado">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filtro-tipo-doc">
                    <option value="todo">Todo</option>
                    <option value="cotizacion" selected>Cotizaciones</option>
                    <option value="posible_cotizacion">Posibles cotizaciones</option>
                </select>
                <select id="filtro-fuente">
                    <option value="">Todas las fuentes</option>
                    <?php foreach ($source_types as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filtro-proveedor">
                    <option value="">Todos los proveedores</option>
                </select>
                <input type="date" id="filtro-desde" placeholder="Desde">
                <input type="date" id="filtro-hasta" placeholder="Hasta">
                <button type="button" class="button" id="btn-filtrar">
                    <span class="dashicons dashicons-filter"></span> Filtrar
                </button>
                <button type="button" class="button" id="btn-limpiar-filtros">Limpiar</button>
                <label style="margin-left:8px;display:inline-flex;align-items:center;gap:4px;">
                    <input type="checkbox" id="filtro-solo-final" value="1"> Solo versión final
                </label>
            </div>
        </div>

        <!-- Tabla de Cotizaciones -->
        <table class="wp-list-table widefat fixed striped" id="tabla-cotizaciones">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Proveedor</th>
                    <th>Nº Documento</th>
                    <th style="width:100px">Versión</th>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Fuente</th>
                    <th style="width:100px">Ítems</th>
                    <th style="text-align:right">Total</th>
                    <th style="width:120px">Estado</th>
                    <th style="width:220px">Acciones</th>
                </tr>
            </thead>
            <tbody id="lista-cotizaciones">
                <tr><td colspan="11" class="loading">Cargando cotizaciones...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Vista Detalle/Editor -->
    <div id="vista-detalle" style="display:none;">
        <div class="riverso-detail-header">
            <button type="button" class="button" id="btn-volver-lista">
                <span class="dashicons dashicons-arrow-left-alt"></span> Volver
            </button>
            <h2 id="titulo-cotizacion">Nueva Cotización</h2>
            <div class="header-actions">
                <span class="estado-badge" id="badge-estado"></span>
                <span class="tipo-doc-badge" id="badge-tipo-doc"></span>
                <span class="tipo-confirm-aviso" id="aviso-confirmar-tipo" style="display:none;">Por confirmar</span>
                <select id="select-tipo-doc" style="display:none;">
                    <?php foreach ($doc_types as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" id="btn-confirmar-tipo" style="display:none;">
                    <span class="dashicons dashicons-yes"></span> Confirmar tipo
                </button>
                <a class="button" id="btn-ver-correo" href="#" target="_blank" rel="noopener" style="display:none;">
                    <span class="dashicons dashicons-email-alt"></span> Ver correo
                </a>
                <button type="button" class="button" id="btn-ver-adjuntos" style="display:none;">
                    <span class="dashicons dashicons-paperclip"></span> Ver adjuntos
                </button>
            </div>
        </div>
        <div id="banner-por-confirmar" class="quote-tipo-banner" style="display:none;">
            <strong>Por confirmar.</strong>
            Una persona debe revisar este documento (posible cotización, total 0 o origen automático).
            Elija el tipo y pulse <em>Confirmar tipo</em> en esta misma barra.
        </div>
        <div id="banner-parse-error" class="quote-tipo-banner quote-parse-error-banner" style="display:none;">
            <strong>Error al procesar con Gemini.</strong>
            <span id="banner-parse-error-msg"></span>
            <button type="button" class="button button-small" id="btn-reintentar-parse" style="margin-left:8px;">
                Reintentar parseo
            </button>
        </div>

        <div id="quote-versions-bar" class="riverso-card" style="display:none;padding:12px 16px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                <strong style="margin-right:4px;">Versiones:</strong>
                <div id="quote-versions-chips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                <span style="flex:1;"></span>
                <button type="button" class="button button-small" id="btn-vincular-version">
                    <span class="dashicons dashicons-admin-links"></span> Vincular versión
                </button>
                <button type="button" class="button button-small" id="btn-desvincular-version" style="display:none;">
                    Desvincular
                </button>
                <button type="button" class="button button-small" id="btn-comparar-versiones" style="display:none;">
                    <span class="dashicons dashicons-randomize"></span> Comparar
                </button>
            </div>
        </div>

        <!-- Datos generales -->
        <div class="riverso-card">
            <h3><span class="dashicons dashicons-info"></span> Datos Generales</h3>
            <form id="form-cotizacion">
                <input type="hidden" id="cotizacion-id" value="0">
                <div class="form-grid cols-4">
                    <div class="form-group">
                        <label for="proveedor_id">Proveedor</label>
                        <select id="proveedor_id" name="proveedor_id" class="regular-text">
                            <option value="">Seleccionar proveedor...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_documento">Nº Documento</label>
                        <input type="text" id="numero_documento" name="numero_documento" class="regular-text">
                    </div>
                    <div class="form-group">
                        <label for="fecha_documento">Fecha Documento</label>
                        <input type="date" id="fecha_documento" name="fecha_documento">
                    </div>
                    <div class="form-group">
                        <label for="moneda">Moneda</label>
                        <select id="moneda" name="moneda">
                            <option value="CLP">CLP - Peso Chileno</option>
                            <option value="USD">USD - Dólar</option>
                            <option value="EUR">EUR - Euro</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid cols-2">
                    <div class="form-group">
                        <label for="notas">Notas</label>
                        <textarea id="notas" name="notas" rows="2" class="large-text"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Archivo</label>
                        <div id="archivo-info">
                            <span class="no-archivo">Sin archivo adjunto</span>
                        </div>
                        <div id="archivo-actions" style="margin-top:6px;display:none;">
                            <button type="button" class="button" id="btn-ver-documento">
                                <span class="dashicons dashicons-media-document"></span> Ver Documento
                            </button>
                        </div>
                        <input type="file" id="archivo-upload" accept=".pdf,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.webp,.tif,.tiff" style="margin-top:5px;">
                        <p class="description" style="margin:4px 0 0;">Reemplazar archivo de esta cotización (borra ítems y re-procesa).</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="texto-manual">Pegar texto (ingreso manual)</label>
                    <textarea id="texto-manual" rows="4" class="large-text" placeholder="Pega el cuerpo de la cotización y pulsa Parsear texto"></textarea>
                    <button type="button" class="button" id="btn-parsear-texto">Parsear texto</button>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-saved"></span> Guardar
                    </button>
                    <button type="button" class="button" id="btn-match-todos" title="Buscar coincidencias automáticas">
                        <span class="dashicons dashicons-search"></span> Match Automático
                    </button>
                    <button type="button" class="button button-link-delete" id="btn-eliminar-cotizacion" style="float:right;">
                        <span class="dashicons dashicons-trash"></span> Eliminar
                    </button>
                </div>
            </form>
        </div>

        <!-- Totales -->
        <div class="riverso-totals-bar">
            <div class="total-item">
                <span class="label">Subtotal:</span>
                <span class="value" id="total-subtotal">$0</span>
            </div>
            <div class="total-item" id="total-descuento-wrap" style="display:none;">
                <span class="label">Descuento folio:</span>
                <span class="value" id="total-descuento">$0</span>
            </div>
            <div class="total-item">
                <span class="label">IVA:</span>
                <span class="value" id="total-impuesto">$0</span>
            </div>
            <div class="total-item total-main">
                <span class="label">Total:</span>
                <span class="value" id="total-total">$0</span>
            </div>
            <div class="total-actions">
                <button type="button" class="button" id="btn-parsear">
                    <span class="dashicons dashicons-media-code"></span> Parsear con Gemini
                </button>
                <button type="button" class="button" id="btn-pendiente">Pendiente</button>
                <button type="button" class="button button-link-delete" id="btn-rechazar">Rechazar</button>
                <button type="button" class="button button-primary" id="btn-aprobar" style="display:none;">
                    <span class="dashicons dashicons-yes"></span> Aprobar Cotización
                </button>
                <button type="button" class="button" id="btn-convertir-oc" style="display:none;">Convertir a OC</button>
                <button type="button" class="button" id="btn-ver-comparacion">
                    <span class="dashicons dashicons-chart-line"></span> Evaluar costos
                </button>
            </div>
        </div>

        <!-- Ítems de la cotización -->
        <div class="riverso-card">
            <div class="card-header">
                <h3><span class="dashicons dashicons-list-view"></span> Ítems de la Cotización</h3>
                <button type="button" class="button" id="btn-agregar-item">
                    <span class="dashicons dashicons-plus"></span> Agregar Ítem
                </button>
            </div>
            <table class="wp-list-table widefat fixed striped" id="tabla-items">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:120px">Cód. Proveedor</th>
                        <th>Descripción</th>
                        <th style="width:80px">Cant.</th>
                        <th style="width:100px;text-align:right">Costo Neto</th>
                        <th style="width:100px;text-align:right">Total</th>
                        <th style="width:120px">Match</th>
                        <th style="width:100px">Decisión</th>
                        <th style="width:100px">Δ Costo</th>
                        <th style="width:100px">Acciones</th>
                    </tr>
                </thead>
                <tbody id="lista-items">
                    <tr><td colspan="10" class="empty">Sin ítems. Agregue ítems manualmente o suba un archivo.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Editar Ítem -->
    <div id="modal-item" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:700px;">
            <div class="modal-header">
                <h3 id="modal-item-titulo">Agregar Ítem</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <form id="form-item">
                <input type="hidden" id="item-id" value="0">
                <div class="modal-body">
                    <div class="form-grid cols-3">
                        <div class="form-group">
                            <label for="item-codigo-proveedor">Código Proveedor</label>
                            <input type="text" id="item-codigo-proveedor" name="codigo_proveedor">
                        </div>
                        <div class="form-group">
                            <label for="item-codigo-barras">Código de Barras</label>
                            <input type="text" id="item-codigo-barras" name="codigo_barras">
                        </div>
                        <div class="form-group">
                            <label for="item-unidad">Unidad</label>
                            <select id="item-unidad" name="unidad">
                                <option value="UN">UN - Unidad</option>
                                <option value="CJ">CJ - Caja</option>
                                <option value="KG">KG - Kilogramo</option>
                                <option value="MT">MT - Metro</option>
                                <option value="LT">LT - Litro</option>
                                <option value="PAR">PAR - Par</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="item-descripcion">Descripción *</label>
                        <textarea id="item-descripcion" name="descripcion" rows="2" required></textarea>
                    </div>
                    <div class="form-grid cols-4">
                        <div class="form-group">
                            <label for="item-cantidad">Cantidad *</label>
                            <input type="number" id="item-cantidad" name="cantidad" value="1" min="0.0001" step="0.0001" required>
                        </div>
                        <div class="form-group">
                            <label for="item-costo-neto">Costo Neto *</label>
                            <input type="number" id="item-costo-neto" name="costo_neto" value="0" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="item-costo-impuesto">IVA</label>
                            <input type="number" id="item-costo-impuesto" name="costo_impuesto" value="0" min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label for="item-costo-total">Total</label>
                            <input type="number" id="item-costo-total" name="costo_total" value="0" min="0" step="0.01" readonly>
                        </div>
                    </div>
                    <!-- Match info -->
                    <div id="item-match-info" style="display:none;" class="match-info-box">
                        <h4>Producto Vinculado</h4>
                        <div id="item-match-details"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button" id="btn-buscar-match">
                        <span class="dashicons dashicons-search"></span> Buscar Match
                    </button>
                    <button type="submit" class="button button-primary">Guardar</button>
                    <button type="button" class="button modal-close">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Comparación de Costos -->
    <div id="modal-comparacion" class="riverso-modal" style="display:none;">
        <div class="modal-content quote-eval-modal">
            <div class="modal-header">
                <div>
                    <h3 id="quote-eval-title">Evaluación de costos</h3>
                    <div class="analysis-sku" id="quote-eval-meta">—</div>
                </div>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="quote-eval-toolbar">
                    <div class="rce-view-toggle" role="group" aria-label="Vista neto/bruto" id="quote-eval-view-toggle">
                        <button type="button" class="button rce-view-btn is-active" data-quote-view="neto">Neto</button>
                        <button type="button" class="button rce-view-btn" data-quote-view="bruto">Bruto</button>
                    </div>
                    <div class="rce-view-toggle" role="group" aria-label="Base de costo" id="quote-eval-cost-toggle">
                        <button type="button" class="button rce-cost-btn" data-quote-cost="referencia" title="Precio lista / antes de D/R">Costo referencia</button>
                        <button type="button" class="button rce-cost-btn is-active" data-quote-cost="tras_dr" title="Tras descuento y recargo de la fila">Costo tras Descuento/Recargo</button>
                        <button type="button" class="button rce-cost-btn" data-quote-cost="tras_dr_folio" title="Tras D/R de fila y del folio">Costo tras Descuento/Recargo Folio</button>
                    </div>
                    <label class="quote-eval-decimals">
                        <input type="checkbox" id="quote-eval-toggle-decimals" checked>
                        Costos con decimales <em>(hasta 3)</em>
                    </label>
                    <div class="rce-view-toggle" role="group" aria-label="Base de comparación" id="quote-eval-base-toggle">
                        <button type="button" class="button rce-base-btn is-active" data-quote-base="auto">Auto (mayor alza)</button>
                        <button type="button" class="button rce-base-btn" data-quote-base="invoice">Facturas</button>
                        <button type="button" class="button rce-base-btn" data-quote-base="quote">Cotizaciones aprobadas</button>
                    </div>
                    <button type="button" class="button" id="btn-draft-reclamo">Preparar reclamo</button>
                    <button type="button" class="button" id="btn-print-quote-eval" disabled>
                        <span class="dashicons dashicons-printer" style="vertical-align:middle;margin-top:3px;"></span>
                        Imprimir
                    </button>
                </div>
                <div class="comparison-summary" id="comparison-summary"></div>
                <div class="quote-eval-table-wrap">
                    <table class="wp-list-table widefat striped" id="tabla-comparacion">
                        <thead>
                            <tr>
                                <th style="width:36px;">#</th>
                                <th style="width:100px;">Código</th>
                                <th>Descripción</th>
                                <th style="width:110px;text-align:right;">Costo cotización</th>
                                <th style="width:160px;">Última facturación</th>
                                <th style="width:150px;">Última cotización</th>
                                <th style="width:140px;">Legacy</th>
                                <th style="width:90px;">Tendencia</th>
                                <th style="width:90px;text-align:right;">Diferencia</th>
                                <th style="width:70px;text-align:right;">Dif. %</th>
                            </tr>
                        </thead>
                        <tbody id="lista-comparacion"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="button modal-close">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal borrador de reclamo -->
    <div id="modal-reclamo" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header">
                <h3>Borrador de reclamo</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="quote-eval-toolbar" style="margin-bottom:12px;">
                    <div class="rce-view-toggle" role="group" aria-label="Tipo de reclamo" id="reclamo-mode-toggle">
                        <button type="button" class="button rce-view-btn is-active" data-reclamo-mode="simple">Opción simple</button>
                        <button type="button" class="button rce-view-btn" data-reclamo-mode="complex">Opción compleja</button>
                    </div>
                    <button type="button" class="button button-primary" id="btn-copiar-reclamo">
                        <span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-top:3px;"></span>
                        Copiar texto
                    </button>
                    <span id="reclamo-copy-status" style="font-size:13px;color:#00a32a;display:none;">Copiado</span>
                </div>
                <p class="description" id="reclamo-mode-hint" style="margin-top:0;">
                    Pide usar los precios anteriores: código, nombre y precio anterior.
                </p>
                <div class="form-group">
                    <label for="reclamo-asunto">Asunto</label>
                    <input type="text" id="reclamo-asunto" class="regular-text" style="width:100%;">
                </div>
                <div class="form-group">
                    <label for="reclamo-cuerpo">Correo</label>
                    <textarea id="reclamo-cuerpo" rows="16" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.45;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="button button-primary" id="btn-copiar-reclamo-footer">Copiar texto</button>
                <button type="button" class="button modal-close">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Decisión de Ítem -->
    <div id="modal-decision" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Decisión del Ítem</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <form id="form-decision">
                <input type="hidden" id="decision-item-id" value="0">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Decisión</label>
                        <div class="decision-buttons">
                            <button type="button" class="button decision-btn" data-decision="accepted">
                                <span class="dashicons dashicons-yes"></span> Aceptar
                            </button>
                            <button type="button" class="button decision-btn" data-decision="modified">
                                <span class="dashicons dashicons-edit"></span> Modificado
                            </button>
                            <button type="button" class="button decision-btn" data-decision="rejected">
                                <span class="dashicons dashicons-no"></span> Rechazar
                            </button>
                        </div>
                        <input type="hidden" id="decision-value" value="">
                    </div>
                    <div class="form-group">
                        <label for="decision-notas">Notas (opcional)</label>
                        <textarea id="decision-notas" rows="2"></textarea>
                    </div>
                    <div class="form-group" id="decision-manual-match" style="display:none;">
                        <label for="decision-producto">Vincular Producto Manualmente</label>
                        <input type="text" id="decision-producto-search" placeholder="Buscar por SKU o nombre...">
                        <input type="hidden" id="decision-producto-id" value="">
                        <div id="decision-producto-result"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="button button-primary" disabled>Guardar Decisión</button>
                    <button type="button" class="button modal-close">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload modal -->
    <div id="modal-upload" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3>Subir Archivo de Cotización</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p class="description" style="margin-bottom:12px;">
                    Suba <strong>un PDF, imagen o Excel</strong>. Arrástrelo a la zona o use el botón para buscar.
                </p>
                <input type="file" id="file-upload-input" accept=".pdf,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.webp,.tif,.tiff" style="display:none;">
                <div class="upload-zone" id="upload-zone">
                    <span class="dashicons dashicons-upload"></span>
                    <p>Arrastra el archivo aquí</p>
                    <p class="upload-formats">PDF, JPG, PNG, WEBP, TIFF, Excel (.xlsx, .xls), CSV, TXT</p>
                </div>
                <div class="quote-upload-toolbar">
                    <button type="button" class="button button-primary" id="btn-browse-quote-file">
                        <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                    </button>
                    <button type="button" class="button button-primary" id="btn-upload-quote-file" disabled>
                        Subir y procesar
                    </button>
                </div>
                <p id="quote-upload-file-name" class="description" style="margin-top:10px;text-align:center;"></p>
                <div id="quote-upload-status" class="quote-upload-status" aria-live="polite"></div>
                <div id="upload-progress" style="display:none;">
                    <div class="progress-bar"><div class="progress-fill"></div></div>
                    <p class="progress-text">Subiendo archivo...</p>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-adjuntos-origen" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3>Adjuntos del correo</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="adjuntos-origen-meta" class="description" style="margin-top:0;"></p>
                <ul id="lista-adjuntos-origen" style="margin:0;padding-left:18px;"></ul>
            </div>
        </div>
    </div>

    <div id="modal-vincular-version" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:560px;">
            <div class="modal-header">
                <h3>Vincular versión</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p class="description">Busque otra cotización (ID, nº documento o proveedor) para unirlas como versiones de la misma negociación.</p>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <input type="text" id="version-search-q" class="regular-text" placeholder="Buscar…" style="flex:1;">
                    <button type="button" class="button" id="btn-version-search">Buscar</button>
                </div>
                <div id="version-search-results"></div>
            </div>
        </div>
    </div>

    <div id="modal-comparar-versiones" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <h3>Comparar versiones</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
                    <label>Base (A)
                        <select id="compare-version-a"></select>
                    </label>
                    <label>Nueva (B)
                        <select id="compare-version-b"></select>
                    </label>
                    <button type="button" class="button button-primary" id="btn-run-compare">Comparar</button>
                </div>
                <div id="compare-version-stats" class="description" style="margin-bottom:8px;"></div>
                <div style="overflow:auto;max-height:480px;">
                    <table class="wp-list-table widefat striped" id="tabla-compare-versions">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th style="text-align:right">Costo A</th>
                                <th style="text-align:right">Costo B</th>
                                <th style="text-align:right">Δ</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.riverso-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card-clickable {
    cursor: pointer;
}
.stat-card-clickable:hover {
    border-color: #dba617;
}
.stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
}
.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stat-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #fff;
}
.bg-blue { background: #2271b1; }
.bg-orange { background: #d63638; }
.bg-yellow { background: #dba617; }
.bg-green { background: #00a32a; }
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 24px; font-weight: 600; line-height: 1; }
.stat-label { color: #666; font-size: 13px; }

.riverso-filters {
    background: #fff;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.filter-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-row input[type="text"],
.filter-row input[type="date"],
.filter-row select {
    max-width: 200px;
}

.riverso-card {
    background: #fff;
    border: 1px solid #ddd;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.riverso-card h3 {
    margin: 0 0 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.card-header h3 { margin: 0; }

.riverso-detail-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}
.riverso-detail-header h2 {
    flex: 1;
    margin: 0;
}
.header-actions {
    display: flex;
    gap: 10px;
}

.form-grid {
    display: grid;
    gap: 15px;
    margin-bottom: 15px;
}
.form-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.form-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
.form-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
}
.form-actions {
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.riverso-totals-bar {
    background: #f0f0f1;
    border: 1px solid #ddd;
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 30px;
}
.total-item {
    display: flex;
    flex-direction: column;
}
.total-item .label {
    font-size: 12px;
    color: #666;
}
.total-item .value {
    font-size: 18px;
    font-weight: 600;
}
.total-main .value {
    font-size: 24px;
    color: #00a32a;
}
.total-actions {
    margin-left: auto;
    display: flex;
    gap: 10px;
}

.estado-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}
.estado-draft { background: #e0e0e0; color: #333; }
.estado-uploaded { background: #dbeafe; color: #1e40af; }
.estado-parsed { background: #fef3c7; color: #92400e; }
.estado-under_review { background: #fef3c7; color: #92400e; }
.estado-approved { background: #d1fae5; color: #065f46; }
.estado-rejected { background: #fee2e2; color: #991b1b; }
.estado-converted_to_expected { background: #ddd6fe; color: #5b21b6; }
.estado-archived { background: #e5e7eb; color: #374151; }

.tipo-doc-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    background: #dbeafe;
    color: #1e40af;
}
.tipo-doc-badge.tipo-posible_cotizacion {
    background: #fef3c7;
    color: #92400e;
}
.tipo-confirm-aviso {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    background: #fef3c7;
    color: #92400e;
}
tr.quote-por-confirmar {
    background: #fffbeb;
}
.quote-tipo-banner {
    margin: 12px 0 16px;
    padding: 12px 14px;
    border-left: 4px solid #dba617;
    background: #fff8e5;
    color: #614200;
}
.quote-parse-error-banner {
    border-left-color: #b32d2e;
    background: #fcf0f1;
    color: #3c434a;
}

.match-badge {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
}
.match-pending { background: #f3f4f6; color: #6b7280; }
.match-matched { background: #d1fae5; color: #065f46; }
.match-not_found { background: #fee2e2; color: #991b1b; }
.match-ambiguous { background: #fef3c7; color: #92400e; }
.match-manual { background: #dbeafe; color: #1e40af; }

.decision-pending { color: #6b7280; }
.decision-accepted { color: #065f46; }
.decision-modified { color: #1e40af; }
.decision-rejected { color: #991b1b; }

.cost-up { color: #dc2626; }
.cost-down { color: #16a34a; }

.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
.modal-content {
    background: #fff;
    border-radius: 8px;
    width: 90%;
    max-height: 90vh;
    overflow: auto;
}
.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 { margin: 0; }
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}
.modal-body { padding: 20px; }
.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.decision-buttons {
    display: flex;
    gap: 10px;
    margin: 10px 0;
}
.decision-btn.selected {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}

.upload-zone {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.upload-zone:hover,
.upload-zone.dragover {
    border-color: #2271b1;
    background: #f0f6fc;
}
.upload-zone .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #999;
}
.upload-formats {
    font-size: 12px;
    color: #666;
}
.version-badge {
    display: inline-block;
    background: #eef2ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    border-radius: 999px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.version-badge.is-final {
    background: #ecfdf5;
    color: #065f46;
    border-color: #a7f3d0;
}
.quote-version-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #fff;
    cursor: pointer;
    font-size: 12px;
}
.quote-version-chip.is-current {
    border-color: #2271b1;
    background: #f0f6fc;
    font-weight: 600;
}
.quote-version-chip.is-final {
    border-color: #00a32a;
}
.compare-status-agregado { color: #00a32a; font-weight: 600; }
.compare-status-quitado { color: #d63638; font-weight: 600; }
.compare-status-precio_cambio { color: #dba617; font-weight: 600; }
.compare-status-igual { color: #646970; }
.quote-upload-toolbar {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 14px;
    flex-wrap: wrap;
}
.quote-upload-toolbar .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    vertical-align: text-bottom;
    margin-right: 4px;
}
.quote-upload-status {
    margin-top: 12px;
}
.quote-upload-status .notice {
    margin: 0;
    padding: 8px 12px;
}
.quote-upload-status .quote-dup-actions {
    margin-top: 8px;
}

.match-info-box {
    background: #f0f6fc;
    border: 1px solid #c3daf5;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
}
.match-info-box h4 {
    margin: 0 0 10px;
    color: #1e40af;
}

.comparison-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.comparison-summary .summary-item {
    background: #f9fafb;
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}
.comparison-summary .summary-value {
    font-size: 24px;
    font-weight: 600;
}
.comparison-summary .summary-value .summary-total-hint {
    font-size: 13px;
    font-weight: 500;
    opacity: 0.65;
    margin-left: 2px;
}

.quote-eval-modal {
    max-width: 1280px;
    width: 96%;
}
.quote-eval-modal .analysis-sku {
    font-size: 13px;
    color: #646970;
    margin-top: 4px;
}
.quote-eval-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.rce-view-toggle {
    display: inline-flex;
    gap: 0;
}
.rce-view-toggle .button {
    margin: 0;
    border-radius: 0;
}
.rce-view-toggle .button:first-child {
    border-radius: 4px 0 0 4px;
}
.rce-view-toggle .button:last-child {
    border-radius: 0 4px 4px 0;
}
.rce-view-toggle .button.is-active {
    background: #2271b1;
    border-color: #2271b1;
    color: #fff;
}
.quote-eval-decimals {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    cursor: pointer;
}
.quote-eval-table-wrap {
    overflow: auto;
}
#tabla-comparacion tr.trend-up td,
#tabla-comparacion td.trend-up {
    background: #fcf0f1;
}
#tabla-comparacion tr.trend-down td,
#tabla-comparacion td.trend-down {
    background: #edfaef;
}
#tabla-comparacion td.trend-up,
#tabla-comparacion td.trend-up strong {
    color: #d63638;
}
#tabla-comparacion td.trend-down,
#tabla-comparacion td.trend-down strong {
    color: #00a32a;
}
#tabla-comparacion tr.has-change td {
    font-weight: 600;
}
.cost-doc-ref {
    font-size: 11px;
    color: #646970;
    margin-top: 2px;
    line-height: 1.35;
}
#modal-reclamo {
    z-index: 100050;
}

.loading, .empty {
    text-align: center;
    padding: 40px;
    color: #666;
}

@media (max-width: 1200px) {
    .riverso-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .form-grid.cols-4 { grid-template-columns: repeat(2, 1fr); }
}
</style>

<script>
jQuery(document).ready(function($) {
    const ajaxurl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let cotizacionActual = null;
    let itemsActuales = [];
    let proveedoresCache = [];
    let origenActual = null;
    let versionSiblingsActual = [];
    let lastQuoteEval = null;
    let quoteEvalViewMode = 'neto';
    let quoteEvalCostMode = 'tras_dr';
    let quoteEvalBaseMode = 'auto';
    let quoteEvalShowDecimals = true;

    // Formatear moneda
    function formatMoney(val) {
        return '$' + parseFloat(val || 0).toLocaleString('es-CL', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatCostMoney(n) {
        if (n === null || n === undefined || n === '' || isNaN(n)) return '—';
        const num = Number(n);
        if (!isFinite(num)) return '—';
        if (!quoteEvalShowDecimals) {
            return '$' + Math.round(num).toLocaleString('es-CL');
        }
        const rounded = Math.round(num * 1000) / 1000;
        const fixed = rounded.toFixed(3).replace(/\.?0+$/, '');
        const parts = fixed.split('.');
        const intPart = Number(parts[0]).toLocaleString('es-CL');
        return parts.length > 1 ? ('$' + intPart + ',' + parts[1]) : ('$' + intPart);
    }

    function formatDeltaPct(n) {
        if (n === null || n === undefined || isNaN(n)) return '—';
        const sign = n > 0 ? '+' : '';
        return sign + Number(n).toFixed(1) + '%';
    }

    function trendLabel(t) {
        if (t === 'subio') return 'Subió';
        if (t === 'bajo') return 'Bajó';
        if (t === 'se_mantuvo') return 'Se mantuvo';
        return '—';
    }

    function trendClass(t) {
        if (t === 'subio') return 'trend-up';
        if (t === 'bajo') return 'trend-down';
        return '';
    }

    function pickEvalBase(bases, viewMode, costMode) {
        if (!bases || typeof bases !== 'object') return null;
        viewMode = viewMode || quoteEvalViewMode;
        costMode = costMode || quoteEvalCostMode;
        const key = costMode === 'referencia' ? 'referencia'
            : (costMode === 'tras_dr_folio' ? 'tras_dr_folio' : 'tras_dr');
        let pair = bases[key];
        if (!pair && key === 'referencia') pair = bases.tras_dr;
        if (!pair && key === 'tras_dr_folio') pair = bases.tras_dr;
        if (!pair || typeof pair !== 'object') return null;
        const v = viewMode === 'bruto' ? pair.bruto : pair.neto;
        if (v === null || v === undefined || v === '' || isNaN(v)) return null;
        return Number(v);
    }

    function evalUnitCost(rowOrPrev) {
        if (!rowOrPrev) return null;
        if (rowOrPrev.costo_actual_bases || rowOrPrev.costo_bases) {
            const fromBases = pickEvalBase(rowOrPrev.costo_actual_bases || rowOrPrev.costo_bases);
            if (fromBases != null) return fromBases;
        }
        let neto = rowOrPrev.costo_actual != null ? rowOrPrev.costo_actual : rowOrPrev.costo_unitario;
        if (neto === null || neto === undefined || neto === '' || isNaN(neto)) return null;
        neto = Number(neto);
        if (quoteEvalViewMode === 'bruto') {
            return Math.round(neto * 1.19 * 10000) / 10000;
        }
        return neto;
    }

    function quoteEvalRowMetrics(row) {
        const current = evalUnitCost(row);
        const prevInv = row.prev_invoice || null;
        const prevQuote = row.prev_quote || null;
        const legacy = row.legacy || null;
        const prevInvCost = prevInv ? evalUnitCost(prevInv) : null;
        const prevQuoteCost = prevQuote ? evalUnitCost(prevQuote) : null;
        const legacyCost = legacy ? evalUnitCost(legacy) : null;

        let previous = null;
        let source = null;
        if (quoteEvalBaseMode === 'invoice') {
            previous = prevInvCost != null ? prevInvCost : legacyCost;
            source = prevInvCost != null ? 'invoice' : (legacyCost != null ? 'legacy' : null);
        } else if (quoteEvalBaseMode === 'quote') {
            previous = prevQuoteCost != null ? prevQuoteCost : legacyCost;
            source = prevQuoteCost != null ? 'quote' : (legacyCost != null ? 'legacy' : null);
        } else if (prevInvCost != null && prevQuoteCost != null) {
            const dInv = current != null ? current - prevInvCost : 0;
            const dQuote = current != null ? current - prevQuoteCost : 0;
            if (dInv >= dQuote) {
                previous = prevInvCost;
                source = 'invoice';
            } else {
                previous = prevQuoteCost;
                source = 'quote';
            }
        } else if (prevInvCost != null) {
            previous = prevInvCost;
            source = 'invoice';
        } else if (prevQuoteCost != null) {
            previous = prevQuoteCost;
            source = 'quote';
        } else if (legacyCost != null) {
            previous = legacyCost;
            source = 'legacy';
        }

        let delta = null;
        let pct = null;
        let trend = null;
        if (current != null && previous != null) {
            delta = Math.round((current - previous) * 10000) / 10000;
            if (previous !== 0) {
                pct = Math.round((delta / Math.abs(previous)) * 1000) / 10;
            }
            if (Math.abs(delta) < 0.00015) trend = 'se_mantuvo';
            else if (delta > 0) trend = 'subio';
            else trend = 'bajo';
        }
        return {
            current: current,
            prevInvCost: prevInvCost,
            prevQuoteCost: prevQuoteCost,
            legacyCost: legacyCost,
            previous: previous,
            source: source,
            delta: delta,
            pct: pct,
            trend: trend
        };
    }

    function syncQuoteEvalToggles() {
        $('#quote-eval-view-toggle .rce-view-btn').removeClass('is-active');
        $('#quote-eval-view-toggle .rce-view-btn[data-quote-view="' + quoteEvalViewMode + '"]').addClass('is-active');
        $('#quote-eval-cost-toggle .rce-cost-btn').removeClass('is-active');
        $('#quote-eval-cost-toggle .rce-cost-btn[data-quote-cost="' + quoteEvalCostMode + '"]').addClass('is-active');
        $('#quote-eval-base-toggle .rce-base-btn').removeClass('is-active');
        $('#quote-eval-base-toggle .rce-base-btn[data-quote-base="' + quoteEvalBaseMode + '"]').addClass('is-active');
    }

    function quoteEvalViewLabel() {
        return quoteEvalViewMode === 'bruto' ? 'bruto' : 'neto';
    }

    function quoteEvalCostLabel() {
        if (quoteEvalCostMode === 'referencia') return 'Costo referencia';
        if (quoteEvalCostMode === 'tras_dr_folio') return 'Costo tras Descuento/Recargo Folio';
        return 'Costo tras Descuento/Recargo';
    }

    function originCell(cost, ref, fallbackLabel) {
        if (cost == null || !ref) return '—';
        const folio = ref.folio && ref.folio !== 'LEGACY' ? ref.folio : '';
        const fecha = ref.fecha_emision || ref.importado_at || '';
        const label = ref.match_label || fallbackLabel || '';
        let html = '<div><strong>' + escapeHtml(formatCostMoney(cost)) + '</strong></div>';
        const bits = [];
        if (folio) bits.push(folio);
        if (fecha) bits.push(fecha);
        if (ref.proveedor_nombre) bits.push(ref.proveedor_nombre);
        if (bits.length) {
            html += '<div class="cost-doc-ref">' + escapeHtml(bits.join(' · ')) + '</div>';
        }
        if (label) {
            html += '<div class="cost-doc-ref">' + escapeHtml(label) + '</div>';
        }
        return html;
    }

    // Cargar lista de cotizaciones
    function cargarCotizaciones() {
        $.post(ajaxurl, {
            action: 'riverso_get_received_quotes',
            nonce: nonce,
            estado: $('#filtro-estado').val(),
            tipo_fuente: $('#filtro-fuente').val(),
            tipo_doc: $('#filtro-tipo-doc').val() || 'cotizacion',
            proveedor_id: $('#filtro-proveedor').val(),
            buscar: $('#filtro-buscar').val(),
            fecha_desde: $('#filtro-desde').val(),
            fecha_hasta: $('#filtro-hasta').val(),
            solo_version_final: $('#filtro-solo-final').is(':checked') ? 1 : 0
        }, function(r) {
            if (r.success) {
                renderCotizaciones(r.data.quotes);
                renderStats(r.data.stats);
                if (r.data.proveedores) {
                    proveedoresCache = r.data.proveedores;
                    renderProveedoresSelect();
                }
            }
        });
    }

    function renderStats(stats) {
        $('#stat-total').text(stats.total || 0);
        $('#stat-activas').text(stats.activas || 0);
        $('#stat-revision').text(stats.en_revision || 0);
        $('#stat-aprobadas').text(stats.aprobadas || 0);
        const pendientes = parseInt(stats.por_confirmar || 0, 10) || 0;
        $('#stat-por-confirmar').text(pendientes);
        if (pendientes > 0) {
            $('#aviso-posibles-pendientes').show().find('p').html(
                'Hay <strong>' + pendientes + '</strong> documento(s) <strong>por confirmar</strong> (correo o WhatsApp sin adjunto, o tipo pendiente). ' +
                'El filtro por defecto muestra solo cotizaciones confirmadas. ' +
                '<a href="#" id="link-ver-posibles">Ver posibles cotizaciones</a> y resuélvalas con <em>Confirmar tipo</em> en el detalle o en la lista.'
            );
        } else {
            $('#aviso-posibles-pendientes').hide();
        }
    }

    function renderCotizaciones(quotes) {
        const tbody = $('#lista-cotizaciones');
        if (!quotes.length) {
            tbody.html('<tr><td colspan="11" class="empty">No hay cotizaciones</td></tr>');
            return;
        }

        const estados = <?php echo json_encode($estados); ?>;
        const sourceTypes = <?php echo json_encode($source_types); ?>;
        const docTypes = <?php echo json_encode($doc_types); ?>;

        let html = '';
        quotes.forEach(q => {
            const matchInfo = `${q.items_matched}/${q.total_items}`;
            const pendingBadge = q.items_pending > 0 ? `<span class="match-badge match-pending">${q.items_pending} pend.</span>` : '';
            const tipoKey = q.tipo_doc || 'cotizacion';
            const tipoLabel = docTypes[tipoKey] || tipoKey;
            const porConfirmar = String(q.tipo_confirmado) === '0';
            const confirmAviso = porConfirmar
                ? ' <span class="tipo-confirm-aviso">Por confirmar</span>'
                : '';
            const confirmBtn = porConfirmar
                ? '<button class="button button-small button-primary btn-confirmar-tipo-lista" title="Confirmar como cotización">Confirmar tipo</button> '
                : '';
            
            const hasDoc = !!(q.archivo_path || q.archivo_original);
            const docBtn = hasDoc
                ? '<button class="button button-small btn-ver-documento-lista" title="Ver Documento"><span class="dashicons dashicons-media-document"></span></button> '
                : '';
            let versionHtml = '—';
            if (q.version_label) {
                versionHtml = '<span class="version-badge' + (q.is_version_final ? ' is-final' : '') + '">' +
                    escapeHtml(q.version_label) + '</span>';
            } else if (q.version_n) {
                versionHtml = '<span class="version-badge">v' + q.version_n + '</span>';
            }
            
            html += `<tr data-id="${q.id}" class="${porConfirmar ? 'quote-por-confirmar' : ''}">
                <td>${q.id}</td>
                <td>${q.proveedor_nombre || '<em>Sin proveedor</em>'}</td>
                <td>${q.numero_documento || '-'}</td>
                <td>${versionHtml}</td>
                <td>${q.fecha_documento || '-'}</td>
                <td><span class="tipo-doc-badge tipo-${tipoKey}">${tipoLabel}</span>${confirmAviso}</td>
                <td>${sourceTypes[q.tipo_fuente] || q.tipo_fuente || '—'}</td>
                <td>${matchInfo} ${pendingBadge}</td>
                <td style="text-align:right">${formatMoney(q.total)}</td>
                <td><span class="estado-badge estado-${q.estado}">${estados[q.estado] || q.estado}</span></td>
                <td>
                    ${confirmBtn}
                    ${docBtn}
                    <button class="button button-small btn-ver" title="Ver/Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button class="button button-small btn-eliminar" title="Eliminar">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
        });
        tbody.html(html);
    }

    function renderProveedoresSelect() {
        let opts = '<option value="">Todos los proveedores</option>';
        proveedoresCache.forEach(p => {
            opts += `<option value="${p.id}">${p.nombre}</option>`;
        });
        $('#filtro-proveedor').html(opts);

        opts = '<option value="">Seleccionar proveedor...</option>';
        proveedoresCache.forEach(p => {
            opts += `<option value="${p.id}">${p.nombre}${p.rut ? ' - ' + p.rut : ''}</option>`;
        });
        $('#proveedor_id').html(opts);
    }

    // Ver/editar cotización
    function verCotizacion(id) {
        $.post(ajaxurl, {
            action: 'riverso_get_received_quote',
            nonce: nonce,
            id: id
        }, function(r) {
            if (r.success) {
                cotizacionActual = r.data.quote;
                itemsActuales = r.data.items;
                origenActual = r.data.origen || null;
                versionSiblingsActual = r.data.version_siblings || [];
                if (r.data.proveedores) {
                    proveedoresCache = r.data.proveedores;
                    renderProveedoresSelect();
                }
                mostrarDetalle();
            }
        });
    }

    function mostrarDetalle() {
        const q = cotizacionActual;
        $('#cotizacion-id').val(q ? q.id : 0);
        $('#titulo-cotizacion').text(q ? `Cotización #${q.id}` : 'Nueva Cotización');
        
        if (q) {
            $('#proveedor_id').val(q.proveedor_id || '');
            $('#numero_documento').val(q.numero_documento || '');
            $('#fecha_documento').val(q.fecha_documento || '');
            $('#moneda').val(q.moneda || 'CLP');
            $('#notas').val(q.notas || '');
            
            const estados = <?php echo json_encode($estados); ?>;
            $('#badge-estado').text(estados[q.estado] || q.estado).attr('class', 'estado-badge estado-' + q.estado);
            syncTipoDocUi(q);
            
            if (q.archivo_original || q.archivo_path) {
                $('#archivo-info').html(`<span class="dashicons dashicons-media-document"></span> ${escapeHtml(q.archivo_original || q.archivo_path)}`);
                $('#archivo-actions').show();
            } else {
                $('#archivo-info').html('<span class="no-archivo">Sin archivo adjunto</span>');
                $('#archivo-actions').hide();
            }
            
            $('#total-subtotal').text(formatMoney(q.subtotal));
            const dscMonto = Number(q.descuento_monto || 0);
            const dscPct = Number(q.descuento_pct || 0);
            if (dscMonto > 0 || dscPct > 0) {
                let dscLabel = formatMoney(dscMonto);
                if (dscPct > 0) {
                    dscLabel += ' (' + dscPct + '%)';
                }
                $('#total-descuento').text(dscLabel);
                $('#total-descuento-wrap').show();
            } else {
                $('#total-descuento-wrap').hide();
            }
            $('#total-impuesto').text(formatMoney(q.impuesto));
            $('#total-total').text(formatMoney(q.total));
            
            const esCotizacion = (q.tipo_doc || 'cotizacion') === 'cotizacion' && !necesitaConfirmacionHumana(q);
            if (esCotizacion && (q.estado === 'under_review' || q.estado === 'parsed')) {
                $('#btn-aprobar').show();
            } else {
                $('#btn-aprobar').hide();
            }
            if (q.estado === 'approved') {
                $('#btn-convertir-oc').show();
            } else {
                $('#btn-convertir-oc').hide();
            }

            const origenEmail = origenActual && (origenActual.canal === 'email' || q.origen_canal === 'email');
            if (origenActual && origenActual.inbox_url) {
                const label = origenEmail
                    ? '<span class="dashicons dashicons-email-alt"></span> Ver correo'
                    : '<span class="dashicons dashicons-email-alt"></span> Ver mensaje';
                $('#btn-ver-correo').attr('href', origenActual.inbox_url).html(label).show();
            } else {
                $('#btn-ver-correo').hide().attr('href', '#');
            }
            if (origenActual && origenActual.attachments && origenActual.attachments.length) {
                $('#btn-ver-adjuntos').show();
            } else {
                $('#btn-ver-adjuntos').hide();
            }
            renderVersionBar(versionSiblingsActual, q.id);
        } else {
            $('#form-cotizacion')[0].reset();
            $('#badge-estado').text('Nueva').attr('class', 'estado-badge');
            $('#archivo-info').html('<span class="no-archivo">Sin archivo adjunto</span>');
            $('#archivo-actions').hide();
            $('#total-subtotal, #total-impuesto, #total-total').text('$0');
            $('#total-descuento-wrap').hide();
            $('#total-descuento').text('$0');
            $('#btn-aprobar').hide();
            $('#btn-convertir-oc').hide();
            $('#btn-ver-correo').hide().attr('href', '#');
            $('#btn-ver-adjuntos').hide();
            syncTipoDocUi(null);
            origenActual = null;
            versionSiblingsActual = [];
            $('#quote-versions-bar').hide();
        }
        
        renderItems();
        
        $('#vista-lista').hide();
        $('#vista-detalle').show();
    }

    function necesitaConfirmacionHumana(q) {
        return !!q && (q.tipo_doc === 'posible_cotizacion' || String(q.tipo_confirmado) === '0');
    }

    function renderVersionBar(siblings, currentId) {
        versionSiblingsActual = siblings || [];
        const $bar = $('#quote-versions-bar');
        const $chips = $('#quote-versions-chips').empty();
        if (!currentId) {
            $bar.hide();
            return;
        }
        $bar.show();
        const multi = versionSiblingsActual.length > 1;
        $('#btn-desvincular-version').toggle(multi);
        $('#btn-comparar-versiones').toggle(multi);
        if (!versionSiblingsActual.length) {
            $chips.append('<span class="description">v1 (sin hermanas vinculadas)</span>');
            return;
        }
        versionSiblingsActual.forEach(function(s) {
            const label = s.version_label || ('v' + (s.version_n || '?'));
            const $chip = $('<button type="button" class="quote-version-chip"></button>');
            $chip.text(label + ' · #' + s.id);
            if (parseInt(s.id, 10) === parseInt(currentId, 10)) $chip.addClass('is-current');
            if (s.is_version_final) $chip.addClass('is-final');
            $chip.attr('data-id', s.id);
            $chips.append($chip);
        });
    }

    $(document).on('click', '.quote-version-chip', function() {
        const id = $(this).data('id');
        if (id && parseInt(id, 10) !== parseInt($('#cotizacion-id').val(), 10)) {
            verCotizacion(id);
        }
    });

    $('#btn-vincular-version').on('click', function() {
        const id = $('#cotizacion-id').val();
        if (!id || id === '0') { alert('Guarde la cotización primero'); return; }
        $('#version-search-q').val('');
        $('#version-search-results').html('<p class="description">Escriba y pulse Buscar.</p>');
        $('#modal-vincular-version').show();
    });

    $('#btn-version-search').on('click', function() {
        const id = $('#cotizacion-id').val();
        $.post(ajaxurl, {
            action: 'riverso_search_quotes_for_version',
            nonce: nonce,
            exclude_id: id,
            q: $('#version-search-q').val()
        }, function(r) {
            if (!r.success) {
                $('#version-search-results').html('<p class="description">' + escapeHtml((r.data && r.data.message) || 'Error') + '</p>');
                return;
            }
            const rows = r.data.quotes || [];
            if (!rows.length) {
                $('#version-search-results').html('<p class="description">Sin resultados</p>');
                return;
            }
            let html = '<table class="widefat striped"><thead><tr><th>ID</th><th>Proveedor</th><th>Doc</th><th>Versión</th><th></th></tr></thead><tbody>';
            rows.forEach(function(q) {
                html += '<tr><td>' + q.id + '</td><td>' + escapeHtml(q.proveedor_nombre || '—') + '</td><td>' +
                    escapeHtml(q.numero_documento || '—') + '</td><td>' + escapeHtml(q.version_label || '—') +
                    '</td><td><button type="button" class="button button-small btn-link-this-version" data-id="' + q.id + '">Vincular</button></td></tr>';
            });
            html += '</tbody></table>';
            $('#version-search-results').html(html);
        });
    });

    $(document).on('click', '.btn-link-this-version', function() {
        const other = $(this).data('id');
        const id = $('#cotizacion-id').val();
        $.post(ajaxurl, {
            action: 'riverso_link_quote_version',
            nonce: nonce,
            id: id,
            other_id: other,
            orden: 'mensaje'
        }, function(r) {
            if (!r.success) { alert(r.data.message); return; }
            $('#modal-vincular-version').hide();
            verCotizacion(id);
        });
    });

    $('#btn-desvincular-version').on('click', function() {
        const id = $('#cotizacion-id').val();
        if (!id || !confirm('¿Desvincular esta cotización del grupo de versiones?')) return;
        $.post(ajaxurl, {
            action: 'riverso_unlink_quote_version',
            nonce: nonce,
            id: id
        }, function(r) {
            if (!r.success) { alert(r.data.message); return; }
            verCotizacion(id);
        });
    });

    $('#btn-comparar-versiones').on('click', function() {
        const id = parseInt($('#cotizacion-id').val(), 10);
        const sibs = versionSiblingsActual || [];
        if (sibs.length < 2) return;
        const $a = $('#compare-version-a').empty();
        const $b = $('#compare-version-b').empty();
        sibs.forEach(function(s) {
            const label = (s.version_label || ('v' + s.version_n)) + ' · #' + s.id;
            $a.append($('<option></option>').val(s.id).text(label));
            $b.append($('<option></option>').val(s.id).text(label));
        });
        // Default: penúltima vs última
        const sorted = sibs.slice().sort(function(x, y) {
            return (parseInt(x.version_n, 10) || 0) - (parseInt(y.version_n, 10) || 0);
        });
        if (sorted.length >= 2) {
            $a.val(sorted[sorted.length - 2].id);
            $b.val(sorted[sorted.length - 1].id);
        } else {
            $b.val(id);
        }
        $('#tabla-compare-versions tbody').empty();
        $('#compare-version-stats').text('');
        $('#modal-comparar-versiones').show();
        $('#btn-run-compare').trigger('click');
    });

    $('#btn-run-compare').on('click', function() {
        const idA = $('#compare-version-a').val();
        const idB = $('#compare-version-b').val();
        $.post(ajaxurl, {
            action: 'riverso_compare_quote_versions',
            nonce: nonce,
            id_a: idA,
            id_b: idB
        }, function(r) {
            if (!r.success) {
                alert(r.data.message);
                return;
            }
            const st = r.data.stats || {};
            $('#compare-version-stats').text(
                'Agregados: ' + (st.agregado || 0) +
                ' · Quitados: ' + (st.quitado || 0) +
                ' · Precio cambió: ' + (st.precio_cambio || 0) +
                ' · Iguales: ' + (st.igual || 0)
            );
            const statusLabel = {
                agregado: 'Agregado',
                quitado: 'Quitado',
                precio_cambio: 'Precio Δ',
                igual: 'Igual'
            };
            let html = '';
            (r.data.rows || []).forEach(function(row) {
                if (row.status === 'igual') return; // mostrar solo cambios por defecto
                html += '<tr>' +
                    '<td class="compare-status-' + row.status + '">' + (statusLabel[row.status] || row.status) + '</td>' +
                    '<td>' + escapeHtml(row.codigo || '—') + '</td>' +
                    '<td>' + escapeHtml(row.descripcion || '—') + '</td>' +
                    '<td style="text-align:right">' + (row.costo_a != null ? formatCostMoney(row.costo_a) : '—') + '</td>' +
                    '<td style="text-align:right">' + (row.costo_b != null ? formatCostMoney(row.costo_b) : '—') + '</td>' +
                    '<td style="text-align:right">' + (row.delta_costo != null ? formatCostMoney(row.delta_costo) : '—') + '</td>' +
                    '</tr>';
            });
            if (!html) {
                html = '<tr><td colspan="6" class="empty">Sin diferencias materiales (≥ $0,01)</td></tr>';
            }
            $('#tabla-compare-versions tbody').html(html);
        });
    });

    function parseDatosParseados(q) {
        if (!q || !q.datos_parseados) return null;
        if (typeof q.datos_parseados === 'object') return q.datos_parseados;
        try {
            return JSON.parse(q.datos_parseados);
        } catch (e) {
            return null;
        }
    }

    function syncParseErrorUi(q) {
        const parsed = parseDatosParseados(q);
        const err = parsed && parsed.error ? String(parsed.error) : '';
        const $banner = $('#banner-parse-error');
        if (err) {
            $('#banner-parse-error-msg').text(err);
            $banner.show();
        } else {
            $('#banner-parse-error-msg').text('');
            $banner.hide();
        }
    }

    function syncTipoDocUi(q) {
        const docTypes = <?php echo json_encode($doc_types); ?>;
        const tipo = q ? (q.tipo_doc || 'cotizacion') : 'cotizacion';
        const pending = necesitaConfirmacionHumana(q);
        $('#badge-tipo-doc').text(docTypes[tipo] || tipo).attr('class', 'tipo-doc-badge tipo-' + tipo).toggle(!!q);
        $('#aviso-confirmar-tipo').toggle(pending);
        $('#banner-por-confirmar').toggle(pending);
        $('#select-tipo-doc').val(tipo).toggle(!!q);
        $('#btn-confirmar-tipo').toggle(pending);
        $('#btn-ver-comparacion').prop('disabled', pending);
        syncParseErrorUi(q);
    }

    function renderItems() {
        const tbody = $('#lista-items');
        if (!itemsActuales.length) {
            tbody.html('<tr><td colspan="10" class="empty">Sin ítems. Agregue ítems manualmente.</td></tr>');
            return;
        }

        const matchLabels = <?php echo json_encode($match_status); ?>;
        const decisionLabels = <?php echo json_encode($decision_status); ?>;

        let html = '';
        itemsActuales.forEach(item => {
            const diffClass = item.diferencia_costo > 0 ? 'cost-up' : (item.diferencia_costo < 0 ? 'cost-down' : '');
            const diffText = item.diferencia_porcentaje ? `${item.diferencia_porcentaje > 0 ? '+' : ''}${item.diferencia_porcentaje}%` : '-';
            
            html += `<tr data-id="${item.id}">
                <td>${item.linea}</td>
                <td>${item.codigo_proveedor || '-'}</td>
                <td>
                    ${item.descripcion || '-'}
                    ${item.producto_nombre ? `<br><small class="text-muted">→ ${item.producto_nombre}</small>` : ''}
                </td>
                <td>${parseFloat(item.cantidad).toLocaleString('es-CL')}</td>
                <td style="text-align:right">${formatMoney(item.costo_neto)}</td>
                <td style="text-align:right">${formatMoney(item.costo_total * item.cantidad)}</td>
                <td><span class="match-badge match-${item.match_status}">${matchLabels[item.match_status] || item.match_status}</span></td>
                <td><span class="decision-${item.decision_status}">${decisionLabels[item.decision_status] || item.decision_status}</span></td>
                <td class="${diffClass}">${diffText}</td>
                <td>
                    <button class="button button-small btn-editar-item" title="Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button class="button button-small btn-decision-item" title="Decisión">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </button>
                    <button class="button button-small btn-eliminar-item" title="Eliminar">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
        });
        tbody.html(html);
    }

    // Eventos de navegación
    $('#btn-volver-lista').on('click', function() {
        $('#vista-detalle').hide();
        $('#vista-lista').show();
        // Evitar que "Subir Archivo" reemplace la cotización abierta
        $('#cotizacion-id').val(0);
        cotizacionActual = null;
        itemsActuales = [];
        origenActual = null;
        versionSiblingsActual = [];
        cargarCotizaciones();
    });

    $('#btn-nueva-cotizacion').on('click', function() {
        cotizacionActual = null;
        itemsActuales = [];
        origenActual = null;
        versionSiblingsActual = [];
        mostrarDetalle();
    });

    $('#btn-filtrar').on('click', cargarCotizaciones);
    $('#filtro-solo-final').on('change', cargarCotizaciones);
    $('#btn-limpiar-filtros').on('click', function() {
        $('#filtro-buscar, #filtro-estado, #filtro-fuente, #filtro-proveedor, #filtro-desde, #filtro-hasta').val('');
        $('#filtro-tipo-doc').val('cotizacion');
        $('#filtro-solo-final').prop('checked', false);
        cargarCotizaciones();
    });

    // Click en tabla
    function confirmarTipoCotizacion(id, recargarLista) {
        if (!id) return;
        if (!confirm('¿Confirmar que este documento es una cotización?')) return;
        $.post(ajaxurl, {
            action: 'riverso_confirm_received_quote_tipo',
            nonce: nonce,
            id: id
        }, function(r) {
            if (!r.success) { alert(r.data.message); return; }
            if (recargarLista) {
                cargarCotizaciones();
            } else {
                verCotizacion(id);
            }
        });
    }

    $('#stat-card-confirmar').on('click', function() {
        $('#filtro-tipo-doc').val('posible_cotizacion');
        cargarCotizaciones();
    });
    $(document).on('click', '#link-ver-posibles', function(e) {
        e.preventDefault();
        $('#filtro-tipo-doc').val('posible_cotizacion');
        cargarCotizaciones();
    });

    $('#lista-cotizaciones').on('click', '.btn-ver', function() {
        const id = $(this).closest('tr').data('id');
        verCotizacion(id);
    });

    $('#lista-cotizaciones').on('click', '.btn-confirmar-tipo-lista', function() {
        const id = $(this).closest('tr').data('id');
        confirmarTipoCotizacion(id, true);
    });

    $('#lista-cotizaciones').on('click', '.btn-eliminar', function() {
        const id = $(this).closest('tr').data('id');
        if (confirm('¿Eliminar esta cotización?')) {
            $.post(ajaxurl, {
                action: 'riverso_delete_received_quote',
                nonce: nonce,
                id: id
            }, function(r) {
                if (r.success) {
                    cargarCotizaciones();
                } else {
                    alert(r.data.message);
                }
            });
        }
    });

    // Guardar cotización
    $('#form-cotizacion').on('submit', function(e) {
        e.preventDefault();
        const data = {
            action: 'riverso_save_received_quote',
            nonce: nonce,
            id: $('#cotizacion-id').val(),
            proveedor_id: $('#proveedor_id').val(),
            numero_documento: $('#numero_documento').val(),
            fecha_documento: $('#fecha_documento').val(),
            moneda: $('#moneda').val(),
            notas: $('#notas').val()
        };

        $.post(ajaxurl, data, function(r) {
            if (r.success) {
                $('#cotizacion-id').val(r.data.id);
                cotizacionActual = cotizacionActual || {};
                cotizacionActual.id = r.data.id;
                $('#titulo-cotizacion').text(`Cotización #${r.data.id}`);
                alert(r.data.message);
            } else {
                alert(r.data.message);
            }
        });
    });

    // Modal ítem
    $('#btn-agregar-item').on('click', function() {
        if (!$('#cotizacion-id').val() || $('#cotizacion-id').val() === '0') {
            alert('Primero guarde la cotización');
            return;
        }
        $('#modal-item-titulo').text('Agregar Ítem');
        $('#item-id').val(0);
        $('#form-item')[0].reset();
        $('#item-cantidad').val(1);
        $('#item-match-info').hide();
        $('#modal-item').show();
    });

    $('#lista-items').on('click', '.btn-editar-item', function() {
        const id = $(this).closest('tr').data('id');
        const item = itemsActuales.find(i => i.id == id);
        if (!item) return;

        $('#modal-item-titulo').text('Editar Ítem');
        $('#item-id').val(item.id);
        $('#item-codigo-proveedor').val(item.codigo_proveedor || '');
        $('#item-codigo-barras').val(item.codigo_barras || '');
        $('#item-unidad').val(item.unidad || 'UN');
        $('#item-descripcion').val(item.descripcion || '');
        $('#item-cantidad').val(item.cantidad);
        $('#item-costo-neto').val(item.costo_neto);
        $('#item-costo-impuesto').val(item.costo_impuesto);
        $('#item-costo-total').val(item.costo_total);

        if (item.producto_nombre) {
            $('#item-match-details').html(`<strong>${item.producto_nombre}</strong><br>SKU: ${item.sku_match || '-'}`);
            $('#item-match-info').show();
        } else {
            $('#item-match-info').hide();
        }

        $('#modal-item').show();
    });

    // Calcular total automáticamente
    $('#item-costo-neto, #item-costo-impuesto').on('input', function() {
        const neto = parseFloat($('#item-costo-neto').val()) || 0;
        const iva = parseFloat($('#item-costo-impuesto').val()) || 0;
        $('#item-costo-total').val((neto + iva).toFixed(2));
    });

    // Guardar ítem
    $('#form-item').on('submit', function(e) {
        e.preventDefault();
        $.post(ajaxurl, {
            action: 'riverso_save_quote_item',
            nonce: nonce,
            item_id: $('#item-id').val(),
            cotizacion_id: $('#cotizacion-id').val(),
            codigo_proveedor: $('#item-codigo-proveedor').val(),
            codigo_barras: $('#item-codigo-barras').val(),
            unidad: $('#item-unidad').val(),
            descripcion: $('#item-descripcion').val(),
            cantidad: $('#item-cantidad').val(),
            costo_neto: $('#item-costo-neto').val(),
            costo_impuesto: $('#item-costo-impuesto').val(),
            costo_total: $('#item-costo-total').val()
        }, function(r) {
            if (r.success) {
                $('#modal-item').hide();
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    // Eliminar ítem
    $('#lista-items').on('click', '.btn-eliminar-item', function() {
        const id = $(this).closest('tr').data('id');
        if (confirm('¿Eliminar este ítem?')) {
            $.post(ajaxurl, {
                action: 'riverso_delete_quote_item',
                nonce: nonce,
                item_id: id
            }, function(r) {
                if (r.success) {
                    verCotizacion($('#cotizacion-id').val());
                }
            });
        }
    });

    // Buscar match individual
    $('#btn-buscar-match').on('click', function() {
        const itemId = $('#item-id').val();
        if (!itemId || itemId === '0') {
            alert('Guarde el ítem primero para buscar coincidencias');
            return;
        }
        
        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Buscando...');
        
        $.post(ajaxurl, {
            action: 'riverso_match_quote_item',
            nonce: nonce,
            item_id: itemId
        }, function(r) {
            $('#btn-buscar-match').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Buscar Match');
            if (r.success) {
                if (r.data.matches && r.data.matches.length) {
                    const m = r.data.matches[0];
                    $('#item-match-details').html(`<strong>${m.nombre}</strong><br>SKU: ${m.sku || '-'}<br>Confianza: ${r.data.confidence}%`);
                    $('#item-match-info').show();
                } else {
                    $('#item-match-details').html('<em>No se encontraron coincidencias</em>');
                    $('#item-match-info').show();
                }
            }
        });
    });

    // Match automático de todos
    $('#btn-match-todos').on('click', function() {
        const cotizacionId = $('#cotizacion-id').val();
        if (!cotizacionId || cotizacionId === '0') {
            alert('Guarde la cotización primero');
            return;
        }

        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Procesando...');

        $.post(ajaxurl, {
            action: 'riverso_match_all_items',
            nonce: nonce,
            quote_id: cotizacionId
        }, function(r) {
            $('#btn-match-todos').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Match Automático');
            if (r.success) {
                alert(r.data.message);
                verCotizacion(cotizacionId);
            } else {
                alert(r.data.message);
            }
        });
    });

    // Modal decisión
    $('#lista-items').on('click', '.btn-decision-item', function() {
        const id = $(this).closest('tr').data('id');
        const item = itemsActuales.find(i => i.id == id);
        if (!item) return;

        $('#decision-item-id').val(item.id);
        $('#decision-value').val('');
        $('#decision-notas').val(item.decision_notas || '');
        $('.decision-btn').removeClass('selected');
        
        if (item.match_status === 'not_found' || item.match_status === 'ambiguous') {
            $('#decision-manual-match').show();
        } else {
            $('#decision-manual-match').hide();
        }

        $('#form-decision button[type="submit"]').prop('disabled', true);
        $('#modal-decision').show();
    });

    $('.decision-btn').on('click', function() {
        $('.decision-btn').removeClass('selected');
        $(this).addClass('selected');
        $('#decision-value').val($(this).data('decision'));
        $('#form-decision button[type="submit"]').prop('disabled', false);
    });

    $('#form-decision').on('submit', function(e) {
        e.preventDefault();
        const decision = $('#decision-value').val();
        if (!decision) {
            alert('Seleccione una decisión');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_set_item_decision',
            nonce: nonce,
            item_id: $('#decision-item-id').val(),
            decision: decision,
            notas: $('#decision-notas').val(),
            producto_id: $('#decision-producto-id').val()
        }, function(r) {
            if (r.success) {
                $('#modal-decision').hide();
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    // Aprobar cotización
    $('#btn-aprobar').on('click', function() {
        if (necesitaConfirmacionHumana(cotizacionActual)) {
            alert('Confirme el tipo como cotización antes de aprobar.');
            return;
        }
        if (!confirm('¿Aprobar esta cotización? Todos los ítems deben tener una decisión.')) return;

        $.post(ajaxurl, {
            action: 'riverso_approve_received_quote',
            nonce: nonce,
            id: $('#cotizacion-id').val()
        }, function(r) {
            if (r.success) {
                alert(r.data.message);
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    function renderQuoteEval(data) {
        const quote = (data && data.quote) || {};
        const viewTag = quoteEvalViewLabel();
        const costTag = quoteEvalCostLabel();
        $('#quote-eval-title').text('Evaluación de costos · ' + viewTag + ' · ' + costTag);
        $('#quote-eval-meta').text(
            (quote.proveedor_nombre || 'Sin proveedor') +
            (quote.numero_documento ? (' · Doc. ' + quote.numero_documento) : '') +
            (quote.fecha_emision ? (' · ' + quote.fecha_emision) : '')
        );

        const suffix = ' (' + viewTag + ')';
        $('#tabla-comparacion thead th').eq(3).text('Costo cotización' + suffix);
        $('#tabla-comparacion thead th').eq(4).text('Última facturación' + suffix);
        $('#tabla-comparacion thead th').eq(5).text('Última cotización' + suffix);
        $('#tabla-comparacion thead th').eq(6).text('Legacy' + suffix);

        const rowsData = data.rows || [];
        // Δ ≤ $0,01 CLP = ruido de aproximación; no cuenta como alza/baja significativa.
        const DELTA_SIGNIFICATIVO = 0.01;
        let aumentos = 0, bajas = 0, aumentosSig = 0, bajasSig = 0;
        rowsData.forEach(function(row) {
            const m = quoteEvalRowMetrics(row);
            if (m.trend === 'subio') {
                aumentos++;
                if (m.delta != null && Number(m.delta) > DELTA_SIGNIFICATIVO) aumentosSig++;
            }
            if (m.trend === 'bajo') {
                bajas++;
                if (m.delta != null && Number(m.delta) < -DELTA_SIGNIFICATIVO) bajasSig++;
            }
        });
        function summaryCountHtml(sig, total, cls) {
            return '<div class="summary-value ' + cls + '">' + sig +
                '<span class="summary-total-hint">(' + total + ')</span></div>';
        }
        $('#comparison-summary').html(
            '<div class="summary-item"><div class="summary-value">' + rowsData.length + '</div><div>Ítems</div></div>' +
            '<div class="summary-item">' + summaryCountHtml(aumentosSig, aumentos, 'cost-up') + '<div>Alzas</div></div>' +
            '<div class="summary-item">' + summaryCountHtml(bajasSig, bajas, 'cost-down') + '<div>Bajas</div></div>' +
            '<div class="summary-item"><div class="summary-value">' + escapeHtml(costTag) + '</div><div>' + escapeHtml(viewTag) + '</div></div>'
        );

        if (!rowsData.length) {
            $('#lista-comparacion').html('<tr><td colspan="10" class="empty">Sin ítems</td></tr>');
            $('#btn-print-quote-eval').prop('disabled', true);
            return;
        }

        let rows = '';
        rowsData.forEach(function(item) {
            const m = quoteEvalRowMetrics(item);
            const tClass = trendClass(m.trend);
            const changed = m.trend === 'subio' || m.trend === 'bajo';
            let deltaText = '—';
            if (m.delta !== null && m.delta !== undefined) {
                if (m.delta < 0) deltaText = '-' + formatCostMoney(Math.abs(m.delta));
                else if (m.delta > 0) deltaText = '+' + formatCostMoney(Math.abs(m.delta));
                else deltaText = formatCostMoney(0);
            }
            const productHint = item.resolved_product && item.resolved_product.canonical_sku
                ? '<div class="cost-doc-ref">SKU ' + escapeHtml(item.resolved_product.canonical_sku) + '</div>'
                : '';
            rows += '<tr class="' + tClass + (changed ? ' has-change' : '') + '">' +
                '<td>' + escapeHtml(item.numero_linea || '') + '</td>' +
                '<td><code>' + escapeHtml(item.codigo_proveedor || '—') + '</code>' + productHint + '</td>' +
                '<td>' + escapeHtml(item.nombre || '—') + '</td>' +
                '<td style="text-align:right">' + escapeHtml(formatCostMoney(m.current)) + '</td>' +
                '<td>' + originCell(m.prevInvCost, item.prev_invoice) + '</td>' +
                '<td>' + originCell(m.prevQuoteCost, item.prev_quote) + '</td>' +
                '<td>' + originCell(m.legacyCost, item.legacy) + '</td>' +
                '<td class="' + tClass + '"><strong>' + escapeHtml(trendLabel(m.trend)) + '</strong></td>' +
                '<td style="text-align:right" class="' + tClass + '">' + escapeHtml(deltaText) + '</td>' +
                '<td style="text-align:right" class="' + tClass + '">' + escapeHtml(formatDeltaPct(m.pct)) + '</td>' +
                '</tr>';
        });
        $('#lista-comparacion').html(rows);
        $('#btn-print-quote-eval').prop('disabled', false);
    }

    function loadQuoteEval() {
        const cotizacionId = $('#cotizacion-id').val();
        if (!cotizacionId || cotizacionId === '0') return;
        $('#lista-comparacion').html('<tr><td colspan="10" class="empty">Analizando…</td></tr>');
        $('#btn-print-quote-eval').prop('disabled', true);
        $.post(ajaxurl, {
            action: 'riverso_analyze_received_quote',
            nonce: nonce,
            id: cotizacionId,
            compare_base: quoteEvalBaseMode
        }, function(r) {
            if (!r.success) {
                alert((r.data && r.data.message) || 'Error al analizar');
                return;
            }
            lastQuoteEval = r.data;
            quoteEvalShowDecimals = $('#quote-eval-toggle-decimals').is(':checked');
            syncQuoteEvalToggles();
            renderQuoteEval(r.data);
            $('#modal-comparacion').show();
        });
    }

    function buildQuoteEvalPrintHtml(data) {
        const quote = (data && data.quote) || {};
        const rows = (data && data.rows) || [];
        const viewTag = quoteEvalViewLabel();
        const costTag = quoteEvalCostLabel();
        const title = 'Cotización ' + (quote.numero_documento || ('#' + (quote.id || '')));
        const printedAt = new Date().toLocaleString('es-CL');
        const suffix = ' (' + viewTag + ')';
        let rowsHtml = '';
        rows.forEach(function(row) {
            const m = quoteEvalRowMetrics(row);
            const changed = m.trend === 'subio' || m.trend === 'bajo';
            const prevInv = row.prev_invoice;
            let prevInvText = '—';
            if (prevInv && m.prevInvCost != null) {
                prevInvText = formatCostMoney(m.prevInvCost) +
                    ' (' + (prevInv.folio || '') + ' · ' + (prevInv.fecha_emision || '') +
                    (prevInv.match_label ? ' · ' + prevInv.match_label : '') + ')';
            }
            let prevQuoteText = '—';
            if (row.prev_quote && m.prevQuoteCost != null) {
                prevQuoteText = formatCostMoney(m.prevQuoteCost) +
                    ' (' + (row.prev_quote.folio || '') + ' · ' + (row.prev_quote.fecha_emision || '') + ')';
            }
            let legacyText = '—';
            if (row.legacy && m.legacyCost != null) {
                legacyText = formatCostMoney(m.legacyCost) +
                    ' (' + (row.legacy.sku || 'legacy') + ' · ' + (row.legacy.fecha_emision || '') + ')';
            }
            let deltaText = '—';
            if (m.delta !== null && m.delta !== undefined) {
                if (m.delta < 0) deltaText = '-' + formatCostMoney(Math.abs(m.delta));
                else if (m.delta > 0) deltaText = '+' + formatCostMoney(Math.abs(m.delta));
                else deltaText = formatCostMoney(0);
            }
            rowsHtml += '<tr class="' + (changed ? 'changed' : '') + '">' +
                '<td>' + escapeHtml(row.numero_linea || '') + '</td>' +
                '<td>' + escapeHtml(row.codigo_proveedor || '—') + '</td>' +
                '<td>' + escapeHtml(row.nombre || '—') + '</td>' +
                '<td class="num">' + escapeHtml(formatCostMoney(m.current)) + '</td>' +
                '<td>' + escapeHtml(prevInvText) + '</td>' +
                '<td>' + escapeHtml(prevQuoteText) + '</td>' +
                '<td>' + escapeHtml(legacyText) + '</td>' +
                '<td class="chg">' + escapeHtml(trendLabel(m.trend)) + '</td>' +
                '<td class="num chg">' + escapeHtml(deltaText) + '</td>' +
                '<td class="num chg">' + escapeHtml(formatDeltaPct(m.pct)) + '</td>' +
                '</tr>';
        });
        const baseNote = quoteEvalCostMode === 'referencia'
            ? 'Costo referencia = precio lista / costo antes de descuentos y recargos.'
            : (quoteEvalCostMode === 'tras_dr_folio'
                ? 'Costo tras Descuento/Recargo Folio = costo unitario después del D/R de fila y del descuento/recargo del documento.'
                : 'Costo tras Descuento/Recargo = costo unitario después de descuentos y recargos de la fila.');
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">' +
            '<title>Evaluación ' + escapeHtml(title) + '</title>' +
            '<style>body{font-family:Arial,sans-serif;margin:24px;color:#111;}h1{margin:0 0 8px;font-size:22px;}' +
            '.meta{margin-bottom:16px;font-size:13px;color:#444;}.meta div{margin:2px 0;}' +
            'table{width:100%;border-collapse:collapse;font-size:12px;}' +
            'th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top;}' +
            'th{background:#f5f5f5;}td.num{text-align:right;white-space:nowrap;}' +
            'tr.changed td{font-weight:700;}tr.changed td.chg{text-decoration:underline;}' +
            '.legend{margin-top:12px;font-size:12px;color:#444;}' +
            '@media print{body{margin:12mm;} .no-print{display:none;}}</style></head><body>' +
            '<h1>Evaluación de costos — ' + escapeHtml(title) + ' (' + escapeHtml(viewTag) + ' · ' + escapeHtml(costTag) + ')</h1>' +
            '<div class="meta">' +
            '<div><strong>Proveedor:</strong> ' + escapeHtml(quote.proveedor_nombre || '—') + '</div>' +
            '<div><strong>Documento:</strong> ' + escapeHtml(quote.numero_documento || '—') + '</div>' +
            '<div><strong>Fecha:</strong> ' + escapeHtml(quote.fecha_emision || '—') + '</div>' +
            '<div><strong>Vista:</strong> ' + escapeHtml((quoteEvalViewMode === 'bruto' ? 'Bruto' : 'Neto') + ' · ' + costTag) + '</div>' +
            '<div><strong>Generado:</strong> ' + escapeHtml(printedAt) + '</div>' +
            '</div><table><thead><tr>' +
            '<th>#</th><th>Código</th><th>Descripción</th>' +
            '<th>Costo cotización' + escapeHtml(suffix) + '</th>' +
            '<th>Última facturación' + escapeHtml(suffix) + '</th>' +
            '<th>Última cotización' + escapeHtml(suffix) + '</th>' +
            '<th>Legacy' + escapeHtml(suffix) + '</th>' +
            '<th>Tendencia</th><th>Diferencia</th><th>Dif. %</th>' +
            '</tr></thead><tbody>' + rowsHtml + '</tbody></table>' +
            '<div class="legend"><strong>Nota:</strong> ' + escapeHtml(baseNote) +
            ' Las filas en negrita (y diferencia subrayada) indican cambio de costo respecto a la referencia elegida.</div>' +
            '<p class="no-print" style="margin-top:20px;"><button type="button" onclick="window.print()">Imprimir</button></p>' +
            '</body></html>';
    }

    $('#btn-ver-comparacion').on('click', function() {
        if (necesitaConfirmacionHumana(cotizacionActual)) {
            alert('Confirme el tipo como cotización antes de evaluar costos.');
            return;
        }
        loadQuoteEval();
    });

    $('#btn-confirmar-tipo').on('click', function() {
        const id = $('#cotizacion-id').val();
        if (!id || id === '0') return;
        confirmarTipoCotizacion(id, false);
    });

    $('#select-tipo-doc').on('change', function() {
        const id = $('#cotizacion-id').val();
        const tipo = $(this).val();
        if (!id || id === '0' || !tipo) return;
        const labels = <?php echo json_encode($doc_types); ?>;
        const actual = cotizacionActual ? (cotizacionActual.tipo_doc || 'cotizacion') : 'cotizacion';
        if (tipo === actual) return;
        if (!confirm('¿Cambiar el tipo a "' + (labels[tipo] || tipo) + '"?')) {
            $(this).val(actual);
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_set_received_quote_tipo_doc',
            nonce: nonce,
            id: id,
            tipo_doc: tipo
        }, function(r) {
            if (!r.success) {
                alert(r.data.message);
                $('#select-tipo-doc').val(actual);
                return;
            }
            verCotizacion(id);
        });
    });

    $('#quote-eval-view-toggle').on('click', '.rce-view-btn', function() {
        quoteEvalViewMode = $(this).data('quote-view') || 'neto';
        syncQuoteEvalToggles();
        if (lastQuoteEval) renderQuoteEval(lastQuoteEval);
    });
    $('#quote-eval-cost-toggle').on('click', '.rce-cost-btn', function() {
        const mode = $(this).data('quote-cost') || 'tras_dr';
        if (mode !== 'referencia' && mode !== 'tras_dr' && mode !== 'tras_dr_folio') return;
        quoteEvalCostMode = mode;
        syncQuoteEvalToggles();
        if (lastQuoteEval) renderQuoteEval(lastQuoteEval);
    });
    $('#quote-eval-base-toggle').on('click', '.rce-base-btn', function() {
        quoteEvalBaseMode = $(this).data('quote-base') || 'auto';
        syncQuoteEvalToggles();
        loadQuoteEval();
    });
    $('#quote-eval-toggle-decimals').on('change', function() {
        quoteEvalShowDecimals = $(this).is(':checked');
        if (lastQuoteEval) renderQuoteEval(lastQuoteEval);
    });
    $('#btn-print-quote-eval').on('click', function() {
        if (!lastQuoteEval) return;
        quoteEvalShowDecimals = $('#quote-eval-toggle-decimals').is(':checked');
        const html = buildQuoteEvalPrintHtml(lastQuoteEval);
        const w = window.open('', '_blank');
        if (!w) {
            alert('Permite ventanas emergentes para imprimir');
            return;
        }
        w.document.write(html);
        w.document.close();
    });

    let reclamoMode = 'simple';
    let reclamoDrafts = { subject: '', simple: '', complex: '' };

    function isLegacyClaimRef(ref) {
        if (!ref || typeof ref !== 'object') return true;
        const kind = String(ref.source_kind || ref.match_path || '').toLowerCase();
        const folio = String(ref.folio || '');
        const label = String(ref.match_label || '');
        if (kind === 'legacy' || folio.toUpperCase() === 'LEGACY') return true;
        if (label.toLowerCase().indexOf('legacy') !== -1) return true;
        return false;
    }

    function claimPublicReference(row) {
        const inv = row && row.prev_invoice;
        if (inv && !isLegacyClaimRef(inv)) {
            const bits = [];
            bits.push('Factura' + (inv.folio ? (' folio ' + inv.folio) : ''));
            if (inv.fecha_emision) bits.push(inv.fecha_emision);
            if (inv.proveedor_nombre) bits.push(inv.proveedor_nombre);
            const label = bits.join(' · ');
            if (label && label.toLowerCase().indexOf('legacy') === -1) return label;
        }
        const q = row && row.prev_quote;
        if (q && !isLegacyClaimRef(q)) {
            const bits = [];
            bits.push('Cotización' + (q.folio ? (' ' + q.folio) : ''));
            if (q.fecha_emision) bits.push(q.fecha_emision);
            const label = bits.join(' · ');
            if (label && label.toLowerCase().indexOf('legacy') === -1) return label;
        }
        return '';
    }

    function buildClaimEmailsFromEval(data) {
        const quote = (data && data.quote) || {};
        const proveedor = (quote.proveedor_nombre || '').trim() || 'estimados';
        const folio = (quote.numero_documento || quote.folio || '').trim();
        const docLabel = folio ? ('cotización ' + folio) : 'cotización';
        const subject = folio ? ('Reclamo de precios — Cotización ' + folio) : 'Reclamo de precios';
        const greeting = 'Estimados ' + proveedor + ',\n\n';
        const intro = 'Junto con saludar, revisamos la ' + docLabel + ' y les pedimos por favor usar los precios anteriores en:\n\n';
        const closing = '\nQuedamos atentos a su confirmación.\n\nSaludos cordiales,\nCompras Riverso\n';

        const items = [];
        (data.rows || []).forEach(function(row) {
            const m = quoteEvalRowMetrics(row);
            // No reclamar alzas ≤ +$0,01 (ruido de aproximación)
            if (m.trend !== 'subio' || m.previous == null) return;
            if (m.delta == null || Number(m.delta) <= 0.01) return;
            items.push({
                codigo: (row.codigo_proveedor || '').trim(),
                nombre: (row.nombre || '').trim(),
                precio: formatCostMoney(m.previous),
                referencia: claimPublicReference(row)
            });
        });

        if (!items.length) {
            const empty = greeting + 'Revisamos la ' + docLabel + ' y no encontramos alzas con precio anterior para reclamar.\n' + closing;
            return { subject: subject, simple: empty, complex: empty, items: 0 };
        }

        let simple = greeting + intro;
        let complex = greeting + intro;
        items.forEach(function(it) {
            const title = (it.codigo + ' ' + it.nombre).trim();
            const block = title + '\nprecio anterior: ' + it.precio + '\n';
            simple += block + '\n';
            complex += block;
            if (it.referencia) {
                complex += 'referencia: ' + it.referencia + '\n';
            }
            complex += '\n';
        });
        return {
            subject: subject,
            simple: simple + closing,
            complex: complex + closing,
            items: items.length
        };
    }

    function showReclamoDrafts(drafts) {
        reclamoDrafts = drafts || { subject: '', simple: '', complex: '' };
        $('#reclamo-asunto').val(reclamoDrafts.subject || '');
        applyReclamoMode(reclamoMode);
        $('#reclamo-copy-status').hide();
        $('#modal-reclamo').show();
    }

    function applyReclamoMode(mode) {
        reclamoMode = mode === 'complex' ? 'complex' : 'simple';
        $('#reclamo-mode-toggle .rce-view-btn').removeClass('is-active');
        $('#reclamo-mode-toggle .rce-view-btn[data-reclamo-mode="' + reclamoMode + '"]').addClass('is-active');
        $('#reclamo-cuerpo').val(reclamoMode === 'complex' ? (reclamoDrafts.complex || '') : (reclamoDrafts.simple || ''));
        $('#reclamo-mode-hint').text(
            reclamoMode === 'complex'
                ? 'Incluye referencias de factura o cotización. Si solo hay legacy, se omite la referencia.'
                : 'Pide usar los precios anteriores: código, nombre y precio anterior.'
        );
    }

    function copyReclamoText() {
        const subject = $('#reclamo-asunto').val() || '';
        const body = $('#reclamo-cuerpo').val() || '';
        const text = 'Asunto: ' + subject + '\n\n' + body;
        const done = function() {
            $('#reclamo-copy-status').text('Copiado').show();
            setTimeout(function() { $('#reclamo-copy-status').hide(); }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function() {
                $('#reclamo-cuerpo').trigger('select');
                document.execCommand('copy');
                done();
            });
            return;
        }
        $('#reclamo-cuerpo').trigger('select');
        document.execCommand('copy');
        done();
    }

    $(document).on('click', '#btn-draft-reclamo', function() {
        if (lastQuoteEval) {
            showReclamoDrafts(buildClaimEmailsFromEval(lastQuoteEval));
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_quote_claim_draft',
            nonce: nonce,
            id: $('#cotizacion-id').val(),
            compare_base: quoteEvalBaseMode
        }, function(r) {
            if (!r.success) { alert(r.data.message); return; }
            showReclamoDrafts({
                subject: r.data.subject || 'Reclamo de precios',
                simple: r.data.simple || r.data.draft || '',
                complex: r.data.complex || r.data.draft || ''
            });
        });
    });
    $('#reclamo-mode-toggle').on('click', '.rce-view-btn', function() {
        applyReclamoMode($(this).data('reclamo-mode'));
    });
    $('#btn-copiar-reclamo, #btn-copiar-reclamo-footer').on('click', function() {
        copyReclamoText();
    });

    $('#btn-ver-adjuntos').on('click', function() {
        if (!origenActual || !origenActual.attachments || !origenActual.attachments.length) {
            alert('Sin adjuntos en el mensaje de origen');
            return;
        }
        const meta = [
            origenActual.subject || '(sin asunto)',
            origenActual.from_address || '',
            origenActual.sent_at || ''
        ].filter(Boolean).join(' · ');
        $('#adjuntos-origen-meta').text(meta);
        let html = '';
        origenActual.attachments.forEach(function(a) {
            const url = ajaxurl + '?action=riverso_inbox_download_attachment&nonce=' + encodeURIComponent(nonce) + '&id=' + a.id;
            const size = a.size_bytes ? (' (' + Math.round(a.size_bytes / 1024) + ' KB)') : '';
            html += '<li style="margin:8px 0;"><a href="' + url + '">' + (a.filename || ('Adjunto #' + a.id)) + '</a>' + size + '</li>';
        });
        $('#lista-adjuntos-origen').html(html);
        $('#modal-adjuntos-origen').show();
    });
    $('#modal-adjuntos-origen .modal-close').on('click', function() {
        $('#modal-adjuntos-origen').hide();
    });

    function quoteDocumentUrl(id) {
        return ajaxurl +
            '?action=riverso_view_quote_document' +
            '&nonce=' + encodeURIComponent(nonce) +
            '&id=' + encodeURIComponent(id);
    }

    $('#btn-ver-documento').on('click', function() {
        const id = $('#cotizacion-id').val() || (cotizacionActual && cotizacionActual.id);
        if (!id || id === '0') {
            alert('No hay documento asociado');
            return;
        }
        window.open(quoteDocumentUrl(id), '_blank', 'noopener,noreferrer');
    });

    $('#lista-cotizaciones').on('click', '.btn-ver-documento-lista', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const id = $(this).closest('tr').data('id');
        if (!id) return;
        window.open(quoteDocumentUrl(id), '_blank', 'noopener,noreferrer');
    });

    $('#btn-parsear').on('click', function() {
        const id = $('#cotizacion-id').val();
        if (!id || id === '0') { alert('Guarde la cotización primero'); return; }
        const $btn = $(this).prop('disabled', true);
        parseQuoteAfterUpload(id, {
            onDone: function(r) {
                $btn.prop('disabled', false);
                alert((r && r.data && r.data.message) ? r.data.message : 'Cotización parseada');
                verCotizacion(id);
            },
            onFail: function(msg) {
                $btn.prop('disabled', false);
                alert(msg);
                verCotizacion(id);
            }
        });
    });
    $('#btn-parsear-texto').on('click', function() {
        const id = $('#cotizacion-id').val();
        const texto = $('#texto-manual').val();
        if (!id || id === '0') { alert('Guarde la cotización primero (ingreso manual)'); return; }
        const $btn = $(this).prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'riverso_parse_quote_text', nonce: nonce, id: id, texto: texto },
            timeout: 190000,
            success: function(r) {
                $btn.prop('disabled', false);
                alert(r.success ? r.data.message : r.data.message);
                verCotizacion(id);
            },
            error: function(xhr, status) {
                $btn.prop('disabled', false);
                let msg = 'Error al parsear texto';
                if (status === 'timeout') msg = 'El parseo tardó demasiado (timeout).';
                else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
                verCotizacion(id);
            }
        });
    });
    $('#btn-rechazar').on('click', function() {
        if (!confirm('¿Rechazar este documento? No se usará como cotización.')) return;
        $.post(ajaxurl, { action: 'riverso_reject_received_quote', nonce: nonce, id: $('#cotizacion-id').val() }, function(r) {
            if (r.success) verCotizacion($('#cotizacion-id').val());
            else alert(r.data.message);
        });
    });
    $('#btn-pendiente').on('click', function() {
        $.post(ajaxurl, { action: 'riverso_set_received_quote_status', nonce: nonce, id: $('#cotizacion-id').val(), estado: 'under_review' }, function(r) {
            if (r.success) verCotizacion($('#cotizacion-id').val());
        });
    });
    $('#btn-convertir-oc').on('click', function() {
        if (!confirm('¿Crear orden de compra desde esta cotización aprobada?')) return;
        $.post(ajaxurl, { action: 'riverso_convert_quote_to_expected', nonce: nonce, id: $('#cotizacion-id').val() }, function(r) {
            alert(r.success ? r.data.message : r.data.message);
            if (r.success) verCotizacion($('#cotizacion-id').val());
        });
    });

    // Upload (patrón invoices: dropzone + Buscar archivos + confirmar)
    const quoteFileInput = $('#file-upload-input');
    const quoteAllowedExt = ['pdf', 'xlsx', 'xls', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];
    let quoteSelectedFile = null;

    function resetQuoteUploadModal() {
        quoteSelectedFile = null;
        quoteFileInput.val('');
        $('#upload-zone').removeClass('dragover').show();
        $('#quote-upload-file-name').text('');
        $('#quote-upload-status').empty();
        $('#upload-progress').hide();
        $('#btn-upload-quote-file').prop('disabled', true);
    }

    function setQuoteUploadStatus(type, message, extraHtml) {
        const $box = $('#quote-upload-status').empty();
        if (!message && !extraHtml) return;
        const cls = type === 'error' ? 'notice-error'
            : (type === 'warning' ? 'notice-warning'
            : (type === 'success' ? 'notice-success' : 'notice-info'));
        const $notice = $('<div class="notice ' + cls + '"><p></p></div>');
        $notice.find('p').text(message || '');
        if (extraHtml) {
            $notice.append(extraHtml);
        }
        $box.append($notice);
    }

    function isQuoteUploadFile(file) {
        if (!file || !file.name) return false;
        const ext = String(file.name).split('.').pop().toLowerCase();
        return quoteAllowedExt.indexOf(ext) !== -1;
    }

    function setQuoteUploadFile(file) {
        if (!isQuoteUploadFile(file)) {
            quoteSelectedFile = null;
            setQuoteUploadStatus('error', 'Archivo no válido. Use PDF, imagen, Excel, CSV o TXT.');
            $('#btn-upload-quote-file').prop('disabled', true);
            $('#quote-upload-file-name').text('');
            return false;
        }
        quoteSelectedFile = file;
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            quoteFileInput[0].files = dt.files;
        } catch (e) {
            // Drag-and-drop sigue funcionando vía quoteSelectedFile
        }
        $('#quote-upload-file-name').text(file.name);
        $('#btn-upload-quote-file').prop('disabled', false);
        setQuoteUploadStatus('', '');
        return true;
    }

    $('#btn-subir-archivo').on('click', function() {
        resetQuoteUploadModal();
        $('#modal-upload').show();
    });

    $('#btn-browse-quote-file').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        quoteFileInput.val('');
        quoteFileInput[0].click();
    });

    $('#upload-zone').on('click', function(e) {
        if ($(e.target).closest('#btn-browse-quote-file, #btn-upload-quote-file').length) return;
        e.preventDefault();
        quoteFileInput.val('');
        quoteFileInput[0].click();
    });

    $('#upload-zone').on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave', function() {
        $(this).removeClass('dragover');
    }).on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) setQuoteUploadFile(files[0]);
    });

    quoteFileInput.on('change', function() {
        const f = this.files && this.files[0];
        if (f) setQuoteUploadFile(f);
        else {
            quoteSelectedFile = null;
            $('#quote-upload-file-name').text('');
            $('#btn-upload-quote-file').prop('disabled', true);
        }
    });

    $('#btn-upload-quote-file').on('click', function() {
        const f = quoteSelectedFile || (quoteFileInput[0].files && quoteFileInput[0].files[0]);
        if (!f) {
            setQuoteUploadStatus('error', 'Seleccione un archivo primero');
            return;
        }
        // Modal global: siempre crear cotización nueva (nunca reemplazar)
        uploadFile(f, { quoteId: 0, replace: false });
    });

    /**
     * @param {File} file
     * @param {{quoteId?:number, replace?:boolean}} opts
     */
    function uploadFile(file, opts) {
        opts = opts || {};
        const quoteId = opts.replace ? (parseInt(opts.quoteId, 10) || 0) : 0;
        const replace = !!opts.replace && quoteId > 0;

        const formData = new FormData();
        formData.append('action', 'riverso_upload_quote_file');
        formData.append('nonce', nonce);
        formData.append('file', file);
        formData.append('quote_id', quoteId);
        if (replace) {
            formData.append('replace', '1');
        }

        $('#upload-zone').hide();
        $('#upload-progress').show();
        $('#btn-upload-quote-file, #btn-browse-quote-file').prop('disabled', true);
        setQuoteUploadStatus('info', 'Subiendo archivo…');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 120000,
            success: function(r) {
                $('#upload-progress').hide();
                $('#btn-browse-quote-file').prop('disabled', false);

                if (!r || !r.success) {
                    $('#upload-zone').show();
                    $('#btn-upload-quote-file').prop('disabled', !quoteSelectedFile);
                    setQuoteUploadStatus('error', (r && r.data && r.data.message) ? r.data.message : 'Error al subir archivo');
                    return;
                }

                if (r.data && r.data.duplicate) {
                    $('#upload-zone').show();
                    $('#btn-upload-quote-file').prop('disabled', !!quoteSelectedFile);
                    const qid = r.data.quote_id || r.data.id;
                    let msg = r.data.message || 'Este documento ya fue ingresado anteriormente.';
                    if (r.data.numero_documento) {
                        msg += ' Nº ' + r.data.numero_documento;
                    }
                    if (r.data.proveedor_nombre) {
                        msg += ' · ' + r.data.proveedor_nombre;
                    }
                    const $actions = $('<div class="quote-dup-actions"></div>');
                    const $ver = $('<button type="button" class="button button-primary">Ver</button>');
                    $ver.on('click', function() {
                        $('#modal-upload').hide();
                        resetQuoteUploadModal();
                        verCotizacion(qid);
                    });
                    $actions.append($ver);
                    setQuoteUploadStatus('warning', msg, $actions);
                    return;
                }

                const newId = r.data.id || r.data.quote_id;
                setQuoteUploadStatus('info', (r.data && r.data.message) ? r.data.message : 'Procesando con Gemini…');
                parseQuoteAfterUpload(newId, {
                    fromModal: true,
                    onDone: function() {
                        $('#modal-upload').hide();
                        resetQuoteUploadModal();
                        verCotizacion(newId);
                    },
                    onFail: function(msg) {
                        $('#upload-zone').show();
                        $('#upload-progress').hide();
                        $('#btn-browse-quote-file').prop('disabled', false);
                        $('#btn-upload-quote-file').prop('disabled', !quoteSelectedFile);
                        const $actions = $('<div class="quote-dup-actions"></div>');
                        const $ver = $('<button type="button" class="button button-primary">Ver cotización</button>');
                        $ver.on('click', function() {
                            $('#modal-upload').hide();
                            resetQuoteUploadModal();
                            verCotizacion(newId);
                        });
                        $actions.append($ver);
                        setQuoteUploadStatus('error', msg || 'Error al procesar con Gemini', $actions);
                    }
                });
            },
            error: function(xhr, status) {
                $('#upload-zone').show();
                $('#upload-progress').hide();
                $('#btn-browse-quote-file').prop('disabled', false);
                $('#btn-upload-quote-file').prop('disabled', !quoteSelectedFile);
                let msg = 'Error al subir archivo';
                if (status === 'timeout') {
                    msg = 'La subida tardó demasiado (timeout). Reintente.';
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                setQuoteUploadStatus('error', msg);
            }
        });
    }

    /**
     * Parseo Gemini separado de la subida (evita timeout del proxy y muestra el error real).
     */
    function parseQuoteAfterUpload(id, opts) {
        opts = opts || {};
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'riverso_parse_quote',
                nonce: nonce,
                id: id
            },
            timeout: 190000,
            success: function(r) {
                if (r && r.success) {
                    if (typeof opts.onDone === 'function') opts.onDone(r);
                    else verCotizacion(id);
                } else {
                    const msg = (r && r.data && r.data.message) ? r.data.message : 'Error al procesar con Gemini';
                    if (typeof opts.onFail === 'function') opts.onFail(msg);
                    else {
                        alert(msg);
                        verCotizacion(id);
                    }
                }
            },
            error: function(xhr, status) {
                let msg = 'Error al procesar con Gemini';
                if (status === 'timeout') {
                    msg = 'El parseo con Gemini tardó demasiado (timeout). Abra la cotización y pulse «Parsear con Gemini».';
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.status) {
                    msg = 'Error al procesar con Gemini (HTTP ' + xhr.status + ')';
                }
                if (typeof opts.onFail === 'function') opts.onFail(msg);
                else {
                    alert(msg);
                    verCotizacion(id);
                }
            }
        });
    }

    // Reemplazo de archivo solo desde el detalle (confirmación + replace=1)
    $('#archivo-upload').on('change', function() {
        const f = this.files && this.files[0];
        if (!f) return;
        const qid = parseInt($('#cotizacion-id').val(), 10) || 0;
        if (!qid) {
            alert('Guarde o abra una cotización antes de reemplazar el archivo.');
            $(this).val('');
            return;
        }
        if (!confirm('¿Reemplazar el archivo de esta cotización? Se borrarán los ítems actuales y se volverá a procesar con Gemini.')) {
            $(this).val('');
            return;
        }
        const input = this;
        resetQuoteUploadModal();
        $('#modal-upload').show();
        setQuoteUploadFile(f);
        uploadFile(f, { quoteId: qid, replace: true });
        $(input).val('');
    });

    $('#btn-reintentar-parse').on('click', function() {
        const id = $('#cotizacion-id').val();
        if (!id || id === '0') return;
        $(this).prop('disabled', true);
        parseQuoteAfterUpload(id, {
            onDone: function() {
                $('#btn-reintentar-parse').prop('disabled', false);
                verCotizacion(id);
            },
            onFail: function(msg) {
                $('#btn-reintentar-parse').prop('disabled', false);
                alert(msg);
                verCotizacion(id);
            }
        });
    });

    // Cerrar modales
    $('.modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });

    // Spinner CSS
    $('<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }</style>').appendTo('head');

    // Init
    const quoteFromUrl = parseInt(new URLSearchParams(window.location.search).get('quote') || '0', 10);
    if (quoteFromUrl > 0) {
        verCotizacion(quoteFromUrl);
    } else {
        cargarCotizaciones();
    }
});
</script>
