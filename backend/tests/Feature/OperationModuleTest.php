<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de Operación por empresa. La BASE la tienen todos; la Operación (servicios
 * detectados, no-shows, predicciones de llegada) solo la ve quien la contrató.
 */
class OperationModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $withOperation;
    protected Company $baseOnly;

    protected function setUp(): void
    {
        parent::setUp();

        // Igual que en CompaniesTest: sesión de panel para que funcione "Ver como"
        $this->withHeader('Referer', 'http://localhost');
        config(['sanctum.stateful' => ['localhost']]);

        $this->withOperation = Company::create(['name' => 'Kevs Trips', 'operation_module' => true]);
        $this->baseOnly = Company::create(['name' => 'Brandon']);
    }

    protected function user(string $role, ?Company $company = null): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $company?->id]);
    }

    public function test_a_new_company_starts_without_the_operation_module(): void
    {
        $this->assertFalse($this->baseOnly->fresh()->hasOperationModule());
        $this->assertTrue($this->withOperation->fresh()->hasOperationModule());
    }

    public function test_me_tells_the_panel_if_the_company_has_operation(): void
    {
        $this->actingAs($this->user(User::ROLE_OWNER, $this->withOperation))
            ->getJson('/api/me')
            ->assertJsonPath('data.has_operation', true);

        $this->actingAs($this->user(User::ROLE_OWNER, $this->baseOnly))
            ->getJson('/api/me')
            ->assertJsonPath('data.has_operation', false);
    }

    public function test_a_user_without_company_has_no_operation(): void
    {
        $this->actingAs($this->user(User::ROLE_VIEWER))
            ->getJson('/api/me')
            ->assertJsonPath('data.has_operation', false);
    }

    public function test_admin_sees_operation_and_view_as_follows_the_company(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/me')->assertJsonPath('data.has_operation', true);

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => $this->baseOnly->id])->assertOk();
        $this->actingAs($admin)->getJson('/api/me')->assertJsonPath('data.has_operation', false);

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => $this->withOperation->id])->assertOk();
        $this->actingAs($admin)->getJson('/api/me')->assertJsonPath('data.has_operation', true);

        $this->actingAs($admin)->postJson('/api/admin/view-as', ['company_id' => null])->assertOk();
        $this->actingAs($admin)->getJson('/api/me')->assertJsonPath('data.has_operation', true);
    }

    public function test_admin_turns_the_module_on_and_off_without_touching_other_fields(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->baseOnly->update(['plan' => 'Fundador', 'monthly_price' => 320]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/companies/{$this->baseOnly->id}", ['operation_module' => true])
            ->assertOk()
            ->assertJsonPath('data.operation_module', true)
            ->assertJsonPath('data.plan', 'Fundador');

        $this->actingAs($admin)
            ->patchJson("/api/admin/companies/{$this->baseOnly->id}", ['operation_module' => false])
            ->assertOk()
            ->assertJsonPath('data.operation_module', false);

        $this->assertFalse($this->baseOnly->fresh()->hasOperationModule());
        $this->assertSame('Fundador', $this->baseOnly->fresh()->plan);
    }

    public function test_editing_a_company_without_the_field_keeps_the_module(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->patchJson("/api/admin/companies/{$this->withOperation->id}", ['notes' => 'Cliente piloto'])
            ->assertOk()
            ->assertJsonPath('data.operation_module', true);
    }

    public function test_only_admins_can_change_the_module(): void
    {
        $this->actingAs($this->user(User::ROLE_OWNER, $this->baseOnly))
            ->patchJson("/api/admin/companies/{$this->baseOnly->id}", ['operation_module' => true])
            ->assertForbidden();

        $this->assertFalse($this->baseOnly->fresh()->hasOperationModule());
    }

    public function test_the_admin_list_shows_the_module(): void
    {
        $response = $this->actingAs($this->user(User::ROLE_ADMIN))->getJson('/api/admin/companies')->assertOk();

        $modules = collect($response->json('data'))->pluck('operation_module', 'name')->all();
        $this->assertSame(['Brandon' => false, 'Kevs Trips' => true], $modules);
    }
}
