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
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="exportCategories()" :disabled="exporting" title="Xuất danh sách danh mục ra tệp CSV">
                <span x-show="exporting" class="htw-spinner"></span>
                <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;margin-right:4px;"></span>
                <span x-text="exporting ? 'Đang xuất…' : 'Xuất danh mục'"></span>
            </button>
            <button class="htw-btn htw-btn-ghost htw-btn-sm" @click="openImport()" title="Nhập danh mục / sản phẩm từ tệp CSV">
                <span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;margin-right:4px;"></span>
                Nhập từ CSV
            </button>
            <button class="htw-btn htw-btn-primary" @click="openAdd()">+ Thêm sản phẩm</button>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div class="htw-modal-overlay" x-show="importModal" x-cloak @click.self="importModal=false">
        <div class="htw-modal" style="max-width:520px;" @click.stop>
            <div class="htw-modal-title">
                <span class="dashicons dashicons-upload"></span>
                <span>Nhập danh mục / sản phẩm từ CSV</span>
            </div>

            <!-- Instructions -->
            <div style="background:var(--htw-surface-2);border:1px solid var(--htw-border);border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:.84rem;color:var(--htw-text-muted);line-height:1.7;">
                <strong style="color:var(--htw-text);">Định dạng tệp CSV hỗ trợ:</strong><br>
                Cột bắt buộc: <code>Danh mục</code>, <code>Tên sản phẩm</code>, <code>SKU</code><br>
                Cột tuỳ chọn: <code>Đơn vị</code>, <code>Barcode</code>, <code>Link ảnh</code>, <code>Link sản phẩm</code>, <code>Ghi chú</code><br>
                <span style="color:var(--htw-warning,#f59e0b);">⚠</span> Cột <strong>Tồn kho</strong> và <strong>Giá vốn</strong> bị <strong>bỏ qua hoàn toàn</strong> — dữ liệu tồn kho chỉ được cập nhật qua nghiệp vụ nhập/xuất kho.<br>
                • File phải mã hoá <strong>UTF-8</strong> (Excel: <em>Lưu dưới dạng CSV UTF-8</em>)
                <br><br>
                <a href="#" @click.prevent="downloadSample()" style="color:var(--htw-info);text-decoration:none;">
                    <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span>
                    Tải file mẫu
                </a>
            </div>



            <!-- File drop zone -->
            <div id="htw-drop-zone"
                style="border:2px dashed var(--htw-border);border-radius:10px;padding:32px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;"
                :style="importDragging ? 'border-color:var(--htw-info);background:rgba(99,179,237,.07);' : ''"
                @dragover.prevent="importDragging=true"
                @dragleave.prevent="importDragging=false"
                @drop.prevent="onDropFile($event)"
                @click="$refs.csvInput.click()">
                <span class="dashicons dashicons-media-spreadsheet" style="font-size:36px;width:36px;height:36px;color:var(--htw-info);display:block;margin:0 auto 10px;"></span>
                <div style="font-size:.9rem;color:var(--htw-text);" x-text="importFile ? importFile.name : 'Kéo thả file CSV vào đây hoặc nhấp để chọn'"></div>
                <div style="font-size:.78rem;color:var(--htw-text-muted);margin-top:6px;">Chấp nhận: .csv</div>
                <input type="file" accept=".csv,text/csv" x-ref="csvInput" style="display:none;" @change="onPickFile($event)">
            </div>

            <div x-show="importMsg" :class="'htw-alert htw-alert-' + importMsgType" style="margin-top:14px;" x-text="importMsg"></div>

            <div class="htw-modal-footer">
                <button class="htw-btn htw-btn-ghost" @click="importModal=false">Huỷ</button>
                <button class="htw-btn htw-btn-primary" @click="doImport()" :disabled="!importFile || importing">
                    <span x-show="importing" class="htw-spinner"></span>
                    <span x-text="importing ? 'Đang nhập…' : 'Bắt đầu nhập'"></span>
                </button>
            </div>
        </div>
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
                    <th
                        @click="sortBy('current_stock')"
                        style="cursor:pointer;user-select:none;white-space:nowrap;"
                        :style="sortKey==='current_stock' ? 'color:var(--htw-info)' : ''"
                    >
                        SL Tồn kho
                        <span x-text="sortKey==='current_stock' ? (sortDir==='asc' ? ' ↑' : ' ↓') : ' ↕'" style="font-size:.8em;opacity:.7;"></span>
                    </th>
                    <th
                        @click="sortBy('avg_cost')"
                        style="cursor:pointer;user-select:none;white-space:nowrap;"
                        :style="sortKey==='avg_cost' ? 'color:var(--htw-info)' : ''"
                    >
                        Giá vốn 1 SP
                        <span x-text="sortKey==='avg_cost' ? (sortDir==='asc' ? ' ↑' : ' ↓') : ' ↕'" style="font-size:.8em;opacity:.7;"></span>
                    </th>
                    <th
                        @click="sortBy('suggested_price')"
                        style="cursor:pointer;user-select:none;white-space:nowrap;"
                        :style="sortKey==='suggested_price' ? 'color:var(--htw-info)' : ''"
                    >
                        Giá bán đề xuất
                        <span x-text="sortKey==='suggested_price' ? (sortDir==='asc' ? ' ↑' : ' ↓') : ' ↕'" style="font-size:.8em;opacity:.7;"></span>
                    </th>
                    <th
                        @click="sortBy('inventory_value')"
                        style="cursor:pointer;user-select:none;white-space:nowrap;"
                        :style="sortKey==='inventory_value' ? 'color:var(--htw-info)' : ''"
                    >
                        Giá trị tồn kho
                        <span x-text="sortKey==='inventory_value' ? (sortDir==='asc' ? ' ↑' : ' ↓') : ' ↕'" style="font-size:.8em;opacity:.7;"></span>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="p in filtered()" :key="p.id">
                    <tr>
                        <td>
                            <template x-if="p.image_url">
                                <img :src="p.image_url" class="htw-img-preview"
                                    @mouseenter="htwShowZoom($event, p.image_url, p.name)"
                                    @mouseleave="htwHideZoom()"
                                    @mousemove="htwMoveZoom($event)">
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
                        <td style="color:var(--htw-warning,#f59e0b);" x-text="p.suggested_price > 0 ? fmt(p.suggested_price) : '—'"></td>
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
                        <td colspan="10" style="text-align:center;color:var(--htw-text-muted);padding:32px;">Chưa có sản phẩm nào.</td>
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
                <div class="htw-field">
                    <label class="htw-label">Giá bán đề xuất</label>
                    <input class="htw-input" x-model="form.suggested_price" placeholder="0" type="number" min="0" step="1000">
                    <small x-show="parseFloat(form.suggested_price) > 0"
                           style="display:block;margin-top:4px;color:var(--htw-warning,#f59e0b);font-size:.8rem;"
                           x-text="'≈ ' + fmt(form.suggested_price)"></small>
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

