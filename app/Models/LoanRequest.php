<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'customer_name',
        'customer_phone_number',
        'amount',
        'company_id',
        'status',
        'loan_product_id'
    ];
    protected $dates = ['deleted_at'];
}
