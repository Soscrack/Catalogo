<?php
/**
 * Centro de Precios — Asistente de Descuento: ajustes, competidores e historial.
 *
 * @var string $da_view 'settings' | 'history'
 */

if (!defined('ABSPATH')) {
    exit;
}

$da_view = isset($da_view) ? $da_view : 'settings';
$da_can_settings = class_exists('Riverso_Discount_Assistant_Module') && Riverso_Discount_Assistant_Module::can_manage_settings();
$da_settings = class_exists('Riverso_Discount_Assistant_Service')
    ? Riverso_Discount_Assistant_Service::get_settings()
    : [];
$da_num = function ($key) use ($da_settings) {
    return isset($da_settings[$key]) ? esc_attr((string) $da_settings[$key]) : '';
};
?>
<?php if ($da_view === 'settings'): ?>
<div class="rda-admin" id="rda-settings">
    <h3>Asistente de descuento</h3>
    <p class="description">
        Se muestra dentro de «Descuentos» en Editar línea (cotizaciones y borradores de boleta/factura).
        El aviso de volumen solo avisa: no bloquea ni cambia precios.
    </p>
    <form id="rda-settings-form" class="rda-settings-form">
        <fieldset>
            <legend>Criterio de volumen (aviso)</legend>
            <label>Más de (unidades)
                <input type="number" min="0" step="1" name="volume_units" value="<?php echo $da_num('volume_units'); ?>">
            </label>
            <label>Y más de (monto bruto de la línea, $)
                <input type="number" min="0" step="1" name="volume_amount" value="<?php echo $da_num('volume_amount'); ?>">
            </label>
            <label>O más de (monto bruto, $) sin importar unidades
                <input type="number" min="0" step="1" name="volume_amount_alone" value="<?php echo $da_num('volume_amount_alone'); ?>">
            </label>
            <p class="description">Avisa si la línea tiene (más de N unidades y más de $X) o más de $Y. Con 0 en el último campo solo cuenta la primera condición.</p>
        </fieldset>
        <fieldset>
            <legend>Rango recomendado</legend>
            <label>Descuento máximo: factor (precio / costo)
                <input type="number" min="0.01" step="0.01" name="floor_factor" value="<?php echo $da_num('floor_factor'); ?>">
            </label>
            <label>Descuento mínimo: precio × A
                <input type="number" min="0.01" step="0.01" name="min_ratio_num" value="<?php echo $da_num('min_ratio_num'); ?>">
            </label>
            <label>… / B
                <input type="number" min="0.01" step="0.01" name="min_ratio_den" value="<?php echo $da_num('min_ratio_den'); ?>">
            </label>
            <label>Precio deseado: factor
                <input type="number" min="0.01" step="0.01" name="desired_factor" value="<?php echo $da_num('desired_factor'); ?>">
            </label>
            <p class="description" id="rda-min-pct"></p>
        </fieldset>
        <fieldset>
            <legend>Sugerencia</legend>
            <label>Redondear el monto total a múltiplos de ($)
                <input type="number" min="1" step="1" name="round_step" value="<?php echo $da_num('round_step'); ?>">
            </label>
            <label>Precios de competencia vigentes hasta (días)
                <input type="number" min="1" step="1" name="stale_days" value="<?php echo $da_num('stale_days'); ?>">
            </label>
        </fieldset>
        <p>
            <button type="submit" class="button button-primary" <?php echo $da_can_settings ? '' : 'disabled'; ?>>Guardar ajustes</button>
            <span id="rda-settings-msg" role="status"></span>
        </p>
    </form>

    <h3>Competidores</h3>
    <p class="description">
        Actitud por dominio. Se usa cuando el vínculo del producto está «Sin clasificar».
        Solo cuentan para la sugerencia los competidores Codiciosos y Competitivos.
    </p>
    <form id="rda-domain-add" class="rda-domain-add">
        <input type="text" name="dominio" placeholder="dominio.cl o URL" aria-label="Dominio del competidor" required>
        <input type="text" name="nombre" placeholder="Nombre (opcional)" aria-label="Nombre del competidor">
        <select name="actitud" aria-label="Actitud">
            <option value="competitivo">Competitivo</option>
            <option value="codicioso">Codicioso</option>
            <option value="sin_clasificar">Sin clasificar</option>
        </select>
        <button type="submit" class="button">Agregar / actualizar</button>
    </form>
    <table class="wp-list-table widefat striped rda-domains">
        <thead>
            <tr>
                <th scope="col">Competidor</th>
                <th scope="col">Dominio</th>
                <th scope="col">Vínculos</th>
                <th scope="col">Actitud</th>
            </tr>
        </thead>
        <tbody id="rda-domains-body">
            <tr><td colspan="4" class="description">Cargando…</td></tr>
        </tbody>
    </table>
    <p id="rda-domains-msg" class="description" role="status"></p>
</div>
<?php else: ?>
<div class="rda-admin" id="rda-history">
    <h3>Historial de descuentos</h3>
    <p class="description">
        Descuentos de líneas de cotizaciones aceptadas y de documentos emitidos. Al emitir un documento
        desde una cotización, su registro reemplaza al de la aceptación. Desde Editar línea se puede repetir
        un descuento anterior (mismo %, mismo factor o mismo precio unitario).
    </p>
    <div class="rda-history-filters">
        <input type="search" id="rda-history-search" placeholder="SKU, producto, cliente o documento" aria-label="Buscar">
        <select id="rda-history-origen" aria-label="Origen">
            <option value="">Todos los orígenes</option>
            <option value="sugerido">Sugerido</option>
            <option value="maximo">Máximo</option>
            <option value="minimo">Mínimo</option>
            <option value="marcador">Marcador</option>
            <option value="historial">Repetido</option>
            <option value="manual">Manual</option>
        </select>
        <select id="rda-history-evento" aria-label="Evento">
            <option value="">Aceptadas y emitidas</option>
            <option value="aceptada">Cotización aceptada</option>
            <option value="emitida">Documento emitido</option>
        </select>
        <button type="button" class="button" id="rda-history-go">Buscar</button>
    </div>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th scope="col">Fecha</th>
                <th scope="col">Documento</th>
                <th scope="col">Cliente</th>
                <th scope="col">Producto</th>
                <th scope="col">Unidades</th>
                <th scope="col">Bruto</th>
                <th scope="col">Descuento</th>
                <th scope="col">Final</th>
                <th scope="col">Unit. final</th>
                <th scope="col">Factor</th>
                <th scope="col">Origen</th>
            </tr>
        </thead>
        <tbody id="rda-history-body">
            <tr><td colspan="11" class="description">Cargando…</td></tr>
        </tbody>
    </table>
    <div class="tablenav"><div class="tablenav-pages" id="rda-history-pages"></div></div>
</div>
<?php endif; ?>
