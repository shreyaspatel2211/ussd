<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Wave\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Company;

class User extends Authenticatable
{
    use Notifiable;
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'verification_code',
        'verified',
        'trial_ends_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function scopeAgentsOnly($query)
    {
        return $query->where('role_id', 9);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'agent_id', 'id');
    }
}
