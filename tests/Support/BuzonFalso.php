<?php

namespace Tests\Support;

use App\Exceptions\DocumentosRecibidos\BuzonException;
use App\Services\DocumentosRecibidos\Buzon\EstadoBuzon;
use App\Services\DocumentosRecibidos\Buzon\PaginaMensajes;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ImapMailboxClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Buzón IMAP falso, con la MISMA semántica que {@see ImapMailboxClient}:
 * ventana cerrada por día, orden de UID ASCENDENTE, cursor `$desdeUid` estricto y
 * `truncada` cuando quedan más UID de los que entran en la página.
 *
 * No conecta a nada. Sirve para probar lo que de verdad importa —que un día truncado no
 * avance la marca, que reanudar no repita, que un error no se disfrace de éxito— sin
 * tocar el buzón real.
 */
class BuzonFalso implements MailboxClient
{
    /** @var array<int, array<string, mixed>> */
    private array $mensajes = [];

    private ?BuzonException $falla = null;

    /** Cuántas llamadas a `mensajesDelDia` faltan para que empiece a fallar. */
    private ?int $fallarDespuesDe = null;

    private int $llamadas = 0;

    /** El día NUNCA se agota: cada página dice que quedan más. */
    private bool $truncadoPermanente = false;

    public function __construct(
        private ?int $uidValidity = 5001,
        private string $carpeta = 'INBOX',
        private bool $disponible = true,
    ) {}

    // ---------- construcción del escenario ----------

    /**
     * Agrega un correo con un JSON de DTE válido.
     *
     * @param  array<string, mixed>  $extra  claves a pisar (message_id, asunto, adjuntos…)
     */
    public function conDte(int $uid, string $fechaCorreo, string $codigo, ?string $fecEmi = null, array $extra = []): self
    {
        $fecha = Carbon::parse($fechaCorreo);

        $this->mensajes[] = $extra + [
            'uid' => $uid,
            'message_id' => '<'.$codigo.'@proveedor.example>',
            'asunto' => 'CCF '.$codigo,
            'remitente' => 'proveedor@example.com',
            'fecha' => $fecha->toRfc2822String(),
            'fecha_obj' => $fecha,
            'adjuntos' => [[
                'filename' => 'dte-'.$codigo.'.json',
                'mime' => 'application/json',
                'data' => (string) json_encode([
                    'identificacion' => [
                        'tipoDte' => '03',
                        'numeroControl' => 'DTE-03-P-'.$codigo,
                        'codigoGeneracion' => $codigo,
                        'fecEmi' => $fecEmi ?? $fecha->toDateString(),
                    ],
                    'emisor' => ['nombre' => 'PROVEEDOR '.$codigo, 'nit' => '06140000000000', 'nrc' => '999999'],
                    'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
                    'resumen' => ['totalPagar' => 100.0],
                ]),
            ]],
        ];

        return $this;
    }

    /** Agrega un correo YA construido (para casos raros: sin Message-ID, sin adjuntos…). */
    public function conMensaje(array $mensaje): self
    {
        $mensaje['fecha_obj'] ??= Carbon::parse((string) ($mensaje['fecha'] ?? 'now'));
        $this->mensajes[] = $mensaje;

        return $this;
    }

    /** El buzón falla siempre, desde la primera lectura. */
    public function queFalla(BuzonException $e): self
    {
        $this->falla = $e;

        return $this;
    }

    /** El buzón funciona `$n` lecturas y después se cae: simula el corte a mitad. */
    public function queFallaDespuesDe(int $n, BuzonException $e): self
    {
        $this->fallarDespuesDe = $n;
        $this->falla = $e;

        return $this;
    }

    /**
     * El buzón siempre dice que quedan más mensajes en el día, pase lo que pase.
     *
     * No es un caso realista de Yahoo: sirve para comprobar la INVARIANTE de que un día
     * que no se pudo agotar nunca se declara completo, sin depender de que la red se
     * caiga en el momento justo.
     */
    public function siempreTruncado(): self
    {
        $this->truncadoPermanente = true;

        return $this;
    }

    /** Cambia el UIDVALIDITY, como si el servidor hubiera reconstruido la carpeta. */
    public function conUidValidity(?int $uidValidity): self
    {
        $this->uidValidity = $uidValidity;

        return $this;
    }

    /** Mueve un correo a otra carpeta: mismo Message-ID, UID distinto. */
    public function moverA(string $carpeta, int $desplazamientoUid = 10000): self
    {
        $this->carpeta = $carpeta;
        foreach ($this->mensajes as $i => $m) {
            $this->mensajes[$i]['uid'] = (int) $m['uid'] + $desplazamientoUid;
        }

        return $this;
    }

    public function llamadas(): int
    {
        return $this->llamadas;
    }

    // ---------- contrato ----------

    public function disponible(): bool
    {
        return $this->disponible;
    }

    public function fuente(): string
    {
        return 'IMAP buzon@prueba';
    }

    public function estado(): EstadoBuzon
    {
        // `estado()` es lo primero que se llama, así que un buzón que "falla siempre"
        // tiene que fallar acá: es donde el sincronizador detecta credenciales malas.
        if ($this->falla !== null && $this->fallarDespuesDe === null) {
            throw $this->falla;
        }

        return new EstadoBuzon($this->carpeta, $this->uidValidity, count($this->mensajes));
    }

    public function mensajesDelDia(CarbonInterface $dia, int $limite, ?int $desdeUid = null): PaginaMensajes
    {
        $this->llamadas++;

        if ($this->falla !== null && ($this->fallarDespuesDe === null || $this->llamadas > $this->fallarDespuesDe)) {
            throw $this->falla;
        }

        $inicio = $dia->copy()->startOfDay();
        $fin = $inicio->copy()->addDay();

        $delDia = array_values(array_filter(
            $this->mensajes,
            fn ($m) => $m['fecha_obj']->gte($inicio) && $m['fecha_obj']->lt($fin)
                && ($desdeUid === null || (int) $m['uid'] > $desdeUid),
        ));

        usort($delDia, fn ($a, $b) => $a['uid'] <=> $b['uid']); // ASCENDENTE, como IMAP

        $truncada = $this->truncadoPermanente || count($delDia) > $limite;
        $pagina = array_slice($delDia, 0, max(1, $limite));

        $ultimoUid = $pagina === [] ? null : (int) $pagina[count($pagina) - 1]['uid'];

        return new PaginaMensajes(
            array_map(fn ($m) => array_diff_key($m, ['fecha_obj' => null]), $pagina),
            $truncada,
            $ultimoUid,
        );
    }
}
