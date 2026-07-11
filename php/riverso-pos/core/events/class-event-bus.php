<?php
/**
 * Bus de eventos central para Riverso ERP
 * 
 * Proporciona un mecanismo de publicación/suscripción para desacoplar módulos.
 * Se construye encima de WordPress hooks pero con abstracción clara.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Event_Bus {

    /**
     * Instancia singleton.
     */
    private static $instance = null;

    /**
     * Mapa de suscripciones: event_name => [callbacks]
     */
    private $subscribers = [];

    /**
     * Histórico de eventos para debugging
     */
    private $event_history = [];

    /**
     * Máximo de eventos en histórico
     */
    const MAX_HISTORY = 500;

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
     * Publica un evento.
     * 
     * @param string $event_name Nombre del evento (ej: 'riverso.product.created')
     * @param array  $payload    Datos del evento
     * @param array  $context    Contexto adicional (user_id, employee_id, etc.)
     * @return mixed Resultado del último suscriptor (si hay)
     */
    public function publish($event_name, $payload = [], $context = []) {
        // Registrar en histórico
        $this->_add_to_history($event_name, $payload, $context);

        // Emitir hook de WordPress
        $args = array_merge($context, $payload);
        $hook_name = 'riverso_' . str_replace('.', '_', $event_name);
        
        // Aplicar do_action de WordPress (invoca todos los hooks registrados)
        do_action($hook_name, $payload, $context);

        // Si hay suscriptores locales (futura extensión)
        if (isset($this->subscribers[$event_name])) {
            $result = null;
            foreach ($this->subscribers[$event_name] as $callback) {
                $result = call_user_func($callback, $payload, $context);
            }
            return $result;
        }

        return null;
    }

    /**
     * Se suscribe a un evento.
     * 
     * @param string   $event_name Nombre del evento
     * @param callable $callback   Función callback
     * @param int      $priority   Prioridad (mayor = antes)
     */
    public function subscribe($event_name, $callback, $priority = 10) {
        if (!isset($this->subscribers[$event_name])) {
            $this->subscribers[$event_name] = [];
        }

        $this->subscribers[$event_name][] = $callback;

        // También registrar con WordPress hooks para compatibilidad
        $hook_name = 'riverso_' . str_replace('.', '_', $event_name);
        add_action($hook_name, $callback, $priority, 2);
    }

    /**
     * Se desuscribe de un evento.
     * 
     * @param string   $event_name
     * @param callable $callback
     */
    public function unsubscribe($event_name, $callback) {
        if (isset($this->subscribers[$event_name])) {
            $key = array_search($callback, $this->subscribers[$event_name], true);
            if ($key !== false) {
                unset($this->subscribers[$event_name][$key]);
            }
        }

        // Desregistrar de WordPress
        $hook_name = 'riverso_' . str_replace('.', '_', $event_name);
        remove_action($hook_name, $callback);
    }

    /**
     * Obtiene el histórico de eventos.
     * 
     * @param string $event_name (opcional) Filtrar por nombre
     * @param int    $limit      Cantidad máxima a retornar
     * @return array
     */
    public function get_history($event_name = null, $limit = 50) {
        $history = $this->event_history;

        if ($event_name) {
            $history = array_filter($history, function($e) use ($event_name) {
                return $e['event_name'] === $event_name;
            });
        }

        return array_slice(array_reverse($history), 0, $limit);
    }

    /**
     * Limpia el histórico.
     */
    public function clear_history() {
        $this->event_history = [];
    }

    /**
     * Agrega evento al histórico.
     * 
     * @private
     */
    private function _add_to_history($event_name, $payload, $context) {
        $this->event_history[] = [
            'event_name' => $event_name,
            'payload' => $payload,
            'context' => $context,
            'timestamp' => current_time('mysql'),
        ];

        // Limitar tamaño del histórico
        if (count($this->event_history) > self::MAX_HISTORY) {
            $this->event_history = array_slice($this->event_history, -self::MAX_HISTORY);
        }
    }
}

/**
 * Función helper global para publicar eventos.
 */
function riverso_event_publish($event_name, $payload = [], $context = []) {
    return Riverso_Event_Bus::get_instance()->publish($event_name, $payload, $context);
}

/**
 * Función helper global para suscribirse a eventos.
 */
function riverso_event_subscribe($event_name, $callback, $priority = 10) {
    Riverso_Event_Bus::get_instance()->subscribe($event_name, $callback, $priority);
}
