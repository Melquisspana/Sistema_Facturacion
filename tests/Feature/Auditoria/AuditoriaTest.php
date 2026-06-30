<?php

namespace Tests\Feature\Auditoria;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    public function test_invitado_redirigido(): void
    {
        $this->get(route('auditoria.index'))->assertRedirect('/login');
    }

    public function test_administrador_y_contador_pueden_ver(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('auditoria.index'))->assertOk();
        $this->actingAs($this->usuario('contador'))->get(route('auditoria.index'))->assertOk();
    }

    public function test_facturacion_y_consulta_no_pueden_ver(): void
    {
        $this->actingAs($this->usuario('facturacion'))->get(route('auditoria.index'))->assertForbidden();
        $this->actingAs($this->usuario('consulta'))->get(route('auditoria.index'))->assertForbidden();
    }
}
