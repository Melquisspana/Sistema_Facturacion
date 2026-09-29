<?php

namespace App\Services\Gastos;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Guarda adjuntos privados. Lo usan por igual «Registrar gasto», «Registrar pago»
 * y las pantallas de adjuntar después, para que las garantías no dependan de por
 * dónde entró el archivo:
 *
 *  - Nombre y ruta los genera el SERVIDOR. El nombre del cliente se conserva solo
 *    como etiqueta, recortado, y jamás se usa para construir una ruta.
 *  - Se escribe ANTES del commit y se registra la ruta para poder retirarla si la
 *    transacción se deshace.
 *  - El MIME que se guarda es el que el servidor dedujo del CONTENIDO, no el que
 *    declaró el navegador.
 *
 * Un adjunto cuelga del gasto (documento de cobro) o del pago (comprobante),
 * nunca de los dos: son respaldos distintos y cada uno hereda su propio candado.
 */
final class AlmacenAdjuntos
{
    public const DIRECTORIO = 'gastos';

    /**
     * @param  array<int, UploadedFile>  $archivos
     * @param  array<int, string>  $rutas  se rellena con lo escrito, para limpiar en rollback
     * @return array<int, int> ids de los adjuntos creados
     */
    public function guardar(array $archivos, ?int $gastoId, ?int $pagoId, int $usuarioId, array &$rutas, string $campo): array
    {
        if (($gastoId === null) === ($pagoId === null)) {
            // Ni huérfano ni con dos dueños: sin un dueño único no hay candado que
            // aplicar cuando alguien pida el archivo.
            throw new \InvalidArgumentException('Un adjunto pertenece a un gasto o a un pago, nunca a ambos ni a ninguno.');
        }

        $ids = [];

        foreach ($archivos as $archivo) {
            $nombreDisco = Uuid::uuid4()->toString().'.'.($archivo->guessExtension() ?: 'bin');
            $ruta = self::DIRECTORIO.'/'.$nombreDisco;
            $rutas[] = $ruta;

            if (! Storage::disk('local')->putFileAs(self::DIRECTORIO, $archivo, $nombreDisco)) {
                throw ValidationException::withMessages([
                    $campo => 'No se pudo guardar el archivo. No se registró nada.',
                ]);
            }

            $ids[] = DB::table('gastos_adjuntos')->insertGetId([
                'gasto_id' => $gastoId,
                'pago_id' => $pagoId,
                'ruta' => $ruta,
                'nombre' => mb_substr(basename($archivo->getClientOriginalName()), 0, 240),
                'mime' => $archivo->getMimeType(),
                'bytes' => $archivo->getSize(),
                'sha256' => hash_file('sha256', $archivo->getRealPath()),
                'registrado_por' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** Retira del disco lo escrito en un intento que terminó deshaciéndose. */
    public function limpiar(array $rutas): void
    {
        foreach ($rutas as $ruta) {
            Storage::disk('local')->delete($ruta);
        }
    }

    /**
     * Cuántos adjuntos tiene ya ese dueño. Sirve para que «adjuntar después» respete
     * el mismo tope que el formulario original en vez de acumular sin límite.
     */
    public function cuantos(?int $gastoId, ?int $pagoId): int
    {
        return DB::table('gastos_adjuntos')
            ->when($gastoId !== null, fn ($q) => $q->where('gasto_id', $gastoId))
            ->when($pagoId !== null, fn ($q) => $q->where('pago_id', $pagoId))
            ->count();
    }
}
