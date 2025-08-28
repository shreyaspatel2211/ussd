<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Plan;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'plan_id',
        'phone_number',
        'recurring_invoice_id',
        'status'
    ];
    protected $dates = ['deleted_at'];

    public function planData()
    {
        
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
