<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Models\Cliente;
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EL CASO REAL: el archivo de pagos que Calleja mandó el 07/09/2026 aplicado al
 * seguimiento de cobros.
 *
 * Es la prueba que define el módulo, y corre sobre el ARCHIVO REAL
 * (`tests/Fixtures/Ppq/pagos-000123-20260907.txt`) y sobre los documentos reales de la
 * solicitud histórica: los 45 CCF y las 13 NC que el cliente informó, más el ajuste QD.
 *
 * Lo que comprueba no es que «funcione», sino las cuatro cosas que, si se rompen, el
 * sistema afirma algo falso sobre el dinero:
 *
 *  1. los totales del archivo son los que el cliente informó;
 *  2. las DOS facturas que el archivo no menciona siguen SIN pago, sin que nadie las
 *     declare pagadas ni les borre nada;
 *  3. cargar el archivo dos veces no cobra nada dos veces;
 *  4. el ajuste QD queda pendiente de nota de crédito, sin repartirse entre facturas y sin
 *     inventar su desglose fiscal.
 *
 * No emite documentos, no toca la red y no manda un solo correo.
 */
class CobrosCasoRealTest extends TestCase
{
    use RefreshDatabase;

    /** Las dos facturas del Excel histórico que el TXT NO menciona, con su motivo real. */
    private const AUSENTES = [
        'DTE-03-M001P002-000000000000119' => ['monto' => '77.74', 'motivo' => 'FALTA NOTA DE CREDITO'],
        'DTE-03-M001P001-000000000001186' => ['monto' => '141.25', 'motivo' => 'NO APARECE EN REPORTERIA'],
    ];

    private function archivoReal(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Ppq/pagos-000123-20260907.txt'));
    }

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    /**
     * Da de alta en el seguimiento los documentos que el archivo menciona, más las dos
     * facturas ausentes. Se crean como INCORPORADOS a mano, que es exactamente la fuente
     * alterna que el módulo debe seguir admitiendo: así la prueba no depende de emitir 60
     * documentos fiscales.
     *
     * @return array<string, CobroDocumento>
     */
    private function sembrarDesdeElArchivo(Cliente $cliente): array
    {
        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());
        $documentos = [];

        foreach ($filas as $fila) {
            if (! in_array($fila['tipo'], ['CF', 'NC'], true)) {
                continue;
            }

            // El importe del documento es el que el archivo informa: en el caso real
            // coincide, y así la prueba mide el cruce, no la aritmética del sembrado.
            $magnitud = Dinero::comparar($fila['valor'], '0') < 0
                ? Dinero::redondear(Dinero::restar('0', $fila['valor']))
                : Dinero::redondear($fila['valor']);

            $documentos[$fila['numeroNorm']] = CobroDocumento::create([
                'cliente_id' => $cliente->id,
                'origen' => OrigenCobroDocumento::Externo->value,
                'tipo_dte' => $fila['tipo'] === 'NC' ? '05' : '03',
                'numero_control' => (string) $fila['numero'],
                'fecha_emision' => $fila['fecha'],
                'monto' => $magnitud,
            ]);
        }

        foreach (self::AUSENTES as $control => $datos) {
            $documentos[$control] = CobroDocumento::create([
                'cliente_id' => $cliente->id,
                'origen' => OrigenCobroDocumento::Externo->value,
                'tipo_dte' => '03',
                'numero_control' => $control,
                'fecha_emision' => '2026-08-28',
                'monto' => $datos['monto'],
                'observaciones' => $datos['motivo'],
            ]);
        }

