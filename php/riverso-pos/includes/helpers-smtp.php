<?php
/**
 * Envío de correos por SMTP autenticado (Zoho).
 *
 * Credenciales en un .env fuera del webroot (por defecto <vhost>/.env.riverso),
 * o como constantes RIVERSO_SMTP_* en wp-config.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

function riverso_smtp_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $env = [];
    $file = defined('RIVERSO_ENV_FILE') ? RIVERSO_ENV_FILE : dirname(rtrim(ABSPATH, '/\\')) . '/.env.riverso';
    if (@is_readable($file)) {
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($value), "\"'");
        }
    }
    $get = static function ($key, $default = '') use ($env) {
        if (defined($key)) {
            return (string) constant($key);
        }
        return isset($env[$key]) ? $env[$key] : $default;
    };
    $config = [
        'host'      => $get('RIVERSO_SMTP_HOST', 'smtp.zoho.com'),
        'port'      => (int) $get('RIVERSO_SMTP_PORT', '465'),
        'secure'    => $get('RIVERSO_SMTP_SECURE', 'ssl'),
        'user'      => $get('RIVERSO_SMTP_USER'),
        'pass'      => $get('RIVERSO_SMTP_PASS'),
        'from'      => $get('RIVERSO_SMTP_FROM'),
        'from_name' => $get('RIVERSO_SMTP_FROM_NAME', 'Riverso'),
    ];
    if ($config['from'] === '') {
        $config['from'] = $config['user'];
    }
    return $config;
}

add_action('phpmailer_init', static function ($phpmailer) {
    $c = riverso_smtp_config();
    if ($c['user'] === '' || $c['pass'] === '') {
        return;
    }
    $phpmailer->isSMTP();
    $phpmailer->Host       = $c['host'];
    $phpmailer->Port       = $c['port'];
    $phpmailer->SMTPSecure = $c['secure'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $c['user'];
    $phpmailer->Password   = $c['pass'];
    // Zoho rechaza remitentes distintos de la cuenta autenticada.
    $phpmailer->setFrom($c['from'], $c['from_name'], false);
    $phpmailer->Sender = $c['from'];
});
