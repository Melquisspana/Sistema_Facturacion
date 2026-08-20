<?php

namespace App\Ajustes\Rotacion;

use App\Ajustes\RepositorioAjustes;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Re-cifra con una APP_KEY nueva TODO lo que la aplicación guarda cifrado.
 *
 * POR QUÉ HACE FALTA UN MECANISMO Y NO BASTA CON EDITAR EL .env
 * ------------------------------------------------------------------
 * Un valor cifrado solo se recupera con la clave con la que se cifró. Cambiar
 * APP_KEY a mano con secretos guardados NO los invalida: los vuelve
 * IRRECUPERABLES, y el síntoma llega después —cuando el correo deja de
 * autenticarse o Gmail pide reconectar— con el original ya perdido.
 *
 * CUBRE TODOS LOS ORÍGENES, NO SOLO LOS AJUSTES
 * ------------------------------------------------------------------
 * `ajustes_sistema.valor` y los tokens OAuth de `gmail_cuentas` están cifrados con
 * la misma clave. Una rotación que solo mirara la tabla de ajustes dejaría los
 * tokens ilegibles y obligaría a reconectar Gmail sin que nadie supiera por qué:
 * media rotación es peor que ninguna, porque da la sensación de haber terminado.
 * Agregar un origen nuevo es agregar una entrada en {@see self::ORIGENES}.
 *
 * Se lee con el QUERY BUILDER y no con Eloquent a propósito: `GmailCuenta` castea
 * sus tokens como `encrypted`, así que leerlos por el modelo los descifraría con
 * la clave actual y devolvería texto plano donde hace falta el criptograma.
 *
 * EL ORDEN ES LA GARANTÍA
 * ------------------------------------------------------------------
 * 1. se descifra TODO con la clave actual — si una sola fila falla, se aborta;
 * 2. se re-cifra TODO con la clave nueva, en memoria;
 * 3. se comprueba el round-trip: lo re-cifrado se descifra con la clave nueva y
 *    tiene que dar exactamente lo mismo;
 * 4. recién entonces se escribe, en una transacción.
 *
 * El paso 3 es el que hace esto utilizable. Sin él, "se re-cifró bien" es una
 * suposición, y la forma de descubrir que era falsa sería un DecryptException en
 * producción con el original ya sobrescrito.
 *
 * LO QUE NUNCA SALE DE ESTA CLASE
 * ------------------------------------------------------------------
 * Ni las claves de cifrado, ni los valores descifrados, ni los criptogramas. El
 * {@see InformeRotacion} lleva ETIQUETAS y recuentos, porque es lo que se imprime
 * en una consola que alguien puede estar mirando por encima del hombro.
 *
 * NO TOCA EL .env: un fallo a mitad dejaría la aplicación con una clave que ya no
 * corresponde a sus datos. El comando dice qué falta; ese paso es de una persona.
 */
class RotacionAppKey
{
    /**
     * Dónde vive lo cifrado.
     *
     * `filtro` acota a las filas que de verdad lo están (en `ajustes_sistema`
     * conviven valores planos y cifrados); `etiqueta` es lo único que se muestra.
     *
     * @var array<int, array{tabla: string, columnas: array<int, string>, etiqueta: string, filtro: array<string, mixed>}>
     */
    private const ORIGENES = [
        [
            'tabla' => 'ajustes_sistema',
            'columnas' => ['valor'],
            'etiqueta' => 'clave',
            'filtro' => ['cifrado' => true],
        ],
        [
            'tabla' => 'gmail_cuentas',
            'columnas' => ['access_token', 'refresh_token'],
            'etiqueta' => 'email',
            'filtro' => [],
        ],
    ];

    public function __construct(private readonly RepositorioAjustes $repositorio) {}

    /** Analiza la rotación SIN escribir nada. Es el modo por defecto del comando. */
    public function analizar(string $claveNueva): InformeRotacion
    {
        return $this->evaluar($this->encriptador($claveNueva))['informe'];
    }

    /**
     * Ejecuta la rotación. Solo escribe si el análisis dice que se puede.
     *
     * @throws RotacionImposibleException si algo no se descifra o no verifica
     */
    public function ejecutar(string $claveNueva): InformeRotacion
    {
        ['informe' => $informe, 'escrituras' => $escrituras] = $this->evaluar($this->encriptador($claveNueva));

        if (! $informe->puedeAplicarse()) {
            throw RotacionImposibleException::por($informe);
        }

        if ($escrituras !== []) {
            DB::transaction(function () use ($escrituras) {
                foreach ($escrituras as $escritura) {
                    DB::table($escritura['tabla'])
                        ->where('id', $escritura['id'])
                        ->update([$escritura['columna'] => $escritura['valor']]);
                }
            });
        }

        // La caché guarda el criptograma: servirlo después de rotar significaría
        // intentar descifrarlo con la clave equivocada hasta que caducara.
        $this->repositorio->invalidar();

        return $informe->conAplicada(true);
    }

    /**
     * Qué tocaría la rotación, sin analizar nada. Para el aviso previo y para
     * responder a "¿puedo cambiar APP_KEY sin más?".
     *
     * @return array<int, string>
     */
    public function afectados(): array
    {
        $etiquetas = [];

        foreach (self::ORIGENES as $origen) {
            foreach ($this->filasDe($origen) as $fila) {
                foreach ($origen['columnas'] as $columna) {
                    if (filled($fila->{$columna} ?? null)) {
                        $etiquetas[] = $this->etiqueta($origen, $fila, $columna);
                    }
                }
            }
        }

        sort($etiquetas);

        return $etiquetas;
    }

