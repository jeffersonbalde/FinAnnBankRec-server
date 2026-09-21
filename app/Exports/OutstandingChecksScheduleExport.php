<?php

namespace App\Exports;

use App\Enums\CheckStatus;
use App\Models\Reconciliation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Schedule 1 — List of Outstanding Checks, mirroring the client's template.
 */
class OutstandingChecksScheduleExport
{
    private const MONEY = '#,##0.00';

    public function build(Reconciliation $reconciliation): Spreadsheet
    {
        $reconciliation->load('bankAccount.signatories');
        $account = $reconciliation->bankAccount;

        $checks = $account->checkIssuances()
            ->where('status', CheckStatus::Outstanding->value)
            ->where(fn ($q) => $q->whereNull('check_date')->orWhere('check_date', '<=', $reconciliation->period_end))
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Schedule 1');
        $sheet->getColumnDimension('A')->setWidth(48);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(16);

        $header = [
            4 => $account->entity_name,
            5 => 'Schedule 1',
            6 => $reconciliation->statement_label ?: ('As of '.$reconciliation->period_end->format('F j, Y')),
            7 => 'FUND '.$account->fund_cluster,
            9 => 'SCHEDULE 1',
            10 => 'LIST OF OUTSTANDING CHECKS',
        ];
        foreach ($header as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setBold(in_array($row, [5, 9, 10], true));
        }

        foreach (['A11' => 'PAYEE', 'B11' => 'DATE OF CHECK', 'C11' => 'CHECK/ADA NO.', 'D11' => 'AMOUNT'] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        $sheet->getStyle('A11:D11')->getFont()->setBold(true);
        $sheet->getStyle('A11:D11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 12;
        foreach ($checks as $check) {
            $sheet->setCellValue("A{$row}", $check->payee);
            $sheet->setCellValue("B{$row}", $check->check_date?->format('m/d/Y'));
            $sheet->setCellValueExplicit("C{$row}", $check->serial_no, DataType::TYPE_STRING);
            $sheet->setCellValue("D{$row}", (float) $check->amount);
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValue("D{$row}", (float) $checks->sum('amount'));
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);

        $sheet->getStyle("D12:D{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("A11:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $spreadsheet;
    }
}
