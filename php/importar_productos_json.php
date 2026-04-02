<?php
/**
 * Importador de productos variables desde JSON
 * 
 * Uso: wpr eval-file importar_productos_json.php
 * 
 * Espera un archivo productos.json en el mismo directorio
 */

if (!defined('ABSPATH')) {
    echo "Este script debe ejecutarse con: wpr eval-file importar_productos_json.php\n";
    exit(1);
}

require_once __DIR__ . '/crear_producto_variable.php';

/**
 * Importa productos desde un archivo JSON
 */
function importar_desde_json($archivo_json) {
    if (!file_exists($archivo_json)) {
        return new WP_Error('file_not_found', "Archivo no encontrado: {$archivo_json}");
    }
    
    $contenido = file_get_contents($archivo_json);
    $productos = json_decode($contenido, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Error al parsear JSON: ' . json_last_error_msg());
    }
    
    if (!is_array($productos)) {
        return new WP_Error('invalid_format', 'El JSON debe contener un array de productos');
    }
    
    $resultados = [
        'exitosos' => [],
        'errores' => []
    ];
    
    foreach ($productos as $idx => $producto_data) {
        echo "\n--- Procesando producto " . ($idx + 1) . "/" . count($productos) . " ---\n";
        
        $resultado = crear_producto_variable($producto_data);
        
        if (is_wp_error($resultado)) {
            $resultados['errores'][] = [
                'nombre' => $producto_data['nombre'] ?? 'Sin nombre',
                'error' => $resultado->get_error_message()
            ];
        } else {
            $resultados['exitosos'][] = [
                'id' => $resultado,
                'nombre' => $producto_data['nombre']
            ];
        }
    }
    
    return $resultados;
}

/**
 * Genera un template JSON de ejemplo
 */
function generar_template_json($archivo_salida) {
    $template = [
        [
            'nombre' => 'TORNILLO HEXAGONAL ACERO',
            'descripcion' => 'Tornillo hexagonal de acero al carbono',
            'estado' => 'private',
            'nominales' => ['M6', 'M8', 'M10'],
            'largos' => ['20MM', '30MM', '40MM'],
            'variaciones' => [
                [
                    'valor' => 'M6 x 20MM',
                    'sku' => 'TORN-HEX-AC-M6-20',
                    'precio' => '12.00',
                    'stock' => 100,
                    'stock_status' => 'instock'
                ],
                [
                    'valor' => 'M6 x 30MM',
                    'sku' => 'TORN-HEX-AC-M6-30',
                    'precio' => '15.00',
                    'stock' => 80,
                    'stock_status' => 'instock'
                ],
                [
                    'valor' => 'M8 x 40MM',
                    'sku' => 'TORN-HEX-AC-M8-40',
                    'precio' => '22.00',
                    'stock' => null,
                    'stock_status' => 'instock'
                ]
            ],
            'default' => 'M6 x 20MM'
        ],
        [
            'nombre' => 'TUERCA HEXAGONAL INOX',
            'descripcion' => 'Tuerca hexagonal acero inoxidable 304',
            'estado' => 'private',
            'nominales' => ['M6', 'M8'],
            'largos' => ['Estándar'],
            'variaciones' => [
                [
                    'valor' => 'M6 x Estándar',
                    'sku' => 'TUER-HEX-INOX-M6',
                    'precio' => '8.00',
                    'stock' => 200,
                    'stock_status' => 'instock'
                ],
                [
                    'valor' => 'M8 x Estándar',
                    'sku' => 'TUER-HEX-INOX-M8',
                    'precio' => '10.00',
                    'stock' => 150,
                    'stock_status' => 'instock'
                ]
            ],
            'default' => 'M6 x Estándar'
        ]
    ];
    
    $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($archivo_salida, $json);
    
    echo "Template generado: {$archivo_salida}\n";
    return true;
}

// ============================================
// EJECUCIÓN
// ============================================

$archivo_json = __DIR__ . '/productos.json';
$archivo_template = __DIR__ . '/productos_template.json';

// Si no existe el archivo de productos, generar template
if (!file_exists($archivo_json)) {
    echo "No se encontró {$archivo_json}\n";
    echo "Generando template de ejemplo...\n\n";
    generar_template_json($archivo_template);
    echo "\nEdita {$archivo_template} y renómbralo a productos.json para importar.\n";
    exit(0);
}

// Importar productos
echo "========================================\n";
echo "   IMPORTADOR DE PRODUCTOS JSON\n";
echo "========================================\n";

$resultados = importar_desde_json($archivo_json);

if (is_wp_error($resultados)) {
    echo "\n❌ ERROR: " . $resultados->get_error_message() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "   RESUMEN DE IMPORTACIÓN\n";
echo "========================================\n";
echo "✅ Exitosos: " . count($resultados['exitosos']) . "\n";
echo "❌ Errores: " . count($resultados['errores']) . "\n";

if (!empty($resultados['errores'])) {
    echo "\nDetalle de errores:\n";
    foreach ($resultados['errores'] as $error) {
        echo "  - {$error['nombre']}: {$error['error']}\n";
    }
}

if (!empty($resultados['exitosos'])) {
    echo "\nProductos creados:\n";
    foreach ($resultados['exitosos'] as $exito) {
        echo "  - ID {$exito['id']}: {$exito['nombre']}\n";
    }
}
