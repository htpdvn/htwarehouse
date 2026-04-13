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

$products = $wpdb->get_results("SELECT id, name, sku, unit, avg_cost, current_stock FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
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
                                    <button class="htw-btn htw-btn-info htw-btn-sm" @click="openDetail(o.id)">Chi tiết</button>
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
                            <th style="width:30%;">Sản phẩm</th>
                            <th>Tồn kho</th>
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
                            <tr :style="item.product_id && parseNum(item.qty) > parseFloat(item.current_stock || 0) ? 'background:#fff5f5;' : ''">
                                <td>
                                    <select class="htw-select" x-model="item.product_id" @change="onProductChange(item)" x-html="'<option value=\'\'>-- Chọn sản phẩm --</option>' + products.map(function(p){return '<option value=\''+p.id+'\''+(item.product_id==p.id?' selected':'')+'>'+p.name+(p.sku?' ['+p.sku+']':'')+' (tồn: '+parseFloat(p.current_stock)+')</option>';}).join('')">
                                    </select>
                                </td>
                                <td style="text-align:right;">
                                    <span x-show="item.product_id" :style="parseNum(item.qty) > parseFloat(item.current_stock||0) ? 'color:#ef4444;font-weight:700;' : 'color:#22c55e;'" x-text="fmtNum(item.current_stock || 0)"></span>
                                    <span x-show="!item.product_id" style="color:var(--htw-text-muted);">—</span>
                                </td>
                                <td>
                                    <input class="htw-input" type="text"
                                           :value="fmtNum(item.qty)"
                                           @input="item.qty = parseNum($event.target.value)"
                                           @blur="$event.target.value = fmtNum(item.qty)"
                                           :style="item.product_id && parseNum(item.qty) > parseFloat(item.current_stock||0) ? 'border-color:#ef4444;text-align:right;' : 'text-align:right;'"
                                           placeholder="0">
                                    <div x-show="item.product_id && parseNum(item.qty) > parseFloat(item.current_stock||0)" style="color:#ef4444;font-size:.75rem;white-space:nowrap;">⚠ Vượt tồn kho!</div>
                                </td>
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
                            <td colspan="3" style="padding:10px 12px;">
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

    <!-- Order Detail Modal (confirmed orders) -->
    <div class="htw-modal-overlay" x-show="detailModal" x-cloak @click.self="detailModal=false">
        <div class="htw-modal" style="max-width:700px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-clipboard"></span>
                <span>Chi tiết đơn hàng</span>
                <span x-show="detailOrder" style="font-weight:400;color:var(--htw-text-muted);" x-text="' — ' + (detailOrder.order_code || '')"></span>
            </div>

            <template x-if="detailOrder">
                <div>
                    <!-- Summary badges -->
                    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                        <span :class="'htw-badge htw-badge-' + detailOrder.channel" x-text="channelLabel(detailOrder.channel)"></span>
                        <span class="htw-badge htw-badge-confirmed">Đã xác nhận</span>
                        <span style="color:var(--htw-text-muted);font-size:.8rem;align-self:center;" x-text="fmtDate(detailOrder.order_date)"></span>
                        <span x-show="detailOrder.customer_name" style="color:var(--htw-text-muted);font-size:.8rem;align-self:center;" x-text="'• Khách: ' + detailOrder.customer_name"></span>
                    </div>

                    <!-- Financial summary -->
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                        <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                            <div style="color:var(--htw-text-muted);font-size:.75rem;margin-bottom:4px;">DOANH THU</div>
                            <div style="font-weight:700;color:var(--htw-primary);font-size:1.1rem;" x-text="fmt(detailOrder.total_revenue)"></div>
                        </div>
                        <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                            <div style="color:var(--htw-text-muted);font-size:.75rem;margin-bottom:4px;">GIÁ VỐN</div>
                            <div style="font-weight:700;color:#64748b;font-size:1.1rem;" x-text="fmt(detailOrder.total_cogs)"></div>
                        </div>
                        <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                            <div style="color:var(--htw-text-muted);font-size:.75rem;margin-bottom:4px;">LỢI NHUẬN</div>
                            <div style="font-weight:700;font-size:1.1rem;"
                                 :style="{color: parseFloat(detailOrder.total_profit) >= 0 ? '#22c55e' : '#ef4444'}"
                                 x-text="fmt(detailOrder.total_profit)"></div>
                        </div>
                    </div>

                    <!-- Items table -->
                    <div style="font-weight:600;margin-bottom:8px;">Sản phẩm đã bán</div>
                    <div class="htw-items-table-wrap" style="margin-bottom:14px;">
                        <table class="htw-table htw-items-table">
                            <thead>
                                <tr>
                                    <th>Sản phẩm</th>
                                    <th style="text-align:right;">SL</th>
                                    <th style="text-align:right;">Giá vốn</th>
                                    <th style="text-align:right;">Giá bán</th>
                                    <th style="text-align:right;">Doanh thu</th>
                                    <th style="text-align:right;">Lợi nhuận</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in detailOrder.items" :key="item.id">
                                    <tr>
                                        <td x-text="item.product_name || '—'"></td>
                                        <td style="text-align:right;" x-text="fmtNum(item.qty)"></td>
                                        <td style="text-align:right;color:var(--htw-text-muted);" x-text="fmt(item.cogs_per_unit)"></td>
                                        <td style="text-align:right;" x-text="fmt(item.sale_price)"></td>
                                        <td style="text-align:right;font-weight:600;" x-text="fmt(item.qty * item.sale_price)"></td>
                                        <td style="text-align:right;font-weight:600;"
                                            :style="{color: parseFloat(item.profit) >= 0 ? '#22c55e' : '#ef4444'}"
                                            x-text="fmt(item.profit)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Notes -->
                    <div x-show="detailOrder.notes" style="margin-bottom:16px;">
                        <div style="font-weight:600;margin-bottom:4px;">Ghi chú</div>
                        <div style="color:var(--htw-text-muted);font-size:.85rem;background:#f8f9fa;padding:10px;border-radius:6px;" x-text="detailOrder.notes"></div>
                    </div>
                </div>
            </template>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-primary" @click="detailModal=false">Đóng</button>
            </div>
        </div>
    </div>

</div>