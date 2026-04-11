<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Database
{

    const DB_VERSION_OPTION = 'htw_db_version';
    const DB_VERSION        = '1.0.0';

    /**
     * Run on plugin activation.
     */
    public static function install(): void
    {
        self::create_tables();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Upgrade check on every load.
     */
    public static function maybe_upgrade(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');
        if (version_compare($installed, self::DB_VERSION, '<')) {
            self::create_tables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Products ─────────────────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_products (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku          VARCHAR(100)    NOT NULL DEFAULT '',
            name         VARCHAR(255)    NOT NULL,
            category     VARCHAR(100)    NOT NULL DEFAULT '',
            unit         VARCHAR(50)     NOT NULL DEFAULT 'cái',
            barcode      VARCHAR(100)    NOT NULL DEFAULT '',
            image_url    TEXT            NOT NULL DEFAULT '',
            current_stock DECIMAL(15,3)  NOT NULL DEFAULT 0,
            avg_cost     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            notes        TEXT            NOT NULL DEFAULT '',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku (sku),
            KEY category (category)
        ) $charset;";

        // ── Import Batches ────────────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_import_batches (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_code   VARCHAR(50)     NOT NULL,
            supplier     VARCHAR(255)    NOT NULL DEFAULT '',
            import_date  DATE            NOT NULL,
            shipping_fee DECIMAL(15,2)   NOT NULL DEFAULT 0,
            tax_fee      DECIMAL(15,2)   NOT NULL DEFAULT 0,
            other_fee    DECIMAL(15,2)   NOT NULL DEFAULT 0,
            notes        TEXT            NOT NULL DEFAULT '',
            status       ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY batch_code (batch_code),
            KEY import_date (import_date),
            KEY status (status)
        ) $charset;";

        // ── Import Batch Items ────────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_import_items (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id                BIGINT UNSIGNED NOT NULL,
            product_id              BIGINT UNSIGNED NOT NULL,
            qty                     DECIMAL(15,3)   NOT NULL DEFAULT 0,
            unit_price              DECIMAL(15,2)   NOT NULL DEFAULT 0,
            allocated_cost_per_unit DECIMAL(15,4)   NOT NULL DEFAULT 0,
            total_cost              DECIMAL(15,2)   NOT NULL DEFAULT 0,
            created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY product_id (product_id)
        ) $charset;";

        // ── Export Orders ─────────────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_export_orders (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_code     VARCHAR(50)     NOT NULL,
            channel        ENUM('facebook','tiktok','shopee','other') NOT NULL DEFAULT 'other',
            order_date     DATE            NOT NULL,
            customer_name  VARCHAR(255)    NOT NULL DEFAULT '',
            notes          TEXT            NOT NULL DEFAULT '',
            total_revenue  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            total_cogs     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            total_profit   DECIMAL(15,2)   NOT NULL DEFAULT 0,
            status         ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_code (order_code),
            KEY order_date (order_date),
            KEY channel (channel),
            KEY status (status)
        ) $charset;";

        // ── Export Order Items ────────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_export_items (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id      BIGINT UNSIGNED NOT NULL,
            product_id    BIGINT UNSIGNED NOT NULL,
            qty           DECIMAL(15,3)   NOT NULL DEFAULT 0,
            sale_price    DECIMAL(15,2)   NOT NULL DEFAULT 0,
            cogs_per_unit DECIMAL(15,4)   NOT NULL DEFAULT 0,
            revenue       DECIMAL(15,2)   NOT NULL DEFAULT 0,
            cogs          DECIMAL(15,2)   NOT NULL DEFAULT 0,
            profit        DECIMAL(15,2)   NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) $charset;";

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }
}
