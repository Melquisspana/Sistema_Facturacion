<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\LineaCalculada;
use App\DataTransferObjects\Dte\LineaDocumento;
use App\DataTransferObjects\Dte\ResultadoCalculo;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\CalculoNoSoportadoException;
use App\Exceptions\Dte\DescuentoInvalidoException;
use App\Support\Dinero;

/**
 * Calculadora pura del DTE: recibe líneas (DTOs) y devuelve totales. No depende
 * de Eloquent ni de base de datos, para poder probarla exhaustivamente.
 *
 * PASO 3A/3B: CCF con líneas gravadas, exentas y no sujetas (IVA separado del
 * precio; IVA solo sobre lo gravado), con descuento por línea.
 * Lo demás (factura, exportación, descuento global, retención) se irá
 * habilitando en sub-pasos posteriores.
 */
class CalculadoraDte
{
    /** Tasa de IVA centralizada en config/dte.php (ej. 0.13). */
    private string $tasaIva;

    /** Divisor para separar IVA incluido (1 + tasa, ej. 1.13). */
    private string $divisorIva;

    /** Tasa de retención de IVA (CCF a agente de retención, ej. 0.01). */
    private string $tasaRetencion;

    public function __construct()
    {
        $this->tasaIva = Dinero::de(config('dte.iva_tasa', 0.13));
        $this->divisorIva = Dinero::sumar('1', $this->tasaIva);
        $this->tasaRetencion = Dinero::de(config('dte.retencion_iva_tasa', 0.01));
    }

    /**
     * @param  array<int, LineaDocumento>  $lineas
     *
     * @throws CalculoNoSoportadoException
     */
    public function calcular(
        array $lineas,
        TipoDte $tipoDte,
        string|int|float $descuentoGlobal = 0,
        string|int|float $flete = 0,
        string|int|float $seguro = 0,
        bool $aplicaRetencion = false,
    ): ResultadoCalculo {
        $descuento = Dinero::de($descuentoGlobal);

        return match ($tipoDte) {
            // La Nota de crédito (05) calcula igual que el CCF: IVA separado del
            // precio, solo sobre lo gravado. (Sin retención en este alcance.)
            TipoDte::CreditoFiscal,
            TipoDte::NotaCredito => $this->calcularCreditoFiscal($lineas, $descuento, $aplicaRetencion),
            // Factura (01) y exportación (11) NO aplican retención aunque se solicite.
            TipoDte::Factura => $this->calcularFactura($lineas, $descuento),
            TipoDte::FacturaExportacion => $this->calcularFacturaExportacion(
                $lineas,
                $descuento,
                Dinero::de($flete),
                Dinero::de($seguro),
            ),
            default => throw new CalculoNoSoportadoException(
                "Tipo {$tipoDte->label()} aún no soportado por la calculadora (llega en un sub-paso posterior)."
            ),
        };
    }

