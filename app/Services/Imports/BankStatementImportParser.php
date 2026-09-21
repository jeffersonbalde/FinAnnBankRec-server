<?php

namespace App\Services\Imports;

/**
 * Parses a LANDBANK "Statement of Account" export (CSV or XLSX). Columns are
 * located by matching the header labels, tolerating the blank spacer column.
 */
class BankStatementImportParser implements ImportParser
{
    public function parse(string $absolutePath): ParsedImport
    {
        $grid = SpreadsheetReader::grid($absolutePath);
        $meta = $this->extractMeta($grid);

        $headerRowNumber = null;
        $columns = [];

        foreach ($grid as $rowNumber => $row) {
            if (SpreadsheetReader::rowContains($row, ['date', 'description']) && SpreadsheetReader::rowContains($row, ['debit'])) {
                $headerRowNumber = $rowNumber;
                $columns = $this->mapColumns($row);
                break;
            }
        }

        if ($headerRowNumber === null) {
            return new ParsedImport([], [['row' => 0, 'message' => 'Could not find the statement header row (Date / Description / Debit / Credit).']], $meta);
        }

        $rows = [];
        $errors = [];
        $blankStreak = 0;

        foreach ($grid as $rowNumber => $row) {
            if ($rowNumber <= $headerRowNumber) {
                continue;
            }

            $joined = mb_strtolower(trim(implode(' ', $row)));

            if ($joined === '') {
                if (++$blankStreak > 25) {
                    break;
                }

                continue;
            }
            $blankStreak = 0;

            $get = fn (string $key) => isset($columns[$key]) ? ($row[$columns[$key]] ?? null) : null;

            if (str_contains($joined, 'balance forward') || str_contains($joined, 'balance b/f') || str_contains($joined, 'beginning balance')) {
                $rows[] = [
                    'is_balance_forward' => true,
                    'txn_date' => null,
                    'servicing_branch' => null,
                    'check_no' => null,
                    'description' => 'Balance forwarded',
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'running_balance' => CellValue::decimal($get('running_balance')) ?? $this->lastNumeric($row),
                ];

                continue;
            }

            $date = CellValue::date($get('date'));
            $checkNo = CellValue::string($get('check_no'));
            $description = CellValue::string($get('description'));
            $debit = CellValue::decimal($get('debit')) ?? 0.0;
            $credit = CellValue::decimal($get('credit')) ?? 0.0;

            if ($date === null && $checkNo === null && $description === null && $debit === 0.0 && $credit === 0.0) {
                continue;
            }

            if ($debit === 0.0 && $credit === 0.0) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Transaction has neither a debit nor a credit amount.'];

                continue;
            }

            $rows[] = [
                'is_balance_forward' => false,
                'txn_date' => $date?->toDateTimeString(),
                'servicing_branch' => CellValue::string($get('servicing_branch')),
                'check_no' => $checkNo,
                'description' => $description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => CellValue::decimal($get('running_balance')),
            ];
        }

        return new ParsedImport($rows, $errors, $meta);
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    private function mapColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $label) {
            $key = mb_strtolower(trim($label));

            match (true) {
                $key === 'date' => $map['date'] = $index,
                str_contains($key, 'servicing') || str_contains($key, 'branch') => $map['servicing_branch'] = $index,
                str_contains($key, 'check') || str_contains($key, 'cheque') || str_contains($key, 'ref') => $map['check_no'] = $index,
                str_contains($key, 'description') || str_contains($key, 'particular') => $map['description'] = $index,
                str_contains($key, 'debit') || str_contains($key, 'withdrawal') => $map['debit'] = $index,
                str_contains($key, 'credit') || str_contains($key, 'deposit') => $map['credit'] = $index,
                str_contains($key, 'balance') => $map['running_balance'] = $index,
                default => null,
            };
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function lastNumeric(array $row): ?float
    {
        foreach (array_reverse($row) as $cell) {
            $value = CellValue::decimal($cell);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array<string, mixed>
     */
    private function extractMeta(array $grid): array
    {
        $meta = [];

        foreach (array_slice($grid, 0, 12, true) as $row) {
            $line = trim(implode('  ', $row));

            if (preg_match('/for the period of:\s*([^|]+?)(?:\s{2,}|$)/i', $line, $m)) {
                $meta['statement_period'] = trim($m[1]);
            }
            if (preg_match('/account number:\s*([A-Za-z0-9\-]+)/i', $line, $m)) {
                $meta['account_number'] = trim($m[1]);
            }
            if (preg_match('/account name:\s*([^|]+?)(?:\s{2,}|$)/i', $line, $m)) {
                $meta['account_name'] = trim($m[1]);
            }
            if (preg_match('/currency:\s*([A-Za-z]+)/i', $line, $m)) {
                $meta['currency'] = trim($m[1]);
            }
        }

        return $meta;
    }
}
