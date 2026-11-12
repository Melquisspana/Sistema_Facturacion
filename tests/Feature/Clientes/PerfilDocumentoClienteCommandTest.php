<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando `perfil-documento:cliente` rechaza lo mismo que rechaza el formulario del
 * perfil documental: una tolerancia negativa, un código con caracteres raros o una tasa
 * fuera de 0 a 100 no se guardan. Lo inválido se rechaza ANTES de escribir.
 */
class PerfilDocumentoClienteCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(): Cliente
    {
        return Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
    }

    public function test_guarda_un_perfil_valido(): void
    {
        $cliente = $this->cliente();

        $this->artisan('perfil-documento:cliente', [
            'cliente' => $cliente->id,
            '--activar' => true,
            '--tolerancia' => '0.05',
            '--mapear' => ['averia:ac02:ccf', 'devolucion_producto:AC04:tasa_propia:5.50'],
        ])->assertSuccessful();

        $perfil = ClientePerfilDocumento::where('cliente_id', $cliente->id)->sole();
        $this->assertTrue($perfil->activo);
        $this->assertSame('0.05', number_format((float) $perfil->tolerancia_albaran, 2));
        $this->assertSame(['AC02', 'AC04'], $perfil->tiposNc()->orderBy('codigo_externo')->pluck('codigo_externo')->all());
        $this->assertSame('5.50', (string) $perfil->tiposNc()->where('codigo_externo', 'AC04')->sole()->descuento_tasa);
    }

    public function test_rechaza_una_tolerancia_negativa_sin_escribir_nada(): void
    {
        $cliente = $this->cliente();

        $this->artisan('perfil-documento:cliente', [
            'cliente' => $cliente->id, '--activar' => true, '--tolerancia' => '-1',
        ])->expectsOutputToContain('tolerancia contra el albarán')->assertFailed();

        $this->assertSame(0, ClientePerfilDocumento::count());
    }

    public function test_rechaza_una_tolerancia_que_no_es_un_numero(): void
    {
        $cliente = $this->cliente();

        $this->artisan('perfil-documento:cliente', [
            'cliente' => $cliente->id, '--activar' => true, '--tolerancia' => 'mucha',
        ])->expectsOutputToContain('tolerancia contra el albarán')->assertFailed();

        $this->assertSame(0, ClientePerfilDocumento::count());
    }

    public function test_rechaza_un_codigo_externo_con_el_formato_que_el_formulario_no_admite(): void
    {
        $cliente = $this->cliente();

        foreach (['AC-02', 'AC 02', 'ABCDEFGHIJK'] as $codigo) {
            $this->artisan('perfil-documento:cliente', [
                'cliente' => $cliente->id, '--activar' => true, '--mapear' => ["averia:{$codigo}:ccf"],
            ])->expectsOutputToContain('código externo')->assertFailed();
        }

        $this->assertSame(0, ClientePerfilDocumento::count(), 'Ni el perfil queda creado.');
        $this->assertSame(0, ClientePerfilTipoNc::count());
    }

    public function test_rechaza_una_tasa_fuera_de_cero_a_cien(): void
    {
        $cliente = $this->cliente();

        foreach (['150', '-5', '100.01'] as $tasa) {
            $this->artisan('perfil-documento:cliente', [
                'cliente' => $cliente->id, '--activar' => true, '--mapear' => ["averia:AC02:tasa_propia:{$tasa}"],
            ])->expectsOutputToContain('tasa')->assertFailed();
        }

        $this->assertSame(0, ClientePerfilDocumento::count());
        $this->assertSame(0, ClientePerfilTipoNc::count());
    }

    public function test_un_mapeo_invalido_no_deja_guardado_el_mapeo_valido_que_venia_antes(): void
    {
        $cliente = $this->cliente();

        $this->artisan('perfil-documento:cliente', [
            'cliente' => $cliente->id,
            '--activar' => true,
            '--mapear' => ['averia:AC02:ccf', 'devolucion_producto:AC04:tasa_propia:250'],
        ])->assertFailed();

        $this->assertSame(0, ClientePerfilDocumento::count());
        $this->assertSame(0, ClientePerfilTipoNc::count());
    }
}
