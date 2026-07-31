<?php

namespace App\Console\Commands;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaRecepcionDetalle;
use App\Models\User;
use App\Services\Planta\LoteService;
use App\Services\Planta\PlantaCambioDisponibilidadService;
use App\Services\Planta\PlantaInventarioService;
use App\Services\Planta\PlantaRecepcionService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Verificación de CONCURRENCIA REAL del motor de inventario, contra el motor de
 * verdad (MySQL/InnoDB) y con procesos de sistema operativo separados.
 *
 * POR QUÉ EXISTE. La suite corre sobre SQLite en memoria, donde `lockForUpdate`
 * es un no-op: SQLite serializa la base entera durante una escritura, así que
 * cualquier prueba de «dos escritores a la vez» pasaría incluso si el servicio
 * NO tomara ningún bloqueo. Presentar eso como prueba de que el bloqueo
 * funciona sería mentir. Lo que de verdad hay que demostrar —que dos
 * transacciones InnoDB simultáneas sobre el mismo bucket se serializan y ninguna
 * pierde su efecto— solo se puede demostrar contra MySQL y con procesos reales:
 * dos hilos en un mismo proceso PHP compartirían conexión y no habría carrera.
 *
 * Es una herramienta de DIAGNÓSTICO, no una prueba automática, y por eso no
 * vive en la suite. Escribe datos reales en la base configurada, con el prefijo
 * `DIAGCONC-`, y los borra al terminar.
 *
 * CANDADOS. Escribe en la base configurada, así que se blinda por LISTA BLANCA,
 * no por lista negra:
 *   - solo corre con APP_ENV en {@see ENTORNOS_PERMITIDOS} (`local`, `testing`).
 *     Denegar únicamente `production` sería insuficiente: `staging`, `preprod` o
 *     cualquier entorno nuevo apuntan a bases que tampoco son de usar y tirar, y
 *     con una lista negra entrarían por omisión;
 *   - exige `--confirmar`, porque escribe;
 *   - muestra SIEMPRE conexión y base antes de tocar nada, para que quien lo
 *     lanza vea contra qué va;
 *   - trabaja SOLO con datos que crea él mismo, con el prefijo `DIAGCONC-`.
 *     No admite identificadores por parámetro y los trabajadores verifican que
 *     los que reciben corresponden a datos propios antes de escribir;
 *   - la limpieza corre en `finally`, por escenario y otra vez al terminar,
 *     aunque un proceso falle o se agote su tiempo. No crea usuarios.
 *
 * USO:
 *   php artisan planta:diagnostico-concurrencia --confirmar
 *   php artisan planta:diagnostico-concurrencia --confirmar --escenario=ultimo-saldo --procesos=8
 *
 * El rol `trabajador` es interno: lo invoca el coordinador en subprocesos y
 * escribe una sola línea JSON con su resultado.
 */
class PlantaDiagnosticoConcurrenciaCommand extends Command
{
    protected $signature = 'planta:diagnostico-concurrencia
        {--confirmar : Obligatorio: el diagnóstico ESCRIBE datos temporales en la base configurada}
        {--escenario=todos : crear-bucket|sumar|ultimo-saldo|lote-generico|recepcion-numero|recepcion-confirmar|recepcion-mismo-bucket|disponibilidad-confirmar|todos}
        {--procesos=6 : Cuántos procesos compiten a la vez}
        {--rol=coordinador : Interno. `trabajador` lo usan los subprocesos}
        {--contexto= : Interno. Contexto JSON del escenario, en base64}';

    protected $description = 'Verifica la concurrencia real del inventario de Planta contra MySQL con procesos separados';

    /** Prefijo de todo lo que crea, para poder limpiarlo sin ambigüedad. */
    private const PREFIJO = 'DIAGCONC-';

    private const ESCENARIOS = [
        // Motor de inventario (paso 5).
        'crear-bucket', 'sumar', 'ultimo-saldo', 'lote-generico',
        // Documento de recepción (paso 6).
        'recepcion-numero', 'recepcion-confirmar', 'recepcion-mismo-bucket',
        // Cambio de disponibilidad (paso 7).
        'disponibilidad-confirmar',
    ];

    /**
     * Únicos entornos donde este diagnóstico puede correr. Lista BLANCA: un
     * entorno nuevo queda excluido por omisión, que es el lado seguro.
     */
    private const ENTORNOS_PERMITIDOS = ['local', 'testing'];

    /** Segundos que se le dan a un trabajador antes de matarlo. */
    private const TIMEOUT_TRABAJADOR = 90;

    /** Segundos que espera el coordinador a que todos avisen que están listos. */
    private const TIMEOUT_BARRERA = 60;

