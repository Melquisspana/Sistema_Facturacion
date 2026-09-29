<?php

namespace Tests\Feature\Cobros;

use App\Services\Cobros\AuditorEnviados\AdjuntoDteLeido;
use App\Services\Cobros\AuditorEnviados\AnalizadorAuditoriaEnviados;
use App\Services\Cobros\AuditorEnviados\CorreoConAdjuntosDte;
use Tests\TestCase;

/**
 * EL ANALIZADOR PURO DE LA AUDITORÍA DE ENVIADOS.
 *
 * No toca Gmail: recibe listas de {@see CorreoConAdjuntosDte} ya armadas a mano y
 * comprueba que clasifica bien cada caso. Es la pieza que decide qué es "de este
 * cliente", qué está incompleto, qué está repetido y qué NC quedó sin su CCF a la
 * vista — sin inventar un vínculo que el JSON no declare.
 */
class AnalizadorAuditoriaEnviadosTest extends TestCase
{
    private const NIT_CLIENTE = '06140101010011'; // normalizado (solo dígitos)

    private function analizador(): AnalizadorAuditoriaEnviados
    {
        return new AnalizadorAuditoriaEnviados;
    }

    private function ccf(string $codigo, string $nit = self::NIT_CLIENTE): AdjuntoDteLeido
    {
        return new AdjuntoDteLeido(
            tipoDte: '03',
            numeroControl: 'DTE-03-M001P001-'.str_pad($codigo, 15, '0', STR_PAD_LEFT),
            codigoGeneracion: $codigo,
            receptorNit: $nit,
        );
    }

    private function nc(string $codigo, array $relacionados, string $nit = self::NIT_CLIENTE): AdjuntoDteLeido
    {
        return new AdjuntoDteLeido(
            tipoDte: '05',
            numeroControl: 'DTE-05-M001P001-'.str_pad($codigo, 15, '0', STR_PAD_LEFT),
            codigoGeneracion: $codigo,
            receptorNit: $nit,
            documentoRelacionadoCodigos: $relacionados,
        );
    }

    // ------------------------------------------------------------ caso dorado

    public function test_cuenta_ccf_y_nc_relacionados_cuando_el_ccf_aparecio_en_el_barrido(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1')]),
            new CorreoConAdjuntosDte('m2', [$this->nc('NC-1', ['CCF-1'])]),
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(2, $r->correosRevisados);
        $this->assertSame(1, $r->ccf);
        $this->assertSame(1, $r->nc);
        $this->assertSame([], $r->ncSinCcfEnBarrido);
        $this->assertFalse($r->truncado);
    }

    // ------------------------------------------------------------ nc sin ccf

