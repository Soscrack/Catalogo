<?php
/**
 * Asistente de Descuento: ajustes, competencia con actitud e historial de descuentos.
 *
 * Las reglas de la sugerencia viven en assets/js/discount-assistant.js (el editor de
 * líneas ya tiene bruto, unidades y costo); aquí solo se entregan los datos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Discount_Assistant_Service {

    const OPTION = 'riverso_discount_assistant_settings';

    const ACTITUDES = ['codicioso', 'competitivo', 'sin_clasificar'];

    /** Tipos de vínculo que cuentan para la sugerencia; el resto es solo referencia. */
    const TIPOS_VALIDOS = ['exacto', 'exacto_envase'];

    const ORIGENES = ['sugerido', 'maximo', 'minimo', 'marcador', 'historial', 'manual'];

    private static function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    /* ===== Ajustes ===== */

    /**
     * Criterio de volumen (solo aviso): (más de volume_units unidades y más de volume_amount)
     * o más de volume_amount_alone sin importar las unidades (0 desactiva esta segunda condición).
     */
    public static function default_settings() {
        return [
            'volume_units' => 1000,
            'volume_amount' => 75000,
            'volume_amount_alone' => 120000,
            'floor_factor' => 1.6,
            'desired_factor' => 1.7,
            'min_ratio_num' => 1.6,
            'min_ratio_den' => 1.8,
            'round_step' => 50,
            'stale_days' => 90,
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION, []);
        $settings = self::default_settings();
        if (is_array($saved)) {
            // Ajustes guardados antes del criterio con monto solo: 2.000 u. era el valor por defecto antiguo.
            if (!array_key_exists('volume_amount_alone', $saved) && isset($saved['volume_units'])
                && (float) $saved['volume_units'] === 2000.0) {
                unset($saved['volume_units']);
            }
            foreach ($settings as $key => $default) {
                if (isset($saved[$key]) && is_numeric($saved[$key])) {
                    $settings[$key] = (float) $saved[$key];
                }
            }
        }
        $settings['volume_units'] = (float) $settings['volume_units'];
        $settings['round_step'] = (int) $settings['round_step'];
        $settings['stale_days'] = (int) $settings['stale_days'];
        return $settings;
    }

    /**
     * @param array $input
     * @return array|WP_Error Ajustes guardados.
     */
    public static function save_settings(array $input) {
        $settings = self::get_settings();
        foreach (array_keys($settings) as $key) {
            if (!isset($input[$key]) || $input[$key] === '') {
                continue;
            }
            $value = str_replace(',', '.', (string) $input[$key]);
            if (!is_numeric($value)) {
                return new WP_Error('invalid', 'Valor no numérico en «' . $key . '».');
            }
            $settings[$key] = (float) $value;
        }
        if ($settings['floor_factor'] <= 0 || $settings['desired_factor'] <= 0) {
            return new WP_Error('invalid', 'Los factores deben ser mayores a 0.');
        }
        if ($settings['min_ratio_num'] <= 0 || $settings['min_ratio_den'] <= 0
            || $settings['min_ratio_num'] >= $settings['min_ratio_den']) {
            return new WP_Error('invalid', 'El descuento mínimo debe ser precio × A / B con A menor que B.');
        }
        if ($settings['round_step'] < 1 || $settings['stale_days'] < 1) {
            return new WP_Error('invalid', 'Redondeo y días de vigencia deben ser al menos 1.');
        }
        if ($settings['volume_units'] < 0 || $settings['volume_amount'] < 0 || $settings['volume_amount_alone'] < 0) {
            return new WP_Error('invalid', 'El criterio de volumen no puede ser negativo.');
        }
        $settings['round_step'] = (int) round($settings['round_step']);
        $settings['stale_days'] = (int) round($settings['stale_days']);
        update_option(self::OPTION, $settings, false);
        return self::get_settings();
    }

    /* ===== Actitud de competidores ===== */

    public static function normalize_actitud($value) {
        $value = sanitize_key((string) $value);
        if ($value === 'otro' || $value === '') {
            return 'sin_clasificar';
        }
        return in_array($value, self::ACTITUDES, true) ? $value : 'sin_clasificar';
    }

    public static function actitud_label($value) {
        switch (self::normalize_actitud($value)) {
            case 'codicioso':
                return 'Codicioso';
            case 'competitivo':
                return 'Competitivo';
            default:
                return 'Sin clasificar';
        }
    }

    /** Dominio sin www. de una URL; '' si no tiene host. */
    public static function domain_from_url($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host);
    }

    /** Dominio del competidor de una fila de competencia (URL del producto o de la fuente). */
    private static function row_domain(array $row) {
        $url = class_exists('Riverso_Competencia_Match_Service')
            ? Riverso_Competencia_Match_Service::product_page_url($row)
            : (string) ($row['url_producto'] ?? '');
        $domain = self::domain_from_url($url);
        if ($domain === '' && !empty($row['fuente_base_url'])) {
            $domain = self::domain_from_url($row['fuente_base_url']);
        }
        if ($domain === '' && !empty($row['fuente_slug']) && $row['fuente_slug'] !== 'manual') {
            $domain = $row['fuente_slug'] . '.cl';
        }
        return $domain;
    }

    /** @return array<string,array{dominio:string,nombre:string,actitud:string}> */
    private static function domain_map() {
        global $wpdb;
        $table = self::prefix() . 'competencia_competidores';
        $rows = $wpdb->get_results("SELECT dominio, nombre, actitud FROM {$table}", ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['dominio']] = [
                'dominio' => (string) $row['dominio'],
                'nombre' => (string) ($row['nombre'] ?? ''),
                'actitud' => self::normalize_actitud($row['actitud'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @return array|WP_Error
     */
    public static function set_domain_attitude($dominio, $actitud, $nombre = null) {
        global $wpdb;
        $dominio = strtolower(trim((string) $dominio));
        if (strpos($dominio, '://') !== false) {
            $dominio = self::domain_from_url($dominio);
        }
        $dominio = preg_replace('/^www\./', '', $dominio);
        if ($dominio === '' || !preg_match('/^[a-z0-9.-]+$/', $dominio)) {
            return new WP_Error('invalid_domain', 'Dominio no válido.');
        }
        $actitud = self::normalize_actitud($actitud);
        $table = self::prefix() . 'competencia_competidores';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre FROM {$table} WHERE dominio = %s",
            $dominio
        ), ARRAY_A);
        $data = [
            'actitud' => $actitud,
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        ];
        if ($nombre !== null && trim((string) $nombre) !== '') {
            $data['nombre'] = substr(sanitize_text_field((string) $nombre), 0, 120);
        }
        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
        } else {
            $data['dominio'] = $dominio;
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }
        return ['dominio' => $dominio, 'actitud' => $actitud];
    }

    /**
     * @return array|WP_Error
     */
    public static function set_link_attitude($producto_competencia_id, $actitud) {
        global $wpdb;
        $producto_competencia_id = (int) $producto_competencia_id;
        $table = self::prefix() . 'competencia_match';
        $match = $wpdb->get_row($wpdb->prepare(
            "SELECT id, estado FROM {$table} WHERE producto_competencia_id = %d",
            $producto_competencia_id
        ), ARRAY_A);
        if (!$match || ($match['estado'] ?? '') !== 'confirmado') {
            return new WP_Error('not_linked', 'No hay un vínculo confirmado para este producto de competencia.');
        }
        $actitud = self::normalize_actitud($actitud);
        $wpdb->update($table, [
            'actitud' => $actitud === 'sin_clasificar' ? null : $actitud,
            'revisado_por' => get_current_user_id(),
            'revisado_at' => current_time('mysql'),
        ], ['id' => (int) $match['id']]);
        return ['producto_competencia_id' => $producto_competencia_id, 'actitud' => $actitud];
    }

    /**
     * Competidores conocidos: los marcados más los dominios que aparecen en vínculos confirmados.
     *
     * @return array<int,array>
     */
    public static function list_domains() {
        global $wpdb;
        $prefix = self::prefix();
        $map = self::domain_map();
        $rows = $wpdb->get_results(
            "SELECT cp.url_producto, cp.slug, cp.fuente_id, f.slug AS fuente_slug, f.base_url AS fuente_base_url
             FROM {$prefix}competencia_match cm
             INNER JOIN {$prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
             LEFT JOIN {$prefix}competencia_fuentes f ON f.id = cp.fuente_id
             WHERE cm.estado = 'confirmado'",
            ARRAY_A
        ) ?: [];
        $counts = [];
        foreach ($rows as $row) {
            $domain = self::row_domain($row);
            if ($domain === '') {
                continue;
            }
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($map), array_keys($counts))) as $domain) {
            $known = $map[$domain] ?? null;
            $out[] = [
                'dominio' => $domain,
                'nombre' => $known && $known['nombre'] !== '' ? $known['nombre'] : $domain,
                'actitud' => $known ? $known['actitud'] : 'sin_clasificar',
                'actitud_label' => self::actitud_label($known ? $known['actitud'] : ''),
                'vinculos' => (int) ($counts[$domain] ?? 0),
            ];
        }
        usort($out, function ($a, $b) {
            return $b['vinculos'] <=> $a['vinculos'] ?: strcmp($a['dominio'], $b['dominio']);
        });
        return $out;
    }

    /**
     * Otros miembros activos de la familia (grupo de equivalencia) del SKU: pb => [sku, nombre].
     *
     * @return array<int,array{sku:string,nombre:string}>
     */
    private static function family_members($producto_base_id) {
        global $wpdb;
        $prefix = self::prefix();
        if (!self::has_table("{$prefix}equivalence_members") || !self::has_table("{$prefix}equivalence_groups")
            || !self::has_table("{$prefix}producto_base")) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT o.producto_base_id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id AND g.activo = 1
             INNER JOIN {$prefix}equivalence_members o ON o.grupo_id = em.grupo_id AND o.activo = 1
             INNER JOIN {$prefix}producto_base pb ON pb.id = o.producto_base_id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND o.producto_base_id <> %d",
            (int) $producto_base_id,
            (int) $producto_base_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['producto_base_id']] = [
                'sku' => (string) ($row['canonical_sku'] ?? ''),
                'nombre' => (string) ($row['nombre_canonico'] ?? ''),
            ];
        }
        return $out;
    }

    private static function has_table($table) {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            global $wpdb;
            $cache[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        return $cache[$table];
    }

    /**
     * Vínculos de competencia de un SKU con actitud efectiva, vigencia y si cuentan.
     * Incluye los vínculos confirmados de los otros miembros de su familia (via_familia; el
     * precio por unidad ya es comparable) y los sugeridos del propio SKU (no cuentan hasta
     * confirmarlos).
     *
     * @return array<int,array>
     */
    public static function competitors_for_product($producto_base_id, $settings = null) {
        global $wpdb;
        $prefix = self::prefix();
        $settings = $settings ?: self::get_settings();
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return [];
        }
        $has_actitud = self::has_column("{$prefix}competencia_match", 'actitud');
        $actitud_sql = $has_actitud ? 'cm.actitud' : 'NULL';
        // Fase 82 (URL deshabilitada) y precio oculto: columnas opcionales antes de migrar.
        $disabled_sql = self::has_column("{$prefix}competencia_productos", 'deshabilitado')
            ? 'cp.deshabilitado, cp.deshabilitado_at, cp.deshabilitado_motivo, cp.deshabilitado_hasta,
               cp.deshabilitado_precio, cp.precio_cambio_at'
            : '0 AS deshabilitado, NULL AS deshabilitado_at, NULL AS deshabilitado_motivo, NULL AS deshabilitado_hasta,
               NULL AS deshabilitado_precio, NULL AS precio_cambio_at';
        $oculto_sql = self::has_column("{$prefix}competencia_precios", 'oculto') ? 'pr.oculto' : '0';
        // Fase 83: precio oculto que igual cuenta («Usar siempre»).
        $usar_oculto_sql = self::has_column("{$prefix}competencia_productos", 'usar_oculto')
            ? 'cp.usar_oculto, cp.usar_oculto_at'
            : '0 AS usar_oculto, NULL AS usar_oculto_at';
        $family = self::family_members($producto_base_id);
        $pbs = array_merge([$producto_base_id], array_keys($family));
        $in = implode(',', array_map('intval', $pbs));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cm.tipo_match, {$actitud_sql} AS actitud_vinculo, cm.estado AS match_estado,
                    cm.producto_base_id AS match_producto_base_id,
                    cp.id AS producto_competencia_id, cp.nombre, cp.url_producto, cp.slug, cp.fuente_id,
                    {$disabled_sql}, {$usar_oculto_sql},
                    f.slug AS fuente_slug, f.nombre AS fuente_nombre, f.base_url AS fuente_base_url,
                    pr.precio_bruto_unitario, pr.precio_bruto_total, pr.cantidad_min,
                    pr.snapshot_fecha, pr.actualizado_at, {$oculto_sql} AS oculto
             FROM {$prefix}competencia_match cm
             INNER JOIN {$prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
             LEFT JOIN {$prefix}competencia_fuentes f ON f.id = cp.fuente_id
             LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
             WHERE cm.producto_base_id IN ({$in})
               AND (cm.estado = 'confirmado' OR (cm.estado = 'sugerido' AND cm.producto_base_id = %d))
             ORDER BY pr.precio_bruto_unitario ASC",
            $producto_base_id
        ), ARRAY_A) ?: [];

        $map = self::domain_map();
        $now = current_time('timestamp');
        $stale_days = max(1, (int) $settings['stale_days']);
        $today = current_time('Y-m-d');
        $out = [];
        foreach ($rows as $row) {
            $disabled = self::url_disabled_state($row, $today);
            $sugerido = ($row['match_estado'] ?? '') === 'sugerido';
            $member_pb = (int) ($row['match_producto_base_id'] ?? 0);
            $via = $member_pb !== $producto_base_id && isset($family[$member_pb])
                ? ['producto_base_id' => $member_pb] + $family[$member_pb]
                : null;
            $domain = self::row_domain($row);
            $known = $map[$domain] ?? null;
            $link = self::normalize_actitud($row['actitud_vinculo'] ?? '');
            $dom_actitud = $known ? $known['actitud'] : 'sin_clasificar';
            $effective = $link !== 'sin_clasificar' ? $link : $dom_actitud;
            $date = (string) ($row['actualizado_at'] ?: ($row['snapshot_fecha'] ?? ''));
            $ts = $date !== '' ? strtotime($date) : false;
            $age = $ts ? (int) floor(($now - $ts) / DAY_IN_SECONDS) : null;
            $stale = $age === null || $age > $stale_days;
            $unit = isset($row['precio_bruto_unitario']) && $row['precio_bruto_unitario'] !== null
                ? round((float) $row['precio_bruto_unitario'], 4)
                : null;
            $tipo = sanitize_key((string) ($row['tipo_match'] ?? ''));
            $valid_tipo = in_array($tipo, self::TIPOS_VALIDOS, true);
            // Motivos antes y después del precio oculto: el asistente puede usar el oculto
            // («Usar esta vez» / «Usar siempre») y entonces manda el motivo que quede.
            $before = '';
            if ($sugerido) {
                $before = 'Sugerido por coincidencias: confírmalo para que cuente.';
            } elseif (!$valid_tipo) {
                $before = 'Solo referencia: vínculo ' . strtolower(self::tipo_label($tipo)) . '.';
            } elseif ($unit === null || $unit <= 0) {
                $before = 'Sin precio.';
            }
            $after = '';
            if ($disabled['activo']) {
                $after = 'Deshabilitado' . ($disabled['motivo'] !== '' ? ': ' . $disabled['motivo'] : '') . '.'
                    . ($disabled['precio_cambio'] ? ' El precio cambió el ' . $disabled['precio_cambio'] . ': revisar.' : '');
            } elseif ($stale) {
                $after = 'Precio desactualizado (más de ' . $stale_days . ' días).';
            }
            $oculto = !empty($row['oculto']);
            $usar_oculto = !empty($row['usar_oculto']);
            $reason_used = $before !== '' ? $before : $after;
            $reason_unused = $before !== '' ? $before : ($oculto ? 'Precio oculto por el competidor.' : $after);
            $reason = $oculto && $usar_oculto ? $reason_used : $reason_unused;
            $out[] = [
                'producto_competencia_id' => (int) $row['producto_competencia_id'],
                'nombre' => (string) ($row['nombre'] ?? ''),
                'url' => class_exists('Riverso_Competencia_Match_Service')
                    ? Riverso_Competencia_Match_Service::product_page_url($row)
                    : (string) ($row['url_producto'] ?? ''),
                'dominio' => $domain,
                'competidor' => $known && $known['nombre'] !== ''
                    ? $known['nombre']
                    : ($domain !== '' ? $domain : (string) ($row['fuente_nombre'] ?? '')),
                'tipo_match' => $tipo,
                'tipo_match_label' => self::tipo_label($tipo),
                'precio_unitario' => $unit,
                'precio_total' => isset($row['precio_bruto_total']) ? (float) $row['precio_bruto_total'] : null,
                'cantidad_min' => isset($row['cantidad_min']) ? (int) $row['cantidad_min'] : 1,
                'fecha' => $date !== '' ? substr($date, 0, 10) : '',
                'dias' => $age,
                'desactualizado' => $stale,
                'actitud_vinculo' => $link,
                'actitud_dominio' => $dom_actitud,
                'actitud' => $effective,
                'actitud_label' => self::actitud_label($effective),
                'sugerido' => $sugerido,
                'via_familia' => $via,
                'oculto' => $oculto,
                'usar_oculto' => $usar_oculto,
                'usar_oculto_desde' => $usar_oculto && !empty($row['usar_oculto_at']) ? substr((string) $row['usar_oculto_at'], 0, 10) : '',
                // Motivo si se usa / no se usa el precio oculto ('' = cuenta); el navegador elige según «esta vez».
                'motivo_oculto_usado' => $reason_used,
                'motivo_oculto_sin_usar' => $reason_unused,
                'deshabilitado' => $disabled['activo'],
                'deshabilitado_marcado' => $disabled['marcado'],
                'deshabilitado_motivo' => $disabled['motivo'],
                'deshabilitado_desde' => $disabled['desde'],
                'deshabilitado_hasta' => $disabled['hasta'],
                'precio_cambio' => $disabled['precio_cambio'],
                'cuenta' => $reason === '',
                'motivo' => $reason,
            ];
        }
        return $out;
    }

    /**
     * URL deshabilitada: marcada y sin «hasta» vencido. precio_cambio: fecha en que el refresco
     * nocturno vio otro precio que el congelado al deshabilitar (para revisar y reactivar).
     */
    private static function url_disabled_state(array $row, $today) {
        $marcado = !empty($row['deshabilitado']);
        $hasta = !empty($row['deshabilitado_hasta']) ? substr((string) $row['deshabilitado_hasta'], 0, 10) : '';
        return [
            'marcado' => $marcado,
            'activo' => $marcado && ($hasta === '' || $hasta >= $today),
            'motivo' => (string) ($row['deshabilitado_motivo'] ?? ''),
            'desde' => !empty($row['deshabilitado_at']) ? substr((string) $row['deshabilitado_at'], 0, 10) : '',
            'hasta' => $hasta,
            'precio_cambio' => $marcado && !empty($row['precio_cambio_at']) ? substr((string) $row['precio_cambio_at'], 0, 10) : '',
        ];
    }

    /**
     * Deshabilita o habilita una URL de competencia para los cálculos de descuento.
     * Al deshabilitar congela el precio vigente; el refresco nocturno compara contra él.
     *
     * @return array|WP_Error
     */
    public static function set_url_enabled($producto_competencia_id, $enabled, $motivo = '', $hasta = '') {
        global $wpdb;
        $prefix = self::prefix();
        $id = (int) $producto_competencia_id;
        if (!self::has_column("{$prefix}competencia_productos", 'deshabilitado')) {
            return new WP_Error('schema', 'Falta la migración de la fase 82.');
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_productos WHERE id = %d",
            $id
        ));
        if ($exists <= 0) {
            return new WP_Error('not_found', 'Producto de competencia no encontrado.');
        }
        if ($enabled) {
            $data = [
                'deshabilitado' => 0,
                'deshabilitado_at' => null,
                'deshabilitado_por' => null,
                'deshabilitado_motivo' => null,
                'deshabilitado_hasta' => null,
                'deshabilitado_precio' => null,
                'precio_cambio_at' => null,
            ];
        } else {
            $hasta = trim((string) $hasta);
            if ($hasta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
                return new WP_Error('invalid_date', 'Fecha «hasta» no válida (AAAA-MM-DD).');
            }
            if ($hasta !== '' && $hasta < current_time('Y-m-d')) {
                return new WP_Error('invalid_date', 'La fecha «hasta» ya pasó.');
            }
            $precio = $wpdb->get_var($wpdb->prepare(
                "SELECT precio_bruto_unitario FROM {$prefix}competencia_precios WHERE producto_id = %d LIMIT 1",
                $id
            ));
            $motivo = trim((string) $motivo);
            $data = [
                'deshabilitado' => 1,
                'deshabilitado_at' => current_time('mysql'),
                'deshabilitado_por' => get_current_user_id() ?: null,
                'deshabilitado_motivo' => substr($motivo !== '' ? $motivo : 'Precio desactualizado', 0, 255),
                'deshabilitado_hasta' => $hasta !== '' ? $hasta : null,
                'deshabilitado_precio' => $precio !== null ? round((float) $precio, 6) : null,
                'precio_cambio_at' => null,
            ];
        }
        $wpdb->update("{$prefix}competencia_productos", $data, ['id' => $id]);
        return ['producto_competencia_id' => $id, 'habilitado' => (bool) $enabled];
    }

    /**
     * «Usar siempre» / «Dejar de usar para siempre» un precio oculto por el competidor: el
     * precio igual llega en la API (Sande) y con la marca cuenta en todos los descuentos.
     *
     * @return array|WP_Error
     */
    public static function set_hidden_price_use($producto_competencia_id, $use) {
        global $wpdb;
        $prefix = self::prefix();
        $id = (int) $producto_competencia_id;
        if (!self::has_column("{$prefix}competencia_productos", 'usar_oculto')) {
            return new WP_Error('schema', 'Falta la migración de la fase 83.');
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_productos WHERE id = %d",
            $id
        ));
        if ($exists <= 0) {
            return new WP_Error('not_found', 'Producto de competencia no encontrado.');
        }
        if ($use) {
            $oculto = self::has_column("{$prefix}competencia_precios", 'oculto')
                ? (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT oculto FROM {$prefix}competencia_precios WHERE producto_id = %d LIMIT 1",
                    $id
                ))
                : 0;
            if ($oculto !== 1) {
                return new WP_Error('not_hidden', 'Este precio no está oculto por el competidor: no hace falta marcarlo.');
            }
        }
        $wpdb->update("{$prefix}competencia_productos", $use ? [
            'usar_oculto' => 1,
            'usar_oculto_at' => current_time('mysql'),
            'usar_oculto_por' => get_current_user_id() ?: null,
        ] : [
            'usar_oculto' => 0,
            'usar_oculto_at' => null,
            'usar_oculto_por' => null,
        ], ['id' => $id]);
        return ['producto_competencia_id' => $id, 'usar_oculto' => (bool) $use];
    }

    /** Competencia de varios SKU (Aplicar descuentos sugeridos): pb => vínculos. */
    public static function competitors_for_products(array $ids, $settings = null) {
        $settings = $settings ?: self::get_settings();
        $out = [];
        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids))), 0, 150) as $pb) {
            $out[$pb] = self::competitors_for_product($pb, $settings);
        }
        return $out;
    }

    private static function tipo_label($tipo) {
        if (class_exists('Riverso_Competencia_Match_Service')) {
            return (string) Riverso_Competencia_Match_Service::tipo_match_label($tipo ?: 'otro');
        }
        return $tipo !== '' ? $tipo : 'otro';
    }

    /** SKU y nombre del producto local (búsquedas del modal Competencia). */
    public static function product_label($producto_base_id) {
        global $wpdb;
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT canonical_sku, nombre_canonico FROM ' . self::prefix() . 'producto_base WHERE id = %d',
            $producto_base_id
        ), ARRAY_A);
        return $row ? [
            'sku' => (string) ($row['canonical_sku'] ?? ''),
            'nombre' => (string) ($row['nombre_canonico'] ?? ''),
        ] : null;
    }

    /**
     * Ingreso manual desde el editor de líneas: crea/actualiza el vínculo y deja la actitud.
     *
     * @return array|WP_Error
     */
    public static function add_competitor(array $args) {
        if (!class_exists('Riverso_Competencia_Match_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/competencia/class-competencia-match-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Competencia_Match_Service')) {
            return new WP_Error('unavailable', 'Módulo de competencia no disponible.');
        }
        $result = Riverso_Competencia_Match_Service::manual_ingreso([
            'producto_base_id' => (int) ($args['producto_base_id'] ?? 0),
            'url' => (string) ($args['url'] ?? ''),
            'precio_total' => (float) ($args['precio_total'] ?? 0),
            'unidad' => max(1, (int) ($args['unidad'] ?? 1)),
            'tipo_match' => (string) ($args['tipo_match'] ?? ''),
            'nota' => 'Ingreso desde Asistente de Descuento',
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        $actitud = self::normalize_actitud($args['actitud'] ?? '');
        self::set_link_attitude((int) $result['producto_competencia_id'], $actitud);
        return $result;
    }

    /* ===== Historial ===== */

    private static function history_table() {
        return self::prefix() . 'descuento_historial';
    }

    private static function history_ready() {
        static $ready = null;
        if ($ready === null) {
            global $wpdb;
            $table = self::history_table();
            $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        return $ready;
    }

    private static function has_column($table, $column) {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            global $wpdb;
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $cache[$key] = is_array($cols) && in_array($column, $cols, true);
        }
        return $cache[$key];
    }

    public static function normalize_origin($value) {
        $value = sanitize_key((string) $value);
        return in_array($value, self::ORIGENES, true) ? $value : null;
    }

    /** Unidades facturables: envase multiplica cantidad × units_per_pack (igual que Riverso_Quote_Totals). */
    private static function billable_units(array $line) {
        $qty = (float) ($line['quantity'] ?? 0);
        $upp = isset($line['units_per_pack']) ? (float) $line['units_per_pack'] : 1.0;
        return $upp > 1.0001 ? round($qty * $upp, 3) : round($qty, 3);
    }

    /**
     * Fila de historial de una línea con descuento; null si la línea no tiene descuento.
     *
     * @param array $line  Línea presentada (cotización o borrador).
     * @param float $final Monto final de la línea (con descuento, bruto).
     * @param array $doc   evento, cotizacion_id, borrador_id, dte_id, documento, cliente.
     */
    private static function history_row(array $line, $final, array $doc) {
        $discount = round((float) ($line['discount_amount'] ?? 0), 2);
        if ($discount <= 0) {
            return null;
        }
        $final = round((float) $final, 2);
        $gross = round($final + $discount, 2);
        $units = self::billable_units($line);
        $cost = isset($line['unit_cost']) && $line['unit_cost'] !== null && $line['unit_cost'] !== ''
            ? (float) $line['unit_cost'] : null;
        $unit_final = $units > 0 ? round($final / $units, 4) : null;
        $anchor = isset($line['discount_anchor']) && $line['discount_anchor'] !== '' ? (string) $line['discount_anchor'] : null;
        $origin = self::normalize_origin($line['discount_origin'] ?? '');
        return [
            'producto_base_id' => !empty($line['producto_base_id']) ? (int) $line['producto_base_id'] : null,
            'sku' => substr((string) ($line['sku'] ?? ''), 0, 64),
            'descripcion' => substr((string) ($line['description'] ?? ''), 0, 255),
            'evento' => $doc['evento'],
            'cotizacion_id' => $doc['cotizacion_id'] ?: null,
            'borrador_id' => $doc['borrador_id'] ?: null,
            'dte_id' => $doc['dte_id'] ?: null,
            'documento' => substr((string) $doc['documento'], 0, 80),
            'cliente' => substr((string) $doc['cliente'], 0, 191),
            'cantidad' => round((float) ($line['quantity'] ?? 0), 3),
            'unidades' => $units,
            'costo_unitario' => $cost !== null ? round($cost, 4) : null,
            'bruto' => $gross,
            'descuento_monto' => $discount,
            'descuento_pct' => $gross > 0 ? round($discount / $gross * 100, 4) : 0,
            'total_final' => $final,
            'precio_final_unitario' => $unit_final,
            'factor_final' => $cost !== null && $cost > 0 && $unit_final !== null ? round($unit_final / $cost, 4) : null,
            'discount_anchor' => $anchor,
            'discount_anchor_value' => $anchor !== null && isset($line['discount_anchor_value']) && $line['discount_anchor_value'] !== null
                ? round((float) $line['discount_anchor_value'], 6) : null,
            'origen' => $origin ?: 'manual',
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ];
    }

    /**
     * Cotización aceptada (pasa a Aprobada): guarda sus líneas con descuento.
     * Si se vuelve a aceptar, reemplaza lo guardado de esa cotización.
     */
    public static function record_quote_accepted(array $quote) {
        if (!self::history_ready()) {
            return 0;
        }
        global $wpdb;
        $quote_id = (int) ($quote['id'] ?? 0);
        if ($quote_id <= 0) {
            return 0;
        }
        $wpdb->delete(self::history_table(), ['cotizacion_id' => $quote_id, 'evento' => 'aceptada']);
        $doc = [
            'evento' => 'aceptada',
            'cotizacion_id' => $quote_id,
            'borrador_id' => null,
            'dte_id' => null,
            'documento' => 'Cotización ' . (string) ($quote['quote_number'] ?? ('#' . $quote_id)),
            'cliente' => (string) ($quote['customer_name'] ?? ''),
        ];
        $count = 0;
        foreach ((array) ($quote['lines'] ?? []) as $line) {
            $row = self::history_row($line, (float) ($line['line_net'] ?? 0), $doc);
            if ($row !== null) {
                $wpdb->insert(self::history_table(), $row);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Documento emitido (o boleta cerrada sin SII): guarda sus líneas con descuento.
     * Reemplaza lo guardado al aceptar la cotización de origen: cuenta la venta real.
     */
    public static function record_draft_finalized(array $draft) {
        if (!self::history_ready()) {
            return 0;
        }
        global $wpdb;
        $draft_id = (int) ($draft['id'] ?? 0);
        if ($draft_id <= 0) {
            return 0;
        }
        $quote_id = !empty($draft['quote_id']) ? (int) $draft['quote_id'] : 0;
        $wpdb->delete(self::history_table(), ['borrador_id' => $draft_id]);
        if ($quote_id > 0) {
            $wpdb->delete(self::history_table(), ['cotizacion_id' => $quote_id, 'evento' => 'aceptada']);
        }
        $dte_id = !empty($draft['dte_id']) ? (int) $draft['dte_id'] : 0;
        $type = (int) ($draft['document_type_id'] ?? 37) === 37 ? 'Boleta' : 'Factura';
        $label = $type;
        if ($dte_id > 0) {
            $folio = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT folio FROM {$wpdb->prefix}riverso_dte_issued WHERE id = %d",
                $dte_id
            ));
            $label .= $folio !== '' ? ' ' . $folio : ' #' . $dte_id;
        } else {
            $label .= ' local #' . $draft_id;
        }
        $doc = [
            'evento' => 'emitida',
            'cotizacion_id' => $quote_id ?: null,
            'borrador_id' => $draft_id,
            'dte_id' => $dte_id ?: null,
            'documento' => $label,
            'cliente' => (string) ($draft['receiver_legal_name'] ?? ''),
        ];
        $count = 0;
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            $row = self::history_row($line, (float) ($line['line_total_bruto'] ?? 0), $doc);
            if ($row !== null) {
                $wpdb->insert(self::history_table(), $row);
                $count++;
            }
        }
        return $count;
    }

    private static function present_history_row(array $row) {
        $origin = (string) ($row['origen'] ?? '');
        $labels = [
            'sugerido' => 'Sugerido',
            'maximo' => 'Máximo',
            'minimo' => 'Mínimo',
            'marcador' => 'Marcador',
            'historial' => 'Repetido',
            'manual' => 'Manual',
        ];
        return [
            'id' => (int) $row['id'],
            'producto_base_id' => $row['producto_base_id'] !== null ? (int) $row['producto_base_id'] : null,
            'sku' => (string) ($row['sku'] ?? ''),
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'evento' => (string) $row['evento'],
            'documento' => (string) ($row['documento'] ?? ''),
            'cotizacion_id' => $row['cotizacion_id'] !== null ? (int) $row['cotizacion_id'] : null,
            'cliente' => (string) ($row['cliente'] ?? ''),
            'cantidad' => (float) $row['cantidad'],
            'unidades' => (float) $row['unidades'],
            'costo_unitario' => $row['costo_unitario'] !== null ? (float) $row['costo_unitario'] : null,
            'bruto' => (float) $row['bruto'],
            'descuento_monto' => (float) $row['descuento_monto'],
            'descuento_pct' => (float) $row['descuento_pct'],
            'total_final' => (float) $row['total_final'],
            'precio_final_unitario' => $row['precio_final_unitario'] !== null ? (float) $row['precio_final_unitario'] : null,
            'factor_final' => $row['factor_final'] !== null ? (float) $row['factor_final'] : null,
            'origen' => $origin,
            'origen_label' => $labels[$origin] ?? 'Manual',
            'fecha' => substr((string) $row['created_at'], 0, 10),
        ];
    }

    /** Últimos descuentos de un producto (para «Repetir» en el editor). */
    public static function history_for_product($producto_base_id, $limit = 8) {
        if (!self::history_ready() || (int) $producto_base_id <= 0) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::history_table() . ' WHERE producto_base_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
            (int) $producto_base_id,
            max(1, (int) $limit)
        ), ARRAY_A) ?: [];
        return array_map([__CLASS__, 'present_history_row'], $rows);
    }

    /** Historial paginado (Centro de Precios). */
    public static function list_history(array $args) {
        if (!self::history_ready()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50];
        }
        global $wpdb;
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = 50;
        $where = ['1=1'];
        $params = [];
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(sku LIKE %s OR descripcion LIKE %s OR cliente LIKE %s OR documento LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        $origen = self::normalize_origin($args['origen'] ?? '');
        if ($origen !== null) {
            $where[] = 'origen = %s';
            $params[] = $origen;
        }
        $evento = sanitize_key((string) ($args['evento'] ?? ''));
        if (in_array($evento, ['aceptada', 'emitida'], true)) {
            $where[] = 'evento = %s';
            $params[] = $evento;
        }
        $where_sql = implode(' AND ', $where);
        $table = self::history_table();
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($list_sql, array_merge($params, [$per_page, ($page - 1) * $per_page])),
            ARRAY_A
        ) ?: [];
        return [
            'rows' => array_map([__CLASS__, 'present_history_row'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }
}
