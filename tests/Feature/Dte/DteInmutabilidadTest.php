<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\TransicionInvalidaException;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Dte\DteStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteInmutabilidadTest extends TestCase
{
    use RefreshDatabase;

    private int $establecimientoId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $empresa = Empresa::create(['razon_social' => 'X', 'ambiente' => '00', 'activo' => true]);
        $this->establecimientoId = Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true,
        ])->id;
    }

    private function crearDte(string $estado = 'borrador'): Dte
    {
        return Dte::create([
            'tipo_dte' => '03',
            'estado' => $estado,
            'ambiente' => '00',
            'establecimiento_id' => $this->establecimientoId,
            'condicion_operacion' => 1,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
        ]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    // --- Inmutabilidad (Observer) ---

    public function test_borrador_es_editable(): void
    {
        $dte = $this->crearDte('borrador');
        $this->assertTrue($dte->esEditable());

        $dte->observaciones = 'Cambio en borrador';
        $dte->save();

        $this->assertSame('Cambio en borrador', $dte->fresh()->observaciones);
    }

    public function test_generado_no_es_editable(): void
    {
        $dte = $this->crearDte('generado');
        $this->assertFalse($dte->esEditable());

        $this->expectException(DocumentoInmutableException::class);
        $dte->observaciones = 'No debería poder';
        $dte->save();
    }

    public function test_aceptado_no_es_editable(): void
    {
        $dte = $this->crearDte('aceptado');

        $this->expectException(DocumentoInmutableException::class);
        $dte->condicion_operacion = 2;
        $dte->save();
    }

    public function test_no_se_puede_eliminar_no_borrador(): void
    {
        $dte = $this->crearDte('generado');

        $this->expectException(DocumentoInmutableException::class);
        $dte->delete();
    }

    public function test_se_puede_eliminar_borrador(): void
    {
        $dte = $this->crearDte('borrador');
        $dte->delete();
        $this->assertSoftDeleted('dtes', ['id' => $dte->id]);
    }

    public function test_lineas_de_documento_emitido_son_inmutables(): void
    {
        $dte = $this->crearDte('generado');

        $this->expectException(DocumentoInmutableException::class);
        DteLinea::create([
            'dte_id' => $dte->id,
            'numero_linea' => 1,
            'descripcion' => 'Intento',
            'tipo_impuesto' => 'gravado',
            'cantidad' => 1,
            'precio_unitario' => 1,
        ]);
    }

    // --- Máquina de estados ---

    public function test_transicion_valida_registra_historial(): void
    {
        $dte = $this->crearDte('borrador');
        $user = $this->usuario('facturacion');

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado, $user, 'Emisión');

        $this->assertSame(EstadoDte::Generado, $dte->fresh()->estado);
        $this->assertDatabaseHas('dte_estado_historial', [
            'dte_id' => $dte->id,
            'estado_anterior' => 'borrador',
            'estado_nuevo' => 'generado',
            'user_id' => $user->id,
            'comentario' => 'Emisión',
        ]);
    }

    public function test_transicion_invalida_falla_y_no_cambia_estado(): void
    {
        $dte = $this->crearDte('borrador');

        try {
            app(DteStateMachine::class)->transicionar($dte, EstadoDte::Aceptado);
            $this->fail('Debió lanzar TransicionInvalidaException.');
        } catch (TransicionInvalidaException $e) {
            // esperado
        }

        $this->assertSame(EstadoDte::Borrador, $dte->fresh()->estado);
        $this->assertDatabaseCount('dte_estado_historial', 0);
    }

    public function test_maquina_de_estados_si_cambia_estado_de_documento_emitido(): void
    {
        // El Observer permite el cambio de ESTADO (no de contenido) en documentos emitidos.
        $dte = $this->crearDte('generado');

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Firmado);

        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
    }

    // --- Policy ---

    public function test_policy_gestor_edita_solo_borrador(): void
    {
        $admin = $this->usuario('administrador');
        $facturacion = $this->usuario('facturacion');

        $borrador = $this->crearDte('borrador');
        $generado = $this->crearDte('generado');

        $this->assertTrue(Gate::forUser($admin)->allows('update', $borrador));
        $this->assertTrue(Gate::forUser($facturacion)->allows('update', $borrador));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $generado));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $generado));
    }

    public function test_policy_lectores_no_editan_pero_ven(): void
    {
        $consulta = $this->usuario('consulta');
        $contador = $this->usuario('contador');
        $borrador = $this->crearDte('borrador');

        $this->assertTrue(Gate::forUser($consulta)->allows('view', $borrador));
        $this->assertTrue(Gate::forUser($contador)->allows('view', $borrador));
        $this->assertFalse(Gate::forUser($consulta)->allows('update', $borrador));
        $this->assertFalse(Gate::forUser($contador)->allows('create', Dte::class));
    }
}
