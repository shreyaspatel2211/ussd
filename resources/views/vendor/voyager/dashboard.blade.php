<div class="row">
    <div class="col-md-6">
        <h3>Customers by Date</h3>
        <div style="width: 30%; height: 30%;">
            <canvas id="customersChart"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <h3>Transactions by Date</h3>
        <div style="width: 30%; height: 30%;">
            <canvas id="transactionsChart"></canvas>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Pass data from the backend
    const customersData = @json($customersData);
    const transactionsData = @json($transactionsData);

    // Customers Chart
    const ctx1 = document.getElementById('customersChart').getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: customersData.map(data => data.date), // Dates from the customers table
            datasets: [{
                label: 'Number of Customers',
                data: customersData.map(data => data.count), // Count of customers per date
                borderColor: 'blue',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                fill: true,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                x: { title: { display: true, text: 'Date' } },
                y: { title: { display: true, text: 'Customers Count' } }
            }
        }
    });

    // Transactions Chart
    const ctx2 = document.getElementById('transactionsChart').getContext('2d');
    new Chart(ctx2, {
        type: 'bar',
        data: {
            labels: transactionsData.map(data => data.date), // Dates from the transactions table
            datasets: [{
                label: 'Total Transactions Amount',
                data: transactionsData.map(data => data.total), // Total amount per date
                backgroundColor: 'green',
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                x: { title: { display: true, text: 'Date' } },
                y: { title: { display: true, text: 'Transaction Amount' } }
            }
        }
    });
</script>
