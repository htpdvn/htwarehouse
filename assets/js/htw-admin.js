/* global HTW, wp */
(function ($) {
  'use strict';

  // ── Helpers ──────────────────────────────────────────────────────────────────
  window.HTWApp = {

    nonce:   HTW.nonce,
    ajaxUrl: HTW.ajaxUrl,

    fmt: function (n) {
      if (n === null || n === undefined || n === '') return '';
      return new Intl.NumberFormat('vi-VN').format(Math.round(parseFloat(n) || 0)) + ' đ';
    },

    fmtNum: function (n, decimals) {
      if (n === null || n === undefined || n === '') return '';
      var num = parseFloat(n);
      if (isNaN(num)) return '';
      return new Intl.NumberFormat('vi-VN', {
        minimumFractionDigits: decimals !== undefined ? decimals : 0,
        maximumFractionDigits: decimals !== undefined ? decimals : 2
      }).format(num);
    },

    fmtDate: function (d) {
      if (!d) return '—';
      var dateOnly = String(d).split(' ')[0]; // strip time component if present
      var parts = dateOnly.split('-');
      if (parts.length < 3) return d;
      return parts[2] + '/' + parts[1] + '/' + parts[0];
    },

    parseNum: function (s) {
      if (!s) return 0;
      var cleaned = String(s).replace(/\./g, '').replace(',', '.');
      var num = parseFloat(cleaned);
      return isNaN(num) ? 0 : num;
    },

    request: function (action, data, callback) {
      data.action = action;
      data.nonce  = this.nonce;
      $.post(this.ajaxUrl, data, function (res) {
        if (typeof callback === 'function') callback(res);
      }).fail(function () {
        callback({ success: false, data: 'Lỗi kết nối. Vui lòng thử lại.' });
      });
    },

    showAlert: function (container, type, msg) {
      var icon = type === 'success' ? '✓' : '✕';
      var html = '<div class="htw-alert htw-alert-' + type + '">' + icon + ' ' + msg + '</div>';
      $(container).html(html);
      setTimeout(function () { $(container).html(''); }, 4000);
    },

    openMediaUploader: function (callback) {
      if (typeof wp === 'undefined' || !wp.media) return;
      var frame = wp.media({
        title: 'Chọn hình ảnh sản phẩm',
        button: { text: 'Dùng ảnh này' },
        multiple: false,
        library: { type: 'image' }
      });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        callback(attachment.id, attachment.url);
      });
      frame.open();
    },

    channelLabel: function (ch) {
      var map = { facebook: 'Facebook', tiktok: 'TikTok', shopee: 'Shopee', other: 'Khác' };
      return map[ch] || ch || '—';
    },

    /**
     * Submit a report export PDF request via a hidden iframe to receive a binary PDF.
     * @param {string} report    stock | movement | profit_by_product | profit_by_channel
     * @param {string} dateFrom  YYYY-MM-DD
     * @param {string} dateTo    YYYY-MM-DD
     */
    exportPdf: function (report, dateFrom, dateTo) {
      var iframeId = 'htw-pdf-download-frame';
      var old = document.getElementById(iframeId);
      if (old) old.parentNode.removeChild(old);

      var iframe = document.createElement('iframe');
      iframe.id = iframeId;
      iframe.style.display = 'none';
      document.body.appendChild(iframe);

      var form = document.createElement('form');
      form.method = 'POST';
      form.action = this.ajaxUrl;
      form.target = iframeId;

      var fields = [
        { name: 'action',     value: 'htw_export_pdf' },
        { name: 'nonce',      value: this.nonce },
        { name: 'report',     value: report },
        { name: 'date_from',  value: dateFrom },
        { name: 'date_to',    value: dateTo }
      ];
      fields.forEach(function (f) {
        var input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = f.name;
        input.value = f.value;
        form.appendChild(input);
      });

      document.body.appendChild(form);
      iframe.onload = function () { document.body.removeChild(form); };
      form.submit();
    }
  };

  // ── Alpine.data registration ─────────────────────────────────────────────────
  // All page components are registered as Alpine.data factories.
  // The 'alpine:init' event fires when Alpine starts, guaranteeing Alpine is available.
  function registerComponents() {
    Alpine.data('htwDashboard', function () {
      return {
        kpi:  {},
        top5: [],
        chart: null,
        loading: true,

        init: function () {
          var self = this;
          HTWApp.request('htw_dashboard_data', {}, function (res) {
            self.loading = false;
            if (!res.success) return;
            self.kpi  = res.data.kpi;
            self.top5 = res.data.top5;
            self.$nextTick(function () { self.drawChart(res.data.chart); });
          });
        },

        drawChart: function (data) {
          var ctx = document.getElementById('htwRevenueChart');
          if (!ctx) return;

          var wrapper = ctx.parentElement;

          // Guard: Chart.js failed to load (CDN blocked / network error)
          if (typeof Chart === 'undefined') {
            console.warn('[HTW] Chart.js chưa được tải — biểu đồ không thể hiển thị.');
            ctx.style.display = 'none';
            if (!wrapper.querySelector('.htw-chart-msg')) {
              var errMsg = document.createElement('p');
              errMsg.className = 'htw-chart-msg';
              errMsg.style.cssText = 'color:var(--htw-danger,#ef4444);font-size:.85rem;text-align:center;padding:60px 0;';
              errMsg.textContent = 'Không thể tải thư viện biểu đồ. Vui lòng kiểm tra kết nối mạng.';
              wrapper.insertBefore(errMsg, ctx);
            }
            return;
          }

          // Remove any previous message overlay
          var prevMsg = wrapper.querySelector('.htw-chart-msg');
          if (prevMsg) prevMsg.remove();

          // Guard: empty data — show friendly placeholder instead of blank canvas
          if (!data || !data.length) {
            ctx.style.display = 'none';
            if (!wrapper.querySelector('.htw-chart-msg')) {
              var noDataMsg = document.createElement('p');
              noDataMsg.className = 'htw-chart-msg';
              noDataMsg.style.cssText = 'color:var(--htw-text-muted,#9ca3af);font-size:.85rem;text-align:center;padding:60px 0;';
              noDataMsg.textContent = 'Chưa có dữ liệu doanh thu trong 6 tháng qua.';
              wrapper.insertBefore(noDataMsg, ctx);
            }
            return;
          }

          ctx.style.display = '';
          var labels   = data.map(function (r) { return r.month; });
          var revenues = data.map(function (r) { return parseFloat(r.revenue) || 0; });
          var profits  = data.map(function (r) { return parseFloat(r.profit)  || 0; });

          if (this.chart) this.chart.destroy();
          try {
            this.chart = new Chart(ctx, {
              type: 'bar',
              data: {
                labels: labels,
                datasets: [
                  { label: 'Doanh thu', data: revenues, backgroundColor: 'rgba(108,99,255,0.7)', borderRadius: 6 },
                  { label: 'Lợi nhuận', data: profits,  backgroundColor: 'rgba(34,197,94,0.7)',  borderRadius: 6 }
                ]
              },
              options: {
                responsive: true,
                plugins: {
                  legend: { labels: { color: '#6b7280' } },
                  tooltip: {
                    callbacks: {
                      label: function (ctx) {
                        return ctx.dataset.label + ': ' + new Intl.NumberFormat('vi-VN').format(ctx.raw) + ' đ';
                      }
                    }
                  }
                },
                scales: {
                  x: { ticks: { color: '#6b7280' }, grid: { color: '#dde1ec' } },
                  y: { ticks: { color: '#6b7280', callback: function(v) { return (v/1000000).toFixed(1) + 'M'; } }, grid: { color: '#dde1ec' } }
                }
              }
            });
          } catch (e) {
            console.error('[HTW] Lỗi khởi tạo biểu đồ:', e);
          }
        },

        fmt: HTWApp.fmt
      };
    });

    Alpine.data('htwProducts', function () {
      var products = window._htwProducts || [];
      return {
        products:   products,
        search:     '',
        modal:      false,
        saving:     false,
        alertMsg:   '',
        alertType:  '',

        // ── Category combo-box state ──────────────────────────────────────
        categories:    [],   // all distinct existing categories
        catShow:       false, // dropdown visible?
        catFilter:     '',   // what user typed in the category field

        // ── CSV import/export state ───────────────────────────────────────
        exporting:      false,
        importModal:    false,
        importFile:     null,
        importDragging: false,
        importing:      false,
        importMsg:      '',
        importMsgType:  'success',

        form: {
          id: 0, sku: '', name: '', category: '', unit: 'cái',
          barcode: '', image_url: '', image_attachment_id: 0,
          notes: '', preview_url: ''
        },

        init: function () {
          var self = this;
          HTWApp.request('htw_get_product_categories', {}, function (res) {
            if (res.success) { self.categories = res.data || []; }
          });
        },

        // Filtered suggestions: match anything the user has typed
        catSuggestions: function () {
          var q = (this.catFilter || '').toLowerCase();
          return this.categories.filter(function (c) {
            return !q || c.toLowerCase().includes(q);
          });
        },

        onCatInput: function () {
          this.catFilter    = this.form.category;
          this.catShow      = true;
        },

        selectCat: function (cat) {
          this.form.category = cat;
          this.catFilter     = cat;
          this.catShow       = false;
        },

        hideCatList: function () {
          // small delay so a click on an option registers before the list hides
          var self = this;
          setTimeout(function () { self.catShow = false; }, 180);
        },

        filtered: function () {
          var q = this.search.toLowerCase();
          return this.products.filter(function (p) {
            return !q || p.name.toLowerCase().includes(q) ||
                   p.sku.toLowerCase().includes(q) ||
                   p.category.toLowerCase().includes(q);
          });
        },

        openAdd: function () {
          this.form = { id: 0, sku: '', name: '', category: '', unit: 'cái', barcode: '', image_url: '', image_attachment_id: 0, notes: '', preview_url: '', product_url: '' };
          this.catFilter = '';
          this.catShow   = false;
          this.modal = true;
        },

        openEdit: function (p) {
          this.form = Object.assign({}, p, { preview_url: p.image_url, image_attachment_id: 0 });
          this.catFilter = p.category || '';
          this.catShow   = false;
          this.modal = true;
        },

        chooseImage: function () {
          var self = this;
          HTWApp.openMediaUploader(function (id, url) {
            self.form.image_attachment_id = id;
            self.form.image_url           = '';
            self.form.preview_url         = url;
          });
        },

        save: function () {
          var self = this;
          if (!self.form.name) { self.alertType = 'error'; self.alertMsg = 'Tên sản phẩm không được để trống.'; return; }
          self.saving = true;
          HTWApp.request('htw_save_product', {
            id: self.form.id, sku: self.form.sku, name: self.form.name,
            category: self.form.category, unit: self.form.unit,
            barcode: self.form.barcode, image_url: self.form.image_url,
            image_attachment_id: self.form.image_attachment_id,
            notes: self.form.notes, product_url: self.form.product_url
          }, function (res) {
            self.saving = false;
            self.alertType = res.success ? 'success' : 'error';
            self.alertMsg  = res.data.message || res.data;
            if (res.success) {
              self.modal = false;
              location.reload();
            }
          });
        },

        del: function (id, name) {
          if (!confirm('Xoá sản phẩm "' + name + '"?')) return;
          HTWApp.request('htw_delete_product', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        fmt: HTWApp.fmt,
        fmtNum: HTWApp.fmtNum,

        // ── Export categories as CSV ──────────────────────────────────────
        exportCategories: function () {
          var self = this;
          self.exporting = true;

          // Use a hidden form POST so the browser triggers a file download
          var form = document.createElement('form');
          form.method  = 'POST';
          form.action  = HTW.ajaxUrl;
          form.style.display = 'none';

          var fields = { action: 'htw_export_categories', nonce: HTW.nonce };
          Object.keys(fields).forEach(function (k) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = k;
            inp.value = fields[k];
            form.appendChild(inp);
          });

          document.body.appendChild(form);
          form.submit();
          document.body.removeChild(form);

          // Reset spinner after a short delay (download is async from server)
          setTimeout(function () { self.exporting = false; }, 2500);
        },

        // ── Download a sample CSV file ────────────────────────────────────
        downloadSample: function () {
          var bom  = '\uFEFF'; // UTF-8 BOM for Excel
          var rows = [
            'Danh m\u1ee5c,T\u00ean s\u1ea3n ph\u1ea9m,SKU,\u0110\u01a1n v\u1ecb,Barcode,T\u1ed3n kho,Gi\u00e1 v\u1ed1n,Link s\u1ea3n ph\u1ea9m,Ghi ch\u00fa',
            '\u0110\u1ed3 ch\u01a1i,Xe l\u1eeda g\u1ed7,TOY-001,c\u00e1i,,0,0,,',
            '\u0110\u1ed3 ch\u01a1i,B\u00fap b\u00ea nh\u1ed3i b\u00f4ng,TOY-002,c\u00e1i,,0,0,,',
            'V\u0103n ph\u00f2ng ph\u1ea9m,B\u00fat bi xanh,OFC-001,h\u1ed9p,,0,0,,',
          ].join('\r\n');

          var blob = new Blob([bom + rows], { type: 'text/csv;charset=utf-8;' });
          var url  = URL.createObjectURL(blob);
          var a    = document.createElement('a');
          a.href     = url;
          a.download = 'mau-danh-muc-san-pham.csv';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
        },

        // ── Open import modal ─────────────────────────────────────────────
        openImport: function () {
          this.importFile     = null;
          this.importDragging = false;
          this.importing      = false;
          this.importMsg      = '';
          this.importMsgType  = 'success';
          this.importModal    = true;
        },

        // ── File picker ───────────────────────────────────────────────────
        onPickFile: function (evt) {
          var f = evt.target.files[0];
          if (f) { this.importFile = f; this.importMsg = ''; }
        },

        onDropFile: function (evt) {
          this.importDragging = false;
          var f = evt.dataTransfer && evt.dataTransfer.files[0];
          if (f) { this.importFile = f; this.importMsg = ''; }
        },

        // ── Run the import ────────────────────────────────────────────────
        doImport: function () {
          var self = this;
          if (!self.importFile) return;

          self.importing  = true;
          self.importMsg  = '';

          var fd = new FormData();
          fd.append('action',   'htw_import_categories');
          fd.append('nonce',    HTW.nonce);
          fd.append('csv_file', self.importFile, self.importFile.name);

          var xhr = new XMLHttpRequest();
          xhr.open('POST', HTW.ajaxUrl, true);
          xhr.onload = function () {
            self.importing = false;
            try {
              var res = JSON.parse(xhr.responseText);
              if (res.success) {
                self.importMsgType = 'success';
                self.importMsg     = res.data.message || 'Nh\u1eadp th\u00e0nh c\u00f4ng.';
                self.importFile    = null;
                // Reload after 1.8 s so user can read the message
                setTimeout(function () { location.reload(); }, 1800);
              } else {
                self.importMsgType = 'error';
                self.importMsg     = res.data || 'C\u00f3 l\u1ed7i x\u1ea3y ra.';
              }
            } catch (e) {
              self.importMsgType = 'error';
              self.importMsg     = 'Ph\u1ea3n h\u1ed3i kh\u00f4ng h\u1ee3p l\u1ec7 t\u1eeb m\u00e1y ch\u1ee7.';
            }
          };
          xhr.onerror = function () {
            self.importing     = false;
            self.importMsgType = 'error';
            self.importMsg     = 'K\u1ebft n\u1ed1i th\u1ea5t b\u1ea1i. Vui l\u00f2ng th\u1eed l\u1ea1i.';
          };
          xhr.send(fd);
        }

      };
    });

    Alpine.data('htwImports', function () {
      var batches        = window._htwImports         || [];
      var products       = window._htwProducts        || [];
      var suppliersList  = window._htwSuppliersList  || [];
      return {
        batches:  batches,
        products: products,
        suppliersList: suppliersList,
        modal:    false,
        detailModal: false,
        detail:   null,
        saving:   false,
        form: {
          id: 0, batch_code: '', supplier_id: 0, supplier: '',
          import_date: new Date().toISOString().split('T')[0],
          shipping_fee: 0, tax_fee: 0, service_fee: 0, inspection_fee: 0,
          packing_fee: 0, other_fee: 0, notes: '',
          items: []
        },

        get extraTotal() {
          return parseFloat(this.form.shipping_fee||0)
               + parseFloat(this.form.tax_fee||0)
               + parseFloat(this.form.service_fee||0)
               + parseFloat(this.form.inspection_fee||0)
               + parseFloat(this.form.packing_fee||0)
               + parseFloat(this.form.other_fee||0);
        },

        get itemsTotal() {
          return this.form.items.reduce(function (s, i) { return s + (parseFloat(i.qty||0) * parseFloat(i.unit_price||0)); }, 0);
        },

        get grandTotal() { return this.itemsTotal + this.extraTotal; },

        previewAllocated: function (item) {
          var qty       = parseFloat(item.qty) || 0;
          var unitPrice = parseFloat(item.unit_price) || 0;
          var total     = this.itemsTotal;
          if (!total) return unitPrice;
          var share = (qty * unitPrice) / total;
          var extra = share * this.extraTotal;
          return unitPrice + (qty > 0 ? extra / qty : 0);
        },

        openAdd: function () {
          this.form = { id: 0, batch_code: '', supplier_id: 0, supplier: '', import_date: new Date().toISOString().split('T')[0], shipping_fee: '', tax_fee: '', service_fee: '', inspection_fee: '', packing_fee: '', other_fee: '', notes: '', items: [] };
          this.addItem();
          this.modal = true;
        },

        openDetail: function (b) {
          this.detail = b;
          this.detailModal = true;
        },

        openEdit: function (b) {
          var self = this;
          self.form = {
            id: b.id, batch_code: b.batch_code,
            supplier_id: b.supplier_id || 0,
            supplier: b.supplier,
            import_date: b.import_date,
            shipping_fee: b.shipping_fee || '',
            tax_fee: b.tax_fee || '',
            service_fee: b.service_fee || '',
            inspection_fee: b.inspection_fee || '',
            packing_fee: b.packing_fee || '',
            other_fee: b.other_fee || '',
            notes: b.notes || '',
            items: (b.items || []).map(function (i) { return { product_id: i.product_id, qty: i.qty != null ? String(i.qty) : '', unit_price: i.unit_price != null ? String(i.unit_price) : (i.total_cost && i.qty ? String(parseFloat(i.total_cost) / parseFloat(i.qty)) : '') }; })
          };
          if (!self.form.items.length) self.addItem();
          self.modal = true;
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: '', unit_price: '' });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
        },

        /**
         * Build an <option> HTML string for each product in the dropdown.
         * Works correctly inside Alpine x-html (no x-for in <select>).
         */
        productOptions: function (selectedId) {
          var prods = this.products || [];
          console.log('[HTW] productOptions called, products count:', prods.length, 'selectedId:', selectedId);
          console.log('[HTW] first product:', prods[0]);
          var opts = '<option value="">-- Chọn sản phẩm --</option>';
          for (var i = 0; i < prods.length; i++) {
            var p = prods[i];
            var sel = (String(p.id) === String(selectedId)) ? ' selected' : '';
            var stock = isNaN(parseFloat(p.current_stock)) ? 0 : parseFloat(p.current_stock);
            opts += '<option value="' + p.id + '"' + sel + '>' + p.name + (p.sku ? ' [' + p.sku + ']' : '') + ' (tồn: ' + stock + ')</option>';
          }
          return opts;
        },

        parseNum: function (s) {
          if (!s) return 0;
          var cleaned = String(s).replace(/\./g, '').replace(',', '.');
          var num = parseFloat(cleaned);
          return isNaN(num) ? 0 : num;
        },

        onQtyChange: function (item) {
          item.qty = this.parseNum(String(item.qty || ''));
        },

        onPriceChange: function (item) {
          item.unit_price = this.parseNum(String(item.unit_price || ''));
        },

        productName: function (id) {
          var p = this.products.find(function (x) { return x.id == id; });
          return p ? p.name : '';
        },

        save: function () {
          var self = this;
          self.saving = true;
          var data = {
            id: self.form.id, batch_code: self.form.batch_code,
            supplier_id: self.form.supplier_id || 0,
            supplier: self.form.supplier,
            import_date: self.form.import_date,
            shipping_fee: self.form.shipping_fee,
            tax_fee: self.form.tax_fee,
            service_fee: self.form.service_fee,
            inspection_fee: self.form.inspection_fee,
            packing_fee: self.form.packing_fee,
            other_fee: self.form.other_fee,
            notes: self.form.notes
          };
          self.form.items.forEach(function (item, idx) {
            data['items[' + idx + '][product_id]'] = item.product_id;
            data['items[' + idx + '][qty]']        = item.qty;
            data['items[' + idx + '][unit_price]'] = item.unit_price;
          });
          HTWApp.request('htw_save_import', data, function (res) {
            self.saving = false;
            if (res.success) { self.modal = false; location.reload(); }
            else             { alert(res.data); }
          });
        },

        confirm: function (id) {
          if (!confirm('Xác nhận lô hàng? Sau khi xác nhận sẽ cập nhật tồn kho và giá vốn.')) return;
          HTWApp.request('htw_confirm_import', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        del: function (id) {
          if (!confirm('Xoá lô hàng này?')) return;
          HTWApp.request('htw_delete_import', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        fmt: HTWApp.fmt,
        fmtNum: HTWApp.fmtNum,
        fmtDate: HTWApp.fmtDate
      };
    });

    Alpine.data('htwExports', function () {
      var orders   = window._htwExports  || [];
      var products = window._htwProducts || [];
      return {
        orders:   orders,
        products: products,
        modal:         false,
        detailModal:   false,
        detailOrder:   null,
        detailReturns: [],
        returnModal:   false,
        returnSaving:  false,
        saving:        false,
        filterChannel: '',
        returnForm: {
          export_order_id:   0,
          export_order_code: '',
          return_date: new Date().toISOString().split('T')[0],
          reason: '',
          notes:  '',
          items:  []
        },
        form: {
          id: 0, order_code: '', channel: 'facebook',
          order_date: new Date().toISOString().split('T')[0],
          customer_name: '', notes: '', items: []
        },

        filtered: function () {
          var ch = this.filterChannel;
          return this.orders.filter(function (o) { return !ch || o.channel === ch; });
        },

        get revenue() { return this.form.items.reduce(function (s, i) { return s + this.parseNum(i.qty||0) * this.parseNum(i.sale_price||0); }, 0); },
        get cogs()    { return this.form.items.reduce(function (s, i) { return s + this.parseNum(i.qty||0) * parseFloat(i.avg_cost||0); }, 0); },
        get profit()  { return this.revenue - this.cogs; },

        openAdd: function () {
          this.form = { id: 0, order_code: '', channel: 'facebook', order_date: new Date().toISOString().split('T')[0], customer_name: '', notes: '', items: [] };
          this.addItem();
          this.modal = true;
        },

        openEdit: function (o) {
          var allProducts = this.products;
          this.form = {
            id: o.id, order_code: o.order_code, channel: o.channel,
            order_date: o.order_date, customer_name: o.customer_name, notes: o.notes || '',
            items: (o.items || []).map(function (i) {
              var p = allProducts.find(function (x) { return x.id == i.product_id; });
              return {
                product_id: i.product_id,
                qty: i.qty != null ? String(i.qty) : '',
                sale_price: i.sale_price != null ? String(i.sale_price) : '',
                avg_cost: p ? parseFloat(p.avg_cost || 0) : parseFloat(i.cogs_per_unit || 0),
                current_stock: p ? parseFloat(p.current_stock || 0) : 0
              };
            })
          };
          if (!this.form.items.length) this.addItem();
          this.modal = true;
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: '', sale_price: '', avg_cost: 0, current_stock: 0 });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
        },

        /**
         * Build <option> HTML string for the product dropdown in the export modal.
         * Uses x-html because x-for inside <select> is not reliable in Alpine v3.
         */
        productOptions: function (selectedId) {
          var prods = this.products || [];
          var opts = '<option value="">-- Chọn sản phẩm --</option>';
          for (var i = 0; i < prods.length; i++) {
            var p = prods[i];
            var sel = (String(p.id) === String(selectedId)) ? ' selected' : '';
            var stock = isNaN(parseFloat(p.current_stock)) ? 0 : parseFloat(p.current_stock);
            opts += '<option value="' + p.id + '"' + sel + '>'
                  + p.name + (p.sku ? ' [' + p.sku + ']' : '')
                  + ' (tồn: ' + stock + ')'
                  + '</option>';
          }
          return opts;
        },

        onProductChange: function (item) {
          var p = this.products.find(function (x) { return x.id == item.product_id; });
          if (p) {
            item.avg_cost      = parseFloat(p.avg_cost || 0);
            item.current_stock = parseFloat(p.current_stock || 0);
          } else {
            item.avg_cost      = 0;
            item.current_stock = 0;
          }
        },

        parseNum: function (s) {
          if (!s) return 0;
          var cleaned = String(s).replace(/\./g, '').replace(',', '.');
          var num = parseFloat(cleaned);
          return isNaN(num) ? 0 : num;
        },

        onQtyChange: function (item) {
          item.qty = this.parseNum(String(item.qty || ''));
        },

        onPriceChange: function (item) {
          item.sale_price = this.parseNum(String(item.sale_price || ''));
        },

        save: function () {
          var self = this;
          // ── Client-side stock validation ──────────────────────────────────
          var overStock = [];
          self.form.items.forEach(function (item) {
            if (!item.product_id) return;
            var qty   = self.parseNum(item.qty);
            var stock = parseFloat(item.current_stock || 0);
            if (qty > stock) {
              var p = self.products.find(function (x) { return x.id == item.product_id; });
              overStock.push((p ? p.name : 'SP #' + item.product_id) + ' (tồn: ' + stock + ', cần: ' + qty + ')');
            }
          });
          if (overStock.length) {
            alert('Số lượng vượt tồn kho:\n• ' + overStock.join('\n• '));
            return;
          }
          self.saving = true;
          var data = {
            id: self.form.id, order_code: self.form.order_code, channel: self.form.channel,
            order_date: self.form.order_date, customer_name: self.form.customer_name, notes: self.form.notes
          };
          self.form.items.forEach(function (item, idx) {
            data['items[' + idx + '][product_id]'] = item.product_id;
            data['items[' + idx + '][qty]']        = item.qty;
            data['items[' + idx + '][sale_price]'] = item.sale_price;
          });
          HTWApp.request('htw_save_export', data, function (res) {
            self.saving = false;
            if (res.success) { self.modal = false; location.reload(); }
            else             { alert(res.data); }
          });
        },

        confirm: function (id) {
          var self = this;
          if (!confirm('Xác nhận đơn hàng và trừ kho?')) return;
          HTWApp.request('htw_confirm_export', { id: id }, function (res) {
            if (res.success) {
              // Fetch the just-confirmed order from the server so we have the real totals
              HTWApp.request('htw_export_detail', { id: id }, function (dr) {
                if (dr.success) {
                  self.detailOrder = dr.data;
                  self.detailModal = true;
                } else {
                  location.reload();
                }
              });
            } else {
              alert(res.data);
            }
          });
        },

        openDetail: function (id) {
          var self = this;
          HTWApp.request('htw_export_detail', { id: id }, function (res) {
            if (res.success) {
              self.detailOrder   = res.data;
              self.detailReturns = [];
              self.detailModal   = true;
              // Load return history
              HTWApp.request('htw_return_list', { export_order_id: id }, function (rr) {
                if (rr.success) { self.detailReturns = rr.data || []; }
              });
            } else {
              alert(res.data || 'Không thể tải chi tiết đơn hàng.');
            }
          });
        },

        del: function (id) {
          if (!confirm('Xoá đơn hàng này?')) return;
          HTWApp.request('htw_delete_export', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        /**
         * Open the Return Order modal for a confirmed/partial_return export order.
         * Builds the list of returnable items with already-returned qty pre-fetched.
         */
        openReturn: function (order) {
          var self = this;
          self.returnForm = {
            export_order_id:   order.id,
            export_order_code: order.order_code,
            return_date: new Date().toISOString().split('T')[0],
            reason: '',
            notes:  '',
            items:  []
          };

          // Fetch confirmed-return history for this order to compute max_returnable per item
          HTWApp.request('htw_return_list', { export_order_id: order.id }, function (rr) {
            var alreadyMap = {};
            if (rr.success) {
              (rr.data || []).forEach(function (ro) {
                if (ro.status !== 'confirmed') return;
                (ro.items || []).forEach(function (ri) {
                  alreadyMap[ri.export_item_id] = (alreadyMap[ri.export_item_id] || 0) + parseFloat(ri.qty_returned || 0);
                });
              });
            }

            self.returnForm.items = (order.items || []).map(function (ei) {
              var soldQty          = parseFloat(ei.qty || 0);
              var alreadyReturned  = alreadyMap[ei.id] || 0;
              var maxReturnable    = Math.max(0, soldQty - alreadyReturned);
              return {
                export_item_id:      ei.id,
                product_name:        ei.product_name || '—',
                qty_sold:            soldQty,
                qty_already_returned: alreadyReturned,
                max_returnable:      maxReturnable,
                qty_returned:        maxReturnable > 0 ? maxReturnable : 0,
                sale_price:          parseFloat(ei.sale_price || 0),
                cogs_per_unit:       parseFloat(ei.cogs_per_unit || 0),
                selected:            maxReturnable > 0
              };
            }).filter(function (ri) { return ri.qty_sold > 0; });

            self.returnModal = true;
          });
        },

        toggleAllReturn: function (evt) {
          var checked = evt.target.checked;
          this.returnForm.items.forEach(function (ri) {
            if (ri.max_returnable > 0) ri.selected = checked;
          });
        },

        get returnTotalQty() {
          var self = this;
          return (self.returnForm.items || []).reduce(function (s, ri) {
            return s + (ri.selected ? (parseFloat(ri.qty_returned) || 0) : 0);
          }, 0);
        },

        get returnTotalRefund() {
          var self = this;
          return (self.returnForm.items || []).reduce(function (s, ri) {
            if (!ri.selected) return s;
            return s + (parseFloat(ri.qty_returned) || 0) * ri.sale_price;
          }, 0);
        },

        get returnTotalCogs() {
          var self = this;
          return (self.returnForm.items || []).reduce(function (s, ri) {
            if (!ri.selected) return s;
            return s + (parseFloat(ri.qty_returned) || 0) * ri.cogs_per_unit;
          }, 0);
        },

        /**
         * Save + immediately confirm the return order in one step.
         * (We combine save & confirm for UX simplicity — the backend validates everything.)
         */
        saveReturn: function () {
          var self = this;
          var selectedItems = (self.returnForm.items || []).filter(function (ri) {
            return ri.selected && parseFloat(ri.qty_returned) > 0;
          });

          if (!selectedItems.length) {
            alert('Vui lòng chọn ít nhất 1 sản phẩm để trả.');
            return;
          }

          var hasExcess = selectedItems.some(function (ri) {
            return parseFloat(ri.qty_returned) > ri.max_returnable;
          });
          if (hasExcess) {
            alert('Một số sản phẩm có số lượng trả vượt giới hạn. Vui lòng kiểm tra lại.');
            return;
          }

          if (!confirm('Xác nhận đơn trả hàng? Tồn kho sẽ được hoàn lại ngay lập tức.')) return;

          self.returnSaving = true;

          var data = {
            export_order_id: self.returnForm.export_order_id,
            return_date:     self.returnForm.return_date,
            reason:          self.returnForm.reason,
            notes:           self.returnForm.notes
          };
          selectedItems.forEach(function (ri, idx) {
            data['items[' + idx + '][export_item_id]'] = ri.export_item_id;
            data['items[' + idx + '][qty_returned]']   = ri.qty_returned;
          });

          // Step 1: Save pending return
          HTWApp.request('htw_save_return', data, function (saveRes) {
            if (!saveRes.success) {
              self.returnSaving = false;
              alert(saveRes.data);
              return;
            }
            // Step 2: Confirm the return immediately
            HTWApp.request('htw_confirm_return', { return_id: saveRes.data.return_id }, function (confirmRes) {
              self.returnSaving = false;
              if (confirmRes.success) {
                self.returnModal = false;
                location.reload();
              } else {
                alert('Lưu thành công nhưng xác nhận thất bại: ' + confirmRes.data
                    + '\n\nVui lòng vào Chi tiết đơn để xác nhận thủ công.');
                location.reload();
              }
            });
          });
        },

        statusLabel: function (s) {
          var map = {
            'draft':          'Nháp',
            'confirmed':      'Đã xác nhận',
            'partial_return': 'Trả 1 phần',
            'fully_returned': 'Đã trả toàn bộ'
          };
          return map[s] || s;
        },

        channelLabel: HTWApp.channelLabel,
        fmt:          HTWApp.fmt,
        fmtNum:       HTWApp.fmtNum,
        parseNum: function (v) {
          if (typeof v === 'string') v = v.replace(/[^0-9.]/g, '');
          return parseFloat(v) || 0;
        },

        /**
         * Print a return order slip.
         * Opens a new window with a formatted, print-ready HTML document.
         */
        printReturn: function (ro, order) {
          var fmt    = HTWApp.fmt;
          var fmtNum = HTWApp.fmtNum;

          var rows = '';
          var total = 0;
          (ro.items || []).forEach(function (ri) {
            var subtotal = parseFloat(ri.qty_returned) * parseFloat(ri.sale_price);
            total += subtotal;
            rows += '<tr>'
              + '<td style="padding:6px 10px;">' + (ri.product_name || '\u2014') + '</td>'
              + '<td style="text-align:right;padding:6px 10px;">' + fmtNum(ri.qty_returned) + '</td>'
              + '<td style="text-align:right;padding:6px 10px;">' + fmt(ri.sale_price) + '</td>'
              + '<td style="text-align:right;padding:6px 10px;font-weight:700;color:#e53e3e;">' + fmt(subtotal) + '</td>'
              + '</tr>';
          });

          var orderCode = order ? (order.order_code || '') : '';
          var customer  = order ? (order.customer_name || '\u2014') : '\u2014';
          var returnDate = String(ro.return_date || '').split(' ')[0];
          var rParts = returnDate.split('-');
          var dateFmt = rParts.length === 3 ? rParts[2]+'/'+rParts[1]+'/'+rParts[0] : returnDate;

          var html = '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">'
            + '<title>Phi\u1ebfu tr\u1ea3 h\u00e0ng \u2014 ' + ro.return_code + '</title>'
            + '<style>'
            + 'body{font-family:Arial,sans-serif;font-size:13px;color:#1a1a1a;margin:0;padding:24px;}'
            + 'h1{font-size:18px;margin:0 0 4px;} .sub{color:#555;font-size:12px;margin-bottom:16px;}'
            + 'table{width:100%;border-collapse:collapse;margin-top:10px;}'
            + 'th{background:#f5f5f5;text-align:left;padding:8px 10px;border-bottom:2px solid #ddd;font-size:12px;}'
            + 'td{padding:6px 10px;border-bottom:1px solid #eee;}'
            + '.total-row{font-weight:700;background:#fff8f0;}'
            + '.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}'
            + '.info-label{color:#666;font-size:11px;text-transform:uppercase;letter-spacing:.5px;}'
            + '.info-value{font-weight:600;}'
            + '.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#22c55e;color:#fff;}'
            + '@media print{@page{margin:15mm;}}'
            + '</style></head><body>'
            + '<h1>\uD83D\uDD04 Phi\u1ebfu tr\u1ea3 h\u00e0ng</h1>'
            + '<div class="sub">M\u00e3 phi\u1ebfu: <strong>' + ro.return_code + '</strong>'
            + ' &nbsp;|&nbsp; \u0110\u01a1n b\u00e1n: <strong>' + orderCode + '</strong>'
            + ' &nbsp;|&nbsp; Ng\u00e0y in: <strong>' + dateFmt + '</strong></div>'
            + '<div class="info-grid">'
            + '<div><div class="info-label">Kh\u00e1ch h\u00e0ng</div><div class="info-value">' + customer + '</div></div>'
            + '<div><div class="info-label">Ng\u00e0y tr\u1ea3 h\u00e0ng</div><div class="info-value">' + dateFmt + '</div></div>'
            + '<div><div class="info-label">L\u00fd do tr\u1ea3</div><div class="info-value">' + (ro.reason || '\u2014') + '</div></div>'
            + '<div><div class="info-label">Tr\u1ea1ng th\u00e1i</div><div><span class="badge">\u0110\u00e3 x\u00e1c nh\u1eadn</span></div></div>'
            + '</div>'
            + '<table><thead><tr>'
            + '<th>S\u1ea3n ph\u1ea9m</th><th style="text-align:right;min-width:60px;">SL tr\u1ea3</th>'
            + '<th style="text-align:right;min-width:100px;">Gi\u00e1 b\u00e1n</th>'
            + '<th style="text-align:right;min-width:110px;">Th\u00e0nh ti\u1ec1n ho\u00e0n</th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot><tr class="total-row">'
            + '<td colspan="3" style="padding:8px 10px;text-align:right;">T\u1ed5ng ho\u00e0n tr\u1ea3:</td>'
            + '<td style="text-align:right;padding:8px 10px;color:#e53e3e;font-size:15px;">' + fmt(total) + '</td>'
            + '</tr></tfoot></table>'
            + (ro.notes ? '<p style="margin-top:16px;font-size:12px;color:#666;font-style:italic;">Ghi ch\u00fa: ' + ro.notes + '</p>' : '')
            + '<div style="margin-top:40px;display:grid;grid-template-columns:1fr 1fr;gap:30px;font-size:12px;text-align:center;">'
            + '<div><div style="border-top:1px solid #999;padding-top:6px;margin-top:40px;">Ng\u01b0\u1eddi l\u1eadp phi\u1ebfu</div></div>'
            + '<div><div style="border-top:1px solid #999;padding-top:6px;margin-top:40px;">Kh\u00e1ch h\u00e0ng x\u00e1c nh\u1eadn</div></div>'
            + '</div>'
            + '<scr'+'ipt>window.onload=function(){window.print();};<\/scr'+'ipt>'
            + '</body></html>';

          var w = window.open('', '_blank', 'width=800,height=600');
          if (w) { w.document.write(html); w.document.close(); }
        },

        fmtDate: function (d) {
          if (!d) return '\u2014';
          var dateOnly = String(d).split(' ')[0];
          var parts = dateOnly.split('-');
          if (parts.length < 3) return d;
          return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
      };
    });

    Alpine.data('htwReports', function () {
      return {
        tab:       'stock',
        rows:      [],
        summary:   {},
        loading:   false,
        dateFrom:  new Date().toISOString().slice(0,7) + '-01',
        dateTo:    new Date().toISOString().slice(0,10),

        // Export tab state
        exportTab:     'stock',
        exportDateFrom: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0,10),
        exportDateTo:   new Date().toISOString().slice(0,10),
        exporting:      false,

        // Performance tab sort state
        sortKey: 'performance_score',
        sortAsc: false,

        // Performance tab filter state
        perfFilter: 'all',   // 'all' | 'increase' | 'maintain' | 'review'

        load: function () {
          var self = this;
          self.loading = true;
          HTWApp.request('htw_report_data', {
            report:    self.tab,
            date_from: self.dateFrom,
            date_to:   self.dateTo
          }, function (res) {
            self.loading = false;
            if (!res.success) return;
            self.rows    = res.data.rows    || [];
            self.summary = res.data;
            // Reset sort on new load
            self.sortKey = self.tab === 'product_performance' ? 'performance_score' : '';
            self.sortAsc = false;
            self.perfFilter = 'all';
          });
        },

        get totalInvValue() {
          return (this.rows || []).reduce(function (s, r) { return s + parseFloat(r.inventory_value||0); }, 0);
        },

        get totalRevenue() {
          return (this.rows || []).reduce(function (s, r) { return s + parseFloat(r.total_revenue||r.revenue||0); }, 0);
        },

        get totalProfit() {
          return (this.rows || []).reduce(function (s, r) { return s + parseFloat(r.total_profit||r.profit||0); }, 0);
        },

        // Performance tab: top_cards from summary
        get topCards() {
          return (this.summary && this.summary.top_cards) ? this.summary.top_cards : null;
        },

        // Performance tab: filtered + sorted rows
        get perfRows() {
          var self = this;
          var filtered = (self.rows || []).filter(function (r) {
            if (self.perfFilter === 'all') return true;
            return r.recommendation === self.perfFilter;
          });
          if (!self.sortKey) return filtered;
          var key = self.sortKey;
          var asc = self.sortAsc;
          return filtered.slice().sort(function (a, b) {
            var av = parseFloat(a[key]);
            var bv = parseFloat(b[key]);
            if (!isNaN(av) && !isNaN(bv)) return asc ? (av - bv) : (bv - av);
            var as = String(a[key] || '');
            var bs = String(b[key] || '');
            return asc ? as.localeCompare(bs) : bs.localeCompare(as);
          });
        },

        sort: function (key) {
          if (this.sortKey === key) {
            this.sortAsc = !this.sortAsc;
          } else {
            this.sortKey = key;
            this.sortAsc = false;
          }
        },

        sortIcon: function (key) {
          if (this.sortKey !== key) return ' ↕';
          return this.sortAsc ? ' ↑' : ' ↓';
        },

        scoreColor: function (score) {
          var s = parseFloat(score);
          if (s >= 70) return '#22c55e';
          if (s >= 40) return '#f59e0b';
          return '#ef4444';
        },

        scoreBg: function (score) {
          var s = parseFloat(score);
          if (s >= 70) return 'rgba(34,197,94,0.12)';
          if (s >= 40) return 'rgba(245,158,11,0.12)';
          return 'rgba(239,68,68,0.12)';
        },

        recoLabel: function (rec) {
          var map = {
            'increase': '🟢 Tăng vốn',
            'maintain': '🟡 Duy trì',
            'review':   '🔴 Xem xét',
            'no_sales': '⚫ Chưa bán'
          };
          return map[rec] || rec;
        },

        recoColor: function (rec) {
          if (rec === 'increase') return '#22c55e';
          if (rec === 'maintain') return '#f59e0b';
          if (rec === 'review')   return '#ef4444';
          return 'var(--htw-text-muted)';
        },

        switchTab: function (t) {
          this.tab = t;
          if (t !== 'export') this.load();
        },

        reportLabel: function (r) {
          var labels = {
            stock:               'Tồn kho',
            movement:            'Nhập - Xuất - Tồn',
            profit_by_product:   'Lợi nhuận theo SP',
            profit_by_channel:   'Lợi nhuận theo Kênh',
            product_performance: 'Hiệu suất Sản phẩm'
          };
          return labels[r] || r;
        },

        exportPdf: function () {
          var self = this;
          self.exporting = true;
          setTimeout(function () {
            HTWApp.exportPdf(self.exportTab, self.exportDateFrom, self.exportDateTo);
            self.exporting = false;
          }, 100);
        },

        channelLabel: HTWApp.channelLabel,
        fmt:    HTWApp.fmt,
        fmtNum: HTWApp.fmtNum,
        fmtDate: HTWApp.fmtDate
      };
    });

    Alpine.data('htwPurchaseOrders', function () {
      var pos      = window._htwPOs      || [];
      var products = window._htwProducts || [];
      var suppliersList = window._htwSuppliersList || [];
      return {
        pos:      pos,
        products: products,
        suppliersList: suppliersList,
        modal:         false,
        detailModal:   false,
        detailTab:     'info',
        paymentModal:  false,
        editPaymentModal: false,
        saving:        false,
        detail:        {},
        editingPO:     null,
        fmtDate: function (d) {
          if (!d) return '—';
          var dateOnly = String(d).split(' ')[0];
          var parts = dateOnly.split('-');
          if (parts.length < 3) return d;
          return parts[2] + '/' + parts[1] + '/' + parts[0];
        },
        form: {
          id: 0, po_code: '', supplier_id: 0,
          supplier_name: '', supplier_contact: '',
          supplier_phone: '', supplier_address: '',
          order_date: new Date().toISOString().split('T')[0],
          shipping_fee: 0, tax_fee: 0, service_fee: 0, inspection_fee: 0,
          packing_fee: 0, other_fee: 0, notes: '',
          amount_paid: 0, amount_remaining: 0, payments: [],
          items: []
        },
        paymentForm: {
          id: 0, po_code: '', total_amount: 0, amount_remaining: 0,
          amount: 0, payment_date: new Date().toISOString().split('T')[0], note: ''
        },
        editPaymentForm: {
          payment_id: 0, po_id: 0,
          amount: 0,
          payment_date: new Date().toISOString().split('T')[0],
          payment_day: '', payment_month: '', payment_year: '',
          note: ''
        },

        get goodsTotal() {
          return this.form.items.reduce(function (s, i) {
            return s + (parseFloat(i.qty||0) * parseFloat(i.unit_price||0));
          }, 0);
        },

        get extraFeesTotal() {
          return parseFloat(this.form.shipping_fee||0)
               + parseFloat(this.form.tax_fee||0)
               + parseFloat(this.form.service_fee||0)
               + parseFloat(this.form.inspection_fee||0)
               + parseFloat(this.form.packing_fee||0)
               + parseFloat(this.form.other_fee||0);
        },

        get grandTotal() { return this.goodsTotal + this.extraFeesTotal; },

        get remaining() {
          return Math.max(0, this.grandTotal - parseFloat(this.form.amount_paid||0));
        },

        extraFees: function (po) {
          return (parseFloat(po.shipping_fee||0)
                + parseFloat(po.tax_fee||0)
                + parseFloat(po.service_fee||0)
                + parseFloat(po.inspection_fee||0)
                + parseFloat(po.packing_fee||0)
                + parseFloat(po.other_fee||0));
        },

        statusLabel: function (status) {
          var map = { draft: 'Nháp', confirmed: 'Đã gửi', received: 'Đã nhận hàng', paid_off: 'Đã thanh toán' };
          return map[status] || status;
        },

        paymentPercent: function (form) {
          var total = this.grandTotal || parseFloat(form.total_amount || 0);
          if (!total) return 0;
          return Math.min(100, (parseFloat(form.amount_paid || 0) / total) * 100);
        },

        openAdd: function () {
          var today = new Date();
          var y = today.getFullYear();
          var m = String(today.getMonth() + 1).padStart(2, '0');
          var d = String(today.getDate()).padStart(2, '0');
          var iso = y + '-' + m + '-' + d;
          this.form = {
            id: 0, po_code: '', supplier_id: 0,
            order_date: iso,
            order_day: d, order_month: m, order_year: String(y),
            shipping_fee: 0, tax_fee: 0, service_fee: 0, inspection_fee: 0,
            packing_fee: 0, other_fee: 0, notes: '',
            amount_paid: 0, amount_remaining: 0, payments: [],
            items: []
          };
          this.addItem();
          this.modal = true;
        },

        openEdit: function (po) {
          var self = this;
          var parts = String(po.order_date || '').split('-');
          self.form = {
            id: po.id, po_code: po.po_code,
            supplier_id: po.supplier_id || 0,
            supplier_name: po.supplier_name || '',
            supplier_contact: po.supplier_contact || '',
            supplier_phone: po.supplier_phone || '',
            supplier_address: po.supplier_address || '',
            order_date: po.order_date,
            order_day: parts[2] || '', order_month: parts[1] || '', order_year: parts[0] || '',
            shipping_fee: po.shipping_fee || 0,
            tax_fee: po.tax_fee || 0,
            service_fee: po.service_fee || 0,
            inspection_fee: po.inspection_fee || 0,
            packing_fee: po.packing_fee || 0,
            other_fee: po.other_fee || 0,
            notes: po.notes || '',
            total_amount: parseFloat(po.total_amount || 0),
            amount_paid: parseFloat(po.amount_paid || 0),
            amount_remaining: parseFloat(po.amount_remaining || 0),
            payments: po.payments || [],
            items: (po.items || []).map(function (i) {
              return { product_id: i.product_id, qty: i.qty, unit_price: i.unit_price };
            })
          };
          if (!self.form.items.length) self.addItem();
          self.modal = true;
        },

        openDetail: function (po) {
          this.detail = po;
          this.detailTab = 'info';
          this.detailModal = true;
        },

        openEditFromDetail: function () {
          this.detailModal = false;
          this.openEdit(this.detail);
        },

        openPaymentModalFromDetail: function () {
          this.detailModal = false;
          this.openPaymentModal(this.detail);
        },

        syncOrderDate: function () {
          var dd = String(this.form.order_day || '').padStart(2, '0');
          var mm = String(this.form.order_month || '').padStart(2, '0');
          var yy = String(this.form.order_year || '');
          if (dd && mm && yy.length === 4) {
            this.form.order_date = yy + '-' + mm + '-' + dd;
          }
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: '', unit_price: '' });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
        },

        fmtNum: function (n, decimals) {
          if (n === null || n === undefined || n === '') return '';
          var num = parseFloat(n);
          if (isNaN(num)) return '';
          return new Intl.NumberFormat('vi-VN', {
            minimumFractionDigits: decimals !== undefined ? decimals : 0,
            maximumFractionDigits: decimals !== undefined ? decimals : 0
          }).format(num);
        },

        fmtQty: function (n) {
          if (n === null || n === undefined || n === '') return '';
          var num = parseFloat(n);
          if (isNaN(num)) return '';
          return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 }).format(num);
        },

        parseNum: function (s) {
          if (!s) return 0;
          var cleaned = String(s).replace(/\./g, '').replace(',', '.');
          var num = parseFloat(cleaned);
          return isNaN(num) ? 0 : num;
        },

        onQtyChange: function (item) {
          item.qty = this.parseNum(String(item.qty || ''));
        },

        onPriceChange: function (item) {
          item.unit_price = this.parseNum(String(item.unit_price || ''));
        },

        save: function () {
          var self = this;
          self.saving = true;
          var data = {
            id: self.form.id, po_code: self.form.po_code,
            supplier_id: self.form.supplier_id,
            supplier_name: self.form.supplier_name || '',
            supplier_contact: self.form.supplier_contact || '',
            supplier_phone: self.form.supplier_phone || '',
            supplier_address: self.form.supplier_address || '',
            order_date: self.form.order_date,
            shipping_fee: self.form.shipping_fee || 0,
            tax_fee: self.form.tax_fee || 0,
            service_fee: self.form.service_fee || 0,
            inspection_fee: self.form.inspection_fee || 0,
            packing_fee: self.form.packing_fee || 0,
            other_fee: self.form.other_fee || 0,
            notes: self.form.notes
          };
          self.form.items.forEach(function (item, idx) {
            data['items[' + idx + '][product_id]'] = item.product_id;
            data['items[' + idx + '][qty]']        = item.qty;
            data['items[' + idx + '][unit_price]'] = item.unit_price;
          });
          HTWApp.request('htw_save_po', data, function (res) {
            self.saving = false;
            if (res.success) { self.modal = false; location.reload(); }
            else             { alert(res.data); }
          });
        },

        confirm: function (id) {
          if (!confirm('Gửi đơn đặt hàng đến nhà cung cấp?')) return;
          HTWApp.request('htw_confirm_po', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        sendToImport: function (id) {
          if (!confirm('Tạo lô nhập kho từ đơn đặt hàng này?')) return;
          HTWApp.request('htw_po_send_to_import', { id: id }, function (res) {
            if (res.success) { alert(res.data.message); location.reload(); }
            else             { alert(res.data); }
          });
        },

        del: function (id) {
          if (!confirm('Xoá đơn đặt hàng này?')) return;
          HTWApp.request('htw_delete_po', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        openPaymentModal: function (po) {
          var today = new Date();
          var y = today.getFullYear();
          var m = String(today.getMonth() + 1).padStart(2, '0');
          var d = String(today.getDate()).padStart(2, '0');
          var iso = y + '-' + m + '-' + d;
          var amt = parseFloat(po.amount_remaining) > 0 ? parseFloat(po.amount_remaining) : 0;
          this.paymentForm = {
            id: po.id,
            po_code: po.po_code,
            total_amount: po.total_amount,
            amount_remaining: po.amount_remaining,
            amount: amt,
            payment_date: iso,
            payment_day: d, payment_month: m, payment_year: String(y),
            note: ''
          };
          this.paymentModal = true;
        },

        syncPaymentDate: function () {
          var dd = String(this.paymentForm.payment_day || '').padStart(2, '0');
          var mm = String(this.paymentForm.payment_month || '').padStart(2, '0');
          var yy = String(this.paymentForm.payment_year || '');
          if (dd && mm && yy.length === 4) {
            this.paymentForm.payment_date = yy + '-' + mm + '-' + dd;
          }
        },

        recordPayment: function () {
          var self = this;
          self.saving = true;
          HTWApp.request('htw_po_record_payment', {
            id:           self.paymentForm.id,
            amount:       self.paymentForm.amount,
            payment_date: self.paymentForm.payment_date,
            note:         self.paymentForm.note
          }, function (res) {
            self.saving = false;
            if (res.success) {
              self.paymentModal = false;
              alert(res.data.message);
              location.reload();
            } else {
              alert(res.data);
            }
          });
        },

        openEditPaymentModal: function (pmt) {
          var parts = String(pmt.payment_date || '').split('-');
          this.editPaymentForm = {
            payment_id: pmt.id,
            po_id:      pmt.po_id,
            amount:     parseFloat(pmt.amount) || 0,
            payment_date: pmt.payment_date || '',
            payment_day: parts[2] || '', payment_month: parts[1] || '', payment_year: parts[0] || '',
            note: pmt.note || ''
          };
          this.editPaymentModal = true;
        },

        syncEditPaymentDate: function () {
          var dd = String(this.editPaymentForm.payment_day || '').padStart(2, '0');
          var mm = String(this.editPaymentForm.payment_month || '').padStart(2, '0');
          var yy = String(this.editPaymentForm.payment_year || '');
          if (dd && mm && yy.length === 4) {
            this.editPaymentForm.payment_date = yy + '-' + mm + '-' + dd;
          }
        },

        saveEditPayment: function () {
          var self = this;
          self.saving = true;
          HTWApp.request('htw_po_edit_payment', {
            payment_id:   self.editPaymentForm.payment_id,
            po_id:        self.editPaymentForm.po_id,
            amount:       self.editPaymentForm.amount,
            payment_date: self.editPaymentForm.payment_date,
            note:         self.editPaymentForm.note
          }, function (res) {
            self.saving = false;
            if (res.success) {
              self.editPaymentModal = false;
              alert(res.data.message);
              location.reload();
            } else {
              alert(res.data);
            }
          });
        },

        deletePayment: function (paymentId) {
          var self = this;
          if (!confirm('Xoá bản ghi thanh toán này?')) return;
          HTWApp.request('htw_po_delete_payment', {
            payment_id: paymentId
          }, function (res) {
            if (res.success) {
              alert(res.data.message);
              location.reload();
            } else {
              alert(res.data);
            }
          });
        },

        onSupplierChange: function () {
          var supplierId = parseInt(this.form.supplier_id, 10);
          if (!supplierId) {
            this.form.supplier_name    = '';
            this.form.supplier_contact = '';
            this.form.supplier_phone   = '';
            this.form.supplier_address = '';
            return;
          }
          var supplier = (this.suppliersList || []).find(function (s) {
            return parseInt(s.id, 10) === supplierId;
          });
          if (supplier) {
            this.form.supplier_name    = supplier.name         || '';
            this.form.supplier_contact = supplier.contact_name || '';
            this.form.supplier_phone   = supplier.phone        || '';
            this.form.supplier_address = supplier.address     || '';
          }
        },

        fmt: function (n) {
          if (n === null || n === undefined || n === '') return '';
          return new Intl.NumberFormat('vi-VN').format(Math.round(parseFloat(n) || 0)) + ' đ';
        }
      };
    });

    // ── Suppliers ────────────────────────────────────────────────────────────────
    Alpine.data('htwSuppliers', function () {
      var suppliers = window._htwSuppliers || [];
      return {
        suppliers: suppliers,
        search:   '',
        modal:    false,
        saving:   false,
        form: {
          id: 0, name: '', contact_name: '', phone: '',
          email: '', address: '', tax_code: '', notes: ''
        },

        // ── Transaction modal state ───────────────────────────────────────────
        txModal:   false,
        txLoading: false,
        txTab:     'orders',
        txData:    { supplier: null, pos: [], payments: [], total_amount: 0, total_paid: 0, total_remaining: 0 },

        get filtered() {
          var q = this.search.toLowerCase().trim();
          if (!q) return this.suppliers;
          return this.suppliers.filter(function (s) {
            return (s.name || '').toLowerCase().includes(q)
                || (s.phone || '').toLowerCase().includes(q)
                || (s.email || '').toLowerCase().includes(q)
                || (s.tax_code || '').toLowerCase().includes(q);
          });
        },

        totalPOs: function () {
          return this.suppliers.reduce(function (sum, s) {
            return sum + parseInt(s.po_count || 0, 10);
          }, 0);
        },

        totalAmount: function () {
          return this.suppliers.reduce(function (sum, s) {
            return sum + parseFloat(s.total_po_amount || 0);
          }, 0);
        },

        openAdd: function () {
          this.form = { id: 0, name: '', contact_name: '', phone: '', email: '', address: '', tax_code: '', notes: '' };
          this.modal = true;
        },

        openEdit: function (s) {
          this.form = {
            id: s.id, name: s.name || '', contact_name: s.contact_name || '',
            phone: s.phone || '', email: s.email || '',
            address: s.address || '', tax_code: s.tax_code || '',
            notes: s.notes || ''
          };
          this.modal = true;
        },

        // ── Open transaction detail modal ─────────────────────────────────────
        openTransactions: function (s) {
          var self = this;
          self.txData    = { supplier: s, pos: [], payments: [], total_amount: 0, total_paid: 0, total_remaining: 0 };
          self.txTab     = 'orders';
          self.txLoading = true;
          self.txModal   = true;

          HTWApp.request('htw_supplier_transactions', { supplier_id: s.id }, function (res) {
            self.txLoading = false;
            if (res.success) {
              self.txData = res.data;
            } else {
              alert(res.data || 'Lỗi tải dữ liệu giao dịch.');
              self.txModal = false;
            }
          });
        },

        txStatusLabel: function (status) {
          var map = { draft: 'Nháp', confirmed: 'Đã gửi', received: 'Đã nhận hàng', paid_off: 'Đã thanh toán' };
          return map[status] || status;
        },

        fmtDate: HTWApp.fmtDate,

        onSupplierChange: function () {
          if (!this.form.name) {
            this.form.contact_name = '';
            this.form.phone = '';
            this.form.email = '';
            this.form.address = '';
            this.form.tax_code = '';
            return;
          }
          var self = this;
          var found = this.suppliers.find(function (s) { return s.name === self.form.name; });
          if (found) {
            this.form.contact_name = found.contact_name || '';
            this.form.phone       = found.phone       || '';
            this.form.email       = found.email        || '';
            this.form.address    = found.address       || '';
            this.form.tax_code   = found.tax_code     || '';
          }
        },

        save: function () {
          var self = this;
          if (!self.form.name.trim()) {
            alert('Tên nhà cung cấp không được để trống.');
            return;
          }
          self.saving = true;
          HTWApp.request('htw_save_supplier', {
            id:          self.form.id,
            name:        self.form.name,
            contact_name: self.form.contact_name,
            phone:       self.form.phone,
            email:       self.form.email,
            address:     self.form.address,
            tax_code:    self.form.tax_code,
            notes:       self.form.notes
          }, function (res) {
            self.saving = false;
            if (res.success) { self.modal = false; location.reload(); }
            else             { alert(res.data); }
          });
        },

        del: function (id) {
          var self = this;
          if (!confirm('Xoá nhà cung cấp này?')) return;
          HTWApp.request('htw_delete_supplier', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        fmt: HTWApp.fmt
      };
    });

    // ── Snapshot Page ────────────────────────────────────────────────────────────
    Alpine.data('htwSnapshots', function () {
      var snapList    = window._htwSnapshots    || [];
      var snapStatus  = window._htwSnapshotStatus || null;
      var nextRun     = window._htwNextRun      || null;
      return {
        snapshots:     snapList,
        scheduled:     false,
        nextRunStr:    nextRun,
        lastRun:       null,
        lastStatus:    snapStatus,
        creating:      false,
        restoring:     false,
        restoreModal:  false,
        restoreTarget: null,
        toast: { show: false, message: '', type: 'success' },

        init: function () {
          var _runAt = this.lastStatus && this.lastStatus.run_at;
          this.lastRun = _runAt ? this._fmtDt(_runAt) : null;
          this.fetchStatus();
        },

        fetchStatus: function () {
          var self = this;
          HTWApp.request('htw_snapshot_status', {}, function (res) {
            if (res.success && res.data) {
              self.scheduled  = res.data.is_scheduled;
              self.nextRunStr = res.data.next_run || null;
              self.lastStatus = res.data.last_status || null;
              if (self.lastStatus && self.lastStatus.run_at) {
                self.lastRun = self._fmtDt(self.lastStatus.run_at);
              }
            }
          });
        },

        showToast: function (message, type) {
          var self = this;
          this.toast = { show: true, message: message, type: type || 'success' };
          setTimeout(function () { self.toast.show = false; }, 5500);
        },

        createSnapshot: function () {
          var self = this;
          this.creating = true;
          HTWApp.request('htw_snapshot_create', {}, function (res) {
            self.creating = false;
            if (res.success) {
              self.snapshots.unshift({
                filename:   res.data.filename,
                url:        res.data.url,
                created_at: res.data.created_at,
                size_bytes: res.data.size_bytes,
                row_counts: res.data.row_counts,
              });
              self.showToast('Snapshot tạo thành công! (' + res.data.size_formatted + ')');
            } else {
              self.showToast(res.data || 'Tạo snapshot thất bại.', 'error');
            }
          });
        },

        confirmRestore: function (s) {
          this.restoreTarget = s;
          this.restoreModal  = true;
        },

        doRestore: function () {
          var self = this;
          this.restoring = true;
          HTWApp.request('htw_snapshot_restore', { filename: this.restoreTarget.filename }, function (res) {
            self.restoring    = false;
            self.restoreModal = false;
            if (res.success) {
              self.showToast('Khôi phục thành công!');
            } else {
              self.showToast(res.data || 'Khôi phục thất bại.', 'error');
            }
          });
        },

        deleteSnapshot: function (s) {
          if (!confirm('Xoá snapshot "' + s.filename + '"? Hành động không thể hoàn tác.')) return;
          var self = this;
          HTWApp.request('htw_snapshot_delete', { filename: s.filename }, function (res) {
            if (res.success) {
              self.snapshots = self.snapshots.filter(function (x) { return x.filename !== s.filename; });
              self.showToast('Đã xoá snapshot.');
            } else {
              self.showToast(res.data || 'Xoá thất bại.', 'error');
            }
          });
        },

        rowCnt: function (s, key) {
          return (s.row_counts && s.row_counts[key]) ? s.row_counts[key] : '—';
        },

        _fmtDt: function (str) {
          if (!str) return '—';
          var d = new Date(String(str).replace(' ', 'T'));
          if (isNaN(d.getTime())) return str;
          var pad = function (n) { return ('0' + n).slice(-2); };
          return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
               + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },

        fmtSize: function (bytes) {
          if (!bytes) return '—';
          if (bytes < 1024) return bytes + ' B';
          if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
          return (bytes / (1024 * 1024)). toFixed(1) + ' MB';
        }
      };
    });
  }

  if (window.Alpine) {
    registerComponents();
  } else {
    document.addEventListener('alpine:init', registerComponents);
  }

})(jQuery);