<?php
/**
 * Diseños de autollenado de receptor (por RUT + actividad económica)
 * y caché de consultas SII stc.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Receiver_Design_Repository {

    /** @return string */
    private function designs_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_receiver_designs';
    }

    /** @return string */
    private function cache_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_sii_cache';
    }

    /**
     * @param string $rut
     * @return string
     */
    public function normalize_rut($rut) {
        $rut = preg_replace('/[^0-9kK]/', '', (string) $rut);
        if (!is_string($rut) || strlen($rut) < 2) {
            return '';
        }
        return substr($rut, 0, -1) . '-' . strtoupper(substr($rut, -1));
    }

    /**
     * @param string $rut
     * @param string $activity_code
     * @param string $activity_glosa
     * @return string
     */
    public function resolve_activity_code($rut, $activity_code, $activity_glosa) {
        $code = trim((string) $activity_code);
        if ($code !== '') {
            return $code;
        }
        $glosa = trim((string) $activity_glosa);
        if ($glosa === '') {
            return 'MANUAL';
        }
        return 'G' . strtoupper(substr(md5(mb_strtoupper($glosa, 'UTF-8')), 0, 10));
    }

    /**
     * @param array<string, mixed> $data
     * @return int|WP_Error design id
     */
    public function upsert(array $data) {
        global $wpdb;
        $table = $this->designs_table();
        $rut = $this->normalize_rut($data['rut'] ?? '');
        if ($rut === '') {
            return new WP_Error('design_rut', 'RUT requerido para guardar diseño.');
        }
        $glosa = trim((string) ($data['activity_glosa'] ?? ''));
        $code = $this->resolve_activity_code($rut, (string) ($data['activity_code'] ?? ''), $glosa);
        $direccion = trim((string) ($data['direccion'] ?? ''));
        if ($glosa === '' || $direccion === '') {
            return new WP_Error('design_incomplete', 'Giro y dirección requeridos para guardar diseño.');
        }

        $row = [
            'rut' => $rut,
            'activity_code' => substr($code, 0, 32),
            'activity_glosa' => substr($glosa, 0, 255),
            'razon_social' => substr(trim((string) ($data['razon_social'] ?? '')), 0, 255),
            'direccion' => substr($direccion, 0, 255),
            'comuna' => substr(trim((string) ($data['comuna'] ?? '')), 0, 100),
            'ciudad' => substr(trim((string) ($data['ciudad'] ?? '')), 0, 100),
            'telefono' => substr(trim((string) ($data['telefono'] ?? '')), 0, 50),
            'codigo_postal' => substr(trim((string) ($data['codigo_postal'] ?? '0')), 0, 20),
            'updated_by' => get_current_user_id() ?: null,
            'updated_at' => current_time('mysql'),
        ];

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE rut = %s AND activity_code = %s LIMIT 1",
            $row['rut'],
            $row['activity_code']
        ));

        if ($existing_id > 0) {
            $ok = $wpdb->update($table, $row, ['id' => $existing_id]);
            if ($ok === false) {
                return new WP_Error('design_update', 'No se pudo actualizar el diseño.');
            }
            return $existing_id;
        }

        $row['created_at'] = current_time('mysql');
        $ok = $wpdb->insert($table, $row);
        if (!$ok) {
            return new WP_Error('design_insert', 'No se pudo guardar el diseño.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * @param string $rut
     * @param string $activity_code
     * @return array<string, mixed>|null
     */
    public function get($rut, $activity_code) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        $code = trim((string) $activity_code);
        if ($rut === '' || $code === '') {
            return null;
        }
        $table = $this->designs_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rut = %s AND activity_code = %s LIMIT 1",
            $rut,
            $code
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * @param string $rut
     * @return array<int, array<string, mixed>>
     */
    public function list_by_rut($rut) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        if ($rut === '') {
            return [];
        }
        $table = $this->designs_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rut = %s ORDER BY updated_at DESC",
            $rut
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row) {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'rut' => (string) ($row['rut'] ?? ''),
            'activity_code' => (string) ($row['activity_code'] ?? ''),
            'activity_glosa' => (string) ($row['activity_glosa'] ?? ''),
            'razon_social' => (string) ($row['razon_social'] ?? ''),
            'direccion' => (string) ($row['direccion'] ?? ''),
            'comuna' => (string) ($row['comuna'] ?? ''),
            'ciudad' => (string) ($row['ciudad'] ?? ''),
            'telefono' => (string) ($row['telefono'] ?? ''),
            'codigo_postal' => (string) ($row['codigo_postal'] ?? '0'),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param string $rut
     * @param bool   $ignore_ttl Return stale cache if true (for cooldown fallback).
     * @return array<string, mixed>|null
     */
    public function get_sii_cache($rut, $ignore_ttl = false) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        if ($rut === '') {
            return null;
        }
        $table = $this->cache_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload_json, fetched_at FROM {$table} WHERE rut = %s LIMIT 1",
            $rut
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        if (!$ignore_ttl) {
            $fetched = strtotime((string) $row['fetched_at']);
            if (!$fetched || (time() - $fetched) > Riverso_Sii_Stc_Client::TTL_SECONDS) {
                return null;
            }
        }
        $payload = json_decode((string) $row['payload_json'], true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * @param string               $rut
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function set_sii_cache($rut, array $payload) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        if ($rut === '') {
            return false;
        }
        $table = $this->cache_table();
        $json = wp_json_encode($payload);
        $now = current_time('mysql');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE rut = %s LIMIT 1",
            $rut
        ));
        if ($existing) {
            return false !== $wpdb->update($table, [
                'payload_json' => $json,
                'fetched_at' => $now,
            ], ['id' => (int) $existing]);
        }
        return false !== $wpdb->insert($table, [
            'rut' => $rut,
            'payload_json' => $json,
            'fetched_at' => $now,
        ]);
    }
}
