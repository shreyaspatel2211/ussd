<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    use HasFactory;
    protected $table = 'sessions';

    protected $fillable = [
        'request_json',
        'response_json',
        'session_id',
        'mobile',
        'sequence',
        'message',
        'internal_number',
        'casetype',
        'selected_plan_id',
        'payment_system',
        'package_selection',
        'packages_start_index',
        'request_id',
        'recurring_invoice_id',
        'otpPrefix'
    ];
}
