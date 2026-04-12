<?php
defined('ABSPATH') || exit;
global $wpdb;
$products = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}htw_products ORDER BY name", ARRAY_A);
?>
<script>
    window._htwProducts = <?php echo wp_json_encode($products); ?>;
</script>
<div class="htw-wrap" x-data="htwProducts">

    <div class="htw-page-header">
        <h1 class="htw-page-title"><span class="dashicons dashicons-archive"></span> Sản phẩm</h1>
        <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Thêm sản phẩm</button>
    </div>

    <!-- Search -->
    <div class="htw-search-bar">
        <input class="htw-input" type="text" placeholder="Tìm theo tên, SKU, danh mục…" x-model="search">
        <span style="color:var(--htw-text-muted);font-size:.8rem;" x-text="filtered().length + ' sản phẩm'"></span>
    </div>

    <!-- Table -->
    <div class="htw-table-wrap">
        <table class="htw-table">
            <thead>
                <tr>
                    <th>Ảnh</th>
                    <th>SKU</th>
                    <th>Tên sản phẩm</th>
                    <th>Danh mục</th>
                    <th>ĐVT</th>
                    <th>SL Tồn kho</th>
                    <th>Giá vốn 1 SP</th>
                    <th>Giá trị tồn kho</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="p in filtered()" :key="p.id">
                    <tr>
                        <td>
                            <template x-if="p.image_url">
                                <img :src="p.image_url" class="htw-img-preview">
                            </template>
                            <template x-if="!p.image_url">
                                <div style="width:70px;height:70px;background:var(--htw-surface-2);border-radius:8px;border:1px solid var(--htw-border);display:flex;align-items:center;justify-content:center;color:var(--htw-text-muted);">
                                    <span class="dashicons dashicons-format-image"></span>
                                </div>
                            </template>
                        </td>
                        <td x-text="p.sku || '—'"></td>
                        <td style="font-weight:600;">
                            <a x-show="p.product_url" :href="p.product_url" target="_blank" rel="noopener" x-text="p.name" style="text-decoration:none;color:inherit;"></a>
                            <span x-show="!p.product_url" x-text="p.name"></span>
                        </td>
                        <td x-text="p.category || '—'"></td>
                        <td x-text="p.unit"></td>
                        <td x-text="fmtNum(p.current_stock, 0)"></td>
                        <td x-text="fmt(p.avg_cost)"></td>
                        <td style="color:var(--htw-info);" x-text="fmt(parseFloat(p.current_stock) * parseFloat(p.avg_cost))"></td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openEdit(p)">Sửa</button>
                                <button class="htw-btn htw-btn-danger htw-btn-sm" @click="del(p.id, p.name)">Xoá</button>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-if="!filtered().length">
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--htw-text-muted);padding:32px;">Chưa có sản phẩm nào.</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Modal Add/Edit -->
    <div class="htw-modal-overlay" x-show="modal" x-cloak @click.self="modal=false">
        <div class="htw-modal" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-archive"></span>
                <span x-text="form.id ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm mới'"></span>
            </div>

            <div x-show="alertMsg" :class="'htw-alert htw-alert-' + alertType" x-text="alertMsg"></div>

            <div class="htw-form-grid">
                <div class="htw-field">
                    <label class="htw-label">Tên sản phẩm *</label>
                    <input class="htw-input" x-model="form.name" placeholder="Ví dụ: Đồ chơi xe lửa gỗ">
                </div>
                <div class="htw-field">
                    <label class="htw-label">SKU</label>
                    <input class="htw-input" x-model="form.sku" placeholder="Ví dụ: TOY-001">
                </div>
                <div class="htw-field" style="position:relative;">
                    <label class="htw-label">Danh mục</label>
                    <input class="htw-input" autocomplete="off"
                        x-model="form.category"
                        @input="onCatInput()"
                        @focus="onCatInput()"
                        @blur="hideCatList()"
                        placeholder="Chọn hoặc nhập danh mục mới…">
                    <!-- Dropdown suggestions -->
                    <div x-show="catShow && catSuggestions().length"
                        x-cloak
                        style="position:absolute;top:100%;left:0;right:0;z-index:9999;
                                background:var(--htw-surface-2);border:1px solid var(--htw-border);
                                border-radius:8px;margin-top:4px;max-height:200px;overflow-y:auto;
                                box-shadow:0 8px 24px rgba(0,0,0,.18);">
                        <template x-for="cat in catSuggestions()" :key="cat">
                            <div @mousedown.prevent="selectCat(cat)"
                                style="padding:9px 14px;cursor:pointer;font-size:.88rem;
                                        border-bottom:1px solid var(--htw-border);transition:background .15s;"
                                @mouseover="$el.style.background='var(--htw-surface-3)'"
                                @mouseout="$el.style.background=''"
                                x-text="cat">
                            </div>
                        </template>
                    </div>
                </div>
                <div class="htw-field">
                    <label class="htw-label">Đơn vị</label>
                    <input class="htw-input" x-model="form.unit" placeholder="cái, bộ, hộp…">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Barcode</label>
                    <input class="htw-input" x-model="form.barcode" placeholder="Mã vạch">
                </div>
                <div class="htw-field">
                    <label class="htw-label">Link sản phẩm</label>
                    <input class="htw-input" x-model="form.product_url" placeholder="https://…" type="url">
                </div>
            </div>

            <!-- Image upload -->
            <div class="htw-field" style="margin-top:16px;">
                <label class="htw-label">Hình ảnh</label>
                <div style="display:flex;align-items:center;gap:14px;margin-top:6px;">
                    <template x-if="form.preview_url">
                        <img :src="form.preview_url" class="htw-img-preview" style="width:90px;height:90px;">
                    </template>
                    <template x-if="!form.preview_url">
                        <div style="width:90px;height:90px;background:var(--htw-surface-2);border:2px dashed var(--htw-border);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--htw-text-muted);">
                            <span class="dashicons dashicons-format-image" style="font-size:24px;"></span>
                        </div>
                    </template>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <button type="button" class="htw-btn htw-btn-ghost htw-btn-sm" @click="chooseImage()">
                            📁 Tải ảnh lên
                        </button>
                        <div style="font-size:.75rem;color:var(--htw-text-muted);">hoặc nhập URL:</div>
                        <input class="htw-input" style="font-size:.8rem;padding:6px 10px;" x-model="form.image_url"
                            placeholder="https://…" @input="form.preview_url = form.image_url; form.image_attachment_id = 0;">
                    </div>
                </div>
            </div>

            <div class="htw-field" style="margin-top:16px;">
                <label class="htw-label">Ghi chú</label>
                <textarea class="htw-textarea" x-model="form.notes" placeholder="Ghi chú thêm…"></textarea>
            </div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="modal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="save()" :disabled="saving">
                    <span x-show="saving" class="htw-spinner"></span>
                    <span x-text="saving ? 'Đang lưu…' : 'Lưu sản phẩm'"></span>
                </button>
            </div>
        </div>
    </div>

</div>