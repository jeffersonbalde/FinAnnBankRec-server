<?php

namespace App\Services\Imports;

/**
 * Parses the COA Appendix 35 "Report of Checks Issued". The form layout is
 * standardised, so columns are read by their fixed position (A..N) once the
 * data region is located.
 */
class RciImportParser implements ImportParser
{
    private const COL_DATE = 1;

    private const COL_SERIAL = 2;

    private const COL_DV = 3;

    private const COL_OR = 4;

    private const COL_RCC = 5;

    private const COL_PAYEE = 6;

    private const COL_UACS = 7;

    private const COL_NATURE = 8;

    private const COL_AMOUNT = 9;

    private const COL_GROSS = 10;

    private const COL_WT_FIRST = 11;

    private const COL_WT_LAST = 14;

    public function parse(string $absolutePath): ParsedImport
    {
        $grid = SpreadsheetReader::grid($absolutePath);
        $meta = $this->extractMeta($grid);

        $rows = [];
        $errors = [];
        $blankStreak = 0;
        $started = false;

        foreach ($grid as $rowNumber => $row) {
            $joined = mb_strtolower(implode(' ', $row));

            if (! $started) {
                // Data begins on the row after the "mm/dd/yyyy 00010000" format hint.
                if (str_contains($joined, 'mm/dd/yyyy') || str_contains($joined, '00010000')) {
                    $started = true;
                }

                continue;
            }

            if (str_contains($joined, 'certification') || str_contains($joined, 'i hereby certify')) {
                break;
            }

            $serial = CellValue::string($row[self::COL_SERIAL] ?? null);
            $payee = CellValue::string($row[self::COL_PAYEE] ?? null);
            $amountRaw = $row[self::COL_AMOUNT] ?? null;
            $rowHasContent = trim(implode('', $row)) !== '';

            if (! $rowHasContent) {
                if (++$blankStreak > 25) {
                    break;
                }

                continue;
            }
            $blankStreak = 0;

            // A trailing "TOTAL" line only has an amount — ignore it.
            if ($serial === null && $payee === null && str_contains($joined, 'total')) {
                continue;
            }

            $amount = CellValue::decimal($amountRaw);

            if ($serial === null && $payee === null && $amount === null) {
                continue;
            }

            if ($serial === null || $amount === null || $payee === null) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => 'Missing check serial number, payee or amount.',
                ];

                continue;
            }

            $rows[] = [
                'check_date' => optional(CellValue::date($row[self::COL_DATE] ?? null))?->toDateString(),
                'serial_no' => $serial,
                'dv_no' => CellValue::string($row[self::COL_DV] ?? null),
                'or_burs_no' => CellValue::string($row[self::COL_OR] ?? null),
                'responsibility_center_code' => CellValue::string($row[self::COL_RCC] ?? null),
                'payee' => $payee,
                'uacs_object_code' => CellValue::string($row[self::COL_UACS] ?? null),
                'nature_of_payment' => CellValue::string($row[self::COL_NATURE] ?? null),
                'amount' => $amount,
                'gross_taxable_amount' => CellValue::decimal($row[self::COL_GROSS] ?? null),
                'withholding_tax' => $this->sumWithholding($row),
                'report_no' => $meta['report_no'] ?? null,
            ];
        }

        return new ParsedImport($rows, $errors, $meta);
    }

    /**
     * @param  array<int, string>  $row
     */
    private function sumWithholding(array $row): ?float
    {
        $total = 0.0;
        $found = false;

        for ($col = self::COL_WT_FIRST; $col <= self::COL_WT_LAST; $col++) {
            $value = CellValue::decimal($row[$col] ?? null);
            if ($value !== null) {
                $total += $value;
                $found = true;
            }
        }

        return $found ? round($total, 2) : null;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array<string, mixed>
     */
    private function extractMeta(array $grid): array
    {
        $meta = [];

        foreach (array_slice($grid, 0, 14, true) as $row) {
            $line = trim(preg_replace('/\s{2,}/', '  ', implode('  ', $row)) ?? '');
            if ($line === '') {
                continue;
            }

            if (preg_match('/period covered:\s*([^|]+?)(?:\s{2,}|$)/i', $line, $m)) {
                $meta['period_covered'] = trim($m[1]);
            }
            if (preg_match('/fund cluster:\s*([^|]+?)(?:\s{2,}|$)/i', $line, $m)) {
                $meta['fund_cluster'] = trim($m[1]);
            }
            if (preg_match('/rep(?:o|or)t no\.?:\s*([A-Za-z0-9\-\/]+)/i', $line, $m)) {
                $meta['report_no'] = trim($m[1]);
            }
            if (preg_match('/bank name\/account no\.?:\s*([^|]+?)(?:\s{2,}|$)/i', $line, $m)) {
                $meta['bank_account_hint'] = trim($m[1]);
            }
        }

        return $meta;
    }
}
