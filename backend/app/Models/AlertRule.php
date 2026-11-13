<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una alerta configurada por el cliente: qué vigilar, en qué unidades, cuándo y a quién avisar.
 */
class AlertRule extends Model
{
    public const OVERSPEED = 'overspeed';
    public const GEOFENCE_EXIT = 'geofence_exit';
    public const GEOFENCE_ENTER = 'geofence_enter';
    public const LONG_STOP = 'long_stop';
    public const SIGNAL_LOST = 'signal_lost';
    public const AFTER_HOURS = 'after_hours';

    public const TYPES = [
        self::OVERSPEED,
        self::GEOFENCE_EXIT,
        self::GEOFENCE_ENTER,
        self::LONG_STOP,
        self::SIGNAL_LOST,
        self::AFTER_HOURS,
    ];

    public const LABELS = [
        self::OVERSPEED => 'Exceso de velocidad',
        self::GEOFENCE_EXIT => 'Sale de geocerca',
        self::GEOFENCE_ENTER => 'Entra a geocerca',
        self::LONG_STOP => 'Parada larga',
        self::SIGNAL_LOST => 'Sin señal',
        self::AFTER_HOURS => 'Movimiento fuera de horario',
    ];

    // Opciones de "no repetir antes de", en minutos
    public const REPEAT_OPTIONS = [0, 15, 30, 60, 180, 1440];

    // Los horarios de las reglas son de Cancún
    public const TIMEZONE = 'America/Cancun';

    protected $fillable = [
        'company_id', 'name', 'type', 'settings', 'vehicle_ids', 'days',
        'time_from', 'time_to', 'repeat_minutes', 'emails', 'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'vehicle_ids' => 'array',
            'days' => 'array',
            'emails' => 'array',
            'repeat_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function appliesTo(int $vehicleId): bool
    {
        return $this->vehicle_ids === null || $this->vehicle_ids === [] || in_array($vehicleId, $this->vehicle_ids, true);
    }

    /**
     * ¿La regla está vigente en ese momento? Revisa el día y el horario en hora de Cancún.
     * Un horario que cruza la medianoche (22:00 a 05:00) pertenece al día en que empieza:
     * el lunes de 22:00 a 05:00 incluye la madrugada del martes.
     */
    public function isActiveAt(int $timestamp): bool
    {
        $local = CarbonImmutable::createFromTimestamp($timestamp, self::TIMEZONE);
        $days = array_map('intval', $this->days ?? []);
        $day = $local->dayOfWeekIso;

        if (!$this->time_from || !$this->time_to) {
            return in_array($day, $days, true);
        }

        $now = $local->format('H:i');
        $from = $this->time_from;
        $to = $this->time_to;

        if ($from < $to) {
            return in_array($day, $days, true) && $now >= $from && $now < $to;
        }

        // Cruza la medianoche: la noche del día de hoy o la madrugada que viene de ayer
        $yesterday = $day === 1 ? 7 : $day - 1;

        return ($now >= $from && in_array($day, $days, true))
            || ($now < $to && in_array($yesterday, $days, true));
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'settings' => (object) ($this->settings ?? []),
            'vehicle_ids' => $this->vehicle_ids,
            'days' => array_map('intval', $this->days ?? []),
            'time_from' => $this->time_from,
            'time_to' => $this->time_to,
            'repeat_minutes' => $this->repeat_minutes,
            'emails' => $this->emails ?? [],
            'active' => $this->active,
        ];
    }
}
