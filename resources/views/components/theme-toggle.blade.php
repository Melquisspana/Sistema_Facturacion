{{--
    Toggle claro/oscuro. Oscuro es el tema por defecto de la app (ver el script
    anti-flash en <head> de layouts/app.blade.php y layouts/guest.blade.php,
    que ya deja la clase `dark` puesta en <html> antes de pintar la página).
    Este botón solo alterna la clase y guarda la preferencia en localStorage;
    no hay lógica de servidor ni cookie.
--}}
<button type="button"
        x-data="{ dark: document.documentElement.classList.contains('dark') }"
        x-on:click="
            dark = ! dark;
            document.documentElement.classList.toggle('dark', dark);
            localStorage.setItem('dlg-theme', dark ? 'dark' : 'light');
        "
        {{ $attributes->merge(['class' => 'inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100']) }}
        :aria-label="dark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'"
        title="Claro / oscuro">
    {{-- Sol: visible en oscuro (click para pasar a claro). --}}
    <svg x-show="dark" x-cloak viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
        <path d="M10 4a1 1 0 011-1h.01a1 1 0 110 2H11a1 1 0 01-1-1zM10 15a1 1 0 011 1v.01a1 1 0 11-2 0V16a1 1 0 011-1zM4 10a1 1 0 011-1h.01a1 1 0 110 2H5a1 1 0 01-1-1zM15 10a1 1 0 011-1h.01a1 1 0 110 2H16a1 1 0 01-1-1zM5.64 5.64a1 1 0 011.41 0l.01.01a1 1 0 01-1.42 1.41l-.01-.01a1 1 0 010-1.41zM13.95 13.95a1 1 0 011.41 0l.01.01a1 1 0 01-1.41 1.41l-.01-.01a1 1 0 010-1.41zM5.64 14.36a1 1 0 010-1.41l.01-.01a1 1 0 111.41 1.42l-.01.01a1 1 0 01-1.41 0zM13.95 6.05a1 1 0 010-1.41l.01-.01a1 1 0 111.41 1.41l-.01.01a1 1 0 01-1.41 0z" />
        <circle cx="10" cy="10" r="3.25" />
    </svg>
    {{-- Luna: visible en claro (click para pasar a oscuro). --}}
    <svg x-show="! dark" x-cloak viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
        <path fill-rule="evenodd" d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" clip-rule="evenodd" />
    </svg>
</button>
