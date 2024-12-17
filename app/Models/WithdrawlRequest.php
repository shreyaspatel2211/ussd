<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawlRequest extends Model
{
    use HasFactory;
    protected $fillable = [
        'customer_name',
        'customer_phone_number',
        'amount'
    ];
}
