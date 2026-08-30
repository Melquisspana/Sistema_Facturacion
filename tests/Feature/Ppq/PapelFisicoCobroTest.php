<?php

namespace Tests\Feature\Ppq;

use App\Enums\ModoPapelFisico;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\PpqBusquedaService;
use App\Services\Rutas\AsignadorDocumentos;
use App\Support\PpqElegibilidad;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El CCF FÍSICO firmado y sellado como condición de cobro, declarada por cada cliente.
 *
 * ─────────────────────────── La realidad que modela ───────────────────────────
 *
 * El CCF impreso viaja con el pedido, la sala lo firma y lo sella, y el motorista debería
 * traerlo de vuelta. A veces no vuelve. Mientras nadie lo encuentra, ese documento no se
 * puede cobrar aunque el pedido esté entregado hace semanas.
 *
 * El sistema conocía la ENTREGA —el albarán llega solo al correo— pero no tenía forma de
 * exigir el PAPEL: era disciplina de oficina, sin modelar, y por eso el hueco se volvía
 * invisible justo cuando fallaba.
 *
 * ─────────────────────── Las dos garantías que fijan estas pruebas ───────────────────────
 *
 * 1. Es CONFIGURABLE por cliente y el valor por defecto no cambia nada. Sin perfil, o con
 *    perfil sin declarar el modo, el cobro se comporta exactamente como antes. En ningún
 *    lado se compara el nombre del cliente.
 *
 * 2. NO alcanza a los históricos de Gmail. Esos documentos no tienen DTE local, entran al
 *    lote como snapshot por su propio camino y ahí la condición no se consulta.
 *    Aplicárselas los bloquearía a todos de golpe.
 */
class PapelFisicoCobroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    /** Declara el modo del cliente. Por id de perfil, nunca por nombre de cliente. */
    private function perfil(ModoPapelFisico $modo): ClientePerfilDocumento
    {
        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $this->calleja()->id,
            'activo' => true,
            'modo_papel_fisico' => $modo,
        ]);

        // El resolutor memoiza por request: en una prueba que cambia el perfil en caliente
        // hay que hacerle olvidar lo que ya respondió.
        app(PerfilDocumentoResolver::class)->olvidar();

        return $perfil;
    }

    private function ccf(string $control = 'DTE-03-M001P002-000000000000001'): Dte
    {
        $sucursal = $this->calleja()->sucursales()->create(['nombre' => 'Selectos San Miguel', 'codigo' => '0232']);

        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'cliente_sucursal_id' => $sucursal->id,
            'numero_control' => $control,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '260602320012345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 136.33,
        ]);
    }

    /** Registra que el CCF físico de ese documento volvió firmado. */
    private function registrarPapelRecibido(Dte $dte): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $dte->clienteSucursal->update(['ruta_id' => $ruta->id]);

        $salida = SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => 'en_curso',
        ]);

        $asignador = app(AsignadorDocumentos::class);
        $documento = $asignador->agregarDte($salida, $dte, null);
        $asignador->marcarDocumentacionFisica($salida, [$documento->id], null);
    }

    private function lote(): PpqLote
    {
        return PpqLote::create([
            'referencia' => 'Pronto pago',
            'fecha' => now(),
            'estado' => 'borrador',
            'cliente_id' => $this->calleja()->id,
        ]);
    }

    private function agregarAlLote(PpqLote $lote, Dte $dte)
    {
        return $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'dte_id' => $dte->id,
                'sin_albaran' => '1',
            ]);
    }

    // ═════════════════════════ sin perfil: nada cambia

    public function test_un_cliente_sin_perfil_cobra_igual_que_siempre(): void
    {
        $dte = $this->ccf();

        $this->assertNull(PpqElegibilidad::motivoParaCobrar($dte));
        $this->assertTrue(PpqElegibilidad::sePuedeCobrar($dte));
        $this->assertNull(PpqElegibilidad::advertenciaParaCobrar($dte));

        $lote = $this->lote();
        $this->agregarAlLote($lote, $dte)->assertSessionMissing('error');
        $this->assertSame(1, $lote->items()->count());
    }

    public function test_un_perfil_que_no_declara_el_modo_tampoco_cambia_nada(): void
    {
        ClientePerfilDocumento::create(['cliente_id' => $this->calleja()->id, 'activo' => true]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $dte = $this->ccf();

        // El valor por defecto de la columna es `no_requerir`: el comportamiento histórico.
        $this->assertSame(
            ModoPapelFisico::NoRequerir,
            ClientePerfilDocumento::sole()->modoPapelFisico(),
        );
        $this->assertTrue(PpqElegibilidad::sePuedeCobrar($dte));
    }

    // ═════════════════════════ bloquear

    public function test_en_modo_bloquear_un_ccf_sin_el_papel_no_entra_al_lote(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $dte = $this->ccf();

        $motivo = PpqElegibilidad::motivoParaCobrar($dte);

        $this->assertNotNull($motivo);
        // El mensaje dice QUÉ falta y QUÉ hacer: un bloqueo que solo dice «no se puede»
        // obliga a adivinar a quien está intentando cobrar.
        $this->assertStringContainsString('documento físico', $motivo);
        $this->assertStringContainsString('Registrá su recepción', $motivo);

        $lote = $this->lote();
        $this->agregarAlLote($lote, $dte)->assertSessionHas('error');
        $this->assertSame(0, $lote->items()->count());
    }

    public function test_en_modo_bloquear_con_el_papel_recibido_si_entra(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $dte = $this->ccf();
        $this->registrarPapelRecibido($dte);

        $this->assertNull(PpqElegibilidad::motivoParaCobrar($dte));

        $lote = $this->lote();
        $this->agregarAlLote($lote, $dte)->assertSessionMissing('error');
        $this->assertSame(1, $lote->items()->count());
    }

    public function test_el_motivo_fiscal_manda_sobre_el_del_papel(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $dte = $this->ccf();
        $dte->update(['estado' => 'rechazado']);

        // A quien intenta cobrar un rechazado no le sirve que le digan que falta el papel:
        // el papel no arreglaría nada. Se informa la causa raíz.
        $this->assertStringContainsString('rechazó', (string) PpqElegibilidad::motivoParaCobrar($dte));
    }

    // ═════════════════════════ advertir

    public function test_en_modo_advertir_el_documento_entra_pero_queda_avisado(): void
    {
        $this->perfil(ModoPapelFisico::Advertir);
        $dte = $this->ccf();

        $this->assertNull(PpqElegibilidad::motivoParaCobrar($dte));

        $advertencia = PpqElegibilidad::advertenciaParaCobrar($dte);
        $this->assertNotNull($advertencia);
        $this->assertStringContainsString('Se puede cobrar igual', $advertencia);

        $lote = $this->lote();
        $this->agregarAlLote($lote, $dte)->assertSessionMissing('error');
        $this->assertSame(1, $lote->items()->count());
    }

    public function test_en_modo_advertir_con_el_papel_recibido_no_hay_aviso(): void
    {
        $this->perfil(ModoPapelFisico::Advertir);
        $dte = $this->ccf();
        $this->registrarPapelRecibido($dte);

        $this->assertNull(PpqElegibilidad::advertenciaParaCobrar($dte));
    }

    // ═════════════════════════ separación de dimensiones

    public function test_la_entrega_detectada_no_cuenta_como_papel_recibido(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $dte = $this->ccf();

        // Llega el albarán de entrega: el cliente recibió la mercadería.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => $dte->numero_orden_compra,
            'monto_albaran' => 136.33,
            'fecha_albaran' => now()->toDateString(),
            'origen' => 'gmail',
        ]);

        // Y sin embargo el papel sigue sin volver. Son dos hechos, en dos manos, con días
        // de diferencia: el albarán NUNCA puede dar por recuperado el CCF impreso.
        $this->assertNotNull(PpqElegibilidad::motivoParaCobrar($dte));
    }

    public function test_la_regla_fiscal_a_secas_no_mira_el_papel(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $dte = $this->ccf();

        // `motivo()` es la pregunta FISCAL y la usa la búsqueda para decidir si consulta
        // Gmail. Si el papel entrara acá, un CCF local perfectamente válido dejaría de
        // «resolver la búsqueda» y el sistema saldría a buscarlo al correo, pudiendo
        // agregarlo como snapshot histórico: un duplicado de algo que ya tenemos.
        $this->assertNull(PpqElegibilidad::motivo($dte));
        $this->assertTrue(PpqElegibilidad::esElegible($dte));
        $this->assertTrue(PpqBusquedaService::resuelveSinGmail($dte));

        // Y a la vez, cobrarlo sigue bloqueado.
        $this->assertFalse(PpqElegibilidad::sePuedeCobrar($dte));
    }

    // ═════════════════════════ compatibilidad histórica

    public function test_un_historico_de_gmail_no_queda_bloqueado_por_el_papel(): void
    {
        $this->perfil(ModoPapelFisico::Bloquear);
        $lote = $this->lote();

        // Documento de ContaPortable: no está en `dtes` y nunca lo va a estar. Entra como
        // snapshot por su propio camino, donde la regla del papel no se consulta.
        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'origen' => 'gmail',
                'numero_control' => 'DTE-03-M001P001-000000000000967',
                'tipo_dte' => '03',
                'monto_dte' => 126.44,
                'numero_orden_compra' => '260602320012345',
                'sin_albaran' => '1',
            ])
            ->assertSessionMissing('error');

        $item = PpqItem::sole();
        $this->assertSame('gmail', $item->origen);
        $this->assertNull($item->dte_id);
    }

    public function test_los_renglones_ya_cobrados_no_se_tocan_al_activar_la_regla(): void
    {
        $dte = $this->ccf();
        $lote = $this->lote();

        // Se cobra ANTES de que exista la exigencia.
        $this->agregarAlLote($lote, $dte)->assertSessionMissing('error');
        $item = PpqItem::sole();
        $item->forceFill(['conciliacion_estado' => 'pagado', 'monto_pagado' => 136.33, 'fecha_pago' => now()])->save();

        // Se activa el bloqueo para el cliente.
        $this->perfil(ModoPapelFisico::Bloquear);

        // La regla gobierna el ALTA de documentos nuevos; no reabre ni recalcula nada de
        // lo ya cobrado.
        $item->refresh();
        $this->assertSame('pagado', $item->conciliacion_estado);
        $this->assertSame('136.33', (string) $item->monto_pagado);
        $this->assertSame(1, $lote->items()->count());
    }

    // ═════════════════════════ configuración

    public function test_el_modo_se_configura_por_comando_y_no_por_nombre_de_cliente(): void
    {
        $calleja = $this->calleja();

        $this->artisan('perfil-documento:cliente', [
            'cliente' => $calleja->id,
            '--activar' => true,
            '--papel-fisico' => 'bloquear',
        ])->assertSuccessful();

        $this->assertSame(
            ModoPapelFisico::Bloquear,
            ClientePerfilDocumento::where('cliente_id', $calleja->id)->sole()->modoPapelFisico(),
        );
    }

    public function test_un_modo_mal_escrito_se_rechaza_en_vez_de_aceptarse_en_silencio(): void
    {
        $this->artisan('perfil-documento:cliente', [
            'cliente' => $this->calleja()->id,
            '--activar' => true,
            '--papel-fisico' => 'bloquear-todo',
        ])->assertFailed();

        // Aceptarlo dejaría el perfil en un estado que nadie declaró y, en el peor caso,
        // sin el bloqueo que el cliente sí exige.
        $this->assertNull(ClientePerfilDocumento::first()?->modo_papel_fisico);
    }
}
