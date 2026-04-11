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
    }
}
