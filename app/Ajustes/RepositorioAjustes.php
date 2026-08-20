<?php

namespace App\Ajustes;

use App\Models\AjusteSistema;
use App\Models\Configuracion;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Acceso a la tabla `ajustes_sistema`: lectura cacheada, cifrado/descifrado y
 * escritura con invalidación.
 *
 * ESTRATEGIA DE CACHÉ — el problema que resuelve
 * ------------------------------------------------------------------
 * La caché estática de {@see Configuracion} vive en una propiedad
 * `static` del proceso. En una petición web da igual (el proceso muere al
 * terminar), pero un worker de cola vive horas: si lee `correo.auto_envio` una
 * vez, se queda con ese valor hasta que alguien reinicie el worker. Cambiar la
 * configuración desde la web no tiene efecto sobre los correos que salen.
 *
 * Acá la caché es del STORE COMPARTIDO (database en este despliegue), no del
 * proceso, y está VERSIONADA por una huella:
 *
 *   ajustes:huella          → un UUID que cambia en cada escritura
 *   ajustes:mapa:{huella}   → el mapa completo clave ⇒ fila
 *
 * Cada lectura consulta la huella (1 hit de caché) y solo reutiliza su memoria
 * de proceso si la huella no cambió. Cuando un administrador guarda algo, la
 * huella cambia y TODOS los procesos —web, worker, CLI— pasan a la entrada nueva
 * en su siguiente lectura, sin reinicios y sin invalidación cruzada manual.
 *
 * El mapa se guarda entero en una sola entrada, no clave por clave: la tabla es
 * de decenas de filas, se lee muchas veces por petición y así una escritura
 * invalida un objeto, no N.
 *
 * LÍMITE CONOCIDO (documentado, no mágico): si el store de caché es `array`
 * (proceso local, como en la suite de tests), la huella no se comparte entre
 * procesos y un worker seguiría sin enterarse. En este despliegue CACHE_STORE es
 * `database`, que sí se comparte. La TTL de 5 minutos del mapa es la red de
 * seguridad si una invalidación se perdiera.
 */
class RepositorioAjustes
{
    /** Clave de la huella de versión (compartida entre procesos). */
    private const CLAVE_HUELLA = 'ajustes:huella';

    /** Prefijo del mapa cacheado, versionado por la huella. */
    private const PREFIJO_MAPA = 'ajustes:mapa:';

    /** Red de seguridad si una invalidación se perdiera. Segundos. */
    private const TTL_MAPA = 300;

    /** Memoria de proceso, válida SOLO mientras la huella no cambie. */
    private ?string $huellaMemo = null;

    /** @var array<string, array{valor: ?string, cifrado: bool, actualizado: ?string}>|null */
    private ?array $mapaMemo = null;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Fila cruda de un ajuste, o null si no hay override.
     *
     * @return array{valor: ?string, cifrado: bool, actualizado: ?string}|null
     */
    public function fila(string $clave): ?array
    {
        return $this->mapa()[$clave] ?? null;
    }

    /**
     * Valor almacenado ya DESCIFRADO si correspondía, o null si no hay override.
     * Es el único punto del sistema que descifra ajustes.
     */
    public function valor(string $clave): ?string
    {
        $fila = $this->fila($clave);

        if ($fila === null || $fila['valor'] === null) {
            return null;
        }

        return $fila['cifrado'] ? Crypt::decryptString($fila['valor']) : $fila['valor'];
    }

    public function existe(string $clave): bool
    {
        return $this->fila($clave) !== null;
    }

    /** Momento del último cambio del override, para la comprobación optimista. */
    public function actualizadoEn(string $clave): ?Carbon
    {
        $fila = $this->fila($clave);

        return $fila !== null && $fila['actualizado'] !== null ? Carbon::parse($fila['actualizado']) : null;
    }

    /**
     * Escribe (o reemplaza) el override. `$texto` llega YA validado y normalizado;
     * este método solo decide si se cifra y persiste.
     */
    public function guardar(string $clave, ?string $texto, bool $cifrar): void
    {
        AjusteSistema::query()->updateOrCreate(
            ['clave' => $clave],
            [
                'valor' => $texto !== null && $cifrar ? Crypt::encryptString($texto) : $texto,
                'cifrado' => $texto !== null && $cifrar,
            ],
        );

        $this->invalidar();
    }

