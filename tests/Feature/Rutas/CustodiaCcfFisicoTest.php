<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoCustodia;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FuncionPersonalRuta;
use App\Enums\RolEnSalida;
use App\Enums\TipoEventoCustodia;
use App\Exceptions\Rutas\DocumentoYaRecibidoException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\CustodiaDocumentoEvento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\SalidaRutaParticipante;
use App\Models\User;
use App\Services\Rutas\AsignadorDocumentos;
use App\Services\Rutas\Custodia;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * La custodia del CCF FÍSICO: quién se llevó el papel, por qué manos pasó y cuándo volvió.
 *
 * ─────────────────────── Lo que estas pruebas defienden ───────────────────────
 *
 * 1. Que ENTREGA y CUSTODIA no se confundan. El albarán prueba que el cliente recibió la
 *    mercadería; el papel firmado es otra cosa, con otro dueño y otra fecha. Un documento
 *    puede estar entregado hace tres semanas y con el papel perdido, y esa combinación
 *    —invisible antes— es justo la que el módulo existe para mostrar.
 *
 * 2. Que la RECEPCIÓN sea de oficina. Quien llevaba el papel no puede declarar que la
 *    empresa ya lo recibió: son dos actores y dos permisos.
 *
 * 3. Que no se pueda recibir dos veces. Ni escaneando dos veces, ni con dos pestañas a la
 *    vez: lo primero lo atajan las comprobaciones, lo segundo el índice único.
 *
 * 4. Que nada se borre. Un registro mal hecho se ANULA con otro que lo compensa y exige
 *    motivo; el original queda.
 */
class CustodiaCcfFisicoTest extends TestCase
{
    use RefreshDatabase;

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

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function persona(string $nombre, bool $activo = true, array $funciones = []): PersonalRuta
    {
        $persona = PersonalRuta::create(['nombre' => $nombre, 'activo' => $activo]);

        foreach ($funciones as $funcion) {
            $persona->funciones()->create(['funcion' => $funcion]);
        }

        return $persona->load('funciones');
    }

    private function sala(?Ruta $ruta, string $nombre = 'Selectos San Miguel'): ClienteSucursal
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
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