    public function test_nc_cuyo_ccf_relacionado_no_aparecio_queda_senalado_sin_afirmar_que_no_exista(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', [$this->nc('NC-1', ['CCF-AUSENTE'])]),
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->nc);
        $this->assertSame(0, $r->ccf);
        $this->assertSame(['CCF-AUSENTE'], $r->ncSinCcfEnBarrido);
    }

    public function test_nc_sin_documento_relacionado_se_cuenta_como_caso_sin_vinculo(): void
    {
        $r = $this->analizador()->analizar(
            [new CorreoConAdjuntosDte('m1', [$this->nc('NC-1', [])])],
            self::NIT_CLIENTE,
            truncado: false,
        );

        $this->assertSame(1, $r->nc);
        $this->assertSame(1, $r->ncSinRelacion);
        $this->assertSame([], $r->ncSinCcfEnBarrido);
    }

    // ------------------------------------------------------------ receptor ajeno

    public function test_receptor_distinto_no_se_cuenta_como_ccf_ni_nc(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1', nit: '99999999999999')]),
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(0, $r->ccf);
        $this->assertSame(1, $r->correosReceptorDistinto);
    }

    public function test_receptor_sin_nit_en_el_json_queda_no_identificable_y_no_se_asume_del_cliente(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1', nit: '')]),
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(0, $r->ccf);
        $this->assertSame(0, $r->correosReceptorDistinto);
        $this->assertSame(1, $r->correosReceptorNoIdentificable);
    }

    // ------------------------------------------------------------ json ilegible

    public function test_correo_sin_ningun_adjunto_legible_se_cuenta_aparte(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', []),
            new CorreoConAdjuntosDte('m2', [$this->ccf('CCF-1')]),
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->correosSinJsonLegible);
        $this->assertSame(1, $r->ccf);
    }

    // ------------------------------------------------------------ dte incompleto

    public function test_dte_del_cliente_sin_codigo_de_generacion_queda_incompleto(): void
    {
        $incompleto = new AdjuntoDteLeido(
            tipoDte: '03',
            numeroControl: 'DTE-03-M001P001-000000000000001',
            codigoGeneracion: null,
            receptorNit: self::NIT_CLIENTE,
        );

        $correos = [new CorreoConAdjuntosDte('m1', [$incompleto])];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->dtesIncompletos);
        $this->assertSame(0, $r->ccf);
    }

    public function test_tipo_de_dte_distinto_de_03_05_no_se_cuenta_como_incompleto(): void
    {
        $otro = new AdjuntoDteLeido(
            tipoDte: '01',
            numeroControl: 'DTE-01-M001P001-000000000000001',
            codigoGeneracion: 'FAC-1',
            receptorNit: self::NIT_CLIENTE,
        );

        $r = $this->analizador()->analizar([new CorreoConAdjuntosDte('m1', [$otro])], self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->dtesTipoDistinto);
        $this->assertSame(0, $r->dtesIncompletos);
    }

    // ------------------------------------------------------------ duplicados de correo

    public function test_el_mismo_id_de_correo_repetido_en_la_lista_se_cuenta_una_sola_vez(): void
    {
        $correo = new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1')]);

        $r = $this->analizador()->analizar([$correo, $correo], self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->correosRevisados);
        $this->assertSame(1, $r->correosDuplicados);
        $this->assertSame(1, $r->ccf);
    }

    public function test_un_correo_con_varios_adjuntos_legitimos_no_se_cuenta_como_duplicado(): void
    {
        $correo = new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1'), $this->ccf('CCF-2')]);

        $r = $this->analizador()->analizar([$correo], self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->correosRevisados);
        $this->assertSame(0, $r->correosDuplicados);
        $this->assertSame(2, $r->ccf);
    }

    // ------------------------------------------------------------ mismo código en varios mensajes

    public function test_el_mismo_codigo_de_generacion_en_otro_correo_no_se_recuenta_pero_se_senala(): void
    {
        $correos = [
            new CorreoConAdjuntosDte('m1', [$this->ccf('CCF-1')]),
            new CorreoConAdjuntosDte('m2', [$this->ccf('CCF-1')]), // reenvío del mismo DTE
        ];

        $r = $this->analizador()->analizar($correos, self::NIT_CLIENTE, truncado: false);

        $this->assertSame(1, $r->ccf, 'El mismo DTE no debe contarse dos veces aunque llegue en dos correos.');
        $this->assertSame(1, $r->dtesRepetidosEnOtroCorreo);
        $this->assertSame(['CCF-1'], $r->codigosDuplicados);
    }

    // ------------------------------------------------------------ límite/truncamiento

    public function test_el_flag_de_truncado_se_traslada_intacto_al_resultado(): void
    {
        $r = $this->analizador()->analizar([], self::NIT_CLIENTE, truncado: true);

        $this->assertTrue($r->truncado);
    }

    // ------------------------------------------------------------ normalización de NIT

    public function test_normalizar_nit_ignora_guiones_y_espacios(): void
    {
        $this->assertSame('06140101010011', AnalizadorAuditoriaEnviados::normalizarNit('0614-010101-001-1'));
        $this->assertSame('06140101010011', AnalizadorAuditoriaEnviados::normalizarNit(' 0614 0101 0100 11 '));
        $this->assertNull(AnalizadorAuditoriaEnviados::normalizarNit(null));
        $this->assertNull(AnalizadorAuditoriaEnviados::normalizarNit('---'));
    }
}
