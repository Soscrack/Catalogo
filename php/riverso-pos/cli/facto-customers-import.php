#!/usr/bin/env php
<?php
/**
 * Importa clientes y contactos de FACTO hacia Riverso.
 *
 * Solo clientes (is_customer). Los proveedores se omiten.
 * Idempotente: reencuentra por id FACTO guardado en datos extra y, si no, por RUT.
 * Rellena campos vacíos. No pisa un dato que Riverso ya tenga.
 *
 *   php facto-customers-import.php
 *   php facto-customers-import.php --limit=5
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$wp_root = getenv('RIVERSO_WP_PATH');
if (!is_string($wp_root) || $wp_root === '') {
    $wp_root = '/var/www/vhosts/riverso.cl/httpdocs';
}

$_SERVER['HTTP_HOST'] = 'riverso.cl';
$_SERVER['SERVER_NAME'] = 'riverso.cl';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTPS'] = 'on';

define('WP_USE_THEMES', false);
require_once $wp_root . '/wp-load.php';

if (!defined('RIVERSO_POS_PLUGIN_DIR')) {
    fwrite(STDERR, "Plugin riverso-pos no cargado\n");
    exit(1);
}

$files = [
    'includes/helpers-facto.php',
    'modules/integrations/facto/class-facto-client.php',
    'sales/customers/class-customer-repository.php',
    'sales/customers/class-cliente-contact-repository.php',
    'sales/customers/class-customer-module.php',
];
foreach ($files as $rel) {
    $path = RIVERSO_POS_PLUGIN_DIR . $rel;
    if (is_file($path)) {
        require_once $path;
    }
}

if (!class_exists('Riverso_Facto_Client') || !class_exists('Riverso_Customer_Repository')) {
    fwrite(STDERR, "Clases FACTO o clientes no disponibles\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

$client = new Riverso_Facto_Client();
if (!$client->is_configured()) {
    fwrite(STDERR, "Credenciales FACTO incompletas\n");
    exit(1);
}

$customers = new Riverso_Customer_Repository();
$contacts = new Riverso_Cliente_Contact_Repository();

/**
 * @param Riverso_Facto_Client $client
 * @param string               $path
 * @param array<string, mixed> $query
 * @return array<string, mixed>|WP_Error
 */
function riverso_facto_get($client, $path, $query = []) {
    $last = null;
    for ($try = 1; $try <= 4; $try++) {
        $resp = $client->request('GET', $path, null, $query);
        if (!is_wp_error($resp)) {
            return $resp;
        }
        $last = $resp;
        $code = $resp->get_error_code();
        $retry = in_array($code, ['facto_rate_limited', 'facto_timeout', 'http_request_failed'], true);
        if (!$retry) {
            return $resp;
        }
        sleep($code === 'facto_rate_limited' ? 30 : 3);
    }
    return $last;
}

/**
 * @param string $value
 * @return string
 */
function riverso_fold($value) {
    $value = trim((string) $value);
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $value = strtr($value, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    ]);
    $folded = preg_replace('/[^a-z0-9]/', '', $value);
    return is_string($folded) ? $folded : '';
}

/**
 * @return array<string, string>
 */
function riverso_comuna_index() {
    $index = [];
    if (!class_exists('Riverso_Customer_Module')) {
        return $index;
    }
    foreach (Riverso_Customer_Module::chile_comunas() as $comuna) {
        $key = riverso_fold($comuna);
        if ($key !== '' && !isset($index[$key])) {
            $index[$key] = $comuna;
        }
    }
    return $index;
}

/**
 * @param string $rut
 * @return string
 */
function riverso_rut_key($rut) {
    $clean = preg_replace('/[^0-9kK]/', '', (string) $rut);
    return is_string($clean) ? strtoupper($clean) : '';
}

/**
 * @param string $rut
 * @return string
 */
function riverso_rut_body($rut) {
    $key = riverso_rut_key($rut);
    if (strlen($key) < 2) {
        return '';
    }
    return substr($key, 0, -1) . '-' . substr($key, -1);
}

/**
 * @param mixed $extra
 * @return array<int, array{nombre: string, valor: string}>
 */