    /** CCF de producción aceptado de verdad: es lo único que puede viajar en una salida. */
    private function ccf(ClienteSucursal $sala, string $control, string $oc = '260602320012345'): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => $control,
            'numero_orden_compra' => $oc,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 136.33,
        ]);
    }

    /** Documento ya dentro de una salida, listo para que empiece su custodia. */
    private function documento(SalidaRuta $salida, ClienteSucursal $sala, string $control, string $oc = '260602320012345'): SalidaRutaDocumento
    {
        return app(AsignadorDocumentos::class)->agregarDte($salida, $this->ccf($sala, $control, $oc), null);
    }

    /**
     * Mete a estas personas en la salida.
     *
     * La custodia solo le entrega el papel a quien VIAJÓ, así que casi toda prueba de
     * custodia necesita esto antes. Se escribe el participante directo y no con
     * {@see ParticipantesSalida::sincronizar()} porque ese exige personas activas, y algunas
     * pruebas necesitan justamente a alguien que se desactivó con el papel en la mano.
     */
    private function enLaSalida(SalidaRuta $salida, PersonalRuta ...$personas): void
    {
        foreach ($personas as $persona) {
            SalidaRutaParticipante::firstOrCreate(
                ['salida_ruta_id' => $salida->id, 'rutas_personal_id' => $persona->id],
                ['rol' => RolEnSalida::Acompanante],
            );
        }
    }

    private function custodia(): Custodia
    {
        return app(Custodia::class);
    }

    // ══════════════════════════════ entrega inicial

    public function test_bodega_entrega_varios_ccf_a_una_persona(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $vendedor = $this->persona('Rene Barillas', funciones: [FuncionPersonalRuta::Vendedor->value]);
        $usuario = $this->usuario();
        $this->enLaSalida($salida, $vendedor);

        $documentos = collect(['001', '002', '003'])->map(
            fn ($n) => $this->documento($salida, $sala, 'DTE-03-M001P002-00000000000'.$n, '26060232001234'.$n)
        );

        foreach ($documentos as $documento) {
            $this->custodia()->entregar($documento, $vendedor, $usuario);
        }

        foreach ($documentos as $documento) {
            $this->assertSame(EstadoCustodia::ConPersonal, $this->custodia()->estado($documento));
            $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento)?->id);
        }

        // Tres eventos, uno por documento: la bitácora cuenta hechos, no lotes.
        $this->assertSame(3, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::EntregaAPersonal)->count());
        $this->assertTrue(Activity::where('log_name', 'custodia_documento')->exists());
    }

    public function test_no_se_entrega_a_una_persona_inactiva(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $inactivo = $this->persona('Ex Vendedor', activo: false);

        try {
            $this->custodia()->entregar($documento, $inactivo, $this->usuario());
            $this->fail('No debería poder entregarse un documento a alguien inactivo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('inactivo', $e->getMessage());
        }

        $this->assertSame(EstadoCustodia::EnBodega, $this->custodia()->estado($documento));
    }

    // ══════════════════════════════ transferencia

    public function test_un_vendedor_le_pasa_el_papel_al_responsable(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen', funciones: [FuncionPersonalRuta::ResponsableSalida->value]);
        $usuario = $this->usuario();
        $this->enLaSalida($salida, $vendedor, $responsable);

        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->custodia()->entregar($documento, $vendedor, $usuario);
        $this->custodia()->transferir($documento, $responsable, $usuario);

        $this->assertSame($responsable->id, $this->custodia()->tenedorActual($documento)?->id);

        // El paso por las manos del vendedor NO se pierde: es la única forma de poder
        // preguntarle a alguien concreto por un documento que no aparece.
        $historial = $this->custodia()->historial($documento);
        $this->assertCount(2, $historial);
        $this->assertSame($vendedor->id, $historial->last()->origen_personal_id);
        $this->assertSame($responsable->id, $historial->last()->destino_personal_id);
    }

    public function test_no_se_transfiere_un_documento_que_nadie_tiene(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->expectException(ValidationException::class);
        $this->custodia()->transferir($documento, $this->persona('Alguien'), $this->usuario());
    }

    // ══════════════════════════════ recepción

    public function test_la_recepcion_individual_registra_el_evento_y_la_proyeccion(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $vendedor = $this->persona('Rene Barillas');
        $recepcionista = $this->usuario();
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $this->enLaSalida($salida, $vendedor);

        $this->custodia()->entregar($documento, $vendedor, $recepcionista);

        $this->actingAs($recepcionista)
            ->post(route('rutas.recepcion.recibir'), ['documento_id' => $documento->id])
            ->assertRedirect(route('rutas.recepcion.index'))
            ->assertSessionHas('status');

        $documento->refresh();

        // El evento manda…
        $this->assertSame(EstadoCustodia::Recibido, $this->custodia()->estado($documento));
        // …y las columnas heredadas quedan sincronizadas: son su proyección, no otra verdad.
        $this->assertNotNull($documento->documentacion_fisica_recibida_at);
        $this->assertSame($recepcionista->id, $documento->documentacion_fisica_recibida_por);
        $this->assertTrue($documento->documentacionFisicaRecibida());
    }

    public function test_la_recepcion_por_lote_confirma_solo_lo_marcado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $usuario = $this->usuario();

        $unos = collect(['001', '002'])->map(fn ($n) => $this->documento($salida, $sala, 'DTE-03-M001P002-00000000000'.$n, '26060232001234'.$n));
        $fuera = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000003', '260602320012343');

        $this->actingAs($usuario)
            ->post(route('rutas.recepcion.lote'), ['documentos' => $unos->pluck('id')->all()])
            ->assertRedirect(route('rutas.recepcion.index'));

        foreach ($unos as $documento) {
            $this->assertTrue($documento->refresh()->documentacionFisicaRecibida());
        }

        // El que no se marcó no se toca: una operación por lote solo confirma lo que la
        // persona vio antes de guardar.
        $this->assertFalse($fuera->refresh()->documentacionFisicaRecibida());
    }

    // ══════════════════════════════ doble recepción y concurrencia

    public function test_recibir_dos_veces_no_duplica_nada_y_lo_explica(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $usuario = $this->usuario();
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->custodia()->recibir($documento, $usuario);
        $recibidoEn = $documento->refresh()->documentacion_fisica_recibida_at;

        // Segundo escaneo del mismo papel: se responde, no se rompe.
        $this->actingAs($usuario)
            ->post(route('rutas.recepcion.recibir'), ['documento_id' => $documento->id])
            ->assertSessionHas('error');

        $this->assertSame(1, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::RecepcionOficina)->count());
        $this->assertEquals($recibidoEn, $documento->refresh()->documentacion_fisica_recibida_at);
    }

    public function test_dos_recepciones_simultaneas_no_pueden_convivir(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->custodia()->recibir($documento, $this->usuario());

        // Se simula la carrera saltándose la comprobación del servicio y escribiendo el
        // evento directamente, que es lo que haría una segunda petición que leyó «libre»
        // antes de que la primera confirmara. El índice único es el que tiene que
        // rechazarlo: la comprobación en PHP puede perder la carrera, el índice no.
        $this->expectException(QueryException::class);

        CustodiaDocumentoEvento::create([
            'salida_ruta_documento_id' => $documento->id,
            'salida_ruta_id' => $salida->id,
            'tipo' => TipoEventoCustodia::RecepcionOficina,
            'ocurrido_en' => now(),
            'recepcion_vigente' => 1,
        ]);
    }

    // ══════════════════════════════ corrección

    public function test_anular_una_recepcion_exige_motivo_y_devuelve_el_documento_a_pendiente(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $usuario = $this->usuario();
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $evento = $this->custodia()->recibir($documento, $usuario);

        // Un motivo de relleno no explica nada.
        $this->actingAs($usuario)
            ->delete(route('rutas.custodia.anular', $evento), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');
        $this->assertTrue($documento->refresh()->documentacionFisicaRecibida());

        $this->actingAs($usuario)
            ->delete(route('rutas.custodia.anular', $evento), [
                'motivo' => 'Se escaneó el documento equivocado: el papel sigue con el vendedor.',
            ])
            ->assertSessionHas('status');

        $documento->refresh();

        // Vuelve a estar pendiente y la proyección se limpia en la misma transacción.
        $this->assertFalse($documento->documentacionFisicaRecibida());
        $this->assertNull($documento->documentacion_fisica_recibida_por);
        $this->assertSame(EstadoCustodia::EnBodega, $this->custodia()->estado($documento));

        // El evento original NO se borra: queda marcado y con su anulación al lado.
        $this->assertTrue($evento->refresh()->anulado);
        $this->assertNull($evento->recepcion_vigente);
        $this->assertSame(2, CustodiaDocumentoEvento::where('salida_ruta_documento_id', $documento->id)->count());
    }

    public function test_tras_anular_se_puede_volver_a_recibir(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $usuario = $this->usuario();
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $evento = $this->custodia()->recibir($documento, $usuario);
        $this->custodia()->anular($evento, 'Se registró sobre el documento equivocado.', $usuario);

        // El índice único quedó libre: anular no puede dejar el documento imposible de
        // recibir para siempre.
        $this->custodia()->recibir($documento, $usuario);

        $this->assertTrue($documento->refresh()->documentacionFisicaRecibida());
        $this->assertSame(2, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::RecepcionOficina)->count());
    }

    // ══════════════════════════════ las dimensiones no se confunden

    public function test_el_albaran_no_da_por_recibido_el_papel(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Llega el AC01: el cliente recibió la mercadería.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => '260602320012345',
            'monto_albaran' => 136.33,
            'fecha_albaran' => now()->subDays(10)->toDateString(),
            'origen' => 'gmail',
        ]);

        $documento->refresh();

        $this->assertTrue($documento->entregado(), 'El albarán debería probar la entrega.');
        // Y sin embargo el papel sigue sin volver. Son dos hechos, en dos manos.
        $this->assertFalse($documento->documentacionFisicaRecibida());
        $this->assertSame(EstadoCustodia::EnBodega, $documento->estadoCustodia());
    }

    public function test_recibir_el_papel_no_es_cobrar(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->custodia()->recibir($documento, $this->usuario());
        $documento->refresh();

        $this->assertTrue($documento->documentacionFisicaRecibida());
        // Que el papel esté de vuelta habilita el cobro; no es el cobro.
        $this->assertFalse($documento->pagado());
        $this->assertFalse($documento->enPpq());
        $this->assertNull($documento->montoCobrado());
    }

    // ══════════════════════════════ permisos

    public function test_quien_lleva_el_papel_no_puede_declarar_que_la_oficina_lo_recibio(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Un usuario de campo: puede registrar movimientos de custodia, no recepciones.
        $campo = User::factory()->create();
        $campo->givePermissionTo(['rutas.ver', 'rutas.custodia.ver', 'rutas.custodia.registrar']);

        $this->actingAs($campo)
            ->post(route('rutas.recepcion.recibir'), ['documento_id' => $documento->id])
            ->assertForbidden();

        $this->assertFalse($documento->refresh()->documentacionFisicaRecibida());
    }

    public function test_anular_una_custodia_exige_su_propio_permiso(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $evento = $this->custodia()->recibir($documento, $this->usuario());

        // Recepción puede recibir, pero corregir es otro acto y otro permiso.
        $recepcion = User::factory()->create();
        $recepcion->givePermissionTo(['rutas.ver', 'rutas.custodia.ver', 'rutas.recepcion']);

        $this->actingAs($recepcion)
            ->delete(route('rutas.custodia.anular', $evento), ['motivo' => 'Intento sin permiso de corregir.'])
            ->assertForbidden();

        $this->assertFalse($evento->refresh()->anulado);
    }

    // ══════════════════════════════ los hechos de campo, por HTTP

    /**
     * Hasta acá la entrega y la transferencia se probaban llamando al servicio. Estas
     * pruebas entran por la ruta real: son las que defienden que el controlador valide,
     * que el permiso esté puesto y que el documento sea de ESA salida.
     */
    public function test_bodega_entrega_el_papel_desde_la_pantalla_de_la_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas', funciones: [FuncionPersonalRuta::Vendedor->value]);
        $this->enLaSalida($salida, $vendedor);

        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), [
                'destino_personal_id' => $vendedor->id,
            ])
            ->assertSessionHas('status');

        $this->assertSame(EstadoCustodia::ConPersonal, $this->custodia()->estado($documento->refresh()));
        $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento)?->id);
    }

    public function test_transferir_por_http_exige_a_quien_le_queda_el_papel(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        // Sin destino no hay transferencia: el papel no puede quedar en el aire.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.transferir', [$salida, $documento]), [])
            ->assertSessionHasErrors('destino_personal_id');

        $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento)?->id);
    }

    public function test_una_incidencia_sin_descripcion_no_se_registra(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        // Una incidencia sin texto no le sirve a nadie: no dice qué pasó.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), [])
            ->assertSessionHasErrors('observacion');

        $this->assertSame(EstadoCustodia::ConPersonal, $this->custodia()->estado($documento->refresh()));

        // Con descripción sí, y el documento pasa a estar señalado.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), [
                'observacion' => 'Se mojó en la sala y quedó ilegible.',
            ])
            ->assertSessionHas('status');

        $this->assertSame(EstadoCustodia::Incidencia, $this->custodia()->estado($documento->refresh()));
    }

    public function test_los_hechos_de_campo_exigen_permiso_de_registrar(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');

        // Consultar dónde está cada papel no habilita a mover ninguno.
        $mirón = User::factory()->create();
        $mirón->givePermissionTo(['rutas.ver', 'rutas.custodia.ver']);

        $this->actingAs($mirón)
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), [
                'destino_personal_id' => $vendedor->id,
            ])
            ->assertForbidden();

        $this->assertSame(EstadoCustodia::EnBodega, $this->custodia()->estado($documento->refresh()));
    }

    public function test_no_se_mueve_un_documento_que_es_de_otra_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salidaA = $this->salida($ruta);
        $salidaB = $this->salida($ruta);
        $documento = $this->documento($salidaA, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');

        // El enlace quedó viejo o alguien está probando ids: 404 y no se toca nada.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salidaB, $documento]), [
                'destino_personal_id' => $vendedor->id,
            ])
            ->assertNotFound();

        $this->assertSame(EstadoCustodia::EnBodega, $this->custodia()->estado($documento->refresh()));
    }

    /**
     * La excepción no es un fallo: es la respuesta, y lleva dentro CUÁNDO y QUIÉN recibió
     * para que la pantalla pueda decirlo en vez de un «no se pudo».
     */
    public function test_recibir_dos_veces_lanza_la_excepcion_con_cuando_y_quien(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $recepcionista = $this->usuario();
        $primera = $this->custodia()->recibir($documento, $recepcionista);

        try {
            $this->custodia()->recibir($documento->refresh(), $this->usuario());
            $this->fail('Se esperaba DocumentoYaRecibidoException.');
        } catch (DocumentoYaRecibidoException $e) {
            $this->assertSame($documento->numeroLegible(), $e->numeroControl);
            $this->assertSame($primera->id, $e->recepcionAnterior?->id);
            $this->assertStringContainsString($recepcionista->name, $e->getMessage());
        }

        $this->assertSame(1, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::RecepcionOficina)->count());
    }

    // ══════════════════════════════ el panel de custodia en el detalle

    /**
     * El panel vive DENTRO de la tarjeta de cada documento del detalle de la salida.
     *
     * Estas pruebas lo miran desde donde lo mira una persona: abren la pantalla y comprueban
     * qué se ofrece. Sin ellas, los endpoints podrían seguir existiendo sin que nadie pueda
     * llegar a ellos —que es exactamente el agujero que este panel vino a cerrar—.
     */
    public function test_el_detalle_ofrece_las_acciones_de_custodia_del_documento(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);

        $respuesta = $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida));

        $respuesta->assertOk()
            // En bodega: sale de bodega, o se reporta que ya hay un problema.
            ->assertSee(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), false)
            ->assertSee(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), false)
            // El destino solo puede ser quien va en la salida.
            ->assertSee('Rene Barillas');
    }

    public function test_las_opciones_del_panel_cambian_con_el_estado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);

        $entregar = route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]);
        $transferir = route('rutas.salidas.documentos.custodia.transferir', [$salida, $documento]);

        // EN BODEGA: se entrega, no se transfiere. Nadie lo tiene todavía.
        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertSee($entregar, false)
            ->assertDontSee($transferir, false);

        // CON PERSONAL: se transfiere, no se vuelve a entregar desde bodega.
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertSee($transferir, false)
            ->assertDontSee($entregar, false);

        // RECIBIDO: desde campo no se toca nada.
        $this->custodia()->recibir($documento->refresh(), $this->usuario());

        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertDontSee($entregar, false)
            ->assertDontSee($transferir, false)
            ->assertDontSee(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), false);
    }

    /**
     * Quien solo puede MIRAR no ve botones. Un botón que al pulsarlo da 403 no informa: hace
     * que la persona crea que el sistema falla.
     */
    public function test_quien_no_puede_registrar_no_ve_las_acciones_del_panel(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);

        $miron = User::factory()->create();
        $miron->givePermissionTo(['rutas.ver', 'rutas.custodia.ver']);

        $this->actingAs($miron)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            // El estado sí se ve: consultar dónde está el papel es justo lo que puede hacer.
            ->assertSee(EstadoCustodia::EnBodega->label())
            ->assertDontSee(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), false)
            ->assertDontSee(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), false);
    }

    /**
     * La recepción NO está en el panel del vendedor, en ningún estado. Es la separación que
     * sostiene todo el control: quien llevó el papel no declara que la oficina lo recibió.
     */
    public function test_el_panel_de_campo_nunca_ofrece_recibir_en_oficina(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            // Ningún formulario de esta pantalla envía a la recepción de oficina. Se compara
            // contra el `action=` y no contra la URL suelta porque `rutas.recepcion.index`
            // —el enlace del menú— comparte URI con `rutas.recepcion.recibir`: una es GET y
            // la otra POST. Lo que importa es que desde acá no se pueda POSTear.
            ->assertDontSee('action="'.route('rutas.recepcion.recibir').'"', false)
            ->assertDontSee('action="'.route('rutas.recepcion.lote').'"', false)
            // Y sí se dice dónde se hace, para que nadie la busque acá.
            ->assertSee('Recepción de CCF');
    }

    public function test_el_panel_muestra_el_historial_en_orden(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $this->custodia()->transferir($documento, $responsable, $this->usuario());

        $html = $this->actingAs($this->usuario())
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->getContent();

        // Del más viejo al más nuevo: la entrega aparece antes que la transferencia.
        $entrega = strpos($html, 'bodega entregó el documento');
        $traspaso = strpos($html, 'el documento cambió de manos');

        $this->assertNotFalse($entrega);
        $this->assertNotFalse($traspaso);
        $this->assertLessThan($traspaso, $entrega);
    }

    /**
     * Un error tiene que poder atenderse. Con treinta tarjetas plegadas y un mensaje arriba,
     * saber a CUÁL documento se refería obligaría a abrirlas de a una: la vista reabre el
     * panel que falló.
     */
    public function test_un_error_reabre_el_panel_del_documento_que_fallo(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $otroDocumento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000002', '260602320012346');
        $ajeno = $this->persona('Ajeno Al Viaje');

        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), [
                'destino_personal_id' => $ajeno->id,
            ])
            ->assertSessionHas('custodia_abierta', $documento->id);

        // Y la pantalla siguiente lo abre: solo ese, no todos.
        $html = $this->actingAs($this->usuario())
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="custodia-'.$documento->id.'" open', $html);
        $this->assertStringNotContainsString('id="custodia-'.$otroDocumento->id.'" open', $html);
    }

    // ══════════════════════════════ lo que el servidor rechaza igual

    public function test_no_se_le_entrega_el_papel_a_quien_no_va_en_la_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Existe y está activa, pero viajó en otra salida —o en ninguna—.
        $ajeno = $this->persona('Ajeno Al Viaje');

        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), [
                'destino_personal_id' => $ajeno->id,
            ])
            ->assertSessionHasErrors('destino');

        $this->assertSame(EstadoCustodia::EnBodega, $this->custodia()->estado($documento->refresh()));
        $this->assertSame(0, CustodiaDocumentoEvento::count());
    }

    public function test_tampoco_se_transfiere_hacia_alguien_ajeno_a_la_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        $ajeno = $this->persona('Ajeno Al Viaje');

        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.transferir', [$salida, $documento]), [
                'destino_personal_id' => $ajeno->id,
                'custodio_esperado_id' => $vendedor->id,
            ])
            ->assertSessionHasErrors('destino');

        // El papel sigue donde estaba.
        $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento->refresh())?->id);
    }

    public function test_no_se_entrega_dos_veces_el_mismo_documento(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $otro = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $otro);

        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        // Segunda entrega desde bodega: crearía un eslabón que dice que bodega lo tenía
        // cuando ya lo tenía una persona.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.entregar', [$salida, $documento]), [
                'destino_personal_id' => $otro->id,
            ])
            ->assertSessionHasErrors('destino');

        $this->assertSame(1, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::EntregaAPersonal)->count());
        $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento->refresh())?->id);
    }

    /**
     * Dos compañeros con la misma pantalla abierta. El primero transfiere; el segundo envía
     * un formulario que dice que el papel lo tiene alguien que ya no lo tiene, y se rechaza
     * en vez de encadenarse sobre un origen que esa persona nunca vio.
     */
    public function test_no_se_transfiere_desde_un_custodio_desactualizado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $tercero = $this->persona('Marta Elena');
        $this->enLaSalida($salida, $vendedor, $responsable, $tercero);

        $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        // Alguien más rápido ya lo pasó al responsable.
        $this->custodia()->transferir($documento, $responsable, $this->usuario());

        // Esta pantalla todavía creía que lo tenía el vendedor.
        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.transferir', [$salida, $documento]), [
                'destino_personal_id' => $tercero->id,
                'custodio_esperado_id' => $vendedor->id,
            ])
            ->assertSessionHasErrors('destino');

        $this->assertSame($responsable->id, $this->custodia()->tenedorActual($documento->refresh())?->id);
        $this->assertSame(2, CustodiaDocumentoEvento::count());
    }

    public function test_una_incidencia_no_da_el_papel_por_recibido_ni_por_perdido(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('rutas.salidas.documentos.custodia.incidencia', [$salida, $documento]), [
                'observacion' => 'Se quedó en la sala de Selectos; vuelvo el jueves.',
            ])
            ->assertSessionHas('status');

        $documento->refresh();

        // Señalado, no cerrado: ni recibido…
        $this->assertSame(EstadoCustodia::Incidencia, $this->custodia()->estado($documento));
        $this->assertFalse($documento->documentacionFisicaRecibida());
        // …ni perdido para siempre: el papel puede aparecer y volver a salir de bodega.
        $this->assertContains(
            TipoEventoCustodia::EntregaAPersonal,
            Custodia::accionesDeCampo($this->custodia()->estado($documento))
        );
        // Y la explicación queda en la bitácora.
        $this->assertStringContainsString('Selectos', $this->custodia()->historial($documento)->last()->observacion);
    }

    // ══════════════════════════════ anulación: corrección administrativa

    /**
     * Anular no es un hecho de campo: contradice uno ya asentado. Por eso tiene su propio
     * permiso, vive en el historial y no entre entregar/transferir/reportar, y exige motivo.
     *
     * La regla que lo sostiene todo es «solo el último vigente»: deshacer desde el final, un
     * evento a la vez. Anular uno del medio dejaría una cadena que no describe ninguna
     * realidad —el papel llegando a alguien de manos de nadie—.
     */
    public function test_solo_se_anula_el_ultimo_registro_no_uno_del_medio(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $this->custodia()->transferir($documento, $responsable, $this->usuario());

        // La entrega quedó en el medio: ya no se puede tocar sin deshacer lo de encima.
        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $entrega), [
                'motivo' => 'Me equivoqué de documento al registrar la entrega.',
            ])
            ->assertSessionHasErrors('motivo');

        $this->assertFalse($entrega->refresh()->anulado);
        $this->assertSame($responsable->id, $this->custodia()->tenedorActual($documento->refresh())?->id);
        $this->assertSame(2, CustodiaDocumentoEvento::count());
    }

    public function test_un_registro_ya_anulado_no_se_anula_dos_veces(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);

        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $this->custodia()->anular($entrega, 'Se registró sobre el documento equivocado.', $this->usuario());

        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $entrega), [
                'motivo' => 'Otra vez, por si acaso, a ver si pasa.',
            ])
            ->assertSessionHasErrors('motivo');

        // Una sola anulación: la segunda no escribió nada.
        $this->assertSame(1, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::Anulacion)->count());
    }

    public function test_una_anulacion_no_se_anula(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);

        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $anulacion = $this->custodia()->anular($entrega, 'Se registró sobre el documento equivocado.', $this->usuario());

        // Deshacer un deshacer no es corregir: si el hecho pasó de verdad, se registra de nuevo.
        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $anulacion), [
                'motivo' => 'En realidad la entrega sí había ocurrido.',
            ])
            ->assertSessionHasErrors('motivo');

        $this->assertFalse($anulacion->refresh()->anulado);
    }

    /**
     * Dos personas con el historial abierto. La primera registra un hecho nuevo; la segunda
     * pulsa «anular» sobre lo que su pantalla mostraba como último y ya no lo es.
     */
    public function test_anular_desde_una_pantalla_vieja_se_rechaza(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        // La pantalla se dibujó acá: la entrega era el último registro.
        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        // Mientras tanto, un compañero transfiere desde su teléfono.
        $this->custodia()->transferir($documento, $responsable, $this->usuario());

        $respuesta = $this->actingAs($this->usuario())
            ->from(route('rutas.salidas.show', $salida))
            ->delete(route('rutas.custodia.anular', $entrega), [
                'motivo' => 'Creí que esto seguía siendo lo último registrado.',
            ]);

        $respuesta->assertSessionHasErrors('motivo');
        // El mensaje dice cuál es el último ahora, para que se pueda actuar sin adivinar.
        $this->assertStringContainsString(
            TipoEventoCustodia::Transferencia->label(),
            session('errors')->first('motivo')
        );

        $this->assertFalse($entrega->refresh()->anulado);
        $this->assertSame(2, CustodiaDocumentoEvento::count());
    }

    /**
     * Lo importante de anular: que el documento quede EXACTAMENTE como estaba antes del
     * registro deshecho, y que el registro deshecho siga ahí con quién, cuándo y por qué.
     */
    public function test_anular_restaura_exactamente_el_custodio_y_el_estado_anteriores(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        $quienRegistro = $this->usuario();
        $entrega = $this->custodia()->entregar($documento, $vendedor, $quienRegistro);
        $transferencia = $this->custodia()->transferir($documento, $responsable, $quienRegistro);

        // Estado ANTES de anular, para compararlo después.
        $this->assertSame($responsable->id, $this->custodia()->tenedorActual($documento)?->id);

        $corrector = $this->usuario();
        $this->actingAs($corrector)
            ->delete(route('rutas.custodia.anular', $transferencia), [
                'motivo' => 'La transferencia se registró sobre el documento equivocado.',
            ])
            ->assertSessionHas('status');

        $documento->refresh();

        // El papel vuelve a manos del vendedor, con el estado y la fecha de la entrega.
        $this->assertSame($vendedor->id, $this->custodia()->tenedorActual($documento)?->id);
        $this->assertSame(EstadoCustodia::ConPersonal, $this->custodia()->estado($documento));
        $this->assertEquals(
            $entrega->ocurrido_en,
            $this->custodia()->ultimoVigente($documento)?->ocurrido_en
        );

        // El registro anulado NO se borra y conserva su contenido íntegro.
        $transferencia->refresh();
        $this->assertTrue($transferencia->anulado);
        $this->assertSame($vendedor->id, $transferencia->origen_personal_id);
        $this->assertSame($responsable->id, $transferencia->destino_personal_id);
        $this->assertSame($quienRegistro->id, $transferencia->registrado_por);

        // Y la anulación dice quién corrigió, cuándo y por qué.
        $anulacion = CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::Anulacion)->sole();
        $this->assertSame($transferencia->id, $anulacion->anula_evento_id);
        $this->assertSame($corrector->id, $anulacion->registrado_por);
        $this->assertNotNull($anulacion->ocurrido_en);
        $this->assertStringContainsString('documento equivocado', $anulacion->motivo);
    }

    /**
     * Anular un hecho de custodia no puede tocar lo que no es custodia: ni la recepción en
     * oficina, ni la entrega probada por el albarán AC01, ni nada de PPQ.
     */
    public function test_anular_una_transferencia_no_toca_la_recepcion_ni_el_albaran(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        // El cliente ya recibió la mercadería: eso lo prueba el AC01 y no depende de nadie.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => '260602320012345',
            'monto_albaran' => 136.33,
            'fecha_albaran' => now()->subDays(10)->toDateString(),
            'origen' => 'gmail',
        ]);

        $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $transferencia = $this->custodia()->transferir($documento, $responsable, $this->usuario());

        $documento->refresh();
        $this->assertTrue($documento->entregado());

        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $transferencia), [
                'motivo' => 'El papel nunca cambió de manos; fue un error de registro.',
            ])
            ->assertSessionHas('status');

        $documento->refresh();

        // La entrega AC01 sigue igual…
        $this->assertTrue($documento->entregado());
        // …y la recepción en oficina sigue sin ocurrir: anular una transferencia no la inventa
        // ni la borra.
        $this->assertNull($documento->documentacion_fisica_recibida_at);
        $this->assertNull($documento->documentacion_fisica_recibida_por);
        $this->assertFalse($documento->documentacionFisicaRecibida());
    }

    public function test_la_anulacion_exige_una_explicacion_de_verdad(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        // Sin motivo: ni se intenta.
        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $entrega), [])
            ->assertSessionHasErrors('motivo');

        // Con un motivo que no explica nada, tampoco.
        $this->actingAs($this->usuario())
            ->delete(route('rutas.custodia.anular', $entrega), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');

        $this->assertFalse($entrega->refresh()->anulado);
        $this->assertSame(0, CustodiaDocumentoEvento::deTipo(TipoEventoCustodia::Anulacion)->count());
    }

    // ══════════════════════════════ la anulación en la pantalla

    public function test_la_anulacion_aparece_en_el_historial_solo_con_su_permiso(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $this->enLaSalida($salida, $vendedor);
        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());

        $anular = route('rutas.custodia.anular', $entrega);

        // Quien registra hechos de campo NO corrige: es otro acto y otro permiso.
        $campo = User::factory()->create();
        $campo->givePermissionTo(['rutas.ver', 'rutas.custodia.ver', 'rutas.custodia.registrar']);

        $this->actingAs($campo)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertDontSee('action="'.$anular.'"', false);

        // El administrador sí, y aparece dentro del historial.
        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('action="'.$anular.'"', false)
            ->assertSee('Anular este registro');
    }

    public function test_solo_el_ultimo_registro_ofrece_anular_en_la_pantalla(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $vendedor = $this->persona('Rene Barillas');
        $responsable = $this->persona('Lucia Del Carmen');
        $this->enLaSalida($salida, $vendedor, $responsable);

        $entrega = $this->custodia()->entregar($documento, $vendedor, $this->usuario());
        $transferencia = $this->custodia()->transferir($documento, $responsable, $this->usuario());

        $this->actingAs($this->usuario())->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            // El último sí…
            ->assertSee('action="'.route('rutas.custodia.anular', $transferencia).'"', false)
            // …y el del medio no: el servidor lo rechazaría igual, pero un botón que no
            // funciona hace creer que el sistema falla.
            ->assertDontSee('action="'.route('rutas.custodia.anular', $entrega).'"', false);
    }

    // ══════════════════════════════ compatibilidad con lo anterior

    public function test_una_fila_antigua_sin_eventos_sigue_contando_como_recibida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $usuario = $this->usuario();
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Así quedaban las filas antes de que existiera la bitácora: las dos columnas
        // escritas y ningún evento detrás.
        $documento->forceFill([
            'documentacion_fisica_recibida_at' => now()->subMonth(),
            'documentacion_fisica_recibida_por' => $usuario->id,
        ])->save();

        $documento->refresh();

        // Lo que ya leía media aplicación sigue funcionando igual.
        $this->assertTrue($documento->documentacionFisicaRecibida());
        $this->assertSame(1, SalidaRutaDocumento::conDocumentacionFisica()->count());

        // La bitácora no inventa un evento que nadie registró: dice «en bodega» porque no
        // consta ningún movimiento. Son dos preguntas distintas y ninguna miente.
        $this->assertSame(0, CustodiaDocumentoEvento::count());
        $this->assertSame(EstadoCustodia::EnBodega, $documento->estadoCustodia());
    }

    public function test_la_recepcion_sigue_disponible_con_la_salida_finalizada(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $salida->finalizar();

        // El papel casi siempre vuelve DESPUÉS de cerrar el viaje: bloquearlo acá dejaría
        // sin registrar la mitad de las recepciones reales.
        $this->custodia()->recibir($documento->refresh(), $this->usuario());

        $this->assertTrue($documento->refresh()->documentacionFisicaRecibida());
    }

    public function test_una_salida_cancelada_no_admite_hechos_de_custodia(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $salida->cancelar();

        // Una salida cancelada nunca ocurrió: no puede tener hechos operativos nuevos.
        $this->expectException(HttpException::class);
        $this->custodia()->recibir($documento->refresh(), $this->usuario());
    }

    // ══════════════════════════════ participantes

    public function test_una_salida_lleva_un_responsable_y_dos_acompanantes(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);

        $responsable = $this->persona('Lucia Del Carmen', funciones: [FuncionPersonalRuta::ResponsableSalida->value]);
        $uno = $this->persona('Rene Barillas');
        $dos = $this->persona('Javier Perez');

        app(ParticipantesSalida::class)->sincronizar(
            $salida,
            [$responsable->id, $uno->id, $dos->id],
            $responsable->id,
        );

        $salida->load('participantes.personal');

        $this->assertCount(3, $salida->participantes);
        $this->assertSame($responsable->id, $salida->responsable->rutas_personal_id);
        $this->assertSame(
            2,
            $salida->participantes->where('rol', RolEnSalida::Acompanante)->count(),
        );
    }

    public function test_el_responsable_es_por_salida_y_no_fija_a_nadie_a_una_ruta(): void
    {
        $sanMiguel = Ruta::create(['nombre' => 'San Miguel']);
        $sonsonate = Ruta::create(['nombre' => 'Sonsonate']);
        $persona = $this->persona('Rene Barillas');
        $otra = $this->persona('Javier Perez');

        $viajeUno = $this->salida($sanMiguel, EstadoSalidaRuta::Planificada);
        $viajeDos = $this->salida($sonsonate, EstadoSalidaRuta::Planificada);

        app(ParticipantesSalida::class)->sincronizar($viajeUno, [$persona->id, $otra->id], $persona->id);
        app(ParticipantesSalida::class)->sincronizar($viajeDos, [$persona->id, $otra->id], $otra->id);

        // La misma persona: responsable en un viaje, acompañante en el otro. Y ninguna de
        // las dos rutas queda «suya».
        $this->assertSame($persona->id, $viajeUno->refresh()->responsable->rutas_personal_id);
        $this->assertSame($otra->id, $viajeDos->refresh()->responsable->rutas_personal_id);
        $this->assertSame(2, $persona->salidas()->count());
    }

    public function test_no_puede_haber_dos_responsables_en_la_misma_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);
        $uno = $this->persona('Rene Barillas');
        $dos = $this->persona('Javier Perez');

        app(ParticipantesSalida::class)->sincronizar($salida, [$uno->id, $dos->id], $uno->id);

        // El candado es de BASE: la comprobación en PHP puede perder una carrera entre dos
        // pestañas, el índice único no.
        $this->expectException(QueryException::class);

        SalidaRutaParticipante::where('salida_ruta_id', $salida->id)
            ->where('rutas_personal_id', $dos->id)
            ->update(['rol' => RolEnSalida::Responsable->value, 'responsable_unico' => 1]);
    }

    public function test_el_responsable_tiene_que_ir_en_la_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);
        $va = $this->persona('Rene Barillas');
        $noVa = $this->persona('Javier Perez');

        $this->expectException(ValidationException::class);
        app(ParticipantesSalida::class)->sincronizar($salida, [$va->id], $noVa->id);
    }

    public function test_una_salida_puede_no_tener_responsable(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Planificada);
        $sola = $this->persona('Rene Barillas');

        // Una salida de una sola persona no necesita que nadie responda por el grupo.
        app(ParticipantesSalida::class)->sincronizar($salida, [$sola->id], null);

        $this->assertNull($salida->refresh()->responsable);
        $this->assertCount(1, $salida->participantes);
    }
}
