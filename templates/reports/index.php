<?php defined('ABSPATH') || exit; ?>
<div class="htw-wrap" x-data="htwReports()" x-init="load()">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-chart-bar"></span> Báo cáo</h1>
    </div>

    <!-- Tabs -->
    <div class="htw-tabs">
        <button class="htw-tab" :class="{active: tab==='stock'}"             @click="switchTab('stock')">📦 Tồn kho</button>
        <button class="htw-tab" :class="{active: tab==='movement'}"         @click="switchTab('movement')">🔄 Nhập Xuất Tồn</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_product'}" @click="switchTab('profit_by_product')">📊 Lợi nhuận / SP</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_channel'}" @click="switchTab('profit_by_channel')">📡 Lợi nhuận / Kênh</button>
        <button class="htw-tab" :class="{active: tab==='product_performance'}" @click="switchTab('product_performance')">🏆 Hiệu suất SP</button>
        <button class="htw-tab" :class="{active: tab==='export'}"           @click="switchTab('export')">📥 Xuất báo cáo</button>
    </div>

    <!-- Date filter -->
    <div class="htw-search-bar" x-show="tab !== 'stock' && tab !== 'export'">
        <div class="htw-field" style="flex-direction:row;align-items:center;gap:20px;">
            <label class="htw-label" style="white-space:nowrap;">Từ ngày:</label>
            <input class="htw-input" type="date" x-model="dateFrom" style="max-width:160px;">
        </div>
        <div class="htw-field" style="flex-direction:row;align-items:center;gap:12px;">
            <label class="htw-label" style="white-space:nowrap;">Đến ngày:</label>
            <input class="htw-input" type="date" x-model="dateTo" style="max-width:160px;">
        </div>
        <button class="htw-btn htw-btn-primary htw-btn-sm" @click="load()">Xem báo cáo</button>
    </div>

    <!-- Loading -->
    <div x-show="loading" style="text-align:center;padding:40px;color:var(--htw-text-muted);">
        <span class="htw-spinner"></span> Đang tải…
    </div>

    <!-- ══ Tồn kho ══ -->
    <template x-if="!loading && tab === 'stock'">
        <div>
            <div style="margin-bottom:14px;display:flex;align-items:center;gap:16px;">
                <span style="color:var(--htw-text-muted);font-size:.85rem;" x-text="rows.length + ' sản phẩm'"></span>
                <span>|</span>
                <span style="font-size:.9rem;">Tổng giá trị kho: <strong style="color:var(--htw-info);" x-text="fmt(totalInvValue)"></strong></span>
            </div>
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Tên sản phẩm</th>
                            <th>Danh mục</th>
                            <th>ĐVT</th>
                            <th>Tồn kho</th>
                            <th>Giá vốn TB</th>
                            <th>Giá trị kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.id">
                            <tr>
                                <td x-text="r.sku || '—'"></td>
                                <td style="font-weight:500;" x-text="r.name"></td>
                                <td x-text="r.category || '—'"></td>
                                <td x-text="r.unit"></td>
                                <td :style="{color: parseFloat(r.current_stock) <= 5 ? 'var(--htw-danger)' : 'inherit'}" x-text="fmtNum(r.current_stock, 1)"></td>
                                <td x-text="fmt(r.avg_cost)"></td>
                                <td style="color:var(--htw-info);" x-text="fmt(r.inventory_value)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- ══ Nhập Xuất Tồn ══ -->
    <template x-if="!loading && tab === 'movement'">
        <div class="htw-table-wrap">
            <table class="htw-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Tên sản phẩm</th>
                        <th>ĐVT</th>
                        <th>Tồn đầu kỳ</th>
                        <th>Nhập</th>
                        <th>Xuất</th>
                        <th>Tồn cuối kỳ</th>
                        <th>Giá vốn TB</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in rows" :key="r.sku">
                        <tr>
                            <td x-text="r.sku || '—'"></td>
                            <td style="font-weight:500;" x-text="r.name"></td>
                            <td x-text="r.unit"></td>
                            <td x-text="fmtNum(r.opening_stock, 1)"></td>
                            <td style="color:var(--htw-success);" x-text="fmtNum(r.qty_in, 1)"></td>
                            <td style="color:var(--htw-warning);" x-text="fmtNum(r.qty_out, 1)"></td>
                            <td style="font-weight:700;" x-text="fmtNum(r.closing_stock, 1)"></td>
                            <td x-text="fmt(r.avg_cost)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ══ Lợi nhuận / Sản phẩm ══ -->
    <template x-if="!loading && tab === 'profit_by_product'">
        <div>
            <div style="margin-bottom:14px;display:flex;gap:24px;flex-wrap:wrap;">
                <span>Tổng doanh thu: <strong style="color:var(--htw-info);" x-text="fmt(totalRevenue)"></strong></span>
                <span>Tổng lợi nhuận: <strong style="color:var(--htw-success);" x-text="fmt(totalProfit)"></strong></span>
            </div>
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Tên sản phẩm</th>
                            <th>ĐVT</th>
                            <th>SL bán</th>
                            <th>Doanh thu</th>
                            <th>Giá vốn</th>
                            <th>Lợi nhuận</th>
                            <th>Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.name">
                            <tr>
                                <td x-text="r.sku || '—'"></td>
                                <td style="font-weight:500;" x-text="r.name"></td>
                                <td x-text="r.unit"></td>
                                <td x-text="fmtNum(r.total_qty, 1)"></td>
                                <td x-text="fmt(r.total_revenue)"></td>
                                <td x-text="fmt(r.total_cogs)"></td>
                                <td :style="{color:parseFloat(r.total_profit)>=0?'#22c55e':'#ef4444',fontWeight:600}" x-text="fmt(r.total_profit)"></td>
                                <td>
                                    <span :style="{color:parseFloat(r.margin_pct)>=20?'#22c55e':parseFloat(r.margin_pct)>=10?'#f59e0b':'#ef4444'}"
                                        x-text="r.margin_pct + '%'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- ══ Lợi nhuận / Kênh ══ -->
    <template x-if="!loading && tab === 'profit_by_channel'">
        <div>
            <div style="margin-bottom:14px;display:flex;gap:24px;flex-wrap:wrap;">
                <span>Tổng doanh thu: <strong style="color:var(--htw-info);" x-text="fmt(totalRevenue)"></strong></span>
                <span>Tổng lợi nhuận: <strong style="color:var(--htw-success);" x-text="fmt(totalProfit)"></strong></span>
            </div>
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th>Kênh bán</th>
                            <th>Số đơn</th>
                            <th>Doanh thu</th>
                            <th>Giá vốn</th>
                            <th>Lợi nhuận</th>
                            <th>Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.channel">
                            <tr>
                                <td><span :class="'htw-badge htw-badge-' + r.channel" x-text="channelLabel(r.channel)"></span></td>
                                <td x-text="r.total_orders"></td>
                                <td x-text="fmt(r.revenue)"></td>
                                <td x-text="fmt(r.cogs)"></td>
                                <td :style="{color:parseFloat(r.profit)>=0?'#22c55e':'#ef4444',fontWeight:600}" x-text="fmt(r.profit)"></td>
                                <td>
                                    <span :style="{color:parseFloat(r.margin_pct)>=20?'#22c55e':parseFloat(r.margin_pct)>=10?'#f59e0b':'#ef4444'}"
                                        x-text="r.margin_pct + '%'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- ══ Xuất báo cáo PDF ══ -->
    <template x-if="!loading && tab === 'export'">
        <div>
            <div class="htw-card" style="max-width:600px;">

                <h3 style="margin:0 0 20px;font-size:1.1rem;color:var(--htw-primary);display:flex;align-items:center;gap:8px;">
                    <span>📥</span> Xuất báo cáo PDF
                </h3>

                <!-- Loại báo cáo -->
                <div class="htw-field" style="margin-bottom:16px;">
                    <label class="htw-label" style="margin-bottom:6px;">Loại báo cáo</label>
                    <select class="htw-input" x-model="exportTab" style="max-width:360px;">
                        <option value="stock">📦 Báo cáo Tồn kho</option>
                        <option value="movement">🔄 Báo cáo Nhập - Xuất - Tồn</option>
                        <option value="profit_by_product">📊 Báo cáo Lợi nhuận theo Sản phẩm</option>
                        <option value="profit_by_channel">📡 Báo cáo Lợi nhuận theo Kênh bán</option>
                        <option value="product_performance">🏆 Báo cáo Hiệu suất Sản phẩm</option>
                    </select>
                </div>

                <!-- Khoảng thời gian -->
                <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;" x-show="exportTab !== 'stock'">
                    <div class="htw-field" style="flex-direction:row;align-items:center;gap:20px;margin-bottom:10px;">
                        <label class="htw-label" style="white-space:nowrap;">Từ ngày:</label>
                        <input class="htw-input" type="date" x-model="exportDateFrom" style="max-width:160px;">
                    </div>
                    <div class="htw-field" style="flex-direction:row;align-items:center;gap:12px;margin-bottom:0;">
                        <label class="htw-label" style="white-space:nowrap;">Đến ngày:</label>
                        <input class="htw-input" type="date" x-model="exportDateTo" style="max-width:160px;">
                    </div>
                </div>

                <!-- Ghi chú -->
                <div x-show="exportTab === 'stock'" style="margin-bottom:24px;color:var(--htw-text-muted);font-size:.85rem;">
                    Báo cáo tồn kho không cần chọn khoảng thời gian — xuất dữ liệu tồn kho hiện tại.
                </div>

                <!-- Nút xuất -->
                <button
                    class="htw-btn htw-btn-primary"
                    @click="exportPdf()"
                    :disabled="exporting"
                    style="min-width:180px;"
                >
                    <span x-show="!exporting">📄 Tải file PDF</span>
                    <span x-show="exporting">⏳ Đang tạo PDF…</span>
                </button>

                <p style="margin-top:14px;color:var(--htw-text-muted);font-size:.8rem;">
                    File PDF sẽ được tải về tự động. Kiểm tra trình duyệt nếu không thấy thông báo tải.
                </p>
            </div>
        </div>
    </template>

    <!-- ══ Hiệu suất Sản phẩm ══ -->
    <template x-if="!loading && tab === 'product_performance'">
        <div>

            <!-- Summary Cards — .htw-kpi-card như Dashboard -->
            <template x-if="topCards">
                <div class="htw-kpi-grid" style="margin-bottom:24px;">

                    <!-- Hiệu suất cao nhất -->
                    <template x-if="topCards.top_score">
                        <div class="htw-kpi-card green">
                            <div class="htw-kpi-label">Hiệu suất cao nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="topCards.top_score.name"></div>
                            <div class="htw-kpi-sub" x-text="'Score: ' + topCards.top_score.performance_score + ' đ | Margin: ' + topCards.top_score.margin_pct + '%'"></div>
                        </div>
                    </template>

                    <!-- Vòng quay vốn nhanh nhất -->
                    <template x-if="topCards.top_turnover">
                        <div class="htw-kpi-card blue">
                            <div class="htw-kpi-label">Vòng quay vốn nhanh nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="topCards.top_turnover.name"></div>
                            <div class="htw-kpi-sub" x-text="'Turnover: ' + topCards.top_turnover.turnover + 'x | Đã bán: ' + fmtNum(topCards.top_turnover.net_qty_sold, 1) + ' ' + topCards.top_turnover.unit"></div>
                        </div>
                    </template>

                    <!-- Lợi nhuận cao nhất -->
                    <template x-if="topCards.top_profit">
                        <div class="htw-kpi-card yellow">
                            <div class="htw-kpi-label">Lợi nhuận cao nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="topCards.top_profit.name"></div>
                            <div class="htw-kpi-sub" x-text="fmt(topCards.top_profit.net_profit) + ' | DT: ' + fmt(topCards.top_profit.net_revenue)"></div>
                        </div>
                    </template>

                    <!-- Cần xem xét lại -->
                    <template x-if="topCards.worst && topCards.worst.performance_score < 40">
                        <div class="htw-kpi-card red">
                            <div class="htw-kpi-label">Cần xem xét lại</div>
                            <div class="htw-kpi-value" style="font-size:1.2rem;" x-text="topCards.worst.name"></div>
                            <div class="htw-kpi-sub" x-text="'Score: ' + topCards.worst.performance_score + ' đ | Trả hàng: ' + topCards.worst.return_rate_pct + '%'"></div>
                        </div>
                    </template>

                </div>
            </template>

            <!-- Toolbar: filter + đếm -->
            <div class="htw-search-bar" style="margin-bottom:16px;">
                <span style="font-size:.8rem;color:var(--htw-text-muted);white-space:nowrap;">Lọc khuyến nghị:</span>
                <button class="htw-btn htw-btn-sm" :class="perfFilter==='all' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                    @click="perfFilter='all'">Tất cả</button>
                <button class="htw-btn htw-btn-sm" :class="perfFilter==='increase' ? 'htw-btn-success' : 'htw-btn-ghost'"
                    @click="perfFilter='increase'">Tăng vốn</button>
                <button class="htw-btn htw-btn-sm" :class="perfFilter==='maintain' ? 'htw-btn-warning' : 'htw-btn-ghost'"
                    @click="perfFilter='maintain'">Duy trì</button>
                <button class="htw-btn htw-btn-sm" :class="perfFilter==='review' ? 'htw-btn-danger' : 'htw-btn-ghost'"
                    @click="perfFilter='review'">Xem xét</button>
                <span style="margin-left:auto;font-size:.8rem;color:var(--htw-text-muted);" x-text="perfRows.length + ' sản phẩm'"></span>
            </div>

            <!-- Bảng chi tiết -->
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th style="cursor:pointer;user-select:none;" @click="sort('name')">
                                Sản phẩm<span x-text="sortIcon('name')"></span>
                            </th>
                            <th>ĐVT</th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('net_qty_sold')">
                                SL bán<span x-text="sortIcon('net_qty_sold')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('net_revenue')">
                                Doanh thu<span x-text="sortIcon('net_revenue')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('net_profit')">
                                Lợi nhuận<span x-text="sortIcon('net_profit')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('margin_pct')">
                                Margin %<span x-text="sortIcon('margin_pct')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('turnover')">
                                Vòng quay<span x-text="sortIcon('turnover')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:right;" @click="sort('return_rate_pct')">
                                Trả hàng %<span x-text="sortIcon('return_rate_pct')"></span>
                            </th>
                            <th style="cursor:pointer;user-select:none;text-align:center;" @click="sort('performance_score')">
                                Score<span x-text="sortIcon('performance_score')"></span>
                            </th>
                            <th style="text-align:center;">Khuyến nghị</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in perfRows" :key="r.product_id">
                            <tr>
                                <td>
                                    <div style="font-weight:600;" x-text="r.name"></div>
                                    <div style="font-size:.75rem;color:var(--htw-text-muted);" x-text="r.sku || ''"></div>
                                </td>
                                <td x-text="r.unit"></td>
                                <td style="text-align:right;" x-text="fmtNum(r.net_qty_sold, 1)"></td>
                                <td style="text-align:right;" x-text="fmt(r.net_revenue)"></td>
                                <td style="text-align:right;font-weight:600;"
                                    :style="{color: parseFloat(r.net_profit) >= 0 ? 'var(--htw-success)' : 'var(--htw-danger)'}"
                                    x-text="fmt(r.net_profit)"></td>
                                <td style="text-align:right;">
                                    <span :style="{color: parseFloat(r.margin_pct)>=20 ? 'var(--htw-success)' : parseFloat(r.margin_pct)>=10 ? 'var(--htw-warning)' : 'var(--htw-danger)'}"
                                          x-text="r.margin_pct + '%'"></span>
                                </td>
                                <td style="text-align:right;font-weight:600;"
                                    :style="{color: parseFloat(r.turnover)>=1 ? 'var(--htw-info)' : 'var(--htw-text-muted)'}"
                                    x-text="r.turnover + 'x'"></td>
                                <td style="text-align:right;"
                                    :style="{color: parseFloat(r.return_rate_pct)>10 ? 'var(--htw-danger)' : 'var(--htw-text-muted)'}"
                                    x-text="r.return_rate_pct + '%'"></td>
                                <td style="text-align:center;">
                                    <span class="htw-badge"
                                        :class="parseFloat(r.performance_score)>=70 ? 'htw-badge-confirmed' : parseFloat(r.performance_score)>=40 ? 'htw-badge-warning' : 'htw-badge-draft'"
                                        x-text="r.performance_score + ' đ'"></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="htw-badge"
                                        :class="r.recommendation==='increase' ? 'htw-badge-confirmed' : r.recommendation==='maintain' ? 'htw-badge-warning' : 'htw-badge-draft'"
                                        x-text="recoLabel(r.recommendation)"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Ghi chú công thức -->
            <div class="htw-chart-card" style="margin-top:16px;padding:14px 18px;">
                <span style="font-size:.8rem;color:var(--htw-text-muted);">
                    <strong style="color:var(--htw-text);">Score</strong> = Vòng quay vốn (35%) + Biên LN (30%) + Tổng LN (25%) + Nghịch tỷ lệ trả hàng (10%).
                    Điểm chuẩn hoá trong kỳ — cao nhất = 100 đ.
                    &nbsp;|&nbsp;
                    <span class="htw-badge htw-badge-confirmed">≥ 70 Tăng vốn</span>&nbsp;
                    <span class="htw-badge htw-badge-warning">40–69 Duy trì</span>&nbsp;
                    <span class="htw-badge htw-badge-draft">&lt; 40 Xem xét</span>
                </span>
            </div>

        </div>
    </template>

    <!-- Empty state -->
    <template x-if="!loading && !rows.length">
        <div style="text-align:center;padding:48px;color:var(--htw-text-muted);">
            <div style="font-size:2rem;">📭</div>
            <div style="margin-top:8px;">Không có dữ liệu trong khoảng thời gian này.</div>
        </div>
    </template>

</div>