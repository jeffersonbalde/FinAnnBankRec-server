@php
    $money = fn ($v) => number_format((float) $v, 2);
    $signatory = fn ($block) => $account->signatories->firstWhere('block.value', $block);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        body { color: #111; margin: 24px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .b { font-weight: bold; }
        .muted { color: #555; }
        .appendix { text-align: right; font-style: italic; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #333; padding: 4px 6px; }
        th { background: #eee; }
        .no-border, .no-border td { border: none; }
        .sig { margin-top: 40px; }
        .sig td { border: none; padding-top: 28px; }
        .sig .name { font-weight: bold; text-decoration: underline; }
        h2 { font-size: 13px; margin: 24px 0 0; }
    </style>
</head>
<body>
    <p class="appendix">Appendix 81</p>
    <p class="center b">{{ $account->entity_name }}</p>
    <p class="center">Cash in Bank - Local Currency, Current Account</p>
    <p class="center b">Bank Reconciliation Statement</p>
    <p class="center">{{ $reconciliation->statement_label ?: 'As of '.$reconciliation->period_end->format('F j, Y') }}</p>
    <p class="center muted">Fund Cluster: {{ $account->fund_cluster }} &nbsp;&middot;&nbsp; Account No.: {{ $account->account_number }}</p>

    <table>
        <thead>
            <tr>
                <th style="text-align:left">Particulars</th>
                <th class="right">Agency (Book)</th>
                <th class="right">Bank</th>
                <th style="text-align:left">Explanatory Comment</th>
            </tr>
        </thead>
        <tbody>
            <tr class="b">
                <td>Unadjusted Balances</td>
                <td class="right">{{ $money($brs['unadjusted_book_balance']) }}</td>
                <td class="right">{{ $money($brs['unadjusted_bank_balance']) }}</td>
                <td></td>
            </tr>
            <tr><td colspan="4"><em>Add/Deduct: Bank Reconciling Items</em></td></tr>
            @forelse ($brs['bank_items'] as $item)
                <tr>
                    <td>&nbsp;&nbsp;{{ $item['operation'] === 'add' ? '(+)' : '(−)' }} {{ $item['label'] }}</td>
                    <td></td>
                    <td class="right">{{ $money($item['amount']) }}</td>
                    <td>{{ $item['schedule_no'] ? 'See '.$item['schedule_no'] : $item['explanatory_comment'] }}</td>
                </tr>
            @empty
                <tr><td class="muted">&nbsp;&nbsp;(none)</td><td></td><td></td><td></td></tr>
            @endforelse
            <tr><td colspan="4"><em>Add/Deduct: Agency Book Reconciling Items</em></td></tr>
            @forelse ($brs['book_items'] as $item)
                <tr>
                    <td>&nbsp;&nbsp;{{ $item['operation'] === 'add' ? '(+)' : '(−)' }} {{ $item['label'] }}</td>
                    <td class="right">{{ $money($item['amount']) }}</td>
                    <td></td>
                    <td>{{ $item['schedule_no'] ? 'See '.$item['schedule_no'] : $item['explanatory_comment'] }}</td>
                </tr>
            @empty
                <tr><td class="muted">&nbsp;&nbsp;(none)</td><td></td><td></td><td></td></tr>
            @endforelse
            <tr class="b">
                <td>Adjusted Balances</td>
                <td class="right">{{ $money($brs['adjusted_book_balance']) }}</td>
                <td class="right">{{ $money($brs['adjusted_bank_balance']) }}</td>
                <td>{{ $brs['is_balanced'] ? '' : 'Difference: '.$money($brs['difference']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="sig no-border">
        <tr>
            <td style="width:50%">Prepared by:</td>
            <td style="width:50%">Certified Correct:</td>
        </tr>
        <tr>
            <td class="name">{{ optional($signatory('prepared_by'))->name }}</td>
            <td class="name">{{ optional($signatory('certified_correct'))->name }}</td>
        </tr>
        <tr>
            <td class="muted">{{ optional($signatory('prepared_by'))->designation }}</td>
            <td class="muted">{{ optional($signatory('certified_correct'))->designation }}</td>
        </tr>
    </table>

    <h2>Schedule 1 — List of Outstanding Checks</h2>
    <table>
        <thead>
            <tr>
                <th style="text-align:left">Payee</th>
                <th>Date of Check</th>
                <th>Check/ADA No.</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($outstandingChecks as $check)
                <tr>
                    <td>{{ $check->payee }}</td>
                    <td class="center">{{ optional($check->check_date)->format('m/d/Y') }}</td>
                    <td class="center">{{ $check->serial_no }}</td>
                    <td class="right">{{ $money($check->amount) }}</td>
                </tr>
            @endforeach
            <tr class="b">
                <td colspan="3">TOTAL</td>
                <td class="right">{{ $money($outstandingChecks->sum('amount')) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
