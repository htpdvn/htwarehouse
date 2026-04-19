<?php

namespace HTWarehouse\Services;

use TCPDF;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Clean monochrome PDF reports — grayscale only, print-safe.
 * Absolute XY positioning. Right-aligned cells always use right-edge calculation.
 */
class PdfService
{
    private TCPDF $pdf;
    private string $currency = 'VND';

    private float $pw = 277;  // content width (landscape A4)
    private float $ph = 190;  // content height
    private float $ml = 15;   // margin left
    private float $mt = 10;   // margin top
    private float $y  = 10;   // current drawing Y position (mm)

    private string $companyName = '';
    private string $companyAddr = '';
    private string $companyTax  = '';
    private string $reportCode  = '';
    private string $reportTitle = '';
    private string $dateFrom     = '';
    private string $dateTo       = '';

    // Grayscale palette — B&W printer safe
    private const C_BLACK   = [20, 20, 20];
    private const C_DARK     = [50, 50, 50];
    private const C_MID      = [90, 90, 90];
    private const C_SLATE    = [120, 120, 120];
    private const C_LIGHT    = [160, 160, 160];
    private const C_LINER    = [180, 180, 180];
    private const C_ALT      = [242, 242, 242];
    private const C_WHITE    = [255, 255, 255];

    private const FONT    = 'dejavusans';
    private const FONT_B  = 'dejavusansb';

    // ─── Public API ─────────────────────────────────────────────────────────

    public static function generate(
        string $report,
        string $dateFrom,
        string $dateTo,
        array $data,
        string $currency = 'VND'
    ): string {
        $inst = new self();
        $inst->currency = $currency;
        $inst->setMeta($report, $dateFrom, $dateTo);
        $inst->build($report, $dateFrom, $dateTo, $data);
        return $inst->pdf->Output('', 'S');
    }

    public function setCompanyInfo(string $name, string $addr = '', string $tax = ''): void
    {
        $this->companyName = $name;
        $this->companyAddr = $addr;
        $this->companyTax  = $tax;
    }

    // ─── Setup ─────────────────────────────────────────────────────────────

    private function build(string $report, string $from, string $to, array $data): void
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        $pageW = $this->pdf->getPageWidth();
        $pageH = $this->pdf->getPageHeight();
        $this->pw = $pageW - $this->ml * 2;
        $this->ph = $pageH - $this->mt * 2;

        $this->pdf->SetCreator('PDF Report');
        $this->pdf->SetAuthor($this->companyName ?: 'Report');
        $this->pdf->SetTitle($this->reportTitle);
        $this->pdf->SetSubject($this->reportTitle);
        $this->pdf->SetAutoPageBreak(false, 0);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetHeaderMargin(0);
        $this->pdf->SetFooterMargin(0);
        $this->pdf->AddPage();

