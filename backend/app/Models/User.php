<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Equipo de EkMira: ve y administra todas las empresas
    public const ROLE_ADMIN = 'admin';
    // Dentro de una empresa
    public const ROLE_OWNER = 'owner';       // ve todo lo suyo y da de alta a sus usuarios
    public const ROLE_OPERATOR = 'operator'; // opera el día: confirma servicios, marca viajes
    public const ROLE_VIEWER = 'viewer';     // solo consulta

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OWNER, self::ROLE_OPERATOR, self::ROLE_VIEWER];
    public const COMPANY_ROLES = [self::ROLE_OWNER, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    // En la sesión del admin: la empresa que está viendo "como cliente"
    public const ACTING_COMPANY_SESSION_KEY = 'acting_company_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Puede cambiar cosas de la operación (confirmar servicios, marcar viajes). Un usuario
     * de solo lectura no.
     */
    public function canEdit(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_OWNER, self::ROLE_OPERATOR], true);
    }

    /**
     * La empresa que un admin está viendo como cliente, o null si ve todo.
     */
    public function actingCompanyId(): ?int
    {
        if (!$this->isAdmin() || !request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get(self::ACTING_COMPANY_SESSION_KEY);

        return $id !== null ? (int) $id : null;
    }

    /**
     * De qué empresa son las unidades que ve: la suya, o la que un admin está viendo.
     * null en un admin significa todas.
     */
    public function visibleCompanyId(): ?int
    {
        return $this->isAdmin() ? $this->actingCompanyId() : $this->company_id;
    }

    /**
     * Consulta de las unidades que este usuario puede ver. Todos los endpoints parten de aquí,
     * así que un usuario nunca recibe datos de una unidad que no es de su empresa.
     *
     * @return Builder<Vehicle>
     */
    public function visibleVehicles(): Builder
    {
        if ($this->isAdmin() && $this->actingCompanyId() === null) {
            return Vehicle::query();
        }

        $companyId = $this->visibleCompanyId();

        // Sin empresa no ve nada (whereRaw evita depender de que no exista un id 0)
        return $companyId === null
            ? Vehicle::query()->whereRaw('1 = 0')
            : Vehicle::where('company_id', $companyId);
    }

    /**
     * Limita una consulta con columna vehicle_id (viajes, servicios, posiciones) a las
     * unidades de este usuario. A un admin que ve todo no le cambia nada.
     *
     * @template TQuery of Builder
     * @param TQuery $query
     * @return TQuery
     */
    public function restrictToVisibleVehicles(Builder $query, string $column = 'vehicle_id'): Builder
    {
        if ($this->isAdmin() && $this->actingCompanyId() === null) {
            return $query;
        }

        return $query->whereIn($column, $this->visibleVehicles()->select('vehicles.id'));
    }

    public function canSeeVehicle(Vehicle $vehicle): bool
    {
        return $this->visibleVehicles()->whereKey($vehicle->id)->exists();
    }
}
