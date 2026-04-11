<?php defined('ABSPATH') || exit; ?>
<div class="htw-wrap" x-data="htwReports()" x-init="load()">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-chart-bar"></span> Báo cáo</h1>
    </div>

    <!-- Tabs -->
    <div class="htw-tabs">
        <button class="htw-tab" :class="{active: tab==='stock'}" @click="tab='stock';         load()">📦 Tồn kho</button>
        <button class="htw-tab" :class="{active: tab==='movement'}" @click="tab='movement';      load()">🔄 Nhập Xuất Tồn</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_product'}" @click="tab='profit_by_product';  load()">📊 Lợi nhuận / SP</button>
        <button class="htw-tab" :class="{active: tab==='profit_by_channel'}" @click="tab='profit_by_channel';  load()">📡 Lợi nhuận / Kênh</button>
    </div>

    <!-- Date filter -->
    <div class="htw-search-bar" x-show="tab !== 'stock'">
        <div class="htw-field" style="flex-direction:row;align-items:center;gap:8px;">
            <label class="htw-label" style="white-space:nowrap;">Từ ngày:</label>
            <input class="htw-input" type="date" x-model="dateFrom" style="max-width:160px;">
        </div>
        <div class="htw-field" style="flex-direction:row;align-items:center;gap:8px;">
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

    <!-- Empty state -->
    <template x-if="!loading && !rows.length">
        <div style="text-align:center;padding:48px;color:var(--htw-text-muted);">
            <div style="font-size:2rem;">📭</div>
            <div style="margin-top:8px;">Không có dữ liệu trong khoảng thời gian này.</div>
        </div>
    </template>

</div>