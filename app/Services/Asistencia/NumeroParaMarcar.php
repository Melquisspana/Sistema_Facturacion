<?php

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaEmpleado;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * El número de 4 dígitos para marcar sin huella (aprobado por Melqui el 26/11/2026). Es el
 * único sitio que lo pone, lo quita, lo busca y arma la lista que se lleva el lector.
 *
 * ───────────────────────────── Cómo se guarda ─────────────────────────────
 *
 * Dos columnas: el número CIFRADO (`numero_marcar`, cast `encrypted`) y un ÍNDICE
 * (`numero_marcar_indice`) = HMAC-SHA256 del número con una clave derivada de APP_KEY.
 * El índice deja buscar a la persona sin descifrar a todos, y su ÚNICO en la base es lo
 * que de verdad garantiza que dos personas no compartan número aunque dos pedidos lleguen
 * a la vez. Sin la APP_KEY, el índice no sirve para adivinar el número.
 *
 * Si se rota APP_KEY, `ajustes:rotar-app-key` re-cifra el número y rehace el índice
 * (ver App\Ajustes\Rotacion\RotacionAppKey).
 *
 * ──────────────────────────── Lo que ve el lector ────────────────────────────
 *
 * Nunca el número en claro: sha256(sal . número). La `version` es un hash de lo que se
 * lista, así no hay que guardarla, y la `sal` sale de APP_KEY y de esa versión: cambia
 * cada vez que cambia la lista. Quien tenga la lista puede probar los 10.000 números; por
 * eso solo la entrega el endpoint con token de lector.
 *
 * Después de guardarlo, ninguna pantalla lo vuelve a mostrar: si alguien lo olvida, se le
 * pone otro.
 */
class NumeroParaMarcar
{
    /** Exactamente cuatro dígitos ASCII. `D`: el `$` no acepta un salto de línea al final. */
    public const PATRON = '/^[0-9]{4}$/D';

    public const OCUPADO = 'Ese número ya lo tiene otra persona. Elegí otro.';

    /** La pantalla del lector no aguanta más. */
    private const LARGO_NOMBRE = 24;

    /** El índice con la APP_KEY actual, o con otra (la rotación la pasa en binario). */
    public static function indice(string $numero, ?string $claveApp = null): string
    {
        return hash_hmac('sha256', $numero, self::clave('indice', $claveApp));
    }

    /**
     * @throws ValidationException si otra persona ya tiene ese número
     */
    public function asignar(AsistenciaEmpleado $empleado, string $numero): void
    {
        if (preg_match(self::PATRON, $numero) !== 1) {
            throw new InvalidArgumentException('El número para marcar tiene que tener exactamente 4 dígitos.');
        }

        $indice = self::indice($numero);

        // La consulta da el mensaje; el ÚNICO de la base, la garantía (dos pedidos a la vez).
        if (AsistenciaEmpleado::query()->where('numero_marcar_indice', $indice)->whereKeyNot($empleado->getKey())->exists()) {
            throw ValidationException::withMessages(['numero' => self::OCUPADO]);
        }

        try {
            DB::transaction(function () use ($empleado, $numero, $indice) {
                $empleado->forceFill(['numero_marcar' => $numero, 'numero_marcar_indice' => $indice])->save();
                // Sin el número ni el índice: solo que se puso.
                activity('asistencia')->performedOn($empleado)->event('numero_marcar')->log('puso un número para marcar');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero' => self::OCUPADO]);
        }
    }

    /** Deja a la persona solo con huella. `false` si no tenía número. */
    public function quitar(AsistenciaEmpleado $empleado): bool
    {
        if (! $empleado->tieneNumeroMarcar()) {
            return false;
        }

        DB::transaction(function () use ($empleado) {
            $empleado->forceFill(['numero_marcar' => null, 'numero_marcar_indice' => null])->save();
            activity('asistencia')->performedOn($empleado)->event('numero_marcar')->log('quitó el número para marcar');
        });

        return true;
    }

    /** Un número libre al azar, para proponerlo en la ficha. `null` si están todos ocupados. */
    public function proponer(): ?string
    {
        $ocupados = array_flip(AsistenciaEmpleado::query()->whereNotNull('numero_marcar_indice')->pluck('numero_marcar_indice')->all());
        $clave = self::clave('indice');

        // Orden al azar con el generador seguro del sistema: el primero libre que aparezca.
        foreach ((new Randomizer)->shuffleArray(range(0, 9999)) as $n) {
            $numero = sprintf('%04d', $n);
            if (! isset($ocupados[hash_hmac('sha256', $numero, $clave)])) {
                return $numero;
            }
        }

        return null;
    }

    /** La persona que tiene ese número, activa o no. */
    public function buscar(string $numero): ?AsistenciaEmpleado
    {
        $indice = self::indice($numero);
        $empleado = AsistenciaEmpleado::query()->where('numero_marcar_indice', $indice)->first();

        // La collation de MySQL compara sin distinguir mayúsculas: se confirma exacto, a tiempo constante.
        return $empleado !== null && hash_equals((string) $empleado->numero_marcar_indice, $indice) ? $empleado : null;
    }

    /** La versión de la lista del lector, sin descifrar nada. Va en el ping. */
    public function version(): string
    {
        return $this->versionDe($this->listados());
    }

    /**
     * Lo que se lleva el lector: activos con número, sin el número en claro.
     *
     * @return array{version: string, sal: string, numeros: list<array{e: int, h: string, n: string}>}
     */
    public function paraLector(): array
    {
        $empleados = $this->listados();
        $version = $this->versionDe($empleados);
        $sal = substr(hash_hmac('sha256', $version, self::clave('sal')), 0, 32);

        return [
            'version' => $version,
            'sal' => $sal,
            'numeros' => $empleados->map(fn (AsistenciaEmpleado $empleado) => [
                'e' => (int) $empleado->id,
                'h' => hash('sha256', $sal.$empleado->numero_marcar),
                'n' => $this->nombre($empleado),
            ])->values()->all(),
        ];
    }

    /** @return Collection<int, AsistenciaEmpleado> */
    private function listados(): Collection
    {
        return AsistenciaEmpleado::query()
            ->where('activo', true)
            ->whereNotNull('numero_marcar_indice')
            ->orderBy('id')
            ->get();
    }

    /** Hash determinista de (id, índice, nombre, activo) de los listados: no hace falta guardarla. */
    private function versionDe(Collection $empleados): string
    {
        $lista = $empleados->map(fn (AsistenciaEmpleado $empleado) => [
            (int) $empleado->id, $empleado->numero_marcar_indice, $this->nombre($empleado), (bool) $empleado->activo,
        ])->values()->all();

        return substr(hash('sha256', json_encode($lista, JSON_THROW_ON_ERROR)), 0, 16);
    }

    private function nombre(AsistenciaEmpleado $empleado): string
    {
        return mb_substr($empleado->nombreCorto(), 0, self::LARGO_NOMBRE);
    }

    /** Una clave por uso, derivada de APP_KEY: el índice y la sal no comparten clave. */
    private static function clave(string $uso, ?string $claveApp = null): string
    {
        return hash_hmac('sha256', 'asistencia.numero_marcar.'.$uso, $claveApp ?? Crypt::getKey(), true);
    }
}
