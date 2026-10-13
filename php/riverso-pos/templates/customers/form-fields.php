<?php
/**
 * Campos del formulario Crear/Modificar cliente (reutilizable en modal de cotizaciones).
 *
 * @var array<int, string> $comunas
 * @var bool               $show_footer  Si true, muestra activo + acciones (página clientes).
 * @var bool               $can_edit
 */
if (!isset($comunas) || !is_array($comunas)) {
    $comunas = [];
}
$show_footer = !empty($show_footer);
$can_edit = !isset($can_edit) || !empty($can_edit);
?>
<input type="hidden" id="cust-id" name="id" value="0">

<div class="cust-card">
    <h2 class="cust-card-title" id="cust-card-general-title">Crear cliente</h2>
    <label class="cust-field">
        <span>Nombre de fantasía (*)</span>
        <input type="text" id="cust-nombre-fantasia" name="nombre_fantasia" required autocomplete="organization">
    </label>
    <p class="cust-hint">*Si no existe el nombre de fantasía puede usar algún nombre representativo.</p>
</div>

<div class="cust-card">
    <label class="cust-section-toggle">
        <input type="checkbox" id="cust-has-contacto" checked>
        <span>Contacto Primario</span>
    </label>
    <p class="cust-hint">*El contacto primario es la persona con la que sueles tener contacto relacionado con el cliente que estás creando.</p>
    <div class="cust-section-body" id="cust-section-contacto">
        <div class="cust-grid-2">
            <label class="cust-field">
                <span>Primer Nombre (*)</span>
                <input type="text" id="cust-primer-nombre" name="primer_nombre" autocomplete="given-name">
            </label>
            <label class="cust-field">
                <span>Apellido Paterno (*)</span>
                <input type="text" id="cust-apellido-paterno" name="apellido_paterno" autocomplete="family-name">
            </label>
            <label class="cust-field">
                <span>Teléfono</span>
                <input type="text" id="cust-contacto-telefono" name="contacto_telefono" autocomplete="tel">
            </label>
            <label class="cust-field">
                <span>Email <span class="cust-info" title="Correo de contacto del cliente">i</span></span>
                <input type="email" id="cust-contacto-email" name="contacto_email" autocomplete="email">
            </label>
        </div>
    </div>
</div>

<div class="cust-card">
    <label class="cust-section-toggle">
        <input type="checkbox" id="cust-has-facturacion" checked>
        <span>Datos de facturación</span>
    </label>
    <p class="cust-hint">*Si existen más datos de facturación, puede ingresarlos luego de crear al cliente, en la pantalla Modificar.</p>
    <div class="cust-section-body" id="cust-section-facturacion">
        <div class="cust-grid-2">
            <label class="cust-field">
                <span>País</span>
                <select id="cust-pais" name="pais">
                    <option value="CHILE" selected>CHILE</option>
                </select>
            </label>
            <div class="cust-id-pair">
                <label class="cust-field">
                    <span>Tipo de identificación</span>
                    <select id="cust-tipo-id" name="tipo_identificacion">
                        <option value="RUT_CLIENTE" selected>RUT Cliente</option>
                    </select>
                </label>
                <label class="cust-field">
                    <span>RUT Cliente</span>
                    <input type="text" id="cust-rut" name="rut" placeholder="12345678-9" autocomplete="off">
                </label>
            </div>
            <label class="cust-field">
                <span>Nombre o Razón Social</span>
                <input type="text" id="cust-razon-social" name="razon_social" autocomplete="organization">
            </label>
            <label class="cust-field">
                <span>Dirección</span>
                <input type="text" id="cust-direccion" name="direccion" autocomplete="street-address">
            </label>
            <label class="cust-field">
                <span>Comuna</span>
                <select id="cust-comuna" name="comuna">
                    <option value="">** Seleccione **</option>
                    <?php foreach ($comunas as $comuna): ?>
                    <option value="<?php echo htmlspecialchars((string) $comuna, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) $comuna, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="cust-field">
                <span>Ciudad</span>
                <input type="text" id="cust-ciudad" name="ciudad" autocomplete="address-level2">
            </label>
            <label class="cust-field">
                <span>Giro</span>
                <input type="text" id="cust-giro" name="giro">
            </label>
            <label class="cust-field">
                <span>Teléfono</span>
                <input type="text" id="cust-facturacion-telefono" name="facturacion_telefono" autocomplete="tel">
            </label>
            <label class="cust-field">
                <span>Código postal</span>
                <input type="text" id="cust-codigo-postal" name="codigo_postal" value="0">
            </label>
        </div>
    </div>
</div>

<div class="cust-card">
    <label class="cust-section-toggle">
        <input type="checkbox" id="cust-has-datos-extra" checked>
        <span>Datos Adicionales Configurables</span>
    </label>
    <p class="cust-hint">*Estos datos adicionales configurables permiten ingresar datos extras, informativos al cliente.</p>
    <div class="cust-section-body" id="cust-section-extra">
        <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="cust-extra-row">
            <span class="cust-extra-label">Dato Configurable <?php echo (int) $i; ?></span>
            <div class="cust-grid-2">
                <input type="text" class="cust-extra-nombre" data-index="<?php echo (int) ($i - 1); ?>" placeholder="Nombre Dato Configurable <?php echo (int) $i; ?>">
                <input type="text" class="cust-extra-valor" data-index="<?php echo (int) ($i - 1); ?>" placeholder="Valor Dato Configurable <?php echo (int) $i; ?>">
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<?php if ($show_footer): ?>
<div class="cust-form-footer">
    <label class="cust-check-inline">
        <input type="checkbox" id="cust-activo" checked>
        <span>Cliente activo</span>
    </label>
    <div class="cust-form-actions">
        <button type="button" class="cust-btn cust-btn-secondary" id="cust-cancel">Cancelar</button>
        <?php if ($can_edit): ?>
        <button type="submit" class="cust-btn cust-btn-primary" id="cust-save">Guardar cliente</button>
        <?php endif; ?>
    </div>
</div>
<p class="cust-form-msg" id="cust-form-msg" hidden></p>
<?php else: ?>
<input type="hidden" id="cust-activo" value="1">
<p class="cust-form-msg" id="cust-form-msg" hidden></p>
<?php endif; ?>
