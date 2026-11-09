<?php

namespace Tests\Unit\Support;

use App\Models\Dte;
use App\Support\VigenciaFiscalDte;
use Tests\TestCase;

/**
 * Qué sale solo de un archivo del portal (quedan, formato de NC) al generarlo (#12): el CCF
 * invalidado y la NC que ya no está aceptada de verdad por Hacienda.
 */
class VigenciaFiscalDteFueraDelArchivoTest extends TestCase
{
    private function dte(string $tipo, string $estado, array $extra = []): Dte
    {
        return (new Dte)->forceFill($extra + [
            'tipo_dte' => $tipo,
            'estado' => $estado,
            'sello_recepcion' => '2026SELLOREAL0001',
            'fecha_procesamiento_mh' => '2026-09-10 08:00:00',
        ]);
    }

    public function test_el_ccf_solo_sale_si_se_invalido(): void
    {
        $this->assertNull(VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('03', 'aceptado')));
        $this->assertSame('fue invalidado ante Hacienda', VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('03', 'invalidado')));
        $this->assertSame('fue invalidado ante Hacienda',
            VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('03', 'aceptado', ['sello_invalidacion' => 'INVALIDACION'])));
    }

    public function test_la_nc_sale_si_ya_no_esta_aceptada_de_verdad(): void
    {
        $this->assertNull(VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('05', 'aceptado')));
        $this->assertSame('fue invalidada ante Hacienda', VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('05', 'invalidado')));
        $this->assertSame('Hacienda la rechazó', VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('05', 'rechazado')));
        $this->assertSame('no tiene sello de recepción real de Hacienda',
            VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('05', 'aceptado', ['sello_recepcion' => 'MOCK-prueba'])));
        $this->assertSame('todavía no está aceptada por Hacienda (estado: Enviado)',
            VigenciaFiscalDte::motivoFueraDelArchivo($this->dte('05', 'enviado')));
    }
}
