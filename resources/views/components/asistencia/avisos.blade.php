{{-- Mensajes de éxito y de error del módulo Asistencia. Un solo sitio para todas
     sus pantallas, con el mismo estilo que usan Facturación y Planta.

     Los errores de VALIDACIÓN se dibujan aparte, junto a su campo: mezclar «ya
     hay otra persona con ese código» con «la ranura 7 la ocupa Ana» en el mismo
     cajón obliga a leer todo para saber qué hay que corregir. --}}
@if (session('status'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
        {{ session('error') }}
    </div>
@endif

<x-asistencia.token-generado />
