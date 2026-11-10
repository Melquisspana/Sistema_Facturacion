{{-- El logo de la empresa: el mismo de los PDF de los DTE (public/images/dte, ver config/dte.php),
     ya reducido (192×288, 12 KB). Proporción 2:3: quien lo usa fija la altura y deja w-auto.
     En oscuro va sobre un fondo claro redondeado, porque el logo es oscuro y no se veía.
     Son clases que ya están en el CSS de producción, que allá no se recompila. --}}
<img src="{{ asset('images/dte/logo-pdf.png') }}" alt="{{ \App\Support\NombreEmpresa::actual() }}" width="192" height="288" {{ $attributes->merge(['class' => 'rounded p-0.5 dark:bg-paper-100']) }}>
