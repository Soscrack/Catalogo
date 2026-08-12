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
        
        /* Badges */
        .completeness-badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; white-space: nowrap; }
        .badge-completo { background: #28a745; color: white; }
        .badge-publicado { background: #007bff; color: white; }
        .badge-falta-online { background: #ffc107; color: #333; }
        .badge-falta-codigo { background: #fd7e14; color: white; }
        .badge-solo-online { background: #6f42c1; color: white; }
        .badge-incompleto { background: #dc3545; color: white; }
        
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
        .badge-tooltip { display: none; position: absolute; top: 100%; left: 0; background: #333; color: white; padding: 8px 12px; border-radius: 3px; font-size: 12px; z-index: 1000; margin-top: 5px; min-width: 200px; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .detail-badge:hover .badge-tooltip { display: block; }
        .detail-badge.tasks:hover .badge-tooltip { color: white; }
        
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

    function loadCatalogs() {
        post('riverso_products_get_catalogs', {}).done(function(r) {
            if (r.success && r.data.catalogs) {
                const select = $('#products-catalog');
                r.data.catalogs.forEach(cat => {
                    select.append(`<option value="${esc(cat.name)}">${esc(cat.name)}</option>`);
                });
            }
        });
    }

    function loadProducts(offset = 0) {
        const status = $('#products-status').val();
        const catalog = $('#products-catalog').val();
        const completeness = $('#products-completeness').val();
        const search = $('#products-search').val();

        post('riverso_products_list', {
            offset: offset || 0,
            limit: LIMIT,
            status: status,
            catalog: catalog,
            completeness: completeness,
            search: search || ''
        }).done(function(r) {
            if (!r.success) {
                $('#products-tbody').html('<tr><td colspan="9" style="color: #d32f2f; padding: 20px; text-align: center;">Error: ' + esc(r.data.message) + '</td></tr>');
                return;
            }

            const products = r.data.items || [];
            totalCount = r.data.total || 0;
            let html = '';
            
            if (products.length === 0) {
                html = '<tr><td colspan="9" style="text-align: center; color: #999; padding: 40px;">No hay productos</td></tr>';
            } else {
                products.forEach(p => {
                    const completenessClass = 'badge-' + (p.completitud || 'incompleto').replace(/\s+/g, '-').toLowerCase();
                    html += `<tr>
                        <td><code>${esc(p.id)}</code></td>
                        <td><code>${esc(p.canonical_sku || '-')}</code></td>
                        <td><code>${esc(p.woo_sku || '-')}</code></td>
                        <td>${esc(p.nombre_canonico || '-')}</td>
                        <td><span class="completeness-badge ${completenessClass}">${esc(p.completitud || 'Incompleto')}</span></td>
                        <td><code>${esc(p.codigo_proveedor || '-')}</code></td>
                        <td><code>${esc(p.codigo_catalogo || '-')}</code></td>
                        <td>${esc(p.woocommerce_product_id ? '✓' : '✕')}</td>
                        <td><button class="btn-small" onclick="window.portalProducts.openDetail(${p.id})">Ver</button></td>
                    </tr>`;
                });
            }
            
            $('#products-tbody').html(html);
            currentOffset = offset || 0;

            // Update counter and pagination
            const showing = products.length;
            const start = offset + 1;
            const end = offset + showing;
            $('#products-counter').text(`Mostrando ${showing} de ${totalCount}`);
            $('#products-page-info').text(`Página ${Math.floor(offset / LIMIT) + 1}`);
            
            $('#products-prev').toggle(offset > 0).off('click').on('click', () => loadProducts(Math.max(0, offset - LIMIT)));
            $('#products-next').toggle(offset + LIMIT < totalCount).off('click').on('click', () => loadProducts(offset + LIMIT));
        });
    }

    function openDetail(productId) {
        post('riverso_products_get', { producto_id: productId }).done(function(r) {
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            currentProduct = r.data.product;
            isEditMode = false;
            renderDetail();
            $('#product-detail-panel').show();
            $('html, body').animate({ scrollTop: $('#product-detail-panel').offset().top - 50 }, 300);
        });
    }

    function renderDetail() {
        const p = currentProduct;
        $('#detail-title').text('Detalle: ' + (p.nombre_canonico || p.canonical_sku || 'Sin nombre'));

        // Badges
        calculateFieldAlerts(p);
        calculatePendingTasks(p);

        // Tab: Local
        $('#local-sku-view').text(p.canonical_sku || '-');
        $('#local-name-view').text(p.nombre_canonico || '-');
        $('#local-unit-view').text(p.unidad_base || '-');
        $('#local-decimal-view').html(p.permite_decimal ? '<span style="color: #28a745;">✓ Sí</span>' : '<span style="color: #999;">✕ No</span>');
        $('#local-ean-view').html(p.permite_ean13 ? '<span style="color: #28a745;">✓ Sí</span>' : '<span style="color: #999;">✕ No</span>');
        $('#local-stock-view').html(p.stock_abierto ? '<span style="color: #28a745;">✓ Habilitado</span>' : '<span style="color: #999;">✕ Deshabilitado</span>');
        $('#local-origen').text(p.origen || '-');
        $('#local-estado').text(p.estado || '-');

        // Precio Local
        if (p.precio) {
            const priceText = `${formatMoney(p.precio.precio_asignado)} (Ref: ${formatMoney(p.precio.precio_ref)}, Costo: ${formatMoney(p.precio.costo_ref)})`;
            $('#local-precio-view').html(priceText);
            $('#precio-c-ref').text(formatMoney(p.precio.costo_ref));
            $('#precio-p-ref').text(formatMoney(p.precio.precio_ref));
            $('#precio-factor-min').text((p.precio.factor_min || 0).toFixed(2));
            $('#precio-p-asignado').val(p.precio.precio_asignado || 0);
        } else {
            $('#local-precio-view').html('-');
        }

        // Regla de Precio
        if (p.regla_precio) {
            $('#regla-display').text(p.regla_precio.nombre || '-');
            if (p.regla_precio.origen) {
                $('#regla-origen').text(`(${p.regla_precio.origen})`).show();
            }
        } else {
            $('#regla-display').text('-');
            $('#regla-origen').hide();
        }

        // Familia
        $('#familia-display').text(p.familia ? p.familia.nombre : 'Sin familia');
        loadFamiliesDropdown();

        // Imagen Local
        if (p.imagen_local_url) {
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
        if (!p.codigo_proveedor && p.woocommerce_product_id) {
            $('#online-missing-code-banner').show();
        } else {
            $('#online-missing-code-banner').hide();
        }

        renderOnlineDetails(p);
        loadCategoryTree(p);
        renderSupplierCodes(p);
        renderBarcodes(p);
        renderTasks(p);
        if (canManageFamilies) {
            loadFamilyTree(p);
        }

        // Edit/View mode buttons
        if (canManage) {
            $('#btn-detail-edit').show();
            $('#btn-detail-save').hide();
            $('#btn-detail-cancel').hide();
        }

        exitEditMode();
    }

    function calculateFieldAlerts(p) {
        const alerts = [];
        if (!p.canonical_sku) alerts.push('SKU Local');
        if (!p.nombre_canonico) alerts.push('Nombre');
        if (!p.codigo_proveedor) alerts.push('Código Proveedor');
        
        if (alerts.length > 0) {
            $('#detail-alerts-badge').show();
            $('#detail-alerts-badge-text').text(`⚠️ ${alerts.length} campo${alerts.length > 1 ? 's' : ''}`);
            let tooltipHTML = '<ul style="margin: 0; padding-left: 20px;">';
            alerts.forEach(a => {
                tooltipHTML += `<li style="margin: 4px 0; font-size: 12px;">${esc(a)}</li>`;
            });
            tooltipHTML += '</ul>';
            $('#detail-alerts-tooltip').html(tooltipHTML);
        } else {
            $('#detail-alerts-badge').hide();
        }
    }

    function calculatePendingTasks(p) {
        const tasks = (p.tasks || []).filter(t => t.estado !== 'completada');
        if (tasks.length > 0) {
            $('#detail-tasks-badge').show();
            $('#detail-tasks-badge-text').text(`📋 ${tasks.length} tarea${tasks.length > 1 ? 's' : ''}`);
            let tooltipHTML = '<ul style="margin: 0; padding-left: 20px;">';
            tasks.forEach(t => {
                tooltipHTML += `<li style="margin: 4px 0; font-size: 12px;"><strong>${esc(t.titulo)}</strong><br><small style="color: #ccc;">${esc(t.tipo)}</small></li>`;
            });
            tooltipHTML += '</ul>';
            $('#detail-tasks-tooltip').html(tooltipHTML);
        } else {
            $('#detail-tasks-badge').hide();
        }
    }

    function renderOnlineDetails(p) {
        let html = '';
        if (p.woocommerce_product_id) {
            html += `<strong>ID WooCommerce:</strong> ${esc(p.woocommerce_product_id)}<br>`;
            html += `<strong>Estado Match:</strong> ${esc(p.match_estado_online || 'UNMATCHED')}<br>`;
            if (p.woo_attributes) {
                html += `<strong>Atributos:</strong> ${esc(p.woo_attributes)}<br>`;
            }
            if (p.woo_price) {
                html += `<strong>Precio WooCommerce:</strong> ${formatMoney(p.woo_price)}<br>`;
                if (canManage) {
                    html += `<button class="btn-small" onclick="window.portalProducts.editOnlinePrice()">✎ Editar precio</button>`;
                    $('#online-price-current').text(formatMoney(p.woo_price));
                }
            }
        } else {
            html = '<em style="color: #999;">Sin producto WooCommerce asociado. Use el formulario de búsqueda/creación para vincular.</em>';
        }
        $('#online-details').html(html);
    }

    function searchWooProducts() {
        const query = $('#woo-search').val().trim();
        if (!query) {
            $('#woo-results').hide();
            return;
        }

        post('riverso_products_search_woo', { search: query, limit: 10 }).done(function(r) {
            if (r.success && r.data.products) {
                let html = '';
                r.data.products.forEach(prod => {
                    html += `<div style="padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;" onclick="window.portalProducts.selectWooProduct(${prod.id}, '${esc(prod.name)}')">
                        <strong>${esc(prod.name)}</strong><br>
                        <small style="color: #666;">ID: ${prod.id} | SKU: ${esc(prod.sku)}</small>
                    </div>`;
                });
                $('#woo-results').html(html).show();
            }
        });
    }

    function loadCategoryTree(p) {
        if (!p.woocommerce_product_id) {
            $('#online-categories-tree').html('<p style="color: #999;">Primero vincule un producto WooCommerce.</p>');
            return;
        }

        post('riverso_products_get_category_tree', { producto_id: p.id }).done(function(r) {
            if (r.success) {
                let html = renderCategoryTreeWithCheckboxes(r.data.categories || [], p.categorias_asignadas || []);
                $('#online-categories-tree').html(html || '<p style="color: #999;">Sin categorías disponibles</p>');

                // Check suggested categories
                if (p.categorias_sugeridas) {
                    $('#online-categories-suggested-banner').show();
                    $('#online-categories-suggested-text').text(esc(p.categorias_sugeridas.join(', ')));
                } else {
                    $('#online-categories-suggested-banner').hide();
                }

                // Show task panel if there's a pending task
                if (p.tasks && p.tasks.some(t => t.tipo === 'validar_categoria' && t.estado !== 'completada')) {
                    $('#online-categories-task-panel').show();
                    $('#online-categories-accept-task').off('click').on('click', function() {
                        const task = p.tasks.find(t => t.tipo === 'validar_categoria' && t.estado !== 'completada');
                        if (task) {
                            post('riverso_products_complete_task', { tarea_id: task.id }).done(function(r) {
                                if (r.success) {
                                    alert('Tarea completada');
                                    openDetail(p.id);
                                }
                            });
                        }
                    });
                } else {
                    $('#online-categories-task-panel').hide();
                }
            }
        });
    }

    function renderCategoryTreeWithCheckboxes(categories, assigned) {
        if (!categories || categories.length === 0) return '';
        let html = '<div style="font-size: 13px;">';
        categories.forEach(cat => {
            const isChecked = assigned && assigned.includes(cat.id);
            html += `<div class="tree-item">
                <label style="margin: 0; display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" class="tree-checkbox category-checkbox" data-category-id="${cat.id}" ${isChecked ? 'checked' : ''}>
                    <span>${esc(cat.name)}</span>
                </label>`;
            if (cat.children && cat.children.length > 0) {
                html += renderCategoryTreeWithCheckboxes(cat.children, assigned);
            }
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function renderSupplierCodes(p) {
        if (!p.supplier_codes || p.supplier_codes.length === 0) {
            $('#suppliers-list').html('<p style="color: #999;">Sin códigos proveedor asignados</p>');
            return;
        }
        let html = '<h4>Códigos Asignados</h4><table style="width: 100%; font-size: 13px; border-collapse: collapse;">';
        p.supplier_codes.forEach(code => {
            html += `<tr style="border-bottom: 1px solid #eee;"><td style="padding: 8px;">${esc(code.proveedor)}</td><td style="padding: 8px;"><code>${esc(code.codigo)}</code></td><td style="padding: 8px; text-align: right;"><button class="btn-small danger" onclick="window.portalProducts.removeSupplierCode(${code.id})">✕ Quitar</button></td></tr>`;
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
                <td style="padding: 8px; text-align: right;"><button class="btn-small danger" onclick="window.portalProducts.removeBarcode(${barcode.id})">✕ Quitar</button></td>
            </tr>`;
        });
        html += '</table>';
        $('#barcodes-list').html(html);
    }

    function renderTasks(p) {
        const pendingTasks = (p.tasks || []).filter(t => t.estado !== 'completada');
        if (pendingTasks.length === 0) {
            $('#tasks-list').hide();
            $('#tasks-empty').show();
            return;
        }
        $('#tasks-empty').hide();
        let html = '<h4>Tareas Pendientes</h4><div style="font-size: 13px;">';
        pendingTasks.forEach(task => {
            html += `<div style="margin-bottom: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #ffc107;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong>${esc(task.titulo)}</strong>
                    <button class="btn-small success" onclick="window.portalProducts.completeTask(${task.id})">✓ Completar</button>
                </div>
                <small style="color: #666;">${esc(task.tipo)} - ${esc(task.descripcion || '')}</small>
            </div>`;
        });
        html += '</div>';
        $('#tasks-list').html(html);
        $('#tasks-list').show();
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

        // Categories
        $('#online-categories-save').click(function() {
            const selected = [];
            $('.category-checkbox:checked').each(function() {
                selected.push($(this).data('category-id'));
            });

            post('riverso_products_set_product_categories', {
                producto_id: currentProduct.id,
                categorias: selected
            }).done(function(r) {
                if (r.success) {
                    alert('Categorías asignadas');
                    openDetail(currentProduct.id);
                }
            });
        });

        $('#online-categories-add-new').click(() => {
            $('#online-categories-add-form').toggle();
        });

        $('#online-categories-cancel-btn').click(() => {
            $('#online-categories-add-form').hide();
        });

        $('#online-categories-create-btn').click(function() {
            const name = $('#online-categories-new-name').val().trim();
            const parent = $('#online-categories-new-parent').val();

            if (!name) {
                alert('Ingrese nombre de categoría');
                return;
            }

            post('riverso_products_create_category', {
                nombre: name,
                parent_id: parent || 0
            }).done(function(r) {
                if (r.success) {
                    alert('Categoría creada');
                    $('#online-categories-new-name').val('');
                    $('#online-categories-add-form').hide();
                    loadCategoryTree(currentProduct);
                } else {
                    alert('Error: ' + r.data.message);
                }
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
    
    $('.detail-tab').click(function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.detail-tab').removeClass('active');
        $(this).addClass('active');
        $('.detail-tab-content').removeClass('active');
        $(`.detail-tab-content[data-tab-content="${tab}"]`).addClass('active');
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
