<?php

namespace App\Services\Cobros\ImportadorEnviados;

/**
 * Lo que hizo (o, en seco, lo que HARÍA) una corrida de importación de enviados.
 *
 * Nunca lleva JSON, cuerpos de correo, nombres ni NIT: como mucho identificadores de
 * documento (número de control, código de generación) y el id del mensaje de Gmail, que
 * es lo que hace falta para localizar un caso a mano.
 *
 * `truncado` falso NO significa que el cliente no tenga más documentos ni más NC: solo
 * que se recorrió todo lo que Gmail devolvió para esa consulta y ese rango.
 */
final class ResultadoImportacionEnviados
{
    public bool $aplicado = false;

    public bool $truncado = false;

    public int $correosRevisados = 0;

    public int $correosRepetidos = 0;

    public int $correosSinJson = 0;

    public int $adjuntosIlegibles = 0;

    public int $receptorAjeno = 0;

    public int $receptorNoIdentificable = 0;

    public int $tipoDistinto = 0;

    public int $incompletos = 0;

    /** Documentos (por código de generación) sin ninguna copia con sello de recepción. */
    public int $omitidosSinSello = 0;

    /** Copias adicionales del mismo documento en otros correos o adjuntos. */
    public int $reenvios = 0;

    /** Documentos que ya son DTE de este sistema: los da de alta `cobros:sincronizar`. */
    public int $propiosDelSistema = 0;

    public int $ccfCreados = 0;

    public int $ncCreadas = 0;

    public int $yaExistian = 0;

    /** Existentes a los que se les completó un dato que tenían vacío (nunca se pisa uno lleno). */
    public int $completados = 0;

    public int $procedenciasNuevas = 0;

    public int $revisionHistorica = 0;

    public int $relacionesNuevas = 0;

    public int $relacionesResueltas = 0;

    public int $ncSinRelacion = 0;

    /**
     * @var array<string, true> códigos de CCF que alguna NC declara y que no están en el
     *                          seguimiento. No prueba que no existan.
     */
    public array $relacionesSinCcf = [];

    /** @var array<int, array{referencia: string, mensaje: ?string, motivo: string}> */
    public array $excepciones = [];

    public function excepcion(string $referencia, ?string $mensaje, string $motivo): void
    {
        $this->excepciones[] = ['referencia' => $referencia, 'mensaje' => $mensaje, 'motivo' => $motivo];
    }
}
