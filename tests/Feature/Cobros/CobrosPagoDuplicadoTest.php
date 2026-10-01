<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\User;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * EL MISMO PAGO EN DOS ARCHIVOS DISTINTOS.
 *
 * La llave de evidencia `(documento, tipo, huella, línea)` protege contra recargar el mismo
 * archivo. No protege contra esto otro: Calleja manda el lunes una remesa con la factura
 * 119 y el miércoles otra —huella distinta— que la vuelve a traer. Como el importe cobrado
 * se suma desde los eventos, la factura quedaba cobrada dos veces y el saldo desaparecía.
 *
 * La corrección NO es descartar todo pago del mismo importe: eso perdería los abonos
 * parciales legítimos, que se ven exactamente igual desde el archivo. El segundo pago se
 * registra entero, con su evidencia, NO suma, y queda en revisión con el motivo para que
 * una persona decida en diez segundos.
 *
 * Estas pruebas cubren las dos salidas, porque las dos son legítimas y el sistema no puede
 * elegir por nadie.
 */
class CobrosPagoDuplicadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Con perfil documental activo: es lo que hace que aparezca en la bandeja. */
    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);

        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);

        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    private function documento(Cliente $cliente, string $monto = '100.00'): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000090059',
            'fecha_emision' => '2026-08-26',
            'monto' => $monto,
        ]);
    }

    /** Un TXT de una sola línea con el importe indicado. El nombre cambia la huella. */
    private function aplicar(Cliente $cliente, string $importe, string $nombre, string $relleno = ''): array
    {
        // El relleno va en un comentario del nombre del proveedor: cambia el CONTENIDO —y
        // por tanto la huella— sin cambiar lo que el archivo informa.
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO{$relleno};CF;DTE03M001P002000000000090059;26-AGO-26;{$importe}\n";

        return app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, $nombre),
        );
    }

    public function test_un_documento_pagado_queda_presentado_recibido_y_sin_revision_historica(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');
        $documento->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Sin antecedente'])->save();

        $this->aplicar($cliente, '100.00', 'pagos.txt');

        $documento->refresh();
        $this->assertSame(EstadoPresentacionCobro::Recibida, $documento->presentacion_estado);
        $this->assertFalse($documento->revisar_historico);
        $this->assertSame(1, CobroEvento::where('cobro_documento_id', $documento->id)->count(), 'Solo el evento de pago.');
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · dos archivos DISTINTOS que traen el mismo pago no lo cobran dos veces.
     *
     * Esta es la prueba que faltaba: antes el importe cobrado subía a 200.00 sobre una
     * factura de 100.00 y el saldo pasaba a −100.00.
     */
    public function test_dorada_dos_archivos_distintos_con_el_mismo_pago_no_cobran_dos_veces(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');

        // Lunes: la remesa informa el pago. Se aplica.
        $lunes = $this->aplicar($cliente, '100.00', 'pagos-lunes.txt');
        $this->assertCount(1, $lunes['aplicados']);
        $this->assertSame([], $lunes['en_revision']);

        $documento->refresh();
        $this->assertSame(0, Dinero::comparar('100.00', $documento->monto_pagado));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);

        // Miércoles: OTRO archivo —otra huella— vuelve a traer el mismo pago.
        $miercoles = $this->aplicar($cliente, '100.00', 'pagos-miercoles.txt', ' EJEMPLO');

        // Se registró, pero NO sumó.
        $this->assertCount(1, $miercoles['en_revision']);
        $this->assertCount(0, $miercoles['aplicados']);

        $documento->refresh();
        $this->assertSame(0, Dinero::comparar('100.00', $documento->monto_pagado), 'No puede haber cobrado 200.');
        $this->assertSame(0, Dinero::comparar('0.00', $documento->saldo()));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);

        // Y queda a la vista, con los dos eventos y el motivo.
        $this->assertSame(2, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertTrue($documento->tienePagosEnRevision());
        $this->assertSame(0, Dinero::comparar('100.00', $documento->montoEnRevision()));

        $enRevision = $documento->pagosEnRevision()->first();
        $this->assertSame(EstadoEventoCobro::EnRevision, $enRevision->estado);
        $this->assertStringContainsString('pagos-lunes.txt', (string) $enRevision->estado_motivo);
        $this->assertStringContainsString('pagos-miercoles.txt', (string) $enRevision->estado_motivo);
        $this->assertStringContainsString('REPITIERA', (string) $enRevision->estado_motivo);
        $this->assertStringContainsString('DOS ABONOS', (string) $enRevision->estado_motivo);
    }

    /**
     * DORADA · el mismo caso con IMPORTES DISTINTOS tampoco se resuelve solo.
     *
     * Dos abonos que suman el total es lo que hay que poder registrar; que el cliente
     * repita con un centavo de diferencia también existe. Desde el archivo se ven igual, así
     * que ninguno de los dos se aplica sin que alguien lo diga.
     */
    public function test_dorada_un_segundo_importe_distinto_tampoco_suma_solo(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '60.00', 'parcial-1.txt');
        $documento->refresh();
        $this->assertSame(EstadoPagoCobro::Parcial, $documento->pago_estado);

        $segundo = $this->aplicar($cliente, '40.00', 'parcial-2.txt', ' EJEMPLO');

        $this->assertCount(1, $segundo['en_revision']);
        $documento->refresh();
        $this->assertSame(0, Dinero::comparar('60.00', $documento->monto_pagado), 'El segundo abono aún no cuenta.');
        $this->assertSame(EstadoPagoCobro::Parcial, $documento->pago_estado);
        $this->assertSame(0, Dinero::comparar('40.00', $documento->montoEnRevision()));
    }

    /**
     * DORADA · resuelto como ABONO NUEVO, suma; resuelto como REPETICIÓN, no.
     *
     * Las dos salidas con motivo obligatorio: la decisión es de una persona y tiene que
     * poder rastrearse.
     */
    public function test_dorada_resolver_la_revision_aplica_o_descarta_con_motivo(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '60.00', 'parcial-1.txt');
        $this->aplicar($cliente, '40.00', 'parcial-2.txt', ' EJEMPLO');

        $evento = $documento->pagosEnRevision()->first();

        // Era un abono más: se aplica y suma.
        $this->actingAs($usuario)
            ->put(route('cobros.documentos.pagos.resolver', [$documento, $evento]), [
                'decision' => 'aplicado',
                'motivo' => 'Confirmado con el banco: son dos depósitos distintos.',
            ])
            ->assertRedirect();

        $documento->refresh();
        $this->assertSame(0, Dinero::comparar('100.00', $documento->monto_pagado));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
        $this->assertFalse($documento->tienePagosEnRevision());

        $evento->refresh();
        $this->assertSame(EstadoEventoCobro::Aplicado, $evento->estado);
        $this->assertStringContainsString('dos depósitos distintos', (string) $evento->estado_motivo);
        $this->assertStringContainsString($usuario->name, (string) $evento->estado_motivo);
        $this->assertSame($usuario->id, $evento->resuelto_por);
        $this->assertNotNull($evento->resuelto_en);
    }

    /** La otra salida: era una repetición, se descarta y el cobrado no se mueve. */
    public function test_descartar_la_repeticion_deja_el_cobrado_igual(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');
        $this->aplicar($cliente, '100.00', 'remesa-2.txt', ' EJEMPLO');

        $evento = $documento->pagosEnRevision()->first();

        $this->actingAs($usuario)
            ->put(route('cobros.documentos.pagos.resolver', [$documento, $evento]), [
                'decision' => 'descartado',
                'motivo' => 'La remesa del miércoles repite la del lunes.',
            ])
            ->assertRedirect();

        $documento->refresh();
        $this->assertSame(0, Dinero::comparar('100.00', $documento->monto_pagado));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
        $this->assertFalse($documento->tienePagosEnRevision());

        // El evento descartado se CONSERVA: es la prueba de que el cliente lo informó.
        $this->assertSame(EstadoEventoCobro::Descartado, $evento->refresh()->estado);
        $this->assertSame(2, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
    }

    // ------------------------------------------------------------------ bordes

    /** Recargar el MISMO archivo sigue sin crear nada: no entra en revisión ni suma. */
    public function test_recargar_el_mismo_archivo_no_genera_una_revision(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '100.00', 'remesa.txt');
        $segundo = $this->aplicar($cliente, '100.00', 'remesa.txt');

        $this->assertSame([], $segundo['en_revision'], 'El mismo archivo no es una segunda evidencia.');
        $this->assertCount(1, $segundo['sin_cambio']);
        $this->assertSame(1, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertSame(0, Dinero::comparar('100.00', $documento->refresh()->monto_pagado));
    }

    /** Dos archivos que hablan de documentos DISTINTOS no disparan ninguna revisión. */
    public function test_dos_archivos_sin_documentos_en_comun_no_disparan_revision(): void
    {
        $cliente = $this->cliente();
        $uno = $this->documento($cliente, '100.00');
        $dos = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000090017',
            'monto' => '50.00',
        ]);

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000090017;26-AGO-26;50.00\n";
        $informe = app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'remesa-2.txt'),
        );

        $this->assertSame([], $informe['en_revision']);
        $this->assertCount(1, $informe['aplicados']);
        $this->assertSame(EstadoPagoCobro::Pagado, $uno->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pagado, $dos->refresh()->pago_estado);
    }

    /** Un pago ya resuelto no se vuelve a resolver: se dice y no se cambia nada. */
    public function test_un_pago_ya_resuelto_no_se_resuelve_otra_vez(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');
        $this->aplicar($cliente, '100.00', 'remesa-2.txt', ' EJEMPLO');

        $evento = $documento->pagosEnRevision()->first();
        $evento->resolver(EstadoEventoCobro::Descartado, $usuario, 'Repetida.');

        $this->actingAs($usuario)
            ->put(route('cobros.documentos.pagos.resolver', [$documento, $evento]), [
                'decision' => 'aplicado',
                'motivo' => 'Me equivoqué.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(EstadoEventoCobro::Descartado, $evento->refresh()->estado);
        $this->assertSame(0, Dinero::comparar('100.00', $documento->refresh()->monto_pagado));
    }

    /** Resolver exige motivo: una decisión sobre dinero no puede quedar sin explicación. */
    public function test_resolver_exige_motivo(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');
        $this->aplicar($cliente, '100.00', 'remesa-2.txt', ' EJEMPLO');

        $evento = $documento->pagosEnRevision()->first();

        $this->actingAs($this->usuario())
            ->put(route('cobros.documentos.pagos.resolver', [$documento, $evento]), ['decision' => 'aplicado'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoEventoCobro::EnRevision, $evento->refresh()->estado);
    }

    /** La bandeja cuenta y filtra los documentos con pagos en revisión. */
    public function test_la_bandeja_muestra_los_pagos_en_revision(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');
        $tranquilo = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000121',
            'monto' => '10.00',
        ]);

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');
        $this->aplicar($cliente, '100.00', 'remesa-2.txt', ' EJEMPLO');

        $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id, 'pago_revision' => '1']))
            ->assertOk()
            ->assertSee($documento->numero_control)
            ->assertDontSee($tranquilo->numero_control);
    }

    /** Un pago que no pertenece a ese documento no se puede resolver desde su ficha. */
    public function test_no_se_puede_resolver_un_pago_de_otro_documento(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente, '100.00');
        $ajeno = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000090016',
            'monto' => '10.00',
        ]);

        $this->aplicar($cliente, '100.00', 'remesa-1.txt');
        $this->aplicar($cliente, '100.00', 'remesa-2.txt', ' EJEMPLO');

        $evento = $documento->pagosEnRevision()->first();

        $this->actingAs($this->usuario())
            ->put(route('cobros.documentos.pagos.resolver', [$ajeno, $evento]), [
                'decision' => 'aplicado',
                'motivo' => 'No debería poder.',
            ])
            ->assertNotFound();
    }
}
