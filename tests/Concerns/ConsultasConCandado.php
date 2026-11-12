<?php

namespace Tests\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Deja ver en SQLite qué lecturas van con candado.
 *
 * SQLite no escribe `for update` ni `lock in share mode` (todo va en serie), así que acá
 * se dejan como comentario, que la base ignora; MySQL ya los escribe solo. Es la forma de
 * comprobar en la suite que una lectura que decide va con candado. La carrera completa
 * solo se ve en MySQL con dos conexiones.
 */
trait ConsultasConCandado
{
    /**
     * Las consultas que corren de acá en adelante, en minúsculas y con su candado escrito.
     *
     * @return \ArrayObject<int, string>
     */
    protected function consultasConCandado(): \ArrayObject
    {
        $conexion = DB::connection();

        if ($conexion->getDriverName() === 'sqlite') {
            $conexion->setQueryGrammar(new class($conexion) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    if (is_string($value)) {
                        return $value;
                    }

                    return $value ? '/* for update */' : '/* lock in share mode */';
                }
            });
        }

        $sql = new \ArrayObject;
        DB::listen(fn ($q) => $sql->append(mb_strtolower($q->sql)));

        return $sql;
    }

    /**
     * Las lecturas de una tabla, en el orden en que corrieron.
     *
     * @param  \ArrayObject<int, string>  $sql
     * @return array<int, string>
     */
    protected function lecturasDe(\ArrayObject $sql, string $tabla): array
    {
        return array_values(array_filter($sql->getArrayCopy(),
            fn (string $q) => str_starts_with($q, 'select') && $this->nombra($q, $tabla)));
    }

    /**
     * ¿La consulta nombra esa tabla o esa columna? MySQL cita los nombres con acentos
     * graves y SQLite con comillas dobles: buscar una sola forma hace que la prueba valga
     * en una base y no en la otra.
     */
    protected function nombra(string $sql, string $nombre): bool
    {
        return preg_match('/[`"]'.preg_quote($nombre, '/').'[`"]/', $sql) === 1;
    }

    protected function llevaCandado(string $sql): bool
    {
        return preg_match('/for update|lock in share mode/', $sql) === 1;
    }

    /**
     * Las consultas que corren DENTRO de una transacción que abra el código probado, en
     * orden y con sus valores. Sirve para comprobar qué se lee antes de tomar un candado:
     * en MySQL la primera lectura común fija la foto de toda la transacción.
     *
     * @return \ArrayObject<int, array{sql: string, valores: array<int, mixed>}>
     */
    protected function consultasEnTransaccion(): \ArrayObject
    {
        $this->consultasConCandado();
        // La suite ya corre dentro de una transacción: lo que interesa es lo que se abre encima.
        $base = DB::transactionLevel();
        $consultas = new \ArrayObject;

        DB::listen(function ($q) use ($consultas, $base) {
            if (DB::transactionLevel() > $base) {
                $consultas->append(['sql' => mb_strtolower($q->sql), 'valores' => $q->bindings]);
            }
        });

        return $consultas;
    }

    /** @param  \ArrayObject<int, string>  $sql */
    protected function assertTodasLeidasConCandado(\ArrayObject $sql, string $tabla): void
    {
        $lecturas = $this->lecturasDe($sql, $tabla);

        $this->assertNotEmpty($lecturas, 'No hubo ninguna lectura de '.$tabla.'.');

        foreach ($lecturas as $lectura) {
            $this->assertTrue($this->llevaCandado($lectura),
                'Una lectura de '.$tabla.' que decide un saldo va sin candado: en MySQL devuelve la foto vieja. '.$lectura);
        }
    }
}
