<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'api';

    /**
     * Roles that must belong to a sales group.
     */
    public const GROUP_REQUIRED_ROLES = ['seller', 'group_leader'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_code',
        'username',
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'status',
        'sales_group_id',
        'printify_shop_id',
        'printify_shop_assigned_by',
        'printify_shop_assigned_at',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'printify_shop_assigned_at' => 'datetime',
        ];
    }

    public function salesGroup(): BelongsTo
    {
        return $this->belongsTo(SalesGroup::class);
    }

    public function printifyShop(): BelongsTo
    {
        return $this->belongsTo(PrintifyShop::class, 'printify_shop_id');
    }

    public function printifyShopAssignedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'printify_shop_assigned_by');
    }
}
