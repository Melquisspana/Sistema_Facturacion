<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\Buzon\IdentidadCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El backfill de identidad de las filas históricas.
 *
 * Es explícito y aparte de la migración a propósito: rellenar `identidad` durante un
 * despliegue sería reinterpretar datos históricos sin que nadie pueda mirar antes qué va
 * a pasar. Acá se comprueba que sin `--aplicar` no toca nada, que solo escribe la columna
 * nueva, y que las filas sin dato histórico se dejan como están en vez de inventarles una
 * identidad.
 */
class ComprasBackfillIdentidadTest extends TestCase
{
    use RefreshDatabase;

    private function historica(?string $gmailMessageId, array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => $gmailMessageId,
            'emisor_nombre' => 'PROVEEDOR VIEJO '.$n,
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-OLD-'.$n,
            'estado' => 'pendiente',
            'total' => 100,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => Carbon::parse('2026-07-10'),
        ]);
    }

    public function test_sin_aplicar_no_escribe_nada(): void
    {
        $doc = $this->historica('1803');

        $this->artisan('compras:backfill-identidad')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertNull($doc->refresh()->identidad);
    }

    public function test_con_aplicar_marca_las_filas_historicas_como_legado(): void
    {
        $doc = $this->historica('1803');

        $this->artisan('compras:backfill-identidad', ['--aplicar' => true])->assertSuccessful();

        $doc->refresh();
        $this->assertSame(IdentidadCorreo::PREFIJO_LEGADO.'1803', $doc->identidad);
        // No se inventa un Message-ID que esas filas nunca tuvieron.
        $this->assertNull($doc->message_id);
        // Y el dato histórico queda intacto.
        $this->assertSame('1803', $doc->gmail_message_id);
    }

    public function test_no_toca_las_filas_que_ya_tienen_identidad(): void
    {
        $nueva = $this->historica('9001', ['identidad' => 'mid:ya-tengo@proveedor.example']);

        $this->artisan('compras:backfill-identidad', ['--aplicar' => true])
            ->expectsOutputToContain('No hay filas sin identidad')
            ->assertSuccessful();

        $this->assertSame('mid:ya-tengo@proveedor.example', $nueva->refresh()->identidad);
    }

    /** Sin dato histórico no hay nada determinista que escribir: se deja en NULL. */
    public function test_una_fila_sin_gmail_message_id_queda_sin_identidad(): void
    {
        $sinDato = $this->historica(null);

        $this->artisan('compras:backfill-identidad', ['--aplicar' => true])
            ->expectsOutputToContain('quedaron sin identidad')
            ->assertSuccessful();

        $this->assertNull($sinDato->refresh()->identidad);
    }

    /** Correrlo dos veces no cambia nada la segunda vez. */
    public function test_es_idempotente(): void
    {
        $doc = $this->historica('1803');

        $this->artisan('compras:backfill-identidad', ['--aplicar' => true])->assertSuccessful();
        $primera = $doc->refresh()->identidad;

        $this->artisan('compras:backfill-identidad', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame($primera, $doc->refresh()->identidad);
        $this->assertSame(1, DocumentoRecibido::count());
    }
}
