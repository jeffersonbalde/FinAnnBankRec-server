<?php

namespace App\Services\Reconciliation;

use App\Models\Reconciliation;
use App\Models\ReconcilingItem;
use Illuminate\Support\Collection;

/**
 * Applies the reconciling items to the unadjusted balances (adjusted-balance
 * method) and persists the result. The adjusted book and bank balances must be
 * equal for the reconciliation to be certified.
 */
class BrsCalculator
{
    /**
     * @return array<string, mixed>
     */
    public function compute(Reconciliation $reconciliation): array
    {
        $items = $reconciliation->reconcilingItems()->get();

        $bankAdjustment = $items->where('side', 'bank')->sum(fn (ReconcilingItem $i) => $i->signedAmount());
        $bookAdjustment = $items->where('side', 'book')->sum(fn (ReconcilingItem $i) => $i->signedAmount());

        $adjustedBank = round((float) $reconciliation->unadjusted_bank_balance + $bankAdjustment, 2);
        $adjustedBook = round((float) $reconciliation->unadjusted_book_balance + $bookAdjustment, 2);
        $difference = round($adjustedBank - $adjustedBook, 2);

        $reconciliation->update([
            'adjusted_bank_balance' => $adjustedBank,
            'adjusted_book_balance' => $adjustedBook,
            'difference' => $difference,
        ]);

        return [
            'unadjusted_book_balance' => (float) $reconciliation->unadjusted_book_balance,
            'unadjusted_bank_balance' => (float) $reconciliation->unadjusted_bank_balance,
            'bank_items' => $this->serialiseSide($items, 'bank'),
            'book_items' => $this->serialiseSide($items, 'book'),
            'adjusted_bank_balance' => $adjustedBank,
            'adjusted_book_balance' => $adjustedBook,
            'difference' => $difference,
            'is_balanced' => abs($difference) < 0.01,
        ];
    }

    /**
     * @param  Collection<int, ReconcilingItem>  $items
     * @return list<array<string, mixed>>
     */
    private function serialiseSide($items, string $side): array
    {
        return $items->where('side', $side)->values()->map(fn (ReconcilingItem $i) => [
            'id' => $i->id,
            'category' => $i->category->value,
            'label' => $i->category->label(),
            'operation' => $i->operation,
            'amount' => (float) $i->amount,
            'signed_amount' => $i->signedAmount(),
            'schedule_no' => $i->schedule_no,
            'explanatory_comment' => $i->explanatory_comment,
            'is_auto_generated' => $i->is_auto_generated,
        ])->all();
    }
}
