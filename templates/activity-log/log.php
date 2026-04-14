<?php defined('ABSPATH') || exit; ?>

<div class="htw-wrap" id="htw-activity-log-page">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="htw-page-header">
        <h1 class="htw-page-title">
            <span class="dashicons dashicons-list-view"></span>
            Nhật ký thao tác
        </h1>
        <div style="display:flex;gap:10px;align-items:center;">
            <button id="htw-log-prune-btn" class="htw-btn htw-btn-ghost htw-btn-sm" title="Chạy dọn dẹp ngay theo chính sách lưu trữ">
                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;color:var(--htw-danger);"></span>
                Dọn ngay
            </button>
            <button id="htw-log-export-btn" class="htw-btn htw-btn-ghost">
                <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;"></span>
                Xuất CSV
            </button>
        </div>
    </div>

    <!-- ── Retention policy notice ──────────────────────────────────────────── -->
    <div class="htw-alert" style="background:rgba(91,80,232,.07);border:1px solid rgba(91,80,232,.2);color:var(--htw-primary);margin-bottom:20px;">
        <span class="dashicons dashicons-info-outline" style="flex-shrink:0;"></span>
        <span>
            <strong>Chính sách lưu trữ:</strong>
            <strong id="htw-max-days">365</strong> ngày
            &amp; tối đa <strong id="htw-max-rows">50.000</strong> bản ghi.
        </span>
    </div>

    <!-- ── Filter bar ────────────────────────────────────────────────────────── -->
    <div class="htw-card" style="margin-bottom:18px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px;align-items:flex-end;">
            <div class="htw-field">
                <label class="htw-label">Từ ngày</label>
                <input type="date" id="log-date-from" class="htw-input">
            </div>
            <div class="htw-field">
                <label class="htw-label">Đến ngày</label>
                <input type="date" id="log-date-to" class="htw-input">
            </div>
            <div class="htw-field">
                <label class="htw-label">Loại đối tượng</label>
                <select id="log-object-type" class="htw-select">
                    <option value="">— Tất cả —</option>
                    <option value="product">Sản phẩm</option>
                    <option value="import_batch">Lô nhập kho</option>
                    <option value="export_order">Đơn xuất kho</option>
                    <option value="return_order">Đơn trả hàng</option>
                    <option value="purchase_order">Đơn đặt hàng</option>
                    <option value="po_payment">Thanh toán PO</option>
                    <option value="supplier">Nhà cung cấp</option>
                </select>
            </div>
            <div class="htw-field">
                <label class="htw-label">Hành động</label>
                <select id="log-action" class="htw-select">
                    <option value="">— Tất cả —</option>
                    <option value="create">Tạo mới</option>
                    <option value="update">Cập nhật</option>
                    <option value="delete">Xóa</option>
                    <option value="confirm">Xác nhận</option>
                    <option value="send_to_import">Chuyển nhập kho</option>
                    <option value="import_csv">Import CSV</option>
                </select>
            </div>
            <div class="htw-field">
                <label class="htw-label">Từ khóa</label>
                <input type="text" id="log-keyword" class="htw-input" placeholder="Mã, mô tả, user…">
            </div>
            <div class="htw-field">
                <button id="htw-log-search-btn" class="htw-btn htw-btn-primary" style="width:100%;">
                    <span class="dashicons dashicons-search" style="font-size:16px;width:16px;height:16px;"></span>
                    Tìm kiếm
                </button>
            </div>
        </div>
    </div>

    <!-- ── Results table ─────────────────────────────────────────────────────── -->
    <div class="htw-table-wrap">
        <table class="htw-table" id="htw-log-table">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Người dùng</th>
                    <th>Hành động</th>
                    <th>Đối tượng</th>
                    <th>Mã</th>
                    <th>Mô tả</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody id="htw-log-tbody">
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--htw-text-muted);padding:48px;">
                        <span class="dashicons dashicons-search" style="font-size:28px;display:block;margin-bottom:8px;color:var(--htw-border);"></span>
                        Nhấn "Tìm kiếm" để tải dữ liệu nhật ký.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ── Summary + Pagination ──────────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;flex-wrap:wrap;gap:10px;">
        <div id="htw-log-summary" style="font-size:.8rem;color:var(--htw-text-muted);"></div>
        <div id="htw-log-pagination" style="display:none;display:flex;gap:5px;flex-wrap:wrap;"></div>
    </div>

