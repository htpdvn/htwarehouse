<?php
defined('ABSPATH') || exit;
global $wpdb;

$batches = $wpdb->get_results(
    "SELECT b.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_import_items WHERE batch_id = b.id) AS item_count,
            (SELECT COALESCE(SUM(qty * unit_price),0) FROM {$wpdb->prefix}htw_import_items WHERE batch_id = b.id) AS items_value
     FROM {$wpdb->prefix}htw_import_batches b
     ORDER BY b.import_date DESC, b.id DESC
     LIMIT 200",
    ARRAY_A
);

// Attach items to each batch for editing
foreach ($batches as &$b) {
    $b['items'] = $wpdb->get_results($wpdb->prepare(
        "SELECT ii.*, p.name AS product_name
         FROM {$wpdb->prefix}htw_import_items ii
         LEFT JOIN {$wpdb->prefix}htw_products p ON p.id = ii.product_id
         WHERE ii.batch_id = %d",
        $b['id']
    ), ARRAY_A);
}
unset($b);

$products = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
?>
<script>
    window._htwImports = <?php echo wp_json_encode($batches); ?>;
    window._htwProducts = <?php echo wp_json_encode($products); ?>;
    window._htwSuppliersList = <?php echo wp_json_encode($suppliers); ?>;
</script>
<div class="htw-wrap" x-data="htwImports">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-download"></span> Nhập kho</h1>
        <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Tạo lô nhập mới</button>
    </div>

    <!-- Table -->
    <div class="htw-table-wrap">
        <table class="htw-table">
            <thead>
                <tr>
                    <th>Mã lô</th>
                    <th>Nhà cung cấp</th>
                    <th>Ngày nhập</th>
                    <th>Số mã SP</th>
                    <th>G. trị hàng</th>
                    <th>Phí+Thuế</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="b in batches" :key="b.id">
                    <tr>
                        <td style="font-weight:600;color:var(--htw-primary);" x-text="b.batch_code"></td>
                        <td x-text="b.supplier || '—'"></td>
                        <td x-text="fmtDate(b.import_date)"></td>
                        <td x-text="b.item_count"></td>
                        <td x-text="fmt(b.items_value)"></td>
                        <td x-text="fmt((parseFloat(b.shipping_fee)||0)+(parseFloat(b.tax_fee)||0)+(parseFloat(b.service_fee)||0)+(parseFloat(b.inspection_fee)||0)+(parseFloat(b.packing_fee)||0)+(parseFloat(b.other_fee)||0))"></td>
                        <td style="font-weight:600;" x-text="fmt((parseFloat(b.items_value)||0)+(parseFloat(b.shipping_fee)||0)+(parseFloat(b.tax_fee)||0)+(parseFloat(b.service_fee)||0)+(parseFloat(b.inspection_fee)||0)+(parseFloat(b.packing_fee)||0)+(parseFloat(b.other_fee)||0))"></td>
                        <td>
                            <span :class="'htw-badge htw-badge-' + b.status" x-text="b.status === 'confirmed' ? 'Đã lưu kho' : 'Nháp'"></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(b)">Sửa</button>
                                </template>
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirm(b.id)">Xác nhận lưu kho</button>
                                </template>
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(b.id)">Xoá</button>
                                </template>
                                <template x-if="b.status === 'confirmed'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openDetail(b)">Xem chi tiết</button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="!batches.length">
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--htw-text-muted);padding:32px;">Chưa có lô nhập nào.</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Modal -->
    <div class="htw-modal-overlay" x-show="modal" x-cloak @click.self="modal=false">
        <div class="htw-modal" style="max-width:900px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-download"></span>
                <span x-text="form.id ? 'Sửa lô nhập: ' + form.batch_code : 'Tạo lô nhập mới'"></span>
            </div>

            <!-- Header fields -->
            <div class="htw-form-grid" style="margin-bottom:20px;">
                <div class="htw-field">
                    <label class="htw-label">Mã lô (tự sinh nếu để trống)</label>
                    <input class="htw-input" x-model="form.batch_code" placeholder="VD: IMP-2024-001">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Nhà cung cấp</label>
                    <select class="htw-select" x-model="form.supplier_id" @change="form.supplier = suppliersList.find(function(s){return s.id==form.supplier_id}) ? suppliersList.find(function(s){return s.id==form.supplier_id}).name : ''">
                        <option value="0">-- Khác / Tự nhập --</option>
                        <template x-for="s in suppliersList" :key="s.id">
                            <option :value="s.id" x-text="(s.supplier_code ? '['+s.supplier_code+'] ' : '') + s.name + (s.phone ? ' (' + s.phone + ')' : '')"></option>
                        </template>
                    </select>
                </div>
                <div class="htw-field" x-show="!form.supplier_id">
                    <label class="htw-label">Tên NCC (tự nhập)</label>
                    <input class="htw-input" x-model="form.supplier" placeholder="VD: Công ty TNHH ABC">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Ngày nhập</label>
                    <input class="htw-input" type="date" x-model="form.import_date">
                </div>
            </div>

            <!-- Items table -->
            <div style="font-weight:600;margin-bottom:8px;color:var(--htw-text);">Danh sách hàng hoá</div>
            <div class="htw-items-table-wrap">
                <table class="htw-table htw-items-table" style="width:100%;">
                    <thead class="htw-items-thead">
                        <tr>
                            <th style="width:40%;">Sản phẩm</th>
                            <th>Số lượng</th>
                            <th>Đơn giá</th>
                            <th>Thành tiền</th>
                            <th>Giá vốn (ước)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <tr class="htw-item-row">
                                <td data-label="Sản phẩm">
                                    <select class="htw-select" x-model="item.product_id" x-html="'<option value=\'\'>-- Chọn sản phẩm --</option>' + products.map(function(p){return '<option value=\''+p.id+'\''+(item.product_id==p.id?' selected':'')+'>'+p.name+(p.sku?' ['+p.sku+']':'')+'</option>';}).join('')">
                                    </select>
                                </td>
                                <td data-label="Số lượng"><input class="htw-input" type="text"
                                        :value="fmtNum(item.qty)"
                                        @input="item.qty = parseNum($event.target.value)"
                                        @blur="$event.target.value = fmtNum(item.qty)"
                                        placeholder="0" style="text-align:right;"></td>
                                <td data-label="Đơn giá"><input class="htw-input" type="text"
                                        :value="fmtNum(item.unit_price)"
                                        @input="item.unit_price = parseNum($event.target.value)"
                                        @blur="$event.target.value = fmtNum(item.unit_price)"
                                        placeholder="0" style="text-align:right;"></td>
                                <td data-label="Thành tiền" x-text="fmt(item.qty * item.unit_price)"></td>
                                <td data-label="Giá vốn (ước)" style="color:var(--htw-success);" x-text="fmt(previewAllocated(item))"></td>
                                <td class="htw-item-del-cell"><button type="button" class="htw-btn htw-btn-danger htw-btn-sm" @click="removeItem(idx)">✕</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="htw-items-footer-row">
                            <td colspan="3" style="padding:10px 12px;">
                                <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="addItem()">+ Thêm dòng</button>
                            </td>
                            <td class="htw-items-total-cell" style="font-weight:700;color:var(--htw-text);" x-text="fmt(itemsTotal)"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Extra costs -->
            <div style="font-weight:600;margin:20px 0 10px;color:var(--htw-text);">Chi phí lô hàng</div>
            <div class="htw-form-grid htw-import-fees-grid">
                <div class="htw-field">
                    <label class="htw-label">Phí vận chuyển (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.shipping_fee)"
                        @input="form.shipping_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.shipping_fee)"
                        placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Thuế nhập khẩu (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.tax_fee)"
                        @input="form.tax_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.tax_fee)"
                        placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí dịch vụ (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.service_fee)"
                        @input="form.service_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.service_fee)"
                        placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí kiểm định (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.inspection_fee)"
                        @input="form.inspection_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.inspection_fee)"
                        placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Phí đóng gói (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.packing_fee)"
                        @input="form.packing_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.packing_fee)"
                        placeholder="0 đ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Chi phí khác (đ)</label>
                    <input class="htw-input" type="text" style="text-align:right;"
                        :value="fmtNum(form.other_fee)"
                        @input="form.other_fee = parseNum($event.target.value)"
                        @blur="$event.target.value = fmtNum(form.other_fee)"
                        placeholder="0 đ">
                </div>
            </div>

            <!-- Summary -->
            <div style="background:var(--htw-surface-2);border-radius:8px;padding:14px 18px;margin-top:16px;display:flex;gap:24px;flex-wrap:wrap;">
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Giá trị hàng: </span><strong x-text="fmt(itemsTotal)"></strong></div>
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tổng chi phí: </span><strong style="color:var(--htw-warning);" x-text="fmt(extraTotal)"></strong></div>
                <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tổng lô: </span><strong style="color:var(--htw-success);" x-text="fmt(grandTotal)"></strong></div>
            </div>

            <div class="htw-field" style="margin-top:14px;">
                <label class="htw-label">Ghi chú</label>
                <textarea class="htw-textarea" x-model="form.notes" style="min-height:60px;"></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="modal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="save()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu nháp'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Detail Modal for Confirmed Batches -->
    <div class="htw-modal-overlay" x-show="detailModal" x-cloak @click.self="detailModal=false">
        <div class="htw-modal" style="max-width:900px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-download"></span>
                <span>Chi tiết lô nhập: </span>
                <span x-text="detail ? detail.batch_code : ''"></span>
            </div>

            <template x-if="detail">
                <div>
                    <!-- Header info -->
                    <div class="htw-form-grid" style="margin-bottom:20px;">
                        <div class="htw-field">
                            <label class="htw-label">Nhà cung cấp</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="detail.supplier || '—'"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Ngày nhập</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmtDate(detail.import_date)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Trạng thái</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;color:var(--htw-success);">Đã lưu kho</div>
                        </div>
                    </div>

                    <!-- Items table -->
                    <div style="font-weight:600;margin-bottom:8px;color:var(--htw-text);">Danh sách hàng hoá</div>
                    <div class="htw-items-table-wrap">
                        <table class="htw-table htw-items-table" style="width:100%;">
                            <thead class="htw-items-thead">
                                <tr>
                                    <th style="width:35%;">Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>Đơn giá</th>
                                    <th>Thành tiền</th>
                                    <th>Giá vốn đã phân bổ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in detail.items" :key="item.id">
                                    <tr class="htw-item-row">
                                        <td data-label="Sản phẩm" x-text="item.product_name || productName(item.product_id)"></td>
                                        <td data-label="Số lượng" style="text-align:right;" x-text="fmtNum(item.qty)"></td>
                                        <td data-label="Đơn giá" style="text-align:right;" x-text="fmt(item.unit_price)"></td>
                                        <td data-label="Thành tiền" style="text-align:right;" x-text="fmt(item.total_cost)"></td>
                                        <td data-label="Giá vốn phân bổ" style="text-align:right;color:var(--htw-success);" x-text="fmt(item.allocated_cost_per_unit)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Extra costs -->
                    <div style="font-weight:600;margin:20px 0 10px;color:var(--htw-text);">Chi phí lô hàng</div>
                    <div class="htw-form-grid htw-import-fees-grid">
                        <div class="htw-field">
                            <label class="htw-label">Phí vận chuyển</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.shipping_fee)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Thuế nhập khẩu</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.tax_fee)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Phí dịch vụ</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.service_fee)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Phí kiểm định</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.inspection_fee)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Phí đóng gói</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.packing_fee)"></div>
                        </div>
                        <div class="htw-field">
                            <label class="htw-label">Chi phí khác</label>
                            <div class="htw-input" style="display:flex;align-items:center;background:#f1f5f9;cursor:default;" x-text="fmt(detail.other_fee)"></div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div style="background:var(--htw-surface-2);border-radius:8px;padding:14px 18px;margin-top:16px;display:flex;gap:24px;flex-wrap:wrap;">
                        <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Giá trị hàng: </span><strong x-text="fmt(detail.items_value)"></strong></div>
                        <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tổng chi phí: </span><strong style="color:var(--htw-warning);" x-text="fmt((parseFloat(detail.shipping_fee)||0)+(parseFloat(detail.tax_fee)||0)+(parseFloat(detail.service_fee)||0)+(parseFloat(detail.inspection_fee)||0)+(parseFloat(detail.packing_fee)||0)+(parseFloat(detail.other_fee)||0))"></strong></div>
                        <div><span style="color:var(--htw-text-muted);font-size:.8rem;">Tổng lô: </span><strong style="color:var(--htw-success);" x-text="fmt((parseFloat(detail.items_value)||0)+(parseFloat(detail.shipping_fee)||0)+(parseFloat(detail.tax_fee)||0)+(parseFloat(detail.service_fee)||0)+(parseFloat(detail.inspection_fee)||0)+(parseFloat(detail.packing_fee)||0)+(parseFloat(detail.other_fee)||0))"></strong></div>
                    </div>

                    <!-- Notes -->
                    <template x-if="detail.notes">
                        <div style="margin-top:16px;">
                            <div style="font-weight:600;margin-bottom:6px;color:var(--htw-text);">Ghi chú</div>
                            <div style="color:var(--htw-text-muted);font-size:.9rem;" x-text="detail.notes"></div>
                        </div>
                    </template>
                </div>
            </template>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="detailModal=false">Đóng</button>
            </div>
        </div>
    </div>

</div>