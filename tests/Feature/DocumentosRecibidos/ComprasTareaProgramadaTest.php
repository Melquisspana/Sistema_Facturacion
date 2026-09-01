<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoProgreso;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * La tarea programada de compras: que exista, que esté apagada por defecto, que corra en
 * MODO DE APLICACIÓN, y que al correr guarde de verdad.
 *
 * POR QUÉ ESTA CLASE. El comando es dry-run por defecto —lo correcto para una corrida
 * manual— así que la tarea programada tiene que pedir `--aplicar` explícitamente. Si
 * alguien lo quita, la sincronización automática leería el buzón y no guardaría nada, y
 * el sistema PARECERÍA estar funcionando: la franja de Compras se pondría en verde, la
 * bitácora registraría éxitos, y los documentos no llegarían nunca. Es exactamente el
 * modo de fallo que este trabajo vino a eliminar, así que tiene una prueba propia.
 */
class ComprasTareaProgramadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * La tarea de compras, tal como la registró `routes/console.php`.
     *
     * El kernel de consola se bota a mano porque en una prueba de este tipo las rutas de
     * consola pueden no haberse cargado todavía.
     */
    private function tarea(): Event
    {
        $this->app->make(Kernel::class)->bootstrap();

        $eventos = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'compras:sincronizar'))
            ->values();

        $this->assertCount(1, $eventos, 'tiene que haber exactamente UNA tarea programada de compras');

        return $eventos->first();
    }

    /** Lo que la tarea le pasaría a artisan, sin el binario ni el `artisan`. */
    private function argumentosDe(Event $tarea): string
    {
        $comando = (string) $tarea->command;
        $inicio = strpos($comando, 'compras:sincronizar');
        $this->assertNotFalse($inicio);

        return trim(substr($comando, $inicio));
    }

    // ---------------------------------------------------- la definición

    public function test_existe_la_tarea_programada_de_compras(): void
    {
        $tarea = $this->tarea();

        $this->assertSame('*/15 * * * *', $tarea->expression, 'cada 15 minutos');
        $this->assertNotNull($tarea->output);
        $this->assertStringContainsString('compras-sincronizacion.log', (string) $tarea->output);
    }

    /**
     * LA PRUEBA QUE PIDE EL RIESGO: si la corrida programada queda en dry-run, esto falla.
     */
    public function test_la_tarea_programada_corre_en_modo_de_aplicacion(): void
    {
        $argumentos = $this->argumentosDe($this->tarea());

        $this->assertStringContainsString(
            '--aplicar',
            $argumentos,
            'La tarea programada NO puede quedar en dry-run: leería el buzón sin guardar nada '
            .'y parecería estar funcionando. Argumentos actuales: '.$argumentos,
        );
        $this->assertStringContainsString('--solape', $argumentos, 'el solape recupera los correos con retraso');
    }

    /** No se solapa consigo misma: dos corridas se pisarían el cursor. */
    public function test_la_tarea_programada_no_se_solapa(): void
    {
        $this->assertTrue($this->tarea()->withoutOverlapping);
    }

    // ---------------------------------------------------- el interruptor

    /**
     * APAGADA por defecto. El código puede llegar al servidor antes de que el buzón esté
     * configurado y antes de recuperar el backlog, sin que nada arranque solo.
     */
    public function test_esta_apagada_por_defecto(): void
    {
        $this->assertFalse(
            (bool) config('documentos_recibidos.sincronizacion_automatica'),
            'el interruptor tiene que venir apagado de fábrica',
        );

        $this->assertFalse($this->tarea()->filtersPass($this->app), 'apagada, la tarea no debe pasar sus filtros');
    }

    public function test_el_interruptor_la_habilita(): void
    {
        config(['documentos_recibidos.sincronizacion_automatica' => true]);

        $this->assertTrue($this->tarea()->filtersPass($this->app));
    }

    // ---------------------------------------------------- que sincronice de verdad

    /**
     * Se ejecutan LOS MISMOS argumentos que correría el scheduler —extraídos de la
     * definición, no escritos a mano— y se comprueba que quedan documentos y progreso.
     *
     * Es la diferencia entre «la tarea está declarada» y «la tarea sincroniza».
     */
    public function test_la_corrida_programada_persiste_documentos_y_progreso(): void
    {
        config(['documentos_recibidos.sincronizacion_automatica' => true]);
        $tarea = $this->tarea();
        $this->assertTrue($tarea->filtersPass($this->app));

        // Un correo de hoy: la ventana incremental sin marca previa llega hasta hoy.
        $this->app->instance(MailboxClient::class,
            (new BuzonFalso)->conDte(4242, now()->startOfDay()->addHours(9)->toDateTimeString(), 'COD-PROGRAMADA'));

        $codigo = Artisan::call($this->argumentosDe($tarea));

        $this->assertSame(0, $codigo, Artisan::output());
        $this->assertSame(1, DocumentoRecibido::count(), 'la corrida programada tiene que GUARDAR el documento');
        $this->assertSame('mid:cod-programada@proveedor.example', DocumentoRecibido::firstOrFail()->identidad);

        $hoy = DocumentoRecibidoProgreso::where('dia', now()->toDateString())->firstOrFail();
        $this->assertTrue($hoy->estaCompleto(), 'y tiene que dejar el día cerrado');

        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->assertNotNull($bitacora->ultimoExito());
        $this->assertNull($bitacora->ultimoError());
    }

    /**
     * Contraprueba: los mismos argumentos SIN `--aplicar` no guardan nada. Es lo que
     * pasaría si alguien quitara el flag de la tarea programada — y es la razón de que
     * la prueba de arriba exista.
     */
    public function test_sin_aplicar_la_misma_corrida_no_guardaria_nada(): void
    {
        $this->app->instance(MailboxClient::class,
            (new BuzonFalso)->conDte(4242, now()->startOfDay()->addHours(9)->toDateTimeString(), 'COD-DRY'));

        $argumentos = str_replace('--aplicar', '', $this->argumentosDe($this->tarea()));
        Artisan::call($argumentos);

        $this->assertSame(0, DocumentoRecibido::count());
        $this->assertSame(0, DocumentoRecibidoProgreso::count());
    }
}
