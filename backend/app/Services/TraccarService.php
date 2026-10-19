<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TraccarService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.traccar.base_url'), '/');
        $this->username = config('services.traccar.username');
        $this->password = config('services.traccar.password');
    }

    /**
     * Regresa la lista de dispositivos registrados en Traccar.
     */
    public function getDevices(): array
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->get("{$this->baseUrl}/api/devices");

        if ($response->failed()) {
            Log::error('TraccarService: fallo al obtener dispositivos', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(
                "Error al consultar Traccar: HTTP {$response->status()}"
            );
        }

        return $response->json();
    }

    /**
     * Regresa posiciones de un dispositivo. Si se omiten $from/$to,
     * Traccar regresa únicamente la última posición conocida.
     */
    public function getPositions(int $deviceId, ?string $from = null, ?string $to = null): array
    {
        $query = ['deviceId' => $deviceId];

        if ($from && $to) {
            $query['from'] = $from; // formato ISO 8601, ej. 2026-09-22T00:00:00Z
            $query['to'] = $to;
        }

        $response = Http::withBasicAuth($this->username, $this->password)
            ->get("{$this->baseUrl}/api/positions", $query);

        if ($response->failed()) {
            Log::error('TraccarService: fallo al obtener posiciones', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(
                "Error al consultar posiciones en Traccar: HTTP {$response->status()}"
            );
        }

        return $response->json();
    }

    /**
     * Regresa la última posición conocida de cada dispositivo, todos en una sola llamada.
     * Es lo que usa el mapa en vivo: no guarda nada, solo pregunta qué tiene Traccar ahora.
     *
     * Timeout corto a propósito: si Traccar tarda, el mapa se queda con lo último que tenía
     * y vuelve a preguntar en la siguiente vuelta, en lugar de colgarse esperando.
     */
    public function getLatestPositions(): array
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout(5)
            ->get("{$this->baseUrl}/api/positions");

        if ($response->failed()) {
            Log::warning('TraccarService: fallo al obtener posiciones en vivo', [
                'status' => $response->status(),
            ]);

            throw new \RuntimeException(
                "Error al consultar posiciones en vivo en Traccar: HTTP {$response->status()}"
            );
        }

        return $response->json();
    }
}