{{-- Contrato individual de trabajo. El texto legal es fijo y los datos son marcadores: acá
     no va ningún dato real. Ver App\Services\Planilla\ContratoTrabajo. --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Contrato individual de trabajo</title>
<style>
    @page { margin: 18mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; line-height: 1.45; color: #111; }
    h1 { font-size: 13px; text-align: center; margin: 4px 0 10px; }
    h2 { font-size: 9.5px; margin: 11px 0 3px; }
    p { margin: 0 0 4px; }
    .logo { text-align: center; }
    .logo img { height: 42px; }
    table { width: 100%; border-collapse: collapse; }
    .generales td { width: 50%; vertical-align: top; padding: 0 8px 0 0; }
    .generales th { text-align: left; padding: 0 0 3px; }
    .dependientes th, .dependientes td { border: 1px solid #555; padding: 2px 4px; text-align: left; }
    .firmas { margin-top: 90px; }
    .firmas td { width: 50%; text-align: center; padding: 0 12px; }
    .linea { border-top: 1px solid #111; padding-top: 2px; }
    .huella { margin-top: 26px; }
</style>
</head>
<body>
    @if ($logo)
        <div class="logo"><img src="{{ $logo }}" alt=""></div>
    @endif
    <h1>CONTRATO INDIVIDUAL DE TRABAJO</h1>

    <table class="generales">
        <tr><th>GENERALES DE PARTE PATRONAL</th><th>GENERALES DEL TRABAJADOR</th></tr>
        <tr>
            <td>
                <p>Nombre: {{ $patrono['nombre'] }}</p>
                <p>Sexo: {{ $patrono['sexo'] }}</p>
                <p>Edad: {{ $patrono['edad'] }}</p>
                <p>Estado Civil: {{ $patrono['estado_civil'] }}</p>
                <p>Profesión u Oficio: {{ $patrono['profesion'] }}</p>
                <p>Domicilio: {{ $patrono['domicilio'] }}</p>
                <p>Residencia: {{ $patrono['residencia'] }}</p>
                <p>Nacionalidad: {{ $patrono['nacionalidad'] }}</p>
                <p>DUI: {{ $patrono['dui'] }}</p>
                <p>Expedido en {{ $patrono['dui_expedido_en'] }}</p>
                <p>El {{ $patrono['expedido_el'] }}</p>
                <p>En Representación de (Razón Social): {{ $patrono['razon_social'] }}</p>
                <p>NIT: {{ $patrono['nit'] }}</p>
                <p>Actividad Económica de la Empresa: {{ $patrono['actividad'] }}</p>
            </td>
            <td>
                <p>Nombre: {{ $empleado->nombre }}</p>
                <p>Sexo: {{ $empleado->sexo }}</p>
                <p>Edad: {{ $edad }}</p>
                <p>Estado Civil: {{ $empleado->estado_civil }}</p>
                <p>Profesión u Oficio: {{ $empleado->profesion }}</p>
                <p>Domicilio: {{ $empleado->domicilio }}</p>
                <p>Residencia: {{ $empleado->residencia }}</p>
                <p>Nacionalidad: {{ $empleado->nacionalidad }}</p>
                <p>{{ $documento }}: {{ $empleado->dui }}</p>
                @if ($empleado->documento_tipo === 'dui')
                    <p>Expedido en {{ $empleado->dui_expedido_en }}</p>
                    <p>El {{ $expedido_el }}</p>
                @endif
                <p>Otros datos de Identificación:</p>
            </td>
        </tr>
    </table>

    <p style="margin-top: 10px">
        NOSOTROS {{ $patrono['nombre'] }} y {{ $empleado->nombre }}, de las generales arriba indicadas y actuando en el
        carácter que aparece expresado, convenimos en celebrar el presente Contrato Individual de Trabajo sujeto a las
        estipulaciones siguientes:
    </p>

    <h2>a) CLASE DE TRABAJO O SERVICIO:</h2>
    <p>El trabajador se obliga a prestar sus servicios al patrono como: {{ $empleado->cargo }}</p>
    <p>Desempeñando las funciones de: {{ $empleado->funciones }}</p>

    <h2>b) DURACIÓN DEL CONTRATO Y TIEMPO DE SERVICIO:</h2>
    <p>El presente Contrato se celebrará por: Tiempo indefinido</p>
    <p>A partir de: {{ $inicio }}</p>
    <p>
        Queda estipulado para los trabajadores de nuevo ingreso que los primeros treinta días serán de prueba y dentro de
        ese término cualquiera de las partes podrá dar por terminado el contrato sin expresión de causa.
    </p>

    <h2>c) LUGAR DE PRESTACIÓN DE SERVICIOS Y DE ALOJAMIENTO:</h2>
    <p>El lugar de prestación de los servicios será: {{ $empleado->lugar_trabajo }}</p>
    <p>y el trabajador habitará en ________________________________________________________</p>
    <p>Dado que la empresa (sí) o (no) le proporciona alojamiento</p>

    <h2>d) HORARIO DE TRABAJO:</h2>
    <p>Del día lunes al día viernes, de 8:00 a 17:00.</p>
    <p>El día sábado de 8:00 a 12:00.</p>
    <p>De lunes a viernes, de las 12:00 a las 13:00, para la toma de alimentos. Esa hora no se cuenta como jornada.</p>
    <p>
        Cumpliendo con una jornada ordinaria de 8 horas diarias de lunes a viernes y de 4 horas el sábado, para una semana
        laboral de 44 horas.
    </p>

    <h2>e) SALARIO: FORMA, PERÍODO Y LUGAR DEL PAGO:</h2>
    <p>El salario que recibirá el trabajador, por sus servicios, será la suma de {{ $sueldo_letras }} (${{ $sueldo }})</p>
    <p>
        Se pagará en dólares de los Estados Unidos de América de la siguiente forma: Por tiempo;
        El pago se efectuará por medio de: Transferencia
    </p>
    <p>En la Dirección: {{ $patrono['direccion_pago'] }}</p>
    <p>Dicho pago se efectuará de la manera siguiente: Quincenal</p>
    <p>
        La operación del pago principiará y se continuará sin interrupción, a más tardar a la Terminación de la jornada de
        trabajo correspondiente a la respectiva fecha, en caso de reclamo de la persona trabajadora, se estará a lo dispuesto
        en el artículo 613 del Código de Trabajo.
    </p>

    <h2>f) HERRAMIENTAS Y MATERIALES:</h2>
    <p>El patrono suministrará al trabajador las herramientas y materiales siguientes: {{ $empleado->herramientas }}</p>
    <p>
        Que se entregan en Perfectas condiciones y deben ser devueltos así por el trabajador, cuando sea requerida al efecto
        por sus jefes inmediatos, salvo la disminución o deterioro causados por caso fortuito o fuerza mayor, o por la acción
        del tiempo o por el consumo y uso normal de los mismos.
    </p>

    <h2>g) PERSONAS QUE DEPENDEN ECONÓMICAMENTE DEL TRABAJADOR:</h2>
    <table class="dependientes">
        <tr><th>Nombre</th><th>Apellido</th><th>Edad</th><th>Dirección</th></tr>
        @for ($i = 0; $i < 4; $i++)
            @php $d = ($empleado->dependientes ?? [])[$i] ?? []; @endphp
            <tr>
                <td>{{ $d['nombre'] ?? ' ' }}</td><td>{{ $d['apellido'] ?? ' ' }}</td>
                <td>{{ $d['edad'] ?? ' ' }}</td><td>{{ $d['direccion'] ?? ' ' }}</td>
            </tr>
        @endfor
    </table>

    <h2>h) OTRAS ESTIPULACIONES:</h2>
    <p>________________________________________________________________________________________</p>

    <p style="margin-top: 8px">
        i) En el presente Contrato Individual de Trabajo se entenderán incluidos, según el caso, los derechos y deberes
        laborales establecidos por las Leyes y Reglamentos de Trabajo pertinentes, por el Reglamento Interno de Trabajo y por
        el o los Contratos Colectivos de Trabajo que celebre el patrono; los reconocidos en las sentencias que resuelvan
        conflictos colectivos de trabajo en la empresa, y los consagrados por la costumbre.
    </p>
    <p>
        j) Este contrato sustituye cualquier otro Convenio Individual de Trabajo anterior, ya sea escrito o verbal, que haya
        estado vigente entre el patrono y el trabajador, pero no altera en manera alguna los derechos y prerrogativas del
        trabajador que emanen de su antigüedad en el servicio, ni se entenderá como negativa de mejores condiciones concedidas
        al trabajador en el Contrato anterior y que no consten el presente.
    </p>
    <p>En fe de lo cual firmamos el presente documento por triplicado en: {{ $patrono['ciudad'] }}</p>
    <p>A los {{ $hoy->day }} días del mes {{ $hoy->translatedFormat('F') }} de {{ $hoy->year }}</p>

    <table class="firmas">
        <tr>
            <td><div class="linea">(f) {{ mb_strtoupper($patrono['nombre']) }}</div></td>
            <td><div class="linea">(f) {{ mb_strtoupper($empleado->nombre) }}</div></td>
        </tr>
    </table>
    <div class="huella">
        <p>Huellas digitales del trabajador</p>
        <p>Si no puede firmar</p>
        <p style="margin-top: 60px">(f)____________________________________</p>
        <p>A ruego del Trabajador</p>
    </div>
</body>
</html>
