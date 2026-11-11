<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cliente de EkMira. Sus unidades y sus usuarios cuelgan de aquí.
 */
class Company extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    protected $fillable = [
        'name',
        'legal_name',
        'rfc',
        'contact_name',
        'contact_email',
        'contact_phone',
        'plan',
        'monthly_price',
        'status',
        'notes',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
