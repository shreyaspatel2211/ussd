<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Plan;

class Subscription extends Model
{
    use HasFactory;
    protected $fillable = [
        'plan_id',
        'phone_number',
        'recurring_invoice_id',
        'status'
    ];
    public function planData()
    {
        
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
