<x-eh.tabs class="ops-subnav" label="Finance" :active="$active" :items="[
    'payments' => ['label' => 'Payments', 'href' => route('ops.finance')],
    'payouts' => ['label' => 'Payouts', 'href' => route('ops.finance.payouts')],
    'reconciliation' => ['label' => 'Reconciliation and issues', 'href' => route('ops.finance.reconciliation')],
    'ledger' => ['label' => 'Ledger', 'href' => route('ops.finance.ledger')],
]" />
