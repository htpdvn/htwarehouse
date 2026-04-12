<?php
defined('ABSPATH') || exit;
global $wpdb;

$suppliers = $wpdb->get_results(
    "SELECT s.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}htw_purchase_orders WHERE supplier_id = s.id) AS po_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}htw_purchase_orders WHERE supplier_id = s.id) AS total_po_amount
     FROM {$wpdb->prefix}htw_suppliers s
     ORDER BY s.name ASC",
    ARRAY_A
);
?>
<script>
    window._htwSuppliers = <?php echo wp_json_encode($suppliers); ?>;
</script>
<div class="htw-wrap" x-data="htwSuppliers">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-building"></span> Nhà cung cấp</h1>
        <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Thêm nhà cung cấp</button>
    </div>

    <!-- Stats -->
    <div class="htw-kpi-grid" style="margin-bottom:24px;">
        <div class="htw-kpi-card purple">
            <div class="htw-kpi-label">Tổng NCC</div>
            <div class="htw-kpi-value" x-text="suppliers.length"></div>
        </div>
        <div class="htw-kpi-card green">
            <div class="htw-kpi-label">Tổng đơn đặt hàng</div>
            <div class="htw-kpi-value" x-text="totalPOs()"></div>
        </div>
        <div class="htw-kpi-card yellow">
            <div class="htw-kpi-label">Tổng giá trị đơn</div>
            <div class="htw-kpi-value" x-text="fmt(totalAmount())"></div>
        </div>
    </div>

    <!-- Search -->
    <div class="htw-search-bar">
        <input class="htw-input" type="text" x-model="search" placeholder="Tìm kiếm nhà cung cấp...">
    </div>

    <!-- Table -->
    <div class="htw-table-wrap">
        <table class="htw-table">
            <thead>
                <tr>
                    <th>Tên NCC</th>
                    <th>Người liên hệ</th>
                    <th>Điện thoại</th>
                    <th>Email</th>
                    <th>Mã số thuế</th>
                    <th>Đơn đặt hàng</th>
                    <th>Tổng giá trị</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="s in filtered" :key="s.id">
                    <tr>
                        <td>
                            <div
                                style="font-weight:600;color:var(--htw-primary);cursor:pointer;text-decoration:underline;text-underline-offset:3px;"
                                x-text="s.name"
                                @click="openTransactions(s)"
                                :title="'Xem giao dịch với ' + s.name"></div>
                            <div style="font-size:.75rem;color:var(--htw-text-muted);" x-text="s.address || '—'"></div>
                        </td>
                        <td x-text="s.contact_name || '—'"></td>
                        <td x-text="s.phone || '—'"></td>
                        <td x-text="s.email || '—'"></td>
                        <td x-text="s.tax_code || '—'"></td>
                        <td>
                            <span class="htw-badge htw-badge-confirmed" x-text="s.po_count + ' đơn'"></span>
                        </td>
                        <td style="font-weight:600;" x-text="fmt(s.total_po_amount || 0)"></td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(s)">Sửa</button>
                                <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(s.id)">Xoá</button>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="!filtered.length">
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--htw-text-muted);padding:32px;">
                            <span x-show="search">Không tìm thấy nhà cung cấp phù hợp.</span>
                            <span x-show="!search">Chưa có nhà cung cấp nào.</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Modal -->
    <div class="htw-modal-overlay" x-show="modal" x-cloak @click.self="modal=false">
        <div class="htw-modal" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-building"></span>
                <span x-text="form.id ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp mới'"></span>
            </div>

            <div class="htw-form-grid" style="grid-template-columns:repeat(2,1fr);">
                <div class="htw-field">
                    <label class="htw-label">Tên nhà cung cấp <span style="color:var(--htw-danger);">*</span></label>
                    <input class="htw-input" type="text" x-model="form.name" placeholder="VD: Công ty TNHH ABC" style="width:100%;">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Người liên hệ</label>
                    <input class="htw-input" x-model="form.contact_name" placeholder="VD: Nguyễn Văn A">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Số điện thoại</label>
                    <input class="htw-input" x-model="form.phone" placeholder="VD: 0901234567">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Email</label>
                    <input class="htw-input" type="email" x-model="form.email" placeholder="VD: ncc@email.com">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Mã số thuế</label>
                    <input class="htw-input" x-model="form.tax_code" placeholder="VD: 0123456789">
                </div>
            </div>

            <div class="htw-field" style="margin-top:16px;">
                <label class="htw-label">Địa chỉ</label>
                <textarea class="htw-textarea" x-model="form.address" placeholder="Địa chỉ nhà cung cấp" style="min-height:70px;"></textarea>
            </div>

            <div class="htw-field" style="margin-top:16px;">
                <label class="htw-label">Ghi chú</label>
                <textarea class="htw-textarea" x-model="form.notes" placeholder="Ghi chú khác..." style="min-height:60px;"></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="modal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="save()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Supplier Transactions Modal ─────────────────────────────────────── -->
    <div class="htw-modal-overlay" x-show="txModal" x-cloak @click.self="txModal=false" style="z-index:100001;">
        <div class="htw-modal" style="max-width:900px;width:96%;" @click.stop>

            <!-- Header -->
            <div class="htw-modal-title" style="justify-content:space-between;align-items:center;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="dashicons dashicons-building" style="font-size:22px;"></span>
                    <div>
                        <div x-text="txData.supplier ? txData.supplier.name : ''" style="font-size:1.1rem;font-weight:700;"></div>
                        <div x-show="txData.supplier && txData.supplier.phone" x-text="'📞 ' + (txData.supplier ? txData.supplier.phone : '')" style="font-size:.8rem;opacity:.7;margin-top:2px;"></div>
                    </div>
                </div>
                <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="txModal=false" style="padding:4px 10px;">✕</button>
            </div>

            <!-- Loading state -->
            <div x-show="txLoading" style="text-align:center;padding:48px;">
                <span class="htw-spinner" style="width:28px;height:28px;border-width:3px;display:inline-block;"></span>
                <div style="margin-top:12px;color:var(--htw-text-muted);">Đang tải dữ liệu…</div>
            </div>

            <template x-if="!txLoading">
                <div>
                    <!-- KPI Summary -->
                    <div class="htw-kpi-grid" style="grid-template-columns:repeat(3,1fr);margin:16px 0 20px;">
                        <div class="htw-kpi-card blue" style="padding:14px 16px;">
                            <div class="htw-kpi-label">Tổng giá trị đơn hàng</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="fmt(txData.total_amount || 0)"></div>
                        </div>
                        <div class="htw-kpi-card green" style="padding:14px 16px;">
                            <div class="htw-kpi-label">Đã thanh toán</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="fmt(txData.total_paid || 0)"></div>
                        </div>
                        <div class="htw-kpi-card" :class="parseFloat(txData.total_remaining||0) > 0 ? 'yellow' : 'purple'" style="padding:14px 16px;">
                            <div class="htw-kpi-label">Còn nợ</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="fmt(txData.total_remaining || 0)"></div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div style="display:flex;gap:4px;border-bottom:1px solid var(--htw-border);margin-bottom:16px;">
                        <button
                            class="htw-btn htw-btn-sm"
                            :class="txTab==='orders' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                            @click="txTab='orders'"
                            style="border-radius:6px 6px 0 0;border-bottom:none;">📋 Đơn đặt hàng (<span x-text="(txData.pos||[]).length"></span>)</button>
                        <button
                            class="htw-btn htw-btn-sm"
                            :class="txTab==='payments' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                            @click="txTab='payments'"
                            style="border-radius:6px 6px 0 0;border-bottom:none;">💰 Lịch sử thanh toán (<span x-text="(txData.payments||[]).length"></span>)</button>
                    </div>

                    <!-- Tab: Purchase Orders -->
                    <div x-show="txTab==='orders'">
                        <div class="htw-table-wrap" style="max-height:380px;overflow-y:auto;">
                            <table class="htw-table" style="font-size:.85rem;">
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Ngày đặt</th>
                                        <th>Trạng thái</th>
                                        <th style="text-align:right;">Giá trị đơn</th>
                                        <th style="text-align:right;">Đã TT</th>
                                        <th style="text-align:right;">Còn nợ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="po in (txData.pos||[])" :key="po.id">
                                        <tr>
                                            <td><span style="font-weight:600;color:var(--htw-primary);" x-text="po.po_code"></span></td>
                                            <td x-text="fmtDate(po.order_date)"></td>
                                            <td>
                                                <span
                                                    class="htw-badge"
                                                    :class="{
                                                        'htw-badge-draft':      po.status==='draft',
                                                        'htw-badge-confirmed':  po.status==='confirmed',
                                                        'htw-badge-received':   po.status==='received',
                                                        'htw-badge-paid_off':   po.status==='paid_off'
                                                    }"
                                                    x-text="txStatusLabel(po.status)"></span>
                                            </td>
                                            <td style="text-align:right;font-weight:600;" x-text="fmt(po.total_amount)"></td>
                                            <td style="text-align:right;color:var(--htw-success);" x-text="fmt(po.amount_paid)"></td>
                                            <td style="text-align:right;" :style="parseFloat(po.amount_remaining)>0 ? 'color:var(--htw-warning);font-weight:600;' : ''" x-text="fmt(po.amount_remaining)"></td>
                                        </tr>
                                    </template>
                                    <template x-if="!(txData.pos||[]).length">
                                        <tr>
                                            <td colspan="6" style="text-align:center;color:var(--htw-text-muted);padding:24px;">Chưa có đơn đặt hàng nào.</td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot x-show="(txData.pos||[]).length > 0">
                                    <tr style="font-weight:700;border-top:2px solid var(--htw-border);">
                                        <td colspan="3">Tổng cộng</td>
                                        <td style="text-align:right;" x-text="fmt(txData.total_amount||0)"></td>
                                        <td style="text-align:right;color:var(--htw-success);" x-text="fmt(txData.total_paid||0)"></td>
                                        <td style="text-align:right;" :style="parseFloat(txData.total_remaining||0)>0?'color:var(--htw-warning);':''" x-text="fmt(txData.total_remaining||0)"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Tab: Payment History -->
                    <div x-show="txTab==='payments'">
                        <div x-show="!(txData.payments||[]).length" style="text-align:center;color:var(--htw-text-muted);padding:32px;">
                            Chưa có lịch sử thanh toán nào.
                        </div>
                        <div class="htw-table-wrap" style="max-height:380px;overflow-y:auto;" x-show="(txData.payments||[]).length">
                            <table class="htw-table" style="font-size:.85rem;">
                                <thead>
                                    <tr>
                                        <th>Ngày TT</th>
                                        <th>Mã đơn</th>
                                        <th style="text-align:right;">Số tiền</th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="pmt in (txData.payments||[])" :key="pmt.id">
                                        <tr>
                                            <td x-text="fmtDate(pmt.payment_date)"></td>
                                            <td><span style="font-weight:600;color:var(--htw-primary);" x-text="pmt.po_code"></span></td>
                                            <td style="text-align:right;font-weight:600;color:var(--htw-success);" x-text="fmt(pmt.amount)"></td>
                                            <td style="color:var(--htw-text-muted);" x-text="pmt.note || '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot x-show="(txData.payments||[]).length > 0">
                                    <tr style="font-weight:700;border-top:2px solid var(--htw-border);">
                                        <td colspan="2">Tổng đã thanh toán</td>
                                        <td style="text-align:right;color:var(--htw-success);" x-text="fmt(txData.total_paid||0)"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                </div>
            </template>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="txModal=false">Đóng</button>
            </div>
        </div>
    </div>

</div>