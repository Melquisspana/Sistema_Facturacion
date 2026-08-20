<?php

namespace App\Ajustes;

use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Support\Dinero;

/**
 * Conversión DETERMINISTA entre el texto almacenado y el valor PHP tipado, en
 * ambos sentidos, y validación del valor propuesto.
 *
 * Los valores viajan siempre como texto (columna `valor`, .env, config), así que
 * la conversión de vuelta es donde se cuelan los errores clásicos:
 *
 *   (bool) 'false'  === true     ← el que motivó este archivo
 *   (int)  '12abc'  === 12
 *   float: 0.1 + 0.2 !== 0.3
 *
 * Acá nada de eso pasa: cada tipo tiene una gramática cerrada y lo que no encaja
 * es un error explícito, no una interpretación optimista.
 */
class ConversorValor
{
    /** Textos que significan VERDADERO. */
    private const VERDADEROS = ['1', 'true', 'on', 'yes', 'si', 'sí'];

    /** Textos que significan FALSO. Se listan aparte para poder RECHAZAR lo que no es ninguno. */
    private const FALSOS = ['0', 'false', 'off', 'no', ''];

    /**
     * Texto almacenado → valor tipado. `null` (ausencia) se propaga como null: la
     * ausencia de valor no es "cero" ni "false".
     */
    public function aValor(DefinicionAjuste $definicion, ?string $texto): mixed
    {
        if ($texto === null) {
            return null;
        }

        return match ($definicion->tipo) {
            TipoAjuste::Booleano => $this->aBooleano($texto),
            TipoAjuste::Entero => $this->validarEntero($definicion, $texto),
            // A propósito CADENA, no float: un decimal fiscal no hace round-trip
            // por float. Se opera con App\Support\Dinero (bcmath).
            TipoAjuste::Decimal => Dinero::de($this->validarDecimal($definicion, $texto)),
            TipoAjuste::Lista => $this->aLista($texto),
            TipoAjuste::Texto, TipoAjuste::Email, TipoAjuste::Enumerado, TipoAjuste::Secreto => $texto,
        };
    }

    /**
     * Valor tipado → texto almacenable. `null` significa "sin valor" y se guarda
     * como NULL, no como cadena vacía.
     */
    public function aTexto(DefinicionAjuste $definicion, mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return match ($definicion->tipo) {
            TipoAjuste::Booleano => $this->normalizarBooleano($definicion, $valor) ? '1' : '0',
            TipoAjuste::Entero => (string) $valor,
            TipoAjuste::Decimal => Dinero::de(is_string($valor) ? $valor : (string) $valor),
            TipoAjuste::Lista => $this->codificarLista((array) $valor),
            TipoAjuste::Email => strtolower(trim((string) $valor)),
            TipoAjuste::Secreto => (string) $valor,
            TipoAjuste::Texto, TipoAjuste::Enumerado => trim((string) $valor),
        };
    }

    /**
     * Valida el valor PROPUESTO (lo que llega de un formulario o de un comando) y
     * devuelve el texto ya normalizado y listo para guardar.
     *
     * @throws ValorAjusteInvalidoException
     */
    public function validarYNormalizar(DefinicionAjuste $definicion, mixed $valor): ?string
    {
        // Un ajuste puede vaciarse (volver al fallback) SALVO que sus reglas lo
        // declaren obligatorio. Un secreto vacío NO es "vaciar": para eso está
        // quitar el override, y así un formulario en blanco jamás borra una clave.
        $vacio = $valor === null
            || (is_string($valor) && trim($valor) === '' && ! $definicion->tipo->esSecreto());

        if ($vacio) {
            if (in_array('required', $definicion->reglas, true)) {
                throw ValorAjusteInvalidoException::con($definicion->clave, "«{$definicion->etiqueta}» es obligatorio.");
            }

            return null;
        }

        return match ($definicion->tipo) {
            TipoAjuste::Booleano => $this->normalizarBooleano($definicion, $valor) ? '1' : '0',
            TipoAjuste::Entero => (string) $this->validarEntero($definicion, $valor),
            TipoAjuste::Decimal => Dinero::de($this->validarDecimal($definicion, (string) $valor)),
            TipoAjuste::Email => $this->validarEmail($definicion, (string) $valor),
            TipoAjuste::Enumerado => $this->validarEnumerado($definicion, (string) $valor),
            TipoAjuste::Lista => $this->validarLista($definicion, $valor),
            TipoAjuste::Secreto => $this->validarSecreto($definicion, (string) $valor),
            TipoAjuste::Texto => $this->validarTexto($definicion, (string) $valor),
        };
    }

    // ------------------------------------------------------------------ tipos

    /**
     * LECTURA de un booleano ya almacenado. Tolerante (el texto puede venir de un
     * .env escrito a mano) pero nunca "verdadero por descuido": solo los textos de
     * VERDADEROS dan true, y en particular 'false' da false.
     */
    private function aBooleano(string $texto): bool
    {
        return in_array(strtolower(trim($texto)), self::VERDADEROS, true);
    }

    /** ESCRITURA de un booleano: acepta bool nativo o texto de la gramática cerrada; nada más. */
    private function normalizarBooleano(DefinicionAjuste $definicion, mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_int($valor) && in_array($valor, [0, 1], true)) {
            return $valor === 1;
        }

