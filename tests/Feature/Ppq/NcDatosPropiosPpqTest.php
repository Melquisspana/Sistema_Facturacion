<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Models\User;
use App\Services\Ppq\PpqBusquedaService;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LO QUE LA NOTA DE CRÉDITO YA SABE NO SE VUELVE A PEDIR.
 *
 * Una NC emitida por este sistema llega a PPQ con dos datos suyos ya guardados:
 *
 *   · el CCF que acredita, elegido al emitirla (`dte_relacionado_id`), que es un VÍNCULO
 *     y no una coincidencia de orden de compra —una misma OC ampara varios CCF—;
 *   · su albarán de crédito (`dte_albaranes`), el AC02/AC04 que la originó, con su número
 *     canónico, su fecha y su total.
 *
 * Hasta ahora la pantalla de PPQ pedía el albarán a mano aunque estuviera guardado, y el
 * CCF relacionado solo se ofrecía para las fichas de correo, por OC. Estas pruebas fijan
 * las tres cosas que no pueden volver a perderse:
 *
 *   1. que los dos datos se MUESTREN como lo que son (vínculo guardado, no sugerencia);
 *   2. que al agregar la NC al lote el albarán salga de la BASE y no del formulario —un
 *      campo oculto se cambia, una fila de `dte_albaranes` no—, conservando el monto del
 *      ALBARÁN y no el total fiscal de la nota;
 *   3. que un envío que nombre otro albarán se rechace sin escribir nada, incluido el
 *      caso traicionero del AC01 de ENTREGA con el mismo correlativo.
 *
 * Y que la NC sin albarán guardado siga pudiendo capturarlo a mano, como siempre.
 */
class NcDatosPropiosPpqTest extends TestCase
{
    use RefreshDatabase;

    /** Número canónico del albarán de crédito de la nota (AC04 = devolución). */
    private const ALBARAN_NC = 'AC04/0033/00/3209';

    /** Total impreso en el albarán: NO es el total fiscal de la nota. */
    private const TOTAL_ALBARAN = '18.50';

    /** Total fiscal de la nota, distinto a propósito. */
    private const TOTAL_NC = 20.00;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        PpqSala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    // ------------------------------------------------------------------ utilidades