    /**
     * Convierte lo que escribió la persona («base64:...» o los bytes crudos) en la
     * clave binaria, y comprueba que sirva para el cifrado configurado ANTES de
     * tocar ningún dato.
     *
     * @throws InvalidArgumentException con un mensaje que NO incluye la clave
     */
    public static function normalizar(string $clave): string
    {
        $clave = trim($clave);

        if ($clave === '') {
            throw new InvalidArgumentException('No se indicó ninguna clave nueva.');
        }

        if (str_starts_with($clave, 'base64:')) {
            $decodificada = base64_decode(substr($clave, 7), true);

            if ($decodificada === false) {
                throw new InvalidArgumentException('La clave nueva dice ser base64 pero no se puede decodificar.');
            }

            $clave = $decodificada;
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        if (! Encrypter::supported($clave, $cipher)) {
            throw new InvalidArgumentException(
                'La clave nueva no tiene la longitud que exige el cifrado configurado ('.$cipher.'). '
                .'Generala con: php artisan key:generate --show'
            );
        }

        return $clave;
    }

    // ---------------------------------------------------------------- interno

    /**
     * Descifra con la clave ACTUAL, re-cifra con la nueva y verifica el round-trip.
     * No escribe: devuelve el informe y lo re-cifrado, para que quien decida
     * escribir lo haga después de mirar el informe.
     *
     * @return array{informe: InformeRotacion, escrituras: array<int, array{tabla: string, id: int, columna: string, valor: string}>}
     */
    private function evaluar(Encrypter $nuevo): array
    {
        $legibles = [];
        $ilegibles = [];
        $noVerificados = [];
        $escrituras = [];

        foreach (self::ORIGENES as $origen) {
            foreach ($this->filasDe($origen) as $fila) {
                foreach ($origen['columnas'] as $columna) {
                    $criptogramaActual = $fila->{$columna} ?? null;

                    // Una columna vacía no es un secreto: no hay nada que rotar y
                    // contarla como fallo dejaría rotaciones bloqueadas para siempre
                    // por una cuenta de Gmail a medio conectar.
                    if (blank($criptogramaActual)) {
                        continue;
                    }

                    $etiqueta = $this->etiqueta($origen, $fila, $columna);

                    try {
                        $plano = Crypt::decryptString((string) $criptogramaActual);
                    } catch (Throwable) {
                        // Sin el original no hay nada que re-cifrar. Se anota y se
                        // sigue, para informar TODAS las filas rotas de una vez en
                        // vez de obligar a descubrirlas de una en una.
                        $ilegibles[] = $etiqueta;

                        continue;
                    }

                    $criptogramaNuevo = $nuevo->encryptString($plano);

                    if (! $this->verifica($nuevo, $criptogramaNuevo, $plano)) {
                        $noVerificados[] = $etiqueta;

                        continue;
                    }

                    $legibles[] = $etiqueta;
                    $escrituras[] = [
                        'tabla' => $origen['tabla'],
                        'id' => (int) $fila->id,
                        'columna' => $columna,
                        'valor' => $criptogramaNuevo,
                    ];
                }
            }
        }

        return [
            'informe' => new InformeRotacion($legibles, $ilegibles, $noVerificados, aplicada: false),
            'escrituras' => $escrituras,
        ];
    }

    /**
     * Filas crudas de un origen.
     *
     * Se comprueba que la tabla exista: la rotación tiene que poder correrse en un
     * despliegue que todavía no aplicó alguna migración, y morir con un error de
     * SQL en medio de un análisis es la peor forma de enterarse.
     *
     * @param  array{tabla: string, columnas: array<int, string>, etiqueta: string, filtro: array<string, mixed>}  $origen
     * @return Collection<int, object>
     */
    private function filasDe(array $origen): Collection
    {
        if (! Schema::hasTable($origen['tabla'])) {
            return collect();
        }

        return DB::table($origen['tabla'])
            ->where($origen['filtro'])
            ->orderBy('id')
            ->get(array_merge(['id', $origen['etiqueta']], $origen['columnas']));
    }

    /**
     * Nombre legible de un secreto, para el informe. NUNCA su contenido.
     *
     * @param  array{tabla: string, columnas: array<int, string>, etiqueta: string, filtro: array<string, mixed>}  $origen
     */
    private function etiqueta(array $origen, object $fila, string $columna): string
    {
        $nombre = (string) ($fila->{$origen['etiqueta']} ?? ('#'.$fila->id));

        // Con una sola columna cifrada, el nombre de la fila ya identifica el
        // secreto ("mail.smtp.password"); con varias hace falta decir cuál.
        return count($origen['columnas']) === 1
            ? $origen['tabla'].': '.$nombre
            : $origen['tabla'].': '.$nombre.' ('.$columna.')';
    }

    /** ¿Lo re-cifrado vuelve a dar EXACTAMENTE el original con la clave nueva? */
    private function verifica(Encrypter $nuevo, string $criptograma, string $plano): bool
    {
        try {
            // hash_equals y no ===: la comparación es sobre material secreto y no
            // tiene por qué filtrar por tiempo en qué byte dejó de coincidir.
            return hash_equals($plano, $nuevo->decryptString($criptograma));
        } catch (Throwable) {
            return false;
        }
    }

    private function encriptador(string $claveNueva): Encrypter
    {
        return new Encrypter(self::normalizar($claveNueva), (string) config('app.cipher', 'AES-256-CBC'));
    }
}
