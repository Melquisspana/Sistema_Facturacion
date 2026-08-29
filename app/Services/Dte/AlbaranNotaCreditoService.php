<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\PpqAlbaran;
use App\Support\Dinero;
use App\Support\NumeroAlbaran;
use App\Support\OrdenCompra;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Captura y control del ALBARÁN DE CRÉDITO que origina una nota de crédito.
 *
 * Nada de lo que hace acá toca un valor fiscal. Ese es el punto: el albarán es el
 * documento del CLIENTE y la NC es el documento de HACIENDA; cuando no coinciden, lo
 * correcto es enseñar la diferencia, no maquillarla moviendo un total. Por eso
 * {@see avisos()} devuelve texto para mostrar y jamás ajusta nada.
 *
 * Lo único que sí bloquea es la reutilización de un albarán que ya tiene una NC viva
 * ({@see DteAlbaran::scopeDeNotasVigentes()}), porque eso no es un aviso: es acreditar
 * dos veces la misma mercadería.
 */
class AlbaranNotaCreditoService
{
    public function __construct(private readonly PerfilDocumentoResolver $perfiles) {}

    /**
     * Registra (o reemplaza) el albarán de una NC en borrador.
     *
     * @param  array<string, mixed>  $datos  numero, fecha, total, tipo_codigo?, sala_codigo?
     *
     * @throws DocumentoInmutableException
     * @throws ValidationException
     */
    public function registrar(Dte $nc, array $datos): DteAlbaran
    {
        $this->verificarNcEditable($nc);

        $validado = Validator::make($datos, [
            'numero' => ['required', 'string', 'max:60'],
            'fecha' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'tipo_codigo' => ['nullable', 'string', 'max:10'],
            'sala_codigo' => ['nullable', 'string', 'max:10'],
        ], [], [
            'numero' => 'número de albarán',
            'total' => 'total del albarán',
        ])->validate();

        $piezas = $this->desglosar($nc, $validado);

        $this->verificarNoReutilizado($nc, $piezas['numero_canonico']);

        return DB::transaction(function () use ($nc, $piezas, $validado) {
            $albaran = DteAlbaran::updateOrCreate(
                ['dte_id' => $nc->id],
                $piezas + [
                    'fecha' => $validado['fecha'],
                    // El albarán imprime el abono en negativo; se guarda en positivo para
                    // no arrastrar dos convenios de signo. Ver la migración.
                    'total' => number_format(abs((float) $validado['total']), 2, '.', ''),
                    'ppq_albaran_id' => $this->ppqAlbaranEquivalente($nc, $piezas['numero']),
                ]
            );

            return $albaran->refresh();
        });
    }

    /** Quita el albarán de una NC en borrador. Lo libera para otra nota. */
    public function quitar(Dte $nc): void
    {
        $this->verificarNcEditable($nc);

        $nc->albaran()->delete();
    }

