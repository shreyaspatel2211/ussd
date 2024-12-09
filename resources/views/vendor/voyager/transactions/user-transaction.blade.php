<style>
    .filter-tab.active {
        background-color: #007bff;
        color: white;
        font-weight: bold;
        box-shadow: 0px 4px 8px rgba(0, 123, 255, 0.3);
    }

    .form-control {
        max-width: 100%;
    }

    .mr-2 {
        margin-right: 0.5rem;
    }

    #transactions-content {
        margin-top: 1rem;
    }
</style>


@extends('voyager::master')


@section('content')
    <div class="page-content read container-fluid">
        <div class="row">
            <div class="col-md-12">

                <div class="panel panel-bordered" style="padding-bottom:5px;">
                    <!-- form start -->
                    
                    <h3>Transactions </h3>
                <div class="row mb-3 align-items-center">
                    <!-- Tabs for Date Range Filters (Left) -->
                    <div class="col-md-8">
                        <div class="btn-group" id="dateRangeTabs">
                            <button class="btn btn-primary filter-tab active" data-filter="today">Today</button>
                            <button class="btn btn-primary filter-tab" data-filter="week">This Week</button>
                            <button class="btn btn-primary filter-tab" data-filter="month">This Month</button>
                            <button class="btn btn-primary filter-tab" data-filter="quarter">This Quarter</button>
                            <button class="btn btn-primary filter-tab" data-filter="year">This Year</button>
                        </div>
                    </div>
                
                    <!-- Search Filters (Right, Single Row) -->
                    <div class="col-md-4">
                        <div class="d-inline-flex col-md-6">
                            <input type="text" id="searchName" class="form-control" placeholder="Search Name">
                        </div>
                        <div class="d-inline-flex col-md-6">
                            <input type="number" id="searchAmount" class="form-control" placeholder="Search Amount">
                        </div>
                    </div>
                </div>
                <div id="transactions-content">
                    
                </div>
                </div>
            </div>
        </div>
    </div>

@stop

@section('javascript')
    
    <script>
        var deleteFormAction;
        $('.delete').on('click', function (e) {
            var form = $('#delete_form')[0];

            if (!deleteFormAction) {
                // Save form action initial value
                deleteFormAction = form.action;
            }

            form.action = deleteFormAction.match(/\/[0-9]+$/)
                ? deleteFormAction.replace(/([0-9]+$)/, $(this).data('id'))
                : deleteFormAction + '/' + $(this).data('id');

            $('#delete_modal').modal('show');
        });


        $(document).ready(function() {
            
            function loadTransactions(filter) {
                const name = $('#searchName').val() || ''; 
                const amount = $('#searchAmount').val() || ''; 

                $.ajax({
                    url: '{{ route('customer.transactions.filter') }}',
                    type: 'GET',
                    data: {
                        customer_id: '{{ $id }}',
                        filter: filter,
                        name: name,
                        amount: amount
                    },
                    success: function(data) {
                        $('#transactions-content').html(data);
                    },
                    error: function() {
                        $('#transactions-content').html('<p>Failed to load transactions.</p>');
                    }
                });
            }

            function debounce(func, delay) {
                let debounceTimer;
                return function() {
                    const context = this;
                    const args = arguments;
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => func.apply(context, args), delay);
                };
            }

            loadTransactions('today');

            $('.filter-tab').on('click', function() {
                $('.filter-tab').removeClass('active'); 
                $(this).addClass('active'); 
                var filter = $(this).data('filter');
                loadTransactions(filter);
            });

            $('#searchName, #searchAmount').on('keyup', debounce(function() {
                const activeFilter = $('.filter-tab.active').data('filter') || 'today'; 
                loadTransactions(activeFilter);
            }, 300)); 
        });

    </script>
@stop
