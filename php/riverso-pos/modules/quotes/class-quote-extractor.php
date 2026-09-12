<?php
/**
 * Extractor Gemini de cotizaciones de proveedores (no DTE).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Extractor {

    /**
     * @param string      $file_path
     * @param string      $mime
     * @param string|null $text_fallback texto pegado o extraído de Excel
     * @return array|WP_Error
     */
    public function extract($file_path, $mime, $text_fallback = null) {
        if (!class_exists('Riverso_Gemini_Client')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-gemini-client.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Gemini_Client')) {
            return new WP_Error('no_gemini', 'Cliente Gemini no disponible');
        }

        $client = new Riverso_Gemini_Client();
        if (!$client->is_configured()) {
            return new WP_Error('gemini_not_configured', 'Gemini API no está configurada.');
        }

        $prompt = $this->build_prompt();
        $schema = self::response_schema();

        $image_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff'];
        if ($file_path && is_readable($file_path) && in_array($mime, $image_mimes, true)) {
            $result = $client->extract_document($file_path, $mime, $prompt, $schema);
        } else {
            $text = $text_fallback;
            if (($text === null || $text === '') && $file_path && is_readable($file_path)) {
                $text = $this->file_to_text($file_path, $mime);
            }
            if ($text === null || trim((string) $text) === '') {
                return new WP_Error('no_content', 'No hay contenido para parsear.');
            }
            $result = $client->generate_json($prompt . "\n\n--- DOCUMENTO ---\n" . $text, $schema);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->normalize($result);
    }

    public function build_prompt() {
        return <<<'PROMPT'
Eres un extractor de COTIZACIONES / OFERTAS COMERCIALES de proveedores en Chile.
NO es una factura SII ni un DTE: no hay tipo_dte ni timbre electrónico obligatorio.

Extrae la cabecera y TODAS las líneas de productos/servicios.

Reglas:
- Montos en formato chileno: punto miles, coma decimal (ej. 1.234,56). Devuélvelos como números.
- precio_lista = precio de lista ANTES de descuento. Si solo hay un precio, úsalo como precio_neto y deja precio_lista igual o null.
- precio_neto = precio unitario NETO (después de descuento de línea, sin IVA).
- descuento_pct y descuento_monto de línea si aparecen (Würth DTO, Steelfix Descto., etc.).
- No inventes códigos ni precios. Si un campo no está, usa null.
- folio / numero_cotizacion si aparece (N° cotización, OC, referencia).
- fecha_documento y fecha_validez (válido hasta) en YYYY-MM-DD.
- tasa_iva típica Chile 19 si el documento muestra IVA; si es exento, 0.
- condiciones_pago si aparecen (crédito 30 días, contado, etc.).
- proveedor_nombre y rut_proveedor si se leen en el PDF.
- confianza_global 0–1. Lista campos_dudosos.

Ítems: codigo, descripcion, cantidad, unidad, precio_lista, precio_neto, descuento_pct, descuento_monto, subtotal, tasa_iva.
PROMPT;
    }

    public static function response_schema() {
        $item = [
            'type'       => 'OBJECT',
            'properties' => [
                'linea'           => ['type' => 'INTEGER', 'nullable' => true],
                'codigo'          => ['type' => 'STRING', 'nullable' => true],
                'descripcion'     => ['type' => 'STRING'],
                'cantidad'        => ['type' => 'NUMBER'],
                'unidad'          => ['type' => 'STRING', 'nullable' => true],
                'precio_lista'    => ['type' => 'NUMBER', 'nullable' => true],
                'precio_neto'     => ['type' => 'NUMBER'],
                'descuento_pct'   => ['type' => 'NUMBER', 'nullable' => true],
                'descuento_monto' => ['type' => 'NUMBER', 'nullable' => true],
                'subtotal'        => ['type' => 'NUMBER', 'nullable' => true],
                'tasa_iva'        => ['type' => 'NUMBER', 'nullable' => true],
            ],
            'required' => ['descripcion', 'cantidad', 'precio_neto'],
        ];

        return [
            'type'       => 'OBJECT',
            'properties' => [
                'proveedor_nombre'  => ['type' => 'STRING', 'nullable' => true],
                'rut_proveedor'     => ['type' => 'STRING', 'nullable' => true],
                'folio'             => ['type' => 'STRING', 'nullable' => true],
                'fecha_documento'   => ['type' => 'STRING', 'nullable' => true],
                'fecha_validez'     => ['type' => 'STRING', 'nullable' => true],
                'moneda'            => ['type' => 'STRING', 'nullable' => true],
                'tasa_iva'          => ['type' => 'NUMBER', 'nullable' => true],
                'descuento_pct'     => ['type' => 'NUMBER', 'nullable' => true],
                'descuento_monto'   => ['type' => 'NUMBER', 'nullable' => true],
                'condiciones_pago'  => ['type' => 'STRING', 'nullable' => true],
                'subtotal'          => ['type' => 'NUMBER', 'nullable' => true],
                'impuesto'          => ['type' => 'NUMBER', 'nullable' => true],
                'total'             => ['type' => 'NUMBER', 'nullable' => true],
                'items'             => ['type' => 'ARRAY', 'items' => $item],
                'confianza_global'  => ['type' => 'NUMBER'],
                'campos_dudosos'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['items', 'confianza_global'],
        ];
    }

    /**
     * @param array $raw
     * @return array
     */
    public function normalize($raw) {
        $items_in = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : [];
        $items = [];
        $linea = 1;
        foreach ($items_in as $it) {
            $neto = isset($it['precio_neto']) ? (float) $it['precio_neto'] : 0;
            $qty = isset($it['cantidad']) ? (float) $it['cantidad'] : 1;
            $lista = isset($it['precio_lista']) && $it['precio_lista'] !== null ? (float) $it['precio_lista'] : null;
            $sub = isset($it['subtotal']) && $it['subtotal'] !== null ? (float) $it['subtotal'] : round($neto * $qty, 4);
            $tasa = isset($it['tasa_iva']) && $it['tasa_iva'] !== null ? (float) $it['tasa_iva'] : (isset($raw['tasa_iva']) ? (float) $raw['tasa_iva'] : 19);
            $impuesto_u = $tasa > 0 ? round($neto * ($tasa / 100), 4) : 0;
            $items[] = [
                'linea'            => isset($it['linea']) ? (int) $it['linea'] : $linea,
                'codigo_proveedor' => isset($it['codigo']) ? (string) $it['codigo'] : '',
                'descripcion'      => isset($it['descripcion']) ? (string) $it['descripcion'] : '',
                'cantidad'         => $qty,
                'unidad'           => !empty($it['unidad']) ? (string) $it['unidad'] : 'UN',
                'precio_lista'     => $lista,
                'descuento_pct'    => isset($it['descuento_pct']) ? (float) $it['descuento_pct'] : null,
                'descuento_monto'  => isset($it['descuento_monto']) ? (float) $it['descuento_monto'] : null,
                'costo_neto'       => $neto,
                'tasa_iva'         => $tasa,
                'costo_impuesto'   => $impuesto_u,
                'costo_total'      => round($neto + $impuesto_u, 4),
                'subtotal'         => $sub,
            ];
            $linea++;
        }

        return [
            'proveedor_nombre' => $raw['proveedor_nombre'] ?? null,
            'rut_proveedor'    => $raw['rut_proveedor'] ?? null,
            'folio'            => $raw['folio'] ?? null,
            'fecha_documento'  => $this->normalize_date($raw['fecha_documento'] ?? null),
            'fecha_validez'    => $this->normalize_date($raw['fecha_validez'] ?? null),
            'moneda'           => !empty($raw['moneda']) ? strtoupper((string) $raw['moneda']) : 'CLP',
            'tasa_iva'         => isset($raw['tasa_iva']) ? (float) $raw['tasa_iva'] : 19,
            'descuento_pct'    => isset($raw['descuento_pct']) ? (float) $raw['descuento_pct'] : null,
            'descuento_monto'  => isset($raw['descuento_monto']) ? (float) $raw['descuento_monto'] : null,
            'condiciones_pago' => $raw['condiciones_pago'] ?? null,
            'subtotal'         => isset($raw['subtotal']) ? (float) $raw['subtotal'] : null,
            'impuesto'         => isset($raw['impuesto']) ? (float) $raw['impuesto'] : null,
            'total'            => isset($raw['total']) ? (float) $raw['total'] : null,
            'items'            => $items,
            'confianza_global' => isset($raw['confianza_global']) ? (float) $raw['confianza_global'] : 0,
            'campos_dudosos'   => $raw['campos_dudosos'] ?? [],
            'raw'              => $raw,
        ];
    }

    private function normalize_date($value) {
        if (!$value) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d', $ts) : null;
    }

    /**
     * Extrae texto plano de CSV/TXT/XLSX básico.
     */
    public function file_to_text($file_path, $mime) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'csv'], true) || strpos((string) $mime, 'text/') === 0) {
            $raw = file_get_contents($file_path);
            return is_string($raw) ? $raw : '';
        }
        if (in_array($ext, ['xlsx', 'xls'], true) && class_exists('ZipArchive')) {
            return $this->xlsx_to_text($file_path);
        }
        return '';
    }

    private function xlsx_to_text($file_path) {
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return '';
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss) {
            if (preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $ss, $m)) {
                $shared = $m[1];
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$sheet) {
            return '';
        }
        $rows = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $row_m)) {
            foreach ($row_m[1] as $row_xml) {
                $cells = [];
                if (preg_match_all('/<c([^>]*)>(?:<v>([^<]*)<\/v>)?/s', $row_xml, $c_m, PREG_SET_ORDER)) {
                    foreach ($c_m as $c) {
                        $attrs = $c[1];
                        $v = isset($c[2]) ? $c[2] : '';
                        if (strpos($attrs, 't="s"') !== false && isset($shared[(int) $v])) {
                            $cells[] = $shared[(int) $v];
                        } else {
                            $cells[] = $v;
                        }
                    }
                }
                if ($cells) {
                    $rows[] = implode("\t", $cells);
                }
            }
        }
        return implode("\n", $rows);
    }

    /**
     * Borrador de reclamo a partir de un análisis de costos.
     *
     * @param array  $analysis
     * @param string $mode simple|complex
     * @return string|array|WP_Error
     */
    public function draft_claim($analysis, $mode = 'simple') {
        $emails = $this->build_claim_emails($analysis);
        if ($mode === 'all') {
            return $emails;
        }
        return $mode === 'complex' ? $emails['complex'] : $emails['simple'];
    }

    /**
     * Correos de reclamo (simple / complejo). Nunca menciona legacy.
     *
     * @param array $analysis
     * @return array{subject:string,simple:string,complex:string,items:int}
     */
    public function build_claim_emails($analysis) {
        $header = $analysis['quote'] ?? $analysis['invoice'] ?? [];
        $proveedor = trim((string) ($header['proveedor_nombre'] ?? ''));
        if ($proveedor === '') {
            $proveedor = 'estimados';
        }
        $folio = trim((string) ($header['folio'] ?? ($header['numero_documento'] ?? '')));
        $doc_label = $folio !== '' ? ('cotización ' . $folio) : 'cotización';
        $subject = $folio !== ''
            ? ('Reclamo de precios — Cotización ' . $folio)
            : 'Reclamo de precios';

        $items = [];
        foreach ($analysis['rows'] ?? [] as $row) {
            if (($row['trend'] ?? '') !== 'subio') {
                continue;
            }
            $prev = $row['reference_cost'] ?? null;
            if ($prev === null || !is_numeric($prev)) {
                continue;
            }
            $items[] = [
                'codigo' => trim((string) ($row['codigo_proveedor'] ?? '')),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'precio_anterior' => (float) $prev,
                'referencia' => $this->claim_public_reference($row),
            ];
        }

        $greeting = "Estimados {$proveedor},\n\n";
        $intro = "Junto con saludar, revisamos la {$doc_label} y les pedimos por favor usar los precios anteriores en:\n\n";
        $closing = "\nQuedamos atentos a su confirmación.\n\nSaludos cordiales,\nCompras Riverso\n";

        if (!$items) {
            $empty = $greeting . "Revisamos la {$doc_label} y no encontramos alzas con precio anterior para reclamar.\n" . $closing;
            return [
                'subject' => $subject,
                'simple' => $empty,
                'complex' => $empty,
                'items' => 0,
            ];
        }

        $simple_body = $greeting . $intro;
        $complex_body = $greeting . $intro;
        foreach ($items as $it) {
            $title = trim($it['codigo'] . ' ' . $it['nombre']);
            $prev = '$' . number_format($it['precio_anterior'], 0, ',', '.');
            $block = $title . "\nprecio anterior: " . $prev . "\n";
            $simple_body .= $block . "\n";
            $complex_body .= $block;
            if ($it['referencia'] !== '') {
                $complex_body .= 'referencia: ' . $it['referencia'] . "\n";
            }
            $complex_body .= "\n";
        }

        return [
            'subject' => $subject,
            'simple' => $simple_body . $closing,
            'complex' => $complex_body . $closing,
            'items' => count($items),
        ];
    }

    /**
     * Referencia pública (factura o cotización). Legacy se omite.
     *
     * @param array $row
     * @return string
     */
    private function claim_public_reference(array $row) {
        $candidates = [];
        if (!empty($row['prev_invoice']) && is_array($row['prev_invoice'])) {
            $candidates[] = $row['prev_invoice'];
        }
        if (!empty($row['prev_quote']) && is_array($row['prev_quote'])) {
            $candidates[] = $row['prev_quote'];
        }
        foreach ($candidates as $ref) {
            $kind = strtolower((string) ($ref['source_kind'] ?? $ref['match_path'] ?? ''));
            $folio = trim((string) ($ref['folio'] ?? ''));
            if ($kind === 'legacy' || strcasecmp($folio, 'LEGACY') === 0) {
                continue;
            }
            if ($folio === '' && empty($ref['fecha_emision']) && empty($ref['factura_id']) && empty($ref['cotizacion_id'])) {
                continue;
            }
            $parts = [];
            if (!empty($ref['factura_id']) || (isset($ref['tipo_dte']) && $ref['tipo_dte'])) {
                $parts[] = 'Factura' . ($folio !== '' ? (' folio ' . $folio) : '');
            } elseif (!empty($ref['cotizacion_id'])) {
                $parts[] = 'Cotización' . ($folio !== '' ? (' ' . $folio) : '');
            } elseif ($folio !== '') {
                $parts[] = $folio;
            }
            if (!empty($ref['fecha_emision'])) {
                $parts[] = $ref['fecha_emision'];
            }
            if (!empty($ref['proveedor_nombre'])) {
                $parts[] = $ref['proveedor_nombre'];
            }
            $label = implode(' · ', array_filter($parts));
            if ($label !== '' && stripos($label, 'legacy') === false) {
                return $label;
            }
        }
        return '';
    }
}
