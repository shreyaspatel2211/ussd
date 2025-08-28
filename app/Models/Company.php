<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'category',
        'location',
        'contact_person',
        'phone_number',
        'business_email',
        'password',
        'logo',
        'payment_key',
        'sms_key',
        'smp_keys',
        'callback_url',
        'data_monk_key',
        'set_main_commission',
        'set_agent_commission',
        'service_code',
        'opening_time',
        'closing_time',
        'company_id',
        'total_balance',
        'total_loan_balance',
        'ussd_menu',
        'pos_sales_id',
        'api_key',
        'api_id',
        'total_dues_balance',
        'total_susu_savings_balance',
        'total_product_balance',
        'total_pay_fees_balance',
        'org_dues_amount',
        'members_welfare_amount',
        'org_dues',
        'service_charge',
        'members_welfare',
        'org_dues_balance',
        'members_welfare_balance',
        'main_commission_balance',
        'service_charge_balance',
        'business_encryption_id'
    ];
    protected $dates = ['deleted_at'];
}