        return $documentos;
    }

    private function archivo(): ArchivoConciliacion
    {
        return ArchivoConciliacion::desdeContenido($this->archivoReal(), 'pagos-000123-20260907.txt');
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · el archivo real aplicado entero: 45 CF, 13 NC, el QD, y las dos facturas
     * ausentes intactas.
     */
    public function test_dorada_el_archivo_real_se_aplica_y_cuadra_con_lo_informado(): void
    {
        $cliente = $this->cliente();
        $this->sembrarDesdeElArchivo($cliente);

        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());
        $informe = app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $this->archivo());

        // 1 · Los totales del archivo son los que el cliente informó.
        $t = $informe['totales'];
        $this->assertSame(45, $t['cantidad_cf']);
        $this->assertSame(13, $t['cantidad_nc']);
        $this->assertSame(1, $t['cantidad_qd']);
        $this->assertSame('6826.42', $t['total_cf']);
        $this->assertSame('-125.90', $t['total_nc']);
        $this->assertSame('-107.21', $t['total_qd']);
        $this->assertSame('6593.31', $t['neto_archivo']);

        // 2 · Los 58 documentos del archivo quedaron aplicados; ninguno sin identificar.
        $this->assertCount(58, $informe['aplicados']);
        $this->assertSame([], $informe['no_identificados']);
        $this->assertSame([], $informe['invalidas']);

        // 3 · El proveedor del archivo es el esperado.
        $this->assertTrue($informe['proveedor']['coincide']);
        $this->assertSame([], $informe['proveedor']['ajenos']);

        // 4 · Todo lo mencionado quedó pagado (importe informado == importe del documento).
        $pagados = CobroDocumento::where('pago_estado', EstadoPagoCobro::Pagado->value)->count();
        $this->assertSame(58, $pagados);

        // 5 · Las DOS ausentes siguen pendientes, con su observación intacta.
        foreach (self::AUSENTES as $control => $datos) {
            $documento = CobroDocumento::where('numero_control', $control)->firstOrFail();

            $this->assertSame(EstadoPagoCobro::Pendiente, $documento->pago_estado, "{$control} no puede figurar pagada.");
            $this->assertSame(0, Dinero::comparar('0', $documento->monto_pagado));
            $this->assertNull($documento->fecha_pago);
            $this->assertSame($datos['motivo'], $documento->observaciones, 'La observación no se pierde al conciliar.');
            $this->assertSame(0, Dinero::comparar($datos['monto'], $documento->saldo()));
        }
    }

    /**
     * DORADA · cargar dos veces el mismo archivo no cobra nada dos veces.
     *
     * Es la garantía de la que cuelga todo lo demás: los acumulados se recalculan desde los
     * eventos y el único de evidencia impide que la misma línea entre otra vez.
     */
    public function test_dorada_recargar_el_mismo_archivo_no_duplica_ningun_pago(): void
    {
        $cliente = $this->cliente();
        $this->sembrarDesdeElArchivo($cliente);

        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());
        $aplicador = app(AplicadorPagosTxt::class);

        $primero = $aplicador->aplicar($cliente, $filas, $this->archivo());
        $cobradoPrimero = CobroDocumento::sum('monto_pagado');
        $eventosPrimero = CobroEvento::count();

        $segundo = $aplicador->aplicar($cliente, $filas, $this->archivo());

        $this->assertSame($cobradoPrimero, CobroDocumento::sum('monto_pagado'), 'El total cobrado no puede cambiar.');
        $this->assertSame($eventosPrimero, CobroEvento::count(), 'No se crean eventos nuevos.');
        $this->assertSame(1, CobroAjuste::count(), 'El ajuste QD tampoco se duplica.');

        // La segunda corrida no aplicó nada nuevo: todo quedó «sin cambio».
        $this->assertCount(58, $primero['aplicados']);
        $this->assertCount(0, $segundo['aplicados']);
        $this->assertCount(58, $segundo['sin_cambio']);
    }

    /**
     * DORADA · el ajuste QD PPQ/31001 se relaciona con la referencia 31001, NO se reparte
     * entre las facturas y queda como NC de pronto pago pendiente.
     */
    public function test_dorada_el_ajuste_qd_queda_pendiente_de_nc_y_no_se_reparte(): void
    {
        $cliente = $this->cliente();
        $this->sembrarDesdeElArchivo($cliente);

        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());
        $informe = app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $this->archivo());

        $this->assertCount(1, $informe['ajustes']);

        $ajuste = CobroAjuste::firstOrFail();
        $this->assertSame('PPQ/31001', $ajuste->referencia);
        $this->assertSame('31001', $ajuste->referencia_calleja);
        $this->assertSame(0, Dinero::comparar('-107.21', $ajuste->monto));
        $this->assertSame('pendiente_nc', $ajuste->estado);
        $this->assertSame('NC de pronto pago pendiente', $ajuste->label());
        $this->assertNull($ajuste->nc_dte_id, 'No se emite ninguna NC automáticamente.');

        // Y no tocó ninguna factura: la suma de lo cobrado es la de CF + NC, sin el ajuste.
        $cobrado = Dinero::redondear((string) CobroDocumento::sum('monto_pagado'));
        $esperado = Dinero::redondear(Dinero::sumar('6826.42', '125.90')); // magnitudes
        $this->assertSame($esperado, $cobrado, 'El QD no puede haberse repartido entre las facturas.');

        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Ajuste->value)->count());
    }

    // ------------------------------------------------------------------ bordes

    /** Un documento que el archivo no menciona CONSERVA el pago que ya tenía. */
    public function test_un_archivo_posterior_no_borra_el_pago_de_lo_que_no_menciona(): void
    {
        $cliente = $this->cliente();
        $this->sembrarDesdeElArchivo($cliente);

        $filas = app(ConciliacionTxtParser::class)->parse($this->archivoReal());
        $aplicador = app(AplicadorPagosTxt::class);
        $aplicador->aplicar($cliente, $filas, $this->archivo());

        $unaPagada = CobroDocumento::where('pago_estado', EstadoPagoCobro::Pagado->value)->firstOrFail();
        $cobradoAntes = (string) $unaPagada->monto_pagado;

        // Un archivo POSTERIOR que solo trae otro documento.
        $otro = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000999',
            'fecha_emision' => '2026-09-10',
            'monto' => '50.00',
        ]);

        $posterior = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000999;10-SEP-26;50.00\n";

        $informe = $aplicador->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($posterior),
            ArchivoConciliacion::desdeContenido($posterior, 'posterior.txt'),
        );

        $this->assertSame(EstadoPagoCobro::Pagado, $otro->refresh()->pago_estado);
        $this->assertSame($cobradoAntes, (string) $unaPagada->refresh()->monto_pagado, 'El pago anterior se conserva.');
        $this->assertSame(EstadoPagoCobro::Pagado, $unaPagada->pago_estado);

        // Y se informa explícitamente que se conservaron.
        $this->assertGreaterThan(0, $informe['conservados']->count());
    }

    /** Un importe distinto al facturado no se da por pagado: queda como pago parcial. */
    public function test_un_importe_menor_deja_el_documento_en_pago_parcial(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000500',
            'fecha_emision' => '2026-09-01',
            'monto' => '100.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000500;01-SEP-26;60.00\n";

        app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'parcial.txt'),
        );

        $documento->refresh();
        $this->assertSame(EstadoPagoCobro::Parcial, $documento->pago_estado);
        $this->assertSame(0, Dinero::comparar('60.00', $documento->monto_pagado));
        $this->assertSame(0, Dinero::comparar('40.00', $documento->saldo()));
    }

    /** Cobrar de MÁS no es un pago parcial: es una diferencia que alguien debe mirar. */
    public function test_un_importe_mayor_deja_el_documento_en_diferencia(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000501',
            'monto' => '100.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000501;01-SEP-26;130.00\n";

        app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'demas.txt'),
        );

        $this->assertSame(EstadoPagoCobro::Diferencia, $documento->refresh()->pago_estado);
    }

    /**
     * La FECHA_DOCUMENTO del archivo no es la fecha del pago: se guarda con su nombre y el
     * documento no adquiere fecha de pago hasta que alguien la aporta.
     */
    public function test_la_fecha_del_documento_no_se_confunde_con_la_del_pago(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000502',
            'monto' => '25.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000502;03-SEP-26;25.00\n";

        $aplicador = app(AplicadorPagosTxt::class);
        $archivo = ArchivoConciliacion::desdeContenido($txt, 'sinfecha.txt');
        $aplicador->aplicar($cliente, app(ConciliacionTxtParser::class)->parse($txt), $archivo);

        $documento->refresh();
        $this->assertNull($documento->fecha_pago, 'El archivo no trae la fecha del pago y no se inventa.');

        $evento = CobroEvento::where('cobro_documento_id', $documento->id)->firstOrFail();
        $this->assertSame('2026-09-03', $evento->datos['fecha_documento_txt']);
        $this->assertNull($evento->fecha);
        $this->assertNotNull($evento->created_at, 'La fecha de CARGA queda por separado.');

        // Con la fecha del pago aportada, sí se escribe.
        $otroTxt = str_replace('000000000502', '000000000503', $txt);
        $otro = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000503',
            'monto' => '25.00',
        ]);

        $aplicador->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($otroTxt),
            ArchivoConciliacion::desdeContenido($otroTxt, 'confecha.txt'),
            null,
            Carbon::parse('2026-09-07'),
        );

        $this->assertSame('2026-09-07', $otro->refresh()->fecha_pago?->toDateString());
    }

    /** Un número que no está en el seguimiento se MUESTRA; no se crea ni se descarta. */
    public function test_un_documento_ajeno_se_informa_como_no_identificado(): void
    {
        $cliente = $this->cliente();

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P009000000000000777;01-SEP-26;10.00\n";

        $informe = app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'ajeno.txt'),
        );

        $this->assertCount(1, $informe['no_identificados']);
        $this->assertSame(0, CobroDocumento::count(), 'El archivo no da de alta documentos.');
    }

    /** Un archivo que se contradice no se aplica: ni un renglón. */
    public function test_un_archivo_que_se_contradice_no_aplica_nada(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000600',
            'monto' => '80.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000600;01-SEP-26;80.00\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000600;01-SEP-26;95.00\n";

        $this->expectException(ArchivoConciliacionInconsistenteException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($txt),
                ArchivoConciliacion::desdeContenido($txt, 'contradictorio.txt'),
            );
        } finally {
            $this->assertSame(EstadoPagoCobro::Pendiente, $documento->refresh()->pago_estado);
            $this->assertSame(0, CobroEvento::count());
        }
    }

    /** Una fila repetida IDÉNTICA se acepta una vez y se informa. */
    public function test_una_fila_repetida_identica_se_aplica_una_sola_vez(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000601',
            'monto' => '80.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000601;01-SEP-26;80.00\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000601;01-SEP-26;80.00\n";

        $informe = app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'repetida.txt'),
        );

        $this->assertCount(1, $informe['repetidas']);
        $this->assertSame(2, $informe['repetidas'][0]['veces']);
        $this->assertSame(0, Dinero::comparar('80.00', $documento->refresh()->monto_pagado));
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->pago_estado);
    }

    /** Filas sin número o sin importe se muestran; no se descartan en silencio. */
    public function test_las_filas_invalidas_se_muestran(): void
    {
        $cliente = $this->cliente();

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;;01-SEP-26;10.00\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000700;01-SEP-26;\n"
            ."000123;TITULAR DE EJEMPLO;XX;LOQUESEA;01-SEP-26;10.00\n";

        $informe = app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, 'invalidas.txt'),
        );

        $this->assertCount(2, $informe['invalidas'], 'Una sin número y una sin importe.');
        $this->assertCount(1, $informe['otros_tipos'], 'El tipo XX se informa, no se descarta.');
    }

    /** Un archivo de otro proveedor se rechaza ENTERO: no es evidencia de ningún pago suyo. */
    public function test_un_archivo_de_otro_proveedor_se_rechaza_entero(): void
    {
        $cliente = $this->cliente();

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."009999;OTRO PROVEEDOR;CF;DTE03M001P002000000000000800;01-SEP-26;10.00\n";

        $this->expectException(ArchivoProveedorInvalidoException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($txt),
                ArchivoConciliacion::desdeContenido($txt, 'otro.txt'),
            );
        } finally {
            $this->assertSame(0, CobroDocumento::count(), 'No se da de alta nada de un archivo rechazado.');
            $this->assertSame(0, CobroEvento::count());
        }
    }

    /**
     * Un archivo MIXTO (una fila del proveedor esperado + una ajena) se rechaza entero,
     * aunque la fila ajena coincida con un documento local: coincidir de número no prueba
     * que sea el mismo emisor.
     */
    public function test_un_archivo_mixto_con_fila_ajena_que_coincide_localmente_se_rechaza_entero(): void
    {
        $cliente = $this->cliente();

        $valido = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000810',
            'monto' => '10.00',
        ]);

        $ajeno = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000811',
            'monto' => '20.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000810;01-SEP-26;10.00\n"
            ."009999;OTRO PROVEEDOR;CF;DTE03M001P002000000000000811;01-SEP-26;20.00\n";

        $this->expectException(ArchivoProveedorInvalidoException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($txt),
                ArchivoConciliacion::desdeContenido($txt, 'mixto.txt'),
            );
        } finally {
            $this->assertSame(EstadoPagoCobro::Pendiente, $valido->refresh()->pago_estado, 'La fila válida tampoco se aplica: el archivo se rechaza entero.');
            $this->assertSame(EstadoPagoCobro::Pendiente, $ajeno->refresh()->pago_estado);
            $this->assertSame(0, CobroEvento::count());
        }
    }

    /** Una NC con proveedor ajeno también rechaza el archivo entero, aunque haya una fila válida. */
    public function test_nc_ajena_rechaza_el_archivo_aunque_haya_una_fila_valida(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000820',
            'monto' => '10.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000820;01-SEP-26;10.00\n"
            ."009999;OTRO;NC;DTE05M001P002000000000000821;01-SEP-26;-1.00\n";

        $this->expectException(ArchivoProveedorInvalidoException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($txt),
                ArchivoConciliacion::desdeContenido($txt, 'nc-ajena.txt'),
            );
        } finally {
            $this->assertSame(EstadoPagoCobro::Pendiente, $documento->refresh()->pago_estado);
            $this->assertSame(0, CobroEvento::count());
        }
    }

    /** Un QD con proveedor ajeno también rechaza el archivo entero, aunque no impute a ninguna factura. */
    public function test_qd_ajeno_rechaza_el_archivo_aunque_haya_una_fila_valida(): void
    {
        $cliente = $this->cliente();

        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000830',
            'monto' => '10.00',
        ]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000830;01-SEP-26;10.00\n"
            ."009999;OTRO;QD;PPQ/999;;-5.00\n";

        $this->expectException(ArchivoProveedorInvalidoException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($txt),
                ArchivoConciliacion::desdeContenido($txt, 'qd-ajeno.txt'),
            );
        } finally {
            $this->assertSame(EstadoPagoCobro::Pendiente, $documento->refresh()->pago_estado);
            $this->assertSame(0, CobroAjuste::count());
        }
    }
}
