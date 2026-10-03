<?php
/**
 * Documento imprimible de cotización de venta (estilo Facto).
 *
 * @var array $doc Resultado de Riverso_Quote_Pdf::build()
 * @var string $css_url URL del CSS de impresión
 */
if (!defined('ABSPATH')) {
    exit;
}

$company = isset($doc['company']) && is_array($doc['company']) ? $doc['company'] : array();
$totals = isset($doc['totals']) && is_array($doc['totals']) ? $doc['totals'] : array();
$rows = isset($doc['rows']) && is_array($doc['rows']) ? $doc['rows'] : array();
$css_url = isset($css_url) ? (string) $css_url : '';
$logo_url = isset($logo_url) ? (string) $logo_url : '';
$notes = trim((string) ($doc['notes'] ?? ''));
$terms = trim((string) ($doc['validity_terms'] ?? ''));
$days = $doc['validity_days'] ?? null;
$has_validity = ($days !== null && $days !== '');

if (!function_exists('riverso_cq_pdf_money')) {
    /**
     * @param float|int $amount
     * @param int       $decimals
     * @return string
     */
    function riverso_cq_pdf_money($amount, $decimals = 0) {
        $amount = (float) $amount;
        $decimals = (int) $decimals;
        if ($decimals <= 0) {
            return '$' . number_format((int) round($amount), 0, ',', '.');
        }
        return '$' . number_format($amount, $decimals, ',', '.');
    }
}

