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
      return map[ch] || ch;
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
          if (!ctx || !data) return;
          var labels   = data.map(function (r) { return r.month; });
          var revenues = data.map(function (r) { return parseFloat(r.revenue); });
          var profits  = data.map(function (r) { return parseFloat(r.profit); });

          if (this.chart) this.chart.destroy();
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
                legend: { labels: { color: '#e2e8f0' } },
                tooltip: {
                  callbacks: {
                    label: function (ctx) {
                      return ctx.dataset.label + ': ' + new Intl.NumberFormat('vi-VN').format(ctx.raw) + ' đ';
                    }
                  }
                }
              },
              scales: {
                x: { ticks: { color: '#8892a4' }, grid: { color: '#2d3150' } },
                y: { ticks: { color: '#8892a4', callback: function(v) { return (v/1000000).toFixed(1) + 'M'; } }, grid: { color: '#2d3150' } }
              }
            }
          });
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
        fmtNum: HTWApp.fmtNum
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
          id: 0, batch_code: '', supplier: '',
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
          this.form = { id: 0, batch_code: '', supplier: '', import_date: new Date().toISOString().split('T')[0], shipping_fee: '', tax_fee: '', service_fee: '', inspection_fee: '', packing_fee: '', other_fee: '', notes: '', items: [] };
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
            id: b.id, batch_code: b.batch_code, supplier: b.supplier,
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
            id: self.form.id, batch_code: self.form.batch_code, supplier: self.form.supplier,
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
        modal:    false,
        saving:   false,
        filterChannel: '',
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
          this.form = {
            id: o.id, order_code: o.order_code, channel: o.channel,
            order_date: o.order_date, customer_name: o.customer_name, notes: o.notes || '',
            items: (o.items || []).map(function (i) { return { product_id: i.product_id, qty: i.qty != null ? String(i.qty) : '', sale_price: i.sale_price != null ? String(i.sale_price) : '', avg_cost: parseFloat(i.cogs_per_unit||0) }; })
          };
          if (!this.form.items.length) this.addItem();
          this.modal = true;
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: '', sale_price: '', avg_cost: 0 });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
        },

        onProductChange: function (item) {
          var p = this.products.find(function (x) { return x.id == item.product_id; });
          if (p) item.avg_cost = parseFloat(p.avg_cost || 0);
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
          if (!confirm('Xác nhận đơn hàng và trừ kho?')) return;
          HTWApp.request('htw_confirm_export', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        del: function (id) {
          if (!confirm('Xoá đơn hàng này?')) return;
          HTWApp.request('htw_delete_export', { id: id }, function (res) {
            if (res.success) { location.reload(); }
            else             { alert(res.data); }
          });
        },

        channelLabel: HTWApp.channelLabel,
        fmt: HTWApp.fmt,
        fmtNum: HTWApp.fmtNum
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
            self.rows    = res.data.rows || [];
            self.summary = res.data;
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

        channelLabel: HTWApp.channelLabel,
        fmt:    HTWApp.fmt,
        fmtNum: HTWApp.fmtNum
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
  }

  if (window.Alpine) {
    registerComponents();
  } else {
    document.addEventListener('alpine:init', registerComponents);
  }

})(jQuery);