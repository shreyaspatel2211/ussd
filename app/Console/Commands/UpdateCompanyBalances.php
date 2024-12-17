<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    protected $description = 'Update total_balance and total_loan_balance for companies based on customer data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $companies = DB::table('companies')->pluck('id');

        foreach ($companies as $companyId) {
            $totals = DB::table('customers')
                ->where('company_id', $companyId)
                ->selectRaw('SUM(balance) as total_balance, SUM(loan_balance) as total_loan_balance')
                ->first();

            DB::table('companies')
                ->where('id', $companyId)
                ->update([
                    'total_balance' => $totals->total_balance ?? 0,
                    'total_loan_balance' => $totals->total_loan_balance ?? 0,
                ]);
        }

        $this->info('Company balances updated successfully.');
        return 0;
    }
}
