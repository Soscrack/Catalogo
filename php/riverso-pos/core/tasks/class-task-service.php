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
     *
     * @param string $tipo
     * @param string $titulo
     * @param array  $data
     * @return mixed|null
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
        return null;
    }
}
