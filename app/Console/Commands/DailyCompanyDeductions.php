<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;
use App\Models\Customer;

class DailyCompanyDeductions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:daily-company-deductions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deduct daily members welfare and org dues amounts from companies with positive balance.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Log::info("Company Desuction Cron Start");
        // $companies = Company::get();

        // foreach ($companies as $company) {
        //     $originalBalance = $company->total_balance;

        //     if ($company->org_dues === 'yes' && $company->org_dues_amount > 0) {
        //         $daysSinceCreated = now()->diffInDays($company->created_at);
        //         $dailyOrgDue = $company->org_dues_amount / 365;
        //         $deductAmount = $dailyOrgDue * $daysSinceCreated;

        //         $newBalance = $company->total_balance - $deductAmount;
        //         if ($newBalance < 0) {
        //             $newBalance = 0; // Optional: Prevent negative balances
        //         }

        //         $company->org_dues_balance = $deductAmount;
        //         $company->total_balance = $newBalance;
        //         $company->save();
        //     }

        //     Log::info("Company ID {$company->id}: Updated Balance {$company->total_balance}");
        // }
        // Log::info("Company Desuction Cron End");
        // return Command::SUCCESS;

    }
}
