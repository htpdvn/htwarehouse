<?php
defined('ABSPATH') || exit;
global $wpdb;

$batches = $wpdb->get_results(
    "SELECT b.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_import_items WHERE batch_id = b.id) AS item_count,
            (SELECT COALESCE(SUM(total_cost),0) FROM {$wpdb->prefix}htw_import_items WHERE batch_id = b.id) AS items_value
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
         JOIN {$wpdb->prefix}htw_products p ON p.id = ii.product_id
         WHERE ii.batch_id = %d",
        $b['id']
    ), ARRAY_A);
}
unset($b);

$products = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
?>
<script>
window._htwImports  = <?php echo wp_json_encode($batches); ?>;
window._htwProducts = <?php echo wp_json_encode($products); ?>;
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
                    <th>Số SP</th>
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
                        <td x-text="b.import_date"></td>
                        <td x-text="b.item_count"></td>
                        <td x-text="fmt(b.items_value)"></td>
                        <td x-text="fmt(parseFloat(b.shipping_fee||0)+parseFloat(b.tax_fee||0)+parseFloat(b.other_fee||0))"></td>
                        <td style="font-weight:600;" x-text="fmt(parseFloat(b.items_value||0)+parseFloat(b.shipping_fee||0)+parseFloat(b.tax_fee||0)+parseFloat(b.other_fee||0))"></td>
                        <td>
                            <span :class="'htw-badge htw-badge-' + b.status" x-text="b.status === 'confirmed' ? 'Đã xác nhận' : 'Nháp'"></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(b)">✏️ Sửa</button>
                                </template>
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirm(b.id)">✓ Xác nhận</button>
                                </template>
                                <template x-if="b.status === 'draft'">
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(b.id)">🗑</button>
                                </template>
                                <template x-if="b.status === 'confirmed'">
                                    <span style="color:var(--htw-text-muted);font-size:.8rem;">— đã khoá —</span>
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
                    <input class="htw-input" x-model="form.supplier" placeholder="Tên nhà cung cấp">
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
                    <thead>
                        <tr>
                            <th style="width:40%;">Sản phẩm</th>
                            <th>Số lượng</th>
                            <th>Đơn giá (VND)</th>
                            <th>Thành tiền</th>
                            <th>Giá vốn (ước)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <tr>
                                <td>
                                    <select class="htw-select" x-model="item.product_id">
                                        <option value="">-- Chọn sản phẩm --</option>
                                        <template x-for="p in products" :key="p.id">
                                            <option :value="p.id" x-text="p.name + (p.sku ? ' [' + p.sku + ']' : '')"></option>
                                        </template>
                                    </select>
                                </td>
                                <td><input class="htw-input" type="number" x-model="item.qty" min="0" step="0.001"></td>
                                <td><input class="htw-input" type="number" x-model="item.unit_price" min="0" step="1"></td>
                                <td x-text="fmt(item.qty * item.unit_price)"></td>
                                <td style="color:var(--htw-success);" x-text="fmt(previewAllocated(item))"></td>
                                <td><button type="button" class="htw-btn htw-btn-danger htw-btn-sm" @click="removeItem(idx)">✕</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="padding:10px 12px;">
                                <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="addItem()">+ Thêm dòng</button>
                            </td>
                            <td style="font-weight:700;color:var(--htw-text);" x-text="fmt(itemsTotal)"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Extra costs -->
            <div style="font-weight:600;margin:20px 0 10px;color:var(--htw-text);">Chi phí lô hàng (phân bổ theo tỷ lệ giá trị)</div>
            <div class="htw-form-grid" style="grid-template-columns:repeat(3,1fr);">
                <div class="htw-field">
                    <label class="htw-label">Phí vận chuyển (VND)</label>
                    <input class="htw-input" type="number" x-model="form.shipping_fee" min="0">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Thuế nhập khẩu (VND)</label>
                    <input class="htw-input" type="number" x-model="form.tax_fee" min="0">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Chi phí khác (VND)</label>
                    <input class="htw-input" type="number" x-model="form.other_fee" min="0">
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

</div>