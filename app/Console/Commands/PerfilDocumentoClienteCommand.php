<?php

namespace App\Console\Commands;

use App\Enums\ModoPapelFisico;
use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Consulta y configura el PERFIL DE DOCUMENTOS de un cliente: si exige albarán en sus
 * notas de crédito, con qué código de proveedor y formato se le exporta, y a qué código
 * externo y regla de descuento corresponde cada modalidad.
 *
 * Sin argumentos de escritura solo MUESTRA, para que consultar sea inofensivo. Los
 * mapeos se declaran como `modalidad:CODIGO:origen[:tasa]`, por ejemplo:
 *
 *   php artisan perfil-documento:cliente 8 --activar \
 *       --codigo-proveedor=001065 --formato=albaran_nc_v1 --exige-albaran \
 *       --mapear=averia:AC02:ccf \
 *       --mapear=devolucion_producto:AC04:ninguno
 *
 * Es un comando de configuración: no emite, no firma, no transmite y no recalcula ningún
 * documento ya existente. Los documentos en borrador toman la regla nueva la próxima vez
 * que se recalculan; los ya generados son inmutables y no se tocan.
 */
class PerfilDocumentoClienteCommand extends Command
{
    protected $signature = 'perfil-documento:cliente
        {cliente : ID o código del cliente}
        {--activar : Crea el perfil si no existe y lo deja activo}
        {--desactivar : Deja de aplicar el perfil sin borrar su configuración}
        {--codigo-proveedor= : Código que el cliente asigna al emisor (columna A del archivo)}
        {--formato= : Slug del formato de exportación}
        {--exige-albaran : La NC no se podrá generar sin los datos del albarán}
        {--no-exige-albaran : Deja de exigirlo}
        {--papel-fisico= : Qué hacer si el CCF físico firmado no regresó: bloquear | advertir | no_requerir}
        {--tolerancia= : Diferencia tolerada contra el albarán antes de avisar}
        {--mapear=* : modalidad:CODIGO:origen[:tasa] (repetible)}
        {--olvidar-mapeo=* : modalidad a quitar del mapeo (repetible)}';

    protected $description = 'Muestra o configura el perfil de documentos de un cliente (albarán en NC, mapeo de modalidades y formato de exportación).';

