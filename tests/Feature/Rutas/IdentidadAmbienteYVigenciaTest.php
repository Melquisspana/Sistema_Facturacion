<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Exceptions\Rutas\DocumentoNoVigenteException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\AsignadorAutomaticoDocumentos;
use App\Services\Rutas\AsignadorDocumentos;
use App\Services\Rutas\CandidatosDocumentos;
use App\Support\IdentidadPpq;
use App\Support\VigenciaFiscalDte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un documento de PRUEBAS no puede bloquear, cobrar ni prestarle datos a uno de PRODUCCIÓN.
 *
 * ─────────────────────────── De dónde sale el problema ───────────────────────────
 *
 * `dtes.numero_control` dejó de ser único cuando la unicidad pasó a ser
 * `(ambiente, numero_control)`, y por una razón correcta: los correlativos de pruebas (00)
 * y de producción (01) cuentan desde cero de forma independiente, así que el primer
 * documento REAL de una serie coincide exactamente con uno de prueba ya emitido en esa
 * misma posición. En la base de desarrollo hay cuatro pares así conviviendo.
 *
 * Rutas seguía tratando el número de control como identidad completa. Con eso, un CCF de
 * prueba metido en una salida hacía que el real «ya tuviera dueño», lo escondía de los
 * candidatos, y al teclear su número en el alta de históricos se resolvía al de pruebas
 * —con su sala, su fecha y su monto—.
 *
 * ─────────────────────────── Las dos defensas ───────────────────────────
 *
 *  1. La PUERTA: a una salida de ruta solo entra un documento fiscalmente vigente
 *     ({@see VigenciaFiscalDte}), y eso excluye por completo el ambiente de
 *     pruebas. Un documento simulado no llega a tener fila.
 *  2. La IDENTIDAD: donde hay que resolver un DTE desde un número, se resuelve con el
 *     ambiente ({@see IdentidadPpq::dteLocal()}), no a ciegas.
 *
 * Las dos hacen falta: la primera evita el caso, la segunda evita que reaparezca por otra
 * puerta el día que alguien relaje la primera.
 */
class IdentidadAmbienteYVigenciaTest extends TestCase
{
    use RefreshDatabase;

    private const CONTROL = 'DTE-03-M001P002-000000000000003';

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

