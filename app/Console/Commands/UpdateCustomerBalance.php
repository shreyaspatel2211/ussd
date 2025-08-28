<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\Product;

class UpdateCustomerBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:customer-balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the balance of customers who have not been updated in the last 30 minutes';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        Log::info("Cron Start");
        // Get customers not updated in last 30 minutes
        $customers = Customer::where('updated_at', '<', Carbon::now()->subMinutes(30))->get();
        $companyDeductions = [];

        foreach ($customers as $customer) {
            // Calculate total successful transaction amount for this customer
            $totalAmount = Transaction::where('phone_number', $customer->phone_number)
                ->where('status', 'success')
                ->sum('amount');

            $totalAmountForDues = Transaction::where('phone_number', $customer->phone_number)
                                            ->where('status', 'success')
                                            ->where('description', 'Dues')
                                            ->sum('amount');

            $totalAmountForLoanRepayment = Transaction::where('phone_number', $customer->phone_number)
                                                    ->where('status', 'success')
                                                    ->where('description', 'LoanRepayment')
                                                    ->sum('amount');

            $totalAmountForSusuSavings = Transaction::where('phone_number', $customer->phone_number)
                                                    ->where('status', 'success')
                                                    ->where('description', 'SusuSavings')
                                                    ->sum('amount');

            $productNames = Product::pluck('name')->toArray();
            $totalAmountForProduct = Transaction::where('phone_number', $customer->phone_number)
                                                ->where('status', 'success')
                                                ->where(function ($query) use ($productNames) {
                                                    $query->where('description', 'Product')
                                                          ->orWhereIn('description', $productNames);
                                                })
                                                ->sum('amount');

            $totalAmountForPayFees = Transaction::where('phone_number', $customer->phone_number)
                                ->where('status', 'success')
                                ->where('description', 'PayFees')
                                ->sum('amount');

            // ------------------------------
            // Members Welfare Deduction
            // ------------------------------
            $company = Company::find($customer->company_id);
            $membersWelfareDeduct = 0;
            if ($company && $company->members_welfare === 'yes' && $company->members_welfare_amount > 0) {
                $dailyWelfare = $company->members_welfare_amount / 365;
                $daysSinceCreated = now()->diffInDays($customer->created_at);
                $membersWelfareDeduct = $dailyWelfare * $daysSinceCreated;
            }

            // ------------------------------
            // Service Charge Deduction
            // ------------------------------
            $serviceChargeDeduct = 0;

            if ($company && $company->service_charge > 0 && $company->show_service_charge == 'yes') {
                $customerTransactionTotal = Transaction::where('company_id', $company->company_id)
                    ->where('phone_number', $customer->phone_number)
                    ->where('status', 'success')
                    ->sum('amount');

                if ($customerTransactionTotal > 0) {
                    $serviceChargeDeduct = ($customerTransactionTotal * $company->service_charge) / 100;
                }
            }

            // ------------------------------
            // Final Balance Update
            // ------------------------------
            $finalBalance = $totalAmount - ($membersWelfareDeduct + $serviceChargeDeduct);
            $finalBalance = max($finalBalance, 0); // prevent negative balance
            
            // Update customer's balance
            $customer->update(['balance' => $finalBalance]);
            $customer->update(['dues_balance' => $totalAmountForDues]);
            $customer->update(['loan_balance' => $totalAmountForLoanRepayment]);
            $customer->update(['susu_savings_balance' => $totalAmountForSusuSavings]);
            $customer->update(['product_balance' => $totalAmountForProduct]);
            $customer->update(['pay_fees_balance' => $totalAmountForPayFees]);
            $customer->update(['members_welfare' => $membersWelfareDeduct]);
            $customer->update(['service_charge' => $serviceChargeDeduct]);

            // ------------------------------
            // Company Balances Update
            // ------------------------------
            if ($company) {
                if (!isset($companyDeductions[$company->id])) {
                    $companyDeductions[$company->id] = [
                        'welfare' => 0,
                        'service' => 0,
                    ];
                }

                $companyDeductions[$company->id]['welfare'] += $membersWelfareDeduct;
                $companyDeductions[$company->id]['service'] += $serviceChargeDeduct;
            }

            if (!empty($totalAmountForLoanRepayment)) {
                $loan = Loan::where('customer_id', $customer->id)->first();
    
                if ($loan) {
                    $loan->total_payment += $totalAmountForLoanRepayment;
                    $loan->remaining_payment -= $totalAmountForLoanRepayment;
                    $loan->save();
    
                    $this->info("Updated loan for customer: {$customer->phone_number}");
                }
            }
            
            $this->info("Updated balance for customer: {$customer->phone_number}");
        }

        // foreach ($companyDeductions as $companyId => $amounts) {
        //     $company = Company::find($companyId);

        //     if ($company) {
        //         $final_balance = $company->total_balance;
        //         $company->members_welfare_balance = $amounts['welfare'];
        //         $company->service_charge_balance = $amounts['service'];
        //         $company->total_balance = $final_balance - $amounts['welfare'] - $amounts['service'];
        //         $company->save();

        //         Log::info("Updated company ID {$companyId}: welfare +{$amounts['welfare']}, service +{$amounts['service']}");
        //     }
        // }

        Log::info("Cron End");
    }
}
