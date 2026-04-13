<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Admin
{

    private static ?Admin $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_action('admin_menu',            [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('script_loader_tag',      [$this, 'add_sri_attributes'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_htw_save_product',            [Pages\ProductsPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_delete_product',          [Pages\ProductsPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_get_product_categories',  [Pages\ProductsPage::class, 'ajax_get_categories']);
        add_action('wp_ajax_htw_save_import',        [Pages\ImportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_import',      [Pages\ImportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_import',     [Pages\ImportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_save_export',        [Pages\ExportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_export',      [Pages\ExportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_export',     [Pages\ExportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_export_detail',      [Pages\ExportPage::class,   'ajax_export_detail']);
        add_action('wp_ajax_htw_report_data',        [Pages\ReportsPage::class,      'ajax_data']);
        add_action('wp_ajax_htw_dashboard_data',    [Pages\DashboardPage::class,   'ajax_data']);
        add_action('wp_ajax_htw_save_po',             [Pages\PurchaseOrderPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_confirm_po',         [Pages\PurchaseOrderPage::class, 'ajax_confirm']);
        add_action('wp_ajax_htw_po_send_to_import',   [Pages\PurchaseOrderPage::class, 'ajax_send_to_import']);
        add_action('wp_ajax_htw_delete_po',           [Pages\PurchaseOrderPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_po_record_payment',    [Pages\PurchaseOrderPage::class, 'ajax_record_payment']);
        add_action('wp_ajax_htw_po_edit_payment',      [Pages\PurchaseOrderPage::class, 'ajax_edit_payment']);
        add_action('wp_ajax_htw_po_delete_payment',    [Pages\PurchaseOrderPage::class, 'ajax_delete_payment']);
        add_action('wp_ajax_htw_save_supplier',            [Pages\SuppliersPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_delete_supplier',          [Pages\SuppliersPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_supplier_transactions',    [Pages\SuppliersPage::class, 'ajax_transactions']);
    }

    /**
     * Add SRI (Subresource Integrity) and crossorigin attributes to CDN scripts.
     *
     * @param string $tag  The generated <script> tag.
     * @param string $handle  WordPress script handle.
     * @return string Modified tag.
     */
    public function add_sri_attributes(string $tag, string $handle): string
    {
        // SRI (Subresource Integrity) hashes — verify at:
        //   https://www.jsdelivr.com/package/npm/chart.js@4.4.2
        //   https://www.jsdelivr.com/package/npm/alpinejs@3.13.5
        // Or run:  openssl dgst -sha384 -binary <(curl -s URL) | openssl base64 -A
        //
        // Uncomment and fill after verifying:
        // $sri_map = [
        //     'chartjs'  => ['integrity' => 'sha384-XXXXXXXX', 'crossorigin' => 'anonymous'],
        //     'alpinejs' => ['integrity' => 'sha384-XXXXXXXX', 'crossorigin' => 'anonymous'],
        // ];
        // if (isset($sri_map[$handle])) {
        //     $attrs = 'integrity="' . $sri_map[$handle]['integrity'] . '" '
        //            . 'crossorigin="' . $sri_map[$handle]['crossorigin'] . '"';
        //     $tag = str_replace(' src=', ' ' . $attrs . ' src=', $tag);
        // }

        return $tag;
    }

    public function register_menus(): void
    {
        add_menu_page(
            'HTWarehouse',
            'HTWarehouse',
            'manage_options',
            'htwarehouse',
            [Pages\DashboardPage::class, 'render'],
            'dashicons-store',
            56
        );

        add_submenu_page('htwarehouse', 'Dashboard',      'Dashboard',       'manage_options', 'htwarehouse',           [Pages\DashboardPage::class, 'render']);
        add_submenu_page('htwarehouse', 'Sản phẩm',       'Sản phẩm',        'manage_options', 'htw-products',          [Pages\ProductsPage::class,  'render']);
        add_submenu_page('htwarehouse', 'Nhà cung cấp',   'Nhà cung cấp',    'manage_options', 'htw-suppliers',         [Pages\SuppliersPage::class, 'render']);
        add_submenu_page('htwarehouse', 'Nhập kho',       'Nhập kho',        'manage_options', 'htw-imports',           [Pages\ImportPage::class,    'render']);
        add_submenu_page('htwarehouse', 'Xuất kho / Bán', 'Xuất kho / Bán',  'manage_options', 'htw-exports',           [Pages\ExportPage::class,    'render']);
        add_submenu_page('htwarehouse', 'Đơn đặt hàng',   'Đơn đặt hàng',    'manage_options', 'htw-purchase-orders',   [Pages\PurchaseOrderPage::class, 'render']);
        add_submenu_page('htwarehouse', 'Báo cáo',        'Báo cáo',         'manage_options', 'htw-reports',           [Pages\ReportsPage::class,   'render']);
    }

    public function enqueue_assets(string $hook): void
    {
        $htw_pages = [
            'toplevel_page_htwarehouse',
            'htwarehouse_page_htw-products',
            'htwarehouse_page_htw-suppliers',
            'htwarehouse_page_htw-imports',
            'htwarehouse_page_htw-exports',
            'htwarehouse_page_htw-reports',
            'htwarehouse_page_htw-purchase-orders',
        ];

        if (! in_array($hook, $htw_pages, true)) {
            return;
        }

        // Chart.js
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js', [], '4.4.2', true);

        // WP Media uploader
        wp_enqueue_media();

        // Plugin assets — load BEFORE Alpine so component factories are registered before Alpine processes x-data.
        // Alpine runs after all deferred scripts, so htw-admin (defer) executes before alpinejs (also defer).
        wp_enqueue_style('htw-admin', HTW_PLUGIN_URL . 'assets/css/htw-admin.css', [], HTW_VERSION);
        wp_enqueue_script('htw-admin', HTW_PLUGIN_URL . 'assets/js/htw-admin.js', ['jquery'], HTW_VERSION, true);

        // Alpine.js — pinned to a specific stable version to avoid unexpected breaking changes
        // from the "latest 3.x" alias. Update this version manually when you need a newer release.
        wp_enqueue_script('alpinejs', 'https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js', [], '3.13.5', true);

        wp_localize_script('htw-admin', 'HTW', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('htw_nonce'),
            'currencySymbol' => 'đ',
        ]);
    }
}
