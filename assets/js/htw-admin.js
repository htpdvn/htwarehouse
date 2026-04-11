/* global HTW, wp */
(function ($) {
  'use strict';

  // ── Helpers ──────────────────────────────────────────────────────────────────
  window.HTWApp = {

    nonce:   HTW.nonce,
    ajaxUrl: HTW.ajaxUrl,

    fmt: function (n) {
      return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + ' ₫';
    },

    fmtNum: function (n, decimals) {
      return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: decimals || 2 }).format(n);
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
        low:  [],
        chart: null,
        loading: true,

        init: function () {
          var self = this;
          HTWApp.request('htw_dashboard_data', {}, function (res) {
            self.loading = false;
            if (!res.success) return;
            self.kpi  = res.data.kpi;
            self.top5 = res.data.top5;
            self.low  = res.data.low_stock;
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
                      return ctx.dataset.label + ': ' + new Intl.NumberFormat('vi-VN').format(ctx.raw) + ' ₫';
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
        form: {
          id: 0, sku: '', name: '', category: '', unit: 'cái',
          barcode: '', image_url: '', image_attachment_id: 0,
          notes: '', preview_url: ''
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
          this.form = { id: 0, sku: '', name: '', category: '', unit: 'cái', barcode: '', image_url: '', image_attachment_id: 0, notes: '', preview_url: '' };
          this.modal = true;
        },

        openEdit: function (p) {
          this.form = Object.assign({}, p, { preview_url: p.image_url, image_attachment_id: 0 });
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
            notes: self.form.notes
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
      var batches  = window._htwImports  || [];
      var products = window._htwProducts || [];
      return {
        batches:  batches,
        products: products,
        modal:    false,
        detailModal: false,
        detail:   null,
        saving:   false,
        form: {
          id: 0, batch_code: '', supplier: '',
          import_date: new Date().toISOString().split('T')[0],
          shipping_fee: 0, tax_fee: 0, other_fee: 0, notes: '',
          items: []
        },

        get extraTotal() {
          return parseFloat(this.form.shipping_fee||0) + parseFloat(this.form.tax_fee||0) + parseFloat(this.form.other_fee||0);
        },

        get itemsTotal() {
          return this.form.items.reduce(function (s, i) { return s + (parseFloat(i.qty||0) * parseFloat(i.unit_price||0)); }, 0);
        },

        get grandTotal() { return this.itemsTotal + this.extraTotal; },

        previewAllocated: function (item) {
          var total = this.itemsTotal;
          if (!total) return parseFloat(item.unit_price || 0);
          var share = (parseFloat(item.qty||0) * parseFloat(item.unit_price||0)) / total;
          var extra = share * this.extraTotal;
          var qty   = parseFloat(item.qty || 1);
          return parseFloat(item.unit_price||0) + (qty > 0 ? extra / qty : 0);
        },

        openAdd: function () {
          this.form = { id: 0, batch_code: '', supplier: '', import_date: new Date().toISOString().split('T')[0], shipping_fee: 0, tax_fee: 0, other_fee: 0, notes: '', items: [] };
          this.addItem();
          this.modal = true;
        },

        openEdit: function (b) {
          var self = this;
          self.form = {
            id: b.id, batch_code: b.batch_code, supplier: b.supplier,
            import_date: b.import_date, shipping_fee: b.shipping_fee,
            tax_fee: b.tax_fee, other_fee: b.other_fee, notes: b.notes,
            items: (b.items || []).map(function (i) { return { product_id: i.product_id, qty: i.qty, unit_price: i.unit_price }; })
          };
          if (!self.form.items.length) self.addItem();
          self.modal = true;
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: 1, unit_price: 0 });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
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
            import_date: self.form.import_date, shipping_fee: self.form.shipping_fee,
            tax_fee: self.form.tax_fee, other_fee: self.form.other_fee, notes: self.form.notes
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
        fmtNum: HTWApp.fmtNum
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

        get revenue() { return this.form.items.reduce(function (s, i) { return s + parseFloat(i.qty||0) * parseFloat(i.sale_price||0); }, 0); },
        get cogs()    { return this.form.items.reduce(function (s, i) { return s + parseFloat(i.qty||0) * parseFloat(i.avg_cost||0); }, 0); },
        get profit()  { return this.revenue - this.cogs; },

        openAdd: function () {
          this.form = { id: 0, order_code: '', channel: 'facebook', order_date: new Date().toISOString().split('T')[0], customer_name: '', notes: '', items: [] };
          this.addItem();
          this.modal = true;
        },

        openEdit: function (o) {
          this.form = {
            id: o.id, order_code: o.order_code, channel: o.channel,
            order_date: o.order_date, customer_name: o.customer_name, notes: o.notes,
            items: (o.items || []).map(function (i) { return { product_id: i.product_id, qty: i.qty, sale_price: i.sale_price, avg_cost: i.cogs_per_unit }; })
          };
          if (!this.form.items.length) this.addItem();
          this.modal = true;
        },

        addItem: function () {
          this.form.items.push({ product_id: '', qty: 1, sale_price: 0, avg_cost: 0 });
        },

        removeItem: function (idx) {
          this.form.items.splice(idx, 1);
        },

        onProductChange: function (item) {
          var p = this.products.find(function (x) { return x.id == item.product_id; });
          if (p) item.avg_cost = parseFloat(p.avg_cost || 0);
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
  }

  if (window.Alpine) {
    registerComponents();
  } else {
    document.addEventListener('alpine:init', registerComponents);
  }

})(jQuery);