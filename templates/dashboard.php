<?php defined('ABSPATH') || exit; ?>
<script>window._htwLowStock = <?php echo wp_json_encode($low_stock); ?>;</script>
<div class="htw-wrap" x-data="htwDashboard()">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-store"></span> Dashboard</h1>
        <span style="color:var(--htw-text-muted);font-size:.8rem;"><?php echo esc_html(date_i18n('d/m/Y', current_time('timestamp'))); ?></span>
    </div>

    <!-- KPI Cards -->
    <div class="htw-kpi-grid">
        <div class="htw-kpi-card purple">
            <div class="htw-kpi-label">Số SP tồn kho</div>
            <div class="htw-kpi-value" x-text="kpi.total_stock_qty !== undefined ? fmtNum(kpi.total_stock_qty, 0) : '—'"></div>
            <div class="htw-kpi-sub">Đơn vị SP đang lưu kho</div>
        </div>
        <div class="htw-kpi-card blue">
            <div class="htw-kpi-label">Giá trị hàng tồn kho</div>
            <div class="htw-kpi-value" x-text="kpi.inventory_value !== undefined ? fmt(kpi.inventory_value) : '—'"></div>
            <div class="htw-kpi-sub">Theo giá vốn bình quân</div>
        </div>
        <div class="htw-kpi-card orange">
            <div class="htw-kpi-label">Hàng đặt chưa về</div>
            <div class="htw-kpi-value" x-text="kpi.po_pending_value !== undefined ? fmt(kpi.po_pending_value) : '—'"></div>
            <div class="htw-kpi-sub" x-text="(kpi.po_active ?? 0) + ' đơn đặt NCC có hàng chưa về'"></div>
        </div>
        <div class="htw-kpi-card red">
            <div class="htw-kpi-label">Công nợ NCC</div>
            <div class="htw-kpi-value" x-text="kpi.po_debt !== undefined ? fmt(kpi.po_debt) : '—'"></div>
            <div class="htw-kpi-sub">Tổng nợ phải trả</div>
        </div>
        <div class="htw-kpi-card teal">
            <div class="htw-kpi-label">Đã thanh toán NCC</div>
            <div class="htw-kpi-value" x-text="kpi.po_paid_total !== undefined ? fmt(kpi.po_paid_total) : '—'"></div>
            <div class="htw-kpi-sub">Tổng tiền đã trả nhà cung cấp</div>
        </div>
        <div class="htw-kpi-card green">
            <div class="htw-kpi-label">Doanh thu tháng này</div>
            <div class="htw-kpi-value" x-text="kpi.revenue !== undefined ? fmt(kpi.revenue) : '—'"></div>
            <div class="htw-kpi-sub" x-text="(kpi.total_orders ?? 0) + ' đơn hàng'"></div>
        </div>
        <div class="htw-kpi-card yellow">
            <div class="htw-kpi-label">Lợi nhuận tháng này</div>
            <div class="htw-kpi-value" x-text="kpi.profit !== undefined ? fmt(kpi.profit) : '—'"></div>
            <div class="htw-kpi-sub" x-text="kpi.revenue > 0 ? 'Margin: ' + Math.round(kpi.profit/kpi.revenue*100) + '%' : ''"></div>
        </div>
    </div>

    <!-- Charts + top5 -->
    <div class="htw-chart-grid">
        <div class="htw-chart-card">
            <div class="htw-chart-title">📊 Doanh thu &amp; Lợi nhuận 6 tháng</div>
            <canvas id="htwRevenueChart" height="220"></canvas>
        </div>
        <div class="htw-chart-card">
            <div class="htw-chart-title">🏆 Top 5 sản phẩm tháng này</div>
            <template x-if="!top5.length">
                <p style="color:var(--htw-text-muted);font-size:.85rem;">Chưa có dữ liệu.</p>
            </template>
            <div x-show="top5.length">
                <template x-for="(p, i) in top5" :key="i">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--htw-border);">
                        <span style="font-size:.85rem;color:var(--htw-text);" x-text="(i+1) + '. ' + p.name"></span>
                        <span style="font-size:.8rem;color:var(--htw-success);" x-text="fmt(p.profit)"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Low stock -->
    <div class="htw-card" style="margin-top:18px;" x-show="lowStock && lowStock.length">
        <div class="htw-chart-title" style="margin-bottom:12px">⚠️ Hàng sắp hết kho</div>
        <div class="htw-table-wrap">
            <table class="htw-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Tên sản phẩm</th>
                        <th>Tồn kho</th>
                        <th>ĐVT</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="p in lowStock" :key="p.sku">
                    <tr>
                        <td><span x-text="p.sku || '—'"></span></td>
                        <td><span x-text="p.name || '—'"></span></td>
                        <td style="color:var(--htw-danger);font-weight:700;" x-text="parseFloat(p.current_stock || 0).toLocaleString('vi-VN')"></td>
                        <td><span x-text="p.unit || '—'"></span></td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

</div>