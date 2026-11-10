<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MySQL no acepta nombres de índice de más de 64 caracteres; SQLite sí. Un índice con el
 * nombre automático largo pasa las pruebas locales y tumba la migración en producción y en
 * la CI de MySQL (pasó con pedido_sala_envio_lineas el 09/11/2026). Laravel arma el mismo
 * nombre en los dos motores, así que se puede revisar acá.
 */
class NombresIndicesMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_ningun_indice_pasa_de_64_caracteres(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('La revisión lee sqlite_master.');
        }

        $largos = DB::table('sqlite_master')
            ->where('type', 'index')
            ->whereRaw('length(name) > 64')
            ->pluck('name')
            ->all();

        $this->assertSame([], $largos, 'Estos índices no entran en MySQL (máximo 64 caracteres): ponerles un nombre corto.');
    }
}
