<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Customer;
use App\Models\Company;
use Carbon\Carbon;
use App\Models\WithdrawlRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class APIController extends Controller
{
    public function getTransactionSummary(Request $request)
    {
        $filter = $request->get('filter');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $datamonkKey = $request->get('user_id');

        $company = Company::where('data_monk_key', $datamonkKey)->first();

        if (!$company) {
            return response()->json(['error' => 'Company not found.'], 404);
        }

        $companyIdForTransactions = $company->company_id; 
        $companyIdForCustomers = $company->id;

        $query = Transaction::with('customer')
            ->where('company_id', $companyIdForTransactions)
            ->where('status', 'success');

        $totalquery = Transaction::with('customer')
            ->where('company_id', $companyIdForTransactions)
            ->where('status', 'success');

        $totalweek = Transaction::where('company_id', $companyIdForTransactions)
            ->where('status', 'success')
            ->whereBetween('transactions.datetime', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->sum('amount');

        $totaltoday = Transaction::where('company_id', $companyIdForTransactions)
            ->where('status', 'success')
            ->whereDate('transactions.datetime', Carbon::today())
            ->sum('amount');

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
                case 'custom':
                    if ($startDate && $endDate) {
                        $query->whereBetween('transactions.datetime', [$startDate, $endDate]);
                    }
                    break;
            }
        }

        $totalTransaction = $totalquery->sum('amount');
        $totalTransactionCount = $query->count();

        $userQuery = Customer::where('company_id', $companyIdForCustomers);
        $totalUserQuery = Customer::where('company_id', $companyIdForCustomers);

        if ($filter) {
            switch ($filter) {
                case 'today':
                    $userQuery->whereDate('created_at', Carbon::today());
                    break;
                case 'week':
                    $userQuery->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                    break;
                case 'month':
                    $userQuery->whereMonth('created_at', Carbon::now()->month);
                    break;
                case 'quarter':
                    $userQuery->whereBetween('created_at', [Carbon::now()->firstOfQuarter(), Carbon::now()->lastOfQuarter()]);
                    break;
                case 'year':
                    $userQuery->whereYear('created_at', Carbon::now()->year);
                    break;
                case 'custom':
                    if ($startDate && $endDate) {
                        $userQuery->whereBetween('created_at', [$startDate, $endDate]);
                    }
                    break;
            }
        }

        $customerCount = $userQuery->count();
        $totalCustomerCount = $totalUserQuery->count();

        return response()->json([
            'transaction_count' => $totalTransactionCount,
            'customer_count' => $customerCount,
            'total_customers' => $totalCustomerCount,
            'total_revenue' => $totalTransaction,
            'total_for_this_week' => $totalweek,
            'total_for_today' => $totaltoday,
        ]);
    }

    // get customers, transactions and withdrawls
    public function getCustomers(Request $request)
    {
        $datamonkKey = $request->get('user_id');

        // ✅ Fetch company directly using datamonk_key
        $company = Company::where('data_monk_key', $datamonkKey)->first();

        if (!$company) {
            return response()->json(['error' => 'Company not found.'], 404);
        }

        $companyIdForCustomers = $company->id;             // used in customers table
        $companyIdForTransactions = $company->company_id;  // used in transactions/withdrawls

        // ✅ Prepare queries based on company IDs
        $query = Customer::where('company_id', $companyIdForCustomers)->whereNull('deleted_at');
        $transactionQuery = Transaction::where('company_id', $companyIdForTransactions)->whereNull('deleted_at');
        $withdrawlQuery = WithdrawlRequest::where('company_id', $companyIdForTransactions)->whereNull('deleted_at');

        $customers = $query->get();
        $transactions = $transactionQuery->get();
        $withdrawls = $withdrawlQuery->get();

        // ✅ First, login to external API and get token
        $loginResponse = Http::post('https://app.atdamss.com/api/login', [
            'username' => env('MAIN_DB_USERNAME'),
            'password' => env('MAIN_DB_PASSWORD'),
        ]);

        if ($loginResponse->failed()) {
            return response()->json(['error' => 'Failed to login and fetch token.'], 500);
        }

        $loginData = $loginResponse->json();
        $token = $loginData['token'];

        // ✅ Call the external API
        $apiResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(10)->get('https://app.atdamss.com/api/members');

        if (!$apiResponse->successful()) {
            return response()->json([
                'error' => 'Failed to fetch members data.',
                'status' => $apiResponse->status(),
                'body' => $apiResponse->body()
            ], 500);
        }

        $members = $apiResponse->json();
        $membersList = $members['data']['members'];

        // ✅ Match members with customers by phone number
        foreach ($customers as $customer) {
            $matchedMember = collect($membersList)->first(function ($member) use ($customer) {
                return $member['phone_no'] == $customer->phone_number;
            });

            if ($matchedMember) {
                $customer->branch_name = $matchedMember['level_data_name'];
                $customer->id_number = $matchedMember['id_card_number'];
            } else {
                $customer->branch_name = null;
                $customer->id_number = null;
            }

            $customer->makeHidden('pin'); // hide sensitive field
        }

        return response()->json([
            'customers' => $customers,
            'transactions' => $transactions,
            'withdrawls' => $withdrawls
        ]);
    }


    // generate report with customers, transactions and withdrawls
    public function generateReport(Request $request)
    {
        $datamonkKey = $request->get('user_id');

        // ✅ Fetch company using datamonk_key
        $company = Company::where('data_monk_key', $datamonkKey)->first();

        if (!$company) {
            return response()->json(['error' => 'Company not found.'], 404);
        }

        $companyIdForCustomers = $company->id;             // internal id used in customers table
        $companyIdForTransactions = $company->company_id;  // external id used in transactions/withdrawals

        // ✅ Prepare queries
        $query = Customer::with('company')
            ->where('company_id', $companyIdForCustomers)
            ->whereNull('deleted_at');

        $transactionQuery = Transaction::with(['company', 'customerID'])
            ->where('company_id', $companyIdForTransactions)
            ->whereNull('deleted_at');

        $withdrawlQuery = WithdrawlRequest::with('company')
            ->where('company_id', $companyIdForTransactions)
            ->whereNull('deleted_at');

        $customers = $query->get();
        $transactions = $transactionQuery->get();
        $withdrawls = $withdrawlQuery->get();

        // ✅ Login to external API to fetch token
        $loginResponse = Http::post('https://app.atdamss.com/api/login', [
            'username' => env('MAIN_DB_USERNAME'),
            'password' => env('MAIN_DB_PASSWORD'),
        ]);

        if ($loginResponse->failed()) {
            return response()->json(['error' => 'Failed to login and fetch token.'], 500);
        }

        $loginData = $loginResponse->json();
        $token = $loginData['token'];

        // ✅ Fetch members from external API
        $apiResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(10)->get('https://app.atdamss.com/api/members');

        if (!$apiResponse->successful()) {
            return response()->json([
                'error' => 'Failed to fetch members data.',
                'status' => $apiResponse->status(),
                'body' => $apiResponse->body()
            ], 500);
        }

        $members = $apiResponse->json();
        $membersList = $members['data']['members'];

        // ✅ Match members with customers by phone number
        foreach ($customers as $customer) {
            $matchedMember = collect($membersList)->first(function ($member) use ($customer) {
                return $member['phone_no'] == $customer->phone_number;
            });

            if ($matchedMember) {
                $customer->branch_name = $matchedMember['level_data_name'];
                $customer->id_number = $matchedMember['id_card_number'];
            } else {
                $customer->branch_name = null;
                $customer->id_number = null;
            }
        }

        // ✅ Generate PDF
        $pdf = Pdf::loadView('pdf.customer_report', [
            'customers' => $customers,
            'transactions' => $transactions,
            'withdrawls' => $withdrawls
        ]);

        $fileName = 'customer_report_' . time() . '.pdf';
        $filePath = 'reports/' . $fileName;

        Storage::disk('public')->put($filePath, $pdf->output());
        $pdfUrl = asset('storage/' . $filePath);

        return response()->json([
            'pdf_link' => $pdfUrl
        ]);
    }


}
