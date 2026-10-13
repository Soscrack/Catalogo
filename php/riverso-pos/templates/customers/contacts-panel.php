<?php
/**
 * Pestaña Contactos del cliente comercial.
 *
 * @var array<int, string> $comunas
 * @var bool               $can_edit
 */
if (!isset($comunas) || !is_array($comunas)) {
    $comunas = [];
}
$can_edit = !isset($can_edit) || !empty($can_edit);
$ct_year = (int) gmdate('Y');
$ct_months = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

if (!function_exists('riverso_cust_comuna_options')) {
    function riverso_cust_comuna_options($comunas) {
        $html = '<option value=""></option>';
        foreach ($comunas as $comuna) {
            $comuna = (string) $comuna;
            $html .= '<option value="' . htmlspecialchars($comuna, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($comuna, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $html;
    }
}
$comuna_options = riverso_cust_comuna_options($comunas);
?>
<section id="ct-search-view" aria-labelledby="ct-title">
    <header class="cust-top">
        <div>
            <h1 id="ct-title">Contactos</h1>
            <p class="cust-lead">Busca personas de contacto. Los inhabilitados salen de la búsqueda activa.</p>
        </div>
        <?php if ($can_edit): ?>
        <button type="button" class="cust-btn cust-btn-primary" id="ct-new">Crear contacto</button>
        <?php endif; ?>
    </header>

    <div class="cust-banner" id="ct-client-banner" hidden>
        <span id="ct-client-banner-text"></span>
        <button type="button" class="cust-btn cust-btn-link" id="ct-clear-client">Ver todos</button>
    </div>

    <div class="cust-search-card">
        <div class="cust-search-grid">
            <label class="cust-field">
                <span>Nombre</span>
                <input type="text" id="ct-filter-nombre" autocomplete="off">
            </label>
            <label class="cust-field">
                <span>Segundo Nombre</span>
                <input type="text" id="ct-filter-segundo" autocomplete="off">
            </label>
            <label class="cust-field">
                <span>Apellido Paterno</span>
                <input type="text" id="ct-filter-paterno" autocomplete="off">
            </label>
            <label class="cust-field">
                <span>Apellido Materno</span>
                <input type="text" id="ct-filter-materno" autocomplete="off">
            </label>
            <label class="cust-field">
                <span>Email</span>
                <input type="text" id="ct-filter-email" autocomplete="off">
            </label>
            <label class="cust-check">
                <input type="checkbox" id="ct-filter-activo" checked>
                <span>Activo</span>
            </label>
        </div>
        <p class="cust-hint">Desmarca Activo para ver inhabilitados y eliminarlos. Mientras no se eliminen, ese correo no se puede volver a guardar.</p>
        <div class="cust-search-actions">
            <button type="button" class="cust-btn cust-btn-search" id="ct-search">Buscar</button>
        </div>
    </div>

    <p class="cust-form-msg" id="ct-list-msg" hidden></p>

    <div class="cust-table-wrap">
        <table class="cust-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Cliente</th>
                    <th>Estado</th>
                    <th class="cust-th-actions">Acciones</th>
                </tr>
            </thead>
            <tbody id="ct-tbody">
                <tr><td colspan="6" class="cust-empty">Usa los filtros y pulsa Buscar.</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section id="ct-form-view" hidden aria-labelledby="ct-form-title">
    <header class="cust-top">
        <div>
            <h1 id="ct-form-title">Editar contacto</h1>
            <p class="cust-lead" id="ct-form-lead"></p>
        </div>
        <button type="button" class="cust-btn cust-btn-secondary" id="ct-back">Volver al buscador</button>
    </header>

    <form id="ct-form" novalidate>
        <input type="hidden" id="ct-id" value="0">
        <div class="cust-card">
            <label class="cust-field">
                <span>Cliente</span>
                <input type="text" id="ct-cliente-buscar" placeholder="Nombre de fantasía o RUT" autocomplete="off">
                <input type="hidden" id="ct-cliente-id" value="0">
                <div class="cust-picks" id="ct-cliente-picks" hidden></div>
                <p class="cust-hint" id="ct-cliente-chosen"></p>
            </label>
            <div class="cust-grid-2">
                <label class="cust-field">
                    <span>Primer Nombre (*)</span>
                    <input type="text" id="ct-primer-nombre" autocomplete="given-name">
                </label>
                <label class="cust-field">
                    <span>Segundo Nombre</span>
                    <input type="text" id="ct-segundo-nombre" autocomplete="off">
                </label>
                <label class="cust-field">
                    <span>Apellido Paterno (*)</span>
                    <input type="text" id="ct-apellido-paterno" autocomplete="family-name">
                </label>
                <label class="cust-field">
                    <span>Apellido Materno</span>
                    <input type="text" id="ct-apellido-materno" autocomplete="off">
                </label>
                <label class="cust-field">
                    <span>Fono Oficina</span>
                    <input type="text" id="ct-fono-oficina" autocomplete="tel">
                </label>
                <label class="cust-field">
                    <span>Fono Móvil</span>
                    <input type="text" id="ct-fono-movil" autocomplete="tel">
                </label>
                <label class="cust-field">
                    <span>Email</span>
                    <input type="email" id="ct-email" autocomplete="email">
                </label>
                <label class="cust-check cust-check-inline">
                    <input type="checkbox" id="ct-activo" checked>
                    <span>Activo</span>
                </label>
            </div>
            <label class="cust-field">
                <span>Observaciones</span>
                <textarea id="ct-observaciones" rows="3"></textarea>
            </label>
            <button type="button" class="cust-btn cust-btn-link" id="ct-toggle-advanced">Mostrar opciones avanzadas</button>
            <div id="ct-advanced" hidden>
                <h2 class="cust-card-title">Fecha de nacimiento</h2>
                <div class="cust-grid-3">
                    <label class="cust-field">
                        <span>Día</span>
                        <select id="ct-nac-dia">
                            <option value="0"></option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                            <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="cust-field">
                        <span>Mes</span>
                        <select id="ct-nac-mes">
                            <option value="0"></option>
                            <?php foreach ($ct_months as $num => $label): ?>
                            <option value="<?php echo (int) $num; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="cust-field">
                        <span>Año</span>
                        <select id="ct-nac-anio">
                            <option value="0"></option>
                            <?php for ($y = $ct_year; $y >= 1920; $y--): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>

                <h2 class="cust-card-title">Dirección laboral</h2>
                <div class="cust-grid-2">
                    <label class="cust-field"><span>Calle</span><input type="text" id="ct-lab-calle"></label>
                    <label class="cust-field"><span>Número</span><input type="text" id="ct-lab-numero"></label>
                    <label class="cust-field"><span>Depto / Oficina</span><input type="text" id="ct-lab-depto"></label>
                    <label class="cust-field"><span>Ciudad</span><input type="text" id="ct-lab-ciudad"></label>
                    <label class="cust-field">
                        <span>Comuna</span>
                        <select id="ct-lab-comuna"><?php echo $comuna_options; ?></select>
                    </label>
                    <label class="cust-field"><span>País</span><input type="text" id="ct-lab-pais" value="CHILE"></label>
                    <label class="cust-field"><span>Código Postal</span><input type="text" id="ct-lab-postal"></label>
                </div>

                <h2 class="cust-card-title">Dirección particular</h2>
                <div class="cust-grid-2">
                    <label class="cust-field"><span>Calle</span><input type="text" id="ct-part-calle"></label>
                    <label class="cust-field"><span>Número</span><input type="text" id="ct-part-numero"></label>
                    <label class="cust-field"><span>Depto / Oficina</span><input type="text" id="ct-part-depto"></label>
                    <label class="cust-field"><span>Ciudad</span><input type="text" id="ct-part-ciudad"></label>
                    <label class="cust-field">
                        <span>Comuna</span>
                        <select id="ct-part-comuna"><?php echo $comuna_options; ?></select>
                    </label>
                    <label class="cust-field"><span>País</span><input type="text" id="ct-part-pais" value="CHILE"></label>
                    <label class="cust-field"><span>Código Postal</span><input type="text" id="ct-part-postal"></label>
                </div>
            </div>
        </div>
        <p class="cust-form-msg" id="ct-form-msg" hidden></p>
        <div class="cust-form-footer">
            <?php if ($can_edit): ?>
            <button type="submit" class="cust-btn cust-btn-primary" id="ct-save">Guardar contacto</button>
            <?php endif; ?>
            <button type="button" class="cust-btn cust-btn-secondary" id="ct-cancel">Cancelar</button>
        </div>
    </form>
</section>
