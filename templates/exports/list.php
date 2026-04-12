<?php
defined('ABSPATH') || exit;
global $wpdb;

$orders = $wpdb->get_results(
    "SELECT o.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_export_items WHERE order_id = o.id) AS item_count
     FROM {$wpdb->prefix}htw_export_orders o
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT 200",
    ARRAY_A
);

foreach ($orders as &$o) {
    $o['items'] = $wpdb->get_results($wpdb->prepare(
        "SELECT ei.*, p.name AS product_name
         FROM {$wpdb->prefix}htw_export_items ei
         JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
         WHERE ei.order_id = %d",
        $o['id']
    ), ARRAY_A);
}
unset($o);

$products = $wpdb->get_results("SELECT id, name, sku, unit, avg_cost FROM {$wpdb->prefix}htw_products WHERE current_stock > 0 ORDER BY name", ARRAY_A);
?>
<script>
window._htwExports  = <?php echo wp_json_encode($orders); ?>;
window._htwProducts = <?php echo wp_json_encode($products); ?>;
</script>
<div class="htw-wrap" x-data="htwExports">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-upload"></span> Xuất kho / Bán hàng</h1>
        <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Tạo đơn mới</button>
    </div>

    <!-- Filter -->
    <div class="htw-search-bar">
        <label class="htw-label" style="white-space:nowrap;">Kênh bán:</label>
        <select class="htw-select" style="max-width:200px;" x-model="filterChannel">
            <option value="">Tất cả</option>
            <option value="facebook">Facebook</option>
            <option value="tiktok">TikTok Shop</option>
            <option value="shopee">Shopee</option>
            <option value="other">Khác</option>
        </select>
        <span style="color:var(--htw-text-muted);font-size:.8rem;" x-text="filtered().length + ' đơn'"></span>
    </div>

    <!-- Table -->
    <div class="htw-table-wrap">
        <table class="htw-table">
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Kênh</th>
                    <th>Ngày</th>
                    <th>Khách hàng</th>
                    <th>Số SP</th>
                    <th>Doanh thu</th>
                    <th>Giá vốn</th>
                    <th>Lợi nhuận</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="o in filtered()" :key="o.id">
                    <tr>
                        <td style="font-weight:600;color:var(--htw-primary);" x-text="o.order_code"></td>
                        <td><span :class="'htw-badge htw-badge-' + o.channel" x-text="channelLabel(o.channel)"></span></td>
                        <td x-text="fmtDate(o.order_date)"></td>
                        <td x-text="o.customer_name || '—'"></td>
                        <td x-text="o.item_count"></td>
                        <td x-text="fmt(o.total_revenue)"></td>
                        <td x-text="fmt(o.total_cogs)"></td>
                        <td :style="{color: parseFloat(o.total_profit)>=0 ? '#22c55e' : '#ef4444'}" x-text="fmt(o.total_profit)"></td>
                        <td><span :class="'htw-badge htw-badge-' + o.status" x-text="o.status === 'confirmed' ? 'Đã xác nhận' : 'Nháp'"></span></td>
                        <td>
                            <div style="display:flex;gap:5px;">
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(o)">Sửa</button>
                                </template>
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirm(o.id)">✓ Xác nhận</button>
                                </template>
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(o.id)">Xoá</button>
                                </template>
                                <template x-if="o.status === 'confirmed'">
                                    <span style="color:var(--htw-text-muted);font-size:.8rem;">— khoá —</span>
                                </template>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="!filtered().length">
                    <tr>
                        <td colspan="10" style="text-align:center;color:var(--htw-text-muted);padding:32px;">Chưa có đơn hàng nào.</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Modal -->
    <div class="htw-modal-overlay" x-show="modal" x-cloak @click.self="modal=false">
        <div class="htw-modal" style="max-width:900px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-upload"></span>
                <span x-text="form.id ? 'Sửa đơn: ' + form.order_code : 'Tạo đơn bán mới'"></span>
            </div>

            <div class="htw-form-grid" style="margin-bottom:20px;">
                <div class="htw-field">
                    <label class="htw-label">Mã đơn (tự sinh nếu để trống)</label>
                    <input class="htw-input" x-model="form.order_code" placeholder="VD: ORD-2024-001">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Kênh bán hàng</label>
                    <select class="htw-select" x-model="form.channel">
                        <option value="facebook">Facebook</option>
                        <option value="tiktok">TikTok Shop</option>
                        <option value="shopee">Shopee</option>
                        <option value="other">Khác</option>
                    </select>
                </div>
                <div class="htw-field">
                    <label class="htw-label">Ngày bán</label>
                    <input class="htw-input" type="date" x-model="form.order_date">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Tên khách hàng (tuỳ chọn)</label>
                    <input class="htw-input" x-model="form.customer_name" placeholder="Tên KH">
                </div>
            </div>

            <!-- Items -->
            <div style="font-weight:600;margin-bottom:8px;">Sản phẩm bán</div>
            <div class="htw-items-table-wrap">
                <table class="htw-table htw-items-table">
                    <thead>
                        <tr>
                            <th style="width:38%;">Sản phẩm</th>
                            <th>SL</th>
                            <th>Giá bán</th>
                            <th>Giá vốn TB</th>
                            <th>Doanh thu</th>
                            <th>Lợi nhuận</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <tr>
                                <td>
                                    <select class="htw-select" x-model="item.product_id" @change="onProductChange(item)" x-html="'<option value=\'\'>-- Chọn sản phẩm --</option>' + products.map(function(p){return '<option value=\''+p.id+'\''+(item.product_id==p.id?' selected':'')+'>'+p.name+(p.sku?' ['+p.sku+']':'')+'</option>';}).join('')">
                                    </select>
                                </td>
                                <td><input class="htw-input" type="text"
                                           :value="fmtNum(item.qty)"
                                           @input="item.qty = parseNum($event.target.value)"
                                           @blur="$event.target.value = fmtNum(item.qty)"
                                           placeholder="0" style="text-align:right;"></td>
                                <td><input class="htw-input" type="text"
                                           :value="fmtNum(item.sale_price)"
                                           @input="item.sale_price = parseNum($event.target.value)"
                                           @blur="$event.target.value = fmtNum(item.sale_price)"
                                           placeholder="0" style="text-align:right;"></td>
                                <td style="color:var(--htw-text-muted);font-size:.8rem;" x-text="fmt(item.avg_cost)"></td>
                                <td x-text="fmt(item.qty * item.sale_price)"></td>
                                <td :style="{color: (item.qty*(item.sale_price-item.avg_cost))>=0 ? '#22c55e':'#ef4444'}"
                                    x-text="fmt(item.qty * (item.sale_price - item.avg_cost))"></td>
                                <td><button type="button" class="htw-btn htw-btn-danger htw-btn-sm" @click="removeItem(idx)">✕</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="padding:10px 12px;">
                                <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="addItem()">+ Thêm dòng</button>
                            </td>
                            <td colspan="2"></td>
                            <td style="font-weight:700;" x-text="fmt(revenue)"></td>
                            <td :style="{fontWeight:700,color:profit>=0?'#22c55e':'#ef4444'}" x-text="fmt(profit)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="htw-field" style="margin-top:14px;">
                <label class="htw-label">Ghi chú</label>
                <textarea class="htw-textarea" x-model="form.notes" style="min-height:60px;"></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="modal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="save()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu đơn nháp'"></span>
                </button>
            </div>
        </div>
    </div>

</div>