if (!function_exists('riverso_cq_pdf_esc')) {
    /**
     * @param mixed $text
     * @return string
     */
    function riverso_cq_pdf_esc($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

$doc_heading = trim((string) ($doc['document_heading'] ?? ''));
if ($doc_heading === '') {
    $doc_heading = 'COTIZACIÓN';
}
$doc_number = (string) ($doc['document_number'] ?? ($doc['quote_number'] ?? ''));
$title = trim($doc_heading . ' ' . $doc_number);
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo riverso_cq_pdf_esc($title); ?></title>
    <?php if ($css_url !== ''): ?>
    <link rel="stylesheet" href="<?php echo riverso_cq_pdf_esc($css_url); ?>">
    <?php endif; ?>
</head>
<body class="cq-pdf-body">
    <div class="cq-pdf-toolbar no-print">
        <button type="button" class="cq-pdf-print-btn" onclick="window.print()">Imprimir / Guardar PDF</button>
        <button type="button" class="cq-pdf-close-btn" onclick="window.close()">Cerrar</button>
    </div>

    <article class="cq-pdf">
        <header class="cq-pdf-company">
            <?php if ($logo_url !== ''): ?>
            <img class="cq-pdf-logo" src="<?php echo riverso_cq_pdf_esc($logo_url); ?>" alt="Logo RS Riverso" width="78" height="74">
            <?php endif; ?>
            <div class="cq-pdf-company-text">
                <h1 class="cq-pdf-razon"><?php echo riverso_cq_pdf_esc($company['razon_social'] ?? ''); ?></h1>
                <p class="cq-pdf-giro"><?php echo riverso_cq_pdf_esc($company['giro'] ?? ''); ?></p>
                <p class="cq-pdf-meta"><?php echo riverso_cq_pdf_esc($company['direccion'] ?? ''); ?></p>
                <p class="cq-pdf-meta">
                    Fono: <?php echo riverso_cq_pdf_esc($company['fono'] ?? ''); ?>
                    &nbsp;·&nbsp;
                    Email: <?php echo riverso_cq_pdf_esc($company['email'] ?? ''); ?>
                    &nbsp;·&nbsp;
                    RUT: <?php echo riverso_cq_pdf_esc($company['rut'] ?? ''); ?>
                </p>
            </div>
        </header>

        <div class="cq-pdf-title-row">
            <h2><?php echo riverso_cq_pdf_esc($doc_heading); ?></h2>
            <div class="cq-pdf-number">
                <span class="cq-pdf-number-label">N º</span>
                <strong><?php echo riverso_cq_pdf_esc($doc_number); ?></strong>
            </div>
        </div>

        <section class="cq-pdf-header-grid">
            <div class="cq-pdf-party">
                <table class="cq-pdf-kv">
                    <tr>
                        <th>Fecha</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['issue_date'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>Señor(es)</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['customer_name'] !== '' ? $doc['customer_name'] : '—'); ?></td>
                        <th>RUT</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['customer_rut'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>Teléfono</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['customer_phone'] ?? ''); ?></td>
                        <th>Email Cliente</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['customer_email'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>Vendedor</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['seller_name'] ?? ''); ?></td>
                        <th>Email Vendedor</th>
                        <td><?php echo riverso_cq_pdf_esc($doc['seller_email'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>Moneda</th>
                        <td colspan="3"><?php echo riverso_cq_pdf_esc($doc['currency'] ?? 'PESO CHILENO'); ?></td>
                    </tr>
                </table>
            </div>
        </section>

        <section class="cq-pdf-payment">
            <div class="cq-pdf-payment-block">
                <strong>Transferencia bancaria</strong>
                <p><?php echo riverso_cq_pdf_esc($company['banco'] ?? ''); ?></p>
                <p><?php echo riverso_cq_pdf_esc($company['cuenta'] ?? ''); ?></p>
                <p>Titular <?php echo riverso_cq_pdf_esc($company['titular'] ?? ''); ?></p>
                <p>RUT <?php echo riverso_cq_pdf_esc($company['rut_banco'] ?? ''); ?></p>
                <p><?php echo riverso_cq_pdf_esc($company['email'] ?? ''); ?></p>
            </div>
        </section>

        <table class="cq-pdf-items">
            <thead>
                <tr>
                    <th class="cq-pdf-col-desc">Servicio/Producto</th>
                    <th class="cq-pdf-col-desc">Descripción</th>
                    <th class="cq-pdf-col-qty">Cant.</th>
                    <th class="cq-pdf-col-num">Valor</th>
                    <th class="cq-pdf-col-dsc">Dsc</th>
                    <th class="cq-pdf-col-iva">Afec. IVA</th>
                    <th class="cq-pdf-col-num">Imp. Esp.</th>
                    <th class="cq-pdf-col-num">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="8" class="cq-pdf-empty">Sin líneas.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td></td>
                    <td class="cq-pdf-col-desc"><?php echo riverso_cq_pdf_esc($row['description'] ?? ''); ?></td>
                    <td class="cq-pdf-col-qty"><?php echo riverso_cq_pdf_esc($row['qty_label'] ?? ''); ?></td>
                    <td class="cq-pdf-col-num"><?php
                        $unit_decimals = isset($row['unit_decimals']) ? (int) $row['unit_decimals'] : 2;
                        if ($unit_decimals < 2) {
                            $unit_decimals = 2;
                        }
                        if ($unit_decimals > 6) {
                            $unit_decimals = 6;
                        }
                        echo riverso_cq_pdf_money($row['unit_net'] ?? 0, $unit_decimals);
                    ?></td>
                    <td class="cq-pdf-col-dsc">
                        <?php
                        $dsc = (float) ($row['discount_pct'] ?? 0);
                        echo $dsc > 0 ? riverso_cq_pdf_esc(number_format($dsc, 2, ',', '.') . '%') : '';
                        ?>
                    </td>
                    <td class="cq-pdf-col-iva"><?php echo riverso_cq_pdf_esc($row['afecto_label'] ?? 'SI'); ?></td>
                    <td class="cq-pdf-col-num"></td>
                    <td class="cq-pdf-col-num"><?php
                        $line_net = (float) ($row['line_net'] ?? 0);
                        $line_decimals = (abs($line_net - round($line_net)) < 0.001) ? 0 : 2;
                        echo riverso_cq_pdf_money($line_net, $line_decimals);
                    ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <section class="cq-pdf-bottom">
            <div class="cq-pdf-notes-box">
                <h3>Observaciones</h3>
                <div class="cq-pdf-notes-body">
                    <?php if ($has_validity): ?>
                    <p><strong>Validez:</strong> <?php echo riverso_cq_pdf_esc((string) (int) $days); ?> días</p>
                    <?php endif; ?>
                    <?php if ($terms !== ''): ?>
                    <p><?php echo nl2br(riverso_cq_pdf_esc($terms)); ?></p>
                    <?php endif; ?>
                    <?php if ($notes !== ''): ?>
                    <p><?php echo nl2br(riverso_cq_pdf_esc($notes)); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <aside class="cq-pdf-totals-box">
                <table>
                    <tr>
                        <th>Monto Neto:</th>
                        <td><?php echo riverso_cq_pdf_money($totals['monto_neto'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Monto Exento:</th>
                        <td><?php echo riverso_cq_pdf_money($totals['monto_exento'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>IVA 19%:</th>
                        <td><?php echo riverso_cq_pdf_money($totals['iva'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Imp. Esp.:</th>
                        <td><?php echo riverso_cq_pdf_money($totals['imp_esp'] ?? 0); ?></td>
                    </tr>
                    <tr class="cq-pdf-total-row">
                        <th>Total:</th>
                        <td><?php echo riverso_cq_pdf_money($totals['total'] ?? 0); ?></td>
                    </tr>
                </table>
            </aside>
        </section>

        <footer class="cq-pdf-footer">
            <p>Documento generado por Riverso POS</p>
        </footer>
    </article>
</body>
</html>
