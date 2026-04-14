# HTWarehouse Unit Tests

## Cấu trúc

```
tests/
├── bootstrap.php                   # Stub WordPress globals, autoloader
└── Unit/
    ├── NumberHelperTest.php         # bcmath arithmetic, comparison, normalization
    ├── CostCalculatorTest.php       # WAC formula, allocate_extra_costs, remainder
    ├── ReportMovementTest.php       # NXT: closing = opening + qty_in - qty_out
    ├── ReturnCalculationTest.php    # WAC on return, net financials, order status
    └── PaymentLogicTest.php         # Overpayment guard, status regression
```

## Chạy test

```bash
# Tất cả tests
vendor/bin/phpunit

# Verbose (tên từng test)
vendor/bin/phpunit --testdox

# 1 file cụ thể
vendor/bin/phpunit tests/Unit/PaymentLogicTest.php

# 1 method cụ thể
vendor/bin/phpunit --filter test_wac_blended_correctly

# Qua composer
composer test
```

## Yêu cầu

- PHP 8.1+
- Extension `bcmath` — kiểm tra: `php -m | grep bcmath`
- Không cần WordPress, không cần DB

---

## Coverage tổng hợp

| File | Tests | Assertions | Phủ |
|---|---|---|---|
| `NumberHelperTest` | 21 | 32 | Toàn bộ `NumberHelper` |
| `CostCalculatorTest` | 16 | 30 | WAC add/deduct, phân bổ chi phí |
| `ReportMovementTest` | 21 | 33 | Công thức NXT, regression Lego76300 |
| `ReturnCalculationTest` | 19 | 32 | WAC hoàn kho, tài chính net, status |
| `PaymentLogicTest` | 33 | 61 | Overpayment, status regression |
| **Tổng** | **110** | **188** | |

---

## Bug regressions được bảo vệ

| Test method | Bug đã từng xảy ra |
|---|---|
| `test_regression_no_period_activity_opening_equals_closing` | NXT hiển thị opening=12, closing=6 khi không có giao dịch trong kỳ |
| `test_allocate_sum_equals_extra_cost_exactly` | SUM phí phân bổ ≠ tổng phí thực tế do rounding tích lũy |
| `test_sub_decimal_precision` | `1.0 - 0.9 ≠ 0.1` với PHP float native |
| `test_payment_with_1d_tolerance_allowed` | Chặn nhầm nhập khi chênh lệch 1đ do rounding giao diện |
| `test_return_to_zero_stock_uses_cogs_back_as_new_avg` | WAC sai khi hoàn kho về từ 0 tồn |
| `test_delete_payment_reverts_to_confirmed_if_no_confirmed_batch` | Status nhảy sai: `paid_off → received` khi chưa nhận hàng |

---

## Thêm test mới

```php
<?php
namespace HTWarehouse\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyFeatureTest extends TestCase
{
    public function test_something(): void
    {
        $this->assertTrue(true);
    }
}
```

Đặt file vào `tests/Unit/` — PHPUnit tự discover, không cần đăng ký thêm.
