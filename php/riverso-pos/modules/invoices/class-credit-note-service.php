<?php
/**
 * Servicio de Notas de Crédito (TipoDTE=61)
 *
 * Responsabilidades:
 *   - Detectar si una factura es NC (TipoDTE=61)
 *   - Resolver la factura origen desde referencias XML
 *   - Calcular saldo efectivo (factura - abs(NC))
 *   - Gestionar reversas opcionales de inventario
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Credit_Note_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detecta si una factura es nota de crédito
     */
    public function is_credit_note($tipo_dte) {
        return (int) $tipo_dte === 61;
    }

    /**
     * Intenta resolver automáticamente la factura origen desde referencias XML
     *
     * @param array  $factura Datos parseados del XML
     * @param int    $rut_emisor RUT del emisor (para validación)
     * @param string $modo 'automatica' o 'manual'
     * @return array ['factura_id' => int|null, 'estado' => 'resuelta_automatica'|'resuelta_manual'|'ambigua'|'pendiente', 'mensaje' => string]
     */
    public function resolve_origen_factura(array $factura, $rut_emisor, $modo = 'automatica') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $referencias = $factura['referencias'] ?? [];
        if (empty($referencias)) {
            return [
                'factura_id' => null,
                'estado' => 'pendiente',
                'mensaje' => 'No hay referencias en el XML. Requiere asociación manual.',
            ];
        }

        // En este flujo, tomaremos la primera referencia (típicamente hay una)
        $ref = $referencias[0];

        $tipo_doc_ref = (int) ($ref['tipo_doc_ref'] ?? 0);
        $folio_ref = (string) ($ref['folio_ref'] ?? '0');
        $ind_global = (int) ($ref['ind_global'] ?? 0);

        // Si FolioRef=0 (documento global), no se puede resolver automáticamente
        if ($folio_ref === '0' || $folio_ref === 0) {
            return [
                'factura_id' => null,
                'estado' => 'ambigua',
                'mensaje' => 'La referencia tiene FolioRef=0 (documento global). Requiere asociación manual de la factura origen.',
            ];
        }

        // Buscar factura con (tipo_dte, folio, rut_emisor)
        // Tipos DTE comunes: 33=Factura, 34=Boleta, 52=Guía
        $posibles_tipos = $this->map_tipo_doc_ref_to_tipo_dte($tipo_doc_ref);

        $query = "SELECT f.id, f.tipo_dte, f.folio, f.monto_total 
                  FROM {$prefix}facturas f 
                  WHERE f.folio = %s 
                    AND f.rut_emisor = %s 
                    AND f.tipo_dte IN (" . implode(',', array_fill(0, count($posibles_tipos), '%d')) . ")
                  LIMIT 2";

        $params = array_merge([$folio_ref, $rut_emisor], $posibles_tipos);
        $matches = $wpdb->get_results($wpdb->prepare($query, ...$params));

        if (count($matches) === 1) {
            // Resolución automática exitosa
            return [
                'factura_id' => (int) $matches[0]->id,
                'estado' => 'resuelta_automatica',
                'mensaje' => "Factura origen resuelta: {$matches[0]->tipo_dte} Folio {$matches[0]->folio}",
            ];
        } elseif (count($matches) > 1) {
            // Múltiples coincidencias = ambiguo
            return [
                'factura_id' => null,
                'estado' => 'ambigua',
                'mensaje' => 'Se encontraron múltiples facturas coincidentes. Requiere selección manual.',
                'candidatos' => $matches,
            ];
        } else {
            // Sin coincidencias
            return [
                'factura_id' => null,
                'estado' => 'pendiente',
                'mensaje' => "No se encontró factura origen de tipo {$tipo_doc_ref} con folio {$folio_ref}.",
            ];
        }
    }

    /**
     * Mapea TpoDocRef (XML) a TipoDTE de BD
     *
     * TpoDocRef posibles: 33=Factura, 34=Boleta, 52=Guía, etc.
     */
    private function map_tipo_doc_ref_to_tipo_dte($tipo_doc_ref) {
        $mappings = [
            33 => [33],          // Factura → TipoDTE 33
            34 => [34],          // Boleta → TipoDTE 34
            52 => [52],          // Guía → TipoDTE 52
            61 => [61],          // NC → TipoDTE 61
        ];
        return $mappings[$tipo_doc_ref] ?? [$tipo_doc_ref];
    }

    /**
     * Registra la referencia de una NC (vinculada o pendiente de folio origen).
     *
     * @param int      $factura_nc_id ID de la NC
     * @param array    $referencia Datos XML (tipo_doc_ref, folio_ref, etc.)
     * @param int|null $factura_origen_id ID origen o null si queda pendiente
     * @param array    $options ['estado_resolucion','reversa_inventario','user_id','notas']
     * @return array|WP_Error
     */
    public function register_reference($factura_nc_id, array $referencia, $factura_origen_id = null, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura_origen_id = $factura_origen_id ? (int) $factura_origen_id : null;
        $user_id = $options['user_id'] ?? get_current_user_id();
        $reversa_inventario = !empty($options['reversa_inventario']) && $factura_origen_id;
        $estado = $options['estado_resolucion'] ?? ($factura_origen_id ? 'resuelta_manual' : 'pendiente');
        $folio_ref = (string) ($referencia['folio_ref'] ?? '0');
        $notas = (string) ($options['notas'] ?? '');

        if (!$factura_origen_id && $folio_ref !== '' && $folio_ref !== '0') {
            $notas = trim($notas . ' Folio origen ' . $folio_ref . ' pendiente de subir o vincular.');
        } elseif (!$factura_origen_id) {
            $notas = trim($notas . ' NC sin factura origen asignada.');
        }

        $nc_data = $wpdb->get_row($wpdb->prepare(
            "SELECT monto_total FROM {$prefix}facturas WHERE id = %d",
            $factura_nc_id
        ));
        $monto_nc = abs((float) ($nc_data->monto_total ?? 0));
        $saldo_efectivo = null;

        if ($factura_origen_id) {
            $origen_data = $wpdb->get_row($wpdb->prepare(
                "SELECT monto_total FROM {$prefix}facturas WHERE id = %d",
                $factura_origen_id
            ));
            if (!$origen_data) {
                return new WP_Error('not_found', 'Factura origen no encontrada');
            }
            $monto_origen = (float) ($origen_data->monto_total ?? 0);
            $saldo_efectivo = $monto_origen - $monto_nc;
            if ($saldo_efectivo < 0) {
                return new WP_Error(
                    'invalid_balance',
                    "Saldo negativo resultante: {$monto_origen} - {$monto_nc} = {$saldo_efectivo}. NC mayor que factura original."
                );
            }
        }

        $fecha_ref = (string) ($referencia['fecha_ref'] ?? '');
        if ($fecha_ref === '') {
            $fecha_ref = null;
        }

        $result = $wpdb->insert(
            "{$prefix}factura_referencias",
            [
                'factura_id' => $factura_nc_id,
                'tipo_doc_ref' => (int) ($referencia['tipo_doc_ref'] ?? 0),
                'folio_ref' => $folio_ref,
                'ind_global' => (int) ($referencia['ind_global'] ?? 0),
                'cod_ref' => isset($referencia['cod_ref']) && $referencia['cod_ref'] !== '' && $referencia['cod_ref'] !== null
                    ? (int) $referencia['cod_ref'] : null,
                'razon_ref' => (string) ($referencia['razon_ref'] ?? ''),
                'fecha_ref' => $fecha_ref,
                'factura_origen_id' => $factura_origen_id,
                'estado_resolucion' => $estado,
                'monto_descuento' => $monto_nc,
                'estado_reversa_inventario' => $reversa_inventario ? 'pendiente_aplicar' : 'sin_reversa',
                'notas' => $notas ?: null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%f', '%s', '%s', '%s']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error guardando referencia: ' . $wpdb->last_error);
        }

        $referencia_id = (int) $wpdb->insert_id;

        if ($factura_origen_id && class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.credit_note_linked',
                'factura_referencias',
                $referencia_id,
                'info',
                "NC vinculada a factura origen #{$factura_origen_id}. Monto: \${$monto_nc}",
                $user_id
            );
        } elseif (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.credit_note_pending',
                'factura_referencias',
                $referencia_id,
                'info',
                "NC pendiente de folio origen {$folio_ref}",
                $user_id
            );
        }

        return [
            'referencia_id' => $referencia_id,
            'factura_origen_id' => $factura_origen_id,
            'estado_resolucion' => $estado,
            'saldo_efectivo' => $saldo_efectivo,
            'folio_ref' => $folio_ref,
            'pendiente' => !$factura_origen_id,
            'reversa_pendiente' => (bool) $reversa_inventario,
        ];
    }

    /**
     * Vincula una NC a una factura origen e inserta registro en factura_referencias
     *
     * @param int   $factura_nc_id ID de la factura NC (la que se acaba de crear)
     * @param int   $factura_origen_id ID de la factura que se descuenta
     * @param array $referencia Datos extraídos del XML (tipo_doc_ref, folio_ref, etc.)
     * @param array $options ['reversa_inventario' => true/false, 'user_id' => int, 'estado_resolucion' => string]
     * @return array|WP_Error
     */
    public function link_credit_note($factura_nc_id, $factura_origen_id, array $referencia, array $options = []) {
        if (!(int) $factura_origen_id) {
            return new WP_Error('invalid', 'factura_origen_id requerido para vincular');
        }
        $options['estado_resolucion'] = $options['estado_resolucion'] ?? 'resuelta_manual';
        return $this->register_reference($factura_nc_id, $referencia, (int) $factura_origen_id, $options);
    }

    /**
     * Vincula (o re-vincula) una NC existente a una factura/flete origen.
     */
    public function assign_origen_to_credit_note($factura_nc_id, $factura_origen_id, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $factura_nc_id = (int) $factura_nc_id;
        $factura_origen_id = (int) $factura_origen_id;
        $user_id = $options['user_id'] ?? get_current_user_id();

        if (!$factura_nc_id || !$factura_origen_id) {
            return new WP_Error('invalid', 'IDs de NC y factura origen requeridos');
        }
        if ($factura_nc_id === $factura_origen_id) {
            return new WP_Error('invalid', 'La NC no puede vincularse a sí misma');
        }

        $nc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, tipo_dte, folio, monto_total, estado FROM {$prefix}facturas WHERE id = %d",
            $factura_nc_id
        ));
        $origen = $wpdb->get_row($wpdb->prepare(
            "SELECT id, tipo_dte, folio, monto_total, documento_subtipo FROM {$prefix}facturas WHERE id = %d",
            $factura_origen_id
        ));

        if (!$nc || (int) $nc->tipo_dte !== 61) {
            return new WP_Error('invalid', 'El documento no es una nota de crédito');
        }
        if (!$origen) {
            return new WP_Error('not_found', 'Factura/flete origen no encontrado');
        }
        if ((int) $origen->tipo_dte === 61) {
            return new WP_Error('invalid', 'No se puede vincular una NC a otra NC');
        }

        $monto_nc = abs((float) $nc->monto_total);
        $monto_origen = (float) $origen->monto_total;
        $saldo = $monto_origen - $monto_nc;
        if ($saldo < 0) {
            return new WP_Error(
                'invalid_balance',
                "Saldo negativo: {$monto_origen} - {$monto_nc} = {$saldo}"
            );
        }

        $ref = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}factura_referencias WHERE factura_id = %d ORDER BY id DESC LIMIT 1",
            $factura_nc_id
        ));

        if ($ref) {
            $updated = $wpdb->update(
                "{$prefix}factura_referencias",
                [
                    'factura_origen_id' => $factura_origen_id,
                    'estado_resolucion' => 'resuelta_manual',
                    'folio_ref' => (string) $origen->folio,
                    'tipo_doc_ref' => (int) $origen->tipo_dte,
                    'monto_descuento' => $monto_nc,
                    'notas' => sprintf(
                        'Vinculada manualmente a %s folio %s',
                        ($origen->documento_subtipo === 'envio' ? 'flete' : 'factura'),
                        $origen->folio
                    ),
                ],
                ['id' => (int) $ref->id],
                ['%d', '%s', '%s', '%d', '%f', '%s'],
                ['%d']
            );
            if ($updated === false) {
                return new WP_Error('db_error', 'Error actualizando referencia: ' . $wpdb->last_error);
            }
            $referencia_id = (int) $ref->id;
        } else {
            $created = $this->register_reference(
                $factura_nc_id,
                [
                    'tipo_doc_ref' => (int) $origen->tipo_dte,
                    'folio_ref' => (string) $origen->folio,
                ],
                $factura_origen_id,
                [
                    'user_id' => $user_id,
                    'estado_resolucion' => 'resuelta_manual',
                ]
            );
            if (is_wp_error($created)) {
                return $created;
            }
            $referencia_id = (int) $created['referencia_id'];
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => 'recibido'],
            ['id' => $factura_nc_id],
            ['%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.credit_note_assigned',
                'factura_referencias',
                $referencia_id,
                'info',
                "NC #{$factura_nc_id} vinculada a origen #{$factura_origen_id} (folio {$origen->folio})",
                $user_id
            );
        }

        return [
            'referencia_id' => $referencia_id,
            'factura_origen_id' => $factura_origen_id,
            'folio_origen' => $origen->folio,
            'documento_subtipo_origen' => $origen->documento_subtipo,
            'saldo_efectivo' => $saldo,
        ];
    }

    /**
     * Calcula el saldo efectivo de una factura considerando todas sus NC activas
     *
     * @param int $factura_id ID de la factura
     * @return float Saldo = monto_total - abs(sum(NC))
     */
    public function calculate_saldo_efectivo($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                f.monto_total,
                COALESCE(SUM(nc.monto_total), 0) as suma_nc
            FROM {$prefix}facturas f
            LEFT JOIN {$prefix}factura_referencias ref 
                ON ref.factura_origen_id = f.id AND ref.factura_id = %d
            LEFT JOIN {$prefix}facturas nc 
                ON nc.id = ref.factura_id AND nc.tipo_dte = 61
            WHERE f.id = %d
            GROUP BY f.id",
            $factura_id,
            $factura_id
        ));

        if (!$data) {
            return 0.0;
        }

        $monto_total = (float) $data->monto_total;
        $suma_nc = abs((float) $data->suma_nc);

        return max(0, $monto_total - $suma_nc);
    }

    /**
     * Desvincula una NC de su factura origen (para cancelación de pagos)
     *
     * @param int $referencia_id
     * @param array $options ['user_id' => int, 'razon' => string]
     * @return array|WP_Error
     */
    public function unlink_credit_note($referencia_id, array $options = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $user_id = $options['user_id'] ?? get_current_user_id();
        $razon = $options['razon'] ?? 'Desvinculación sin especificar razón';

        $ref = $wpdb->get_row($wpdb->prepare(
            "SELECT factura_id, factura_origen_id, estado_reversa_inventario FROM {$prefix}factura_referencias WHERE id = %d",
            $referencia_id
        ));

        if (!$ref) {
            return new WP_Error('not_found', 'Referencia no encontrada');
        }

        // Si hay reversa aplicada, no se puede desvincularse sin revertirla primero
        if ($ref->estado_reversa_inventario === 'aplicada') {
            return new WP_Error('reversa_active', 'No se puede desvinculares una NC con reversa aplicada. Primero revierta la reversa.');
        }

        // Eliminar registro
        $result = $wpdb->delete("{$prefix}factura_referencias", ['id' => $referencia_id]);

        if (!$result) {
            return new WP_Error('db_error', 'Error al desvincularse: ' . $wpdb->last_error);
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log(
                'invoice.credit_note_unlinked',
                'factura_referencias',
                $referencia_id,
                'info',
                "Referencia desvinculada. Razón: {$razon}",
                $user_id
            );
        }

        return ['success' => true, 'referencia_id' => $referencia_id];
    }
}
