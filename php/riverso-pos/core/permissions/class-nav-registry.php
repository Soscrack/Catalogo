<?php
/**
 * Registry unificado de navegación (portal + WP Admin).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Nav_Registry {

    /**
     * Grupos de menú (orden de aparición).
     */
    const GROUPS = [
        'inicio'   => ['label' => 'Inicio', 'order' => 10],
        'ventas'   => ['label' => 'Ventas', 'order' => 20],
        'catalogo' => ['label' => 'Catálogo', 'order' => 30],
        'bodega'   => ['label' => 'Bodega', 'order' => 40],
        'compras'  => ['label' => 'Compras', 'order' => 50],
        'gestion'  => ['label' => 'Gestión', 'order' => 60],
    ];

    /**
     * Ítems de navegación. capabilities por superficie se mantienen
     * como estaban (portal y admin pueden diferir).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items() {
        return [
            // === Inicio ===
            [
                'id' => 'dashboard',
                'group' => 'inicio',
                'label' => 'Dashboard',
                'icon' => 'dashboard',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'dashboard',
                'admin_page' => 'riverso-pos',
                'admin_callback' => 'render_dashboard',
                'portal_capability' => 'riverso_access_portal',
                'admin_capability' => 'riverso_view_products',
            ],
            [
                'id' => 'tasks',
                'group' => 'inicio',
                'label' => 'Tareas',
                'icon' => 'clipboard',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'tasks',
                'admin_page' => 'riverso-pos-tasks',
                'admin_callback' => 'render_tasks',
                'capability' => 'riverso_view_tasks',
            ],

            // === Ventas ===
            [
                'id' => 'pos',
                'group' => 'ventas',
                'label' => 'Punto de Venta',
                'menu_label' => '🛒 Punto de Venta',
                'icon' => 'cart',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'pos',
                'admin_page' => 'riverso-pos-pos',
                'admin_callback' => 'render_pos',
                'capability' => 'riverso_use_pos',
            ],
            [
                'id' => 'customer-quotes',
                'group' => 'ventas',
                'label' => 'Cotizaciones Clientes',
                'menu_label' => 'Cotizaciones a Clientes',
                'icon' => 'media-document',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'customer-quotes',
                'admin_page' => 'riverso-pos-customer-quotes',
                'admin_callback' => 'render_customer_quotes',
                'capability' => 'riverso_view_quotes',
            ],
            [
                'id' => 'reports',
                'group' => 'ventas',
                'label' => 'Reportes',
                'menu_label' => '📊 Reportes',
                'icon' => 'chart-bar',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'reports',
                'admin_page' => 'riverso-pos-reports',
                'admin_callback' => 'render_reports',
                'capability' => 'riverso_view_reports',
            ],

            // === Catálogo ===
            [
                'id' => 'products',
                'group' => 'catalogo',
                'label' => 'Productos',
                'icon' => 'archive',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'products',
                'admin_page' => 'riverso-pos-products',
                'admin_callback' => 'render_products',
                'capability' => 'riverso_view_products',
            ],
            [
                'id' => 'categories',
                'group' => 'catalogo',
                'label' => 'Categorías',
                'menu_label' => 'Categorías y Familias',
                'icon' => 'category',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'categories',
                'admin_page' => 'riverso-pos-categories',
                'admin_callback' => 'render_categories',
                'portal_capability' => 'riverso_view_categories',
                'admin_capability' => 'riverso_manage_products',
            ],
            [
                'id' => 'catalog',
                'group' => 'catalogo',
                'label' => 'Catálogo',
                'icon' => 'category',
                'surfaces' => ['portal'],
                'portal_slug' => 'catalog',
                'portal_capability_any' => ['riverso_review_products', 'riverso_publish_products'],
            ],
            [
                'id' => 'publish',
                'group' => 'catalogo',
                'label' => 'Publicación',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-publish',
                'admin_callback' => 'render_publish',
                'admin_capability' => 'riverso_review_products',
            ],
            [
                'id' => 'tienda-local',
                'group' => 'catalogo',
                'label' => 'Tienda Local',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-tienda-local',
                'admin_callback' => 'render_tienda_local',
                'admin_capability' => 'riverso_view_products',
            ],
            [
                'id' => 'competencia',
                'group' => 'catalogo',
                'label' => 'Competencia',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-competencia',
                'admin_callback' => 'render_competencia',
                'admin_capability' => 'riverso_manage_competencia',
            ],
            [
                'id' => 'domain',
                'group' => 'catalogo',
                'label' => 'Catálogo Canónico',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-domain',
                'admin_callback' => 'render_domain',
                'admin_capability' => 'riverso_manage_codes',
            ],
            [
                'id' => 'catalog-health',
                'group' => 'catalogo',
                'label' => 'Salud del catálogo',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-catalog-health',
                'admin_callback' => 'render_catalog_health',
                'admin_capability' => 'riverso_manage_codes',
            ],

            // === Bodega ===
            [
                'id' => 'warehouse',
                'group' => 'bodega',
                'label' => 'Bodega',
                'icon' => 'store',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'warehouse',
                'admin_page' => 'riverso-pos-warehouse',
                'admin_callback' => 'render_warehouse',
                'portal_capability' => 'riverso_view_warehouse',
                'admin_capability' => 'riverso_view_stock',
            ],
            [
                'id' => 'reception',
                'group' => 'bodega',
                'label' => 'Recepción',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-reception',
                'admin_callback' => 'render_reception',
                'admin_capability' => 'riverso_receive_items',
            ],
            [
                'id' => 'barcodes',
                'group' => 'bodega',
                'label' => 'Códigos de Barra',
                'icon' => 'barcode',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'barcodes',
                'admin_page' => 'riverso-pos-barcodes',
                'admin_callback' => 'render_barcodes',
                'portal_capability_any' => ['riverso_scan_barcodes', 'riverso_assign_barcodes'],
                'admin_capability' => 'riverso_manage_products',
            ],
            [
                'id' => 'impresiones',
                'group' => 'bodega',
                'label' => 'Impresiones',
                'icon' => 'printer',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'impresiones',
                'admin_page' => 'riverso-pos-print-orders',
                'admin_callback' => 'render_print_orders',
                'portal_capability_any' => ['riverso_view_print_orders', 'riverso_print_labels'],
                'admin_capability' => 'riverso_view_print_orders',
            ],
            [
                'id' => 'manufacturing',
                'group' => 'bodega',
                'label' => 'Manufactura [WIP]',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-manufacturing',
                'admin_callback' => 'render_manufacturing',
                'admin_capability' => 'riverso_manage_manufacturing',
            ],
            [
                'id' => 'packaging',
                'group' => 'bodega',
                'label' => 'Embolsado',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-packaging',
                'admin_callback' => 'render_packaging',
                'admin_capability' => 'riverso_manage_packaging',
            ],

            // === Compras ===
            [
                'id' => 'inbox',
                'group' => 'compras',
                'label' => 'Bandeja',
                'menu_label' => 'Bandeja correo/WhatsApp',
                'icon' => 'email',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'inbox',
                'admin_page' => 'riverso-pos-inbox',
                'admin_callback' => 'render_inbox',
                'capability' => 'riverso_view_inbox',
            ],
            [
                'id' => 'invoices',
                'group' => 'compras',
                'label' => 'Facturas',
                'icon' => 'media-spreadsheet',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'invoices',
                'admin_page' => 'riverso-pos-invoices',
                'admin_callback' => 'render_invoices',
                'capability' => 'riverso_view_invoices',
            ],
            [
                'id' => 'received-quotes',
                'group' => 'compras',
                'label' => 'Cotizaciones Proveedores',
                'menu_label' => 'Cotizaciones Recibidas',
                'icon' => 'download',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'received-quotes',
                'admin_page' => 'riverso-pos-received-quotes',
                'admin_callback' => 'render_received_quotes',
                'portal_capability' => 'riverso_view_received_quotes',
                'admin_capability' => 'riverso_view_invoices',
            ],
            [
                'id' => 'suppliers',
                'group' => 'compras',
                'label' => 'Proveedores',
                'icon' => 'groups',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'suppliers',
                'admin_page' => 'riverso-pos-suppliers',
                'admin_callback' => 'render_suppliers',
                'capability' => 'riverso_view_suppliers',
            ],
            [
                'id' => 'codes',
                'group' => 'compras',
                'label' => 'Códigos Proveedor',
                'menu_label' => 'Códigos',
                'icon' => 'admin-links',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'codes',
                'admin_page' => 'riverso-pos-codes',
                'admin_callback' => 'render_codes',
                'capability' => 'riverso_manage_codes',
            ],
            [
                'id' => 'manual-mapping',
                'group' => 'compras',
                'label' => 'Mapeo manual',
                'icon' => 'randomize',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'manual-mapping',
                'admin_page' => 'riverso-pos-manual-mapping',
                'admin_callback' => 'render_manual_mapping',
                'capability' => 'riverso_manage_codes',
            ],

            // === Gestión ===
            [
                'id' => 'cost-history',
                'group' => 'gestion',
                'label' => 'Historial Costos',
                'menu_label' => 'Historial de Costos',
                'icon' => 'chart-line',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'cost-history',
                'admin_page' => 'riverso-pos-costs',
                'admin_callback' => 'render_costs',
                'capability' => 'riverso_view_costs',
            ],
            [
                'id' => 'pricing',
                'group' => 'gestion',
                'label' => 'Precios',
                'menu_label' => '💲 Precios',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-pricing',
                'admin_callback' => 'render_pricing',
                'admin_capability' => 'riverso_view_prices',
            ],
            [
                'id' => 'price-rules',
                'group' => 'gestion',
                'label' => 'Reglas de Precio',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-price-rules',
                'admin_callback' => 'render_price_rules',
                'admin_capability' => 'riverso_manage_prices',
            ],
            [
                'id' => 'facto-export',
                'group' => 'gestion',
                'label' => 'Export FACTO',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-facto-export',
                'admin_callback' => 'render_facto_export',
                'admin_capability' => 'riverso_export_facto',
            ],
            [
                'id' => 'tpv-export',
                'group' => 'gestion',
                'label' => 'Export TPV',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-tpv-export',
                'admin_callback' => 'render_tpv_export',
                'admin_capability' => 'riverso_export_facto',
            ],
            [
                'id' => 'employees',
                'group' => 'gestion',
                'label' => 'Empleados',
                'icon' => 'admin-users',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'employees',
                'admin_page' => 'riverso-pos-employees',
                'admin_callback' => 'render_employees',
                'capability' => 'riverso_manage_users',
            ],
            [
                'id' => 'settings',
                'group' => 'gestion',
                'label' => 'Configuración',
                'icon' => 'admin-generic',
                'surfaces' => ['portal', 'admin'],
                'portal_slug' => 'settings',
                'admin_page' => 'riverso-pos-settings',
                'admin_callback' => 'render_settings',
                'capability' => 'riverso_manage_settings',
            ],
            [
                'id' => 'permissions',
                'group' => 'gestion',
                'label' => 'Permisos',
                'menu_label' => '🔐 Permisos',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-permissions',
                'admin_callback' => 'render_permissions',
                'admin_capability' => 'riverso_manage_permissions',
            ],
            [
                'id' => 'audit',
                'group' => 'gestion',
                'label' => 'Auditoría',
                'surfaces' => ['admin'],
                'admin_page' => 'riverso-pos-audit',
                'admin_callback' => 'render_audit',
                'admin_capability' => 'riverso_view_audit',
            ],
        ];
    }

    /**
     * @param int|null $user_id
     * @return callable
     */
    private static function can_checker($user_id = null) {
        return static function ($cap) use ($user_id) {
            if ($user_id) {
                return user_can($user_id, $cap);
            }
            return current_user_can($cap);
        };
    }

    /**
     * ¿El usuario puede ver este ítem en la superficie dada?
     *
     * @param array    $item
     * @param string   $surface portal|admin
     * @param int|null $user_id
     */
    public static function user_can_item(array $item, $surface, $user_id = null) {
        if (!in_array($surface, $item['surfaces'] ?? [], true)) {
            return false;
        }

        $can = self::can_checker($user_id);
        $any_key = $surface . '_capability_any';
        $cap_key = $surface . '_capability';

        if (!empty($item[$any_key]) && is_array($item[$any_key])) {
            foreach ($item[$any_key] as $cap) {
                if ($can($cap)) {
                    return true;
                }
            }
            return false;
        }

        if (!empty($item[$cap_key])) {
            return $can($item[$cap_key]);
        }

        if (!empty($item['capability_any']) && is_array($item['capability_any'])) {
            foreach ($item['capability_any'] as $cap) {
                if ($can($cap)) {
                    return true;
                }
            }
            return false;
        }

        if (!empty($item['capability'])) {
            return $can($item['capability']);
        }

        return false;
    }

    /**
     * Módulos accesibles en portal (formato legacy de get_accessible_modules).
     *
     * @param int|null $user_id
     * @return array<string, array{icon: string, label: string, group: string}>
     */
    public static function get_portal_modules($user_id = null) {
        $modules = [];
        foreach (self::items() as $item) {
            if (!self::user_can_item($item, 'portal', $user_id)) {
                continue;
            }
            $slug = $item['portal_slug'] ?? $item['id'];
            $modules[$slug] = [
                'icon'  => $item['icon'] ?? 'admin-generic',
                'label' => $item['label'],
                'group' => $item['group'],
            ];
        }
        return $modules;
    }

    /**
     * Grupos con ítems accesibles para una superficie.
     * Omite grupos vacíos.
     *
     * @param string   $surface portal|admin
     * @param int|null $user_id
     * @return array<string, array{label: string, order: int, items: array}>
     */
    public static function get_grouped($surface, $user_id = null) {
        $grouped = [];

        foreach (self::GROUPS as $group_id => $meta) {
            $grouped[$group_id] = [
                'label' => $meta['label'],
                'order' => $meta['order'],
                'items' => [],
            ];
        }

        foreach (self::items() as $item) {
            if (!self::user_can_item($item, $surface, $user_id)) {
                continue;
            }
            $group_id = $item['group'];
            if (!isset($grouped[$group_id])) {
                continue;
            }
            $grouped[$group_id]['items'][] = $item;
        }

        // Quitar grupos sin ítems y ordenar
        $grouped = array_filter($grouped, static function ($g) {
            return !empty($g['items']);
        });

        uasort($grouped, static function ($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        return $grouped;
    }

    /**
     * Capability mínima para mostrar el menú top-level en admin
     * (primera capability de dashboard admin, o read como fallback).
     */
    public static function get_admin_top_capability() {
        return 'riverso_view_products';
    }
}
