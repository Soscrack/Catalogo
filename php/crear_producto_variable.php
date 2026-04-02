<?php
/**
 * Script para crear productos variables en WooCommerce
 * 
 * Uso: wpr eval-file crear_producto_variable.php
 * 
 * Este script usa las clases oficiales de WooCommerce:
 * - WC_Product_Variable
 * - WC_Product_Variation
 * - WC_Product_Attribute
 */

if (!defined('ABSPATH')) {
    echo "Este script debe ejecutarse con: wpr eval-file crear_producto_variable.php\n";
    exit(1);
}

/**
 * Crea un producto variable con atributos Nominal, Largo y Nominal X Largo
 * 
 * @param array $data Datos del producto:
 *   - nombre: string (requerido)
 *   - descripcion: string (opcional)
 *   - estado: string (draft|private|publish, default: private)
 *   - nominales: array de valores nominales (requerido)
 *   - largos: array de valores de largo (requerido)
 *   - variaciones: array de variaciones (requerido), cada una con:
 *     - valor: string "Nominal x Largo" (requerido)
 *     - sku: string (requerido)
 *     - precio: string (requerido)
 *     - stock: int|null (opcional)
 *     - stock_status: string (instock|outofstock, default: instock)
 *     - descripcion: string (opcional)
 *   - default: string valor por defecto "Nominal x Largo" (opcional)
 *   - categoria_ids: array de IDs de categoría (opcional)
 *   - imagen_id: int ID de imagen destacada (opcional)
 * 
 * @return int|WP_Error ID del producto creado o error
 */
function crear_producto_variable($data) {
    // Validar datos requeridos
    if (empty($data['nombre'])) {
        return new WP_Error('missing_name', 'El nombre del producto es requerido');
    }
    if (empty($data['nominales']) || !is_array($data['nominales'])) {
        return new WP_Error('missing_nominales', 'Los valores de Nominal son requeridos');
    }
    if (empty($data['largos']) || !is_array($data['largos'])) {
        return new WP_Error('missing_largos', 'Los valores de Largo son requeridos');
    }
    if (empty($data['variaciones']) || !is_array($data['variaciones'])) {
        return new WP_Error('missing_variaciones', 'Las variaciones son requeridas');
    }

    // Validar SKUs únicos antes de crear
    $skus_existentes = validar_skus_unicos($data['variaciones']);
    if (is_wp_error($skus_existentes)) {
        return $skus_existentes;
    }

    // Crear producto padre
    $producto = new WC_Product_Variable();
    
    // Configurar datos básicos
    $producto->set_name($data['nombre']);
    $producto->set_status($data['estado'] ?? 'private');
    
    if (!empty($data['descripcion'])) {
        $producto->set_description($data['descripcion']);
    }
    
    if (!empty($data['categoria_ids'])) {
        $producto->set_category_ids($data['categoria_ids']);
    }
    
    if (!empty($data['imagen_id'])) {
        $producto->set_image_id($data['imagen_id']);
    }

    // Generar valores combinados para "Nominal X Largo"
    $valores_combinados = array();
    foreach ($data['variaciones'] as $var) {
        if (!empty($var['valor'])) {
            $valores_combinados[] = $var['valor'];
        }
    }

    // Configurar atributos
    $atributos = array();

    // Atributo 1: Nominal (informativo, no variación)
    $attr_nominal = new WC_Product_Attribute();
    $attr_nominal->set_id(0);
    $attr_nominal->set_name('Nominal');
    $attr_nominal->set_options($data['nominales']);
    $attr_nominal->set_visible(true);
    $attr_nominal->set_variation(false);
    $atributos[] = $attr_nominal;

    // Atributo 2: Largo (informativo, no variación)
    $attr_largo = new WC_Product_Attribute();
    $attr_largo->set_id(0);
    $attr_largo->set_name('Largo');
    $attr_largo->set_options($data['largos']);
    $attr_largo->set_visible(true);
    $attr_largo->set_variation(false);
    $atributos[] = $attr_largo;

    // Atributo 3: Nominal X Largo (variación real)
    $attr_combinado = new WC_Product_Attribute();
    $attr_combinado->set_id(0);
    $attr_combinado->set_name('Nominal X Largo');
    $attr_combinado->set_options($valores_combinados);
    $attr_combinado->set_visible(false);
    $attr_combinado->set_variation(true);
    $atributos[] = $attr_combinado;

    $producto->set_attributes($atributos);

    // Guardar producto padre
    $producto_id = $producto->save();

    if (!$producto_id) {
        return new WP_Error('save_failed', 'Error al guardar el producto padre');
    }

    echo "✓ Producto padre creado: ID {$producto_id} - {$data['nombre']}\n";

    // Crear variaciones
    $variaciones_creadas = 0;
    foreach ($data['variaciones'] as $var_data) {
        $resultado = crear_variacion($producto_id, $var_data);
        if (is_wp_error($resultado)) {
            echo "✗ Error en variación {$var_data['valor']}: " . $resultado->get_error_message() . "\n";
        } else {
            $variaciones_creadas++;
            echo "  ↳ Variación creada: ID {$resultado} - {$var_data['valor']} (SKU: {$var_data['sku']})\n";
        }
    }

    // Configurar atributo por defecto si se especifica
    if (!empty($data['default'])) {
        update_post_meta($producto_id, '_default_attributes', array(
            'nominal-x-largo' => $data['default']
        ));
        echo "✓ Atributo por defecto: {$data['default']}\n";
    }

    // Sincronizar producto variable
    WC_Product_Variable::sync($producto_id);
    
    // Limpiar cachés de WooCommerce
    wc_delete_product_transients($producto_id);

    echo "\n✓ Producto variable completado: {$variaciones_creadas} variaciones creadas\n";
    echo "  URL Admin: " . admin_url("post.php?post={$producto_id}&action=edit") . "\n";

    return $producto_id;
}

