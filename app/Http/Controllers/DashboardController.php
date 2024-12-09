<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use Carbon\Carbon;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use App\Models\Customer;

class DashboardController extends Controller
{
    public function index()
    {
        // Fetch customers data grouped by date
        $customersData = Customer::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                        ->groupBy('date')
                        ->orderBy('date')
                        ->get();

        // Fetch transactions data grouped by date
        $transactionsData = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
                            ->groupBy('date')
                            ->orderBy('date')
                            ->get();

            return view('voyager::index', [
                'customersData' => $customersData,
                'transactionsData' => $transactionsData
            ]);
    }

    public function getAllTransactions(Request $request)
    {
        $filter = $request->get('filter');
        $name = $request->get('name');
        $amount = $request->get('amount');

        $query = Transaction::with('customer')->where('status','success');
        $totalquery = Transaction::with('customer')->where('status','success');
        $totalweek= Transaction::with('customer')->where('status','success')->whereBetween('transactions.datetime', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->sum('amount');
        $totaltoday= Transaction::with('customer')->where('status','success')->whereDate('transactions.datetime', Carbon::today())->sum('amount');

        if ($filter) {
            switch ($filter) {
                case 'today':
                    $query->whereDate('transactions.datetime', Carbon::today());
                    break;
                case 'week':
                    $query->whereBetween('transactions.datetime', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereMonth('transactions.datetime', Carbon::now()->month);
                    break;
                case 'quarter':
                    $query->whereBetween('transactions.datetime', [Carbon::now()->firstOfQuarter(), Carbon::now()->lastOfQuarter()]);
                    break;
                case 'year':
                    $query->whereYear('transactions.datetime', Carbon::now()->year);
                    break;
            }
        }
        $totalAmount = $query->sum('amount');
        $totalTransaction = $totalquery->sum('amount');
        $totalTransactionCount = $query->count();

        $user = Customer::query();
        $totaluserquery = Customer::query();
        if ($filter) {
            switch ($filter) {
                case 'today':
                    $user->whereDate('created_at', Carbon::today());
                    break;
                case 'week':
                    $user->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                    break;
                case 'month':
                    $user->whereMonth('created_at', Carbon::now()->month);
                    break;
                case 'quarter':
                    $user->whereBetween('created_at', [Carbon::now()->firstOfQuarter(), Carbon::now()->lastOfQuarter()]);
                    break;
                case 'year':
                    $user->whereYear('created_at', Carbon::now()->year);
                    break;
            }
        }
        $totalcustomerCount = $totaluserquery->count();
        $customerCount = $user->count();
        return response()->json(['total_amount'=>$totalAmount,'transaction_count'=>$totalTransactionCount,'customer_count'=>$customerCount,'total_customers'=> $totalcustomerCount,'total_revenue'=>$totalTransaction,'customer_count'=> $customerCount,'total_week'=> $totalweek,'total_today'=> $totaltoday]);
    }
}
