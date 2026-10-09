<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Exports\BrsExport;
use App\Exports\MatchingListExport;
use App\Exports\OutstandingChecksListExport;
use App\Exports\OutstandingChecksScheduleExport;
use App\Exports\RciExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly BrsExport $brsExport,
        private readonly OutstandingChecksScheduleExport $scheduleExport,
        private readonly RciExport $rciExport,
        private readonly OutstandingChecksListExport $outstandingList,
        private readonly MatchingListExport $matchingList,
        private readonly BrsCalculator $calculator,
    ) {}

    public function brsXlsx(Request $request, Reconciliation $reconciliation): StreamedResponse
    {
        $this->logExport($request, 'the BRS (Excel)', $reconciliation);

        return $this->stream(
            $this->brsExport->build($reconciliation),
            $this->filename($reconciliation, 'BRS').'.xlsx',
        );
    }

    public function scheduleXlsx(Request $request, Reconciliation $reconciliation): StreamedResponse
    {
        $this->logExport($request, 'Schedule 1 - Outstanding Checks (Excel)', $reconciliation);

        return $this->stream(
            $this->scheduleExport->build($reconciliation),
            $this->filename($reconciliation, 'Schedule-1-Outstanding-Checks').'.xlsx',
        );
    }

    public function rciXlsx(Request $request, Reconciliation $reconciliation): StreamedResponse
    {
        $this->logExport($request, 'the Report of Checks Issued (Excel)', $reconciliation);

        return $this->stream(
            $this->rciExport->build($reconciliation),
            $this->filename($reconciliation, 'RCI-Report-of-Checks-Issued').'.xlsx',
        );
    }

    /**
     * The whole Checks Register (filters match the register screen) as Excel.
     */
    public function registerXlsx(Request $request): StreamedResponse
    {
        $request->validate([
            'bank_account_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:'.implode(',', array_column(CheckStatus::cases(), 'value'))],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->logExport($request, 'the Checks Register (Excel)');

        $checks = CheckIssuance::query()
            ->filtered($request->only(['bank_account_id', 'status', 'search', 'date_from', 'date_to']))
            ->with('bankAccount')
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->get();

        $account = $request->filled('bank_account_id') ? BankAccount::query()->find($request->integer('bank_account_id')) : null;
        $scope = collect([
            $account ? ($account->bank_short_name ?: $account->bank_name).' · '.$account->account_number.' · FUND '.$account->fund_cluster : 'All bank accounts',
            $request->filled('status') ? 'Status: '.ucfirst($request->string('status')->value()) : null,
            $request->filled('date_from') || $request->filled('date_to')
                ? 'Dated '.($request->input('date_from') ?: 'start').' to '.($request->input('date_to') ?: 'today')
                : null,
        ])->filter()->implode('  |  ');

        return $this->stream(
            $this->rciExport->buildRegister($checks, $scope),
            'Checks-Register_'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * The Outstanding Checks list (filters match the screen) as Excel, oldest first.
     */
    public function outstandingXlsx(Request $request): StreamedResponse
    {
        $request->validate([
            'bank_account_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:'.CheckStatus::Outstanding->value.','.CheckStatus::Stale->value],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->logExport($request, 'the Outstanding Checks list (Excel)');

        $checks = CheckIssuance::query()
            ->outstandingFiltered($request->only(['bank_account_id', 'status', 'search']))
            ->with('bankAccount')
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->get();

        $account = $request->filled('bank_account_id') ? BankAccount::query()->find($request->integer('bank_account_id')) : null;
        $scope = collect([
            $account ? ($account->bank_short_name ?: $account->bank_name).' · '.$account->account_number.' · FUND '.$account->fund_cluster : 'All bank accounts',
            $request->filled('status') ? 'Status: '.ucfirst($request->string('status')->value()) : 'Outstanding and stale',
            $request->filled('search') ? 'Search: '.$request->string('search')->value() : null,
        ])->filter()->implode('  |  ');

        return $this->stream(
            $this->outstandingList->build($checks, $scope, CarbonImmutable::now()),
            'Outstanding-Checks_'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * One list of the Matching tab as Excel: the cleared checks, the outstanding ones,
     * or the items that need attention.
     */
    public function matchingXlsx(Request $request, Reconciliation $reconciliation): StreamedResponse
    {
        $data = $request->validate(['category' => ['required', Rule::in(MatchingListExport::CATEGORIES)]]);

        $label = ['cleared' => 'Cleared Checks', 'outstanding' => 'Outstanding Checks', 'flags' => 'Needs Attention'][$data['category']];
        $this->logExport($request, "the {$label} list (Excel)", $reconciliation);

        return $this->stream(
            $this->matchingList->build($reconciliation, $data['category']),
            $this->filename($reconciliation, str_replace(' ', '-', $label)).'.xlsx',
        );
    }

    public function brsPdf(Request $request, Reconciliation $reconciliation)
    {
        $this->logExport($request, 'the BRS (PDF)', $reconciliation);
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

    /** Leave a footprint: who downloaded what. */
    private function logExport(Request $request, string $what, ?Reconciliation $reconciliation = null): void
    {
        AuditLog::record($request->user(), 'exported', 'Downloaded '.$what.($reconciliation ? ' of reconciliation #'.$reconciliation->id : ''), $reconciliation);
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
