<?php
/**
 * Módulo Tienda Local - búsqueda por códigos de barra.
 *
 * Importa el catálogo local desde CSV y permite buscar productos por código de barra,
 * SKU o nombre sin depender de WooCommerce.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Tienda_Local_Module {

    private static $instance = null;

    private $table_productos;
    private $table_barcodes;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $this->table_productos = $prefix . 'tienda_local_productos';
        $this->table_barcodes = $prefix . 'tienda_local_barcodes';
    }

    public function init() {
        add_action('wp_ajax_riverso_tienda_local_search', [$this, 'ajax_search_local']);
        add_action('wp_ajax_riverso_tienda_local_import', [$this, 'ajax_import_local']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('riverso tienda-local import', [$this, 'cli_import']);
        }
    }

    /**
     * Crea las tablas propias del catálogo local.
     */
    public static function create_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $charset_collate = $wpdb->get_charset_collate();

        $table_productos = $prefix . 'tienda_local_productos';
        $table_barcodes = $prefix . 'tienda_local_barcodes';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_productos = "CREATE TABLE {$table_productos} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(100) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            precio DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            stock INT NOT NULL DEFAULT 0,
            fecha_scraping DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_sku_unique (sku),
            KEY idx_nombre (nombre)
        ) {$charset_collate};";

        $sql_barcodes = "CREATE TABLE {$table_barcodes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(100) NOT NULL,
            barcode VARCHAR(50) NOT NULL,
            barcode_norm VARCHAR(50) NOT NULL,
            fecha DATE DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_barcode_unique (barcode),
            KEY idx_barcode (barcode),
            KEY idx_norm (barcode_norm),
            KEY idx_sku (sku)
        ) {$charset_collate};";

        dbDelta($sql_productos);
        dbDelta($sql_barcodes);
    }

    public function default_products_path() {
        $path = RIVERSO_POS_PLUGIN_DIR . '../../CodigosBarra/productos_2026-04-01.csv';
        $real = realpath($path);
        return $real ?: $path;
    }

    public function default_barcodes_path() {
        $path = RIVERSO_POS_PLUGIN_DIR . '../../CodigosBarra/codigos_barras_2026-04-01.csv';
        $real = realpath($path);
        return $real ?: $path;
    }

    /**
     * Importa productos y códigos de barra desde CSV.
     *
     * @param string $productos_path Ruta del CSV de productos.
     * @param string $barcodes_path Ruta del CSV de códigos.
     * @return array|WP_Error
     */
    public function import_from_csv($productos_path = '', $barcodes_path = '') {
        $productos_path = $productos_path ?: $this->default_products_path();
        $barcodes_path = $barcodes_path ?: $this->default_barcodes_path();

        if (!file_exists($productos_path)) {
            return new WP_Error('productos_not_found', 'CSV de productos no encontrado: ' . $productos_path);
        }
        if (!file_exists($barcodes_path)) {
            return new WP_Error('barcodes_not_found', 'CSV de códigos de barra no encontrado: ' . $barcodes_path);
        }

        $product_result = $this->import_products_csv($productos_path);
        if (is_wp_error($product_result)) {
            return $product_result;
        }

        $barcode_result = $this->import_barcodes_csv($barcodes_path);
        if (is_wp_error($barcode_result)) {
            return $barcode_result;
        }

        $stats = $this->get_stats();

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('tienda_local_imported', 'tienda_local', 0, [
                'new_value' => [
                    'productos' => $product_result,
                    'barcodes' => $barcode_result,
                ],
                'details' => 'Catálogo local importado desde CSV',
            ]);
        }

        return [
            'productos' => $product_result,
            'barcodes' => $barcode_result,
            'stats' => $stats,
        ];
    }

    private function import_products_csv($path) {
        global $wpdb;

        $handle = fopen($path, 'r');
        if (!$handle) {
            return new WP_Error('productos_read_error', 'No se pudo leer el CSV de productos');
        }

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $row_num++;
            if ($row_num === 1) {
                continue;
            }

            $sku = trim($row[0] ?? '');
            $nombre = trim($row[1] ?? '');
            if ($sku === '' || $nombre === '') {
                $skipped++;
                continue;
            }

            $result = $wpdb->replace(
                $this->table_productos,
                [
                    'sku' => $sku,
                    'nombre' => $nombre,
                    'precio' => $this->normalize_price($row[2] ?? ''),
                    'stock' => $this->normalize_stock($row[3] ?? ''),
                    'fecha_scraping' => $this->normalize_datetime($row[4] ?? ''),
                ],
                ['%s', '%s', '%f', '%d', '%s']
            );

            if ($result === false) {
                $errors[] = ['line' => $row_num, 'sku' => $sku];
                continue;
            }
            $imported++;
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function import_barcodes_csv($path) {
        global $wpdb;

        $handle = fopen($path, 'r');
        if (!$handle) {
            return new WP_Error('barcodes_read_error', 'No se pudo leer el CSV de códigos de barra');
        }

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $row_num++;
            if ($row_num === 1) {
                continue;
            }

            $sku = trim($row[0] ?? '');
            $barcode = trim($row[1] ?? '');
            if ($sku === '' || $barcode === '') {
                $skipped++;
                continue;
            }

            $result = $wpdb->replace(
                $this->table_barcodes,
                [
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'barcode_norm' => $this->normalize_barcode($barcode),
                    'fecha' => $this->normalize_date($row[2] ?? ''),
                ],
                ['%s', '%s', '%s', '%s']
            );

            if ($result === false) {
                $errors[] = ['line' => $row_num, 'sku' => $sku, 'barcode' => $barcode];
                continue;
            }
            $imported++;
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Busca por código de barra, SKU o nombre.
     *
     * @param string $query
     * @return array
     */
    public function search($query) {
        global $wpdb;

        $query = trim((string) $query);
        if ($query === '') {
            return [
                'type' => 'empty',
                'items' => [],
            ];
        }

        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, p.nombre, p.precio, p.stock, p.fecha_scraping
             FROM {$this->table_barcodes} b
             INNER JOIN {$this->table_productos} p ON p.sku = b.sku
             WHERE b.barcode = %s
             LIMIT 1",
            $query
        ), ARRAY_A);

        if (!$barcode) {
            $normalized = $this->normalize_barcode($query);
            $barcode = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, p.nombre, p.precio, p.stock, p.fecha_scraping
                 FROM {$this->table_barcodes} b
                 INNER JOIN {$this->table_productos} p ON p.sku = b.sku
                 WHERE b.barcode_norm = %s
                 LIMIT 1",
                $normalized
            ), ARRAY_A);
        }

        if ($barcode) {
            return [
                'type' => 'barcode',
                'items' => [$this->format_product($barcode['sku'], $barcode['barcode'])],
            ];
        }

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_productos} WHERE sku = %s LIMIT 1",
            $query
        ), ARRAY_A);

        if ($product) {
            return [
                'type' => 'sku',
                'items' => [$this->format_product($product['sku'])],
            ];
        }

        $like = '%' . $wpdb->esc_like($query) . '%';
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_productos}
             WHERE nombre LIKE %s
             ORDER BY nombre ASC
             LIMIT 25",
            $like
        ), ARRAY_A);

        return [
            'type' => 'name',
            'items' => array_map(function ($item) {
                return $this->format_product($item['sku']);
            }, $products),
        ];
    }

    private function format_product($sku, $matched_barcode = '') {
        global $wpdb;

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_productos} WHERE sku = %s LIMIT 1",
            $sku
        ), ARRAY_A);

        if (!$product) {
            return null;
        }

        $barcodes = $wpdb->get_results($wpdb->prepare(
            "SELECT barcode, fecha
             FROM {$this->table_barcodes}
             WHERE sku = %s
             ORDER BY barcode ASC",
            $sku
        ), ARRAY_A);

        return [
            'sku' => $product['sku'],
            'nombre' => $product['nombre'],
            'precio' => (float) $product['precio'],
            'precio_formateado' => $this->format_clp($product['precio']),
            'stock' => (int) $product['stock'],
            'fecha_scraping' => $product['fecha_scraping'],
            'matched_barcode' => $matched_barcode,
            'barcodes' => $barcodes,
        ];
    }

    public function get_stats() {
        global $wpdb;

        return [
            'productos' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_productos}"),
            'barcodes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_barcodes}"),
            'productos_con_barcode' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT sku) FROM {$this->table_barcodes}"
            ),
        ];
    }

    public function ajax_search_local() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_scan_barcodes') && !current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $query = sanitize_text_field($_POST['query'] ?? '');
        $result = $this->search($query);

        if (empty($result['items'])) {
            wp_send_json_error([
                'message' => 'Producto local no encontrado',
                'query' => $query,
                'stats' => $this->get_stats(),
            ]);
        }

        wp_send_json_success($result + ['stats' => $this->get_stats()]);
    }

    public function ajax_import_local() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $productos_path = $this->default_products_path();
        $barcodes_path = $this->default_barcodes_path();

        if (!empty($_FILES['productos_csv']['tmp_name']) && is_uploaded_file($_FILES['productos_csv']['tmp_name'])) {
            $productos_path = $_FILES['productos_csv']['tmp_name'];
        }
        if (!empty($_FILES['barcodes_csv']['tmp_name']) && is_uploaded_file($_FILES['barcodes_csv']['tmp_name'])) {
            $barcodes_path = $_FILES['barcodes_csv']['tmp_name'];
        }

        $result = $this->import_from_csv($productos_path, $barcodes_path);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * WP-CLI: wp riverso tienda-local import [--productos=...] [--barcodes=...]
     */
    public function cli_import($args, $assoc_args) {
        $productos_path = $assoc_args['productos'] ?? $this->default_products_path();
        $barcodes_path = $assoc_args['barcodes'] ?? $this->default_barcodes_path();

        $result = $this->import_from_csv($productos_path, $barcodes_path);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(sprintf(
            'Importación tienda local: %d productos, %d códigos. Total actual: %d productos / %d códigos.',
            $result['productos']['imported'],
            $result['barcodes']['imported'],
            $result['stats']['productos'],
            $result['stats']['barcodes']
        ));
    }

    private function normalize_price($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return 0.0;
        }
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    private function normalize_stock($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return 0;
        }
        return (int) $value;
    }

    private function normalize_barcode($barcode) {
        $normalized = ltrim(trim((string) $barcode), '0');
        return $normalized === '' ? '0' : $normalized;
    }

    private function normalize_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('d-m-Y', $value);
        return $date ? $date->format('Y-m-d') : null;
    }

    private function normalize_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    private function format_clp($value) {
        return '$' . number_format((float) $value, 0, ',', '.');
    }
}
