<?php
/**
 * Correos de envío de DTE asociados a un RUT de receptor.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Receiver_Email_Repository {

    /** @return string */
    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_billing_receiver_emails';
    }

    /**
     * @param string $rut
     * @return string
     */
    public function normalize_rut($rut) {
        if (class_exists('Riverso_Receiver_Design_Repository')) {
            return (new Riverso_Receiver_Design_Repository())->normalize_rut($rut);
        }
        $rut = preg_replace('/[^0-9kK]/', '', (string) $rut);
        if (!is_string($rut) || strlen($rut) < 2) {
            return '';
        }
        return substr($rut, 0, -1) . '-' . strtoupper(substr($rut, -1));
    }

    /**
     * @param string $email
     * @return string
     */
    private function normalize_email($email) {
        $email = sanitize_email(strtolower(trim((string) $email)));
        return ($email !== '' && is_email($email)) ? $email : '';
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
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE rut = %s ORDER BY is_selected DESC, email ASC",
            $rut
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    /**
     * @param string $rut
     * @param string $email
     * @param bool   $selected
     * @return array{ok:bool,id?:int,email?:array,message?:string}
     */
    public function add($rut, $email, $selected = true) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        $email = $this->normalize_email($email);
        if ($rut === '') {
            return ['ok' => false, 'message' => 'RUT requerido para guardar el correo.'];
        }
        if ($email === '') {
            return ['ok' => false, 'message' => 'Correo no válido.'];
        }
        $now = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE rut = %s AND email = %s LIMIT 1",
            $rut,
            $email
        ), ARRAY_A);
        if ($existing) {
            $wpdb->update($this->table(), [
                'is_selected' => $selected ? 1 : 0,
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);
            $row = $this->get((int) $existing['id']);
            return ['ok' => true, 'id' => (int) $existing['id'], 'email' => $row];
        }
        $ok = $wpdb->insert($this->table(), [
            'rut' => $rut,
            'email' => $email,
            'is_selected' => $selected ? 1 : 0,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo guardar el correo.'];
        }
        $id = (int) $wpdb->insert_id;
        return ['ok' => true, 'id' => $id, 'email' => $this->get($id)];
    }

    /**
     * @param int  $id
     * @param bool $selected
     * @return array{ok:bool,email?:array,message?:string}
     */
    public function set_selected($id, $selected) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Correo inválido.'];
        }
        $existing = $this->get($id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Correo no encontrado.'];
        }
        $ok = $wpdb->update($this->table(), [
            'is_selected' => $selected ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo actualizar el correo.'];
        }
        return ['ok' => true, 'email' => $this->get($id)];
    }

    /**
     * @param int $id
     * @return array{ok:bool,email?:array,message?:string}
     */
    public function delete($id) {
        global $wpdb;
        $id = absint($id);
        $existing = $this->get($id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Correo no encontrado.'];
        }
        $ok = $wpdb->delete($this->table(), ['id' => $id]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo eliminar el correo.'];
        }
        return ['ok' => true, 'email' => $existing];
    }

    /**
     * Marca como seleccionados los correos usados y desmarca el resto del RUT.
     * Agrega los que aún no existan.
     *
     * @param string             $rut
     * @param array<int, string> $emails
     */
    public function mark_used($rut, array $emails) {
        global $wpdb;
        $rut = $this->normalize_rut($rut);
        if ($rut === '') {
            return;
        }
        $normalized = [];
        foreach ($emails as $email) {
            $e = $this->normalize_email($email);
            if ($e !== '') {
                $normalized[$e] = $e;
            }
        }
        $normalized = array_values($normalized);
        $now = current_time('mysql');
        $wpdb->update($this->table(), [
            'is_selected' => 0,
            'updated_at' => $now,
        ], ['rut' => $rut]);
        foreach ($normalized as $email) {
            $this->add($rut, $email, true);
        }
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get($id) {
        global $wpdb;
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        return $row ? $this->present($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row) {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'rut' => (string) ($row['rut'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'is_selected' => !empty($row['is_selected']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
