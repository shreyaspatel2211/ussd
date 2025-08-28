<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'session_id',
        'phone_number',
        'status',
        'company_id',
        'transaction_id',
        'description',
        'client_reference',
        'amount',
        'name',
        'selected_plan_id',
        'charges',
        'amount_after_charges',
        'amount_charged',
        'delivery_fee',
        'recurring_invoice_id',
        'datetime',
        'otpPrefix',
        'customer_id',
        'selected_plan',
        'request_id'
    ];
    protected $dates = ['deleted_at'];

    public function customer()
    {
        
        return $this->belongsTo(Customer::class, 'phone_number');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customerID()
    {
        return $this->belongsTo(customer::class, 'customer_id');
    }
}
