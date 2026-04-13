<?php
defined('ABSPATH') || exit;
global $wpdb;

$pos = $wpdb->get_results(
    "SELECT
            po.id, po.po_code, po.supplier_id, po.supplier_name,
            po.supplier_contact, po.supplier_phone, po.supplier_address,
            po.order_date,
            po.goods_total, po.shipping_fee, po.tax_fee, po.service_fee,
            po.inspection_fee, po.packing_fee, po.other_fee,
            po.total_amount, po.amount_paid, po.amount_remaining,
            po.status, po.import_batch_id, po.notes,
            ib.batch_code AS import_batch_code,
            ib.status AS import_batch_status,
            COALESCE(s.name, po.supplier_name, '') AS supplier_full_name,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_purchase_order_items WHERE po_id = po.id) AS item_count
     FROM {$wpdb->prefix}htw_purchase_orders po
     LEFT JOIN {$wpdb->prefix}htw_import_batches ib ON ib.id = po.import_batch_id
     LEFT JOIN {$wpdb->prefix}htw_suppliers s ON s.id = po.supplier_id
     ORDER BY po.order_date DESC, po.id DESC
     LIMIT 200",
    ARRAY_A
);

// Attach items and payments to each PO
foreach ($pos as &$po) {
    $po['items'] = $wpdb->get_results($wpdb->prepare(
        "SELECT poi.*, p.name AS product_name
         FROM {$wpdb->prefix}htw_purchase_order_items poi
         JOIN {$wpdb->prefix}htw_products p ON p.id = poi.product_id
         WHERE poi.po_id = %d",
        $po['id']
    ), ARRAY_A);
    $po['payments'] = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}htw_po_payments WHERE po_id = %d ORDER BY payment_date ASC, id ASC",
        $po['id']
    ), ARRAY_A);
}
unset($po);

