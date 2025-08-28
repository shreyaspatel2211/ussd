<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;
use App\Models\Product;

class UpdateCompanyBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:company-balances';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the balance of companies that have not been updated in the last 30 minutes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info("company balance Cron Start");

        // Get companies not updated in last 30 minutes
        $companies = Company::where('updated_at', '<', Carbon::now()->subMinutes(30))->get();

        foreach ($companies as $company) {
            $totalAmount = Transaction::where('company_id', $company->company_id)
                                        ->where('status', 'success')
                                        ->sum('amount');


            $mainCommissionAmount = 0;
            if ($company->set_main_commission > 0) {
                $mainCommissionAmount = ($totalAmount * $company->set_main_commission) / 100;
            }
            Log::info("Total Amount is " . $totalAmount . " Main Commission " . $mainCommissionAmount . " for company " . $company->id);
            $netBalance = $totalAmount - $mainCommissionAmount;

            $totalAmountForDues = Transaction::where('company_id', $company->company_id)
                                ->where('status', 'success')
                                ->where('description', 'Dues')
                                ->sum('amount');

            $totalAmountForLoanRepayment = Transaction::where('company_id', $company->company_id)
                                    ->where('status', 'success')
                                    ->where('description', 'LoanRepayment')
                                    ->sum('amount');

            $totalAmountForSusuSavings = Transaction::where('company_id', $company->company_id)
                                    ->where('status', 'success')
                                    ->where('description', 'SusuSavings')
                                    ->sum('amount');

            $productNames = Product::pluck('name')->toArray();
            $totalAmountForProduct = Transaction::where('company_id', $company->company_id)
                                ->where('status', 'success')
                                ->where(function ($query) use ($productNames) {
                                    $query->where('description', 'Product')
                                            ->orWhereIn('description', $productNames);
                                })
                                ->sum('amount');

            $totalAmountForPayFees = Transaction::where('company_id', $company->company_id)
                                ->where('status', 'success')
                                ->where('description', 'PayFees')
                                ->sum('amount');

            // ------------------------------
            // ORG Dues Deduction
            // ------------------------------
            $orgDuesDeduct = 0;
            if ($company->org_dues === 'yes' && $company->org_dues_amount > 0) {
                $daysSinceCreated = now()->diffInDays($company->created_at);
                $dailyOrgDue = $company->org_dues_amount / 365;
                $orgDuesDeduct = $dailyOrgDue * $daysSinceCreated;

                // Apply deduction from net balance
                $netBalance -= $orgDuesDeduct;
                if ($netBalance < 0) {
                    $netBalance = 0;
                }
            } elseif ($company->org_dues === 'no' && $company->org_dues_balance > 0) {
                $netBalance -= $company->org_dues_balance;
                // if($company->company_id == 'unit_1234'){
                //     dd($company->org_dues_balance);
                // }
            }

            // ✅ Get all customers of the company
            $customers = Customer::where('company_id', $company->id)->get();

            $totalWelfareFromCustomers = $customers->sum('members_welfare');
            $totalServiceFromCustomers = $customers->sum('service_charge');

            // ✅ Deduct from net balance
            $netBalance -= ($totalWelfareFromCustomers + $totalServiceFromCustomers);

            // Update company's balance
            $company->update(['total_balance' => $netBalance]);
            $company->update(['main_commission_balance' => $mainCommissionAmount]);
            $company->update(['total_dues_balance' => $totalAmountForDues]);
            $company->update(['total_loan_balance' => $totalAmountForLoanRepayment]);
            $company->update(['total_susu_savings_balance' => $totalAmountForSusuSavings]);
            $company->update(['total_product_balance' => $totalAmountForProduct]);
            $company->update(['total_pay_fees_balance' => $totalAmountForPayFees]);
            $company->update(['members_welfare_balance' => $totalWelfareFromCustomers]);
            $company->update(['service_charge_balance' => $totalServiceFromCustomers]);

            $this->info("Updated balance for company ID: {$company->company_id}");
        }
        Log::info("Company Balance Cron End");
    }
}