    /**
     * CCF (03): el precio NO incluye IVA; el IVA se calcula aparte y SOLO sobre
     * lo gravado. Cada línea cae en su bucket según tipo_impuesto. El IVA del
     * resumen se calcula sobre el TOTAL gravado (forma correcta del MH), no como
     * suma de los IVA por línea, para que el redondeo cuadre.
     */
    private function calcularCreditoFiscal(array $lineas, string $descuentoGlobal, bool $aplicaRetencion = false): ResultadoCalculo
    {
        if ($lineas === []) {
            throw new CalculoNoSoportadoException('El documento debe tener al menos una línea.');
        }

        $lineasCalculadas = [];
        $totalGravado = '0';
        $totalExento = '0';
        $totalNoSujeto = '0';

        foreach ($lineas as $linea) {
            // importe = cantidad * precio − descuento de línea (todo a 2 decimales)
            $importe = Dinero::multiplicar($linea->cantidad, $linea->precioUnitario);
            $base = Dinero::redondear(Dinero::restar($importe, $linea->descuentoMonto), 2);

            $ventaGravada = '0.00';
            $ventaExenta = '0.00';
            $ventaNoSujeta = '0.00';
            $ivaLinea = '0.00';

            switch ($linea->tipoImpuesto) {
                case TipoImpuesto::Gravado:
                    $ventaGravada = $base;
                    $ivaLinea = Dinero::redondear(Dinero::multiplicar($base, $this->tasaIva), 2);
                    $totalGravado = Dinero::sumar($totalGravado, $base);
                    break;
                case TipoImpuesto::Exento:
                    $ventaExenta = $base;
                    $totalExento = Dinero::sumar($totalExento, $base);
                    break;
                case TipoImpuesto::NoSujeto:
                    $ventaNoSujeta = $base;
                    $totalNoSujeto = Dinero::sumar($totalNoSujeto, $base);
                    break;
            }

            // En CCF el total de la línea solo suma IVA en las gravadas.
            $totalLinea = Dinero::redondear(Dinero::sumar($base, $ivaLinea), 2);

            $lineasCalculadas[] = new LineaCalculada(
                ventaGravada: $ventaGravada,
                ventaExenta: $ventaExenta,
                ventaNoSujeta: $ventaNoSujeta,
                ivaLinea: $ivaLinea,
                totalLinea: $totalLinea,
            );
        }

        $totalGravado = Dinero::redondear($totalGravado, 2);
        $totalExento = Dinero::redondear($totalExento, 2);
        $totalNoSujeto = Dinero::redondear($totalNoSujeto, 2);

        // Subtotal bruto (suma de ventas antes del descuento global).
        $subtotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($totalGravado, $totalExento), $totalNoSujeto),
            2
        );

        // Descuento global prorrateado entre los buckets (suma exacta).
        [$descGravado, $descExento, $descNoSujeto] = $this->prorratearDescuentoGlobal(
            $descuentoGlobal,
            ['gravado' => $totalGravado, 'exento' => $totalExento, 'no_sujeto' => $totalNoSujeto],
            $subtotal,
        );
        $descuentoTotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($descGravado, $descExento), $descNoSujeto),
            2
        );

        // El IVA solo se calcula sobre lo gravado, DESPUÉS del descuento.
        $gravadoNeto = Dinero::redondear(Dinero::restar($totalGravado, $descGravado), 2);
        $ivaTotal = Dinero::redondear(Dinero::multiplicar($gravadoNeto, $this->tasaIva), 2);

        // total antes de retención = (subtotal − descuento global) + IVA
        $totalAntesRetencion = Dinero::redondear(
            Dinero::sumar(Dinero::restar($subtotal, $descuentoTotal), $ivaTotal),
            2
        );

        // Retención de IVA: solo si aplica, sobre el gravado NETO (sin IVA), y se
        // RESTA del total a pagar. Sin base gravada → retención 0.00.
        $baseRetencion = '0.00';
        $retencionIva = '0.00';
        $porcentajeRetencion = '0.00';
        if ($aplicaRetencion) {
            $porcentajeRetencion = Dinero::redondear(Dinero::multiplicar($this->tasaRetencion, '100'), 2);
            $baseRetencion = $gravadoNeto;
            $retencionIva = Dinero::redondear(Dinero::multiplicar($baseRetencion, $this->tasaRetencion), 2);
        }

        $totalPagar = Dinero::redondear(Dinero::restar($totalAntesRetencion, $retencionIva), 2);

        return new ResultadoCalculo(
            lineas: $lineasCalculadas,
            subtotal: $subtotal,
            totalGravado: $totalGravado,
            totalExento: $totalExento,
            totalNoSujeto: $totalNoSujeto,
            descuentoGravado: $descGravado,
            descuentoExento: $descExento,
            descuentoNoSujeto: $descNoSujeto,
            descuentoTotal: $descuentoTotal,
            ivaTotal: $ivaTotal,
            totalPagar: $totalPagar,
            aplicaRetencion: $aplicaRetencion,
            porcentajeRetencion: $porcentajeRetencion,
            baseRetencion: $baseRetencion,
            retencionIva: $retencionIva,
            totalAntesRetencion: $totalAntesRetencion,
        );
    }

    /**
     * Factura consumidor final (01): el precio gravado YA INCLUYE IVA.
     * - El descuento (línea y global) reduce el importe CON IVA.
     * - La base y el IVA se separan DESPUÉS del descuento: base = importe/1.13.
     * - El IVA no se suma aparte; total_pagar es el neto con IVA dentro.
     * Los buckets se reportan NETOS de descuento global (base sin IVA en gravado).
     */
    private function calcularFactura(array $lineas, string $descuentoGlobal): ResultadoCalculo
    {
        if ($lineas === []) {
            throw new CalculoNoSoportadoException('El documento debe tener al menos una línea.');
        }

        $lineasCalculadas = [];
        $gravadoConIva = '0';
        $exentoTotal = '0';
        $noSujetoTotal = '0';

        foreach ($lineas as $linea) {
            // importe = cantidad*precio − descuento de línea (con IVA en gravadas).
            $importe = Dinero::multiplicar($linea->cantidad, $linea->precioUnitario);
            $importe = Dinero::redondear(Dinero::restar($importe, $linea->descuentoMonto), 2);

            $ventaGravada = '0.00';
            $ventaExenta = '0.00';
            $ventaNoSujeta = '0.00';
            $ivaLinea = '0.00';

            switch ($linea->tipoImpuesto) {
                case TipoImpuesto::Gravado:
                    // Separar base/IVA del importe con IVA incluido.
                    $ventaGravada = Dinero::redondear(Dinero::dividir($importe, $this->divisorIva), 2);
                    $ivaLinea = Dinero::redondear(Dinero::restar($importe, $ventaGravada), 2);
                    $gravadoConIva = Dinero::sumar($gravadoConIva, $importe);
                    break;
                case TipoImpuesto::Exento:
                    $ventaExenta = $importe;
                    $exentoTotal = Dinero::sumar($exentoTotal, $importe);
                    break;
                case TipoImpuesto::NoSujeto:
                    $ventaNoSujeta = $importe;
                    $noSujetoTotal = Dinero::sumar($noSujetoTotal, $importe);
                    break;
            }

            $lineasCalculadas[] = new LineaCalculada(
                ventaGravada: $ventaGravada,
                ventaExenta: $ventaExenta,
                ventaNoSujeta: $ventaNoSujeta,
                ivaLinea: $ivaLinea,
                totalLinea: $importe, // con IVA incluido
            );
        }

        $gravadoConIva = Dinero::redondear($gravadoConIva, 2);
        $exentoTotal = Dinero::redondear($exentoTotal, 2);
        $noSujetoTotal = Dinero::redondear($noSujetoTotal, 2);

        // Subtotal bruto = suma de ventas (con IVA en gravado), antes del descuento global.
        $subtotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($gravadoConIva, $exentoTotal), $noSujetoTotal),
            2
        );

        // Descuento global prorrateado sobre los importes (con IVA en gravado).
        [$descGravado, $descExento, $descNoSujeto] = $this->prorratearDescuentoGlobal(
            $descuentoGlobal,
            ['gravado' => $gravadoConIva, 'exento' => $exentoTotal, 'no_sujeto' => $noSujetoTotal],
            $subtotal,
        );
        $descuentoTotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($descGravado, $descExento), $descNoSujeto),
            2
        );

        // Sobre el gravado NETO (ya con el descuento), separar base e IVA.
        $gravadoNetoConIva = Dinero::restar($gravadoConIva, $descGravado);
        $totalGravado = Dinero::redondear(Dinero::dividir($gravadoNetoConIva, $this->divisorIva), 2);
        $ivaTotal = Dinero::redondear(Dinero::restar($gravadoNetoConIva, $totalGravado), 2);

        $totalExento = Dinero::redondear(Dinero::restar($exentoTotal, $descExento), 2);
        $totalNoSujeto = Dinero::redondear(Dinero::restar($noSujetoTotal, $descNoSujeto), 2);

        // total a pagar = base + IVA (incluido) + exento + no sujeto = subtotal − descuento.
        $totalPagar = Dinero::redondear(
            Dinero::sumar(Dinero::sumar(Dinero::sumar($totalGravado, $ivaTotal), $totalExento), $totalNoSujeto),
            2
        );

        return new ResultadoCalculo(
            lineas: $lineasCalculadas,
            subtotal: $subtotal,
            totalGravado: $totalGravado,
            totalExento: $totalExento,
            totalNoSujeto: $totalNoSujeto,
            descuentoGravado: $descGravado,
            descuentoExento: $descExento,
            descuentoNoSujeto: $descNoSujeto,
            descuentoTotal: $descuentoTotal,
            ivaTotal: $ivaTotal,
            totalPagar: $totalPagar,
        );
    }

    /**
     * Factura de exportación (11): operación SIEMPRE a 0% IVA.
     * - Las ventas gravadas se reportan como VENTA EXPORTADA (bucket aparte); no
     *   se calcula ni se suma IVA (iva_total = 0.00, total_gravado = 0.00).
     * - El descuento (línea y global) reduce la base de exportación.
     * - Flete y seguro son cargos que se SUMAN al total a pagar (no llevan IVA).
     * Se mantiene soporte de exento/no sujeto por si el documento los incluye.
     */
    private function calcularFacturaExportacion(
        array $lineas,
        string $descuentoGlobal,
        string $flete,
        string $seguro,
    ): ResultadoCalculo {
        if ($lineas === []) {
            throw new CalculoNoSoportadoException('El documento debe tener al menos una línea.');
        }

        $flete = Dinero::redondear($flete, 2);
        $seguro = Dinero::redondear($seguro, 2);
        if (Dinero::comparar($flete, '0') < 0 || Dinero::comparar($seguro, '0') < 0) {
            throw new DescuentoInvalidoException('El flete y el seguro no pueden ser negativos.');
        }

        $lineasCalculadas = [];
        $exportacionTotal = '0';
        $exentoTotal = '0';
        $noSujetoTotal = '0';

        foreach ($lineas as $linea) {
            // importe = cantidad*precio − descuento de línea (sin IVA: exportación 0%).
            $importe = Dinero::multiplicar($linea->cantidad, $linea->precioUnitario);
            $importe = Dinero::redondear(Dinero::restar($importe, $linea->descuentoMonto), 2);

            $ventaExportacion = '0.00';
            $ventaExenta = '0.00';
            $ventaNoSujeta = '0.00';

            switch ($linea->tipoImpuesto) {
                case TipoImpuesto::Exento:
                    $ventaExenta = $importe;
                    $exentoTotal = Dinero::sumar($exentoTotal, $importe);
                    break;
                case TipoImpuesto::NoSujeto:
                    $ventaNoSujeta = $importe;
                    $noSujetoTotal = Dinero::sumar($noSujetoTotal, $importe);
                    break;
                default: // Gravado → en exportación se trata como venta exportada 0% IVA.
                    $ventaExportacion = $importe;
                    $exportacionTotal = Dinero::sumar($exportacionTotal, $importe);
                    break;
            }

            $lineasCalculadas[] = new LineaCalculada(
                ventaGravada: '0.00',
                ventaExenta: $ventaExenta,
                ventaNoSujeta: $ventaNoSujeta,
                ivaLinea: '0.00',
                totalLinea: $importe,
                ventaExportacion: $ventaExportacion,
            );
        }

        $exportacionTotal = Dinero::redondear($exportacionTotal, 2);
        $exentoTotal = Dinero::redondear($exentoTotal, 2);
        $noSujetoTotal = Dinero::redondear($noSujetoTotal, 2);

        // Subtotal bruto = ventas (exportación + exento + no sujeto), antes del descuento global.
        $subtotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($exportacionTotal, $exentoTotal), $noSujetoTotal),
            2
        );

        // Descuento global prorrateado entre los buckets (suma exacta).
        $desc = $this->prorratearEntreBuckets(
            $descuentoGlobal,
            ['exportacion' => $exportacionTotal, 'exento' => $exentoTotal, 'no_sujeto' => $noSujetoTotal],
            $subtotal,
        );
        $descuentoTotal = Dinero::redondear(
            Dinero::sumar(Dinero::sumar($desc['exportacion'], $desc['exento']), $desc['no_sujeto']),
            2
        );

        $totalExportacion = Dinero::redondear(Dinero::restar($exportacionTotal, $desc['exportacion']), 2);
        $totalExento = Dinero::redondear(Dinero::restar($exentoTotal, $desc['exento']), 2);
        $totalNoSujeto = Dinero::redondear(Dinero::restar($noSujetoTotal, $desc['no_sujeto']), 2);

        // total a pagar = (subtotal − descuento) + flete + seguro. Sin IVA.
        $totalPagar = Dinero::redondear(
            Dinero::sumar(
                Dinero::sumar(Dinero::restar($subtotal, $descuentoTotal), $flete),
                $seguro
            ),
            2
        );

        return new ResultadoCalculo(
            lineas: $lineasCalculadas,
            subtotal: $subtotal,
            totalGravado: '0.00',     // en exportación no hay venta gravada con IVA
            totalExento: $totalExento,
            totalNoSujeto: $totalNoSujeto,
            descuentoGravado: '0.00',
            descuentoExento: $desc['exento'],
            descuentoNoSujeto: $desc['no_sujeto'],
            descuentoTotal: $descuentoTotal,
            ivaTotal: '0.00',          // exportación SIEMPRE 0% IVA
            totalPagar: $totalPagar,
            totalExportacion: $totalExportacion,
            descuentoExportacion: $desc['exportacion'],
            flete: $flete,
            seguro: $seguro,
        );
    }

    /**
     * Reparte un descuento global (monto fijo) entre los buckets gravado/exento/
     * no_sujeto. Atajo posicional sobre {@see prorratearEntreBuckets()} usado por
     * CCF y Factura.
     *
     * @param  array<string, string>  $bases  ['gravado'=>.., 'exento'=>.., 'no_sujeto'=>..]
     * @return array{0: string, 1: string, 2: string}  [descGravado, descExento, descNoSujeto]
     *
     * @throws DescuentoInvalidoException
     */
    private function prorratearDescuentoGlobal(string $descuentoGlobal, array $bases, string $subtotal): array
    {
        $r = $this->prorratearEntreBuckets($descuentoGlobal, $bases, $subtotal);

        return [$r['gravado'], $r['exento'], $r['no_sujeto']];
    }

    /**
     * Reparte un descuento global (monto fijo) entre los buckets indicados de
     * forma proporcional a su base, redondeando a 2 decimales y asignando el
     * residuo de redondeo al bucket de mayor base, de modo que la suma de los
     * descuentos prorrateados sea EXACTAMENTE el descuento global. Es genérico
     * en las claves de los buckets (gravado/exportación/exento/no_sujeto).
     *
     * @param  array<string, string>  $bases  claves arbitrarias => base
     * @return array<string, string>  mismas claves => descuento prorrateado
     *
     * @throws DescuentoInvalidoException
     */
    private function prorratearEntreBuckets(string $descuentoGlobal, array $bases, string $subtotal): array
    {
        if (Dinero::comparar($descuentoGlobal, '0') < 0) {
            throw new DescuentoInvalidoException('El descuento global no puede ser negativo.');
        }
        if (Dinero::comparar($descuentoGlobal, $subtotal) > 0) {
            throw new DescuentoInvalidoException('El descuento global no puede ser mayor al subtotal.');
        }

        $proporcional = [];
        foreach ($bases as $clave => $base) {
            $proporcional[$clave] = '0.00';
        }

        // Sin descuento o sin base: nada que repartir.
        if (Dinero::comparar($descuentoGlobal, '0') === 0 || Dinero::comparar($subtotal, '0') === 0) {
            return $proporcional;
        }

        $claveMayor = null;
        $baseMayor = '-1';
        foreach ($bases as $clave => $base) {
            $crudo = Dinero::dividir(Dinero::multiplicar($descuentoGlobal, $base), $subtotal);
            $proporcional[$clave] = Dinero::redondear($crudo, 2);

            if (Dinero::comparar($base, $baseMayor) > 0) {
                $baseMayor = $base;
                $claveMayor = $clave;
            }
        }

        // Ajuste del residuo de redondeo al bucket de mayor base.
        $sumaProrateo = '0';
        foreach ($proporcional as $valor) {
            $sumaProrateo = Dinero::sumar($sumaProrateo, $valor);
        }
        $residuo = Dinero::restar($descuentoGlobal, $sumaProrateo);
        $proporcional[$claveMayor] = Dinero::redondear(Dinero::sumar($proporcional[$claveMayor], $residuo), 2);

        return $proporcional;
    }
}