    /**
     * Espera máxima por un bloqueo de fila, en segundos, dentro del trabajador.
     * El valor de MySQL por defecto (50 s) convertiría un atasco en un plantón; en
     * un diagnóstico interesa que salte pronto y se vea.
     */
    private const ESPERA_BLOQUEO = 10;

    public function handle(): int
    {
        if ($this->option('rol') === 'trabajador') {
            return $this->ejecutarTrabajador();
        }

        return $this->ejecutarCoordinador();
    }

    // --- Coordinador ---

    private function ejecutarCoordinador(): int
    {
        $entorno = app()->environment();
        $conexion = DB::connection();

        // Conexión y base SIEMPRE a la vista, incluso al denegar: si alguien lanza
        // esto contra la base equivocada, lo primero que debe leer es cuál era.
        $this->components->twoColumnDetail('Entorno', $entorno);
        $this->components->twoColumnDetail('Conexión', $conexion->getName());
        $this->components->twoColumnDetail('Motor', $conexion->getDriverName());
        $this->components->twoColumnDetail('Base', (string) $conexion->getDatabaseName());

        if (! in_array($entorno, self::ENTORNOS_PERMITIDOS, true)) {
            $this->components->error(sprintf(
                'Este diagnóstico ESCRIBE datos y solo puede correr en %s. Entorno actual: %s.',
                implode(' o ', self::ENTORNOS_PERMITIDOS),
                $entorno,
            ));

            return self::FAILURE;
        }

        if (! $this->option('confirmar')) {
            $this->components->error('Falta --confirmar: el diagnóstico escribe datos temporales en la base configurada.');

            return self::FAILURE;
        }

        if ($conexion->getDriverName() !== 'mysql') {
            $this->components->warn(
                'La conexión activa no es MySQL. Sobre SQLite este diagnóstico NO demuestra nada: '
                .'lockForUpdate es no-op y la base se serializa entera.'
            );
        }

        $escenarios = $this->option('escenario') === 'todos'
            ? self::ESCENARIOS
            : [$this->option('escenario')];

        $procesos = max(2, (int) $this->option('procesos'));
        $fallos = 0;

        // Limpieza de arranque: si una ejecución anterior murió de mala manera,
        // sus restos se van ahora y no contaminan la medición.
        $this->limpiar();

        try {
            foreach ($escenarios as $escenario) {
                if (! in_array($escenario, self::ESCENARIOS, true)) {
                    $this->components->error("Escenario desconocido: {$escenario}");

                    return self::FAILURE;
                }

                $this->newLine();
                $this->components->info("Escenario «{$escenario}» con {$procesos} procesos simultáneos");

                try {
                    $ok = $this->correrEscenario($escenario, $procesos);
                } catch (Throwable $e) {
                    $this->components->error($e->getMessage());
                    $ok = false;
                } finally {
                    $this->limpiar();
                }

                $fallos += $ok ? 0 : 1;
            }
        } finally {
            // Red final: cubre también el `return` de escenario desconocido y
            // cualquier excepción que se escape del bucle.
            $this->limpiar();
            $this->confirmarQueNoQuedaNada();
        }

        $this->newLine();

        if ($fallos > 0) {
            $this->components->error("{$fallos} escenario(s) NO se comportaron como exige el motor.");

            return self::FAILURE;
        }

        $this->components->info('Todos los escenarios se comportaron correctamente.');

        return self::SUCCESS;
    }

    /**
     * Comprueba y reporta que la base quedó como estaba. Un diagnóstico que deja
     * basura detrás es peor que no tenerlo: la siguiente reconciliación la
     * encontraría y la reportaría como corrupción real.
     */
    private function confirmarQueNoQuedaNada(): void
    {
        $restos = [
            'insumos' => DB::table('planta_insumos')->where('codigo', 'like', self::PREFIJO.'%')->count(),
            'ubicaciones' => DB::table('planta_ubicaciones')->where('codigo', 'like', self::PREFIJO.'%')->count(),
            'lotes' => DB::table('planta_lotes')->where('codigo_interno', 'like', self::PREFIJO.'%')->count(),
        ];

        $total = array_sum($restos);

        $this->newLine();
        $this->components->twoColumnDetail(
            'Datos temporales restantes',
            $total === 0 ? '<fg=green>ninguno</>' : '<fg=red>'.json_encode($restos).'</>'
        );
    }

