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
        .detail-tabs { display: flex; gap: 10px; border-bottom: 2px solid #eee; margin-bottom: 20px; flex-wrap: wrap; }
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
        <p style="color: #666; margin: 0 0 15px 0;">Gestión centralizada: crear Local, vincular Online, asignar códigos proveedor, agregar barcodes y monitorear completitud.</p>
        
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
                    <th style="width: 6%;">ID</th>
                    <th style="width: 11%;">SKU Local</th>
                    <th style="width: 11%;">SKU Online</th>
                    <th style="width: 18%;">Nombre</th>
                    <th style="width: 12%;">Completitud</th>
                    <th style="width: 11%;">Código Proveedor</th>
                    <th style="width: 11%;">Código Catálogo</th>
                    <th style="width: 8%;">Woo</th>
                    <th style="width: 12%;">Acciones</th>
                </tr>
            </thead>
            <tbody id="products-tbody">
                <tr><td colspan="9" style="text-align: center; color: #999; padding: 40px;">Cargando...</td></tr>
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
            <button class="detail-tab active" data-tab="local">Local</button>
            <button class="detail-tab" data-tab="online">Online</button>
            <button class="detail-tab" data-tab="suppliers">Códigos</button>
            <button class="detail-tab" data-tab="barcodes">Barcodes</button>
            <button class="detail-tab" data-tab="tasks">Tareas</button>
            <?php if ($can_manage_families): ?>
                <button class="detail-tab" data-tab="families">Familias</button>
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
                        <div id="local-familia-view">
                            <span id="familia-display">-</span>
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
                <input type="text" id="woo-search" placeholder="Buscar producto WooCommerce">
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
            <p>Agregar códigos de barra y gestionar por tipo.</p>
            <div style="margin: 12px 0; padding: 12px; background: #f9f9f9; border-radius: 4px;">
                <h4>Nuevo código de barra</h4>
                
                <div style="margin-bottom: 12px;">
                    <label><strong>Tipo de código:</strong></label>
                    <select id="barcode-type" style="width: 100%; padding: 6px;">
                        <option value="ean13">EAN-13</option>
                        <option value="supplier">Código de Proveedor</option>
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
                        <th><label for="family-codigo">Código Único</label></th>
                        <td><input type="text" id="family-codigo" placeholder="ej. FAM001"></td>
                    </tr>
                    <tr>
                        <th><label for="family-nombre">Nombre</label></th>
                        <td><input type="text" id="family-nombre" placeholder="ej. Bebidas Refrescantes"></td>
                    </tr>
                    <tr>
                        <th><label for="family-tipo">Tipo de Sustitución</label></th>
                        <td>
                            <select id="family-tipo">
                                <option value="compatible">Compatible</option>
                                <option value="sustituto">Sustituto</option>
                                <option value="preferido">Preferido</option>
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
jQuery(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canManageCategories = <?php echo $can_manage_categories ? 'true' : 'false'; ?>;
    const canManageFamilies = <?php echo $can_manage_families ? 'true' : 'false'; ?>;
    
    let currentProduct = null;
    let currentOffset = 0;
    let totalCount = 0;
    const LIMIT = 20;
    let isEditMode = false;
    let originalValues = {};

    function esc(v) {
        return $('<div>').text(v === null || v === undefined ? '' : v).html();
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
                $('#products-tbody').html('<tr><td colspan="9" style="color: #d32f2f; padding: 20px; text-align: center;">Error: ' + esc((r.data && r.data.message) || 'cargando') + '</td></tr>');
                return;
            }

            const products = r.data.items || [];
            totalCount = r.data.total || 0;
            const pages = r.data.pages || 0;
            currentOffset = offset || 0;
            let html = '';
            
            if (products.length === 0) {
                html = '<tr><td colspan="9" style="text-align: center; color: #999; padding: 40px;">Sin productos.</td></tr>';
            } else {
                products.forEach(p => {
                    const cat = p.completeness_category || 'incompleto';
                    const wooId = parseInt(p.woocommerce_product_id || 0) ? p.woocommerce_product_id : '-';
                    const skuLocal = p.sku_local || p.canonical_sku || '';
                    const skuOnline = p.sku_online || '';
                    let codigoProv = renderSkuCell(p.codigos_proveedor, 'Código Proveedor');
                    let codigoCat = renderSkuCell(p.codigos_catalogo, 'Código Catálogo');

                    const hasOnline = !!p.woocommerce_product_id;
                    const hasCode = parseInt(p.proveedores_count || 0) > 0;
                    if (hasOnline && !hasCode && cat === 'falta_codigo') {
                        codigoProv = `<span class="completeness-badge falta_codigo" style="cursor:pointer; padding:4px 8px; display:inline-block;" data-product-id="${p.id}" title="Ir a Códigos">Falta código</span>`;
                        codigoCat = `<span style="color:#999;">—</span>`;
                    }

                    html += `<tr>
                        <td>${p.id}</td>
                        <td>${renderSkuCell(skuLocal, 'SKU Local')}</td>
                        <td>${renderSkuCell(skuOnline, 'SKU Online')}</td>
                        <td>${esc(p.nombre_canonico || '-')}</td>
                        <td><span class="completeness-badge ${cat}">${completenessLabel(cat)}</span></td>
                        <td>${codigoProv}</td>
                        <td>${codigoCat}</td>
                        <td>${wooId}</td>
                        <td><button class="btn-small" onclick="window.portalProducts.openDetail(${p.id})">Ver</button></td>
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

    function openDetail(productId) {
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
    }

    function renderDetail() {
        const p = currentProduct;
        $('#detail-title').text(`Producto: ${p.nombre_canonico || 'Sin nombre'} (SKU Local: ${p.canonical_sku || '—'})`);

        // Tab: Local
        $('#local-sku-view').text(p.canonical_sku || '-');
        $('#local-sku-edit').val(p.canonical_sku || '');
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
        if (p.familia) {
            $('#familia-display').html(`<strong>${esc(p.familia.nombre)}</strong> <small style="color:#666;">(${esc(p.familia.tipo_sustitucion || p.familia.codigo_grupo || '')})</small>`);
        } else {
            $('#familia-display').html('<span style="color:#999;">Sin familia</span>');
        }
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
        $('#online-woo-id').text(p.woocommerce_product_id || '-');
        $('#online-match-estado').text(p.match_estado_online || 'UNMATCHED');
        const hasCode = parseInt(p.proveedores_count || 0) > 0 || (p.proveedores && p.proveedores.length > 0);
        if (!hasCode && p.woocommerce_product_id) {
            $('#online-missing-code-banner').show();
        } else {
            $('#online-missing-code-banner').hide();
        }
        $('#suppliers-missing-banner').toggle(!hasCode);
        const hasEan = p.barcodes && p.barcodes.some(b => b.tipo === 'ean13');
        $('#barcodes-missing-banner').toggle(!!p.woocommerce_product_id && !hasEan);

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

    function calculateFieldAlerts(product) {
        const alerts = [];

        if (!product.canonical_sku) {
            alerts.push({ field: 'SKU Local', icon: '❌', action: 'edit-sku' });
        }
        if (!product.precio_local || !product.precio_local.p_asignado) {
            alerts.push({ field: 'Precio Local', icon: '⚠️', action: 'tab-local' });
        }
        if (!product.familia) {
            alerts.push({ field: 'Familia', icon: '👥', action: 'tab-local' });
        }
        if (!product.imagen_id) {
            alerts.push({ field: 'Imagen Local', icon: '📷', action: 'tab-local' });
        }
        if ((product.proveedores_count || 0) === 0) {
            alerts.push({ field: 'Código Proveedor', icon: '📦', action: 'tab-suppliers' });
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
        if (!product.familia) {
            $('#familia-display').after('<span class="field-warning-inline" title="Falta Familia">⚠️</span>');
        }
        if (!product.imagen_id) {
            $('#local-image-select').after('<span class="field-warning-inline" title="Falta Imagen">⚠️</span>');
        }
        if ((product.proveedores_count || 0) === 0) {
            $('#suppliers-list').before('<span class="field-warning-inline suppliers-warn" title="Falta Código Proveedor" style="display:block;margin-bottom:8px;">⚠️ Falta código proveedor</span>');
        }
        if (product.woocommerce_product_id) {
            const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
            if (!hasEan) {
                $('#barcodes-list').before('<span class="field-warning-inline barcodes-warn" title="Falta EAN-13" style="display:block;margin-bottom:8px;">⚠️ Falta Barcode EAN-13</span>');
            }
        }
    }

    function renderOnlineDetails(p) {
        if (!p.woocommerce_product_id && !p.woocommerce_variation_id) {
            $('#online-details').html('<em style="color:#999;">Sin producto WooCommerce asociado. Use el formulario de búsqueda/creación para vincular.</em>');
            if (canManage) {
                $('#online-create-btn').show();
            }
            return;
        }

        const d = p.online_details || null;
        if (!d) {
            let html = `<strong>ID WooCommerce:</strong> ${esc(p.woocommerce_product_id || '-')}<br>`;
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
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(p.woocommerce_variation_id || p.woocommerce_product_id || '-')}</td>
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

        post('riverso_products_search_woo', { s: query, limit: 10 }).done(function(r) {
            if (r.success) {
                const products = r.data.results || r.data.products || [];
                let html = '';
                products.forEach(prod => {
                    const name = prod.name || prod.nombre || '';
                    const sku = prod.sku || '';
                    html += `<div style="padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;" class="woo-result-item" data-id="${prod.id}" data-name="${esc(name)}">
                        <strong>${esc(name)}</strong><br>
                        <small style="color: #666;">ID: ${prod.id} | SKU: ${esc(sku)}</small>
                    </div>`;
                });
                $('#woo-results').html(html || '<div style="padding:10px;color:#999;">Sin resultados</div>').show();
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
        let html = '<h4>Códigos de Barra</h4><table style="width: 100%; font-size: 13px; border-collapse: collapse;">';
        p.barcodes.forEach(barcode => {
            html += `<tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 8px;"><code>${esc(barcode.codigo)}</code></td>
                <td style="padding: 8px;">${esc(barcode.tipo)} - ${esc(barcode.cantidad || 1)} ${esc(barcode.unidad || 'unidad')}</td>
                <td style="padding: 8px; text-align: right;"><button class="btn-small danger barcode-remove-btn" data-id="${barcode.id}">✕ Quitar</button></td>
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
            'confirmar_relacion_online': { button: 'Ir a Online', tab: 'online' },
            'confirmar_estructura_atributos': { button: 'Ver Atributos', tab: 'online' },
            'barcode_faltante': { button: 'Ir a Barcodes', tab: 'barcodes' },
            'codigo_faltante': { button: 'Ir a Códigos', tab: 'suppliers' },
            'autorizar_publicacion': { button: 'Autorizar', tab: 'online' },
            'validar_categoria': { button: 'Ver Online', tab: 'online' },
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
                if (action.tab) {
                    actionHtml = `<button class="btn-small task-goto" data-tab="${action.tab}">${action.button}</button>`;
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
            if (r.success && r.data.families) {
                let html = renderFamilyTreeHTML(r.data.families);
                $('#family-tree').html(html || '<p style="color: #999;">Sin familias</p>');
            }
        });
    }

    function renderFamilyTreeHTML(families) {
        if (!families || families.length === 0) return '';
        let html = '';
        families.forEach(fam => {
            html += `<div style="margin-bottom: 12px; padding: 12px; background: white; border-radius: 4px; border-left: 4px solid #2196f3;">
                <strong>${esc(fam.nombre)}</strong> <small style="color: #999;">(${esc(fam.codigo)})</small><br>
                <small style="color: #666;">Tipo: ${esc(fam.tipo_sustitucion)}</small>`;
            if (fam.miembros && fam.miembros.length > 0) {
                html += '<div style="margin-top: 8px; margin-left: 12px; border-left: 2px solid #e0e0e0; padding-left: 12px;">';
                fam.miembros.forEach(member => {
                    html += `<div style="margin: 4px 0;"><code>${esc(member.sku)}</code> - ${esc(member.nombre)}</div>`;
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
                producto_id: currentProduct.id,
                type: type,
                codigo: code,
                proveedor: type === 'supplier' ? $('#barcode-proveedor').val() : null,
                cantidad: cantidad,
                unidad: unidad,
                origen: origen,
                audit_reason: reason
            }).done(function(r) {
                if (r.success) {
                    alert('Barcode agregado');
                    $('#barcode-new').val('');
                    $('#barcode-cantidad').val('1');
                    $('#barcode-audit-reason').val('');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        });

        // WooCommerce link/create
        $('#woo-search').on('input', () => searchWooProducts());

        $('#online-link-btn').click(function() {
            const wooId = $('#woo-selected-id').val();
            if (!wooId) {
                alert('Seleccione un producto WooCommerce');
                return;
            }

            post('riverso_products_link_woo', {
                producto_id: currentProduct.id,
                woo_id: wooId
            }).done(function(r) {
                if (r.success) {
                    alert('Producto vinculado');
                    openDetail(currentProduct.id);
                } else {
                    alert('Error: ' + r.data.message);
                }
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

            if (!codigo || !nombre) {
                alert('Ingrese código y nombre');
                return;
            }

            post('riverso_families_create', {
                codigo: codigo,
                nombre: nombre,
                tipo_sustitucion: tipo
            }).done(function(r) {
                if (r.success) {
                    alert('Familia creada');
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
            'confirmar_relacion_online': 'online',
            'barcode_faltante': 'barcodes',
            'codigo_faltante': 'suppliers',
            'validar_categoria': 'online',
            'autorizar_publicacion': 'online',
            'confirmar_estructura_atributos': 'online'
        };
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
        switchDetailTab($(this).data('tab'));
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

    $('#online-assign-code-btn').on('click', function() {
        switchDetailTab('suppliers');
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
                    }
                });
            }
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

    // Initial load
    loadCatalogs();
    loadProducts(0);
});
</script>