    /** Quita el override para que el ajuste vuelva a resolverse por su fallback. */
    public function eliminar(string $clave): void
    {
        AjusteSistema::query()->where('clave', $clave)->delete();

        $this->invalidar();
    }

    /**
     * Invalida la caché compartida y la memoria de proceso. Pública porque los
     * tests y un comando de mantenimiento necesitan poder forzarla.
     */
    public function invalidar(): void
    {
        $huellaVieja = $this->huellaMemo;

        $this->cache->forever(self::CLAVE_HUELLA, (string) Str::uuid());

        if ($huellaVieja !== null) {
            $this->cache->forget(self::PREFIJO_MAPA.$huellaVieja);
        }

        $this->huellaMemo = null;
        $this->mapaMemo = null;
    }

    /**
     * Claves con valor CIFRADO. Precondición de la rotación de APP_KEY: antes de
     * cambiarla hay que saber exactamente qué filas hay que volver a cifrar.
     * Ver docs/ROTACION_APP_KEY.md.
     *
     * @return array<int, string>
     */
    public function clavesCifradas(): array
    {
        return array_keys(array_filter($this->mapa(), static fn (array $f) => $f['cifrado']));
    }

    // ---------------------------------------------------------------- interno

    /** @return array<string, array{valor: ?string, cifrado: bool, actualizado: ?string}> */
    private function mapa(): array
    {
        $huella = $this->huella();

        if ($this->mapaMemo !== null && $this->huellaMemo === $huella) {
            return $this->mapaMemo;
        }

        $this->huellaMemo = $huella;
        $this->mapaMemo = $this->cache->remember(
            self::PREFIJO_MAPA.$huella,
            self::TTL_MAPA,
            fn () => $this->leerDeBaseDeDatos(),
        );

        return $this->mapaMemo;
    }

    /** @return array<string, array{valor: ?string, cifrado: bool, actualizado: ?string}> */
    private function leerDeBaseDeDatos(): array
    {
        // LA VENTANA ENTRE DESPLEGAR Y MIGRAR.
        //
        // Un despliegue normal es `git pull` y después `php artisan migrate`. En
        // los segundos o minutos que pasan entre las dos cosas, el código nuevo
        // corre contra el esquema viejo y esta tabla todavía no existe. Sin esta
        // comprobación, CADA lectura de configuración lanzaría una excepción de
        // SQL — y no solo se caería el Centro de Configuración: también el
        // observer de DTE y el job de correo, que resuelven ajustes. La
        // aplicación entera devolvería 500 durante toda la ventana.
        //
        // Sin la tabla no hay overrides, así que devolver un mapa vacío es
        // exactamente correcto: cada ajuste cae a su lectura de transición, a
        // config/.env o a su valor por defecto, que es como se comportaba el
        // sistema antes de que la tabla existiera.
        //
        // Se comprueba la EXISTENCIA en vez de atrapar la excepción a propósito:
        // un try/catch convertiría también una base caída en "no hay overrides" y
        // la configuración caería a sus valores por defecto sin que nadie se
        // enterara. Una tabla que falta es una situación conocida y transitoria;
        // una consulta que falla teniendo la tabla, no.
        //
        // El coste es una consulta de esquema por RECARGA del mapa, no por
        // lectura: lo que sigue queda cacheado igual que el mapa.
        if (! Schema::hasTable((new AjusteSistema)->getTable())) {
            return [];
        }

        // Una sola consulta para toda la tabla: es pequeña por diseño (overrides,
        // no catálogo) y se consulta varias veces por petición.
        return AjusteSistema::query()
            ->get(['clave', 'valor', 'cifrado', 'updated_at'])
            ->mapWithKeys(static fn (AjusteSistema $a) => [
                (string) $a->clave => [
                    'valor' => $a->valor,
                    'cifrado' => (bool) $a->cifrado,
                    'actualizado' => $a->updated_at?->toDateTimeString(),
                ],
            ])
            ->all();
    }

    private function huella(): string
    {
        $huella = $this->cache->get(self::CLAVE_HUELLA);

        if (! is_string($huella) || $huella === '') {
            $huella = (string) Str::uuid();
            $this->cache->forever(self::CLAVE_HUELLA, $huella);
        }

        return $huella;
    }
}
