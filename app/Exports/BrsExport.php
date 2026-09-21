<?php

namespace App\Exports;

use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Renders the Bank Reconciliation Statement (COA Appendix 81) as an .xlsx that
 * mirrors the client's working template.
 */
class BrsExport
{
    private const MONEY = '#,##0.00';

    public function __construct(private readonly BrsCalculator $calculator) {}

    public function build(Reconciliation $reconciliation): Spreadsheet
    {
        $reconciliation->load('bankAccount.signatories');
        $brs = $this->calculator->compute($reconciliation);
        $account = $reconciliation->bankAccount;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BRS');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(28);

        $sheet->setCellValue('D3', 'Appendix 81');
        $sheet->getStyle('D3')->getFont()->setItalic(true);

        $titleRows = [
            6 => $account->entity_name,
            7 => 'Cash in Bank - Local Currency, Current Account',
            8 => 'Bank Reconciliation Statement',
            9 => $reconciliation->statement_label ?: ('As of '.$reconciliation->period_end->format('F j, Y')),
        ];
        foreach ($titleRows as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setBold($row <= 8);
        }

        $sheet->setCellValue('C11', 'Fund Cluster: '.$account->fund_cluster);

        foreach (['A12' => 'Particulars', 'B12' => 'Agency', 'C12' => 'Bank', 'D12' => 'Explanatory Comment'] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        $sheet->getStyle('A12:D12')->getFont()->setBold(true);
        $sheet->getStyle('A12:D12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 14;
        $sheet->setCellValue("A{$row}", 'Unadjusted Balances');
        $sheet->setCellValue("B{$row}", $brs['unadjusted_book_balance']);
        $sheet->setCellValue("C{$row}", $brs['unadjusted_bank_balance']);
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Add/Deduct: Bank Reconciling Items');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        $row++;
        $row = $this->writeItems($sheet, $row, $brs['bank_items'], bankSide: true);

        $row++;
        $sheet->setCellValue("A{$row}", 'Add/Deduct: Agency Book Reconciling Items');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        $row++;
        $row = $this->writeItems($sheet, $row, $brs['book_items'], bankSide: false);

        $row++;
        $sheet->setCellValue("A{$row}", 'Adjusted Balances');
        $sheet->setCellValue("B{$row}", $brs['adjusted_book_balance']);
        $sheet->setCellValue("C{$row}", $brs['adjusted_bank_balance']);
        if (! $brs['is_balanced']) {
            $sheet->setCellValue("D{$row}", 'Difference: '.number_format($brs['difference'], 2));
        }
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);

        $sheet->getStyle("B14:C{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("A12:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $this->writeSignatories($sheet, $row + 4, $account->signatories);

        return $spreadsheet;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeItems($sheet, int $row, array $items, bool $bankSide): int
    {
        if ($items === []) {
            $sheet->setCellValue("A{$row}", '   (none)');

            return $row + 1;
        }

        foreach ($items as $i => $item) {
            $sign = $item['operation'] === 'add' ? '(+) ' : '(−) ';
            $sheet->setCellValue("A{$row}", '   '.($i + 1).'. '.$sign.$item['label']);
            $sheet->setCellValue($bankSide ? "C{$row}" : "B{$row}", $item['amount']);
            $sheet->setCellValue("D{$row}", $item['schedule_no'] ? 'See '.$item['schedule_no'] : (string) $item['explanatory_comment']);
            $row++;
        }

        return $row;
    }

    private function writeSignatories($sheet, int $row, $signatories): void
    {
        $find = fn (string $block) => $signatories->first(fn ($s) => $s->block->value === $block);
        $prepared = $find('prepared_by');
        $certified = $find('certified_correct');

        $sheet->setCellValue("A{$row}", 'Prepared by:');
        $sheet->setCellValue("C{$row}", 'Certified Correct:');
        $row += 3;
        $sheet->setCellValue("A{$row}", $prepared?->name ?? '');
        $sheet->setCellValue("C{$row}", $certified?->name ?? '');
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true)->setUnderline(true);
        $row++;
        $sheet->setCellValue("A{$row}", $prepared?->designation ?? '');
        $sheet->setCellValue("C{$row}", $certified?->designation ?? '');
    }
}