    private int $proximoCorrelativo = 1;

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'PPQ datos propios', 'fecha' => now(), 'estado' => 'borrador']);
    }

    /** Documento local de producción aceptado de verdad (el caso bueno). */
    private function documento(array $extra = []): Dte
    {
        $correlativo = $this->proximoCorrelativo++;
        $tipo = $extra['tipo_dte'] ?? '03';

        return Dte::create($extra + [
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail()->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $correlativo, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    /**
     * Nota de crédito que acredita un CCF, con su albarán propio ya guardado.
     *
     * @return array{0: Dte, 1: Dte} la nota y el CCF que acredita
     */
    private function notaConDatosPropios(bool $conAlbaran = true, array $albaran = []): array
    {
        $ccf = $this->documento();

        $nc = $this->documento([
            'tipo_dte' => '05',
            'total_pagar' => self::TOTAL_NC,
            'dte_relacionado_id' => $ccf->id,
        ]);

        if ($conAlbaran) {
            // Así queda la fila cuando el albarán se capturó para poder emitir la nota.
            DteAlbaran::create($albaran + [
                'dte_id' => $nc->id,
                'numero_canonico' => self::ALBARAN_NC,
                'tipo_codigo' => 'AC04',
                'sala_codigo' => '0033',
                'numero' => '3209',
                'fecha' => '2026-08-26',
                'total' => self::TOTAL_ALBARAN,
            ]);
        }

        return [$nc->refresh(), $ccf];
    }

    /** El correlativo tal como lo teclea el usuario. */
    private function correlativo(Dte $dte): string
    {
        $secuencia = substr((string) $dte->numero_control, strrpos((string) $dte->numero_control, '-') + 1);

        return str_pad(ltrim($secuencia, '0'), 4, '0', STR_PAD_LEFT);
    }

    private function verNc(Dte $nc)
    {
        return $this->actingAs($this->usuario())
            ->get(route('ppq.index', ['q' => $this->correlativo($nc), 'tipo' => '05']));
    }

    private function agregar(PpqLote $lote, array $datos)
    {
        return $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), $datos);
    }

    /** Registro PPQ previo del MISMO albarán de la nota (mismo canónico y OC). */
    private function albaranPpqPrevio(Dte $nc, array $extra = []): PpqAlbaran
    {
        return PpqAlbaran::create($extra + [
            'numero_albaran' => self::ALBARAN_NC,
            'numero_orden_compra' => $nc->numero_orden_compra,
            'monto_albaran' => self::TOTAL_ALBARAN,
            'fecha_albaran' => '2026-08-26',
            'origen' => 'manual',
        ]);
    }

    /** Afirma el rechazo sin ninguna escritura: ni item ni albarán nuevo. */
    private function assertRechazoSinEscrituras($resp, string $fragmento, int $albaranesAntes): void
    {
        $resp->assertRedirect();
        $resp->assertSessionHas('error', fn ($m) => str_contains((string) $m, $fragmento));
        $this->assertSame(0, PpqItem::count(), 'Un rechazo no deja item.');
        $this->assertSame($albaranesAntes, PpqAlbaran::count(), 'Un rechazo no registra albaranes.');
    }

    // ------------------------------------------------------------------ lo que se ve

    public function test_la_nc_muestra_su_albaran_guardado_y_no_pide_capturarlo(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $this->lote();

        $resp = $this->verNc($nc);

        $resp->assertOk();
        $resp->assertSee('Guardado en la nota', false);
        $resp->assertSee(self::ALBARAN_NC, false);   // el canónico, con su tipo AC04
        $resp->assertSee('26/08/2026', false);       // la fecha DEL ALBARÁN
        $resp->assertSee('$18.50', false);           // su total, no el de la nota

        // Y no hay ningún campo para volver a escribir lo que ya está guardado.
        $resp->assertDontSee('name="numero_albaran"', false);
        $resp->assertDontSee('name="fecha_albaran"', false);
        $resp->assertDontSee('name="monto_albaran"', false);
        $resp->assertDontSee('Captura manual', false);
    }

    public function test_el_ccf_relacionado_se_muestra_como_vinculo_y_no_como_sugerencia_por_oc(): void
    {
        [$nc, $ccf] = $this->notaConDatosPropios();
        $this->lote();

        $resp = $this->verNc($nc);

        $resp->assertOk();
        $resp->assertSee('CCF relacionado:', false);
        $resp->assertSee($ccf->numero_control, false);
        // El texto de las fichas de correo no se usa acá: este vínculo está guardado.
        $resp->assertDontSee('Relación sugerida: misma OC', false);
    }

    public function test_la_nc_sin_albaran_guardado_conserva_la_captura_manual(): void
    {
        [$nc] = $this->notaConDatosPropios(conAlbaran: false);
        $this->lote();

        $resp = $this->verNc($nc);

        $resp->assertOk();
        $resp->assertSee('Captura manual', false);
        $resp->assertSee('name="numero_albaran"', false);
        $resp->assertDontSee('Guardado en la nota', false);
    }

    // ------------------------------------------------------------------ lo que se guarda

    public function test_agregar_la_nc_reutiliza_su_albaran_sin_recapturarlo(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        // El envío NO lleva un solo dato del albarán: solo el documento y el lote.
        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $nc->id])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains((string) $m, 'Se reutilizó el albarán guardado'));

        $albaran = PpqAlbaran::where('numero_albaran', self::ALBARAN_NC)->firstOrFail();
        // El tipo lo deriva el propio modelo del número canónico: es un AC04 de crédito,
        // nunca el AC01 de entrega del CCF.
        $this->assertSame('AC04', $albaran->tipo_codigo);
        $this->assertSame('2026-08-26', $albaran->fecha_albaran->toDateString());
        $this->assertEquals(18.50, (float) $albaran->monto_albaran);

        $item = PpqItem::where('ppq_lote_id', $lote->id)->firstOrFail();
        $this->assertSame($nc->id, $item->dte_id);
        $this->assertSame($albaran->id, $item->ppq_albaran_id);
        $this->assertFalse((bool) $item->sin_albaran);
        // Las dos magnitudes se conservan separadas: la del albarán y la fiscal de la nota.
        $this->assertEquals(18.50, (float) $item->monto_albaran);
        $this->assertEquals(self::TOTAL_NC, (float) $item->monto_dte);
        $this->assertSame($nc->numero_control, $item->numero_control);
    }

    public function test_repetir_el_mismo_albaran_no_es_contradiccion_y_sus_valores_salen_de_la_base(): void
    {
        // Reenviar el formulario con el mismo número no puede bloquear el alta: no hay
        // ninguna discrepancia que revisar. Pero la fecha y el monto que vengan en el
        // envío NO se usan: los valores del albarán salen de lo guardado en la nota.
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'dte_id' => $nc->id,
                'numero_albaran' => self::ALBARAN_NC,
                'fecha_albaran' => '2020-01-01',
                'monto_albaran' => '999.99',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, PpqItem::count());
        $this->assertSame(1, PpqAlbaran::count());

        $albaran = PpqAlbaran::firstOrFail();
        $this->assertSame('2026-08-26', $albaran->fecha_albaran->toDateString());
        $this->assertEquals(18.50, (float) $albaran->monto_albaran);
    }

    // ------------------------------------------------------------------ contradicciones

    public function test_un_envio_con_otro_numero_de_albaran_se_rechaza_sin_crear_nada(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        $resp = $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'dte_id' => $nc->id,
                'numero_albaran' => 'AC04/0033/00/9999',
                'monto_albaran' => '1.00',
                'fecha_albaran' => '2026-01-01',
            ]);

        $resp->assertRedirect();
        $resp->assertSessionHas('error', fn ($m) => str_contains((string) $m, self::ALBARAN_NC)
            && str_contains((string) $m, 'AC04/0033/00/9999'));

        $this->assertSame(0, PpqItem::count(), 'Un envío contradictorio no deja item.');
        $this->assertDatabaseMissing('ppq_albaranes', ['numero_albaran' => 'AC04/0033/00/9999']);
    }

    public function test_el_albaran_de_entrega_con_el_mismo_correlativo_no_puede_suplantar_al_de_la_nc(): void
    {
        // El caso caro: AC01 y AC04 comparten el correlativo 3209 y son papeles distintos.
        // El de entrega prueba una entrega del CCF; el de crédito, la devolución.
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        $entrega = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0033/00/3209',
            'numero_orden_compra' => $nc->numero_orden_compra,
            'monto_albaran' => 113.58,
            'fecha_albaran' => '2026-08-20',
            'origen' => 'gmail',
        ]);

        $resp = $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'dte_id' => $nc->id,
                'ppq_albaran_id' => $entrega->id,
            ]);

        $resp->assertRedirect();
        $resp->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'AC01/0033/00/3209'));

        $this->assertSame(0, PpqItem::count());
        $this->assertSame(1, PpqAlbaran::count(), 'No se registra el albarán de la nota tras rechazar.');
    }

    public function test_agregar_sin_albaran_sigue_siendo_una_decision_explicita_valida(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $nc->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $item = PpqItem::where('ppq_lote_id', $lote->id)->firstOrFail();
        $this->assertTrue((bool) $item->sin_albaran);
        $this->assertNull($item->ppq_albaran_id);
        $this->assertSame(0, PpqAlbaran::count());
    }

    // ------------------------------------------------------------------ registro PPQ previo

    public function test_registro_ppq_previo_con_otro_monto_se_rechaza_sin_sobrescribirlo(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc, ['monto_albaran' => 5.00]);

        $this->assertRechazoSinEscrituras($this->agregar($lote, ['dte_id' => $nc->id]), 'monto $5.00', 1);
        $this->assertEquals(5.00, (float) $previo->refresh()->monto_albaran, 'La evidencia previa no se toca.');
    }

    public function test_registro_ppq_previo_con_otra_fecha_se_rechaza_sin_sobrescribirlo(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc, ['fecha_albaran' => '2026-01-01']);

        $this->assertRechazoSinEscrituras($this->agregar($lote, ['dte_id' => $nc->id]), '01/01/2026', 1);
        $this->assertSame('2026-01-01', $previo->refresh()->fecha_albaran->toDateString());
    }

    public function test_registro_ppq_previo_compatible_se_reutiliza_sin_duplicar(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc);

        $this->agregar($lote, ['dte_id' => $nc->id])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, PpqAlbaran::count());
        $this->assertSame($previo->id, PpqItem::firstOrFail()->ppq_albaran_id);
    }

    public function test_registro_previo_sin_fecha_se_completa_con_la_de_la_nota(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc, ['fecha_albaran' => null]);

        $this->agregar($lote, ['dte_id' => $nc->id])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, PpqAlbaran::count());
        $this->assertSame('2026-08-26', $previo->refresh()->fecha_albaran->toDateString());
        $this->assertSame($previo->id, PpqItem::firstOrFail()->ppq_albaran_id);
    }

    public function test_parcial_con_registro_previo_compatible_informa_lo_persistido(): void
    {
        // La nota no trae fecha ni monto; el registro PPQ previo ya tiene monto pero no
        // fecha, y el envío aporta solo la fecha. El mensaje debe decir lo que quedó.
        [$nc] = $this->notaConDatosPropios(albaran: ['fecha' => null, 'total' => null]);
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc, ['fecha_albaran' => null, 'monto_albaran' => 17.25]);

        $this->agregar($lote, ['dte_id' => $nc->id, 'fecha_albaran' => '2026-08-27'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains((string) $m, 'se completó en PPQ: fecha y monto')
                && ! str_contains((string) $m, 'Sigue sin'));

        $previo->refresh();
        $this->assertSame('2026-08-27', $previo->fecha_albaran->toDateString());
        $this->assertEquals(17.25, (float) $previo->monto_albaran);
        $this->assertEquals(17.25, (float) PpqItem::firstOrFail()->monto_albaran);
        $this->assertNull(DteAlbaran::where('dte_id', $nc->id)->firstOrFail()->fecha, 'La nota no se modifica.');
    }

    public function test_si_el_item_falla_la_fecha_completada_vuelve_a_quedar_vacia(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $previo = $this->albaranPpqPrevio($nc, ['fecha_albaran' => null]);

        PpqItem::creating(function () {
            throw new \RuntimeException('fallo simulado al crear el item');
        });

        $this->agregar($lote, ['dte_id' => $nc->id])->assertStatus(500);

        $this->assertSame(0, PpqItem::count());
        $this->assertNull($previo->refresh()->fecha_albaran, 'La transacción deshace la fecha completada.');
    }

    // ------------------------------------------------------------------ ppq_albaran_id ajeno

    public function test_ppq_albaran_id_del_mismo_numero_pero_otra_oc_se_rechaza(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $ajeno = $this->albaranPpqPrevio($nc, ['numero_orden_compra' => '999999999999999']);

        $this->assertRechazoSinEscrituras(
            $this->agregar($lote, ['dte_id' => $nc->id, 'ppq_albaran_id' => $ajeno->id]),
            'otro documento u orden de compra',
            1,
        );
    }

    public function test_ppq_albaran_id_del_mismo_numero_pero_de_otro_documento_se_rechaza(): void
    {
        [$nc, $ccf] = $this->notaConDatosPropios();
        $lote = $this->lote();
        $ajeno = $this->albaranPpqPrevio($nc, ['dte_id' => $ccf->id]);

        $this->assertRechazoSinEscrituras(
            $this->agregar($lote, ['dte_id' => $nc->id, 'ppq_albaran_id' => $ajeno->id]),
            'otro documento',
            1,
        );
    }

    // ------------------------------------------------------------------ parcial / vacío / inválido

    public function test_albaran_guardado_parcial_pide_solo_lo_que_falta(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: ['fecha' => null, 'total' => null]);
        $this->lote();

        $resp = $this->verNc($nc);

        $resp->assertOk();
        $resp->assertSee('Guardado, incompleto', false);
        $resp->assertSee(self::ALBARAN_NC, false);
        $resp->assertDontSee('name="numero_albaran"', false); // el número ya está guardado
        $resp->assertSee('name="fecha_albaran"', false);
        $resp->assertSee('name="monto_albaran"', false);
    }

    public function test_completar_el_parcial_va_al_item_ppq_y_no_modifica_la_nota(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: ['fecha' => null, 'total' => null]);
        $lote = $this->lote();

        $this->agregar($lote, [
            'dte_id' => $nc->id,
            'fecha_albaran' => '2026-08-27',
            'monto_albaran' => '17.25',
        ])->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains((string) $m, 'se completó en PPQ'));

        $albaran = PpqAlbaran::where('numero_albaran', self::ALBARAN_NC)->firstOrFail();
        $this->assertSame('2026-08-27', $albaran->fecha_albaran->toDateString());
        $this->assertEquals(17.25, (float) $albaran->monto_albaran);
        $this->assertEquals(17.25, (float) PpqItem::firstOrFail()->monto_albaran);

        // Lo guardado en la NC queda tal cual: la nota emitida no se edita desde PPQ.
        $guardado = DteAlbaran::where('dte_id', $nc->id)->firstOrFail();
        $this->assertNull($guardado->fecha);
        $this->assertNull($guardado->total);
    }

    public function test_parcial_sin_completar_no_usa_el_total_fiscal_como_monto(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: ['total' => null]);
        $lote = $this->lote();

        $this->agregar($lote, ['dte_id' => $nc->id])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains((string) $m, 'Sigue sin monto'));

        $item = PpqItem::firstOrFail();
        $this->assertNull($item->monto_albaran, 'El total fiscal de la NC no reemplaza al del albarán.');
        $this->assertNull(PpqAlbaran::firstOrFail()->monto_albaran);
        $this->assertEquals(self::TOTAL_NC, (float) $item->monto_dte);
    }

    public function test_fila_de_albaran_vacia_se_trata_como_sin_datos(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: ['numero_canonico' => '', 'numero' => '', 'fecha' => null, 'total' => null]);
        $this->lote();

        $this->verNc($nc)
            ->assertOk()
            ->assertSee('Captura manual', false)
            ->assertSee('name="numero_albaran"', false);
    }

    public function test_canonico_de_entrega_ac01_se_detiene_con_explicacion(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: [
            'numero_canonico' => 'AC01/0033/00/3209',
            'tipo_codigo' => 'AC01',
        ]);
        $lote = $this->lote();

        $this->verNc($nc)
            ->assertOk()
            ->assertSee('Revisar en la nota', false)
            ->assertSee('es de entrega, no de crédito', false)
            ->assertDontSee('Agregar NC al PPQ', false)
            ->assertDontSee('Guardado en la nota', false);

        $this->assertRechazoSinEscrituras($this->agregar($lote, ['dte_id' => $nc->id]), 'es de entrega', 0);
    }

    public function test_canonico_que_no_coincide_con_sus_piezas_se_detiene(): void
    {
        [$nc] = $this->notaConDatosPropios(albaran: ['numero' => '3210']);
        $lote = $this->lote();

        $this->assertRechazoSinEscrituras($this->agregar($lote, ['dte_id' => $nc->id]), 'no coincide', 0);
    }

    // ------------------------------------------------------------------ atomicidad

    public function test_un_fallo_al_crear_el_item_no_deja_el_albaran_registrado(): void
    {
        [$nc] = $this->notaConDatosPropios();
        $lote = $this->lote();

        PpqItem::creating(function () {
            throw new \RuntimeException('fallo simulado al crear el item');
        });

        $this->agregar($lote, ['dte_id' => $nc->id])->assertStatus(500);

        $this->assertSame(0, PpqItem::count());
        $this->assertSame(0, PpqAlbaran::count(), 'La transacción deshace el albarán si el item falla.');
    }

    // ------------------------------------------------------------------ precarga

    public function test_las_dos_busquedas_precargan_albaran_y_ccf_relacionado(): void
    {
        [$nc, $ccf] = $this->notaConDatosPropios();
        $servicio = app(PpqBusquedaService::class);

        $listada = $servicio->buscar(['oc' => $nc->numero_orden_compra, 'tipo' => '05']);
        $this->assertCount(1, $listada->items());
        $enLista = $listada->items()[0];
        $this->assertTrue($enLista->relationLoaded('albaran'));
        $this->assertTrue($enLista->relationLoaded('dteRelacionado'));
        $this->assertSame($ccf->numero_control, $enLista->dteRelacionado->numero_control);

        $exacto = $servicio->buscarExacto($this->correlativo($nc), '05');
        $this->assertTrue($exacto->relationLoaded('albaran'));
        $this->assertTrue($exacto->relationLoaded('dteRelacionado'));
    }
}
