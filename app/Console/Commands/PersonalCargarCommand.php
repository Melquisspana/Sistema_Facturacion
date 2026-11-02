<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Carga personal desde un CSV que vive FUERA del repositorio: trae datos reales.
 *
 * Por defecto NO escribe nada: hace todo de verdad y lo deshace, así que muestra lo que
 * pasaría con las mismas validaciones de la pantalla. Con `--aplicar` lo deja, en una sola
 * transacción: o entra toda la lista o no entra nadie.
 *
 * Por pantalla solo salen números de fila e identificadores. Ni nombres ni documentos ni
 * sueldos, porque la salida de un comando termina en registros.
 *
 * Usa {@see AltaEmpleado}, con el mismo control de duplicados: si una persona coincide con
 * una ficha, se enlaza; si coincide con dos en el mismo módulo, se detiene sin adivinar.
 * No crea usuarios: el CSV no lleva contraseñas. Con `usuario_email` enlaza uno que exista.
 */
class PersonalCargarCommand extends Command
{
    private const COLUMNAS = [
        'documento_tipo', 'documento', 'nombres', 'apellidos', 'cargo', 'fecha_ingreso', 'sueldo_mensual',
        'con_descuentos', 'marca', 'sale_rutas', 'usuario_email',
    ];

    protected $signature = 'personal:cargar
        {archivo : CSV con el personal, fuera del repositorio}
        {--usuario= : Correo del administrador que queda como responsable}
        {--sueldo-desde= : Fecha AAAA-MM-DD desde la que rige el sueldo (por defecto, la de ingreso o hoy)}
        {--aplicar : Escribe los cambios (sin esto solo muestra lo que haría)}';

    protected $description = 'Carga personal desde un CSV privado (por defecto, solo muestra lo que haría)';

    public function handle(AltaEmpleado $alta): int
    {
        $actor = User::where('email', (string) $this->option('usuario'))->where('activo', true)->first();

        if ($actor === null) {
            $this->error('Indicá con --usuario el correo de un administrador activo.');

            return self::FAILURE;
        }

        try {
            $filas = $this->leer((string) $this->argument('archivo'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        DB::beginTransaction();
        $errores = 0;

        foreach ($filas as $numero => $fila) {
            try {
                $datos = $this->datosDeFila($alta, $fila);
                $empleado = $alta->registrar($actor, $datos);
                $this->line(sprintf(
                    'Fila %d: %s. Usuario %s, asistencia %s, rutas %s.',
                    $numero,
                    $this->option('aplicar') ? 'cargada' : 'se cargaría',
                    $empleado->user_id ?? 'no',
                    $empleado->asistencia_empleado_id ?? 'no',
                    $empleado->personal_ruta_id ?? 'no',
                ));
            } catch (ValidationException $e) {
                $errores++;
                $this->error('Fila '.$numero.': '.implode(' ', $e->validator->errors()->all()));
            } catch (QueryException) {
                // Su mensaje trae los valores de la consulta: no se muestra.
                $errores++;
                $this->error('Fila '.$numero.': la base de datos la rechazó.');
            } catch (RuntimeException $e) {
                // abort() también llega acá, sin mensaje.
                $errores++;
                $this->error('Fila '.$numero.': '.($e->getMessage() ?: 'el responsable no tiene permiso.'));
            }
        }

        if ($errores > 0 || ! $this->option('aplicar')) {
            DB::rollBack();
            $this->info($errores > 0
                ? 'No se escribió nada: hay '.$errores.' fila(s) con problemas.'
                : 'Revisión terminada: '.count($filas).' fila(s) sin problemas. No se escribió nada. Usá --aplicar para cargarlas.');

            return $errores > 0 ? self::FAILURE : self::SUCCESS;
        }

        DB::commit();
        $this->info('Cargadas '.count($filas).' fila(s).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $fila
     * @return array<string, mixed>
     */
    private function datosDeFila(AltaEmpleado $alta, array $fila): array
    {
        $datos = [
            'nombres' => $fila['nombres'], 'apellidos' => $fila['apellidos'], 'cargo' => $fila['cargo'] ?: null,
            'documento_tipo' => $fila['documento_tipo'] ?: 'dui', 'dui' => $fila['documento'],
            'alta_el' => $fila['fecha_ingreso'] ?: null, 'sueldo_mensual' => $fila['sueldo_mensual'] ?: null,
            'sueldo_desde' => $this->option('sueldo-desde') ?: null,
            'con_descuentos_ley' => $this->siNo($fila['con_descuentos'], 'con_descuentos'),
            'marca' => $this->siNo($fila['marca'], 'marca'), 'sale_rutas' => $this->siNo($fila['sale_rutas'], 'sale_rutas'),
            'confirmar_bajo_minimo' => $this->siNo($fila['confirmar_bajo_minimo'] ?? '', 'confirmar_bajo_minimo'),
            'usa_sistema' => false,
        ];

        // Cada coincidencia se enlaza. Si hay dos en un módulo, no se adivina cuál es.
        $porModulo = collect($alta->coincidencias($fila['nombres'], $fila['apellidos']))->groupBy('tipo');

        if ($porModulo->has('planilla_empleado_id')) {
            throw new RuntimeException('ya está en planilla la ficha '.$porModulo['planilla_empleado_id']->first()['id'].'.');
        }

        foreach ($porModulo as $tipo => $fichas) {
            if ($fichas->count() > 1) {
                throw new RuntimeException('coincide con varias fichas de '.$tipo.': '.$fichas->pluck('id')->implode(', ').'.');
            }

            $datos[$tipo] = $fichas->first()['id'];
        }

        if (filled($fila['usuario_email'])) {
            $usuario = User::where('email', $fila['usuario_email'])->first();

            if ($usuario === null) {
                throw new RuntimeException('no existe un usuario con ese correo.');
            }

            $datos['user_id'] = $usuario->id;
        }

        return $datos;
    }

    private function siNo(string $valor, string $columna): bool
    {
        return match (mb_strtolower(trim($valor))) {
            'si', 'sí', '1', 'x' => true,
            'no', '0', '' => false,
            default => throw new RuntimeException('la columna '.$columna.' tiene que decir sí o no.'),
        };
    }

    /** @return array<int, array<string, string>> número de fila => fila */
    private function leer(string $ruta): array
    {
        if (! is_file($ruta)) {
            throw new RuntimeException('No existe el archivo.');
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lineas = array_map(fn (string $l) => mb_check_encoding($l, 'UTF-8') ? $l : mb_convert_encoding($l, 'UTF-8', 'Windows-1252'), $lineas);
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', (string) array_shift($lineas));
        // Excel en español guarda con punto y coma.
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        $encabezado = array_map('trim', str_getcsv($primera, $separador));

        if (($faltan = array_diff(self::COLUMNAS, $encabezado)) !== []) {
            throw new RuntimeException('Faltan columnas: '.implode(', ', $faltan).'.');
        }

        $filas = [];

        foreach ($lineas as $i => $linea) {
            $valores = array_map('trim', str_getcsv($linea, $separador));

            if (count($valores) !== count($encabezado)) {
                throw new RuntimeException('La fila '.($i + 2).' no tiene todas las columnas.');
            }

            $filas[$i + 2] = array_combine($encabezado, $valores);
        }

        return $filas;
    }
}
