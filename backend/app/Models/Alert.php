<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso que salió: aparece en la bandeja de la empresa y, si la regla tiene correos,
 * también se manda por correo.
 */
class Alert extends Model
{
    public const EMAIL_SENT = 'sent';
    public const EMAIL_FAILED = 'failed';
    public const EMAIL_SKIPPED = 'skipped';

    protected $fillable = [
        'company_id', 'alert_rule_id', 'vehicle_id', 'type', 'triggered_at',
        'latitude', 'longitude', 'value', 'message', 'data', 'read_at', 'read_by', 'email_status',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'read_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'value' => 'float',
            'data' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => AlertRule::LABELS[$this->type] ?? $this->type,
            'rule' => $this->rule ? ['id' => $this->rule->id, 'name' => $this->rule->name] : null,
            'vehicle' => $this->vehicle ? ['id' => $this->vehicle->id, 'name' => $this->vehicle->name] : null,
            'triggered_at' => $this->triggered_at?->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'value' => $this->value,
            'message' => $this->message,
            'data' => (object) ($this->data ?? []),
            'read_at' => $this->read_at?->toIso8601String(),
            'email_status' => $this->email_status,
        ];
    }
}
