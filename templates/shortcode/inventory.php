<?php
/**
 * Template: [htw_inventory] shortcode output
 *
 * @package HTWarehouse
 */
defined('ABSPATH') || exit;
?>

<div class="htws-wrap" x-data="htwInventory" x-cloak>

    <!-- ── PASSWORD GATE ─────────────────────────────────────── -->
    <div class="htws-gate" x-show="!authed" x-transition>
        <div class="htws-gate-card">
            <div class="htws-gate-icon">🔐</div>
            <h2 class="htws-gate-title">Kho hàng GachStudio</h2>
            <p class="htws-gate-subtitle">Nhập mật khẩu để xem danh sách tồn kho</p>

            <div class="htws-gate-form" @keydown.enter.window="authed || submitPassword()">
                <div class="htws-gate-input-wrap">
                    <input
                        :type="showPw ? 'text' : 'password'"
                        class="htws-gate-input"
                        x-model="password"
                        placeholder="Mật khẩu…"
                        autocomplete="current-password"
                        id="htws-pw-input"
                    >
                    <button type="button" class="htws-gate-toggle" @click="showPw = !showPw" tabindex="-1">
                        <span x-text="showPw ? '🙈' : '👁'"></span>
                    </button>
                </div>

                <div class="htws-gate-error" :class="gateError ? 'show' : ''" x-text="gateError"></div>

                <button class="htws-gate-btn" @click="submitPassword()" :disabled="gateLoading">
                    <span x-show="gateLoading">Đang kiểm tra…</span>
                    <span x-show="!gateLoading">Xem tồn kho</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── AUTHENTICATED VIEW ─────────────────────────────────── -->
    <div x-show="authed" x-transition>

        <!-- Header -->
        <div class="htws-header">
            <div class="htws-header-left">
                <h1 class="htws-header-title">📦 Danh sách tồn kho</h1>
                <p class="htws-header-subtitle" x-text="'Cập nhật lúc ' + new Date().toLocaleString('vi-VN')"></p>
            </div>
            <button class="htws-logout-btn" @click="logout()" title="Đăng xuất">🔒 Đăng xuất</button>
        </div>

        <!-- Stats row -->
        <div class="htws-stats" x-show="!loading">
            <div class="htws-stat-card">
                <div class="htws-stat-icon">🗂️</div>
                <div class="htws-stat-label">Tổng loại sản phẩm</div>
                <div class="htws-stat-value" x-text="totalProducts"></div>
            </div>
            <div class="htws-stat-card">
                <div class="htws-stat-icon">📊</div>
                <div class="htws-stat-label">Tổng số lượng tồn</div>
                <div class="htws-stat-value" x-text="fmtNum(totalStock, 0)"></div>
            </div>
        </div>

        <!-- Search bar -->
        <div class="htws-search-wrap" x-show="!loading">
            <input
                type="text"
                class="htws-search"
                x-model="search"
                placeholder="Tìm theo tên, SKU, danh mục…"
                id="htws-search-input"
            >
        </div>

        <!-- Loading skeleton — uses htws-grid so it inherits the responsive layout -->
        <div x-show="loading" class="htws-grid">
            <template x-for="i in [1,2,3,4,5,6]" :key="i">
                <div class="htws-card" style="pointer-events:none;">
                    <div class="htws-card-img-wrap htws-skeleton"></div>
                    <div class="htws-card-body">
                        <div class="htws-skeleton" style="height:16px;width:45%;border-radius:6px;"></div>
                        <div class="htws-skeleton" style="height:20px;width:80%;border-radius:6px;margin-top:2px;"></div>
                        <div class="htws-skeleton" style="height:14px;width:65%;border-radius:6px;"></div>
                        <div class="htws-skeleton" style="height:14px;width:55%;border-radius:6px;"></div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Product grid -->
        <div class="htws-grid" x-show="!loading">

            <template x-for="p in filtered" :key="p.id">
                <div class="htws-card">
                    <!-- Image -->
                    <div class="htws-card-img-wrap">
                        <template x-if="p.image_url">
                            <img :src="p.image_url" :alt="p.name" class="htws-card-img" loading="lazy">
                        </template>
                        <template x-if="!p.image_url">
                            <span class="htws-card-img-placeholder">📦</span>
                        </template>
                    </div>

                    <!-- Body -->
                    <div class="htws-card-body">
                        <!-- SKU -->
                        <span class="htws-card-sku" x-text="p.sku || 'Chưa có SKU'"></span>

                        <!-- Name -->
                        <div class="htws-card-name" x-text="p.name"></div>

                        <!-- Meta rows -->
                        <div class="htws-card-meta">
                            <!-- Stock -->
                            <div class="htws-card-row">
                                <span class="htws-card-row-label">Tồn kho</span>
                                <span
                                    class="htws-stock-badge"
                                    :class="p.current_stock <= 0 ? 'htws-stock-zero' : ''"
                                    x-text="fmtNum(p.current_stock, 0) + ' SP'"
                                ></span>
                            </div>

                            <!-- Suggested price -->
                            <div class="htws-card-row">
                                <span class="htws-card-row-label">Giá bán đề xuất</span>
                                <span class="htws-card-row-value htws-price-value"
                                    x-text="p.suggested_price > 0 ? fmtPrice(p.suggested_price) : '—'"
                                ></span>
                            </div>

                            <!-- Category (subtle) -->
                            <div class="htws-card-row" x-show="p.category">
                                <span class="htws-card-row-label" style="font-size:.75rem;">Danh mục</span>
                                <span class="htws-card-row-value" style="font-size:.75rem;color:var(--htws-text-muted);" x-text="p.category"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Empty state -->
            <template x-if="filtered.length === 0 && !loading">
                <div class="htws-empty" style="grid-column:1/-1;">
                    <span class="htws-empty-icon">🔍</span>
                    <div x-text="search ? 'Không tìm thấy sản phẩm nào khớp với \"' + search + '\".' : 'Chưa có sản phẩm nào trong kho.'"></div>
                </div>
            </template>
        </div>

    </div><!-- /authed -->

</div><!-- /htws-wrap -->
