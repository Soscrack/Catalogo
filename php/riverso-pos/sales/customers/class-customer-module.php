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

    /** @var Riverso_Cliente_Contact_Repository */
    private $contacts;

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
        $contact_file = dirname(__FILE__) . '/class-cliente-contact-repository.php';
        if (file_exists($contact_file)) {
            require_once $contact_file;
        }
        $this->repo = new Riverso_Customer_Repository();
        $this->contacts = new Riverso_Cliente_Contact_Repository();
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
        $contact_file = dirname(__FILE__) . '/class-cliente-contact-repository.php';
        if (file_exists($contact_file)) {
            require_once $contact_file;
        }
        if (class_exists('Riverso_Cliente_Contact_Repository')) {
            Riverso_Cliente_Contact_Repository::ensure_schema();
        }
    }

    private function ensure_schema() {
        self::create_tables();
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_customers_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_customers_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_customers_save', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_customer_contacts_search', [$this, 'ajax_contacts_search']);
        add_action('wp_ajax_riverso_customer_contacts_get', [$this, 'ajax_contacts_get']);
        add_action('wp_ajax_riverso_customer_contacts_save', [$this, 'ajax_contacts_save']);
        add_action('wp_ajax_riverso_customer_contacts_delete', [$this, 'ajax_contacts_delete']);
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
                'contactsSearch' => 'riverso_customer_contacts_search',
                'contactsGet' => 'riverso_customer_contacts_get',
                'contactsSave' => 'riverso_customer_contacts_save',
                'contactsDelete' => 'riverso_customer_contacts_delete',
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
        $email = strtolower(trim((string) $input['contacto_email']));
        $skip_contact_sync = false;
        if ($input['id'] > 0 && !empty($input['has_contacto']) && $email !== '' && is_email($email)) {
            $current = $this->repo->get((int) $input['id']);
            $already = $current && strtolower(trim((string) ($current['contacto_email'] ?? ''))) === $email;
            if ($this->contacts->inactive_email((int) $input['id'], $email)) {
                if (!$already) {
                    wp_send_json_error([
                        'message' => $email . ' está inhabilitado. Elimínalo en Contactos (desmarca Activo y pulsa Eliminar) antes de volver a guardarlo.',
                    ]);
                }
                $skip_contact_sync = true;
            }
        }

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

        $message = (string) ($result['message'] ?? 'Guardado');
        $customer = $result['customer'] ?? null;
        if (!$skip_contact_sync && is_array($customer)) {
            $sync = $this->contacts->sync_primary($customer);
            if (!empty($sync['status']) && $sync['status'] === 'blocked' && !empty($sync['message'])) {
                $message .= ' ' . $sync['message'];
            }
        }

        wp_send_json_success([
            'message' => $message,
            'id' => $result['id'],
            'customer' => $customer,
        ]);
    }

    public function ajax_contacts_search() {
        $this->authorize_view();
        $solo = !isset($_POST['solo_activos']) || !in_array((string) wp_unslash($_POST['solo_activos']), ['0', 'false'], true);
        $contacts = $this->contacts->search([
            'cliente_id' => isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0,
            'primer_nombre' => $this->post_string('primer_nombre'),
            'segundo_nombre' => $this->post_string('segundo_nombre'),
            'apellido_paterno' => $this->post_string('apellido_paterno'),
            'apellido_materno' => $this->post_string('apellido_materno'),
            'email' => $this->post_string('email'),
            'solo_activos' => $solo,
        ]);
        wp_send_json_success(['contacts' => $contacts]);
    }

    public function ajax_contacts_get() {
        $this->authorize_view();
        $contact = $this->contacts->get(isset($_POST['id']) ? (int) $_POST['id'] : 0);
        if (!$contact) {
            wp_send_json_error(['message' => 'Contacto no encontrado']);
        }
        wp_send_json_success(['contact' => $contact]);
    }

    public function ajax_contacts_save() {
        $this->authorize_edit();
        $result = $this->contacts->save($this->contact_input_from_request());
        if (empty($result['ok'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? 'No se pudo guardar el contacto',
                'status' => $result['status'] ?? '',
            ]);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'id' => $result['id'],
            'contact' => $result['contact'] ?? null,
        ]);
    }

    public function ajax_contacts_delete() {
        $this->authorize_edit();
        $result = $this->contacts->delete(isset($_POST['id']) ? (int) $_POST['id'] : 0);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo eliminar']);
        }
        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contact_input_from_request() {
        $activo_raw = isset($_POST['activo']) ? (string) wp_unslash($_POST['activo']) : '1';
        return [
            'id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
            'cliente_id' => isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0,
            'primer_nombre' => $this->post_string('primer_nombre'),
            'segundo_nombre' => $this->post_string('segundo_nombre'),
            'apellido_paterno' => $this->post_string('apellido_paterno'),
            'apellido_materno' => $this->post_string('apellido_materno'),
            'email' => $this->post_string('email'),
            'fono_oficina' => $this->post_string('fono_oficina'),
            'fono_movil' => $this->post_string('fono_movil'),
            'observaciones' => isset($_POST['observaciones'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['observaciones']))
                : '',
            'nacimiento_dia' => isset($_POST['nacimiento_dia']) ? (int) $_POST['nacimiento_dia'] : 0,
            'nacimiento_mes' => isset($_POST['nacimiento_mes']) ? (int) $_POST['nacimiento_mes'] : 0,
            'nacimiento_anio' => isset($_POST['nacimiento_anio']) ? (int) $_POST['nacimiento_anio'] : 0,
            'lab_calle' => $this->post_string('lab_calle'),
            'lab_numero' => $this->post_string('lab_numero'),
            'lab_depto' => $this->post_string('lab_depto'),
            'lab_ciudad' => $this->post_string('lab_ciudad'),
            'lab_comuna' => $this->post_string('lab_comuna'),
            'lab_pais' => $this->post_string('lab_pais'),
            'lab_codigo_postal' => $this->post_string('lab_codigo_postal'),
            'part_calle' => $this->post_string('part_calle'),
            'part_numero' => $this->post_string('part_numero'),
            'part_depto' => $this->post_string('part_depto'),
            'part_ciudad' => $this->post_string('part_ciudad'),
            'part_comuna' => $this->post_string('part_comuna'),
            'part_pais' => $this->post_string('part_pais'),
            'part_codigo_postal' => $this->post_string('part_codigo_postal'),
            'activo' => !in_array($activo_raw, ['0', 'false'], true),
            'origen' => 'manual',
        ];
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
