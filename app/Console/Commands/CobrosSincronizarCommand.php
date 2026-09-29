<?php

namespace App\Console\Commands;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Cobros\VinculadorAlbaranes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Incorpora al seguimiento de cobros los CCF/NC que Hacienda ya aceptó.
 *
 * ═══════════════════ Por qué esto tiene que poder correr solo ═══════════════════
 *
 * El módulo existe para controlar «cada factura, incluidas las que nunca se presentaron».
 * Mientras el alta dependa de que alguien pulse un botón, la factura olvidada es justo la
 * que nunca entra: el sistema no puede avisar de lo que no sabe que existe, y el día que
 * nadie pulse el botón el agujero no se ve por ningún lado.
 *
 * El botón de la pantalla SIGUE existiendo, y no como duplicado: es la recuperación para
 * cuando el planificador estuvo caído, para cuando hace falta el resultado ahora mismo, y
 * para el servidor donde la automática está apagada a propósito.
 *
 * ═══════════════════════ Lo que NO toca ═══════════════════════
 *
 * Solo LEE `dtes` —los ya aceptados— y escribe en `cobro_documentos`. No emite, no firma,
 * no transmite, no cambia un estado fiscal y no bloquea ninguna fila que la emisión
 * necesite. Una corrida a mitad de una facturación no la estorba.
 *
 * Es IDEMPOTENTE: repetirla no crea nada nuevo ni pisa ningún estado.
 *
 * ═══════════════════════ Dry-run por defecto ═══════════════════════
 *
 * Sin `--aplicar` enumera lo que haría y no escribe una fila. Es el paso previo con el que
 * se comprueba, sobre los datos reales, cuántos documentos entrarían y cuántos quedarían
 * marcados para revisión histórica ANTES de dejar que corra solo.
 *
 * ═══════════════════════ `--vincular`: otra llave, aparte ═══════════════════════
 *
 * `--vincular` audita (y con `--aplicar` + `cobros.vinculacion.automatica` encendida,
 * escribe) los albaranes de entrega que {@see VinculadorAlbaranes} decida ÚNICOS y sin
 * contradicciones para los CCF de este cliente. Es una llave DISTINTA de la del alta a
 * propósito: dar de alta un CCF y unirle un albarán son afirmaciones de distinto peso, y la
 * segunda no debe encenderse sola por encender la primera. Sin esa llave, `--vincular
 * --aplicar` audita igual mostrando qué pasaría, pero no escribe nada.
 */
class CobrosSincronizarCommand extends Command
{
    protected $signature = 'cobros:sincronizar
        {--cliente= : ID del cliente (por defecto, el único con perfil documental activo)}
        {--vincular : Además, audita/aplica la vinculación de albaranes que salga ÚNICA (con --aplicar exige COBROS_VINCULACION_AUTO)}
        {--aplicar : Escribe de verdad. Sin esto solo enumera lo que haría}';

    protected $description = 'Incorpora al seguimiento de cobros los CCF/NC aceptados por Hacienda (no emite ni transmite nada)';

    public function handle(AltaCobrosService $alta, VinculadorAlbaranes $vinculador): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if ($aplicar && ! config('cobros.alta.automatica')) {
            $this->error('El alta automática de cobros está apagada (COBROS_ALTA_AUTO=false). '
                .'No se escribió nada. Ver config/cobros.php.');

            return self::FAILURE;
        }

        $clientes = $this->clientes();

        if ($clientes->isEmpty()) {
            $this->error('No se pudo determinar el cliente. Pasá --cliente=ID.');

            return self::FAILURE;
        }

