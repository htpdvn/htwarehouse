<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Plugin
{

    private static ?Plugin $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        // Ensure DB is up to date on every load (handles plugin updates)
        Database::maybe_upgrade();

        if (is_admin()) {
            Admin::get_instance()->init();
        }

        // Frontend shortcode — also registers the wp_ajax_nopriv AJAX action,
        // so it must run outside of is_admin() too.
        \HTW_Shortcode_Inventory::get_instance()->init();
    }
}
