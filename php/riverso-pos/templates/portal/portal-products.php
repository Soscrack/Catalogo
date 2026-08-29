<?php
/**
 * Sección de Productos del Portal Interno
 * Paridad completa con wp-admin Hub
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permisos
if (!current_user_can('riverso_view_products')) {
    echo '<div style="padding:20px; color:#d32f2f;">Sin permisos para acceder a esta sección</div>';
    return;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
$can_assign_barcodes = $can_manage || current_user_can('riverso_assign_barcodes');
$can_manage_categories = current_user_can('riverso_manage_categories');
$can_manage_families = current_user_can('riverso_manage_families');
?>

<div class="portal-products-section">
    <style>
        .portal-products-section { max-width: 1400px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        /* Toolbar */
        .products-toolbar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center; }
        .products-toolbar select, .products-toolbar input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        .products-toolbar input[type="text"] { flex: 1; min-width: 200px; position: relative; }
        
        /* Search suggestions */
        #products-search-suggestions { display: none; position: absolute; left: 0; top: 100%; z-index: 100; background: #fff; border: 1px solid #ccc; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-height: 220px; overflow-y: auto; min-width: 100%; width: max-content; }
        #products-search-suggestions > div { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px; }
        #products-search-suggestions > div:hover { background: #f5f5f5; }
        
        /* Table */
        .products-table { width: 100%; border-collapse: collapse; background: white; border-radius: 4px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .products-table thead { background: #f5f5f5; font-weight: bold; }
        .products-table th { padding: 12px; text-align: left; border-bottom: 2px solid #ddd; font-size: 13px; }
        .products-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        .products-table tr:hover { background: #fafafa; }
        .products-table code { background: #f0f0f0; padding: 2px 4px; border-radius: 2px; font-size: 12px; }
        
        /* Badges (mismas clases/colores que wp-admin Hub) */
        .completeness-badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; white-space: nowrap; }
        .completeness-badge.completo { background: #28a745; color: white; }
        .completeness-badge.publicado { background: #007bff; color: white; }
        .completeness-badge.falta_online { background: #ffc107; color: #333; }
        .completeness-badge.falta_codigo { background: #fd7e14; color: white; }
        .completeness-badge.solo_online { background: #6f42c1; color: white; }
        .completeness-badge.solo_online_publicado { background: #17a2b8; color: white; }
        .completeness-badge.incompleto { background: #dc3545; color: white; }
        
        /* Buttons */
        .btn-small { padding: 6px 10px; background: #2196f3; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 13px; }
        .btn-small:hover { background: #1976d2; }
        .btn-small.secondary { background: #666; }
        .btn-small.secondary:hover { background: #555; }
        .btn-small.success { background: #28a745; }
        .btn-small.success:hover { background: #218838; }
        .btn-small.danger { background: #d32f2f; }
        .btn-small.danger:hover { background: #b71c1c; }
        
        /* Counter and help */
        .products-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
        .products-counter { color: #666; font-size: 13px; }
        .help-icon { cursor: pointer; color: #2271b1; font-size: 18px; }
        
        /* Detail panel */
        #product-detail-panel { display: none; margin-top: 20px; background: white; padding: 20px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        /* Detail header */
        .detail-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .detail-title { display: flex; align-items: center; gap: 12px; }
        .detail-title h3 { margin: 0; font-size: 18px; }
        .detail-badge { background: #dc3545; color: white; border-radius: 12px; padding: 4px 10px; font-weight: bold; font-size: 13px; white-space: nowrap; cursor: pointer; position: relative; }
        .detail-badge.tasks { background: #e67e22; }
        .badge-tooltip {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: #333;
            color: white;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 12px;
            z-index: 2000;
            margin-top: 0;
            padding-top: 14px;
            min-width: 240px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            white-space: normal;
            font-weight: normal;
            pointer-events: auto;
        }
        .badge-tooltip::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 0;
            right: 0;
            height: 14px;
        }
        .detail-badge:hover .badge-tooltip,
        .detail-badge.is-open .badge-tooltip { display: block; }
        .alerts-tooltip-item { padding: 6px 0; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px; color: white; }
        .alerts-tooltip-item:hover { text-decoration: underline; color: #ffeb3b; }
        .field-warning-inline { color: #dc3545; margin-left: 6px; cursor: help; font-size: 14px; font-weight: bold; vertical-align: middle; }
        .task-item { margin-bottom: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #ffc107; }
        .category-edit-btn { margin-left: 6px; font-size: 11px; padding: 2px 6px; background: #f0f0f0; border: 1px solid #ccc; cursor: pointer; border-radius: 3px; }
        .category-edit-btn:hover { background: #e0e0e0; }
        #online-categories-tree .cat-tree-row { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; }
        #online-categories-tree .cat-branch-toggle {
            width: 20px; height: 20px; flex-shrink: 0; border: 1px solid #ccc; border-radius: 3px;
            background: #fff; cursor: pointer; padding: 0; font-size: 10px; line-height: 18px; color: #555;
        }
        #online-categories-tree .cat-branch-toggle:hover { background: #f0f0f0; }
        #online-categories-tree .cat-branch-spacer { width: 20px; flex-shrink: 0; display: inline-block; }
        #online-categories-tree .cat-tree-row label { display: flex; align-items: center; user-select: none; margin: 0; flex: 1; cursor: pointer; }
        
        .detail-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        
        /* Tabs */
        .detail-tabs { display: flex; gap: 10px; border-bottom: 2px solid #eee; margin-bottom: 20px; flex-wrap: wrap; position: relative; z-index: 3; }
        .detail-tab { padding: 10px 15px; cursor: pointer; border-bottom: 3px solid transparent; text-decoration: none; color: #666; font-size: 13px; background: none; border: none; }
        .detail-tab.active { border-bottom-color: #2196f3; color: #2196f3; font-weight: bold; }
        .detail-tab:hover { color: #2196f3; }
        
        .detail-tab-content { display: none; }
        .detail-tab-content.active { display: block; }
        
        /* Form styles */
        .form-table { width: 100%; }
        .form-table tr { border-bottom: 1px solid #eee; }
        .form-table th { font-weight: bold; width: 20%; vertical-align: top; padding: 12px; text-align: left; background: #f9f9f9; }
        .form-table td { padding: 12px; }
        .form-table input[type="text"], .form-table input[type="number"], .form-table select, .form-table textarea {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; font-size: 13px; box-sizing: border-box;
        }
        .form-table textarea { resize: vertical; min-height: 80px; }
        .form-row { margin-bottom: 15px; }
        .form-row label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; }
        
        /* Info boxes */
        .info-box { padding: 12px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; }
        .info-box.warning { background: #fff3cd; border-left: 4px solid #ffc107; color: #856404; }
        .info-box.info { background: #e7f3ff; border-left: 4px solid #2196F3; color: #004085; }
        
        /* Tree structure */
        .tree-item { margin-left: 20px; padding: 8px 0; border-left: 1px solid #ddd; padding-left: 12px; }
        .tree-checkbox { margin-right: 8px; }
        
        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 15px; flex-wrap: wrap; }
        .pagination button { padding: 6px 12px; border: 1px solid #ddd; background: white; border-radius: 3px; cursor: pointer; font-size: 13px; }
        .pagination button:hover { background: #f5f5f5; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        
        /* Spinner */
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #f3f3f3; border-top: 2px solid #2196f3; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Help panel */
        #help-completeness { display: none; margin-top: 18px; background: #f9f9f9; border-left: 4px solid #2271b1; padding: 12px; border-radius: 2px; }
        #help-completeness h3 { margin-top: 0; }
        #help-completeness dt { font-weight: bold; margin-top: 8px; }
        #help-completeness dd { margin: 4px 0 8px 0; }
    </style>

    <div>
        <h2 style="margin: 0 0 10px 0;">Productos</h2>
        <p style="color: #666; margin: 0 0 15px 0;">Listado de productos locales. Asigna un código de barra en la fila o ábrelo para ver el detalle.</p>
        
        <div class="products-toolbar">
            <select id="products-status">
                <option value="active">Activos</option>
                <option value="archived">Archivados</option>
                <option value="deleted">Eliminados</option>
            </select>
            <select id="products-catalog">
                <option value="">Catálogo: Todos</option>
            </select>
            <select id="products-completeness">
                <option value="todos">Completitud: Todos</option>
                <option value="completo">Completitud: Completo</option>
                <option value="publicado">Completitud: Publicado</option>
                <option value="falta_online">Completitud: Falta Online</option>
                <option value="falta_codigo">Completitud: Falta Código</option>
                <option value="solo_online">Completitud: Solo Online</option>
                <option value="solo_online_publicado">Completitud: Solo Online Publicado</option>
                <option value="incompleto">Completitud: Incompleto</option>
            </select>
            <div style="position: relative; display: inline-block; flex: 1; min-width: 200px;">
                <input type="text" id="products-search" placeholder="SKU Local, Online, código proveedor/catálogo, nombre o barcode" autocomplete="off">
                <div id="products-search-suggestions"></div>
            </div>
            <button class="btn-small secondary" id="products-reload">🔄 Actualizar</button>
            <?php if ($can_manage): ?>
                <button class="btn-small success" id="products-new">+ Nuevo producto</button>
            <?php endif; ?>
        </div>

        <div class="products-header">
            <span class="products-counter" id="products-counter">Mostrando 0 de 0</span>
            <span class="help-icon" id="completeness-help" title="Ver ayuda de completitud">ℹ️</span>
        </div>

        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th style="width: 10%;">SKU Local</th>
                    <th style="width: 10%;">SKU Online</th>
                    <th style="width: 14%;">Nombre</th>
                    <th style="width: 11%;">Completitud</th>
                    <th style="width: 9%;">Código Proveedor</th>
                    <th style="width: 9%;">Código Catálogo</th>
                    <th style="width: 8%;">Woo</th>
                    <th style="width: 14%;">Código de barra</th>
                    <th style="width: 10%;">Acciones</th>
                </tr>
            </thead>
            <tbody id="products-tbody">
                <tr><td colspan="10" style="text-align: center; color: #999; padding: 40px;">Cargando...</td></tr>
            </tbody>
        </table>

        <div class="pagination">
            <button id="products-prev" style="display: none;">← Anterior</button>
            <span id="products-page-info" style="align-self: center; color: #666; font-size: 13px;"></span>
            <button id="products-next" style="display: none;">Siguiente →</button>
        </div>
    </div>

    <!-- Detail Panel -->
    <div id="product-detail-panel">
        <div class="detail-header">
            <div class="detail-title">
                <h3 id="detail-title">Detalle del producto</h3>
                <span class="detail-badge" id="detail-alerts-badge" style="display: none;">
                    <span id="detail-alerts-badge-text">⚠️ 0 campos</span>
                    <div class="badge-tooltip" id="detail-alerts-tooltip"></div>
                </span>
                <span class="detail-badge tasks" id="detail-tasks-badge" style="display: none;">
                    <span id="detail-tasks-badge-text">📋 0 tareas</span>
                    <div class="badge-tooltip" id="detail-tasks-tooltip"></div>
                </span>
            </div>
            <div class="detail-buttons">
                <?php if ($can_manage): ?>
                    <button class="btn-small" id="btn-detail-edit" style="display: none;">✎ Editar</button>
                    <button class="btn-small success" id="btn-detail-save" style="display: none;">✓ Guardar</button>
                    <button class="btn-small danger" id="btn-detail-cancel" style="display: none;">✕ Cancelar</button>
                <?php endif; ?>
                <button class="btn-small secondary" id="btn-detail-close">✕ Cerrar</button>
            </div>
        </div>

        <div class="detail-tabs">
            <button type="button" class="detail-tab active" data-tab="local">Local</button>
            <button type="button" class="detail-tab" data-tab="online">Online</button>
            <button type="button" class="detail-tab" data-tab="suppliers">Códigos</button>
            <button type="button" class="detail-tab" data-tab="barcodes">Barcodes</button>
            <button type="button" class="detail-tab" data-tab="tasks">Tareas</button>
            <?php if ($can_manage_families): ?>
                <button type="button" class="detail-tab" data-tab="families">Familias</button>
            <?php endif; ?>
            <?php if (current_user_can('riverso_view_warehouse') || current_user_can('manage_options')): ?>
                <button type="button" class="detail-tab" data-tab="locations">Ubicaciones</button>
            <?php endif; ?>
        </div>

        <!-- TAB: LOCAL -->
        <div class="detail-tab-content active" data-tab-content="local">
            <table class="form-table">
                <tr>
                    <th>SKU Local</th>
                    <td>
                        <code id="local-sku-view">-</code>
                        <input type="text" id="local-sku-edit" style="display: none;">
                        <!-- Panel vincular Local o generar SKU cuando está vacío -->
                        <div id="detail-local-empty-panel" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border:1px solid #e0e0e0; border-radius:4px;">
                            <p style="margin:0 0 12px 0; color:#666; font-size:13px;">Este producto no tiene Local: busca uno existente o genera un nuevo SKU.</p>
                            
                            <!-- Buscador Local -->
                            <div style="margin-bottom:12px;">
                                <strong style="display:block; margin-bottom:6px;">Buscar Local existente</strong>
                                <input type="text" id="detail-local-search" class="regular-text" placeholder="Buscar Falta Online por SKU, nombre o código..." style="margin-bottom:8px; width:100%;">
                                <div id="detail-local-suggestions" style="border:1px solid #ddd; border-radius:2px; max-height:150px; overflow-y:auto; margin-bottom:8px; padding:0; background:#fff; display:none;"></div>
                                <div id="detail-local-selected" style="padding:8px; background:#e8f5e9; border:1px solid #4caf50; border-radius:2px; display:none; margin-bottom:8px; font-size:13px;"></div>
                                <button type="button" class="btn-small success" id="detail-local-adopt-btn" style="display:none; width:100%;">Vincular este Local</button>
                            </div>

                            <!-- Separador -->
                            <div style="text-align:center; margin:12px 0; color:#999; font-size:12px;">-- o --</div>

                            <!-- Generar SKU -->
                            <div>
                                <strong style="display:block; margin-bottom:6px;">Generar nuevo SKU Local</strong>
                                <button type="button" class="btn-small" id="detail-local-generate-sku" style="width:100%; margin-bottom:8px;">Generar nuevo SKU Local</button>
                                <div id="detail-local-new-sku-preview" style="padding:10px; background:#e8f5e9; border:1px solid #4caf50; border-radius:2px; display:none;">
                                    <small style="color:#2e7d32; display:block; margin-bottom:4px;">SKU Local sugerido:</small>
                                    <input type="text" id="detail-local-new-sku-input" class="regular-text" placeholder="Cargando..." style="margin-bottom:4px;">
                                    <small style="color:#666; display:block;">Puedes editarlo si lo deseas.</small>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Nombre</th>
                    <td>
                        <span id="local-name-view">-</span>
                        <input type="text" id="local-name-edit" style="display: none;">
                    </td>
                </tr>
                <tr>
                    <th>Unidad base</th>
                    <td>
                        <span id="local-unit-view">-</span>
                        <select id="local-unit-edit" style="display: none;">
                            <option value="unidad">Unidad</option>
                            <option value="caja">Caja</option>
                            <option value="docena">Docena</option>
                            <option value="kilogramo">Kilogramo</option>
                            <option value="litro">Litro</option>
                            <option value="metro">Metro</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Permite decimal</th>
                    <td>
                        <span id="local-decimal-view">-</span>
                        <input type="checkbox" id="local-decimal-edit" style="display: none;">
                    </td>
                </tr>
                <tr>
                    <th>Permite EAN-13 personalizado</th>
                    <td>
                        <span id="local-ean-view">-</span>
                        <input type="checkbox" id="local-ean-edit" style="display: none;">
                    </td>
                </tr>
                <tr>
                    <th>Stock abierto habilitado</th>
                    <td>
                        <span id="local-stock-view">-</span>
                        <input type="checkbox" id="local-stock-edit" style="display: none;">
                    </td>
                </tr>
                <tr>
                    <th>Origen</th>
                    <td id="local-origen">-</td>
                </tr>
                <tr>
                    <th>Estado</th>
                    <td id="local-estado">-</td>
                </tr>
                <tr>
                    <th>Precio Local</th>
                    <td>
                        <div id="local-precio-view">-</div>
                        <div id="local-precio-edit" style="display: none; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                            <table style="width: 100%; margin-bottom: 8px;">
                                <tr>
                                    <td style="width: 30%;"><strong>Costo Ref:</strong></td>
                                    <td><span id="precio-c-ref">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Precio Ref:</strong></td>
                                    <td><span id="precio-p-ref">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Factor Min:</strong></td>
                                    <td><span id="precio-factor-min">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Margen:</strong></td>
                                    <td><span id="precio-margen" style="color: green;">✓ Correcto</span></td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label><strong>Precio Asignado:</strong></label><br>
                                        <input type="number" id="precio-p-asignado" step="0.01" min="0" placeholder="0.00">
                                        <button class="btn-small success" id="precio-save-btn" style="margin-top: 8px;">Guardar precio</button>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Regla de Precio</th>
                    <td>
                        <span id="regla-display">-</span>
                        <small id="regla-origen" style="color: #666; display: none;"></small>
                    </td>
                </tr>
                <tr>
                    <th>Familia</th>
                    <td>
                        <div id="local-familia-view" data-section="familia">
                            <span id="familia-display">-</span>
                            <button class="btn-small" id="familia-view-btn" style="margin-left: 8px; display: none;">Ver familia</button>
                            <button class="btn-small" id="familia-edit-toggle" style="margin-left: 8px;">Editar familia</button>
                        </div>
                        <div id="local-familia-edit" style="display: none; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                            <label><strong>Seleccionar familia:</strong></label><br>
                            <select id="familia-select" style="width: 100%; padding: 6px; margin: 6px 0;">
                                <option value="">— Sin familia —</option>
                            </select>
                            <button class="btn-small success" id="familia-save-btn">Asignar familia</button>
                            <button class="btn-small" id="familia-cancel-btn">Cancelar</button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Imagen Local</th>
                    <td>
                        <div id="local-image-view">
                            <img id="local-image-thumb" src="" style="max-width: 120px; max-height: 120px; display: none; border-radius: 4px; margin-bottom: 8px; border: 1px solid #ddd;">
                            <br>
                            <button class="btn-small" id="local-image-select">📷 Seleccionar imagen</button>
                            <span id="local-image-select-btn" style="display:none;"></span>
                            <button class="btn-small danger" id="local-image-clear" style="display: none;">Quitar imagen</button>
                        </div>
                        <div id="local-image-edit" style="display: none;">
                            <label><strong>URL de imagen:</strong></label>
                            <input type="text" id="local-image-url" placeholder="https://ejemplo.com/imagen.jpg">
                            <p style="font-size: 12px; color: #666; margin-top: 8px;">O cargue un archivo desde tu dispositivo:</p>
                            <input type="file" id="local-image-file" accept="image/*" style="margin-top: 8px;">
                            <div style="margin-top: 8px;">
                                <button class="btn-small success" id="local-image-save-btn">✓ Guardar imagen</button>
                                <button class="btn-small" id="local-image-cancel-btn">Cancelar</button>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- TAB: ONLINE -->
        <div class="detail-tab-content" data-tab-content="online">
            <div id="online-missing-code-banner" class="info-box warning" style="display: none;">
                <strong>⚠️ Falta código proveedor</strong>
                <p style="margin: 6px 0 0 0;">Este producto tiene contraparte WooCommerce pero no tiene código proveedor asignado.</p>
                <button class="btn-small" id="online-assign-code-btn" style="margin-top: 8px;">Asignar código ahora</button>
            </div>

            <div id="online-details" style="margin-bottom: 20px; padding: 12px; background: #fafafa; border-radius: 4px;"></div>

            <div id="online-price-editor" style="display: none; margin: 12px 0; padding: 12px; background: #f0f7ff; border: 1px solid #0073aa; border-radius: 4px;">
                <h4>Editar Precio Online</h4>
                <table style="width: 100%; margin-bottom: 12px;">
                    <tr>
                        <td style="width: 40%;"><strong>Precio Actual (WooCommerce):</strong></td>
                        <td><span id="online-price-current">$0.00</span></td>
                    </tr>
                    <tr>
                        <td><strong>Nuevo Precio:</strong></td>
                        <td><input type="number" id="online-price-new" step="0.01" min="0" placeholder="0.00"></td>
                    </tr>
                </table>
                <label><input type="checkbox" id="online-sync-to-woo" checked> Sincronizar precio a WooCommerce</label>
                <div style="margin-top: 12px;">
                    <button class="btn-small success" id="online-price-save">Guardar precio</button>
                    <button class="btn-small" id="online-price-cancel">Cancelar</button>
                </div>
            </div>

            <p>Vincular o crear contraparte WooCommerce.</p>
            <div style="margin: 12px 0;">
                <h4>Buscar y vincular producto existente</h4>
                <input type="text" id="woo-search" placeholder="Buscar Solo Online por nombre, SKU o ID Woo">
                <div id="woo-results" style="display: none; border: 1px solid #ddd; max-height: 180px; overflow: auto; margin-top: 6px;"></div>
                <input type="hidden" id="woo-selected-id">
                <div id="woo-selected-display" style="margin-top: 6px; color: #2271b1;"></div>
            </div>
            <table class="form-table">
                <tr><th>Woo ID</th><td id="online-woo-id">-</td></tr>
                <tr><th>Estado match</th><td id="online-match-estado">-</td></tr>
            </table>
            <p>
                <button class="btn-small success" id="online-link-btn" style="display: none;">Vincular producto WooCommerce</button>
                <button class="btn-small danger" id="online-unlink-btn" style="display: none; margin-left: 6px;">Desvincular</button>
            </p>
            <hr style="margin: 16px 0;">
            <p>
                <button class="btn-small success" id="online-create-btn" style="display: none;">Crear nuevo producto WooCommerce</button>
            </p>

            <hr style="margin: 16px 0;">
            <h4>Categorías WooCommerce</h4>
            
            <div id="online-categories-suggested-banner" class="info-box info" style="display: none;">
                <strong>Sugerido por catálogo Mamut:</strong>
                <span id="online-categories-suggested-text"></span>
            </div>
            
            <div style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-small" id="online-categories-expand-all">Expandir todo</button>
                <button type="button" class="btn-small" id="online-categories-collapse-all">Colapsar todo</button>
            </div>
            <div id="online-categories-tree" style="border: 1px solid #ddd; padding: 12px; border-radius: 4px; background: #fafafa; max-height: 400px; overflow-y: auto; margin-bottom: 12px;">
                <p style="color: #666; text-align: center;">Cargando categorías...</p>
            </div>
            
            <div style="margin-bottom: 12px;">
                <button class="btn-small success" id="online-categories-save" style="display: none;">Guardar categorías</button>
                <button class="btn-small" id="online-categories-add-new">+ Nueva categoría</button>
            </div>
            
            <div id="online-categories-add-form" style="display: none; background: #f9f9f9; border: 1px solid #ddd; padding: 12px; border-radius: 4px; margin-bottom: 12px;">
                <label for="online-categories-new-name" style="display: block; margin-bottom: 6px;">Nombre de la categoría:</label>
                <input type="text" id="online-categories-new-name" placeholder="Ej. Herramientas">
                <label for="online-categories-new-parent" style="display: block; margin-bottom: 6px; margin-top: 8px;">Categoría padre:</label>
                <select id="online-categories-new-parent" style="width: 100%; padding: 6px; margin-bottom: 6px;">
                    <option value="0">Sin padre (categoría raíz)</option>
                </select>
                <button class="btn-small success" id="online-categories-create-btn" style="margin-right: 6px;">Crear</button>
                <button class="btn-small" id="online-categories-cancel-btn">Cancelar</button>
            </div>
            
            <div id="online-categories-task-panel" class="info-box warning" style="display: none;">
                <strong>Tarea pendiente:</strong> Validar categoría
                <div style="margin-top: 8px; font-size: 12px; color: #666;">
                    <span id="online-categories-task-suggested"></span>
                </div>
                <button class="btn-small success" id="online-categories-accept-task" style="margin-top: 8px;">Aceptar categorías y completar tarea</button>
            </div>
        </div>

        <!-- TAB: CÓDIGOS PROVEEDOR -->
        <div class="detail-tab-content" data-tab-content="suppliers">
            <div id="suppliers-missing-banner" class="info-box warning" style="display: none;">
                <strong>⚠️ Falta código proveedor</strong>
                <p style="margin: 6px 0 0 0;">Asigna al menos un código proveedor para completar el producto.</p>
            </div>
            <p>Buscar y asignar códigos proveedor.</p>
            <div style="margin: 12px 0;">
                <input type="text" id="supplier-code-search" placeholder="Código proveedor (p.ej. 123456)">
                <div id="supplier-search-results" style="display: none; border: 1px solid #ddd; max-height: 180px; overflow: auto; margin-top: 6px;"></div>
                <input type="hidden" id="supplier-id-select">
                <input type="hidden" id="supplier-code-select">
            </div>
            <div style="margin: 12px 0;">
                <label>Motivo auditoría:</label>
                <textarea id="supplier-audit-reason" rows="2" placeholder="Describe por qué asignas este código..."></textarea>
            </div>
            <p>
                <button class="btn-small success" id="supplier-link-btn">Asignar código proveedor</button>
            </p>
            <div id="suppliers-list" style="margin-top: 12px;"></div>
        </div>

        <!-- TAB: BARCODES -->
        <div class="detail-tab-content" data-tab-content="barcodes">
            <div id="barcodes-missing-banner" class="info-box warning" style="display: none;">
                <strong>⚠️ Falta Barcode EAN-13</strong>
                <p style="margin: 6px 0 0 0;">El producto online no tiene un código EAN-13 asociado.</p>
            </div>
            <div id="barcodes-legacy-banner" class="info-box warning" style="display: none;">
                <strong>⚠️ Códigos legacy por confirmar</strong>
                <p style="margin: 6px 0 0 0;">Hay códigos importados del legacy. Acéptalos como Código de Proveedor o recházalos para eliminarlos.</p>
            </div>
            <p>Agregar códigos de barra y gestionar por tipo.</p>
            <div style="margin: 12px 0; padding: 12px; background: #f9f9f9; border-radius: 4px;">
                <h4>Nuevo código de barra</h4>
                
                <div style="margin-bottom: 12px;">
                    <label><strong>Tipo de código:</strong></label>
                    <select id="barcode-type" style="width: 100%; padding: 6px;">
                        <option value="supplier" selected>Código de Proveedor</option>
                        <option value="ean13">EAN-13</option>
                        <option value="internal">Interno</option>
                    </select>
                </div>

                <div style="margin-bottom: 12px;">
                    <label><strong>Código:</strong></label>
                    <input type="text" id="barcode-new" placeholder="Ingrese código de barra">
                </div>

                <div id="barcode-supplier-section" style="display: none; margin-bottom: 12px;">
                    <label><strong>Proveedor (si aplica):</strong></label>
                    <select id="barcode-proveedor" style="width: 100%; padding: 6px;">
                        <option value="">— Seleccione proveedor —</option>
                    </select>
                </div>

                <div style="margin-bottom: 12px;">
                    <label><strong>Cantidad:</strong></label>
                    <input type="number" id="barcode-cantidad" placeholder="1" step="0.01" value="1">
                </div>

                <div style="margin-bottom: 12px;">
                    <label><strong>Unidad:</strong></label>
                    <select id="barcode-unidad" style="width: 100%; padding: 6px;">
                        <option value="unidad">Unidad</option>
                        <option value="caja">Caja</option>
                        <option value="pallet">Pallet</option>
                        <option value="kg">Kilogramo</option>
                        <option value="lt">Litro</option>
                    </select>
                </div>

                <div style="margin-bottom: 12px;">
                    <label><strong>Origen:</strong></label>
                    <select id="barcode-origen" style="width: 100%; padding: 6px;">
                        <option value="manual">Manual</option>
                        <option value="proveedor">Proveedor</option>
                        <option value="import">Importado</option>
                    </select>
                </div>

                <div style="margin-bottom: 12px;">
                    <label><strong>Motivo (opcional):</strong></label>
                    <textarea id="barcode-audit-reason" rows="2" placeholder="Motivo auditoría o comentario"></textarea>
                </div>

                <button class="btn-small success" id="barcode-add-btn">Agregar código de barra</button>
            </div>
            <div id="barcodes-list" style="margin-top: 12px;"></div>
        </div>

        <!-- TAB: TASKS -->
        <div class="detail-tab-content" data-tab-content="tasks">
            <div id="tasks-list" style="margin-top: 12px;"></div>
            <p id="tasks-empty" style="color: #666;">Sin tareas activas.</p>
        </div>

        <!-- TAB: FAMILIAS -->
        <?php if ($can_manage_families): ?>
        <div class="detail-tab-content" data-tab-content="families">
            <div style="margin-bottom: 12px;">
                <button class="btn-small success" id="family-create-btn">+ Nueva familia</button>
            </div>

            <div id="family-create-form" style="display: none; margin-bottom: 12px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h4 style="margin-top: 0;">Crear Nueva Familia</h4>
                <table class="form-table">
                    <tr>
                        <th><label for="family-nombre">Nombre</label></th>
                        <td><input type="text" id="family-nombre" placeholder="ej. Bebidas Refrescantes"></td>
                    </tr>
                    <tr>
                        <th><label for="family-codigo">Código Único</label></th>
                        <td>
                            <input type="text" id="family-codigo" placeholder="Se genera del nombre si lo dejas vacío">
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#666;">Opcional. Si está vacío se usa el slug del nombre.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="family-tipo">Tipo de Sustitución</label></th>
                        <td>
                            <select id="family-tipo">
                                <option value="exacta" selected>Exacta (mismo ítem, distinto envase)</option>
                                <option value="preferida">Preferida</option>
                                <option value="complementaria">Complementaria</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="btn-small success" id="family-save-btn">Crear familia</button>
                    <button class="btn-small" id="family-cancel-btn">Cancelar</button>
                </p>
            </div>

            <div id="family-tree" style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 12px; background: #fafafa;">
                <p style="color: #666; text-align: center;">Cargando familias...</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (current_user_can('riverso_view_warehouse') || current_user_can('manage_options')): ?>
        <div class="detail-tab-content" data-tab-content="locations">
            <div id="prod-loc-preferidas" style="margin-bottom:16px;">
                <h4 style="margin:0 0 8px;">Lugares preferidos</h4>
                <div id="prod-loc-pref-list">Cargando...</div>
                <?php if (current_user_can('riverso_edit_warehouse') || current_user_can('manage_options')): ?>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <select id="prod-loc-add-select" style="min-width:220px;padding:6px;"></select>
                    <button type="button" class="btn-small" id="prod-loc-add">Agregar a preferidos</button>
                </div>
                <?php endif; ?>
            </div>
            <div id="prod-loc-actuales" style="margin-bottom:16px;">
                <h4 style="margin:0 0 8px;">Lugares actuales (último conteo)</h4>
                <div id="prod-loc-act-list">—</div>
            </div>
            <div id="prod-loc-historial">
                <h4 style="margin:0 0 8px;">Lugares vistos (historial)</h4>
                <table class="products-table" style="box-shadow:none;">
                    <thead><tr><th>Fecha</th><th>Lugar</th><th>Cantidad</th><th>Conteo</th></tr></thead>
                    <tbody id="prod-loc-hist-body"><tr><td colspan="4">—</td></tr></tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Help panel -->
    <div id="help-completeness" style="display: none; margin-top: 18px; background: #f9f9f9; border-left: 4px solid #2271b1; padding: 12px; border-radius: 2px;">
        <h3 style="margin-top: 0;">Guía de Completitud</h3>
        <dl style="margin: 8px 0; font-size: 13px;">
            <dt style="font-weight: bold; color: #28a745;">Producto Completo</dt>
            <dd style="margin: 4px 0 8px 0;">Producto local (SKU + nombre) + contraparte WooCommerce (publicado o no) + al menos un código proveedor.</dd>

            <dt style="font-weight: bold; color: #007bff;">Producto Publicado</dt>
            <dd style="margin: 4px 0 8px 0;">Completo y además está publicado en WooCommerce (aprobado y visible).</dd>

            <dt style="font-weight: bold; color: #ffc107;">Falta Online</dt>
            <dd style="margin: 4px 0 8px 0;">Producto local completo pero sin vínculo a WooCommerce.</dd>

            <dt style="font-weight: bold; color: #fd7e14;">Falta Código</dt>
            <dd style="margin: 4px 0 8px 0;">Producto local + online pero sin código proveedor asignado.</dd>

            <dt style="font-weight: bold; color: #6f42c1;">Solo Online</dt>
            <dd style="margin: 4px 0 8px 0;">Existe en WooCommerce pero no tiene datos locales (SKU/nombre) completos.</dd>

            <dt style="font-weight: bold; color: #dc3545;">Incompleto</dt>
            <dd style="margin: 4px 0 8px 0;">No tiene SKU o nombre local definidos.</dd>
        </dl>
        <button class="btn-small" id="help-close" style="margin-top: 8px;">Cerrar</button>
    </div>
</div>

<script>
(window.riversoWhenJQuery || function(fn){ jQuery(fn); })(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canAssignBarcodes = <?php echo $can_assign_barcodes ? 'true' : 'false'; ?>;
    const canManageCategories = <?php echo $can_manage_categories ? 'true' : 'false'; ?>;
    const canManageFamilies = <?php echo $can_manage_families ? 'true' : 'false'; ?>;
    const canEditWarehouse = <?php echo (current_user_can('riverso_edit_warehouse') || current_user_can('manage_options')) ? 'true' : 'false'; ?>;
    
    let currentProduct = null;
    let currentOffset = 0;
    let totalCount = 0;
    const LIMIT = 20;
    let isEditMode = false;
    let originalValues = {};

    function esc(v) {
        return $('<div>').text(v === null || v === undefined ? '' : v).html();
    }

    /** Woo ID compacto: producto simple, o "padre / variación". */
    function formatWooIds(p) {
        const parent = parseInt(p.woocommerce_product_id || 0, 10);
        const child = parseInt(p.woocommerce_variation_id || 0, 10);
        if (child > 0) {
            const padre = parent > 0 ? parent : '?';
            return padre + ' / ' + child;
        }
        if (parent > 0) return String(parent);
        return '-';
    }

    /** Woo ID legible en pestaña Online. */
    function formatWooIdsLabel(p) {
        const parent = parseInt(p.woocommerce_product_id || 0, 10);
        const child = parseInt(p.woocommerce_variation_id || 0, 10);
        if (child > 0) {
            const padre = parent > 0 ? parent : '?';
            return 'Padre ' + padre + ' · Var ' + child;
        }
        if (parent > 0) return String(parent);
        return '-';
    }

    function post(action, data) {
        return $.post(ajaxUrl, { action, nonce, ...data });
    }

    function formatMoney(val) {
        return '$' + parseFloat(val || 0).toFixed(2);
    }

    function completenessLabel(cat) {
        const labels = {
            'completo': 'Producto Completo',
            'publicado': 'Producto Publicado',
            'falta_online': 'Falta Online',
            'falta_codigo': 'Falta Código',
            'solo_online': 'Solo Online',
            'solo_online_publicado': 'Solo Online Publicado',
            'incompleto': 'Incompleto'
        };
        return labels[cat] || cat;
    }

    function renderSkuCell(value, label) {
        const v = (value || '').toString().trim();
        if (!v) {
            return `<span style="color:#999;" title="${esc(label)}">—</span>`;
        }
        const parts = v.split(',').map(s => s.trim()).filter(Boolean).slice(0, 2);
        return `<code title="${esc(label)}: ${esc(parts.join(', '))}">${esc(parts.join(', '))}</code>`;
    }

    function loadCatalogs() {
        post('riverso_catalogs_list', {}).done(function(r) {
            if (!r.success) return;
            const select = $('#products-catalog');
            const currentVal = select.val();
            select.find('option:not(:first)').remove();
            (r.data.catalogs || []).forEach(cat => {
                select.append(`<option value="${cat.id}">${esc(cat.nombre)}</option>`);
            });
            if (currentVal) select.val(currentVal);
        });
    }

    function loadProducts(offset = 0) {
        const status = $('#products-status').val();
        const catalogId = $('#products-catalog').val() || 0;
        const completeness = $('#products-completeness').val();
        const search = $('#products-search').val();

        post('riverso_products_list', {
            offset: offset || 0,
            limit: LIMIT,
            status: status,
            catalog_id: catalogId,
            completeness: completeness,
            search: search || ''
        }).done(function(r) {
            if (!r.success) {
                $('#products-tbody').html('<tr><td colspan="10" style="color: #d32f2f; padding: 20px; text-align: center;">Error: ' + esc((r.data && r.data.message) || 'cargando') + '</td></tr>');
                return;
            }

            const products = r.data.items || [];
            totalCount = r.data.total || 0;
            const pages = r.data.pages || 0;
            currentOffset = offset || 0;
            let html = '';
            
            if (products.length === 0) {
                html = '<tr><td colspan="10" style="text-align: center; color: #999; padding: 40px;">Sin productos locales.</td></tr>';
            } else {
                products.forEach(p => {
                    const cat = p.completeness_category || 'incompleto';
                    const skuLocal = p.sku_local || p.canonical_sku || '';
                    const skuOnline = p.sku_online || '';
                    let codigoProv = renderSkuCell(p.codigos_proveedor, 'Código Proveedor');
                    let codigoCat = renderSkuCell(p.codigos_catalogo, 'Código Catálogo');

                    const hasOnline = parseInt(p.woocommerce_product_id || 0, 10) > 0
                        || parseInt(p.woocommerce_variation_id || 0, 10) > 0;
                    const hasCode = parseInt(p.proveedores_count || 0) > 0;
                    if (hasOnline && !hasCode && cat === 'falta_codigo') {
                        codigoProv = `<span class="completeness-badge falta_codigo" style="cursor:pointer; padding:4px 8px; display:inline-block;" data-product-id="${p.id}" title="Ir a Códigos">Falta código</span>`;
                        codigoCat = `<span style="color:#999;">—</span>`;
                    }

                    const wooId = formatWooIds(p);

                    const bcCount = parseInt(p.barcodes_count || 0, 10);
                    const bcSample = p.barcode_sample ? esc(p.barcode_sample) : '';
                    let barcodeCell = bcCount
                        ? `<div style="font-size:12px;"><strong>${bcCount}</strong>${bcSample ? `<div style="color:#666;max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="${bcSample}">${bcSample}</div>` : ''}</div>`
                        : '<span style="color:#c77700;font-size:12px;">Sin código</span>';
                    if (canAssignBarcodes) {
                        barcodeCell += `<div style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
                            <input type="text" class="products-barcode-input" placeholder="Escanear código" data-id="${p.id}" style="width:130px;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:12px;">
                            <button type="button" class="btn-small success products-assign-barcode" data-id="${p.id}" data-sku="${esc(skuLocal)}">Asignar</button>
                        </div>`;
                    }

                    html += `<tr>
                        <td>${p.id}</td>
                        <td>${renderSkuCell(skuLocal, 'SKU Local')}</td>
                        <td>${renderSkuCell(skuOnline, 'SKU Online')}</td>
                        <td>${esc(p.nombre_canonico || '-')}</td>
                        <td><span class="completeness-badge ${cat}">${completenessLabel(cat)}</span></td>
                        <td>${codigoProv}</td>
                        <td>${codigoCat}</td>
                        <td><code style="font-size:12px;">${esc(wooId)}</code></td>
                        <td>${barcodeCell}</td>
                        <td><button type="button" class="btn-small" onclick="window.portalProducts.openDetail(${p.id})">Ver</button></td>
                    </tr>`;
                });
            }
            
            $('#products-tbody').html(html);

            const showing = Math.min(LIMIT, totalCount - currentOffset);
            const startItem = totalCount === 0 ? 0 : currentOffset + 1;
            const endItem = currentOffset + Math.max(0, showing);
            $('#products-counter').text(`Mostrando ${startItem} a ${endItem} de ${totalCount}`);
            $('#products-page-info').text(`Página ${Math.floor(currentOffset / LIMIT) + 1} de ${pages || 1}`);
            
            $('#products-prev').toggle(currentOffset > 0).off('click').on('click', () => loadProducts(Math.max(0, currentOffset - LIMIT)));
            $('#products-next').toggle(currentOffset + LIMIT < totalCount).off('click').on('click', () => loadProducts(currentOffset + LIMIT));
        });
    }

    function openDetail(productId, onReady) {
        post('riverso_products_get', { id: productId }).done(function(r) {
            if (!r.success) {
                alert('Error: ' + ((r.data && r.data.message) || 'desconocido'));
                return;
            }
            currentProduct = r.data.item || r.data.product;
            isEditMode = false;
            renderDetail();
            $('#product-detail-panel').show();
            $('html, body').animate({ scrollTop: $('#product-detail-panel').offset().top - 50 }, 300);
            if (typeof onReady === 'function') {
                onReady();
            }
        });
    }

    function switchDetailTab(tab) {
        $('.detail-tab').removeClass('active');
        $(`.detail-tab[data-tab="${tab}"]`).addClass('active');
        $('.detail-tab-content').removeClass('active');
        $(`.detail-tab-content[data-tab-content="${tab}"]`).addClass('active');
        const $panel = $(`.detail-tab-content[data-tab-content="${tab}"]`);
        if ($panel.length) {
            $('html, body').animate({ scrollTop: $panel.offset().top - 80 }, 200);
        }
        if (tab === 'locations' && currentProduct) {
            loadProductLocations(currentProduct.id);
        }
    }

    function renderDetail() {
        const p = currentProduct;
        $('#detail-title').text(`Producto: ${p.nombre_canonico || 'Sin nombre'} (SKU Local: ${p.canonical_sku || '—'})`);

        // Tab: Local
        $('#local-sku-view').text(p.canonical_sku || '-');
        $('#local-sku-edit').val(p.canonical_sku || '');
        
        // Mostrar panel de vincular Local o generar SKU si está vacío
        const hasLocalSku = !!p.canonical_sku;
        const hasWooLink = p.woocommerce_product_id || p.woocommerce_variation_id;
        if (!hasLocalSku && hasWooLink) {
            $('#detail-local-empty-panel').show();
            // Limpiar campos del panel
            $('#detail-local-search').val('');
            $('#detail-local-suggestions').html('').hide();
            $('#detail-local-selected').hide();
            $('#detail-local-adopt-btn').hide();
            $('#detail-local-new-sku-preview').hide();
        } else {
            $('#detail-local-empty-panel').hide();
        }
        
        $('#local-name-view').text(p.nombre_canonico || '-');
        $('#local-name-edit').val(p.nombre_canonico || '');
        $('#local-unit-view').text(p.unidad_base || 'unidad');
        $('#local-unit-edit').val(p.unidad_base || 'unidad');
        $('#local-decimal-view').text(p.permite_decimal ? 'Sí' : 'No');
        $('#local-decimal-edit').prop('checked', !!parseInt(p.permite_decimal || 0));
        $('#local-ean-view').text((p.permite_ean13_personalizado || p.permite_ean13) ? 'Sí' : 'No');
        $('#local-ean-edit').prop('checked', !!(p.permite_ean13_personalizado || p.permite_ean13));
        $('#local-stock-view').text((p.stock_abierto_habilitado || p.stock_abierto) ? 'Sí' : 'No');
        $('#local-stock-edit').prop('checked', !!(p.stock_abierto_habilitado || p.stock_abierto));
        $('#local-origen').text(p.origen_datos || p.origen || 'manual');
        $('#local-estado').text(p.estado || '-');

        // Precio Local (campo API: precio_local)
        const precio = p.precio_local || p.precio || null;
        if (precio) {
            const c_ref = parseFloat(precio.c_ref || precio.costo_ref || 0);
            const p_ref = parseFloat(precio.p_ref || precio.precio_ref || 0);
            const p_asignado = parseFloat(precio.p_asignado || precio.precio_asignado || 0);
            const factor_min = parseFloat(precio.factor_minimo || precio.factor_min || 1.30);
            const alerta = !!precio.alerta_margen;
            $('#local-precio-view').html(`
                <div>Costo Ref: ${formatMoney(c_ref)} | Precio Ref: ${formatMoney(p_ref)} | Asignado: <strong style="${alerta ? 'color:red;' : ''}">${formatMoney(p_asignado)}</strong></div>
            `);
            $('#precio-c-ref').text(formatMoney(c_ref));
            $('#precio-p-ref').text(formatMoney(p_ref));
            $('#precio-factor-min').text(factor_min.toFixed(2));
            $('#precio-margen').html(alerta ? '<span style="color:red;">⚠️ Alerta margen</span>' : '<span style="color:green;">✓ Correcto</span>');
            $('#precio-p-asignado').val(p_asignado || 0);
        } else {
            $('#local-precio-view').html('<span style="color:#999;">Sin precio asignado</span>');
        }

        // Regla de Precio
        if (p.regla_precio && (p.regla_precio.nombre || p.regla_precio.id)) {
            $('#regla-display').text(p.regla_precio.nombre || '-');
            if (p.regla_precio.origen) {
                $('#regla-origen').text(`(${p.regla_precio.origen})`).show();
            } else {
                $('#regla-origen').hide();
            }
        } else {
            $('#regla-display').text('-');
            $('#regla-origen').hide();
        }

        // Familia
        renderFamiliaHub(p);
        loadFamiliesDropdown();

        // Imagen Local
        if (p.imagen_id && p.imagen_url) {
            $('#local-image-thumb').attr('src', p.imagen_url).show();
            $('#local-image-clear').show();
            $('#local-image-url').val(p.imagen_full || p.imagen_url || '');
        } else if (p.imagen_local_url) {
            $('#local-image-thumb').attr('src', p.imagen_local_url).show();
            $('#local-image-clear').show();
            $('#local-image-url').val(p.imagen_local_url);
        } else {
            $('#local-image-thumb').hide();
            $('#local-image-clear').hide();
            $('#local-image-url').val('');
        }

        // Tab: Online
        const hasWoo = parseInt(p.woocommerce_product_id || 0, 10) > 0
            || parseInt(p.woocommerce_variation_id || 0, 10) > 0;
        $('#online-woo-id').text(formatWooIdsLabel(p));
        $('#online-match-estado').text(p.match_estado_online || 'UNMATCHED');
        if (canManage) {
            $('#online-unlink-btn').toggle(!!hasWoo);
        } else {
            $('#online-unlink-btn').hide();
        }
        const hasCode = parseInt(p.proveedores_count || 0) > 0 || (p.proveedores && p.proveedores.length > 0);
        if (!hasCode && hasWoo) {
            $('#online-missing-code-banner').show();
        } else {
            $('#online-missing-code-banner').hide();
        }
        $('#suppliers-missing-banner').toggle(!hasCode);
        const hasEan13Tipo = p.barcodes && p.barcodes.some(b => (b.tipo || '') === 'ean13');
        $('#barcodes-missing-banner').toggle(hasWoo && !hasEan13Tipo);
        const hasLegacyPending = p.barcodes && p.barcodes.some(b => {
            const o = String(b.origen_datos || b.origen || '').toLowerCase();
            const m = String(b.migrado_de_tabla || '').toLowerCase();
            return o.includes('legacy') || m !== '' || !!b.legacy_ref;
        });
        $('#barcodes-legacy-banner').toggle(!!hasLegacyPending);

        // Badges + warnings inline (análogo wp-admin)
        calculateFieldAlerts(p);
        showFieldWarningIcons(p);
        calculatePendingTasks(p);

        renderOnlineDetails(p);
        loadCategoryTree(p.woocommerce_product_id);
        renderSupplierCodes(p);
        renderBarcodes(p);
        renderTasks(p.tasks || []);
        if (canManageFamilies) {
            loadFamilyTree(p);
        }

        if (canManage) {
            $('#btn-detail-edit').show();
            $('#btn-detail-save').hide();
            $('#btn-detail-cancel').hide();
        }

        exitEditMode();
        // Reaplicar warnings tras exitEditMode (limpia/oculta nodos)
        showFieldWarningIcons(p);
    }

    function getPendingFamilyTasks(product) {
        const tasks = (product.tasks || []).filter(t => t.estado !== 'completada');
        return {
            ask: tasks.find(t => t.tipo === 'preguntar_familia') || null,
            assign: tasks.find(t => t.tipo === 'asignar_familia') || null,
        };
    }

    function renderFamiliaHub(product) {
        $('.familia-decision-ui, .familia-assign-warn').remove();
        if (product.familia) {
            $('#familia-display').html(`<strong>${esc(product.familia.nombre)}</strong> <small style="color:#666;">(${esc(product.familia.tipo_sustitucion || product.familia.codigo_grupo || '')})</small>`);
            $('#familia-view-btn').show().data('familia-id', product.familia.id);
            return;
        }

        $('#familia-view-btn').hide().data('familia-id', '');
        const ft = getPendingFamilyTasks(product);

        if (ft.ask) {
            $('#familia-display').html('<span style="color:#666;">¿Este producto necesita familia?</span>');
            $('#local-familia-view').append(
                `<div class="familia-decision-ui" style="margin-top:8px;">
                    <button type="button" class="btn-small success familia-answer-yes">Sí, necesita</button>
                    <button type="button" class="btn-small familia-answer-no" style="margin-left:6px;">No, queda solo</button>
                </div>`
            );
            return;
        }

        if (ft.assign) {
            $('#familia-display').html('<span style="color:#b45309;">Asignar familia</span>');
            $('#local-familia-view').append('<span class="field-warning-inline familia-assign-warn" title="Asignar familia">⚠️</span>');
            return;
        }

        if (product.familia_decision === 'no_requiere') {
            $('#familia-display').html('<span style="color:#666;">No requiere familia</span>');
            return;
        }

        $('#familia-display').html('<span style="color:#999;">Sin familia</span>');
    }

    function answerFamilyNeed(needsFamily) {
        if (!currentProduct || !currentProduct.id) return $.Deferred().reject();
        return post('riverso_products_answer_family_need', {
            product_id: currentProduct.id,
            needs_family: needsFamily ? 1 : 0
        });
    }

    function refreshProductAfterFamilyAnswer(r) {
        if (r.success && r.data && r.data.product) {
            currentProduct = r.data.product;
            renderFamiliaHub(currentProduct);
            calculateFieldAlerts(currentProduct);
            showFieldWarningIcons(currentProduct);
            calculatePendingTasks(currentProduct);
            renderTasks(currentProduct.tasks || []);
        }
    }

    $(document).on('click', '.familia-answer-yes, .task-family-yes', function(e) {
        e.preventDefault();
        answerFamilyNeed(true).done(refreshProductAfterFamilyAnswer).fail(function() {
            alert('Error al guardar la respuesta');
        });
    });

    $(document).on('click', '.familia-answer-no, .task-family-no', function(e) {
        e.preventDefault();
        answerFamilyNeed(false).done(refreshProductAfterFamilyAnswer).fail(function() {
            alert('Error al guardar la respuesta');
        });
    });

    function calculateFieldAlerts(product) {
        const alerts = [];

        if (!product.canonical_sku) {
            alerts.push({ field: 'SKU Local', icon: '❌', action: 'edit-sku' });
        }
        if (!product.precio_local || !product.precio_local.p_asignado) {
            alerts.push({ field: 'Precio Local', icon: '⚠️', action: 'tab-local' });
        }
        const ft = getPendingFamilyTasks(product);
        if (ft.assign) {
            alerts.push({ field: 'Asignar familia', icon: '👥', action: 'tab-local' });
        }
        if (!product.imagen_id) {
            alerts.push({ field: 'Imagen Local', icon: '📷', action: 'tab-local' });
        }
        if ((product.proveedores_count || 0) === 0) {
            alerts.push({ field: 'Código Proveedor', icon: '📦', action: 'tab-suppliers' });
        }
        const legacyPending = (product.barcodes || []).filter(b => {
            const o = String(b.origen_datos || b.origen || '').toLowerCase();
            const m = String(b.migrado_de_tabla || '').toLowerCase();
            return o.includes('legacy') || m !== '' || !!b.legacy_ref;
        });
        if (legacyPending.length > 0) {
            alerts.push({
                field: 'Códigos legacy pendientes (' + legacyPending.length + ')',
                icon: '📊',
                action: 'tab-barcodes'
            });
        }
        if (product.woocommerce_product_id) {
            const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
            if (!hasEan) {
                alerts.push({ field: 'Barcode EAN-13', icon: '📊', action: 'tab-barcodes' });
            }
            if (Array.isArray(product.current_categories) && product.current_categories.length === 0) {
                alerts.push({ field: 'Categorías Online', icon: '📂', action: 'tab-online' });
            }
        }

        if (alerts.length > 0) {
            $('#detail-alerts-badge-text').text(`⚠️ ${alerts.length} campos`);
            $('#detail-alerts-badge').show();
            $('#detail-alerts-tooltip').html(alerts.map(a =>
                `<div class="alerts-tooltip-item" data-alert-action="${esc(a.action)}">
                    <span>${a.icon}</span> ${esc(a.field)}
                </div>`
            ).join(''));
        } else {
            $('#detail-alerts-badge').hide();
            $('#detail-alerts-tooltip').html('');
        }
        return alerts;
    }

    function calculatePendingTasks(product) {
        const tasks = (product.tasks || []).filter(t => t.estado !== 'completada');
        if (tasks.length > 0) {
            $('#detail-tasks-badge-text').text('📋 ' + tasks.length + ' tarea' + (tasks.length > 1 ? 's' : ''));
            $('#detail-tasks-badge').show();
            $('#detail-tasks-tooltip').html(tasks.map(t =>
                `<div class="alerts-tooltip-item task-tooltip-goto" data-task-tipo="${esc(t.tipo)}">
                    <span>📌</span> ${esc(t.titulo)}
                </div>`
            ).join(''));
        } else {
            $('#detail-tasks-badge').hide();
            $('#detail-tasks-tooltip').html('');
        }
    }

    function showFieldWarningIcons(product) {
        $('.field-warning-inline').remove();

        if (!product.canonical_sku) {
            $('#local-sku-view').after('<span class="field-warning-inline" title="Falta SKU Local">⚠️</span>');
        }
        if (!product.precio_local || !product.precio_local.p_asignado) {
            $('#local-precio-view').after('<span class="field-warning-inline" title="Falta Precio Local">⚠️</span>');
        }
        // Familia: warning solo vía renderFamiliaHub cuando hay tarea asignar_familia
        
        if (!product.imagen_id) {
            $('#local-image-select').after('<span class="field-warning-inline" title="Falta Imagen">⚠️</span>');
        }
        if ((product.proveedores_count || 0) === 0) {
            $('#suppliers-list').before('<span class="field-warning-inline suppliers-warn" title="Falta Código Proveedor" style="display:block;margin-bottom:8px;">⚠️ Falta código proveedor</span>');
        }
        const legacyPendingIcons = (product.barcodes || []).filter(b => {
            const o = String(b.origen_datos || b.origen || '').toLowerCase();
            const m = String(b.migrado_de_tabla || '').toLowerCase();
            return o.includes('legacy') || m !== '' || !!b.legacy_ref;
        });
        if (legacyPendingIcons.length > 0) {
            $('#barcodes-list').before('<span class="field-warning-inline barcodes-warn" title="Códigos legacy pendientes" style="display:block;margin-bottom:8px;">⚠️ Hay códigos legacy por confirmar</span>');
        }
        if (product.woocommerce_product_id) {
            const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
            if (!hasEan) {
                $('#barcodes-list').before('<span class="field-warning-inline barcodes-warn" title="Falta EAN-13" style="display:block;margin-bottom:8px;">⚠️ Falta Barcode EAN-13</span>');
            }
        }
    }

    function renderOnlineDetails(p) {
        const hasWoo = parseInt(p.woocommerce_product_id || 0, 10) > 0
            || parseInt(p.woocommerce_variation_id || 0, 10) > 0;
        if (!hasWoo) {
            $('#online-details').html('<em style="color:#999;">Sin producto WooCommerce asociado. Use el formulario de búsqueda/creación para vincular.</em>');
            if (canManage) {
                $('#online-create-btn').show();
                $('#online-link-btn').show();
            }
            $('#online-unlink-btn').hide();
            return;
        }
        $('#online-create-btn').hide();
        // online-link-btn solo se muestra al seleccionar un Woo en búsqueda
        $('#online-link-btn').hide();
        if (canManage) {
            $('#online-unlink-btn').show();
        }

        const d = p.online_details || null;
        if (!d) {
            let html = `<strong>ID WooCommerce:</strong> ${esc(formatWooIdsLabel(p))}<br>`;
            html += `<strong>Estado Match:</strong> ${esc(p.match_estado_online || 'UNMATCHED')}<br>`;
            if (p.precio_online && p.precio_online.p_asignado != null) {
                html += `<strong>Precio Online:</strong> ${formatMoney(p.precio_online.p_asignado)}`;
                if (canManage) {
                    html += ` <button class="btn-small" id="btn-edit-online-price" style="margin-left:8px;">✎ Editar</button>`;
                    $('#online-price-current').text(formatMoney(p.precio_online.p_asignado));
                }
            }
            $('#online-details').html(html);
            $('#btn-edit-online-price').off('click').on('click', () => $('#online-price-editor').show());
            return;
        }

        const price = (d.price != null && d.price !== '')
            ? parseFloat(d.price)
            : (p.precio_online && p.precio_online.p_asignado != null ? parseFloat(p.precio_online.p_asignado) : 0);
        const priceSafe = isNaN(price) ? 0 : price;

        let html = '';

        // Identidad Online
        html += `<div style="margin-bottom:20px;">
            <h4 style="margin:0 0 10px 0;">Identidad Online</h4>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee; width:140px;"><strong>Tipo:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(d.type || 'N/A')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Nombre:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(d.name || '')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>SKU Online:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><code>${esc(d.sku || '-')}</code></td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Estado:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(d.status || 'N/A')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Match:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(p.match_estado_online || 'UNMATCHED')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Woo ID:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(formatWooIdsLabel(p))}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Precio:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">
                        <span id="online-price-display">${formatMoney(priceSafe)}</span>
                        ${canManage ? '<button class="btn-small" id="btn-edit-online-price" style="margin-left:8px;">Editar</button>' : ''}
                    </td>
                </tr>
            </table>
        </div>`;

        // Atributos de Variación
        if (d.attributes && d.attributes.length > 0) {
            html += '<div style="margin-bottom:20px;">';
            html += '<h4 style="margin:0 0 10px 0;">Atributos de Variación</h4>';
            if (d.type === 'variation') {
                html += '<ul style="margin:0; padding-left:20px;">';
                d.attributes.forEach(attr => {
                    html += `<li>${esc(attr.name)}: <strong>${esc(attr.value)}</strong></li>`;
                });
                html += '</ul>';
            } else if (d.type === 'variable') {
                html += '<ul style="margin:0; padding-left:20px;">';
                d.attributes.forEach(attr => {
                    const options = (attr.options || []).join(', ');
                    html += `<li>${esc(attr.name)}: <small>${esc(options)}</small></li>`;
                });
                html += '</ul>';
            } else {
                html += '<ul style="margin:0; padding-left:20px;">';
                d.attributes.forEach(attr => {
                    if (attr.value != null) {
                        html += `<li>${esc(attr.name)}: <strong>${esc(attr.value)}</strong></li>`;
                    } else if (attr.options) {
                        html += `<li>${esc(attr.name)}: <small>${esc((attr.options || []).join(', '))}</small></li>`;
                    }
                });
                html += '</ul>';
            }
            html += '</div>';
        }

        // Producto Padre (si es variación)
        if (d.parent) {
            html += `<div style="margin-bottom:20px; padding:12px; background:#f5f5f5; border-left:4px solid #0073aa; border-radius:0 4px 4px 0;">
                <h4 style="margin:0 0 8px 0;">Producto Padre</h4>
                <p style="margin:4px 0;"><strong>${esc(d.parent.name)}</strong></p>
                <p style="margin:4px 0; color:#666;"><code>${esc(d.parent.sku || 'Sin SKU')}</code> · Woo ID ${esc(d.parent.id)}</p>
            </div>`;
        }

        // Variaciones Hermanas
        if (d.siblings && d.siblings.length > 0) {
            html += '<div style="margin-bottom:20px;">';
            html += '<h4 style="margin:0 0 10px 0;">Variaciones Hermanas</h4>';
            html += '<div style="border:1px solid #ddd; border-radius:4px; max-height:300px; overflow-y:auto; background:#fff;">';
            d.siblings.forEach(sibling => {
                const hasLocal = !!sibling.has_local_sku;
                const badgeBg = hasLocal ? '#28a745' : '#ccc';
                const badgeColor = hasLocal ? '#fff' : '#333';
                const badgeText = hasLocal ? ('SKU Local: ' + esc(sibling.sku_local)) : 'Sin SKU Local';
                const clickable = sibling.producto_base_id > 0;
                const cursor = clickable ? 'pointer' : 'default';
                const clickAttr = clickable
                    ? `onclick="window.portalProducts.openSibling(${sibling.producto_base_id})"`
                    : '';

                html += `<div class="sibling-row" ${clickAttr} onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='white'" style="padding:10px; border-bottom:1px solid #eee; cursor:${cursor};">
                    <strong>${esc(sibling.name)}</strong><br>
                    <small style="color:#666;">${esc(sibling.attributes_text || '')}</small><br>
                    <small style="color:#999;">SKU Online: <code>${esc(sibling.sku_online || '-')}</code></small><br>
                    <span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:3px;font-size:11px;background:${badgeBg};color:${badgeColor};">${badgeText}</span>
                </div>`;
            });
            html += '</div></div>';
        }

        $('#online-details').html(html);
        $('#online-price-current').text(formatMoney(priceSafe));
        $('#btn-edit-online-price').off('click').on('click', () => $('#online-price-editor').show());
    }

    function openSiblingProduct(baseId) {
        if (!baseId || baseId <= 0) return;
        openDetail(baseId);
    }

    function searchWooProducts() {
        const query = $('#woo-search').val().trim();
        if (!query) {
            $('#woo-results').hide();
            return;
        }

        post('riverso_products_search_woo', { s: query, limit: 10, filter: 'solo_online' }).done(function(r) {
            if (r.success) {
                const products = r.data.results || r.data.products || [];
                let html = '';
                products.forEach(prod => {
                    const name = prod.name || prod.nombre || '';
                    const sku = prod.sku || '';
                    const typeLabel = {'simple': 'Simple', 'variable': 'Variable', 'variation': 'Variación'}[prod.type] || prod.type || '';
                    const parentHint = (prod.type === 'variation' && prod.parent_id)
                        ? ` | Padre: ${prod.parent_id}`
                        : '';
                    html += `<div style="padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;" class="woo-result-item" data-id="${prod.id}" data-name="${esc(name)}">
                        <strong>${esc(name)}</strong><br>
                        <small style="color: #666;">ID: ${prod.id}${parentHint} | SKU: ${esc(sku || '(sin SKU)')} | Tipo: ${esc(typeLabel)}</small>
                    </div>`;
                });
                $('#woo-results').html(html || '<div style="padding:10px;color:#999;">Sin resultados (solo Online sin Local completo)</div>').show();
            }
        });
    }

    function loadCategoryTree(wooId) {
        if (!wooId) {
            $('#online-categories-tree').html('<p style="color:#999;">Sin producto WooCommerce asignado</p>');
            $('#online-categories-suggested-banner').hide();
            $('#online-categories-task-panel').hide();
            $('#online-categories-save').hide();
            return;
        }

        const catTask = ((currentProduct && currentProduct.tasks) || []).find(t => t.tipo === 'validar_categoria' && t.estado !== 'completada');
        const suggestedCat = catTask ? (catTask.datos_extra || null) : null;

        if (suggestedCat && suggestedCat.categoria) {
            let suggestedText = suggestedCat.categoria;
            if (suggestedCat.subcategoria) {
                suggestedText += ' > ' + suggestedCat.subcategoria;
            }
            $('#online-categories-suggested-text').text(suggestedText);
            $('#online-categories-suggested-banner').show();
        } else {
            $('#online-categories-suggested-banner').hide();
        }

        if (catTask) {
            let suggestedText = (suggestedCat && suggestedCat.categoria) ? suggestedCat.categoria : 'Sin categoría';
            if (suggestedCat && suggestedCat.subcategoria) {
                suggestedText += ' > ' + suggestedCat.subcategoria;
            }
            $('#online-categories-task-suggested').text('Categoría sugerida: ' + suggestedText);
            $('#online-categories-task-panel').data('task_id', catTask.id).show();
        } else {
            $('#online-categories-task-panel').hide();
        }

        $('#online-categories-tree').html('<p style="color:#666; text-align:center;">Cargando categorías...</p>');

        post('riverso_products_get_category_tree', { parent_id: 0 }).done(function(r) {
            if (!r.success) {
                $('#online-categories-tree').html('<p style="color:#dc3545;">Error: ' + esc((r.data && r.data.message) || 'desconocido') + '</p>');
                return;
            }

            post('riverso_products_get_product_categories', {
                woocommerce_product_id: wooId
            }).done(function(r2) {
                const currentCats = r2.success ? (r2.data.current_categories || []) : [];
                renderCategoryTreeWithCheckboxes(r.data.tree || [], currentCats, suggestedCat);
                $('#online-categories-save').show();
                populateCategoryParentDropdown(r.data.tree || []);
            });
        });
    }

    function collectCategoryExpandIds(categories, selectedIds, suggestedCat) {
        const selected = new Set((selectedIds || []).map(id => parseInt(id, 10)).filter(id => id > 0));
        const expandIds = new Set();
        const sugCat = ((suggestedCat && suggestedCat.categoria) || '').toLowerCase().trim();
        const sugSub = ((suggestedCat && suggestedCat.subcategoria) || '').toLowerCase().trim();

        const walk = (cats, ancestors) => {
            (cats || []).forEach(cat => {
                const id = parseInt(cat.id, 10);
                const nameLower = (cat.name || '').toLowerCase().trim();
                const isSelected = selected.has(id);
                const isSuggested = !!(sugCat && (nameLower === sugCat || (sugSub && nameLower === sugSub)));
                if (isSelected || isSuggested) {
                    ancestors.forEach(aid => expandIds.add(aid));
                }
                if (cat.children && cat.children.length) {
                    walk(cat.children, ancestors.concat([id]));
                }
            });
        };
        walk(categories, []);
        return expandIds;
    }

    function renderCategoryTreeWithCheckboxes(categories, selectedIds, suggestedCat) {
        if (!categories || categories.length === 0) {
            $('#online-categories-tree').html('<p style="color:#666;">Sin categorías disponibles</p>');
            return;
        }

        const selected = (selectedIds || []).map(id => parseInt(id, 10));
        const expandIds = collectCategoryExpandIds(categories, selected, suggestedCat);

        const renderTree = (cats, level) => {
            return cats.map(cat => {
                const catId = parseInt(cat.id, 10);
                const checked = selected.includes(catId);
                let isSuggested = false;
                let badge = '';

                if (suggestedCat) {
                    const catNameLower = (cat.name || '').toLowerCase().trim();
                    const suggestedCatLower = (suggestedCat.categoria || '').toLowerCase().trim();
                    const suggestedSubLower = (suggestedCat.subcategoria || '').toLowerCase().trim();
                    if (catNameLower === suggestedCatLower || (suggestedSubLower && catNameLower === suggestedSubLower)) {
                        isSuggested = true;
                        badge = ' <span style="background:#28a745; color:white; font-size:11px; padding:2px 6px; border-radius:3px; margin-left:6px;">Sugerido</span>';
                    }
                }

                const shouldBeChecked = checked || isSuggested;
                const hasChildren = !!(cat.children && cat.children.length > 0);
                const isExpanded = hasChildren && expandIds.has(catId);
                const childrenHtml = hasChildren ? renderTree(cat.children, level + 1) : '';
                const toggleHtml = hasChildren
                    ? `<button type="button" class="cat-branch-toggle" aria-expanded="${isExpanded ? 'true' : 'false'}" title="${isExpanded ? 'Ocultar rama' : 'Mostrar rama'}">${isExpanded ? '▼' : '▶'}</button>`
                    : '<span class="cat-branch-spacer"></span>';

                return `<div class="cat-tree-node" data-term-id="${catId}">
                    <div class="cat-tree-row" style="margin-left:${level * 16}px;">
                        ${toggleHtml}
                        <label>
                            <input type="checkbox" class="category-checkbox" value="${catId}" ${shouldBeChecked ? 'checked' : ''} data-category-id="${catId}">
                            <span style="margin-left:6px;">${esc(cat.name)}${badge} <small style="color:#999;">(${cat.count || 0})</small>
                                ${canManageCategories ? `<button type="button" class="category-edit-btn" data-term-id="${catId}">Editar</button>` : ''}
                            </span>
                        </label>
                    </div>
                    ${hasChildren ? `<div class="cat-children" style="display:${isExpanded ? 'block' : 'none'};">${childrenHtml}</div>` : ''}
                </div>`;
            }).join('');
        };

        $('#online-categories-tree').html(renderTree(categories, 0) || '<p style="color:#666;">Sin categorías</p>');
    }

    function populateCategoryParentDropdown(categories) {
        const parentSelect = $('#online-categories-new-parent');
        parentSelect.find('option:not(:first)').remove();
        const addCategories = (cats, prefix) => {
            (cats || []).forEach(cat => {
                parentSelect.append(`<option value="${cat.id}">${esc((prefix || '') + cat.name)}</option>`);
                if (cat.children && cat.children.length > 0) {
                    addCategories(cat.children, (prefix || '') + '— ');
                }
            });
        };
        addCategories(categories, '');
    }

    function renderSupplierCodes(p) {
        const codes = p.proveedores || p.supplier_codes || [];
        if (!codes.length) {
            $('#suppliers-list').html('<p style="color: #999;">Sin códigos proveedor asignados</p>');
            return;
        }
        let html = '<h4>Códigos Asignados</h4><table style="width: 100%; font-size: 13px; border-collapse: collapse;">';
        codes.forEach(code => {
            const id = code.id || code.pp_id || 0;
            const nombre = code.proveedor_nombre || code.proveedor || code.nombre || '-';
            const codigo = code.codigo_proveedor || code.codigo || code.supplier_code || '-';
            html += `<tr style="border-bottom: 1px solid #eee;"><td style="padding: 8px;">${esc(nombre)}</td><td style="padding: 8px;"><code>${esc(codigo)}</code></td></tr>`;
        });
        html += '</table>';
        $('#suppliers-list').html(html);
    }

    function renderBarcodes(p) {
        if (!p.barcodes || p.barcodes.length === 0) {
            $('#barcodes-list').html('<p style="color: #999;">Sin códigos de barra registrados</p>');
            return;
        }
        const isLegacyBarcode = (barcode) => {
            const origen = String(barcode.origen_datos || barcode.origen || '').toLowerCase();
            const migrado = String(barcode.migrado_de_tabla || '').toLowerCase();
            return origen.includes('legacy') || migrado !== '' || !!barcode.legacy_ref;
        };
        const barcodePriority = (barcode) => {
            if ((barcode.estado || '') === 'verificado') return 3;
            if (isLegacyBarcode(barcode)) return 2;
            return 1;
        };
        const groupedBarcodes = [];
        const groupedByCode = new Map();
        (p.barcodes || []).forEach(barcode => {
            const codigo = String(barcode.codigo || '').trim();
            if (!codigo) return;
            const existing = groupedByCode.get(codigo);
            if (!existing) {
                groupedByCode.set(codigo, barcode);
                groupedBarcodes.push(barcode);
                return;
            }
            if (barcodePriority(barcode) > barcodePriority(existing)) {
                const index = groupedBarcodes.indexOf(existing);
                if (index >= 0) groupedBarcodes[index] = barcode;
                groupedByCode.set(codigo, barcode);
            }
        });
        let html = '<h4>Códigos de Barra</h4><table style="width: 100%; font-size: 13px; border-collapse: collapse;">';
        groupedBarcodes.forEach(barcode => {
            const isLegacy = isLegacyBarcode(barcode);
            const tipo = isLegacy ? 'legacy' : (barcode.tipo || 'ean13');
            const unidad = barcode.unidad_medida || barcode.unidad || 'unidad';
            let actions = '';
            if (isLegacy) {
                actions = `<button class="btn-small success barcode-accept-legacy-btn" data-id="${barcode.id}" style="margin-right: 6px;">Aceptar</button>` +
                    `<button class="btn-small danger barcode-reject-legacy-btn" data-id="${barcode.id}">Rechazar</button>`;
            } else {
                actions = `<button class="btn-small barcode-edit-btn" data-id="${barcode.id}" style="margin-right: 6px;">Editar</button>` +
                    `<button class="btn-small danger barcode-remove-btn" data-id="${barcode.id}">✕ Quitar</button>`;
            }
            html += `<tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 8px;"><code>${esc(barcode.codigo)}</code></td>
                <td style="padding: 8px;">${esc(tipo)} - ${esc(barcode.cantidad || 1)} ${esc(unidad)}</td>
                <td style="padding: 8px; text-align: right;">${actions}</td>
            </tr>`;
        });
        html += '</table>';
        $('#barcodes-list').html(html);
    }

    function renderTasks(tasks) {
        const taskActionMap = {
            'crear_contraparte_online': { button: 'Ir a Online', tab: 'online' },
            'crear_contraparte_local': { button: 'Asignar SKU Local', action: 'editLocal' },
            'relacionar_producto_proveedor': { button: 'Ir a Códigos', tab: 'suppliers' },
            'confirmar_codigo_proveedor': { button: 'Ir a Códigos', tab: 'suppliers' },
            'confirmar_relacion_online': { button: 'Ir a Online', tab: 'online' },
            'confirmar_estructura_atributos': { button: 'Ver Atributos', tab: 'online' },
            'barcode_faltante': { button: 'Ir a Barcodes', tab: 'barcodes' },
            'confirmar_barcode_legacy': { button: 'Ir a Barcodes', tab: 'barcodes' },
            'codigo_faltante': { button: 'Ir a Códigos', tab: 'suppliers' },
            'autorizar_publicacion': { button: 'Autorizar', tab: 'online' },
            'validar_categoria': { button: 'Ver Online', tab: 'online' },
            'preguntar_familia': { buttons: 'familyAsk' },
            'asignar_familia': { button: 'Ir a Familia', tab: 'local', focusEdit: true },
        };

        const pending = (tasks || []).filter(t => t.estado !== 'completada');
        if (!pending.length) {
            $('#tasks-list').html('');
            $('#tasks-empty').show();
            return;
        }
        $('#tasks-empty').hide();
        let html = pending.map(t => {
            let actionHtml = '';
            const action = taskActionMap[t.tipo];
            if (action) {
                if (action.buttons === 'familyAsk') {
                    actionHtml = `<button class="btn-small success task-family-yes">Sí, necesita</button>
                        <button class="btn-small task-family-no" style="margin-left:6px;">No, queda solo</button>`;
                } else if (action.tab) {
                    actionHtml = `<button class="btn-small task-goto" data-tab="${action.tab}" data-focus-edit="${action.focusEdit ? '1' : ''}">${action.button}</button>`;
                } else if (action.action === 'editLocal') {
                    actionHtml = '<button class="btn-small task-edit-local">Asignar SKU Local</button>';
                }
            } else if (t.target_url) {
                actionHtml = `<button class="btn-small task-open-external" data-url="${esc(t.target_url)}">Abrir →</button>`;
            }
            return `<div class="task-item">
                <strong>${esc(t.titulo)}</strong>
                <br><small>${esc(t.tipo)} | ${esc(t.estado)} | Prioridad: ${esc(t.prioridad || '-')}</small>
                ${actionHtml ? '<div style="margin-top:6px;">' + actionHtml + '</div>' : ''}
            </div>`;
        }).join('');
        $('#tasks-list').html(html).show();
    }

    function loadFamiliesDropdown() {
        post('riverso_families_list', { limit: 1000 }).done(function(r) {
            if (r.success && r.data.items) {
                const select = $('#familia-select');
                select.html('<option value="">— Sin familia —</option>');
                r.data.items.forEach(fam => {
                    select.append(`<option value="${fam.id}">${esc(fam.nombre)}</option>`);
                });
                if (currentProduct.familia) {
                    select.val(currentProduct.familia.id);
                }
            }
        });
    }

    function loadFamilyTree(p) {
        post('riverso_families_tree', { producto_id: p.id }).done(function(r) {
            if (r.success) {
                const list = r.data.tree || r.data.families || [];
                let html = renderFamilyTreeHTML(list);
                $('#family-tree').html(html || '<p style="color: #999;">Sin familias</p>');
            }
        });
    }

    function renderFamilyTreeHTML(families) {
        if (!families || families.length === 0) return '';
        let html = '';
        families.forEach(fam => {
            const stock = (fam.stock_unidades !== undefined && fam.stock_unidades !== null)
                ? Number(fam.stock_unidades).toLocaleString('es-CL') + ' u'
                : '—';
            const members = fam.members || fam.miembros || fam.children || [];
            html += `<div style="margin-bottom: 12px; padding: 12px; background: white; border-radius: 4px; border-left: 4px solid #2196f3;">
                <strong>${esc(fam.nombre)}</strong> <small style="color: #999;">(${esc(fam.codigo_grupo || fam.codigo || '')})</small><br>
                <small style="color: #666;">Tipo: ${esc(fam.tipo_sustitucion || '')} · Stock familia: ${stock}</small>`;
            if (members.length > 0) {
                html += '<div style="margin-top: 8px; margin-left: 12px; border-left: 2px solid #e0e0e0; padding-left: 12px;">';
                members.forEach(member => {
                    html += `<div style="margin: 4px 0;"><code>${esc(member.canonical_sku || member.sku || '')}</code> - ${esc(member.nombre_canonico || member.nombre || '')}</div>`;
                });
                html += '</div>';
            }
            html += '</div>';
        });
        return html;
    }

    function enterEditMode() {
        isEditMode = true;
        originalValues = {
            sku: $('#local-sku-view').text(),
            name: $('#local-name-view').text(),
            unit: $('#local-unit-view').text(),
            decimal: $('#local-decimal-edit').is(':checked'),
            ean: $('#local-ean-edit').is(':checked'),
            stock: $('#local-stock-edit').is(':checked')
        };

        // Show edit fields
        $('#local-sku-view').hide();
        $('#local-sku-edit').val(originalValues.sku).show();
        $('#local-name-view').hide();
        $('#local-name-edit').val(originalValues.name).show();
        $('#local-unit-view').hide();
        $('#local-unit-edit').val(originalValues.unit).show();
        $('#local-decimal-view').hide();
        $('#local-decimal-edit').prop('checked', originalValues.decimal).show();
        $('#local-ean-view').hide();
        $('#local-ean-edit').prop('checked', originalValues.ean).show();
        $('#local-stock-view').hide();
        $('#local-stock-edit').prop('checked', originalValues.stock).show();

        // Toggle buttons
        $('#btn-detail-edit').hide();
        $('#btn-detail-save').show();
        $('#btn-detail-cancel').show();

        // Show edit sections
        $('#local-precio-view').hide();
        $('#local-precio-edit').show();
        $('#local-familia-view').hide();
        $('#local-familia-edit').show();
        $('#local-image-view').hide();
        $('#local-image-edit').show();
    }

    function exitEditMode() {
        isEditMode = false;
        $('#local-sku-view').show();
        $('#local-sku-edit').hide();
        $('#local-name-view').show();
        $('#local-name-edit').hide();
        $('#local-unit-view').show();
        $('#local-unit-edit').hide();
        $('#local-decimal-view').show();
        $('#local-decimal-edit').hide();
        $('#local-ean-view').show();
        $('#local-ean-edit').hide();
        $('#local-stock-view').show();
        $('#local-stock-edit').hide();

        if (canManage) {
            $('#btn-detail-edit').show();
        }
        $('#btn-detail-save').hide();
        $('#btn-detail-cancel').hide();

        $('#local-precio-view').show();
        $('#local-precio-edit').hide();
        $('#local-familia-view').show();
        $('#local-familia-edit').hide();
        $('#local-image-view').show();
        $('#local-image-edit').hide();
    }

    // Supplier codes
    let supplierSearchTimeout;
    $('#supplier-code-search').on('input', function() {
        clearTimeout(supplierSearchTimeout);
        const query = $(this).val().trim();
        if (!query) {
            $('#supplier-search-results').hide();
            return;
        }
        supplierSearchTimeout = setTimeout(function() {
            post('riverso_products_search_supplier_codes', { search: query, limit: 10 }).done(function(r) {
                if (r.success && r.data.codes) {
                    let html = '';
                    r.data.codes.forEach(code => {
                        html += `<div style="padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;" onclick="window.portalProducts.selectSupplierCode(${code.id}, '${esc(code.codigo)}')">
                            <strong>${esc(code.proveedor)}</strong><br>
                            <small style="color: #666;">Código: ${esc(code.codigo)}</small>
                        </div>`;
                    });
                    $('#supplier-search-results').html(html).show();
                }
            });
        }, 300);
    });

    if (canManage) {
        $('#supplier-link-btn').click(function() {
            const supplierId = $('#supplier-id-select').val();
            const supplierCode = $('#supplier-code-select').val();
            const reason = $('#supplier-audit-reason').val().trim();

            if (!supplierId || !supplierCode) {
                alert('Seleccione un código proveedor');
                return;
            }

            post('riverso_products_assign_supplier_code', {
                producto_id: currentProduct.id,
                supplier_id: supplierId,
                supplier_code: supplierCode,
                audit_reason: reason
            }).done(function(r) {
                if (r.success) {
                    alert('Código asignado');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        $('#barcode-type').change(function() {
            $('#barcode-supplier-section').toggle($(this).val() === 'supplier');
        });
        $('#barcode-type').trigger('change');

        $('#barcode-add-btn').click(function() {
            const type = $('#barcode-type').val();
            const code = $('#barcode-new').val().trim();
            const cantidad = parseFloat($('#barcode-cantidad').val()) || 1;
            const unidad = $('#barcode-unidad').val();
            const origen = $('#barcode-origen').val();
            const reason = $('#barcode-audit-reason').val().trim();

            if (!code) {
                alert('Ingrese un código');
                return;
            }

            post('riverso_products_add_barcode', {
                product_id: currentProduct.id,
                tipo: type,
                barcode: code,
                proveedor_id: type === 'supplier' ? $('#barcode-proveedor').val() : null,
                cantidad: cantidad,
                unidad_medida: unidad,
                origen_datos: origen,
                audit_reason: reason
            }).done(function(r) {
                if (r.success) {
                    alert('Barcode agregado');
                    $('#barcode-new').val('');
                    $('#barcode-cantidad').val('1');
                    $('#barcode-audit-reason').val('');
                    $('#barcode-type').val('supplier').trigger('change');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        // WooCommerce link/create — siempre vía merge si hay conflicto
        $('#woo-search').on('input', () => searchWooProducts());

        // Modal rico de merge (Portal)
        function openMergeModalPortal(merge) {
            return new Promise((resolve) => {
                if (!merge) { resolve(false); return; }
                const src = merge.source || {};
                const tgt = merge.target || {};
                const woo = merge.woo || {};
                const codes = (merge.codes_to_transfer || []).map(c => c.codigo_proveedor).filter(Boolean);
                let codesHTML = '';
                if (codes.length) {
                    codesHTML = '<div style="margin-top:10px;"><strong>Códigos a heredar:</strong><br>' + codes.map(c => '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-right:4px;">' + c + '</code>').join(' ') + '</div>';
                }
                let warningsHTML = '';
                (merge.warnings || []).forEach(w => {
                    const sev = w.severity || 'info';
                    const color = sev === 'error' ? '#f8d7da' : (sev === 'warning' ? '#fff3cd' : '#d1ecf1');
                    const borderColor = sev === 'error' ? '#dc3545' : (sev === 'warning' ? '#ffc107' : '#17a2b8');
                    warningsHTML += '<div style="background:' + color + ';border-left:4px solid ' + borderColor + ';padding:10px;margin-top:8px;border-radius:2px;font-size:13px;">' + esc(w.message) + '</div>';
                });
                const blocked = !!merge.block_merge;
                const confirmBtn = blocked
                    ? '<button type="button" class="btn-small" disabled style="opacity:0.5;cursor:not-allowed;">Merge bloqueado</button>'
                    : '<button type="button" id="merge-portal-confirm" class="btn-small success" style="cursor:pointer;">Confirmar</button>';
                const html = `
<div id="merge-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.3);padding:30px;max-width:550px;width:90%;max-height:80vh;overflow-y:auto;">
    <h2 style="margin:0 0 20px 0;color:#1d2327;">` + (blocked ? 'Merge bloqueado' : 'Confirmar Merge') + `</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
      <div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 8px 0;color:#d32f2f;font-size:13px;">Origen</h4>
        <div style="font-size:12px;"><strong>#` + merge.source_id + `</strong><br>SKU: ` + (src.canonical_sku || '—') + `<br>` + (src.nombre_canonico ? esc(src.nombre_canonico.substring(0, 40)) : 'Sin nombre') + `</div>
      </div>
      <div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 8px 0;color:#1976d2;font-size:13px;">Destino</h4>
        <div style="font-size:12px;"><strong>#` + merge.target_id + `</strong><br>SKU: ` + (tgt.canonical_sku || '—') + `<br>` + (tgt.nombre_canonico ? esc(tgt.nombre_canonico.substring(0, 40)) : 'Sin nombre') + `</div>
      </div>
    </div>
    <div style="background:#e8f5e9;border:1px solid #4caf50;padding:10px;border-radius:6px;margin-bottom:15px;font-size:12px;"><strong style="color:#2e7d32;">SKU Online: </strong>` + esc(woo.sku || 'N/A') + `</div>
    ` + codesHTML + `
    ` + warningsHTML + `
    <div style="margin-top:15px;border-top:1px solid #ddd;padding-top:12px;display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" id="merge-portal-cancel" class="btn-small" style="cursor:pointer;">` + (blocked ? 'Cerrar' : 'Cancelar') + `</button>
      ` + confirmBtn + `
    </div>
  </div>
</div>
                `.trim();
                $('body').append(html);
                $('#merge-portal-cancel').on('click', function(){ $('#merge-modal-overlay').remove(); resolve(false); });
                $('#merge-portal-confirm').on('click', function(){ $('#merge-modal-overlay').remove(); resolve(true); });
                $(document).on('keydown.merge-modal', function(e){
                    if (e.key === 'Escape') { $('#merge-modal-overlay').remove(); resolve(false); $(document).off('keydown.merge-modal'); }
                });
            });
        }

        $('#online-link-btn').click(function() {
            const wooId = $('#woo-selected-id').val();
            if (!wooId) {
                alert('Seleccione un producto WooCommerce');
                return;
            }

            const tryLink = (confirm_merge) => {
                post('riverso_products_set_online', {
                    product_id: currentProduct.id,
                    woo_id: wooId,
                    confirm_merge: confirm_merge ? 1 : 0
                }).done(function(r) {
                    if (r.success) {
                        alert(r.data.message || 'Producto vinculado');
                        openDetail(currentProduct.id);
                        return;
                    }
                    if (r.data && r.data.needs_merge) {
                        openMergeModalPortal(r.data.merge).then(confirmed => {
                            if (!confirmed) return;
                            tryLink(true);
                        });
                        return;
                    }
                    alert('Error: ' + (r.data && r.data.message ? r.data.message : 'No se pudo vincular'));
                });
            };
            tryLink(false);
        });

        function openUnlinkSplitModalPortal(preview) {
            return new Promise((resolve) => {
                if (!preview) { resolve(null); return; }

                const local = preview.local || {};
                const woo = preview.woo || {};
                const codes = preview.codes || [];

                let codesRows = '';
                if (!codes.length) {
                    codesRows = '<p style="color:#666;font-size:13px;margin:8px 0 0 0;">No hay códigos de proveedor/catálogo. Solo se creará el Solo Online con el Woo.</p>';
                } else {
                    codesRows = '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">'
                        + '<thead><tr style="text-align:left;border-bottom:1px solid #ddd;">'
                        + '<th style="padding:6px;">Código</th><th style="padding:6px;">Fuente</th>'
                        + '<th style="padding:6px;">Local</th><th style="padding:6px;">Online</th></tr></thead><tbody>';
                    codes.forEach(c => {
                        const sug = c.suggested_destination === 'online' ? 'online' : 'local';
                        const label = esc(c.origen_label || (c.is_catalog ? 'Catálogo' : 'Proveedor'));
                        const nameHint = c.catalogo_nombre
                            ? esc(c.catalogo_nombre)
                            : esc(c.proveedor_nombre || '');
                        codesRows += '<tr style="border-bottom:1px solid #eee;">'
                            + '<td style="padding:8px;"><code>' + esc(c.codigo_proveedor || '') + '</code>'
                            + (nameHint ? '<br><small style="color:#666;">' + nameHint + '</small>' : '')
                            + (c.needs_confirm ? ' <span style="background:#fff3cd;color:#856404;padding:1px 6px;border-radius:3px;font-size:11px;">Por confirmar</span>' : '')
                            + '</td>'
                            + '<td style="padding:8px;">' + label + '</td>'
                            + '<td style="padding:8px;"><label><input type="radio" name="unlink-code-' + c.id + '" value="local"' + (sug === 'local' ? ' checked' : '') + '> Local</label></td>'
                            + '<td style="padding:8px;"><label><input type="radio" name="unlink-code-' + c.id + '" value="online"' + (sug === 'online' ? ' checked' : '') + '> Online</label></td>'
                            + '</tr>';
                    });
                    codesRows += '</tbody></table>'
                        + '<p style="font-size:12px;color:#666;margin:10px 0 0 0;">La tarea de confirmar viaja con el código.</p>';
                }

                const wooLabel = (woo.variation_id
                    ? ('Padre ' + (woo.product_id || '?') + ' · Var ' + woo.variation_id)
                    : (woo.product_id || '-'));

                const html = `
<div id="unlink-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.3);padding:28px;max-width:640px;width:92%;max-height:80vh;overflow-y:auto;">
    <h2 style="margin:0 0 16px 0;">Desvincular WooCommerce</h2>
    <p style="margin:0 0 14px 0;font-size:13px;color:#555;">Se creará un producto Solo Online con el Woo. Elige dónde queda cada código.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
      <div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 8px 0;color:#1976d2;">Local (se queda)</h4>
        <div style="font-size:12px;line-height:1.6;">
          <div><strong>ID:</strong> #` + (local.id || '?') + `</div>
          <div><strong>SKU:</strong> ` + esc(local.canonical_sku || '—') + `</div>
          <div><strong>Nombre:</strong> ` + esc(local.nombre_canonico || '—') + `</div>
        </div>
      </div>
      <div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 8px 0;color:#2e7d32;">Online (nuevo stub)</h4>
        <div style="font-size:12px;line-height:1.6;">
          <div><strong>Woo:</strong> ` + esc(String(wooLabel)) + `</div>
          <div><strong>SKU Online:</strong> ` + esc(woo.sku || '—') + `</div>
          <div><strong>Nombre:</strong> ` + esc(woo.name || '—') + `</div>
        </div>
      </div>
    </div>
    <div>` + codesRows + `</div>
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid #ddd;display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" id="unlink-modal-cancel" class="btn-small">Cancelar</button>
      <button type="button" id="unlink-modal-confirm" class="btn-small success">Confirmar desvínculo</button>
    </div>
  </div>
</div>`;

                $('body').append(html);

                const finish = (result) => {
                    $('#unlink-modal-overlay').remove();
                    $(document).off('keydown.unlink-modal');
                    resolve(result);
                };

                $('#unlink-modal-cancel').on('click', () => finish(null));
                $('#unlink-modal-confirm').on('click', function() {
                    const destinations = {};
                    codes.forEach(c => {
                        const val = $('input[name="unlink-code-' + c.id + '"]:checked').val() || 'local';
                        destinations[c.id] = val;
                    });
                    finish(destinations);
                });
                $(document).on('keydown.unlink-modal', function(e) {
                    if (e.key === 'Escape') finish(null);
                });
                $('#unlink-modal-overlay').on('click', function(e) {
                    if (e.target === this) finish(null);
                });
            });
        }

        $('#online-unlink-btn').click(function() {
            if (!currentProduct || !currentProduct.id) return;

            post('riverso_products_preview_unlink', {
                product_id: currentProduct.id
            }).done(function(r) {
                if (!r.success) {
                    alert('Error: ' + ((r.data && r.data.message) ? r.data.message : 'No se pudo preparar el desvínculo'));
                    return;
                }
                openUnlinkSplitModalPortal(r.data.preview).then(function(destinations) {
                    if (destinations === null) return;
                    post('riverso_products_unlink_online', {
                        product_id: currentProduct.id,
                        code_destinations: JSON.stringify(destinations)
                    }).done(function(r2) {
                        if (r2.success) {
                            alert(r2.data.message || 'Producto desvinculado');
                            openDetail(currentProduct.id);
                            loadProducts(currentOffset);
                        } else {
                            alert('Error: ' + ((r2.data && r2.data.message) ? r2.data.message : 'No se pudo desvincular'));
                        }
                    });
                });
            });
        });

        $('#online-create-btn').click(function() {
            const name = currentProduct.nombre_canonico;
            if (!name) {
                alert('El producto debe tener nombre definido');
                return;
            }

            post('riverso_products_create_woo', {
                producto_id: currentProduct.id,
                nombre: name
            }).done(function(r) {
                if (r.success) {
                    alert('Producto WooCommerce creado');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        $('#online-price-save').click(function() {
            const newPrice = parseFloat($('#online-price-new').val());
            if (isNaN(newPrice)) {
                alert('Ingrese un precio válido');
                return;
            }

            post('riverso_products_set_online_price', {
                producto_id: currentProduct.id,
                precio: newPrice,
                sync_woo: $('#online-sync-to-woo').is(':checked') ? 1 : 0
            }).done(function(r) {
                if (r.success) {
                    alert('Precio actualizado');
                    $('#online-price-editor').hide();
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        $('#online-price-cancel').click(() => $('#online-price-editor').hide());

        // Categories (análogo wp-admin)
        $('#online-categories-save').off('click').on('click', function() {
            if (!currentProduct || !currentProduct.woocommerce_product_id) {
                alert('Sin producto WooCommerce');
                return;
            }
            const selectedCats = [];
            $('#online-categories-tree .category-checkbox:checked').each(function() {
                selectedCats.push(parseInt($(this).val(), 10));
            });
            post('riverso_products_set_product_categories', {
                woocommerce_product_id: currentProduct.woocommerce_product_id,
                category_ids: selectedCats
            }).done(function(r) {
                if (!r.success) {
                    alert('Error: ' + ((r.data && r.data.message) || 'desconocido'));
                    return;
                }
                alert('Categorías guardadas exitosamente');
            });
        });

        $('#online-categories-add-new').off('click').on('click', function() {
            $('#online-categories-add-form').toggle();
        });

        $('#online-categories-cancel-btn').off('click').on('click', function() {
            $('#online-categories-add-form').hide();
            $('#online-categories-new-name').val('');
        });

        $('#online-categories-create-btn').off('click').on('click', function() {
            const name = $('#online-categories-new-name').val().trim();
            const parent_id = parseInt($('#online-categories-new-parent').val() || 0, 10);
            if (!name) {
                alert('Ingrese el nombre de la categoría');
                return;
            }
            post('riverso_products_create_category', {
                name: name,
                parent_id: parent_id
            }).done(function(r) {
                if (!r.success) {
                    alert('Error: ' + ((r.data && r.data.message) || 'desconocido'));
                    return;
                }
                alert('Categoría creada exitosamente');
                $('#online-categories-new-name').val('');
                $('#online-categories-add-form').hide();
                if (currentProduct && currentProduct.woocommerce_product_id) {
                    loadCategoryTree(currentProduct.woocommerce_product_id);
                }
            });
        });

        $('#online-categories-accept-task').off('click').on('click', function() {
            const taskId = $('#online-categories-task-panel').data('task_id');
            if (!taskId || !currentProduct || !currentProduct.woocommerce_product_id) {
                alert('No hay tarea de categoría pendiente');
                return;
            }
            const selectedCats = [];
            $('#online-categories-tree .category-checkbox:checked').each(function() {
                selectedCats.push(parseInt($(this).val(), 10));
            });
            post('riverso_products_set_product_categories', {
                woocommerce_product_id: currentProduct.woocommerce_product_id,
                category_ids: selectedCats
            }).done(function(r) {
                if (!r.success) {
                    alert('Error al guardar categorías: ' + ((r.data && r.data.message) || ''));
                    return;
                }
                post('riverso_products_complete_task', { tarea_id: taskId }).done(function(r2) {
                    if (r2.success) {
                        alert('Categorías aceptadas y tarea completada');
                        $('#online-categories-task-panel').hide();
                        openDetail(currentProduct.id);
                    } else {
                        alert('Error al completar tarea: ' + ((r2.data && r2.data.message) || ''));
                    }
                });
            });
        });

        // Family management
        $('#family-create-btn').click(() => {
            $('#family-create-form').toggle();
        });

        $('#family-cancel-btn').click(() => {
            $('#family-create-form').hide();
        });

        $('#family-save-btn').click(function() {
            const codigo = $('#family-codigo').val().trim();
            const nombre = $('#family-nombre').val().trim();
            const tipo = $('#family-tipo').val();

            if (!nombre) {
                alert('Ingrese el nombre de la familia');
                return;
            }

            post('riverso_families_create', {
                codigo_grupo: codigo,
                nombre: nombre,
                tipo_sustitucion: tipo
            }).done(function(r) {
                if (r.success) {
                    const codigoFinal = (r.data && r.data.family && r.data.family.codigo_grupo) || '';
                    alert('Familia creada' + (codigoFinal ? ' (' + codigoFinal + ')' : ''));
                    $('#family-codigo').val('');
                    $('#family-nombre').val('');
                    $('#family-create-form').hide();
                    loadFamilyTree(currentProduct);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });
    }

    // Event listeners
    $('#products-status, #products-catalog, #products-completeness').change(() => loadProducts(0));
    
    let searchTimeout;
    $('#products-search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => loadProducts(0), 300);
    });
    
    $('#products-reload').click(() => loadProducts(0));
    $('#completeness-help').click(() => $('#help-completeness').toggle());
    $('#help-close').click(() => $('#help-completeness').hide());
    
    // Tooltips de badges: se mantienen abiertos ~3s al salir
    let badgeTooltipTimer = null;
    $(document).on('mouseenter', '.detail-badge', function() {
        clearTimeout(badgeTooltipTimer);
        $('.detail-badge').removeClass('is-open');
        $(this).addClass('is-open');
    });
    $(document).on('mouseleave', '.detail-badge', function() {
        const $badge = $(this);
        clearTimeout(badgeTooltipTimer);
        badgeTooltipTimer = setTimeout(function() {
            $badge.removeClass('is-open');
        }, 3000);
    });
    $(document).on('mouseenter', '.badge-tooltip', function() {
        clearTimeout(badgeTooltipTimer);
        $(this).closest('.detail-badge').addClass('is-open');
    });
    $(document).on('mouseleave', '.badge-tooltip', function() {
        const $badge = $(this).closest('.detail-badge');
        clearTimeout(badgeTooltipTimer);
        badgeTooltipTimer = setTimeout(function() {
            $badge.removeClass('is-open');
        }, 3000);
    });
    // Click en badge también fija el tooltip abierto
    $(document).on('click', '#detail-alerts-badge, #detail-tasks-badge', function(e) {
        if ($(e.target).closest('.alerts-tooltip-item').length) return;
        e.preventDefault();
        clearTimeout(badgeTooltipTimer);
        const $badge = $(this);
        const wasOpen = $badge.hasClass('is-open');
        $('.detail-badge').removeClass('is-open');
        if (!wasOpen) $badge.addClass('is-open');
    });

    $(document).on('click', '#online-categories-tree .cat-branch-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const $children = $btn.closest('.cat-tree-node').children('.cat-children');
        const open = $children.is(':visible');
        if (open) {
            $children.hide();
            $btn.attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
        } else {
            $children.show();
            $btn.attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
        }
    });

    $('#online-categories-expand-all').off('click').on('click', function() {
        $('#online-categories-tree .cat-children').show();
        $('#online-categories-tree .cat-branch-toggle').attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
    });

    $('#online-categories-collapse-all').off('click').on('click', function() {
        $('#online-categories-tree .cat-children').hide();
        $('#online-categories-tree .cat-branch-toggle').attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
    });

    $(document).on('click', '.category-edit-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const termId = $(this).data('term-id');
        const currentName = $(this).closest('label').find('span').clone().children().remove().end().text().trim().split('(')[0].trim();
        const newName = prompt('Nuevo nombre para la categoría:', currentName);
        if (!newName || newName === currentName) return;
        post('riverso_products_rename_category', {
            term_id: termId,
            name: newName
        }).done(function(r) {
            if (!r.success) {
                alert('Error: ' + ((r.data && r.data.message) || ''));
                return;
            }
            if (currentProduct && currentProduct.woocommerce_product_id) {
                loadCategoryTree(currentProduct.woocommerce_product_id);
            }
        });
    });

    $('.detail-tab').click(function(e) {
        e.preventDefault();
        switchDetailTab($(this).data('tab'));
    });

    $(document).on('click', '.products-assign-barcode', function() {
        const $btn = $(this);
        const id = $btn.data('id');
        const sku = $btn.data('sku') || '';
        const codigo = String($btn.closest('td').find('.products-barcode-input').val() || '').trim();
        if (!codigo) {
            alert('Escribe o escanea el código de barra.');
            $btn.closest('td').find('.products-barcode-input').focus();
            return;
        }
        post('riverso_barcode_upsert', {
            codigo: codigo,
            producto_base_id: id,
            sku_local: sku,
            estado: 'verificado',
            cantidad: 1,
            tipo_envase: 'envase',
            create_envase: 1
        }).done(function(r) {
            if (r.success) {
                alert(r.data && r.data.message ? r.data.message : 'Código asignado');
                loadProducts(currentOffset);
            } else {
                alert('Error: ' + ((r.data && r.data.message) || 'no se pudo asignar'));
            }
        }).fail(function() {
            alert('No se pudo asignar el código.');
        });
    });
    $(document).on('keydown', '.products-barcode-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('td').find('.products-assign-barcode').click();
        }
    });

    // Shortcuts desde badge de campos faltantes
    $(document).on('click', '.alerts-tooltip-item', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const action = $(this).data('alert-action');
        if (action === 'edit-sku') {
            switchDetailTab('local');
            if (canManage) {
                enterEditMode();
                setTimeout(() => $('#local-sku-edit').focus().select(), 100);
            }
        } else if (action && String(action).startsWith('tab-')) {
            switchDetailTab(String(action).replace('tab-', ''));
        }
    });

    // Badge tareas → tab Tasks (o sección según tipo)
    $(document).on('click', '.task-tooltip-goto', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const tipo = $(this).data('task-tipo');
        const map = {
            'crear_contraparte_online': 'online',
            'crear_contraparte_local': 'local',
            'relacionar_producto_proveedor': 'suppliers',
            'confirmar_codigo_proveedor': 'suppliers',
            'confirmar_relacion_online': 'online',
            'barcode_faltante': 'barcodes',
            'confirmar_barcode_legacy': 'barcodes',
            'codigo_faltante': 'suppliers',
            'validar_categoria': 'online',
            'autorizar_publicacion': 'online',
            'confirmar_estructura_atributos': 'online',
            'preguntar_familia': 'local',
            'asignar_familia': 'local'
        };
        if (tipo === 'asignar_familia') {
            switchDetailTab('local');
            $('#familia-edit-toggle').trigger('click');
            return;
        }
        if (tipo === 'crear_contraparte_local') {
            switchDetailTab('local');
            if (canManage) {
                enterEditMode();
                setTimeout(() => $('#local-sku-edit').focus().select(), 100);
            }
            return;
        }
        switchDetailTab(map[tipo] || 'tasks');
    });

    $(document).on('click', '.task-goto', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        const focusEdit = $(this).data('focus-edit') === 1 || $(this).data('focus-edit') === '1';
        switchDetailTab(tab);
        if (focusEdit && tab === 'local') {
            $('#familia-edit-toggle').trigger('click');
        }
    });

    $(document).on('click', '.task-edit-local', function(e) {
        e.preventDefault();
        switchDetailTab('local');
        if (canManage) {
            enterEditMode();
            setTimeout(() => $('#local-sku-edit').focus().select(), 100);
        }
    });

    $(document).on('click', '.task-open-external', function(e) {
        e.preventDefault();
        const url = $(this).data('url');
        if (url) window.open(url, '_blank');
    });

    $(document).on('click', '.woo-result-item', function() {
        window.portalProducts.selectWooProduct($(this).data('id'), $(this).data('name'));
    });

    $(document).on('click', '.barcode-remove-btn', function() {
        window.portalProducts.removeBarcode($(this).data('id'));
    });

    $(document).on('click', '.barcode-edit-btn', function() {
        window.portalProducts.editBarcode($(this).data('id'));
    });

    $(document).on('click', '.barcode-accept-legacy-btn', function() {
        window.portalProducts.acceptLegacyBarcode($(this).data('id'));
    });

    $(document).on('click', '.barcode-reject-legacy-btn', function() {
        window.portalProducts.rejectLegacyBarcode($(this).data('id'));
    });

    $('#online-assign-code-btn').on('click', function() {
        switchDetailTab('suppliers');
    });

    // Panel Local vacío: Buscar Local existente
    $('#detail-local-search').on('keyup', function(e){
        const search = $(this).val().trim();
        if (search.length < 2) {
            $('#detail-local-suggestions').html('').hide();
            return;
        }
        post('riverso_products_list', {
            search: search,
            limit: 10,
            status: 'active',
            completeness: 'falta_online'
        }).done(function(r) {
            if (r.success) {
                const items = r.data.items || [];
                let html = '';
                items.forEach(item => {
                    const display = esc(item.canonical_sku || '') + ' - ' + esc(item.nombre_canonico || '');
                    html += '<div style="padding:8px; border-bottom:1px solid #eee; cursor:pointer; font-size:13px;" class="detail-local-option" data-id="' + item.id + '" data-display="' + esc(display) + '">' + display + '</div>';
                });
                $('#detail-local-suggestions').html(html || '<div style="padding:8px; color:#999;">Sin resultados</div>').show();
            }
        });
    });

    $(document).on('click', '.detail-local-option', function(){
        const id = $(this).data('id');
        const display = $(this).data('display');
        $('#detail-local-search').data('selected-id', id);
        $('#detail-local-selected').html(display).show();
        $('#detail-local-suggestions').html('').hide();
        $('#detail-local-adopt-btn').show();
    });

    // Panel Local vacío: Generar nuevo SKU Local
    $('#detail-local-generate-sku').on('click', function(){
        $(this).prop('disabled', true).text('Obteniendo siguiente SKU...');
        post('riverso_products_next_sku', {}).done(function(r) {
            $('#detail-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            if (r.success) {
                const nextSku = r.data.next_sku;
                $('#detail-local-new-sku-input').val(nextSku).prop('readonly', false);
                $('#detail-local-new-sku-preview').show();
                // Limpiar búsqueda
                $('#detail-local-search').val('').data('selected-id', 0);
                $('#detail-local-selected').hide();
                $('#detail-local-suggestions').html('').hide();
                $('#detail-local-adopt-btn').hide();
            } else {
                alert('Error: ' + r.data.message);
            }
        }).fail(function(){
            $('#detail-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            alert('Error al obtener el siguiente SKU');
        });
    });

    // Panel Local vacío: editar SKU generado
    $('#detail-local-new-sku-input').on('change', function(){
        // El valor se puede editar, se guardará junto con el SKU Local
    });

    // Panel Local vacío: Vincular Local existente → merge con preview
    $('#detail-local-adopt-btn').on('click', function(){
        if (!currentProduct) return;
        const targetLocalId = $('#detail-local-search').data('selected-id');
        if (!targetLocalId) {
            alert('Selecciona un Local primero');
            return;
        }

        const tryAdopt = (confirm_merge) => {
            post('riverso_products_adopt_local', {
                source_id: currentProduct.id,
                target_id: targetLocalId,
                confirm_merge: confirm_merge ? 1 : 0
            }).done(function(r){
                if (r.success) {
                    alert(r.data.message);
                    openDetail(r.data.item.id);
                    loadProducts(currentOffset);
                    return;
                }
                if (r.data && r.data.needs_merge) {
                    openMergeModalPortal(r.data.merge).then(confirmed => {
                        if (!confirmed) return;
                        tryAdopt(true);
                    });
                    return;
                }
                alert('Error: ' + (r.data && r.data.message ? r.data.message : 'No se pudo vincular'));
            });
        };
        tryAdopt(false);
    });

    $('#btn-detail-close').click(() => {
        $('#product-detail-panel').hide();
        currentProduct = null;
    });

    if (canManage) {
        $('#btn-detail-edit').click(() => enterEditMode());
        $('#btn-detail-cancel').click(() => exitEditMode());
        $('#btn-detail-save').click(function() {
            post('riverso_products_update', {
                producto_id: currentProduct.id,
                canonical_sku: $('#local-sku-edit').val(),
                nombre_canonico: $('#local-name-edit').val(),
                unidad_base: $('#local-unit-edit').val(),
                permite_decimal: $('#local-decimal-edit').is(':checked') ? 1 : 0,
                permite_ean13: $('#local-ean-edit').is(':checked') ? 1 : 0,
                stock_abierto: $('#local-stock-edit').is(':checked') ? 1 : 0
            }).done(function(r) {
                if (r.success) {
                    alert('Producto actualizado');
                    exitEditMode();
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        $('#precio-save-btn').click(function() {
            post('riverso_products_set_local_price', {
                producto_id: currentProduct.id,
                precio_asignado: $('#precio-p-asignado').val()
            }).done(function(r) {
                if (r.success) {
                    alert('Precio actualizado');
                    openDetail(currentProduct.id);
                }
            });
        });

        $('#familia-edit-toggle').click(() => {
            $('#local-familia-view').hide();
            $('#local-familia-edit').show();
        });

        $('#familia-view-btn').click(function() {
            const grupoId = $(this).data('familia-id') || (currentProduct && currentProduct.familia && currentProduct.familia.id);
            if (!grupoId) return;
            post('riverso_families_get', { grupo_id: grupoId }).done(function(r) {
                if (!r.success || !r.data || !r.data.family) {
                    alert('No se pudo cargar la familia');
                    return;
                }
                const fam = r.data.family;
                const members = fam.members || [];
                const pending = fam.pending || [];
                let rows = members.map(m => {
                    const units = m.cantidad_unidades != null ? m.cantidad_unidades : '—';
                    return `<tr>
                        <td>#${m.producto_base_id}</td>
                        <td><code>${esc(m.canonical_sku || '')}</code></td>
                        <td>${esc(m.nombre_canonico || '')}</td>
                        <td>${esc(String(units))}</td>
                        <td>${esc(String(m.unidades_familia != null ? m.unidades_familia : (m.stock_abierto || 0)))}</td>
                    </tr>`;
                }).join('');
                let pendingHtml = '';
                if (pending.length) {
                    pendingHtml = '<h4 style="margin:16px 0 8px;">Pendientes (proveedor)</h4><ul style="margin:0;padding-left:18px;">' +
                        pending.map(p => `<li><code>${esc(p.codigo_proveedor || p.codigo || '')}</code> · ${esc(p.nombre_proveedor || p.proveedor || '')}${p.cantidad_unidades ? ' · ' + esc(String(p.cantidad_unidades)) + ' u' : ''}</li>`).join('') +
                        '</ul>';
                }
                const stock = fam.stock && (fam.stock.stock_unidades != null ? fam.stock.stock_unidades : fam.stock.total_unidades);
                const stockLabel = stock != null ? String(stock) : '—';
                const html = `
<div id="familia-view-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;max-width:720px;width:94%;max-height:85vh;overflow:auto;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,.25);">
    <h2 style="margin:0 0 8px;">${esc(fam.nombre || fam.codigo_grupo || 'Familia')}</h2>
    <p style="margin:0 0 12px;color:#666;font-size:13px;">${esc(fam.tipo_sustitucion || '')} · Stock familia: <strong>${esc(stockLabel)}</strong> u · ${members.length} miembro(s)</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="text-align:left;border-bottom:1px solid #ddd;"><th>ID</th><th>SKU</th><th>Nombre</th><th>Envase</th><th>Unid. fam.</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="5">Sin miembros</td></tr>'}</tbody>
    </table>
    ${pendingHtml}
    <div style="margin-top:16px;text-align:right;"><button type="button" class="btn-small" id="familia-view-close">Cerrar</button></div>
  </div>
</div>`;
                $('body').append(html);
                $('#familia-view-close, #familia-view-overlay').on('click', function(ev){
                    if (ev.target.id === 'familia-view-overlay' || ev.target.id === 'familia-view-close') {
                        $('#familia-view-overlay').remove();
                    }
                });
            });
        });

        $('#familia-cancel-btn').click(() => {
            $('#local-familia-view').show();
            $('#local-familia-edit').hide();
        });

        $('#familia-save-btn').click(function() {
            const familiaId = $('#familia-select').val();
            post('riverso_products_set_family', {
                producto_id: currentProduct.id,
                familia_id: familiaId || null
            }).done(function(r) {
                if (r.success) {
                    alert('Familia actualizada');
                    openDetail(currentProduct.id);
                }
            });
        });

        $('#local-image-select').click(() => {
            $('#local-image-view').hide();
            $('#local-image-edit').show();
        });

        $('#local-image-cancel-btn').click(() => {
            $('#local-image-view').show();
            $('#local-image-edit').hide();
        });

        $('#local-image-clear').click(function() {
            if (confirm('¿Eliminar imagen?')) {
                post('riverso_products_set_image', {
                    producto_id: currentProduct.id,
                    imagen_url: ''
                }).done(function(r) {
                    if (r.success) {
                        openDetail(currentProduct.id);
                    }
                });
            }
        });

        $('#local-image-save-btn').click(function() {
            const imageUrl = $('#local-image-url').val().trim();
            const file = $('#local-image-file')[0].files[0];

            if (file) {
                // Upload file via FormData
                const formData = new FormData();
                formData.append('action', 'riverso_products_upload_image');
                formData.append('nonce', nonce);
                formData.append('producto_id', currentProduct.id);
                formData.append('file', file);

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(r) {
                        if (r.success) {
                            alert('Imagen subida');
                            openDetail(currentProduct.id);
                        } else {
                            alert('Error: ' + (r.data ? r.data.message : 'desconocido'));
                        }
                    }
                });
            } else if (imageUrl) {
                // Use URL
                post('riverso_products_set_image', {
                    producto_id: currentProduct.id,
                    imagen_url: imageUrl
                }).done(function(r) {
                    if (r.success) {
                        alert('Imagen actualizada');
                        openDetail(currentProduct.id);
                    }
                });
            } else {
                alert('Ingrese una URL o seleccione un archivo');
            }
        });
    }

    // Expose globally
    window.portalProducts = {
        openDetail: openDetail,
        openSibling: openSiblingProduct,
        completeTask: function(taskId) {
            post('riverso_products_complete_task', { tarea_id: taskId }).done(function(r) {
                if (r.success) {
                    alert('Tarea completada');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        },
        removeSupplierCode: function(codeId) {
            if (confirm('¿Eliminar código proveedor?')) {
                post('riverso_products_remove_supplier_code', { codigo_id: codeId }).done(function(r) {
                    if (r.success) {
                        openDetail(currentProduct.id);
                    }
                });
            }
        },
        removeBarcode: function(barcodeId) {
            if (confirm('¿Eliminar barcode?')) {
                post('riverso_products_remove_barcode', { barcode_id: barcodeId }).done(function(r) {
                    if (r.success) {
                        openDetail(currentProduct.id);
                    } else {
                        alert('Error: ' + ((r.data && r.data.message) ? r.data.message : 'No se pudo eliminar el barcode'));
                    }
                });
            }
        },
        acceptLegacyBarcode: function(barcodeId) {
            if (!confirm('¿Aceptar este código legacy como Código de Proveedor?\nQuedará en el mapeo interno y será editable.')) {
                return;
            }
            post('riverso_products_accept_legacy_barcode', {
                barcode_id: barcodeId,
                product_id: currentProduct.id,
                audit_reason: 'Aceptado desde portal de productos'
            }).done(function(r) {
                if (r.success) {
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + ((r.data && r.data.message) ? r.data.message : 'No se pudo aceptar'));
                }
            });
        },
        rejectLegacyBarcode: function(barcodeId) {
            if (!confirm('¿Rechazar y eliminar este código legacy?')) {
                return;
            }
            post('riverso_products_reject_legacy_barcode', {
                barcode_id: barcodeId,
                product_id: currentProduct.id,
                audit_reason: 'Rechazado desde portal de productos'
            }).done(function(r) {
                if (r.success) {
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + ((r.data && r.data.message) ? r.data.message : 'No se pudo rechazar'));
                }
            });
        },
        editBarcode: function(barcodeId) {
            const barcodes = (currentProduct && currentProduct.barcodes) ? currentProduct.barcodes : [];
            const barcode = barcodes.find(function(item) {
                return String(item.id) === String(barcodeId);
            });
            if (!barcode) {
                alert('No se encontró el barcode a editar');
                return;
            }

            const origen = String(barcode.origen_datos || barcode.origen || '').toLowerCase();
            const migrado = String(barcode.migrado_de_tabla || '').toLowerCase();
            if (origen.includes('legacy') || migrado !== '' || !!barcode.legacy_ref) {
                alert('Los códigos legacy no se editan desde esta vista');
                return;
            }

            const code = prompt('Código de barra:', barcode.codigo || '');
            if (code === null) return;
            const cleanCode = code.trim();
            if (!cleanCode) {
                alert('El código no puede quedar vacío');
                return;
            }

            const type = prompt('Tipo de código (supplier, ean13, internal):', barcode.tipo || 'ean13');
            if (type === null) return;
            const cleanType = type.trim().toLowerCase();
            if (!['supplier', 'ean13', 'internal'].includes(cleanType)) {
                alert('Tipo inválido. Usa supplier, ean13 o internal.');
                return;
            }

            const cantidadRaw = prompt('Cantidad:', barcode.cantidad || 1);
            if (cantidadRaw === null) return;
            const cantidad = parseFloat(cantidadRaw);
            if (!Number.isFinite(cantidad) || cantidad <= 0) {
                alert('Cantidad inválida');
                return;
            }

            const unidad = prompt('Unidad:', barcode.unidad_medida || barcode.unidad || 'unidad');
            if (unidad === null) return;
            const cleanUnidad = unidad.trim() || 'unidad';

            post('riverso_products_update_barcode', {
                barcode_id: barcodeId,
                barcode: cleanCode,
                tipo: cleanType,
                cantidad: cantidad,
                unidad_medida: cleanUnidad,
                proveedor_id: cleanType === 'supplier' ? (barcode.proveedor_id || '') : '',
                audit_reason: 'Editado desde portal de productos'
            }).done(function(r) {
                if (r.success) {
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + ((r.data && r.data.message) ? r.data.message : 'No se pudo actualizar el barcode'));
                }
            });
        },
        selectSupplierCode: function(supplierId, supplierCode) {
            $('#supplier-id-select').val(supplierId);
            $('#supplier-code-select').val(supplierCode);
            $('#supplier-search-results').hide();
            $('#supplier-code-search').val('');
        },
        selectWooProduct: function(wooId, wooName) {
            $('#woo-selected-id').val(wooId);
            $('#woo-selected-display').html(`<span style="color: green;">✓ Seleccionado: ${esc(wooName)} (ID: ${wooId})</span>`);
            $('#woo-results').hide();
            $('#woo-search').val('');
            $('#online-link-btn').show();
        },
        editOnlinePrice: function() {
            $('#online-price-editor').toggle();
        }
    };

    function loadProductLocations(productId) {
        post('riverso_inventory_get_product_locations', { producto_base_id: productId }).done(function(r) {
            if (!r.success) {
                $('#prod-loc-pref-list').text((r.data && r.data.message) || 'Error');
                return;
            }
            const pref = r.data.preferidas || [];
            if (!pref.length) {
                $('#prod-loc-pref-list').html('<p style="color:#666;">Sin lugares preferidos.</p>');
            } else {
                $('#prod-loc-pref-list').html(pref.map(function(p) {
                    const star = parseInt(p.es_preferido, 10) ? '★' : '☆';
                    let actions = '';
                    if (canEditWarehouse) {
                        if (!parseInt(p.es_preferido, 10)) {
                            actions += ` <button class="btn-small" onclick="window.portalProducts.setPrimaryLoc(${p.ubicacion_id})">Preferido</button>`;
                        }
                        actions += ` <button class="btn-small danger" onclick="window.portalProducts.removePrefLoc(${p.ubicacion_id})">Quitar</button>`;
                    }
                    return `<div style="padding:6px 0;border-bottom:1px solid #eee;">${star} <strong>${esc(p.codigo)}</strong> ${esc(p.nombre || '')}${actions}</div>`;
                }).join(''));
            }
            const act = r.data.actuales || [];
            $('#prod-loc-act-list').html(act.length
                ? act.map(a => `<div><strong>${esc(a.codigo)}</strong> ${esc(a.nombre || '')} · ${esc(a.cantidad_contada)} uds</div>`).join('')
                : '<p style="color:#666;">Aún no hay conteos cerrados para este producto.</p>');
            const hist = r.data.historial || [];
            $('#prod-loc-hist-body').html(hist.length
                ? hist.map(h => `<tr><td>${esc(h.fecha_conteo)}</td><td>${esc(h.codigo)} ${esc(h.nombre || '')}</td><td>${esc(h.cantidad_contada)}</td><td>${esc(h.conteo_nombre || h.conteo_id || '')}</td></tr>`).join('')
                : '<tr><td colspan="4">Sin historial</td></tr>');
        });
        post('riverso_inventory_get_locations', { activo: 1 }).done(function(r) {
            if (!r.success) return;
            const $sel = $('#prod-loc-add-select');
            $sel.html('<option value="">— Elegir ubicación —</option>');
            (r.data.locations || []).forEach(function(l) {
                $sel.append(`<option value="${l.id}">${esc(l.codigo)} · ${esc(l.nombre || '')}</option>`);
            });
        });
    }

    $('#prod-loc-add').on('click', function() {
        if (!currentProduct) return;
        const locId = $('#prod-loc-add-select').val();
        if (!locId) return;
        post('riverso_inventory_save_preferred_location', {
            producto_base_id: currentProduct.id,
            ubicacion_id: locId
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            loadProductLocations(currentProduct.id);
        });
    });

    window.portalProducts.setPrimaryLoc = function(locId) {
        if (!currentProduct) return;
        post('riverso_inventory_set_primary_location', {
            producto_base_id: currentProduct.id,
            ubicacion_id: locId
        }).done(function() { loadProductLocations(currentProduct.id); });
    };
    window.portalProducts.removePrefLoc = function(locId) {
        if (!currentProduct) return;
        post('riverso_inventory_remove_preferred_location', {
            producto_base_id: currentProduct.id,
            ubicacion_id: locId
        }).done(function() { loadProductLocations(currentProduct.id); });
    };

    // Initial load
    loadCatalogs();
    const deepLinkParams = new URLSearchParams(window.location.search);
    const deepProductId = parseInt(deepLinkParams.get('id') || '0', 10);
    if (deepProductId) {
        const deepTab = deepLinkParams.get('tab') || 'local';
        const deepEdit = deepLinkParams.get('edit') === '1';
        loadProducts(0);
        openDetail(deepProductId, function() {
            switchDetailTab(deepTab);
            if (deepEdit && deepTab === 'local' && canManage) {
                enterEditMode();
                setTimeout(function() { $('#local-sku-edit').focus().select(); }, 100);
            }
        });
    } else {
        loadProducts(0);
    }
});
</script>
