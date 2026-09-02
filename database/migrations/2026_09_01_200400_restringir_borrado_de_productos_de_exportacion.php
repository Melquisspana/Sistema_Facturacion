<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quita el `ON DELETE CASCADE` de `exportacion_cliente_productos`.
 *
 * Era el único riesgo de pérdida de datos VIGENTE del módulo: borrar un producto
 * del catálogo —desde la aplicación, desde tinker o desde un cliente SQL— se
 * llevaba por delante, en silencio, los precios negociados con cada importador.
 * Esos precios no se pueden reconstruir desde el precio base: en la base real solo
 * 9 de 58 coinciden con él.
 *
 * El controlador ya lo bloquea, pero un candado que vive solo en la aplicación no
 * protege de un DELETE directo. A partir de acá lo impide la base. La operación
 * normal para retirar un producto sigue siendo ARCHIVARLO (`activo = false`).
 *
 * ═══ CÓMO SE CAMBIA, Y POR QUÉ ASÍ ═══
 *
 * En MySQL se toca EXCLUSIVAMENTE el constraint, con dos sentencias:
 *
 *     ALTER TABLE … DROP FOREIGN KEY <nombre>;
 *     ALTER TABLE … ADD CONSTRAINT <mismo nombre> FOREIGN KEY … ON DELETE RESTRICT;
 *
 * No hay tabla temporal, ni copia, ni `DROP TABLE`, ni `CREATE TABLE … SELECT`.
 * Recrear la tabla para cambiar una regla de borrado es la operación más cara de
 * deshacer y la que más cosas puede perder por el camino —defaults, colación,
 * `AUTO_INCREMENT`, el orden de las columnas, un índice que nadie recordaba—, y
 * nada de eso hace falta para cambiar una palabra del constraint.
 *
 * El NOMBRE del constraint se conserva. Eso hace que `down()` pueda devolver
 * exactamente la misma FK que había, y que el índice que MySQL mantiene con ese
 * mismo nombre se reutilice en vez de duplicarse.
 *
 * SQLite (el motor de las pruebas) no sabe alterar constraints: la única forma es
 * recrear la tabla, y eso lo hace el propio constructor de esquema de Laravel. Es
 * la estrategia específica de ese motor y no se aplica a MySQL.
 *
 * ═══ VERIFICACIÓN PREVIA ═══
 *
 * Antes de tocar nada se comprueba que la estructura es EXACTAMENTE la esperada:
 * tablas, columnas, la FK que se va a cambiar (existe, es una sola, apunta a
 * donde debe), los índices que tienen que sobrevivir y que no hay filas huérfanas.
 * Si algo no cuadra, la migración ABORTA sin haber ejecutado ningún ALTER.
 */
