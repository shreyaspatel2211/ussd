<style>
    /* Add custom styles for the table to match Voyager theme */
    .table-voyager {
        border: 1px solid #e2e2e2;
        background-color: #f9f9f9;
    }

    .table-voyager thead {
        background-color: #f8f8f8;
        color: #333;
    }

    .table-voyager th {
        font-weight: bold;
        padding: 12px;
        border-bottom: 2px solid #ddd;
        text-align: center;
    }

    .table-voyager td {
        padding: 10px;
        text-align: center;
        vertical-align: middle;
        color: #666;
    }

    .table-voyager tbody tr:hover {
        background-color: #eaeaea;
    }

    .no-transactions {
        font-style: italic;
        color: #999;
        text-align: center;
    }
</style>

<table class="table table-bordered table-voyager">
    <thead>
        <tr>
            <th>Name</th>
            <th>Transaction Date</th>
            <th>Amount</th>
            <th>Phone Number</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @forelse($transactions as $transaction)
            <tr>
                <td>{{ $transaction->customer->name ?? 'N/A' }}</td>
                <td>{{ \Carbon\Carbon::parse($transaction->datetime)->format('Y-m-d H:i') }}</td>
                <td>${{ number_format($transaction->amount, 2) }}</td>
                <td>{{ $transaction->phone_number }}</td>
                <?php
                if($transaction->status == 'complete'){
                    $class = 'label-success';
                } elseif($transaction->status == 'failed') {
                    $class = 'label-danger';
                } else {
                    $class = 'label-warning';
                }
                ?>
                <td>
                    <span class="label <?= $class ?>">
                        {{ ucfirst($transaction->status) }}
                    </span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="no-transactions">No transactions found.</td>
            </tr>
        @endforelse
    </tbody>
</table>
