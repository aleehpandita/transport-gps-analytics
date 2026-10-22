<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_CLIENT = 'client';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_CLIENT];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
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
        ];
    }

    /**
     * Unidades asignadas. Solo aplica a clientes: un admin ve todas sin necesidad de asignarlas.
     */
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Consulta de las unidades que este usuario puede ver. Todos los endpoints parten de aquí,
     * así que un cliente nunca recibe datos de una unidad que no es suya.
     *
     * @return Builder<Vehicle>
     */
    public function visibleVehicles(): Builder
    {
        return $this->isAdmin()
            ? Vehicle::query()
            : Vehicle::whereIn('id', $this->vehicles()->pluck('vehicles.id'));
    }

    /**
     * Limita una consulta con columna vehicle_id (viajes, servicios, posiciones) a las
     * unidades de este usuario. A un admin no le cambia nada.
     *
     * @template TQuery of Builder
     * @param TQuery $query
     * @return TQuery
     */
    public function restrictToVisibleVehicles(Builder $query, string $column = 'vehicle_id'): Builder
    {
        return $this->isAdmin()
            ? $query
            : $query->whereIn($column, $this->vehicles()->pluck('vehicles.id'));
    }

    public function canSeeVehicle(Vehicle $vehicle): bool
    {
        return $this->isAdmin() || $this->vehicles()->whereKey($vehicle->id)->exists();
    }
}