</div>

<!-- ── Toast ──────────────────────────────────────────────────────────────────── -->
<div id="htw-log-toast" style="
    position:fixed;bottom:32px;right:32px;z-index:99999;
    border-radius:10px;padding:14px 20px;font-size:.875rem;font-weight:500;
    box-shadow:0 4px 20px rgba(0,0,0,.15);min-width:260px;max-width:420px;
    font-family:'Inter',sans-serif;display:none;line-height:1.5;
"></div>

<style>
    /* ── Action badge ──────────────────────────────────────────────────────────── */
    .htw-action-badge {
        display: inline-block;
        padding: 2px 9px;
        border-radius: 99px;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        white-space: nowrap;
    }

    .htw-ab-create {
        background: rgba(22, 163, 74, .1);
        color: #15803d;
    }

    .htw-ab-update {
        background: rgba(2, 132, 199, .1);
        color: #0369a1;
    }

    .htw-ab-delete {
        background: rgba(220, 38, 38, .1);
        color: #b91c1c;
    }

    .htw-ab-confirm {
        background: rgba(91, 80, 232, .1);
        color: var(--htw-primary);
    }

    .htw-ab-send_to_import {
        background: rgba(217, 119, 6, .1);
        color: #92400e;
    }

    .htw-ab-import_csv {
        background: rgba(20, 184, 166, .1);
        color: #0f766e;
    }

    .htw-ab-default {
        background: rgba(107, 114, 128, .1);
        color: var(--htw-text-muted);
    }

    /* ── Object type chip ─────────────────────────────────────────────────────── */
    .htw-obj-chip {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: .72rem;
        background: var(--htw-surface-2);
        color: var(--htw-text-muted);
        border: 1px solid var(--htw-border);
        white-space: nowrap;
    }

    /* ── Pagination ───────────────────────────────────────────────────────────── */
    .htw-pg-btn {
        padding: 4px 10px;
        border: 1px solid var(--htw-border);
        border-radius: 7px;
        background: var(--htw-surface);
        color: var(--htw-text);
        cursor: pointer;
        font-size: .8rem;
        font-family: 'Inter', sans-serif;
        font-weight: 500;
        transition: all .15s;
        line-height: 1.6;
    }

    .htw-pg-btn:hover:not(:disabled) {
        background: var(--htw-primary);
        color: #fff;
        border-color: var(--htw-primary);
    }

    .htw-pg-btn.active {
        background: var(--htw-primary);
        color: #fff;
        border-color: var(--htw-primary);
    }

    .htw-pg-btn:disabled {
        opacity: .4;
        cursor: not-allowed;
    }

    /* ── Toast animation ─────────────────────────────────────────────────────── */
    @keyframes htw-slide-up {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .htw-toast-show {
        display: block !important;
        animation: htw-slide-up .2s ease;
    }
</style>

<script>
/* HTW Activity Log – tương thích WordPress noConflict jQuery */
jQuery(document).ready(function($) {
        'use strict';

        /* ── Config from PHP constants ────────────────────────────────────────── */
        if (typeof htwAdmin !== 'undefined') {
            $('#htw-max-days').text((+htwAdmin.logMaxDays || 365).toLocaleString('vi-VN'));
            $('#htw-max-rows').text((+htwAdmin.logMaxRows || 50000).toLocaleString('vi-VN'));
        }

        /* ── State ────────────────────────────────────────────────────────────── */
        let currentPage = 1;
        let currentFilters = {};

        /* ── Label maps ───────────────────────────────────────────────────────── */
        const ACTION_LABELS = {
            create: 'Tạo mới',
            update: 'Cập nhật',
            delete: 'Xóa',
            confirm: 'Xác nhận',
            send_to_import: 'Chuyển NK',
            import_csv: 'Import CSV',
        };
        const OBJ_LABELS = {
            product: 'Sản phẩm',
            import_batch: 'Lô nhập kho',
            export_order: 'Đơn xuất kho',
            return_order: 'Đơn trả hàng',
            purchase_order: 'Đơn đặt hàng',
            po_payment: 'Thanh toán PO',
            supplier: 'Nhà cung cấp',
        };

        /* ── Helpers ──────────────────────────────────────────────────────────── */
        function esc(s) {
            return String(s || '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function getFilters() {
            return {
                date_from: $('#log-date-from').val(),
                date_to: $('#log-date-to').val(),
                object_type: $('#log-object-type').val(),
                log_action: $('#log-action').val(),
                keyword: $('#log-keyword').val(),
            };
        }

        /* ── Load logs ────────────────────────────────────────────────────────── */
        function loadLogs(page) {
            currentPage = page || 1;
            currentFilters = getFilters();

            const tbody = $('#htw-log-tbody');
            const summary = $('#htw-log-summary');
            const pager = $('#htw-log-pagination');

            tbody.html(
                '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--htw-text-muted);">' +
                '<span class="htw-spinner"></span> Đang tải…</td></tr>'
            );
            pager.hide().empty();
            summary.text('');

            var postData = jQuery.extend({
                    action: 'htw_activity_log_data',
                    nonce: htwAdmin.nonce,
                    page: currentPage
                }, currentFilters);

            $.post(htwAdmin.ajaxUrl, postData)
                .done(function(res) {
                    if (!res.success) {
                        tbody.html('<tr><td colspan="7" class="htw-alert htw-alert-error">Lỗi tải dữ liệu.</td></tr>');
                        return;
                    }

                    const d = res.data;
                    summary.text(
                        'Tìm thấy ' + d.total.toLocaleString('vi-VN') +
                        ' bản ghi — trang ' + d.page + '/' + d.total_pages
                    );

                    if (!d.rows || d.rows.length === 0) {
                        tbody.html(
                            '<tr><td colspan="7" style="text-align:center;color:var(--htw-text-muted);padding:40px;">' +
                            'Không tìm thấy bản ghi nào.</td></tr>'
                        );
                        return;
                    }

                    let html = '';
                    d.rows.forEach(function(r) {
                        const actionClass = 'htw-ab-' + (r.action in ACTION_LABELS ? r.action : 'default');
                        const actionLabel = ACTION_LABELS[r.action] || r.action;
                        const objLabel = OBJ_LABELS[r.object_type] || r.object_type;
                        const dt = r.created_at ? r.created_at.replace('T', ' ') : '';

                        html +=
                            '<tr>' +
                            '<td style="font-size:.78rem;white-space:nowrap;color:var(--htw-text-muted);">' + esc(dt) + '</td>' +
                            '<td style="font-weight:600;">' + esc(r.user_login) + '</td>' +
                            '<td><span class="htw-action-badge ' + actionClass + '">' + esc(actionLabel) + '</span></td>' +
                            '<td><span class="htw-obj-chip">' + esc(objLabel) + '</span></td>' +
                            '<td style="font-family:monospace;font-size:.8rem;color:var(--htw-primary);">' + esc(r.object_code) + '</td>' +
                            '<td style="font-size:.85rem;">' + esc(r.summary) + '</td>' +
                            '<td style="font-size:.75rem;color:var(--htw-text-muted);">' + esc(r.ip_address) + '</td>' +
                            '</tr>';
                    });
                    tbody.html(html);

                    if (d.total_pages > 1) {
                        buildPagination(d.page, d.total_pages);
                        pager.css('display', 'flex').show();
                    }
                })
                .fail(function() {
                    tbody.html('<tr><td colspan="7" class="htw-alert htw-alert-error">Lỗi kết nối máy chủ.</td></tr>');
                });
        }

        /* ── Pagination ───────────────────────────────────────────────────────── */
        function buildPagination(page, total) {
            const p = $('#htw-log-pagination');
            p.empty();

            function btn(label, targetPage, disabled, active) {
                return $('<button>')
                    .addClass('htw-pg-btn' + (active ? ' active' : ''))
                    .text(label)
                    .prop('disabled', !!disabled)
                    .on('click', function() {
                        if (!disabled) loadLogs(targetPage);
                    });
            }

            p.append(btn('«', 1, page === 1));
            p.append(btn('‹', page - 1, page === 1));

            const start = Math.max(1, page - 3);
            const end = Math.min(total, page + 3);
            for (let i = start; i <= end; i++) {
                p.append(btn(i, i, false, i === page));
            }

            p.append(btn('›', page + 1, page === total));
            p.append(btn('»', total, page === total));
        }

        /* ── CSV Export ───────────────────────────────────────────────────────── */
        $('#htw-log-export-btn').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).find('.dashicons').attr('class', 'dashicons dashicons-update htw-spinner');

            const form = $('<form>', {
                method: 'POST',
                action: htwAdmin.ajaxUrl,
                target: '_blank'
            });
            form.append($('<input>', {
                type: 'hidden',
                name: 'action',
                value: 'htw_export_activity_log'
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'nonce',
                value: htwAdmin.nonce
            }));
            const f = getFilters();
            Object.keys(f).forEach(function(k) {
                form.append($('<input>', {
                    type: 'hidden',
                    name: k,
                    value: f[k]
                }));
            });
            $('body').append(form);
            form.submit();
            form.remove();

            setTimeout(function() {
                btn.prop('disabled', false).find('.dashicons').attr('class', 'dashicons dashicons-download');
            }, 1800);
        });

        /* ── Manual Prune ─────────────────────────────────────────────────────── */
        $('#htw-log-prune-btn').on('click', function() {
            if (!confirm('Chạy dọn dẹp nhật ký ngay theo chính sách lưu trữ?\nThao tác này không thể hoàn tác.')) return;

            const btn = $(this);
            btn.prop('disabled', true).html(
                '<span class="htw-spinner" style="width:14px;height:14px;border-width:2px;"></span> Đang dọn…'
            );

            $.post(htwAdmin.ajaxUrl, {
                    action: 'htw_log_prune_now',
                    nonce: htwAdmin.nonce
                })
                .done(function(res) {
                    if (res.success) {
                        showToast(
                            (res.data.deleted > 0 ? '🗑️ ' : '✅ ') + res.data.message,
                            res.data.deleted > 0 ? '#16a34a' : '#5b50e8'
                        );
                        loadLogs(1);
                    } else {
                        showToast('❌ Dọn dẹp thất bại.', '#dc2626');
                    }
                })
                .fail(function() {
                    showToast('❌ Lỗi kết nối.', '#dc2626');
                })
                .always(function() {
                    btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;color:var(--htw-danger);"></span> Dọn ngay'
                    );
                });
        });

        /* ── Toast ────────────────────────────────────────────────────────────── */
        function showToast(msg, bg) {
            const t = $('#htw-log-toast');
            t.css({
                background: bg || '#1e293b',
                color: '#fff'
            }).text(msg);
            t.addClass('htw-toast-show');
            clearTimeout(t.data('timer'));
            t.data('timer', setTimeout(function() {
                t.removeClass('htw-toast-show').hide();
            }, 4000));
        }

        /* ── Event bindings ───────────────────────────────────────────────────── */
        $('#htw-log-search-btn').on('click', function() {
            loadLogs(1);
        });
        $('#log-keyword').on('keydown', function(e) {
            if (e.key === 'Enter') loadLogs(1);
        });

        /* ── Auto-load last 7 days on open ───────────────────────────────────── */
        const today = new Date();
        const from = new Date(today);
        from.setDate(today.getDate() - 6);
        const iso = function(d) {
            return d.toISOString().slice(0, 10);
        };
        $('#log-date-from').val(iso(from));
        $('#log-date-to').val(iso(today));
        loadLogs(1);

    });
</script>