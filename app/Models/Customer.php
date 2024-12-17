<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'phone_number',
        'balance',
        'pin',
        'plan_id',
        'operator_channel',
        'reset_pin',
        'company_id'
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'phone_number');
    }
}
