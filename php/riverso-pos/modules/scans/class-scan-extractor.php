<?php
/**
 * Extracción, validación y normalización de documentos escaneados.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Scan_Extractor {

    /**
     * Prompt principal para segmentación y extracción.
     */
    public function build_prompt($page_hint = null) {
        $expected_rut = riverso_get_scan_config('expected_receptor_rut', '76.443.852-3');
        $hint = '';
        if ($page_hint && !empty($page_hint['pagina_inicio']) && !empty($page_hint['pagina_fin'])) {
            $p0 = (int) $page_hint['pagina_inicio'];
            $p1 = (int) $page_hint['pagina_fin'];
            $hint = <<<HINT

MODO PÁGINAS ACOTADAS: analiza ÚNICAMENTE las páginas {$p0} a {$p1} (numeración 1-based).
Devuelve solo documentos cuyo rango pagina_inicio/pagina_fin caiga dentro de [{$p0}, {$p1}].
HINT;
        }

        return <<<PROMPT
Eres un extractor experto de documentos comerciales y tributarios chilenos a partir de PDFs escaneados (fotos de facturas impresas). El OCR embebido del escáner suele ser ilegible: usa VISIÓN sobre la imagen, no el texto extraído del PDF.

## Receptor esperado (nuestro cliente)
RUT {$expected_rut} — COMERCIALIZADORA YUBINZA RIVERA RAMIREZ EIRL (o variante abreviada "COM. YUBINZA…"). Dirección habitual: CONDELL 2999, Antofagasta.

## SEGMENTACIÓN — regla más importante
Antes de extraer campos, identifica cuántos documentos distintos hay:

1. **Por página**: en cada página busca encabezado propio (logo emisor, "FACTURA ELECTRÓNICA", "Guía de Despacho", "Detalle Embalaje", folio, RUT emisor).
2. **Agrupar páginas consecutivas** en UN solo documento solo si comparten: mismo RUT emisor + mismo folio + mismo tipo de documento. Ejemplo: página 1 con encabezado + página 2 solo con tabla de ítems y totales = 1 documento (pagina_inicio=1, pagina_fin=2).
3. **Separar documentos** cuando cambia folio, RUT emisor, o el tipo de documento. Ejemplo: páginas 1-2 factura Ferromat folio 1291575 + página 3 "Detalle Embalaje del Despacho NV:202102" = 2 documentos distintos.
4. **Hojas auxiliares** (packing list, detalle bultos, nota de venta interna del proveedor) son documentos separados aunque referencien la misma factura. Usar tipo_documento: nota_venta o guia_despacho según corresponda.
5. **Nunca** fusiones dos facturas con folios diferentes en un solo objeto.
6. Reporta pagina_inicio y pagina_fin (1-based) para cada documento.

## Tipos de documento y tipo_dte
| tipo_documento              | tipo_dte | Señales visuales |
| factura_electronica         | 33       | "FACTURA ELECTRÓNICA/ELECTRONICA", timbre PDF417 SII |
| factura_exenta              | 34       | "EXENTA" en encabezado |
| guia_despacho_electronica   | 52       | "GUÍA DE DESPACHO ELECTRÓNICA" |
| nota_credito                | 61       | "NOTA DE CRÉDITO" |
| nota_venta                  | 803      | "NV", "Nota de Venta", "Detalle Embalaje", sin timbre SII |
| guia_despacho               | 52       | Guía impresa o referencia de despacho |

## Campos a extraer por documento
- **emisor**: rut, razon_social, giro, direccion, comuna
- **receptor**: rut, razon_social, giro, direccion, comuna
- **folio**: número sin puntos ni espacios (1.291.575 → 1291575; Nº 257423 → 257423)
- **fecha_emision**, **fecha_vencimiento**: YYYY-MM-DD
- **forma_pago**: Crédito, Contado, "FACTURA 30 DIAS", etc.
- **items[]**: numero, codigo, descripcion, cantidad, unidad, precio_unitario, monto_total, descuento_pct, confianza
- **totales**: neto, exento, iva, flete, total, tasa_iva (usualmente 19)
- **referencias[]**: tipo + folio + fecha + razon

## Referencias — tipos permitidos en campo "tipo"
- guia_despacho — "Guía de Despacho Electrónica", tabla REFERENCIAS del DTE
- orden_compra — "Orden de Compra", "Ord.Compra", "O/C"
- nro_pedido — "Nro de Pedido", "Pedido", número interno del proveedor (ej. 202102)
- factura_electronica — cuando una guía/packing menciona "Las FACTURAS Son: 1291575"
- nota_venta — "NV : 202102" en documentos de embalaje

## Montos (Chile)
- Punto = separador de miles: 58.220 → 58220, 1.444 → 1444
- Coma = decimal (si aparece): 24,00 → 24
- precio_unitario y monto_total en pesos enteros salvo que haya decimales explícitos
- Si hay línea de FLETE separada, incluir en totales.flete

## Anotaciones manuscritas y sellos
- **anotaciones_manuscritas[]**: texto escrito a mano ("NETO FLETE 6.000", "x2" junto a cantidad, correcciones)
- **sellos_recepcion[]**: sellos de transporte ("TRANSPORTES G&G", fecha, cajas, kilos, "SIN REVISAR")
- Si un manuscrito modifica cantidad o precio, reflejar el valor corregido en items y anotar en campos_dudosos

## Calidad y honestidad
- NO inventes datos. Campo ilegible → null.
- confianza_global: 0.0–1.0 por documento
- confianza por ítem en items[].confianza
- campos_dudosos[]: nombres de campos con lectura incierta
- Si una página está en blanco o ilegible, no crear documento para ella
{$hint}
Responde JSON con TODOS los documentos detectados en el array "documentos". Un PDF multipágina casi siempre requiere revisar cada página antes de agrupar.
PROMPT;
    }

    /**
     * Indica si la extracción parece sub-segmentada respecto al total de páginas.
     */
    public function needs_resegmentation(array $documentos, $total_paginas) {
        $total_paginas = (int) $total_paginas;
        if ($total_paginas <= 1) {
            return false;
        }
        if (empty($documentos)) {
            return true;
        }
        if (count($documentos) === 1) {
            $doc = $documentos[0];
            $fin = (int) ($doc['pagina_fin'] ?? 1);
            $inicio = (int) ($doc['pagina_inicio'] ?? 1);
            // Un solo doc que no cubre todas las páginas → probable sub-segmentación
            if ($fin < $total_paginas) {
                return true;
            }
            // Un solo doc en PDF multipágina sin rango explícito
            if ($inicio === 1 && $fin === 1 && $total_paginas > 1) {
                return true;
            }
        }
        // Verificar cobertura: alguna página sin asignar
        $covered = [];
        foreach ($documentos as $doc) {
            $a = max(1, (int) ($doc['pagina_inicio'] ?? 1));
            $b = max($a, (int) ($doc['pagina_fin'] ?? $a));
            for ($p = $a; $p <= $b; $p++) {
                $covered[$p] = true;
            }
        }
        for ($p = 1; $p <= $total_paginas; $p++) {
            if (empty($covered[$p])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Segunda pasada: una llamada por página para forzar segmentación fina.
     *
     * @return array Lista de documentos mergeados
     */
    public function resegment_by_page($gemini, $file_path, $mime, $total_paginas) {
        $schema = Riverso_Gemini_Client::extraction_schema();
        $merged = [];
        for ($p = 1; $p <= $total_paginas; $p++) {
            $prompt = $this->build_prompt(['pagina_inicio' => $p, 'pagina_fin' => $p]);
            $partial = $gemini->extract_page_range($file_path, $mime, $prompt, $schema);
            if (is_wp_error($partial) || empty($partial['documentos'])) {
                continue;
            }
            foreach ($partial['documentos'] as $doc) {
                $doc['pagina_inicio'] = $p;
                $doc['pagina_fin'] = $p;
                $merged[] = $doc;
            }
        }
        return $this->merge_continuation_pages($merged);
    }

    /**
     * Fusiona documentos consecutivos que son continuación (mismo folio+emisor).
     */
    public function merge_continuation_pages(array $documentos) {
        if (count($documentos) <= 1) {
            return $documentos;
        }
        usort($documentos, function ($a, $b) {
            return ((int) ($a['pagina_inicio'] ?? 0)) <=> ((int) ($b['pagina_inicio'] ?? 0));
        });

        $merged = [];
        $current = null;

        foreach ($documentos as $doc) {
            $folio = riverso_normalize_folio($doc['folio'] ?? '');
            $rut = riverso_normalize_rut(($doc['emisor']['rut'] ?? ''));
            $tipo = (int) ($doc['tipo_dte'] ?? riverso_scan_tipo_dte($doc['tipo_documento'] ?? '', 0));

            if ($current === null) {
                $current = $doc;
                continue;
            }

            $cur_folio = riverso_normalize_folio($current['folio'] ?? '');
            $cur_rut = riverso_normalize_rut(($current['emisor']['rut'] ?? ''));
            $cur_tipo = (int) ($current['tipo_dte'] ?? riverso_scan_tipo_dte($current['tipo_documento'] ?? '', 0));
            $cur_fin = (int) ($current['pagina_fin'] ?? $current['pagina_inicio'] ?? 1);
            $doc_inicio = (int) ($doc['pagina_inicio'] ?? 1);

            $same_doc = ($folio !== '' && $folio === $cur_folio && $rut !== '' && $rut === $cur_rut && $tipo === $cur_tipo)
                || ($folio === '' && $cur_folio !== '' && $rut === $cur_rut && $doc_inicio === $cur_fin + 1);

            if ($same_doc) {
                $current['pagina_fin'] = (int) ($doc['pagina_fin'] ?? $doc_inicio);
                $current['items'] = array_merge($current['items'] ?? [], $doc['items'] ?? []);
                $current['referencias'] = array_merge($current['referencias'] ?? [], $doc['referencias'] ?? []);
                if (empty($current['folio']) && !empty($doc['folio'])) {
                    $current['folio'] = $doc['folio'];
                }
                if (empty($current['totales']['total']) && !empty($doc['totales']['total'])) {
                    $current['totales'] = $doc['totales'];
                }
                $ann = array_merge($current['anotaciones_manuscritas'] ?? [], $doc['anotaciones_manuscritas'] ?? []);
                $current['anotaciones_manuscritas'] = array_values(array_unique($ann));
                $sellos = array_merge($current['sellos_recepcion'] ?? [], $doc['sellos_recepcion'] ?? []);
                $current['sellos_recepcion'] = array_values(array_unique($sellos));
            } else {
                $merged[] = $current;
                $current = $doc;
            }
        }
        if ($current !== null) {
            $merged[] = $current;
        }
        return $merged;
    }

    /**
     * Valida un documento extraído.
     *
     * @return array{ok:bool,errors:array,warnings:array,error_count:int,warning_count:int}
     */
    public function validate_document(array $doc) {
        $errors = [];
        $warnings = [];

        $rut_emisor = $doc['emisor']['rut'] ?? '';
        if ($rut_emisor && !riverso_validate_rut($rut_emisor)) {
            $errors[] = 'RUT emisor inválido';
        }

        $rut_recep = $doc['receptor']['rut'] ?? '';
        if ($rut_recep && !riverso_validate_rut($rut_recep)) {
            $warnings[] = 'RUT receptor inválido o ilegible';
        }

        $expected = strtoupper(preg_replace('/[^0-9K]/', '', riverso_get_scan_config('expected_receptor_rut', '764438523')));
        $recep_clean = strtoupper(preg_replace('/[^0-9K]/', '', $rut_recep));
        if ($recep_clean && $expected && $recep_clean !== $expected) {
            $warnings[] = 'Documento para otro receptor (no es nuestra empresa)';
        }

        $tot = $doc['totales'] ?? [];
        $neto = (float) ($tot['neto'] ?? 0);
        $exento = (float) ($tot['exento'] ?? 0);
        $iva = (float) ($tot['iva'] ?? 0);
        $flete = (float) ($tot['flete'] ?? 0);
        $total = (float) ($tot['total'] ?? 0);
        $tasa = (float) ($tot['tasa_iva'] ?? 19);

        if ($total > 0) {
            $calc = $neto + $exento + $iva + $flete;
            if (abs($calc - $total) > 2) {
                $errors[] = 'Totales no cuadran (neto+exento+iva+flete ≠ total)';
            }
        }

        if ($neto > 0 && $iva > 0 && $tasa > 0) {
            $iva_calc = round($neto * ($tasa / 100));
            if (abs($iva_calc - $iva) > max(2, $iva * 0.02)) {
                $warnings[] = 'IVA no coincide con neto × tasa';
            }
        }

        $sum_items = 0;
        foreach ($doc['items'] ?? [] as $item) {
            $sum_items += (float) ($item['monto_total'] ?? 0);
        }
        if ($sum_items > 0 && $neto > 0 && abs($sum_items - $neto) > max(5, $neto * 0.02)) {
            $warnings[] = 'Suma de líneas difiere del neto';
        }

        $f_em = $doc['fecha_emision'] ?? '';
        $f_ve = $doc['fecha_vencimiento'] ?? '';
        if ($f_em && $f_ve && strtotime($f_em) > strtotime($f_ve)) {
            $warnings[] = 'Fecha emisión posterior a vencimiento';
        }

        if (empty($doc['folio'])) {
            $errors[] = 'Folio no detectado';
        }

        return [
            'ok'             => empty($errors),
            'errors'         => $errors,
            'warnings'       => $warnings,
            'error_count'    => count($errors),
            'warning_count'  => count($warnings),
        ];
    }

    /**
     * Normaliza documento IA → formato parse_dte_xml() / save_invoice().
     */
    public function normalize_to_factura(array $doc) {
        $tipo_dte = (int) ($doc['tipo_dte'] ?? 0);
        if ($tipo_dte <= 0) {
            $tipo_dte = riverso_scan_tipo_dte($doc['tipo_documento'] ?? 'factura_electronica', 33);
        }

        $folio = riverso_normalize_folio($doc['folio'] ?? '');
        $emisor = $doc['emisor'] ?? [];
        $receptor = $doc['receptor'] ?? [];
        $tot = $doc['totales'] ?? [];

        $factura = [
            'tipo_dte'      => $tipo_dte,
            'folio'         => $folio !== '' ? $folio : '0',
            'fecha_emision' => $this->normalize_date($doc['fecha_emision'] ?? ''),
            'forma_pago'    => $doc['forma_pago'] ?? '',
            'emisor'        => [
                'rut'          => riverso_normalize_rut($emisor['rut'] ?? ''),
                'razon_social' => trim($emisor['razon_social'] ?? ''),
                'giro'         => trim($emisor['giro'] ?? ''),
                'direccion'    => trim($emisor['direccion'] ?? ''),
                'comuna'       => trim($emisor['comuna'] ?? ''),
            ],
            'receptor'      => [
                'rut'          => riverso_normalize_rut($receptor['rut'] ?? ''),
                'razon_social' => trim($receptor['razon_social'] ?? ''),
                'giro'         => trim($receptor['giro'] ?? ''),
                'direccion'    => trim($receptor['direccion'] ?? ''),
                'comuna'       => trim($receptor['comuna'] ?? ''),
            ],
            'totales'       => [
                'neto'                  => (float) ($tot['neto'] ?? 0),
                'exento'                => (float) ($tot['exento'] ?? 0),
                'iva'                   => (float) ($tot['iva'] ?? 0),
                'flete'                 => (float) ($tot['flete'] ?? 0),
                'total'                 => (float) ($tot['total'] ?? 0),
                'tasa_iva'              => (float) ($tot['tasa_iva'] ?? 19),
                'impuestos_adicionales' => [],
            ],
            'items'         => [],
            'referencias'   => [],
            '_scan_meta'    => [
                'anotaciones_manuscritas' => $doc['anotaciones_manuscritas'] ?? [],
                'sellos_recepcion'        => $doc['sellos_recepcion'] ?? [],
                'campos_dudosos'          => $doc['campos_dudosos'] ?? [],
                'confianza_global'        => (float) ($doc['confianza_global'] ?? 0),
                'pagina_inicio'           => (int) ($doc['pagina_inicio'] ?? 1),
                'pagina_fin'              => (int) ($doc['pagina_fin'] ?? 1),
                'tipo_documento'          => $doc['tipo_documento'] ?? '',
            ],
        ];

        $num = 1;
        foreach ($doc['items'] ?? [] as $item) {
            $desc = trim($item['descripcion'] ?? '');
            $codigo = trim($item['codigo'] ?? '');
            $cantidad = (float) ($item['cantidad'] ?? 1);
            $precio = (float) ($item['precio_unitario'] ?? 0);
            $monto = (float) ($item['monto_total'] ?? 0);
            if ($precio <= 0 && $cantidad > 0 && $monto > 0) {
                $precio = $monto / $cantidad;
            }

            $factura_item = [
                'numero'              => (int) ($item['numero'] ?? $num),
                'nombre'              => $desc,
                'descripcion'         => $desc,
                'descripcion_raw'     => $desc,
                'cantidad'            => $cantidad,
                'unidad'              => trim($item['unidad'] ?? 'UN') ?: 'UN',
                'precio'              => $precio,
                'monto'               => $monto,
                'descuento_porcentaje'=> isset($item['descuento_pct']) ? (float) $item['descuento_pct'] : null,
                'descuento_monto'     => null,
                'recargo_porcentaje'  => null,
                'recargo_monto'       => null,
                'cod_imp_adic'        => null,
                'codigos'             => [],
                '_confianza'          => (float) ($item['confianza'] ?? 0),
            ];
            if ($codigo !== '') {
                $factura_item['codigos'][] = ['tipo' => 'INT1', 'valor' => $codigo];
            }
            $factura['items'][] = $factura_item;
            $num++;
        }

        $ref_num = 1;
        foreach ($doc['referencias'] ?? [] as $ref) {
            $tipo_ref = $ref['tipo'] ?? '';
            $factura['referencias'][] = [
                'numero_linea' => $ref_num,
                'tipo_doc_ref' => riverso_scan_ref_tipo_doc($tipo_ref),
                'folio_ref'    => riverso_normalize_folio($ref['folio'] ?? ''),
                'ind_global'   => 0,
                'cod_ref'      => null,
                'razon_ref'    => trim($ref['razon'] ?? ''),
                'fecha_ref'    => $this->normalize_date($ref['fecha'] ?? ''),
                '_tipo_ref'    => $tipo_ref,
            ];
            $ref_num++;
        }

        return $factura;
    }

    /**
     * Ruta R2 para archivo original.
     */
    public function r2_key_archivo($hash, $ext) {
        $ym = gmdate('Y/m');
        $prefix = riverso_scan_r2_prefix();
        return $prefix . 'archivos/' . $ym . '/' . $hash . '.' . $ext;
    }

    /**
     * Ruta R2 para meta.json del archivo.
     */
    public function r2_key_archivo_meta($hash) {
        $ym = gmdate('Y/m');
        $prefix = riverso_scan_r2_prefix();
        return $prefix . 'archivos/' . $ym . '/' . $hash . '.meta.json';
    }

    /**
     * Ruta R2 para JSON normalizado de documento.
     */
    public function r2_key_documento($rut_emisor, $tipo_dte, $folio, $doc_hash) {
        $rut_clean = strtoupper(preg_replace('/[^0-9K]/', '', (string) $rut_emisor));
        $year = gmdate('Y');
        $prefix = riverso_scan_r2_prefix();
        $folio_clean = riverso_normalize_folio($folio);
        if ($folio_clean === '') {
            $folio_clean = substr($doc_hash, 0, 12);
        }
        return $prefix . 'documentos/' . $rut_clean . '/' . $year . '/' . (int) $tipo_dte . '-' . $folio_clean . '.json';
    }

    private function normalize_date($value) {
        return self::normalize_date_value($value);
    }

    /**
     * Normaliza fecha a YYYY-MM-DD.
     */
    public static function normalize_date_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return current_time('Y-m-d');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{2})$/', $value, $m)) {
            return '20' . $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d', $ts) : current_time('Y-m-d');
    }
}
