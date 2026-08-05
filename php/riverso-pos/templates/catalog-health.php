<?php
/**
 * Template: Salud del catálogo.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Catalog_Health_Module')) {
    echo '<div class="notice notice-error"><p>El módulo de salud del catálogo no está disponible.</p></div>';
    return;
}

$filters = [
    'estado' => isset($_GET['estado']) ? sanitize_key(wp_unslash($_GET['estado'])) : 'abierto',
    'regla' => isset($_GET['regla']) ? sanitize_key(wp_unslash($_GET['regla'])) : '',
    'severidad' => isset($_GET['severidad']) ? sanitize_key(wp_unslash($_GET['severidad'])) : '',
];
$summary = Riverso_Catalog_Health_Module::get_summary();
$gaps = Riverso_Catalog_Health_Module::get_gaps($filters, 300);
$rules = [];
foreach ($summary['by_rule'] as $row) {
    $rules[$row['regla']] = $row['regla'];
}
?>
<div class="wrap riverso-catalog-health">
    <h1>Salud del catálogo</h1>
    <p>Brechas de productos, presentaciones, códigos, ubicación y precios detectadas automáticamente.</p>

    <?php if (!empty($_GET['scan_ok'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                Revisión completada:
                <?php echo esc_html(intval($_GET['detected'] ?? 0)); ?> brechas vigentes,
                <?php echo esc_html(intval($_GET['resolved'] ?? 0)); ?> resueltas.
            </p>
        </div>
    <?php elseif (!empty($_GET['scan_error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p>No se pudo completar la revisión: <?php echo esc_html(sanitize_key(wp_unslash($_GET['scan_error']))); ?>.</p>
        </div>
    <?php endif; ?>

    <div class="riverso-health-cards">
        <div class="riverso-health-card">
            <strong><?php echo esc_html(number_format_i18n($summary['total'])); ?></strong>
            <span>Brechas abiertas</span>
        </div>
        <div class="riverso-health-card critical">
            <strong><?php echo esc_html(number_format_i18n($summary['criticos'])); ?></strong>
            <span>Críticas</span>
        </div>
        <div class="riverso-health-card high">
            <strong><?php echo esc_html(number_format_i18n($summary['altos'])); ?></strong>
            <span>Altas</span>
        </div>
        <div class="riverso-health-card coverage">
            <strong><?php echo esc_html(number_format_i18n($summary['coverage'], 1)); ?>%</strong>
            <span>Cobertura orientativa</span>
        </div>
    </div>

    <?php if (!empty($summary['dimensions'])): ?>
        <p class="description">
            Cobertura:
            <?php
            $parts = [];
            foreach ($summary['dimensions'] as $dimension => $percent) {
                $parts[] = ucfirst($dimension) . ' ' . number_format_i18n($percent, 1) . '%';
            }
            echo esc_html(implode(' · ', $parts));
            ?>
        </p>
    <?php endif; ?>

    <div class="riverso-health-toolbar">
        <form method="get">
            <input type="hidden" name="page" value="riverso-pos-catalog-health">
            <select name="estado">
                <?php foreach (['abierto' => 'Abiertos', 'resuelto' => 'Resueltos', 'ignorado' => 'Ignorados'] as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['estado'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="severidad">
                <option value="">Todas las severidades</option>
                <?php foreach (['critica' => 'Crítica', 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'] as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['severidad'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="regla">
                <option value="">Todas las reglas</option>
                <?php foreach ($rules as $rule): ?>
                    <option value="<?php echo esc_attr($rule); ?>" <?php selected($filters['regla'], $rule); ?>>
                        <?php echo esc_html($rule); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button">Filtrar</button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="riverso_catalog_health_scan">
            <?php wp_nonce_field('riverso_catalog_health_scan'); ?>
            <button class="button button-primary">Ejecutar revisión ahora</button>
        </form>
    </div>

    <p class="description">
        Última revisión:
        <?php echo $summary['last_scan'] ? esc_html($summary['last_scan']) : 'aún no ejecutada'; ?>.
        Completar una tarea no oculta la brecha: se cierra cuando los datos quedan corregidos.
    </p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:100px;">Severidad</th>
                <th style="width:230px;">Regla</th>
                <th style="width:150px;">Entidad</th>
                <th>Detalle</th>
                <th style="width:100px;">Tarea</th>
                <th style="width:150px;">Detectado</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$gaps): ?>
            <tr><td colspan="6">No hay brechas para los filtros seleccionados.</td></tr>
        <?php else: ?>
            <?php foreach ($gaps as $gap): ?>
                <?php
                $detail = json_decode($gap['detalle_json'] ?? '{}', true);
                $detail_text = is_array($detail)
                    ? implode(' · ', array_map(
                        static function ($key, $value) {
                            if (is_array($value) || is_object($value)) {
                                $value = wp_json_encode($value);
                            }
                            return $key . ': ' . $value;
                        },
                        array_keys($detail),
                        array_values($detail)
                    ))
                    : '';
                ?>
                <tr>
                    <td>
                        <span class="health-severity severity-<?php echo esc_attr($gap['severidad']); ?>">
                            <?php echo esc_html($gap['severidad']); ?>
                        </span>
                    </td>
                    <td><code><?php echo esc_html($gap['regla']); ?></code></td>
                    <td>
                        <?php echo esc_html($gap['entidad_tipo']); ?>
                        #<?php echo esc_html($gap['entidad_id']); ?>
                    </td>
                    <td><?php echo esc_html($detail_text ?: 'Sin detalle adicional'); ?></td>
                    <td>
                        <?php if ($gap['tarea_id']): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=riverso-pos-tasks&id=' . intval($gap['tarea_id']))); ?>">
                                #<?php echo esc_html($gap['tarea_id']); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($gap['detectado_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.riverso-health-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}
.riverso-health-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:16px}
.riverso-health-card strong{display:block;font-size:28px;line-height:1.1}
.riverso-health-card span{color:#646970}
.riverso-health-card.critical{border-left-color:#d63638}
.riverso-health-card.high{border-left-color:#dba617}
.riverso-health-card.coverage{border-left-color:#00a32a}
.riverso-health-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:16px 0}
.riverso-health-toolbar form{display:flex;gap:8px;align-items:center}
.health-severity{display:inline-block;padding:3px 8px;border-radius:12px;background:#dcdcde}
.severity-critica{background:#d63638;color:#fff}.severity-alta{background:#f0c33c;color:#1d2327}
.severity-media{background:#72aee6;color:#1d2327}.severity-baja{background:#dcdcde;color:#1d2327}
@media(max-width:782px){.riverso-health-toolbar,.riverso-health-toolbar form{align-items:stretch;flex-direction:column}}
</style>
