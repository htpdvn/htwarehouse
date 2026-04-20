<?php
defined('ABSPATH') || exit;
global $wpdb;

$orders = $wpdb->get_results(
    "SELECT o.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_export_items WHERE order_id = o.id) AS item_count,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_return_orders WHERE export_order_id = o.id AND status = 'confirmed') AS return_count
     FROM {$wpdb->prefix}htw_export_orders o
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT 200",
    ARRAY_A
);

foreach ($orders as &$o) {
    $o['items'] = $wpdb->get_results($wpdb->prepare(
        "SELECT ei.*, p.name AS product_name
         FROM {$wpdb->prefix}htw_export_items ei
         LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ei.product_id
         WHERE ei.order_id = %d",
        $o['id']
    ), ARRAY_A);
}
unset($o);

$products = $wpdb->get_results("SELECT id, name, sku, unit, avg_cost, current_stock, suggested_price FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
?>
<script>
    window._htwExports = <?php echo wp_json_encode($orders); ?>;
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
                    <th>Số mã SP</th>
                    <th>Doanh thu</th>
                    <th>Giá vốn</th>
                    <th>Lợi nhuận</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="o in filtered()" :key="o.id">
                    <tr :style="o.status === 'fully_returned' ? 'opacity:.65;' : ''">
                        <td style="font-weight:600;color:var(--htw-primary);" x-text="o.order_code"></td>
                        <td><span :class="'htw-badge htw-badge-' + o.channel" x-text="channelLabel(o.channel)"></span></td>
                        <td x-text="fmtDate(o.order_date)"></td>
                        <td x-text="o.customer_name || '—'"></td>
                        <td x-text="o.item_count"></td>
                        <td x-text="fmt(o.total_revenue)"></td>
                        <td x-text="fmt(o.total_cogs)"></td>
                        <td :style="{color: parseFloat(o.total_profit)>=0 ? '#22c55e' : '#ef4444'}" x-text="fmt(o.total_profit)"></td>
                        <td><span :class="'htw-badge htw-badge-' + o.status" x-text="statusLabel(o.status)"></span></td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(o)">Sửa</button>
                                </template>
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirm(o.id)">✓ Xác nhận</button>
                                </template>
                                <template x-if="o.status === 'draft'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(o.id)">Xoá</button>
                                </template>
                                <template x-if="o.status !== 'draft'">
                                    <button class="htw-btn htw-btn-info htw-btn-sm" @click="openDetail(o.id)">Chi tiết</button>
                                </template>
                                <template x-if="o.status === 'confirmed' || o.status === 'partial_return'">
                                    <button class="htw-btn htw-btn-warning htw-btn-sm" @click="openReturn(o)">↩ Trả hàng</button>
                                </template>
                                <template x-if="o.status === 'fully_returned'">
                                    <span style="color:var(--htw-text-muted);font-size:.8rem;">— đã trả toàn bộ —</span>
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

            <div class="htw-form-grid htw-export-info-grid">
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
                    <thead class="htw-items-thead">
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
                            <tr class="htw-item-row" :style="item.product_id && parseNum(item.qty) > parseFloat(item.current_stock || 0) ? 'background:#fff5f5;' : ''">
                                <td data-label="Sản phẩm">
                                    <select class="htw-select" x-model="item.product_id" @change="onProductChange(item)" x-html="productOptions(item.product_id)">
                                    </select>
                                </td>
                                <td data-label="Tồn kho" style="text-align:right;">
                                    <span x-show="item.product_id" :style="parseNum(item.qty) > parseFloat(item.current_stock||0) ? 'color:#ef4444;font-weight:700;' : 'color:#22c55e;'" x-text="fmtNum(item.current_stock || 0)"></span>
                                    <span x-show="!item.product_id" style="color:var(--htw-text-muted);">—</span>
                                </td>
                                <td data-label="Số lượng">
                                    <input class="htw-input" type="text"
                                        x-model="item.qty"
                                        @blur="item.qty = parseNum(String(item.qty || '')); $event.target.value = fmtNum(item.qty)"
                                        :style="item.product_id && parseNum(String(item.qty||'')) > parseFloat(item.current_stock||0) ? 'border-color:#ef4444;text-align:right;' : 'text-align:right;'"
                                        placeholder="0">
                                    <div x-show="item.product_id && parseNum(String(item.qty||'')) > parseFloat(item.current_stock||0)" style="color:#ef4444;font-size:.75rem;white-space:nowrap;">⚠ Vượt tồn kho!</div>
                                </td>
                                <td data-label="Giá bán">
                                    <input class="htw-input" type="text"
                                        x-model="item.sale_price"
                                        @blur="var el = $event.target; el.value = fmtNum(parseFloat(item.sale_price) || 0)"
                                        placeholder="0" style="text-align:right;">
                                    <div x-show="item.product_id && parseFloat(item.suggested_price) > 0"
                                        style="font-size:.72rem;color:var(--htw-warning,#f59e0b);margin-top:3px;white-space:nowrap;cursor:pointer;"
                                        @click="item.sale_price = parseFloat(item.suggested_price)"
                                        :title="'Nhấn để điền: ' + fmt(item.suggested_price)">
                                        💡 Đề xuất: <span x-text="fmt(item.suggested_price)"></span>
                                    </div>
                                </td>
                                <td data-label="Giá vốn" style="color:var(--htw-text-muted);font-size:.8rem;" x-text="fmt(item.avg_cost)"></td>
                                <td data-label="Doanh thu" x-text="fmt(item.qty * item.sale_price)"></td>
                                <td data-label="Lợi nhuận" :style="{color: (item.qty*(item.sale_price-item.avg_cost))>=0 ? '#22c55e':'#ef4444'}"
                                    x-text="fmt(item.qty * (item.sale_price - item.avg_cost))"></td>
                                <td data-label=" " class="htw-item-del-cell"><button type="button" class="htw-btn htw-btn-danger htw-btn-sm" @click="removeItem(idx)">✕ Xoá</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="htw-items-footer-row">
                            <td colspan="3" style="padding:10px 12px;">
                                <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="addItem()">+ Thêm dòng</button>
                            </td>
                            <td colspan="2"></td>
                            <td class="htw-items-total-cell" style="font-weight:700;" x-text="fmt(revenue)"></td>
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
        <div class="htw-modal" style="max-width:760px;" @click.stop>
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
                        <span :class="'htw-badge htw-badge-' + detailOrder.status" x-text="statusLabel(detailOrder.status)"></span>
                        <span style="color:var(--htw-text-muted);font-size:.8rem;align-self:center;" x-text="fmtDate(detailOrder.order_date)"></span>
                        <span x-show="detailOrder.customer_name" style="color:var(--htw-text-muted);font-size:.8rem;align-self:center;" x-text="'• Khách: ' + detailOrder.customer_name"></span>
                    </div>

                    <!-- Financial summary -->
                    <div class="htw-export-summary-grid">
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
                            <thead class="htw-items-thead">
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
                                    <tr class="htw-item-row">
                                        <td data-label="Sản phẩm" x-text="item.product_name || '—'"></td>
                                        <td data-label="Số lượng" style="text-align:right;" x-text="fmtNum(item.qty)"></td>
                                        <td data-label="Giá vốn" style="text-align:right;color:var(--htw-text-muted);" x-text="fmt(item.cogs_per_unit)"></td>
                                        <td data-label="Giá bán" style="text-align:right;" x-text="fmt(item.sale_price)"></td>
                                        <td data-label="Doanh thu" style="text-align:right;font-weight:600;" x-text="fmt(item.qty * item.sale_price)"></td>
                                        <td data-label="Lợi nhuận" style="text-align:right;font-weight:600;"
                                            :style="{color: parseFloat(item.profit) >= 0 ? '#22c55e' : '#ef4444'}"
                                            x-text="fmt(item.profit)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Return history -->
                    <template x-if="detailReturns.length > 0">
                        <div>
                            <div style="font-weight:600;margin-bottom:8px;color:#f59e0b;">
                                <span class="dashicons dashicons-undo" style="vertical-align:middle;"></span>
                                Lịch sử trả hàng (<span x-text="detailReturns.length"></span> đơn)
                            </div>
                            <template x-for="ro in detailReturns" :key="ro.id">
                                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-bottom:10px;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
                                        <div>
                                            <span style="font-weight:700;color:#92400e;font-size:1rem;" x-text="ro.return_code"></span>
                                            <span style="color:var(--htw-text-muted);font-size:.8rem;" x-text="' • ' + fmtDate(ro.return_date)"></span>
                                            <span x-show="ro.reason" style="color:var(--htw-text-muted);font-size:.8rem;" x-text="' • ' + ro.reason"></span>
                                        </div>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <span :class="'htw-badge htw-badge-' + (ro.status === 'confirmed' ? 'confirmed' : 'draft')"
                                                x-text="ro.status === 'confirmed' ? 'Đã xác nhận' : 'Chờ xác nhận'"></span>
                                            <template x-if="ro.status === 'confirmed'">
                                                <button class="htw-btn htw-btn-ghost htw-btn-sm"
                                                    style="padding:2px 8px;font-size:.75rem;"
                                                    @click="printReturn(ro, detailOrder)"
                                                    title="In phiếu trả hàng">
                                                    🖨 In phiếu
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:16px;font-size:.85rem;flex-wrap:wrap;">
                                        <span>SL trả: <strong x-text="fmtNum(ro.total_qty)"></strong></span>
                                        <span>Doanh thu hoàn: <strong style="color:#ef4444;" x-text="fmt(ro.total_refund)"></strong></span>
                                        <span>Giá vốn hoàn kho: <strong style="color:#22c55e;" x-text="fmt(ro.total_cogs_back)"></strong></span>
                                    </div>
                                    <template x-if="ro.items && ro.items.length">
                                        <div style="margin-top:8px;">
                                            <table style="width:100%;font-size:.8rem;border-collapse:collapse;">
                                                <thead>
                                                    <tr style="background:#fef3c7;">
                                                        <th style="text-align:left;padding:4px 8px;">Sản phẩm</th>
                                                        <th style="text-align:right;padding:4px 8px;">SL trả</th>
                                                        <th style="text-align:right;padding:4px 8px;">Giá bán</th>
                                                        <th style="text-align:right;padding:4px 8px;">Giá vốn</th>
                                                        <th style="text-align:right;padding:4px 8px;">Thành tiền hoàn</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="ri in ro.items" :key="ri.id">
                                                        <tr style="border-top:1px solid #fde68a;">
                                                            <td style="padding:4px 8px;" x-text="ri.product_name || '—'"></td>
                                                            <td style="text-align:right;padding:4px 8px;" x-text="fmtNum(ri.qty_returned)"></td>
                                                            <td style="text-align:right;padding:4px 8px;" x-text="fmt(ri.sale_price)"></td>
                                                            <td style="text-align:right;padding:4px 8px;color:var(--htw-text-muted);" x-text="fmt(ri.cogs_per_unit)"></td>
                                                            <td style="text-align:right;padding:4px 8px;font-weight:600;color:#ef4444;"
                                                                x-text="fmt(ri.qty_returned * ri.sale_price)"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </template>
                                    <template x-if="ro.notes">
                                        <div style="margin-top:8px;font-size:.8rem;color:var(--htw-text-muted);font-style:italic;" x-text="'Ghi chú: ' + ro.notes"></div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

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

    <!-- ── Return Order Modal ─────────────────────────────────────────────── -->
    <div class="htw-modal-overlay" x-show="returnModal" x-cloak @click.self="returnModal=false">
        <div class="htw-modal" style="max-width:820px;" @click.stop>
            <div class="htw-modal-title" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:8px 8px 0 0;padding:16px 20px;margin:-30px -32px 20px;">
                <span class="dashicons dashicons-undo" style="font-size:20px;width:20px;height:20px;"></span>
                <span>Tạo đơn trả hàng</span>
                <span x-show="returnForm.export_order_code" style="font-weight:400;font-size:.9rem;" x-text="' — ' + returnForm.export_order_code"></span>
            </div>

            <!-- Return info -->
            <div class="htw-form-grid htw-return-info-grid">
                <div class="htw-field">
                    <label class="htw-label">Ngày trả hàng</label>
                    <input class="htw-input" type="date" x-model="returnForm.return_date">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Lý do trả hàng</label>
                    <input class="htw-input" x-model="returnForm.reason" placeholder="VD: Hàng lỗi, sai size, đổi ý...">
                </div>
            </div>

            <!-- Return items table -->
            <div style="font-weight:600;margin-bottom:8px;">Chọn sản phẩm và số lượng trả</div>
            <div class="htw-items-table-wrap" style="margin-bottom:16px;">
                <table class="htw-table htw-items-table">
                    <colgroup>
                        <col style="width:32px;">
                        <col>
                        <col style="width:7%;">
                        <col style="width:8%;">
                        <col style="width:8%;">
                        <col style="width:11%;">
                        <col style="width:13%;">
                        <col style="width:13%;">
                    </colgroup>
                    <thead class="htw-items-thead">
                        <tr>
                            <th style="text-align:center;">
                                <input type="checkbox" @change="toggleAllReturn($event)" title="Chọn tất cả">
                            </th>
                            <th>Sản phẩm</th>
                            <th style="text-align:right;white-space:nowrap;">SL bán</th>
                            <th style="text-align:right;white-space:nowrap;">Đã trả</th>
                            <th style="text-align:right;white-space:nowrap;">Có thể trả</th>
                            <th style="text-align:right;white-space:nowrap;">SL trả</th>
                            <th style="text-align:right;white-space:nowrap;">Giá bán</th>
                            <th style="text-align:right;white-space:nowrap;">Giá vốn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(ri, idx) in returnForm.items" :key="ri.export_item_id">
                            <tr :style="ri.selected && parseFloat(ri.qty_returned) > parseFloat(ri.max_returnable) ? 'background:#fff5f5;' : (ri.selected ? 'background:#f0fdf4;' : '')">
                                <td style="text-align:center;">
                                    <input type="checkbox" x-model="ri.selected">
                                </td>
                                <td x-text="ri.product_name"></td>
                                <td style="text-align:right;" x-text="fmtNum(ri.qty_sold)"></td>
                                <td style="text-align:right;color:var(--htw-text-muted);" x-text="fmtNum(ri.qty_already_returned)"></td>
                                <td style="text-align:right;" :style="{color: parseFloat(ri.max_returnable) > 0 ? '#22c55e' : '#ef4444'}" x-text="fmtNum(ri.max_returnable)"></td>
                                <td>
                                    <template x-if="ri.selected">
                                        <div>
                                            <input class="htw-input" type="text" style="text-align:right;min-width:80px;"
                                                :value="fmtNum(ri.qty_returned)"
                                                @input="ri.qty_returned = parseNum($event.target.value)"
                                                @blur="$event.target.value = fmtNum(ri.qty_returned)"
                                                :max="ri.max_returnable"
                                                placeholder="0">
                                            <div x-show="parseFloat(ri.qty_returned) > parseFloat(ri.max_returnable)" style="color:#ef4444;font-size:.75rem;white-space:nowrap;">⚠ Vượt giới hạn!</div>
                                        </div>
                                    </template>
                                    <template x-if="!ri.selected">
                                        <span style="color:var(--htw-text-muted);">—</span>
                                    </template>
                                </td>
                                <td style="text-align:right;font-size:.85rem;" x-text="fmt(ri.sale_price)"></td>
                                <td style="text-align:right;font-size:.85rem;color:var(--htw-text-muted);" x-text="fmt(ri.cogs_per_unit)"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr style="background:#fef9c3;font-weight:700;">
                            <td colspan="5" style="padding:8px 12px;">Tổng cộng</td>
                            <td style="text-align:right;padding:8px 12px;" x-text="fmtNum(returnTotalQty)"></td>
                            <td style="text-align:right;padding:8px 12px;color:#ef4444;" x-text="'- ' + fmt(returnTotalRefund)"></td>
                            <td style="text-align:right;padding:8px 12px;color:#22c55e;" x-text="'+ ' + fmt(returnTotalCogs)"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Impact preview -->
            <div x-show="returnTotalQty > 0" style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-bottom:16px;font-size:.85rem;">
                <div style="font-weight:700;margin-bottom:6px;color:#92400e;">📊 Tác động lên đơn bán gốc:</div>
                <div style="display:flex;gap:20px;flex-wrap:wrap;">
                    <span>Doanh thu giảm: <strong style="color:#ef4444;" x-text="fmt(returnTotalRefund)"></strong></span>
                    <span>Giá vốn giảm: <strong style="color:#22c55e;" x-text="fmt(returnTotalCogs)"></strong></span>
                    <span>Tồn kho tăng: <strong x-text="fmtNum(returnTotalQty) + ' SP'"></strong></span>
                </div>
            </div>

            <div class="htw-field" style="margin-bottom:16px;">
                <label class="htw-label">Ghi chú nội bộ</label>
                <textarea class="htw-textarea" x-model="returnForm.notes" style="min-height:50px;" placeholder="Ghi chú thêm về lô hàng trả..."></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="returnModal=false">Huỷ</button>
                <button class="htw-btn htw-btn-warning" @click="saveReturn()" :disabled="returnSaving || returnTotalQty <= 0">
                    <span x-show="returnSaving" class="htw-spinner"></span>
                    <span x-text="returnSaving ? 'Đang lưu…' : 'Lưu & Xác nhận đơn trả'"></span>
                </button>
            </div>
        </div>
    </div>

</div>