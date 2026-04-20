<?php
defined('ABSPATH') || exit;

$status   = $status ?? [];
$next_run = $next_run ?? null;
?>
<script>
window._htwSnapshots = <?php echo wp_json_encode($snapshots); ?>;
window._htwSnapshotStatus = <?php echo wp_json_encode($status); ?>;
window._htwNextRun = <?php echo $next_run ? wp_json_encode(date_i18n('d/m/Y H:i', $next_run)) : 'null'; ?>;
</script>

<div class="htw-wrap" x-data="htwSnapshots" x-init="init()">

    <!-- Page Header -->
    <div class="htw-page-header">
        <div>
            <h1 class="htw-page-title">
                <span class="dashicons dashicons-backup"></span> Sao lưu dữ liệu
            </h1>
            <p style="color:var(--htw-text-muted);font-size:.85rem;margin-top:3px;">
                Snapshot toàn bộ dữ liệu kho hàng. Giữ tối thiểu 7 ngày gần nhất.
            </p>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
            <span
                class="htw-badge"
                :class="scheduled ? 'htw-badge-success' : 'htw-badge-warning'"
                x-text="scheduled ? 'Auto: Bật' : 'Auto: Tắt'">
            </span>
            <button
                x-show="!scheduled"
                class="htw-btn htw-btn-ghost htw-btn-sm"
                @click="reschedule()"
                title="Đặt lại cron hàng ngày">
                <span class="dashicons dashicons-clock" style="font-size:.9rem;"></span>
                Đặt lại lịch
            </button>
            <button class="htw-btn htw-btn-primary" @click="createSnapshot()" :disabled="creating">
                <span x-show="creating" class="htw-spinner"></span>
                <span x-show="!creating" class="dashicons dashicons-plus-alt" style="font-size:.9rem;"></span>
                <span x-text="creating ? 'Đang tạo…' : 'Tạo snapshot ngay'"></span>
            </button>
        </div>
    </div>

    <!-- KPI Cards: 4 stats + last run info -->
    <div class="htw-kpi-grid">
        <!-- Count -->
        <div class="htw-kpi-card purple">
            <div class="htw-kpi-label">Snapshot đã lưu</div>
            <div class="htw-kpi-value" x-text="snapshots.length"></div>
            <div class="htw-kpi-sub">bản trên server</div>
        </div>
        <!-- Auto status -->
        <div class="htw-kpi-card blue">
            <div class="htw-kpi-label">Tự động chạy</div>
            <div class="htw-kpi-value" :style="scheduled ? 'color:var(--htw-success)' : 'color:var(--htw-warning)'"
                 x-text="scheduled ? 'Hàng ngày' : 'Chưa bật'"></div>
            <div class="htw-kpi-sub" x-text="nextRunStr ? 'Lần tiếp: ' + nextRunStr : '02:00 AM mỗi ngày'"></div>
        </div>
        <!-- Last run -->
        <div class="htw-kpi-card green">
            <div class="htw-kpi-label">Lần chạy gần nhất</div>
            <div class="htw-kpi-value" style="font-size:1.1rem;" x-text="lastRun || '—'"></div>
            <div class="htw-kpi-sub" x-text="lastStatus && lastStatus.duration_ms ? 'Hoàn tất trong ' + lastStatus.duration_ms + 'ms' : ''"></div>
        </div>
        <!-- Error warning -->
        <div class="htw-kpi-card red" x-show="lastStatus && !lastStatus.success">
            <div class="htw-kpi-label">Trạng thái gần nhất</div>
            <div class="htw-kpi-value" style="font-size:1rem;color:var(--htw-danger);">Lỗi</div>
            <div class="htw-kpi-sub" x-text="lastStatus && lastStatus.errors ? lastStatus.errors[0] : ''"></div>
        </div>
        <!-- OK status -->
        <div class="htw-kpi-card green" x-show="lastStatus && lastStatus.success">
            <div class="htw-kpi-label">Trạng thái gần nhất</div>
            <div class="htw-kpi-value" style="font-size:1rem;color:var(--htw-success);">OK</div>
            <div class="htw-kpi-sub">Không có lỗi</div>
        </div>
    </div>

    <!-- Section: Snapshot History Table -->
    <div style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
        <h2 style="font-size:1rem;font-weight:700;color:var(--htw-text);margin:0;">Lịch sử snapshot</h2>
        <span style="font-size:.78rem;color:var(--htw-text-muted);">Tự động xoá snapshot cũ hơn 7 ngày</span>
    </div>

    <!-- Empty state -->
    <template x-if="!snapshots.length">
        <div style="background:var(--htw-surface);border:1px solid var(--htw-border);border-radius:var(--htw-radius);padding:52px;text-align:center;color:var(--htw-text-muted);">
            <span class="dashicons dashicons-backup" style="font-size:2.5rem;opacity:.3;"></span>
            <p style="margin-top:12px;font-size:.9rem;">Chưa có snapshot nào. Nhấn <strong>"Tạo snapshot ngay"</strong> để tạo bản đầu tiên.</p>
        </div>
    </template>

    <!-- Snapshot table -->
    <template x-if="snapshots.length">
        <div class="htw-table-wrap">
            <table class="htw-table">
                <thead>
                    <tr>
                        <th>Ngày tạo</th>
                        <th>Dung lượng</th>
                        <th style="text-align:right;">Sản phẩm</th>
                        <th style="text-align:right;">NCC</th>
                        <th style="text-align:right;">Lô nhập</th>
                        <th style="text-align:right;">Đơn xuất</th>
                        <th style="text-align:right;">Đơn đặt</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="s in snapshots" :key="s.filename">
                        <tr>
                            <td style="font-weight:600;white-space:nowrap;color:var(--htw-primary);" x-text="fmtDate(s.created_at)"></td>
                            <td style="color:var(--htw-text-muted);" x-text="fmtSize(s.size_bytes)"></td>
                            <td style="text-align:right;" x-text="rowCnt(s, 'htw_products')"></td>
                            <td style="text-align:right;" x-text="rowCnt(s, 'htw_suppliers')"></td>
                            <td style="text-align:right;" x-text="rowCnt(s, 'htw_import_batches')"></td>
                            <td style="text-align:right;" x-text="rowCnt(s, 'htw_export_orders')"></td>
                            <td style="text-align:right;" x-text="rowCnt(s, 'htw_purchase_orders')"></td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <button class="htw-btn htw-btn-success htw-btn-sm" @click="confirmRestore(s)" title="Khôi phục">
                                        <span class="dashicons dashicons-undo" style="font-size:.85rem;"></span> Khôi phục
                                    </button>
                                    <!-- D6 fix: download via AJAX endpoint with nonce + auth check instead of public URL -->
                                    <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="downloadSnapshot(s)" title="'Tải ' + s.filename">
                                        <span class="dashicons dashicons-download" style="font-size:.85rem;"></span>
                                    </button>
                                    <button class="htw-btn htw-btn-danger htw-btn-sm" @click="deleteSnapshot(s)" title="Xoá">
                                        <span class="dashicons dashicons-trash" style="font-size:.85rem;"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- Restore Confirm Modal -->
    <div class="htw-modal-overlay" x-show="restoreModal" x-cloak @click.self="restoreModal=false">
        <div class="htw-modal" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-warning" style="color:var(--htw-warning);"></span>
                Xác nhận khôi phục dữ liệu
            </div>

            <template x-if="restoreTarget">
                <div>
                    <div style="background:var(--htw-surface-2);border:1px solid var(--htw-border);border-radius:10px;padding:18px 22px;margin-bottom:18px;">
                        <div style="font-size:.75rem;font-weight:600;color:var(--htw-text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">
                            Đang khôi phục snapshot
                        </div>
                        <div style="font-size:1.15rem;font-weight:700;color:var(--htw-text);" x-text="fmtDate(restoreTarget.created_at)"></div>
                        <div style="font-size:.82rem;color:var(--htw-text-muted);margin-top:4px;">
                            Dung lượng: <span x-text="fmtSize(restoreTarget.size_bytes)"></span>
                        </div>
                    </div>

                    <!-- Warning box -->
                    <div style="background:#fff8f8;border:1px solid rgba(220,38,38,.2);border-radius:10px;padding:16px 18px;margin-bottom:16px;">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <span class="dashicons dashicons-warning" style="color:var(--htw-danger);margin-top:1px;flex-shrink:0;"></span>
                            <div>
                                <div style="font-weight:700;font-size:.88rem;color:var(--htw-danger);margin-bottom:8px;">Cảnh báo quan trọng</div>
                                <ul style="margin:0;padding-left:16px;color:var(--htw-text-muted);font-size:.85rem;line-height:1.8;">
                                    <li>Dữ liệu <strong>hiện tại</strong> sẽ bị <strong style="color:var(--htw-danger);">xoá hoàn toàn</strong> và thay bằng dữ liệu trong snapshot.</li>
                                    <li>Hệ thống tự động tạo <strong>backup khẩn cấp</strong> trước khi restore — bạn có thể dùng để rollback ngược.</li>
                                    <li>Sau khi khôi phục, plugin hoạt động bình thường.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- What will be restored -->
                    <template x-if="restoreTarget.row_counts">
                        <div style="margin-bottom:16px;">
                            <div style="font-size:.8rem;font-weight:600;color:var(--htw-text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
                                Dữ liệu sẽ khôi phục
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <template x-for="(count, key) in restoreTarget.row_counts" :key="key">
                                    <div style="background:var(--htw-surface-2);border:1px solid var(--htw-border);border-radius:8px;padding:6px 12px;font-size:.8rem;">
                                        <span style="color:var(--htw-text-muted);" x-text="key.replace('htw_','').replace('_',' ').toUpperCase()"></span>
                                        <span style="font-weight:700;color:var(--htw-primary);margin-left:6px;" x-text="count + ' rows'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="restoreModal=false">Huỷ</button>
                <button class="htw-btn" style="background:var(--htw-warning);border-color:var(--htw-warning);color:#fff;" @click="doRestore()" :disabled="restoring">
                    <span x-show="restoring" class="htw-spinner"></span>
                    <span x-text="restoring ? 'Đang khôi phục…' : 'Xác nhận khôi phục'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div
        x-show="toast.show"
        x-transition:enter="htw-toast-enter"
        x-transition:enter-end="htw-toast-enter-end"
        x-transition:leave="htw-toast-leave"
        x-transition:leave-end="htw-toast-leave-end"
        :class="toast.type === 'success' ? 'htw-toast-success' : 'htw-toast-error'"
        style="position:fixed;bottom:28px;right:28px;z-index:99999;pointer-events:none;"
    >
        <div style="display:flex;align-items:center;gap:10px;padding:14px 20px;">
            <span x-show="toast.type === 'success'" class="dashicons dashicons-yes-alt2" style="font-size:1rem;"></span>
            <span x-show="toast.type === 'error'" class="dashicons dashicons-warning" style="font-size:1rem;"></span>
            <span style="font-weight:500;font-size:.875rem;" x-text="toast.message"></span>
        </div>
    </div>

</div>