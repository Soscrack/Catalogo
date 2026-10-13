<?php
/**
 * Catálogo de métodos de pago (IDs FACTO).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Payment_Method_Repository {

    /** @return string */
    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_payment_methods';
    }

    /**
     * @param bool $only_active
     * @param bool $only_visible
     * @return array<int, array<string, mixed>>
     */
    public function list_all($only_active = true, $only_visible = false) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()}";
        $where = [];
        if ($only_active) {
            $where[] = 'activo = 1';
        }
        if ($only_visible) {
            $where[] = 'visible = 1';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY orden ASC, nombre ASC';
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        return array_map([$this, 'present'], $rows);
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
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return array{ok:bool,message?:string}
     */
    public function update($id, array $data) {
        global $wpdb;
        $method = $this->get($id);
        if (!$method) {
            return ['ok' => false, 'message' => 'Método de pago no encontrado.'];
        }
        $ok = $wpdb->update(
            $this->table(),
            [
                'facto_payment_type_id' => substr(sanitize_text_field((string) ($data['facto_payment_type_id'] ?? $method['facto_payment_type_id'])), 0, 16),
                'visible' => !empty($data['visible']) ? 1 : 0,
                'requiere_cheque' => !empty($data['requiere_cheque']) ? 1 : 0,
                'permite_vuelto' => !empty($data['permite_vuelto']) ? 1 : 0,
            ],
            ['id' => absint($id)]
        );
        if ($ok === false) {
            return ['ok' => false, 'message' => 'No se pudo actualizar el método.'];
        }
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row) {
        return [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'facto_payment_type_id' => (string) ($row['facto_payment_type_id'] ?? ''),
            'visible' => !empty($row['visible']),
            'requiere_cheque' => !empty($row['requiere_cheque']),
            'permite_vuelto' => !empty($row['permite_vuelto']),
            'orden' => (int) ($row['orden'] ?? 0),
            'activo' => !empty($row['activo']),
        ];
    }
}
