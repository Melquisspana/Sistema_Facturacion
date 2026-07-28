{{-- Panel de inicio del área Producción (planta). Fase 1: pantalla informativa.
     SIN indicadores, sin tarjetas de datos y sin consultas: el controlador no
     toca la base de datos. No se inventan KPIs que todavía no existen. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Producción</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                        Módulo en preparación
                    </span>
                </div>

                <h1 class="mt-4 text-2xl font-semibold text-gray-800 dark:text-paper-100">Área de Producción</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                    Esta área todavía no tiene funciones operativas. Se habilitó únicamente su
                    espacio de trabajo: acceso propio, menú propio y permisos propios, separados
                    de Facturación.
                </p>
                <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                    No hay datos que mostrar porque aún no se registra información de producción
                    en el sistema.
                </p>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500">
                    Qué se podrá hacer aquí más adelante
                </h2>
                <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-paper-300">
                    <li class="flex gap-2"><span class="text-gray-300 dark:text-paper-500">&bull;</span>Registrar lo que se produce cada día.</li>
                    <li class="flex gap-2"><span class="text-gray-300 dark:text-paper-500">&bull;</span>Llevar control de materia prima y empaque.</li>
                    <li class="flex gap-2"><span class="text-gray-300 dark:text-paper-500">&bull;</span>Ordenar el trabajo de la planta por lotes.</li>
                    <li class="flex gap-2"><span class="text-gray-300 dark:text-paper-500">&bull;</span>Consultar el historial de lo producido.</li>
                </ul>
                <p class="mt-4 text-xs text-gray-400 dark:text-paper-500">
                    Cada una se irá habilitando por separado. Nada de esta área modifica la
                    facturación electrónica ni los documentos ya emitidos.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