    /**
     * Descompone lo que escribió el operador en tipo / sala / número / canónico.
     *
     * Acepta tres formas, de la más completa a la más cómoda: el número canónico
     * (AC04/0033/00/3209), el nombre del archivo que manda el cliente
     * (26-04-0045-00-002270-AC02-0001.PDF) y el número suelto (3209), en cuyo caso el
     * tipo lo pone el mapeo del perfil y la sala, la sucursal del documento.
     *
     * @param  array<string, mixed>  $validado
     * @return array{numero_canonico: string, tipo_codigo: string, sala_codigo: ?string, numero: string}
     *
     * @throws ValidationException
     */
    private function desglosar(Dte $nc, array $validado): array
    {
        $regla = $this->perfiles->reglaNotaCredito($nc);
        $parseado = NumeroAlbaran::desde($validado['numero']);

        $tipo = strtoupper(trim((string) ($validado['tipo_codigo'] ?? '')))
            ?: ($parseado?->tipo ?? strtoupper((string) $regla?->codigo_externo));

        if ($tipo === '') {
            throw ValidationException::withMessages([
                'numero' => 'No se pudo determinar el tipo de albarán. Escriba el número completo '
                    .'(por ejemplo AC04/0033/00/3209) o configure el mapeo de esta modalidad en el perfil del cliente.',
            ]);
        }

        // El tipo declarado por el perfil manda: capturar un AC02 en una devolución es un
        // error de operación que conviene detener acá y no descubrirlo en el Excel.
        $esperado = strtoupper((string) $regla?->codigo_externo);
        if ($esperado !== '' && $tipo !== $esperado) {
            throw ValidationException::withMessages([
                'numero' => "Este albarán es de tipo {$tipo}, pero una nota de crédito por "
                    .mb_strtolower($nc->tipo_nota_credito?->label() ?? 'esta modalidad')
                    ." corresponde a un albarán {$esperado}.",
            ]);
        }

        $numero = $parseado?->numero ?? ltrim(trim((string) $validado['numero']), '0');
        if ($numero === '' || preg_match('/^\d{1,10}$/', $numero) !== 1) {
            throw ValidationException::withMessages([
                'numero' => 'El número de albarán no se reconoce. Use el número completo '
                    .'(AC04/0033/00/3209), el nombre del archivo PDF, o solo los dígitos del correlativo.',
            ]);
        }

        $sala = trim((string) ($validado['sala_codigo'] ?? '')) ?: ($parseado?->sala ?? $this->salaDe($nc));

        return [
            'numero_canonico' => $parseado?->canonico ?? ($tipo.'/'.($sala ?? '0000').'/00/'.$numero),
            'tipo_codigo' => $tipo,
            'sala_codigo' => $sala,
            'numero' => $numero,
        ];
    }

    /** Código de sala del documento: el de la sucursal, o el que va dentro de la OC. */
    private function salaDe(Dte $nc): ?string
    {
        $nc->loadMissing('clienteSucursal');

        return $nc->clienteSucursal?->codigo ?: OrdenCompra::salaDesde($nc->numero_orden_compra);
    }

    /**
     * El mismo albarán no puede originar dos notas de crédito.
     *
     * "Vive" se decide con el MISMO criterio que el saldo acreditable: un borrador
     * eliminado, una NC invalidada o una rechazada-archivada liberan el albarán, porque
     * ninguna de las tres va a llegar nunca a Hacienda. Se acota al cliente porque el
     * correlativo de albarán lo lleva cada cadena por su cuenta.
     *
     * @throws ValidationException
     */
    private function verificarNoReutilizado(Dte $nc, string $canonico): void
    {
        $enUso = DteAlbaran::query()
            ->where('numero_canonico', $canonico)
            ->where('dte_id', '!=', $nc->id)
            ->whereHas('dte', fn ($d) => $d->where('cliente_id', $nc->cliente_id)->consumeSaldoAcreditable())
            ->with('dte:id,numero_control,numero_interno,estado')
            ->first();

        if ($enUso === null) {
            return;
        }

        $otra = $enUso->dte;
        $referencia = $otra?->numero_control ?? $otra?->numero_interno ?? ('#'.$otra?->id);

        throw ValidationException::withMessages([
            'numero' => "El albarán {$canonico} ya originó la nota de crédito {$referencia} "
                .'('.($otra?->estado->label() ?? 'en curso').'). Un mismo albarán no puede acreditarse dos veces.',
        ]);
    }

    /** Albarán del módulo PPQ con ese número, si ya fue ingresado por correo. */
    private function ppqAlbaranEquivalente(Dte $nc, string $numero): ?int
    {
        return PpqAlbaran::query()
            ->where('numero_albaran', $numero)
            ->orWhere('numero_albaran', 'like', '%/'.$numero)
            ->value('id');
    }

