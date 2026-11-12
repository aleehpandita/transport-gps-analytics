<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuentas por empresa: cada usuario ve solo lo de su empresa, los roles limitan lo que
 * puede cambiar y el admin de EkMira administra a todos.
 */
class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $kevs;
    protected Company $other;
    protected Vehicle $kevs1;
    protected Vehicle $otherVan;

    protected function setUp(): void
    {
        parent::setUp();

        // El panel manda Referer de un dominio "stateful" y Sanctum le abre sesión
        $this->withHeader('Referer', 'http://localhost');
        config(['sanctum.stateful' => ['localhost']]);
        config(['services.traccar' => ['base_url' => 'http://traccar.test', 'username' => 'x', 'password' => 'x']]);

        $this->kevs = Company::create(['name' => 'Kevs Trips']);
        $this->other = Company::create(['name' => 'Otra Transportadora']);
        $this->kevs1 = Vehicle::create(['company_id' => $this->kevs->id, 'name' => 'Kevs1', 'imei' => '111111111111111', 'traccar_device_id' => 1, 'active' => true]);
        $this->otherVan = Vehicle::create(['company_id' => $this->other->id, 'name' => 'Van 7', 'imei' => '222222222222222', 'traccar_device_id' => 2, 'active' => true]);
    }

    protected function user(string $role, ?Company $company = null): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $company?->id]);
    }

    public function test_a_company_user_only_sees_its_own_vehicles(): void
    {
        $response = $this->actingAs($this->user(User::ROLE_VIEWER, $this->kevs))->getJson('/api/vehicles');

        $response->assertOk();
        $this->assertSame(['Kevs1'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_a_user_cannot_open_a_vehicle_of_another_company(): void
    {
        $this->actingAs($this->user(User::ROLE_OWNER, $this->kevs))
            ->getJson("/api/vehicles/{$this->otherVan->id}")
            ->assertNotFound();
    }

    public function test_a_user_without_company_sees_nothing(): void
    {
        $response = $this->actingAs($this->user(User::ROLE_VIEWER))->getJson('/api/vehicles');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_sees_every_vehicle_and_can_view_as_a_company(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/vehicles')->assertJsonCount(2, 'data');

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => $this->kevs->id])->assertOk();
        $this->actingAs($admin)->getJson('/api/vehicles')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/me')->assertJsonPath('data.acting_company.name', 'Kevs Trips');

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => null])->assertOk();
        $this->actingAs($admin)->getJson('/api/vehicles')->assertJsonCount(2, 'data');
    }

    public function test_a_viewer_cannot_mark_trips(): void
    {
        $trip = Trip::create([
            'vehicle_id' => $this->kevs1->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'distance_km' => 10,
            'duration_seconds' => 3600,
        ]);

        $this->actingAs($this->user(User::ROLE_VIEWER, $this->kevs))
            ->postJson('/api/trip-reviews', ['trip_id' => $trip->id, 'status' => 'servicio'])
            ->assertForbidden();
    }

    public function test_only_admins_reach_the_admin_panel(): void
    {
        $this->actingAs($this->user(User::ROLE_OWNER, $this->kevs))->getJson('/api/admin/companies')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_ADMIN))->getJson('/api/admin/companies')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_creates_a_company_and_its_owner_with_a_temporary_password(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $company = $this->actingAs($admin)->postJson('/api/admin/companies', [
            'name' => 'Cliente Nuevo',
            'contact_email' => 'dueno@cliente.mx',
            'monthly_price' => 399,
        ])->assertCreated()->json('data');

        $user = $this->actingAs($admin)->postJson("/api/admin/companies/{$company['id']}/users", [
            'name' => 'Dueño',
            'email' => 'dueno@cliente.mx',
            'role' => User::ROLE_OWNER,
        ])->assertCreated()->json('data');

        $this->assertSame(12, strlen($user['temporary_password']));
        $this->assertTrue(Hash::check($user['temporary_password'], User::where('email', 'dueno@cliente.mx')->value('password')));
    }

    public function test_admin_registers_a_new_device_in_traccar_by_imei(): void
    {
        Http::fake([
            'traccar.test/api/devices?uniqueId=*' => Http::response([]),
            'traccar.test/api/devices' => Http::response(['id' => 77, 'name' => 'Van 1', 'uniqueId' => '350612070000001']),
        ]);

        $row = $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/admin/companies/{$this->kevs->id}/vehicles", [
                'name' => 'Van 1',
                'imei' => '350612070000001',
                'plate' => 'ABC-123',
            ])->assertCreated()->json('data');

        $this->assertSame(77, $row['traccar_device_id']);
        $this->assertSame('never', $row['signal']);
        $this->assertSame($this->kevs->id, Vehicle::find($row['id'])->company_id);
    }

    public function test_a_vehicle_of_another_company_cannot_be_taken_without_releasing_it(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/admin/companies/{$this->kevs->id}/vehicles", ['vehicle_id' => $this->otherVan->id])
            ->assertUnprocessable();
    }

    public function test_owner_manages_only_the_users_of_its_company(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);
        $stranger = $this->user(User::ROLE_OPERATOR, $this->other);

        $created = $this->actingAs($owner)->postJson('/api/company/users', [
            'name' => 'Chofer',
            'email' => 'chofer@kevs.mx',
            'role' => User::ROLE_VIEWER,
        ])->assertCreated()->json('data');

        $this->assertSame($this->kevs->id, $created['company_id']);
        $this->actingAs($owner)->getJson('/api/company/users')->assertJsonCount(2, 'data');

        // Otra empresa: ni la ve ni la toca
        $this->actingAs($owner)->deleteJson("/api/company/users/{$stranger->id}")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/company/users/{$stranger->id}/reset-password")->assertNotFound();
        $this->assertNotNull($stranger->fresh());
    }

    public function test_owner_cannot_lock_itself_out(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->kevs);

        $this->actingAs($owner)->patchJson("/api/company/users/{$owner->id}", ['role' => User::ROLE_VIEWER])->assertStatus(422);
        $this->actingAs($owner)->deleteJson("/api/company/users/{$owner->id}")->assertStatus(422);
    }

    public function test_operators_cannot_manage_users(): void
    {
        $this->actingAs($this->user(User::ROLE_OPERATOR, $this->kevs))
            ->postJson('/api/company/users', ['name' => 'X', 'email' => 'x@x.mx', 'role' => User::ROLE_VIEWER])
            ->assertForbidden();
    }

    public function test_users_of_a_suspended_company_cannot_log_in(): void
    {
        $this->other->update(['status' => Company::STATUS_SUSPENDED]);
        $user = User::factory()->create(['role' => User::ROLE_OWNER, 'company_id' => $this->other->id, 'password' => 'una-clave-larga']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'una-clave-larga'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'La cuenta de tu empresa está suspendida. Escríbenos a contacto@ekmira.com.');
        $this->assertGuest('web');
    }
}
