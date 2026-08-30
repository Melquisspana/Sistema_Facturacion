<?php

namespace App\Console\Commands;

use App\Exceptions\Ppq\AlbaranDadoDeBajaException;
use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Models\Configuracion;
use App\Models\PpqAlbaran;
use App\Services\Ppq\AlbaranPersistidor;
use App\Services\Ppq\PpqGmailService;
use App\Services\Ppq\SalaSucursalResolver;
use App\Support\Albaran;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sincroniza los albaranes de Calleja desde el label de Gmail hacia `ppq_albaranes`.
 *
 * Reutiliza toda la mecánica que ya existe: GmailClient (OAuth + búsqueda + adjuntos),
 * PpqGmailService::albaranesDeFecha() (lista y parsea los correos de un día) y
 * AlbaranParser (extrae número/fecha/OC/monto del PDF). No agrega una segunda forma de
 * hablar con Gmail ni un segundo parser.
 *
 * INCREMENTAL: el progreso se ancla en una MARCA propia — el último día (fecha del CORREO)
 * cuya ventana se leyó completa y sin truncar — guardada en `configuraciones`. Desde ahí,
 * menos unos días de solape, hasta hoy. Así una corrida diaria mira solo lo nuevo, y el
 * solape recupera los albaranes que Calleja envía con retraso.
 *
 * La marca NO sale de `fecha_albaran`: esa es la fecha impresa en el PDF, un dato parseado
 * que puede venir mal (un año equivocado empujaba la ventana hacia adelante y dejaba correos
 * viejos afuera para siempre). La marca solo avanza cuando la corrida escribió de verdad
 * (`--aplicar`), terminó entera, no quedó ningún día truncado y la ventana fue contigua con
 * lo ya cubierto — así una corrida acotada a mano no puede saltarse el backlog.
 * `fecha_albaran` queda solo como respaldo para la primera corrida, antes de que exista marca.
 *
 * IDEMPOTENTE en dos niveles: se saltan los correos cuyo `gmail_message_id` ya está
 * guardado, y la escritura pasa por AlbaranPersistidor, que identifica el albarán por
 * número + OC (índice único). Correrlo N veces deja exactamente el mismo resultado.
 *
 * Dry-run por defecto: sin `--aplicar` no escribe nada. SOLO LECTURA de Gmail; no toca
 * DTE, correlativos, conciliación ni los lotes/items de PPQ.
 */
class PpqSincronizarAlbaranesCommand extends Command
{
    protected $signature = 'ppq:sincronizar-albaranes
        {--desde= : Fecha inicial YYYY-MM-DD (por defecto: último albarán sincronizado menos el solape)}
        {--hasta= : Fecha final YYYY-MM-DD (por defecto: hoy)}
        {--dias= : Ventana de N días hacia atrás desde --hasta (alternativa a --desde)}
        {--solape=3 : Días hacia atrás sobre el último sincronizado, para recuperar envíos con retraso}
        {--limite=40 : Máximo de correos a leer por día}
        {--aplicar : Escribe en ppq_albaranes (por defecto solo muestra lo que haría)}';

    protected $description = 'Importa los albaranes de Calleja desde Gmail a ppq_albaranes (incremental, idempotente, dry-run por defecto)';

    /**
     * Marca de progreso: último día (fecha del correo, YYYY-MM-DD) leído COMPLETO. Vive en
     * `configuraciones` para no depender de datos parseados del PDF.
     */
    public const CLAVE_ULTIMO_DIA = 'ppq.albaranes.ultimo_dia_completo';