    /**
     * Comparación entre el total FISCAL de la nota y el total impreso en el albarán.
     * Null si la NC no tiene albarán registrado.
     *
     * @return array{total_nc: string, total_albaran: string, diferencia: string, cuadra: bool}|null
     */
    public function comparacion(Dte $nc): ?array
    {
        $nc->loadMissing('albaran');
        $albaran = $nc->albaran;

        if ($albaran === null || $albaran->total === null) {
            return null;
        }

        $totalNc = Dinero::redondear((string) $nc->total_pagar, 2);
        $totalAlbaran = Dinero::redondear((string) $albaran->total, 2);
        $diferencia = Dinero::redondear(Dinero::restar($totalNc, $totalAlbaran), 2);

        $tolerancia = (string) ($this->perfiles->para($nc)?->tolerancia_albaran ?? '0.00');
        $cuadra = Dinero::comparar($this->absoluto($diferencia), $tolerancia) <= 0;

        return [
            'total_nc' => $totalNc,
            'total_albaran' => $totalAlbaran,
            'diferencia' => $diferencia,
            'cuadra' => $cuadra,
        ];
    }

    private function absoluto(string $valor): string
    {
        return Dinero::comparar($valor, '0') < 0 ? Dinero::restar('0', $valor) : $valor;
    }

    /**
     * Avisos que el operador debe ver —y confirmar— ANTES de generar la nota. Ninguno
     * bloquea por sí solo: son diferencias legítimas que alguien tiene que mirar.
     *
     * @return array<int, array{clave: string, texto: string}>
     */
    public function avisos(Dte $nc): array
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito) {
            return [];
        }

        $avisos = [];

        // Retención: los albaranes de crédito del cliente vienen con "Retención 0.00" y
        // los montos son casi siempre pequeños, así que una NC que sí retiene es una
        // rareza que hay que mirar antes de emitir, no algo que se prohíba a ciegas.
        if ($nc->aplica_retencion_iva) {
            $avisos[] = [
                'clave' => 'retencion',
                'texto' => 'Esta nota de crédito aplica retención de IVA por '.$nc->iva_retenido
                    .'. El total ('.$nc->total_pagar.') va NETO de retención y por eso puede no '
                    .'coincidir con el total del albarán, que no la incluye.',
            ];
        }

        $comparacion = $this->comparacion($nc);
        if ($comparacion !== null && ! $comparacion['cuadra']) {
            $avisos[] = [
                'clave' => 'diferencia',
                'texto' => 'El total de la nota ('.$comparacion['total_nc'].') no coincide con el del albarán ('
                    .$comparacion['total_albaran'].'). Diferencia: '.$comparacion['diferencia'].'.',
            ];
        }

        return $avisos;
    }

    /**
     * ¿Falta el albarán en una NC de un cliente que lo exige? A diferencia de los avisos,
     * esto sí impide generar: no es una diferencia a valorar sino un dato que el cliente
     * declaró obligatorio y sin el cual su Excel no se puede llenar.
     */
    public function faltaAlbaranObligatorio(Dte $nc): bool
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito) {
            return false;
        }

        $perfil = $this->perfiles->para($nc);

        if ($perfil === null || ! $perfil->exige_albaran_en_nc) {
            return false;
        }

        // Solo lo exige en las modalidades que el cliente mapeó a un albarán suyo; un
        // pronto pago no nace de un albarán y no debe quedar bloqueado por esta regla.
        if ($this->perfiles->reglaNotaCredito($nc) === null) {
            return false;
        }

        $nc->loadMissing('albaran');

        return $nc->albaran === null;
    }

    /** @throws DocumentoInmutableException|ValidationException */
    private function verificarNcEditable(Dte $nc): void
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito) {
            throw ValidationException::withMessages([
                'numero' => 'Solo una nota de crédito puede tener un albarán de crédito asociado.',
            ]);
        }

        if (! $nc->esEditable()) {
            throw new DocumentoInmutableException(
                'El documento ya fue generado: no se puede cambiar su albarán.'
            );
        }
    }
}
