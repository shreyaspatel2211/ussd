<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WithdrawlRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'customer_name',
        'customer_phone_number',
        'amount',
        'company_id',
        'status'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }
}
