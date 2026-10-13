<?php
/**
 * Módulo Clientes comerciales (solo clientes, no proveedores).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Module {

    private static $instance = null;

    /** @var Riverso_Customer_Repository */
    private $repo;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->ensure_schema();
        $repo_file = dirname(__FILE__) . '/class-customer-repository.php';
        if (file_exists($repo_file)) {
            require_once $repo_file;
        }
        $this->repo = new Riverso_Customer_Repository();
        $this->init_hooks();
    }

    public function init() {
        // Compatibilidad con bootstrap del plugin.
    }

    /**
     * Schema propio (por si el activador no corrió).
     */
    public static function create_tables() {
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_clientes_schema')) {
            Riverso_POS_Activator::ensure_clientes_schema();
        }
    }

    private function ensure_schema() {
        self::create_tables();
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_customers_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_customers_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_customers_save', [$this, 'ajax_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'riverso-pos-customers') === false) {
            return;
        }
        $this->enqueue_front_assets();
    }

    public function enqueue_front_assets() {
        $css = RIVERSO_POS_PLUGIN_DIR . 'assets/css/customers.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'riverso-customers',
                RIVERSO_POS_PLUGIN_URL . 'assets/css/customers.css',
                [],
                (string) filemtime($css)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function app_config() {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_customers'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'standalone' => false,
            'surface' => 'admin',
            'portalUrl' => home_url('/interno/customers/'),
            'caps' => [
                'view' => $this->can_view(),
                'edit' => $this->can_edit(),
            ],
            'comunas' => self::chile_comunas(),
            'actions' => [
                'list' => 'riverso_customers_list',
                'get' => 'riverso_customers_get',
                'save' => 'riverso_customers_save',
            ],
        ];
    }

    /**
     * @param string|null $surface
     */
    public function render_app($surface = null) {
        if ($surface !== 'portal' && $surface !== 'admin') {
            $surface = (function_exists('is_admin') && is_admin()) ? 'admin' : 'portal';
        }
        if (!$this->can_view()) {
            echo '<div class="wrap"><p>No tienes permisos para ver clientes.</p></div>';
            return;
        }
        $riverso_customers = $this->app_config();
        $riverso_customers['surface'] = $surface;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/customers/app.php';
    }

    public function ajax_list() {
        $this->authorize_view();
        $search = $this->post_string('search');
        $status = $this->post_string('status');
        if ($status === '') {
            $status = 'active';
        }
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 25;

        $result = $this->repo->list_customers(
            [
                'search' => $search,
                'status' => $status,
            ],
            $page,
            $per_page
        );

        wp_send_json_success([
            'customers' => $result['items'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
        ]);
    }

    public function ajax_get() {
        $this->authorize_view();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $customer = $this->repo->get($id);
        if (!$customer) {
            wp_send_json_error(['message' => 'Cliente no encontrado']);
        }
        wp_send_json_success(['customer' => $customer]);
    }

    /**
     * Arma el payload de guardado desde $_POST (reutilizable desde cotizaciones).
     *
     * @return array<string, mixed>
     */
    public function input_from_request() {
        $datos_extra = [];
        if (isset($_POST['datos_extra'])) {
            $raw = wp_unslash($_POST['datos_extra']);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $datos_extra = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $datos_extra = $raw;
            }
        }

        return [
            'id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
            'nombre_fantasia' => $this->post_string('nombre_fantasia'),
            'has_contacto' => !empty($_POST['has_contacto']),
            'primer_nombre' => $this->post_string('primer_nombre'),
            'apellido_paterno' => $this->post_string('apellido_paterno'),
            'contacto_telefono' => $this->post_string('contacto_telefono'),
            'contacto_email' => $this->post_string('contacto_email'),
            'has_facturacion' => !empty($_POST['has_facturacion']),
            'pais' => $this->post_string('pais'),
            'tipo_identificacion' => $this->post_string('tipo_identificacion'),
            'rut' => $this->post_string('rut'),
            'razon_social' => $this->post_string('razon_social'),
            'direccion' => $this->post_string('direccion'),
            'comuna' => $this->post_string('comuna'),
            'ciudad' => $this->post_string('ciudad'),
            'giro' => $this->post_string('giro'),
            'facturacion_telefono' => $this->post_string('facturacion_telefono'),
            'codigo_postal' => $this->post_string('codigo_postal'),
            'has_datos_extra' => !empty($_POST['has_datos_extra']),
            'datos_extra' => $datos_extra,
            'activo' => !isset($_POST['activo']) || !empty($_POST['activo']),
        ];
    }

    public function ajax_save() {
        $this->authorize_edit();

        $input = $this->input_from_request();

        $result = $this->repo->save($input);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo guardar']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            $action = ($input['id'] > 0) ? 'customer_updated' : 'customer_created';
            Riverso_POS_Audit::log($action, 'customer', (int) $result['id'], [
                'entity_name' => $input['nombre_fantasia'],
            ]);
        }

        wp_send_json_success([
            'message' => $result['message'],
            'id' => $result['id'],
            'customer' => $result['customer'] ?? null,
        ]);
    }

    private function authorize_view() {
        check_ajax_referer('riverso_customers', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
    }

    private function authorize_edit() {
        check_ajax_referer('riverso_customers', 'nonce');
        if (!$this->can_edit()) {
            wp_send_json_error(['message' => 'Sin permisos para editar clientes']);
        }
    }

    private function can_view() {
        return current_user_can('riverso_view_customers')
            || current_user_can('riverso_edit_customers')
            || current_user_can('manage_options');
    }

    private function can_edit() {
        return current_user_can('riverso_edit_customers')
            || current_user_can('manage_options');
    }

    /**
     * @param string $key
     * @return string
     */
    private function post_string($key) {
        if (!isset($_POST[$key])) {
            return '';
        }
        return sanitize_text_field(wp_unslash((string) $_POST[$key]));
    }

    /**
     * Comunas de Chile (lista operativa para el select).
     *
     * @return array<int, string>
     */
    public static function chile_comunas() {
        return [
            'Algarrobo', 'Alhué', 'Alto Biobío', 'Alto del Carmen', 'Alto Hospicio', 'Ancud', 'Andacollo', 'Angol',
            'Antártica', 'Antofagasta', 'Antuco', 'Arauco', 'Arica', 'Aysén', 'Buin', 'Bulnes', 'Cabildo',
            'Cabo de Hornos', 'Cabrero', 'Calama', 'Calbuco', 'Caldera', 'Calera de Tango', 'Calle Larga', 'Camarones',
            'Camiña', 'Canela', 'Cañete', 'Carahue', 'Cartagena', 'Casablanca', 'Castro', 'Catemu', 'Cauquenes',
            'Cerrillos', 'Cerro Navia', 'Chaitén', 'Chanco', 'Chañaral', 'Chépica', 'Chiguayante', 'Chile Chico',
            'Chillán', 'Chillán Viejo', 'Chimbarongo', 'Cholchol', 'Chonchi', 'Cisnes', 'Cobquecura', 'Cochamó',
            'Cochrane', 'Codegua', 'Coelemu', 'Coihueco', 'Coinco', 'Colbún', 'Colchane', 'Colina', 'Collipulli',
            'Coltauco', 'Combarbalá', 'Concepción', 'Conchalí', 'Concón', 'Constitución', 'Contulmo', 'Copiapó',
            'Coquimbo', 'Coronel', 'Corral', 'Coyhaique', 'Cunco', 'Curacautín', 'Curacaví', 'Curaco de Vélez',
            'Curanilahue', 'Curarrehue', 'Curepto', 'Curicó', 'Dalcahue', 'Diego de Almagro', 'Doñihue', 'El Bosque',
            'El Carmen', 'El Monte', 'El Quisco', 'El Tabo', 'Empedrado', 'Ercilla', 'Estación Central', 'Florida',
            'Freire', 'Freirina', 'Fresia', 'Frutillar', 'Futaleufú', 'Futrono', 'Galvarino', 'General Lagos',
            'Gorbea', 'Graneros', 'Guaitecas', 'Hijuelas', 'Hualaihué', 'Hualañé', 'Hualpén', 'Hualqui', 'Huara',
            'Huasco', 'Huechuraba', 'Illapel', 'Independencia', 'Iquique', 'Isla de Maipo', 'Isla de Pascua',
            'Juan Fernández', 'La Calera', 'La Cisterna', 'La Cruz', 'La Estrella', 'La Florida', 'La Granja',
            'La Higuera', 'La Ligua', 'La Pintana', 'La Reina', 'La Serena', 'La Unión', 'Lago Ranco', 'Lago Verde',
            'Laguna Blanca', 'Laja', 'Lampa', 'Lanco', 'Las Cabras', 'Las Condes', 'Lautaro', 'Lebu', 'Licantén',
            'Limache', 'Linares', 'Litueche', 'Llanquihue', 'Llay-Llay', 'Lo Barnechea', 'Lo Espejo', 'Lo Prado',
            'Lolol', 'Loncoche', 'Longaví', 'Lonquimay', 'Los Álamos', 'Los Andes', 'Los Ángeles', 'Los Lagos',
            'Los Muermos', 'Los Sauces', 'Los Vilos', 'Lota', 'Lumaco', 'Machalí', 'Macul', 'Máfil', 'Maipú',
            'Malloa', 'Marchihue', 'María Elena', 'María Pinto', 'Mariquina', 'Maule', 'Maullín', 'Mejillones',
            'Melipeuco', 'Melipilla', 'Molina', 'Monte Patria', 'Mostazal', 'Mulchén', 'Nacimiento', 'Nancagua',
            'Natales', 'Navidad', 'Negrete', 'Ninhue', 'Nogales', 'Nueva Imperial', 'Ñiquén', 'Ñuñoa', 'O\'Higgins',
            'Olivar', 'Ollagüe', 'Olmué', 'Osorno', 'Ovalle', 'Padre Hurtado', 'Padre Las Casas', 'Paihuano',
            'Paillaco', 'Paine', 'Palena', 'Palmilla', 'Panguipulli', 'Panquehue', 'Papudo', 'Paredones', 'Parral',
            'Pedro Aguirre Cerda', 'Pelarco', 'Pelluhue', 'Pemuco', 'Pencahue', 'Penco', 'Peñaflor', 'Peñalolén',
            'Peralillo', 'Perquenco', 'Petorca', 'Peumo', 'Pica', 'Pichidegua', 'Pichilemu', 'Pinto', 'Pirque',
            'Pitrufquén', 'Placilla', 'Portezuelo', 'Porvenir', 'Pozo Almonte', 'Primavera', 'Providencia',
            'Puchuncaví', 'Pucón', 'Pudahuel', 'Puente Alto', 'Puerto Montt', 'Puerto Octay', 'Puerto Varas',
            'Pumanque', 'Punitaqui', 'Punta Arenas', 'Puqueldón', 'Purén', 'Purranque', 'Putaendo', 'Putre',
            'Puyehue', 'Queilén', 'Quellón', 'Quemchi', 'Quilaco', 'Quilicura', 'Quilleco', 'Quillón', 'Quillota',
            'Quilpué', 'Quinchao', 'Quinta de Tilcoco', 'Quinta Normal', 'Quintero', 'Quirihue', 'Rancagua',
            'Ránquil', 'Rauco', 'Recoleta', 'Renaico', 'Renca', 'Rengo', 'Requínoa', 'Retiro', 'Rinconada',
            'Río Bueno', 'Río Claro', 'Río Hurtado', 'Río Ibáñez', 'Río Negro', 'Río Verde', 'Romeral', 'Saavedra',
            'Sagrada Familia', 'Salamanca', 'San Antonio', 'San Bernardo', 'San Carlos', 'San Clemente',
            'San Esteban', 'San Fabián', 'San Felipe', 'San Fernando', 'San Gregorio', 'San Ignacio', 'San Javier',
            'San Joaquín', 'San José de Maipo', 'San Juan de la Costa', 'San Miguel', 'San Nicolás', 'San Pablo',
            'San Pedro', 'San Pedro de Atacama', 'San Pedro de la Paz', 'San Rafael', 'San Ramón', 'San Rosendo',
            'San Vicente', 'Santa Bárbara', 'Santa Cruz', 'Santa Juana', 'Santa María', 'Santiago',
            'Santo Domingo', 'Sierra Gorda', 'Talagante', 'Talca', 'Talcahuano', 'Taltal', 'Temuco', 'Teno',
            'Teodoro Schmidt', 'Tierra Amarilla', 'Tiltil', 'Timaukel', 'Tirúa', 'Tocopilla', 'Toltén', 'Tomé',
            'Torres del Paine', 'Tortel', 'Traiguén', 'Treguaco', 'Tucapel', 'Valdivia', 'Vallenar', 'Valparaíso',
            'Vichuquén', 'Victoria', 'Vicuña', 'Vilcún', 'Villa Alegre', 'Villa Alemana', 'Villarrica', 'Viña del Mar',
            'Vitacura', 'Yerbas Buenas', 'Yumbel', 'Yungay', 'Zapallar',
        ];
    }
}
