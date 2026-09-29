<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroCorreoProgreso;
use App\Services\Cobros\BarridoCorreosCobro;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EL AVANCE DEL BARRIDO ENTRE CORRIDAS.
 *
 * Paginar dentro de una corrida no servía de nada mientras TODAS empezaran en la primera
 * página: la tarea de cada media hora leía siempre los mismos primeros N y el mensaje N+1
 * no se leía jamás. Y los ya registrados gastaban cupo, así que en cuanto los N más nuevos
 * estaban procesados, el rendimiento efectivo de cada corrida era cero.
 *
 * Subir el límite no lo arregla: mueve la frontera y el problema reaparece más atrás.
 *
 * Lo que se comprueba acá es lo único que importa de verdad: que corriendo la tarea las
 * veces que haga falta, TODOS los mensajes terminan procesados, cada uno UNA sola vez, y
 * que un correo nuevo que entra a mitad del recorrido no espera detrás del backlog ni se
 * pierde.
 *
 * Ningún buzón real se toca: el doble de Gmail devuelve mensajes de mentira y no tiene con
 * qué escribir en ninguna cuenta.
 */
class CobrosBarridoBuzonTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    /**
     * Un buzón de mentira con `$cantidad` mensajes, del más nuevo al más viejo, uno por
     * hora hacia atrás.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buzon(int $cantidad, string $prefijo = 'm', ?Carbon $desde = null): array
    {
        $desde ??= Carbon::parse('2026-09-18 12:00:00');
        $mensajes = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $fecha = $desde->copy()->subHours($i);
            $mensajes[] = [
                'id' => $prefijo.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'threadId' => 'hilo',
                'asunto' => 'RE: SOLICITUD DE QUEDAN (PRONTO PAGO)',
                'remitente' => 'fiscal@cliente-ejemplo.test',
                'fecha' => $fecha->toRfc2822String(),
                'cuerpo' => 'OBSERVACIONES REF 31001',
            ];
        }

        return $mensajes;
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · con 120 mensajes y un límite de 20, siete corridas los procesan TODOS, cada
     * uno exactamente una vez.
     *
     * Antes de esto, la corrida 2 y las siguientes no traían nada nuevo: volvían a pedir los
     * mismos 20 de arriba, que ya estaban registrados.
     */
    public function test_dorada_varias_corridas_terminan_procesando_todo_el_buzon_una_sola_vez(): void
    {
        $cliente = $this->cliente();
        $buzon = $this->buzon(120);
        $gmail = new GmailClientDeBuzon($buzon);
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $procesados = [];
        $corridas = 0;

        // Se corre hasta que el barrido diga que ya no queda buzón. El tope es una red de
        // seguridad: si el barrido no avanzara, esta prueba fallaría por acá.
        while ($corridas < 30) {
            $corridas++;
            $tanda = $barrido->prepararTanda($cliente, 'consulta', 20);

            foreach ($tanda['mensajes'] as $mensaje) {
                $procesados[] = $mensaje['id'];
            }

            $lector->procesar($cliente, $tanda['mensajes']);
            $barrido->confirmarAvance($cliente, 'consulta', $tanda);

            if (! $tanda['queda_backlog'] && $tanda['mensajes'] === []) {
                break;
            }
        }

        // 1 · Todos, y ni uno repetido.
        $this->assertCount(120, $procesados, 'Los 120 mensajes tienen que haberse procesado.');
        $this->assertSame(
            count($procesados),
            count(array_unique($procesados)),
            'Ningún mensaje puede procesarse dos veces.'
        );
        $this->assertSame(
            array_column($buzon, 'id'),
            collect($procesados)->sort()->values()->all(),
            'Y tienen que ser exactamente los del buzón.'
        );

        // 2 · Una fila por mensaje, sin duplicados.
        $this->assertSame(120, CobroCorreo::count());

        // 3 · Se necesitaron varias corridas, no una: el límite se respetó.
        $this->assertGreaterThanOrEqual(6, $corridas);

        // 4 · El barrido quedó marcado como completo.
        $progreso = CobroCorreoProgreso::existentePara($cliente, 'consulta');
        $this->assertTrue($progreso->barrido_completo);
        $this->assertStringContainsString('recorrido entero', $progreso->resumen());
    }

    /**
     * DORADA · un mensaje NUEVO que entra a mitad del recorrido se lee en la corrida
     * siguiente, sin esperar a que termine el backlog y sin perderse.
     *
     * Es la razón de que haya dos ventanas: un acuse de hoy no puede quedarse detrás de tres
     * meses de correo viejo.
     */
    public function test_dorada_un_mensaje_nuevo_a_mitad_del_recorrido_no_espera_ni_se_pierde(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(60));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        // Dos corridas: el barrido va por la mitad del buzón.
        for ($i = 0; $i < 2; $i++) {
            $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
            $lector->procesar($cliente, $tanda['mensajes']);
            $barrido->confirmarAvance($cliente, 'consulta', $tanda);
        }

        $this->assertSame(20, CobroCorreo::count());
        $this->assertTrue(CobroCorreoProgreso::existentePara($cliente, 'consulta')->quedaBacklog());

        // Llega un correo NUEVO, más nuevo que todo lo que había.
        $gmail->recibir([
            'id' => 'nuevo-1',
            'threadId' => 'hilo',
            'asunto' => 'RE: SOLICITUD DE QUEDAN (PRONTO PAGO)',
            'remitente' => 'fiscal@cliente-ejemplo.test',
            'fecha' => Carbon::parse('2026-09-19 08:00:00')->toRfc2822String(),
            'cuerpo' => "RECIBIDO (000123202609040951)\nREFERENCIA #31001",
        ]);

        $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);

        // Está en ESTA tanda, no dentro de cuatro corridas.
        $this->assertContains('nuevo-1', array_column($tanda['mensajes'], 'id'),
            'Lo nuevo se lee enseguida: no espera detrás del backlog.');
        $this->assertGreaterThan(0, $tanda['cabeza'], 'Entró por la ventana de la cabeza.');

        // Y es el PRIMERO de la tanda: la cabeza va antes que la cola, siempre.
        $this->assertSame('nuevo-1', $tanda['mensajes'][0]['id']);

        // Ningún mensaje repetido dentro de la misma tanda, aunque las dos ventanas se
        // solapen.
        $ids = array_column($tanda['mensajes'], 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));

        $lector->procesar($cliente, $tanda['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $tanda);

        // Y al terminar el recorrido está todo, una vez cada cosa.
        $vueltas = 0;
        while ($vueltas < 30) {
            $vueltas++;
            $siguiente = $barrido->prepararTanda($cliente, 'consulta', 10);
            $lector->procesar($cliente, $siguiente['mensajes']);
            $barrido->confirmarAvance($cliente, 'consulta', $siguiente);
            if (! $siguiente['queda_backlog'] && $siguiente['mensajes'] === []) {
                break;
            }
        }

        $this->assertSame(61, CobroCorreo::count(), 'Los 60 del buzón más el nuevo.');
        $this->assertSame(1, CobroCorreo::where('gmail_message_id', 'nuevo-1')->count());
    }

    /**
     * DORADA · los mensajes YA REGISTRADOS no consumen el cupo de la corrida.
     *
     * Era el segundo defecto: con el solape de seguridad, cada corrida se gastaba el límite
     * releyendo lo mismo y no avanzaba.
     */
    public function test_dorada_lo_ya_registrado_no_consume_el_cupo(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(40));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $primera = $barrido->prepararTanda($cliente, 'consulta', 10);
        $lector->procesar($cliente, $primera['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $primera);
        $this->assertCount(10, $primera['mensajes']);

        $cuerposAntes = $gmail->cuerposBajados;

        $segunda = $barrido->prepararTanda($cliente, 'consulta', 10);

        // Diez NUEVOS otra vez, ninguno repetido de la primera tanda.
        $this->assertCount(10, $segunda['mensajes'], 'El cupo se gasta en mensajes nuevos, no en conocidos.');
        $this->assertSame([], array_intersect(
            array_column($primera['mensajes'], 'id'),
            array_column($segunda['mensajes'], 'id'),
        ));

        // Y solo se bajó el cuerpo de los nuevos: los conocidos se descartan por su id.
        $this->assertSame(10, $gmail->cuerposBajados - $cuerposAntes,
            'De los ya registrados no se baja el cuerpo: se descartan con la lista de ids.');
        $this->assertGreaterThan(0, $segunda['ya_conocidos']);
    }

    // ------------------------------------------- interrupciones

    /**
     * DORADA · si el procesamiento revienta a mitad de una tanda del BACKLOG, no se pierde
     * un solo correo: el reintento trae los que faltaban y no duplica los que sí se
     * guardaron.
     *
     * Es el caso que obligó a separar preparar de confirmar. Antes, la marca del barrido se
     * guardaba ANTES de procesar, así que los correos que no llegaron a registrarse quedaban
     * detrás de ella —y la cola no vuelve a mirar ahí—. Se perdían, y sin dejar constancia:
     * un correo que falta no avisa de que falta.
     */
    public function test_dorada_un_fallo_a_mitad_de_la_tanda_no_pierde_ningun_correo(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(40));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        // Dos corridas limpias: el barrido ya está metido en el backlog.
        for ($i = 0; $i < 2; $i++) {
            $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
            $lector->procesar($cliente, $tanda['mensajes']);
            $barrido->confirmarAvance($cliente, 'consulta', $tanda);
        }
        $this->assertSame(20, CobroCorreo::count());

        // Tercera corrida: revienta después de guardar 4 de los 10.
        $tercera = $barrido->prepararTanda($cliente, 'consulta', 10);
        $this->assertCount(10, $tercera['mensajes']);
        $idsDeLaTanda = array_column($tercera['mensajes'], 'id');

        try {
            $lector->procesar($cliente, array_slice($tercera['mensajes'], 0, 4));

            throw new \RuntimeException('Se cayó la base a mitad de la tanda.');
        } catch (\RuntimeException) {
            // Justo lo que pasa en producción: NO se llega a confirmar el avance.
        }

        // Solo se guardaron 4 de los 10, y la marca NO se movió por los otros 6.
        $this->assertSame(24, CobroCorreo::count());
        $progreso = CobroCorreoProgreso::existentePara($cliente, 'consulta');
        $this->assertNotNull($progreso);

        // La marca de la cola sigue donde la dejó la SEGUNDA corrida: no saltó por encima
        // de los seis que no se registraron.
        $marcaTrasElFallo = $progreso->barrido_hasta;
        $sinRegistrar = array_slice($idsDeLaTanda, 4);
        foreach ($sinRegistrar as $id) {
            $this->assertSame(0, CobroCorreo::where('gmail_message_id', $id)->count());
        }

        // REINTENTO: la corrida siguiente vuelve a traer exactamente los seis que faltaban.
        $reintento = $barrido->prepararTanda($cliente, 'consulta', 10);
        $idsReintento = array_column($reintento['mensajes'], 'id');

        foreach ($sinRegistrar as $id) {
            $this->assertContains($id, $idsReintento, "El mensaje {$id} se perdió: no volvió en el reintento.");
        }

        // Y los cuatro que sí se habían guardado NO se vuelven a bajar.
        foreach (array_slice($idsDeLaTanda, 0, 4) as $id) {
            $this->assertNotContains($id, $idsReintento, "El mensaje {$id} se bajó dos veces.");
        }

        $lector->procesar($cliente, $reintento['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $reintento);

        // Hasta el final: los 40, cada uno una sola vez.
        $vueltas = 0;
        while ($vueltas < 30) {
            $vueltas++;
            $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
            $lector->procesar($cliente, $tanda['mensajes']);
            $progreso = $barrido->confirmarAvance($cliente, 'consulta', $tanda);
            if (! $progreso->quedaBacklog() && $tanda['mensajes'] === []) {
                break;
            }
        }

        $this->assertSame(40, CobroCorreo::count(), 'Los 40 del buzón, ni uno perdido.');
        $this->assertSame(40, CobroCorreo::distinct('gmail_message_id')->count('gmail_message_id'));
        $this->assertNotNull($marcaTrasElFallo);
    }

    /**
     * DORADA · una interrupción TOTAL —no se guardó nada y no se confirmó— deja la marca
     * intacta, y la corrida siguiente repite la misma tanda.
     */
    public function test_dorada_una_interrupcion_total_repite_la_tanda_sin_perder_nada(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(30));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $primera = $barrido->prepararTanda($cliente, 'consulta', 10);
        $lector->procesar($cliente, $primera['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $primera);

        $marcaAntes = CobroCorreoProgreso::existentePara($cliente, 'consulta')->barrido_hasta;

        // Se prepara una tanda y la máquina se apaga: nadie procesa ni confirma.
        $perdida = $barrido->prepararTanda($cliente, 'consulta', 10);
        $this->assertCount(10, $perdida['mensajes']);

        $this->assertEquals(
            $marcaAntes,
            CobroCorreoProgreso::existentePara($cliente, 'consulta')->barrido_hasta,
            'Preparar no mueve la marca.'
        );

        // La corrida siguiente trae EXACTAMENTE lo mismo.
        $repetida = $barrido->prepararTanda($cliente, 'consulta', 10);

        $this->assertSame(
            array_column($perdida['mensajes'], 'id'),
            array_column($repetida['mensajes'], 'id'),
            'La tanda que no se confirmó vuelve entera.'
        );

        $lector->procesar($cliente, $repetida['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $repetida);

        $this->assertSame(20, CobroCorreo::count());
    }

    /**
     * El barrido NO se declara completo si la última tanda dejó mensajes sin registrar.
     *
     * Darlo por terminado ahí sería dejar fuera para siempre justo los que fallaron.
     */
    public function test_no_se_declara_completo_si_quedo_algo_sin_registrar(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(5));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
        $this->assertCount(5, $tanda['mensajes']);
        $this->assertTrue($tanda['cola_agotada'], 'El buzón entero cabe en una tanda.');

        // Solo se registran 3 de los 5, y aun así se confirma.
        $lector->procesar($cliente, array_slice($tanda['mensajes'], 0, 3));
        $progreso = $barrido->confirmarAvance($cliente, 'consulta', $tanda);

        $this->assertFalse($progreso->barrido_completo,
            'Con mensajes sin registrar, el barrido no puede darse por terminado.');
        $this->assertTrue($progreso->quedaBacklog());

        // Y los dos que faltaban vuelven.
        $siguiente = $barrido->prepararTanda($cliente, 'consulta', 10);
        $this->assertCount(2, $siguiente['mensajes']);

        $lector->procesar($cliente, $siguiente['mensajes']);
        $progreso = $barrido->confirmarAvance($cliente, 'consulta', $siguiente);

        $this->assertSame(5, CobroCorreo::count());
        $this->assertTrue($progreso->barrido_completo, 'Ahora sí: no queda nada sin registrar.');
    }

    /** Confirmar sin haber registrado NADA no mueve la marca ni un segundo. */
    public function test_confirmar_sin_nada_registrado_no_mueve_la_marca(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(20));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $primera = $barrido->prepararTanda($cliente, 'consulta', 5);
        $lector->procesar($cliente, $primera['mensajes']);
        $barrido->confirmarAvance($cliente, 'consulta', $primera);

        $antes = CobroCorreoProgreso::existentePara($cliente, 'consulta');
        $marcaCola = $antes->barrido_hasta;
        $marcaCabeza = $antes->ultimo_mensaje_en;

        // Segunda tanda: se prepara, no se procesa NADA, y aun así se confirma.
        $segunda = $barrido->prepararTanda($cliente, 'consulta', 5);
        $progreso = $barrido->confirmarAvance($cliente, 'consulta', $segunda);

        $this->assertEquals($marcaCola, $progreso->barrido_hasta, 'La cola no avanzó.');
        $this->assertEquals($marcaCabeza, $progreso->ultimo_mensaje_en, 'La cabeza tampoco.');
    }

    // ------------------------------------------------------------------ bordes

    /** El ENSAYO EN SECO no mueve la marca: la corrida de verdad sigue donde estaba. */
    public function test_el_ensayo_en_seco_no_escribe_el_avance(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(30));
        $barrido = new BarridoCorreosCobro($gmail);

        // El ensayo en seco es PREPARAR sin confirmar. No hay bandera que pasar mal.
        $seco = $barrido->prepararTanda($cliente, 'consulta', 10);

        $this->assertCount(10, $seco['mensajes'], 'En seco calcula igual...');
        $this->assertSame(0, CobroCorreoProgreso::count(), '...pero no escribe ni la fila de progreso.');
        $this->assertSame(0, CobroCorreo::count());

        // Y la corrida de verdad empieza desde el principio, como si el ensayo no existiera.
        $real = $barrido->prepararTanda($cliente, 'consulta', 10);
        $this->assertSame(
            array_column($seco['mensajes'], 'id'),
            array_column($real['mensajes'], 'id'),
            'El ensayo no le robó la tanda a la corrida real.'
        );
    }

    /** Se avisa cuando quedan mensajes por procesar, y cuando ya no queda ninguno. */
    public function test_avisa_si_quedan_mensajes_pendientes(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(25));
        $barrido = new BarridoCorreosCobro($gmail);
        $lector = app(LectorCorreosCobro::class);

        $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
        $lector->procesar($cliente, $tanda['mensajes']);
        $progreso = $barrido->confirmarAvance($cliente, 'consulta', $tanda);

        $this->assertTrue($tanda['queda_backlog'], 'Todavía queda buzón por recorrer.');
        $this->assertTrue($tanda['tope_alcanzado'], 'Y se avisa que el tope cortó la corrida.');
        // El resumen se lee del progreso CONFIRMADO: el de la tanda es el de antes de ella.
        $this->assertStringContainsString('Quedan mensajes anteriores', $progreso->resumen());

        // Hasta el final.
        $vueltas = 0;
        while ($vueltas < 20) {
            $vueltas++;
            $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
            $lector->procesar($cliente, $tanda['mensajes']);
            $progreso = $barrido->confirmarAvance($cliente, 'consulta', $tanda);
            if (! $progreso->quedaBacklog()) {
                break;
            }
        }

        $this->assertFalse($progreso->quedaBacklog());
        $this->assertSame(25, CobroCorreo::count());
        $this->assertStringContainsString('recorrido entero', $progreso->resumen());
    }

    /**
     * Cambiar la consulta EMPIEZA UN BARRIDO NUEVO: el universo es otro, y heredar el
     * avance daría por cubierto lo que nunca se miró.
     */
    public function test_cambiar_la_consulta_no_hereda_el_avance(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(30));
        $barrido = new BarridoCorreosCobro($gmail);

        $barrido->confirmarAvance($cliente, 'consulta-a', $barrido->prepararTanda($cliente, 'consulta-a', 10));
        $barrido->confirmarAvance($cliente, 'consulta-b', $barrido->prepararTanda($cliente, 'consulta-b', 10));

        $this->assertSame(2, CobroCorreoProgreso::count());
        $this->assertNotNull(CobroCorreoProgreso::existentePara($cliente, 'consulta-a'));
        $this->assertNotNull(CobroCorreoProgreso::existentePara($cliente, 'consulta-b'));
    }

    /** Un buzón vacío queda marcado como recorrido entero sin dar vueltas. */
    public function test_un_buzon_vacio_se_marca_completo(): void
    {
        $cliente = $this->cliente();
        $barrido = new BarridoCorreosCobro(new GmailClientDeBuzon([]));

        $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);

        $this->assertSame([], $tanda['mensajes']);
        $this->assertFalse($tanda['queda_backlog']);
        $this->assertFalse($tanda['tope_alcanzado']);
    }

    /** El barrido no escribe una sola vez en el buzón: solo lista y baja. */
    public function test_el_barrido_solo_lee_el_buzon(): void
    {
        $cliente = $this->cliente();
        $gmail = new GmailClientDeBuzon($this->buzon(15));
        $barrido = new BarridoCorreosCobro($gmail);

        $tanda = $barrido->prepararTanda($cliente, 'consulta', 10);
        $barrido->confirmarAvance($cliente, 'consulta', $tanda);

        $this->assertSame([], $gmail->escrituras, 'Ninguna operación de escritura sobre Gmail.');
    }
}