    /** Prepara datos, lanza los procesos y verifica la invariante del escenario. */
    private function correrEscenario(string $escenario, int $procesos): bool
    {
        $contexto = $this->prepararEscenario($escenario);

        // Barrera de arranque POR FICHEROS. Un instante fijo no sirve: arrancar N
        // procesos PHP tarda lo que tarde la máquina, y si el instante pasa mientras
        // el último todavía bootea, los primeros ya habrán terminado y no habría
        // colisión que medir. Aquí cada trabajador avisa cuando está listo y ninguno
        // actúa hasta que lo están todos.
        $contexto['barrera'] = storage_path('app/diagnostico-concurrencia/'.Str::random(12));
        $contexto['escenario'] = $escenario;

        @mkdir($contexto['barrera'], 0777, true);

        try {
            $resultados = $this->lanzarTrabajadores($contexto, $procesos);
        } finally {
            $this->borrarBarrera($contexto['barrera']);
        }

        $exitos = count(array_filter($resultados, fn (array $r) => $r['ok']));
        $fallidos = count($resultados) - $exitos;

        $this->components->twoColumnDetail('Procesos con éxito', (string) $exitos);
        $this->components->twoColumnDetail('Procesos rechazados', (string) $fallidos);

        foreach ($resultados as $resultado) {
            if (! $resultado['ok']) {
                $this->line('  · rechazado: '.Str::limit($resultado['error'], 120));
            }
        }

        return $this->verificar($escenario, $contexto, $procesos, $exitos);
    }

    /**
     * Lanza los trabajadores, los libera a la vez y espera a que terminen TODOS.
     *
     * Esperar a todos no es un detalle: la limpieza del escenario corre justo
     * después, y borrar filas mientras un trabajador sigue escribiendo produce un
     * descuadre que parece un fallo del inventario sin serlo. Por eso ningún
     * camino de salida —ni el timeout— puede dejar procesos vivos.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<int, array{ok: bool, error: string}>
     */
    private function lanzarTrabajadores(array $contexto, int $procesos): array
    {
        $payload = base64_encode(json_encode($contexto));
        $enMarcha = [];

        for ($i = 0; $i < $procesos; $i++) {
            $proceso = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'planta:diagnostico-concurrencia',
                '--rol=trabajador',
                '--contexto='.$payload,
            ], base_path());

            $proceso->setTimeout(self::TIMEOUT_TRABAJADOR);
            $proceso->start();

