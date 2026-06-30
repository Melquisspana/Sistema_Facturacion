<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nuevo lote PPQ</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                <form method="POST" action="{{ route('ppq.lotes.store') }}" class="space-y-4">
                    @csrf
                    @include('ppq.lotes._form', ['lote' => null])
                    <div class="flex justify-end gap-2 pt-2">
                        <a href="{{ route('ppq.lotes.index') }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Crear lote</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
