<?php defined('ABSPATH') || exit; ?>
<div class="htw-wrap" x-data="htwReports()" x-init="load()">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-chart-bar"></span> Báo cáo</h1>
    </div>

    <!-- Tabs -->
    <div class="htw-tabs">
        <button class="htw-tab" :class="{active: tab==='stock'}" @click="switchTab('stock')">📦 Tồn kho</button>
        <button class="htw-tab" :class="{active: tab==='movement'}" @click="switchTab('movement')">🔄 Nhập Xuất Tồn</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_product'}" @click="switchTab('profit_by_product')">📊 Lợi nhuận / SP</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_channel'}" @click="switchTab('profit_by_channel')">📡 Lợi nhuận / Kênh</button>
        <button class="htw-tab" :class="{active: tab==='product_performance'}" @click="switchTab('product_performance')">🏆 Hiệu suất SP</button>
        <button class="htw-tab" :class="{active: tab==='supplier_scorecard'}" @click="switchTab('supplier_scorecard')">🏭 Đánh giá NCC</button>
        <button class="htw-tab" :class="{active: tab==='export'}" @click="switchTab('export')">📥 Xuất báo cáo</button>
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
            <div style="margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="color:var(--htw-text-muted);font-size:.85rem;" x-text="rows.length + ' dòng sản phẩm'"></span>
                <span style="color:var(--htw-text-muted);">|</span>
                <span style="font-size:.9rem;">Tổng SL tồn: <strong style="color:var(--htw-success);" x-text="fmtNum(totalStock, 0) + ' SP'"></strong></span>
                <span style="color:var(--htw-text-muted);">|</span>
                <span style="font-size:.9rem;">Tổng giá trị kho: <strong style="color:var(--htw-info);" x-text="fmt(totalInvValue)"></strong></span>
                <span style="color:var(--htw-text-muted);">|</span>
                <span style="font-size:.9rem;">DT tiềm năng: <strong style="color:var(--htw-warning,#f59e0b);" x-text="fmt(rows.reduce(function(s,r){ return s + (parseFloat(r.suggested_price)>0 ? parseFloat(r.suggested_price)*parseFloat(r.current_stock) : 0); }, 0))"></strong></span>
            </div>
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Tên sản phẩm</th>
                            <th>Danh mục</th>
                            <th>ĐVT</th>
                            <th>SL tồn kho</th>
                            <th>Giá vốn TB</th>
                            <th>Giá trị lưu kho</th>
                            <th>Giá bán đề xuất</th>
                            <th>DT tiềm năng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.id">
                            <tr>
                                <td x-text="r.sku || '—'"></td>
                                <td style="font-weight:500;" x-text="r.name"></td>
                                <td x-text="r.category || '—'"></td>
                                <td x-text="r.unit"></td>
                                <td :style="{color: parseFloat(r.current_stock) <= 5 ? 'var(--htw-danger)' : 'inherit'}" x-text="fmtNum(r.current_stock, 0)"></td>
                                <td x-text="fmt(r.avg_cost)"></td>
                                <td style="color:var(--htw-info);" x-text="fmt(r.inventory_value)"></td>
                                <td style="color:var(--htw-warning,#f59e0b);" x-text="parseFloat(r.suggested_price)>0 ? fmt(r.suggested_price) : '—'"></td>
                                <td style="color:var(--htw-warning,#f59e0b);font-weight:600;" x-text="parseFloat(r.suggested_price)>0 ? fmt(parseFloat(r.suggested_price)*parseFloat(r.current_stock)) : '—'"></td>
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
                            <td x-text="fmtNum(r.opening_stock, 0)"></td>
                            <td style="color:var(--htw-success);" x-text="fmtNum(r.qty_in, 0)"></td>
                            <td style="color:var(--htw-warning);" x-text="fmtNum(r.qty_out, 0)"></td>
                            <td style="font-weight:700;" x-text="fmtNum(r.closing_stock, 0)"></td>
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
                    style="min-width:180px;">
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

    <!-- ══ Đánh giá Nhà Cung Cấp ══ -->
    <template x-if="!loading && tab === 'supplier_scorecard'">
        <div>

            <!-- KPI Cards -->
            <template x-if="supplierKpi">
                <div class="htw-kpi-grid" style="margin-bottom:24px;">

                    <!-- Giao nhanh nhất -->
                    <template x-if="supplierKpi.fastest">
                        <div class="htw-kpi-card green">
                            <div class="htw-kpi-label">⚡ Giao hàng nhanh nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.15rem;" x-text="supplierKpi.fastest.supplier_name"></div>
                            <div class="htw-kpi-sub" x-text="'TB ' + supplierKpi.fastest.avg_lead_time_days + ' ngày | ' + supplierKpi.fastest.total_orders + ' đơn'"></div>
                        </div>
                    </template>

                    <!-- Phí TB/đơn thấp nhất -->
                    <template x-if="supplierKpi.cheapest_fee">
                        <div class="htw-kpi-card yellow">
                            <div class="htw-kpi-label">💰 Phí TB/đơn thấp nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.15rem;" x-text="supplierKpi.cheapest_fee.supplier_name"></div>
                            <div class="htw-kpi-sub" x-text="fmt(supplierKpi.cheapest_fee.avg_fee_per_order) + '/đơn | ' + supplierKpi.cheapest_fee.total_orders + ' đơn'"></div>
                        </div>
                    </template>

                    <!-- Nhiều đơn nhất -->
                    <template x-if="supplierKpi.most_orders">
                        <div class="htw-kpi-card blue">
                            <div class="htw-kpi-label">📦 Nhiều đơn nhất</div>
                            <div class="htw-kpi-value" style="font-size:1.15rem;" x-text="supplierKpi.most_orders.supplier_name"></div>
                            <div class="htw-kpi-sub" x-text="supplierKpi.most_orders.total_orders + ' đơn đặt hàng | ' + fmtNum(supplierKpi.most_orders.total_qty,0) + ' sản phẩm'"></div>
                        </div>
                    </template>

                </div>
            </template>

            <!-- Chart + toggle -->
            <div class="htw-chart-card" style="margin-bottom:20px;padding:18px 20px;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <span style="font-weight:600;font-size:.95rem;color:var(--htw-text);">So sánh trực quan</span>
                    <div style="display:flex;gap:6px;margin-left:auto;">
                        <button class="htw-btn htw-btn-sm"
                            :class="supplierChartMode==='lead_time' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                            @click="supplierChartMode='lead_time'; renderSupplierChart()">⏱ Thời gian giao</button>
                        <button class="htw-btn htw-btn-sm"
                            :class="supplierChartMode==='cost' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                            @click="supplierChartMode='cost'; renderSupplierChart()">💸 Chi phí/ĐV</button>
                        <button class="htw-btn htw-btn-sm"
                            :class="supplierChartMode==='total' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                            @click="supplierChartMode='total'; renderSupplierChart()">🏦 Tổng chi phí</button>
                    </div>
                </div>
                <div style="position:relative;height:260px;max-height:260px;overflow:hidden;">
                    <canvas id="supplierScorecardChart"></canvas>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="htw-search-bar" style="margin-bottom:14px;">
                <span style="font-size:.8rem;color:var(--htw-text-muted);white-space:nowrap;">Sắp xếp theo:</span>
                <button class="htw-btn htw-btn-sm" :class="supplierSortKey==='avg_lead_time_days' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                    @click="supplierSort('avg_lead_time_days')">⏱ TG giao</button>
                <button class="htw-btn htw-btn-sm" :class="supplierSortKey==='avg_cost_per_unit' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                    @click="supplierSort('avg_cost_per_unit')">💰 Chi phí/ĐV</button>
                <button class="htw-btn htw-btn-sm" :class="supplierSortKey==='total_landed_cost' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                    @click="supplierSort('total_landed_cost')">🏦 Tổng CP</button>
                <button class="htw-btn htw-btn-sm" :class="supplierSortKey==='total_orders' ? 'htw-btn-primary' : 'htw-btn-ghost'"
                    @click="supplierSort('total_orders')">📦 Số đơn</button>
                <span style="margin-left:auto;font-size:.8rem;color:var(--htw-text-muted);" x-text="supplierRows.length + ' nhà cung cấp'"></span>
            </div>

            <!-- Bảng chi tiết -->
            <div class="htw-table-wrap">
                <table class="htw-table">
                    <thead>
                        <tr>
                            <th style="cursor:pointer;" @click="supplierSort('supplier_name')">
                                Nhà cung cấp<span x-text="supplierSortIcon('supplier_name')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('total_orders')">
                                Số đơn<span x-text="supplierSortIcon('total_orders')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('total_goods')">
                                Tiền hàng<span x-text="supplierSortIcon('total_goods')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('total_fees')">
                                Tổng phí<span x-text="supplierSortIcon('total_fees')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('fee_ratio_pct')">
                                Phí %<span x-text="supplierSortIcon('fee_ratio_pct')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('avg_fee_per_order')">
                                Phí TB/đơn<span x-text="supplierSortIcon('avg_fee_per_order')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('total_landed_cost')">
                                Tổng chi phí<span x-text="supplierSortIcon('total_landed_cost')"></span>
                            </th>
                            <th style="text-align:right;cursor:pointer;" @click="supplierSort('avg_cost_per_unit')">
                                Chi phí/ĐV<span x-text="supplierSortIcon('avg_cost_per_unit')"></span>
                            </th>
                            <th style="text-align:center;cursor:pointer;" @click="supplierSort('avg_lead_time_days')">
                                TG giao TB<span x-text="supplierSortIcon('avg_lead_time_days')"></span>
                            </th>
                            <th style="text-align:center;cursor:pointer;" @click="supplierSort('min_lead_time_days')">
                                Nhanh nhất<span x-text="supplierSortIcon('min_lead_time_days')"></span>
                            </th>
                            <th style="text-align:center;cursor:pointer;" @click="supplierSort('max_lead_time_days')">
                                Chậm nhất<span x-text="supplierSortIcon('max_lead_time_days')"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in supplierRows" :key="r.supplier_id + '_' + r.supplier_name">
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <span x-show="r.supplier_code" x-text="r.supplier_code"
                                              class="htw-badge htw-badge-info"
                                              style="font-family:monospace;font-size:.72rem;letter-spacing:.5px;"></span>
                                        <span style="font-weight:600;" x-text="r.supplier_name"></span>
                                    </div>
                                    <div style="font-size:.75rem;color:var(--htw-text-muted);" x-text="r.total_orders + ' đơn · ' + fmtNum(r.total_qty,0) + ' sản phẩm'"></div>
                                </td>
                                <td style="text-align:right;font-weight:600;" x-text="r.total_orders"></td>
                                <td style="text-align:right;" x-text="fmt(r.total_goods)"></td>
                                <td style="text-align:right;color:var(--htw-warning);" x-text="fmt(r.total_fees)"></td>
                                <td style="text-align:right;">
                                    <span :style="{color: parseFloat(r.fee_ratio_pct)>15 ? 'var(--htw-danger)' : parseFloat(r.fee_ratio_pct)>8 ? 'var(--htw-warning)' : 'var(--htw-success)'}"
                                        x-text="r.fee_ratio_pct + '%'"></span>
                                </td>
                                <td style="text-align:right;color:var(--htw-warning);font-weight:600;" x-text="fmt(r.avg_fee_per_order)"></td>
                                <td style="text-align:right;font-weight:700;color:var(--htw-info);" x-text="fmt(r.total_landed_cost)"></td>
                                <td style="text-align:right;font-weight:600;" x-text="r.avg_cost_per_unit > 0 ? fmt(r.avg_cost_per_unit) : '—'"></td>
                                <td style="text-align:center;">
                                    <template x-if="r.avg_lead_time_days !== null">
                                        <span class="htw-badge" :class="leadTimeBadgeClass(r.avg_lead_time_days)"
                                            x-text="r.avg_lead_time_days + ' ngày'"></span>
                                    </template>
                                    <template x-if="r.avg_lead_time_days === null">
                                        <span style="color:var(--htw-text-muted);">—</span>
                                    </template>
                                </td>
                                <td style="text-align:center;color:var(--htw-success);font-weight:600;"
                                    x-text="r.min_lead_time_days !== null ? r.min_lead_time_days + ' ngày' : '—'"></td>
                                <td style="text-align:center;color:var(--htw-danger);font-weight:600;"
                                    x-text="r.max_lead_time_days !== null ? r.max_lead_time_days + ' ngày' : '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Ghi chú -->
            <div class="htw-chart-card" style="margin-top:16px;padding:14px 18px;">
                <span style="font-size:.8rem;color:var(--htw-text-muted);">
                    <strong style="color:var(--htw-text);">Thời gian giao hàng</strong>: tính từ ngày đặt hàng đến ngày lô nhập kho được xác nhận.
                    Chỉ tính các đơn đặt hàng đã nhận hàng (<em>received / paid_off</em>) và lô nhập đã confirmed.
                    &nbsp;|
                    <span class="htw-badge htw-badge-confirmed">≤ 7 ngày Nhanh</span>&nbsp;
                    <span class="htw-badge htw-badge-warning">8–14 ngày Trung bình</span>&nbsp;
                    <span class="htw-badge htw-badge-draft">&gt; 14 ngày Chậm</span>
                </span>
            </div>



            <!-- So sánh giá theo SKU chung -->
            <template x-if="supplierSkuRows.length > 0">
                <div style="margin-top:28px;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                        <h3 style="margin:0;font-size:.95rem;font-weight:700;color:var(--htw-text);">&#x1F50D; So sánh giá theo SKU chung</h3>
                        <span style="font-size:.8rem;color:var(--htw-text-muted);" x-text="supplierSkuRows.length + ' sản phẩm mua từ ≥2 NCC'"></span>
                    </div>
                    <template x-for="item in supplierSkuRows" :key="item.product_id">
                        <div class="htw-chart-card" style="margin-bottom:12px;padding:14px 18px;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                                <span style="font-weight:700;font-size:.9rem;" x-text="item.product_name"></span>
                                <span style="font-size:.75rem;color:var(--htw-text-muted);" x-text="'[' + item.sku + ']'"></span>
                            </div>
                            <div class="htw-table-wrap" style="margin:0;">
                                <table class="htw-table" style="font-size:.85rem;">
                                    <thead>
                                        <tr>
                                            <th>Nhà cung cấp</th>
                                            <th style="text-align:right;">SL nhập</th>
                                            <th style="text-align:right;">Giá nhập/đv</th>
                                            <th style="text-align:right;">Phí phân bổ/đv</th>
                                            <th style="text-align:right;">Chi phí thực/đv</th>
                                            <th style="text-align:center;">So sánh</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="sup in item.suppliers" :key="sup.supplier_id">
                                            <tr :style="{background: sup.is_cheapest ? 'rgba(34,197,94,0.06)' : ''}">
                                                <td style="font-weight:600;">
                                                    <div style="display:flex;align-items:center;gap:5px;">
                                                        <span x-show="sup.supplier_code" x-text="sup.supplier_code"
                                                              class="htw-badge htw-badge-info"
                                                              style="font-family:monospace;font-size:.7rem;"></span>
                                                        <span x-text="sup.supplier_name"></span>
                                                    </div>
                                                </td>
                                                <td style="text-align:right;" x-text="fmtNum(sup.total_qty, 0)"></td>
                                                <td style="text-align:right;" x-text="fmt(sup.avg_unit_price)"></td>
                                                <td style="text-align:right;color:var(--htw-warning);" x-text="fmt(sup.allocated_fee_per_unit)"></td>
                                                <td style="text-align:right;font-weight:700;"
                                                    :style="{color: sup.is_cheapest ? 'var(--htw-success)' : 'var(--htw-text)'}"
                                                    x-text="fmt(sup.total_cost_per_unit)"></td>
                                                <td style="text-align:center;">
                                                    <template x-if="sup.is_cheapest">
                                                        <span class="htw-badge htw-badge-confirmed">&#x2605; Rẻ nhất</span>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                    <div class="htw-chart-card" style="padding:12px 18px;margin-bottom:0;">
                        <span style="font-size:.78rem;color:var(--htw-text-muted);">
                            <strong style="color:var(--htw-text);">Chi phí thực/đv</strong>
                            = Giá nhập + Phí phân bổ theo tỉ lệ giá trị hàng.
                            Chỉ báo cáo SKU được nhập từ ≥ 2 nhà cung cấp trong kỳ.
                        </span>
                    </div>
                </div>
            </template>

        </div>
    </template>

    <!-- Empty state (chỉ áp dụng cho các tab dùng rows trực tiếp) -->
    <template x-if="!loading && !rows.length && tab !== 'supplier_scorecard' && tab !== 'export'">
        <div style="text-align:center;padding:48px;color:var(--htw-text-muted);">
            <div style="font-size:2rem;">📭</div>
            <div style="margin-top:8px;">Không có dữ liệu trong khoảng thời gian này.</div>
        </div>
    </template>

</div>