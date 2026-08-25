<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
$can_review = current_user_can('riverso_review_products') || $can_manage;
?>
<div class="wrap">
    <h1>Hub de Productos</h1>
    <p>Gestión centralizada: crear Local, vincular Online, asignar códigos proveedor, agregar barcodes y monitorear completitud.</p>

    <div style="display:flex; gap:8px; align-items:center; margin:12px 0; flex-wrap:wrap;">
        <select id="products-status">
            <option value="active">Activos</option>
            <option value="archived">Archivados</option>
            <option value="deleted">Eliminados</option>
        </select>
        <select id="products-catalog">
            <option value="">Catálogo: Todos</option>
            <!-- Se llenan dinámicamente -->
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
        <span style="position:relative; display:inline-block;">
            <input type="text" id="products-search" class="regular-text" placeholder="SKU Local, Online, código proveedor/catálogo, nombre o barcode" autocomplete="off">
            <div id="products-search-suggestions" style="display:none; position:absolute; left:0; top:100%; z-index:100; background:#fff; border:1px solid #c3c4c7; box-shadow:0 2px 8px rgba(0,0,0,.1); max-height:220px; overflow-y:auto; min-width:100%; width:max-content;"></div>
        </span>
        <button class="button" id="products-reload">Actualizar</button>
        <?php if ($can_manage): ?>
            <button class="button button-primary" id="products-new">Nuevo producto local</button>
            <button class="button button-secondary" id="products-new-online">Crear/Vincular online</button>
        <?php endif; ?>
    </div>

    <div style="margin:12px 0; display:flex; justify-content:space-between; align-items:center;">
        <span id="products-counter" style="color:#666; font-size:13px;">Mostrando 0 de 0</span>
        <span class="dashicons dashicons-editor-help" id="completeness-help" style="cursor:pointer; color:#2271b1; font-size:20px;" title="Ver ayuda de completitud"></span>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:6%">ID</th>
                <th style="width:11%">SKU Local</th>
                <th style="width:11%">SKU Online</th>
                <th style="width:18%">Nombre</th>
                <th style="width:12%">
                    Completitud 
                    <span class="dashicons dashicons-editor-help" id="completeness-col-help" style="cursor:pointer; color:#2271b1;" title="Ver ayuda"></span>
                </th>
                <th style="width:11%">Código Proveedor</th>
                <th style="width:11%">Código Catálogo</th>
                <th style="width:8%">Woo</th>
                <th style="width:12%">Acciones</th>
            </tr>
        </thead>
        <tbody id="products-tbody"><tr><td colspan="9">Cargando...</td></tr></tbody>
    </table>

    <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
        <button class="button" id="products-prev" style="display:none;">← Anterior</button>
        <span id="products-page-info" style="align-self:center; color:#666;"></span>
        <button class="button" id="products-next" style="display:none;">Siguiente →</button>
    </div>

    <?php if ($can_manage): ?>
    <div id="product-editor" style="display:none; margin-top:18px; background:#fff; border:1px solid #ccd0d4; padding:14px;">
        <h2 id="product-editor-title">Producto</h2>
        <input type="hidden" id="product-id">
        <table class="form-table">
            <tr><th>SKU Local</th><td><input type="text" id="product-sku" class="regular-text"></td></tr>
            <tr><th>Nombre</th><td><input type="text" id="product-name" class="large-text"></td></tr>
            <tr><th>Unidad base</th><td><input type="text" id="product-unit" class="regular-text" value="unidad"></td></tr>
            <tr><th>Flags</th><td>
                <label><input type="checkbox" id="product-decimal"> Permite decimal</label><br>
                <label><input type="checkbox" id="product-ean13" checked> Permite EAN13 personalizado</label><br>
                <label><input type="checkbox" id="product-open-stock"> Habilitar stock abierto</label>
            </td></tr>
        </table>
        <p>
            <button class="button button-primary" id="product-save">Guardar producto</button>
            <button class="button" id="product-cancel">Cancelar</button>
        </p>
    </div>

    <div id="product-detail-panel" style="display:none; margin-top:18px; background:#fff; border:1px solid #ccd0d4; padding:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <h2 id="detail-title" style="margin:0;">Detalle del producto</h2>
                <span id="detail-alerts-badge" style="display:none; background:#dc3545; color:white; border-radius:12px; padding:4px 10px; font-weight:bold; font-size:13px; white-space:nowrap; cursor:pointer; position:relative;">
                    <span id="detail-alerts-badge-text">⚠️ 0 campos</span>
                    <div id="detail-alerts-tooltip" class="alerts-tooltip">
                        <!-- Se puebla dinámicamente -->
                    </div>
                </span>
                <span id="detail-tasks-badge" style="display:none; background:#e67e22; color:white; border-radius:12px; padding:4px 10px; font-weight:bold; font-size:13px; white-space:nowrap; cursor:pointer; position:relative; margin-left:8px;">
                    <span id="detail-tasks-badge-text">📋 0 tareas</span>
                    <div id="detail-tasks-tooltip" class="alerts-tooltip">
                        <!-- Se puebla dinámicamente -->
                    </div>
                </span>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="button button-primary" id="detail-edit-btn" style="display:none;">✎ Editar</button>
                <button class="button button-primary" id="detail-save-btn" style="display:none; background:#28a745;">✓ Guardar</button>
                <button class="button" id="detail-cancel-btn" style="display:none;">✕ Cancelar</button>
                <button class="button" id="detail-close">Cerrar</button>
            </div>
        </div>

        <div style="border-bottom:1px solid #ddd; margin-bottom:12px;">
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <a href="#" class="detail-tab active" data-tab="local" title="Datos del producto local">Local <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Información del producto local (SKU, nombre, unidad)"></span></a>
                <a href="#" class="detail-tab" data-tab="online" title="Crear o vincular contraparte WooCommerce">Online <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Vincular o crear producto en WooCommerce"></span></a>
                <a href="#" class="detail-tab" data-tab="suppliers" title="Asignar códigos de proveedores">Códigos <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Códigos de proveedores para comprar este producto"></span></a>
                <a href="#" class="detail-tab" data-tab="barcodes" title="Gestionar códigos EAN13">Barcodes <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Códigos de barra del producto"></span></a>
                <a href="#" class="detail-tab" data-tab="tasks" title="Tareas pendientes">Tasks <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Tareas automáticas para completar el producto"></span></a>
                <a href="#" class="detail-tab" data-tab="families" title="Gestionar familias de productos">Familias <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Familias y grupos de equivalencia"></span></a>
            </div>
        </div>

        <!-- TAB: LOCAL -->
        <div class="detail-tab-content" id="tab-local">
            <table class="form-table">
                <tr>
                    <th>SKU Local</th>
                    <td>
                        <code id="local-sku-view">-</code>
                        <input type="text" id="local-sku-edit" class="regular-text" style="display:none;">
                        <!-- Panel vincular Local o generar SKU cuando está vacío -->
                        <div id="detail-local-empty-panel" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border:1px solid #e0e0e0; border-radius:4px;">
                            <p style="margin:0 0 12px 0; color:#666; font-size:13px;">Este producto no tiene Local: busca uno existente o genera un nuevo SKU.</p>
                            
                            <!-- Buscador Local -->
                            <div style="margin-bottom:12px;">
                                <strong style="display:block; margin-bottom:6px;">Buscar Local existente</strong>
                                <input type="text" id="detail-local-search" class="regular-text" placeholder="Buscar Falta Online por SKU, nombre o código..." style="margin-bottom:8px; width:100%;">
                                <div id="detail-local-suggestions" style="border:1px solid #ddd; border-radius:2px; max-height:150px; overflow-y:auto; margin-bottom:8px; padding:0; background:#fff; display:none;"></div>
                                <div id="detail-local-selected" style="padding:8px; background:#e8f5e9; border:1px solid #4caf50; border-radius:2px; display:none; margin-bottom:8px; font-size:13px;"></div>
                                <button type="button" class="button button-primary" id="detail-local-adopt-btn" style="display:none; width:100%;">Vincular este Local</button>
                            </div>

                            <!-- Separador -->
                            <div style="text-align:center; margin:12px 0; color:#999; font-size:12px;">-- o --</div>

                            <!-- Generar SKU -->
                            <div>
                                <strong style="display:block; margin-bottom:6px;">Generar nuevo SKU Local</strong>
                                <button type="button" class="button" id="detail-local-generate-sku" style="width:100%; margin-bottom:8px;">Generar nuevo SKU Local</button>
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
                        <input type="text" id="local-name-edit" class="regular-text" style="display:none;">
                    </td>
                </tr>
                <tr>
                    <th>Unidad base</th>
                    <td>
                        <span id="local-unit-view">-</span>
                        <select id="local-unit-edit" class="regular-text" style="display:none;">
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
                        <input type="checkbox" id="local-decimal-edit" style="display:none;">
                    </td>
                </tr>
                <tr>
                    <th>Permite EAN-13 personalizado</th>
                    <td>
                        <span id="local-ean-view">-</span>
                        <input type="checkbox" id="local-ean-edit" style="display:none;">
                    </td>
                </tr>
                <tr>
                    <th>Stock abierto habilitado</th>
                    <td>
                        <span id="local-stock-view">-</span>
                        <input type="checkbox" id="local-stock-edit" style="display:none;">
                    </td>
                </tr>
                <tr><th>Origen</th><td id="local-origen">-</td></tr>
                <tr><th>Estado</th><td id="local-estado">-</td></tr>
                <tr>
                    <th>Precio Local</th>
                    <td>
                        <div id="local-precio-view">-</div>
                        <div id="local-precio-edit" style="display:none; background:#f9f9f9; padding:10px; border-radius:4px;">
                            <table style="width:100%; margin-bottom:8px;">
                                <tr>
                                    <td style="width:30%;"><strong>Costo Ref:</strong></td>
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
                                    <td><span id="precio-margen" style="color:green;">✓ Correcto</span></td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label><strong>Precio Asignado:</strong></label><br>
                                        <input type="number" id="precio-p-asignado" class="regular-text" step="0.01" min="0" placeholder="0.00">
                                        <button class="button button-primary" id="precio-save-btn" style="margin-top:8px;">Guardar precio</button>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
                <!-- FASE 9: REGLA DE PRECIO VISIBLE -->
                <tr>
                    <th>Regla de Precio</th>
                    <td>
                        <span id="regla-display">-</span>
                        <small id="regla-origen" style="color:#666; display:none;"></small>
                    </td>
                </tr>
                <tr>
                    <th>Familia</th>
                    <td>
                        <div id="local-familia-view">
                            <span id="familia-display">-</span>
                            <button class="button button-small" id="familia-view-btn" style="margin-left:8px; display:none;">Ver familia</button>
                            <button class="button button-small" id="familia-edit-toggle" style="margin-left:8px;">Editar familia</button>
                        </div>
                        <div id="local-familia-edit" style="display:none; background:#f9f9f9; padding:10px; border-radius:4px;">
                            <label><strong>Seleccionar familia:</strong></label><br>
                            <select id="familia-select" class="regular-text" style="width:100%; padding:6px; margin:6px 0;">
                                <option value="">— Sin familia —</option>
                            </select>
                            <button class="button button-primary" id="familia-save-btn">Asignar familia</button>
                            <button class="button" id="familia-cancel-btn">Cancelar</button>
                        </div>
                    </td>
                </tr>
                <!-- FASE 7: IMAGEN LOCAL -->
                <tr>
                    <th>Imagen Local</th>
                    <td>
                        <div id="local-image-view">
                            <img id="local-image-thumb" src="" style="max-width:120px; max-height:120px; display:none; border-radius:4px; margin-bottom:8px; border:1px solid #ddd;">
                            <br>
                            <button class="button" id="local-image-select">📷 Seleccionar imagen</button>
                            <button class="button" id="local-image-clear" style="display:none;">Quitar imagen</button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- TAB: ONLINE -->
        <div class="detail-tab-content" id="tab-online" style="display:none;">
            <div id="online-missing-code-banner" style="display:none; background:#fff3cd; border:1px solid #ffc107; border-radius:3px; padding:12px; margin-bottom:12px;">
                <strong>⚠️ Falta código proveedor</strong>
                <p style="margin:6px 0 0 0; font-size:13px;">Este producto tiene contraparte WooCommerce pero no tiene código proveedor asignado.</p>
                <button class="button button-primary" id="online-assign-code-btn" style="margin-top:8px;">Asignar código ahora</button>
            </div>

            <!-- Detalles del producto online (atributos, padre, hermanos) -->
            <div id="online-details" style="margin-bottom:20px; padding:12px; background:#fafafa; border-radius:4px;"></div>

            <div id="online-price-editor" style="display:none; margin:12px 0; padding:12px; background:#f0f7ff; border:1px solid #0073aa; border-radius:4px;">
                <h4>Editar Precio Online</h4>
                <table style="width:100%; margin-bottom:12px;">
                    <tr>
                        <td style="width:40%;"><strong>Precio Actual (WooCommerce):</strong></td>
                        <td><span id="online-price-current">$0.00</span></td>
                    </tr>
                    <tr>
                        <td><strong>Nuevo Precio:</strong></td>
                        <td>
                            <input type="number" id="online-price-new" class="regular-text" step="0.01" min="0" placeholder="0.00">
                        </td>
                    </tr>
                </table>
                <label><input type="checkbox" id="online-sync-to-woo" checked> Sincronizar precio a WooCommerce</label>
                <div style="margin-top:12px;">
                    <button class="button button-primary" id="online-price-save">Guardar precio</button>
                    <button class="button" id="online-price-cancel">Cancelar</button>
                </div>
            </div>

            <p>Vincular o crear contraparte WooCommerce.</p>
            <div style="margin:12px 0;">
                <h4>Buscar y vincular producto existente</h4>
                <input type="text" id="woo-search" class="regular-text" placeholder="Buscar Solo Online por nombre, SKU o ID Woo">
                <div id="woo-results" style="display:none; border:1px solid #ddd; max-height:180px; overflow:auto; margin-top:6px;"></div>
                <input type="hidden" id="woo-selected-id">
                <div id="woo-selected-display" style="margin-top:6px; color:#2271b1;"></div>
            </div>
            <table class="form-table">
                <tr><th>Woo ID</th><td id="online-woo-id">-</td></tr>
                <tr><th>Estado match</th><td id="online-match-estado">-</td></tr>
            </table>
            <p>
                <button class="button button-primary" id="online-link-btn" style="display:none;">Vincular producto WooCommerce</button>
            </p>
            <hr style="margin:16px 0;">
            <p>
                <button class="button button-primary" id="online-create-btn" style="display:none;">Crear nuevo producto WooCommerce</button>
            </p>

            <!-- FASE 6: CATEGORÍAS ONLINE -->
			<hr style="margin:16px 0;">
			<h4>Categorías WooCommerce (Fase 6)</h4>
			
			<div id="online-categories-suggested-banner" style="display:none; background:#e7f3ff; border-left:4px solid #2196F3; padding:12px; margin-bottom:12px; border-radius:4px;">
				<strong>Sugerido por catálogo Mamut:</strong>
				<span id="online-categories-suggested-text"></span>
			</div>
			
			<div style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">
				<button type="button" class="button button-small" id="online-categories-expand-all">Expandir todo</button>
				<button type="button" class="button button-small" id="online-categories-collapse-all">Colapsar todo</button>
			</div>
			<div id="online-categories-tree" style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa; max-height:400px; overflow-y:auto; margin-bottom:12px;">
				<p style="color:#666; text-align:center;">Cargando categorías...</p>
			</div>
			<style>
				#online-categories-tree .cat-tree-row { display:flex; align-items:center; gap:4px; margin-bottom:6px; }
				#online-categories-tree .cat-branch-toggle {
					width:20px; height:20px; flex-shrink:0; border:1px solid #ccc; border-radius:3px;
					background:#fff; cursor:pointer; padding:0; font-size:10px; line-height:18px; color:#555;
				}
				#online-categories-tree .cat-branch-toggle:hover { background:#f0f0f0; }
				#online-categories-tree .cat-branch-spacer { width:20px; flex-shrink:0; display:inline-block; }
				#online-categories-tree .cat-tree-row label { display:flex; align-items:center; user-select:none; margin:0; flex:1; }
			</style>
			
			<div style="margin-bottom:12px;">
				<button class="button button-primary" id="online-categories-save" style="display:none;">Guardar categorías</button>
				<button class="button button-secondary" id="online-categories-add-new" style="margin-left:6px;">+ Nueva categoría</button>
			</div>
			
			<div id="online-categories-add-form" style="display:none; background:#f9f9f9; border:1px solid #ddd; padding:12px; border-radius:4px; margin-bottom:12px;">
				<label for="online-categories-new-name" style="display:block; margin-bottom:6px;">Nombre de la categoría:</label>
				<input type="text" id="online-categories-new-name" placeholder="Ej. Herramientas" style="width:100%; padding:6px; box-sizing:border-box; margin-bottom:6px;">
				<label for="online-categories-new-parent" style="display:block; margin-bottom:6px;">Categoría padre:</label>
				<select id="online-categories-new-parent" style="width:100%; padding:6px; box-sizing:border-box; margin-bottom:6px;">
					<option value="0">Sin padre (categoría raíz)</option>
				</select>
				<button class="button button-primary" id="online-categories-create-btn" style="margin-right:6px;">Crear</button>
				<button class="button" id="online-categories-cancel-btn">Cancelar</button>
			</div>
			
			<div id="online-categories-task-panel" style="display:none; background:#fff3cd; border-left:4px solid #ffc107; padding:12px; border-radius:4px; margin-bottom:12px;">
				<strong>Tarea pendiente:</strong> Validar categoría
				<div style="margin-top:8px; font-size:12px; color:#666;">
					<span id="online-categories-task-suggested"></span>
				</div>
				<button class="button button-success" id="online-categories-accept-task" style="margin-top:8px;">Aceptar categorías y completar tarea</button>
			</div>
        </div>

        <!-- TAB: CÓDIGOS PROVEEDOR -->
        <div class="detail-tab-content" id="tab-suppliers" style="display:none;">
            <p>Para asignar un código hay que elegir primero el proveedor y después el código de ese proveedor.</p>

            <div class="supplier-link-form">
                <div class="supplier-link-step">
                    <label class="supplier-step-label">
                        <span class="supplier-step-num">1</span> Proveedor <span class="supplier-step-req">*</span>
                    </label>
                    <input type="text" id="supplier-provider-search" class="regular-text"
                           placeholder="Buscar proveedor por nombre o RUT..." autocomplete="off">
                    <div id="supplier-provider-results" class="supplier-picker-results"></div>
                    <div id="supplier-provider-selected" class="supplier-picked"></div>
                    <input type="hidden" id="supplier-id-select">
                </div>

                <div class="supplier-link-step">
                    <label class="supplier-step-label">
                        <span class="supplier-step-num">2</span> Código de proveedor <span class="supplier-step-req">*</span>
                    </label>
                    <input type="text" id="supplier-code-search" class="regular-text"
                           placeholder="Selecciona un proveedor primero" autocomplete="off" disabled>
                    <p class="description" id="supplier-code-hint">Escribe para buscar entre los códigos de este proveedor.</p>
                    <div id="supplier-search-results" class="supplier-picker-results"></div>
                    <div id="supplier-code-warning" class="supplier-code-warning"></div>
                    <div id="supplier-code-selected" class="supplier-picked"></div>
                    <input type="hidden" id="supplier-code-select">
                    <input type="hidden" id="supplier-force-reassign" value="0">
                </div>

                <div class="supplier-link-step">
                    <label class="supplier-step-label">Motivo auditoría</label>
                    <textarea id="supplier-audit-reason" class="large-text" rows="2" placeholder="Describe por qué asignas este código..."></textarea>
                </div>

                <p>
                    <button class="button button-primary" id="supplier-link-btn" disabled>Asignar código proveedor</button>
                    <button type="button" class="button" id="supplier-link-reset">Limpiar</button>
                    <span id="supplier-link-state" class="description"></span>
                </p>
            </div>

            <div id="suppliers-list" style="margin-top:12px;"></div>
        </div>

        <!-- TAB: BARCODES -->
        <div class="detail-tab-content" id="tab-barcodes" style="display:none;">
            <p>Agregar códigos de barra y gestionar por tipo.</p>
            <div style="margin:12px 0; padding:12px; background:#f9f9f9; border-radius:4px;">
                <h4>Nuevo código de barra</h4>
                
                <div style="margin-bottom:12px;">
                    <label><strong>Tipo de código:</strong></label><br>
                    <select id="barcode-type" style="width:100%; padding:6px;">
                        <option value="ean13">EAN-13</option>
                        <option value="supplier">Código de Proveedor</option>
                        <option value="internal">Interno</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label><strong>Código:</strong></label><br>
                    <input type="text" id="barcode-new" class="regular-text" placeholder="Ingrese código de barra">
                </div>

                <div id="barcode-supplier-section" style="display:none; margin-bottom:12px;">
                    <label><strong>Proveedor (si aplica):</strong></label><br>
                    <select id="barcode-proveedor" style="width:100%; padding:6px;">
                        <option value="">— Seleccione proveedor —</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label><strong>Cantidad:</strong></label><br>
                    <input type="number" id="barcode-cantidad" class="regular-text" placeholder="1" step="0.01" value="1">
                </div>

                <div style="margin-bottom:12px;">
                    <label><strong>Unidad:</strong></label><br>
                    <select id="barcode-unidad" style="width:100%; padding:6px;">
                        <option value="unidad">Unidad</option>
                        <option value="caja">Caja</option>
                        <option value="pallet">Pallet</option>
                        <option value="kg">Kilogramo</option>
                        <option value="lt">Litro</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label><strong>Origen:</strong></label><br>
                    <select id="barcode-origen" style="width:100%; padding:6px;">
                        <option value="manual">Manual</option>
                        <option value="proveedor">Proveedor</option>
                        <option value="import">Importado</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label><strong>Motivo (opcional):</strong></label><br>
                    <textarea id="barcode-audit-reason" class="large-text" rows="2" placeholder="Motivo auditoría o comentario"></textarea>
                </div>

                <button class="button button-primary" id="barcode-add-btn">Agregar código de barra</button>
            </div>
            <div id="barcodes-list" style="margin-top:12px;"></div>
        </div>

        <!-- TAB: TASKS -->
        <div class="detail-tab-content" id="tab-tasks" style="display:none;">
            <div id="tasks-list" style="margin-top:12px;"></div>
            <p id="tasks-empty" style="color:#666;">Sin tareas activas.</p>
        </div>

        <!-- TAB: FAMILIAS (Fase 5) -->
        <div class="detail-tab-content" id="tab-families" style="display:none;">
            <div style="margin-bottom:12px;">
                <button class="button button-primary" id="family-create-btn">+ Nueva familia</button>
            </div>

            <div id="family-create-form" style="display:none; margin-bottom:12px; padding:12px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
                <h4 style="margin-top:0;">Crear Nueva Familia</h4>
                <table class="form-table">
                    <tr>
                        <th><label for="family-codigo">Código Único</label></th>
                        <td><input type="text" id="family-codigo" class="regular-text" placeholder="ej. FAM001"></td>
                    </tr>
                    <tr>
                        <th><label for="family-nombre">Nombre</label></th>
                        <td><input type="text" id="family-nombre" class="regular-text" placeholder="ej. Bebidas Refrescantes"></td>
                    </tr>
                    <tr>
                        <th><label for="family-tipo">Tipo de Sustitución</label></th>
                        <td>
                            <select id="family-tipo" class="regular-text">
                                <option value="exacta" selected>Exacta (mismo ítem, distinto envase)</option>
                                <option value="preferida">Preferida</option>
                                <option value="complementaria">Complementaria</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" id="family-save-btn">Crear familia</button>
                    <button class="button" id="family-cancel-btn">Cancelar</button>
                </p>
            </div>

            <div id="family-tree" style="max-height:600px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; padding:12px; background:#fafafa;">
                <p style="color:#666; text-align:center;">Cargando familias...</p>
            </div>
        </div>
    </div>
    <!-- HELP PANEL: COMPLETITUD -->
    <div id="help-completeness" style="display:none; margin-top:18px; background:#f9f9f9; border-left:4px solid #2271b1; padding:12px; border-radius:2px;">
        <h3 style="margin-top:0;">Guía de Completitud</h3>
        <dl style="margin:8px 0; font-size:13px;">
            <dt><strong style="background:#28a745; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Producto Completo</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local (SKU + nombre) + contraparte WooCommerce (publicado o no) + al menos un código proveedor.</dd>

            <dt><strong style="background:#007bff; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Producto Publicado</strong></dt>
            <dd style="margin:4px 0 8px 0;">Completo y además está publicado en WooCommerce (aprobado y visible).</dd>

            <dt><strong style="background:#ffc107; color:#333; padding:2px 6px; border-radius:2px; display:inline-block;">Falta Online</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local completo pero sin vínculo a WooCommerce.</dd>

            <dt><strong style="background:#fd7e14; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Falta Código</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local + online pero sin código proveedor asignado.</dd>

            <dt><strong style="background:#6f42c1; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Solo Online</strong></dt>
            <dd style="margin:4px 0 8px 0;">Existe en WooCommerce pero no tiene datos locales (SKU/nombre) completos.</dd>

            <dt><strong style="background:#dc3545; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Incompleto</strong></dt>
            <dd style="margin:4px 0 8px 0;">No tiene SKU o nombre local definidos.</dd>
        </dl>
        <button class="button" id="help-close" style="margin-top:8px;">Cerrar</button>
    </div>

    <!-- MODAL: CREAR / VINCULAR PRODUCTO ONLINE (WIZARD) -->
    <div id="create-online-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:4px; padding:20px; max-width:600px; width:90%; max-height:90vh; overflow-y:auto;">
            <h2 style="margin-top:0; margin-bottom:15px;">Crear / Vincular Producto Online</h2>

            <!-- Pestañas del wizard -->
            <div style="border-bottom:2px solid #e0e0e0; margin-bottom:20px; display:flex; gap:15px;">
                <button class="wizard-tab active" data-tab="create" style="background:none; border:none; border-bottom:3px solid #2271b1; padding:10px 0; color:#2271b1; font-weight:bold; cursor:pointer;">Crear nuevo</button>
                <button class="wizard-tab" data-tab="link" style="background:none; border:none; border-bottom:3px solid transparent; padding:10px 0; color:#666; cursor:pointer;">Vincular existente</button>
            </div>

            <!-- TAB: Crear nuevo -->
            <div class="wizard-tab-content active" data-tab="create" style="display:none;">
                <table class="form-table">
                    <tr>
                        <th>
                            Tipo de producto
                            <span class="dashicons dashicons-editor-help" style="cursor:pointer; color:#2271b1; font-size:16px;" title="Simple: sin variantes. Variable: con variantes. Asignar a padre: crear como hijo de un padre existente"></span>
                        </th>
                        <td>
                            <label><input type="radio" name="create-type" value="simple" checked> Producto Simple</label><br>
                            <label><input type="radio" name="create-type" value="variable"> Producto Variable</label><br>
                            <label><input type="radio" name="create-type" value="child"> Asignar a padre variable existente</label>
                        </td>
                    </tr>
                    <tr><th>Nombre</th><td><input type="text" id="create-name" class="large-text" required></td></tr>
                    <tr><th>SKU Online</th><td><input type="text" id="create-sku" class="regular-text" required></td></tr>
                    <tr><th>Precio</th><td><input type="number" id="create-price" class="regular-text" step="0.01" min="0" placeholder="0.00"></td></tr>
                    
                    <!-- Variable: Atributos -->
                    <tr id="create-variable-section" style="display:none;">
                        <th colspan="2">
                            <h4 style="margin:0 0 8px 0;">Atributos de variación (opcional)</h4>
                            <div id="create-attributes-list" style="margin-bottom:12px; max-height:200px; overflow-y:auto; border:1px solid #ddd; border-radius:2px; padding:8px;"></div>
                            <button class="button" id="create-attr-add" type="button">+ Agregar atributo</button>
                        </th>
                    </tr>
                    
                    <!-- Child: Padre variable -->
                    <tr id="create-child-section" style="display:none;">
                        <th colspan="2">
                            <h4 style="margin:0 0 8px 0;">Buscar padre variable</h4>
                            <select id="create-parent-catalog" style="margin-bottom:8px; width:100%;">
                                <option value="">Catálogo: Todos</option>
                            </select>
                            <input type="text" id="create-parent-search" class="large-text" placeholder="Buscar por nombre o SKU..." style="margin-bottom:8px;">
                            <div id="create-parent-suggestions" style="border:1px solid #ddd; border-radius:2px; max-height:150px; overflow-y:auto; margin-bottom:8px; padding:0;"></div>
                            <input type="hidden" id="create-parent-id">
                            <div id="create-parent-selected" style="padding:8px; background:#f0f8ff; border:1px solid #2271b1; border-radius:2px; margin-bottom:8px; display:none;"></div>
                            <fieldset style="border:1px solid #ddd; border-radius:2px; padding:8px; margin-bottom:8px;">
                                <legend style="padding:0 8px; font-weight:bold;">Modo de asignación</legend>
                                <label style="display:block; margin-bottom:6px;">
                                    <input type="radio" name="create-attach-mode" value="create" checked> Crear nueva variación
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="create-attach-mode" value="link"> Vincular si existe coincidente
                                </label>
                            </fieldset>
                            <div id="create-parent-attrs" style="background:#f9f9f9; padding:8px; border-radius:2px; display:none; font-size:13px;">
                                <h5 style="margin:0 0 8px 0;">Atributos del padre y hijos</h5>
                                <div id="create-parent-detail" style="max-height:200px; overflow-y:auto;"></div>
                            </div>
                        </th>
                    </tr>

                    <!-- Categorías (aplica a todos) -->
                    <tr id="create-categories-row">
                        <th colspan="2">
                            <h4 style="margin:0 0 8px 0;">Categorías WooCommerce (opcional)</h4>
                            <div style="border:1px solid #ddd; border-radius:4px; padding:8px; max-height:150px; overflow-y:auto; background:#f9f9f9;">
                                <div id="create-categories-list" style="font-size:13px;"></div>
                            </div>
                        </th>
                    </tr>
                </table>

                <!-- Bloque Producto Local (opcional) -->
                <div style="background:#f5f5f5; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin:15px 0;">
                    <h4 style="margin:0 0 10px 0;">Vincular a Producto Local (opcional)</h4>
                    <p style="margin:0 0 8px 0; color:#666; font-size:13px;">Si deseas asociar este producto a un Local existente, búscalo aquí.</p>
                    <input type="text" id="create-local-search" class="large-text" placeholder="Buscar Falta Online por SKU, nombre o código..." style="margin-bottom:8px;">
                    <div id="create-local-suggestions" style="border:1px solid #ddd; border-radius:2px; max-height:150px; overflow-y:auto; margin-bottom:8px; padding:0; background:#fff;"></div>
                    <input type="hidden" id="create-local-id">
                    <div id="create-local-selected" style="padding:8px; background:#f0f8ff; border:1px solid #2271b1; border-radius:2px; display:none; font-size:13px;"></div>

                    <!-- Separador o generar nuevo SKU -->
                    <div style="text-align:center; margin:12px 0; color:#999; font-size:12px;">-- o --</div>
                    <button class="button" id="create-local-generate-sku" type="button" style="width:100%; margin-bottom:8px;">Generar nuevo SKU Local</button>
                    <input type="hidden" id="create-local-new-sku">
                    <div id="create-local-new-sku-preview" style="padding:10px; background:#e8f5e9; border:1px solid #4caf50; border-radius:2px; display:none; margin-bottom:8px;">
                        <small style="color:#2e7d32; display:block; margin-bottom:4px;">SKU Local sugerido:</small>
                        <input type="text" id="create-local-new-sku-input" class="regular-text" placeholder="Cargando..." readonly style="margin-bottom:4px;">
                        <small style="color:#666; display:block;">Puedes editarlo si lo deseas.</small>
                    </div>
                </div>
            </div>

            <!-- TAB: Vincular existente -->
            <div class="wizard-tab-content" data-tab="link" style="display:none;">
                <h4>Buscar Producto WooCommerce Existente</h4>
                <input type="text" id="link-woo-search" class="large-text" placeholder="Buscar Solo Online por nombre, SKU o ID Woo..." style="margin-bottom:8px;">
                <div id="link-woo-results" style="border:1px solid #ddd; border-radius:2px; max-height:250px; overflow-y:auto; margin-bottom:12px; padding:0; background:#fff;"></div>
                <input type="hidden" id="link-woo-selected-id">
                <div id="link-woo-selected" style="padding:12px; background:#f0f8ff; border:1px solid #2271b1; border-radius:2px; margin-bottom:12px; display:none; font-size:13px;"></div>

                <div id="link-woo-merge-warnings" style="display:none; background:#fff3cd; border:1px solid #ffb800; border-radius:4px; padding:12px; margin-bottom:12px;">
                    <h5 style="margin:0 0 8px 0; color:#856404;">Advertencias de merge</h5>
                    <ul id="link-woo-warnings-list" style="margin:0; padding-left:20px; font-size:13px;"></ul>
                </div>

                <!-- Bloque Producto Local (opcional) -->
                <div style="background:#f5f5f5; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin:15px 0;">
                    <h4 style="margin:0 0 10px 0;">Vincular a Producto Local (opcional)</h4>
                    <input type="text" id="link-local-search" class="large-text" placeholder="Buscar Falta Online por SKU, nombre o código..." style="margin-bottom:8px;">
                    <div id="link-local-suggestions" style="border:1px solid #ddd; border-radius:2px; max-height:150px; overflow-y:auto; margin-bottom:8px; padding:0; background:#fff;"></div>
                    <input type="hidden" id="link-local-id">
                    <div id="link-local-selected" style="padding:8px; background:#f0f8ff; border:1px solid #2271b1; border-radius:2px; display:none; font-size:13px;"></div>

                    <!-- Separador o generar nuevo SKU -->
                    <div style="text-align:center; margin:12px 0; color:#999; font-size:12px;">-- o --</div>
                    <button class="button" id="link-local-generate-sku" type="button" style="width:100%; margin-bottom:8px;">Generar nuevo SKU Local</button>
                    <input type="hidden" id="link-local-new-sku">
                    <div id="link-local-new-sku-preview" style="padding:10px; background:#e8f5e9; border:1px solid #4caf50; border-radius:2px; display:none; margin-bottom:8px;">
                        <small style="color:#2e7d32; display:block; margin-bottom:4px;">SKU Local sugerido:</small>
                        <input type="text" id="link-local-new-sku-input" class="regular-text" placeholder="Cargando..." readonly style="margin-bottom:4px;">
                        <small style="color:#666; display:block;">Puedes editarlo si lo deseas.</small>
                    </div>
                </div>
            </div>

            <!-- Botones -->
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px; border-top:1px solid #e0e0e0; padding-top:15px;">
                <button class="button" id="create-online-cancel">Cancelar</button>
                <button class="button button-primary" id="create-online-submit">Guardar</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.detail-tab {
    padding: 8px 12px;
    border-bottom: 2px solid transparent;
    text-decoration: none;
    color: #2271b1;
    cursor: pointer;
    display: inline-block;
    font-size: 14px;
}
.detail-tab.active {
    border-bottom-color: #2271b1;
    font-weight: bold;
}
.detail-tab-content {
    animation: fadeIn 0.2s;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
#create-online-modal {
    display: none !important;
}
#create-online-modal.show {
    display: flex !important;
}
#help-completeness {
    display: none !important;
}
#help-completeness.show {
    display: block !important;
}
.help-icon {
    cursor: pointer;
    color: #2271b1;
    font-size: 16px;
    display: inline-block;
}
.help-icon:hover {
    color: #135e96;
}
.completeness-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}
.completeness-badge.completo {
    background: #28a745;
    color: white;
}
.completeness-badge.publicado {
    background: #007bff;
    color: white;
}
.completeness-badge.falta_online {
    background: #ffc107;
    color: #333;
}
.completeness-badge.falta_codigo {
    background: #fd7e14;
    color: white;
}
.completeness-badge.solo_online {
    background: #6f42c1;
    color: white;
}
.completeness-badge.solo_online_publicado {
    background: #17a2b8;
    color: white;
}
.completeness-badge.incompleto {
    background: #dc3545;
    color: white;
}
.supplier-code-item {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.supplier-link-form {
    background: #f9f9f9;
    border: 1px solid #e2e4e7;
    border-radius: 4px;
    padding: 16px;
    max-width: 640px;
}
.supplier-link-step {
    margin-bottom: 16px;
}
.supplier-link-step:last-of-type {
    margin-bottom: 8px;
}
.supplier-step-label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}
.supplier-step-num {
    display: inline-block;
    width: 20px;
    height: 20px;
    line-height: 20px;
    text-align: center;
    border-radius: 50%;
    background: #2271b1;
    color: #fff;
    font-size: 12px;
    margin-right: 4px;
}
.supplier-step-req {
    color: #d63638;
}
.supplier-picker-results {
    display: none;
    border: 1px solid #ddd;
    border-top: none;
    background: #fff;
    max-height: 240px;
    overflow-y: auto;
}
.supplier-picker-group {
    padding: 6px 10px;
    background: #f0f0f1;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #50575e;
    border-bottom: 1px solid #ddd;
}
.supplier-picker-item {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f1;
}
.supplier-picker-item:hover {
    background: #f6f7f7;
}
.supplier-picker-item.is-linked {
    background: #fbfbfb;
    color: #8c8f94;
    cursor: not-allowed;
}
.supplier-picker-item.is-linked:hover {
    background: #fcf0f1;
}
.supplier-picker-item.is-create {
    background: #edfaef;
}
.supplier-picker-empty {
    padding: 10px;
    color: #8c8f94;
}
.supplier-linked-badge {
    display: inline-block;
    background: #f0b849;
    color: #1d2327;
    font-size: 11px;
    padding: 1px 6px;
    border-radius: 3px;
    margin-left: 6px;
}
.supplier-code-warning {
    display: none;
    margin-top: 8px;
    padding: 10px 12px;
    background: #fcf0f1;
    border-left: 4px solid #d63638;
    border-radius: 2px;
}
.supplier-picked {
    margin-top: 6px;
    color: #007017;
    font-weight: 600;
}
.supplier-picked.is-forced {
    color: #b32d2e;
}
.barcode-item {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.task-item {
    padding: 8px;
    border-left: 4px solid #2271b1;
    margin-bottom: 8px;
    background: #f9f9f9;
}
.woo-result-item, .supplier-result-item {
    padding: 8px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
}
.woo-result-item:hover, .supplier-result-item:hover {
    background: #f5f5f5;
}
#detail-alerts-badge:hover .alerts-tooltip {
    display: block !important;
}
#detail-tasks-badge:hover .alerts-tooltip {
    display: block !important;
}
.alerts-tooltip {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: #333;
    color: white;
    padding: 10px;
    border-radius: 6px;
    min-width: 220px;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    margin-top: 4px;
    white-space: normal;
    font-weight: normal;
}
.alerts-tooltip-item {
    padding: 6px 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}
