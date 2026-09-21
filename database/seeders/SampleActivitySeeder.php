<?php

namespace Database\Seeders;

use App\Enums\CheckStatus;
use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CheckIssuance;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationEngine;
use App\Services\Workflow\WorkflowService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;

/**
 * Past-period activity (Apr–Jun 2026) on a few bank accounts so every page has
 * something to show on a fresh deploy: reconciliations in every status,
 * outstanding + stale checks, workflow notifications and an audit trail.
 *
 * The LBP 1292-0001-01 (101-MOOE) account is deliberately left untouched so a
 * July 2026 reconciliation can still be created and walked through live.
 */
class SampleActivitySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string}> payee, particulars, UACS */
    private const PAYEES = [
        ['MISAMIS OCCIDENTAL II ELECTRIC COOPERATIVE', 'Payment of electricity bill for the month', '5020402000'],
        ['PLDT INC.', 'Payment of telephone service for the month', '5020502000'],
        ['GLOBE TELECOM INC.', 'Payment of internet subscription for the month', '5020503000'],
        ['OFFICE WAREHOUSE INC.', 'Purchase of office supplies and materials', '5020301000'],
        ['CATERING PLUS SERVICES', 'Payment of meals and snacks for training activity', '5029904000'],
        ['ABC TRAINING CENTER INC.', 'Payment of training cost of FY 2026 TWSP (CO Allocation)', '5020202000'],
        ['DEF SKILLS TRAINING CORP.', 'Payment of training cost of FY 2026 TWSP (Batch 2)', '5020202000'],
        ['JOHN D. DELOS SANTOS', 'Reimbursement of traveling expenses, official travel', '5020101000'],
        ['MARIA CLARA S. REYES', "Payment of resource speaker's fee, TVET Trainers' Methodology Course", '5020201000'],
        ['METRO WATER DISTRICT', 'Payment of water bill for the month', '5020401000'],
        ['SAFEGUARD SECURITY AGENCY', 'Payment of security services for the month', '5021203000'],
        ['CLEANPRO JANITORIAL SERVICES', 'Payment of janitorial services for the month', '5021202000'],
    ];

    /**
     * account number => periods to build. 'by' / 'reviewer' pick the preparer and
     * certifier by email so different people appear in the activity.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const PLAN = [
        '0077-0123-45' => [
            ['month' => '2026-04', 'status' => 'certified', 'new' => 9, 'legacy' => true, 'charge' => true],
            ['month' => '2026-05', 'status' => 'certified', 'new' => 8, 'by' => 'msantos@tesda.gov.ph', 'reviewer' => 'jreyes@tesda.gov.ph'],
            ['month' => '2026-06', 'status' => 'certified', 'new' => 10, 'charge' => true],
        ],
        '1234-5678-90' => [
            ['month' => '2026-04', 'status' => 'certified', 'new' => 7, 'by' => 'rlim@tesda.gov.ph'],
            ['month' => '2026-05', 'status' => 'certified', 'new' => 8, 'charge' => true],
            ['month' => '2026-06', 'status' => 'for_review', 'new' => 9],
        ],
        '8801-1001-01' => [
            ['month' => '2026-05', 'status' => 'certified', 'new' => 6, 'by' => 'mtorralba@tesda.gov.ph'],
            ['month' => '2026-06', 'status' => 'returned', 'new' => 7, 'remarks' => 'Please attach the supporting Schedule 1 and re-check the outstanding checks issued in late June.'],
        ],
        '8801-1002-01' => [
            ['month' => '2026-06', 'status' => 'draft', 'new' => 6],
        ],
        '5500-3001-01' => [
            ['month' => '2026-06', 'status' => 'for_review', 'new' => 8, 'by' => 'rlim@tesda.gov.ph', 'charge' => true],
        ],
    ];

    public function run(): void
    {
        $engine = app(ReconciliationEngine::class);
        $workflow = app(WorkflowService::class);
        $users = User::query()->get()->keyBy('email');

        $defaultAnalyst = $users->get('analyst@tesda.gov.ph');
        $defaultReviewer = $users->get('budget@tesda.gov.ph');

        if ($defaultAnalyst === null || $defaultReviewer === null) {
            $this->command?->warn('SampleActivitySeeder skipped — run DemoSeeder first.');

            return;
        }

        mt_srand(2026);
        $accountIndex = 0;

        foreach (self::PLAN as $accountNumber => $periods) {
            $accountIndex++;
            $account = BankAccount::query()->where('account_number', $accountNumber)->first();

            if ($account === null) {
                $this->command?->warn("SampleActivitySeeder skipped {$accountNumber} — run MasterDataSeeder first.");

                continue;
            }

            $serial = 200_000_000 + $accountIndex * 100_000;

            foreach ($periods as $period) {
                $analyst = $users->get($period['by'] ?? '') ?? $defaultAnalyst;
                $reviewer = $users->get($period['reviewer'] ?? '') ?? $defaultReviewer;

                $this->seedPeriod($account, $period, $analyst, $reviewer, $serial, $engine, $workflow);
            }
        }

        $this->tidyNotifications();

        Auth::guard()->forgetUser();
        $this->command?->info('Sample reconciliations, checks, notifications and audit trail created.');
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function seedPeriod(
        BankAccount $account,
        array $period,
        User $analyst,
        User $reviewer,
        int &$serial,
        ReconciliationEngine $engine,
        WorkflowService $workflow,
    ): void {
        Auth::setUser($analyst);

        $start = Carbon::parse($period['month'].'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $label = strtoupper($start->format('F'));

        $reconciliation = Reconciliation::query()->create([
            'bank_account_id' => $account->id,
            'period_type' => 'monthly',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'statement_label' => 'As of '.$end->format('F j, Y'),
            'report_no' => $end->format('Y-m').'-001',
            'unadjusted_book_balance' => 0,
            'unadjusted_bank_balance' => 0,
            'status' => ReconciliationStatus::Draft,
            'prepared_by' => $analyst->id,
        ]);

        $rciBatch = ImportBatch::query()->create([
            'reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'type' => ImportType::Rci,
            'original_filename' => "Report of Checks Issued_ FUND MOOE_{$start->format('F')} 2026.xlsx",
            'uploaded_by' => $analyst->id,
            'status' => 'committed',
            'committed_at' => $end->copy()->addDay(),
        ]);

        $count = (int) $period['new'];
        $daysSpan = max(1, $start->daysInMonth - 4);
        $newChecks = 0;

        for ($i = 0; $i < $count; $i++) {
            $this->issueCheck($account, $reconciliation, $rciBatch, $start->copy()->addDays((int) floor($i * $daysSpan / $count)), ++$serial, $i);
            $newChecks++;
        }

        if ($period['legacy'] ?? false) {
            foreach (['2025-10-14', '2025-11-19'] as $n => $date) {
                $this->issueCheck($account, $reconciliation, $rciBatch, Carbon::parse($date), ++$serial, $n + 3);
                $newChecks++;
            }
        }

        $rciBatch->update(['row_count' => $newChecks, 'imported_count' => $newChecks]);

        $bankBatch = ImportBatch::query()->create([
            'reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'type' => ImportType::BankStatement,
            'original_filename' => "BANK STATEMENT_{$label} 2026.csv",
            'uploaded_by' => $analyst->id,
            'status' => 'committed',
            'committed_at' => $end->copy()->addDay(),
        ]);

        $rows = $this->clearOutstanding($account, $reconciliation, $bankBatch, $start, $end);

        if ($period['charge'] ?? false) {
            BankTransaction::query()->create([
                'bank_account_id' => $account->id,
                'reconciliation_id' => $reconciliation->id,
                'import_batch_id' => $bankBatch->id,
                'txn_date' => $end->copy()->subDays(2),
                'servicing_branch' => 'OROQUIETA ',
                'description' => 'BANK SERVICE CHARGE',
                'debit' => 150,
                'credit' => 0,
            ]);
            $rows++;
        }

        $bankBatch->update(['row_count' => $rows, 'imported_count' => $rows]);

        $engine->run($reconciliation->fresh(), $analyst->id);

        // Enter unadjusted balances that make the statement balance (book side
        // is arbitrary; the bank side follows from the reconciling items).
        $base = mt_rand(600_000, 2_400_000) + mt_rand(0, 99) / 100;
        $reconciliation->update(['unadjusted_book_balance' => $base, 'unadjusted_bank_balance' => $base]);
        $engine->refresh($reconciliation->fresh());
        $difference = (float) $reconciliation->fresh()->difference;
        $reconciliation->update(['unadjusted_bank_balance' => round($base - $difference, 2)]);
        $engine->refresh($reconciliation->fresh());

        $this->advance($reconciliation->fresh(), (string) $period['status'], $analyst, $reviewer, $workflow, $period, $end);
    }

    private function issueCheck(BankAccount $account, Reconciliation $reconciliation, ImportBatch $batch, Carbon $date, int $serial, int $index): void
    {
        [$payee, $particulars, $uacs] = self::PAYEES[($serial + $index) % count(self::PAYEES)];

        CheckIssuance::query()->create([
            'bank_account_id' => $account->id,
            'reconciliation_id' => $reconciliation->id,
            'import_batch_id' => $batch->id,
            'check_date' => $date->toDateString(),
            'serial_no' => sprintf('%010d', $serial),
            'dv_no' => $date->format('Y-m').'-'.sprintf('%04d', $index + 1),
            'payee' => $payee,
            'uacs_object_code' => $uacs,
            'nature_of_payment' => $particulars,
            'amount' => mt_rand(2_500, 480_000) + mt_rand(0, 99) / 100,
            'status' => CheckStatus::Outstanding,
            'report_no' => $reconciliation->report_no,
        ]);
    }

    /**
     * Bank clears most of the recent checks, leaving the latest couple and any old
     * ones outstanding (the old ones later turn stale).
     */
    private function clearOutstanding(BankAccount $account, Reconciliation $reconciliation, ImportBatch $bankBatch, Carbon $start, Carbon $end): int
    {
        $pool = $account->checkIssuances()
            ->where('status', CheckStatus::Outstanding->value)
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->get();

        $rows = 0;
        $recentCutoff = $start->copy()->subMonths(2);
        $keepFrom = max(0, $pool->count() - 2);

        foreach ($pool->values() as $i => $check) {
            $date = Carbon::parse($check->check_date);

            if ($date->lt($recentCutoff) || $i >= $keepFrom || $date->gt($end->copy()->subDays(4))) {
                continue;
            }

            $inPeriod = $date->gte($start);

            if (! $inPeriod && $i % 2 !== 0) {
                continue;
            }

            $clearedOn = $date->copy()->addDays(2 + ($i % 7));
            if ($clearedOn->lt($start)) {
                $clearedOn = $start->copy()->addDays($i % 5);
            }
            if ($clearedOn->gt($end)) {
                $clearedOn = $end->copy();
            }

            BankTransaction::query()->create([
                'bank_account_id' => $account->id,
                'reconciliation_id' => $reconciliation->id,
                'import_batch_id' => $bankBatch->id,
                'txn_date' => $clearedOn->copy()->setTime(23, 59),
                'servicing_branch' => 'OROQUIETA ',
                'check_no' => $check->serial_no,
                'description' => 'ICC-LOCAL CHECK        ',
                'debit' => $check->amount,
                'credit' => 0,
            ]);
            $rows++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function advance(Reconciliation $reconciliation, string $status, User $analyst, User $reviewer, WorkflowService $workflow, array $period, Carbon $end): void
    {
        if ($status === 'draft') {
            return;
        }

        Auth::setUser($analyst);
        $workflow->submit($reconciliation, $analyst);
        $reconciliation->update(['prepared_at' => $end->copy()->addDays(3)]);

        if ($status === 'for_review') {
            return;
        }

        Auth::setUser($reviewer);

        if ($status === 'returned') {
            $workflow->returnForRevision($reconciliation->fresh(), $reviewer, (string) ($period['remarks'] ?? 'Please review and resubmit.'));

            return;
        }

        $workflow->certify($reconciliation->fresh(), $reviewer);
        $reconciliation->update(['certified_at' => $end->copy()->addDays(6)]);
    }

    /**
     * Older notifications read, the ones still needing action left unread, and all
     * dated to when the action would have happened.
     */
    private function tidyNotifications(): void
    {
        DatabaseNotification::query()->get()->each(function (DatabaseNotification $notification): void {
            $data = $notification->data;
            $periodEnd = isset($data['period']) ? trim(explode('–', (string) $data['period'])[1] ?? '') : '';

            if ($periodEnd !== '') {
                $notification->created_at = Carbon::parse($periodEnd)->addDays(4);
            }

            if (! in_array($data['status'] ?? '', ['for_review', 'returned'], true)) {
                $notification->read_at = $notification->created_at;
            }

            $notification->save();
        });
    }
}