$products = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
$suppliers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}htw_suppliers ORDER BY name", ARRAY_A);
?>
<script>
window._htwPOs          = <?php echo wp_json_encode($pos); ?>;
window._htwProducts     = <?php echo wp_json_encode($products); ?>;
window._htwSuppliersList = <?php echo wp_json_encode($suppliers); ?>;
</script>
<div class="htw-wrap" x-data="htwPurchaseOrders">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-cart"></span> Đơn đặt hàng</h1>
        <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Tạo đơn đặt hàng</button>
    </div>

    <!-- Table -->
    <div class="htw-table-wrap">
        <table class="htw-table">
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>NCC</th>
                    <th>Ngày đặt</th>
                    <th>Số SP</th>
                    <th>Tổng đơn</th>
                    <th>Đã TT</th>
                    <th>Còn nợ</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="po in pos" :key="po.id">
                    <tr>
                        <td style="font-weight:600;color:var(--htw-primary);" x-text="po.po_code"></td>
                        <td x-text="(po.supplier_full_name || po.supplier_name || '').trim() || '—'"></td>
                        <td x-text="fmtDate(po.order_date)"></td>
                        <td x-text="po.item_count"></td>
                        <td style="font-weight:600;" x-text="fmt(po.total_amount || 0)"></td>
                        <td style="color:var(--htw-success);" x-text="fmt(po.amount_paid || 0)"></td>
                        <td :style="'font-weight:600;color:' + ((po.amount_remaining || 0) > 0.01 ? 'var(--htw-danger)' : 'var(--htw-success)')" x-text="fmt(po.amount_remaining || 0)"></td>
                        <td>
                            <span :class="'htw-badge htw-badge-' + po.status" x-text="statusLabel(po.status)"></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                <!-- Chi tiết: all statuses -->
                                <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openDetail(po)">Chi tiết</button>

                                <!-- draft actions -->
                                <template x-if="po.status === 'draft'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(po)">Sửa</button>
                                </template>
                                <template x-if="po.status === 'draft'">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirm(po.id)">Gửi đơn</button>
                                </template>
                                <template x-if="po.status === 'draft'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(po.id)">Xoá</button>
                                </template>
                                <!-- confirmed / paid_off actions: Chuyển NK (only if not yet sent to import) -->
                                <template x-if="(po.status === 'confirmed' || po.status === 'paid_off') && !po.import_batch_id">
                                    <button class="htw-btn htw-btn-warning htw-btn-sm" @click="sendToImport(po.id)">Chuyển NK</button>
                                </template>
                                <!-- received / confirmed: payment -->
                                <template x-if="po.status === 'confirmed' || po.status === 'received'">
                                    <button class="htw-btn htw-btn-primary htw-btn-sm" @click="openPaymentModal(po)">Thanh toán</button>
                                </template>
                                <!-- received actions: edit, delete -->
                                <template x-if="po.status === 'received'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(po)">Sửa</button>
                                </template>
                                <template x-if="po.status === 'received'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(po.id)">Xoá</button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="!pos.length">
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--htw-text-muted);padding:32px;">Chưa có đơn đặt hàng nào.</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Detail Modal (with tabs) -->
    <div class="htw-modal-overlay" x-show="detailModal" x-cloak @click.self="detailModal=false">
        <div class="htw-modal" style="max-width:900px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-cart"></span>
                <span x-text="'Chi tiết đơn: ' + (detail.po_code || '')"></span>
            </div>

            <!-- Tabs -->
            <div class="htw-tabs" style="margin-bottom:16px;">
                <button class="htw-tab" :class="{'htw-tab-active': detailTab === 'info'}" @click="detailTab = 'info'">Thông tin đơn</button>
                <button class="htw-tab" :class="{'htw-tab-active': detailTab === 'payments'}" @click="detailTab = 'payments'">
                    Lịch sử thanh toán
                    <span x-show="detail.payments && detail.payments.length" class="htw-badge htw-badge-info" x-text="detail.payments.length"></span>
                </button>
            </div>

            <!-- Tab: Thông tin đơn -->
            <div x-show="detailTab === 'info'">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <div class="htw-info-row"><span class="htw-info-label">Mã đơn:</span> <strong x-text="detail.po_code"></strong></div>
                        <div class="htw-info-row"><span class="htw-info-label">Nhà cung cấp:</span> <span x-text="(detail.supplier_full_name || detail.supplier_name || '—')"></span></div>
                        <div class="htw-info-row"><span class="htw-info-label">Người liên hệ:</span> <span x-text="detail.supplier_contact || '—'"></span></div>
                        <div class="htw-info-row"><span class="htw-info-label">Điện thoại:</span> <span x-text="detail.supplier_phone || '—'"></span></div>
                        <div class="htw-info-row"><span class="htw-info-label">Địa chỉ:</span> <span x-text="detail.supplier_address || '—'"></span></div>
                    </div>
                    <div>
                        <div class="htw-info-row"><span class="htw-info-label">Ngày đặt:</span> <span x-text="fmtDate(detail.order_date)"></span></div>
                        <div class="htw-info-row"><span class="htw-info-label">Trạng thái:</span> <span :class="'htw-badge htw-badge-' + detail.status" x-text="statusLabel(detail.status)"></span></div>
                        <div class="htw-info-row" x-show="detail.import_batch_id">
                            <span class="htw-info-label">Lô nhập kho:</span> <span x-text="detail.import_batch_code || detail.import_batch_id"></span>
                            <span class="htw-badge htw-badge-info" x-show="detail.import_batch_status === 'draft'">Chưa xác nhận lưu kho</span>
                            <span class="htw-badge htw-badge-success" x-show="detail.import_batch_status === 'confirmed'">Đã xác nhận lưu kho</span>
                        </div>
                        <div class="htw-info-row"><span class="htw-info-label">Tổng đơn:</span> <strong style="color:var(--htw-success);" x-text="fmt(detail.total_amount || 0)"></strong></div>
                        <div class="htw-info-row"><span class="htw-info-label">Đã TT:</span> <strong style="color:var(--htw-success);" x-text="fmt(detail.amount_paid || 0)"></strong></div>
                        <div class="htw-info-row">
                            <span class="htw-info-label">Còn nợ:</span>
                            <strong :style="'color:' + ((detail.amount_remaining || 0) > 0.01 ? 'var(--htw-danger)' : 'var(--htw-success)')" x-text="fmt(detail.amount_remaining || 0)"></strong>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div style="font-weight:600;margin-bottom:8px;">Danh sách hàng hoá</div>
                <div class="htw-table-wrap" style="border-radius:8px;">
                    <table class="htw-table" style="font-size:.82rem;">
                        <thead>
                            <tr><th>Sản phẩm</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="item in detail.items" :key="item.id">
                                <tr>
                                    <td x-text="item.product_name || item.product_id"></td>
                                    <td x-text="fmtQty(item.qty)"></td>
                                    <td x-text="fmt(item.unit_price)"></td>
                                    <td x-text="fmt(item.line_total)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Fees summary -->
                <div style="background:var(--htw-surface-2);border-radius:8px;padding:12px 16px;margin-top:12px;display:flex;gap:20px;flex-wrap:wrap;font-size:.85rem;">
                    <div><span style="color:var(--htw-text-muted);">Tiền hàng: </span><strong x-text="fmt(detail.goods_total || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Vận chuyển: </span><strong x-text="fmt(detail.shipping_fee || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Thuế: </span><strong x-text="fmt(detail.tax_fee || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Dịch vụ: </span><strong x-text="fmt(detail.service_fee || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Kiểm định: </span><strong x-text="fmt(detail.inspection_fee || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Đóng gói: </span><strong x-text="fmt(detail.packing_fee || 0)"></strong></div>
                    <div><span style="color:var(--htw-text-muted);">Khác: </span><strong x-text="fmt(detail.other_fee || 0)"></strong></div>
                </div>

                <!-- Notes -->
                <div x-show="detail.notes" style="margin-top:12px;">
                    <div style="font-weight:600;margin-bottom:4px;font-size:.85rem;">Ghi chú</div>
                    <div style="color:var(--htw-text-muted);font-size:.85rem;" x-text="detail.notes || '—'"></div>
                </div>
            </div>

            <!-- Tab: Lịch sử thanh toán -->
            <div x-show="detailTab === 'payments'">
                <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-size:.85rem;color:var(--htw-text-muted);">
                        Tổng đã TT: <strong style="color:var(--htw-success);" x-text="fmt(detail.amount_paid || 0)"></strong>
                        &nbsp;|&nbsp;
                        Còn nợ: <strong :style="'color:' + ((detail.amount_remaining || 0) > 0.01 ? 'var(--htw-danger)' : 'var(--htw-success)')" x-text="fmt(detail.amount_remaining || 0)"></strong>
                    </div>
                    <button class="htw-btn htw-btn-primary htw-btn-sm"
                            x-show="detail.status === 'confirmed' || detail.status === 'received'"
                            @click="openPaymentModalFromDetail()">
                        + Ghi nhận thanh toán
                    </button>
                </div>

                <div class="htw-table-wrap" style="border-radius:8px;">
                    <table class="htw-table" style="font-size:.82rem;">
                        <thead>
                            <tr>
                                <th>Ngày thanh toán</th>
                                <th>Số tiền</th>
                                <th>Ghi chú</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="pmt in detail.payments" :key="pmt.id">
                                <tr>
                                    <td x-text="fmtDate(pmt.payment_date)"></td>
                                    <td style="color:var(--htw-success);font-weight:600;" x-text="fmt(pmt.amount)"></td>
                                    <td style="color:var(--htw-text-muted);" x-text="pmt.note || '—'"></td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEditPaymentModal(pmt)" title="Sửa">Sửa</button>
                                        <button class="htw-btn htw-btn-danger htw-btn-sm" @click="deletePayment(pmt.id)" title="Xoá">Xoá</button>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="!detail.payments || !detail.payments.length">
                                <tr>
                                    <td colspan="4" style="text-align:center;color:var(--htw-text-muted);padding:24px;">Chưa có thanh toán nào.</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="detailModal=false">Đóng</button>
                <button class="htw-btn htw-btn-primary" x-show="detailTab === 'info' && (detail.status === 'draft' || detail.status === 'received')" @click="openEditFromDetail()">Sửa đơn</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="htw-modal-overlay" x-show="modal" x-cloak @click.self="modal=false">
        <div class="htw-modal" style="max-width:960px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-cart"></span>
                <span x-text="form.id ? 'Sửa đơn đặt hàng: ' + form.po_code : 'Tạo đơn đặt hàng mới'"></span>
            </div>

            <div x-show="form.id && form.amount_paid > 0" style="background:#fffbeb;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.8rem;color:#92400e;">
                ⚠️ Đơn đã thanh toán <strong x-text="fmt(form.amount_paid)"></strong>. Các thay đổi về hàng hoá/chi phí sẽ không ảnh hưởng đến số tiền đã thanh toán.
            </div>

            <!-- Supplier info -->
            <div style="font-weight:600;margin-bottom:10px;color:var(--htw-text);">Thông tin nhà cung cấp</div>
            <div class="htw-form-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
                <div class="htw-field">
                    <label class="htw-label">Mã đơn (tự sinh nếu để trống)</label>
                    <input class="htw-input" x-model="form.po_code" placeholder="VD: PO-ABC123">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Ngày đặt hàng</label>
                    <div style="display:flex;gap:6px;">
                        <input class="htw-input" type="number" min="1" max="31" placeholder="Ngày"
                               x-model="form.order_day" @change="syncOrderDate()" style="width:70px;">
                        <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                        <input class="htw-input" type="number" min="1" max="12" placeholder="Tháng"
                               x-model="form.order_month" @change="syncOrderDate()" style="width:70px;">
                        <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                        <input class="htw-input" type="number" min="2000" max="2100" placeholder="Năm"
                               x-model="form.order_year" @change="syncOrderDate()" style="width:90px;">
                    </div>
                </div>
                <div class="htw-field">
                    <label class="htw-label">Chọn nhà cung cấp</label>
                    <select class="htw-select" x-model="form.supplier_id" @change="onSupplierChange()">
                        <option value="0">-- Chọn NCC --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"><?php echo esc_html($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Items table -->
            <div style="font-weight:600;margin-bottom:8px;color:var(--htw-text);">Danh sách hàng hoá</div>
            <div class="htw-items-table-wrap">
                <table class="htw-table htw-items-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:40%;">Sản phẩm</th>
                            <th>Số lượng</th>
                            <th>Đơn giá (đ)</th>
                            <th>Thành tiền</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <tr>
                                <td>
                                    <select class="htw-select" x-model="item.product_id" x-html="'<option value=\'\'>-- Chọn sản phẩm --</option>' + products.map(function(p){return '<option value=\''+p.id+'\''+(item.product_id==p.id?' selected':'')+'>'+p.name+(p.sku?' ['+p.sku+']':'')+'</option>';}).join('')">
                                    </select>
                                </td>
                                <td><input class="htw-input" type="text"
                                           :value="fmtNum(item.qty)"
                                           @input="item.qty = parseNum($event.target.value)"
                                           @blur="$event.target.value = fmtNum(item.qty)"
                                           placeholder="0" style="text-align:right;"></td>
                                <td><input class="htw-input" type="text"
                                           :value="fmtNum(item.unit_price)"
                                           @input="item.unit_price = parseNum($event.target.value)"
                                           @blur="$event.target.value = fmtNum(item.unit_price)"
                                           placeholder="0" style="text-align:right;"></td>
                                <td style="text-align:right;font-weight:600;" x-text="fmt(item.qty * item.unit_price)"></td>
                                <td><button type="button" class="htw-btn htw-btn-danger htw-btn-sm" @click="removeItem(idx)">✕</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="padding:10px 12px;">
                                <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="addItem()">+ Thêm dòng</button>
                            </td>
                            <td style="text-align:right;font-weight:700;color:var(--htw-text);" x-text="fmt(goodsTotal)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Extra fees -->
            <div style="font-weight:600;margin:20px 0 10px;color:var(--htw-text);">Chi phí đơn hàng</div>
            <div class="htw-form-grid" style="grid-template-columns:repeat(6,1fr);">
                <div class="htw-field">
                    <label class="htw-label">Phí vận chuyển</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.shipping_fee)"
                           @input="form.shipping_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.shipping_fee)"
                           placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Thuế nhập khẩu</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.tax_fee)"
                           @input="form.tax_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.tax_fee)"
                           placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí dịch vụ</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.service_fee)"
                           @input="form.service_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.service_fee)"
                           placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí kiểm định</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.inspection_fee)"
                           @input="form.inspection_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.inspection_fee)"
                           placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí đóng gói</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.packing_fee)"
                           @input="form.packing_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.packing_fee)"
                           placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Chi phí khác</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                           :value="fmtNum(form.other_fee)"
                           @input="form.other_fee = parseNum($event.target.value)"
                           @blur="$event.target.value = fmtNum(form.other_fee)"
                           placeholder="0 đ">
                </div>
            </div>

            <!-- Summary -->
            <div style="background:var(--htw-surface-2);border-radius:8px;padding:14px 18px;margin-top:16px;display:flex;gap:24px;flex-wrap:wrap;">
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tiền hàng: </span><strong x-text="fmt(goodsTotal)"></strong></div>
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Chi phí: </span><strong style="color:var(--htw-warning);" x-text="fmt(extraFeesTotal)"></strong></div>
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tổng đơn: </span><strong style="color:var(--htw-success);" x-text="fmt(grandTotal)"></strong></div>
            </div>

            <!-- Payment progress (edit mode) -->
            <template x-if="form.id && (form.amount_paid > 0 || form.amount_remaining > 0)">
                <div style="margin-top:14px;">
                    <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--htw-text-muted);margin-bottom:6px;">
                        <span>Thanh toán: <strong style="color:var(--htw-success);" x-text="fmt(form.amount_paid || 0)"></strong></span>
                        <span>Còn nợ: <strong :style="'color:' + (form.amount_remaining > 0.01 ? 'var(--htw-danger)' : 'var(--htw-success)')" x-text="fmt(form.amount_remaining || 0)"></strong></span>
                    </div>
                    <div class="htw-payment-bar">
                        <div class="htw-payment-fill" :style="'width:' + paymentPercent(form) + '%'"></div>
                    </div>
                </div>
            </template>

            <div class="htw-field" style="margin-top:14px;">
                <label class="htw-label">Ghi chú</label>
                <textarea class="htw-textarea" x-model="form.notes" style="min-height:60px;"></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="modal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="save()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu đơn'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div class="htw-modal-overlay" x-show="paymentModal" x-cloak @click.self="paymentModal=false">
        <div class="htw-modal" style="max-width:480px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-money-alt"></span>
                <span>Ghi nhận thanh toán</span>
            </div>
            <div x-show="paymentForm.po_code">
                <div style="background:var(--htw-surface-2);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                    <div style="font-size:.8rem;color:var(--htw-text-muted);">Đơn: <strong style="color:var(--htw-text);" x-text="paymentForm.po_code"></strong></div>
                    <div style="font-size:.8rem;color:var(--htw-text-muted);">Tổng: <strong style="color:var(--htw-text);" x-text="fmt(paymentForm.total_amount || 0)"></strong></div>
                    <div style="font-size:.8rem;color:var(--htw-text-muted);">Còn nợ: <strong :style="'color:' + (paymentForm.amount_remaining > 0.01 ? 'var(--htw-danger)' : 'var(--htw-success)')" x-text="fmt(paymentForm.amount_remaining || 0)"></strong></div>
                </div>
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Số tiền thanh toán (đ)</label>
                <input class="htw-input" type="text" style="text-align:right;"
                       :value="fmtNum(paymentForm.amount)"
                       @input="paymentForm.amount = parseNum($event.target.value)"
                       @blur="$event.target.value = fmtNum(paymentForm.amount)"
                       placeholder="0 đ">
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Ngày thanh toán</label>
                <div style="display:flex;gap:6px;">
                    <input class="htw-input" type="number" min="1" max="31" placeholder="Ngày"
                           x-model="paymentForm.payment_day" @change="syncPaymentDate()" style="width:70px;">
                    <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                    <input class="htw-input" type="number" min="1" max="12" placeholder="Tháng"
                           x-model="paymentForm.payment_month" @change="syncPaymentDate()" style="width:70px;">
                    <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                    <input class="htw-input" type="number" min="2000" max="2100" placeholder="Năm"
                           x-model="paymentForm.payment_year" @change="syncPaymentDate()" style="width:90px;">
                </div>
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Ghi chú</label>
                <input class="htw-input" x-model="paymentForm.note" placeholder="Ghi chú thanh toán">
            </div>
            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="paymentModal=false">Huỷ</button>
                <button class="htw-btn htw-btn-success" @click="recordPayment()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Ghi nhận'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Payment Modal -->
    <div class="htw-modal-overlay" x-show="editPaymentModal" x-cloak @click.self="editPaymentModal=false">
        <div class="htw-modal" style="max-width:480px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-money-alt"></span>
                <span>Sửa thanh toán</span>
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Số tiền thanh toán (đ)</label>
                <input class="htw-input" type="text" style="text-align:right;"
                       :value="fmtNum(editPaymentForm.amount)"
                       @input="editPaymentForm.amount = parseNum($event.target.value)"
                       @blur="$event.target.value = fmtNum(editPaymentForm.amount)"
                       placeholder="0 đ">
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Ngày thanh toán</label>
                <div style="display:flex;gap:6px;">
                    <input class="htw-input" type="number" min="1" max="31" placeholder="Ngày"
                           x-model="editPaymentForm.payment_day" @change="syncEditPaymentDate()" style="width:70px;">
                    <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                    <input class="htw-input" type="number" min="1" max="12" placeholder="Tháng"
                           x-model="editPaymentForm.payment_month" @change="syncEditPaymentDate()" style="width:70px;">
                    <span style="align-self:center;color:var(--htw-text-muted);">/</span>
                    <input class="htw-input" type="number" min="2000" max="2100" placeholder="Năm"
                           x-model="editPaymentForm.payment_year" @change="syncEditPaymentDate()" style="width:90px;">
                </div>
            </div>
            <div class="htw-field" style="margin-bottom:14px;">
                <label class="htw-label">Ghi chú</label>
                <input class="htw-input" x-model="editPaymentForm.note" placeholder="Ghi chú thanh toán">
            </div>
            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="editPaymentModal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="saveEditPayment()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu'"></span>
                </button>
            </div>
        </div>
    </div>

</div>
