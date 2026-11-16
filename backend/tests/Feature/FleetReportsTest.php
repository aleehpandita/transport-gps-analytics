<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Company;
use App\Models\Geofence;
use App\Models\GeofenceVisit;
use App\Models\Position;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\GeofenceVisitTracker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reportes de EkMira BASE: entradas y salidas de geocercas (con su cálculo y el recálculo
 * hacia atrás), distancia por unidad y día, alertas por unidad y tipo, y las descargas.
 */
class FleetReportsTest extends TestCase
{
    use RefreshDatabase;

    // Miércoles 11 nov 2026, 10:00 en Cancún
    protected CarbonImmutable $base;
    protected Company $kevs;
    protected Company $other;
    protected Vehicle $van;
    protected Vehicle $van2;
    protected Vehicle $foreignVan;
    protected Geofence $baseFence;
    protected Geofence $hotel;

    protected const IN = [21.1500, -86.8500];
    protected const OUT = [21.1680, -86.8500];
    protected const HOTEL = [21.1000, -86.7700];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        config(['sanctum.stateful' => ['localhost']]);

        $this->base = CarbonImmutable::parse('2026-11-11 15:00:00', 'UTC');
        $this->kevs = Company::create(['name' => 'Kevs Trips']);
        $this->other = Company::create(['name' => 'Otra Transportadora']);
        $this->van = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs1', 'imei' => '111111111111111', 'traccar_device_id' => 1, 'active' => true]);
        $this->van2 = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs2', 'imei' => '222222222222222', 'traccar_device_id' => 2, 'active' => true]);
        $this->foreignVan = Vehicle::create(['company_id' => $this->other->id, 'name' => 'Ajena', 'imei' => '333333333333333', 'traccar_device_id' => 3, 'active' => true]);

        $this->baseFence = Geofence::create([
            'company_id' => $this->kevs->id, 'name' => 'Base', 'shape' => 'circle',
            'latitude' => self::IN[0], 'longitude' => self::IN[1], 'radius_m' => 300,
        ]);
        $this->hotel = Geofence::create([
            'company_id' => $this->kevs->id, 'name' => 'Hotel', 'shape' => 'polygon',
            'latitude' => self::HOTEL[0], 'longitude' => self::HOTEL[1],
            'points' => [[21.099, -86.771], [21.099, -86.769], [21.101, -86.769], [21.101, -86.771]],
        ]);

