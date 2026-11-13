<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\AlertRule;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de un aviso. Sale de alertas@ekmira.com y las respuestas van a contacto@.
 */
class AlertMail extends Mailable
{
    public function __construct(public Alert $alert)
    {
    }

    public function envelope(): Envelope
    {
        $label = AlertRule::LABELS[$this->alert->type] ?? 'Alerta';
        $vehicle = $this->alert->vehicle?->name ?? 'Unidad';

        return new Envelope(subject: "{$label} · {$vehicle}");
    }

    public function content(): Content
    {
        $local = $this->alert->triggered_at->copy()->timezone(AlertRule::TIMEZONE);

        return new Content(
            view: 'mail.alert',
            text: 'mail.alert-text',
            with: [
                'label' => AlertRule::LABELS[$this->alert->type] ?? 'Alerta',
                'ruleName' => $this->alert->rule?->name,
                'when' => $local->format('d/m/Y H:i'),
                'mapUrl' => sprintf('https://www.google.com/maps?q=%.6f,%.6f', $this->alert->latitude, $this->alert->longitude),
                'panelUrl' => rtrim(config('app.url'), '/') . '/#/alertas',
            ],
        );
    }
}
