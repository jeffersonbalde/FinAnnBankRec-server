<?php

namespace App\Exports;

use App\Models\CheckIssuance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * The Outstanding Checks list as Excel: every check still uncashed (or stale),
 * across bank accounts, with how many days it has been outstanding — the
 * follow-up list, as seen on the Outstanding Checks screen.
 */
class OutstandingChecksListExport
{
    private const MONEY = '#,##0.00';

    private const HEAD_ROW = 7;

    /**
     * @param  Collection<int, CheckIssuance>  $checks
     */
    public function build(Collection $checks, string $scope, CarbonInterface $asOf): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Outstanding Checks');

        foreach (['A' => 14, 'B' => 16, 'C' => 34, 'D' => 26, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $titles = [
            2 => 'Outstanding Checks Register',
            3 => $scope,
            4 => 'As of '.$asOf->format('F j, Y'),
        ];
        foreach ($titles as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);

        $headRow = self::HEAD_ROW;
        $heads = ['DATE', 'CHECK/ADA NO.', 'PAYEE', 'BANK ACCOUNT', 'FUND', 'AGE (DAYS)', 'STATUS', 'AMOUNT'];
        foreach ($heads as $i => $label) {
            $sheet->setCellValue(chr(65 + $i).$headRow, $label);
        }
        $sheet->getStyle("A{$headRow}:H{$headRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headRow}:H{$headRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = $headRow + 1;
        foreach ($checks as $check) {
            $account = $check->bankAccount;

            $sheet->setCellValue("A{$row}", $check->check_date?->format('m/d/Y'));
            $sheet->setCellValueExplicit("B{$row}", $check->serial_no, DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $check->payee);
            $sheet->setCellValue("D{$row}", $account ? ($account->bank_short_name ?: $account->bank_name).' '.$account->account_number : '');
            $sheet->setCellValue("E{$row}", $account?->fund_cluster);
            $sheet->setCellValue("F{$row}", $check->check_date ? (int) $check->check_date->diffInDays($asOf) : null);
            $sheet->setCellValue("G{$row}", $check->status->label());
            $sheet->setCellValue("H{$row}", (float) $check->amount);
            $row++;
        }

        $lastDataRow = max($row - 1, $headRow + 1);
        $sheet->setCellValue("G{$row}", 'TOTAL');
        $sheet->setCellValue("H{$row}", (float) $checks->sum('amount'));
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);

        $sheet->getStyle('H'.($headRow + 1).":H{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("F{$headRow}:F{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$headRow}:H{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A'.($headRow + 1));

        return $spreadsheet;
    }
}
