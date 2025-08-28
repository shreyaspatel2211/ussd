<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use DB;

class UpdateAgentBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-agent-balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update agent_balance for agents based on active customer count';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Running agent balance update...");

        $companies = Company::where('set_agent_commission', 'yes')->get();

        foreach ($companies as $company) {
            $commissionValue = $company->agent_commission_value;

            // Get active customers with agent assigned
            $customers = Customer::where('company_id', $company->id)
                ->where('balance', '>', 0)
                ->whereNotNull('agent_id')
                ->get();

            $grouped = $customers->groupBy('agent_id');

            foreach ($grouped as $agentId => $customerGroup) {
                $count = $customerGroup->count();
                $amount = $count * $commissionValue;

                User::where('id', $agentId)
                    ->where('role_id', 9) // Ensure it's really an agent
                    ->update(['agent_balance' => $amount]);

                $this->info("Updated agent_id $agentId: $count customers * $commissionValue = $amount");
            }
        }

        $this->info("Agent balances updated successfully.");
    }
}
