
@extends('voyager::master')
<link rel="stylesheet" type="text/css" href="/vendor/tcg/voyager/assets/css/custom.css">

<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />


{{-- @section('content')
    <div class="page-content">

        <!-- Start of Date Filter -->
        <div class="row mb-4">
            <div class="col-lg-6">
                <label for="dateFilter">Select Date Range:</label>
                <select id="dateFilter" class="form-control">
                    <option value="today">Today</option>
                    <option value="last7days">Last 7 Days</option>
                    <option value="last30days">Last 30 Days</option>
                    <option value="thismonth">This Month</option>
                    <option value="thisyear">This Year</option>
                </select>
            </div>
        </div>
        <!-- End of Date Filter -->

        <!-- Start of the charts -->
        <div class="row mt-4">
            <!-- Customers by Date -->
            <div class="col-lg-6">
                <h3>Customers by Date</h3>
                <div style="width: 100%; height: 300px;">
                    <canvas id="customersChart"></canvas>
                </div>
            </div>

            <!-- Transactions by Date -->
            <div class="col-lg-6">
                <h3>Transactions by Date</h3>
                <div style="width: 100%; height: 300px;">
                    <canvas id="transactionsChart"></canvas>
                </div>
            </div>
        </div>
        <!-- End of charts -->

    </div>
@stop

@section('javascript')
    <!-- Include Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Pass data from the backend (Controller)
        const customersData = @json($customersData);
        const transactionsData = @json($transactionsData);

        // Function to filter data based on the selected date range
        function filterDataByDate(data, range) {
            const today = new Date();
            let startDate = new Date(today);
            let endDate = new Date(today);

            switch (range) {
                case 'today':
                    startDate.setHours(0, 0, 0, 0);
                    endDate.setHours(23, 59, 59, 999);
                    break;
                case 'last7days':
                    startDate.setDate(today.getDate() - 7);
                    break;
                case 'last30days':
                    startDate.setDate(today.getDate() - 30);
                    break;
                case 'thismonth':
                    startDate.setDate(1); // Start of the current month
                    break;
                case 'thisyear':
                    startDate.setMonth(0, 1); // Start of the current year
                    break;
                default:
                    return data;
            }

            return data.filter(item => {
                const itemDate = new Date(item.date);
                return itemDate >= startDate && itemDate <= endDate;
            });
        }

        // Initialize charts with default data
        const ctx1 = document.getElementById('customersChart').getContext('2d');
        const customersChart = new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: customersData.map(data => data.date),
                datasets: [{
                    label: 'Number of Customers',
                    data: customersData.map(data => data.count),
                    borderColor: 'blue',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    hoverBackgroundColor: 'rgba(54, 162, 235, 0.6)', // Hover effect for bar
                }]
            },
            options: {
                responsive: true,
                indexAxis: 'x', // Swap axes (Make it a vertical bar chart)
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    tooltip: {
                        enabled: true, // Enable tooltips for interactivity
                        mode: 'nearest',
                        intersect: false,
                    }
                },
                hover: {
                    animationDuration: 400,
                }
            }
        });

        const ctx2 = document.getElementById('transactionsChart').getContext('2d');
        const transactionsChart = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: transactionsData.map(data => data.date),
                datasets: [{
                    label: 'Number of Transactions',
                    data: transactionsData.map(data => data.count),
                    borderColor: 'green',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    hoverBackgroundColor: 'rgba(75, 192, 192, 0.6)', // Hover effect for bar
                }]
            },
            options: {
                responsive: true,
                indexAxis: 'x', // Swap axes (Make it a vertical bar chart)
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    tooltip: {
                        enabled: true, // Enable tooltips for interactivity
                        mode: 'nearest',
                        intersect: false,
                    }
                },
                hover: {
                    animationDuration: 400,
                }
            }
        });

        // Event listener for date filter change
        document.getElementById('dateFilter').addEventListener('change', updateCharts);

        // Update charts based on selected date range
        function updateCharts() {
            const selectedRange = document.getElementById('dateFilter').value;

            // Filter the data based on the selected date range
            const filteredCustomersData = filterDataByDate(customersData, selectedRange);
            const filteredTransactionsData = filterDataByDate(transactionsData, selectedRange);

            // Update Customers Chart
            customersChart.data.labels = filteredCustomersData.map(data => data.date);
            customersChart.data.datasets[0].data = filteredCustomersData.map(data => data.count);
            customersChart.update();

            // Update Transactions Chart
            transactionsChart.data.labels = filteredTransactionsData.map(data => data.date);
            transactionsChart.data.datasets[0].data = filteredTransactionsData.map(data => data.count);
            transactionsChart.update();
        }
    </script>
@stop --}}


@section('content')

