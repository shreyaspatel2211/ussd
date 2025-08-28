<!DOCTYPE html>
<html>
<head>
    <title>Customer Report</title>

</head>
<body>

<h2>Customer Report</h2>

<h3>Customers</h3>
<table border="1" style="width:100%; table-layout: fixed; font-size:10px;">
    <thead>
        <tr>
            <th>Name</th><th>Phone Number</th><th>Balance</th>
            {{-- <th>PIN</th> --}}
            <th>Company</th><th>Loan Balance</th><th>Due Balance</th><th>Susu Savings Balance</th><th>Product Balance</th><th>Branch</th><th>ID Number</th>
            <th>Members Welfare</th><th>Service Charge</th>
        </tr>
    </thead>
    <tbody>
        @foreach($customers as $customer)
            <tr>
                <td style="width: 10%;">{{ $customer->name }}</td>
                <td style="width: 10%;">{{ $customer->phone_number ?? '-' }}</td>
                <td style="width: 8%;">{{ $customer->balance ?? '-' }}</td>
                {{-- <td style="width: 8%;">{{ $customer->pin ?? '-' }}</td> --}}
                <td style="width: 10%;">{{ $customer->company->name ?? '-' }}</td>
                <td style="width: 8%;">{{ $customer->loan_balance ?? 0}}</td>
                <td style="width: 8%;">{{ $customer->dues_balance ?? 0 }}</td>
                <td style="width: 8%;">{{ $customer->susu_savings_balance ?? 0 }}</td>
                <td style="width: 8%;">{{ $customer->product_balance ?? 0 }}</td>
                <td style="width: 10%;">{{ $customer->branch_name ?? '-' }}</td>
                <td style="width: 10%;">{{ $customer->id_number ?? '-' }}</td>
                <td style="width: 8%;">{{ $customer->members_welfare ?? '-' }}</td>
                <td style="width: 8%;">{{ $customer->service_charge ?? '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Transactions</h3>
<table border="1">
    <thead>
        <tr>
            <th>Name</th><th>Phone Number</th><th>Amount</th><th>Status</th><th>Date/Time</th><th>Customer</th><th>Company</th><th>Description</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transactions as $transaction)
            <tr>
                <td>{{ $transaction->name }}</td>
                <td>{{ $transaction->phone_number }}</td>
                <td>{{ $transaction->amount }}</td>
                <td>{{ $transaction->status }}</td>
                <td>{{ $transaction->datetime }}</td>
                <td>{{ $transaction->customerID->name ?? '-' }}</td>
                <td>{{ $transaction->company->name ?? '-' }}</td>
                <td>{{ $transaction->description }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Withdrawals</h3>
<table border="1">
    <thead>
        <tr>
            <th>Customer</th><th>Phone Number</th><th>Amount</th><th>Company</th><th>Date</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($withdrawls as $withdrawl)
            <tr>
                <td>{{ $withdrawl->customer_name }}</td>
                <td>{{ $withdrawl->customer_phone_number }}</td>
                <td>{{ $withdrawl->amount }}</td>
                <td>{{ $withdrawl->company->name ?? '-' }}</td>
                <td>{{ $withdrawl->created_at }}</td>
                <td>{{ $withdrawl->status }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

</body>
</html>
