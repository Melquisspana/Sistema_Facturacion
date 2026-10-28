<?php

namespace App\Services\Cobros;

use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\Ppq\ArchivoConciliacion;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Busca en el buzón de Compras los TXT de pago que manda el cliente y deja su copia por
 * huella, sin escribir en la base. Aplicarlos es otro paso, con un clic de una persona.
 *
 * Un TXT es de pago si viene de la dirección EXACTA configurada (IMAP busca por subcadena) y
 * su nombre tiene la forma `NNNNNN_AAAAMMDDhhmmss.txt`. El contenido lo valida después el
 * aplicador, como cualquier TXT subido. La llave es la huella del contenido: el mismo TXT en
 * dos correos es uno solo, y «ya aplicado» es que Cobros ya tiene pagos o ajustes con ella.
 */
class TxtPagosDelCorreo
{
    private const NOMBRE = '/^\d{6}_\d{14}\.txt$/i';

    public function __construct(private readonly MailboxClient $buzon) {}

    /**
     * @return array<int, array{nombre: string, hash: string, estado: string, fecha: ?Carbon, asunto: ?string, archivo: ArchivoConciliacion}>
     *
     * @throws BuzonInaccesibleException función apagada o buzón sin configurar
     */
    public function buscar(): array
    {
        $remitente = mb_strtolower(trim((string) config('cobros.txt_correo.remitente', '')));
        if ($remitente === '') {
            throw new BuzonInaccesibleException('La búsqueda de TXT por correo está apagada: falta COBROS_TXT_REMITENTE.');
        }
        if (! $this->buzon->disponible()) {
            throw new BuzonInaccesibleException('El buzón de compras no está disponible. Revisá Configuración > Integraciones > Documentos recibidos.');
        }

        $mensajes = $this->buzon->adjuntosDeRemitente(
            $remitente,
            today()->subDays((int) config('cobros.txt_correo.dias', 60)),
            ['txt'],
            // Los TXT llegan a otra carpeta que los DTE de Compras: se abre la suya, no la de Compras.
            trim((string) config('cobros.txt_correo.carpeta', 'INBOX')) ?: 'INBOX',
        );
        // Por UID ascendente: si el mismo TXT llegó dos veces, queda el correo más viejo.
        usort($mensajes, fn ($a, $b) => (int) $a['uid'] <=> (int) $b['uid']);

        $encontrados = [];
        foreach ($mensajes as $mensaje) {
            $from = (string) ($mensaje['remitente'] ?? '');
            $direccion = preg_match('/<([^<>]+)>/', $from, $match) ? $match[1] : $from;
            if (mb_strtolower(trim($direccion)) !== $remitente) {
                continue;
            }
            foreach ($mensaje['adjuntos'] as $adjunto) {
                $nombre = (string) $adjunto['filename'];
                $hash = hash('sha256', (string) $adjunto['data']);
                if (! preg_match(self::NOMBRE, $nombre) || isset($encontrados[$hash])) {
                    continue;
                }
                $encontrados[$hash] = [
                    'nombre' => $nombre,
                    'hash' => $hash,
                    'estado' => app(EvidenciaEntreCircuitos::class)->enCobros($hash) !== null ? 'ya_aplicado' : 'nuevo',
                    'fecha' => $this->fecha($mensaje['fecha'] ?? null),
                    'asunto' => $mensaje['asunto'] ?? null,
                    // La copia se guarda al revisar: es lo que «Aplicar» vuelve a cargar y verifica.
                    'archivo' => ArchivoConciliacion::guardarContenido((string) $adjunto['data'], $nombre),
                ];
            }
        }

        return array_values($encontrados);
    }

    private function fecha(?string $valor): ?Carbon
    {
        try {
            return filled($valor) ? Carbon::parse($valor) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