    public function handle(PpqGmailService $gmail, AlbaranPersistidor $persistidor): int
    {
        if (! $gmail->disponible()) {
            $this->error('Gmail no está disponible (integración deshabilitada o cuenta desconectada). Conectá la cuenta antes de sincronizar.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        [$desde, $hasta] = $this->ventana();

        if ($desde->gt($hasta)) {
            $this->error('La fecha --desde ('.$desde->toDateString().') es posterior a --hasta ('.$hasta->toDateString().').');

            return self::FAILURE;
        }

        $this->info('Ventana: '.$desde->toDateString().' → '.$hasta->toDateString().' ('.count($this->dias($desde, $hasta)).' día/s).');

        // Idempotencia rápida: los correos ya procesados no se vuelven a bajar/parsear.
        // Incluye los borrados (withTrashed) a propósito: si alguien dio de baja un albarán,
        // la sincronización NO debe resucitarlo en la próxima corrida.
        $yaVistos = PpqAlbaran::withTrashed()
            ->whereNotNull('gmail_message_id')
            ->pluck('gmail_message_id')
            ->flip();

        $filas = [];
        $conteo = ['leidos' => 0, 'omitidos' => 0, 'nuevos' => 0, 'existentes' => 0, 'excepciones' => 0, 'sin_numero' => 0, 'dados_de_baja' => 0];
        /** @var array<int, string> $diasTruncados */
        $diasTruncados = [];

        try {
            foreach ($this->dias($desde, $hasta) as $dia) {
                $candidatos = $gmail->albaranesDeFecha($dia, (int) $this->option('limite'));

                // Se consulta JUSTO después de la búsqueda: si Gmail dejó resultados sin
                // devolver, ese día está incompleto y la marca no puede pasarlo.
                if ($gmail->ultimaBusquedaTruncada()) {
                    $diasTruncados[] = $dia;
                }

                foreach ($candidatos as $candidato) {
                    $conteo['leidos']++;

                    $messageId = $candidato['gmail_message_id'] ?? null;
                    if (filled($messageId) && $yaVistos->has($messageId)) {
                        $conteo['omitidos']++;

                        continue;
                    }

                    $filas[] = $this->procesar($candidato, $persistidor, $aplicar, $conteo);

                    // Dentro de la misma corrida, un reenvío del mismo correo tampoco se repite.
                    if (filled($messageId)) {
                        $yaVistos->put($messageId, true);
                    }
                }
            }
        } catch (GmailDesconectadoException $e) {
            $this->error('Gmail se desconectó durante la sincronización: '.$e->getMessage());
            $this->warn('Lo procesado hasta acá '.($aplicar ? 'quedó guardado' : 'no se guardó (dry-run)').'. Reconectá la cuenta y volvé a correr: es idempotente.');
            $this->warn('La marca de progreso NO se movió: la próxima corrida vuelve a leer esta ventana.');

            return self::FAILURE;
        }

        $this->avanzarMarca($desde, $hasta, $diasTruncados, $aplicar);
        $this->mostrar($filas, $conteo, $diasTruncados, $aplicar);

        return self::SUCCESS;
    }

    /**
     * Procesa un candidato: resuelve su sala y, si corresponde, lo persiste.
     *
     * @param  array<string, mixed>  $candidato
     * @param  array<string, int>  $conteo
     * @return array<int, string>
     */
    private function procesar(array $candidato, AlbaranPersistidor $persistidor, bool $aplicar, array &$conteo): array
    {
        $datos = [
            'numero_albaran' => $candidato['numero_albaran'] ?? null,
            'numero_orden_compra' => $candidato['orden_compra'] ?? null,
            'monto_albaran' => $candidato['monto'] ?? null,
            'fecha_albaran' => $candidato['fecha'] ?? null,
            'gmail_message_id' => $candidato['gmail_message_id'] ?? null,
            'sala_codigo' => $candidato['sala'] ?? null,
            // El PDF viaja hasta el persistidor, que es quien lo guarda. Antes se parseaba
            // y se descartaba: la entrega quedaba probada por un identificador de correo y
            // por nada más.
            'archivo_nombre' => $candidato['archivo_nombre'] ?? null,
            'archivo_contenido' => $candidato['archivo_contenido'] ?? null,
        ];

        $sala = $persistidor->resolverSala($datos);
        if ($sala['excepcion']) {
            $conteo['excepciones']++;
        }

        // Sin número no hay identidad posible (el índice único es número + OC): se reporta
        // como excepción y NO se escribe, para no crear filas basura imposibles de deduplicar.
        if (blank($datos['numero_albaran'])) {
            $conteo['sin_numero']++;

            return [
                (string) ($datos['gmail_message_id'] ?? '—'),
                '(sin número)',
                (string) ($datos['numero_orden_compra'] ?? '—'),
                $this->etiquetaSala($sala),
                'EXCEPCIÓN: no se pudo leer el número de albarán',
            ];
        }

        if (! $aplicar) {
            $existe = PpqAlbaran::where('numero_albaran', Albaran::numeroLimpio($datos['numero_albaran']))
                ->where('numero_orden_compra', $datos['numero_orden_compra'])
                ->exists();
            $existe ? $conteo['existentes']++ : $conteo['nuevos']++;

            return $this->fila($datos, $sala, $existe ? 'ya existe' : 'se crearía');
        }

        // registrarConSala(): esta es la ÚNICA vía que resuelve la sala y escribe
        // `cliente_sucursal_id`. El alta desde la pantalla de PPQ no la toca.
        //
        // Un albarán dado de baja se reporta y se sigue con los demás correos: antes
        // reventaba con violación de integridad y abortaba la corrida entera, dejando la
        // sincronización trabada en el mismo punto en cada intento.
        try {
            $albaran = $persistidor->registrarConSala($datos, 'gmail');
        } catch (AlbaranDadoDeBajaException $e) {
            $conteo['dados_de_baja']++;

            return $this->fila($datos, $sala, 'OMITIDO: dado de baja (#'.$e->albaranId.')');
        }

        $albaran->wasRecentlyCreated ? $conteo['nuevos']++ : $conteo['existentes']++;

        return $this->fila($datos, $sala, ($albaran->wasRecentlyCreated ? 'creado #' : 'reusado #').$albaran->id);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $sala
     * @return array<int, string>
     */
    private function fila(array $datos, array $sala, string $accion): array
    {
        return [
            (string) ($datos['gmail_message_id'] ?? '—'),
            (string) Albaran::numeroLimpio($datos['numero_albaran']),
            (string) ($datos['numero_orden_compra'] ?? '—'),
            $this->etiquetaSala($sala),
            $accion,
        ];
    }

    /** Cómo se resolvió la sala, en una celda legible. */
    private function etiquetaSala(array $sala): string
    {
        $codigo = $sala['sala_codigo'] ?? '—';

        return match ($sala['fuente']) {
            SalaSucursalResolver::FUENTE_SUCURSAL => $codigo.' · '.$sala['nombre'].' (sucursal)',
            SalaSucursalResolver::FUENTE_MAPA => $codigo.' · '.$sala['nombre'].' (mapa PPQ, sin sucursal fiscal)',
            default => $codigo.' · DESCONOCIDA',
        };
    }

    /**
     * Ventana a sincronizar. Prioridad: --desde explícito > --dias > incremental desde la
     * MARCA de progreso (menos el solape) > respaldo por `fecha_albaran` mientras no exista
     * marca > los últimos `solape` días si la tabla está vacía (primera corrida sin --desde:
     * no se barre Gmail entero sin pedirlo).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function ventana(): array
    {
        $hasta = ($this->option('hasta') ? Carbon::parse((string) $this->option('hasta')) : Carbon::today())->startOfDay();
        $solape = max(0, (int) $this->option('solape'));

        if ($this->option('desde')) {
            return [Carbon::parse((string) $this->option('desde'))->startOfDay(), $hasta];
        }

        if ($this->option('dias')) {
            return [$hasta->copy()->subDays(max(0, (int) $this->option('dias') - 1))->startOfDay(), $hasta];
        }

        $marca = Configuracion::get(self::CLAVE_ULTIMO_DIA);

        if (filled($marca)) {
            $this->line('Última ventana completa: '.$marca.' (se relee con '.$solape.' día/s de solape).');

            // El día siguiente al cubierto, retrocediendo el solape. Nunca más allá de
            // `hasta`: la ventana no puede invertirse ni saltear.
            $desde = Carbon::parse($marca)->addDay()->subDays($solape)->startOfDay();

            return [$desde->min($hasta), $hasta];
        }

        // Respaldo mientras no exista marca (primera corrida tras el despliegue).
        $ultimo = PpqAlbaran::where('origen', 'gmail')->max('fecha_albaran');

        if ($ultimo === null) {
            $this->warn('No hay albaranes de Gmail todavía: se sincronizan los últimos '.($solape + 1).' día/s. Usá --desde para una carga inicial más amplia.');

            return [$hasta->copy()->subDays($solape)->startOfDay(), $hasta];
        }

        $this->line('Sin marca de progreso todavía; se parte del último albarán sincronizado: '
            .Carbon::parse($ultimo)->toDateString().' (se relee con '.$solape.' día/s de solape).');

        // `fecha_albaran` sale del PDF y puede venir con el año mal. Se acota a `hasta` para
        // que una fecha futura no invierta la ventana y deje el comando plantado.
        $desde = Carbon::parse($ultimo)->subDays($solape)->startOfDay();
        if ($desde->gt($hasta)) {
            $this->warn('La fecha del último albarán ('.Carbon::parse($ultimo)->toDateString().') es posterior a hoy: probable error de parseo. Se acota la ventana a hoy.');
        }

        return [$desde->min($hasta), $hasta];
    }

    /**
     * Mueve la marca de progreso al último día leído COMPLETO. Es la pieza que evita perder
     * correos para siempre, así que solo avanza cuando todo se cumplió:
     *
     *  - `--aplicar`: un dry-run no leyó para guardar, no declara nada cubierto;
     *  - la corrida llegó al final (una desconexión sale antes, sin tocar la marca);
     *  - ningún día quedó truncado por el límite — si lo hubo, la marca se planta ANTES del
     *    día truncado más viejo para que la próxima corrida lo vuelva a leer;
     *  - la ventana fue CONTIGUA con lo ya cubierto: una corrida acotada a mano hacia
     *    adelante (ej. `--dias 1`) dejaría un hueco sin leer, así que no mueve la marca.
     *
     * Nunca retrocede: si otra corrida ya cubrió más, se respeta.
     *
     * @param  array<int, string>  $diasTruncados
     */
    private function avanzarMarca(Carbon $desde, Carbon $hasta, array $diasTruncados, bool $aplicar): void
    {
        if (! $aplicar) {
            return;
        }

        $marca = Configuracion::get(self::CLAVE_ULTIMO_DIA);
        $cubierto = filled($marca) ? Carbon::parse($marca)->startOfDay() : null;

        if ($cubierto !== null && $desde->gt($cubierto->copy()->addDay())) {
            $this->warn('La marca de progreso NO se movió: esta ventana arranca en '.$desde->toDateString()
                .' y lo cubierto llega hasta '.$cubierto->toDateString().', así que quedaría un hueco sin leer.');
            $this->line('Para cerrarlo, corré con --desde '.$cubierto->copy()->addDay()->toDateString().'.');

            return;
        }

        // Un día truncado no está completo: la marca se queda en el día ANTERIOR al más viejo.
        $tope = $diasTruncados === []
            ? $hasta->copy()
            : Carbon::parse(min($diasTruncados))->subDay()->startOfDay();

        if ($tope->lt($desde)) {
            $this->warn('La marca de progreso NO se movió: no hubo ningún día completo en esta ventana.');

            return;
        }

        if ($cubierto !== null && $tope->lte($cubierto)) {
            return; // ya estaba cubierto: la marca nunca retrocede
        }

        if ($cubierto === null) {
            $this->warn('Se establece la marca de progreso en '.$tope->toDateString()
                .'. Los correos ANTERIORES a '.$desde->toDateString().' no los cubrió esta corrida: si hay backlog, traelo con --desde.');
        }

        Configuracion::set(self::CLAVE_ULTIMO_DIA, $tope->toDateString());
    }

    /** @return array<int, string> fechas YYYY-MM-DD de la ventana, inclusive */
    private function dias(Carbon $desde, Carbon $hasta): array
    {
        $dias = [];
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $dias[] = $d->toDateString();
        }

        return $dias;
    }

    /**
     * @param  array<int, array<int, string>>  $filas
     * @param  array<string, int>  $conteo
     * @param  array<int, string>  $diasTruncados
     */
    private function mostrar(array $filas, array $conteo, array $diasTruncados, bool $aplicar): void
    {
        if ($filas !== []) {
            $this->table(['Mensaje Gmail', 'Albarán', 'OC', 'Sala', 'Acción'], $filas);
        }

        $this->info(sprintf(
            '%d correo(s) leídos · %d ya sincronizados (omitidos) · %d %s · %d ya existentes',
            $conteo['leidos'],
            $conteo['omitidos'],
            $conteo['nuevos'],
            $aplicar ? 'creados' : 'por crear',
            $conteo['existentes'],
        ));

        if ($diasTruncados !== []) {
            sort($diasTruncados);
            $this->warn('VENTANA TRUNCADA: el límite de '.(int) $this->option('limite')
                .' correo/s por día se alcanzó en '.implode(', ', $diasTruncados).'. Hay correos SIN LEER en esos días.');
            $this->warn('La marca de progreso se dejó antes del día truncado más viejo, así que la próxima corrida los vuelve a leer.');
            $this->line('Para cerrarlos ahora: volvé a correr con --limite más alto (ej. --limite '.(max(1, (int) $this->option('limite')) * 4).').');
        }

        if ($conteo['dados_de_baja'] > 0) {
            $this->warn('OMITIDOS: '.$conteo['dados_de_baja'].' albarán/es ya existen pero están DADOS DE BAJA; no se resucitan solos.');
            $this->line('Si alguno se dio de baja por error, restauralo a mano y volvé a correr.');
        }

        if ($conteo['excepciones'] > 0 || $conteo['sin_numero'] > 0) {
            $this->warn(sprintf(
                'EXCEPCIONES: %d con sala desconocida%s.',
                $conteo['excepciones'],
                $conteo['sin_numero'] > 0 ? ' · '.$conteo['sin_numero'].' sin número de albarán legible (no se guardan)' : '',
            ));
            $this->warn('No se creó ninguna sucursal automáticamente: esos códigos hay que revisarlos a mano.');
            $this->line('Para listarlas: PpqAlbaran::salaSinResolver()->get()');
        }

        if (! $aplicar) {
            $this->warn('DRY-RUN: no se escribió nada. Corré con --aplicar para guardar en ppq_albaranes.');
        }
    }
}
