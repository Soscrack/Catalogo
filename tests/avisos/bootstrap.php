<?php
/**
 * Entorno mínimo para ejecutar el servicio de avisos contra un MySQL local de prueba.
 * No es WordPress: solo los símbolos que usan el servicio, el modelo de barras y la migración.
 *
 * Necesita un MySQL desechable en 127.0.0.1:33077 con root sin clave, por ejemplo:
 *   mysqld --no-defaults --initialize-insecure --datadir=<carpeta vacía>
 *   mysqld --no-defaults --datadir=<carpeta> --port=33077 --bind-address=127.0.0.1 --mysqlx=OFF
 * Borra y recrea la base riverso_test en cada corrida.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$repo = defined('HARNESS_REPO') ? HARNESS_REPO : ($argv[1] ?? dirname(__DIR__, 2));
define('HARNESS_TMP', sys_get_temp_dir() . '/riverso-avisos-test');
define('ABSPATH', HARNESS_TMP . '/fakewp/');
define('RIVERSO_POS_PLUGIN_DIR', rtrim(str_replace('\\', '/', $repo), '/') . '/php/riverso-pos/');
define('RIVERSO_POS_PLUGIN_URL', defined('HARNESS_KEEP_DB') ? '/' : 'https://example.test/wp-content/plugins/riverso-pos/');
define('RIVERSO_POS_VERSION', 'test');
define('DAY_IN_SECONDS', 86400);
define('DB_NAME', 'riverso_test');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['TEST_USER'] = 1;
$GLOBALS['TEST_CAPS'] = [];

function get_current_user_id() { return $GLOBALS['TEST_USER']; }
function current_user_can($cap) { return !empty($GLOBALS['TEST_CAPS'][$cap]); }
function current_time($type) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s', time() - 3 * 3600) : time() - 3 * 3600; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_unslash($v) { return $v; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function wp_upload_dir() { return ['basedir' => HARNESS_TMP . '/uploads', 'baseurl' => defined('HARNESS_KEEP_DB') ? '/uploads' : 'https://example.test/wp-content/uploads']; }
function human_time_diff($from, $to) {
    $d = max(0, $to - $from);
    if ($d < 3600) return max(1, (int) round($d / 60)) . ' min';
    if ($d < 86400) return (int) round($d / 3600) . ' horas';
    return (int) round($d / 86400) . ' días';
}
function dbDelta($sql) {
    global $wpdb;
    $wpdb->query(preg_replace('/^\s*CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql));
}

class Test_WPDB {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public $insert_id = 0;
    public $last_error = '';
    public $queries = 0;
    /** @var mysqli */
    private $db;

    public function __construct(mysqli $db) { $this->db = $db; }
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $query = str_replace(["'%s'", '"%s"'], '%s', $query);
        $query = preg_replace('/(?<!%)%s/', "'%s'", $query);
        $args = array_map(function ($a) {
            return is_string($a) ? $this->db->real_escape_string($a) : $a;
        }, $args);
        return vsprintf($query, $args);
    }

    public function query($sql) {
        $this->queries++;
        $res = $this->db->query($sql);
        if ($res === true) {
            $this->insert_id = $this->db->insert_id;
            return $this->db->affected_rows;
        }
        return $res;
    }

    public function get_results($sql, $output = OBJECT) {
        $res = $this->query($sql);
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $output === ARRAY_A ? $row : (object) $row;
        }
        return $rows;
    }

    public function get_row($sql, $output = OBJECT) {
        $rows = $this->get_results($sql, $output);
        return $rows ? $rows[0] : null;
    }

    public function get_var($sql) {
        $res = $this->query($sql);
        $row = $res->fetch_row();
        return $row ? $row[0] : null;
    }

    public function get_col($sql) {
        $res = $this->query($sql);
        $out = [];
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        return $out;
    }

    private function lit($v) {
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return (string) $v;
        return "'" . $this->db->real_escape_string((string) $v) . "'";
    }

    public function insert($table, $data) {
        $cols = implode(',', array_map(function ($c) { return "`$c`"; }, array_keys($data)));
        $vals = implode(',', array_map([$this, 'lit'], array_values($data)));
        return $this->query("INSERT INTO `$table` ($cols) VALUES ($vals)");
    }

    public function update($table, $data, $where) {
        $set = [];
        foreach ($data as $k => $v) $set[] = "`$k` = " . $this->lit($v);
        $cond = [];
        foreach ($where as $k => $v) $cond[] = "`$k` = " . $this->lit($v);
        return $this->query("UPDATE `$table` SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond));
    }
}

@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n");

$root = new mysqli('127.0.0.1', 'root', '', '', 33077);
// El servidor de la prueba de navegador reutiliza la base que dejó run.php.
if (!defined('HARNESS_KEEP_DB')) {
    $root->query('DROP DATABASE IF EXISTS riverso_test');
    $root->query('CREATE DATABASE riverso_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$root->select_db('riverso_test');
$root->set_charset('utf8mb4');
// Modo estricto: una columna que no acepta el valor falla en vez de truncar en silencio.
$root->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
$GLOBALS['wpdb'] = new Test_WPDB($root);