            return Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'activo' => true,
            ]);
        });
    }

    private function p002(): PuntoVenta
    {
        return PuntoVenta::firstOrCreate(
            ['establecimiento_id' => $this->establecimiento()->id, 'codigo' => 'P002'],
            ['nombre' => 'Sistema nuevo', 'activo' => true],
        );
    }

    private function sala(?Ruta $ruta): ClienteSucursal
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => 'Selectos San Miguel',
            'codigo' => '0232',
            'ruta_id' => $ruta?->id,
        ]);
    }

    private function salida(Ruta $ruta, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => $estado,
        ]);
    }

    /**
     * CCF de PRODUCCIÓN aceptado de verdad por Hacienda.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ccfReal(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'punto_venta_id' => $this->p002()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260602320012345',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 136.33,
        ]);
    }

    /** El gemelo de pruebas: MISMO número de control, ambiente 00. */
    private function ccfDePruebas(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'punto_venta_id' => $this->p002()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '00',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260609990099999',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '09:00:00',
            'total_pagar' => 1.00,
        ]);
    }

    // ═════════════════════════ la coexistencia es real y legítima

    public function test_el_mismo_numero_de_control_existe_en_pruebas_y_en_produccion(): void
    {
        $sala = $this->sala(null);
        $real = $this->ccfReal($sala, self::CONTROL);
        $prueba = $this->ccfDePruebas($sala, self::CONTROL);

        // No es un dato corrupto: es lo que la base permite a propósito desde que la
        // unicidad es (ambiente, numero_control).
        $this->assertNotSame($real->id, $prueba->id);
        $this->assertSame(2, Dte::where('numero_control', self::CONTROL)->count());
    }

    // ═════════════════════════ 1) la puerta

    public function test_un_ccf_de_pruebas_no_puede_entrar_a_una_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $prueba = $this->ccfDePruebas($sala, self::CONTROL);

        $this->expectException(DocumentoNoVigenteException::class);

        app(AsignadorDocumentos::class)->agregarDte($salida, $prueba, null);
    }

    public function test_tampoco_entra_un_borrador_ni_un_rechazado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        foreach (['borrador', 'rechazado', 'invalidado'] as $i => $estado) {
            $dte = $this->ccfReal($sala, 'DTE-03-M001P002-00000000000010'.$i, ['estado' => $estado]);

            try {
                app(AsignadorDocumentos::class)->agregarDte($salida, $dte, null);
                $this->fail("Un CCF en estado {$estado} no debería poder viajar en una salida.");
            } catch (DocumentoNoVigenteException $e) {
                $this->assertStringContainsString($dte->numero_control, $e->getMessage());
            }
        }

        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_un_aceptado_con_sello_simulado_tampoco_entra(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        // Sello MOCK: la aceptación es de una prueba, su código de generación no existe
        // en Hacienda.
        $dte = $this->ccfReal($sala, self::CONTROL, ['sello_recepcion' => 'MOCK-1234567890']);

        $this->expectException(DocumentoNoVigenteException::class);
        app(AsignadorDocumentos::class)->agregarDte($salida, $dte, null);
    }

    public function test_el_ccf_real_si_entra_y_guarda_su_ambiente(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $real = $this->ccfReal($sala, self::CONTROL);

        $documento = app(AsignadorDocumentos::class)->agregarDte($salida, $real, null);

        $this->assertSame($real->id, $documento->dte_id);
        // El ambiente queda como snapshot: es la otra mitad de la identidad fiscal.
        $this->assertSame('01', $documento->ambiente);
    }

    // ═════════════════════════ 2) no se bloquean entre sí

    public function test_un_documento_de_pruebas_asignado_no_bloquea_al_real(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $real = $this->ccfReal($sala, self::CONTROL);
        $prueba = $this->ccfDePruebas($sala, self::CONTROL);

        // Se fuerza la fila del documento de pruebas saltándose la puerta, para probar la
        // segunda defensa por separado: aunque una fila así existiera (por un dato viejo
        // o por otra vía), el CCF real tiene que poder asociarse igual.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => $prueba->id,
            'numero_control' => $prueba->numero_control,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            'ambiente' => '00',
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $decision = app(AsignadorAutomaticoDocumentos::class)->evaluar($real);

        // Antes esto respondía YA_ASIGNADO y el CCF real no se asociaba nunca.
        $this->assertNotSame(AsignadorAutomaticoDocumentos::YA_ASIGNADO, $decision['estado']);
        $this->assertSame(AsignadorAutomaticoDocumentos::ASIGNADO, $decision['estado']);
    }

    public function test_el_mismo_documento_si_se_reconoce_como_ya_asignado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $real = $this->ccfReal($sala, self::CONTROL);

        app(AsignadorDocumentos::class)->agregarDte($salida, $real, null);

        $this->assertSame(
            AsignadorAutomaticoDocumentos::YA_ASIGNADO,
            app(AsignadorAutomaticoDocumentos::class)->evaluar($real)['estado'],
        );
    }

    // ═════════════════════════ 3) candidatos

    public function test_los_candidatos_no_ofrecen_documentos_de_pruebas_ni_borradores(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $real = $this->ccfReal($sala, self::CONTROL);
        $this->ccfDePruebas($sala, 'DTE-03-M001P002-000000000000009');
        $this->ccfReal($sala, 'DTE-03-M001P002-000000000000010', ['estado' => 'borrador']);

        $candidatos = app(CandidatosDocumentos::class)->paraSalida($salida);

        $this->assertCount(1, $candidatos->items());
        $this->assertSame($real->id, $candidatos->items()[0]->id);
    }

    public function test_el_gemelo_de_pruebas_asignado_no_esconde_al_real_de_los_candidatos(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $real = $this->ccfReal($sala, self::CONTROL);
        $prueba = $this->ccfDePruebas($sala, self::CONTROL);

        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => $prueba->id,
            'numero_control' => $prueba->numero_control,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            'ambiente' => '00',
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $ids = collect(app(CandidatosDocumentos::class)->paraSalida($salida)->items())->pluck('id');

        $this->assertTrue($ids->contains($real->id), 'El CCF real quedó escondido por su gemelo de pruebas.');
    }

    public function test_un_historico_en_una_salida_abierta_si_esconde_al_documento(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $real = $this->ccfReal($sala, self::CONTROL);

        // Fila HISTÓRICA (sin `dte_id` ni ambiente: no está en `dtes`). Que no tenga
        // ambiente no la vuelve inofensiva: retiene ese número de control igual, y
        // ofrecerlo otra vez terminaría en un choque al guardar.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'numero_control' => self::CONTROL,
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $ids = collect(app(CandidatosDocumentos::class)->paraSalida($salida)->items())->pluck('id');

        $this->assertFalse($ids->contains($real->id));
    }

    // ═════════════════════════ 4) resolución por número

    public function test_resolver_un_numero_de_control_prefiere_el_documento_que_existe_ante_hacienda(): void
    {
        $sala = $this->sala(null);
        $prueba = $this->ccfDePruebas($sala, self::CONTROL);
        $real = $this->ccfReal($sala, self::CONTROL);

        $this->assertSame($real->id, IdentidadPpq::dteLocal(self::CONTROL)?->id);

        // Y se puede pedir uno concreto cuando quien llama sí sabe de qué ambiente habla.
        $this->assertSame($prueba->id, IdentidadPpq::dteLocal(self::CONTROL, '00')?->id);
    }

    public function test_resolver_tolera_el_numero_escrito_sin_separadores(): void
    {
        $sala = $this->sala(null);
        $real = $this->ccfReal($sala, self::CONTROL);

        $this->assertSame($real->id, IdentidadPpq::dteLocal('DTE03M001P002000000000000003')?->id);
        $this->assertNull(IdentidadPpq::dteLocal(null));
        $this->assertNull(IdentidadPpq::dteLocal('   '));
    }

    public function test_el_alta_de_historico_no_agarra_el_gemelo_de_pruebas(): void
    {
        $usuario = User::factory()->create();
        $usuario->givePermissionTo(['rutas.ver', 'rutas.gestionar']);

        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $this->ccfDePruebas($sala, self::CONTROL);
        $real = $this->ccfReal($sala, self::CONTROL);

        $this->actingAs($usuario)
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => self::CONTROL,
            ])
            ->assertRedirect(route('rutas.salidas.show', $salida));

        // Se agregó el REAL, con su vínculo y su ambiente. Antes podía quedar el de
        // pruebas —con la sala, la fecha y el monto equivocados— sin ninguna señal.
        $documento = SalidaRutaDocumento::sole();
        $this->assertSame($real->id, $documento->dte_id);
        $this->assertSame('01', $documento->ambiente);
    }

    public function test_un_numero_que_existe_solo_como_documento_invalido_no_se_guarda_como_historico(): void
    {
        $usuario = User::factory()->create();
        $usuario->givePermissionTo(['rutas.ver', 'rutas.gestionar']);

        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $this->ccfReal($sala, self::CONTROL, ['estado' => 'rechazado']);

        // Existe y está mal: no se cae al camino histórico, que guardaría una copia
        // congelada de un documento que sí conocemos y que no ampara nada.
        $this->actingAs($usuario)
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => self::CONTROL,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, SalidaRutaDocumento::count());
    }
}
