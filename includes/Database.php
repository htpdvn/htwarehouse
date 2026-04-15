<?php

namespace HTWarehouse;

defined('ABSPATH') || exit;

class Database
{

    const DB_VERSION_OPTION = 'htw_db_version';
    const DB_VERSION        = '2.1.0';

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

    /**
     * Run CREATE TABLE statements only for tables that do not yet exist.
     * This is the only safe way to use dbDelta — it must never be called on
     * tables that already exist, as it will attempt structural changes and may
     * corrupt existing data or fail on ENUM differences.
     *
     * After fresh tables are created, all schema upgrades (new columns, indexes,
     * ENUM changes) are applied idempotently via ALTER statements.
     *
     * @param array $sql  CREATE TABLE statements keyed by short table name.
     */
    private static function create_tables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_defs = [
            'htw_products' => "
                CREATE TABLE {$wpdb->prefix}htw_products (
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
                ) $charset",

            'htw_import_batches' => "
                CREATE TABLE {$wpdb->prefix}htw_import_batches (
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
                ) $charset",

            'htw_import_items' => "
                CREATE TABLE {$wpdb->prefix}htw_import_items (
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
                ) $charset",

            'htw_export_orders' => "
                CREATE TABLE {$wpdb->prefix}htw_export_orders (
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
                ) $charset",

            'htw_export_items' => "
                CREATE TABLE {$wpdb->prefix}htw_export_items (
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
                ) $charset",

            'htw_purchase_orders' => "
                CREATE TABLE {$wpdb->prefix}htw_purchase_orders (
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
                ) $charset",

            'htw_suppliers' => "
                CREATE TABLE {$wpdb->prefix}htw_suppliers (
                    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    supplier_code   VARCHAR(50)     NOT NULL DEFAULT '',
                    name            VARCHAR(255)    NOT NULL,
                    contact_name    VARCHAR(255)    NOT NULL DEFAULT '',
                    phone           VARCHAR(50)     NOT NULL DEFAULT '',
                    email           VARCHAR(100)    NOT NULL DEFAULT '',
                    address         TEXT            NOT NULL DEFAULT '',
                    tax_code        VARCHAR(50)     NOT NULL DEFAULT '',
                    notes           TEXT            NOT NULL DEFAULT '',
                    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY supplier_code (supplier_code),
                    KEY name (name)
                ) $charset",

            'htw_purchase_order_items' => "
                CREATE TABLE {$wpdb->prefix}htw_purchase_order_items (
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
                ) $charset",

            'htw_po_payments' => "
                CREATE TABLE {$wpdb->prefix}htw_po_payments (
                    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    po_id        BIGINT UNSIGNED NOT NULL,
                    amount       DECIMAL(15,2)   NOT NULL DEFAULT 0,
                    payment_date DATE            NOT NULL,
                    note         VARCHAR(255)    NOT NULL DEFAULT '',
                    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY po_id (po_id)
                ) $charset",

            'htw_return_orders' => "
                CREATE TABLE {$wpdb->prefix}htw_return_orders (
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
                ) $charset",

            // D2 fix: KEY idx_ri_order_item REMOVED from CREATE TABLE.
            // It is added idempotently via ALTER TABLE after the dbDelta loop.
            // This prevents "Duplicate key name" errors on fresh installs where
            // dbDelta() already creates the index as part of CREATE parsing.
            'htw_return_items' => "
                CREATE TABLE {$wpdb->prefix}htw_return_items (
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
                    KEY export_item_id (export_item_id),
                    KEY product_id (product_id)
                ) $charset",

            'htw_activity_logs' => "
                CREATE TABLE {$wpdb->prefix}htw_activity_logs (
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
                ) $charset",
        ];

        // ── Step 1: Create ONLY tables that do not yet exist ─────────────────────────
        // dbDelta() must NEVER be called on existing tables because it parses the
        // full CREATE statement and may attempt structural changes that fail on ENUM
        // differences or corrupt data. We only call dbDelta() for truly new tables.
        foreach ($table_defs as $short_name => $create_sql) {
            $full_table = $wpdb->prefix . $short_name;
            if ($wpdb->get_var("SHOW TABLES LIKE %s", $full_table) !== $full_table) {
                dbDelta($create_sql);
            }
        }

        // ── Step 2: Apply all schema upgrades idempotently ──────────────────────────

        // 2a. Add ENUM 'partial_return' and 'fully_returned' to export_orders.status.
        // D3 fix: use IGNORE so that if existing values are incompatible (e.g. NULL
        // or invalid string from manual DB edits), MySQL does not abort the entire
        // migration. Rows with invalid values are kept as-is (preserving data).
        // Note: 'other' is intentionally NOT included here — it exists in the
        // CREATE TABLE for fresh installs; old installs with 'other' in their data
        // will have those rows preserved.
        $wpdb->query(
            "ALTER IGNORE TABLE {$wpdb->prefix}htw_export_orders
             MODIFY COLUMN status ENUM('draft','confirmed','partial_return','fully_returned') NOT NULL DEFAULT 'draft'"
        );

        // 2b. Add composite index for get_confirmed_returned_qty() hot path.
        // Idempotent: only runs if index does not exist.
        $idx_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = %s
               AND table_name = %s
               AND index_name = 'idx_ri_order_item'",
            DB_NAME,
            $wpdb->prefix . 'htw_return_items'
        ));
        if ((int) $idx_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}htw_return_items
                 ADD INDEX idx_ri_order_item (return_order_id, export_item_id)"
            );
        }

        // 2c. Add before_data / after_data columns to activity_logs (idempotent).
        $col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s
               AND table_name = %s
               AND column_name = 'before_data'",
            DB_NAME,
            $wpdb->prefix . 'htw_activity_logs'
        ));
        if ((int) $col_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}htw_activity_logs
                 ADD COLUMN before_data MEDIUMTEXT NULL DEFAULT NULL AFTER summary,
                 ADD COLUMN after_data  MEDIUMTEXT NULL DEFAULT NULL AFTER before_data"
            );
        }

        // 2d. Add supplier_code column + unique index to htw_suppliers (idempotent).
        $sc_col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s
               AND table_name = %s
               AND column_name = 'supplier_code'",
            DB_NAME,
            $wpdb->prefix . 'htw_suppliers'
        ));
        if ((int) $sc_col_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}htw_suppliers
                 ADD COLUMN supplier_code VARCHAR(50) NOT NULL DEFAULT '' AFTER id"
            );
        }

        $sc_idx_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = %s
               AND table_name = %s
               AND index_name = 'supplier_code'",
            DB_NAME,
            $wpdb->prefix . 'htw_suppliers'
        ));
        if ((int) $sc_idx_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}htw_suppliers ADD UNIQUE KEY supplier_code (supplier_code)"
            );
        }

        // 2e. Back-fill supplier_code for existing suppliers that have none.
        $wpdb->query(
            "UPDATE {$wpdb->prefix}htw_suppliers
             SET supplier_code = CONCAT('NCC-', LPAD(id, 4, '0'))
             WHERE supplier_code = '' OR supplier_code IS NULL"
        );
    }
}
