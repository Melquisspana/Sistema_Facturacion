<?php

namespace App\Enums;

/**
 * Qué exige un cliente respecto del DOCUMENTO FÍSICO (el CCF impreso que viaja con el
 * pedido y que la sala firma y sella) antes de dejar que ese documento entre a cobro.
 *
 * Existe porque son dos hechos distintos y hasta ahora el sistema solo conocía uno:
 *
 *   · el ALBARÁN prueba que el cliente recibió la mercadería;
 *   · el PAPEL FIRMADO prueba que tenemos con qué cobrarla.
 *
 * El albarán llega solo al correo; el papel lo tiene que traer de vuelta el motorista, y
 * a veces no vuelve, se pierde o aparece meses después. Confundirlos hace invisible
 * justamente el hueco que hay que ver.
 *
 * ─────────────────────────── Por qué es configurable ───────────────────────────
 *
 * Porque no es una regla del sistema: es una exigencia comercial de CADA cliente. Una
 * cadena que firma y sella el CCF impreso no se cobra igual que un cliente que paga
 * contra factura electrónica. Quemar el criterio en el código —o peor, quemar el nombre
 * de una cadena en un `if`— obligaría a tocar código cada vez que entre un cliente nuevo.
 *
 * El valor por defecto es {@see self::NoRequerir}, y eso es lo que hace segura la
 * introducción de esta regla: un cliente sin perfil, o con perfil pero sin declarar el
 * modo, se comporta EXACTAMENTE como antes de que esta clase existiera.
 */
enum ModoPapelFisico: string
{
    /** El documento no entra a cobro sin el papel firmado de vuelta. */
    case Bloquear = 'bloquear';

    /** Se deja cobrar, pero la pantalla avisa que el papel todavía no volvió. */
    case Advertir = 'advertir';

    /** El papel no interviene en el cobro. Comportamiento histórico. */
    case NoRequerir = 'no_requerir';

    public function label(): string
    {
        return match ($this) {
            self::Bloquear => 'Bloquear el cobro',
            self::Advertir => 'Solo advertir',
            self::NoRequerir => 'No requerir',
        };
    }

    /** Qué significa, en una frase, para la pantalla de configuración. */
    public function detalle(): string
    {
        return match ($this) {
            self::Bloquear => 'Un CCF no se puede agregar a un lote de cobro hasta que se registre el regreso del documento físico firmado y sellado.',
            self::Advertir => 'El CCF se puede cobrar sin el documento físico, pero la pantalla lo señala para que alguien lo busque.',
            self::NoRequerir => 'El documento físico no interviene en la decisión de cobro.',
        };
    }

    /** ¿Impide agregar el documento a un lote? */
    public function bloquea(): bool
    {
        return $this === self::Bloquear;
    }

    /** ¿Genera un aviso visible sin impedir nada? */
    public function advierte(): bool
    {
        return $this === self::Advertir;
    }

    /** @return array<string, string> [valor => label] para selects y comandos. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
