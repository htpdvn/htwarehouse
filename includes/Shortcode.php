<?php
defined('ABSPATH') || exit;

/**
 * HTWarehouse — Frontend Shortcode: [htw_inventory]
 *
 * Displays the full in-stock product list (image, SKU, name, stock qty,
 * suggested price) behind a password gate.
 * Password: "hoangtd135"  (checked client-side via sessionStorage so the
 * page never reloads; the actual hash comparison happens in JS only —
 * acceptable for a lightweight access gate that is NOT a security boundary).
 *
 * Usage:   [htw_inventory]
 *
 * @package HTWarehouse
 */
class HTW_Shortcode_Inventory
{
    /** bcrypt/SHA-256 is overkill for a display-only gate; we store a simple
     *  SHA-256 hex of the password so it is not visible in HTML source. */
    const PASSWORD_HASH = '8afc2e5c176e1a8b1821d7bf6561f8427e4103e07a0ed1da20e7567b952f65d2';

    private static ?HTW_Shortcode_Inventory $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_shortcode('htw_inventory', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        // AJAX endpoint — public (no_priv) so non-logged-in visitors can fetch data
        add_action('wp_ajax_htw_sc_inventory',        [$this, 'ajax_inventory']);
        add_action('wp_ajax_nopriv_htw_sc_inventory', [$this, 'ajax_inventory']);
    }

    /** Enqueue assets only when the shortcode is actually on the page. */
    public function maybe_enqueue_assets(): void
    {
        global $post;
        if (! $post || ! has_shortcode($post->post_content, 'htw_inventory')) {
            return;
        }
        $this->enqueue_assets();
    }

    private function enqueue_assets(): void
    {
        wp_enqueue_style(
            'htw-shortcode',
            HTW_PLUGIN_URL . 'assets/css/htw-shortcode.css',
            [],
            HTW_VERSION
        );

        // Inline [x-cloak] rule — must exist before Alpine removes it
        wp_add_inline_style('htw-shortcode', '[x-cloak] { display: none !important; }');

        // Use the already-bundled Alpine.js
        wp_enqueue_script(
            'htw-sc-alpine',
            HTW_PLUGIN_URL . 'assets/js/alpinejs.min.js',
            [],
            '3.13.5',
            true
        );

        // Register the Alpine component as inline script BEFORE Alpine runs.
        // wp_add_inline_script with 'before' position outputs the script
        // immediately before the alpinejs tag, so alpine:init fires after
        // our Alpine.data() call is already registered.
        wp_add_inline_script('htw-sc-alpine', $this->build_inline_script(), 'before');
    }

    /** Output the shortcode markup. */
    public function render(array $atts = []): string
    {
        ob_start();
        include HTW_PLUGIN_DIR . 'templates/shortcode/inventory.php';
        return ob_get_clean();
    }

    /** AJAX: return products JSON.
     *  No authentication — data is inventory info that the site owner is
     *  intentionally sharing behind only a soft password gate. */
    public function ajax_inventory(): void
    {
        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT id, sku, name, category, image_url, current_stock, suggested_price
             FROM {$wpdb->prefix}htw_products
             WHERE current_stock > 0
             ORDER BY name ASC",
            ARRAY_A
        );

        // Cast numeric fields
        foreach ($products as &$p) {
            $p['current_stock']  = (float) $p['current_stock'];
            $p['suggested_price'] = (float) $p['suggested_price'];
        }
        unset($p);

        wp_send_json_success($products);
    }

    /** Build the Alpine component JS string for wp_add_inline_script(). */
    public function build_inline_script(): string
    {
        $ajax_url      = esc_url(admin_url('admin-ajax.php'));
        $password_hash = self::PASSWORD_HASH;

        // Return pure JS — no <script> tags (WP adds them automatically).
        return <<<JS
/* ─── HTWarehouse Inventory Shortcode ─── */
(function () {
    'use strict';

    async function sha256hex(str) {
        const buf  = new TextEncoder().encode(str);
        const hash = await crypto.subtle.digest('SHA-256', buf);
        return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function fmtVnd(v) {
        const n = parseFloat(v) || 0;
        if (n === 0) return '\u2014';
        return n.toLocaleString('vi-VN') + ' \u0111';
    }

    const SESSION_KEY  = 'htw_sc_auth';
    const CORRECT_HASH = '{$password_hash}';
    const AJAX_URL     = '{$ajax_url}';

    document.addEventListener('alpine:init', () => {
        Alpine.data('htwInventory', () => ({
            authed:      sessionStorage.getItem(SESSION_KEY) === '1',
            password:    '',
            showPw:      false,
            gateError:   '',
            gateLoading: false,
            products:    [],
            loading:     false,
            search:      '',

            get totalProducts() { return this.products.length; },
            get totalStock()    { return this.products.reduce((s, p) => s + p.current_stock, 0); },
            get withPrice()     { return this.products.filter(p => p.suggested_price > 0).length; },

            get filtered() {
                const q = this.search.trim().toLowerCase();
                if (!q) return this.products;
                return this.products.filter(p =>
                    (p.name     || '').toLowerCase().includes(q) ||
                    (p.sku      || '').toLowerCase().includes(q) ||
                    (p.category || '').toLowerCase().includes(q)
                );
            },

            async init() {
                if (this.authed) await this.fetchProducts();
            },

            async submitPassword() {
                if (!this.password) { this.gateError = 'Vui l\u00f2ng nh\u1eadp m\u1eadt kh\u1ea9u.'; return; }
                this.gateLoading = true;
                this.gateError   = '';
                try {
                    const hash = await sha256hex(this.password);
                    if (hash === CORRECT_HASH) {
                        sessionStorage.setItem(SESSION_KEY, '1');
                        this.authed   = true;
                        this.password = '';
                        await this.fetchProducts();
                    } else {
                        this.gateError = 'M\u1eadt kh\u1ea9u kh\u00f4ng \u0111\u00fang. Vui l\u00f2ng th\u1eed l\u1ea1i.';
                    }
                } catch(e) {
                    this.gateError = '\u0110\u00e3 x\u1ea3y ra l\u1ed7i. Vui l\u00f2ng th\u1eed l\u1ea1i.';
                } finally {
                    this.gateLoading = false;
                }
            },

            logout() {
                sessionStorage.removeItem(SESSION_KEY);
                this.authed = false; this.products = []; this.search = '';
            },

            async fetchProducts() {
                this.loading = true;
                try {
                    const fd = new FormData();
                    fd.append('action', 'htw_sc_inventory');
                    const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) this.products = json.data;
                } finally {
                    this.loading = false;
                }
            },

            fmtPrice: fmtVnd,
            fmtNum(v, dec = 0) {
                return parseFloat(v).toLocaleString('vi-VN', { maximumFractionDigits: dec });
            },
        }));
    });
})();
JS;
    }
}
