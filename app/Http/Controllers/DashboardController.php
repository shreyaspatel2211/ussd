<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use Carbon\Carbon;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use App\Models\Company;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {

        $user = Auth::user();
        if (!$user) {
            return redirect()->route('voyager.login');
        }
        $roleId = $user->role_id;
        $companyId = $user->company_id;

        if($roleId == 7){

            $company_Details = Company::where('id', $companyId)->first();
            // Fetch customers data grouped by date
            $customersData = Customer::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                            ->groupBy('date')
                            ->orderBy('date')
                            ->where('company_id', $companyId)
                            ->get();

            $comp = Company::where('id',$companyId)->first();

            // Fetch transactions data grouped by date
            $transactionsData = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
                                ->groupBy('date')
                                ->orderBy('date')
                                ->where('company_id',$comp->company_id)
                                ->where('status', 'success')
                                ->get();

            $companyId = Auth::user()->company_id;

            $agents = User::withCount('customers')
                    ->where('role_id', 9)
                    ->where('company_id', $companyId)
                    ->get();

        } else {
            $company_Details = (object)[
                'total_balance' => Company::sum('total_balance'),
                'org_dues_balance' => Company::sum('org_dues_balance'),
                'members_welfare_balance' => Company::sum('members_welfare_balance'),
                'service_charge' => Company::sum('service_charge_balance'),
            ];
            // Fetch customers data grouped by date
            $customersData = Customer::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                            ->groupBy('date')
                            ->orderBy('date')
                            ->get();
    
            // Fetch transactions data grouped by date
            $transactionsData = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
                                ->groupBy('date')
                                ->orderBy('date')
                                ->where('status', 'success')
                                ->get();


            $agents = User::where('role_id', 9)
                ->select('name', 'agent_balance')
                ->get();
        }

        return view('voyager::index', [
            'customersData' => $customersData,
            'transactionsData' => $transactionsData,
            'company_Details' => $company_Details,
            'agents' => $agents
        ]);
    }

    public function getAllTransactions(Request $request)
    {
        $filter = $request->get('filter');
        $name = $request->get('name');
        $amount = $request->get('amount');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $user = Auth::user();
        $roleId = $user->role_id;
        $companyId = $user->company_id;
        $comp = Company::where('id',$companyId)->first();
        
        if($roleId == 7){
            $query = Transaction::with('customer')->where('company_id',$comp->company_id)->where('status','success');
            $totalquery = Transaction::with('customer')->where('company_id',$comp->company_id)->where('status','success');

            $totalweek = 0;
            $totaltoday = 0;
            
            $transactionsWeek = Transaction::where('status', 'success')
                ->where('company_id',$comp->company_id)
                ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
                ->get();

            $transactionsToday = Transaction::where('status', 'success')
                ->where('company_id',$comp->company_id)
                ->whereDate('created_at', Carbon::today())
                ->get();

            foreach ($transactionsWeek as $transaction) {
                $company = Company::where('company_id', $transaction->company_id)->first();
                if ($company) {
                    $commission = $company->set_main_commission ?? 0;
                    if($commission > 0){
                        $netAmount = $transaction->amount - ($transaction->amount * $commission / 100);
                    } else {
                        $netAmount = $transaction->amount;
                    }
            
                    $totalweek += $netAmount;
                }
            }
            // Deduct org_dues if applicable
            if ($company->org_dues == 'yes') {
                $orgAmount = $comp->org_dues_amount ?? 0;
                $daysSinceCreation = Carbon::parse($comp->created_at)->diffInDays(Carbon::now());
                $dailyDeduction = ($orgAmount / 365) * 7;
                $totalweek -= $dailyDeduction;
            }
            // Deduct members_welfare if applicable
            // if ($company->members_welfare == 'yes') {
            //     $members_welfareAmount = $comp->members_welfare_amount ?? 0;
            //     $daysSinceCreation = Carbon::parse($comp->created_at)->diffInDays(Carbon::now());
            //     $dailyDeduction = ($members_welfareAmount / 365) * 7;
            //     $totalweek -= $dailyDeduction;
            // }    
            foreach ($transactionsToday as $transaction) {
                $company = Company::where('company_id', $transaction->company_id)->first();
                if ($company) {
                    $commission = $company->set_main_commission ?? 0;
                    if($commission > 0){
                        $netAmount = $transaction->amount - ($transaction->amount * $commission / 100);
                    } else {
                        $netAmount = $transaction->amount;
                    }
            
                    $totaltoday += $netAmount;
                }
            }

            if ($company->org_dues == 'yes') {
                $orgAmount = $comp->org_dues_amount ?? 0;
                $daysSinceCreation = Carbon::parse($comp->created_at)->diffInDays(Carbon::now());
                $dailyDeduction = ($orgAmount / 365);
                $totaltoday -= $dailyDeduction;
            }
            // if ($company->members_welfare == 'yes') {
            //     $members_welfareAmount = $comp->members_welfare_amount ?? 0;
            //     $daysSinceCreation = Carbon::parse($comp->created_at)->diffInDays(Carbon::now());
            //     $dailyDeduction = ($members_welfareAmount / 365);
            //     $totaltoday -= $dailyDeduction;
            // }
            // $totalweek= Transaction::with('customer')->where('company_id',$comp->company_id)->where('status','success')->whereBetween('transactions.datetime', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->sum('amount');
            // $totaltoday= Transaction::with('customer')->where('company_id',$comp->company_id)->where('status','success')->whereDate('transactions.datetime', Carbon::today())->sum('amount');
        } else{
            
            $query = Transaction::with('customer')->where('status','success');
            $totalquery = Transaction::with('customer')->where('status','success');

            $totalweek = 0;
            $totaltoday = 0;

            $transactionsWeek = Transaction::where('status', 'success')
                ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
                ->get();

            $transactionsToday = Transaction::where('status', 'success')
                ->whereDate('created_at', Carbon::today())
                ->get();


            $deductedCompanies = []; // To track companies whose org_dues has already been deducted
            $deductedCompaniess = [];
            foreach ($transactionsWeek as $transaction) {
                $company = Company::where('company_id', $transaction->company_id)->first();
            
                if ($company) {
                    $commission = $company->set_main_commission ?? 0;
            
                    // Apply commission deduction
                    if ($commission > 0) {
                        $netAmount = $transaction->amount - ($transaction->amount * $commission / 100);
                    } else {
                        $netAmount = $transaction->amount;
                    }
            
                    // Deduct org_dues only once per company
                    if ($company->org_dues == 'yes' && !in_array($company->company_id, $deductedCompanies)) {
                        $orgAmount = $company->org_dues_amount ?? 0;
                        $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                        $dailyDeduction = ($orgAmount / 365) * $daysSinceCreation;
            
                        $netAmount -= $dailyDeduction;
            
                        // Mark this company as already deducted
                        $deductedCompanies[] = $company->company_id;
                    }

                    // Deduct org_dues only once per company
                    // if ($company->members_welfare == 'yes' && !in_array($company->company_id, $deductedCompaniess)) {
                    //     $membersWelfareAmount = $company->members_welfare_amount ?? 0;
                    //     $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                    //     $dailyDeduction = ($membersWelfareAmount / 365) * $daysSinceCreation;
            
                    //     $netAmount -= $dailyDeduction;
            
                    //     // Mark this company as already deducted
                    //     $deductedCompaniess[] = $company->company_id;
                    // }
            
                    $totalweek += $netAmount;
                }
            }
                
            $deductedCompaniesToday = []; // Track companies already deducted for today
            $deductedCompaniesTodays = [];
            foreach ($transactionsToday as $transaction) {
                $company = Company::where('company_id', $transaction->company_id)->first();

                if ($company) {
                    $commission = $company->set_main_commission ?? 0;

                    // Apply commission deduction
                    if ($commission > 0) {
                        $netAmount = $transaction->amount - ($transaction->amount * $commission / 100);
                    } else {
                        $netAmount = $transaction->amount;
                    }

                    // Deduct org_dues only once per company
                    if ($company->org_dues == 'yes' && !in_array($company->company_id, $deductedCompaniesToday)) {
                        $orgAmount = $company->org_dues_amount ?? 0;
                        $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                        $dailyDeduction = ($orgAmount / 365) * $daysSinceCreation;

                        $netAmount -= $dailyDeduction;

                        // Mark this company as deducted
                        $deductedCompaniesToday[] = $company->company_id;
                    }

                    // if ($company->members_welfare == 'yes' && !in_array($company->company_id, $deductedCompaniesTodays)) {
                    //     $membersWelfareAmount = $company->members_welfare_amount ?? 0;
                    //     $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                    //     $dailyDeduction = ($membersWelfareAmount / 365) * $daysSinceCreation;

                    //     $netAmount -= $dailyDeduction;

                    //     // Mark this company as deducted
                    //     $deductedCompaniesTodays[] = $company->company_id;
                    // }
                    $totaltoday += $netAmount;
                }
            }
            // $totalweek= Transaction::with('customer')->where('status','success')->whereBetween('transactions.datetime', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->sum('amount');
            // $totaltoday= Transaction::with('customer')->where('status','success')->whereDate('transactions.datetime', Carbon::today())->sum('amount');
        }

        if($roleId == 7){
            $query_for_transactions = Transaction::where('company_id',$comp->company_id)->where('status','success');
        } else {
            $query_for_transactions = Transaction::where('status','success');
        }
        if ($filter) {
            switch ($filter) {
                case 'today':
                    $query_for_transactions->whereDate('transactions.created_at', Carbon::today());
                    break;
        
                case 'last_7_days':
                    $query_for_transactions->whereBetween('transactions.created_at', [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()]);
                    break;
        
                case 'last_30_days':
                    $query_for_transactions->whereBetween('transactions.created_at', [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()]);
                    break;
        
                case 'this_month':
                    $query_for_transactions->whereBetween('transactions.created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);
                    break;
        
                case 'last_month':
                    $query_for_transactions->whereBetween('transactions.created_at', [
                        Carbon::now()->subMonth()->startOfMonth(),
                        Carbon::now()->subMonth()->endOfMonth()
                    ]);
                    break;
        
                case 'custom':
                    if ($startDate && $endDate) {
                        $query_for_transactions->whereBetween('transactions.created_at', [$startDate, $endDate]);
                    }
                    break;
            }
        }
       
        if($roleId == 7){
            $query_for_total_revenue = Transaction::where('company_id',$comp->company_id)->where('status','success')->sum('amount');
        } else {
            $query_for_total_revenue = Transaction::where('status','success')->sum('amount');
        }

        $totalAmountBalance = $query_for_transactions->sum('amount');

        $transactions = $query_for_transactions->get();

        $totalAmount = 0;
        $orgDuesDeducted = [];
        $welfareDeducted = [];
        $totalServiceCharge = 0;

        foreach ($transactions as $transaction) {
            $company = Company::where('company_id', $transaction->company_id)->first();

            if ($company) {
                $commission = $company->set_main_commission ?? 0;
                $netAmount = $transaction->amount - ($transaction->amount * $commission / 100);

                // Deduct org_dues only once per company
                if ($company->org_dues === 'yes' && !in_array($company->company_id, $orgDuesDeducted)) {
                    $orgAmount = $company->org_dues_amount ?? 0;
                    $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                    $dailyDeduction = ($orgAmount / 365) * $daysSinceCreation;
                    $netAmount -= $dailyDeduction;
                    $orgDuesDeducted[] = $company->company_id;
                } elseif ($company->org_dues === 'no' && $company->org_dues_balance > 0 && !in_array($company->company_id, $orgDuesDeducted)) {
                    $orgAmount = $company->org_dues_balance ?? 0;
                    $netAmount -= $orgAmount;
                    $orgDuesDeducted[] = $company->company_id;
                }

                // Deduct members_welfare only once per company
                if ($company->members_welfare === 'yes' && !in_array($company->company_id, $welfareDeducted)) {
                    $welfareAmount = $company->members_welfare_amount ?? 0;
                    $daysSinceCreation = Carbon::parse($company->created_at)->diffInDays(Carbon::now());
                    $dailyDeductionWelfare = ($welfareAmount / 365) * $daysSinceCreation;
                    $netAmount -= $dailyDeductionWelfare;
                    $welfareDeducted[] = $company->company_id;
                } elseif ($company->members_welfare === 'no' && $company->members_welfare_balance > 0 && !in_array($company->company_id, $welfareDeducted)) {
                    $membersWelfareAmount = $company->members_welfare_balance ?? 0;
                    $netAmount -= $membersWelfareAmount;
                    $welfareDeducted[] = $company->company_id;
                }

                // Deduct service charge (percentage of original transaction amount)
                if ($company->service_charge > 0) {
                    $serviceCharge = $transaction->amount * ($company->service_charge / 100);
                    $netAmount -= $serviceCharge;
                    $totalServiceCharge += $serviceCharge;
                }

                $totalAmount += $netAmount;
            }
        }

        $totalTransaction = $totalquery->sum('amount');
        $totalTransactionCount = $query->count();

        $orgDuesCalculated = 0;

        if ($roleId == 7 && $comp && $comp->org_dues === 'yes') {

            $companyCreatedAt = Carbon::parse($comp->created_at)->startOfDay();

            switch ($filter) {
                case 'today':
                    $start = Carbon::today()->startOfDay();
                    $end = Carbon::today()->endOfDay();
                    break;

                case 'last_7_days':
                    $start = Carbon::now()->subDays(6)->startOfDay();
                    $end = Carbon::now()->endOfDay();
                    break;

                case 'last_30_days':
                    $start = Carbon::now()->subDays(29)->startOfDay();
                    $end = Carbon::now()->endOfDay();
                    break;

                case 'this_month':
                    $start = Carbon::now()->startOfMonth();
                    $end = Carbon::now()->endOfMonth();
                    break;

                case 'last_month':
                    $start = Carbon::now()->subMonth()->startOfMonth();
                    $end = Carbon::now()->subMonth()->endOfMonth();
                    break;

                case 'custom':
                    $start = Carbon::parse($startDate)->startOfDay();
                    $end = Carbon::parse($endDate)->endOfDay();
                    break;

                default:
                    // If no filter, fallback to all-time (or today)
                    $start = $companyCreatedAt;
                    $end = Carbon::now()->endOfDay();
                    break;
            }

            // If company created after start date, use that instead
            $actualStart = $companyCreatedAt->greaterThan($start) ? $companyCreatedAt : $start;
            $daysCount = $actualStart->diffInDays($end) + 1;

            $dailyOrgDues = ($comp->org_dues_amount ?? 0) / 365;
            $orgDuesCalculated = round($dailyOrgDues * $daysCount, 2);

        } elseif($comp && $comp->org_dues === 'no' && !empty($comp->org_dues_balance) && $comp->org_dues_balance > 0) {
            $orgDuesCalculated = $comp->org_dues_balance;
        }

        $membersWelfareCalculated = 0;

        if ($roleId == 7 && $comp && $comp->members_welfare === 'yes') {
            $companyCreatedAt = Carbon::parse($comp->created_at)->startOfDay();

            switch ($filter) {
                case 'today':
                    $start = Carbon::today()->startOfDay();
                    $end = Carbon::today()->endOfDay();
                    break;

                case 'last_7_days':
                    $start = Carbon::now()->subDays(6)->startOfDay();
                    $end = Carbon::now()->endOfDay();
                    break;

                case 'last_30_days':
                    $start = Carbon::now()->subDays(29)->startOfDay();
                    $end = Carbon::now()->endOfDay();
                    break;

                case 'this_month':
                    $start = Carbon::now()->startOfMonth();
                    $end = Carbon::now()->endOfMonth();
                    break;

                case 'last_month':
                    $start = Carbon::now()->subMonth()->startOfMonth();
                    $end = Carbon::now()->subMonth()->endOfMonth();
                    break;

                case 'custom':
                    $start = Carbon::parse($startDate)->startOfDay();
                    $end = Carbon::parse($endDate)->endOfDay();
                    break;

                default:
                    $start = $companyCreatedAt;
                    $end = Carbon::now()->endOfDay();
                    break;
            }

            $customers = \App\Models\Customer::where('company_id', $comp->company_id)->get();
            $dailyWelfare = ($comp->members_welfare_amount ?? 0) / 365;

            foreach ($customers as $customer) {
                $customerCreated = Carbon::parse($customer->created_at)->startOfDay();

                if ($customerCreated->lessThan($start)) {
                    $days = $start->diffInDays($end) + 1;
                } else {
                    $days = $customerCreated->diffInDays($end) + 1;
                }

                $membersWelfareCalculated += $dailyWelfare * $days;
            }

            $membersWelfareCalculated = round($membersWelfareCalculated, 2);
        } elseif($comp && $comp->members_welfare === 'no' && $comp->members_welfare_balance > 0) {
            $membersWelfareCalculated = $comp->members_Welfare_balance;
        }

        $user = Customer::query();
        $totaluserquery = Customer::query();
        if($roleId == 7){
            $user = Customer::where('company_id', $companyId);
            $totaluserquery = Customer::where('company_id', $companyId);
            if ($filter) {
                switch ($filter) {
                    case 'today':
                        $user->whereDate('created_at', Carbon::today());
                        break;
            
                    case 'last_7_days':
                        $user->whereBetween('created_at', [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()]);
                        break;
            
                    case 'last_30_days':
                        $user->whereBetween('created_at', [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()]);
                        break;
            
                    case 'this_month':
                        $user->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);
                        break;
            
                    case 'last_month':
                        $user->whereBetween('created_at', [
                            Carbon::now()->subMonth()->startOfMonth(),
                            Carbon::now()->subMonth()->endOfMonth()
                        ]);
                        break;
            
                    case 'custom':
                        if ($startDate && $endDate) {
                            $user->whereBetween('created_at', [$startDate, $endDate]);
                        }
                        break;
                }
            }
            $totalcustomerCount = $totaluserquery->where('company_id', $companyId)->count();
        } else {
            if ($filter) {
                switch ($filter) {
                    case 'today':
                        $user->whereDate('created_at', Carbon::today());
                        break;
            
                    case 'last_7_days':
                        $user->whereBetween('created_at', [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()]);
                        break;
            
                    case 'last_30_days':
                        $user->whereBetween('created_at', [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()]);
                        break;
            
                    case 'this_month':
                        $user->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);
                        break;
            
                    case 'last_month':
                        $user->whereBetween('created_at', [
                            Carbon::now()->subMonth()->startOfMonth(),
                            Carbon::now()->subMonth()->endOfMonth()
                        ]);
                        break;
            
                    case 'custom':
                        if ($startDate && $endDate) {
                            $user->whereBetween('created_at', [$startDate, $endDate]);
                        }
                        break;
                }
            }
            // $totaluserquery = $user->count();
            $totalcustomerCount = $totaluserquery->count();
        }
        $customerCount = $user->count();
        return response()->json([
            'query_for_total_revenue'=>$query_for_total_revenue,
            'total_amount'=>$totalAmount, 
            'total_amount_balance'=>$totalAmountBalance,
            'transaction_count'=>$totalTransactionCount,
            'customer_count'=>$customerCount,
            'total_customers'=> $totalcustomerCount,
            'total_revenue'=>$totalTransaction,
            'customer_count'=> $customerCount,
            'total_week'=> $totalweek,
            'total_today'=> $totaltoday, 
            'orgDuesCalculated'=>$orgDuesCalculated, 
            'totalServiceCharge' => $totalServiceCharge,
            'membersWelfareCalculated' => $membersWelfareCalculated
        ]);
    }
}
