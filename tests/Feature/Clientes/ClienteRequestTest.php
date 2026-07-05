<?php

namespace Tests\Feature\Clientes;

use App\Http\Requests\Clientes\ClienteRequest;
use App\Models\ActividadEconomica;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Pais;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClienteRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
    }

    /** Ejecuta el Form Request y devuelve los errores (vacío si pasó). */
    private function validar(array $data): array
    {
        $request = ClienteRequest::create('/clientes', 'POST', $data);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        try {
            $request->validateResolved();

            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function baseNacional(): array
    {
        $sansal = Departamento::where('codigo', '06')->firstOrFail();
        $muni = Municipio::where('departamento_id', $sansal->id)->where('nombre', 'San Salvador')->firstOrFail();
        $sv = Pais::where('codigo', 'SV')->firstOrFail();

        return [
            'nombre' => 'Juan Pérez',
            'pais_id' => $sv->id,
            'departamento_id' => $sansal->id,
            'municipio_id' => $muni->id,
            'activo' => '1',
        ];
    }

    public function test_consumidor_final_valido_pasa(): void
    {
        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'consumidor_final',
            'tipo_documento' => '13', // DUI
            'num_documento' => '00000000-0', // DUI con dígito verificador válido
        ]);

        $this->assertSame([], $this->validar($data));
    }

    public function test_consumidor_final_no_requiere_nrc(): void
    {
        $data = array_merge($this->baseNacional(), ['tipo_cliente' => 'consumidor_final']);

        $this->assertArrayNotHasKey('nrc', $this->validar($data));
    }

    public function test_contribuyente_requiere_nrc_nit_y_actividad(): void
    {
        $data = array_merge($this->baseNacional(), ['tipo_cliente' => 'contribuyente']);

        $errores = $this->validar($data);

        $this->assertArrayHasKey('nrc', $errores);
        $this->assertArrayHasKey('tipo_documento', $errores);
        $this->assertArrayHasKey('actividad_economica_id', $errores);
    }

    public function test_contribuyente_completo_valido_pasa(): void
    {
        $actividad = ActividadEconomica::firstOrFail();
        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'contribuyente',
            'tipo_persona' => 'juridica',
            'tipo_documento' => '36', // NIT
            'num_documento' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'actividad_economica_id' => $actividad->id,
            'nombre' => 'Distribuidora La Negrita, S.A. de C.V.',
        ]);

        $this->assertSame([], $this->validar($data));
    }

    public function test_nacional_exige_pais_el_salvador(): void
    {
        $usa = Pais::where('codigo', 'US')->firstOrFail();
        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'consumidor_final',
            'pais_id' => $usa->id, // país extranjero en cliente nacional
        ]);

        $this->assertArrayHasKey('pais_id', $this->validar($data));
    }

    public function test_municipio_de_otro_departamento_falla(): void
    {
        $laPaz = Departamento::where('codigo', '08')->firstOrFail();
        $olocuilta = Municipio::where('nombre', 'Olocuilta')->firstOrFail(); // pertenece a La Paz

        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'consumidor_final',
            'departamento_id' => $laPaz->id,
            'municipio_id' => Municipio::where('nombre', 'San Salvador')->firstOrFail()->id, // de otro depto
        ]);

        $this->assertArrayHasKey('municipio_id', $this->validar($data));
        $this->assertArrayNotHasKey('municipio_id', $this->validar(array_merge($data, ['municipio_id' => $olocuilta->id])));
    }

    public function test_dui_invalido_falla(): void
    {
        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'consumidor_final',
            'tipo_documento' => '13',
            'num_documento' => '00000000-5', // dígito verificador incorrecto
        ]);

        $this->assertArrayHasKey('num_documento', $this->validar($data));
    }

    public function test_nit_con_formato_invalido_falla(): void
    {
        $actividad = ActividadEconomica::firstOrFail();
        $data = array_merge($this->baseNacional(), [
            'tipo_cliente' => 'contribuyente',
            'tipo_documento' => '36',
            'num_documento' => '123', // formato NIT inválido
            'nrc' => '123456-7',
            'actividad_economica_id' => $actividad->id,
        ]);

        $this->assertArrayHasKey('num_documento', $this->validar($data));
    }

    public function test_exportacion_valida_pasa(): void
    {
        $usa = Pais::where('codigo', 'US')->firstOrFail();
        $data = [
            'tipo_cliente' => 'exportacion',
            'tipo_persona' => 'juridica',
            'tipo_documento' => '03', // Pasaporte
            'num_documento' => 'P1234567',
            'nombre' => 'Sweet Imports LLC',
            'pais_id' => $usa->id,
            'direccion' => '123 Main St, Miami, FL',
            'activo' => '1',
        ];

        $this->assertSame([], $this->validar($data));
    }

    public function test_exportacion_sin_direccion_falla(): void
    {
        $usa = Pais::where('codigo', 'US')->firstOrFail();
        $data = [
            'tipo_cliente' => 'exportacion',
            'tipo_persona' => 'juridica',
            'tipo_documento' => '03',
            'num_documento' => 'P1234567',
            'nombre' => 'Sweet Imports LLC',
            'pais_id' => $usa->id,
            'activo' => '1',
        ];

        $this->assertArrayHasKey('direccion', $this->validar($data));
    }

    public function test_exportacion_no_acepta_pais_el_salvador(): void
    {
        $sv = Pais::where('codigo', 'SV')->firstOrFail();
        $data = [
            'tipo_cliente' => 'exportacion',
            'tipo_persona' => 'juridica',
            'tipo_documento' => '03',
            'num_documento' => 'P1234567',
            'nombre' => 'Sweet Imports LLC',
            'pais_id' => $sv->id, // El Salvador no es válido para exportación
            'direccion' => 'Dir',
            'activo' => '1',
        ];

        $this->assertArrayHasKey('pais_id', $this->validar($data));
    }

    public function test_tipo_cliente_invalido_falla(): void
    {
        $data = array_merge($this->baseNacional(), ['tipo_cliente' => 'inexistente']);

        $this->assertArrayHasKey('tipo_cliente', $this->validar($data));
    }
}
