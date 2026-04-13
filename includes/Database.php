<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Database
{

    const DB_VERSION_OPTION = 'htw_db_version';
    const DB_VERSION        = '1.2.0';

    public static function install(): void
    {
        self::create_tables();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

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

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_products (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku          VARCHAR(100)    NOT NULL DEFAULT '',
            name         VARCHAR(255)    NOT NULL,
            category     VARCHAR(100)    NOT NULL DEFAULT '',
            unit         VARCHAR(50)     NOT NULL DEFAULT 'cái',
            barcode      VARCHAR(100)    NOT NULL DEFAULT '',
            image_url    TEXT            NOT NULL DEFAULT '',
            product_url  TEXT            NOT NULL DEFAULT '',
            current_stock DECIMAL(15,3)  NOT NULL DEFAULT 0,
            avg_cost     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            notes        TEXT            NOT NULL DEFAULT '',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku (sku),
            KEY category (category)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_import_batches (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_code   VARCHAR(50)     NOT NULL,
            supplier_id  BIGINT UNSIGNED NULL DEFAULT NULL,
            supplier     VARCHAR(255)    NOT NULL DEFAULT '',
            import_date  DATE            NOT NULL,
            shipping_fee    DECIMAL(15,2)   NOT NULL DEFAULT 0,
            tax_fee         DECIMAL(15,2)   NOT NULL DEFAULT 0,
            service_fee     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            inspection_fee  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            packing_fee     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            other_fee       DECIMAL(15,2)   NOT NULL DEFAULT 0,
            notes        TEXT            NOT NULL DEFAULT '',
            status       ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY batch_code (batch_code),
            KEY import_date (import_date),
            KEY status (status),
            KEY supplier_id (supplier_id)
        ) $charset;";

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

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_export_items (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id      BIGINT UNSIGNED NOT NULL,
            product_id    BIGINT UNSIGNED NOT NULL,
            qty           DECIMAL(15,3)   NOT NULL DEFAULT 0,
            sale_price    DECIMAL(15,2)   NOT NULL DEFAULT 0,
            cogs_per_unit DECIMAL(15,4)   NULL DEFAULT NULL,
            revenue       DECIMAL(15,2)   NULL DEFAULT NULL,
            cogs          DECIMAL(15,2)   NULL DEFAULT NULL,
            profit        DECIMAL(15,2)   NULL DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_purchase_orders (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_code          VARCHAR(50)     NOT NULL,
            supplier_id      BIGINT UNSIGNED NULL DEFAULT NULL,
            supplier_name    VARCHAR(255)    NOT NULL DEFAULT '',
            supplier_contact VARCHAR(255)    NOT NULL DEFAULT '',
            supplier_phone   VARCHAR(50)     NOT NULL DEFAULT '',
            supplier_address TEXT            NOT NULL DEFAULT '',
            order_date       DATE            NOT NULL,
            goods_total      DECIMAL(15,2)   NOT NULL DEFAULT 0,
            shipping_fee     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            tax_fee          DECIMAL(15,2)   NOT NULL DEFAULT 0,
            service_fee      DECIMAL(15,2)   NOT NULL DEFAULT 0,
            inspection_fee   DECIMAL(15,2)   NOT NULL DEFAULT 0,
            packing_fee      DECIMAL(15,2)   NOT NULL DEFAULT 0,
            other_fee        DECIMAL(15,2)   NOT NULL DEFAULT 0,
            total_amount     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            amount_paid      DECIMAL(15,2)   NOT NULL DEFAULT 0,
            amount_remaining DECIMAL(15,2)   NOT NULL DEFAULT 0,
            status           ENUM('draft','confirmed','received','paid_off') NOT NULL DEFAULT 'draft',
            import_batch_id  BIGINT UNSIGNED NULL DEFAULT NULL,
            notes            TEXT            NOT NULL DEFAULT '',
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY po_code (po_code),
            KEY order_date (order_date),
            KEY status (status),
            KEY supplier_id (supplier_id),
            KEY import_batch_id (import_batch_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_suppliers (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(255)    NOT NULL,
            contact_name VARCHAR(255)    NOT NULL DEFAULT '',
            phone        VARCHAR(50)     NOT NULL DEFAULT '',
            email        VARCHAR(100)    NOT NULL DEFAULT '',
            address      TEXT            NOT NULL DEFAULT '',
            tax_code     VARCHAR(50)     NOT NULL DEFAULT '',
            notes        TEXT            NOT NULL DEFAULT '',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_purchase_order_items (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id       BIGINT UNSIGNED NOT NULL,
            product_id  BIGINT UNSIGNED NOT NULL,
            qty         DECIMAL(15,3)   NOT NULL DEFAULT 0,
            unit_price  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            line_total  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY po_id (po_id),
            KEY product_id (product_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_po_payments (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id        BIGINT UNSIGNED NOT NULL,
            amount       DECIMAL(15,2)   NOT NULL DEFAULT 0,
            payment_date DATE            NOT NULL,
            note         VARCHAR(255)    NOT NULL DEFAULT '',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY po_id (po_id)
        ) $charset;";

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }
}
