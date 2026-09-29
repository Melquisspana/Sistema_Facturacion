<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\CorreoCobroParser;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\SolicitudCobroService;
use App\Support\Correo\CuerpoHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * RECUPERACIÓN de los correos del cliente: encontrarlos, leerlos enteros y no quedarse
 * siempre con los mismos.
 *
 * Los tres defectos que corrigen estas pruebas, todos del mismo tipo —buscar la información
 * donde no está—:
 *
 *  1. **El asunto real no dice «RECIBIDO».** El acuse llega como respuesta a nuestro propio
 *     correo, así que su asunto es «RE: SOLICITUD DE QUEDAN (PRONTO PAGO)» y la marca viene
 *     DENTRO del cuerpo. Un `subject:(RECIBIDO OR OBSERVACIONES)` no lo encontraba nunca.
 *
 *  2. **Las observaciones vienen en una TABLA HTML.** Con el snippet —o con un
 *     `strip_tags` a secas— los documentos observados se perdían enteros.
 *
 * El tercer defecto de la misma familia —que todas las corridas empezaran por la primera
 * página y el backlog no se leyera nunca— se prueba aparte, en
 * {@see CobrosBarridoBuzonTest}, porque no se arregla leyendo mejor un mensaje sino
 * recordando entre corridas hasta dónde se llegó.
 *
 * Nada de esto toca una cuenta de correo: la conversión de HTML es una función pura y el
 * resto son mensajes ya leídos.
 */
class CobrosCorreoRecuperacionTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    /** El asunto REAL del acuse: no menciona «RECIBIDO» por ningún lado. */
    private const ASUNTO_REAL = 'RE: SOLICITUD DE QUEDAN (PRONTO PAGO)';

    /** El cuerpo real, donde sí está la marca. */
    private const CUERPO_REAL = "Buenos días,\n\nRECIBIDO (000123202609040951)\nREFERENCIA #31001\n"
        ."PROGRAMACION DE PAGO: 07/09/2026\n\nSaludos,";

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    private function solicitudPresentada(Cliente $cliente): CobroSolicitud
    {
        $usuario = User::factory()->create();

        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/5131',
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '26090017003463',
            'sala_codigo' => '0017',
        ]);

        $dte = $this->dteVerificableCobros($cliente, 'DTE-03-M001P002-000000000000119', '2026-08-26', '77.74');
        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000119',
            'codigo_generacion' => '00000000-0000-4000-8000-000000000102',
            'monto' => '77.74',
            'ppq_albaran_id' => $albaran->id,
        ]);

        $servicio = app(SolicitudCobroService::class);
        $solicitud = $servicio->crear($cliente, [$documento->id], $usuario);
        $solicitud->forceFill(['archivo_nombre' => '000123202609040951.xlsx'])->save();
        $servicio->registrarPresentacion($solicitud->refresh(), $usuario);

        return $solicitud->refresh();
    }

    // ------------------------------------- 1 · el asunto real

    /**
     * DORADA · el acuse REAL —marca en el cuerpo, asunto de respuesta— se reconoce y se
     * aplica al envío correcto.
     */
    public function test_dorada_el_acuse_real_se_reconoce_aunque_el_asunto_no_diga_recibido(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);

        $leido = app(CorreoCobroParser::class)->interpretar(self::ASUNTO_REAL, self::CUERPO_REAL);

        $this->assertSame('recibido', $leido['tipo'], 'La marca está en el cuerpo, no en el asunto.');
        $this->assertSame('000123202609040951', $leido['archivo_referido']);
        $this->assertSame('31001', $leido['referencia_calleja']);
        $this->assertSame('2026-09-07', $leido['fecha_programada_pago']);

        $resumen = app(LectorCorreosCobro::class)->procesar($cliente, [[
            'id' => 'msg-real',
            'asunto' => self::ASUNTO_REAL,
            'cuerpo' => self::CUERPO_REAL,
            'fecha' => 'Fri, 04 Sep 2026 09:51:00 -0600',
        ]]);

        $this->assertSame(1, $resumen['asociados']);
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->refresh()->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
    }

    /**
     * La consulta por defecto NO filtra por asunto: es lo que hacía invisible al acuse real.
     */
    public function test_la_consulta_por_defecto_no_filtra_por_asunto(): void
    {
        $query = (string) config('cobros.correo.query');

        $this->assertStringNotContainsString('subject:', $query,
            'Filtrar por asunto deja fuera el acuse real, que trae la marca en el cuerpo.');
        $this->assertStringContainsString('RECIBIDO', $query);
        $this->assertStringContainsString('SOLICITUD DE QUEDAN', $query);
    }

    /**
     * El número del archivo se toma de su forma MARCADA, no del primer número largo del
     * cuerpo: una tabla de importes no puede ganarle a «RECIBIDO (…)».
     */
    public function test_el_archivo_se_toma_de_la_marca_y_no_de_cualquier_numero_largo(): void
    {
        $cuerpo = "Orden 26090017003463999 y monto 12345678901234\n"
            ."RECIBIDO (000123202609040951)\nREFERENCIA #31001";

        $leido = app(CorreoCobroParser::class)->interpretar(self::ASUNTO_REAL, $cuerpo);

        $this->assertSame('000123202609040951', $leido['archivo_referido']);
    }

    // ------------------------------------- 2 · el cuerpo HTML

    /**
     * DORADA · una tabla de observaciones en HTML conserva sus filas, y los documentos
     * observados se reconocen.
     *
     * Sin separar las celdas, `DTE-03-…-119` y el importe de al lado quedaban soldados y no
     * quedaba número de control que reconocer.
     */
    public function test_dorada_la_tabla_html_de_observaciones_conserva_sus_documentos(): void
    {
        $html = '<html><head><style>td{color:red}</style></head><body>'
            .'<p>Estimados,&nbsp;revisar:</p>'
            .'<table><tr><th>Documento</th><th>Monto</th><th>Motivo</th></tr>'
            .'<tr><td>DTE-03-M001P002-000000000000119</td><td>77.74</td><td>FALTA NOTA DE CREDITO</td></tr>'
            .'<tr><td>DTE-03-M001P001-000000000001186</td><td>141.25</td><td>NO APARECE EN REPORTERIA</td></tr>'
            .'</table><script>alert(1)</script></body></html>';

        $texto = CuerpoHtml::aTexto($html);

        // Las celdas quedaron separadas: el número no se soldó al importe.
        $this->assertStringContainsString("DTE-03-M001P002-000000000000119\t77.74", $texto);
        $this->assertStringContainsString('FALTA NOTA DE CREDITO', $texto);
        $this->assertStringContainsString('NO APARECE EN REPORTERIA', $texto);

        // Y lo que no es texto del mensaje no aparece.
        $this->assertStringNotContainsString('alert(1)', $texto);
        $this->assertStringNotContainsString('color:red', $texto);
        $this->assertStringNotContainsString('<', $texto);

        // El espacio duro es un espacio.
        $this->assertStringContainsString('Estimados, revisar:', $texto);

        // Y el parser encuentra los DOS documentos.
        $leido = app(CorreoCobroParser::class)->interpretar('OBSERVACIONES REF 31001', $texto);
        $this->assertSame([
            'DTE03M001P002000000000000119',
            'DTE03M001P001000000000001186',
        ], $leido['numeros_control']);
    }

    /** Las observaciones en tabla HTML se anotan en cada documento que nombran. */
    public function test_las_observaciones_en_html_se_anotan_en_sus_documentos(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);
        $documento = $solicitud->documentos()->firstOrFail();

        $html = '<table><tr><td>DTE-03-M001P002-000000000000119</td>'
            .'<td>FALTA NOTA DE CREDITO</td></tr></table>';

        app(LectorCorreosCobro::class)->procesar($cliente, [[
            'id' => 'msg-obs-html',
            'asunto' => 'RE: SOLICITUD DE QUEDAN (PRONTO PAGO)',
            'cuerpo' => CuerpoHtml::unir('OBSERVACIONES REF 31001', $html),
        ]]);

        $this->assertSame(1, CobroEvento::where('cobro_documento_id', $documento->id)
            ->where('tipo', TipoEventoCobro::Observacion->value)->count());
        $this->assertSame('asociado', CobroCorreo::firstOrFail()->estado);
    }

    /** El texto plano y el HTML se juntan sin repetirse. */
    public function test_el_cuerpo_junta_el_texto_plano_y_el_html_sin_duplicar(): void
    {
        $this->assertSame('Hola', CuerpoHtml::unir('Hola', ''));
        $this->assertSame('Hola', CuerpoHtml::unir('', '<p>Hola</p>'));
        $this->assertSame('Hola', CuerpoHtml::unir('Hola', '<p>Hola</p>'), 'Si ya está, no se repite.');
        $this->assertSame("Hola\nTabla", CuerpoHtml::unir('Hola', '<p>Tabla</p>'));
        $this->assertSame('', CuerpoHtml::unir(null, null));
    }

    /** Un HTML descomunal se acota en vez de inflar la memoria de la corrida. */
    public function test_un_html_enorme_se_acota(): void
    {
        $html = '<p>inicio</p>'.str_repeat('<p>relleno</p>', 200000);

        $texto = CuerpoHtml::aTexto($html);

        $this->assertStringContainsString('inicio', $texto);
        $this->assertLessThanOrEqual(CuerpoHtml::MAX_BYTES, strlen($texto));
    }
}
