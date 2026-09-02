<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * EL DESPLIEGUE REAL, contra la forma REAL de los datos de producción.
 *
 * La auditoría devolvió TRES listas de empaque, y son estas:
 *
 *   lista  estado     archivada  DTE      estado fiscal  ambiente
 *      9   borrador   sí         tipo 11  invalidado     01
 *     14   borrador   no         tipo 11  aceptado       01
 *     15   borrador   no         tipo 11  aceptado       01
 *
 * Las tres con `factura` (el campo textual) en NULL, con la fecha de la lista y la
 * de emisión del DTE coincidentes, y con el DTE ni eliminado ni archivado. Las
 * fixtures de acá reproducen esa estructura EXACTA —ids incluidos— con nombres y
 * cifras inventados: no hay ni un dato sensible en el archivo.
 *
 * A diferencia de {@see BackfillExportacionesTest}, que construye a mano filas ya
 * migradas para ejercitar el MODELO, esta prueba ejecuta las MIGRACIONES DE VERDAD
 * sobre el estado anterior al despliegue: retrocede el lote, inserta las filas tal
 * como las dejó el sistema viejo y vuelve a aplicarlo en el mismo orden que
 * `php artisan migrate`. Lo que se afirma acá es el comportamiento del backfill.
 *
 * LAS CUATRO REGLAS QUE SE VERIFICAN:
 *
 *   1. No archivada + FEX tipo 11 aceptada, viva, mismo cliente, ambiente 01
 *      → finalizada, con su vínculo en el pivote y `dte_id` como principal.
 *   2. Archivada + FEX invalidada → sigue archivada, no editable, con el vínculo
 *      histórico intacto, sin finalizar y sin poder reutilizarse para otra factura.
 *   3. No archivada + FEX generada / firmada / rechazada / invalidada / de otro
 *      tipo / de otro cliente / de otro ambiente → congelada con `requiere_revision`.
 *   4. Lista realmente sin DTE → sigue siendo un borrador de trabajo.
 */
class BackfillListasProductivasTest extends TestCase
{
    use RefreshDatabase;

    /** El lote de migraciones de Exportaciones, en orden de aplicación. */
    private const DESPLIEGUE = [
        '2026_09_01_200000_create_exportacion_dte_table',
        '2026_09_01_200100_add_finalizacion_a_exportaciones',
        '2026_09_01_200200_marcar_fda_de_empresa_en_exportacion_clientes',
        '2026_09_01_200300_add_resolucion_revision_a_exportaciones',
        '2026_09_01_200400_restringir_borrado_de_productos_de_exportacion',
    ];

    /** Las tres migraciones del lote que tocan datos de listas de empaque. */
    private const CON_BACKFILL_DE_LISTAS = [
        '2026_09_01_200000_create_exportacion_dte_table',
        '2026_09_01_200100_add_finalizacion_a_exportaciones',
        '2026_09_01_200300_add_resolucion_revision_a_exportaciones',
    ];

    /** Fecha de la lista Y de emisión del DTE: en producción coinciden. */
    private const FECHA = '2026-07-16';

    /** Último rastro del sistema viejo sobre las filas reales. */
    private const TOCADA_EN = '2026-07-16 15:42:11';

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------------- el despliegue

    private function rutaMigracion(string $nombre): string
    {
        return database_path('migrations/'.$nombre.'.php');
    }

    /**
     * Deshace el lote y deja el esquema EXACTAMENTE como está hoy en producción:
     * con `exportaciones.dte_id`, sin tabla pivote y sin ninguna columna de
     * finalización ni de revisión.
     */
    private function retrocederAlEstadoAnterior(): void
    {
        foreach (array_reverse(self::CON_BACKFILL_DE_LISTAS) as $migracion) {
            (require $this->rutaMigracion($migracion))->down();
        }
    }

    /** Aplica el lote, en el mismo orden que `php artisan migrate`. */
    private function desplegar(): void
    {
        foreach (self::CON_BACKFILL_DE_LISTAS as $migracion) {
            (require $this->rutaMigracion($migracion))->up();
        }
    }

    // ----------------------------------------------------------------- fixtures

    /** Emisor mínimo: `dtes` exige establecimiento y punto de venta. */
    private function emisor(): array
    {
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    /** Perfil de exportación con su cliente maestro detrás. */
    private function perfil(string $nombre): ExportacionCliente
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);

