<?php
/**
 * Publicador controlado Riverso POS -> WooCommerce.
 *
 * Crea productos variables desde MAMUT en estado privado/borrador y solo publica
 * cuando las aprobaciones humanas requeridas están completas.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Woo_Publisher_Module {

    private static $instance = null;

    const STAGES = [
        'computer_created',
        'pending_review',
        'human_verified',
        'price_verified',
        'approved_for_publication',
        'published',
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function create_tables() {
        return true;
    }

    public function init() {
        add_action('wp_ajax_riverso_publish_mamut_group', [$this, 'ajax_publish_mamut_group']);
        add_action('wp_ajax_riverso_publish_authorize', [$this, 'ajax_authorize']);
        add_action('wp_ajax_riverso_publish_product', [$this, 'ajax_publish_product']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('riverso-publish mamut-group', [$this, 'cli_publish_mamut_group']);
        }
    }

    public function load_mamut_entries($path = '') {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/import/class-mamut-import-module.php';
        return Riverso_Mamut_Import_Module::get_instance()->load_entries($path);
    }

    public function group_entries($entries) {
        $groups = [];
        foreach ($entries as $entry) {
            $attrs = $this->attributes_to_map($entry['attributes'] ?? []);
            $acabado = $attrs['acabado'] ?? 'Sin acabado';
            $product_name = $entry['producto'] ?? ($entry['nombre_producto'] ?? 'Producto');
            $key = sanitize_title($product_name . '-' . $acabado);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'name' => trim($product_name . ($acabado !== 'Sin acabado' ? ' - ' . $acabado : '')),
                    'category_path' => $entry['category_path'] ?? [],
                    'acabado' => $acabado,
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $entry;
        }
        return $groups;
    }

    public function create_mamut_group($group_key, $path = '', $status = 'private') {
        $entries = $this->load_mamut_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $groups = $this->group_entries($entries);
        if (empty($groups[$group_key])) {
            return new WP_Error('group_not_found', 'Grupo MAMUT no encontrado');
        }
        return $this->create_variable_product($groups[$group_key], $status);
    }

    public function create_variable_product($group, $status = 'private') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $status = in_array($status, ['draft', 'private'], true) ? $status : 'private';
        $attributes = $this->build_attributes($group['items']);

        $product = new WC_Product_Variable();
        $product->set_name($group['name']);
        $product->set_status($status);
        $product->set_description('Producto creado por Riverso POS desde catálogo MAMUT. Requiere revisión humana antes de publicación.');
        $product->set_attributes($attributes['wc_attributes']);
        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('save_failed', 'No se pudo crear producto variable WooCommerce');
        }

        $created_variations = 0;
        foreach ($group['items'] as $entry) {
            $sku = trim((string) ($entry['sku'] ?? ''));
            if ($sku === '' || wc_get_product_id_by_sku($sku)) {
                continue;
            }

            $attr_map = $this->attributes_to_map($entry['attributes'] ?? []);
            $variation_attrs = $this->variation_attributes($attr_map);
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_status('publish');
            $variation->set_sku($sku);
            $variation->set_attributes($variation_attrs);
            $variation_id = $variation->save();
            if ($variation_id) {
                $created_variations++;
                $wpdb->update(
                    "{$prefix}producto_base",
                    [
                        'woocommerce_product_id' => $product_id,
                        'woocommerce_variation_id' => $variation_id,
                        'publication_stage' => 'pending_review',
                        'requires_human_review' => 1,
                        'human_attribute_review' => 'pending',
                    ],
                    ['canonical_sku' => $sku],
                    ['%d', '%d', '%s', '%d', '%s'],
                    ['%s']
                );
            }
        }

        if (!empty($attributes['default_attributes'])) {
            update_post_meta($product_id, '_default_attributes', $attributes['default_attributes']);
        }
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);

        if (function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'confirmar_estructura_atributos',
                'Confirmar estructura de atributos para ' . $group['name'],
                'product',
                $product_id,
                ['prioridad' => 'alta']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_import('product_created', 'product', $product_id, [
                'new_value' => [
                    'group_key' => $group['key'],
                    'created_variations' => $created_variations,
                    'status' => $status,
                ],
                'details' => 'Producto variable creado desde MAMUT en estado no publicado',
            ]);
        }

        return [
            'product_id' => $product_id,
            'created_variations' => $created_variations,
            'group' => $group['key'],
        ];
    }

    public function can_publish_base($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            absint($producto_base_id)
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }
        foreach (['human_product_review', 'human_price_review', 'human_category_review', 'human_attribute_review'] as $gate) {
            if (($row[$gate] ?? 'pending') !== 'approved') {
                return new WP_Error('gate_pending', 'Falta aprobación: ' . $gate);
            }
        }
        if (empty($row['woocommerce_product_id'])) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no vinculado');
        }
        return $row;
    }

    public function authorize_publication($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $check = $this->can_publish_base($producto_base_id);
        if (is_wp_error($check)) {
            if (function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'autorizar_publicacion',
                    'Autorizar publicación pendiente para producto base #' . absint($producto_base_id),
                    'producto_base',
                    absint($producto_base_id),
                    ['prioridad' => 'alta']
                );
            }
            return $check;
        }
        $wpdb->update(
            "{$prefix}producto_base",
            ['publication_stage' => 'approved_for_publication', 'updated_at' => current_time('mysql')],
            ['id' => absint($producto_base_id)],
            ['%s', '%s'],
            ['%d']
        );
        return true;
    }

    public function publish_product($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $this->can_publish_base($producto_base_id);
        if (is_wp_error($row)) {
            return $row;
        }
        $product = wc_get_product((int) $row['woocommerce_product_id']);
        if (!$product) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no encontrado');
        }
        $old_status = $product->get_status();
        $product->set_status('publish');
        $product->save();

        $wpdb->update(
            "{$prefix}producto_base",
            ['publication_stage' => 'published', 'updated_at' => current_time('mysql')],
            ['id' => absint($producto_base_id)],
            ['%s', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_published', 'producto_base', absint($producto_base_id), [
                'actor_type' => 'human',
                'old_value' => ['status' => $old_status],
                'new_value' => ['status' => 'publish', 'woocommerce_product_id' => $product->get_id()],
            ]);
        }

        return true;
    }

    private function build_attributes($items) {
        $nominal = [];
        $largo = [];
        $combined = [];
        $envase = [];
        $acabado = [];
        $first_variation = [];

        foreach ($items as $entry) {
            $map = $this->attributes_to_map($entry['attributes'] ?? []);
            if (!empty($map['nominal'])) {
                $nominal[] = $map['nominal'];
            }
            if (!empty($map['largo'])) {
                $largo[] = $map['largo'];
            }
            $combo = $this->combined_nominal_largo($map);
            if ($combo !== '') {
                $combined[] = $combo;
                if (!$first_variation) {
                    $first_variation['nominal-x-largo'] = $combo;
                }
            }
            if (!empty($map['envase'])) {
                $envase[] = $map['envase'];
                $first_variation['envase'] = $first_variation['envase'] ?? $map['envase'];
            }
            if (!empty($map['acabado'])) {
                $acabado[] = $map['acabado'];
                $first_variation['acabado'] = $first_variation['acabado'] ?? $map['acabado'];
            }
        }

        $attributes = [];
        $attributes[] = $this->wc_attribute('Nominal', array_unique($nominal), true, false);
        $attributes[] = $this->wc_attribute('Largo', array_unique($largo), true, false);
        $attributes[] = $this->wc_attribute('Nominal X Largo', array_unique($combined), false, true);
        if ($envase) {
            $attributes[] = $this->wc_attribute('Envase', array_unique($envase), true, true);
        }
        if ($acabado) {
            $attributes[] = $this->wc_attribute('Acabado', array_unique($acabado), true, true);
        }

        return [
            'wc_attributes' => array_filter($attributes),
            'default_attributes' => $first_variation,
        ];
    }

    private function wc_attribute($name, $options, $visible, $variation) {
        $options = array_values(array_filter(array_unique($options)));
        if (!$options) {
            return null;
        }
        $attr = new WC_Product_Attribute();
        $attr->set_id(0);
        $attr->set_name($name);
        $attr->set_options($options);
        $attr->set_visible($visible);
        $attr->set_variation($variation);
        return $attr;
    }

    private function variation_attributes($map) {
        $attrs = [];
        $combo = $this->combined_nominal_largo($map);
        if ($combo !== '') {
            $attrs['nominal-x-largo'] = $combo;
        }
        if (!empty($map['envase'])) {
            $attrs['envase'] = $map['envase'];
        }
        if (!empty($map['acabado'])) {
            $attrs['acabado'] = $map['acabado'];
        }
        return $attrs;
    }

    private function combined_nominal_largo($map) {
        if (empty($map['nominal']) && empty($map['largo'])) {
            return '';
        }
        return trim(($map['nominal'] ?? '') . ' x ' . ($map['largo'] ?? ''));
    }

    private function attributes_to_map($attributes) {
        $out = [];
        foreach ($attributes as $attr) {
            $key = strtolower(trim($attr['name'] ?? ''));
            $key = $key === 'acabado' ? 'acabado' : $key;
            if ($key !== '') {
                $out[$key] = trim((string) ($attr['value'] ?? ''));
            }
        }
        return $out;
    }

    public function ajax_publish_mamut_group() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->create_mamut_group(
            sanitize_text_field($_POST['group_key'] ?? ''),
            sanitize_text_field($_POST['path'] ?? ''),
            sanitize_text_field($_POST['status'] ?? 'private')
        );
        $this->send_result($result, 'Producto WooCommerce creado');
    }

    public function ajax_authorize() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->authorize_publication(absint($_POST['producto_base_id'] ?? 0));
        $this->send_result($result, 'Producto autorizado para publicación');
    }

    public function ajax_publish_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->publish_product(absint($_POST['producto_base_id'] ?? 0));
        $this->send_result($result, 'Producto publicado');
    }

    public function cli_publish_mamut_group($args, $assoc) {
        $group = $args[0] ?? ($assoc['group'] ?? '');
        $path = $assoc['path'] ?? '';
        $status = $assoc['status'] ?? 'private';
        $result = $this->create_mamut_group($group, $path, $status);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Producto creado: #' . $result['product_id'] . ' variaciones=' . $result['created_variations']);
    }

    private function send_result($result, $message) {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => $message, 'result' => $result]);
    }
}