    public function handle(): int
    {
        $cliente = $this->resolverCliente();

        if ($cliente === null) {
            $this->error('No se encontró ese cliente (se busca por ID o por código).');

            return self::FAILURE;
        }

        if (! $this->hayCambios()) {
            return $this->mostrar($cliente);
        }

        try {
            DB::transaction(fn () => $this->aplicar($cliente));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Perfil actualizado.');

        return $this->mostrar($cliente->refresh());
    }

    private function resolverCliente(): ?Cliente
    {
        $clave = (string) $this->argument('cliente');

        return Cliente::query()
            ->when(ctype_digit($clave), fn ($q) => $q->whereKey((int) $clave), fn ($q) => $q->where('codigo', $clave))
            ->first();
    }

    private function hayCambios(): bool
    {
        foreach (['activar', 'desactivar', 'exige-albaran', 'no-exige-albaran'] as $bandera) {
            if ($this->option($bandera)) {
                return true;
            }
        }

        foreach (['codigo-proveedor', 'formato', 'tolerancia', 'papel-fisico'] as $valor) {
            if ($this->option($valor) !== null) {
                return true;
            }
        }

        return $this->option('mapear') !== [] || $this->option('olvidar-mapeo') !== [];
    }

    private function aplicar(Cliente $cliente): void
    {
        $perfil = ClientePerfilDocumento::firstOrNew(['cliente_id' => $cliente->id]);

        if ($this->option('activar')) {
            $perfil->activo = true;
        }
        if ($this->option('desactivar')) {
            $perfil->activo = false;
        }
        if ($this->option('exige-albaran')) {
            $perfil->exige_albaran_en_nc = true;
        }
        if ($this->option('no-exige-albaran')) {
            $perfil->exige_albaran_en_nc = false;
        }
        if (($codigo = $this->option('codigo-proveedor')) !== null) {
            $perfil->codigo_proveedor = $codigo;
        }
        if (($tolerancia = $this->option('tolerancia')) !== null) {
            $perfil->tolerancia_albaran = (float) $tolerancia;
        }
        if (($papel = $this->option('papel-fisico')) !== null) {
            // Un modo mal escrito se detiene acá. Aceptarlo en silencio dejaría el perfil
            // en un estado que nadie declaró y, en el peor caso, sin el bloqueo que el
            // cliente sí exige.
            $perfil->modo_papel_fisico = ModoPapelFisico::tryFrom((string) $papel)
                ?? throw new \InvalidArgumentException(
                    "Modo de papel físico desconocido: «{$papel}». Válidos: ".implode(', ', ModoPapelFisico::valores()).'.'
                );
        }
        if (($formato = $this->option('formato')) !== null) {
            // Un slug inexistente se detiene acá y no el día del envío: exportar con un
            // formato que no existe es un error que solo se ve cuando ya hace falta.
            if (! app(ExportadorNcFactory::class)->existe($formato)) {
                throw new \InvalidArgumentException(
                    "El formato «{$formato}» no existe. Disponibles: ".implode(', ', ExportadorNcFactory::slugs()).'.'
                );
            }
            $perfil->formato_export = $formato;
        }

        $perfil->save();

        foreach ($this->option('olvidar-mapeo') as $modalidad) {
            $perfil->tiposNc()->where('tipo_nota_credito', $modalidad)->delete();
        }

        foreach ($this->option('mapear') as $spec) {
            $this->mapear($perfil, (string) $spec);
        }
    }

    /** Interpreta `modalidad:CODIGO:origen[:tasa]`. */
    private function mapear(ClientePerfilDocumento $perfil, string $spec): void
    {
        $partes = explode(':', $spec);

        if (count($partes) < 3) {
            throw new \InvalidArgumentException(
                "Mapeo mal escrito: «{$spec}». Formato esperado: modalidad:CODIGO:origen[:tasa]."
            );
        }

        [$modalidad, $codigo, $origen] = $partes;
        $tasa = $partes[3] ?? null;

        $tipo = TipoNotaCredito::tryFrom($modalidad)
            ?? throw new \InvalidArgumentException(
                "Modalidad desconocida: «{$modalidad}». Válidas: ".implode(', ', array_keys(TipoNotaCredito::opciones())).'.'
            );

        $origenEnum = OrigenDescuentoNc::tryFrom($origen)
            ?? throw new \InvalidArgumentException(
                "Origen de descuento desconocido: «{$origen}». Válidos: ".implode(', ', array_keys(OrigenDescuentoNc::opciones())).'.'
            );

        if ($origenEnum->requiereTasa() && ($tasa === null || ! is_numeric($tasa))) {
            throw new \InvalidArgumentException(
                "El origen «{$origen}» necesita una tasa: modalidad:CODIGO:tasa_propia:5.00."
            );
        }

        ClientePerfilTipoNc::updateOrCreate(
            ['cliente_perfil_documento_id' => $perfil->id, 'tipo_nota_credito' => $tipo->value],
            [
                'codigo_externo' => strtoupper($codigo),
                'descuento_origen' => $origenEnum->value,
                'descuento_tasa' => $origenEnum->requiereTasa() ? (float) $tasa : null,
            ]
        );
    }

    private function mostrar(Cliente $cliente): int
    {
        $perfil = ClientePerfilDocumento::with('tiposNc')->where('cliente_id', $cliente->id)->first();

        $this->line('');
        $this->line("Cliente: <info>{$cliente->nombre}</info> (id {$cliente->id}".($cliente->codigo ? ", código {$cliente->codigo}" : '').')');

        if ($perfil === null) {
            $this->line('Perfil de documentos: <comment>sin configurar</comment> — este cliente se comporta como siempre.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['Activo', $perfil->activo ? 'sí' : 'NO (no se aplica)'],
            ['Código de proveedor', $perfil->codigo_proveedor ?? '—'],
            ['Formato de exportación', $perfil->formato_export ?? '—'],
            ['Exige albarán en la NC', $perfil->exige_albaran_en_nc ? 'sí' : 'no'],
            ['CCF físico para cobrar', $perfil->modoPapelFisico()->label().' — '.$perfil->modoPapelFisico()->detalle()],
            ['Tolerancia contra el albarán', $perfil->tolerancia_albaran],
        ]);

        if ($perfil->tiposNc->isEmpty()) {
            $this->line('Sin modalidades mapeadas: todas siguen el criterio histórico.');

            return self::SUCCESS;
        }

        $this->table(
            ['Modalidad', 'Código externo', 'Origen del descuento', 'Tasa'],
            $perfil->tiposNc->map(fn (ClientePerfilTipoNc $t) => [
                $t->tipo_nota_credito?->label() ?? '—',
                $t->codigo_externo,
                $t->descuento_origen->label(),
                $t->descuento_tasa ?? '—',
            ])->all()
        );

        return self::SUCCESS;
    }
}
