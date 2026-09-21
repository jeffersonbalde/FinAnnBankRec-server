<?php

namespace App\Services\Reconciliation;

use App\Enums\BankTransactionType;
use App\Enums\CheckStatus;
use App\Enums\MatchStatus;
use App\Models\BankTransaction;
use App\Models\CheckIssuance;
use App\Models\MatchRun;
use App\Models\Reconciliation;
use Illuminate\Support\Collection;

class CheckMatchingService
{
    public function __construct(private readonly float $amountTolerance = 0.01) {}

    /**
     * Match issued checks against the bank clearings for this reconciliation.
     *
     * @return array{matched: int, outstanding: int, flags: list<array<string, mixed>>}
     */
    public function run(Reconciliation $reconciliation, ?int $runBy = null): array
    {
        $checks = $reconciliation->bankAccount
            ->checkIssuances()
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Cleared->value, CheckStatus::Stale->value])
            ->get();

        $clearings = $reconciliation->bankTransactions()
            ->where('derived_type', BankTransactionType::CheckClearing->value)
            ->get();

        $this->resetAutoMatches($checks, $clearings);

        $flags = [];
        $matched = 0;
        $checksByNumber = $this->indexByNumber($checks);

        foreach ($clearings as $clearing) {
            if ($clearing->match_status === MatchStatus::Manual) {
                $matched++;

                continue;
            }

            $key = $this->normalise($clearing->check_no);
            $candidates = $checksByNumber->get($key, collect());

            if ($candidates->isEmpty()) {
                $flags[] = [
                    'type' => 'unrecorded_check',
                    'message' => "Bank cleared check {$clearing->check_no} for ".number_format((float) $clearing->debit, 2).' but it is not in the Report of Checks Issued.',
                    'bank_transaction_id' => $clearing->id,
                    'check_no' => $clearing->check_no,
                    'amount' => (float) $clearing->debit,
                ];

                continue;
            }

            $exact = $candidates->first(fn (CheckIssuance $c) => abs((float) $c->amount - (float) $clearing->debit) <= $this->amountTolerance
                && $c->status !== CheckStatus::Cleared);

            if ($exact === null) {
                $near = $candidates->first(fn (CheckIssuance $c) => $c->status !== CheckStatus::Cleared);
                if ($near !== null) {
                    $flags[] = [
                        'type' => 'amount_mismatch',
                        'message' => "Check {$clearing->check_no}: books show ".number_format((float) $near->amount, 2).' but the bank cleared '.number_format((float) $clearing->debit, 2).'.',
                        'check_issuance_id' => $near->id,
                        'bank_transaction_id' => $clearing->id,
                        'book_amount' => (float) $near->amount,
                        'bank_amount' => (float) $clearing->debit,
                        'difference' => round((float) $clearing->debit - (float) $near->amount, 2),
                    ];
                }

                continue;
            }

            $exact->update([
                'status' => CheckStatus::Cleared,
                'cleared_on' => $clearing->txn_date?->toDateString(),
                'cleared_bank_transaction_id' => $clearing->id,
            ]);
            $clearing->update([
                'match_status' => MatchStatus::Auto,
                'matched_check_issuance_id' => $exact->id,
            ]);
            $matched++;
        }

        $outstanding = $reconciliation->bankAccount
            ->checkIssuances()
            ->where('status', CheckStatus::Outstanding->value)
            ->count();

        MatchRun::create([
            'reconciliation_id' => $reconciliation->id,
            'run_by' => $runBy,
            'matched_count' => $matched,
            'outstanding_count' => $outstanding,
            'flagged_count' => count($flags),
            'flags' => $flags,
            'parameters' => ['amount_tolerance' => $this->amountTolerance],
        ]);

        return ['matched' => $matched, 'outstanding' => $outstanding, 'flags' => $flags];
    }

    /**
     * @param  Collection<int, CheckIssuance>  $checks
     * @param  Collection<int, BankTransaction>  $clearings
     */
    private function resetAutoMatches(Collection $checks, Collection $clearings): void
    {
        $manuallyMatchedCheckIds = $clearings
            ->where('match_status', MatchStatus::Manual)
            ->pluck('matched_check_issuance_id')
            ->filter()
            ->all();

        $checks
            ->whereNotIn('id', $manuallyMatchedCheckIds)
            ->where('status', CheckStatus::Cleared->value)
            ->each(fn (CheckIssuance $c) => $c->update([
                'status' => CheckStatus::Outstanding,
                'cleared_on' => null,
                'cleared_bank_transaction_id' => null,
            ]));

        $clearings
            ->where('match_status', '!=', MatchStatus::Manual)
            ->each(fn (BankTransaction $t) => $t->update([
                'match_status' => MatchStatus::Unmatched,
                'matched_check_issuance_id' => null,
            ]));
    }

    /**
     * @param  Collection<int, CheckIssuance>  $checks
     * @return Collection<string, Collection<int, CheckIssuance>>
     */
    private function indexByNumber(Collection $checks): Collection
    {
        return $checks->groupBy(fn (CheckIssuance $c) => $this->normalise($c->serial_no));
    }

    private function normalise(?string $number): string
    {
        return ltrim(preg_replace('/\s+/', '', (string) $number), '0') ?: '0';
    }
}
