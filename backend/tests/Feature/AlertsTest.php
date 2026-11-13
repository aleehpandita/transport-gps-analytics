<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Company;
use App\Models\Geofence;
use App\Models\Position;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AlertEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alertas configurables por empresa: el motor que las detecta, el correo y la API del panel.
 */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    // Miércoles 11 nov 2026, 10:00 en Cancún
    protected CarbonImmutable $base;
    protected Company $kevs;
    protected Company $other;
    protected Vehicle $van;
    protected Geofence $baseFence;

    // La base de Kevin y un punto a 2 km
    protected const IN = [21.1500, -86.8500];
    protected const OUT = [21.1680, -86.8500];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        config(['sanctum.stateful' => ['localhost']]);

        $this->base = CarbonImmutable::parse('2026-11-11 15:00:00', 'UTC');
        $this->kevs = Company::create(['name' => 'Kevs Trips']);
        $this->other = Company::create(['name' => 'Otra Transportadora']);
        $this->van = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs1', 'imei' => '111111111111111', 'traccar_device_id' => 1, 'active' => true]);
        $this->baseFence = Geofence::create([
            'company_id' => $this->kevs->id, 'name' => 'Base', 'shape' => 'circle',
            'latitude' => self::IN[0], 'longitude' => self::IN[1], 'radius_m' => 300,
        ]);
    }

    // ------------------------------------------------------------------ ayudantes

    protected function rule(string $type, array $settings = [], array $extra = []): AlertRule
    {
        return AlertRule::create($extra + [
            'company_id' => $this->kevs->id,
            'name' => AlertRule::LABELS[$type],
            'type' => $type,
            'settings' => $settings,
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'repeat_minutes' => 0,
            'active' => true,
        ]);
    }

    /** Posición a $seconds segundos de la base de la prueba */
    protected function at(int $seconds, array $point, float $speed, ?bool $ignition = null): void
    {
        Position::create([
            'vehicle_id' => $this->van->id,
            'latitude' => $point[0],
            'longitude' => $point[1],
            'speed' => $speed,
            'ignition' => $ignition,
            'valid' => true,
            'device_time' => $this->base->addSeconds($seconds),
        ]);
    }

    /** Corre el motor como lo haría el scheduler a los $seconds de la base */
    protected function runAt(int $seconds): \Illuminate\Support\Collection
    {
        $rules = AlertRule::where('active', true)->get();
        $geofences = Geofence::where('company_id', $this->kevs->id)->get()->keyBy('id');

        return app(AlertEngine::class)->process($this->van, $rules, $geofences, $this->base->addSeconds($seconds), $this->base->subMinute());
    }

    // ------------------------------------------------------------------ motor

    public function test_overspeed_alerts_once_when_sustained_and_keeps_the_max_speed(): void
    {
        $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100, 'seconds' => 30]);

        foreach ([0 => 105, 10 => 120, 20 => 131, 30 => 128, 40 => 115, 50 => 90] as $s => $speed) {
            $this->at($s, self::OUT, $speed);
        }

        $created = $this->runAt(60);

        $this->assertCount(1, $created);
        $alert = Alert::sole();
        $this->assertSame(131.0, $alert->value);
        $this->assertSame('Kevs1 llegó a 131 km/h (límite 100 km/h)', $alert->message);
        $this->assertSame(40, $alert->data['duration_seconds']);
    }

    public function test_a_short_speed_spike_does_not_alert(): void
    {
        $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100, 'seconds' => 30]);

        foreach ([0 => 110, 10 => 115, 20 => 80] as $s => $speed) {
            $this->at($s, self::OUT, $speed);
        }

        $this->assertCount(0, $this->runAt(30));
    }

    public function test_geofence_exit_and_enter_need_two_positions_on_the_other_side(): void
    {
        $exit = $this->rule(AlertRule::GEOFENCE_EXIT, ['geofence_id' => $this->baseFence->id]);
        $enter = $this->rule(AlertRule::GEOFENCE_ENTER, ['geofence_id' => $this->baseFence->id]);

        $this->at(0, self::IN, 0);
        $this->at(60, self::IN, 5);
        $this->at(120, self::OUT, 40);   // una sola afuera: aún no
        $this->at(130, self::IN, 5);     // regresó: era ruido
        $this->at(200, self::OUT, 40);
        $this->at(210, self::OUT, 45);   // ya salió
        $this->at(900, self::IN, 20);
        $this->at(910, self::IN, 5);     // ya volvió

        $this->runAt(1000);

        $this->assertSame(1, Alert::where('alert_rule_id', $exit->id)->count());
        $this->assertSame('Kevs1 salió de la geocerca «Base»', Alert::where('alert_rule_id', $exit->id)->value('message'));
        $this->assertEquals($this->base->addSeconds(200), Alert::where('alert_rule_id', $exit->id)->value('triggered_at'));
        $this->assertSame(1, Alert::where('alert_rule_id', $enter->id)->count());
    }

    public function test_long_stop_skips_the_clients_geofences_when_asked(): void
    {
        $this->rule(AlertRule::LONG_STOP, ['minutes' => 30, 'except_in_geofences' => true]);

        // 40 min en la base: normal
        for ($s = 0; $s <= 2400; $s += 120) {
            $this->at($s, self::IN, 0);
        }
        $this->assertCount(0, $this->runAt(2400));

        // 40 min afuera: aviso
        for ($s = 3000; $s <= 5400; $s += 120) {
            $this->at($s, self::OUT, 0);
        }
        $created = $this->runAt(5400);

        $this->assertCount(1, $created);
        $this->assertSame('Kevs1 lleva 30 min detenida en el mismo punto', $created->first()->message);
    }

    public function test_long_stop_alerts_by_the_clock_when_the_unit_sleeps_parked(): void
    {
        $this->rule(AlertRule::LONG_STOP, ['minutes' => 30]);

        $this->at(0, self::OUT, 0);
        $this->at(60, self::OUT, 0); // el FMC920 se duerme y ya no reporta

        $this->assertCount(0, $this->runAt(600));
        $this->assertCount(1, $this->runAt(1900));
        $this->assertCount(0, $this->runAt(3600), 'La misma parada no avisa dos veces');
    }

    public function test_signal_lost_only_counts_when_it_was_moving(): void
    {
        $this->rule(AlertRule::SIGNAL_LOST, ['minutes' => 15]);

        $this->at(0, self::OUT, 50);
        $this->assertCount(0, $this->runAt(600));
        $this->assertCount(1, $this->runAt(1000));
        $this->assertCount(0, $this->runAt(1200), 'Mismo silencio: un solo aviso');

        // Volvió la señal y se estacionó: dormido no es "sin señal"
        $this->at(1300, self::IN, 0);
        $this->assertCount(0, $this->runAt(5000));
    }

    public function test_signal_lost_also_counts_with_ignition_on(): void
    {
        $this->rule(AlertRule::SIGNAL_LOST, ['minutes' => 15]);

        $this->at(0, self::OUT, 0, ignition: true);

        $this->assertCount(1, $this->runAt(1000));
    }

    public function test_after_hours_movement_only_alerts_inside_its_schedule(): void
    {
        $this->rule(AlertRule::AFTER_HOURS, [], ['time_from' => '22:00', 'time_to' => '05:00']);

        // 10:00 Cancún: horario normal
        $this->at(0, self::OUT, 40);
        $this->at(10, self::OUT, 45);
        $this->assertCount(0, $this->runAt(20));

        // 23:00 Cancún (13 h después)
        $this->at(46800, self::OUT, 30);
        $this->at(46810, self::OUT, 35);
        $this->at(46820, self::OUT, 38);
        $created = $this->runAt(46830);

        $this->assertCount(1, $created);
        $this->assertSame('Kevs1 se está moviendo fuera de horario (35 km/h)', $created->first()->message);
    }

    public function test_schedules_that_cross_midnight_belong_to_the_day_they_start(): void
    {
        $rule = new AlertRule(['days' => [1], 'time_from' => '22:00', 'time_to' => '05:00']);
        $ts = fn (string $local) => CarbonImmutable::parse($local, 'America/Cancun')->getTimestamp();

        $this->assertTrue($rule->isActiveAt($ts('2026-11-09 22:30')));  // lunes en la noche
        $this->assertTrue($rule->isActiveAt($ts('2026-11-10 03:00')));  // madrugada del martes
        $this->assertFalse($rule->isActiveAt($ts('2026-11-10 22:30'))); // martes en la noche
        $this->assertFalse($rule->isActiveAt($ts('2026-11-09 03:00'))); // madrugada del lunes (viene del domingo)
    }

    public function test_repeat_minutes_silences_new_alerts_for_a_while(): void
    {
        $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100, 'seconds' => 0], ['repeat_minutes' => 30]);

        $this->at(0, self::OUT, 120);
        $this->at(10, self::OUT, 60);
        $this->at(300, self::OUT, 120);   // 5 min después: silenciado
        $this->at(310, self::OUT, 60);
        $this->at(2000, self::OUT, 120);  // 33 min después: avisa otra vez
        $this->at(2010, self::OUT, 60);

        $this->assertCount(2, $this->runAt(2100));
    }

    public function test_rules_only_watch_their_vehicles(): void
    {
        $otherVan = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs2', 'imei' => '333333333333333', 'traccar_device_id' => 3, 'active' => true]);
        $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100, 'seconds' => 0], ['vehicle_ids' => [$otherVan->id]]);

        $this->at(0, self::OUT, 130);
        $this->at(10, self::OUT, 130);

        $rules = AlertRule::all()->filter(fn ($r) => $r->appliesTo($this->van->id));
        $this->assertCount(0, $rules);
    }

    // ------------------------------------------------------------------ comando y correo

    public function test_the_command_emails_new_alerts_and_skips_rules_without_emails(): void
    {
        Mail::fake();
        $this->travelTo($this->base->addMinutes(5));

        $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100, 'seconds' => 0], ['emails' => ['kevin@kevs.mx']]);
        $this->rule(AlertRule::GEOFENCE_EXIT, ['geofence_id' => $this->baseFence->id]);

        // La primera corrida mira los últimos 10 minutos
        $this->at(0, self::IN, 0);
        $this->at(60, self::OUT, 120);
        $this->at(70, self::OUT, 125);

        $this->artisan('alerts:check')->assertSuccessful();

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('kevin@kevs.mx')
            && $mail->envelope()->subject === 'Exceso de velocidad · Kevs1');
        Mail::assertSentCount(1);
        $this->assertSame(Alert::EMAIL_SENT, Alert::where('type', AlertRule::OVERSPEED)->value('email_status'));
        $this->assertSame(Alert::EMAIL_SKIPPED, Alert::where('type', AlertRule::GEOFENCE_EXIT)->value('email_status'));
    }

    public function test_the_alert_email_renders(): void
    {
        $rule = $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100]);
        $alert = Alert::create([
            'company_id' => $this->kevs->id, 'alert_rule_id' => $rule->id, 'vehicle_id' => $this->van->id,
            'type' => AlertRule::OVERSPEED, 'triggered_at' => $this->base, 'latitude' => 21.15, 'longitude' => -86.85,
            'value' => 131, 'message' => 'Kevs1 iba a 131 km/h (límite 100 km/h)',
        ]);

        $html = (new AlertMail($alert))->render();

        $this->assertStringContainsString('Kevs1 iba a 131 km/h', $html);
        $this->assertStringContainsString('11/11/2026 10:00', $html);
        $this->assertStringContainsString('https://www.google.com/maps?q=21.150000,-86.850000', $html);
    }

    // ------------------------------------------------------------------ API del panel

    protected function user(string $role, ?Company $company = null): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $company?->id]);
    }

    protected function ruleInput(array $extra = []): array
    {
        return $extra + [
            'name' => 'Velocidad en carretera',
            'type' => AlertRule::OVERSPEED,
            'settings' => ['speed_kmh' => 110, 'seconds' => 20],
            'vehicle_ids' => [],
            'days' => [1, 2, 3, 4, 5],
            'time_from' => null,
            'time_to' => null,
            'repeat_minutes' => 30,
            'emails' => ['Kevin@Kevs.mx'],
        ];
    }

    public function test_an_owner_creates_geofences_and_rules(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);

        $fence = $this->actingAs($owner)->postJson('/api/geofences', [
            'name' => 'Aeropuerto',
            'shape' => 'polygon',
            'points' => [[21.03, -86.88], [21.05, -86.88], [21.05, -86.86], [21.03, -86.86]],
        ])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(21.04, $fence['latitude'], 0.0001);

        $rule = $this->actingAs($owner)->postJson('/api/alert-rules', $this->ruleInput())->assertCreated()->json('data');
        $this->assertSame(['kevin@kevs.mx'], $rule['emails']);
        $this->assertNull($rule['vehicle_ids']);
        $this->assertSame(['speed_kmh' => 110, 'seconds' => 20], (array) $rule['settings']);

        $this->actingAs($owner)->postJson('/api/alert-rules', $this->ruleInput([
            'name' => 'Sale del aeropuerto',
            'type' => AlertRule::GEOFENCE_EXIT,
            'settings' => ['geofence_id' => $fence['id']],
        ]))->assertCreated();

        $this->actingAs($owner)->getJson('/api/alert-rules')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_rules_cannot_point_to_another_companys_geofence_or_vehicle(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);
        $foreignFence = Geofence::create(['company_id' => $this->other->id, 'name' => 'Ajena', 'shape' => 'circle', 'latitude' => 21, 'longitude' => -86, 'radius_m' => 100]);
        $foreignVan = Vehicle::create(['company_id' => $this->other->id, 'name' => 'Van 7', 'imei' => '222222222222222', 'traccar_device_id' => 2, 'active' => true]);

        $this->actingAs($owner)->postJson('/api/alert-rules', $this->ruleInput([
            'type' => AlertRule::GEOFENCE_EXIT, 'settings' => ['geofence_id' => $foreignFence->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('settings.geofence_id');

        $this->actingAs($owner)->postJson('/api/alert-rules', $this->ruleInput(['vehicle_ids' => [$foreignVan->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_ids');

        $this->actingAs($owner)->patchJson("/api/geofences/{$foreignFence->id}", ['name' => 'Mía'])->assertNotFound();
    }

    public function test_after_hours_needs_a_schedule(): void
    {
        $this->actingAs($this->user(User::ROLE_OWNER, $this->kevs))
            ->postJson('/api/alert-rules', $this->ruleInput(['type' => AlertRule::AFTER_HOURS, 'settings' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('time_from');
    }

    public function test_a_viewer_reads_the_inbox_but_cannot_configure(): void
    {
        $viewer = $this->user(User::ROLE_VIEWER, $this->kevs);

        $this->actingAs($viewer)->postJson('/api/alert-rules', $this->ruleInput())->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/geofences', ['name' => 'X', 'shape' => 'circle', 'latitude' => 21, 'longitude' => -86, 'radius_m' => 100])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/alerts')->assertOk();
    }

    public function test_the_inbox_is_per_company_and_marks_read(): void
    {
        $rule = $this->rule(AlertRule::OVERSPEED, ['speed_kmh' => 100]);
        $mine = Alert::create([
            'company_id' => $this->kevs->id, 'alert_rule_id' => $rule->id, 'vehicle_id' => $this->van->id,
            'type' => AlertRule::OVERSPEED, 'triggered_at' => $this->base, 'latitude' => 21.15, 'longitude' => -86.85, 'message' => 'Mía',
        ]);
        $foreignVan = Vehicle::create(['company_id' => $this->other->id, 'name' => 'Van 7', 'imei' => '222222222222222', 'traccar_device_id' => 2, 'active' => true]);
        $theirs = Alert::create([
            'company_id' => $this->other->id, 'vehicle_id' => $foreignVan->id,
            'type' => AlertRule::OVERSPEED, 'triggered_at' => $this->base, 'latitude' => 21.15, 'longitude' => -86.85, 'message' => 'Ajena',
        ]);
        $operator = $this->user(User::ROLE_OPERATOR, $this->kevs);

        $this->actingAs($operator)->getJson('/api/alerts?status=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Mía')
            ->assertJsonPath('meta.unread', 1);

        $this->actingAs($operator)->postJson("/api/alerts/{$theirs->id}/read")->assertNotFound();
        $this->actingAs($operator)->postJson("/api/alerts/{$mine->id}/read")->assertOk();
        $this->actingAs($operator)->getJson('/api/alerts/unread-count')->assertJsonPath('data.unread', 0);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_an_admin_must_pick_a_client_first(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/alert-rules')->assertStatus(400);
        $this->actingAs($admin)->getJson('/api/alerts/unread-count')->assertJsonPath('data.unread', 0);

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => $this->kevs->id])->assertOk();
        $this->actingAs($admin)->getJson('/api/geofences')->assertOk()->assertJsonPath('data.0.name', 'Base');
    }

    public function test_a_geofence_in_use_cannot_be_deleted(): void
    {
        $this->rule(AlertRule::GEOFENCE_EXIT, ['geofence_id' => $this->baseFence->id], ['name' => 'Sale de la base']);

        $this->actingAs($this->user(User::ROLE_OWNER, $this->kevs))
            ->deleteJson("/api/geofences/{$this->baseFence->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La usan estas alertas: Sale de la base. Bórralas o cámbiales la geocerca primero.');
    }
}
