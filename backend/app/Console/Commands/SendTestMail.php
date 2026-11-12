<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda un correo de prueba para revisar que el envío funciona.
 *
 *   php artisan mail:test tu@correo.com
 *
 * Mientras Amazon SES esté en modo de prueba (sandbox) solo llega a direcciones
 * verificadas en SES.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : Correo que recibe la prueba}';

    protected $description = 'Manda un correo de prueba con la configuración actual';

    public function handle(): int
    {
        $to = $this->argument('to');
        $mailer = config('mail.default');
        $from = config('mail.from.address');

        $this->line("Enviando con '{$mailer}' desde {$from} a {$to}...");

        try {
            Mail::raw(
                "Hola:\n\nEste es un correo de prueba de EkMira, enviado el " . now()->timezone('America/Cancun')->format('d/m/Y H:i')
                . " (hora de Cancún).\n\nSi lo recibiste, los avisos por correo ya pueden salir.\n\nEkMira",
                fn ($message) => $message->to($to)->subject('Prueba de correo de EkMira'),
            );
        } catch (Throwable $e) {
            $this->error('No se pudo enviar: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Enviado. Revisa la bandeja de entrada (y Spam la primera vez).');

        return self::SUCCESS;
    }
}
