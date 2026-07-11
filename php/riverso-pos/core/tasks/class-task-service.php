<?php
/**
 * Fachada de tareas — API unificada para otros módulos
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Task_Service {

    /**
     * Solicita/crea una tarea vía Riverso_Task_Module si está disponible.
     * Si el módulo no está cargado, inserta directamente en BD.
     *
     * @param string $tipo
     * @param string $titulo
     * @param array  $data
     * @return int|WP_Error|null ID de tarea, WP_Error del módulo, o null solo si falla el insert
     */
    public static function request($tipo, $titulo, $data = []) {
        if (class_exists('Riverso_Task_Module')) {
            return Riverso_Task_Module::get_instance()->create_task(
                array_merge(
                    [
                        'tipo' => $tipo,
                        'titulo' => $titulo,
                    ],
                    $data
                )
            );
        }

        // Fallback: insert directo cuando el módulo aún no está bootstrappeado.
        global $wpdb;
        $insert = array_merge([
            'tipo' => $tipo,
            'titulo' => $titulo,
            'estado' => 'pendiente',
            'prioridad' => 'normal',
            'creado_por' => get_current_user_id(),
        ], $data);

        // Normalizar datos_extra a JSON si viene como array.
        if (isset($insert['datos_extra']) && is_array($insert['datos_extra'])) {
            $insert['datos_extra'] = wp_json_encode($insert['datos_extra']);
        }

        $ok = $wpdb->insert($wpdb->prefix . 'riverso_tareas', $insert);
        if (!$ok) {
            return null;
        }
        return (int) $wpdb->insert_id;
    }
}
