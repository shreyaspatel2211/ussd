<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Session extends Model
{
    use HasFactory;
    use SoftDeletes;

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
        'otp_prefix',
        'transaction_id',
        'description',
        'client_reference',
        'amount',
        'charges',
        'amount_after_charges',
        'amount_charged',
        'delivery_fee',
        'phone_number'
    ];
    protected $dates = ['deleted_at'];
}
