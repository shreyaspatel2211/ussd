<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CallbackRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = "callback_requests";
    protected $fillable = [
        'request',
    ];
    protected $dates = ['deleted_at'];
}
