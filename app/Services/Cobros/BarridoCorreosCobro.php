<?php

namespace App\Services\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroCorreoProgreso;
use App\Services\Ppq\GmailClient;
use Illuminate\Support\Carbon;

/**
 * Decide QUÉ mensajes del buzón toca leer en esta corrida, y deja anotado hasta dónde se
 * llegó para que la siguiente siga por donde esta se quedó.
 *
 * ═════════════════ El problema: la corrida que nunca avanza ═════════════════
 *
 * Una búsqueda de Gmail devuelve del más nuevo al más viejo. Una tarea que cada media hora
 * pide «los primeros 50» lee SIEMPRE los mismos cincuenta: el mensaje número 51 no se lee
 * jamás, y el buzón se va llenando por detrás sin que nadie se entere.
 *
 * Paginar dentro de la corrida no lo arregla —el problema no es que no sepa avanzar dentro
 * de una corrida, es que todas empiezan en el mismo sitio—. Y subir el límite tampoco:
 * mueve la frontera de 50 a 200 y el problema reaparece en el mensaje 201.
 *
 * ═══════════════════════════ La solución: dos ventanas ═══════════════════════════
 *
 * Cada corrida mira DOS sitios distintos y reparte su presupuesto entre ellos:
 *
 *   1. **CABEZA** — `after:{último mensaje visto}`. Lo que llegó desde la última vez. Es
 *      poco y es lo que no puede esperar: un acuse de hoy no debe quedarse detrás de un
 *      backlog de tres meses.
 *
 *   2. **COLA** — `before:{hasta dónde llegó el barrido}`. El backlog, que retrocede una
 *      tanda por corrida hasta tocar el principio del buzón. Ahí `barrido_completo` queda
 *      en true y las corridas siguientes solo miran la cabeza.
 *
 * La cabeza va PRIMERO y con prioridad de presupuesto. Si un día entran cien correos
 * nuevos, esa corrida no avanza en la cola —y está bien: lo urgente es lo nuevo—, pero la
 * marca de la cola no se mueve, así que no se pierde nada.
 *
 * ═══════════════════ Lo ya registrado no gasta presupuesto ═══════════════════
 *
 * El límite cuenta mensajes POR PROCESAR, no mensajes mirados. Primero se piden los IDS
 * —una llamada barata— y se descartan los que ya están en `cobro_correos`; solo se baja el
 * cuerpo de los que quedan. Sin esto, el solape de seguridad de la cabeza se comía la
 * corrida entera releyendo lo mismo.
 *
 * Y como los ids ya conocidos traen su fecha desde nuestra propia base, la cola AVANZA
 * aunque una tanda entera resulte ser de mensajes ya leídos. Si no, una página repetida
 * dejaría el barrido clavado para siempre.
 *
 * ═══════════════ Preparar y confirmar son dos pasos, y ese es el punto ═══════════════
 *
 * {@see prepararTanda()} calcula qué toca leer y NO escribe nada.
 * {@see confirmarAvance()} mueve la marca, y solo sobre los mensajes que EFECTIVAMENTE
 * quedaron registrados.
 *
 * Juntarlo en una sola llamada —como estaba— significaba mover la marca antes de que nadie
 * hubiera procesado nada: si el procesamiento fallaba a mitad, los correos que no llegaron
 * a guardarse quedaban DETRÁS de la marca, la cola no vuelve a mirar ahí, y se perdían sin
 * dejar constancia de haberse perdido. Un correo perdido no avisa de que falta.
 *
 * El orden correcto es siempre: preparar → procesar → confirmar. Si algo revienta en
 * medio, la marca se queda donde estaba y la corrida siguiente vuelve a traer lo que no se
 * procesó; lo que sí se procesó se descarta por su id y no cuesta ni una llamada.
 *
 * ═══════════════════════════ No toca el buzón ═══════════════════════════
 *
 * Solo lee. No marca como leído, no etiqueta, no mueve y no borra: por eso el avance se
 * anota acá y no en Gmail. El buzón queda exactamente igual que antes de la corrida.
 */
class BarridoCorreosCobro
{
    /**
     * Cuánto se retrocede en la CABEZA respecto del último mensaje visto.
     *
     * No es paranoia: la fecha de un correo es la que trae su cabecera, y un mensaje puede
     * entrar al buzón con una fecha ligeramente anterior a otro que ya habíamos leído
     * (retrasos de entrega, husos distintos). Sin solape, ese mensaje caería justo en el
     * hueco entre las dos ventanas y no lo vería nadie.
     *
     * Lo que se relee por el solape no cuesta nada: son ids ya conocidos, que se descartan
     * antes de bajar ningún cuerpo.
     */
    private const SOLAPE_HORAS = 36;

