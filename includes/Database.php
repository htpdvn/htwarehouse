<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Database
{

    const DB_VERSION_OPTION = 'htw_db_version';
    const DB_VERSION        = '2.0.0';

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
            status         ENUM('draft','confirmed','partial_return','fully_returned') NOT NULL DEFAULT 'draft',
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

        // ── Return orders (hàng trả lại) ───────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_return_orders (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            return_code      VARCHAR(50)     NOT NULL,
            export_order_id  BIGINT UNSIGNED NOT NULL,
            return_date      DATE            NOT NULL,
            reason           VARCHAR(255)    NOT NULL DEFAULT '',
            notes            TEXT            NOT NULL DEFAULT '',
            total_qty        DECIMAL(15,3)   NOT NULL DEFAULT 0,
            total_refund     DECIMAL(15,2)   NOT NULL DEFAULT 0,
            total_cogs_back  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            status           ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY return_code (return_code),
            KEY export_order_id (export_order_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_return_items (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            return_order_id  BIGINT UNSIGNED NOT NULL,
            export_item_id   BIGINT UNSIGNED NOT NULL,
            product_id       BIGINT UNSIGNED NOT NULL,
            qty_returned     DECIMAL(15,3)   NOT NULL DEFAULT 0,
            sale_price       DECIMAL(15,2)   NOT NULL DEFAULT 0,
            cogs_per_unit    DECIMAL(15,4)   NOT NULL DEFAULT 0,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY return_order_id (return_order_id),
            KEY idx_ri_order_item (return_order_id, export_item_id),
            KEY export_item_id (export_item_id),
            KEY product_id (product_id)
        ) $charset;";

        // ── Activity log (audit trail) ──────────────────────────────────────────
        $sql[] = "CREATE TABLE {$wpdb->prefix}htw_activity_logs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_login  VARCHAR(60)     NOT NULL DEFAULT '',
            action      VARCHAR(50)     NOT NULL DEFAULT '',
            object_type VARCHAR(50)     NOT NULL DEFAULT '',
            object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            object_code VARCHAR(100)    NOT NULL DEFAULT '',
            summary     VARCHAR(500)    NOT NULL DEFAULT '',
            before_data MEDIUMTEXT      NULL DEFAULT NULL,
            after_data  MEDIUMTEXT      NULL DEFAULT NULL,
            ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY object_type_id (object_type, object_id)
        ) $charset;";

        foreach ($sql as $query) {
            dbDelta($query);
        }

        // dbDelta cannot change ENUM values — run ALTER manually for existing installs
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}htw_export_orders
             MODIFY COLUMN status ENUM('draft','confirmed','partial_return','fully_returned') NOT NULL DEFAULT 'draft'"
        );

        // Add composite index for get_confirmed_returned_qty() hot path (idempotent: silently skips if exists)
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}htw_return_items
             ADD INDEX idx_ri_order_item (return_order_id, export_item_id)"
        );

        // Add before_data / after_data snapshot columns to activity log (idempotent)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}htw_activity_logs LIKE 'before_data'");
        if (empty($columns)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}htw_activity_logs
                 ADD COLUMN before_data MEDIUMTEXT NULL DEFAULT NULL AFTER summary,
                 ADD COLUMN after_data  MEDIUMTEXT NULL DEFAULT NULL AFTER before_data"
            );
        }
    }
}
