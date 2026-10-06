<?php

namespace App\Services\Dte\Serializadores\NotaCreditoV4;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;

/**
 * Representación de importes de NC v4 separada de su estructura. El ajuste de
 * CCF sigue la Normativa de Cumplimiento 2.0, Anexo IV, pp.116 y 118-120.
 * Las invariantes las exige el serializador, cualquiera que sea la estrategia.
 * La aceptación aún debe comprobarse en el ambiente de pruebas del MH.
 */
interface CalculoImportesNcV4
{
    /** @return array<int, array<string, mixed>> Importes y tributos por línea, en el orden del DTO. */
    public function lineas(DteSalidaData $d): array;

    /** @return array<string, mixed> Importes y tributos del resumen. */
    public function resumen(DteSalidaData $d, array $lineas): array;
}
