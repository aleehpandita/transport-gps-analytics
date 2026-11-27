<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\ServiceOrder;
use App\Models\Vehicle;
use App\Services\OperationClassifier;
use App\Services\OperationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * operations:evaluate mide al detector SIN confirmaciones; --con-confirmaciones compara
 * contra lo que ve el panel. Caso del 24 nov (Coral Beach): la unidad va vacía del aeropuerto
 * al hotel (el GPS lo ve como llegada) y regresa con los pasajeros (el GPS lo ve como regreso);
 * en el panel la llegada se marcó "Sin pasajeros" y el regreso "Servicio".
 */
class EvaluateOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected FakeOperationService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $vehicle = Vehicle::create(['name' => 'Kevs1 66RE9U', 'imei' => '350000000000001', 'traccar_device_id' => 1]);
        $hotel = Place::create(['name' => 'Coral lobby', 'type' => 'hotel', 'latitude' => 21.13548, 'longitude' => -86.74527]);
        ServiceOrder::create([
            'vehicle_id' => $vehicle->id, 'service_date' => '2026-11-24', 'scheduled_time' => '12:10',
            'type' => ServiceOrder::AUTO, 'place_id' => $hotel->id, 'place_text' => 'Coral lobby', 'source' => 'csv',
        ]);

        $this->fake = new FakeOperationService($vehicle->id, $hotel->id);
        $this->app->instance(OperationService::class, $this->fake);
    }

    public function test_by_default_it_measures_the_detector_without_reviews(): void
    {
        // Sin confirmaciones se liga al regreso que vio el GPS, todavía por confirmar
        $this->artisan('operations:evaluate', ['--from' => '2026-11-24', '--to' => '2026-11-24', '--details' => true])
            ->doesntExpectOutputToContain('Con las confirmaciones del panel')
            ->expectsOutputToContain('[el GPS lo vio como regreso; la hora de la hoja dice salida], 12:23, Coral lobby, +13 min, por_confirmar)')
            ->expectsOutputToContain('0 de 1 (0%)  correctos y sin nada que confirmar')
            ->assertSuccessful();

        $this->assertNotEmpty($this->fake->calls);
        $this->assertNotContains(true, $this->fake->calls);
    }

    public function test_with_reviews_it_follows_the_panel(): void
    {
        $this->artisan('operations:evaluate', ['--from' => '2026-11-24', '--to' => '2026-11-24', '--con-confirmaciones' => true, '--details' => true])
            ->expectsOutputToContain('Con las confirmaciones del panel')
            // La salida marcada a mano es la que se liga a la orden
            ->expectsOutputToContain('correcto (GPS: fue salida, 12:23, Coral lobby, +13 min, confirmado)')
            // La llegada marcada "Sin pasajeros" ya no sale como inventada
            ->expectsOutputToContain('Inventados:              0 (0 probables, 0 por confirmar)')
            ->assertSuccessful();

        $this->assertNotContains(false, $this->fake->calls);
    }
}

/**
 * Devuelve lo mismo que OperationService para ese día: sin confirmaciones, la llegada vacía
 * es un servicio por confirmar y el regreso solo es un regreso; con confirmaciones, la llegada
 * queda "Sin pasajeros" y el regreso pasa a servicio (salida por la dirección).
 */
class FakeOperationService extends OperationService
{
    /** @var list<bool> */
    public array $calls = [];

    public function __construct(protected int $vehicleId, protected int $placeId)
    {
    }

    public function forDay(string $date, Collection $vehicles, bool $withReviews = true): array
    {
        $this->calls[] = $withReviews;
        if ($date !== '2026-11-24') {
            return ['date' => $date, 'summary' => [], 'services' => [], 'legs' => [], 'unnamed' => []];
        }

        $at = fn (string $hm) => CarbonImmutable::parse("2026-11-24 {$hm}", 'America/Cancun')->toIso8601String();
        $empty = $withReviews ? ['status' => OperationClassifier::REVIEW_EMPTY] : null;
        $service = $withReviews ? ['status' => OperationClassifier::REVIEW_SERVICE] : null;

        $arrival = [
            'trip_id' => 1, 'vehicle_id' => $this->vehicleId, 'type' => 'llegada',
            'status' => $withReviews ? 'confirmado' : 'por_confirmar', 'review' => $empty,
            'hotel' => 'Coral lobby', 'place_id' => $this->placeId, 'other_hotel' => null,
            'hotel_latitude' => 21.13548, 'hotel_longitude' => -86.74527,
            'left_at' => $at('11:52'), 'arrived_at' => $at('12:19'),
        ];
        $departure = [
            'trip_id' => 2, 'vehicle_id' => $this->vehicleId, 'type' => 'salida',
            'status' => 'confirmado', 'review' => $service,
            'hotel' => 'Coral lobby', 'place_id' => $this->placeId, 'other_hotel' => null,
            'hotel_latitude' => 21.13548, 'hotel_longitude' => -86.74527,
            'left_at' => $at('12:23'), 'arrived_at' => $at('12:50'),
        ];

        return [
            'date' => $date,
            'summary' => [],
            'services' => $withReviews ? [$arrival, $departure] : [$arrival],
            'legs' => [[
                'trip_id' => 2, 'vehicle_id' => $this->vehicleId, 'kind' => OperationClassifier::RETURN,
                'review' => $service, 'started_at' => $at('12:23'), 'origin' => 'Coral lobby',
                'origin_place_id' => $this->placeId, 'origin_latitude' => 21.13548, 'origin_longitude' => -86.74527,
            ]],
            'unnamed' => [],
        ];
    }
}
