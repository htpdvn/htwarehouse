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

        // AJAX handlers
        add_action('wp_ajax_htw_save_product',       [Pages\ProductsPage::class, 'ajax_save']);
        add_action('wp_ajax_htw_delete_product',     [Pages\ProductsPage::class, 'ajax_delete']);
        add_action('wp_ajax_htw_save_import',        [Pages\ImportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_import',      [Pages\ImportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_import',     [Pages\ImportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_save_export',        [Pages\ExportPage::class,   'ajax_save']);
        add_action('wp_ajax_htw_delete_export',      [Pages\ExportPage::class,   'ajax_delete']);
        add_action('wp_ajax_htw_confirm_export',     [Pages\ExportPage::class,   'ajax_confirm']);
        add_action('wp_ajax_htw_report_data',        [Pages\ReportsPage::class,  'ajax_data']);
        add_action('wp_ajax_htw_dashboard_data',     [Pages\DashboardPage::class, 'ajax_data']);
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
        add_submenu_page('htwarehouse', 'Nhập kho',       'Nhập kho',        'manage_options', 'htw-imports',           [Pages\ImportPage::class,    'render']);
        add_submenu_page('htwarehouse', 'Xuất kho / Bán', 'Xuất kho / Bán',  'manage_options', 'htw-exports',           [Pages\ExportPage::class,    'render']);
        add_submenu_page('htwarehouse', 'Báo cáo',        'Báo cáo',         'manage_options', 'htw-reports',           [Pages\ReportsPage::class,   'render']);
    }

    public function enqueue_assets(string $hook): void
    {
        $htw_pages = [
            'toplevel_page_htwarehouse',
            'htwarehouse_page_htw-products',
            'htwarehouse_page_htw-imports',
            'htwarehouse_page_htw-exports',
            'htwarehouse_page_htw-reports',
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

        // Alpine.js — listed LAST so it runs after htw-admin.js has registered Alpine.data() factories.
        wp_enqueue_script('alpinejs', 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js', [], '3.0', true);

        wp_localize_script('htw-admin', 'HTW', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('htw_nonce'),
            'currencySymbol' => '₫',
        ]);
    }
}