    /**
     * Tope de páginas por ventana. Acota el trabajo de una corrida cuando la tanda viene
     * entera de mensajes ya conocidos: sin esto, una cola con miles de correos ya leídos
     * podría recorrer el buzón entero en una sola pasada.
     */
    private const PAGINAS_MAX = 10;

    public function __construct(private readonly GmailClient $gmail) {}

    /**
     * PREPARA la siguiente tanda: la calcula y la devuelve SIN escribir una sola fila.
     *
     * ══════════ Por qué preparar y confirmar son dos pasos y no uno ══════════
     *
     * Antes, este método guardaba el avance del barrido antes de que nadie hubiera
     * procesado los mensajes. Si el procesamiento fallaba a mitad —la base, un mensaje
     * raro, la máquina apagándose— la marca ya había avanzado por encima de correos que
     * nunca llegaron a registrarse. Esos correos quedaban DETRÁS de la marca: la cola no
     * vuelve a mirar ahí, así que se perdían, y sin dejar rastro de haberse perdido.
     *
     * Ahora preparar no escribe nada. La marca solo se mueve en {@see confirmarAvance()},
     * y se mueve sobre lo que EFECTIVAMENTE quedó registrado. Una interrupción, en el
     * momento que sea, deja la marca donde estaba: la corrida siguiente vuelve a traer lo
     * que no se procesó, y lo que sí se procesó se descarta por su id sin costar nada.
     *
     * También es lo que hace del ENSAYO EN SECO algo trivialmente seguro: es esta llamada
     * sin la otra. No hay bandera que alguien pueda pasar mal.
     *
     * @return array{
     *     mensajes: array<int, array<string, mixed>>,
     *     vistos: array<string, Carbon>,
     *     progreso_previo: CobroCorreoProgreso,
     *     cabeza: int,
     *     cola: int,
     *     ya_conocidos: int,
     *     cola_agotada: bool,
     *     queda_backlog: bool,
     *     tope_alcanzado: bool
     * }
     */
    public function prepararTanda(Cliente $cliente, string $consulta, int $limite): array
    {
        // SIN crear la fila: preparar no escribe. Si todavía no existe, se trabaja sobre
        // una instancia en memoria que nadie guarda.
        $progreso = CobroCorreoProgreso::existentePara($cliente, $consulta)
            ?? new CobroCorreoProgreso(['cliente_id' => $cliente->id, 'consulta' => $consulta]);

        $vistos = [];
        $conocidos = 0;
        $topeAlcanzado = false;

        /*
        | Ids ya recogidos EN ESTA corrida.
        |
        | Hace falta porque las dos ventanas pueden solaparse: en cuanto la marca de la cola
        | entra dentro del solape de la cabeza, las dos consultas devuelven los mismos
        | mensajes. Y el filtro de «ya registrado» no los ve, porque todavía no se han
        | guardado —el barrido decide la tanda, el lector la procesa después—.
        |
        | Sin esto, el mismo correo salía dos veces en la misma tanda.
        */
        $enEstaTanda = [];

        // ─── 1 · CABEZA: lo nuevo desde la última corrida ───
        $cabeza = $this->recorrer(
            $this->consultaCabeza($consulta, $progreso),
            $limite,
            $vistos,
            $conocidos,
            $topeAlcanzado,
            $enEstaTanda,
        );
        $mensajes = $cabeza;

        // ─── 2 · COLA: el backlog, si queda presupuesto y queda buzón ───
        $cola = [];
        $colaAgotada = false;
        $restante = $limite - count($mensajes);

        if ($restante > 0 && $progreso->quedaBacklog()) {
            $vistosCola = [];
            $cola = $this->recorrer(
                $this->consultaCola($consulta, $progreso),
                $restante,
                $vistosCola,
                $conocidos,
                $topeAlcanzado,
                $enEstaTanda,
            );

            $colaAgotada = $this->colaAgotada($progreso, $vistosCola);

            $mensajes = array_merge($mensajes, $cola);
            $vistos = array_merge($vistos, $vistosCola);
        }

        return [
            'mensajes' => $mensajes,
            // Todos los ids mirados con su fecha —nuevos y ya conocidos—. Es lo que
            // `confirmarAvance()` cruza contra lo realmente registrado.
            'vistos' => $vistos,
            // El estado ANTES de esta tanda. Se llama «previo» a propósito: leer de acá el
            // resultado final diría que el barrido no avanzó, porque todavía no lo ha hecho.
            // Lo definitivo lo devuelve `confirmarAvance()`.
            'progreso_previo' => $progreso,
            'cabeza' => count($cabeza),
            'cola' => count($cola),
            'ya_conocidos' => $conocidos,
            'cola_agotada' => $colaAgotada,
            'queda_backlog' => $progreso->quedaBacklog() && ! $colaAgotada,
            'tope_alcanzado' => $topeAlcanzado,
        ];
    }

