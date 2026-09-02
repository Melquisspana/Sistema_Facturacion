<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Genera el Excel de Lista de Empaque.
 *
 * YA NO DEPENDE DE NINGUNA PLANTILLA. La hoja se construye por completo con
 * {@see ListaEmpaqueExcelBuilder}: mismo layout, mismas columnas y mismas
 * fórmulas que el formato histórico, pero reproducible en cualquier instalación
 * y cubierto por pruebas. El archivo `lista_empaque.xlsx` del que dependía esto
 * no estaba en el disco, así que la descarga llevaba meses lanzando una excepción
 * que nadie veía porque nunca se creó una lista.
 *
 * `rutaPlantilla()` y `hojaLista()` siguen existiendo porque las usa el
 * IMPORTADOR de catálogo, que sí necesita leer un archivo real (subido por el
 * usuario o guardado en el servidor). Generar y leer dejaron de compartir
 * dependencia.
 *
 * FALLOS VISIBLES. Si el archivo no se puede escribir —disco lleno, permisos,
 * temporal no disponible— se lanza {@see RuntimeException} con el motivo. Nunca
 * se devuelve una descarga vacía: un .xlsx de 0 bytes se abre como «archivo
 * dañado» y manda a buscar el problema al lado equivocado.
 */
class ListaEmpaqueExcelService
{
    public function __construct(private readonly ListaEmpaqueExcelBuilder $builder) {}

    /** Genera el .xlsx en un archivo temporal y devuelve su ruta. */
    public function generar(Exportacion $exportacion): string
    {
        $exportacion->loadMissing('items');

        if ($exportacion->items->isEmpty()) {
            throw new RuntimeException('La exportación no tiene productos.');
        }

        $spreadsheet = $this->builder->construir($exportacion);

        $ruta = $this->rutaTemporal();

        try {
            (new Xlsx($spreadsheet))->save($ruta);
        } catch (\Throwable $e) {
            @unlink($ruta);

            throw new RuntimeException(
                'No se pudo escribir el archivo Excel de la lista de empaque: '.$e->getMessage(),
                previous: $e
            );
        } finally {
            // Libera la memoria del libro pase lo que pase: una lista larga con
            // estilos por celda no es barata y esto corre dentro de una petición web.
            $spreadsheet->disconnectWorksheets();
        }

        $this->verificarArchivoUtil($ruta);

        return $ruta;
    }

    public function nombreArchivo(Exportacion $exportacion): string
    {
        $fecha = $exportacion->fecha?->format('Y-m-d') ?? now()->format('Y-m-d');

        return sprintf('lista-empaque-%d-%s.xlsx', $exportacion->id, $fecha);
    }

    /**
     * Ruta de la plantilla para el IMPORTADOR de catálogo. La generación ya no la
     * usa; se conserva porque importar sí necesita un archivo con datos reales.
     */
    public function rutaPlantilla(): string
    {
        $ruta = storage_path('app/'.config('exportaciones.plantilla'));

        if (! is_file($ruta)) {
            throw new RuntimeException(
                'No hay un archivo de catálogo guardado en el servidor ('.$ruta.'). '
                .'Subí el Excel desde el formulario de importación.'
            );
        }

        return $ruta;
    }

    /** La hoja de la lista puede llamarse "Lista " (con espacio final): se busca saneando. */
    public function hojaLista(Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (strcasecmp(trim($sheet->getTitle()), 'Lista') === 0) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheet(0);
    }

    private function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'lista_empaque_');

        if ($base === false) {
            throw new RuntimeException(
                'No se pudo crear un archivo temporal para el Excel. Revisá el espacio y los permisos del directorio temporal del servidor.'
            );
        }

        // tempnam() crea el archivo sin extensión; el writer necesita el .xlsx.
        // El original queda vacío y se borra para no dejar basura en el temporal.
        @unlink($base);

        return $base.'.xlsx';
    }

    /**
     * Un archivo inexistente o de tamaño cero es exactamente lo que produce una
     * «descarga vacía»: el navegador baja algo, Excel dice que está dañado y nadie
     * sabe si falló el servidor o el correo. Se detecta acá y se convierte en un
     * mensaje.
     */
    private function verificarArchivoUtil(string $ruta): void
    {
        clearstatcache(true, $ruta);

        if (! is_file($ruta) || filesize($ruta) === 0) {
            @unlink($ruta);

            throw new RuntimeException(
                'El Excel de la lista de empaque se generó vacío y no se entregó. Es un fallo del almacenamiento del servidor, no de la lista: revisá espacio y permisos e intentá de nuevo.'
            );
        }
    }
}