        foreach ($clientes as $cliente) {
            $this->line("Cliente: {$cliente->nombre} (id {$cliente->id})");

            if (! $aplicar) {
                $this->enSeco($cliente, $alta, $vinculador);

                continue;
            }

            $resumen = $alta->sincronizar($cliente);
            $historicos = $alta->marcarRevisionHistorica($cliente);

            $this->info(sprintf(
                '  %d nuevo(s) · %d adoptado(s) · %d sin cambio · %d marcado(s) para revisión histórica.',
                $resumen['creados'],
                $resumen['adoptados'],
                $resumen['sin_cambio'],
                $resumen['revision_historica'] + $historicos,
            ));

            if ($this->option('vincular')) {
                $this->info('  '.$this->vincular($cliente, $vinculador));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Enumera sin escribir. Cuenta lo que haría consultando lo mismo que el alta, sin
     * crear ninguna fila.
     */
    private function enSeco(Cliente $cliente, AltaCobrosService $alta, VinculadorAlbaranes $vinculador): void
    {
        $previsto = $alta->previsualizar($cliente);

        $this->table(
            ['Entrarían nuevos', 'Se adoptarían', 'Ya estaban', 'Con antecedente en PPQ'],
            [[
                $previsto['creados'],
                $previsto['adoptados'],
                $previsto['sin_cambio'],
                $previsto['revision_historica'],
            ]],
        );

        if ($this->option('vincular')) {
            $this->line(sprintf(
                '  Albaranes de CCF invalidados retirables: %d se liberarían con la vinculación automática encendida.',
                $this->invalidadosConAlbaranRetirable($cliente)->count(),
            ));

            // AUDITA sobre TODO lo pendiente, no solo una tanda: chunkById recorre el total
            // sin cargarlo entero en memoria, así que un cliente con más de 500 CCF sin
            // albarán no deja los últimos sin comprobar por un límite fijo.
            $resumen = ['vinculado' => 0, 'sin_albaran' => 0, 'revisar' => 0];

            $this->documentosPendientesDeAlbaran($cliente)
                ->chunkById(200, function (Collection $documentos) use ($vinculador, &$resumen) {
                    foreach ($documentos as $documento) {
                        $resumen[$vinculador->auditar($documento)['estado']->value]++;
                    }
                });

            $this->line(sprintf(
                '  Vinculación: %d se vincularían, %d quedarían para revisar, %d sin albarán.',
                $resumen['vinculado'],
                $resumen['revisar'],
                $resumen['sin_albaran'],
            ));

            if (! config('cobros.vinculacion.automatica')) {
                $this->warn('  La vinculación automática está apagada (COBROS_VINCULACION_AUTO=false); '
                    .'con --aplicar tampoco escribiría ningún vínculo. Ver config/cobros.php.');
            }
        }

        $this->warn('  ENSAYO EN SECO: no se escribió nada. Agregá --aplicar cuando el resultado sea el esperado.');
    }

    /**
     * Aplica la vinculación SOLO si la llave `cobros.vinculacion.automatica` está encendida.
     * Escribe únicamente los vínculos ÚNICOS y sin contradicciones; los ambiguos quedan
     * marcados «revisar» con su motivo, nunca vinculados por parecido.
     *
     * Recorre TODO lo pendiente por `chunkById`, no una tanda fija: así un cliente con más
     * de 500 CCF sin albarán no queda siempre atrapado en los primeros 500. Y usa
     * `soloSiCambia` porque un documento que sigue «revisar» o «sin albarán» vuelve a
     * entrar en cada corrida (`ppq_albaran_id` sigue null): sin eso reescribiría
     * `vinculado_en` cada hora aunque el veredicto no cambiara.
     */
    private function vincular(Cliente $cliente, VinculadorAlbaranes $vinculador): string
    {
        if (! config('cobros.vinculacion.automatica')) {
            return 'Vinculación automática apagada (COBROS_VINCULACION_AUTO=false); no se vinculó ningún '
                .'albarán. Auditá en seco (sin --aplicar) y revisá docs/COBROS_CALLEJA.md antes de encenderla.';
        }

        $liberados = $this->liberarAlbaranesInvalidados($cliente);
        $this->info(sprintf('  Albaranes liberados de CCF invalidados: %d.', $liberados));

        $conteo = ['vinculado' => 0, 'revisar' => 0, 'sin_albaran' => 0];

        $this->documentosPendientesDeAlbaran($cliente)
            ->chunkById(200, function (Collection $documentos) use ($vinculador, &$conteo) {
                foreach ($documentos as $documento) {
                    $conteo[$vinculador->aplicar($documento, soloSiCambia: true)->value]++;
                }
            });

        return sprintf(
            'Vinculación: %d vinculado(s), %d para revisar, %d sin albarán.',
            $conteo['vinculado'],
            $conteo['revisar'],
            $conteo['sin_albaran'],
        );
    }

    /**
     * CCF de este cliente que todavía no tienen albarán vinculado. Base común del ensayo en
     * seco y de la aplicación real: mismos criterios, mismo orden de recorrido.
     *
     * @return Builder<CobroDocumento>
     */
    private function documentosPendientesDeAlbaran(Cliente $cliente): Builder
    {
        return CobroDocumento::deCliente($cliente->id)
            ->where('tipo_dte', '03')
            ->whereDoesntHave('dte', fn ($d) => $d->where('estado', EstadoDte::Invalidado->value))
            ->whereNull('ppq_albaran_id')
            ->with('dte:id,numero_orden_compra,estado');
    }

    /** Solo se retiran vínculos sin presentación en curso ni pago registrado. */
    private function invalidadosConAlbaranRetirable(Cliente $cliente): Builder
    {
        return CobroDocumento::deCliente($cliente->id)->invalidados()
            ->where('pago_estado', EstadoPagoCobro::Pendiente->value)
            ->whereNotIn('presentacion_estado', [
                EstadoPresentacionCobro::Preparada->value,
                EstadoPresentacionCobro::Presentada->value,
                EstadoPresentacionCobro::Recibida->value,
            ])
            ->whereNotNull('ppq_albaran_id');
    }

    /** Libera antes de vincular para que el CCF reemitido pueda tomar la entrega. */
    private function liberarAlbaranesInvalidados(Cliente $cliente): int
    {
        $liberados = 0;
        $this->invalidadosConAlbaranRetirable($cliente)
            ->chunkById(200, function (Collection $documentos) use ($cliente, &$liberados) {
                foreach ($documentos as $documento) {
                    $liberados += DB::transaction(function () use ($cliente, $documento) {
                        // Revalida la condición bajo bloqueo: pudo entrar a un PPQ entretanto.
                        $documento = $this->invalidadosConAlbaranRetirable($cliente)
                            ->whereKey($documento->id)->lockForUpdate()->first();
                        if ($documento === null) {
                            return 0;
                        }

                        $albaranId = $documento->ppq_albaran_id;
                        PpqAlbaran::whereKey($albaranId)->lockForUpdate()->firstOrFail();
                        $documento->forceFill([
                            'ppq_albaran_id' => null,
                            'vinculacion_estado' => EstadoVinculacionAlbaran::SinAlbaran->value,
                            'vinculacion_motivo' => 'Albarán liberado: el CCF fue invalidado en Hacienda.',
                            'vinculado_en' => now(),
                        ])->save();

                        activity('cobros_vinculacion')->performedOn($documento)
                            ->withProperties(['albaran_id' => $albaranId])
                            ->log('liberó el albarán de un CCF invalidado en Hacienda');

                        return 1;
                    }, 3);
                }
            });

        return $liberados;
    }

    /** @return Collection<int, Cliente> */
    private function clientes(): Collection
    {
        if ($this->option('cliente')) {
            return Cliente::whereKey((int) $this->option('cliente'))->get();
        }

        $candidatos = Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))
            ->get(['id', 'nombre']);

        // Con más de uno no se elige por el agente: se pide el id. Dar de alta el
        // seguimiento del cliente equivocado mezclaría cobros de dos cadenas.
        return $candidatos->count() === 1 ? $candidatos : collect();
    }
}