    /**
     * CONFIRMA el avance, una vez que los mensajes ya están registrados.
     *
     * ═══════════ La marca avanza sobre lo registrado, no sobre lo mirado ═══════════
     *
     * No se confía en que «el procesamiento devolvió sin excepción»: se vuelve a preguntar
     * a `cobro_correos` cuáles de los ids de la tanda están EFECTIVAMENTE guardados, y la
     * marca se mueve solo sobre esos. Con eso, un fallo a mitad de tanda se resuelve solo:
     * los que se guardaron quedan detrás de la marca y no se vuelven a bajar; los que no,
     * quedan delante y la corrida siguiente los trae.
     *
     * Y el barrido solo se declara COMPLETO si además de haberse agotado la cola, todo lo
     * mirado quedó registrado. Darlo por terminado con mensajes sin guardar sería dejar
     * fuera para siempre justo los que fallaron.
     *
     * @param  array<string, mixed>  $tanda  lo que devolvió {@see prepararTanda()}
     */
    public function confirmarAvance(Cliente $cliente, string $consulta, array $tanda): CobroCorreoProgreso
    {
        $progreso = CobroCorreoProgreso::para($cliente, $consulta);

        /** @var array<string, Carbon> $vistos */
        $vistos = $tanda['vistos'] ?? [];

        $registrados = $vistos === []
            ? collect()
            : CobroCorreo::whereIn('gmail_message_id', array_keys($vistos))
                ->pluck('gmail_message_id')
                ->flip();

        $fechas = [];
        foreach ($vistos as $id => $fecha) {
            if ($registrados->has($id)) {
                $fechas[] = $fecha;
            }
        }

        if ($fechas !== []) {
            $masNueva = null;
            $masVieja = null;

            foreach ($fechas as $fecha) {
                $masNueva = $masNueva === null || $fecha->gt($masNueva) ? $fecha : $masNueva;
                $masVieja = $masVieja === null || $fecha->lt($masVieja) ? $fecha : $masVieja;
            }

            $progreso->avanzarCabeza($masNueva);
            $progreso->avanzarCola($masVieja);
        }

        // Completo solo si la cola se agotó Y no quedó ningún mirado sin registrar.
        if (($tanda['cola_agotada'] ?? false) && count($fechas) === count($vistos)) {
            $progreso->barrido_completo = true;
        }

        $progreso->mensajes_leidos = ($progreso->mensajes_leidos ?? 0) + count($fechas);
        $progreso->ultima_corrida_en = now();
        $progreso->save();

        return $progreso;
    }