<script>
(function() {
    // Build panel with 100% inline styles — no CSS dependency
    const panel = document.createElement('div');
    panel.id = 'htw-img-zoom-panel';
    panel.style.cssText = [
        'position:fixed',
        'top:-9999px',
        'left:-9999px',
        'z-index:99999',
        'pointer-events:none',
        'opacity:0',
        'transition:opacity .22s ease,transform .22s ease',
        'transform:scale(.88) translateY(6px)',
        'filter:drop-shadow(0 12px 40px rgba(30,33,48,.28))',
        'background:#fff',
        'border-radius:14px',
        'padding:6px',
        'border:1.5px solid #dde1ec',
    ].join(';');

    const zImg = document.createElement('img');
    zImg.style.cssText = 'display:block;width:330px;height:330px;object-fit:contain;border-radius:10px;';
    zImg.alt = '';

    const zLabel = document.createElement('div');
    zLabel.style.cssText = [
        'font-size:.72rem',
        'color:#6b7280',
        'text-align:center',
        'font-family:Inter,sans-serif',
        'padding:5px 8px 3px',
        'white-space:nowrap',
        'overflow:hidden',
        'text-overflow:ellipsis',
        'max-width:330px',
    ].join(';');

    panel.appendChild(zImg);
    panel.appendChild(zLabel);
    document.body.appendChild(panel);

    let _timer = null;

    function positionPanel(e) {
        const pw = 378, ph = 410, margin = 18;
        let x = e.clientX + margin;
        let y = e.clientY - ph / 2;
        if (x + pw > window.innerWidth  - margin) x = e.clientX - pw - margin;
        if (y < margin)                           y = margin;
        if (y + ph > window.innerHeight - margin) y = window.innerHeight - ph - margin;
        panel.style.left = x + 'px';
        panel.style.top  = y + 'px';
    }

    window.htwShowZoom = function(e, url, name) {
        clearTimeout(_timer);
        zImg.src           = url;
        zLabel.textContent = name || '';
        positionPanel(e);
        _timer = setTimeout(function() {
            panel.style.opacity   = '1';
            panel.style.transform = 'scale(1) translateY(0)';
        }, 80);
    };

    window.htwHideZoom = function() {
        clearTimeout(_timer);
        panel.style.opacity   = '0';
        panel.style.transform = 'scale(.88) translateY(6px)';
        setTimeout(function() {
            panel.style.top  = '-9999px';
            panel.style.left = '-9999px';
        }, 230);
    };

    window.htwMoveZoom = function(e) {
        if (parseFloat(panel.style.opacity) > 0) positionPanel(e);
    };
})();
</script>