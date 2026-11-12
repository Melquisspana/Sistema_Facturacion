<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\LoteDte;
use App\Models\User;
use App\Services\Dte\CopiaDeAceptadoTardio;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\LoteDteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * LA COPIA DE UN RECHAZADO QUE HACIENDA DESPUÉS ACEPTÓ NO SE EMITE SIN QUE ALGUIEN LO MIRE.
 *
 * Un CCF rechazado se «corrige» duplicándolo. Si después llega el sello de Hacienda para
 * el original, las dos son la misma venta: emitir la copia la factura dos veces. La copia
 * queda frenada —generar, firmar y transmitir, por pantalla, por comando y por lote— hasta
 * que alguien con permiso de emitir diga, con un motivo, que es otra venta. Nada se
 * invalida ni se mueve solo.
 */
class CopiaDeAceptadoTardioTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    private User $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
        $this->habilitar();
        $this->emisor = $this->usuario();
    }

    /** Un CCF que Hacienda rechazó. Devuelve además la copia del pedido que llegará tarde. */
    private function rechazado(): Dte
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba', 'observaciones' => []], 400)]);
        $this->assertSame('rechazado', app(DteTransmisionService::class)->transmitir($dte)['resultado']);

        return $dte->refresh();
    }

    private function copiaDe(Dte $original): Dte
    {
        return $this->borradores->duplicarCcf($original, $this->emisor)->refresh();
    }

    /** Llega el sello de Hacienda para el original, que acá figuraba rechazado. */
    private function haciendaAceptaTarde(Dte $original): void
    {
        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta(Dte::findOrFail($original->id), [
            'resultado' => 'aceptado', 'http_status' => 200, 'mensaje' => 'RECIBIDO', 'sello' => 'SELLO-DE-PRUEBA-TARDIO',
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-TARDIO', 'codigoGeneracion' => $original->codigo_generacion],
        ]);
        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $original->fresh()->estado);
        Http::swap(new Factory);
        Http::fake();
    }

    /** @return array{0: Dte, 1: Dte} original aceptado tarde y su copia en borrador */
    private function conflicto(): array
    {
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);
        $this->haciendaAceptaTarde($original);

        return [$original->refresh(), $copia];
    }

    private function resolver(Dte $copia, string $motivo = 'Es otro pedido de la misma sala.'): void
    {
        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.copia-aceptado.resolver', $copia), ['motivo' => $motivo])
            ->assertSessionHasNoErrors();
    }

    // ─────────── El freno, en cada paso de la emisión ───────────

    public function test_duplicar_deja_anotado_de_que_documento_es_la_copia(): void
    {
        $original = $this->rechazado();

        $copia = $this->copiaDe($original);

        $anotacion = Activity::where('log_name', CopiaDeAceptadoTardio::BITACORA)->where('subject_id', $copia->id)->sole();
        $this->assertSame($original->id, $anotacion->properties['original_id']);
        $this->assertSame('rechazado', $anotacion->properties['original_estado']);
    }

    public function test_la_copia_no_se_puede_generar(): void
    {
        [$original, $copia] = $this->conflicto();

        try {
            app(DteGeneracionService::class)->generar($copia);
            $this->fail('La copia de un documento que Hacienda aceptó no se genera sin revisarla.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('es copia del', $e->getMessage());
            $this->assertStringContainsString($original->numero_control, $e->getMessage());
        }

        $this->assertSame(EstadoDte::Borrador, $copia->fresh()->estado);
        $this->assertSame(1, (int) Correlativo::where('tipo_dte', '03')->sole()->ultimo_numero);
    }

    public function test_la_pantalla_de_generar_lo_dice_y_no_genera(): void
    {
        [, $copia] = $this->conflicto();

        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.generar', $copia))
            ->assertRedirect(route('facturacion.edit', $copia))
            ->assertSessionHasErrors('generar');

        $this->assertStringContainsString('es copia del', session('errors')->first('generar'));
        $this->assertSame(EstadoDte::Borrador, $copia->fresh()->estado);
    }

    public function test_una_copia_ya_generada_no_se_puede_firmar(): void
    {
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);
        app(DteGeneracionService::class)->generar($copia);
        $this->haciendaAceptaTarde($original);

        try {
            app(DteFirmaService::class)->firmar($copia->refresh());
            $this->fail('La copia no se firma sin revisarla.');
        } catch (DteFirmaException $e) {
            $this->assertStringContainsString('es copia del', $e->getMessage());
        }

        $this->actingAs($this->emisor)->post(route('facturacion.firmar-transmitir', $copia))
            ->assertRedirect(route('facturacion.show', $copia))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'es copia del'));
        $this->assertSame(EstadoDte::Generado, $copia->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_una_copia_ya_firmada_no_se_puede_transmitir_ni_por_pantalla_ni_por_comando(): void
    {
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);
        app(DteGeneracionService::class)->generar($copia);
        app(DteFirmaService::class)->firmar($copia->refresh());
        $this->haciendaAceptaTarde($original);
        $copia->refresh();

        try {
            app(DteTransmisionService::class)->transmitir($copia);
            $this->fail('La copia no se transmite sin revisarla.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('es copia del', $e->getMessage());
        }

        $this->actingAs($this->emisor)->post(route('facturacion.firmar-transmitir', $copia))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'es copia del'));
        $this->artisan('dte:transmitir', ['dte' => $copia->id])
            ->expectsOutputToContain('es copia del')
            ->assertExitCode(1);

        $this->assertSame(EstadoDte::Firmado, $copia->fresh()->estado);
        // Ni el envío ni la consulta previa: se frena antes de hablar con Hacienda.
        Http::assertNothingSent();
    }

    public function test_una_copia_en_contingencia_no_entra_en_el_lote_que_se_manda_a_hacienda(): void
    {
        config(['dte.contingencia.enabled' => true, 'dte.firma.mock' => false]);
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);
        app(DteGeneracionService::class)->generar($copia);
        $contingencia = Contingencia::create(['tipo' => 3, 'origen' => 'manual', 'inicio' => now()->subHours(3),
            'cese' => now()->subHours(2), 'estado' => 'informada']);
        $evento = $contingencia->eventos()->create(['parte' => 1, 'codigo_generacion' => strtoupper((string) Str::uuid()),
            'estado' => 'recibido', 'sello_recibido' => 'SELLO-EVENTO-DE-PRUEBA',
            'fecha_transmision' => now()->subHour(), 'fecha_procesamiento' => now()->subHour()]);
        // La copia y otro documento cualquiera quedaron firmados en esa contingencia.
        $otro = $this->generado();
        foreach ([$copia, $otro] as $dte) {
            Storage::disk('local')->put('dte/firmados/prueba-'.$dte->id.'.jws', 'FIRMA.DE.PRUEBA.'.$dte->id);
            Dte::whereKey($dte->id)->update(['estado' => EstadoDte::Firmado->value, 'json_firmado_path' => 'dte/firmados/prueba-'.$dte->id.'.jws',
                'contingencia_id' => $contingencia->id, 'contingencia_evento_id' => $evento->id]);
        }
        $this->haciendaAceptaTarde($original);

        app(LoteDteService::class)->preparar($evento);

        $this->assertNull($copia->fresh()->lote_dte_id, 'La copia en conflicto no se manda en ningún lote.');
        $this->assertNotNull($otro->fresh()->lote_dte_id);

        // Y si el conflicto aparece con el lote ya armado, ese lote no sale.
        $loteDelOtro = LoteDte::findOrFail($otro->fresh()->lote_dte_id);
        Dte::whereKey($copia->id)->update(['lote_dte_id' => $loteDelOtro->id]);
        try {
            app(LoteDteService::class)->enviarLote($loteDelOtro);
            $this->fail('Un lote con la copia en conflicto no se envía.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('es copia del', implode(' ', $e->validator->errors()->all()));
        }
        Http::assertNothingSent();
        Dte::whereKey($copia->id)->update(['lote_dte_id' => null]);

        // Resuelto el conflicto, entra en el lote siguiente.
        $this->resolver($copia->fresh());
        app(LoteDteService::class)->preparar($evento);
        $this->assertNotNull($copia->fresh()->lote_dte_id);
    }

    // ─────────── Resolverlo es una decisión explícita ───────────

    public function test_quien_puede_emitir_dice_que_es_otra_venta_con_un_motivo_y_la_copia_se_puede_generar(): void
    {
        [$original, $copia] = $this->conflicto();

        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.copia-aceptado.resolver', $copia), ['motivo' => 'Es otro pedido de la misma sala.'])
            ->assertRedirect(route('facturacion.edit', $copia))
            ->assertSessionHas('status');

        $anotacion = Activity::where('log_name', CopiaDeAceptadoTardio::BITACORA)
            ->where('description', CopiaDeAceptadoTardio::RESUELTO)->where('subject_id', $copia->id)->sole();
        $this->assertSame($this->emisor->id, (int) $anotacion->causer_id);
        $this->assertSame('Es otro pedido de la misma sala.', $anotacion->properties['motivo']);
        $this->assertSame($original->id, $anotacion->properties['original_id']);

        app(DteGeneracionService::class)->generar($copia->fresh());
        $this->assertSame(EstadoDte::Generado, $copia->fresh()->estado);
        // Nada se invalidó ni se movió solo.
        $this->assertSame(EstadoDte::Aceptado, $original->fresh()->estado);
    }

    public function test_sin_motivo_no_se_resuelve(): void
    {
        [, $copia] = $this->conflicto();

        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.copia-aceptado.resolver', $copia), ['motivo' => 'no'])
            ->assertSessionHasErrors('motivo');

        $this->assertNotNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($copia->fresh()));
    }

    public function test_sin_permiso_de_emitir_no_se_resuelve(): void
    {
        [, $copia] = $this->conflicto();
        // Contabilidad ve los documentos, pero no emite.
        Role::findOrCreate('contabilidad', 'web');
        $sinPermiso = User::factory()->create()->assignRole('contabilidad');

        $this->actingAs($sinPermiso)->post(route('facturacion.copia-aceptado.resolver', $copia), ['motivo' => 'Es otro pedido de la misma sala.'])
            ->assertForbidden();

        $this->assertNotNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($copia->fresh()));
    }

    public function test_donde_no_hay_conflicto_no_hay_nada_que_resolver(): void
    {
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);

        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.copia-aceptado.resolver', $copia), ['motivo' => 'Es otro pedido de la misma sala.'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, Activity::where('description', CopiaDeAceptadoTardio::RESUELTO)->count());
    }

    // ─────────── Dónde se ve ───────────

    public function test_la_ficha_de_la_copia_avisa_cual_es_el_original_y_enlaza_a_el(): void
    {
        [$original, $copia] = $this->conflicto();

        $this->actingAs($this->emisor)->get(route('facturacion.edit', $copia))
            ->assertOk()
            ->assertSee('Esta es la copia de un documento que Hacienda aceptó después')
            ->assertSee($original->numero_control)
            ->assertSee(route('facturacion.show', $original), false)
            ->assertSee('Es otra venta: emitir igual');
    }

    public function test_la_ficha_de_una_copia_ya_generada_tambien_avisa(): void
    {
        $original = $this->rechazado();
        $copia = $this->copiaDe($original);
        app(DteGeneracionService::class)->generar($copia);
        $this->haciendaAceptaTarde($original);

        $this->actingAs($this->emisor)->get(route('facturacion.show', $copia))
            ->assertOk()
            ->assertSee('Esta es la copia de un documento que Hacienda aceptó después')
            ->assertSee(route('facturacion.show', $original), false);
    }

    public function test_resuelto_el_aviso_de_la_copia_ya_no_pide_nada(): void
    {
        [, $copia] = $this->conflicto();
        $this->resolver($copia);

        $this->actingAs($this->emisor)->get(route('facturacion.edit', $copia))
            ->assertOk()
            ->assertDontSee('Esta es la copia de un documento que Hacienda aceptó después')
            ->assertDontSee('Es otra venta: emitir igual');
    }

    public function test_la_ficha_del_original_nombra_la_copia_y_enlaza_a_ella(): void
    {
        [$original, $copia] = $this->conflicto();

        $this->actingAs($this->emisor)->get(route('facturacion.show', $original))
            ->assertOk()
            ->assertSee('Hacienda aceptó este documento después de que acá figuró como rechazado')
            ->assertSee(route('facturacion.edit', $copia), false);
    }

    // ─────────── Lo que NO es un conflicto ───────────

    public function test_la_copia_de_un_rechazado_que_sigue_rechazado_se_emite_como_siempre(): void
    {
        $copia = $this->copiaDe($this->rechazado());

        $this->assertNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($copia));
        app(DteGeneracionService::class)->generar($copia);
        $this->assertSame(EstadoDte::Generado, $copia->fresh()->estado);
    }

    public function test_duplicar_un_aceptado_para_otra_venta_no_es_un_conflicto(): void
    {
        [$original] = $this->conflicto();
        // Duplicado DESPUÉS, con el original ya aceptado: es la forma normal de repetir una venta.
        $otraVenta = $this->copiaDe($original);

        $this->assertNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($otraVenta));
        app(DteGeneracionService::class)->generar($otraVenta);
        $this->assertSame(EstadoDte::Generado, $otraVenta->fresh()->estado);
    }

    public function test_un_documento_que_no_es_copia_de_nada_no_se_frena(): void
    {
        $dte = $this->borrador();

        $this->assertNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($dte));
    }

    // ─────────── A → B → C: la copia de la copia ───────────

    public function test_la_copia_en_conflicto_no_se_puede_duplicar_hasta_resolverlo(): void
    {
        [, $copia] = $this->conflicto();
        $cuantos = Dte::count();

        $this->actingAs($this->emisor)->from(route('facturacion.edit', $copia))
            ->post(route('facturacion.duplicar', $copia))
            ->assertRedirect(route('facturacion.edit', $copia))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'es copia del') && str_contains($error, 'antes de duplicarlo'));

        $this->assertSame($cuantos, Dte::count(), 'No tiene que haber nacido otra copia.');

        // Resuelto, se duplica como cualquier documento.
        $this->resolver($copia);
        $this->actingAs($this->emisor)->post(route('facturacion.duplicar', $copia))->assertSessionHasNoErrors();
        $this->assertSame($cuantos + 1, Dte::count());
    }

    public function test_la_copia_de_la_copia_tambien_queda_frenada(): void
    {
        // A rechazado → B lo corrige → C copia a B, todo antes de que llegue el sello de A.
        $a = $this->rechazado();
        $b = $this->copiaDe($a);
        $c = $this->copiaDe($b);
        $this->haciendaAceptaTarde($a);

        $motivo = app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($c);
        $this->assertNotNull($motivo, 'C es la misma venta que A: no puede emitirse sin revisarla.');
        $this->assertStringContainsString($a->numero_control, $motivo);
        try {
            app(DteGeneracionService::class)->generar($c);
            $this->fail('La copia de la copia no se genera sin revisarla.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('es copia del', $e->getMessage());
        }
        $this->assertNotNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($b));

        // Decir que C es otra venta libera a C y no a B.
        $this->resolver($c);
        $this->assertNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($c->fresh()));
        $this->assertNotNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($b->fresh()));
    }

    public function test_si_la_copia_del_medio_ya_se_resolvio_como_otra_venta_la_suya_no_hereda_el_conflicto(): void
    {
        $a = $this->rechazado();
        $b = $this->copiaDe($a);
        $c = $this->copiaDe($b);
        $this->haciendaAceptaTarde($a);

        $this->resolver($b);

        $this->assertNull(app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($c->fresh()));
    }

    // ─────────── Cadenas largas y cadenas rotas: ante la duda, se frena ───────────

    public function test_una_cadena_de_once_copias_tambien_queda_frenada(): void
    {
        $a = $this->rechazado();
        $ultima = $a;
        for ($i = 0; $i < 11; $i++) {
            $ultima = $this->copiaDe($ultima);
        }
        $this->haciendaAceptaTarde($a);

        $motivo = app(CopiaDeAceptadoTardio::class)->motivoParaNoEmitir($ultima);
        $this->assertNotNull($motivo, 'La undécima copia es la misma venta que el original.');
        $this->assertStringContainsString($a->numero_control, $motivo);
        try {
            app(DteGeneracionService::class)->generar($ultima);
            $this->fail('La undécima copia no se genera sin revisarla.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('es copia del', $e->getMessage());
        }
    }

    public function test_una_cadena_de_copias_que_se_muerde_la_cola_frena_y_pide_revision(): void
    {
        // Solo puede pasar con la bitácora tocada a mano: X copia de Y, e Y copia de X.
        $x = $this->borrador();
        $y = $this->borrador();
        $copias = app(CopiaDeAceptadoTardio::class);
        $copias->anotarCopia($x, $y, $this->emisor);
        $copias->anotarCopia($y, $x, $this->emisor);

        $motivo = $copias->motivoParaNoEmitir($x);
        $this->assertNotNull($motivo, 'Sin poder llegar al original, no se autoriza: se frena.');
        $this->assertStringContainsString('No se pudo determinar', $motivo);
        try {
            app(DteGeneracionService::class)->generar($x);
            $this->fail('Con la cadena de copias rota, el documento no se genera sin revisarlo.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('No se pudo determinar', $e->getMessage());
        }
        $this->assertNotNull($copias->motivoParaNoDuplicar($x));

        // La ficha lo dice y deja resolverlo, igual que el otro aviso.
        $this->actingAs($this->emisor)->get(route('facturacion.edit', $x))
            ->assertOk()->assertSee('No se pudo determinar de qué documento es copia')->assertSee('Es otra venta: emitir igual');
        $this->resolver($x, 'Se revisó: es un documento nuevo.');

        $this->assertNull($copias->motivoParaNoEmitir($x->fresh()));
        app(DteGeneracionService::class)->generar($x->fresh());
        $this->assertSame(EstadoDte::Generado, $x->fresh()->estado);
    }
}