/**
 * Crea una variación individual
 */
function crear_variacion($parent_id, $data) {
    if (empty($data['valor'])) {
        return new WP_Error('missing_valor', 'El valor de la variación es requerido');
    }
    if (empty($data['sku'])) {
        return new WP_Error('missing_sku', 'El SKU de la variación es requerido');
    }
    if (!isset($data['precio'])) {
        return new WP_Error('missing_precio', 'El precio de la variación es requerido');
    }

    $variacion = new WC_Product_Variation();
    
    $variacion->set_parent_id($parent_id);
    $variacion->set_status('publish');
    $variacion->set_sku($data['sku']);
    $variacion->set_regular_price($data['precio']);
    $variacion->set_price($data['precio']);
    
    // Configurar stock
    if (isset($data['stock']) && $data['stock'] !== null) {
        $variacion->set_manage_stock(true);
        $variacion->set_stock_quantity($data['stock']);
    } else {
        $variacion->set_manage_stock(false);
    }
    
    $variacion->set_stock_status($data['stock_status'] ?? 'instock');
    
    if (!empty($data['descripcion'])) {
        $variacion->set_description($data['descripcion']);
    }

    // Configurar atributo de variación
    $variacion->set_attributes(array(
        'nominal-x-largo' => $data['valor']
    ));

    return $variacion->save();
}

/**
 * Valida que los SKUs no existan en la base de datos
 */
function validar_skus_unicos($variaciones) {
    $skus_duplicados = array();
    
    foreach ($variaciones as $var) {
        if (empty($var['sku'])) continue;
        
        $producto_existente = wc_get_product_id_by_sku($var['sku']);
        if ($producto_existente) {
            $skus_duplicados[] = $var['sku'] . " (producto ID: {$producto_existente})";
        }
    }
    
    if (!empty($skus_duplicados)) {
        return new WP_Error(
            'sku_duplicado',
            'SKUs duplicados encontrados: ' . implode(', ', $skus_duplicados)
        );
    }
    
    return true;
}

/**
 * Actualiza un producto variable existente (por ID o nombre)
 */
function actualizar_producto_variable($identificador, $data) {
    $producto_id = null;
    
    // Buscar por ID o nombre
    if (is_numeric($identificador)) {
        $producto_id = intval($identificador);
    } else {
        // Buscar por nombre
        $posts = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'any',
            'title' => $identificador,
            'posts_per_page' => 1
        ));
        if (!empty($posts)) {
            $producto_id = $posts[0]->ID;
        }
    }
    
    if (!$producto_id) {
        return new WP_Error('not_found', "Producto no encontrado: {$identificador}");
    }
    
    $producto = wc_get_product($producto_id);
    if (!$producto || !$producto->is_type('variable')) {
        return new WP_Error('invalid_type', 'El producto no es de tipo variable');
    }
    
    echo "Actualizando producto ID: {$producto_id}\n";
    
    // Actualizar datos básicos si se proporcionan
    if (!empty($data['nombre'])) {
        $producto->set_name($data['nombre']);
    }
    if (!empty($data['descripcion'])) {
        $producto->set_description($data['descripcion']);
    }
    if (!empty($data['estado'])) {
        $producto->set_status($data['estado']);
    }
    
    $producto->save();
    
    // Agregar nuevas variaciones si se proporcionan
    if (!empty($data['variaciones'])) {
        foreach ($data['variaciones'] as $var_data) {
            // Verificar si la variación ya existe por SKU
            $var_existente = wc_get_product_id_by_sku($var_data['sku']);
            if ($var_existente) {
                echo "  ↳ Variación con SKU {$var_data['sku']} ya existe, omitiendo...\n";
                continue;
            }
            
            $resultado = crear_variacion($producto_id, $var_data);
            if (!is_wp_error($resultado)) {
                echo "  ↳ Nueva variación: {$var_data['valor']} (SKU: {$var_data['sku']})\n";
            }
        }
        
        // Actualizar atributos del producto padre si hay nuevos valores
        actualizar_atributos_padre($producto_id, $data);
    }
    
    WC_Product_Variable::sync($producto_id);
    wc_delete_product_transients($producto_id);
    
    return $producto_id;
}

