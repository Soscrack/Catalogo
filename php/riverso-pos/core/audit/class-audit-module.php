<?php
/**
 * Clase de compatibilidad para auditoría.
 *
 * Muchos módulos (POS, costos, barcodes, facturas, cotizaciones) registran
 * eventos mediante `Riverso_Audit_Module::get_instance()->log(...)`, pero esa
 * clase no existía: la auditoría real vive en la clase estática
 * `Riverso_POS_Audit`. Todas esas llamadas estaban protegidas por
 * `class_exists('Riverso_Audit_Module')`, por lo que en la práctica nunca se
 * escribía nada al log.
 *
 * Esta clase puente expone la API esperada y delega en `Riverso_POS_Audit`,
 * habilitando la auditoría en todo el plugin sin tener que reescribir cada
 * punto de llamada.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Audit_Module {

    /**
     * Instancia singleton.
     */
    private static $instance = null;

    /**
     * Obtiene la instancia singleton.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registra una acción de auditoría.
     *
     * Firma compatible con el patrón usado en los módulos:
     *   log($action, $entity_type, $entity_id, $old_value, $new_value)
     *
     * @param string $action      Tipo de acción
     * @param string $entity_type Tipo de entidad
     * @param int    $entity_id   ID de la entidad
     * @param mixed  $old_value   Valor anterior (array o escalar)
     * @param mixed  $new_value   Valor nuevo (array o escalar)
     * @param string $details     Detalle textual opcional
     * @return int|false
     */
    public function log($action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        if (!class_exists('Riverso_POS_Audit')) {
            return false;
        }

        $data = [];
        if ($old_value !== null) {
            $data['old_value'] = $old_value;
        }
        if ($new_value !== null) {
            $data['new_value'] = $new_value;
        }
        if ($details !== '' && $details !== null) {
            $data['details'] = $details;
        }

        return Riverso_POS_Audit::log($action, $entity_type, $entity_id, $data);
    }

    /**
     * Registra una acción ejecutada por el sistema (created_by=computer).
     *
     * @param string $action
     * @param string $entity_type
     * @param int    $entity_id
     * @param mixed  $old_value
     * @param mixed  $new_value
     * @param string $details
     * @return int|false
     */
    public function log_system($action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        if (!class_exists('Riverso_POS_Audit')) {
            return false;
        }

        $data = ['actor_type' => 'computer'];
        if ($old_value !== null) {
            $data['old_value'] = $old_value;
        }
        if ($new_value !== null) {
            $data['new_value'] = $new_value;
        }
        if ($details !== '' && $details !== null) {
            $data['details'] = $details;
        }

        return Riverso_POS_Audit::log($action, $entity_type, $entity_id, $data);
    }

    public function log_import($action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        return $this->log_with_actor('import', $action, $entity_type, $entity_id, $old_value, $new_value, $details);
    }

    public function log_migration($action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        return $this->log_with_actor('migration', $action, $entity_type, $entity_id, $old_value, $new_value, $details);
    }

    public function log_api($action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        return $this->log_with_actor('api', $action, $entity_type, $entity_id, $old_value, $new_value, $details);
    }

    private function log_with_actor($actor_type, $action, $entity_type, $entity_id = null, $old_value = null, $new_value = null, $details = '') {
        if (!class_exists('Riverso_POS_Audit')) {
            return false;
        }

        $data = ['actor_type' => $actor_type];
        if ($old_value !== null) {
            $data['old_value'] = $old_value;
        }
        if ($new_value !== null) {
            $data['new_value'] = $new_value;
        }
        if ($details !== '' && $details !== null) {
            $data['details'] = $details;
        }

        return Riverso_POS_Audit::log($action, $entity_type, $entity_id, $data);
    }
}