<style>
    .transaction-row {
        display: flex;
        align-items: center;        
        justify-content: space-between; 
        width: 100%;
    }
    .dash-white-box {
        background: #fff;
        border-color:blue;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.05);
        border-radius: 15px;
        padding: 20px;
    }
    .dash-box-title {
        font-weight: 500;
        color: #000;
        font-size: 16px;
    }
    .fw-700 {
        font-weight: 700 !important;
    }
    .mt-15 {
        margin-top: 15px !important;
    }
    .dash-xs-title {
        font-size: 10px;
        font-weight: 600;
    }
    .dash-price {
        font-size: 36px;
        font-weight: 600;
        color: #000;
    }
    .dash-price span {
        font-size: 14px;
        color: #ccc;
    }
    .v-top {
        vertical-align: 15px;
        padding-right: 3px;
    }
    .mt-20 {
        margin-top: 20px !important;
    }
    .mb-15 {
        margin-bottom: 15px !important;
    }
    .mt-30 {
        margin-top: 30px !important;
    }
    .mt-50 {
        margin-top: 50px !important;
    }
    .d-flex {
        display: flex !important;
    }
    .align-items-center {
        align-items: center;
    }
    .ml-auto {
        margin-left: auto !important;
    }
    .circle-box {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        font-size: 12px;
        text-align: center;
        line-height: 25px;
        background: #e5e5e5;
        color: #fff;
        font-weight: 600;
        border: solid 5px #e5e5e5;
        box-shadow: 0 0 10px rgb(0 0 0 / 10%);
    }
    .overlap-boxs .circle-box:first-child {
        margin-left: 0;
    }
    .overlap-boxs .circle-box {
        margin-left: -7px;
        overflow: hidden;
    }
    .overlap-boxs .circle-box img {
        width: 100%;
    }
    .b-none {
        border: none !important;
    }
    .overlap-boxs .circle-box.b-none {
        margin-left: -5px;
    }
    .font-14 {
        font-size: 14px !important;
    }
    .mt-10 {
        margin-top: 10px !important;
    }

    .h-box {
        border: solid 1px #f0f0f0;
        border-radius: 10px;
        width: 38px;
        min-width: 38px;
        height: 40px;
        line-height: 38px;
        text-align: center;
        margin-left: 10px;
        font-size: 12px;
        font-weight: 600;
    }
    .h-box.selected {
        background: #1b1bb4;
        color: #fff;
    }
    .dash-search-box {
        position: relative;
    }
    .dash-search-box .form-control {
        border: solid 1px #f0f0f0;
        background: #fff;
        border-radius: 15px;
        padding: 25px 40px 25px 20px;
    }
    .dash-search-icon {
        background: url(../images/search.png) no-repeat center center;
        background-size: 100%;
        width: 20px;
        height: 20px;
        position: absolute;
        right: 13px;
        top: 17px;
        opacity: 0.5;
    }
    .icon-center {
        position: relative;
        width: 50px;
        height: 50px;
    }
    .icon-center img {
        position: absolute;
        left: 50%;
        top: 50%;
        max-width: 50%;
        max-height: 50%;
        transform: translate(-50%, -50%);
    }
    .ml-15 {
        margin-left: 15px !important;
    }
    .green-text {
        color: #49deb9;
    }
    .lblue {
        background: #eff3fe !important;
    }
    .lyellow {
        background: #fef8ee !important;
    }
    .lred {
        background: #fcebea !important;
    }
    .d-none {
        display: none !important;
    }
    .lightpick--inlined {
        text-align: left;
    }
    .lightpick__toolbar {
        justify-content: space-between;
        position: absolute;
        width: calc(100% - 5px);
        left: 0;
    }
    .lightpick__month-title {
        width: 100%;
        text-align: center;
        margin-left: -24px;
    }
    .lightpick__previous-action, 
    .lightpick__next-action,
    .lightpick__close-action {
        background: transparent;
    }
    .lightpick__previous-action, .lightpick__next-action {
        font-size: 26px;
        margin-top: -5px;
    }