        $texto = strtolower(trim((string) $valor));

        if (in_array($texto, self::VERDADEROS, true)) {
            return true;
        }

        if (in_array($texto, self::FALSOS, true)) {
            return false;
        }

        throw ValorAjusteInvalidoException::con(
            $definicion->clave,
            "«{$definicion->etiqueta}» solo acepta sí/no (recibido: «{$texto}»)."
        );
    }

    private function validarEntero(DefinicionAjuste $definicion, mixed $valor): int
    {
        $texto = trim((string) $valor);

        if (preg_match('/^-?\d+$/', $texto) !== 1) {
            throw ValorAjusteInvalidoException::con(
                $definicion->clave,
                "«{$definicion->etiqueta}» debe ser un número entero (recibido: «{$texto}»)."
            );
        }

        $numero = (int) $texto;
        $this->comprobarRango($definicion, $numero);

        return $numero;
    }

    private function validarDecimal(DefinicionAjuste $definicion, string $valor): string
    {
        $texto = trim($valor);

        if (preg_match('/^-?\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValorAjusteInvalidoException::con(
                $definicion->clave,
                "«{$definicion->etiqueta}» debe ser un número decimal (recibido: «{$texto}»)."
            );
        }

        return $texto;
    }

    private function validarEmail(DefinicionAjuste $definicion, string $valor): string
    {
        // Se normaliza a minúsculas ANTES de validar: el correo es case-insensitive
        // a efectos prácticos y así todos los consumidores comparan lo mismo.
        $correo = strtolower(trim($valor));

        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw ValorAjusteInvalidoException::con(
                $definicion->clave,
                "«{$definicion->etiqueta}» debe ser un correo electrónico válido."
            );
        }

        $this->comprobarLongitud($definicion, $correo);

        return $correo;
    }

    private function validarEnumerado(DefinicionAjuste $definicion, string $valor): string
    {
        $texto = trim($valor);

        if (! in_array($texto, $definicion->opciones, true)) {
            throw ValorAjusteInvalidoException::con(
                $definicion->clave,
                "«{$definicion->etiqueta}» solo admite: ".implode(', ', $definicion->opciones).". Recibido: «{$texto}»."
            );
        }

        return $texto;
    }

    private function validarTexto(DefinicionAjuste $definicion, string $valor): string
    {
        $texto = trim($valor);
        $this->comprobarLongitud($definicion, $texto);

        return $texto;
    }

    private function validarSecreto(DefinicionAjuste $definicion, string $valor): string
    {
        // NO se hace trim: un secreto puede terminar legítimamente en espacio y
        // recortarlo en silencio rompería la autenticación con un error opaco.
        if ($valor === '') {
            throw ValorAjusteInvalidoException::con(
                $definicion->clave,
                "«{$definicion->etiqueta}» no puede quedar vacío. Para volver al valor anterior, quitá el override."
            );
        }

        return $valor;
    }

    /** @param  mixed  $valor  array o JSON */
    private function validarLista(DefinicionAjuste $definicion, mixed $valor): string
    {
        $lista = is_array($valor) ? $valor : $this->aLista((string) $valor);

        foreach ($lista as $elemento) {
            if (! is_scalar($elemento)) {
                throw ValorAjusteInvalidoException::con(
                    $definicion->clave,
                    "«{$definicion->etiqueta}» solo admite una lista de valores simples."
                );
            }
        }

        return $this->codificarLista($lista);
    }

    /** @param  array<int|string, mixed>  $lista */
    private function codificarLista(array $lista): string
    {
        return (string) json_encode(
            array_values(array_map(static fn ($v) => (string) $v, $lista)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /** @return array<int, string> */
    private function aLista(string $texto): array
    {
        $decodificado = json_decode($texto, true);

        return is_array($decodificado)
            ? array_values(array_map(static fn ($v) => (string) $v, $decodificado))
            : [];
    }

    // ----------------------------------------------------------------- reglas

    private function comprobarRango(DefinicionAjuste $definicion, int $numero): void
    {
        foreach ($definicion->reglas as $regla) {
            if (str_starts_with($regla, 'min:') && $numero < (int) substr($regla, 4)) {
                throw ValorAjusteInvalidoException::con(
                    $definicion->clave,
                    "«{$definicion->etiqueta}» no puede ser menor que ".substr($regla, 4).'.'
                );
            }
            if (str_starts_with($regla, 'max:') && $numero > (int) substr($regla, 4)) {
                throw ValorAjusteInvalidoException::con(
                    $definicion->clave,
                    "«{$definicion->etiqueta}» no puede ser mayor que ".substr($regla, 4).'.'
                );
            }
        }
    }

    private function comprobarLongitud(DefinicionAjuste $definicion, string $texto): void
    {
        foreach ($definicion->reglas as $regla) {
            if (str_starts_with($regla, 'maxlen:') && mb_strlen($texto) > (int) substr($regla, 7)) {
                throw ValorAjusteInvalidoException::con(
                    $definicion->clave,
                    "«{$definicion->etiqueta}» no puede superar ".substr($regla, 7).' caracteres.'
                );
            }
        }
    }
}