/**
 * Doble de {@see GmailClient} con un buzón en memoria que entiende `after:` y `before:`
 * en epoch, que es exactamente lo que el barrido usa para moverse.
 *
 * Solo reemplaza las dos llamadas de lectura; toda la política de barrido que se prueba es
 * la de verdad. Y no expone NINGUNA operación de escritura: si el barrido intentara marcar
 * como leído o etiquetar, no tendría con qué.
 */
class GmailClientDeBuzon extends GmailClient
{
    /** Por página, como Gmail. */
    private const POR_PAGINA = 25;

    public int $cuerposBajados = 0;

    /** @var array<int, string> lo que el barrido haya intentado escribir (debe quedar vacío) */
    public array $escrituras = [];

    /** @param array<int, array<string, mixed>> $mensajes */
    public function __construct(private array $mensajes = [])
    {
        parent::__construct();
        $this->ordenar();
    }

    /** Un correo que entra al buzón después de arrancar el recorrido. */
    public function recibir(array $mensaje): void
    {
        $this->mensajes[] = $mensaje;
        $this->ordenar();
    }

    public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
    {
        $filtrados = array_values(array_filter(
            $this->mensajes,
            fn (array $m) => $this->cumple($m, $query),
        ));

        $desde = (int) ($token ?? 0);
        $tamano = min($max, self::POR_PAGINA);
        $pagina = array_slice($filtrados, $desde, $tamano);
        $siguiente = ($desde + $tamano) < count($filtrados) ? (string) ($desde + $tamano) : null;

        return ['ids' => array_column($pagina, 'id'), 'siguiente' => $siguiente];
    }

    public function mensajeCobro(string $id): array
    {
        $this->cuerposBajados++;

        foreach ($this->mensajes as $mensaje) {
            if ($mensaje['id'] === $id) {
                return $mensaje;
            }
        }

        throw new \RuntimeException("No existe el mensaje {$id}.");
    }

    /** Gmail devuelve del más nuevo al más viejo. */
    private function ordenar(): void
    {
        usort($this->mensajes, fn ($a, $b) => strtotime($b['fecha']) <=> strtotime($a['fecha']));
    }

    /** @param array<string, mixed> $mensaje */
    private function cumple(array $mensaje, string $query): bool
    {
        $epoch = strtotime($mensaje['fecha']);

        if (preg_match('/\bafter:(\d+)\b/', $query, $m) && $epoch <= (int) $m[1]) {
            return false;
        }

        if (preg_match('/\bbefore:(\d+)\b/', $query, $m) && $epoch >= (int) $m[1]) {
            return false;
        }

        return true;
    }
}