/**
 * Actualiza los atributos del producto padre con nuevos valores
 */
function actualizar_atributos_padre($producto_id, $data) {
    $producto = wc_get_product($producto_id);
    $atributos = $producto->get_attributes();
    
    foreach ($atributos as $slug => $attr) {
        if ($slug === 'nominal' && !empty($data['nominales'])) {
            $opciones_actuales = $attr->get_options();
            $nuevas_opciones = array_unique(array_merge($opciones_actuales, $data['nominales']));
            $attr->set_options($nuevas_opciones);
        }
        if ($slug === 'largo' && !empty($data['largos'])) {
            $opciones_actuales = $attr->get_options();
            $nuevas_opciones = array_unique(array_merge($opciones_actuales, $data['largos']));
            $attr->set_options($nuevas_opciones);
        }
        if ($slug === 'nominal-x-largo' && !empty($data['variaciones'])) {
            $opciones_actuales = $attr->get_options();
            $nuevos_valores = array_column($data['variaciones'], 'valor');
            $nuevas_opciones = array_unique(array_merge($opciones_actuales, $nuevos_valores));
            $attr->set_options($nuevas_opciones);
        }
    }
    
    $producto->set_attributes($atributos);
    $producto->save();
}

/**
 * Lista los productos variables existentes
 */
function listar_productos_variables($limite = 10) {
    $productos = wc_get_products(array(
        'type' => 'variable',
        'limit' => $limite,
        'status' => 'any'
    ));
    
    echo "=== Productos Variables ===\n\n";
    
    foreach ($productos as $producto) {
        $variaciones = $producto->get_children();
        echo "ID: {$producto->get_id()} | {$producto->get_name()} | Variaciones: " . count($variaciones) . "\n";
    }
    
    return $productos;
}

// ============================================
// EJEMPLO DE USO - Descomentar para ejecutar
// ============================================

/*
// Ejemplo 1: Crear producto nuevo
$resultado = crear_producto_variable([
    'nombre' => 'TORNILLO HEXAGONAL INOX M6-M8',
    'descripcion' => 'Tornillo hexagonal de acero inoxidable',
    'estado' => 'private',
    'nominales' => ['M6', 'M8'],
    'largos' => ['30MM', '40MM', '50MM'],
    'variaciones' => [
        [
            'valor' => 'M6 x 30MM',
            'sku' => 'TORN-HEX-INOX-M6-30',
            'precio' => '18.45',
            'stock' => 100,
            'stock_status' => 'instock'
        ],
        [
            'valor' => 'M6 x 40MM',
            'sku' => 'TORN-HEX-INOX-M6-40',
            'precio' => '22.90',
            'stock' => 75,
            'stock_status' => 'instock'
        ],
        [
            'valor' => 'M8 x 50MM',
            'sku' => 'TORN-HEX-INOX-M8-50',
            'precio' => '35.00',
            'stock' => null,
            'stock_status' => 'instock'
        ]
    ],
    'default' => 'M6 x 30MM'
]);

if (is_wp_error($resultado)) {
    echo "ERROR: " . $resultado->get_error_message() . "\n";
} else {
    echo "\nProducto creado exitosamente con ID: {$resultado}\n";
}
*/

/*
// Ejemplo 2: Actualizar producto existente
$resultado = actualizar_producto_variable(120, [
    'variaciones' => [
        [
            'valor' => '10 x 3"',
            'sku' => 'facto0025-1',
            'precio' => '45.00',
            'stock_status' => 'instock'
        ]
    ],
    'nominales' => ['10'],
    'largos' => ['3"']
]);
*/

/*
// Ejemplo 3: Listar productos variables
listar_productos_variables(20);
*/

echo "\n=== Script crear_producto_variable.php cargado ===\n";
echo "Funciones disponibles:\n";
echo "  - crear_producto_variable(\$data)\n";
echo "  - actualizar_producto_variable(\$identificador, \$data)\n";
echo "  - listar_productos_variables(\$limite)\n";
echo "\nDescomente los ejemplos al final del archivo para probar.\n";
