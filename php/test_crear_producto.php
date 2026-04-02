<?php
/**
 * Script de prueba rápida para crear un producto variable
 * 
 * Uso: wpr eval-file test_crear_producto.php
 */

if (!defined('ABSPATH')) {
    echo "Este script debe ejecutarse con: wpr eval-file test_crear_producto.php\n";
    exit(1);
}

// Cargar funciones principales
require_once __DIR__ . '/crear_producto_variable.php';

// Datos de prueba
$datos_producto = [
    'nombre' => 'TORNILLO PRUEBA AUTOMATICO ' . date('His'),
    'descripcion' => 'Producto creado automáticamente para prueba del script',
    'estado' => 'private',
    'nominales' => ['M6', 'M8'],
    'largos' => ['25MM', '30MM'],
    'variaciones' => [
        [
            'valor' => 'M6 x 25MM',
            'sku' => 'TEST-' . date('YmdHis') . '-1',
            'precio' => '15.00',
            'stock' => 50,
            'stock_status' => 'instock'
        ],
        [
            'valor' => 'M6 x 30MM',
            'sku' => 'TEST-' . date('YmdHis') . '-2',
            'precio' => '18.00',
            'stock' => 30,
            'stock_status' => 'instock'
        ],
        [
            'valor' => 'M8 x 25MM',
            'sku' => 'TEST-' . date('YmdHis') . '-3',
            'precio' => '20.00',
            'stock_status' => 'instock'
        ],
        [
            'valor' => 'M8 x 30MM',
            'sku' => 'TEST-' . date('YmdHis') . '-4',
            'precio' => '22.50',
            'stock_status' => 'instock'
        ]
    ],
    'default' => 'M6 x 25MM'
];

echo "\n========================================\n";
echo "   PRUEBA: Crear Producto Variable\n";
echo "========================================\n\n";

$resultado = crear_producto_variable($datos_producto);

if (is_wp_error($resultado)) {
    echo "\n❌ ERROR: " . $resultado->get_error_message() . "\n";
    exit(1);
} else {
    echo "\n✅ ÉXITO: Producto creado con ID: {$resultado}\n";
    
    // Verificar el producto creado
    $producto = wc_get_product($resultado);
    if ($producto) {
        echo "\nVerificación:\n";
        echo "  - Tipo: " . $producto->get_type() . "\n";
        echo "  - Estado: " . $producto->get_status() . "\n";
        echo "  - Variaciones: " . count($producto->get_children()) . "\n";
        
        $atributos = $producto->get_attributes();
        echo "  - Atributos: " . count($atributos) . "\n";
        foreach ($atributos as $slug => $attr) {
            $es_variacion = $attr->get_variation() ? 'SÍ' : 'NO';
            echo "    → {$slug}: variación={$es_variacion}, opciones=" . count($attr->get_options()) . "\n";
        }
    }
}

echo "\n========================================\n";
