<?php

namespace App\Exports;

use App\Enums\CheckStatus;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * One list from a reconciliation's Matching tab as Excel — the cleared checks,
 * the outstanding ones, or the items that need attention — so it can be
 * reviewed or shared outside the system.
 */
class MatchingListExport
{
    public const CATEGORIES = ['cleared', 'outstanding', 'flags'];

    private const MONEY = '#,##0.00';

    private const HEAD_ROW = 7;

    public function build(Reconciliation $reconciliation, string $category): Spreadsheet
    {
        $reconciliation->loadMissing('bankAccount');

        [$title, $heads, $rows, $moneyColumns] = match ($category) {
            'cleared' => $this->cleared($reconciliation),
            'outstanding' => $this->outstanding($reconciliation),
            default => $this->flags($reconciliation),
        };

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31));

        $lastCol = chr(64 + count($heads));
        $account = $reconciliation->bankAccount;

        $titles = [
            2 => $title,
            3 => ($account->bank_short_name ?: $account->bank_name).' · '.$account->account_number.' · FUND '.$account->fund_cluster,
            4 => 'Period: '.$reconciliation->period_start->format('F j, Y').' to '.$reconciliation->period_end->format('F j, Y'),
            5 => 'Generated '.CarbonImmutable::now()->format('F j, Y g:i A'),
        ];
        foreach ($titles as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);

        $headRow = self::HEAD_ROW;
        foreach ($heads as $i => $label) {
            $sheet->setCellValue(chr(65 + $i).$headRow, $label);
        }
        $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = $headRow + 1;
        $totals = array_fill_keys($moneyColumns, 0.0);

        foreach ($rows as $n => $cells) {
            $sheet->setCellValue("A{$row}", $n + 1);

            foreach ($cells as $i => $value) {
                $col = chr(66 + $i);

                if (in_array($col, $moneyColumns, true) && is_numeric($value)) {
                    $sheet->setCellValue("{$col}{$row}", (float) $value);
                    $totals[$col] += (float) $value;
                } else {
                    // Check numbers keep their leading zeros.
                    $sheet->setCellValueExplicit("{$col}{$row}", (string) $value, DataType::TYPE_STRING);
                }
            }
            $row++;
        }

        $lastDataRow = max($row - 1, $headRow + 1);

        if ($rows === []) {
            $sheet->setCellValue("B{$row}", 'Nothing to list.');
            $row++;
        } elseif ($category !== 'flags') {
            $sheet->setCellValue('A'.$row, 'TOTAL');
            foreach ($totals as $col => $total) {
                $sheet->setCellValue("{$col}{$row}", $total);
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
            $lastDataRow = $row;
        }

        foreach ($moneyColumns as $col) {
            $sheet->getStyle("{$col}".($headRow + 1).":{$col}{$lastDataRow}")->getNumberFormat()->setFormatCode(self::MONEY);
        }
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getStyle("A{$headRow}:{$lastCol}{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A'.($headRow + 1));

        return $spreadsheet;
    }

    /** @return array{0: string, 1: list<string>, 2: list<list<mixed>>, 3: list<string>} */
    private function cleared(Reconciliation $reconciliation): array
    {
        $checks = $reconciliation->matchingChecks()->where('status', CheckStatus::Cleared->value)->get();

        return [
            'Cleared Checks',
            ['#', 'DATE', 'CHECK/ADA NO.', 'PAYEE', 'CLEARED ON', 'AMOUNT'],
            $checks->map(fn (CheckIssuance $c) => [
                $c->check_date?->format('m/d/Y'),
                $c->serial_no,
                $c->payee,
                $c->cleared_on?->format('m/d/Y'),
                (float) $c->amount,
            ])->all(),
            ['F'],
        ];
    }

    /** @return array{0: string, 1: list<string>, 2: list<list<mixed>>, 3: list<string>} */
    private function outstanding(Reconciliation $reconciliation): array
    {
        $checks = $reconciliation->matchingChecks()
            ->with('reconciliation')
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
            ->get();
        $asOf = CarbonImmutable::parse($reconciliation->period_end);

        return [
            'Outstanding Checks',
            ['#', 'DATE', 'CHECK/ADA NO.', 'PAYEE', 'AGE (DAYS)', 'STATUS', 'CARRIED OVER FROM', 'AMOUNT'],
            $checks->map(fn (CheckIssuance $c) => [
                $c->check_date?->format('m/d/Y'),
                $c->serial_no,
                $c->payee,
                $c->check_date ? (string) max(0, (int) $c->check_date->diffInDays($asOf)) : '',
                $c->status->label(),
                $c->reconciliation_id !== null && $c->reconciliation_id !== $reconciliation->id && $c->reconciliation
                    ? $c->reconciliation->period_end->format('M Y')
                    : '',
                (float) $c->amount,
            ])->all(),
            ['H'],
        ];
    }

    /** @return array{0: string, 1: list<string>, 2: list<list<mixed>>, 3: list<string>} */
    private function flags(Reconciliation $reconciliation): array
    {
        $flags = collect($reconciliation->latestMatchRun()?->flags ?? []);

        return [
            'Needs Attention',
            ['#', 'WHAT IS WRONG', 'CHECK/ADA NO.', 'BOOKS (RCI)', 'BANK', 'DIFFERENCE', 'DETAILS'],
            $flags->map(function (array $f) {
                $mismatch = ($f['type'] ?? '') === 'amount_mismatch';
                // Flags saved by an older run carry the check number only inside their message.
                $checkNo = $f['check_no'] ?? (preg_match('/^Check (\S+?):/', (string) ($f['message'] ?? ''), $m) === 1 ? $m[1] : '');

                return [
                    $mismatch ? 'Amount differs from the bank' : (($f['type'] ?? '') === 'unrecorded_check' ? 'Cleared by the bank, not in the Report of Checks Issued' : 'Other'),
                    $checkNo,
                    $mismatch ? (float) ($f['book_amount'] ?? 0) : '',
                    $mismatch ? (float) ($f['bank_amount'] ?? 0) : (isset($f['amount']) ? (float) $f['amount'] : ''),
                    $mismatch ? (float) ($f['difference'] ?? 0) : '',
                    $f['message'] ?? '',
                ];
            })->all(),
            ['D', 'E', 'F'],
        ];
    }
}
