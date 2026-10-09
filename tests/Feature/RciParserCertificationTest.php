<?php

use App\Services\Imports\RciImportParser;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** A minimal Report of Checks Issued: the format hint row, the given check rows, then the certification block. */
function rciWithRows(array $rows): string
{
    $sheet = ($book = new Spreadsheet)->getActiveSheet();
    $sheet->fromArray(['mm/dd/yyyy', '00010000'], null, 'A12');

    foreach ($rows as $i => [$date, $serial, $payee, $nature, $amount]) {
        $row = 14 + $i;
        $sheet->setCellValue("A{$row}", $date);
        $sheet->setCellValueExplicit("B{$row}", $serial, DataType::TYPE_STRING);
        $sheet->setCellValue("F{$row}", $payee);
        $sheet->setCellValue("H{$row}", $nature);
        $sheet->setCellValue("I{$row}", $amount);
    }

    $end = 14 + count($rows) + 1;
    $sheet->setCellValue("A{$end}", 'CERTIFICATION');
    $sheet->setCellValue('A'.($end + 1), 'I hereby certify on my official oath that this report is true and correct.');
    // Anything under the certification block must be ignored.
    $sheet->setCellValue('B'.($end + 3), '000999999');
    $sheet->setCellValue('F'.($end + 3), 'SHOULD NOT BE READ');
    $sheet->setCellValue('I'.($end + 3), 1);

    $path = tempnam(sys_get_temp_dir(), 'rci').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    return $path;
}

it('keeps reading checks whose particulars mention "Certification Support Program"', function () {
    $path = rciWithRows([
        ['10/01/2026', '000300001', 'FIRST PAYEE', 'Payment of assessment fee for Certification Support Program in SMAW NC II', 135000],
        ['10/02/2026', '000300002', 'SECOND PAYEE', 'Payment of training cost', 20000],
        ['10/03/2026', '000300003', 'THIRD PAYEE', 'I hereby certify that the training was completed', 5000],
    ]);

    $parsed = (new RciImportParser)->parse($path);
    unlink($path);

    expect($parsed->errors)->toBeEmpty()
        ->and(collect($parsed->rows)->pluck('serial_no')->all())->toBe(['000300001', '000300002', '000300003']);
});

it('still stops at the certification block under the table', function () {
    $path = rciWithRows([['10/01/2026', '000300001', 'FIRST PAYEE', 'Payment of training cost', 1000]]);

    $parsed = (new RciImportParser)->parse($path);
    unlink($path);

    expect(collect($parsed->rows)->pluck('serial_no')->all())->toBe(['000300001']);
});
