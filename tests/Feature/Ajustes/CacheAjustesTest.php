<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Adaptadores\AdaptadorConfiguraciones;
use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\RepositorioAjustes;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invalidación de caché entre procesos.
 *
 * El problema real que hay detrás: la caché estática de `App\Models\Configuracion`
 * vive en una propiedad `static` del proceso. En una petición web da igual, pero
 * el worker de colas vive horas: una vez leído un ajuste, se queda con ese valor
 * hasta que alguien reinicie el worker.
 *
 * Acá se simula exactamente eso. "Otro proceso" es una segunda instancia del
 * resolver, con su propia memoria, compartiendo el MISMO store de caché — que es
 * la relación que tienen de verdad la petición web y el worker. Si la
 * invalidación funciona, el segundo lee el valor nuevo sin reiniciarse.
 */
class CacheAjustesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Una instancia INDEPENDIENTE del resolver: memoria propia, caché compartida. */
    private function otroProceso(): ServicioAjustes
    {
        return new ServicioAjustes(
            app(CatalogoAjustes::class),
            new RepositorioAjustes(app(CacheRepository::class)),
            app(AdaptadorConfiguraciones::class),
            app(ConversorValor::class),
            app(AuditoriaAjustes::class),
        );
    }

    public function test_una_escritura_llega_a_un_proceso_que_ya_habia_leido(): void
    {
        config(['backup_diario.dias_retencion' => 30]);
        $this->actingAs($this->admin());

        $worker = $this->otroProceso();

        // El "worker" lee y se queda con el valor en memoria.
        $this->assertSame(30, $worker->entero('respaldos.dias_retencion'));

        // La "petición web" guarda otro valor.
        Ajustes::guardar('respaldos.dias_retencion', 90);

        // El worker lo ve SIN reiniciarse: la huella de versión cambió.
        $this->assertSame(90, $worker->entero('respaldos.dias_retencion'));
    }

    public function test_quitar_el_override_tambien_llega_al_otro_proceso(): void
    {
        config(['backup_diario.dias_retencion' => 30]);
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 90);

        $worker = $this->otroProceso();
        $this->assertSame(90, $worker->entero('respaldos.dias_retencion'));

        Ajustes::quitarOverride('respaldos.dias_retencion');

        $this->assertSame(30, $worker->entero('respaldos.dias_retencion'));
    }

    public function test_dos_escrituras_seguidas_no_dejan_un_valor_intermedio_cacheado(): void
    {
        $this->actingAs($this->admin());
        $worker = $this->otroProceso();

        foreach ([10, 20, 30, 40] as $dias) {
            Ajustes::guardar('respaldos.dias_retencion', $dias);
            $this->assertSame($dias, $worker->entero('respaldos.dias_retencion'));
        }
    }

    /**
     * La caché sirve para algo: leer N veces el mismo ajuste dentro de un proceso
     * no dispara N consultas. Se mide con el log de consultas, no con una promesa.
     */
    public function test_las_lecturas_repetidas_no_consultan_la_base_cada_vez(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('respaldos.dias_retencion', 45);

        $worker = $this->otroProceso();
        $worker->entero('respaldos.dias_retencion'); // primera lectura: calienta

        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 20; $i++) {
            $worker->entero('respaldos.dias_retencion');
        }

        $consultas = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains((string) $q['query'], 'ajustes_sistema'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $consultas, '20 lecturas del mismo ajuste no deberían tocar la tabla.');
    }

    /** El repositorio sabe qué filas están cifradas: precondición de la rotación de APP_KEY. */
    public function test_el_repositorio_identifica_las_filas_cifradas(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 45);
        Ajustes::guardar('mail.smtp.password', 'secreto-de-prueba');

        $cifradas = app(RepositorioAjustes::class)->clavesCifradas();

        $this->assertSame(['mail.smtp.password'], $cifradas);
    }
}