            $enMarcha[] = $proceso;
        }

        $this->esperarYSoltarLaBarrera($contexto['barrera'], $procesos);

        $resultados = [];

        foreach ($enMarcha as $proceso) {
            try {
                $proceso->wait();
            } catch (Throwable $e) {
                // Timeout u otro fallo del proceso: se mata para que no siga
                // escribiendo por detrás de la limpieza.
                $proceso->stop(5);

                $resultados[] = ['ok' => false, 'error' => 'proceso interrumpido: '.Str::limit($e->getMessage(), 140)];

                continue;
            }

            $salida = trim($proceso->getOutput());
            $decodificada = json_decode($salida, true);

            $resultados[] = is_array($decodificada) && array_key_exists('ok', $decodificada)
                ? ['ok' => (bool) $decodificada['ok'], 'error' => (string) ($decodificada['error'] ?? '')]
                : ['ok' => false, 'error' => 'salida ilegible: '.Str::limit($salida.' '.$proceso->getErrorOutput(), 200)];
        }

        // Red de seguridad: nadie sigue vivo cuando esto retorna.
        foreach ($enMarcha as $proceso) {
            if ($proceso->isRunning()) {
                $proceso->stop(5);
            }
        }

        return $resultados;
    }

    /** Espera a que los N trabajadores avisen que están listos y los suelta a la vez. */
    private function esperarYSoltarLaBarrera(string $barrera, int $procesos): void
    {
        $limite = microtime(true) + self::TIMEOUT_BARRERA;

        while (microtime(true) < $limite) {
            if (count(glob($barrera.'/listo-*') ?: []) >= $procesos) {
                break;
            }

            usleep(50_000);
        }

        touch($barrera.'/ya');
    }

    private function borrarBarrera(string $barrera): void
    {
        foreach (glob($barrera.'/*') ?: [] as $archivo) {
            @unlink($archivo);
        }

        @rmdir($barrera);
    }

    /**
     * Datos mínimos de cada escenario. Se crean con el query builder porque el
     * insumo y la ubicación son catálogo normal, pero el saldo inicial de
     * `ultimo-saldo` sí pasa por el servicio: tiene que ser saldo legítimo.
     *
     * @return array<string, mixed>
     */
    private function prepararEscenario(string $escenario): array
    {
        $sufijo = strtoupper(Str::random(6));

        // Sin control de lotes en los escenarios que necesitan que TODOS los
        // procesos aterricen en el MISMO bucket: el lote genérico es único por
        // insumo, así que la quinta dimensión coincide sin acuerdo previo.
        $controlaLotes = ! in_array($escenario, ['lote-generico', 'recepcion-mismo-bucket'], true);

        $insumo = PlantaInsumo::create([
            'codigo' => self::PREFIJO.$sufijo,
            'nombre' => 'Diagnóstico de concurrencia '.$sufijo,
            'tipo' => TipoInsumo::MateriaPrima->value,
            'unidad_base' => UnidadBase::Libra->value,
            'controla_lotes' => $controlaLotes,
            'permite_fraccion' => true,
            'activo' => true,
        ]);

        $ubicacionId = DB::table('planta_ubicaciones')->insertGetId([
            'codigo' => self::PREFIJO.$sufijo,
            'nombre' => 'Bodega de diagnóstico '.$sufijo,
            'tipo' => TipoUbicacion::Fisica->value,
            'es_sistema' => false,
            'permite_operacion_manual' => true,
            'activo' => true,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contexto = ['insumo_id' => $insumo->id, 'ubicacion_id' => $ubicacionId];

        // En lote-generico el lote es justamente lo que compiten por crear.
        if ($escenario === 'lote-generico') {
            return $contexto;
        }

        // Escenarios de RECEPCIÓN: el lote lo resuelve el propio documento al
        // confirmarse, así que aquí no se prepara ninguno.
        if (str_starts_with($escenario, 'recepcion-')) {
            $contexto['usuario_id'] = $this->usuarioDelDiagnostico();

            // `recepcion-confirmar` necesita UN borrador que todos intenten
            // confirmar a la vez: es la carrera que hay que medir.
            if ($escenario === 'recepcion-confirmar') {
                $contexto['recepcion_id'] = $this->crearBorradorDeDiagnostico($contexto)->id;
            }

            return $contexto;
        }

        // Cambio de DISPONIBILIDAD: hace falta saldo RETENIDO de verdad, que solo
        // llega por una recepción confirmada con ese destino. Fabricarlo a mano en
        // `planta_existencias` produciría un escenario que el dominio no genera.
        if ($escenario === 'disponibilidad-confirmar') {
            $contexto['usuario_id'] = $this->usuarioDelDiagnostico();
            $usuario = User::findOrFail($contexto['usuario_id']);

            $recepcion = $this->crearBorradorDeDiagnostico($contexto, EstadoDisponibilidad::Retenido);
            app(PlantaRecepcionService::class)->confirmar($recepcion, $usuario);

            $detalle = $recepcion->refresh()->detalles->first();

            $cambio = app(PlantaCambioDisponibilidadService::class)->crearBorrador([
                'planta_insumo_id' => $contexto['insumo_id'],
                'planta_lote_id' => $detalle->planta_lote_id,
                'planta_ubicacion_id' => $contexto['ubicacion_id'],
                'estado_destino' => EstadoDisponibilidad::Disponible->value,
                'cantidad' => '100',
                'fecha' => now()->toDateString(),
                'motivo' => 'Diagnóstico de concurrencia del paso 7',
            ], $usuario);

            $contexto['cambio_id'] = $cambio->id;
            $contexto['lote_id'] = $detalle->planta_lote_id;

            return $contexto;
        }

        $contexto['lote_id'] = DB::table('planta_lotes')->insertGetId([
            'planta_insumo_id' => $insumo->id,
            'codigo_interno' => self::PREFIJO.$sufijo,
            'es_generico' => false,
            'fecha_recepcion' => now()->toDateString(),
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `sumar` y `ultimo-saldo` necesitan que el bucket ya exista con saldo.
        if ($escenario !== 'crear-bucket') {
            $servicio = app(PlantaInventarioService::class);
            $bucket = $this->bucketDe($contexto);

            DB::transaction(fn () => $servicio->aplicarMovimiento(
                $bucket,
                $escenario === 'ultimo-saldo' ? '10.0000' : '100.0000',
                ContextoMovimiento::para(
                    tipo: TipoMovimientoPlanta::CargaInicial,
                    documentoType: 'diagnostico.concurrencia',
                    documentoId: 1,
                    transicion: 'preparar',
                    fechaEfectiva: now(),
                    grupoUuid: (string) Str::uuid(),
                ),
            ));
        }

        return $contexto;
    }

    /**
     * Usuario con el que se firman las recepciones del diagnóstico.
     *
     * Se REUTILIZA un administrador existente en vez de crear uno: un usuario de
     * prueba que sobreviva a un fallo de limpieza es una cuenta con permisos
     * flotando en la base, que es bastante peor que una fila de inventario.
     */
    private function usuarioDelDiagnostico(): int
    {
        $usuario = User::role('administrador')->first();

        if ($usuario === null) {
            throw new RuntimeException(
                'El diagnóstico de recepciones necesita un usuario con rol administrador ya existente. '
                .'No crea usuarios: sembrar cuentas de prueba es justo lo que no debe dejar detrás.'
            );
        }

        return $usuario->id;
    }

    /** @param  array<string, mixed>  $contexto */
    private function crearBorradorDeDiagnostico(
        array $contexto,
        EstadoDisponibilidad $destino = EstadoDisponibilidad::Disponible,
    ): PlantaRecepcion {
        return app(PlantaRecepcionService::class)->crearBorrador([
            'fecha' => now()->toDateString(),
            'planta_ubicacion_id' => $contexto['ubicacion_id'],
            'documento_referencia' => self::PREFIJO.'REF',
            'detalles' => [[
                'planta_insumo_id' => $contexto['insumo_id'],
                'cantidad_recibida' => '5',
                'unidad_recibida' => 'saco',
                'contenido_por_unidad' => '100',
                'factor_conversion' => '1',
                'estado_destino' => $destino->value,
            ]],
        ], User::findOrFail($contexto['usuario_id']));
    }

    /** Comprueba la invariante que cada escenario debe cumplir tras la tormenta. */
    private function verificar(string $escenario, array $contexto, int $procesos, int $exitos): bool
    {
        if (str_starts_with($escenario, 'recepcion-')) {
            return $this->verificarRecepcion($escenario, $contexto, $procesos, $exitos);
        }

        if ($escenario === 'disponibilidad-confirmar') {
            return $this->verificarDisponibilidad($contexto, $procesos, $exitos);
        }

        if ($escenario === 'lote-generico') {
            $lotes = DB::table('planta_lotes')
                ->where('planta_insumo_id', $contexto['insumo_id'])
                ->where('es_generico', true)
                ->count();

            $this->components->twoColumnDetail('Lotes genéricos creados', (string) $lotes);

            return $this->afirmar($lotes === 1, 'debe existir EXACTAMENTE 1 lote genérico por insumo');
        }

        $bucket = $this->bucketDe($contexto);
        $columnas = $bucket->aColumnas();

        $filas = DB::table('planta_existencias')->where($columnas)->count();
        $saldo = (string) (DB::table('planta_existencias')->where($columnas)->value('cantidad') ?? '0');
        $sumaMayor = (string) (DB::table('planta_movimientos')->where($columnas)->sum('cantidad'));

        $this->components->twoColumnDetail('Filas de existencia del bucket', (string) $filas);
        $this->components->twoColumnDetail('Saldo proyectado', $saldo);
        $this->components->twoColumnDetail('Suma del mayor', $sumaMayor);

        $ok = $this->afirmar($filas === 1, 'el bucket debe tener EXACTAMENTE 1 fila de existencia');
        $ok = $this->afirmar(bccomp($saldo, $sumaMayor, 4) === 0, 'el saldo proyectado debe igualar la suma del mayor') && $ok;

        $esperado = match ($escenario) {
            // Cada proceso suma 1: ningún efecto puede perderse.
            'crear-bucket' => bcmul((string) $exitos, '1.0000', 4),
            // Saldo inicial 100 más 10 por proceso con éxito.
            'sumar' => bcadd('100.0000', bcmul((string) $exitos, '10.0000', 4), 4),
            // Saldo inicial 10 y un solo ganador que se lo lleva entero.
            'ultimo-saldo' => '0.0000',
            default => $saldo,
        };

        $ok = $this->afirmar(bccomp($saldo, $esperado, 4) === 0, "el saldo esperado es {$esperado}") && $ok;

        if ($escenario === 'ultimo-saldo') {
            $ok = $this->afirmar($exitos === 1, "solo 1 de los {$procesos} procesos puede llevarse el último saldo") && $ok;
        } else {
            $ok = $this->afirmar($exitos === $procesos, 'ningún proceso debería ser rechazado en este escenario') && $ok;
        }

        return $ok;
    }

    /**
     * Invariantes de los escenarios de recepción.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function verificarRecepcion(string $escenario, array $contexto, int $procesos, int $exitos): bool
    {
        $recepciones = PlantaRecepcion::where('planta_ubicacion_id', $contexto['ubicacion_id'])->get();
        $numeros = $recepciones->pluck('numero');

        $this->components->twoColumnDetail('Recepciones creadas', (string) $recepciones->count());
        $this->components->twoColumnDetail('Números distintos', (string) $numeros->unique()->count());

        // Vale para los tres: numerar dos documentos igual rompería el unique y
        // dejaría el módulo sin forma de referirse a uno de ellos.
        $ok = $this->afirmar(
            $numeros->count() === $numeros->unique()->count(),
            'ningún número de recepción puede repetirse'
        );

        if ($escenario === 'recepcion-numero') {
            $ok = $this->afirmar($recepciones->count() === $exitos, 'una recepción por proceso con éxito') && $ok;

            return $this->afirmar($exitos === $procesos, 'crear un borrador no debería rechazar a nadie') && $ok;
        }

        $movimientos = DB::table('planta_movimientos')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])->count();
        $filas = DB::table('planta_existencias')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])->count();
        $saldo = (string) (DB::table('planta_existencias')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])->sum('cantidad') ?? '0');
        $sumaMayor = (string) DB::table('planta_movimientos')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])->sum('cantidad');

        $this->components->twoColumnDetail('Movimientos escritos', (string) $movimientos);
        $this->components->twoColumnDetail('Filas de existencia', (string) $filas);
        $this->components->twoColumnDetail('Saldo proyectado', $saldo);
        $this->components->twoColumnDetail('Suma del mayor', $sumaMayor);

        $ok = $this->afirmar(bccomp($saldo, $sumaMayor, 4) === 0, 'el saldo debe igualar la suma del mayor') && $ok;

        if ($escenario === 'recepcion-confirmar') {
            // La carrera: N procesos confirmando el MISMO documento. Solo uno puede
            // ganar; si ganaran dos, el saldo entraría dos veces.
            $ok = $this->afirmar($exitos === 1, "solo 1 de los {$procesos} procesos puede confirmar el documento") && $ok;
            $ok = $this->afirmar($movimientos === 1, 'el documento tiene una línea: debe producir UN movimiento') && $ok;
            $ok = $this->afirmar(bccomp($saldo, '500.0000', 4) === 0, 'el saldo esperado es 500.0000') && $ok;

            return $ok;
        }

        // recepcion-mismo-bucket: cada proceso crea Y confirma su documento, y
        // todos aterrizan en el mismo bucket porque el insumo no controla lotes.
        $esperado = bcmul((string) $exitos, '500.0000', 4);

        $ok = $this->afirmar($filas === 1, 'todos deben aterrizar en UNA sola fila de existencia') && $ok;
        $ok = $this->afirmar($movimientos === $exitos, 'un movimiento por confirmación con éxito') && $ok;
        $ok = $this->afirmar(bccomp($saldo, $esperado, 4) === 0, "el saldo esperado es {$esperado}") && $ok;

        return $this->afirmar($exitos === $procesos, 'ninguna confirmación debería perderse') && $ok;
    }

    /**
     * Invariantes del cambio de disponibilidad concurrente.
     *
     * N procesos confirman el MISMO documento a la vez. Solo uno puede ganar: si
     * ganaran dos, el saldo se movería dos veces y el par dejaría de sumar cero.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function verificarDisponibilidad(array $contexto, int $procesos, int $exitos): bool
    {
        $movimientos = DB::table('planta_movimientos')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])
            ->where('tipo', TipoMovimientoPlanta::CambioDisponibilidad->value)
            ->get(['cantidad', 'estado']);

        $retenido = (string) (DB::table('planta_existencias')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])
            ->where('estado', EstadoDisponibilidad::Retenido->value)->value('cantidad') ?? '0');
        $disponible = (string) (DB::table('planta_existencias')
            ->where('planta_ubicacion_id', $contexto['ubicacion_id'])
            ->where('estado', EstadoDisponibilidad::Disponible->value)->value('cantidad') ?? '0');

        $sumaPar = (string) $movimientos->reduce(fn ($acc, $m) => bcadd((string) $acc, (string) $m->cantidad, 4), '0');

        $this->components->twoColumnDetail('Procesos con éxito', (string) $exitos);
        $this->components->twoColumnDetail('Movimientos del cambio', (string) $movimientos->count());
        $this->components->twoColumnDetail('Saldo retenido', $retenido);
        $this->components->twoColumnDetail('Saldo disponible', $disponible);
        $this->components->twoColumnDetail('Suma del par', $sumaPar);

        $ok = $this->afirmar($exitos === 1, "solo 1 de los {$procesos} procesos puede confirmar el documento");
        $ok = $this->afirmar($movimientos->count() === 2, 'la confirmación produce EXACTAMENTE dos movimientos') && $ok;
        $ok = $this->afirmar(bccomp($sumaPar, '0', 4) === 0, 'el par compensado debe sumar cero') && $ok;
        $ok = $this->afirmar(bccomp($disponible, '100.0000', 4) === 0, 'el saldo liberado debe ser 100.0000') && $ok;
        $ok = $this->afirmar(bccomp($retenido, '400.0000', 4) === 0, 'el retenido restante debe ser 400.0000') && $ok;

        return $ok;
    }

    private function afirmar(bool $condicion, string $descripcion): bool
    {
        $this->line($condicion ? "  <fg=green>OK</> {$descripcion}" : "  <fg=red>FALLA</> {$descripcion}");

        return $condicion;
    }

    /**
     * Borra TODO lo que creó el diagnóstico, en orden de dependencias.
     *
     * Usa el query builder para los movimientos, que es exactamente la puerta
     * trasera documentada en {@see PlantaMovimiento}: el
     * candado del modelo no la cubre. Aquí es deliberado y acotado a los datos
     * con prefijo `DIAGCONC-`; en el dominio no hay ni un solo sitio que lo haga.
     */
    private function limpiar(): void
    {
        $insumos = DB::table('planta_insumos')->where('codigo', 'like', self::PREFIJO.'%')->pluck('id');
        $ubicaciones = DB::table('planta_ubicaciones')->where('codigo', 'like', self::PREFIJO.'%')->pluck('id');

        if ($insumos->isEmpty() && $ubicaciones->isEmpty()) {
            return;
        }

        // Orden de dependencias, de las hojas a la raíz. Las recepciones van
        // primero porque sus líneas apuntan a lotes e insumos.
        $recepciones = DB::table('planta_recepciones')->whereIn('planta_ubicacion_id', $ubicaciones)->pluck('id');
        $detalles = DB::table('planta_recepcion_detalles')->whereIn('planta_recepcion_id', $recepciones)->pluck('id');

        DB::table('planta_recepcion_detalles')->whereIn('planta_recepcion_id', $recepciones)->delete();
        // Las auto-referencias (reversion_de_id / revertido_por_id) se sueltan
        // antes de borrar: si no, el restrictOnDelete bloquearía el borrado.
        DB::table('planta_recepciones')->whereIn('id', $recepciones)
            ->update(['reversion_de_id' => null, 'revertido_por_id' => null]);
        DB::table('planta_recepciones')->whereIn('id', $recepciones)->delete();

        // Cambios de disponibilidad: mismos punteros de reversión que las
        // recepciones, así que se sueltan antes de borrar.
        $cambios = DB::table('planta_cambios_disponibilidad')
            ->whereIn('planta_ubicacion_id', $ubicaciones)->pluck('id');

        DB::table('planta_cambios_disponibilidad')->whereIn('id', $cambios)
            ->update(['reversion_de_id' => null, 'revertido_por_id' => null]);
        DB::table('planta_cambios_disponibilidad')->whereIn('id', $cambios)->delete();

        // Los movimientos de reversión apuntan a los originales con una auto-FK
        // `restrictOnDelete`: hay que soltarla antes o el borrado en bloque falla.
        DB::table('planta_movimientos')->whereIn('planta_insumo_id', $insumos)
            ->update(['movimiento_revertido_id' => null]);
        DB::table('planta_movimientos')->whereIn('planta_insumo_id', $insumos)->delete();

        DB::table('planta_existencias')->whereIn('planta_insumo_id', $insumos)->delete();
        DB::table('planta_lotes')->whereIn('planta_insumo_id', $insumos)->delete();
        DB::table('planta_insumos')->whereIn('id', $insumos)->delete();
        DB::table('planta_ubicaciones')->whereIn('id', $ubicaciones)->delete();

        // El registro de actividad de las recepciones del diagnóstico tampoco
        // debe quedarse: es ruido en la auditoría real. Se limpian los dos
        // canales —cabecera y líneas—, porque LogsActivity escribe en ambos.
        DB::table('activity_log')
            ->where('subject_type', PlantaRecepcion::class)
            ->whereIn('subject_id', $recepciones)
            ->delete();
        DB::table('activity_log')
            ->where('subject_type', PlantaRecepcionDetalle::class)
            ->whereIn('subject_id', $detalles)
            ->delete();
        DB::table('activity_log')
            ->where('subject_type', PlantaCambioDisponibilidad::class)
            ->whereIn('subject_id', $cambios)
            ->delete();
    }

    // --- Trabajador ---

    /** Un solo intento, en su propio proceso y su propia conexión. */
    private function ejecutarTrabajador(): int
    {
        if (! in_array(app()->environment(), self::ENTORNOS_PERMITIDOS, true)) {
            // El trabajador es otro proceso y vuelve a comprobarlo por su cuenta:
            // el candado del coordinador no viaja con él.
            $this->output->write(json_encode(['ok' => false, 'error' => 'entorno no permitido']));

            return self::FAILURE;
        }

        $contexto = json_decode(base64_decode((string) $this->option('contexto')), true);

        // Conexión ya abierta y espera de bloqueo acotada ANTES de la barrera: así
        // el tiempo de conectar no se cuela dentro de la ventana de colisión.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.self::ESPERA_BLOQUEO);
        }

        try {
            // La validación va ANTES de la barrera: si los identificadores no son
            // del diagnóstico, no tiene sentido esperar a nadie para luego negarse,
            // y esperando se bloquearía además al resto del grupo.
            $this->exigirDatosDelDiagnostico($contexto);

            $this->esperarEnLaBarrera($contexto['barrera']);

            $this->trabajo($contexto);

            $this->output->write(json_encode(['ok' => true]));
        } catch (Throwable $e) {
            $this->output->write(json_encode(['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()]));
        }

        return self::SUCCESS;
    }

    /**
     * Se niega a tocar nada que no haya creado el propio diagnóstico.
     *
     * `--contexto` es un parámetro interno, pero es un parámetro: nada impide
     * invocar el rol `trabajador` a mano con los identificadores de un insumo o
     * una ubicación REALES, y entonces el diagnóstico escribiría movimientos de
     * mentira sobre inventario de verdad. Se comprueba por el prefijo del código,
     * que es lo único que el coordinador controla al crearlos.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function exigirDatosDelDiagnostico(array $contexto): void
    {
        $codigoInsumo = DB::table('planta_insumos')->where('id', $contexto['insumo_id'])->value('codigo');
        $codigoUbicacion = DB::table('planta_ubicaciones')->where('id', $contexto['ubicacion_id'])->value('codigo');

        foreach (['insumo' => $codigoInsumo, 'ubicación' => $codigoUbicacion] as $que => $codigo) {
            if ($codigo === null || ! str_starts_with((string) $codigo, self::PREFIJO)) {
                throw new RuntimeException(sprintf(
                    'El %s indicado no pertenece al diagnóstico (código «%s»): este comando solo '
                    .'escribe sobre datos con prefijo %s que crea él mismo.',
                    $que,
                    $codigo ?? 'inexistente',
                    self::PREFIJO,
                ));
            }
        }

        if (isset($contexto['lote_id'])) {
            $lote = DB::table('planta_lotes')->where('id', $contexto['lote_id'])->first(['codigo_interno', 'planta_insumo_id']);

            if ($lote === null || (int) $lote->planta_insumo_id !== (int) $contexto['insumo_id']) {
                throw new RuntimeException('El lote indicado no pertenece al insumo del diagnóstico.');
            }
        }
    }

    /** Avisa de que este proceso está listo y espera la señal de salida común. */
    private function esperarEnLaBarrera(string $barrera): void
    {
        touch($barrera.'/listo-'.getmypid());

        $limite = microtime(true) + self::TIMEOUT_BARRERA;

        while (! file_exists($barrera.'/ya') && microtime(true) < $limite) {
            usleep(20_000);
        }
    }

    /** @param  array<string, mixed>  $contexto */
    private function trabajo(array $contexto): void
    {
        if (str_starts_with($contexto['escenario'], 'recepcion-')) {
            $this->trabajoDeRecepcion($contexto);

            return;
        }

        if ($contexto['escenario'] === 'disponibilidad-confirmar') {
            app(PlantaCambioDisponibilidadService::class)->confirmar(
                PlantaCambioDisponibilidad::findOrFail($contexto['cambio_id']),
                User::findOrFail($contexto['usuario_id']),
            );

            return;
        }

        if ($contexto['escenario'] === 'lote-generico') {
            $insumo = PlantaInsumo::findOrFail($contexto['insumo_id']);

            app(LoteService::class)->resolverGenerico($insumo, now()->toDateString());

            return;
        }

        $cantidad = match ($contexto['escenario']) {
            'crear-bucket' => '1.0000',
            'sumar' => '10.0000',
            'ultimo-saldo' => '-10.0000',
        };

        $servicio = app(PlantaInventarioService::class);
        $bucket = $this->bucketDe($contexto);

        DB::transaction(fn () => $servicio->aplicarMovimiento(
            $bucket,
            $cantidad,
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'diagnostico.concurrencia',
                // Documento distinto por proceso: si compartieran efecto_uid, el
                // duplicado los rechazaría y no se estaría midiendo la concurrencia
                // del saldo sino la idempotencia.
                documentoId: getmypid(),
                transicion: 'aplicar',
                fechaEfectiva: now(),
                grupoUuid: (string) Str::uuid(),
            ),
        ), 3);
    }

    /**
     * Lo que hace cada proceso en los escenarios de recepción.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function trabajoDeRecepcion(array $contexto): void
    {
        $usuario = User::findOrFail($contexto['usuario_id']);
        $servicio = app(PlantaRecepcionService::class);

        // Todos crean su borrador a la vez: es la carrera por la numeración.
        if ($contexto['escenario'] === 'recepcion-numero') {
            $this->crearBorradorDeDiagnostico($contexto);

            return;
        }

        // Todos confirman el MISMO documento: solo uno puede ganar.
        if ($contexto['escenario'] === 'recepcion-confirmar') {
            $servicio->confirmar(PlantaRecepcion::findOrFail($contexto['recepcion_id']), $usuario);

            return;
        }

        // Cada uno crea Y confirma el suyo, y todos caen en el mismo bucket
        // porque el insumo no controla lotes: comparten el lote genérico.
        $servicio->confirmar($this->crearBorradorDeDiagnostico($contexto), $usuario);
    }

    /** @param  array<string, mixed>  $contexto */
    private function bucketDe(array $contexto): BucketInventario
    {
        return new BucketInventario(
            insumoId: (int) $contexto['insumo_id'],
            loteId: (int) $contexto['lote_id'],
            ubicacionId: (int) $contexto['ubicacion_id'],
            estado: EstadoDisponibilidad::Disponible,
        );
    }
}
