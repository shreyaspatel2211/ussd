<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'phone_number',
        'balance',
        'pin',
        'plan_id',
        'operator_channel',
        'reset_pin',
        'company_id',
        'dues_balance',
        'loan_balance',
        'susu_savings_balance',
        'product_balance',
        'pay_fees_balance',
        'members_welfare',
        'service_charge'
    ];
    protected $dates = ['deleted_at'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'phone_number');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
