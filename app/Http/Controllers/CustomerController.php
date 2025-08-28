<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Subscription;
use App\Models\Transaction;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class CustomerController extends VoyagerBaseController
{
    public function show(Request $request, $id)
    {
        $customer = Customer::with('transactions')->findOrFail($id);
        $subscriptions = Subscription::where('phone_number',$customer->phone_number)->get();
        // Pass the customer with transactions to the view
        return view('vendor.voyager.customers.read', compact('customer','subscriptions'));
    }


    public function filterTransactions(Request $request)
    {
        $customerId = $request->get('customer_id');
        $filter = $request->get('filter');
        $name = $request->get('name');
        $amount = $request->get('amount');
        
        // Initialize the query
        $customer = Customer::find($customerId);
        $query = Transaction::where('phone_number', $customer->phone_number);
        
        // Date filter
        switch ($filter) {
            case 'today':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'week':
                $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
                break;
            case 'quarter':
                $query->whereBetween('created_at', [Carbon::now()->startOfQuarter(), Carbon::now()->endOfQuarter()]);
                break;
            case 'year':
                $query->whereYear('created_at', Carbon::now()->year);
                break;
        }

        // Name filter
        if ($name) {
            $query->whereHas('customer', function($q) use ($name) {
                $q->where('name', 'like', '%' . $name . '%');
            });
        }

        // Amount filter
        if ($amount) {
            $query->where('amount', $amount);
        }

        // Eager load customer and get results
        $transactions = $query->with('customer')->get();
        
        return view('vendor.voyager.customers.partials.transaction_table', compact('transactions'))->render();
    }


    public function assignCustomer(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $customer = Customer::find($request->customer_id);
        $agent = Auth::user();

        // Check if user is agent and same company
        if ($agent->role_id != 9 || $customer->company_id != $agent->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer->agent_id = $agent->id;
        $customer->save();

        return response()->json(['message' => 'Customer assigned successfully!']);
    }

    
}