</style>

    <div class="page-content">
        @include('voyager::alerts')

        @if(Auth::user()->role_id != 9)
            <!-- Start of Date Filter -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <label for="dateFilter">Select Date Range:</label>
                    <select id="dateFilter" class="form-control">
                        <option value="today">Today</option>
                        <option value="last7days">Last 7 Days</option>
                        <option value="last30days">Last 30 Days</option>
                        <option value="thismonth">This Month</option>
                        <option value="thisyear" selected>This Year</option>
                    </select>
                </div>
            </div>
            <!-- End of Date Filter -->

            <!-- Start of the charts -->
            <div class="row mt-4">
                <!-- Customers by Date -->
                <div class="col-lg-6">
                    <h3>Customers by Date</h3>
                    <div style="width: 100%; height: 300px;">
                        <canvas id="customersChart"></canvas>
                    </div>
                </div>

                <!-- Transactions by Date -->
                <div class="col-lg-6">
                    <h3>Transactions by Date</h3>
                    <div style="width: 100%; height: 300px;">
                        <canvas id="transactionsChart"></canvas>
                    </div>
                </div>
            </div>
            <!-- End of charts -->
        @endif
    <div class="panel panel-bordered">
        <div class="panel-heading">
            <h3 class="panel-title">Dashboard</h3>
        </div>

        <div class="panel-body"style="background-color:#f6f6f6;">
            <!-- <div class="row mb-3 align-items-center">
               
                <div class="col-md-8" >
                <label for="dateRangeTabs" class="form-label">Filter by Date Range</label>
                    <select name="dateRangeTabs" id="dateRangeTabs" class="form-select">
                        <option value=''>Select</option>
                        <option value='today' data-filter="today">Today</option>
                        <option value='week' data-filter="week">This Week</option>
                        <option value='month' data-filter="month">This Month</option>
                        <option value='quarter' data-filter="quarter">This Quarter</option>
                        <option value='year' data-filter="year">This Year</option>
                    </select>
                    <div class="btn-group" id="dateRangeTabs">
                        <button class="btn btn-primary filter-tab active" data-filter="today">Today</button>
                        <button class="btn btn-primary filter-tab" data-filter="week">This Week</button>
                        <button class="btn btn-primary filter-tab" data-filter="month">This Month</button>
                        <button class="btn btn-primary filter-tab" data-filter="quarter">This Quarter</button>
                        <button class="btn btn-primary filter-tab" data-filter="year">This Year</button>
                    </div>
                </div>

              
                <div class="col-md-4">
                    <div class="d-inline-flex col-md-6">
                        <input type="text" id="searchName" class="form-control" placeholder="Search Name">
                    </div>
                    <div class="d-inline-flex col-md-6">
                        <input type="number" id="searchAmount" class="form-control" placeholder="Search Amount">
                    </div>
                    {{-- <div class="d-inline-flex">
                        <button class="btn btn-secondary" id="applyFilter">Apply Filter</button>
                    </div> --}}
                </div>
            </div> -->
            @if(Auth::user()->role_id != 9)
            <div class="container-fluid">
            <div class="row">
                <div class="col-md-4">
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700">Total Members</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;" class="customer-total">0</b>
                        </div>
                    </div>
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700"> Revenue This Week</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;"class="transaction-week">0</b>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700">Total Revenue</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                                {{-- <b style="font-size:28px;">{{$final_total_balance}} GHS</b> --}}
                            <b style="font-size:28px;" class="total-revenue">0 GHS</b>
                        </div>
                    </div>
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700"> Revenue Today</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;"class="transaction-today">0</b>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            <div class="row mb-3 align-items-center">
                <div class="col-md-4" style="margin-left: 1%;">
                    <label for="dateRangePicker" class="form-label">Filter Date</label>
                    <div id="dateRangePicker" class="form-control d-flex align-items-center">
                        <i class="fa fa-calendar me-2"></i>
                        <span>Select Date Range</span>
                        <i class="fa fa-caret-down ms-auto"></i>
                    </div>
                </div>
            </div>

            <div class="container-fluid">
            <div class="row">
                <div class="col-md-4">
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700">Total Revenue</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;"class="transaction-amount-balance">0</b>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700">Customers</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;" class="customer-count">0</b>
                        </div>
                    </div>
                </div>
                <?php 
                    $user = Illuminate\Support\Facades\Auth::user();
                    $roleId = $user->role_id;
                    if($roleId == 1 || $roleId == 11){
                ?>
                <div class="col-md-4">
                    <div class="dash-white-box mb-15">
                        <div class="dash-box-title"><span class="fw-700">Total Balance</span></div>
                        <div class="dash-price">
                            <span class="v-top"></span>
                            <b style="font-size:28px;"class="transaction-amount">0</b>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            </div>
            @endif

            <?php 
                $user = Illuminate\Support\Facades\Auth::user();
                $roleId = $user->role_id;
                if($roleId == 7){
            ?>
            <hr>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-3">
                        <div class="dash-white-box mb-15">
                            <div class="dash-box-title"><span class="fw-700">Org Dues</span></div>
                            <div class="dash-price">
                                <span class="v-top"></span>
                                <b style="font-size:28px;" class="org_dues">0</b>
                                {{-- <b style="font-size:28px;" class="org_dues">{{$company_Details->org_dues_balance ?? 0}} GHS</b> --}}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dash-white-box mb-15">
                            <div class="dash-box-title"><span class="fw-700">Members Welfare</span></div>
                            <div class="dash-price">
                                <span class="v-top"></span>
                                <b style="font-size:28px;" class="members_welfare">0</b>
                                {{-- <b style="font-size:28px;">{{$company_Details->members_welfare_balance ?? 0}} GHS</b> --}}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dash-white-box mb-15">
                            <div class="dash-box-title"><span class="fw-700">Service Charge</span></div>
                            <div class="dash-price">
                                <span class="v-top"></span>
                                <b style="font-size:28px;" class="service_charge">0</b>
                                {{-- <b style="font-size:28px;">{{$company_Details->service_charge_balance ?? 0}} GHS</b> --}}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dash-white-box mb-15">
                            <div class="dash-box-title"><span class="fw-700">Total Balance</span></div>
                            <div class="dash-price">
                                <span class="v-top"></span>
                                <b style="font-size:28px;"class="transaction-amount">0</b>
                            </div>
                        </div>
                    </div>
                    {{-- <div class="col-md-3">
                        <div class="dash-white-box mb-15">
                            <div class="dash-box-title"><span class="fw-700">Total Balance</span></div>
                            <div class="dash-price">
                                <span class="v-top"></span>
                                <?php
                                    //$final_total_balance = 0; 
                                    //$org_dues = $company_Details->org_dues_balance ?? 0;
                                    //$members_welfare = $company_Details->members_welfare_balance ?? 0;
                                    //$service_charge = $company_Details->service_charge_balance ?? 0;
                                    //$final_total_balance = $company_Details->total_balance;
                                ?>
                                <b style="font-size:28px;">{{$final_total_balance}} GHS</b>
                            </div>
                        </div>
                    </div> --}}
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Agents List</h4>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Agent Balance</th>
                                <th>Customer Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($agents as $agent)
                                <tr>
                                    <td>{{ $agent->name }}</td>
                                    <td>{{ $agent->agent_balance ?? 0 }} GHS</td>
                                    <td>{{ $agent->customers_count ?? 0 }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center">No agents found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <?php } ?>


            @if(Auth::user()->role_id == 9)
                <div class="card p-4 mb-4">
                <div class="card-body">
                    <h4>Assign Customer</h4>
                    
                    <select id="customerDropdown" class="form-control select2" style="width: 100%">
                        <option value="">Select customer</option>
                        @foreach(\App\Models\Customer::where('company_id', Auth::user()->company_id)->get() as $customer)
                            <option value="{{ $customer->id }}">
                                {{ $customer->name }} ({{ $customer->phone_number }})
                            </option>
                        @endforeach
                    </select>

                    <button class="btn btn-primary mt-2" id="assignCustomerBtn">Add</button>
                </div></div>
            @endif

        </div>
    </div>
@stop


@section('javascript')

<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Pass data from the backend
        const customersData = @json($customersData);
        const transactionsData = @json($transactionsData);
    
        // Function to parse a date string into a JS Date object
        function parseDate(dateString) {
            const [year, month, day] = dateString.split('-');
            return new Date(year, month - 1, day); // Month is 0-indexed in JS
        }
    
        // Function to filter data based on the selected date range
        function filterDataByDate(data, range) {
            const today = new Date();
            let startDate = new Date(today);
            let endDate = new Date(today);
    
            switch (range) {
                case 'today':
                    startDate.setHours(0, 0, 0, 0);
                    endDate.setHours(23, 59, 59, 999);
                    break;
                case 'last7days':
                    startDate.setDate(today.getDate() - 7);
                    break;
                case 'last30days':
                    startDate.setDate(today.getDate() - 30);
                    break;
                case 'thismonth':
                    startDate.setDate(1); // Start of the current month
                    break;
                case 'thisyear':
                    startDate.setMonth(0, 1); // Start of the current year
                    break;
                default:
                    return data; // No filtering
            }
    
            return data.filter(item => {
                const itemDate = parseDate(item.date);
                return itemDate >= startDate && itemDate <= endDate;
            });
        }
    
        // Initialize charts
        const ctx1 = document.getElementById('customersChart').getContext('2d');
        const customersChart = new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: customersData.map(data => data.date),
                datasets: [{
                    label: 'Number of Customers',
                    data: customersData.map(data => data.count),
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    
        const ctx2 = document.getElementById('transactionsChart').getContext('2d');
        const transactionsChart = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: transactionsData.map(data => data.date),
                datasets: [{
                    label: 'Total Transactions',
                    data: transactionsData.map(data => data.total),
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    
        // Event listener for date filter change
        document.getElementById('dateFilter').addEventListener('change', () => {
            const selectedRange = document.getElementById('dateFilter').value;
    
            // Filter the data based on the selected date range
            const filteredCustomersData = filterDataByDate(customersData, selectedRange);
            const filteredTransactionsData = filterDataByDate(transactionsData, selectedRange);
    
            // Update Customers Chart
            customersChart.data.labels = filteredCustomersData.map(data => data.date);
            customersChart.data.datasets[0].data = filteredCustomersData.map(data => data.count);
            customersChart.update();
    
            // Update Transactions Chart
            transactionsChart.data.labels = filteredTransactionsData.map(data => data.date);
            transactionsChart.data.datasets[0].data = filteredTransactionsData.map(data => data.total);
            transactionsChart.update();
        });
    </script>

    <script>
        let activeFilter = '';
        $(document).ready(function() {
            $('#dateRangePicker').daterangepicker({
                opens: 'right',
                startDate: moment(),
                endDate: moment(),  
                ranges: {
                    'Today': [moment(), moment()],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                },
                locale: {
                    format: 'MMMM D, YYYY'
                }
            }, function(start, end, label) {
                $('#dateRangePicker span').html(start.format('MMMM D, YYYY') + ' - ' + end.format('MMMM D, YYYY'));

                let activeFilter;
                switch (label) {
                    case 'Today':
                        activeFilter = 'today';
                        break;
                    case 'Last 7 Days':
                        activeFilter = 'last_7_days';
                        break;
                    case 'Last 30 Days':
                        activeFilter = 'last_30_days';
                        break;
                    case 'This Month':
                        activeFilter = 'this_month';
                        break;
                    case 'Last Month':
                        activeFilter = 'last_month';
                        break;
                    default:
                        activeFilter = 'custom';
                        break;
                }

                fetchTransactionsCount(activeFilter, start.format('YYYY-MM-DD'), end.format('YYYY-MM-DD'));
            });
            $('#dateRangePicker span').html(moment().format('MMMM D, YYYY') + ' - ' + moment().format('MMMM D, YYYY'));

            function fetchTransactionsCount(filter='today', start = null, end = null){
                $.ajax({
                    url:'{{ route('dashboard.transactions.filter') }}', // Update with the URL for your route
                    type: 'GET',
                    data: {
                        filter:filter,
                        start_date: start,
                        end_date: end
                    },
                    success: function(response) {
                        // Handle successful response (e.g., update UI with data from the server)
                       $('.transaction-amount').html(response.total_amount.toFixed(2)+ ' GHS')
                       $('.transaction-amount-balance').html(response.total_amount_balance.toFixed(2)+ ' GHS')
                       $('.customer-count').html(response.customer_count + ' Count')
                       $('.customer-total').html(response.total_customers + ' Count')
                       $('.total-revenue').html(response.query_for_total_revenue.toFixed(2)+ ' GHS')
                       $('.transaction-week').html(response.total_week.toFixed(2)+ ' GHS')
                       $('.transaction-today').html(response.total_today.toFixed(2)+ ' GHS')
                       $('.org_dues').html(response.orgDuesCalculated+ ' GHS')
                       $('.service_charge').html(response.totalServiceCharge.toFixed(2)+ ' GHS')
                       $('.members_welfare').html(response.membersWelfareCalculated.toFixed(2)+ ' GHS')
                    },
                    error: function(xhr, status, error) {
                        // Handle error response
                        console.error("An error occurred: " + error);
                    }
                });
            }
            // function fetchTransactions() {
            //     const activeFilter = $('.filter-tab.active').data('filter') || 'today'; 
            //     const name = $('#searchName').val() || '';
            //     const amount = $('#searchAmount').val() || ''; 

            //     $.ajax({
            //         url: '{{ route('dashboard.transactions.filter') }}',
            //         type: 'GET',
            //         data: { filter: activeFilter, name: name, amount: amount },
            //         success: function(data) {
            //             $('#transactions-content').html(data);
            //         },
            //         error: function() {
            //             $('#transactions-content').html('<p>Failed to load transactions.</p>');
            //         }
            //     });
            // }

            // Debounce function to limit how often fetchTransactions is called
            function debounce(func, delay) {
                let debounceTimer;
                return function() {
                    const context = this;
                    const args = arguments;
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => func.apply(context, args), delay);
                };
            }

            // $('#searchName, #searchAmount').on('keyup', debounce(fetchTransactions, 300));

            // $('.filter-tab').on('click', function() {
            //     $('.filter-tab').removeClass('active');
            //     $(this).addClass('active');
            //     fetchTransactions(); 
            // });
             fetchTransactionsCount();
        });

    </script>

    <script>
        $(document).ready(function () {
            $('#customerDropdown').select2({
                placeholder: "Search by name or phone number"
            });

            $('#assignCustomerBtn').click(function () {
                let customerId = $('#customerDropdown').val();

                if (!customerId) {
                    alert("Please select a customer");
                    return;
                }

                $.ajax({
                    url: '{{ route("agent.assign.customer") }}',
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        customer_id: customerId
                    },
                    success: function (res) {
                        alert(res.message);
                        location.reload();
                    },
                    error: function (err) {
                        alert("Something went wrong");
                    }
                });
            });
        });
    </script>

    @if(isset($google_analytics_client_id) && !empty($google_analytics_client_id))
        <script>
            (function (w, d, s, g, js, fs) {
                g = w.gapi || (w.gapi = {});
                g.analytics = {
                    q: [], ready: function (f) {
                        this.q.push(f);
                    }
                };
                js = d.createElement(s);
                fs = d.getElementsByTagName(s)[0];
                js.src = 'https://apis.google.com/js/platform.js';
                fs.parentNode.insertBefore(js, fs);
                js.onload = function () {
                    g.load('analytics');
                };
            }(window, document, 'script'));
        </script>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/1.1.1/Chart.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.10.2/moment.min.js"></script>
        <script>
            // View Selector 2 JS
            !function(e){function t(r){if(i[r])return i[r].exports;var o=i[r]={exports:{},id:r,loaded:!1};return e[r].call(o.exports,o,o.exports,t),o.loaded=!0,o.exports}var i={};return t.m=e,t.c=i,t.p="",t(0)}([function(e,t,i){"use strict";function r(e){return e&&e.__esModule?e:{"default":e}}var o=i(1),s=r(o);gapi.analytics.ready(function(){function e(e,t,i){e.innerHTML=t.map(function(e){var t=e.id==i?"selected ":" ";return"<option "+t+'value="'+e.id+'">'+e.name+"</option>"}).join("")}function t(e){return e.ids||e.viewId?{prop:"viewId",value:e.viewId||e.ids&&e.ids.replace(/^ga:/,"")}:e.propertyId?{prop:"propertyId",value:e.propertyId}:e.accountId?{prop:"accountId",value:e.accountId}:void 0}gapi.analytics.createComponent("ViewSelector2",{execute:function(){return this.setup_(function(){this.updateAccounts_(),this.changed_&&(this.render_(),this.onChange_())}.bind(this)),this},set:function(e){if(!!e.ids+!!e.viewId+!!e.propertyId+!!e.accountId>1)throw new Error('You cannot specify more than one of the following options: "ids", "viewId", "accountId", "propertyId"');if(e.container&&this.container)throw new Error("You cannot change containers once a view selector has been rendered on the page.");var t=this.get();return(t.ids!=e.ids||t.viewId!=e.viewId||t.propertyId!=e.propertyId||t.accountId!=e.accountId)&&(t.ids=null,t.viewId=null,t.propertyId=null,t.accountId=null),gapi.analytics.Component.prototype.set.call(this,e)},setup_:function(e){function t(){s["default"].get().then(function(t){i.summaries=t,i.accounts=i.summaries.all(),e()},function(e){i.emit("error",e)})}var i=this;gapi.analytics.auth.isAuthorized()?t():gapi.analytics.auth.on("signIn",t)},updateAccounts_:function(){var e=this.get(),i=t(e),r=void 0,o=void 0,s=void 0;if(!this.summaries.all().length)return this.emit("error",new Error('This user does not have any Google Analytics accounts. You can sign up at "www.google.com/analytics".'));if(i)switch(i.prop){case"viewId":r=this.summaries.getProfile(i.value),o=this.summaries.getAccountByProfileId(i.value),s=this.summaries.getWebPropertyByProfileId(i.value);break;case"propertyId":s=this.summaries.getWebProperty(i.value),o=this.summaries.getAccountByWebPropertyId(i.value),r=s&&s.views&&s.views[0];break;case"accountId":o=this.summaries.getAccount(i.value),s=o&&o.properties&&o.properties[0],r=s&&s.views&&s.views[0]}else o=this.accounts[0],s=o&&o.properties&&o.properties[0],r=s&&s.views&&s.views[0];o||s||r?(o!=this.account||s!=this.property||r!=this.view)&&(this.changed_={account:o&&o!=this.account,property:s&&s!=this.property,view:r&&r!=this.view},this.account=o,this.properties=o.properties,this.property=s,this.views=s&&s.views,this.view=r,this.ids=r&&"ga:"+r.id):this.emit("error",new Error("This user does not have access to "+i.prop.slice(0,-2)+" : "+i.value))},render_:function(){var t=this.get();this.container="string"==typeof t.container?document.getElementById(t.container):t.container,this.container.innerHTML=t.template||this.template;var i=this.container.querySelectorAll("select"),r=this.accounts,o=this.properties||[{name:"(Empty)",id:""}],s=this.views||[{name:"(Empty)",id:""}];e(i[0],r,this.account.id),e(i[1],o,this.property&&this.property.id),e(i[2],s,this.view&&this.view.id),i[0].onchange=this.onUserSelect_.bind(this,i[0],"accountId"),i[1].onchange=this.onUserSelect_.bind(this,i[1],"propertyId"),i[2].onchange=this.onUserSelect_.bind(this,i[2],"viewId")},onChange_:function(){var e={account:this.account,property:this.property,view:this.view,ids:this.view&&"ga:"+this.view.id};this.changed_&&(this.changed_.account&&this.emit("accountChange",e),this.changed_.property&&this.emit("propertyChange",e),this.changed_.view&&(this.emit("viewChange",e),this.emit("idsChange",e),this.emit("change",e.ids))),this.changed_=null},onUserSelect_:function(e,t){var i={};i[t]=e.value,this.set(i),this.execute()},template:'<div class="ViewSelector2">  <div class="ViewSelector2-item">    <label>Account</label>    <select class="FormField"></select>  </div>  <div class="ViewSelector2-item">    <label>Property</label>    <select class="FormField"></select>  </div>  <div class="ViewSelector2-item">    <label>View</label>    <select class="FormField"></select>  </div></div>'})})},function(e,t,i){function r(){var e=gapi.client.request({path:n}).then(function(e){return e});return new e.constructor(function(t,i){var r=[];e.then(function o(e){var c=e.result;c.items?r=r.concat(c.items):i(new Error("You do not have any Google Analytics accounts. Go to http://google.com/analytics to sign up.")),c.startIndex+c.itemsPerPage<=c.totalResults?gapi.client.request({path:n,params:{"start-index":c.startIndex+c.itemsPerPage}}).then(o):t(new s(r))}).then(null,i)})}var o,s=i(2),n="/analytics/v3/management/accountSummaries";e.exports={get:function(e){return e&&(o=null),o||(o=r())}}},function(e,t){function i(e){this.accounts_=e,this.webProperties_=[],this.profiles_=[],this.accountsById_={},this.webPropertiesById_=this.propertiesById_={},this.profilesById_=this.viewsById_={};for(var t,i=0;t=this.accounts_[i];i++)if(this.accountsById_[t.id]={self:t},t.webProperties){r(t,"webProperties","properties");for(var o,s=0;o=t.webProperties[s];s++)if(this.webProperties_.push(o),this.webPropertiesById_[o.id]={self:o,parent:t},o.profiles){r(o,"profiles","views");for(var n,c=0;n=o.profiles[c];c++)this.profiles_.push(n),this.profilesById_[n.id]={self:n,parent:o,grandParent:t}}}}function r(e,t,i){Object.defineProperty?Object.defineProperty(e,i,{get:function(){return e[t]}}):e[i]=e[t]}i.prototype.all=function(){return this.accounts_},r(i.prototype,"all","allAccounts"),i.prototype.allWebProperties=function(){return this.webProperties_},r(i.prototype,"allWebProperties","allProperties"),i.prototype.allProfiles=function(){return this.profiles_},r(i.prototype,"allProfiles","allViews"),i.prototype.get=function(e){if(!!e.accountId+!!e.webPropertyId+!!e.propertyId+!!e.profileId+!!e.viewId>1)throw new Error('get() only accepts an object with a single property: either "accountId", "webPropertyId", "propertyId", "profileId" or "viewId"');return this.getProfile(e.profileId||e.viewId)||this.getWebProperty(e.webPropertyId||e.propertyId)||this.getAccount(e.accountId)},i.prototype.getAccount=function(e){return this.accountsById_[e]&&this.accountsById_[e].self},i.prototype.getWebProperty=function(e){return this.webPropertiesById_[e]&&this.webPropertiesById_[e].self},r(i.prototype,"getWebProperty","getProperty"),i.prototype.getProfile=function(e){return this.profilesById_[e]&&this.profilesById_[e].self},r(i.prototype,"getProfile","getView"),i.prototype.getAccountByProfileId=function(e){return this.profilesById_[e]&&this.profilesById_[e].grandParent},r(i.prototype,"getAccountByProfileId","getAccountByViewId"),i.prototype.getWebPropertyByProfileId=function(e){return this.profilesById_[e]&&this.profilesById_[e].parent},r(i.prototype,"getWebPropertyByProfileId","getPropertyByViewId"),i.prototype.getAccountByWebPropertyId=function(e){return this.webPropertiesById_[e]&&this.webPropertiesById_[e].parent},r(i.prototype,"getAccountByWebPropertyId","getAccountByPropertyId"),e.exports=i}]);
            // DateRange Selector JS
            !function(t){function e(n){if(a[n])return a[n].exports;var i=a[n]={exports:{},id:n,loaded:!1};return t[n].call(i.exports,i,i.exports,e),i.loaded=!0,i.exports}var a={};return e.m=t,e.c=a,e.p="",e(0)}([function(t,e){"use strict";gapi.analytics.ready(function(){function t(t){if(n.test(t))return t;var i=a.exec(t);if(i)return e(+i[1]);if("today"==t)return e(0);if("yesterday"==t)return e(1);throw new Error("Cannot convert date "+t)}function e(t){var e=new Date;e.setDate(e.getDate()-t);var a=String(e.getMonth()+1);a=1==a.length?"0"+a:a;var n=String(e.getDate());return n=1==n.length?"0"+n:n,e.getFullYear()+"-"+a+"-"+n}var a=/(\d+)daysAgo/,n=/\d{4}\-\d{2}\-\d{2}/;gapi.analytics.createComponent("DateRangeSelector",{execute:function(){var e=this.get();e["start-date"]=e["start-date"]||"7daysAgo",e["end-date"]=e["end-date"]||"yesterday",this.container="string"==typeof e.container?document.getElementById(e.container):e.container,e.template&&(this.template=e.template),this.container.innerHTML=this.template;var a=this.container.querySelectorAll("input");return this.startDateInput=a[0],this.startDateInput.value=t(e["start-date"]),this.endDateInput=a[1],this.endDateInput.value=t(e["end-date"]),this.setValues(),this.setMinMax(),this.container.onchange=this.onChange.bind(this),this},onChange:function(){this.setValues(),this.setMinMax(),this.emit("change",{"start-date":this["start-date"],"end-date":this["end-date"]})},setValues:function(){this["start-date"]=this.startDateInput.value,this["end-date"]=this.endDateInput.value},setMinMax:function(){this.startDateInput.max=this.endDateInput.value,this.endDateInput.min=this.startDateInput.value},template:'<div class="DateRangeSelector">  <div class="DateRangeSelector-item">    <label>Start Date</label>     <input type="date">  </div>  <div class="DateRangeSelector-item">    <label>End Date</label>     <input type="date">  </div></div>'})})}]);
            // Active Users JS
            !function(t){function i(s){if(e[s])return e[s].exports;var n=e[s]={exports:{},id:s,loaded:!1};return t[s].call(n.exports,n,n.exports,i),n.loaded=!0,n.exports}var e={};return i.m=t,i.c=e,i.p="",i(0)}([function(t,i){"use strict";gapi.analytics.ready(function(){gapi.analytics.createComponent("ActiveUsers",{initialize:function(){this.activeUsers=0,gapi.analytics.auth.once("signOut",this.handleSignOut_.bind(this))},execute:function(){this.polling_&&this.stop(),this.render_(),gapi.analytics.auth.isAuthorized()?this.pollActiveUsers_():gapi.analytics.auth.once("signIn",this.pollActiveUsers_.bind(this))},stop:function(){clearTimeout(this.timeout_),this.polling_=!1,this.emit("stop",{activeUsers:this.activeUsers})},render_:function(){var t=this.get();this.container="string"==typeof t.container?document.getElementById(t.container):t.container,this.container.innerHTML=t.template||this.template,this.container.querySelector("b").innerHTML=this.activeUsers},pollActiveUsers_:function(){var t=this.get(),i=1e3*(t.pollingInterval||5);if(isNaN(i)||5e3>i)throw new Error("Frequency must be 5 seconds or more.");this.polling_=!0,gapi.client.analytics.data.realtime.get({ids:t.ids,metrics:"rt:activeUsers"}).then(function(t){var e=t.result,s=e.totalResults?+e.rows[0][0]:0,n=this.activeUsers;this.emit("success",{activeUsers:this.activeUsers}),s!=n&&(this.activeUsers=s,this.onChange_(s-n)),1==this.polling_&&(this.timeout_=setTimeout(this.pollActiveUsers_.bind(this),i))}.bind(this))},onChange_:function(t){var i=this.container.querySelector("b");i&&(i.innerHTML=this.activeUsers),this.emit("change",{activeUsers:this.activeUsers,delta:t}),t>0?this.emit("increase",{activeUsers:this.activeUsers,delta:t}):this.emit("decrease",{activeUsers:this.activeUsers,delta:t})},handleSignOut_:function(){this.stop(),gapi.analytics.auth.once("signIn",this.handleSignIn_.bind(this))},handleSignIn_:function(){this.pollActiveUsers_(),gapi.analytics.auth.once("signOut",this.handleSignOut_.bind(this))},template:'<div class="ActiveUsers">Active Users: <b class="ActiveUsers-value"></b></div>'})})}]);
        </script>

        <script>
            // == NOTE ==
            // This code uses ES6 promises. If you want to use this code in a browser
            // that doesn't supporting promises natively, you'll have to include a polyfill.

            gapi.analytics.ready(function () {

                /**
                 * Authorize the user immediately if the user has already granted access.
                 * If no access has been created, render an authorize button inside the
                 * element with the ID "embed-api-auth-container".
                 */
                gapi.analytics.auth.authorize({
                    container: 'embed-api-auth-container',
                    clientid: '{{ $google_analytics_client_id }}'
                });


                /**
                 * Create a new ActiveUsers instance to be rendered inside of an
                 * element with the id "active-users-container" and poll for changes every
                 * five seconds.
                 */
                var activeUsers = new gapi.analytics.ext.ActiveUsers({
                    container: 'active-users-container',
                    pollingInterval: 5
                });


                /**
                 * Add CSS animation to visually show the when users come and go.
                 */
                activeUsers.once('success', function () {
                    var element = this.container.firstChild;
                    var timeout;

                    document.getElementById('embed-api-auth-container').style.display = 'none';
                    document.getElementById('analytics-dashboard').style.display = 'block';

                    this.on('change', function (data) {
                        var element = this.container.firstChild;
                        var animationClass = data.delta > 0 ? 'is-increasing' : 'is-decreasing';
                        element.className += (' ' + animationClass);

                        clearTimeout(timeout);
                        timeout = setTimeout(function () {
                            element.className =
                                    element.className.replace(/ is-(increasing|decreasing)/g, '');
                        }, 3000);
                    });
                });


                /**
                 * Create a new ViewSelector2 instance to be rendered inside of an
                 * element with the id "view-selector-container".
                 */
                var viewSelector = new gapi.analytics.ext.ViewSelector2({
                    container: 'view-selector-container',
                    propertyId: '{{ Voyager::setting("site.google_analytics_tracking_id")  }}'
                })
                        .execute();


                /**
                 * Update the activeUsers component, the Chartjs charts, and the dashboard
                 * title whenever the user changes the view.
                 */
                viewSelector.on('viewChange', function (data) {
                    var title = document.getElementById('view-name');
                    if (title) {
                        title.innerHTML = data.property.name + ' (' + data.view.name + ')';
                    }

                    // Start tracking active users for this view.
                    activeUsers.set(data).execute();

                    // Render all the of charts for this view.
                    renderWeekOverWeekChart(data.ids);
                    renderYearOverYearChart(data.ids);
                    renderTopBrowsersChart(data.ids);
                    renderTopCountriesChart(data.ids);
                });


                /**
                 * Draw the a chart.js line chart with data from the specified view that
                 * overlays session data for the current week over session data for the
                 * previous week.
                 */
                function renderWeekOverWeekChart(ids) {

                    // Adjust `now` to experiment with different days, for testing only...
                    var now = moment(); // .subtract(3, 'day');

                    var thisWeek = query({
                        'ids': ids,
                        'dimensions': 'ga:date,ga:nthDay',
                        'metrics': 'ga:users',
                        'start-date': moment(now).subtract(1, 'day').day(0).format('YYYY-MM-DD'),
                        'end-date': moment(now).format('YYYY-MM-DD')
                    });

                    var lastWeek = query({
                        'ids': ids,
                        'dimensions': 'ga:date,ga:nthDay',
                        'metrics': 'ga:users',
                        'start-date': moment(now).subtract(1, 'day').day(0).subtract(1, 'week')
                                .format('YYYY-MM-DD'),
                        'end-date': moment(now).subtract(1, 'day').day(6).subtract(1, 'week')
                                .format('YYYY-MM-DD')
                    });

                    Promise.all([thisWeek, lastWeek]).then(function (results) {

                        var data1 = results[0].rows.map(function (row) {
                            return +row[2];
                        });
                        var data2 = results[1].rows.map(function (row) {
                            return +row[2];
                        });
                        var labels = results[1].rows.map(function (row) {
                            return +row[0];
                        });

                        labels = labels.map(function (label) {
                            return moment(label, 'YYYYMMDD').format('ddd');
                        });

                        var data = {
                            labels: labels,
                            datasets: [
                                {
                                    label: '{{ __('voyager::date.last_week') }}',
                                    fillColor: 'rgba(220,220,220,0.5)',
                                    strokeColor: 'rgba(220,220,220,1)',
                                    pointColor: 'rgba(220,220,220,1)',
                                    pointStrokeColor: '#fff',
                                    data: data2
                                },
                                {
                                    label: '{{ __('voyager::date.this_week') }}',
                                    fillColor: 'rgba(151,187,205,0.5)',
                                    strokeColor: 'rgba(151,187,205,1)',
                                    pointColor: 'rgba(151,187,205,1)',
                                    pointStrokeColor: '#fff',
                                    data: data1
                                }
                            ]
                        };

                        new Chart(makeCanvas('chart-1-container')).Line(data);
                        generateLegend('legend-1-container', data.datasets);
                    });
                }


                /**
                 * Draw the a chart.js bar chart with data from the specified view that
                 * overlays session data for the current year over session data for the
                 * previous year, grouped by month.
                 */
                function renderYearOverYearChart(ids) {

                    // Adjust `now` to experiment with different days, for testing only...
                    var now = moment(); // .subtract(3, 'day');

                    var thisYear = query({
                        'ids': ids,
                        'dimensions': 'ga:month,ga:nthMonth',
                        'metrics': 'ga:users',
                        'start-date': moment(now).date(1).month(0).format('YYYY-MM-DD'),
                        'end-date': moment(now).format('YYYY-MM-DD')
                    });

                    var lastYear = query({
                        'ids': ids,
                        'dimensions': 'ga:month,ga:nthMonth',
                        'metrics': 'ga:users',
                        'start-date': moment(now).subtract(1, 'year').date(1).month(0)
                                .format('YYYY-MM-DD'),
                        'end-date': moment(now).date(1).month(0).subtract(1, 'day')
                                .format('YYYY-MM-DD')
                    });

                    Promise.all([thisYear, lastYear]).then(function (results) {
                        var data1 = results[0].rows.map(function (row) {
                            return +row[2];
                        });
                        var data2 = results[1].rows.map(function (row) {
                            return +row[2];
                        });
                        var labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

                        // Ensure the data arrays are at least as long as the labels array.
                        // Chart.js bar charts don't (yet) accept sparse datasets.
                        for (var i = 0, len = labels.length; i < len; i++) {
                            if (data1[i] === undefined) data1[i] = null;
                            if (data2[i] === undefined) data2[i] = null;
                        }

                        var data = {
                            labels: labels,
                            datasets: [
                                {
                                    label: '{{ __('voyager::date.last_year') }}',
                                    fillColor: 'rgba(220,220,220,0.5)',
                                    strokeColor: 'rgba(220,220,220,1)',
                                    data: data2
                                },
                                {
                                    label: '{{ __('voyager::date.this_year') }}',
                                    fillColor: 'rgba(151,187,205,0.5)',
                                    strokeColor: 'rgba(151,187,205,1)',
                                    data: data1
                                }
                            ]
                        };

                        new Chart(makeCanvas('chart-2-container')).Bar(data);
                        generateLegend('legend-2-container', data.datasets);
                    })
                            .catch(function (err) {
                                console.error(err.stack);
                            });
                }


                /**
                 * Draw the a chart.js doughnut chart with data from the specified view that
                 * show the top 5 browsers over the past seven days.
                 */
                function renderTopBrowsersChart(ids) {

                    query({
                        'ids': ids,
                        'dimensions': 'ga:browser',
                        'metrics': 'ga:pageviews',
                        'sort': '-ga:pageviews',
                        'max-results': 5
                    })
                            .then(function (response) {

                                var data = [];
                                var colors = ['#4D5360', '#949FB1', '#D4CCC5', '#E2EAE9', '#F7464A'];

                                response.rows.forEach(function (row, i) {
                                    data.push({value: +row[1], color: colors[i], label: row[0]});
                                });

                                new Chart(makeCanvas('chart-3-container')).Doughnut(data);
                                generateLegend('legend-3-container', data);
                            });
                }


                /**
                 * Draw the a chart.js doughnut chart with data from the specified view that
                 * compares sessions from mobile, desktop, and tablet over the past seven
                 * days.
                 */
                function renderTopCountriesChart(ids) {
                    query({
                        'ids': ids,
                        'dimensions': 'ga:country',
                        'metrics': 'ga:sessions',
                        'sort': '-ga:sessions',
                        'max-results': 5
                    })
                            .then(function (response) {

                                var data = [];
                                var colors = ['#4D5360', '#949FB1', '#D4CCC5', '#E2EAE9', '#F7464A'];

                                response.rows.forEach(function (row, i) {
                                    data.push({
                                        label: row[0],
                                        value: +row[1],
                                        color: colors[i]
                                    });
                                });

                                new Chart(makeCanvas('chart-4-container')).Doughnut(data);
                                generateLegend('legend-4-container', data);
                            });
                }


                /**
                 * Extend the Embed APIs `gapi.analytics.report.Data` component to
                 * return a promise the is fulfilled with the value returned by the API.
                 * @param {Object} params The request parameters.
                 * @return {Promise} A promise.
                 */
                function query(params) {
                    return new Promise(function (resolve, reject) {
                        var data = new gapi.analytics.report.Data({query: params});
                        data.once('success', function (response) {
                            resolve(response);
                        })
                                .once('error', function (response) {
                                    reject(response);
                                })
                                .execute();
                    });
                }


                /**
                 * Create a new canvas inside the specified element. Set it to be the width
                 * and height of its container.
                 * @param {string} id The id attribute of the element to host the canvas.
                 * @return {RenderingContext} The 2D canvas context.
                 */
                function makeCanvas(id) {
                    var container = document.getElementById(id);
                    var canvas = document.createElement('canvas');
                    var ctx = canvas.getContext('2d');

                    container.innerHTML = '';
                    canvas.width = container.offsetWidth;
                    canvas.height = container.offsetHeight;
                    container.appendChild(canvas);

                    return ctx;
                }


                /**
                 * Create a visual legend inside the specified element based off of a
                 * Chart.js dataset.
                 * @param {string} id The id attribute of the element to host the legend.
                 * @param {Array.<Object>} items A list of labels and colors for the legend.
                 */
                function generateLegend(id, items) {
                    var legend = document.getElementById(id);
                    legend.innerHTML = items.map(function (item) {
                        var color = item.color || item.fillColor;
                        var label = item.label;
                        return '<li><i style="background:' + color + '"></i>' + label + '</li>';
                    }).join('');
                }


                // Set some global Chart.js defaults.
                Chart.defaults.global.animationSteps = 60;
                Chart.defaults.global.animationEasing = 'easeInOutQuart';
                Chart.defaults.global.responsive = true;
                Chart.defaults.global.maintainAspectRatio = false;

                // resize to redraw charts
                window.dispatchEvent(new Event('resize'));

            });

        </script>

    @endif

@stop
