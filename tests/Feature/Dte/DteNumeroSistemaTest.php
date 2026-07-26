<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * NUMERACIÓN GLOBAL VISIBLE del sistema (`dtes.numero_sistema`): el número COMERCIAL que
 * se muestra en pantalla, en lugar de `dtes.id`, que es solo la primary key técnica.
 *
 * Reglas cubiertas:
 *  - Secuencia ÚNICA compartida por CCF (03), NC (05), factura (01) y FEX (11).
 *  - Solo PRODUCCIÓN (ambiente 01); pruebas/APITEST no consume número.
 *  - Se asigna en el punto IRREVERSIBLE de la generación (con el correlativo fiscal), no
 *    al abrir un formulario ni al crear un borrador.
 *  - Una vez asignado NUNCA se reutiliza ni cambia: rechazo, invalidación y archivado lo
 *    conservan.
 *  - El siguiente número sale de un contador con bloqueo de fila, no de MAX()+1.
 */
class DteNumeroSistemaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    // Códigos reales del catálogo oficial (CAT-027 / CAT-033 / CAT-028 / CAT-031).
    private const RECINTO_FISCAL = '01';

    private const TIPO_REGIMEN = 'EX-1';

    private const REGIMEN = '1000.000';

    private const COD_INCOTERMS = '09';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);

        // La instalación opera en PRODUCCIÓN: es el único ambiente que numera.
        config(['dte.ambiente' => '01']);
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $tipo) {
            foreach (['00', '01'] as $ambiente) {
                Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id,
                    'punto_venta_id' => $this->pv->id, 'ambiente' => $ambiente, 'ultimo_numero' => 0, 'activo' => true]);
            }
        }
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Borrador con una línea gravada, del tipo y ambiente indicados. */
    private function borrador(TipoDte $tipo = TipoDte::CreditoFiscal, string $ambiente = '01'): Dte
    {
        $datos = [
            'tipo_dte' => $tipo,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'ambiente' => $ambiente,
        ];

        if ($tipo === TipoDte::FacturaExportacion) {
            $datos += [
                'cliente_id' => Cliente::factory()->exportacion()->create()->id,
                'tipo_item_expor' => 1,
                'recinto_fiscal' => self::RECINTO_FISCAL,
                'tipo_regimen' => self::TIPO_REGIMEN,
                'regimen' => self::REGIMEN,
                'cod_incoterms' => self::COD_INCOTERMS,
            ];
        } else {
            $datos['cliente_id'] = Cliente::factory()->contribuyente()->create()->id;
        }

        $dte = $this->borradores->crearBorrador($datos);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return $dte->refresh();
    }

    /** Documento GENERADO del tipo y ambiente indicados. */
    private function generado(TipoDte $tipo = TipoDte::CreditoFiscal, string $ambiente = '01'): Dte
    {
        $dte = $this->borrador($tipo, $ambiente);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    // ---------- Cuándo se toma el número ----------

    public function test_un_borrador_no_consume_numero(): void
    {
        $borrador = $this->borrador();

        $this->assertNull($borrador->numero_sistema);
        $this->assertFalse($borrador->tieneNumeroSistema());
        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertSame('pendiente', $borrador->etiquetaNumeroSistema());
    }

    public function test_abrir_el_formulario_de_edicion_no_consume_numero(): void
    {
        $borrador = $this->borrador();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $borrador))
            ->assertOk();

        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertNull($borrador->refresh()->numero_sistema);
    }

    public function test_generar_asigna_el_siguiente_numero(): void
    {
        $primero = $this->generado();
        $this->assertSame(1, $primero->numero_sistema);
        $this->assertSame(1, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));

        $segundo = $this->generado();
        $this->assertSame(2, $segundo->numero_sistema);
        $this->assertSame(2, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
    }

    public function test_el_numero_no_tiene_relacion_con_el_id_tecnico(): void
    {
        // El id es una primary key global (la comparten borradores, pruebas, etc.); el
        // número de sistema arranca en 1 con el primer documento numerado.
        $this->borrador();                 // ocupa un id sin numerar
        $this->generado(ambiente: '00');   // ocupa otro id sin numerar (pruebas)
        $dte = $this->generado();

        $this->assertSame(1, $dte->numero_sistema);
        $this->assertNotSame($dte->id, $dte->numero_sistema);
    }

    // ---------- Secuencia compartida por todos los tipos ----------

    public function test_la_secuencia_es_compartida_entre_ccf_nc_factura_y_fex(): void
    {
        $ccf = $this->generado(TipoDte::CreditoFiscal);
        $factura = $this->generado(TipoDte::Factura);
        $fex = $this->generado(TipoDte::FacturaExportacion);

        // La NC exige un CCF ACEPTADO REALMENTE por Hacienda.
        $aceptado = $this->aceptarCcf($ccf);
        $nc = $this->borradores->crearNotaCredito($aceptado, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
        $this->borradores->acreditarLinea($nc, $aceptado->lineas()->first(), cantidad: 1);
        app(DteGeneracionService::class)->generar($nc);

        $this->assertSame([1, 2, 3, 4], [
            $ccf->numero_sistema,
            $factura->numero_sistema,
            $fex->numero_sistema,
            $nc->refresh()->numero_sistema,
        ]);

        // Un único hilo de numeración: sin repetidos y sin huecos.
        $numeros = Dte::whereNotNull('numero_sistema')->orderBy('numero_sistema')->pluck('numero_sistema')->all();
        $this->assertSame([1, 2, 3, 4], $numeros);
    }

    // ---------- Pruebas / APITEST no consume ----------

    public function test_el_ambiente_de_pruebas_no_consume_numero(): void
    {
        $prueba = $this->generado(ambiente: '00');

        $this->assertNull($prueba->numero_sistema);
        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        // Ya emitido y sin número posible: la etiqueta lo dice, no queda "pendiente".
        $this->assertSame('no aplica (pruebas)', $prueba->etiquetaNumeroSistema());
    }

    public function test_un_documento_de_produccion_emitido_sin_numero_queda_pendiente_de_asignar(): void
    {
        // Los documentos de producción anteriores a esta funcionalidad: NO se los puede
        // etiquetar como "pruebas" ni como "pendiente" de generación; esperan el backfill.
        $dte = $this->generado();
        Dte::query()->whereKey($dte->id)->update(['numero_sistema' => null]);

        $this->assertSame('pendiente de asignar', $dte->refresh()->etiquetaNumeroSistema());
        $this->assertSame($dte->tipo_dte->label().' (sin N.º de sistema)', $dte->tituloDocumento());
    }

    public function test_las_pruebas_no_abren_huecos_en_la_serie_de_produccion(): void
    {
        $primero = $this->generado();
        $this->generado(ambiente: '00');
        $this->generado(ambiente: '00');
        $segundo = $this->generado();

        $this->assertSame(1, $primero->numero_sistema);
        $this->assertSame(2, $segundo->numero_sistema); // consecutivo, sin saltos
    }

    // ---------- El número asignado nunca se libera ----------

    public function test_un_rechazo_conserva_el_numero(): void
    {
        $dte = $this->generado();
        $numero = $dte->numero_sistema;

        $dte->update(['estado' => EstadoDte::Rechazado->value]);

        $this->assertSame($numero, $dte->refresh()->numero_sistema);
        // Y el siguiente documento NO reutiliza ese número.
        $this->assertSame($numero + 1, $this->generado()->numero_sistema);
    }

    public function test_archivar_conserva_el_numero(): void
    {
        $dte = $this->generado();
        $numero = $dte->numero_sistema;
        $dte->update(['estado' => EstadoDte::Rechazado->value]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.archivar', $dte))
            ->assertRedirect();

        $dte->refresh();
        $this->assertTrue($dte->estaArchivado());
        $this->assertSame($numero, $dte->numero_sistema);
        $this->assertSame($numero + 1, $this->generado()->numero_sistema);
    }

    public function test_invalidar_conserva_el_numero(): void
    {
        $dte = $this->generado();
        $numero = $dte->numero_sistema;

        $dte->update(['estado' => EstadoDte::Invalidado->value]);

        $this->assertSame($numero, $dte->refresh()->numero_sistema);
        $this->assertSame($numero + 1, $this->generado()->numero_sistema);
    }

    public function test_el_numero_no_se_puede_cambiar_una_vez_asignado(): void
    {
        $dte = $this->generado();

        // Ni por asignación masiva (no está en $fillable)…
        $dte->fill(['numero_sistema' => 999]);
        $this->assertSame(1, $dte->numero_sistema);

        // …ni escribiéndolo en un documento ya emitido (el observer de inmutabilidad
        // bloquea todo campo fuera de su whitelist, y este no está en ella).
        $dte->numero_sistema = 999;
        $this->expectException(\App\Exceptions\Dte\DocumentoInmutableException::class);
        $dte->save();
    }

    // ---------- Concurrencia ----------

    public function test_la_secuencia_exige_transaccion_para_que_el_bloqueo_sirva(): void
    {
        // RefreshDatabase corre cada prueba DENTRO de una transacción, así que el nivel
        // real nunca es 0 acá: se simula para verificar el candado, que es lo que evita
        // pedir un número con un FOR UPDATE que se libera al instante.
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);

        $this->expectException(\LogicException::class);
        Secuencia::siguiente(Secuencia::NUMERO_SISTEMA);
    }

    public function test_la_secuencia_bloquea_la_fila_en_lugar_de_usar_max_mas_uno(): void
    {
        // Con la gramática de MySQL (la de producción) la consulta lleva FOR UPDATE.
        $sql = strtolower(Secuencia::consultaBloqueada(Secuencia::NUMERO_SISTEMA, 'mysql')->toSql());
        $this->assertStringContainsString('for update', $sql);
        $this->assertStringNotContainsString('max(', $sql);
    }

    public function test_la_secuencia_no_entrega_numeros_repetidos(): void
    {
        $numeros = [];
        for ($i = 0; $i < 30; $i++) {
            $numeros[] = DB::transaction(fn () => Secuencia::siguiente(Secuencia::NUMERO_SISTEMA));
        }

        $this->assertSame(range(1, 30), $numeros);
        $this->assertCount(30, array_unique($numeros));
    }

    public function test_el_contador_no_depende_de_los_documentos_existentes(): void
    {
        // Aunque no exista ningún DTE con el número 5, la serie sigue desde el contador:
        // así un documento borrado o de otro ambiente no puede provocar un número repetido
        // (que es exactamente lo que haría MAX(numero_sistema)+1).
        Secuencia::query()->where('clave', Secuencia::NUMERO_SISTEMA)->update(['ultimo_numero' => 5]);

        $this->assertSame(6, $this->generado()->numero_sistema);
        $this->assertSame(0, Dte::where('numero_sistema', 5)->count());
    }

    public function test_si_la_generacion_falla_el_numero_no_se_consume(): void
    {
        // Un CCF que exige orden de compra y no la tiene no llega a generarse: el rollback
        // (o la validación previa) deja la secuencia intacta.
        $borrador = $this->borrador();
        $borrador->lineas()->delete(); // sin líneas no se puede generar

        try {
            app(DteGeneracionService::class)->generar($borrador->refresh());
            $this->fail('Un documento sin líneas no debería generarse.');
        } catch (\App\Exceptions\Dte\GeneracionException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertNull($borrador->refresh()->numero_sistema);
    }

    // ---------- Vistas ----------

    public function test_la_ficha_muestra_el_numero_de_sistema_y_no_el_id_tecnico_como_numero(): void
    {
        $dte = $this->generado();

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk();

        $respuesta->assertSee($dte->tipo_dte->label().' N.º 1', escape: false);
        $respuesta->assertSee('N.º sistema', escape: false);
        // El id de la fila ya no se presenta como número de documento.
        $respuesta->assertDontSee('Documento #'.$dte->id);
        // Sigue disponible, pero etiquetado como técnico (soporte/auditoría).
        $respuesta->assertSee('ID técnico (soporte)', escape: false);
    }

    public function test_el_borrador_se_titula_sin_numero_y_marca_el_numero_como_pendiente(): void
    {
        $nc = $this->borradores->crearNotaCredito(
            $this->aceptarCcf($this->generado()),
            ['tipo' => TipoNotaCredito::DevolucionProducto->value]
        );

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nc))
            ->assertOk();

        // La etiqueta del tipo es la del catálogo ("Nota de Crédito"), no texto libre.
        $respuesta->assertSee(TipoDte::NotaCredito->label().' (borrador)', escape: false);
        $respuesta->assertSee('N.º sistema: pendiente', escape: false);
        $respuesta->assertDontSee(TipoDte::NotaCredito->label().' #'.$nc->id);
    }

    // ---------- Comandos: candidatos (solo lectura) y backfill (dry-run por defecto) ----------

    public function test_el_comando_de_candidatos_no_escribe_nada(): void
    {
        // Documentos de producción SIN número (simulan los ya emitidos antes de esta
        // funcionalidad) y uno de pruebas, que no debe aparecer.
        $a = $this->generado();
        $b = $this->generado();
        $prueba = $this->generado(ambiente: '00');
        Dte::query()->whereIn('id', [$a->id, $b->id])->update(['numero_sistema' => null]);
        Secuencia::query()->where('clave', Secuencia::NUMERO_SISTEMA)->update(['ultimo_numero' => 0]);

        $this->artisan('dte:numero-sistema-candidatos')
            ->expectsOutputToContain('SOLO LECTURA')
            ->assertSuccessful();

        // Nada cambió: ni números, ni secuencia.
        $this->assertNull($a->refresh()->numero_sistema);
        $this->assertNull($b->refresh()->numero_sistema);
        $this->assertNull($prueba->refresh()->numero_sistema);
        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
    }

    public function test_el_backfill_en_dry_run_no_escribe_nada(): void
    {
        $dte = $this->generado();
        Dte::query()->whereKey($dte->id)->update(['numero_sistema' => null]);
        Secuencia::query()->where('clave', Secuencia::NUMERO_SISTEMA)->update(['ultimo_numero' => 0]);

        $this->artisan('dte:numero-sistema-backfill', ['--ids' => (string) $dte->id])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertNull($dte->refresh()->numero_sistema);
        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
    }

    public function test_el_backfill_rechaza_borradores_pruebas_y_ya_numerados(): void
    {
        $borrador = $this->borrador();
        $prueba = $this->generado(ambiente: '00');
        $numerado = $this->generado();

        $this->artisan('dte:numero-sistema-backfill', [
            '--ids' => implode(',', [$borrador->id, $prueba->id, $numerado->id, 999999]),
        ])->assertFailed();

        $this->assertNull($borrador->refresh()->numero_sistema);
        $this->assertNull($prueba->refresh()->numero_sistema);
        $this->assertSame(1, $numerado->refresh()->numero_sistema); // intacto, no renumerado
    }

    public function test_el_backfill_aplicado_numera_en_orden_y_deja_la_serie_lista_para_el_siguiente(): void
    {
        // Seis documentos de producción ya emitidos y sin numerar, como los reales.
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->generado()->id;
        }
        Dte::query()->whereIn('id', $ids)->update(['numero_sistema' => null]);
        Secuencia::query()->where('clave', Secuencia::NUMERO_SISTEMA)->update(['ultimo_numero' => 0]);

        // El orden lo decide la persona: acá se pasa invertido a propósito para comprobar
        // que el comando respeta la lista y no reordena por id.
        $orden = array_reverse($ids);

        $this->artisan('dte:numero-sistema-backfill', [
            '--ids' => implode(',', $orden),
            '--aplicar' => true,
            '--frase' => 'NUMERAR SISTEMA 6',
        ])->assertSuccessful();

        foreach ($orden as $posicion => $id) {
            $this->assertSame($posicion + 1, Dte::find($id)->numero_sistema);
        }

        // Confirmados 1..6, el próximo documento generado toma el 7.
        $this->assertSame(6, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertSame(7, $this->generado()->numero_sistema);
    }

    public function test_el_backfill_exige_la_frase_exacta_para_aplicar(): void
    {
        $dte = $this->generado();
        Dte::query()->whereKey($dte->id)->update(['numero_sistema' => null]);
        Secuencia::query()->where('clave', Secuencia::NUMERO_SISTEMA)->update(['ultimo_numero' => 0]);

        $this->artisan('dte:numero-sistema-backfill', [
            '--ids' => (string) $dte->id,
            '--aplicar' => true,
            '--frase' => 'NUMERAR SISTEMA',
        ])->assertFailed();

        $this->assertNull($dte->refresh()->numero_sistema);
        $this->assertSame(0, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
    }

    public function test_el_listado_muestra_la_columna_de_numero_de_sistema(): void
    {
        $dte = $this->generado();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('N.º sistema', escape: false)
            ->assertSeeInOrder([$dte->tipo_dte->label(), (string) $dte->numero_sistema], escape: false);
    }
}