        return ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $nombre, 'activo' => true]);
    }

    /** Factura de Exportación (tipo 11) en el estado y ambiente que se le pidan. */
    private function fex(
        ExportacionCliente $perfil,
        EstadoDte $estado,
        int $correlativo,
        string $ambiente = '01',
        string $tipo = TipoDte::FacturaExportacion->value,
    ): Dte {
        return Dte::create($this->emisor() + [
            'tipo_dte' => $tipo,
            'cliente_id' => $perfil->cliente_id,
            'estado' => $estado->value,
            'ambiente' => $ambiente,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => self::FECHA,
            'hora_emision' => '10:00:00',
            'total_pagar' => 1250.00,
        ]);
    }

    /**
     * Fila TAL CUAL la dejó el sistema anterior: `estado = 'borrador'`, `dte_id`
     * puesto, `factura` en NULL, sin pivote y sin ninguna columna del flujo nuevo.
     * Se escribe con el query builder y no con el modelo a propósito: el modelo ya
     * conoce columnas que en este punto todavía no existen.
     */
    private function listaProductiva(int $id, ?Dte $fex, ?ExportacionCliente $perfil, string $nombre, bool $archivada = false): int
    {
        DB::table('exportaciones')->insert([
            'id' => $id,
            'exportacion_cliente_id' => $perfil?->id,
            'dte_id' => $fex?->id,
            'cliente_nombre' => $nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => self::FECHA,
            'factura' => null, // NULL en las tres filas reales: el backfill no puede depender de esto
            'estado' => 'borrador',
            'archivada' => $archivada,
            'archivada_en' => $archivada ? self::TOCADA_EN : null,
            'created_at' => '2026-07-15 09:00:00',
            'updated_at' => self::TOCADA_EN,
        ]);

        return $id;
    }

    /**
     * Las TRES filas productivas, con sus ids reales, ya migradas.
     *
     * @return array{9: Dte, 14: Dte, 15: Dte} la FEX de cada lista
     */
    private function desplegarProduccion(): array
    {
        $invalidada = $this->perfil('IMPORTADORA UNO');
        $primera = $this->perfil('IMPORTADORA DOS');
        $segunda = $this->perfil('IMPORTADORA TRES');

        $fex = [
            9 => $this->fex($invalidada, EstadoDte::Invalidado, 9),
            14 => $this->fex($primera, EstadoDte::Aceptado, 14),
            15 => $this->fex($segunda, EstadoDte::Aceptado, 15),
        ];

        $this->retrocederAlEstadoAnterior();
        $this->listaProductiva(9, $fex[9], $invalidada, 'IMPORTADORA UNO', archivada: true);
        $this->listaProductiva(14, $fex[14], $primera, 'IMPORTADORA DOS');
        $this->listaProductiva(15, $fex[15], $segunda, 'IMPORTADORA TRES');
        $this->desplegar();

        return $fex;
    }

    /** Estado observable de una lista, para comparar un despliegue con otro. */
    private function retrato(int $id): array
    {
        $fila = (array) DB::table('exportaciones')->find($id);

        return array_intersect_key($fila, array_flip([
            'estado', 'archivada', 'archivada_en', 'dte_id', 'factura', 'finalizada_en',
            'finalizada_por_user_id', 'requiere_revision', 'revision_motivo',
            'revision_estado_original', 'revision_resolucion',
        ]));
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // =========================================================== REGLAS 1, 2 y 4

    /**
     * El backfill sobre las tres filas reales, fila por fila. Es LA prueba de la
     * auditoría: con las reglas anteriores ninguna de las tres se tocaba y las tres
     * quedaban como borradores de trabajo editables, con su FEX ya emitida detrás.
     */
    public function test_el_backfill_clasifica_las_tres_listas_productivas(): void
    {
        $fex = $this->desplegarProduccion();

        $this->assertSame(3, Exportacion::count(), 'ninguna fila se pierde ni se duplica');

        // ---- Regla 1: #14 y #15, no archivadas, con FEX aceptada de producción.
        foreach ([14, 15] as $id) {
            $lista = Exportacion::findOrFail($id);

            $this->assertSame(Exportacion::ESTADO_FINALIZADA, $lista->estado, "lista #{$id}");
            $this->assertFalse($lista->requiereRevision(), "lista #{$id}: una FEX aceptada no deja nada que revisar");
            $this->assertFalse($lista->archivada, "lista #{$id}");
            $this->assertFalse($lista->puedeEditarse(), "lista #{$id}: una lista finalizada no se edita");

            // La fecha no se inventa: se usa el último rastro real del sistema viejo.
            $this->assertNotNull($lista->finalizada_en, "lista #{$id}");
            $this->assertSame(self::TOCADA_EN, $lista->finalizada_en->format('Y-m-d H:i:s'), "lista #{$id}");
            $this->assertNull($lista->finalizada_por_user_id, "lista #{$id}: el sistema viejo nunca guardó quién cerró");

            // El estado original se conserva aunque la fila SÍ se haya traducido.
            $this->assertSame('borrador', $lista->revision_estado_original, "lista #{$id}");
            $this->assertNotEmpty($lista->revision_motivo, "lista #{$id}: toda decisión automática deja escrito por qué");

            // El vínculo: columna histórica intacta y fila principal en el pivote.
            $this->assertSame($fex[$id]->id, $lista->dte_id, "lista #{$id}");
            $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $id)->where('principal', true)->count(), "lista #{$id}");
            $this->assertSame([$fex[$id]->id], $lista->facturas()->pluck('id')->all(), "lista #{$id}");

            // Y nada de esto dependió del campo textual `factura`, que sigue vacío.
            $this->assertNull($lista->factura, "lista #{$id}");
            $this->assertSame($fex[$id]->numero_control, $lista->textoFactura(), "lista #{$id}");
        }

        // ---- Regla 2: #9, archivada, con FEX invalidada.
        $nueve = Exportacion::findOrFail(9);

        $this->assertTrue($nueve->archivada, 'la #9 sigue archivada');
        $this->assertSame('borrador', $nueve->estado, 'su estado literal se conserva');
        $this->assertFalse($nueve->estaFinalizada(), 'una FEX invalidada no cierra nada');
        $this->assertNull($nueve->finalizada_en);
        $this->assertFalse($nueve->esBorrador(), 'archivada = fuera del flujo de trabajo');
        $this->assertFalse($nueve->puedeEditarse());
        $this->assertFalse($nueve->puedeFinalizarse(), 'no hay ninguna factura vigente detrás');
        $this->assertNotNull($nueve->motivoBloqueo());

        // El vínculo histórico con la factura invalidada se conserva entero.
        $this->assertSame($fex[9]->id, $nueve->dte_id);
        $this->assertSame([$fex[9]->id], $nueve->facturas()->pluck('id')->all());
        $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', 9)->where('principal', true)->count());
        $this->assertCount(0, $nueve->facturasVigentes(), 'una invalidada no respalda el embarque');

        // Y queda auditado qué se encontró, sin congelarla: ya es ineditable.
        $this->assertSame('borrador', $nueve->revision_estado_original);
        $this->assertStringContainsString('invalidado', (string) $nueve->revision_motivo);
        $this->assertFalse($nueve->requiereRevision());
    }

    /** Regla 4: una lista que de verdad no tiene factura sigue siendo un borrador. */
    public function test_una_lista_sin_factura_sigue_siendo_un_borrador_de_trabajo(): void
    {
        $this->retrocederAlEstadoAnterior();
        $this->listaProductiva(21, null, null, 'LISTA EN CURSO');
        $this->desplegar();

        $lista = Exportacion::findOrFail(21);

        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->estado);
        $this->assertFalse($lista->requiereRevision(), 'no hay nada ambiguo en una lista sin facturar');
        $this->assertTrue($lista->esBorrador());
        $this->assertTrue($lista->puedeEditarse());
        $this->assertNull($lista->finalizada_en);
        $this->assertNull($lista->revision_estado_original, 'el backfill no la tocó, así que no la estampa');
        $this->actingAs($this->usuario())->get(route('facturacion.listas.edit', $lista))->assertOk();
    }

    // ==================================================================== REGLA 3

    /**
     * Todo vínculo que no cumple ENTERA la regla 1 congela la lista: se conservan
     * los datos y el vínculo, se marca `requiere_revision` y no se toca el estado.
     */
    public function test_cualquier_vinculo_que_no_es_una_fex_aceptada_congela_la_lista(): void
    {
        $casos = [];

        foreach ([EstadoDte::Generado, EstadoDte::Firmado, EstadoDte::Enviado, EstadoDte::Rechazado, EstadoDte::Invalidado] as $i => $estado) {
            $perfil = $this->perfil('ESTADO '.$estado->value);
            $casos['FEX '.$estado->value] = [
                'lista' => 100 + $i,
                'fex' => $this->fex($perfil, $estado, 100 + $i),
                'perfil' => $perfil,
                'esperado' => $estado->value,
            ];
        }

        // Tipo equivocado: el documento existe, pero no es una factura de exportación.
        $perfilTipo = $this->perfil('TIPO EQUIVOCADO');
        $casos['tipo equivocado'] = [
            'lista' => 200,
            'fex' => $this->fex($perfilTipo, EstadoDte::Aceptado, 200, tipo: TipoDte::CreditoFiscal->value),
            'perfil' => $perfilTipo,
            'esperado' => 'no una factura de exportación',
        ];

        // Cliente incoherente: la FEX es de otro receptor que el perfil de la lista.
        $perfilLista = $this->perfil('CLIENTE DE LA LISTA');
        $casos['cliente incoherente'] = [
            'lista' => 201,
            'fex' => $this->fex($this->perfil('CLIENTE DE LA FACTURA'), EstadoDte::Aceptado, 201),
            'perfil' => $perfilLista,
            'esperado' => 'no es el cliente de la lista',
        ];

        // Ambiente incoherente: una FEX de pruebas (00) colgando de una lista real.
        $perfilAmbiente = $this->perfil('AMBIENTE DE PRUEBAS');
        $casos['ambiente incoherente'] = [
            'lista' => 202,
            'fex' => $this->fex($perfilAmbiente, EstadoDte::Aceptado, 202, ambiente: '00'),
            'perfil' => $perfilAmbiente,
            'esperado' => 'ambiente 00',
        ];

        // Sin perfil con el que contrastar el receptor: tampoco se resuelve a favor.
        $casos['sin perfil de cliente'] = [
            'lista' => 203,
            'fex' => $this->fex($this->perfil('SIN PERFIL EN LA LISTA'), EstadoDte::Aceptado, 203),
            'perfil' => null,
            'esperado' => 'no tiene perfil de cliente',
        ];

        $this->retrocederAlEstadoAnterior();
        foreach ($casos as $nombre => $caso) {
            $this->listaProductiva($caso['lista'], $caso['fex'], $caso['perfil'], strtoupper($nombre));
        }
        $this->desplegar();

        foreach ($casos as $nombre => $caso) {
            $lista = Exportacion::findOrFail($caso['lista']);

            $this->assertTrue($lista->requiereRevision(), $nombre.': tiene que quedar congelada');
            $this->assertSame('borrador', $lista->estado, $nombre.': el estado original se conserva literal');
            $this->assertSame('borrador', $lista->revision_estado_original, $nombre);
            $this->assertNull($lista->finalizada_en, $nombre.': nadie la cerró');
            $this->assertFalse($lista->esBorrador(), $nombre.': no puede pasar por borrador libre');
            $this->assertFalse($lista->puedeEditarse(), $nombre);
            $this->assertFalse($lista->puedeFinalizarse(), $nombre.': cerrarla es decisión de una persona');
            $this->assertNotNull($lista->motivoBloqueo(), $nombre);
            $this->assertStringContainsString($caso['esperado'], (string) $lista->revision_motivo, $nombre.': el motivo dice qué se encontró');

            // El vínculo histórico se conserva en los dos sitios, pase lo que pase.
            $this->assertSame($caso['fex']->id, $lista->dte_id, $nombre);
            $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $caso['lista'])->count(), $nombre);
        }
    }

    // ================================================================ EDITABILIDAD

    /**
     * Ninguna de las tres se puede mutar por ninguna vía después de migrar, y en
     * particular la #9 no se puede reutilizar para otra factura.
     */
    public function test_ninguna_lista_productiva_se_puede_editar_ni_re_facturar(): void
    {
        $this->desplegarProduccion();
        $usuario = $this->usuario();

        // Una FEX libre y coherente con el cliente de la #9: lo único que le falta
        // para poder vincularse es que la lista lo permita. Y no lo permite.
        $nueve = Exportacion::findOrFail(9);
        $otraFex = $this->fex($nueve->cliente, EstadoDte::Aceptado, 999);

        foreach ([9, 14, 15] as $id) {
            $lista = Exportacion::findOrFail($id);

            $this->actingAs($usuario)->get(route('facturacion.listas.edit', $lista))
                ->assertSessionHasErrors('estado');
            $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))
                ->assertSessionHas('error');
            $this->actingAs($usuario)->delete(route('facturacion.listas.destroy', $lista))
                ->assertSessionHas('error');
            $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $otraFex->id])
                ->assertSessionHas('error');
        }

        // Nada cambió: ni estados, ni vínculos, ni el archivado.
        $this->assertSame('borrador', Exportacion::findOrFail(9)->estado);
        $this->assertTrue(Exportacion::findOrFail(9)->archivada);
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, Exportacion::findOrFail(14)->estado);
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, Exportacion::findOrFail(15)->estado);
        $this->assertSame(0, DB::table('exportacion_dte')->where('dte_id', $otraFex->id)->count(), 'la FEX suelta no se coló en ninguna lista');
        $this->assertSame(3, DB::table('exportacion_dte')->count());
    }

    /** La #9 enseña, sin que haya que buscarlo, que su factura fue invalidada. */
    public function test_la_ficha_de_la_lista_archivada_muestra_que_su_fex_esta_invalidada(): void
    {
        $fex = $this->desplegarProduccion();

        $this->actingAs($this->usuario())->get(route('facturacion.listas.show', 9))->assertOk()
            ->assertSee($fex[9]->numero_control)
            ->assertSee(EstadoDte::Invalidado->label());
    }

    // =================================================================== GARANTÍAS

    /**
     * Segunda ejecución: aplicar el lote, revertirlo y volver a aplicarlo tiene que
     * dar EXACTAMENTE el mismo resultado, fila por fila y columna por columna.
     */
    public function test_la_segunda_ejecucion_del_backfill_da_el_mismo_resultado(): void
    {
        $this->desplegarProduccion();

        $primera = [9 => $this->retrato(9), 14 => $this->retrato(14), 15 => $this->retrato(15)];

        $this->retrocederAlEstadoAnterior();
        $this->desplegar();

        $segunda = [9 => $this->retrato(9), 14 => $this->retrato(14), 15 => $this->retrato(15)];

        $this->assertEquals($primera, $segunda, 'la segunda pasada del backfill no coincide con la primera');

        // Ni se duplican los vínculos al reconstruir el pivote.
        $this->assertSame(3, DB::table('exportacion_dte')->count());
        foreach ([9, 14, 15] as $id) {
            $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $id)->count(), "lista #{$id}");
        }

        // Lo que hace idempotente al backfill es la estampa: toda fila que decidió
        // lleva su estado original guardado, y solo mira las que no la tienen.
        $this->assertSame(
            0,
            DB::table('exportaciones')->whereNull('revision_estado_original')->count(),
            'quedó una fila decidida sin estampar: una segunda pasada volvería a tocarla'
        );
    }

    /**
     * Revertir devuelve el `estado` literal que cada fila traía. La versión anterior
     * escribía 'aprobada' en toda lista finalizada con factura, así que un
     * despliegue seguido de una vuelta atrás dejaba las tres filas reales en un
     * estado que nunca tuvieron y sin forma de saber cuál era el suyo.
     */
    public function test_revertir_el_despliegue_devuelve_las_listas_a_su_estado_original(): void
    {
        $fex = $this->desplegarProduccion();

        $this->retrocederAlEstadoAnterior();

        foreach ([9, 14, 15] as $id) {
            $fila = DB::table('exportaciones')->find($id);

            $this->assertSame('borrador', $fila->estado, "lista #{$id}: la vuelta atrás inventó un estado que la fila nunca tuvo");
            $this->assertSame($fex[$id]->id, $fila->dte_id, "lista #{$id}: el vínculo con la factura sobrevive");
        }

        $this->assertTrue((bool) DB::table('exportaciones')->find(9)->archivada, 'el archivado no se toca al revertir');
    }

    /**
     * El FDA repetido es CORRECTO: pertenece al exportador, no al importador, así
     * que dos perfiles pueden compartirlo legítimamente. Ninguna migración puede
     * poner ahí un índice único — sería un error de modelo que rompería el guardado.
     */
    public function test_ninguna_migracion_crea_un_indice_unico_sobre_el_fda(): void
    {
        foreach (['exportaciones', 'exportacion_clientes'] as $tabla) {
            foreach (Schema::getIndexes($tabla) as $indice) {
                $this->assertFalse(
                    ($indice['unique'] ?? false) && in_array('fda_reg_number', $indice['columns'] ?? [], true),
                    "la tabla {$tabla} tiene un índice único sobre fda_reg_number: el FDA es del exportador y se repite"
                );
            }
        }

        // Y la base lo acepta de verdad: mismo FDA en dos perfiles distintos.
        $fda = '00000000001';
        $uno = $this->perfil('IMPORTADORA DOS');
        $dos = $this->perfil('IMPORTADORA TRES');
        $uno->update(['fda_reg_number' => $fda]);
        $dos->update(['fda_reg_number' => $fda]);

        $this->assertSame(2, ExportacionCliente::where('fda_reg_number', $fda)->count());
    }
}
