<?php

namespace App\Exports;

use App\Enums\CheckStatus;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Report of Checks Issued (COA Appendix 35) — either for one reconciliation's
 * period, or the whole Checks Register (every bank account, optionally
 * filtered). Both list every check, however it was recorded (imported or typed
 * in), with its current status.
 */
class RciExport
{
    private const MONEY = '#,##0.00';

    private const HEAD_ROW = 11;

    public function build(Reconciliation $reconciliation): Spreadsheet
    {
        $reconciliation->load('bankAccount');
        $account = $reconciliation->bankAccount;

        $checks = $reconciliation->checkIssuances()
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->get();

        [$spreadsheet, $sheet] = $this->newSheet(withAccount: false);

        $this->writeHeader($sheet, [
            4 => $account->entity_name,
            5 => 'Report of Checks Issued',
            6 => $reconciliation->statement_label ?: ('As of '.$reconciliation->period_end->format('F j, Y')),
            7 => 'FUND '.$account->fund_cluster.' — '.$account->bank_short_name.' '.$account->account_number,
            9 => 'APPENDIX 35',
        ], 'L');

        if ($reconciliation->report_no) {
            $sheet->setCellValue('L3', 'Report No.: '.$reconciliation->report_no);
        }

        $this->writeChecks($sheet, $checks, withAccount: false);

        return $spreadsheet;
    }

    /**
     * The whole register as one sheet, with a bank-account column.
     *
     * @param  Collection<int, CheckIssuance>  $checks
     */
    public function buildRegister(Collection $checks, string $scope): Spreadsheet
    {
        [$spreadsheet, $sheet] = $this->newSheet(withAccount: true);

        $this->writeHeader($sheet, [
            5 => 'Checks Register — Report of Checks Issued',
            6 => $scope,
            7 => 'Printed '.now()->format('F j, Y'),
            9 => 'APPENDIX 35',
        ], 'M');

        $this->writeChecks($sheet, $checks, withAccount: true);

        return $spreadsheet;
    }

    /**
     * @return array{0: Spreadsheet, 1: Worksheet}
     */
    private function newSheet(bool $withAccount): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RCI');

        $widths = ['A' => 14, 'B' => 14, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 30, 'G' => 12, 'H' => 24, 'I' => 15, 'J' => 15, 'K' => 15, 'L' => 22];
        if ($withAccount) {
            $widths['M'] = 26;
        }
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        return [$spreadsheet, $sheet];
    }

    /**
     * @param  array<int, ?string>  $lines
     */
    private function writeHeader(Worksheet $sheet, array $lines, string $lastCol): void
    {
        foreach ($lines as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setBold(in_array($row, [5, 9], true));
        }
    }

    /**
     * @param  Collection<int, CheckIssuance>  $checks
     */
    private function writeChecks(Worksheet $sheet, Collection $checks, bool $withAccount): void
    {
        $lastCol = $withAccount ? 'M' : 'L';
        $columns = [
            'A' => 'DATE',
            'B' => 'CHECK/ADA NO.',
            'C' => 'DV NO.',
            'D' => 'OR/BURS NO.',
            'E' => 'RESP. CENTER',
            'F' => 'PAYEE',
            'G' => 'UACS CODE',
            'H' => 'NATURE OF PAYMENT',
            'I' => 'AMOUNT',
            'J' => 'GROSS TAXABLE',
            'K' => 'WITHHOLDING TAX',
            'L' => 'STATUS',
        ];
        if ($withAccount) {
            $columns['M'] = 'BANK ACCOUNT';
        }

        $headRow = self::HEAD_ROW;
        foreach ($columns as $col => $label) {
            $sheet->setCellValue("{$col}{$headRow}", $label);
        }
        $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = $headRow + 1;
        foreach ($checks as $check) {
            $sheet->setCellValue("A{$row}", $check->check_date?->format('m/d/Y'));
            $sheet->setCellValueExplicit("B{$row}", $check->serial_no, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", $check->dv_no ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $check->or_burs_no ?? '', DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $check->responsibility_center_code);
            $sheet->setCellValue("F{$row}", $check->payee);
            $sheet->setCellValue("G{$row}", $check->uacs_object_code);
            $sheet->setCellValue("H{$row}", $check->nature_of_payment);
            $sheet->setCellValue("I{$row}", (float) $check->amount);
            $sheet->setCellValue("J{$row}", $check->gross_taxable_amount !== null ? (float) $check->gross_taxable_amount : null);
            $sheet->setCellValue("K{$row}", $check->withholding_tax !== null ? (float) $check->withholding_tax : null);
            $sheet->setCellValue("L{$row}", $check->status->label().($check->status->value === 'cancelled' && $check->cancelled_reason ? " — {$check->cancelled_reason}" : ''));
            if ($withAccount) {
                $sheet->setCellValue("M{$row}", $check->bankAccount ? ($check->bankAccount->bank_short_name ?: $check->bankAccount->bank_name).' '.$check->bankAccount->account_number : '');
            }
            $row++;
        }

        $lastDataRow = max($row - 1, $headRow + 1);
        $sheet->setCellValue("H{$row}", 'TOTAL (excl. cancelled)');
        $sheet->setCellValue("I{$row}", (float) $checks->where('status', '!=', CheckStatus::Cancelled)->sum('amount'));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);

        $sheet->getStyle('I'.($headRow + 1).":K{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("A{$headRow}:{$lastCol}{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