        // Antes de esta prueba no hay posiciones: el cálculo empieza en la base de la prueba
        $this->travelTo($this->base->addDay());
    }

    // ------------------------------------------------------------------ ayudantes

    protected function at(int $seconds, array $point, float $speed = 30, ?Vehicle $vehicle = null): void
    {
        Position::create([
            'vehicle_id' => ($vehicle ?? $this->van)->id,
            'latitude' => $point[0],
            'longitude' => $point[1],
            'speed' => $speed,
            'valid' => true,
            'device_time' => $this->base->addSeconds($seconds),
        ]);
    }

    protected function track(?Vehicle $vehicle = null): int
    {
        return app(GeofenceVisitTracker::class)->process(
            $vehicle ?? $this->van,
            Geofence::where('company_id', $this->kevs->id)->get(),
            now(),
            $this->base->subMinute(),
        );
    }

    /** Un día con salida de la base, visita al hotel y regreso */
    protected function dayTrip(): void
    {
        $this->at(0, self::IN, 0);
        $this->at(60, self::IN, 5);
        $this->at(120, self::OUT, 40);    // una sola afuera: ruido
        $this->at(130, self::IN, 5);
        $this->at(600, self::OUT, 40);    // salió de la base a las 10:10
        $this->at(660, self::OUT, 50);
        $this->at(1800, self::HOTEL, 5);  // llegó al hotel a las 10:30
        $this->at(1860, self::HOTEL, 0);
        $this->at(3000, self::OUT, 40);   // salió del hotel a las 10:50
        $this->at(3060, self::OUT, 40);
        $this->at(4200, self::IN, 10);    // regresó a la base a las 11:10
        $this->at(4260, self::IN, 0);
    }

    protected function user(string $role, ?Company $company = null): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $company?->id]);
    }

    protected function owner(): User
    {
        return $this->user(User::ROLE_OWNER, $this->kevs);
    }

    protected function trip(Vehicle $vehicle, string $startUtc, float $km, int $minutes = 30): Trip
    {
        $start = CarbonImmutable::parse($startUtc, 'UTC');

        return Trip::create([
            'vehicle_id' => $vehicle->id,
            'started_at' => $start,
            'ended_at' => $start->addMinutes($minutes),
            'duration_seconds' => $minutes * 60,
            'distance_km' => $km,
            'data_source' => 'real',
        ]);
    }

    protected function alert(Vehicle $vehicle, string $type, int $minutes): Alert
    {
        return Alert::create([
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'type' => $type,
            'triggered_at' => $this->base->addMinutes($minutes),
            'latitude' => self::OUT[0],
            'longitude' => self::OUT[1],
            'message' => "{$vehicle->name}: " . AlertRule::LABELS[$type],
        ]);
    }

    // ------------------------------------------------------------------ cálculo

    public function test_records_entries_exits_and_time_inside_without_any_alert_rule(): void
    {
        $this->assertSame(0, AlertRule::count());
        $this->dayTrip();

        $this->assertSame(4, $this->track());

        $visits = GeofenceVisit::orderBy('entered_at')->get();
        $this->assertCount(3, $visits);

        // En la base desde la primera posición: no se sabe a qué hora entró
        $this->assertSame($this->baseFence->id, $visits[0]->geofence_id);
        $this->assertTrue($visits[0]->started_inside);
        $this->assertEquals($this->base->addSeconds(600), $visits[0]->exited_at);
        $this->assertSame(600, $visits[0]->duration_seconds);

        // El hotel: entra con la primera posición dentro, sale con la primera afuera
        $this->assertSame($this->hotel->id, $visits[1]->geofence_id);
        $this->assertFalse($visits[1]->started_inside);
        $this->assertEquals($this->base->addSeconds(1800), $visits[1]->entered_at);
        $this->assertSame(1200, $visits[1]->duration_seconds);

        // De regreso en la base: sigue dentro
        $this->assertEquals($this->base->addSeconds(4200), $visits[2]->entered_at);
        $this->assertNull($visits[2]->exited_at);
        $this->assertSame($this->kevs->id, $visits[2]->company_id);
    }

    public function test_running_in_pieces_gives_the_same_as_running_once(): void
    {
        $this->at(0, self::IN, 0);
        $this->at(60, self::IN, 5);
        $this->at(600, self::OUT, 40);
        $this->track();
        $this->at(660, self::OUT, 50);    // confirma la salida en la segunda corrida
        $this->track();
        $this->at(1800, self::HOTEL, 5);
        $this->at(1860, self::HOTEL, 0);
        $this->track();

        $this->assertSame(2, GeofenceVisit::count());
        $this->assertEquals($this->base->addSeconds(600), GeofenceVisit::where('geofence_id', $this->baseFence->id)->value('exited_at'));
        $this->assertSame(1, GeofenceVisit::where('geofence_id', $this->hotel->id)->whereNull('exited_at')->count());
    }

    public function test_the_command_recalculates_old_days_without_duplicates(): void
    {
        $this->dayTrip();

        $this->artisan('geofences:visits', ['--from' => '2026-11-11'])->assertSuccessful();
        $first = GeofenceVisit::orderBy('entered_at')->get(['geofence_id', 'entered_at', 'exited_at', 'duration_seconds'])->toArray();
        $this->assertCount(3, $first);

        // Otra vez: el mismo resultado
        $this->artisan('geofences:visits', ['--from' => '2026-11-11'])->assertSuccessful();
        $this->assertSame($first, GeofenceVisit::orderBy('entered_at')->get(['geofence_id', 'entered_at', 'exited_at', 'duration_seconds'])->toArray());

        // Una unidad de otra empresa no usa estas geocercas
        $this->assertSame(0, GeofenceVisit::where('vehicle_id', $this->foreignVan->id)->count());
    }

    public function test_recalculating_from_a_later_day_reopens_the_visit_that_came_from_before(): void
    {
        // Entra al hotel el día 11 y sale el 12 (hora Cancún)
        $this->at(0, self::OUT, 40);
        $this->at(60, self::OUT, 40);
        $this->at(600, self::HOTEL, 5);
        $this->at(660, self::HOTEL, 0);
        $this->at(86400, self::HOTEL, 0);
        $this->at(90000, self::OUT, 40);
        $this->at(90060, self::OUT, 40);
        $this->artisan('geofences:visits', ['--from' => '2026-11-11'])->assertSuccessful();

        $visit = GeofenceVisit::sole();
        $this->assertSame(89400, $visit->duration_seconds);

        $this->artisan('geofences:visits', ['--from' => '2026-11-12'])->assertSuccessful();

        $again = GeofenceVisit::sole();
        $this->assertSame($visit->id, $again->id);
        $this->assertEquals($this->base->addSeconds(600), $again->entered_at);
        $this->assertEquals($this->base->addSeconds(90000), $again->exited_at);
        $this->assertSame(89400, $again->duration_seconds);
    }

    public function test_the_first_recalculation_starts_inside_if_it_was_already_there(): void
    {
        // El 10 por la noche quedó en la base; el 11 sale
        $this->at(-86400, self::IN, 0);
        $this->at(600, self::OUT, 40);
        $this->at(660, self::OUT, 40);

        $this->artisan('geofences:visits', ['--from' => '2026-11-11'])->assertSuccessful();

        $visit = GeofenceVisit::sole();
        $this->assertTrue($visit->started_inside);
        $this->assertEquals(CarbonImmutable::parse('2026-11-11 05:00:00', 'UTC'), $visit->entered_at);
        $this->assertEquals($this->base->addSeconds(600), $visit->exited_at);
    }

    public function test_the_command_rejects_a_bad_date(): void
    {
        $this->artisan('geofences:visits', ['--from' => '2026-13-40'])->assertFailed();
    }

    // ------------------------------------------------------------------ reporte de entradas y salidas

    public function test_geofence_visits_report_with_summary_and_filters(): void
    {
        $this->dayTrip();
        $this->track();

        $res = $this->actingAs($this->owner())
            ->getJson('/api/reports/geofence-visits?from=2026-11-11&to=2026-11-11')
            ->assertOk()
            ->assertJsonPath('totals.visits', 3)
            ->assertJsonPath('data.1.geofence', 'Hotel')
            ->assertJsonPath('data.1.duration_seconds', 1200)
            ->assertJsonPath('data.2.inside', true)
            ->assertJsonCount(2, 'geofences');

        $hotel = collect($res->json('summary'))->firstWhere('geofence', 'Hotel');
        $this->assertSame(['visits' => 1, 'duration_seconds' => 1200], ['visits' => $hotel['visits'], 'duration_seconds' => $hotel['duration_seconds']]);

        $this->actingAs($this->owner())
            ->getJson("/api/reports/geofence-visits?from=2026-11-11&to=2026-11-11&geofence_id={$this->hotel->id}")
            ->assertJsonPath('totals.visits', 1);

        // Otro día: nada (la visita abierta sí toca días posteriores)
        $this->actingAs($this->owner())
            ->getJson('/api/reports/geofence-visits?from=2026-11-01&to=2026-11-10')
            ->assertJsonPath('totals.visits', 0);
    }

    public function test_geofence_visits_csv_and_excel(): void
    {
        $this->dayTrip();
        $this->track();

        $csv = $this->actingAs($this->owner())
            ->get('/api/reports/geofence-visits?from=2026-11-11&to=2026-11-11&format=csv')
            ->assertOk()
            ->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFUnidad,Geocerca,\"Fecha de entrada\"", $csv);
        $this->assertStringContainsString('Kevs1,Hotel,2026-11-11,10:30,2026-11-11,10:50,20,', $csv);

        $xlsx = $this->actingAs($this->owner())
            ->getJson('/api/reports/geofence-visits?from=2026-11-11&to=2026-11-11&format=xlsx')
            ->assertOk()
            ->assertJsonPath('filename', 'entradas-salidas_2026-11-11_2026-11-11.xlsx')
            ->assertJsonPath('sheets.0.name', 'Resumen')
            ->assertJsonPath('sheets.1.name', 'Entradas y salidas');
        $this->assertContains(['Kevs1', 'Hotel', 1, 20, 20], $xlsx->json('sheets.0.rows'));
        $this->assertSame('Sigue dentro', $xlsx->json('sheets.1.rows.2.7'));
    }

    // ------------------------------------------------------------------ distancia

    public function test_distance_report_covers_every_unit_and_day(): void
    {
        $this->trip($this->van, '2026-11-11 15:00:00', 20.25);
        $this->trip($this->van, '2026-11-11 18:00:00', 1.5);        // corto: suma km, no viaje
        $this->trip($this->van, '2026-11-13 04:30:00', 10);         // 12 nov 23:30 en Cancún
        $this->trip($this->van2, '2026-11-12 14:00:00', 5);
        $this->trip($this->foreignVan, '2026-11-11 15:00:00', 99); // de otra empresa

        $res = $this->actingAs($this->owner())
            ->getJson('/api/reports/distance?from=2026-11-11&to=2026-11-13')
            ->assertOk()
            ->assertJsonPath('days', ['2026-11-11', '2026-11-12', '2026-11-13'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.vehicle', 'Kevs1')
            ->assertJsonPath('data.0.km', ['2026-11-11' => 21.8, '2026-11-12' => 10, '2026-11-13' => 0])
            ->assertJsonPath('data.0.trips.2026-11-11', 1)
            ->assertJsonPath('data.0.active_days', 2)
            ->assertJsonPath('totals.km', 36.8);

        $this->assertSame(['2026-11-11' => 21.8, '2026-11-12' => 15, '2026-11-13' => 0], $res->json('totals.by_day'));

        $xlsx = $this->actingAs($this->owner())->getJson('/api/reports/distance?from=2026-11-11&to=2026-11-13&format=xlsx')->assertOk();
        $this->assertSame(['Unidad', '2026-11-11', '2026-11-12', '2026-11-13', 'Total km'], $xlsx->json('sheets.0.headers'));
        $this->assertSame(['Total flota', 21.8, 15, 0, 36.8], $xlsx->json('sheets.0.rows.2'));

        $csv = $this->actingAs($this->owner())->get('/api/reports/distance?from=2026-11-11&to=2026-11-13&format=csv')->streamedContent();
        $this->assertSame(1 + 2 * 3, substr_count(trim($csv), "\n") + 1); // encabezado + unidades × días
        $this->assertStringContainsString('Kevs2,2026-11-12,5,1', $csv);
    }

    // ------------------------------------------------------------------ alertas

    public function test_alerts_report_counts_by_unit_and_type(): void
    {
        $this->alert($this->van, AlertRule::OVERSPEED, 0);
        $this->alert($this->van, AlertRule::OVERSPEED, 30);
        $this->alert($this->van, AlertRule::GEOFENCE_EXIT, 60);
        $this->alert($this->van2, AlertRule::OVERSPEED, 90);
        $this->alert($this->foreignVan, AlertRule::OVERSPEED, 10);
        $this->alert($this->van, AlertRule::LONG_STOP, 60 * 24 * 5); // fuera del periodo

        $res = $this->actingAs($this->owner())
            ->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11')
            ->assertOk()
            ->assertJsonPath('totals.total', 4)
            ->assertJsonPath('totals.by_type.overspeed', 3)
            ->assertJsonPath('types.0.label', 'Exceso de velocidad')
            ->assertJsonCount(2, 'types')
            ->assertJsonPath('summary.0.vehicle', 'Kevs1')
            ->assertJsonPath('summary.0.counts', ['overspeed' => 2, 'geofence_exit' => 1])
            ->assertJsonPath('summary.0.total', 3)
            ->assertJsonCount(4, 'data');

        $this->assertNotContains('Ajena', collect($res->json('data'))->pluck('vehicle'));

        $this->actingAs($this->owner())
            ->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11&type=geofence_exit')
            ->assertJsonPath('totals.total', 1);

        $this->actingAs($this->owner())
            ->getJson("/api/reports/alerts?from=2026-11-11&to=2026-11-11&vehicle_id={$this->van2->id}")
            ->assertJsonPath('totals.total', 1);

        $xlsx = $this->actingAs($this->owner())->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11&format=xlsx')->assertOk();
        $this->assertSame(['Unidad', 'Exceso de velocidad', 'Sale de geocerca', 'Total'], $xlsx->json('sheets.0.headers'));
        $this->assertSame(['Total', 3, 1, 4], $xlsx->json('sheets.0.rows.2'));
        $this->assertCount(4, $xlsx->json('sheets.1.rows'));

        $csv = $this->actingAs($this->owner())->get('/api/reports/alerts?from=2026-11-11&to=2026-11-11&format=csv')->streamedContent();
        $this->assertStringContainsString('Kevs1,2026-11-11,10:00,"Exceso de velocidad"', $csv);
    }

    // ------------------------------------------------------------------ acceso

    public function test_reports_are_per_company_and_admins_use_view_as(): void
    {
        $this->trip($this->van, '2026-11-11 15:00:00', 20);
        $this->trip($this->foreignVan, '2026-11-11 15:00:00', 99);

        // El cliente de la otra empresa no ve a Kevs1, aunque lo pida por id
        $this->actingAs($this->user(User::ROLE_VIEWER, $this->other))
            ->getJson("/api/reports/distance?from=2026-11-11&to=2026-11-11&vehicle_id={$this->van->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->getJson('/api/reports/distance?from=2026-11-11&to=2026-11-11')->assertStatus(400);
        $this->actingAs($admin)->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11')->assertStatus(400);
        $this->actingAs($admin)->getJson('/api/reports/geofence-visits?from=2026-11-11&to=2026-11-11')->assertStatus(400);

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => $this->kevs->id])->assertOk();
        $this->actingAs($admin)
            ->getJson('/api/reports/distance?from=2026-11-11&to=2026-11-11')
            ->assertOk()
            ->assertJsonPath('totals.km', 20)
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->user(User::ROLE_VIEWER))->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11')->assertForbidden();
    }

    public function test_period_is_validated(): void
    {
        $this->actingAs($this->owner())->getJson('/api/reports/distance?from=2026-11-11&to=2026-11-01')->assertUnprocessable();
        $this->actingAs($this->owner())->getJson('/api/reports/distance?from=2026-01-01&to=2026-11-01')->assertUnprocessable();
        $this->actingAs($this->owner())->getJson('/api/reports/alerts?from=2026-11-11&to=2026-11-11&type=nope')->assertUnprocessable();
    }

    public function test_existing_reports_also_download_for_excel(): void
    {
        $this->trip($this->van, '2026-11-11 15:00:00', 20.25);

        $this->actingAs($this->owner())
            ->getJson('/api/reports/daily?from=2026-11-11&to=2026-11-11&format=xlsx')
            ->assertOk()
            ->assertJsonPath('filename', 'resumen-diario_2026-11-11_2026-11-11.xlsx')
            ->assertJsonPath('sheets.0.rows.0.0', 'Kevs1')
            ->assertJsonPath('sheets.0.rows.0.3', 20.3);

        foreach (['trips', 'stops'] as $kind) {
            $this->actingAs($this->owner())->getJson("/api/reports/{$kind}?from=2026-11-11&to=2026-11-11&format=xlsx")->assertOk()->assertJsonStructure(['filename', 'sheets' => [['name', 'headers', 'rows']]]);
        }

        $csv = $this->actingAs($this->owner())->get('/api/reports/daily?from=2026-11-11&to=2026-11-11&format=csv')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFUnidad,Fecha,Viajes,Km", $csv);
    }
}
