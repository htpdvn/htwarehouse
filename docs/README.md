# HTWarehouse — Tài liệu hướng dẫn sử dụng

**Phiên bản:** 1.0.0  
**Nền tảng:** WordPress Plugin  
**Ngôn ngữ giao diện:** Tiếng Việt  
**Đơn vị tiền tệ:** VND (₫)

---

## Mục lục

1. [Tổng quan](#1-tổng-quan)
2. [Cài đặt](#2-cài-đặt)
3. [Kiến trúc hệ thống](#3-kiến-trúc-hệ-thống)
4. [Module Dashboard](#4-module-dashboard)
5. [Module Sản phẩm](#5-module-sản-phẩm)
6. [Module Nhập kho](#6-module-nhập-kho)
7. [Module Xuất kho / Bán hàng](#7-module-xuất-kho--bán-hàng)
8. [Module Báo cáo](#8-module-báo-cáo)
9. [Phương pháp tính giá vốn (WAC)](#9-phương-pháp-tính-giá-vốn-wac)
10. [Quy trình nghiệp vụ mẫu](#10-quy-trình-nghiệp-vụ-mẫu)
11. [Cấu trúc database](#11-cấu-trúc-database)
12. [Cấu trúc thư mục](#12-cấu-trúc-thư-mục)
13. [Câu hỏi thường gặp](#13-câu-hỏi-thường-gặp)

---

## 1. Tổng quan

**HTWarehouse** là plugin WordPress quản lý kho hàng chuyên biệt cho mô hình kinh doanh thương mại điện tử qua các kênh **Facebook, TikTok Shop, Shopee**. Plugin xử lý toàn bộ vòng đời hàng hoá:

```
Nhập hàng (lô) → Tính giá vốn → Tồn kho → Xuất hàng / Bán → Báo cáo lợi nhuận
```

### Tính năng chính

| Tính năng | Mô tả |
|---|---|
| 📦 Quản lý sản phẩm | SKU, barcode, ảnh (upload / URL), danh mục |
| 📥 Nhập kho theo lô | Phân bổ chi phí vận chuyển, thuế, phụ phí |
| 📊 Giá vốn tự động | Bình quân gia quyền (WAC) cập nhật thời gian thực |
| 📤 Xuất kho / Đơn bán | 4 kênh: Facebook, TikTok, Shopee, Khác |
| 💰 Lợi nhuận | Tính tự động theo từng đơn, từng sản phẩm, từng kênh |
| 📈 Báo cáo | Tồn kho, Nhập xuất tồn, Lợi nhuận theo SP / kênh |
| 🔐 Bảo mật | Chỉ admin WordPress mới truy cập được |

---

## 2. Cài đặt

### Yêu cầu hệ thống
- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+

### Cài đặt thủ công

1. Upload thư mục `htwarehouse/` vào `/wp-content/plugins/`
2. Vào **WordPress Admin → Plugins → Installed Plugins**
3. Kích hoạt **HTWarehouse**
4. Plugin tự động tạo 5 bảng database trong lần chạy đầu tiên
5. Menu **HTWarehouse** xuất hiện trong sidebar admin

### Cài đặt qua WP-CLI

```bash
wp plugin activate htwarehouse --allow-root
```

### Kiểm tra sau cài đặt

```bash
wp eval 'global $wpdb; 
$tables = $wpdb->get_results("SHOW TABLES LIKE \"wp_htw%\"", ARRAY_N);
foreach($tables as $t) echo $t[0]."\n";' --allow-root
```

Kết quả mong đợi:
```
wp_htw_export_items
wp_htw_export_orders
wp_htw_import_batches
wp_htw_import_items
wp_htw_products
```

---

## 3. Kiến trúc hệ thống

```
htwarehouse/
├── htwarehouse.php                 ← Bootstrap, autoloader, activation hook
├── includes/
│   ├── Plugin.php                  ← Singleton khởi tạo plugin
│   ├── Database.php                ← Cài đặt / nâng cấp schema database  
│   ├── Admin.php                   ← Đăng ký menu, assets, AJAX handlers
│   ├── Pages/
│   │   ├── DashboardPage.php       ← Trang tổng quan KPI
│   │   ├── ProductsPage.php        ← CRUD sản phẩm + ảnh
│   │   ├── ImportPage.php          ← Quản lý lô nhập kho
│   │   ├── ExportPage.php          ← Quản lý đơn bán / xuất kho
│   │   └── ReportsPage.php         ← 4 loại báo cáo
│   └── Services/
│       └── CostCalculator.php      ← WAC + phân bổ chi phí
├── assets/
│   ├── css/htw-admin.css           ← Dark theme CSS
│   └── js/htw-admin.js             ← Alpine.js components
├── templates/
│   ├── dashboard.php
│   ├── products/list.php
│   ├── imports/list.php
│   ├── exports/list.php
│   └── reports/index.php
└── docs/
    └── README.md                   ← Tài liệu này
```

### Luồng dữ liệu

```
[ProductsPage]  →  wp_htw_products  (tồn kho + avg_cost)
      ↑                    ↑ ↓
[ImportPage]    →  wp_htw_import_batches / wp_htw_import_items
                           ↓  CostCalculator::add_stock()
                   wp_htw_products (cập nhật WAC)
                           ↓
[ExportPage]    →  wp_htw_export_orders / wp_htw_export_items
                           ↓  CostCalculator::deduct_stock()
                   wp_htw_products (trừ stock)
```

---

## 4. Module Dashboard

**Đường dẫn:** Admin → HTWarehouse

Trang tổng quan hiển thị tình hình kinh doanh tháng hiện tại.

### KPI Cards (4 thẻ)

| Thẻ | Dữ liệu |
|---|---|
| 🟣 Tổng sản phẩm | Số lượng sản phẩm trong danh mục |
| 🔵 Giá trị tồn kho | `SUM(current_stock × avg_cost)` toàn bộ kho |
| 🟢 Doanh thu tháng | Tổng `total_revenue` đơn đã xác nhận trong tháng |
| 🟡 Lợi nhuận tháng | Tổng `total_profit` + % margin |

### Biểu đồ 6 tháng

Biểu đồ cột (Chart.js) thể hiện **Doanh thu** và **Lợi nhuận** trong 6 tháng gần nhất.

### Top 5 sản phẩm

Xếp hạng theo số lượng bán trong tháng hiện tại.

### Cảnh báo hàng sắp hết (≤ 5 đơn vị)

Danh sách sản phẩm có tồn kho ≤ 5, tô màu đỏ để cảnh báo.

---

## 5. Module Sản phẩm

**Đường dẫn:** Admin → HTWarehouse → Sản phẩm

### Các trường thông tin

| Trường | Bắt buộc | Mô tả |
|---|---|---|
| Tên sản phẩm | ✅ | Tên hiển thị |
| SKU | ❌ | Mã sản phẩm (duy nhất, tự chọn) |
| Danh mục | ❌ | VD: Đồ gia dụng, Đồ chơi trẻ em |
| Đơn vị | ❌ | VD: cái, bộ, hộp, chiếc |
| Barcode | ❌ | Mã vạch sản phẩm |
| Hình ảnh | ❌ | Upload qua WP Media **hoặc** nhập URL |
| Ghi chú | ❌ | Mô tả thêm |

> **Tồn kho** và **Giá vốn bình quân** được hệ thống tự động tính — không nhập thủ công.

### Hình ảnh sản phẩm

Có 2 cách thêm ảnh:
1. **Upload:** Nhấn nút **"📁 Tải ảnh lên"** → chọn từ thư viện WordPress Media
2. **URL:** Nhập trực tiếp đường link ảnh (VD: từ Shopee, Google Drive, Dropbox)

### Xoá sản phẩm

Sản phẩm chỉ được xoá khi **tồn kho = 0**. Nếu còn hàng, hệ thống báo lỗi.

---

## 6. Module Nhập kho

**Đường dẫn:** Admin → HTWarehouse → Nhập kho

### Khái niệm "Lô nhập"

Mỗi lần nhập hàng từ nhà cung cấp = 1 lô. Một lô có thể chứa nhiều sản phẩm khác nhau.

### Trường thông tin lô nhập

| Trường | Mô tả |
|---|---|
| Mã lô | Tự sinh (VD: `IMP-A3F2B1`) hoặc tự đặt |
| Nhà cung cấp | Tên đối tác nhập hàng |
| Ngày nhập | Mặc định = hôm nay |
| Danh sách hàng | Chọn sản phẩm + số lượng + đơn giá |
| Phí vận chuyển | Chi phí ship quốc tế/nội địa |
| Thuế nhập khẩu | Thuế hải quan (nếu có) |
| Chi phí khác | Phí lưu kho, kiểm định, v.v. |

### Phân bổ chi phí

Chi phí lô (ship + thuế + phụ phí) được phân bổ cho từng sản phẩm theo **tỷ lệ giá trị**:

```
Tỷ lệ SP_A = (qty_A × giá_A) / Tổng giá trị hàng
Chi phí phân bổ cho A = Tổng chi phí lô × Tỷ lệ SP_A
Giá vốn SP_A = Đơn giá + (Chi phí phân bổ / qty_A)
```

🔍 **Preview giá vốn ước tính** hiển thị ngay khi nhập liệu, trước khi xác nhận.

### Quy trình nhập lô

1. Nhấn **"+ Tạo lô nhập mới"**
2. Nhập thông tin lô, thêm sản phẩm
3. Nhấn **"Lưu nháp"** → Lô ở trạng thái `Nháp` (chưa ảnh hưởng kho)
4. Kiểm tra lại → Nhấn **"✓ Xác nhận"**
5. Hệ thống tự động:
   - Tính giá vốn sau phân bổ
   - Cập nhật WAC từng sản phẩm
   - Cộng tồn kho
   - Khoá lô (không thể sửa/xoá)

> ⚠️ **Lưu ý:** Sau khi xác nhận, lô hàng bị khoá vĩnh viễn để đảm bảo tính toán vẹn số liệu.

---

## 7. Module Xuất kho / Bán hàng

**Đường dẫn:** Admin → HTWarehouse → Xuất kho / Bán

### Kênh bán hàng

| Kênh | Badge |
|---|---|
| Facebook | 🔵 Facebook |
| TikTok Shop | ⚫ TikTok |
| Shopee | 🟠 Shopee |
| Khác | Khác |

### Trường thông tin đơn bán

| Trường | Mô tả |
|---|---|
| Mã đơn | Tự sinh (VD: `ORD-B7C2D1`) hoặc tự đặt |
| Kênh bán | Chọn 1 trong 4 kênh |
| Ngày bán | Ngày thực tế bán hàng |
| Tên khách hàng | Tuỳ chọn |
| Sản phẩm | Chọn từ danh mục + số lượng + giá bán |

> 💡 **Giá vốn TB** được tự động điền từ `avg_cost` hiện tại của sản phẩm. Lợi nhuận preview hiển thị ngay theo từng dòng.

### Quy trình tạo đơn bán

1. Nhấn **"+ Tạo đơn mới"**
2. Chọn kênh, ngày, thêm sản phẩm + giá bán
3. Kiểm tra preview lợi nhuận từng dòng
4. Nhấn **"Lưu đơn nháp"** → Chưa trừ kho
5. Nhấn **"✓ XN"** (Xác nhận) → Hệ thống:
   - Kiểm tra đủ tồn kho (nếu thiếu → báo lỗi)
   - Ghi nhận giá vốn tại thời điểm bán
   - Trừ tồn kho
   - Tính lợi nhuận chính xác
   - Khoá đơn

> ⚠️ Chỉ có thể xoá đơn ở trạng thái **Nháp**. Đơn đã xác nhận không thể sửa/xoá.

---

## 8. Module Báo cáo

**Đường dẫn:** Admin → HTWarehouse → Báo cáo

### Tab 1 — Tồn kho hiện tại

Snapshot tức thời (không cần chọn kỳ):

| Cột | Mô tả |
|---|---|
| SKU / Tên | Định danh sản phẩm |
| Tồn kho | Số lượng hiện có (màu đỏ nếu ≤ 5) |
| Giá vốn TB | Giá vốn bình quân gia quyền hiện tại |
| Giá trị kho | `tồn × giá vốn` |

**Tổng giá trị kho** hiển thị ở đầu trang.

### Tab 2 — Nhập Xuất Tồn theo kỳ

Chọn **Từ ngày → Đến ngày**, hệ thống tính:

```
Tồn cuối kỳ = Tồn đầu kỳ + Nhập kỳ - Xuất kỳ
Tồn đầu kỳ  = Tồn hiện tại - Nhập kỳ + Xuất kỳ
```

> Tồn đầu kỳ được suy ra từ tồn hiện tại và biến động trong kỳ.

### Tab 3 — Lợi nhuận theo sản phẩm

Xếp hạng sản phẩm theo lợi nhuận:

| Cột | Mô tả |
|---|---|
| SL bán | Tổng số lượng xuất trong kỳ |
| Doanh thu | Tổng `qty × giá bán` |
| Giá vốn | Tổng `qty × giá vốn tại thời điểm bán` |
| Lợi nhuận | Doanh thu − Giá vốn |
| Margin % | `(Lợi nhuận / Doanh thu) × 100` |

**Màu margin:** 🟢 ≥ 20% · 🟡 10–19% · 🔴 < 10%

### Tab 4 — Lợi nhuận theo kênh bán

So sánh hiệu quả giữa Facebook, TikTok Shop, Shopee, Khác:

| Cột | Mô tả |
|---|---|
| Kênh | Facebook / TikTok / Shopee / Khác |
| Số đơn | Tổng đơn đã xác nhận |
| Doanh thu | Tổng doanh thu kênh |
| Lợi nhuận | Tổng lợi nhuận kênh |
| Margin % | % lợi nhuận trên doanh thu |

---

## 9. Phương pháp tính giá vốn (WAC)

HTWarehouse dùng **Bình quân gia quyền di động** (Weighted Average Cost - WAC).

### Công thức

```
Giá vốn mới = (Tồn kho cũ × Giá vốn cũ) + (Số lượng nhập × Giá vốn nhập lô)
              ─────────────────────────────────────────────────────────────────
                              (Tồn kho cũ + Số lượng nhập)
```

### Ví dụ minh hoạ

**Bước 1:** Nhập lô 1 — 10 cái đồ chơi, đơn giá 100.000₫/cái, phí ship 50.000₫

```
Phí phân bổ: 50.000 / 10 = 5.000₫/cái
Giá vốn lô 1: 100.000 + 5.000 = 105.000₫/cái
Sau lô 1: stock=10, avg_cost=105.000₫
```

**Bước 2:** Bán 3 cái với giá 180.000₫/cái

```
Giá vốn lúc bán: 105.000₫/cái
Doanh thu: 3 × 180.000 = 540.000₫
Giá vốn: 3 × 105.000 = 315.000₫
Lợi nhuận: 225.000₫ (Margin: 41.7%)
Tồn còn: 7 cái, avg_cost vẫn 105.000₫
```

**Bước 3:** Nhập lô 2 — 5 cái, đơn giá 120.000₫/cái, phí ship 30.000₫

```
Phí phân bổ: 30.000 / 5 = 6.000₫/cái
Giá vốn lô 2: 120.000 + 6.000 = 126.000₫/cái
WAC mới = (7 × 105.000 + 5 × 126.000) / (7 + 5)
         = (735.000 + 630.000) / 12
         = 1.365.000 / 12
         = 113.750₫/cái
Sau lô 2: stock=12, avg_cost=113.750₫
```

### Vì sao chọn WAC?

| Tiêu chí | WAC | FIFO |
|---|---|---|
| Độ phức tạp | Thấp ✅ | Cao |
| Phù hợp hàng nhập nhiều lô | ✅ | Hợp lý nhưng phức tạp |
| Giá vốn ổn định | ✅ | Biến động theo lô |
| Thích hợp khi giá hay đổi | ✅ | Kém ổn định hơn |
| Được chuẩn mực kế toán chấp nhận | ✅ (VAS) | ✅ (VAS) |

---

## 10. Quy trình nghiệp vụ mẫu

### Kịch bản: Nhập và bán đồ chơi xe lửa gỗ

#### Bước 1 — Thêm sản phẩm

1. Vào **Sản phẩm → + Thêm sản phẩm**
2. Nhập: Tên = "Xe lửa gỗ 3 toa", SKU = "TOY-TRAIN-001", Danh mục = "Đồ chơi", ĐVT = "bộ"
3. Upload ảnh hoặc dán URL từ Shopee
4. Lưu → Sản phẩm xuất hiện với tồn kho = 0

#### Bước 2 — Nhập lô hàng từ Trung Quốc

1. Vào **Nhập kho → + Tạo lô nhập mới**
2. Nhà cung cấp: "Guangzhou Toys Ltd"
3. Thêm sản phẩm: Xe lửa gỗ 3 toa, SL = 50 bộ, Đơn giá = 85.000₫
4. Phí vận chuyển: 2.000.000₫
5. Hệ thống preview giá vốn: 85.000 + (2.000.000/50) = **125.000₫/bộ**
6. Lưu nháp → Xác nhận
7. Tồn kho: 50 bộ, Giá vốn TB: 125.000₫

#### Bước 3 — Tạo đơn bán qua Shopee

1. Vào **Xuất kho → + Tạo đơn mới**
2. Kênh = Shopee, Ngày = hôm nay
3. Thêm: Xe lửa gỗ, SL = 5, Giá bán = 220.000₫
4. Preview: Doanh thu 1.100.000₫, Giá vốn 625.000₫, **Lợi nhuận 475.000₫** (43.2%)
5. Lưu nháp → Xác nhận → Kho giảm còn 45 bộ

#### Bước 4 — Xem báo cáo

- Tab **Lợi nhuận / Kênh** → thấy Shopee đang có margin 43.2%
- Tab **Tồn kho** → còn 45 bộ, giá trị kho = 45 × 125.000 = 5.625.000₫

---

## 11. Cấu trúc database

### `wp_htw_products`
| Cột | Kiểu | Mô tả |
|---|---|---|
| id | BIGINT | Primary key |
| sku | VARCHAR(100) | Mã sản phẩm (unique) |
| name | VARCHAR(255) | Tên sản phẩm |
| category | VARCHAR(100) | Danh mục |
| unit | VARCHAR(50) | Đơn vị tính |
| barcode | VARCHAR(100) | Mã vạch |
| image_url | TEXT | URL hình ảnh |
| current_stock | DECIMAL(15,3) | **Tồn kho hiện tại** (auto) |
| avg_cost | DECIMAL(15,2) | **Giá vốn bình quân** (auto) |
| notes | TEXT | Ghi chú |

### `wp_htw_import_batches`
| Cột | Kiểu | Mô tả |
|---|---|---|
| id | BIGINT | Primary key |
| batch_code | VARCHAR(50) | Mã lô (unique) |
| supplier | VARCHAR(255) | Nhà cung cấp |
| import_date | DATE | Ngày nhập |
| shipping_fee | DECIMAL(15,2) | Phí vận chuyển |
| tax_fee | DECIMAL(15,2) | Thuế nhập khẩu |
| other_fee | DECIMAL(15,2) | Chi phí khác |
| status | ENUM | `draft` / `confirmed` |

### `wp_htw_import_items`
| Cột | Kiểu | Mô tả |
|---|---|---|
| batch_id | BIGINT | FK → import_batches |
| product_id | BIGINT | FK → products |
| qty | DECIMAL(15,3) | Số lượng nhập |
| unit_price | DECIMAL(15,2) | Đơn giá gốc |
| allocated_cost_per_unit | DECIMAL(15,4) | Giá vốn sau phân bổ |
| total_cost | DECIMAL(15,2) | Thành tiền |

### `wp_htw_export_orders`
| Cột | Kiểu | Mô tả |
|---|---|---|
| id | BIGINT | Primary key |
| order_code | VARCHAR(50) | Mã đơn (unique) |
| channel | ENUM | `facebook`/`tiktok`/`shopee`/`other` |
| order_date | DATE | Ngày bán |
| customer_name | VARCHAR(255) | Tên khách |
| total_revenue | DECIMAL(15,2) | Tổng doanh thu |
| total_cogs | DECIMAL(15,2) | Tổng giá vốn |
| total_profit | DECIMAL(15,2) | Tổng lợi nhuận |
| status | ENUM | `draft` / `confirmed` |

### `wp_htw_export_items`
| Cột | Kiểu | Mô tả |
|---|---|---|
| order_id | BIGINT | FK → export_orders |
| product_id | BIGINT | FK → products |
| qty | DECIMAL(15,3) | Số lượng bán |
| sale_price | DECIMAL(15,2) | Giá bán/đơn vị |
| cogs_per_unit | DECIMAL(15,4) | Giá vốn tại thời điểm bán |
| revenue | DECIMAL(15,2) | Doanh thu dòng |
| cogs | DECIMAL(15,2) | Giá vốn dòng |
| profit | DECIMAL(15,2) | Lợi nhuận dòng |

---

## 12. Cấu trúc thư mục

```
htwarehouse/
├── htwarehouse.php                 ← Plugin bootstrap
├── includes/
│   ├── Plugin.php
│   ├── Database.php
│   ├── Admin.php
│   ├── Pages/
│   │   ├── DashboardPage.php
│   │   ├── ProductsPage.php
│   │   ├── ImportPage.php
│   │   ├── ExportPage.php
│   │   └── ReportsPage.php
│   └── Services/
│       └── CostCalculator.php
├── assets/
│   ├── css/htw-admin.css
│   └── js/htw-admin.js
├── templates/
│   ├── dashboard.php
│   ├── products/list.php
│   ├── imports/list.php
│   ├── exports/list.php
│   └── reports/index.php
└── docs/
    └── README.md
```

---

## 13. Câu hỏi thường gặp

**Q: Giá vốn trong đơn bán có thay đổi nếu tôi nhập thêm hàng sau?**  
A: Không. Khi đơn bán được **xác nhận**, giá vốn tại thời điểm đó được ghi nhận vĩnh viễn vào `cogs_per_unit`. Nhập hàng sau không ảnh hưởng đến lợi nhuận đơn cũ.

**Q: Tôi có thể sửa đơn hàng đã xác nhận không?**  
A: Không. Để bảo toàn số liệu kế toán, đơn đã xác nhận bị khoá. Chỉ chỉnh sửa được khi còn ở trạng thái Nháp.

**Q: Tồn kho có thể xuống số âm không?**  
A: Không. Khi xác nhận đơn bán, hệ thống kiểm tra đủ hàng trước. Nếu thiếu, hiển thị thông báo lỗi rõ ràng.

**Q: Sản phẩm không có biến thể, muốn thêm sau có được không?**  
A: Phiên bản 1.0 chưa hỗ trợ biến thể. Workaround: tạo sản phẩm riêng cho mỗi biến thể (VD: "Đồ chơi xe lửa - Màu đỏ", "Đồ chơi xe lửa - Màu xanh").

**Q: Có xuất báo cáo ra Excel không?**  
A: Phiên bản 1.0 chưa có. Dự kiến bổ sung ở phiên bản 1.1.

**Q: Plugin có xung đột với HTMembership không?**  
A: Không. HTWarehouse hoàn toàn độc lập, dùng namespace và prefix DB riêng (`HTWarehouse\`, `wp_htw_`).

---

*Tài liệu cập nhật: 2026-04-11 | Phiên bản plugin: 1.0.0*
