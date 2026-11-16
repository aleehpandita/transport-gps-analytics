<?php

namespace App\Http\Controllers\Api\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descargas de los reportes.
 *
 *   format=csv   Un archivo CSV para Excel (UTF-8 con BOM). Lleva la hoja marcada 'csv'
 *                (o la primera): el detalle, que es lo que sirve para filtrar.
 *   format=xlsx  Las hojas en JSON ({filename, sheets: [{name, headers, rows}]}). El panel
 *                arma el .xlsx en el navegador, así el servidor no necesita otro paquete.
 *
 * Una hoja: ['name' => 'Detalle', 'headers' => [...], 'rows' => fn () => generador de filas, 'csv' => true].
 * Las filas llevan números como números, para que Excel pueda sumarlos.
 */
trait ExportsReports
{
    protected const EXPORT_FORMATS = ['csv', 'xlsx'];
    protected const TIMEZONE = 'America/Cancun';

    /**
     * @param list<array{name: string, headers: list<string>, rows: callable(): iterable<array>, csv?: bool}> $sheets
     */
    protected function export(string $format, string $filename, array $sheets): JsonResponse|StreamedResponse
    {
        if ($format === 'csv') {
            $sheet = collect($sheets)->first(fn ($s) => $s['csv'] ?? false) ?? $sheets[0];

            return $this->csv("{$filename}.csv", $sheet['headers'], $sheet['rows']);
        }

        return response()->json([
            'filename' => "{$filename}.xlsx",
            'sheets' => array_map(fn (array $s) => [
                'name' => $s['name'],
                'headers' => $s['headers'],
                'rows' => iterator_to_array((function () use ($s) {
                    foreach ($s['rows']() as $row) {
                        yield array_values($row);
                    }
                })(), false),
            ], $sheets),
        ]);
    }

    /**
     * CSV para Excel: UTF-8 con BOM (si no, Excel rompe los acentos) y separado por comas.
     * Las filas llegan de un generador, así que se escriben conforme se arman.
     *
     * @param callable(): iterable<array> $rows
     */
    protected function csv(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function periodFilename(string $name, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return "{$name}_{$from->toDateString()}_{$to->toDateString()}";
    }

    protected function localDate($time): string
    {
        return CarbonImmutable::parse($time)->tz(self::TIMEZONE)->toDateString();
    }

    protected function localTime($time): string
    {
        return CarbonImmutable::parse($time)->tz(self::TIMEZONE)->format('H:i');
    }
}
