<?php

namespace App\Ajustes\Rotacion;

/**
 * Resultado de analizar (o ejecutar) una rotación de APP_KEY.
 *
 * LO QUE ESTE OBJETO NO CONTIENE, Y NO PUEDE CONTENER: ninguna clave de cifrado,
 * ningún valor descifrado y ningún criptograma. Solo NOMBRES de ajustes y
 * recuentos. Es lo que se imprime en consola y lo que verían unos ojos de más
 * mirando la pantalla de quien rota la clave.
 *
 * `$ilegibles` es la razón por la que existe el paso de análisis: si una sola fila
 * no se descifra con la clave actual, rotar la destruiría para siempre. Se prefiere
 * abortar y no tocar nada antes que dejar la mitad de los secretos recuperables.
 */
class InformeRotacion
{
    /**
     * @param  array<int, string>  $legibles  Ajustes cifrados que se descifran bien.
     * @param  array<int, string>  $ilegibles  Ajustes cifrados que NO se descifran.
     * @param  array<int, string>  $noVerificados  Se recifraron pero el round-trip falló.
     */
    public function __construct(
        public readonly array $legibles,
        public readonly array $ilegibles,
        public readonly array $noVerificados,
        public readonly bool $aplicada,
    ) {}

    public function total(): int
    {
        return count($this->legibles) + count($this->ilegibles);
    }

    /**
     * ¿Se puede rotar sin perder nada?
     *
     * Exige las dos cosas: que TODO se descifre con la clave vieja y que TODO
     * vuelva a leerse con la nueva. Una sola fila en cualquiera de las dos listas
     * basta para no tocar nada.
     */
    public function puedeAplicarse(): bool
    {
        return $this->ilegibles === [] && $this->noVerificados === [];
    }

    /** Sin nada cifrado, rotar APP_KEY no afecta a esta capa. */
    public function sinSecretos(): bool
    {
        return $this->total() === 0;
    }

    public function conAplicada(bool $aplicada): self
    {
        return new self($this->legibles, $this->ilegibles, $this->noVerificados, $aplicada);
    }
}