function riverso_extra_list($extra) {
    if (is_string($extra)) {
        $decoded = json_decode($extra, true);
        $extra = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($extra)) {
        return [];
    }
    $out = [];
    foreach ($extra as $item) {
        if (!is_array($item)) {
            continue;
        }
        $out[] = [
            'nombre' => (string) ($item['nombre'] ?? ''),
            'valor' => (string) ($item['valor'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param array<int, array{nombre: string, valor: string}> $extra
 * @param int                                              $facto_id
 * @return array<int, array{nombre: string, valor: string}>
 */
function riverso_with_facto_id($extra, $facto_id) {
    $kept = [];
    foreach ($extra as $item) {
        if (($item['nombre'] ?? '') === 'FACTO ID') {
            continue;
        }
        $kept[] = $item;
    }
    array_unshift($kept, ['nombre' => 'FACTO ID', 'valor' => (string) $facto_id]);
    return array_slice($kept, 0, 4);
}

/**
 * @param array<string, mixed>|null $row
 * @param string                    $field
 * @return bool
 */
function riverso_blank($row, $field) {
    if (!$row) {
        return true;
    }
    $value = isset($row[$field]) ? trim((string) $row[$field]) : '';
    return $value === '';
}

$comunas = riverso_comuna_index();

global $wpdb;
$table = $customers->table_name();
$by_facto = [];
$by_rut = [];
$existing_rows = $wpdb->get_results("SELECT id, rut, datos_extra FROM {$table}", ARRAY_A);
if (is_array($existing_rows)) {
    foreach ($existing_rows as $row) {
        $id = (int) $row['id'];
        $key = riverso_rut_key((string) $row['rut']);
        if ($key !== '' && !isset($by_rut[$key])) {
            $by_rut[$key] = $id;
        }
        if (preg_match('/"nombre":"FACTO ID","valor":"(\d+)"/', (string) $row['datos_extra'], $m)) {
            $by_facto[(int) $m[1]] = $id;
        }
    }
}

$ids = [];
$page = 1;
$page_count = 1;
do {
    $resp = riverso_facto_get($client, 'customers_suppliers', ['page' => $page]);
    if (is_wp_error($resp)) {
        fwrite(STDERR, 'Listado página ' . $page . ': ' . $resp->get_error_message() . "\n");
        exit(1);
    }
    $page_count = isset($resp['page_count']) ? (int) $resp['page_count'] : $page;
    $rows = Riverso_Facto_Client::embed_collection($resp, 'customers_suppliers');
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['is_customer']) || empty($row['customer_supplier_id'])) {
            continue;
        }
        $ids[] = (int) $row['customer_supplier_id'];
    }
    fwrite(STDERR, "Listado {$page}/{$page_count}\n");
    $page++;
} while ($page <= $page_count && $page <= 80);

$ids = array_values(array_unique($ids));
if ($limit > 0) {
    $ids = array_slice($ids, 0, $limit);
}

$stats = [
    'listed_customers' => count($ids),
    'created' => 0,
    'updated' => 0,
    'unchanged' => 0,
    'failed' => 0,
    'invalid_rut' => 0,
    'comuna_matched' => 0,
    'comuna_raw' => 0,
    'contacts_saved' => 0,
    'contacts_existing' => 0,
    'contacts_skipped' => 0,
    'detail_errors' => 0,
];

foreach ($ids as $offset => $facto_id) {
    $detail = riverso_facto_get($client, 'customers_suppliers/' . $facto_id);
    if (is_wp_error($detail) || !is_array($detail)) {
        $stats['detail_errors']++;
        fwrite(STDERR, "Detalle {$facto_id} falló\n");
        continue;
    }

    $name = trim((string) ($detail['name'] ?? ''));
    if ($name === '') {
        $stats['failed']++;
        continue;
    }

    $invoices = isset($detail['invoicing_details']) && is_array($detail['invoicing_details'])
        ? $detail['invoicing_details']
        : [];
    $invoice = null;
    $best = -1;
    foreach ($invoices as $inv) {
        if (!is_array($inv)) {
            continue;
        }
        $score = 0;
        foreach (['tax_id_code', 'legal_name', 'address', 'district', 'city', 'business_activity', 'phone'] as $key) {
            if (trim((string) ($inv[$key] ?? '')) !== '') {
                $score++;
            }
        }
        $rut_try = riverso_rut_body((string) ($inv['tax_id_code'] ?? ''));
        if ($rut_try !== '' && riverso_validate_rut($rut_try)) {
            $score += 5;
        }
        if ($score > $best) {
            $best = $score;
            $invoice = $inv;
        }
    }

    $rut = '';
    if ($invoice) {
        $candidate = riverso_rut_body((string) ($invoice['tax_id_code'] ?? ''));
        if ($candidate !== '' && riverso_validate_rut($candidate)) {
            $rut = $candidate;
        } elseif (trim((string) ($invoice['tax_id_code'] ?? '')) !== '') {
            $stats['invalid_rut']++;
        }
    }

    $district = $invoice ? trim((string) ($invoice['district'] ?? '')) : '';
    $comuna = '';
    if ($district !== '') {
        $folded = riverso_fold($district);
        if ($folded !== '' && isset($comunas[$folded])) {
            $comuna = $comunas[$folded];
            $stats['comuna_matched']++;
        } else {
            $comuna = $district;
            $stats['comuna_raw']++;
        }
    }

    $phone = $invoice ? trim((string) ($invoice['phone'] ?? '')) : '';
    $incoming = [
        'nombre_fantasia' => $name,
        'has_facturacion' => 1,
        'pais' => 'CHILE',
        'tipo_identificacion' => 'RUT_CLIENTE',
        'rut' => $rut,
        'razon_social' => $invoice ? trim((string) ($invoice['legal_name'] ?? '')) : '',
        'direccion' => $invoice ? trim((string) ($invoice['address'] ?? '')) : '',
        'comuna' => $comuna,
        'ciudad' => $invoice ? trim((string) ($invoice['city'] ?? '')) : '',
        'giro' => $invoice ? trim((string) ($invoice['business_activity'] ?? '')) : '',
        'facturacion_telefono' => $phone,
        'activo' => !isset($detail['status']) || (int) $detail['status'] !== 0,
    ];

    $local_id = 0;
    if (isset($by_facto[$facto_id])) {
        $local_id = (int) $by_facto[$facto_id];
    } elseif ($rut !== '' && isset($by_rut[riverso_rut_key($rut)])) {
        $local_id = (int) $by_rut[riverso_rut_key($rut)];
    }

    $current = $local_id > 0 ? $customers->get($local_id) : null;
    $payload = [
        'id' => $local_id,
        'nombre_fantasia' => $current && !riverso_blank($current, 'nombre_fantasia')
            ? (string) $current['nombre_fantasia']
            : $incoming['nombre_fantasia'],
        'has_contacto' => $current ? !empty($current['has_contacto']) : false,
        'primer_nombre' => $current ? (string) $current['primer_nombre'] : '',
        'apellido_paterno' => $current ? (string) $current['apellido_paterno'] : '',
        'contacto_telefono' => $current ? (string) $current['contacto_telefono'] : '',
        'contacto_email' => $current ? (string) $current['contacto_email'] : '',
        'has_facturacion' => 1,
        'pais' => 'CHILE',
        'tipo_identificacion' => 'RUT_CLIENTE',
        'rut' => $current && !riverso_blank($current, 'rut') ? (string) $current['rut'] : $incoming['rut'],
        'razon_social' => $current && !riverso_blank($current, 'razon_social') ? (string) $current['razon_social'] : $incoming['razon_social'],
        'direccion' => $current && !riverso_blank($current, 'direccion') ? (string) $current['direccion'] : $incoming['direccion'],
        'comuna' => $current && !riverso_blank($current, 'comuna') ? (string) $current['comuna'] : $incoming['comuna'],
        'ciudad' => $current && !riverso_blank($current, 'ciudad') ? (string) $current['ciudad'] : $incoming['ciudad'],
        'giro' => $current && !riverso_blank($current, 'giro') ? (string) $current['giro'] : $incoming['giro'],
        'facturacion_telefono' => $current && !riverso_blank($current, 'facturacion_telefono')
            ? (string) $current['facturacion_telefono']
            : $incoming['facturacion_telefono'],
        'codigo_postal' => $current ? (string) $current['codigo_postal'] : '0',
        'has_datos_extra' => 1,
        'datos_extra' => riverso_with_facto_id(riverso_extra_list($current['datos_extra'] ?? []), $facto_id),
        'activo' => $current ? !empty($current['activo']) : !empty($incoming['activo']),
    ];

    $contact_rows = isset($detail['contacts']) && is_array($detail['contacts']) ? $detail['contacts'] : [];
    foreach ($contact_rows as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $first = trim((string) ($contact['first_name'] ?? ''));
        $last = trim((string) ($contact['last_name'] ?? ''));
        if ($first === '' || $last === '') {
            continue;
        }
        if (riverso_blank($payload, 'primer_nombre')) {
            $payload['primer_nombre'] = $first;
            $payload['has_contacto'] = true;
        }
        if (riverso_blank($payload, 'apellido_paterno')) {
            $payload['apellido_paterno'] = $last;
            $payload['has_contacto'] = true;
        }
        $contact_phone = trim((string) ($contact['phone'] ?? ''));
        $contact_email = trim((string) ($contact['email'] ?? ''));
        if ($contact_phone !== '' && riverso_blank($payload, 'contacto_telefono')) {
            $payload['contacto_telefono'] = $contact_phone;
        }
        if ($contact_email !== '' && riverso_blank($payload, 'contacto_email')) {
            $payload['contacto_email'] = $contact_email;
        }
        break;
    }
    if (trim((string) $payload['primer_nombre']) === '' || trim((string) $payload['apellido_paterno']) === '') {
        $payload['has_contacto'] = false;
        $payload['primer_nombre'] = '';
        $payload['apellido_paterno'] = '';
    }

    $saved = $customers->save($payload);
    if (empty($saved['ok'])) {
        $stats['failed']++;
        $message = isset($saved['message']) ? (string) $saved['message'] : 'error';
        fwrite(STDERR, "Cliente {$facto_id}: {$message}\n");
        continue;
    }

    $local_id = (int) $saved['id'];
    $by_facto[$facto_id] = $local_id;
    $saved_rut = riverso_rut_key((string) ($payload['rut'] ?? ''));
    if ($saved_rut !== '' && !isset($by_rut[$saved_rut])) {
        $by_rut[$saved_rut] = $local_id;
    }
    if ($current) {
        $stats['updated']++;
    } else {
        $stats['created']++;
    }

    $primary_used = false;
    foreach ($contact_rows as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $email = strtolower(trim((string) ($contact['email'] ?? '')));
        $first = trim((string) ($contact['first_name'] ?? ''));
        $last = trim((string) ($contact['last_name'] ?? ''));
        $contact_phone = trim((string) ($contact['phone'] ?? ''));
        if ($email === '' && $first === '' && $last === '') {
            $stats['contacts_skipped']++;
            continue;
        }
        if ($email !== '' && !is_email($email)) {
            $stats['contacts_skipped']++;
            continue;
        }

        if ($first !== '' && $last !== '') {
            $existing_contact = null;
            if ($email !== '') {
                foreach ($contacts->search(['cliente_id' => $local_id, 'email' => $email]) as $hit) {
                    if (strtolower((string) ($hit['email'] ?? '')) === $email) {
                        $existing_contact = $hit;
                        break;
                    }
                }
            }
            $result = $contacts->save([
                'id' => $existing_contact ? (int) $existing_contact['id'] : 0,
                'cliente_id' => $local_id,
                'primer_nombre' => $first,
                'apellido_paterno' => $last,
                'email' => $email,
                'fono_movil' => $contact_phone,
                'es_primario' => $primary_used ? 0 : 1,
                'activo' => 1,
                'origen' => 'facturacion',
                'lab_pais' => 'CHILE',
                'part_pais' => 'CHILE',
            ]);
            $primary_used = true;
            if (!empty($result['ok'])) {
                $stats[$existing_contact ? 'contacts_existing' : 'contacts_saved']++;
            } else {
                $stats['contacts_skipped']++;
            }
            continue;
        }

        $remembered = $contacts->remember($local_id, $email, 'facturacion', $contact_phone);
        if (!empty($remembered['ok']) && (($remembered['status'] ?? '') === 'exists')) {
            $stats['contacts_existing']++;
        } elseif (!empty($remembered['ok']) && !empty($remembered['id'])) {
            $stats['contacts_saved']++;
        } else {
            $stats['contacts_skipped']++;
        }
    }

    if ((($offset + 1) % 25) === 0) {
        fwrite(STDERR, 'Progreso ' . ($offset + 1) . '/' . count($ids) . "\n");
    }
}

$stats['riverso_clientes'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$stats['riverso_con_rut'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE rut IS NOT NULL AND rut <> ''");
$stats['riverso_con_direccion'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE direccion IS NOT NULL AND direccion <> ''");
$stats['riverso_con_giro'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE giro IS NOT NULL AND giro <> ''");
$contact_table = $wpdb->prefix . 'riverso_cliente_contactos';
$stats['riverso_contactos'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contact_table}");
$stats['riverso_contactos_email'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contact_table} WHERE email IS NOT NULL AND email <> ''");

echo 'IMPORT_SUMMARY ' . wp_json_encode($stats) . "\n";
exit($stats['failed'] > 0 || $stats['detail_errors'] > 0 ? 2 : 0);
