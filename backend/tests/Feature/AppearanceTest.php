<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apariencia del panel: tema claro u oscuro por usuario, ícono y color de cada unidad
 * (los elige el dueño) y la alerta sin leer que hace parpadear a la unidad en el mapa.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $kevs;
    protected Company $other;
    protected Vehicle $kevs1;
    protected Vehicle $otherVan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        config(['sanctum.stateful' => ['localhost']]);

        $this->kevs = Company::create(['name' => 'Kevs Trips']);
        $this->other = Company::create(['name' => 'Otra Transportadora']);
        $this->kevs1 = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs1', 'imei' => '111111111111111', 'traccar_device_id' => 1, 'active' => true]);
        $this->otherVan = Vehicle::create(['company_id' => $this->other->id, 'name' => 'Van 7', 'imei' => '222222222222222', 'traccar_device_id' => 2, 'active' => true]);
    }

    protected function user(string $role, ?Company $company = null): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $company?->id])->fresh();
    }

    public function test_new_users_start_in_light_theme_and_can_switch(): void
    {
        $user = $this->user(User::ROLE_VIEWER, $this->kevs);

        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('data.theme', 'light');

        $this->actingAs($user)->patchJson('/api/me/preferences', ['theme' => 'dark'])
            ->assertOk()
            ->assertJsonPath('data.theme', 'dark');
        $this->assertSame('dark', $user->fresh()->theme);

        $this->actingAs($user)->patchJson('/api/me/preferences', ['theme' => 'rosa'])->assertUnprocessable();
    }

    public function test_owner_changes_name_plate_icon_and_color_of_own_units(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);

        $this->actingAs($owner)->getJson('/api/company/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.icon', 'van')
            ->assertJsonPath('data.0.color', null);

        $this->actingAs($owner)->patchJson("/api/company/vehicles/{$this->kevs1->id}", [
            'name' => 'Kevs 1',
            'plate' => ' 66re9u ',
            'icon' => 'sedan',
            'color' => '#e5566b',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Kevs 1')
            ->assertJsonPath('data.plate', '66RE9U')
            ->assertJsonPath('data.icon', 'sedan')
            ->assertJsonPath('data.color', '#e5566b');

        // En el mapa llegan igual
        $this->actingAs($owner)->getJson('/api/vehicles')
            ->assertJsonPath('data.0.icon', 'sedan')
            ->assertJsonPath('data.0.color', '#e5566b');
    }

    public function test_owner_cannot_touch_other_companies_units_or_bad_values(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);

        $this->actingAs($owner)->patchJson("/api/company/vehicles/{$this->otherVan->id}", ['color' => '#000000'])->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/company/vehicles/{$this->kevs1->id}", ['icon' => 'tanque'])->assertUnprocessable();
        $this->actingAs($owner)->patchJson("/api/company/vehicles/{$this->kevs1->id}", ['color' => 'red'])->assertUnprocessable();
        // El IMEI no se cambia desde aquí
        $this->actingAs($owner)->patchJson("/api/company/vehicles/{$this->kevs1->id}", ['imei' => '999999999999999'])->assertOk();
        $this->assertSame('111111111111111', $this->kevs1->fresh()->imei);

        $operator = $this->user(User::ROLE_OPERATOR, $this->kevs);
        $this->actingAs($operator)->patchJson("/api/company/vehicles/{$this->kevs1->id}", ['color' => '#000000'])->assertForbidden();
    }

    public function test_admin_edits_icon_and_color_too(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->patchJson("/api/admin/vehicles/{$this->kevs1->id}", ['icon' => 'sedan', 'color' => '#7c6cf2'])
            ->assertOk()
            ->assertJsonPath('data.icon', 'sedan')
            ->assertJsonPath('data.color', '#7c6cf2');
    }

    public function test_an_unread_recent_alert_makes_the_unit_blink(): void
    {
        $viewer = $this->user(User::ROLE_VIEWER, $this->kevs);
        $make = fn (string $when, ?string $readAt = null, string $msg = 'Kevs1 iba a 131 km/h') => Alert::create([
            'company_id' => $this->kevs->id, 'vehicle_id' => $this->kevs1->id, 'type' => 'overspeed',
            'triggered_at' => now()->modify($when), 'latitude' => 21.15, 'longitude' => -86.85,
            'message' => $msg, 'read_at' => $readAt ? now() : null,
        ]);

        // Sin alertas: no parpadea
        $this->actingAs($viewer)->getJson('/api/vehicles')->assertJsonPath('data.0.active_alert', null);

        // Una vieja (más de 24 h) o una ya leída tampoco
        $make('-30 hours');
        $make('-1 hour', 'leida');
        $this->actingAs($viewer)->getJson('/api/vehicles')->assertJsonPath('data.0.active_alert', null);

        // Una reciente sin leer sí, y gana la más nueva
        $make('-2 hours', null, 'vieja');
        $alert = $make('-10 minutes', null, 'Salió de Casa chofer');
        $this->actingAs($viewer)->getJson('/api/vehicles')
            ->assertJsonPath('data.0.active_alert.id', $alert->id)
            ->assertJsonPath('data.0.active_alert.message', 'Salió de Casa chofer');
    }
}
