<?php defined('ABSPATH') || exit; ?>
<div class="htw-wrap" x-data="htwDashboard()" x-init="init()">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-store"></span> Dashboard</h1>
        <span style="color:var(--htw-text-muted);font-size:.8rem;"><?php echo esc_html(date_i18n('d/m/Y', current_time('timestamp'))); ?></span>
    </div>

    <!-- KPI Cards -->
    <div class="htw-kpi-grid">
        <div class="htw-kpi-card purple">
            <div class="htw-kpi-label">Tổng sản phẩm</div>
            <div class="htw-kpi-value" x-text="kpi.total_products ?? '—'"></div>
            <div class="htw-kpi-sub">Trong danh mục</div>
        </div>
        <div class="htw-kpi-card blue">
            <div class="htw-kpi-label">Giá trị tồn kho</div>
            <div class="htw-kpi-value" x-text="kpi.inventory_value !== undefined ? fmt(kpi.inventory_value) : '—'"></div>
            <div class="htw-kpi-sub">Theo giá vốn bình quân</div>
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
        <div class="htw-kpi-card yellow">
            <div class="htw-kpi-label">Đơn đặt hàng đang xử lý</div>
            <div class="htw-kpi-value" x-text="kpi.po_active ?? '—'"></div>
            <div class="htw-kpi-sub">Đơn PO đang mở</div>
        </div>
        <div class="htw-kpi-card red">
            <div class="htw-kpi-label">Công nợ NCC</div>
            <div class="htw-kpi-value" x-text="kpi.po_debt !== undefined ? fmt(kpi.po_debt) : '—'"></div>
            <div class="htw-kpi-sub">Tổng nợ phải trả</div>
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
    <?php if (!empty($low_stock)) : ?>
    <div class="htw-card" style="margin-top:18px;">
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
                    <?php foreach ($low_stock as $p) : ?>
                    <tr>
                        <td><?= esc_html($p['sku']) ?></td>
                        <td><?= esc_html($p['name']) ?></td>
                        <td style="color:var(--htw-danger);font-weight:700;"><?= number_format((float)$p['current_stock'], 0) ?></td>
                        <td><?= esc_html($p['unit']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>