        $this->drawReport($report, $from, $to, $data);
        $this->drawFooter();
    }

    private const FOOTER_H = 7; // reserved height for footer at page bottom

    /**
     * Check if there's enough room to draw $needed mm of content.
     * If not, start a new page.
     */
    private function ensurePage(float $needed): void {
        $pageH = $this->pdf->getPageHeight();
        $bottomEdge = $pageH - self::FOOTER_H;
        if ($this->y + $needed > $bottomEdge) {
            $this->drawFooter();
            $this->pdf->AddPage();
            $this->drawPageHeader();
            // drawPageHeader() already sets $this->y correctly at its end
        }
    }

    /**
     * Redraw the thin top-bar and report title on a new page.
     */
    private function drawPageHeader(): void {
        $l = $this->ml;
        $w = $this->pw;
        $y = $this->mt;

        $barH = 18;
        $this->rect($l, $y, $w, $barH, self::C_DARK);

        $this->f(self::FONT_B, 10, self::C_WHITE);
        $this->cellL($l + 4, $y + 4, 200, 5, $this->companyName ?: '');

        $bw = 30;
        $bx = $l + $w - $bw - 4;
        $this->rect($bx, $y + 3, $bw, 12, self::C_DARK);
        $this->f(self::FONT_B, 9, self::C_WHITE);
        $this->cellC($bx, $y + 4, $bw, 5, $this->reportCode);

        $this->line($l, $y + $barH, $l + $w, $y + $barH, self::C_LINER, 0.3);

        // After bar: add small gap then repeat the colored-left-bar title strip
        // so continuation pages look consistent with page 1.
        $stripY = $y + $barH + 4;         // 32mm
        $stripH = 22;
        $this->rect($l, $stripY, 4, $stripH, self::C_DARK);

        $this->f(self::FONT_B, 12, self::C_BLACK);
        $this->cellL($l + 7, $stripY + 3, $w - 10, 7, mb_strtoupper($this->reportTitle, 'UTF-8'));

        $this->f(self::FONT, 8.5, self::C_MID);
        $period = $this->dateFrom && $this->dateTo
            ? ('Kỳ  ' . $this->d($this->dateFrom) . '  –  ' . $this->d($this->dateTo))
            : ('Tính đến  ' . $this->d(date('Y-m-d')));
        $this->cellL($l + 7, $stripY + 12, $w - 80, 4, $period);

        $this->line($l, $stripY + $stripH, $l + $w, $stripY + $stripH, self::C_LINER, 0.4);

        $this->y = $stripY + $stripH + 4;

        // Reset font to default row style so the first detail row on
        // continuation pages looks identical to rows on page 1.
        $this->f(self::FONT, 7.5, self::C_DARK);
    }

    private function setMeta(string $r, string $from = '', string $to = ''): void {
        $this->reportCode = [
            'stock'               => 'RPT-01',
            'movement'           => 'RPT-02',
            'profit_by_product'   => 'RPT-03',
            'profit_by_channel'   => 'RPT-04',
            'product_performance' => 'RPT-05',
        ][$r] ?? 'RPT-00';

        $this->reportTitle = [
            'stock'               => 'Báo Cáo Tồn Kho',
            'movement'            => 'Báo Cáo Xuất Nhập Kho',
            'profit_by_product'   => 'Lãi Lỗ Theo Sản Phẩm',
            'profit_by_channel'   => 'Lãi Lỗ Theo Kênh Bán',
            'product_performance' => 'Hiệu Suất Dòng Sản Phẩm',
        ][$r] ?? 'Báo Cáo';

        $this->dateFrom = $from;
        $this->dateTo   = $to;
    }

    // ─── Formatters ─────────────────────────────────────────────────────────

    private function m(float $v): string  { return number_format($v, 0, ',', '.'); }
    private function n(float $v, int $d = 1): string { return rtrim(rtrim(number_format($v, $d, ',', '.'), '0'), ','); }
    private function d(string $v): string {
        if (empty($v)) return '—';
        $p = explode('-', $v);
        return count($p) >= 3 ? ($p[2] . '/' . $p[1] . '/' . $p[0]) : $v;
    }

    // ─── Drawing primitives ────────────────────────────────────────────────

    private function f(string $face, float $sz, array $rgb): void {
        $this->pdf->SetFont($face, '', $sz);
        $this->pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function bg(array $rgb): void {
        $this->pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function bd(array $rgb): void {
        $this->pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Left-aligned cell: x = left edge of cell
     */
    private function cellL(float $x, float $y, float $w, float $h, string $text): void {
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell($w, $h, $text, 0, 0, 'L');
    }

    /**
     * Center cell: x = left edge of cell
     */
    private function cellC(float $x, float $y, float $w, float $h, string $text): void {
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell($w, $h, $text, 0, 0, 'C');
    }

    /**
     * Right-aligned cell: x = right edge of the cell (not left edge!)
     */
    private function cellR(float $rightEdge, float $y, float $w, float $h, string $text): void {
        $this->pdf->SetXY($rightEdge - $w, $y);
        $this->pdf->Cell($w, $h, $text, 0, 0, 'R');
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $lw = 0.15): void {
        $this->bd($rgb);
        $this->pdf->SetLineWidth($lw);
        $this->pdf->Line($x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->bg($rgb);
        $this->pdf->Rect($x, $y, $w, $h, 'F');
    }

    /**
     * Helper to compute the right edge of column i in a column-width array,
     * counting from right-to-left (typical for tables where numbers are right-aligned).
     * $cols is an array of widths from LEFT to RIGHT.
     * $rightEdge is the right edge of the entire table ($l + $w).
     */
    private function colRight(float $rightEdge, array $cols, int $i): float {
        // sum all columns to the RIGHT of index i
        $sum = 0;
        for ($j = $i + 1; $j < count($cols); $j++) {
            $sum += $cols[$j];
        }
        return $rightEdge - $sum;
    }

    // ─── Master renderer ────────────────────────────────────────────────────

    private function drawReport(string $report, string $from, string $to, array $data): void {
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;
        $this->y = $this->mt;

        // ── Top bar
        $barH = 22;
        $this->rect($l, $this->y, $w, $barH, self::C_DARK);

        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + 5, $this->y + 5, 200, 6, $this->companyName ?: '');

        if ($this->companyAddr || $this->companyTax) {
            $this->f(self::FONT, 7.5, [160, 160, 160]);
            $line = trim(($this->companyAddr ?: '') . ($this->companyTax ? '   |   MST: ' . $this->companyTax : ''));
            $this->cellL($l + 5, $this->y + 12, 220, 4, $line);
        }

        $bw = 30;
        $bx = $l + $w - $bw - 4;
        $this->rect($bx, $this->y + 4, $bw, 14, self::C_DARK);
        $this->f(self::FONT_B, 10, self::C_WHITE);
        $this->cellC($bx, $this->y + 5, $bw, 6, $this->reportCode);
        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellC($bx, $this->y + 11, $bw, 5, $this->companyName ? substr($this->companyName, 0, 14) : '');

        $this->y += $barH + 6;

        // ── Title section
        $this->rect($l, $this->y, 4, 22, self::C_DARK);

        $this->f(self::FONT_B, 14, self::C_BLACK);
        $this->cellL($l + 7, $this->y + 3, $w - 10, 7, mb_strtoupper($this->reportTitle, 'UTF-8'));

        $period = ($report === 'stock')
            ? ('Tính đến  ' . $this->d($to ?: date('Y-m-d')))
            : ('Kỳ  ' . $this->d($from) . '  –  ' . $this->d($to));
        $this->f(self::FONT, 8.5, self::C_MID);
        $this->cellL($l + 7, $this->y + 12, $w - 80, 4, $period);

        $this->f(self::FONT, 8, self::C_SLATE);
        $this->cellL($l + $w - 52, $this->y + 12, 52, 4, 'Xuất  ' . date('d/m/Y, H:i'));

        $this->line($l, $this->y + 22, $l + $w, $this->y + 22, self::C_LINER, 0.4);

        $this->y += 28;

        switch ($report) {
            case 'stock':
                $this->y = $this->tblStock($this->y, $data['rows'] ?? [], $data);
                break;
            case 'movement':
                $this->y = $this->tblMovement($this->y, $data['rows'] ?? []);
                break;
            case 'profit_by_product':
                $this->y = $this->tblProfitProduct($this->y, $data['rows'] ?? [], $data);
                break;
            case 'profit_by_channel':
                $this->y = $this->tblChannel($this->y, $data['rows'] ?? [], $data);
                break;
            case 'product_performance':
                $this->y = $this->tblPerformance($this->y, $data['rows'] ?? [], $data);
                break;
        }

        $this->ensurePage(32); // reserve height for signature block
        $this->drawSignature($this->y + 6);
    }

    // ─── Stock table ──────────────────────────────────────────────────────
    // SKU | Product | Category | Unit | Stock | AvgCost | Value

    private function tblStock(float $y, array $rows, array $data): float {
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;

        // Column widths (mm), sum = $w
        $cols = [22, 54, 46, 14, 26, 34, 28];
        //     [0]   [1]   [2]    [3]  [4]   [5]     [6]

        $h  = 8;
        $rh = 6.5;

        // Header row
        $this->rect($l, $this->y, $w, $h, self::C_DARK);
        $this->f(self::FONT_B, 7.5, self::C_WHITE);

        $this->cellL($l + 2, $this->y + 1.5, $cols[0], $h, 'SKU');
        $this->cellL($l + 2 + $cols[0], $this->y + 1.5, $cols[1], $h, 'Tên Sản Phẩm');
        $this->cellL($l + 2 + $cols[0] + $cols[1], $this->y + 1.5, $cols[2], $h, 'Danh Mục');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $this->y + 1.5, $cols[3], $h, 'ĐVT');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $this->y + 1.5, $cols[4], $h, 'SL Tồn Kho');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $this->y + 1.5, $cols[5], $h, 'Giá Vốn TB');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $this->y + 1.5, $cols[6], $h, 'Giá Trị Lưu Kho');

        $this->y += $h;

        $this->f(self::FONT, 7.5, self::C_DARK);
        $this->bd(self::C_LINER);
        $this->pdf->SetLineWidth(0.1);

        $totalVal   = 0.0;
        $totalStock = 0.0;
        foreach ($rows as $i => $r) {
            $this->ensurePage($rh);

            if ($i % 2 === 1) $this->rect($l, $this->y, $w, $rh, self::C_ALT);
            $this->line($l, $this->y + $rh, $re, $this->y + $rh, self::C_LINER, 0.1);

            $stock   = (float) ($r['current_stock'] ?? 0);
            $invVal  = (float) ($r['inventory_value'] ?? 0);
            $avgCost = (float) ($r['avg_cost'] ?? 0);
            $totalVal   += $invVal;
            $totalStock += $stock;

            $ty = $this->y + 1.2;

            $this->cellL($l + 2, $ty, $cols[0], $rh, substr((string) ($r['sku'] ?? '—'), 0, 12));
            $this->cellL($l + 2 + $cols[0], $ty, $cols[1], $rh, substr((string) ($r['name'] ?? '—'), 0, 32));
            $this->cellL($l + 2 + $cols[0] + $cols[1], $ty, $cols[2], $rh, substr((string) ($r['category'] ?? '—'), 0, 26));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $ty, $cols[3], $rh, (string) ($r['unit'] ?? ''));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $ty, $cols[4], $rh, $this->n($stock));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $ty, $cols[5], $rh, $this->m($avgCost));
            $this->f(self::FONT_B, 7.5, self::C_BLACK);
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $ty, $cols[6], $rh, $this->m($invVal));
            $this->f(self::FONT, 7.5, self::C_DARK);

            $this->y += $rh;
        }

        $this->ensurePage($rh + 2 + 10);
        $this->y += 2;
        $th = 10;
        $this->rect($l, $this->y, $w, $th, self::C_ALT);
        $this->line($l, $this->y, $l + $w, $this->y, self::C_BLACK, 0.5);
        $this->line($l, $this->y + $th, $l + $w, $this->y + $th, self::C_BLACK, 0.5);

        $this->f(self::FONT_B, 8, self::C_BLACK);
        $this->cellL($l + $cols[0] + 2, $this->y, $cols[1] + $cols[2], $th, count($rows) . '  dòng sản phẩm');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $this->y, $cols[4], $th, $this->n($totalStock));
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $this->y, $cols[6], $th, $this->m($totalVal));

        return $this->y + $th;
    }

    // ─── Movement table ──────────────────────────────────────────────────
    // SKU | Product | Unit | Opening | In | Out | Closing | AvgCost

    private function tblMovement(float $y, array $rows): float {
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;

        $cols = [22, 58, 14, 26, 24, 24, 26, 28];
        //        [0]  [1]   [2]  [3]      [4] [5] [6]     [7]

        $h  = 8;
        $rh = 6.5;

        $this->rect($l, $this->y, $w, $h, self::C_DARK);
        $this->f(self::FONT_B, 7.5, self::C_WHITE);

        $this->cellL($l + 2, $this->y + 1.5, $cols[0], $h, 'SKU');
        $this->cellL($l + 2 + $cols[0], $this->y + 1.5, $cols[1], $h, 'Tên Sản Phẩm');
        $this->cellL($l + $cols[0] + $cols[1] + 2, $this->y + 1.5, $cols[2], $h, 'ĐVT');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $this->y + 1.5, $cols[3], $h, 'Đầu Kỳ');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $this->y + 1.5, $cols[4], $h, 'Nhập');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $this->y + 1.5, $cols[5], $h, 'Xuất');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $this->y + 1.5, $cols[6], $h, 'Cuối Kỳ');
        $this->cellL($re - $cols[7], $this->y + 1.5, $cols[7], $h, 'Giá TB');

        $this->y += $h;
        $this->f(self::FONT, 7.5, self::C_DARK);
        $this->bd(self::C_LINER);
        $this->pdf->SetLineWidth(0.1);

        foreach ($rows as $i => $r) {
            $this->ensurePage($rh);

            if ($i % 2 === 1) $this->rect($l, $this->y, $w, $rh, self::C_ALT);
            $this->line($l, $this->y + $rh, $re, $this->y + $rh, self::C_LINER, 0.1);

            $ty = $this->y + 1.2;

            $this->cellL($l + 2, $ty, $cols[0], $rh, substr((string) ($r['sku'] ?? '—'), 0, 12));
            $this->cellL($l + 2 + $cols[0], $ty, $cols[1], $rh, substr((string) ($r['name'] ?? '—'), 0, 36));
            $this->cellL($l + $cols[0] + $cols[1] + 2, $ty, $cols[2], $rh, (string) ($r['unit'] ?? ''));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $ty, $cols[3], $rh, $this->n($r['opening_stock'] ?? 0));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $ty, $cols[4], $rh, $this->n($r['qty_in'] ?? 0));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $ty, $cols[5], $rh, $this->n($r['qty_out'] ?? 0));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $ty, $cols[6], $rh, $this->n($r['closing_stock'] ?? 0));
            $this->f(self::FONT_B, 7.5, self::C_BLACK);
            $this->cellL($re - $cols[7], $ty, $cols[7], $rh, $this->m((float) ($r['avg_cost'] ?? 0)));
            $this->f(self::FONT, 7.5, self::C_MID);

            $this->y += $rh;
        }

        return $this->y;
    }

    // ─── Profit by product table ────────────────────────────────────────
    // SKU | Product | Unit | Qty Sold | Revenue | COGS | Profit | Margin

    private function tblProfitProduct(float $y, array $rows, array $data): float {
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;

        $cols = [22, 56, 14, 24, 36, 34, 30, 16];
        //        [0]  [1]   [2]  [3]      [4]      [5]  [6]    [7]

        $h  = 8;
        $rh = 6.5;

        $this->rect($l, $this->y, $w, $h, self::C_DARK);
        $this->f(self::FONT_B, 7.5, self::C_WHITE);

        $this->cellL($l + 2, $this->y + 1.5, $cols[0], $h, 'SKU');
        $this->cellL($l + 2 + $cols[0], $this->y + 1.5, $cols[1], $h, 'Tên Sản Phẩm');
        $this->cellL($l + $cols[0] + $cols[1] + 2, $this->y + 1.5, $cols[2], $h, 'ĐVT');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $this->y + 1.5, $cols[3], $h, 'SL Bán');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $this->y + 1.5, $cols[4], $h, 'Doanh Thu');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $this->y + 1.5, $cols[5], $h, 'Giá Vốn');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $this->y + 1.5, $cols[6], $h, 'Lợi Nhuận');
        $this->cellL($re - $cols[7], $this->y + 1.5, $cols[7], $h, 'Tỷ Suất');

        $this->y += $h;
        $this->f(self::FONT, 7.5, self::C_DARK);
        $this->bd(self::C_LINER);
        $this->pdf->SetLineWidth(0.1);

        $totalRev = $totalProf = 0.0;
        foreach ($rows as $i => $r) {
            $this->ensurePage($rh);

            if ($i % 2 === 1) $this->rect($l, $this->y, $w, $rh, self::C_ALT);
            $this->line($l, $this->y + $rh, $re, $this->y + $rh, self::C_LINER, 0.1);

            $profit = (float) ($r['total_profit'] ?? 0);
            $rev    = (float) ($r['total_revenue'] ?? 0);
            $margin = (float) ($r['margin_pct'] ?? 0);
            $totalRev  += $rev;
            $totalProf += $profit;

            $ty = $this->y + 1.2;

            $this->cellL($l + 2, $ty, $cols[0], $rh, substr((string) ($r['sku'] ?? '—'), 0, 12));
            $this->cellL($l + 2 + $cols[0], $ty, $cols[1], $rh, substr((string) ($r['name'] ?? '—'), 0, 34));
            $this->cellL($l + $cols[0] + $cols[1] + 2, $ty, $cols[2], $rh, (string) ($r['unit'] ?? ''));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 2, $ty, $cols[3], $rh, $this->n($r['total_qty'] ?? 0));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 2, $ty, $cols[4], $rh, $this->m($rev));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + 2, $ty, $cols[5], $rh, $this->m((float) ($r['total_cogs'] ?? 0)));
            $this->f(self::FONT_B, 7.5, self::C_BLACK);
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + $cols[4] + $cols[5] + 2, $ty, $cols[6], $rh, $this->m($profit));
            $this->cellL($re - $cols[7], $ty, $cols[7], $rh, number_format($margin, 1) . '%');
            $this->f(self::FONT, 7.5, self::C_DARK);

            $this->y += $rh;
        }

        $totalMargin = $totalRev > 0 ? ($totalProf / $totalRev * 100) : 0;
        $this->ensurePage($sh + 3);
        $this->y += 3;

        $sh = 14;
        $sw = $w / 3;
        $lh = 4;
        $vh = 5;
        $this->rect($l, $this->y, $w, $sh, self::C_DARK);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + 4, $this->y + 2, $sw, $lh, 'Tổng Doanh Thu');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + 4, $this->y + 6, $sw, $vh, $this->m($totalRev) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw + 4, $this->y + 2, $sw, $lh, 'Tổng Lợi Nhuận');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw + 4, $this->y + 6, $sw, $vh, $this->m($totalProf) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw * 2 + 4, $this->y + 2, $sw, $lh, 'Biên LN TB');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw * 2 + 4, $this->y + 6, $sw, $vh, number_format($totalMargin, 1) . '%');

        return $this->y + $sh;
    }

    // ─── Profit by channel table ─────────────────────────────────────────
    // Channel | Orders | Revenue | COGS | Profit | Margin

    private function tblChannel(float $y, array $rows, array $data): float {
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;

        $cols = [70, 30, 42, 38, 34, 18];
        //        [0]  [1]   [2]     [3]   [4]    [5]

        $h  = 9;
        $rh = 9;

        $this->rect($l, $this->y, $w, $h, self::C_DARK);
        $this->f(self::FONT_B, 8.5, self::C_WHITE);

        $this->cellL($l + 4, $this->y + 1.5, $cols[0], $h, 'Kênh Bán');
        $this->cellL($l + $cols[0] + 4, $this->y + 1.5, $cols[1], $h, 'Đơn Hàng');
        $this->cellL($l + $cols[0] + $cols[1] + 4, $this->y + 1.5, $cols[2], $h, 'Doanh Thu');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 4, $this->y + 1.5, $cols[3], $h, 'Giá Vốn');
        $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 4, $this->y + 1.5, $cols[4], $h, 'Lợi Nhuận');
        $this->cellL($re - $cols[5], $this->y + 1.5, $cols[5], $h, 'Tỷ Suất');

        $this->y += $h;
        $this->f(self::FONT, 8.5, self::C_DARK);
        $this->bd(self::C_LINER);
        $this->pdf->SetLineWidth(0.1);

        $totalRev = $totalProf = 0.0;
        $totalOrders = 0;

        $labels = [
            'facebook' => 'Facebook',
            'tiktok'    => 'TikTok',
            'shopee'    => 'Shopee',
            'other'     => 'Khác',
            ''          => '—',
        ];

        foreach ($rows as $i => $r) {
            $this->ensurePage($rh);

            if ($i % 2 === 1) $this->rect($l, $this->y, $w, $rh, self::C_ALT);
            $this->line($l, $this->y + $rh, $re, $this->y + $rh, self::C_LINER, 0.1);

            $ch     = $r['channel'] ?? '';
            $profit = (float) ($r['profit'] ?? 0);
            $rev    = (float) ($r['revenue'] ?? 0);
            $margin = (float) ($r['margin_pct'] ?? 0);
            $orders = (int) ($r['total_orders'] ?? 0);
            $totalRev  += $rev;
            $totalProf += $profit;
            $totalOrders += $orders;

            $ty = $this->y + 2.8;

            $this->cellL($l + 4, $ty, $cols[0], $rh, $labels[$ch] ?? ($ch ?: '—'));
            $this->cellL($l + $cols[0] + 4, $ty, $cols[1], $rh, number_format($orders));
            $this->cellL($l + $cols[0] + $cols[1] + 4, $ty, $cols[2], $rh, $this->m($rev));
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + 4, $ty, $cols[3], $rh, $this->m((float) ($r['cogs'] ?? 0)));
            $this->f(self::FONT_B, 8.5, self::C_BLACK);
            $this->cellL($l + $cols[0] + $cols[1] + $cols[2] + $cols[3] + 4, $ty, $cols[4], $rh, $this->m($profit));
            $this->cellL($re - $cols[5], $ty, $cols[5], $rh, number_format($margin, 1) . '%');
            $this->f(self::FONT, 8.5, self::C_DARK);

            $this->y += $rh;
        }

        $totalMargin = $totalRev > 0 ? ($totalProf / $totalRev * 100) : 0;
        $this->ensurePage($sh + 3);
        $this->y += 3;

        $sh = 14;
        $sw = $w / 3;
        $lh = 4;
        $vh = 5;
        $this->rect($l, $this->y, $w, $sh, self::C_DARK);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + 4, $this->y + 2, $sw, $lh, 'Tổng Doanh Thu');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + 4, $this->y + 6, $sw, $vh, $this->m($totalRev) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw + 4, $this->y + 2, $sw, $lh, 'Tổng Lợi Nhuận');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw + 4, $this->y + 6, $sw, $vh, $this->m($totalProf) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw * 2 + 4, $this->y + 2, $sw, $lh, 'Biên LN TB');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw * 2 + 4, $this->y + 6, $sw, $vh, number_format($totalMargin, 1) . '%');

        return $this->y + $sh;
    }

    // ─── Product Performance table ─────────────────────────────────────────────
    // SP | Unit | SL Bán | Doanh Thu | Lợi Nhuận | Margin% | Vòng Quay | Trả Hàng% | Score

    private function tblPerformance(float $y, array $rows, array $data): float {
        $l  = $this->ml;
        $w  = $this->pw;
        $re = $l + $w;

        $cols = [64, 12, 20, 38, 34, 18, 22, 22, 20];
        //        [0] [1]  [2]  [3]     [4]   [5]    [6]    [7]    [8]

        $h  = 8;
        $rh = 6.5;

        // ── Header row
        $this->rect($l, $this->y, $w, $h, self::C_DARK);
        $this->f(self::FONT_B, 7, self::C_WHITE);

        $cx = $l + 2;
        $this->cellL($cx, $this->y + 1.5, $cols[0], $h, 'Tên Sản Phẩm');      $cx += $cols[0];
        $this->cellL($cx, $this->y + 1.5, $cols[1], $h, 'ĐVT');                 $cx += $cols[1];
        $this->cellL($cx, $this->y + 1.5, $cols[2], $h, 'SL Bán');            $cx += $cols[2];
        $this->cellL($cx, $this->y + 1.5, $cols[3], $h, 'Doanh Thu');           $cx += $cols[3];
        $this->cellL($cx, $this->y + 1.5, $cols[4], $h, 'Lợi Nhuận');         $cx += $cols[4];
        $this->cellL($cx, $this->y + 1.5, $cols[5], $h, 'Margin %');            $cx += $cols[5];
        $this->cellL($cx, $this->y + 1.5, $cols[6], $h, 'Vòng Quay');          $cx += $cols[6];
        $this->cellL($cx, $this->y + 1.5, $cols[7], $h, 'Trả Hàng %');        $cx += $cols[7];
        $this->cellL($cx, $this->y + 1.5, $cols[8], $h, 'Score');

        $this->y += $h;
        $this->f(self::FONT, 7, self::C_DARK);
        $this->bd(self::C_LINER);
        $this->pdf->SetLineWidth(0.1);

        $totalRev  = 0.0;
        $totalProf = 0.0;

        foreach ($rows as $i => $r) {
            if ((float)($r['net_qty_sold'] ?? 0) <= 0 && (float)($r['net_revenue'] ?? 0) <= 0) {
                continue;
            }

            $this->ensurePage($rh);

            if ($i % 2 === 1) $this->rect($l, $this->y, $w, $rh, self::C_ALT);
            $this->line($l, $this->y + $rh, $re, $this->y + $rh, self::C_LINER, 0.1);

            $rev    = (float)($r['net_revenue'] ?? 0);
            $profit = (float)($r['net_profit']  ?? 0);
            $margin = (float)($r['margin_pct']  ?? 0);
            $score  = (float)($r['performance_score'] ?? 0);
            $totalRev  += $rev;
            $totalProf += $profit;

            $ty = $this->y + 1.2;
            $cx = $l + 2;

            $nameStr = substr((string)($r['name'] ?? '—'), 0, 38);
            if (!empty($r['sku'])) $nameStr .= ' (' . substr((string)$r['sku'], 0, 10) . ')';
            $this->cellL($cx, $ty, $cols[0], $rh, substr($nameStr, 0, 46)); $cx += $cols[0];
            $this->cellL($cx, $ty, $cols[1], $rh, (string)($r['unit'] ?? '')); $cx += $cols[1];
            $this->cellL($cx, $ty, $cols[2], $rh, $this->n($r['net_qty_sold'] ?? 0)); $cx += $cols[2];
            $this->cellL($cx, $ty, $cols[3], $rh, $this->m($rev)); $cx += $cols[3];

            $this->f(self::FONT_B, 7, self::C_BLACK);
            $this->cellL($cx, $ty, $cols[4], $rh, $this->m($profit)); $cx += $cols[4];
            $this->f(self::FONT, 7, self::C_DARK);

            $this->cellL($cx, $ty, $cols[5], $rh, number_format($margin, 1) . '%'); $cx += $cols[5];
            $this->cellL($cx, $ty, $cols[6], $rh, $this->n((float)($r['turnover'] ?? 0), 2) . 'x'); $cx += $cols[6];
            $this->cellL($cx, $ty, $cols[7], $rh, number_format((float)($r['return_rate_pct'] ?? 0), 1) . '%'); $cx += $cols[7];

            $rec = $r['recommendation'] ?? '';
            $recLabel = [
                'increase' => '↑',
                'maintain' => '•',
                'review'   => '↓',
                'no_sales' => '—',
            ][$rec] ?? '';
            $this->f(self::FONT_B, 7, self::C_BLACK);
            $this->cellL($cx, $ty, $cols[8], $rh, number_format($score, 1) . ' ' . $recLabel);
            $this->f(self::FONT, 7, self::C_DARK);

            $this->y += $rh;
        }

        $totalMargin = $totalRev > 0 ? ($totalProf / $totalRev * 100) : 0.0;
        $this->ensurePage($sh + 3);
        $this->y += 3;

        $sh = 14;
        $sw = $w / 3;
        $lh = 4;
        $vh = 5;
        $this->rect($l, $this->y, $w, $sh, self::C_DARK);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + 4,        $this->y + 2, $sw, $lh, 'Tổng Doanh Thu');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + 4,        $this->y + 6, $sw, $vh, $this->m($totalRev) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw + 4,  $this->y + 2, $sw, $lh, 'Tổng Lợi Nhuận');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw + 4,  $this->y + 6, $sw, $vh, $this->m($totalProf) . ' ' . $this->currency);

        $this->f(self::FONT, 7, self::C_LIGHT);
        $this->cellL($l + $sw*2 + 4, $this->y + 2, $sw, $lh, 'Biên LN Trung Bình');
        $this->f(self::FONT_B, 11, self::C_WHITE);
        $this->cellL($l + $sw*2 + 4, $this->y + 6, $sw, $vh, number_format($totalMargin, 1) . '%');

        return $this->y + $sh;
    }

    // ─── Signature block ──────────────────────────────────────────────────

    private function drawSignature(float $y): void {
        $l = $this->ml;
        $w = $this->pw;

        $this->line($l, $y, $l + $w, $y, self::C_LINER, 0.4);
        $y += 7;

        $colW = $w / 3;
        $roles = ['Người Lập', 'Kế Toán Trưởng', 'Giám Đốc'];

        foreach ($roles as $i => $role) {
            $rx = $l + $i * $colW;

            $this->f(self::FONT_B, 8, self::C_SLATE);
            $this->cellL($rx + 4, $y, $colW - 4, 5, mb_strtoupper($role, 'UTF-8'));

            $lineY = $y + 16;
            $this->line($rx + 4, $lineY, $rx + $colW - 4, $lineY, self::C_LINER, 0.3);

            $this->f(self::FONT, 7.5, self::C_SLATE);
            $this->cellL($rx + 4, $lineY + 2, $colW - 4, 4, '(Ký và Ghi rõ họ tên)');
            $this->cellL($rx + 4, $lineY + 9, $colW - 4, 4, 'Ngày: ____________');
        }
    }

    // ─── Footer ─────────────────────────────────────────────────────────

    private function drawFooter(): void {
        $pageH = $this->pdf->getPageHeight();
        $l = $this->ml;
        $w = $this->pw;
        $re = $l + $w;
        $y = $pageH - 7;

        $this->line($l, $y - 1, $re, $y - 1, self::C_LINER, 0.3);

        $this->f(self::FONT, 6.5, self::C_SLATE);
        $this->cellL($l, $y, 120, 4, $this->companyName ?: '');
        $this->cellL($re - 60, $y, 60, 4, $this->pdf->getAliasNumPage() . ' / ' . $this->pdf->getAliasNbPages());
    }
}