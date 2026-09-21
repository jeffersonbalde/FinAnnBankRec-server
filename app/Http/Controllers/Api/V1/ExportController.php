<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\BrsExport;
use App\Exports\OutstandingChecksScheduleExport;
use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly BrsExport $brsExport,
        private readonly OutstandingChecksScheduleExport $scheduleExport,
        private readonly BrsCalculator $calculator,
    ) {}

    public function brsXlsx(Reconciliation $reconciliation): StreamedResponse
    {
        return $this->stream(
            $this->brsExport->build($reconciliation),
            $this->filename($reconciliation, 'BRS').'.xlsx',
        );
    }

    public function scheduleXlsx(Reconciliation $reconciliation): StreamedResponse
    {
        return $this->stream(
            $this->scheduleExport->build($reconciliation),
            $this->filename($reconciliation, 'Schedule-1-Outstanding-Checks').'.xlsx',
        );
    }

    public function brsPdf(Reconciliation $reconciliation)
    {
        $reconciliation->load('bankAccount.signatories');
        $brs = $this->calculator->compute($reconciliation);

        $pdf = Pdf::loadView('exports.reconciliation', [
            'reconciliation' => $reconciliation,
            'account' => $reconciliation->bankAccount,
            'brs' => $brs,
            'outstandingChecks' => $reconciliation->bankAccount->checkIssuances()
                ->where('status', 'outstanding')
                ->orderBy('check_date')->orderBy('serial_no')->get(),
        ])->setPaper('a4');

        return $pdf->download($this->filename($reconciliation, 'BRS').'.pdf');
    }

    private function stream(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function filename(Reconciliation $reconciliation, string $prefix): string
    {
        return "{$prefix}_{$reconciliation->bankAccount->fund_cluster}_{$reconciliation->period_end->format('Y-m')}";
    }
}