.alerts-tooltip-item:hover {
    text-decoration: underline;
    color: #ffeb3b;
}
.field-warning-inline {
    color: #dc3545;
    margin-left: 6px;
    cursor: help;
    font-size: 14px;
    font-weight: bold;
}
</style>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canReview = <?php echo $can_review ? 'true' : 'false'; ?>;

    let currentProduct = null;
    let searchTimeout = null;
    let currentOffset = 0;
    let currentLimit = 50;
    let currentTotal = 0;
    let currentPages = 0;

    function esc(v){ return $('<div>').text(v === null || v === undefined ? '' : v).html(); }
    // Para interpolar dentro de atributos: esc() no escapa las comillas dobles.
    function escAttr(v){ return esc(v).replace(/"/g, '&quot;'); }

    // Funciones de edicion de producto
    let editModeActive = false;

    function enterEditMode() {
        editModeActive = true;
        
        // Mostrar/ocultar elementos del tab Local
        $('#local-sku-view, #local-name-view, #local-unit-view, #local-decimal-view, #local-ean-view, #local-stock-view').hide();
        $('#local-sku-edit, #local-name-edit, #local-unit-edit, #local-decimal-edit, #local-ean-edit, #local-stock-edit').show();
        
        // Cambiar botones
        $('#detail-edit-btn').hide();
        $('#detail-save-btn').show();
        $('#detail-cancel-btn').show();
        
        // Focus en SKU Local
        $('#local-sku-edit').focus();
    }

    function exitEditMode() {
        editModeActive = false;
        
        // Mostrar/ocultar elementos del tab Local
        $('#local-sku-view, #local-name-view, #local-unit-view, #local-decimal-view, #local-ean-view, #local-stock-view').show();
        $('#local-sku-edit, #local-name-edit, #local-unit-edit, #local-decimal-edit, #local-ean-edit, #local-stock-edit').hide();
        
        // Cambiar botones
        $('#detail-edit-btn').show();
        $('#detail-save-btn').hide();
        $('#detail-cancel-btn').hide();
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

    function render(items){
        if (!items || !items.length){
            $('#products-tbody').html('<tr><td colspan="9">Sin productos.</td></tr>');
            updatePagination();
            return;
        }
        $('#products-tbody').html(items.map(it => {
            let actions = `<button class="button button-small product-view" data-id="${it.id}">Ver</button>`;
            if (canManage) {
                actions += ` <button class="button button-small product-edit" data-id="${it.id}">Editar</button>`;
                if (!it.archived_at && !it.deleted_at) {
                    actions += ` <button class="button button-small product-archive" data-id="${it.id}">Archivar</button>`;
                } else if (it.archived_at) {
                    actions += ` <button class="button button-small product-restore" data-id="${it.id}">Restaurar</button>`;
                }
            }
            const cat = it.completeness_category || 'incompleto';
            const wooId = parseInt(it.woocommerce_product_id || 0) ? it.woocommerce_product_id : '-';
            const skuLocal = it.sku_local || it.canonical_sku || '';
            const skuOnline = it.sku_online || '';
            let codigoProv = renderSkuCell(it.codigos_proveedor, 'Código Proveedor');
            let codigoCat = renderSkuCell(it.codigos_catalogo, 'Código Catálogo');

            const hasOnline = !!it.woocommerce_product_id;
            const hasCode = parseInt(it.proveedores_count || 0) > 0;
            if (hasOnline && !hasCode && cat === 'falta_codigo') {
                codigoProv = `<span class="completeness-badge falta_codigo" style="cursor:pointer; padding:4px 8px; display:inline-block;" data-product-id="${it.id}" title="Ir a Códigos">Falta código</span>`;
                codigoCat = `<span style="color:#999;">—</span>`;
            }

            return `<tr>
                <td>${it.id}</td>
                <td>${renderSkuCell(skuLocal, 'SKU Local')}</td>
                <td>${renderSkuCell(skuOnline, 'SKU Online')}</td>
                <td>${esc(it.nombre_canonico || '-')}</td>
                <td><span class="completeness-badge ${cat}">${completenessLabel(cat)}</span></td>
                <td>${codigoProv}</td>
                <td>${codigoCat}</td>
                <td>${wooId}</td>
                <td>${actions}</td>
            </tr>`;
        }).join(''));
        updatePagination();
    }

    function updatePagination() {
        const showing = Math.min(currentLimit, currentTotal - currentOffset);
        const startItem = currentTotal === 0 ? 0 : currentOffset + 1;
        const endItem = currentOffset + showing;
        $('#products-counter').text(`Mostrando ${startItem} a ${endItem} de ${currentTotal}`);

        const currentPage = Math.floor(currentOffset / currentLimit) + 1;
        $('#products-page-info').text(`Página ${currentPage} de ${currentPages || 1}`);

        if (currentOffset > 0) {
            $('#products-prev').show();
        } else {
            $('#products-prev').hide();
        }

        if (currentOffset + currentLimit < currentTotal) {
            $('#products-next').show();
        } else {
            $('#products-next').hide();
        }
    }

    function load(){
        $('#products-tbody').html('<tr><td colspan="9">Cargando...</td></tr>');
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            status: $('#products-status').val(),
            search: $('#products-search').val(),
            completeness: $('#products-completeness').val(),
            catalog_id: $('#products-catalog').val(),
            offset: currentOffset,
            limit: currentLimit
        }, function(r){
            if (!r.success){ 
                $('#products-tbody').html('<tr><td colspan="9">Error cargando.</td></tr>'); 
                return; 
            }
            currentTotal = r.data.total || 0;
            currentPages = r.data.pages || 0;
            render(r.data.items || []);
        });
    }

    function loadPage(offset) {
        currentOffset = Math.max(0, offset);
        load();
    }

    function resetEditor(){
        $('#product-id').val('');
        $('#product-sku').val('');
        $('#product-name').val('');
        $('#product-unit').val('unidad');
        $('#product-decimal').prop('checked', false);
        $('#product-ean13').prop('checked', true);
        $('#product-open-stock').prop('checked', false);
    }

    function showDetail(product) {
        currentProduct = product;
        $('#detail-title').text(`Producto: ${product.nombre_canonico} (SKU Local: ${product.canonical_sku || '—'})`);
        
        // Local tab -- poblar para VIEW y EDIT
        $('#local-sku-view').text(product.canonical_sku || '-');
        $('#local-sku-edit').val(product.canonical_sku || '');
        
        // Mostrar panel de vincular Local o generar SKU si está vacío
        const hasLocalSku = !!product.canonical_sku;
        const hasWooLink = product.woocommerce_product_id || product.woocommerce_variation_id;
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
        
        $('#local-name-view').text(product.nombre_canonico);
        $('#local-name-edit').val(product.nombre_canonico);
        
        $('#local-unit-view').text(product.unidad_base || 'unidad');
        $('#local-unit-edit').val(product.unidad_base || 'unidad');
        
        $('#local-decimal-view').text(product.permite_decimal ? 'Sí' : 'No');
        $('#local-decimal-edit').prop('checked', !!product.permite_decimal);
        
        $('#local-ean-view').text(product.permite_ean13_personalizado ? 'Sí' : 'No');
        $('#local-ean-edit').prop('checked', !!product.permite_ean13_personalizado);
        
        $('#local-stock-view').text(product.stock_abierto_habilitado ? 'Sí' : 'No');
        $('#local-stock-edit').prop('checked', !!product.stock_abierto_habilitado);
        
        $('#local-origen').text(product.origen_datos || 'manual');
        $('#local-estado').text(product.estado);
        
        // Precio local
        if (product.precio_local) {
            const precio = product.precio_local;
            const c_ref = precio.c_ref ? parseFloat(precio.c_ref) : 0;
            const p_ref = precio.p_ref ? parseFloat(precio.p_ref) : 0;
            const p_asignado = precio.p_asignado ? parseFloat(precio.p_asignado) : 0;
            const factor_min = precio.factor_minimo ? parseFloat(precio.factor_minimo) : 1.30;
            const alerta = precio.alerta_margen ? 1 : 0;
            
            let priceHtml = `<div style="background:#f9f9f9; padding:10px; border-radius:4px; margin-bottom:8px;">
                <table style="width:100%; margin-bottom:8px;">
                    <tr><td><strong>Costo Ref:</strong></td><td>$${c_ref.toFixed(2)}</td></tr>
                    <tr><td><strong>Precio Ref:</strong></td><td>$${p_ref.toFixed(2)}</td></tr>
                    <tr><td><strong>Precio Asignado:</strong></td><td style="${alerta ? 'color:red;font-weight:bold;' : ''}">$${p_asignado.toFixed(2)}</td></tr>
                    ${alerta ? '<tr><td colspan="2" style="color:red;font-weight:bold;">⚠️ Alerta de margen</td></tr>' : ''}
                </table>
                <button class="button button-small" id="precio-edit-toggle" data-precio-id="${precio.id}">Editar precio</button>
            </div>`;
            $('#local-precio-view').html(priceHtml);
        } else {
            $('#local-precio-view').html('<span style="color:#999;">Sin precio asignado</span>');
        }
        
        // Familia
        if (product.familia) {
            const fam = product.familia;
            $('#familia-display').html(`<strong>${esc(fam.nombre)}</strong> <small style="color:#666;">(${esc(fam.tipo_sustitucion)})</small>`);
            $('#familia-view-btn').show().data('familia-id', fam.id);
        } else {
            $('#familia-display').html('<span style="color:#999;">Sin familia</span>');
            $('#familia-view-btn').hide().data('familia-id', '');
        }
        
        // Imagen (Fase 7)
        if (product.imagen_id && product.imagen_url) {
            $('#local-image-thumb').attr('src', product.imagen_url).show();
            $('#local-image-clear').show();
        } else {
            $('#local-image-thumb').hide();
            $('#local-image-clear').hide();
        }
        
        // Calcular alertas de campos faltantes (Fase 8)
        calculateFieldAlerts(product);
        
        // Mostrar iconos de warning inline en campos vacíos
        showFieldWarningIcons(product);
        
        // Mostrar badge de tareas pendientes
        calculatePendingTasks(product);
        
        // Mostrar regla de precio (Fase 9)
        if (product.regla_precio && product.regla_precio.id) {
            const regla = product.regla_precio;
            const origen = regla.origen || 'producto';
            const originLabel = {
                'producto': 'Regla directa',
                'familia': 'Regla de familia',
                'categoria': 'Regla de categoría'
            }[origen] || origen;
            
            $('#regla-display').html(`<a href="${esc(admin_url)}admin.php?page=riverso-pos-price-rules&id=${regla.id}" target="_blank"><strong>${esc(regla.nombre)}</strong></a>`);
            $('#regla-origen').text(`(${originLabel})`).show();
        } else {
            $('#regla-display').text('Sin regla asignada');
            $('#regla-origen').hide();
        }
        
        // Mostrar botones edit/save/cancel
        $('#detail-edit-btn').show();
        $('#detail-save-btn').hide();
        $('#detail-cancel-btn').hide();
        exitEditMode();  // Asegurar que estamos en modo view

        // Online tab
        const hasWoo = parseInt(product.woocommerce_product_id || 0, 10) > 0
            || parseInt(product.woocommerce_variation_id || 0, 10) > 0;
        $('#online-woo-id').text(hasWoo ? (product.woocommerce_product_id || product.woocommerce_variation_id) : '-');
        $('#online-match-estado').text(product.match_estado_online || 'UNMATCHED');
        $('#woo-selected-id').val('');
        $('#woo-selected-display').text('');
        
        // Renderizar detalles online (atributos, padre, hermanos)
        renderOnlineDetails(product.online_details || null);
        
        // Mostrar/ocultar banner de falta código
        const hasCode = parseInt(product.proveedores_count || 0) > 0;
        if (hasWoo && !hasCode) {
            $('#online-missing-code-banner').show();
        } else {
            $('#online-missing-code-banner').hide();
        }
        
        if (!hasWoo) {
            $('#online-link-btn').show();
            $('#online-create-btn').show();
        } else {
            $('#online-link-btn').hide();
            $('#online-create-btn').hide();
        }

        // Suppliers tab
        resetSupplierLinkForm();
        renderSuppliers(product.proveedores || []);

        // Barcodes tab
        renderBarcodes(product.barcodes || []);

        // Tasks tab
        renderTasks(product.tasks || []);

        $('#product-detail-panel').show();
        $('html, body').animate({scrollTop: $('#product-detail-panel').offset().top - 40}, 300);
    }

    function updateSupplierLinkBtnState() {
        const code = $('#supplier-code-select').val();
        const supplierId = $('#supplier-id-select').val();
        const ready = !!(code && supplierId);

        $('#supplier-link-btn').prop('disabled', !ready).css('opacity', ready ? '1' : '0.5');

        let state = '';
        if (!supplierId) {
            state = 'Falta elegir el proveedor.';
        } else if (!code) {
            state = 'Falta elegir el código de proveedor.';
        }
        $('#supplier-link-state').text(state);
    }

    function renderSuppliers(suppliers) {
        let html = '';
        if (suppliers.length > 0) {
            const pending = suppliers.filter(s => s.needs_confirm);
            if (pending.length > 0) {
                html += `<div class="notice notice-warning" style="margin:0 0 12px;padding:8px 12px;">
                    <strong>${pending.length}</strong> código(s) por confirmar (legacy / catálogo).
                </div>`;
            }
            html += '<h4 style="margin-top:0;">Códigos asignados:</h4>';
            suppliers.forEach(s => {
                const fuente = s.origen_label || s.fuente_display || 'Manual';
                const badgeColor = fuente.includes('Catálogo') ? '#0073aa' : fuente.includes('Factur') ? '#ff6b35' : fuente.includes('Legacy') ? '#9c27b0' : '#666';
                const barcodeProveedor = s.codigo_barras_proveedor ? `<br><small style="color:#999;">Barcode Proveedor: <code>${esc(s.codigo_barras_proveedor)}</code></small>` : '';
                const fecha = s.fecha_ingreso ? String(s.fecha_ingreso).split(' ')[0] : '';
                const confirmBtns = s.needs_confirm
                    ? `<div style="margin-top:6px;">
                        <button type="button" class="button button-small button-primary btn-pp-confirm" data-id="${s.id}">Confirmar</button>
                        <button type="button" class="button button-small btn-pp-reject" data-id="${s.id}">Rechazar</button>
                       </div>`
                    : '';
                const pendingBadge = s.needs_confirm
                    ? '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:3px;font-size:11px;margin-left:6px;">Por confirmar</span>'
                    : '';
                
                html += `<div class="supplier-code-item">
                    <div>
                        <strong>${esc(s.codigo_proveedor)}</strong> 
                        <span style="background:${badgeColor}; color:white; padding:2px 8px; border-radius:3px; font-size:11px; margin-left:8px;">${esc(fuente)}</span>
                        ${pendingBadge}<br>
                        <small>${esc(s.proveedor_nombre || 'Proveedor')}</small><br>
                        <small style="color:#999;">${esc(s.nombre_proveedor || '')}</small>
                        ${fecha ? `<br><small style="color:#999;">Ingreso: ${esc(fecha)}</small>` : ''}
                        ${barcodeProveedor}
                        ${confirmBtns}
                    </div>
                </div>`;
            });
        }
        $('#suppliers-list').html(html || '<p style="color:#666;">Sin códigos asignados.</p>');
    }

    function renderBarcodes(barcodes) {
        let html = '';
        if (barcodes && barcodes.length > 0) {
            // Agrupar por tipo
            const byType = {
                ean13: [],
                supplier: [],
                internal: [],
            };
            
            barcodes.forEach(b => {
                const tipo = b.tipo || 'ean13';
                if (!byType[tipo]) byType[tipo] = [];
                byType[tipo].push(b);
            });

            // Renderizar cada grupo
            if (byType.ean13.length > 0) {
                html += '<h5>EAN-13</h5>';
                byType.ean13.forEach(b => {
                    const detalles = [];
                    if (b.cantidad && b.cantidad !== 1) detalles.push(`Cantidad: ${b.cantidad} ${b.unidad_medida || 'unidad'}`);
                    if (b.origen_datos) detalles.push(`Origen: ${b.origen_datos}`);
                    const detallesHtml = detalles.length > 0 ? `<br><small style="color:#999;">${detalles.join(' | ')}</small>` : '';
                    
                    html += `<div class="barcode-item" style="margin-bottom:8px;">
                        <code>${esc(b.codigo)}</code>
                        <span style="background:#0073aa; color:white; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:8px;">EAN-13</span>
                        ${detallesHtml}
                        <br><button class="button button-small barcode-remove" data-barcode="${esc(b.codigo)}" style="margin-top:4px;">Desactivar</button>
                    </div>`;
                });
            }

            if (byType.supplier.length > 0) {
                html += '<h5 style="margin-top:16px;">Códigos de Proveedor</h5>';
                byType.supplier.forEach(b => {
                    const proveedor = b.proveedor_nombre ? ` (${b.proveedor_nombre})` : '';
                    const detalles = [];
                    if (b.cantidad && b.cantidad !== 1) detalles.push(`Cantidad: ${b.cantidad}`);
                    if (b.envase_id) detalles.push(`Envase: ${b.envase_id}`);
                    if (b.origen_datos) detalles.push(`Origen: ${b.origen_datos}`);
                    const detallesHtml = detalles.length > 0 ? `<br><small style="color:#999;">${detalles.join(' | ')}</small>` : '';
                    
                    html += `<div class="barcode-item" style="margin-bottom:8px;">
                        <code>${esc(b.codigo)}</code>
                        <span style="background:#ff6b35; color:white; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:8px;">Proveedor</span>
                        <br><small>${esc(proveedor)}</small>
                        ${detallesHtml}
                        <br><button class="button button-small barcode-remove" data-barcode="${esc(b.codigo)}" style="margin-top:4px;">Desactivar</button>
                    </div>`;
                });
            }

            if (byType.internal.length > 0) {
                html += '<h5 style="margin-top:16px;">Códigos Internos</h5>';
                byType.internal.forEach(b => {
                    const detalles = [];
                    if (b.cantidad && b.cantidad !== 1) detalles.push(`Cantidad: ${b.cantidad}`);
                    if (b.envase_id) detalles.push(`Envase: ${b.envase_id}`);
                    const detallesHtml = detalles.length > 0 ? `<br><small style="color:#999;">${detalles.join(' | ')}</small>` : '';
                    
                    html += `<div class="barcode-item" style="margin-bottom:8px;">
                        <code>${esc(b.codigo)}</code>
                        <span style="background:#666; color:white; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:8px;">Interno</span>
                        ${detallesHtml}
                        <br><button class="button button-small barcode-remove" data-barcode="${esc(b.codigo)}" style="margin-top:4px;">Desactivar</button>
                    </div>`;
                });
            }
        }
        $('#barcodes-list').html(html || '<p style="color:#666;">Sin códigos de barra.</p>');
    }

    function renderOnlineDetails(onlineDetails) {
        if (!onlineDetails) {
            $('#online-details').html('<p style="color:#999;">Sin información online.</p>');
            return;
        }

        let html = '';

        // Seccion: Identidad Online
        html += `<div style="margin-bottom:20px;">
            <h5>Identidad Online</h5>
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Tipo:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(onlineDetails.type || 'N/A')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Nombre:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(onlineDetails.name || '')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>SKU Online:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><code>${esc(onlineDetails.sku || '-')}</code></td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Estado:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">${esc(onlineDetails.status || 'N/A')}</td>
                </tr>
                <tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;"><strong>Precio:</strong></td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">
                        <span id="online-price-display">$${(onlineDetails.price || 0).toFixed(2)}</span>
                        <button class="button button-small" id="online-price-edit-btn" style="margin-left:8px;">Editar</button>
                    </td>
                </tr>
            </table>
        </div>`;

        // Seccion: Atributos (si tiene)
        if (onlineDetails.attributes && onlineDetails.attributes.length > 0) {
            html += '<div style="margin-bottom:20px;" data-section="attributes">';
            html += '<h5>Atributos de Variación</h5>';
            
            if (onlineDetails.type === 'variation') {
                // Variacion actual: mostrar sus atributos
                html += '<ul style="margin:0; padding-left:20px;">';
                onlineDetails.attributes.forEach(attr => {
                    html += `<li>${esc(attr.name)}: <strong>${esc(attr.value)}</strong></li>`;
                });
                html += '</ul>';
            } else if (onlineDetails.type === 'variable') {
                // Padre variable: mostrar opciones de atributos
                html += '<ul style="margin:0; padding-left:20px;">';
                onlineDetails.attributes.forEach(attr => {
                    const options = (attr.options || []).join(', ');
                    html += `<li>${esc(attr.name)}: <small>${esc(options)}</small></li>`;
                });
                html += '</ul>';
            }
            
            html += '</div>';
        }

        // Seccion: Padre (si es variacion)
        if (onlineDetails.parent) {
            html += `<div style="margin-bottom:20px; padding:12px; background:#f5f5f5; border-left:4px solid #0073aa;">
                <h5 style="margin-top:0;">Producto Padre</h5>
                <p style="margin:4px 0;"><strong>${esc(onlineDetails.parent.name)}</strong></p>
                <p style="margin:4px 0; color:#666;"><code>${esc(onlineDetails.parent.sku || 'Sin SKU')}</code></p>
                <button class="button button-small" style="margin-top:6px;" onclick="openParentDetail('${onlineDetails.parent.id}')">Ver detalles del padre</button>
            </div>`;
        }

        // Seccion: Hermanos (si es variacion)
        if (onlineDetails.siblings && onlineDetails.siblings.length > 0) {
            html += '<div style="margin-bottom:20px;">';
            html += '<h5>Variaciones Hermanas</h5>';
            html += '<div style="border:1px solid #ddd; border-radius:4px; max-height:300px; overflow-y:auto;">';
            
            onlineDetails.siblings.forEach(sibling => {
                const skuBadgeClass = sibling.has_local_sku ? 'style="background:#28a745; color:white;"' : 'style="background:#ccc; color:#333;"';
                const skuBadgeText = sibling.has_local_sku ? 'SKU Local: ' + esc(sibling.sku_local) : 'Sin SKU Local';
                
                html += `<div style="padding:10px; border-bottom:1px solid #eee; cursor:pointer; transition:background 0.2s;" onclick="openVariationDetail(${sibling.producto_base_id})" onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='white'">
                    <strong>${esc(sibling.name)}</strong><br>
                    <small style="color:#666;">${esc(sibling.attributes_text)}</small><br>
                    <small style="color:#999;">SKU Online: <code>${esc(sibling.sku_online || '-')}</code></small><br>
                    <span ${skuBadgeClass}>${skuBadgeText}</span>
                </div>`;
            });
            
            html += '</div>';
            html += '</div>';
        }

        $('#online-details').html(html);
    }

    function openParentDetail(parentId) {
        // Función placeholder para abrir detalle del padre (futuro)
        alert('Abrir detalle del padre Woo ID ' + parentId);
    }

    function openVariationDetail(baseId) {
        // Cargar detalle de la variacion hermana
        if (baseId > 0) {
            $.post(ajaxurl, {
                action: 'riverso_products_get',
                nonce,
                id: baseId
            }, function(r) {
                if (r.success) {
                    showDetail(r.data.item);
                }
            });
        }
    }
    function renderTasks(tasks) {
        // Mapeo de tipo de tarea -> accion en UI
        const taskActionMap = {
            'crear_contraparte_online': { button: 'Ir a Online', tab: 'online' },
            'crear_contraparte_local': { button: 'Asignar SKU Local', action: 'editLocal' },
            'relacionar_producto_proveedor': { button: 'Ir a Códigos', tab: 'suppliers' },
            'confirmar_codigo_proveedor': { button: 'Ir a Códigos', tab: 'suppliers' },
            'confirmar_relacion_online': { button: 'Ir a Online', tab: 'online' },
            'confirmar_estructura_atributos': { button: 'Ver Atributos', tab: 'online', scroll: 'attributes' },
            'barcode_faltante': { button: 'Ir a Barcodes', tab: 'barcodes' },
            'codigo_faltante': { button: 'Ir a Códigos', tab: 'suppliers' },
            'autorizar_publicacion': { button: 'Autorizar', tab: 'online' },
            'validar_categoria': { button: 'Ver Online', tab: 'online' },
        };

        let html = '';
        if (tasks && tasks.length > 0) {
            html = tasks.map(t => {
                let actionHtml = '';
                const action = taskActionMap[t.tipo];
                
                if (action) {
                    if (action.tab) {
                        // Accion interna: cambiar tab
                        actionHtml = `<button class="button button-small task-goto" data-tab="${action.tab}" data-scroll="${action.scroll || ''}">${action.button}</button>`;
                    } else if (action.action === 'editLocal') {
                        // Accion especial: activar editor con foco en SKU
                        actionHtml = '<button class="button button-small task-edit-local">Asignar SKU Local</button>';
                    }
                } else if (t.target_url) {
                    // URL externa resuelta por backend
                    actionHtml = `<button class="button button-small task-open-external" data-url="${esc(t.target_url)}">Abrir →</button>`;
                }
                
                return `
                <div class="task-item">
                    <strong>${esc(t.titulo)}</strong>
                    <br><small>${esc(t.tipo)} | ${esc(t.estado)} | Prioridad: ${esc(t.prioridad)}</small>
                    ${actionHtml ? '<div style="margin-top:6px;">' + actionHtml + '</div>' : ''}
                </div>
            `}).join('');
            $('#tasks-empty').hide();
        } else {
            $('#tasks-empty').show();
        }
        $('#tasks-list').html(html);
    }

    // Evento: cambiar tab en detalle
    $(document).on('click', '.detail-tab', function(e){
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.detail-tab').removeClass('active');
        $(this).addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-' + tab).show();
    });

    // Evento: botones de navegacion de tareas (cambiar tab interno)
    $(document).on('click', '.task-goto', function(e){
        e.preventDefault();
        const tab = $(this).data('tab');
        const scroll = $(this).data('scroll') || '';
        
        // Simular click en la pestaña correspondiente
        $('[data-tab="' + tab + '"].detail-tab').trigger('click');
        
        // Si hay scroll, hacer scroll al elemento (opcional delay para animacion)
        if (scroll) {
            setTimeout(() => {
                const element = $('#tab-' + tab + ' [data-section="' + scroll + '"]');
                if (element.length) {
                    $('#tab-' + tab).scrollTop(element.offset().top - $('#tab-' + tab).offset().top);
                }
            }, 100);
        }
    });

    // Evento: boton para editar SKU local desde tarea
    $(document).on('click', '.task-edit-local', function(e){
        e.preventDefault();
        // Ir al tab Local, abrir editor y hacer focus en SKU Local
        $('[data-tab="local"].detail-tab').trigger('click');
        enterEditMode();
        setTimeout(() => $('#local-sku-edit').focus().select(), 100);
    });

    // Evento: abrir URL externa de tarea en nueva ventana
    $(document).on('click', '.task-open-external', function(e){
        e.preventDefault();
        const url = $(this).data('url');
        if (url) {
            window.open(url, '_blank');
        }
    });

    // EVENTOS DE EDICION DEL PANEL DE DETALLE

    // Boton Editar
    $('#detail-edit-btn').on('click', function(e){
        e.preventDefault();
        enterEditMode();
    });

    // Boton Guardar
    $('#detail-save-btn').on('click', function(e){
        e.preventDefault();
        
        if (!currentProduct) return;
        
        const newData = {
            id: currentProduct.id,
            canonical_sku: $('#local-sku-edit').val(),
            nombre_canonico: $('#local-name-edit').val(),
            unidad_base: $('#local-unit-edit').val(),
            permite_decimal: $('#local-decimal-edit').is(':checked') ? 1 : 0,
            permite_ean13_personalizado: $('#local-ean-edit').is(':checked') ? 1 : 0,
            stock_abierto_habilitado: $('#local-stock-edit').is(':checked') ? 1 : 0,
        };
        
        const postData = {
            action: 'riverso_products_save',
            nonce: nonce
        };
        
        // Combinar datos
        $.extend(postData, newData);
        
        $.post(ajaxurl, postData, function(r){
            if (!r.success) {
                alert('Error al guardar: ' + (r.data.message || 'Error desconocido'));
                return;
            }
            
            alert(r.data.message);
            exitEditMode();
            showDetail(r.data.item);
            load(); // Recargar lista para actualizar completitud, etc.
        });
    });

    // Boton Cancelar
    $('#detail-cancel-btn').on('click', function(e){
        e.preventDefault();
        if (currentProduct) {
            exitEditMode();
            // Revertir valores del formulario
            showDetail(currentProduct);
        }
    });

    // Evento: editar precio local
    $(document).on('click', '#precio-edit-toggle', function(e){
        e.preventDefault();
        if (!currentProduct || !currentProduct.precio_local) return;
        
        const precio = currentProduct.precio_local;
        $('#precio-c-ref').text('$' + (precio.c_ref ? parseFloat(precio.c_ref).toFixed(2) : '0.00'));
        $('#precio-p-ref').text('$' + (precio.p_ref ? parseFloat(precio.p_ref).toFixed(2) : '0.00'));
        $('#precio-factor-min').text(precio.factor_minimo ? parseFloat(precio.factor_minimo).toFixed(2) : '1.30');
        $('#precio-p-asignado').val(precio.p_asignado ? parseFloat(precio.p_asignado).toFixed(2) : '');
        
        // Mostrar el editor
        $('#local-precio-view').hide();
        $('#local-precio-edit').show();
        $('#precio-p-asignado').data('precio-id', precio.id).focus();
    });

    // Evento: guardar precio local
    $('#precio-save-btn').on('click', function(e){
        e.preventDefault();
        if (!currentProduct || !currentProduct.precio_local) return;
        
        const precio_id = currentProduct.precio_local.id;
        const p_asignado = $('#precio-p-asignado').val();
        
        if (!p_asignado || isNaN(p_asignado)) {
            alert('Ingrese un precio válido');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_products_set_local_price',
            nonce,
            precio_id: precio_id,
            p_asignado: parseFloat(p_asignado)
        }, function(r){
            if (!r.success) {
                alert('Error al guardar: ' + (r.data.message || 'Error desconocido'));
                return;
            }
            alert('Precio actualizado');
            
            // Actualizar el precio en el producto actual
            currentProduct.precio_local = r.data.item;
            
            // Ocultar editor y mostrar vista actualizada
            $('#local-precio-edit').hide();
            $('#local-precio-view').show();
            showDetail(currentProduct);
        });
    });

    // Evento: editar precio online
    $(document).on('click', '#online-price-edit-btn', function(e){
        e.preventDefault();
        if (!currentProduct || !currentProduct.online_details) return;
        
        const price = currentProduct.online_details.price ? parseFloat(currentProduct.online_details.price).toFixed(2) : '0.00';
        $('#online-price-current').text('$' + price);
        $('#online-price-new').val(price);
        
        $('#online-details').hide();
        $('#online-price-editor').show();
        $('#online-price-new').focus();
    });

    // Evento: cancelar edición precio online
    $('#online-price-cancel').on('click', function(e){
        e.preventDefault();
        $('#online-price-editor').hide();
        $('#online-details').show();
    });

    // Evento: guardar precio online
    $('#online-price-save').on('click', function(e){
        e.preventDefault();
        if (!currentProduct) return;
        
        const p_asignado = $('#online-price-new').val();
        const sync_to_woo = $('#online-sync-to-woo').is(':checked');
        
        if (!p_asignado || isNaN(p_asignado)) {
            alert('Ingrese un precio válido');
            return;
        }
        
        const var_id = currentProduct.woocommerce_variation_id ? parseInt(currentProduct.woocommerce_variation_id) : 0;
        
        $.post(ajaxurl, {
            action: 'riverso_products_set_online_price',
            nonce,
            producto_base_id: currentProduct.id,
            woocommerce_variation_id: var_id,
            p_asignado: parseFloat(p_asignado),
            sync_to_woo: sync_to_woo ? 1 : 0
        }, function(r){
            if (!r.success) {
                alert('Error al guardar: ' + (r.data.message || 'Error desconocido'));
                return;
            }
            alert('Precio online actualizado');
            
            // Actualizar el precio en el producto actual
            if (currentProduct.online_details) {
                currentProduct.online_details.price = parseFloat(p_asignado);
            }
            currentProduct.precio_online = r.data.item;
            
            // Ocultar editor y mostrar vista actualizada
            $('#online-price-editor').hide();
            $('#online-details').show();
            showDetail(currentProduct);
        });
    });

    // Evento: ver miembros de familia
    $(document).on('click', '#familia-view-btn', function(e){
        e.preventDefault();
        const grupoId = $(this).data('familia-id') || (currentProduct && currentProduct.familia && currentProduct.familia.id);
        if (!grupoId) return;
        $.post(ajaxurl, { action: 'riverso_families_get', nonce, grupo_id: grupoId }, function(r){
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
    <table class="widefat striped" style="font-size:13px;">
      <thead><tr><th>ID</th><th>SKU</th><th>Nombre</th><th>Envase</th><th>Unid. fam.</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="5">Sin miembros</td></tr>'}</tbody>
    </table>
    ${pendingHtml}
    <div style="margin-top:16px;text-align:right;"><button type="button" class="button" id="familia-view-close">Cerrar</button></div>
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

    // Evento: editar familia
    $(document).on('click', '#familia-edit-toggle', function(e){
        e.preventDefault();
        
        // Cargar lista de familias
        $.post(ajaxurl, {
            action: 'riverso_families_list',
            nonce
        }, function(r){
            if (!r.success) {
                alert('Error al cargar familias');
                return;
            }
            
            const options = r.data.families.map(f => 
                `<option value="${f.id}">${esc(f.nombre)} (${esc(f.tipo_sustitucion)})</option>`
            ).join('');
            
            $('#familia-select').html('<option value="">— Sin familia —</option>' + options);
            
            // Seleccionar familia actual si existe
            if (currentProduct.familia) {
                $('#familia-select').val(currentProduct.familia.id);
            }
            
            // Mostrar editor
            $('#local-familia-view').hide();
            $('#local-familia-edit').show();
        });
    });

    // Evento: cancelar edición familia
    $('#familia-cancel-btn').on('click', function(e){
        e.preventDefault();
        $('#local-familia-edit').hide();
        $('#local-familia-view').show();
    });

    // Evento: guardar familia
    $('#familia-save-btn').on('click', function(e){
        e.preventDefault();
        if (!currentProduct) return;
        
        const grupo_id = $('#familia-select').val();
        if (!grupo_id) {
            // Remover de familia actual (sin implementar para ahora)
            alert('Funcionalidad de remover familia en desarrollo');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_families_add_member',
            nonce,
            grupo_id: parseInt(grupo_id),
            producto_base_id: currentProduct.id,
            prioridad: 100,
            es_preferido: 0
        }, function(r){
            if (!r.success) {
                // Si error es "ya es miembro", cargar producto para actualizar
                if (r.data.message && r.data.message.includes('ya es miembro')) {
                    $.post(ajaxurl, {
                        action: 'riverso_products_get',
                        nonce,
                        id: currentProduct.id
                    }, function(r2){
                        if (r2.success) {
                            currentProduct = r2.data.item;
                            showDetail(currentProduct);
                            alert('Familia ya asignada');
                        }
                    });
                } else {
                    alert('Error al asignar familia: ' + (r.data.message || 'Error desconocido'));
                }
                return;
            }
            
            alert('Familia asignada');
            
            // Recargar producto para actualizar
            $.post(ajaxurl, {
                action: 'riverso_products_get',
                nonce,
                id: currentProduct.id
            }, function(r2){
                if (r2.success) {
                    currentProduct = r2.data.item;
                    showDetail(currentProduct);
                }
            });
        });
    });

    // Evento: mostrar ayuda de completitud
    $('#completeness-help, #completeness-col-help').on('click', function(e){
        e.preventDefault();
        $('#help-completeness').toggleClass('show');
    });

    $('#help-close').on('click', function(){
        $('#help-completeness').removeClass('show');
    });

    let wooSearchTimeout = null;

    // Evento: buscar Woo con debounce
    $(document).on('keyup', '#woo-search', function(){
        const q = $(this).val();
        if (q.length < 2) {
            $('#woo-results').hide();
            clearTimeout(wooSearchTimeout);
            return;
        }
        clearTimeout(wooSearchTimeout);
        wooSearchTimeout = setTimeout(function(){
            $.post(ajaxurl, {
                action: 'riverso_products_search_woo',
                nonce,
                s: q,
                filter: 'solo_online'
            }, function(r){
                if (!r.success) return;
                const html = r.data.results.map(p => {
                    const typeLabel = {'simple': 'Simple', 'variable': 'Variable', 'variation': 'Variación'}[p.type] || p.type;
                    const parentInfo = p.parent_id ? ` | Padre: ${p.parent_id}` : '';
                    return `<div class="woo-result-item" data-id="${p.id}" style="padding:10px; border-bottom:1px solid #eee; cursor:pointer; font-size:13px;">
                        <strong>${esc(p.name)}</strong><br>
                        <small style="color:#666;">ID: ${p.id} | SKU: ${esc(p.sku || '(sin SKU)')} | Tipo: ${typeLabel}${parentInfo}</small>
                    </div>`;
                }).join('');
                $('#woo-results').html(html || '<div style="padding:10px;color:#999;">Sin resultados (solo Online sin Local completo)</div>').show();
            });
        }, 300);
    });

    $(document).on('click', '.woo-result-item', function(){
        const id = $(this).data('id');
        const text = $(this).text();
        $('#woo-selected-id').val(id);
        $('#woo-selected-display').text('Seleccionado: ' + text);
        $('#woo-results').hide();
    });

    // --- Códigos de proveedor: paso 1 proveedor, paso 2 código ---

    let supplierProviderTimer = null;
    let supplierCodeTimer = null;
    let supplierCodeRequestId = 0;

    function setSupplierCodeStepEnabled(enabled) {
        $('#supplier-code-search')
            .prop('disabled', !enabled)
            .attr('placeholder', enabled ? 'Escribe el código (p.ej. 123456)' : 'Selecciona un proveedor primero');
        $('#supplier-code-hint').text(enabled
            ? 'Los códigos ya vinculados aparecen bloqueados.'
            : 'Escribe para buscar entre los códigos de este proveedor.');
    }

    function clearSupplierCodeChoice() {
        $('#supplier-code-select').val('');
        $('#supplier-force-reassign').val('0');
        $('#supplier-code-warning').hide().empty();
        $('#supplier-code-selected').removeClass('is-forced').empty();
    }

    function resetSupplierCodeStep() {
        $('#supplier-code-search').val('');
        $('#supplier-search-results').empty().hide();
        clearSupplierCodeChoice();
    }

    function resetSupplierLinkForm() {
        $('#supplier-id-select').val('');
        $('#supplier-provider-search').val('');
        $('#supplier-provider-results').empty().hide();
        $('#supplier-provider-selected').empty();
        $('#supplier-audit-reason').val('');
        resetSupplierCodeStep();
        setSupplierCodeStepEnabled(false);
        updateSupplierLinkBtnState();
    }

    $('#supplier-link-reset').on('click', resetSupplierLinkForm);

    // Paso 1: proveedor
    $(document).on('input', '#supplier-provider-search', function(){
        clearTimeout(supplierProviderTimer);
        const q = $(this).val().trim();

        // Cambiar de proveedor invalida el código ya elegido.
        $('#supplier-id-select').val('');
        $('#supplier-provider-selected').empty();
        resetSupplierCodeStep();
        setSupplierCodeStepEnabled(false);
        updateSupplierLinkBtnState();

        if (q.length < 2) {
            $('#supplier-provider-results').empty().hide();
            return;
        }

        supplierProviderTimer = setTimeout(function(){
            $.post(ajaxurl, {
                action: 'riverso_search_suppliers',
                nonce,
                search: q,
                limit: 15
            }, function(r){
                if (!r.success) return;
                const items = r.data.suppliers || [];
                const html = items.length
                    ? items.map(s => {
                        const apodoHint = s.matched_apodo
                            ? `<br><small style="color:#8c8f94;">Apodo: ${esc(s.matched_apodo)}</small>`
                            : '';
                        return `<div class="supplier-picker-item supplier-provider-pick" data-id="${s.id}" data-nombre="${escAttr(s.nombre)}">
                            <strong>${esc(s.nombre)}</strong> <span style="color:#666;">${esc(s.rut || '')}</span>
                            ${apodoHint}
                        </div>`;
                    }).join('')
                    : '<div class="supplier-picker-empty">Sin proveedores para esa búsqueda.</div>';
                $('#supplier-provider-results').html(html).show();
            });
        }, 250);
    });

    $(document).on('click', '.supplier-provider-pick', function(){
        const nombre = String($(this).data('nombre') || '');
        $('#supplier-id-select').val($(this).data('id'));
        $('#supplier-provider-search').val(nombre);
        $('#supplier-provider-selected').text('Proveedor: ' + nombre);
        $('#supplier-provider-results').empty().hide();
        setSupplierCodeStepEnabled(true);
        updateSupplierLinkBtnState();
        $('#supplier-code-search').trigger('focus');
    });

    // Paso 2: código del proveedor
    $(document).on('input', '#supplier-code-search', function(){
        clearTimeout(supplierCodeTimer);
        const q = $(this).val().trim();
        const supplierId = $('#supplier-id-select').val();

        clearSupplierCodeChoice();
        updateSupplierLinkBtnState();

        if (!supplierId || q.length < 2) {
            $('#supplier-search-results').empty().hide();
            return;
        }

        const requestId = ++supplierCodeRequestId;
        supplierCodeTimer = setTimeout(function(){
            $.post(ajaxurl, {
                action: 'riverso_codes_search_by_supplier',
                nonce,
                supplier_id: supplierId,
                query: q,
                limit: 25
            }, function(r){
                // Descartar respuestas que llegan fuera de orden.
                if (requestId !== supplierCodeRequestId) return;
                if (!r.success) {
                    $('#supplier-search-results')
                        .html(`<div class="supplier-picker-empty">${esc(r.data?.message || 'Error buscando códigos')}</div>`)
                        .show();
                    return;
                }
                renderSupplierCodeResults(r.data, q);
            });
        }, 300);
    });

    function renderSupplierCodeResults(data, query) {
        const results = data.results || [];
        const available = results.filter(c => !c.linked);
        const linked = results.filter(c => c.linked);
        let html = '';

        if (available.length) {
            html += '<div class="supplier-picker-group">Disponibles</div>';
            html += available.map(c => `
                <div class="supplier-picker-item supplier-code-pick" data-code="${escAttr(c.codigo_proveedor)}">
                    <strong>${esc(c.codigo_proveedor)}</strong>
                    ${c.descripcion ? `<br><small style="color:#666;">${esc(c.descripcion)}</small>` : ''}
                </div>`).join('');
        }

        if (data.can_create) {
            html += '<div class="supplier-picker-group">Ingresar manualmente</div>';
            html += `<div class="supplier-picker-item is-create supplier-code-pick" data-code="${escAttr(query)}">
                    Usar <strong>${esc(query)}</strong> como código nuevo de este proveedor
                </div>`;
        }

        if (linked.length) {
            html += '<div class="supplier-picker-group">Ya vinculados — no seleccionables</div>';
            html += linked.map(c => `
                <div class="supplier-picker-item is-linked supplier-code-linked"
                     data-code="${escAttr(c.codigo_proveedor)}"
                     data-sku="${escAttr(c.canonical_sku || '')}"
                     data-nombre="${escAttr(c.nombre_canonico || '')}"
                     data-base-id="${c.producto_base_id || 0}">
                    <strong>${esc(c.codigo_proveedor)}</strong>
                    <span class="supplier-linked-badge">Vinculado a ${esc(c.canonical_sku || 'otro producto')}</span>
                    ${c.nombre_canonico ? `<br><small>${esc(c.nombre_canonico)}</small>` : ''}
                </div>`).join('');
        }

        if (!html) {
            html = '<div class="supplier-picker-empty">Este proveedor no tiene códigos que coincidan.</div>';
        }

        $('#supplier-search-results').html(html).show();
    }

    $(document).on('click', '.supplier-code-pick', function(){
        const code = String($(this).data('code') || '');
        $('#supplier-code-select').val(code);
        $('#supplier-code-search').val(code);
        $('#supplier-force-reassign').val('0');
        $('#supplier-search-results').empty().hide();
        $('#supplier-code-warning').hide().empty();
        $('#supplier-code-selected').removeClass('is-forced').text('Código: ' + code);
        updateSupplierLinkBtnState();
    });

    $(document).on('click', '.supplier-code-linked', function(){
        const code = String($(this).data('code') || '');
        const sku = String($(this).data('sku') || '');
        const nombre = String($(this).data('nombre') || '');
        const baseId = parseInt($(this).data('base-id') || 0, 10);

        clearSupplierCodeChoice();
        updateSupplierLinkBtnState();

        const owner = sku
            ? `<code>${esc(sku)}</code>${nombre ? ' — ' + esc(nombre) : ''}`
            : 'otro producto';
        const openBtn = baseId
            ? `<button type="button" class="button button-small supplier-open-owner" data-base-id="${baseId}">Ver ese producto</button>`
            : '';

        $('#supplier-code-warning').html(`
            <strong>El código ${esc(code)} ya está vinculado.</strong>
            <div style="margin-top:4px;">Dueño actual: ${owner}</div>
            <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">
                ${openBtn}
                <button type="button" class="button button-small supplier-force-pick" data-code="${escAttr(code)}">Reasignar de todas formas</button>
            </div>
        `).show();
    });

    $(document).on('click', '.supplier-force-pick', function(){
        const code = String($(this).data('code') || '');
        if (!confirm(`El código ${code} se quitará del producto que lo tiene hoy y pasará a este.\n\n¿Continuar?`)) {
            return;
        }
        $('#supplier-code-select').val(code);
        $('#supplier-code-search').val(code);
        $('#supplier-force-reassign').val('1');
        $('#supplier-search-results').empty().hide();
        $('#supplier-code-warning').hide().empty();
        $('#supplier-code-selected').addClass('is-forced').text('Código: ' + code + ' (reasignación forzada)');
        updateSupplierLinkBtnState();
    });

    $(document).on('click', '.supplier-open-owner', function(){
        const baseId = parseInt($(this).data('base-id') || 0, 10);
        if (!baseId) return;
        $.post(ajaxurl, { action: 'riverso_products_get', nonce, id: baseId }, function(r){
            if (r.success && r.data.item) {
                showDetail(r.data.item);
            }
        });
    });

    $(document).on('click', function(e){
        if (!$(e.target).closest('#supplier-provider-search, #supplier-provider-results').length) {
            $('#supplier-provider-results').hide();
        }
        if (!$(e.target).closest('#supplier-code-search, #supplier-search-results').length) {
            $('#supplier-search-results').hide();
        }
    });

    $(document).on('click', '.btn-pp-confirm', function(){
        const id = $(this).data('id');
        if (!confirm('¿Confirmar este vínculo de código a SKU local?')) return;
        $.post(ajaxurl, { action: 'riverso_codes_confirm', nonce, pp_id: id }, function(r){
            if (!r.success) {
                alert(r.data?.message || 'Error al confirmar');
                return;
            }
            if (currentProduct && currentProduct.id) {
                $.post(ajaxurl, { action: 'riverso_products_get', nonce, id: currentProduct.id }, function(resp){
                    if (resp.success) showDetail(resp.data.item || resp.data);
                });
            }
        });
    });

    $(document).on('click', '.btn-pp-reject', function(){
        const id = $(this).data('id');
        if (!confirm('¿Rechazar este código? Quedará inactivo.')) return;
        $.post(ajaxurl, { action: 'riverso_codes_reject', nonce, pp_id: id }, function(r){
            if (!r.success) {
                alert(r.data?.message || 'Error al rechazar');
                return;
            }
            if (currentProduct && currentProduct.id) {
                $.post(ajaxurl, { action: 'riverso_products_get', nonce, id: currentProduct.id }, function(resp){
                    if (resp.success) showDetail(resp.data.item || resp.data);
                });
            }
        });
    });

    // Asignar el código al producto
    $('#supplier-link-btn').on('click', function(){
        const productId = currentProduct.id;
        const code = $('#supplier-code-select').val();
        const supplierId = $('#supplier-id-select').val();
        const reason = $('#supplier-audit-reason').val();

        if (!supplierId) {
            alert('Selecciona primero el proveedor');
            return;
        }
        if (!code) {
            alert('Selecciona o escribe el código de proveedor');
            return;
        }

        function sendLink(force) {
            $.post(ajaxurl, {
                action: 'riverso_products_link_supplier',
                nonce,
                product_id: productId,
                supplier_code: code,
                supplier_id: supplierId,
                audit_reason: reason,
                force: force ? 1 : 0
            }, function(r){
                if (r.success) {
                    alert(r.data.message);
                    showDetail(r.data.item);
                    load();
                    return;
                }
                if (r.data?.conflict && !force) {
                    if (confirm((r.data.message || 'Conflicto de SKU') + '\n\n¿Reasignar de todas formas? El dueño anterior perderá este SKU.')) {
                        sendLink(true);
                    }
                    return;
                }
                alert('Error: ' + (r.data?.message || 'No se pudo vincular'));
            });
        }
        sendLink($('#supplier-force-reassign').val() === '1');
    });

    // --- Merge helpers: todos los Vincular pasan por preview → modal → confirm → merge ---

    // Abrir modal rico de merge (Promise-based)
    function openMergeModal(merge) {
        return new Promise((resolve) => {
            if (!merge) { resolve(false); return; }
            
            const src = merge.source || {};
            const tgt = merge.target || {};
            const woo = merge.woo || {};
            const codes = (merge.codes_to_transfer || []).map(c => c.codigo_proveedor).filter(Boolean);
            const barcodes = (merge.barcodes_to_transfer || []).map(b => b.codigo).filter(Boolean);
            
            let codesHTML = '';
            if (codes.length) {
                codesHTML = '<div style="margin-top:10px;"><strong>Códigos a heredar:</strong><br>' + codes.map(c => '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-right:4px;">' + esc(c) + '</code>').join(' ') + '</div>';
            }
            
            let barcodesHTML = '';
            if (barcodes.length) {
                barcodesHTML = '<div style="margin-top:10px;"><strong>Barcodes a heredar:</strong><br>' + barcodes.map(b => '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-right:4px;">' + esc(b) + '</code>').join(' ') + '</div>';
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
                ? '<button type="button" class="button" disabled style="opacity:0.5;cursor:not-allowed;">Merge bloqueado</button>'
                : '<button type="button" id="merge-modal-confirm" class="button button-primary" style="cursor:pointer;">Confirmar Merge</button>';
            
            const html = `
<div id="merge-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div id="merge-modal-box" style="background:#fff;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.3);padding:30px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;">
    <h2 style="margin:0 0 20px 0;color:#1d2327;">` + (blocked ? 'Merge bloqueado' : 'Confirmar Merge de Productos') + `</h2>
    
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
      <!-- Origen -->
      <div style="border:1px solid #ddd;padding:15px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 10px 0;color:#d32f2f;">Origen (a eliminar)</h4>
        <div style="font-size:12px;line-height:1.6;">
          <div><strong>ID:</strong> #` + (merge.source_id || '?') + `</div>
          <div><strong>SKU Local:</strong> ` + (src.canonical_sku || '<em style="color:#999;">sin SKU</em>') + `</div>
          <div><strong>Nombre:</strong> ` + esc(src.nombre_canonico || 'Sin nombre') + `</div>
          <div style="margin-top:8px;color:#666;font-size:11px;">` + (merge.is_stub_source ? '✓ Stub Solo Online' : '⚠ Local completo') + `</div>
        </div>
      </div>
      
      <!-- Destino -->
      <div style="border:1px solid #ddd;padding:15px;border-radius:6px;background:#f9f9f9;">
        <h4 style="margin:0 0 10px 0;color:#1976d2;">Destino (receptor)</h4>
        <div style="font-size:12px;line-height:1.6;">
          <div><strong>ID:</strong> #` + (merge.target_id || '?') + `</div>
          <div><strong>SKU Local:</strong> ` + (tgt.canonical_sku || '<em style="color:#999;">sin SKU</em>') + `</div>
          <div><strong>Nombre:</strong> ` + esc(tgt.nombre_canonico || 'Sin nombre') + `</div>
          <div style="margin-top:8px;color:#666;font-size:11px;">Recibirá Woo + códigos</div>
        </div>
      </div>
    </div>
    
    <!-- Woo Online -->
    <div style="background:#e8f5e9;border:1px solid #4caf50;padding:12px;border-radius:6px;margin-bottom:15px;">
      <strong style="color:#2e7d32;">SKU Online:</strong> <code style="font-weight:bold;font-size:13px;">` + esc(woo.sku || 'N/A') + `</code>
      <div style="font-size:12px;color:#555;margin-top:4px;">Tipo: ` + (woo.type || 'simple') + ` | ID Padre: ` + (woo.product_id || '-') + ` | ID Variación: ` + (woo.variation_id || '0') + `</div>
    </div>
    
    <!-- Transferencias -->
    ` + codesHTML + `
    ` + barcodesHTML + `
    
    <!-- Warnings -->
    ` + warningsHTML + `
    
    <div style="margin-top:20px;padding-top:15px;border-top:1px solid #ddd;display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" id="merge-modal-cancel" class="button" style="cursor:pointer;">` + (blocked ? 'Cerrar' : 'Cancelar') + `</button>
      ` + confirmBtn + `
    </div>
  </div>
</div>
            `.trim();
            
            $('body').append(html);
            
            $('#merge-modal-cancel').on('click', function(){
                $('#merge-modal-overlay').remove();
                resolve(false);
            });
            
            $('#merge-modal-confirm').on('click', function(){
                $('#merge-modal-overlay').remove();
                resolve(true);
            });
            
            // Cerrar al presionar ESC
            $(document).on('keydown.merge-modal', function(e){
                if (e.key === 'Escape') {
                    $('#merge-modal-overlay').remove();
                    resolve(false);
                    $(document).off('keydown.merge-modal');
                }
            });
        });
    }

    function postSetOnlineWithMerge(productId, wooId, done) {
        const payload = {
            action: 'riverso_products_set_online',
            nonce,
            product_id: productId,
            woo_id: wooId,
            confirm_merge: 0
        };
        $.post(ajaxurl, payload, function(r){
            if (r.success) {
                done(r);
                return;
            }
            if (r.data && r.data.needs_merge) {
                openMergeModal(r.data.merge).then(confirmed => {
                    if (!confirmed) return;
                    payload.confirm_merge = 1;
                    $.post(ajaxurl, payload, function(r2){
                        if (!r2.success) {
                            alert('Error: ' + (r2.data && r2.data.message ? r2.data.message : 'Merge falló'));
                            return;
                        }
                        done(r2);
                    });
                });
                return;
            }
            alert('Error: ' + (r.data && r.data.message ? r.data.message : 'No se pudo vincular'));
        });
    }

    // Evento: vincular WooCommerce (detalle Online) → siempre vía merge si hay conflicto
    $('#online-link-btn').on('click', function(){
        const productId = currentProduct.id;
        const wooId = $('#woo-selected-id').val();

        if (!wooId) {
            alert('Selecciona un producto WooCommerce primero');
            return;
        }

        postSetOnlineWithMerge(productId, wooId, function(r){
            alert(r.data.message || 'Producto online vinculado');
            showDetail(r.data.item);
            load();
        });
    });

    // Panel Local vacío: Buscar Local existente
    $('#detail-local-search').on('keyup', function(e){
        const search = $(this).val().trim();
        if (search.length < 2) {
            $('#detail-local-suggestions').html('').hide();
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            search: search,
            limit: 10,
            status: 'active',
            completeness: 'falta_online'
        }, function(r){
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
        $.post(ajaxurl, {
            action: 'riverso_products_next_sku',
            nonce
        }, function(r){
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

        const payload = {
            action: 'riverso_products_adopt_local',
            nonce,
            source_id: currentProduct.id,
            target_id: targetLocalId,
            confirm_merge: 0
        };

        $.post(ajaxurl, payload, function(r){
            if (r.success) {
                alert(r.data.message);
                showDetail(r.data.item);
                load();
                return;
            }
            if (r.data && r.data.needs_merge) {
                openMergeModal(r.data.merge).then(confirmed => {
                    if (!confirmed) return;
                    payload.confirm_merge = 1;
                    $.post(ajaxurl, payload, function(r2){
                        if (!r2.success) {
                            alert('Error: ' + (r2.data && r2.data.message ? r2.data.message : 'Merge falló'));
                            return;
                        }
                        alert(r2.data.message);
                        showDetail(r2.data.item);
                        load();
                    });
                });
                return;
            }
            alert('Error: ' + (r.data && r.data.message ? r.data.message : 'No se pudo vincular'));
        });
    });

    // Evento: abrir modal crear online
    $('#online-create-btn').on('click', function(){
        $('#create-name').val(currentProduct.nombre_canonico || '');
        $('#create-sku').val(currentProduct.canonical_sku || '');
        $('#create-nominal').val('');
        $('#create-largo').val('');
        $('input[name="create-type"][value="simple"]').prop('checked', true);
        $('#create-variable-attrs').hide();
        $('#create-online-modal').addClass('show');
    });

    // Evento: cambiar tipo de producto
    $(document).on('change', 'input[name="create-type"]', function(){
        const type = $(this).val();
        
        if (type === 'variable') {
            $('#create-variable-section').show();
            $('#create-child-section').hide();
            // Si no hay atributos, mostrar sugerencia de Nominal x Largo
            if ($('#create-attributes-list').children().length === 0) {
                addCreateAttribute('Nominal', '', false, true);
                addCreateAttribute('Largo', '', false, true);
            }
        } else if (type === 'child') {
            $('#create-variable-section').hide();
            $('#create-child-section').show();
            // Cargar sugerencias de padres
            loadParentSuggestions();
        } else {
            $('#create-variable-section').hide();
            $('#create-child-section').hide();
        }
    });

    // Cargar sugerencias de padres variables
    let parentSearchTimeout = null;
    function loadParentSuggestions(){
        const searchTerm = $('#create-parent-search').val() || currentProduct.nombre_canonico || '';
        
        clearTimeout(parentSearchTimeout);
        parentSearchTimeout = setTimeout(function(){
            if (searchTerm.length < 1) {
                $('#create-parent-suggestions').empty();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'riverso_products_suggest_variable_parents',
                nonce,
                search: searchTerm,
                catalog_id: $('#create-parent-catalog').val() || 0
            }, function(r){
                if (!r.success) {
                    $('#create-parent-suggestions').html('<div style="padding:8px; color:#999;">Sin sugerencias</div>');
                    return;
                }
                
                const suggestions = r.data.suggestions || [];
                if (suggestions.length === 0) {
                    $('#create-parent-suggestions').html('<div style="padding:8px; color:#999;">Sin coincidencias</div>');
                    return;
                }
                
                let html = '';
                suggestions.forEach(parent => {
                    const childCount = parent.child_count || 0;
                    const catalogo = parent.catalogo || 'Sin catálogo';
                    const codigoCat = parent.codigo_catalogo ? ` | Códigos hijos: ${esc(parent.codigo_catalogo)}` : '';
                    const hint = parent.match_hint ? `<br><small style="color:#2271b1;">${esc(parent.match_hint)}</small>` : '';
                    const skuBits = [];
                    if (parent.sku_local) skuBits.push(`SKU Local: ${esc(parent.sku_local)}`);
                    if (parent.sku_online) skuBits.push(`SKU Online: ${esc(parent.sku_online)}`);
                    if (!skuBits.length && parent.sku) skuBits.push(`SKU: ${esc(parent.sku)}`);
                    html += `<div class="parent-suggestion" data-id="${parent.id}" style="padding:8px; border-bottom:1px solid #e5e5e5; cursor:pointer;">
                        <div>
                            <strong>${esc(parent.name)}</strong>${hint}<br>
                            <small style="color:#666;">ID: ${parent.id} | ${skuBits.join(' | ') || 'Sin SKU'} | ${childCount} hijo(s) | Catálogo: ${esc(catalogo)}${codigoCat}</small>
                        </div>
                    </div>`;
                });
                $('#create-parent-suggestions').html(html);
            });
        }, 300);
    }

    // Buscar padres al escribir o cambiar catálogo
    $(document).on('keyup', '#create-parent-search', function(){
        loadParentSuggestions();
    });
    $(document).on('change', '#create-parent-catalog', function(){
        loadParentSuggestions();
    });

    // Seleccionar padre
    $(document).on('click', '.parent-suggestion', function(){
        const parentId = $(this).data('id');
        const parentName = $(this).find('strong').text();
        const parentSku = $(this).find('small').text().match(/SKU: ([^\s|]+)/)?.[1] || '';
        
        $('#create-parent-id').val(parentId);
        $('#create-parent-selected').html(`<strong>Padre seleccionado:</strong> ${esc(parentName)} (${esc(parentSku)})`).show();
        $('#create-parent-suggestions').empty();
        
        // Cargar atributos del padre
        loadParentAttributes(parentId);
    });

    // Cargar atributos + hijos del padre
    function loadParentAttributes(parentId){
        $('#create-parent-attrs-list').html('<em>Cargando…</em>');
        $('#create-parent-children-list').html('');
        $('#create-parent-children-summary').text('');
        $('#create-parent-attrs').show();

        $.post(ajaxurl, {
            action: 'riverso_products_get_variable_parent_details',
            nonce,
            parent_id: parentId
        }, function(r){
            if (!r.success) {
                $('#create-parent-attrs').hide();
                return;
            }

            const attrs = r.data.attributes || [];
            const children = r.data.children || [];
            const parent = r.data.parent || {};

            let attrHtml = '';
            if (attrs.length === 0) {
                attrHtml = '<em style="color:#999;">Sin atributos de variación</em>';
            } else {
                attrs.forEach(attr => {
                    attrHtml += `<div style="margin-bottom:6px;"><strong>${esc(attr.name)}</strong>: ${(attr.options || []).map(o => esc(String(o))).join(', ')}</div>`;
                });
            }
            $('#create-parent-attrs-list').html(attrHtml);

            const withLocal = parent.with_local_sku || 0;
            const withoutLocal = parent.without_local_sku || 0;
            $('#create-parent-children-summary').text(
                `${children.length} hijo(s): ${withLocal} con SKU Local · ${withoutLocal} sin SKU Local`
            );

            if (children.length === 0) {
                $('#create-parent-children-list').html('<div style="padding:8px; color:#999;">Sin variaciones</div>');
                return;
            }

            let childHtml = '<table style="width:100%; border-collapse:collapse; font-size:12px;">';
            childHtml += '<thead><tr style="background:#f0f0f1; text-align:left;">'
                + '<th style="padding:6px; border-bottom:1px solid #ddd;">Atributos</th>'
                + '<th style="padding:6px; border-bottom:1px solid #ddd;">SKUs</th>'
                + '<th style="padding:6px; border-bottom:1px solid #ddd;">Local</th>'
                + '</tr></thead><tbody>';

            children.forEach(ch => {
                const badge = ch.has_local_sku
                    ? '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:2px;font-size:11px;">Con SKU Local</span>'
                    : '<span style="background:#dc3545;color:#fff;padding:2px 6px;border-radius:2px;font-size:11px;">Sin SKU Local</span>';

                const skuTags = (ch.sku_labels || []).map(lab => {
                    let bg = '#6c757d', fg = '#fff';
                    if (lab.type === 'local') { bg = '#0d6efd'; }
                    else if (lab.type === 'online') { bg = '#6f42c1'; }
                    else if (lab.type === 'catalogo') { bg = '#fd7e14'; }
                    else if (lab.type === 'otro') { bg = '#adb5bd'; fg = '#333'; }
                    const val = lab.value ? `: ${esc(lab.value)}` : '';
                    const cat = lab.catalogo ? ` (${esc(lab.catalogo)})` : '';
                    return `<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 6px;border-radius:2px;background:${bg};color:${fg};font-size:11px;">${esc(lab.label)}${val}${cat}</span>`;
                }).join('');

                childHtml += `<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:6px; vertical-align:top;">
                        <div><strong>#${ch.variation_id}</strong></div>
                        <div style="color:#555;">${esc(ch.attributes_text || 'Sin atributos')}</div>
                    </td>
                    <td style="padding:6px; vertical-align:top;">${skuTags || '<em style="color:#999;">—</em>'}</td>
                    <td style="padding:6px; vertical-align:top; white-space:nowrap;">${badge}</td>
                </tr>`;
            });
            childHtml += '</tbody></table>';
            $('#create-parent-children-list').html(childHtml);
        });
    }

    // Agregar atributo dinámico
    function addCreateAttribute(name = '', value = '', visible = false, variation = false){
        const id = 'attr-' + Date.now() + Math.random().toString(36).substr(2, 9);
        const html = `
            <div class="create-attribute-row" id="${id}" style="padding:8px; margin-bottom:6px; background:#f9f9f9; border:1px solid #e5e5e5; border-radius:2px; display:flex; gap:8px; align-items:flex-start;">
                <div style="flex:1;">
                    <input type="text" class="attr-name regular-text" placeholder="Nombre" value="${esc(name)}" style="width:100%; margin-bottom:4px;">
                    <input type="text" class="attr-value regular-text" placeholder="Valor" value="${esc(value)}" style="width:100%;">
                </div>
                <div style="display:flex; gap:4px; flex-direction:column; white-space:nowrap;">
                    <label style="margin:0; font-size:12px; display:flex; gap:2px; align-items:center;">
                        <input type="checkbox" class="attr-variation" ${variation ? 'checked' : ''}>
                        Usa para hijos
                    </label>
                    <label style="margin:0; font-size:12px; display:flex; gap:2px; align-items:center;">
                        <input type="checkbox" class="attr-visible" ${visible ? 'checked' : ''}>
                        Visible
                    </label>
                    <button class="button button-small attr-remove" type="button" data-id="${id}" style="margin-top:4px;">Quitar</button>
                </div>
            </div>
        `;
        $('#create-attributes-list').append(html);
    }

    // Botón Agregar atributo
    $(document).on('click', '#create-attr-add', function(e){
        e.preventDefault();
        addCreateAttribute('', '', false, true);
    });

    // Botón Quitar atributo
    $(document).on('click', '.attr-remove', function(e){
        e.preventDefault();
        const id = $(this).data('id');
        $('#' + id).remove();
    });

    // Evento: crear producto online
    $('#create-online-submit').on('click', function(){
        const productId = currentProduct.id;
        const productType = $('input[name="create-type"]:checked').val();
        const name = $('#create-name').val();
        const sku = $('#create-sku').val();

        if (!name || !sku) {
            alert('Nombre y SKU son requeridos');
            return;
        }

        if (productType === 'child') {
            const parentId = $('#create-parent-id').val();
            if (!parentId) {
                alert('Debes seleccionar un padre variable');
                return;
            }
        }

        const data = {
            action: 'riverso_products_create_online',
            nonce,
            product_id: productId,
            product_type: productType,
            woo_name: name,
            woo_sku: sku,
        };

        if (productType === 'variable') {
            // Recolectar atributos del editor
            const attributes = [];
            $('#create-attributes-list').find('.create-attribute-row').each(function(){
                const attrName = $(this).find('.attr-name').val();
                const attrValue = $(this).find('.attr-value').val();
                const variation = $(this).find('.attr-variation').is(':checked');
                const visible = $(this).find('.attr-visible').is(':checked');
                
                if (attrName && attrValue) {
                    attributes.push({
                        name: attrName,
                        value: attrValue,
                        variation: variation,
                        visible: visible
                    });
                }
            });
            
            if (attributes.length === 0) {
                // Permitir crear variable sin atributos (solo padre)
                data.attributes = [];
            } else {
                data.attributes = attributes;
            }
        } else if (productType === 'child') {
            data.parent_id = $('#create-parent-id').val();
            data.attach_mode = $('input[name="create-attach-mode"]:checked').val();
        }

        $.post(ajaxurl, data, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            closeCreateModal();
            showDetail(r.data.item);
            load();
        });
    });

    // Función para cerrar modal
    function closeCreateModal(){
        $('#create-online-modal').removeClass('show');
        $('#create-name').val('');
        $('#create-sku').val('');
        $('#create-nominal').val('');
        $('#create-largo').val('');
        $('input[name="create-type"][value="simple"]').prop('checked', true);
        $('#create-variable-attrs').hide();
    }

    // Evento: cerrar modal (botón Cancelar)
    $('#create-online-cancel').on('click', function(){
        closeCreateModal();
    });

    // Cerrar al hacer click en el overlay (no en el contenido del modal)
    $('#create-online-modal').on('click', function(e){
        if ($(e.target).attr('id') === 'create-online-modal') {
            closeCreateModal();
        }
    });

    // Cerrar con ESC
    $(document).on('keydown', function(e){
        if (e.key === 'Escape' && $('#create-online-modal').hasClass('show')) {
            closeCreateModal();
        }
    });

    // Evento: ir a tab códigos desde banner
    $('#online-assign-code-btn').on('click', function(){
        $('.detail-tab').removeClass('active');
        $('[data-tab="suppliers"]').addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-suppliers').show();
        $('html, body').animate({scrollTop: $('#tab-suppliers').offset().top - 40}, 300);
    });

    // Evento: ir a tab desde task
    $(document).on('click', '.task-goto', function(){
        const tab = $(this).data('tab');
        $('.detail-tab').removeClass('active');
        $('[data-tab="' + tab + '"]').addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-' + tab).show();
        $('html, body').animate({scrollTop: $('#tab-' + tab).offset().top - 40}, 300);
    });

    // Evento: click en items del tooltip de alertas
    $(document).on('click', '.alerts-tooltip-item', function(){
        const action = $(this).data('alert-action');
        if (action === 'edit-sku') {
            // Cambiar al tab local y entrar en modo edición
            $('.detail-tab').removeClass('active');
            $('[data-tab="local"]').addClass('active');
            $('.detail-tab-content').hide();
            $('#tab-local').show();
            enterEditMode();
            $('#local-sku-edit').focus();
        } else if (action && action.startsWith('tab-')) {
            // Cambiar al tab especificado
            const tab = action.replace('tab-', '');
            $('.detail-tab').removeClass('active');
            $('[data-tab="' + tab + '"]').addClass('active');
            $('.detail-tab-content').hide();
            $('#tab-' + tab).show();
            $('html, body').animate({scrollTop: $('#tab-' + tab).offset().top - 40}, 300);
        }
    });

    // Evento: cambiar tipo de barcode (mostrar/ocultar selector de proveedor)
    $(document).on('change', '#barcode-type', function(){
        const tipo = $(this).val();
        if (tipo === 'supplier') {
            $('#barcode-supplier-section').show();
            // Poblar select de proveedores del producto actual
            if (currentProduct && currentProduct.proveedores) {
                let options = '<option value="">— Seleccione proveedor —</option>';
                currentProduct.proveedores.forEach(p => {
                    options += `<option value="${p.proveedor_id || p.id}">${esc(p.proveedor_nombre || 'Proveedor')}</option>`;
                });
                $('#barcode-proveedor').html(options);
            }
        } else {
            $('#barcode-supplier-section').hide();
        }
    });

    // Evento: agregar barcode
    $('#barcode-add-btn').on('click', function(){
        const productId = currentProduct.id;
        const barcode = $('#barcode-new').val();
        const tipo = $('#barcode-type').val() || 'ean13';
        const proveedorId = $('#barcode-proveedor').val() || 0;
        const cantidad = $('#barcode-cantidad').val() || 1;
        const unidad = $('#barcode-unidad').val() || 'unidad';
        const origen = $('#barcode-origen').val() || 'manual';
        const reason = $('#barcode-audit-reason').val();

        if (!barcode) {
            alert('Ingresa un código de barra');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_products_add_barcode',
            nonce,
            product_id: productId,
            barcode: barcode,
            tipo: tipo,
            proveedor_id: proveedorId,
            cantidad: cantidad,
            unidad_medida: unidad,
            origen_datos: origen,
            audit_reason: reason
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            $('#barcode-new').val('');
            $('#barcode-audit-reason').val('');
            $('#barcode-type').val('ean13').trigger('change');
            showDetail(r.data.item);
        });
    });

    $(document).on('click', '.barcode-remove', function(){
        const barcode = $(this).data('barcode');
        const reason = prompt('Motivo de remoción:');
        if (reason === null) return;

        $.post(ajaxurl, {
            action: 'riverso_products_remove_barcode',
            nonce,
            product_id: currentProduct.id,
            barcode: barcode,
            audit_reason: reason
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            showDetail(r.data.item);
        });
    });

    // Evento: ver producto
    $(document).on('click', '.product-view, .completeness-badge[data-product-id]', function(){
        const productId = $(this).attr('data-product-id') || $(this).data('id');
        const tab = $(this).hasClass('completeness-badge') ? 'suppliers' : undefined;
        
        $.post(ajaxurl, {
            action: 'riverso_products_get',
            nonce,
            id: productId
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            showDetail(r.data.item);
            
            // Si se hizo click en el badge "Falta código", ir al tab de suppliers
            if (tab) {
                setTimeout(function(){
                    $('.detail-tab').removeClass('active');
                    $('[data-tab="' + tab + '"]').addClass('active');
                    $('.detail-tab-content').hide();
                    $('#tab-' + tab).show();
                }, 100);
            }
        });
    });

    // Evento: editar producto
    $(document).on('click', '.product-edit', function(){
        $.post(ajaxurl, {
            action: 'riverso_products_get',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            const it = r.data.item;
            $('#product-id').val(it.id);
            $('#product-sku').val(it.canonical_sku);
            $('#product-name').val(it.nombre_canonico);
            $('#product-unit').val(it.unidad_base);
            $('#product-decimal').prop('checked', parseInt(it.permite_decimal) === 1);
            $('#product-ean13').prop('checked', parseInt(it.permite_ean13_personalizado) === 1);
            $('#product-open-stock').prop('checked', parseInt(it.stock_abierto_habilitado) === 1);
            $('#product-editor-title').text('Editar: ' + it.nombre_canonico);
            $('#product-editor').show();
        });
    });

    // Evento: nuevo producto online (wizard)
    $('#products-new-online').on('click', function(){
        resetOnlineWizard();
        $('#create-online-modal').addClass('show').css('display', 'flex');
    });

    // Pestaña del wizard: cambiar tab
    $('.wizard-tab').on('click', function(){
        const tab = $(this).data('tab');
        $('.wizard-tab').removeClass('active').css({'border-bottom-color': 'transparent', 'color': '#666', 'font-weight': 'normal'});
        $(this).addClass('active').css({'border-bottom-color': '#2271b1', 'color': '#2271b1', 'font-weight': 'bold'});
        $('.wizard-tab-content').css('display', 'none');
        $('.wizard-tab-content[data-tab="' + tab + '"]').css('display', 'block');
    });

    function resetOnlineWizard() {
        $('input[name="create-type"]').val(['simple']);
        $('#create-name').val('');
        $('#create-sku').val('');
        $('#create-price').val('');
        $('#create-parent-id').val('');
        $('#create-parent-selected').hide();
        $('#create-local-id').val('');
        $('#create-local-selected').hide();
        $('#create-local-new-sku').val('');
        $('#create-local-new-sku-preview').hide();
        $('#create-local-new-sku-input').val('');
        $('#link-woo-selected-id').val('');
        $('#link-woo-selected').hide();
        $('#link-local-id').val('');
        $('#link-local-selected').hide();
        $('#link-local-new-sku').val('');
        $('#link-local-new-sku-preview').hide();
        $('#link-local-new-sku-input').val('');
        $('#link-woo-merge-warnings').hide();
        $('#link-woo-warnings-list').html('');
        
        // Cargar categorías WooCommerce
        loadWooCategories();
    }
    
    function loadWooCategories() {
        $.post(ajaxurl, {
            action: 'riverso_products_get_woo_categories',
            nonce
        }, function(r){
            if (r.success) {
                const categories = r.data.categories || [];
                let html = '';
                categories.forEach(cat => {
                    const indent = '&nbsp;&nbsp;'.repeat(cat.level);
                    html += '<label style="display:block; padding:4px 0; margin:2px 0;">'
                        + '<input type="checkbox" class="woo-cat-checkbox" value="' + cat.id + '" data-name="' + esc(cat.name) + '">'
                        + ' ' + indent + esc(cat.name)
                        + '</label>';
                });
                $('#create-categories-list').html(html || '<div style="color:#999;">Sin categorías</div>');
            }
        });
    }

    // Evento: cerrar wizard
    $('#create-online-cancel').on('click', function(){
        $('#create-online-modal').removeClass('show').css('display', 'none');
        resetOnlineWizard();
    });

    // Cerrar modal al hacer click en el overlay
    $('#create-online-modal').on('click', function(e){
        if ($(e.target).attr('id') === 'create-online-modal') {
            $(this).removeClass('show').css('display', 'none');
            resetOnlineWizard();
        }
    });

    // TAB CREAR: cambio de tipo (simple/variable/child)
    $('input[name="create-type"]').on('change', function(){
        const type = $(this).val();
        $('#create-variable-section').hide();
        $('#create-child-section').hide();
        if (type === 'variable') {
            $('#create-variable-section').show();
        } else if (type === 'child') {
            $('#create-child-section').show();
        }
    });

    // TAB CREAR: buscar Producto Local
    $('#create-local-search').on('keyup', function(e){
        const search = $(this).val().trim();
        if (search.length < 2) {
            $('#create-local-suggestions').html('');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            search: search,
            limit: 10,
            status: 'active',
            completeness: 'falta_online'
        }, function(r){
            if (r.success) {
                const items = r.data.items || [];
                let html = '';
                items.forEach(item => {
                    const display = esc(item.canonical_sku || '') + ' - ' + esc(item.nombre_canonico || '');
                    html += '<div style="padding:8px; border-bottom:1px solid #eee; cursor:pointer; font-size:13px;" class="create-local-option" data-id="' + item.id + '" data-display="' + esc(display) + '">' + display + '</div>';
                });
                $('#create-local-suggestions').html(html || '<div style="padding:8px; color:#999;">Sin resultados (solo Falta Online)</div>');
            }
        });
    });

    $(document).on('click', '.create-local-option', function(){
        const id = $(this).data('id');
        const display = $(this).data('display');
        $('#create-local-id').val(id);
        $('#create-local-selected').html(display).show();
        $('#create-local-suggestions').html('');
        $('#create-local-search').val('');
        // Limpiar SKU generado si el usuario busca un Local existente
        $('#create-local-new-sku').val('');
        $('#create-local-new-sku-preview').hide();
    });

    // TAB CREAR: Generar nuevo SKU Local
    $('#create-local-generate-sku').on('click', function(){
        $(this).prop('disabled', true).text('Obteniendo siguiente SKU...');
        $.post(ajaxurl, {
            action: 'riverso_products_next_sku',
            nonce
        }, function(r){
            $('#create-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            if (r.success) {
                const nextSku = r.data.next_sku;
                $('#create-local-new-sku').val(nextSku);
                $('#create-local-new-sku-input').val(nextSku).prop('readonly', false);
                $('#create-local-new-sku-preview').show();
                // Limpiar búsqueda de Local existente
                $('#create-local-id').val('');
                $('#create-local-selected').hide();
                $('#create-local-search').val('');
                $('#create-local-suggestions').html('');
            } else {
                alert('Error: ' + r.data.message);
            }
        }).fail(function(){
            $('#create-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            alert('Error al obtener el siguiente SKU');
        });
    });

    // TAB VINCULAR: Generar nuevo SKU Local
    $('#link-local-generate-sku').on('click', function(){
        $(this).prop('disabled', true).text('Obteniendo siguiente SKU...');
        $.post(ajaxurl, {
            action: 'riverso_products_next_sku',
            nonce
        }, function(r){
            $('#link-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            if (r.success) {
                const nextSku = r.data.next_sku;
                $('#link-local-new-sku').val(nextSku);
                $('#link-local-new-sku-input').val(nextSku).prop('readonly', false);
                $('#link-local-new-sku-preview').show();
                // Limpiar búsqueda de Local existente
                $('#link-local-id').val('');
                $('#link-local-selected').hide();
                $('#link-local-search').val('');
                $('#link-local-suggestions').html('');
            } else {
                alert('Error: ' + r.data.message);
            }
        }).fail(function(){
            $('#link-local-generate-sku').prop('disabled', false).text('Generar nuevo SKU Local');
            alert('Error al obtener el siguiente SKU');
        });
    });

    // TAB CREAR: editar SKU generado
    $('#create-local-new-sku-input').on('change', function(){
        $('#create-local-new-sku').val($(this).val());
    });

    // TAB VINCULAR: editar SKU generado
    $('#link-local-new-sku-input').on('change', function(){
        $('#link-local-new-sku').val($(this).val());
    });

    // TAB VINCULAR: buscar Woo existente
    $('#link-woo-search').on('keyup', function(e){
        const search = $(this).val().trim();
        if (search.length < 2) {
            $('#link-woo-results').html('');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_search_woo',
            nonce,
            s: search,
            limit: 20,
            filter: 'solo_online'
        }, function(r){
            if (r.success) {
                const results = r.data.results || [];
                let html = '';
                results.forEach(prod => {
                    const type_label = {'simple': 'Simple', 'variable': 'Variable', 'variation': 'Variación'}[prod.type] || prod.type;
                    html += '<div style="padding:10px; border-bottom:1px solid #eee; cursor:pointer; font-size:13px;" class="link-woo-result" data-id="' + prod.id + '" data-parent="' + (prod.parent_id || '') + '" data-type="' + prod.type + '" data-name="' + esc(prod.name) + '" data-sku="' + esc(prod.sku) + '">'
                        + '<strong>' + esc(prod.name) + '</strong><br>'
                        + '<small style="color:#666;">SKU: ' + esc(prod.sku || '(sin SKU)') + ' | Tipo: ' + type_label + '</small>'
                        + '</div>';
                });
                $('#link-woo-results').html(html || '<div style="padding:10px; color:#999;">Sin resultados</div>');
            }
        });
    });

    $(document).on('click', '.link-woo-result', function(){
        const id = $(this).data('id');
        const name = $(this).data('name');
        const sku = $(this).data('sku');
        const type = $(this).data('type');
        $('#link-woo-selected-id').val(id);
        $('#link-woo-selected').html(`<strong>${esc(name)}</strong><br><small style="color:#666;">SKU: ${esc(sku || '(sin SKU)')} | Tipo: ${type}</small>`).show();
        $('#link-woo-results').html('');
        $('#link-woo-search').val('');
        
        // Evaluar posibles conflictos de merge
        $.post(ajaxurl, {
            action: 'riverso_products_evaluate_online',
            nonce,
            woo_id: id,
            woo_sku: sku,
            local_id: parseInt($('#link-local-id').val() || 0),
            product_type: 'simple'
        }, function(r){
            if (r.success && r.data.warnings && r.data.warnings.length) {
                let html = '';
                r.data.warnings.forEach(w => {
                    const icon = w.severity === 'warning' ? '⚠️' : 'ℹ️';
                    html += `<li style="margin-bottom:6px;"><strong>${icon}</strong> ${esc(w.message)}</li>`;
                });
                $('#link-woo-warnings-list').html(html);
                $('#link-woo-merge-warnings').show();
            } else {
                $('#link-woo-merge-warnings').hide();
            }
        });
    });

    // TAB VINCULAR: buscar Producto Local
    $('#link-local-search').on('keyup', function(e){
        const search = $(this).val().trim();
        if (search.length < 2) {
            $('#link-local-suggestions').html('');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            search: search,
            limit: 10,
            status: 'active',
            completeness: 'falta_online'
        }, function(r){
            if (r.success) {
                const items = r.data.items || [];
                let html = '';
                items.forEach(item => {
                    const display = esc(item.canonical_sku || '') + ' - ' + esc(item.nombre_canonico || '');
                    html += '<div style="padding:8px; border-bottom:1px solid #eee; cursor:pointer; font-size:13px;" class="link-local-option" data-id="' + item.id + '" data-display="' + esc(display) + '">' + display + '</div>';
                });
                $('#link-local-suggestions').html(html || '<div style="padding:8px; color:#999;">Sin resultados (solo Falta Online)</div>');
            }
        });
    });

    $(document).on('click', '.link-local-option', function(){
        const id = $(this).data('id');
        const display = $(this).data('display');
        $('#link-local-id').val(id);
        $('#link-local-selected').html(display).show();
        $('#link-local-suggestions').html('');
        $('#link-local-search').val('');
        // Limpiar SKU generado si el usuario busca un Local existente
        $('#link-local-new-sku').val('');
        $('#link-local-new-sku-preview').hide();
    });

    // Evento: guardar (crear o vincular)
    $('#create-online-submit').on('click', function(){
        const activeTab = $('.wizard-tab-content:visible').data('tab');
        if (activeTab === 'create') {
            handleCreateOnlineProduct();
        } else if (activeTab === 'link') {
            handleLinkOnlineProduct();
        }
    });

    function handleCreateOnlineProduct() {
        const type = $('input[name="create-type"]:checked').val();
        const name = $('#create-name').val().trim();
        const sku = $('#create-sku').val().trim();
        const price = parseFloat($('#create-price').val() || 0);
        const local_id = parseInt($('#create-local-id').val() || 0);
        const new_local_sku = $('#create-local-new-sku').val().trim();
        
        // Recopilar categorías seleccionadas
        const categories = [];
        $('.woo-cat-checkbox:checked').each(function(){
            categories.push(parseInt($(this).val()));
        });

        if (!name || !sku) {
            alert('Por favor completa Nombre y SKU');
            return;
        }

        const payload = {
            action: 'riverso_products_create_online_standalone',
            nonce,
            product_type: type,
            woo_name: name,
            woo_sku: sku,
            woo_price: price,
            local_id: local_id,
            new_local_sku: new_local_sku,
            woo_categories: JSON.stringify(categories),
        };

        $.post(ajaxurl, payload, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert('Producto creado exitosamente');
            $('#create-online-modal').removeClass('show').css('display', 'none');
            resetOnlineWizard();
            load();
        });
    }

    function handleLinkOnlineProduct() {
        const woo_id = parseInt($('#link-woo-selected-id').val() || 0);
        const local_id = parseInt($('#link-local-id').val() || 0);
        const new_local_sku = $('#link-local-new-sku').val().trim();

        if (!woo_id) {
            alert('Por favor selecciona un producto WooCommerce');
            return;
        }

        const doLink = function(confirm_merge) {
            const payload = {
                action: 'riverso_products_link_online',
                nonce,
                woo_id: woo_id,
                local_id: local_id,
                new_local_sku: new_local_sku,
                confirm_merge: confirm_merge ? 1 : 0,
            };
            $.post(ajaxurl, payload, function(r){
                if (r.success) {
                    alert(r.data.message || 'Producto vinculado exitosamente');
                    $('#create-online-modal').removeClass('show').css('display', 'none');
                    resetOnlineWizard();
                    load();
                    return;
                }
                if (r.data && r.data.needs_merge) {
                    openMergeModal(r.data.merge).then(confirmed => {
                        if (!confirmed) return;
                        doLink(true);
                    });
                    return;
                }
                alert('Error: ' + (r.data && r.data.message ? r.data.message : 'No se pudo vincular'));
            });
        };

        // Preview previo si hay Local elegido
        if (local_id) {
            $.post(ajaxurl, {
                action: 'riverso_products_evaluate_online',
                nonce,
                woo_id: woo_id,
                local_id: local_id
            }, function(ev){
                if (ev.success && (ev.data.needs_merge || ev.data.has_warnings)) {
                    const merge = ev.data.merge || { warnings: ev.data.warnings || [], needs_merge: !!ev.data.needs_merge, target_id: local_id };
                    openMergeModal(merge).then(confirmed => {
                        if (!confirmed) return;
                        doLink(true);
                    });
                    return;
                }
                doLink(false);
            });
            return;
        }

        doLink(false);
    }


    // Evento: guardar producto
    $('#product-save').on('click', function(){
        const id = $('#product-id').val();
        const payload = {
            action: 'riverso_products_save',
            nonce,
            id: id || 0,
            canonical_sku: $('#product-sku').val(),
            nombre_canonico: $('#product-name').val(),
            unidad_base: $('#product-unit').val(),
            permite_decimal: $('#product-decimal').is(':checked') ? 1 : 0,
            permite_ean13_personalizado: $('#product-ean13').is(':checked') ? 1 : 0,
            stock_abierto_habilitado: $('#product-open-stock').is(':checked') ? 1 : 0,
        };

        $.post(ajaxurl, payload, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            $('#product-editor').hide();
            resetEditor();
            load();
        });
    });

    $('#product-cancel').on('click', function(){
        $('#product-editor').hide();
        resetEditor();
    });

    $('#detail-close').on('click', function(){
        $('#product-detail-panel').hide();
        currentProduct = null;
    });

    // Evento: archivar
    $(document).on('click', '.product-archive', function(){
        if (!confirm('¿Archivar este producto?')) return;
        $.post(ajaxurl, {
            action: 'riverso_products_archive',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) { alert('Error: ' + r.data.message); return; }
            alert(r.data.message);
            load();
        });
    });

    // Evento: restaurar
    $(document).on('click', '.product-restore', function(){
        if (!confirm('¿Restaurar este producto?')) return;
        $.post(ajaxurl, {
            action: 'riverso_products_restore',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) { alert('Error: ' + r.data.message); return; }
            alert(r.data.message);
            load();
        });
    });

    function loadCatalogs() {
        $.post(ajaxurl, {
            action: 'riverso_catalogs_list',
            nonce
        }, function(r){
            if (!r.success) return;
            const catalogs = r.data.catalogs || [];
            ['#products-catalog', '#create-parent-catalog'].forEach(sel => {
                const $el = $(sel);
                if (!$el.length) return;
                const currentVal = $el.val();
                $el.find('option:not(:first)').remove();
                catalogs.forEach(cat => {
                    $el.append(`<option value="${cat.id}">${esc(cat.nombre)}</option>`);
                });
                if (currentVal) $el.val(currentVal);
            });
        });
    }

    let catalogSuggestTimeout = null;
    function loadCatalogSearchSuggestions() {
        const term = ($('#products-search').val() || '').trim();
        const catalogId = $('#products-catalog').val() || 0;
        const $box = $('#products-search-suggestions');
        if (term.length < 1) {
            $box.hide().empty();
            return;
        }
        clearTimeout(catalogSuggestTimeout);
        catalogSuggestTimeout = setTimeout(function(){
            $.post(ajaxurl, {
                action: 'riverso_products_search_catalog',
                nonce,
                search: term,
                catalog_id: catalogId,
                limit: 10
            }, function(r){
                if (!r.success) {
                    $box.hide().empty();
                    return;
                }
                const products = r.data.products || [];
                if (!products.length) {
                    $box.html('<div style="padding:8px;color:#666;">Sin sugerencias de catálogo</div>').show();
                    return;
                }
                let html = '';
                products.forEach(p => {
                    const baseId = p.producto_base_id || 0;
                    const codigo = p.codigo_proveedor || '';
                    const label = `SKU catálogo: ${esc(codigo)}`;
                    const nombre = p.nombre_proveedor ? esc(p.nombre_proveedor) : '';
                    const sku = p.canonical_sku ? ` | Local: ${esc(p.canonical_sku)}` : '';
                    const cat = p.catalogo_nombre ? ` | ${esc(p.catalogo_nombre)}` : '';
                    html += `<div class="catalog-search-suggestion" data-base-id="${baseId}" data-codigo="${esc(codigo)}" style="padding:8px;border-bottom:1px solid #eee;cursor:pointer;">
                        <strong>${label}</strong>${nombre ? `<br>${nombre}` : ''}<br><small style="color:#666;">${sku}${cat}</small>
                    </div>`;
                });
                $box.html(html).show();
            });
        }, 300);
    }

    $(document).on('click', '.catalog-search-suggestion', function(){
        const baseId = parseInt($(this).data('base-id') || 0, 10);
        const codigo = String($(this).data('codigo') || '');
        $('#products-search-suggestions').hide().empty();
        if (codigo) {
            $('#products-search').val(codigo);
            currentOffset = 0;
            load();
        }
        if (baseId > 0) {
            $.post(ajaxurl, { action: 'riverso_products_get', nonce, id: baseId }, function(r){
                if (r.success && r.data.item) {
                    showDetail(r.data.item);
                }
            });
        }
    });

    $(document).on('click', function(e){
        if (!$(e.target).closest('#products-search, #products-search-suggestions').length) {
            $('#products-search-suggestions').hide();
        }
    });

    // Iniciar
    loadCatalogs();
    $('#products-reload, #products-status, #products-completeness, #products-catalog').on('click change', function(){
        currentOffset = 0;
        load();
    });

    $('#products-search').on('keyup', function(){
        currentOffset = 0;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(load, 300);
        loadCatalogSearchSuggestions();
    });

    $('#products-prev').on('click', function(){
        loadPage(currentOffset - currentLimit);
    });

    $('#products-next').on('click', function(){
        loadPage(currentOffset + currentLimit);
    });

    load();

    // Deep link desde tareas: ?action=detail&id=X&tab=local&edit=1
    (function openDeepLinkFromTask() {
        const params = new URLSearchParams(window.location.search);
        const action = params.get('action');
        const id = parseInt(params.get('id') || '0', 10);
        const tab = params.get('tab') || '';
        const edit = params.get('edit') === '1';

        if (action !== 'detail' || !id) {
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_products_get',
            nonce,
            id: id
        }, function(r) {
            if (!r.success) {
                return;
            }
            showDetail(r.data.item);

            setTimeout(function() {
                const targetTab = tab || 'local';
                $('[data-tab="' + targetTab + '"].detail-tab').trigger('click');

                if (edit && canManage) {
                    enterEditMode();
                    setTimeout(() => $('#local-sku-edit').focus().select(), 100);
                }
            }, 150);
        });
    })();

    // ============= FASE 5: FAMILIAS =============
    function loadFamilyTree() {
        $.post(ajaxurl, {
            action: 'riverso_families_tree',
            nonce
        }, function(r) {
            if (!r.success) {
                $('#family-tree').html(`<p style="color:#dc3545;">Error: ${esc(r.data.message || 'Error desconocido')}</p>`);
                return;
            }
            renderFamilyTree(r.data.tree || []);
        });
    }

    function renderFamilyTree(families) {
        if (families.length === 0) {
            $('#family-tree').html('<p style="color:#666; text-align:center;">Sin familias registradas</p>');
            return;
        }

        let html = '';
        families.forEach(family => {
            const membersList = (family.children || []).map(m => {
                const badge = m.es_reemplazo_preferido ? '<span style="background:#28a745;color:white;padding:2px 6px;border-radius:3px;font-size:11px;margin-left:8px;">Preferido</span>' : '';
                return `<div style="margin-left:30px; padding:6px; background:#fff; border:1px solid #e5e5e5; border-radius:3px; margin-bottom:6px;">
                    <strong>${esc(m.nombre_canonico || '-')}</strong> <small style="color:#666;">(${esc(m.canonical_sku || '-')})</small>${badge}
                    <button class="button button-small" data-action="remove-member" data-member-id="${m.id}" style="margin-left:8px; float:right;">Quitar</button>
                </div>`;
            }).join('');

            html += `<div style="margin-bottom:12px; padding:12px; background:#fff; border:1px solid #ddd; border-radius:4px;" class="familia-card">
                <div style="cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                    <span onclick="$(this).closest('.familia-card').find('.familia-members').toggle(); $(this).find('.familia-chevron').text($(this).closest('.familia-card').find('.familia-members').is(':visible') ? '▼' : '▸');" style="user-select:none; flex:1;">
                        <span class="familia-chevron">▸</span> <strong>${esc(family.nombre)}</strong>
                        <small style="color:#666;">(${family.children ? family.children.length : 0} miembros
                        · stock ${family.stock_unidades !== undefined && family.stock_unidades !== null ? Number(family.stock_unidades).toLocaleString('es-CL') : '—'} u
                        · ${esc(family.tipo_sustitucion || '')})</small>
                    </span>
                </div>
                <div class="familia-members" style="display:none; margin-top:8px;">
                    ${membersList}
                    <button class="button button-small" data-action="add-member-form" data-family-id="${family.id}" style="margin-top:8px;">+ Agregar miembro</button>
                </div>
            </div>`;
        }).forEach(div => { html += div; });

        $('#family-tree').html(html);
    }

    // Click: crear familia
    $('#family-create-btn').on('click', function() {
        $('#family-create-form').toggle();
        if ($('#family-create-form').is(':visible')) {
            $('#family-codigo').focus();
        }
    });

    // Click: cancelar creación de familia
    $('#family-cancel-btn').on('click', function() {
        $('#family-create-form').hide();
        $('#family-codigo').val('');
        $('#family-nombre').val('');
        $('#family-tipo').val('exacta');
    });

    // Click: guardar familia
    $('#family-save-btn').on('click', function() {
        const codigo = $('#family-codigo').val().trim();
        const nombre = $('#family-nombre').val().trim();
        const tipo = $('#family-tipo').val();

        if (!codigo || !nombre) {
            alert('Por favor ingresa código y nombre');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_families_create',
            nonce,
            codigo_grupo: codigo,
            nombre: nombre,
            tipo_sustitucion: tipo
        }, function(r) {
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert('Familia creada exitosamente');
            $('#family-create-form').hide();
            $('#family-codigo').val('');
            $('#family-nombre').val('');
            $('#family-tipo').val('exacta');
            loadFamilyTree();
        });
    });

    // Click: quitar miembro
    $(document).on('click', '[data-action="remove-member"]', function() {
        if (!confirm('¿Quitar este miembro de la familia?')) return;
        const memberId = $(this).data('member-id');
        
        $.post(ajaxurl, {
            action: 'riverso_families_remove_member',
            nonce,
            member_id: memberId
        }, function(r) {
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            loadFamilyTree();
        });
    });

    // Evento: cuando se cambia al tab de familias, cargar árbol
    $(document).on('click', '.detail-tab[data-tab="families"]', function() {
        loadFamilyTree();
    });

    // ============= FASE 6: CATEGORÍAS ONLINE =============
	function loadCategoryTree(wooId) {
		if (!wooId) {
			$('#online-categories-tree').html('<p style="color:#999;">Sin producto WooCommerce asignado</p>');
			$('#online-categories-suggested-banner').hide();
			$('#online-categories-task-panel').hide();
			return;
		}

		// Buscar tarea de validar_categoria
		const catTask = (currentProduct.tasks || []).find(t => t.tipo === 'validar_categoria' && t.estado !== 'completada');
		const suggestedCat = catTask ? catTask.datos_extra : null;

		// Mostrar banner si hay categoría sugerida
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

		// Mostrar panel de tarea si existe
		if (catTask) {
			let suggestedText = suggestedCat.categoria || 'Sin categoría';
			if (suggestedCat.subcategoria) {
				suggestedText += ' > ' + suggestedCat.subcategoria;
			}
			$('#online-categories-task-suggested').text('Categoría sugerida: ' + suggestedText);
			$('#online-categories-task-panel').data('task_id', catTask.id).show();
		} else {
			$('#online-categories-task-panel').hide();
		}

		$.post(ajaxurl, {
			action: 'riverso_products_get_category_tree',
			nonce,
			parent_id: 0
		}, function(r) {
			if (!r.success) {
				$('#online-categories-tree').html(`<p style="color:#dc3545;">Error: ${esc(r.data.message || 'Error desconocido')}</p>`);
				return;
			}

			// Obtener categorías actuales del producto
			$.post(ajaxurl, {
				action: 'riverso_products_get_product_categories',
				nonce,
				woocommerce_product_id: wooId
			}, function(r2) {
				const currentCats = r2.success ? r2.data.current_categories : [];
				renderCategoryTreeWithCheckboxes(r.data.tree || [], currentCats, suggestedCat);
				$('#online-categories-save').show();

				// Rellenar dropdown de padre
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

	function renderCategoryTreeWithCheckboxes(categories, selectedIds, suggestedCat, indent = 0) {
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
								<button type="button" class="category-edit-btn" data-term-id="${catId}" style="margin-left:6px; font-size:11px; padding:2px 6px; background:#f0f0f0; border:1px solid #ccc; cursor:pointer;">Editar</button>
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

		const addCategories = (cats) => {
			cats.forEach(cat => {
				parentSelect.append(`<option value="${cat.id}">${esc(cat.name)}</option>`);
				if (cat.children && cat.children.length > 0) {
					addCategories(cat.children);
				}
			});
		};

		addCategories(categories);
	}

    // Click: guardar categorías
    $('#online-categories-save').on('click', function() {
        if (!currentProduct || !currentProduct.woocommerce_product_id) {
            alert('Sin producto WooCommerce');
            return;
        }

        const selectedCats = [];
        $('#online-categories-tree .category-checkbox:checked').each(function() {
            selectedCats.push(parseInt($(this).val()));
        });

        $.post(ajaxurl, {
            action: 'riverso_products_set_product_categories',
            nonce,
            woocommerce_product_id: currentProduct.woocommerce_product_id,
            category_ids: selectedCats
        }, function(r) {
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert('Categorías guardadas exitosamente');
        });
	});

	// ============= EDICIÓN DE CATEGORÍAS =============

	// Click: mostrar formulario de nueva categoría
	$('#online-categories-add-new').on('click', function() {
		$('#online-categories-add-form').toggle();
	});

	// Click: cancelar formulario
	$('#online-categories-cancel-btn').on('click', function() {
		$('#online-categories-add-form').hide();
		$('#online-categories-new-name').val('');
	});

	// Click: crear nueva categoría
	$('#online-categories-create-btn').on('click', function() {
		const name = $('#online-categories-new-name').val().trim();
		const parent_id = absint($('#online-categories-new-parent').val());

		if (!name) {
			alert('Ingrese el nombre de la categoría');
			return;
		}

		$.post(ajaxurl, {
			action: 'riverso_products_create_category',
			nonce: riverso_pos_nonce,
			name: name,
			parent_id: parent_id
		}, function(r) {
			if (!r.success) {
				alert('Error: ' + r.data.message);
				return;
			}
			alert('Categoría creada exitosamente');
			$('#online-categories-new-name').val('');
			$('#online-categories-add-form').hide();
			// Recargar árbol
			if (currentProduct && currentProduct.woocommerce_product_id) {
				loadCategoryTree(currentProduct.woocommerce_product_id);
			}
		});
	});

	// Click: expandir / colapsar rama de categorías
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

	$('#online-categories-expand-all').on('click', function() {
		$('#online-categories-tree .cat-children').show();
		$('#online-categories-tree .cat-branch-toggle').attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
	});

	$('#online-categories-collapse-all').on('click', function() {
		$('#online-categories-tree .cat-children').hide();
		$('#online-categories-tree .cat-branch-toggle').attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
	});

	// Click: editar categoría (renombrar)
	$(document).on('click', '.category-edit-btn', function(e) {
		e.preventDefault();
		const term_id = $(this).data('term_id');
		const currentName = $(this).closest('label').find('span').first().text().split(' ')[0]; // Obtener nombre actual

		const newName = prompt('Nuevo nombre para la categoría:', currentName);
		if (!newName || newName === currentName) {
			return;
		}

		$.post(ajaxurl, {
			action: 'riverso_products_rename_category',
			nonce: riverso_pos_nonce,
			term_id: term_id,
			name: newName
		}, function(r) {
			if (!r.success) {
				alert('Error: ' + r.data.message);
				return;
			}
			alert('Categoría actualizada exitosamente');
			// Recargar árbol
			if (currentProduct && currentProduct.woocommerce_product_id) {
				loadCategoryTree(currentProduct.woocommerce_product_id);
			}
		});
	});

	// ============= ACEPTAR TAREA DE CATEGORÍA =============

	// Click: aceptar categorías y completar tarea
	$('#online-categories-accept-task').on('click', function() {
		const taskId = $('#online-categories-task-panel').data('task_id');
		if (!taskId || !currentProduct) {
			alert('Error: datos incompletos');
			return;
		}

		// 1. Guardar categorías seleccionadas
		const selectedCats = [];
		$('#online-categories-tree .category-checkbox:checked').each(function() {
			selectedCats.push(parseInt($(this).val()));
		});

		$.post(ajaxurl, {
			action: 'riverso_products_set_product_categories',
			nonce: riverso_pos_nonce,
			woocommerce_product_id: currentProduct.woocommerce_product_id,
			category_ids: selectedCats
		}, function(r) {
			if (!r.success) {
				alert('Error al guardar categorías: ' + r.data.message);
				return;
			}

			// 2. Completar tarea
			const catNames = $('#online-categories-tree .category-checkbox:checked').map(function() {
				return $(this).closest('label').find('span').first().text();
			}).get().join(', ');

			$.post(ajaxurl, {
				action: 'riverso_complete_task',
				nonce: riverso_pos_nonce,
				task_id: taskId,
				notas_completado: 'Categorías aceptadas desde Hub: ' + catNames
			}, function(r2) {
				if (!r2.success) {
					alert('Error al completar tarea: ' + r2.data.message);
					return;
				}

				alert('¡Categorías aceptadas y tarea completada exitosamente!');
				$('#online-categories-task-panel').hide();
				// Refrescar datos del producto
				showDetail(currentProduct.id);
			});
		});
	});

	// Evento: cuando se cambia al tab online después de que hay woo_id, cargar categorías
    $(document).on('click', '.detail-tab[data-tab="online"]', function() {
        if (currentProduct && currentProduct.woocommerce_product_id) {
            loadCategoryTree(currentProduct.woocommerce_product_id);
        }
    });

    // ============= FASE 7: IMAGEN LOCAL (MEDIA PICKER) =============
    
    // Click: seleccionar imagen
    $('#local-image-select').on('click', function(e) {
        e.preventDefault();
        
        if (!wp.media) {
            alert('Media Library no disponible');
            return;
        }
        
        const frame = wp.media({
            title: 'Seleccionar imagen del producto',
            button: { text: 'Usar imagen' },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            
            if (!attachment.id) {
                alert('No se seleccionó una imagen válida');
                return;
            }
            
            $.post(ajaxurl, {
                action: 'riverso_products_set_image',
                nonce,
                producto_id: currentProduct.id,
                imagen_id: attachment.id
            }, function(r) {
                if (!r.success) {
                    alert('Error: ' + r.data.message);
                    return;
                }
                
                currentProduct.imagen_id = attachment.id;
                currentProduct.imagen_url = r.data.imagen_url;
                currentProduct.imagen_full = r.data.imagen_full;
                
                $('#local-image-thumb').attr('src', currentProduct.imagen_url).show();
                $('#local-image-clear').show();
                alert('Imagen guardada exitosamente');
            });
        });
        
        frame.open();
    });
    
    // Click: quitar imagen
    $('#local-image-clear').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('¿Quitar la imagen del producto?')) return;
        
        $.post(ajaxurl, {
            action: 'riverso_products_set_image',
            nonce,
            producto_id: currentProduct.id,
            imagen_id: 0
        }, function(r) {
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            
            currentProduct.imagen_id = 0;
            currentProduct.imagen_url = '';
            currentProduct.imagen_full = '';
            
            $('#local-image-thumb').hide();
            $('#local-image-clear').hide();
            alert('Imagen removida');
        });
    });

    // ============= FASE 8: INDICADORES DE EXCLAMACIÓN =============
    
    function calculateFieldAlerts(product) {
        const alerts = [];
        
        // SKU Local vacío
        if (!product.canonical_sku) {
            alerts.push({
                field: 'SKU Local',
                icon: '❌',
                action: 'edit-sku'
            });
        }
        
        // Sin precio local asignado
        if (!product.precio_local || !product.precio_local.p_asignado) {
            alerts.push({
                field: 'Precio Local',
                icon: '⚠️',
                action: 'tab-local'
            });
        }
        
        // Sin familia
        if (!product.familia) {
            alerts.push({
                field: 'Familia',
                icon: '👥',
                action: 'tab-local'
            });
        }
        
        // Sin imagen
        if (!product.imagen_id) {
            alerts.push({
                field: 'Imagen Local',
                icon: '📷',
                action: 'tab-local'
            });
        }
        
        // Sin código proveedor
        if ((product.proveedores_count || 0) === 0) {
            alerts.push({
                field: 'Código Proveedor',
                icon: '📦',
                action: 'tab-suppliers'
            });
        }
        
        // Sin barcode EAN-13 si tiene WooCommerce
        if (product.woocommerce_product_id) {
            const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
            if (!hasEan) {
                alerts.push({
                    field: 'Barcode EAN-13',
                    icon: '📊',
                    action: 'tab-barcodes'
                });
            }
            
            // Sin categorías (solo si tenemos dato explícito vacío)
            if (Array.isArray(product.current_categories) && product.current_categories.length === 0) {
                alerts.push({
                    field: 'Categorías Online',
                    icon: '📂',
                    action: 'tab-online'
                });
            }
        }
        
        // Mostrar badge con contador y tooltip
        if (alerts.length > 0) {
            $('#detail-alerts-badge-text').text(`⚠️ ${alerts.length} campos`);
            $('#detail-alerts-badge').show();
            
            // Llenar el tooltip (sin destruir el contenedor)
            let tooltipHtml = alerts.map(a =>
                `<div class="alerts-tooltip-item" data-alert-action="${esc(a.action)}">
                    <span>${a.icon}</span> ${esc(a.field)}
                </div>`
            ).join('');
            $('#detail-alerts-tooltip').html(tooltipHtml);
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
            
            let html = tasks.map(t => 
                `<div class="alerts-tooltip-item task-tooltip-goto" data-task-tipo="${esc(t.tipo)}">
                    <span>📌</span> ${esc(t.titulo)}
                </div>`
            ).join('');
            $('#detail-tasks-tooltip').html(html);
        } else {
            $('#detail-tasks-badge').hide();
            $('#detail-tasks-tooltip').html('');
        }
    }

    function showFieldWarningIcons(product) {
        // Limpiar warnings previos
        $('.field-warning-inline').remove();
        
        // SKU Local vacío
        if (!product.canonical_sku) {
            $('#local-sku-view').after('<span class="field-warning-inline" title="Falta SKU Local">⚠️</span>');
        }
        
        // Precio Local vacío
        if (!product.precio_local || !product.precio_local.p_asignado) {
            $('#local-precio-view').after('<span class="field-warning-inline" title="Falta Precio Local">⚠️</span>');
        }
        
        // Familia vacía
        if (!product.familia) {
            $('#familia-display').after('<span class="field-warning-inline" title="Falta Familia">⚠️</span>');
        }
        
        // Imagen vacía
        if (!product.imagen_id) {
            $('#local-image-select-btn').after('<span class="field-warning-inline" title="Falta Imagen">⚠️</span>');
        }
        
        // Código proveedor
        if ((product.proveedores_count || 0) === 0) {
            // Este warning aparecerá en el tab de Suppliers cuando se cargue
        }
        
        // Barcode EAN-13 (si tiene WooCommerce)
        if (product.woocommerce_product_id) {
            const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
            if (!hasEan) {
                // Este warning aparecerá en el tab de Barcodes cuando se cargue
            }
        }
    }

    // Event listener para navegar a Tasks tab desde el tooltip de tareas
    $(document).on('click', '.task-tooltip-goto', function() {
        $('.detail-tab[data-tab="tasks"]').click();
    });
});
</script>
