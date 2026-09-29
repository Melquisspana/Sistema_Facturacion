@php
    use App\Models\Gastos\Gasto;

    $campo = 'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 min-h-11';
    $importe = $campo.' text-right tabular-nums text-lg';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

{{-- «Compré y pagué»: lo que se paga en el momento, en una sola pantalla.

     Cuatro campos obligatorios y dos opcionales. No pide vencimiento —lo que ya se
     pagó venció cuando se pagó—, ni cuotas, ni naturaleza, ni documentación: son
     siempre lo mismo en este caso.

     Lo que SÍ pregunta es el ámbito, con las dos opciones a la vista. Fijarlo en
     «empresa» habría ahorrado un campo a costa de clasificar mal todo lo personal que
     entrara por acá, y eso ensucia los informes de los dos lados. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Compré y pagué</h1>
                <p class="text-sm text-gray-500">Para lo que se paga en el momento: no queda debiendo nada.</p>
            </div>
            <a href="{{ route('gastos.panel') }}" class="{{ $boton }}">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-2xl space-y-3">

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('gastos.compre-y-pague.store') }}"
                  enctype="multipart/form-data"
                  class="space-y-5 rounded-lg border border-gray-200 bg-white p-5"
                  x-data="{ metodo: 'efectivo', ambito: 'empresarial' }">
                @csrf
                {{-- La clave viaja con el formulario: reenviarlo no registra dos compras. --}}
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="moneda" value="{{ config('gastos.monedas')[0] ?? 'USD' }}">

                <div>
                    <label for="concepto" class="block text-sm font-medium text-gray-700">¿Qué compraste?</label>
                    <input id="concepto" name="concepto" type="text" maxlength="200" required autofocus
                           value="{{ old('concepto') }}" placeholder="Bolsas" class="{{ $campo }} mt-1">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="importe" class="block text-sm font-medium text-gray-700">Importe</label>
                        <input id="importe" name="importe" type="text" inputmode="decimal" required
                               value="{{ old('importe') }}" placeholder="0.00" class="{{ $importe }} mt-1">
                    </div>
                    <div>
                        <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha</label>
                        <input id="fecha" name="fecha" type="date" required max="{{ $hoy }}"
                               value="{{ old('fecha', $hoy) }}" class="{{ $campo }} mt-1">
                        <p class="mt-1 text-xs text-gray-500">Hoy. Se puede cambiar.</p>
                    </div>
                </div>

                {{-- Botones y no un desplegable: son cuatro y se eligen con un toque. --}}
                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700">¿Cómo pagaste?</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (config('gastos.metodos') as $clave => $texto)
                            <label class="cursor-pointer">
                                <input type="radio" name="metodo" value="{{ $clave }}" class="peer sr-only"
                                       x-model="metodo" @checked(old('metodo', 'efectivo') === $clave)>
                                <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                             peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800
                                             peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600">
                                    {{ $texto }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- El ámbito: dos opciones a la vista, siempre. Quien no tiene permiso
                     para lo personal solo ve la primera, y el servicio lo comprueba otra
                     vez —esconder no autoriza—. --}}
                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700">¿Para quién es?</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="ambito" value="empresarial" class="peer sr-only"
                                   x-model="ambito" @checked(old('ambito', 'empresarial') === 'empresarial')>
                            <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                         peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800">
                                De la empresa
                            </span>
                        </label>
                        @if ($vePersonales)
                            <label class="cursor-pointer">
                                <input type="radio" name="ambito" value="personal" class="peer sr-only"
                                       x-model="ambito" @checked(old('ambito') === 'personal')>
                                <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                             peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800">
                                    Personal o de la casa
                                </span>
                            </label>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500" x-show="ambito === 'personal'" x-cloak>
                        Los gastos personales solo los ven las cuentas con ese permiso.
                    </p>
                </fieldset>

                <details class="rounded-md border border-gray-200 bg-gray-50 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-700">
                        Proveedor, categoría y comprobante <span class="font-normal text-gray-500">· opcionales</span>
                    </summary>
                    <div class="mt-3 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="beneficiario" class="block text-xs text-gray-600">¿A quién se lo compraste?</label>
                                <input id="beneficiario" name="beneficiario" type="text" maxlength="180"
                                       value="{{ old('beneficiario') }}" class="{{ $campo }} mt-1">
                                <p class="mt-1 text-xs text-gray-500">Si se deja vacío, queda como «Sin proveedor».</p>
                            </div>
                            <div>
                                <label for="categoria" class="block text-xs text-gray-600">Categoría</label>
                                <input id="categoria" name="categoria" type="text" maxlength="100"
                                       list="categorias-conocidas" value="{{ old('categoria') }}" class="{{ $campo }} mt-1">
                                <p class="mt-1 text-xs text-gray-500">Si se deja vacío, queda como «Compras».</p>
                            </div>
                        </div>
                        <div>
                            <label for="sin_comprobante" class="block text-xs text-gray-600">¿No te dieron comprobante?</label>
                            <input id="sin_comprobante" name="sin_comprobante" type="text" maxlength="250"
                                   value="{{ old('sin_comprobante') }}" placeholder="Explicá por qué no hay documento"
                                   class="{{ $campo }} mt-1">
                        </div>
                    </div>
                </details>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
                    <p class="text-xs text-gray-500">Se guarda el gasto y su pago en una sola operación.</p>
                    <div class="flex gap-2">
                        <a href="{{ route('gastos.panel') }}" class="{{ $boton }}">Cancelar</a>
                        <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-6 text-sm font-semibold text-white hover:bg-indigo-700">
                            Guardar
                        </button>
                    </div>
                </div>
            </form>

            <datalist id="categorias-conocidas">
                @foreach (['Insumos', 'Productos', 'Servicios', 'Mantenimiento', 'Combustible', 'Compras'] as $c)
                    <option value="{{ $c }}"></option>
                @endforeach
            </datalist>
        </div>
    </div>
</x-app-layout>