return new class extends Migration
{
    private const TABLA = 'exportacion_cliente_productos';

    private const COLUMNA = 'exportacion_producto_id';

    private const TABLA_REFERIDA = 'exportacion_productos';

    private const COLUMNA_REFERIDA = 'id';

    /** Columnas que deben seguir existiendo, con su tipo intacto, después del cambio. */
    private const COLUMNAS_ESPERADAS = [
        'id', 'exportacion_cliente_id', 'exportacion_producto_id',
        'precio_caja', 'activo', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        $this->cambiarReglaDeBorrado('RESTRICT');
    }

    public function down(): void
    {
        $this->cambiarReglaDeBorrado('CASCADE');
    }

    /**
     * Cambia SOLO la regla de borrado de la FK, conservando nombre, columnas y
     * regla de actualización.
     */
    private function cambiarReglaDeBorrado(string $reglaBorrado): void
    {
        // MODO SIMULACIÓN (`migrate --pretend`). Laravel intercepta TODA consulta y
        // devuelve vacío, así que leer el catálogo del motor ahí no informa de nada:
        // `Schema::hasTable()` diría que no existe ni la tabla. Se salta la
        // verificación —que no tendría datos que verificar— y se emiten las dos
        // sentencias, que es justamente lo que el ensayo tiene que enseñar.
        if (DB::pretending()) {
            $this->simular($reglaBorrado);

            return;
        }

        $fk = $this->verificarYObtenerFk();
        $indicesAntes = $this->huellaDeIndices();
        $filasAntes = DB::table(self::TABLA)->count();

        if ($this->esSqlite()) {
            // SQLite no altera constraints: la tabla se recrea. Lo hace el propio
            // constructor de esquema de Laravel, que conserva datos e índices.
            $this->cambiarEnSqlite($reglaBorrado);
        } else {
            $this->cambiarEnMysql($fk, $reglaBorrado);
        }

        $this->comprobarQueNoSePerdioNada($indicesAntes, $filasAntes, $reglaBorrado);
    }

    /**
     * Sentencias que se ejecutarían, con el nombre CONVENCIONAL del constraint (el
     * real se lee del motor, y en simulación no hay motor que responda). Si el
     * nombre real difiere, la ejecución de verdad lo detecta y usa el correcto.
     */
    private function simular(string $reglaBorrado): void
    {
        if ($this->esSqlite()) {
            $this->cambiarEnSqlite($reglaBorrado);

            return;
        }

        $this->cambiarEnMysql([
            'nombre' => self::TABLA.'_'.self::COLUMNA.'_foreign',
            'delete_rule' => $reglaBorrado,
            'update_rule' => 'NO ACTION',
        ], $reglaBorrado);
    }

    /** Dos ALTER y nada más. Ni copia, ni tabla temporal, ni DROP TABLE. */
    private function cambiarEnMysql(array $fk, string $reglaBorrado): void
    {
        $tabla = self::TABLA;
        $nombre = $fk['nombre'];
        $columna = self::COLUMNA;
        $referida = self::TABLA_REFERIDA;
        $columnaReferida = self::COLUMNA_REFERIDA;
        // Se conserva la regla de actualización que ya tenía; solo cambia el borrado.
        $reglaUpdate = $fk['update_rule'] === 'NO ACTION' ? 'NO ACTION' : $fk['update_rule'];

        DB::statement("ALTER TABLE `{$tabla}` DROP FOREIGN KEY `{$nombre}`");

        DB::statement(
            "ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` ".
            "FOREIGN KEY (`{$columna}`) REFERENCES `{$referida}` (`{$columnaReferida}`) ".
            "ON DELETE {$reglaBorrado} ON UPDATE {$reglaUpdate}"
        );
    }

    private function cambiarEnSqlite(string $reglaBorrado): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            $table->dropForeign([self::COLUMNA]);
        });

        Schema::table(self::TABLA, function (Blueprint $table) use ($reglaBorrado) {
            $fk = $table->foreign(self::COLUMNA)
                ->references(self::COLUMNA_REFERIDA)
                ->on(self::TABLA_REFERIDA);

            $reglaBorrado === 'CASCADE' ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        });
    }

    // ------------------------------------------------------------- verificación

    /**
     * Comprueba que se puede tocar el constraint sin destruir nada y devuelve la FK
     * que se va a cambiar.
     *
     * @return array{nombre: string, delete_rule: string, update_rule: string}
     *
     * @throws RuntimeException
     */
    private function verificarYObtenerFk(): array
    {
        foreach ([self::TABLA, self::TABLA_REFERIDA] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new RuntimeException(
                    "No se puede cambiar la clave foránea: falta la tabla «{$tabla}». ".
                    'La estructura no es la esperada; revisá el estado de las migraciones antes de continuar.'
                );
            }
        }

        $faltantes = array_values(array_filter(
            self::COLUMNAS_ESPERADAS,
            fn (string $c) => ! Schema::hasColumn(self::TABLA, $c)
        ));

        if ($faltantes !== []) {
            throw new RuntimeException(
                'No se puede cambiar la clave foránea: a «'.self::TABLA.'» le faltan columnas ('.
                implode(', ', $faltantes).'). La estructura no es la esperada.'
            );
        }

        $this->verificarSinHuerfanas();

        $fks = $this->fksDeLaColumna();

        if ($fks === []) {
            throw new RuntimeException(
                'No se puede cambiar la clave foránea: «'.self::TABLA.'.'.self::COLUMNA.'» no tiene ninguna. '.
                'Puede que ya se haya cambiado a mano; revisá el esquema antes de continuar.'
            );
        }

        if (count($fks) > 1) {
            $nombres = implode(', ', array_column($fks, 'nombre'));
            throw new RuntimeException(
                'No se puede cambiar la clave foránea: «'.self::TABLA.'.'.self::COLUMNA.'» tiene '.
                count($fks)." claves foráneas ({$nombres}) y se esperaba una. No se tocó nada."
            );
        }

        $fk = $fks[0];

        if ($fk['tabla_referida'] !== self::TABLA_REFERIDA || $fk['columna_referida'] !== self::COLUMNA_REFERIDA) {
            throw new RuntimeException(
                'No se puede cambiar la clave foránea: apunta a «'.$fk['tabla_referida'].'.'.$fk['columna_referida'].
                '» y se esperaba «'.self::TABLA_REFERIDA.'.'.self::COLUMNA_REFERIDA.'». No se tocó nada.'
            );
        }

        return $fk;
    }

    /**
     * Filas apuntando a un producto inexistente. Con huérfanas, añadir una FK
     * RESTRICT falla a mitad del ALTER en MySQL (error 1452) con un mensaje poco
     * legible; abortar antes deja la base como estaba y dice qué hay que arreglar.
     */
    private function verificarSinHuerfanas(): void
    {
        $huerfanas = DB::table(self::TABLA.' as ecp')
            ->leftJoin(self::TABLA_REFERIDA.' as p', 'p.'.self::COLUMNA_REFERIDA, '=', 'ecp.'.self::COLUMNA)
            ->whereNull('p.'.self::COLUMNA_REFERIDA)
            ->count();

        if ($huerfanas === 0) {
            return;
        }

        $ids = DB::table(self::TABLA.' as ecp')
            ->leftJoin(self::TABLA_REFERIDA.' as p', 'p.'.self::COLUMNA_REFERIDA, '=', 'ecp.'.self::COLUMNA)
            ->whereNull('p.'.self::COLUMNA_REFERIDA)
            ->limit(10)
            ->pluck('ecp.id')
            ->implode(', ');

        throw new RuntimeException(
            "No se puede cambiar la clave foránea: hay {$huerfanas} asignación(es) de precio apuntando a un ".
            "producto de exportación que ya no existe (ids: {$ids}). ".
            'Resolvelas —reasignando el producto correcto o borrando la fila huérfana— y volvé a migrar. '.
            'No se tocó nada.'
        );
    }

    /**
     * Claves foráneas declaradas sobre la columna, leídas del catálogo del motor.
     *
     * @return list<array{nombre: string, tabla_referida: string, columna_referida: string, delete_rule: string, update_rule: string}>
     */
    private function fksDeLaColumna(): array
    {
        if ($this->esSqlite()) {
            $filas = DB::select('PRAGMA foreign_key_list('.self::TABLA.')');

            return array_values(array_map(
                fn ($f) => [
                    // SQLite no nombra sus FK; el nombre no se usa en esa rama.
                    'nombre' => self::TABLA.'_'.self::COLUMNA.'_foreign',
                    'tabla_referida' => $f->table,
                    'columna_referida' => $f->to ?? self::COLUMNA_REFERIDA,
                    'delete_rule' => strtoupper((string) $f->on_delete),
                    'update_rule' => strtoupper((string) $f->on_update),
                ],
                array_filter($filas, fn ($f) => $f->from === self::COLUMNA)
            ));
        }

        $filas = DB::select(
            'SELECT rc.CONSTRAINT_NAME AS nombre, kcu.REFERENCED_TABLE_NAME AS tabla_referida,
                    kcu.REFERENCED_COLUMN_NAME AS columna_referida, rc.DELETE_RULE AS delete_rule,
                    rc.UPDATE_RULE AS update_rule
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
               JOIN information_schema.KEY_COLUMN_USAGE kcu
                 ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_NAME = rc.TABLE_NAME
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.TABLE_NAME = ?
                AND kcu.COLUMN_NAME = ?',
            [self::TABLA, self::COLUMNA]
        );

        return array_values(array_map(fn ($f) => (array) $f, $filas));
    }

    /**
     * Huella de los índices: nombre ⇒ columnas y unicidad. Se compara antes y
     * después para demostrar que ninguno se perdió por el camino.
     *
     * @return array<string, string>
     */
    private function huellaDeIndices(): array
    {
        if ($this->esSqlite()) {
            $huella = [];
            foreach (DB::select('PRAGMA index_list('.self::TABLA.')') as $indice) {
                $cols = array_map(
                    fn ($c) => $c->name,
                    DB::select('PRAGMA index_info('.$indice->name.')')
                );
                $huella[$indice->name] = ($indice->unique ? 'unique:' : 'index:').implode(',', $cols);
            }
            ksort($huella);

            return $huella;
        }

        $filas = DB::select(
            'SELECT INDEX_NAME AS nombre, COLUMN_NAME AS columna, NON_UNIQUE AS no_unico
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [self::TABLA]
        );

        $huella = [];
        foreach ($filas as $fila) {
            $prefijo = $fila->no_unico ? 'index:' : 'unique:';
            $huella[$fila->nombre] = isset($huella[$fila->nombre])
                ? $huella[$fila->nombre].','.$fila->columna
                : $prefijo.$fila->columna;
        }
        ksort($huella);

        return $huella;
    }

    /**
     * Después del ALTER: mismas filas, mismos índices y la regla de borrado nueva.
     * Si algo no cuadra se lanza en vez de dejar la migración por buena — es la
     * diferencia entre «se ejecutó» y «funcionó».
     *
     * @param  array<string, string>  $indicesAntes
     */
    private function comprobarQueNoSePerdioNada(array $indicesAntes, int $filasAntes, string $reglaEsperada): void
    {
        $filasDespues = DB::table(self::TABLA)->count();

        if ($filasDespues !== $filasAntes) {
            throw new RuntimeException(
                "El cambio de clave foránea alteró el número de filas: había {$filasAntes} y quedaron {$filasDespues}."
            );
        }

        $indicesDespues = $this->huellaDeIndices();
        $perdidos = array_diff_key($indicesAntes, $indicesDespues);

        if ($perdidos !== []) {
            throw new RuntimeException(
                'El cambio de clave foránea perdió índices: '.implode(', ', array_keys($perdidos)).'.'
            );
        }

        $fks = $this->fksDeLaColumna();
        $regla = $fks[0]['delete_rule'] ?? 'sin FK';

        if (count($fks) !== 1 || $regla !== $reglaEsperada) {
            throw new RuntimeException(
                'Tras el cambio, «'.self::TABLA.'.'.self::COLUMNA."» debería tener una FK con ON DELETE {$reglaEsperada} ".
                'y tiene '.count($fks)." con regla «{$regla}»."
            );
        }
    }

    private function esSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