    /**
     * Recorre las páginas de una consulta hasta juntar `$limite` mensajes NUEVOS.
     *
     * Devuelve los mensajes ya con su cuerpo; en `$vistos` deja la fecha de TODOS los ids
     * encontrados —nuevos y ya conocidos—, porque el avance del barrido se calcula sobre
     * todos: si solo contara los nuevos, una tanda entera de conocidos no movería la marca
     * y la corrida siguiente volvería a mirar exactamente lo mismo.
     *
     * @param  array<string, Carbon>  $vistos  se rellena: id => fecha del mensaje
     * @param  array<string, true>  $enEstaTanda  ids ya recogidos por la otra ventana
     * @return array<int, array<string, mixed>>
     */
    private function recorrer(
        string $consulta,
        int $limite,
        ?array &$vistos,
        int &$conocidos,
        bool &$topeAlcanzado,
        array &$enEstaTanda,
    ): array {
        $vistos ??= [];
        $mensajes = [];
        $token = null;
        $paginas = 0;

        do {
            $pagina = $this->gmail->idsDeCobros($consulta, $token, 100);
            $paginas++;

            if ($pagina['ids'] === []) {
                break;
            }

            // Una sola consulta para saber cuáles ya están: bajar cuerpos para descartarlos
            // después es lo que hacía que el solape se comiera la corrida.
            $registrados = CobroCorreo::whereIn('gmail_message_id', $pagina['ids'])
                ->pluck('fecha_mensaje', 'gmail_message_id');

            foreach ($pagina['ids'] as $id) {
                // Ya lo trajo la otra ventana de ESTA corrida: se cuenta para el avance,
                // pero no se baja otra vez ni se devuelve dos veces.
                if (isset($enEstaTanda[$id])) {
                    continue;
                }

                if ($registrados->has($id)) {
                    $conocidos++;
                    $fecha = $registrados->get($id);
                    $vistos[$id] = $fecha instanceof Carbon ? $fecha : ($fecha ? Carbon::parse($fecha) : now());

                    continue;
                }

                if (count($mensajes) >= $limite) {
                    // Hay más para procesar y no cabe en esta corrida. NO se marca el
                    // barrido como completo y se avisa: la siguiente sigue por acá.
                    $topeAlcanzado = true;

                    return $mensajes;
                }

                $mensaje = $this->gmail->mensajeCobro($id);
                $mensajes[] = $mensaje;
                $vistos[$id] = $this->fecha($mensaje) ?? now();
                $enEstaTanda[$id] = true;
            }

            $token = $pagina['siguiente'];
        } while (filled($token) && count($mensajes) < $limite && $paginas < self::PAGINAS_MAX);

        if (filled($token) && count($mensajes) >= $limite) {
            $topeAlcanzado = true;
        }

        return $mensajes;
    }

    /**
     * ¿La cola llegó al principio del buzón?
     *
     * Sí cuando la ventana no devolvió NADA estrictamente más viejo que la marca. No basta
     * con «no devolvió nada»: la consulta usa `before:{marca + 1 segundo}` para no perder
     * los mensajes que comparten segundo con la marca, así que la ÚLTIMA tanda siempre
     * devuelve al menos el mensaje del borde. Tomar eso por «todavía queda» dejaría el
     * barrido sin terminar nunca, dando vueltas sobre el mismo correo.
     *
     * Y al revés: dar por terminado en cuanto una tanda no trae mensajes NUEVOS sería
     * confundir «ya lo leí» con «no hay más», que es justo lo que no puede hacer este
     * módulo.
     *
     * @param  array<string, Carbon>  $vistosCola
     */
    private function colaAgotada(CobroCorreoProgreso $progreso, array $vistosCola): bool
    {
        if ($vistosCola === []) {
            return true;
        }

        $marca = $progreso->barrido_hasta;

        if ($marca === null) {
            // Primera corrida: si trajo algo, queda buzón por recorrer.
            return false;
        }

        foreach ($vistosCola as $fecha) {
            if ($fecha->lt($marca)) {
                return false;
            }
        }

        return true;
    }

    /**
     * La consulta de la CABEZA. La primera vez no hay marca y se busca sin `after:`: la
     * primera corrida barre desde lo más nuevo, que es lo correcto.
     */
    private function consultaCabeza(string $consulta, CobroCorreoProgreso $progreso): string
    {
        if ($progreso->ultimo_mensaje_en === null) {
            return $consulta;
        }

        $desde = $progreso->ultimo_mensaje_en->copy()->subHours(self::SOLAPE_HORAS);

        // Epoch en segundos: `after:2026/09/17` es de día entero y se lleva por delante
        // todo lo que entró esa misma jornada.
        return trim($consulta.' after:'.$desde->getTimestamp());
    }

    /**
     * La consulta de la COLA. Sin marca todavía, es la consulta pelada: la primera corrida
     * arranca el barrido desde lo más nuevo hacia atrás.
     */
    private function consultaCola(string $consulta, CobroCorreoProgreso $progreso): string
    {
        if ($progreso->barrido_hasta === null) {
            return $consulta;
        }

        // +1 segundo para incluir el borde: si varios mensajes comparten el mismo segundo,
        // volver a verlos no cuesta nada (son ids conocidos) y perderlos sí.
        return trim($consulta.' before:'.($progreso->barrido_hasta->getTimestamp() + 1));
    }

    /** @param array<string, mixed> $mensaje */
    private function fecha(array $mensaje): ?Carbon
    {
        if (blank($mensaje['fecha'] ?? null)) {
            return null;
        }

        return rescue(fn () => Carbon::parse((string) $mensaje['fecha']), null, false);
    }
}
