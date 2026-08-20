{{-- Rótulo de sub-bloque DENTRO de un grupo del sidebar (p. ej. «Comercial»
     dentro de «Ventas y facturación»). Un escalón por debajo del título de
     grupo: más pequeño y con menos contraste, para que agrupe sin competir con
     la categoría ni con el enlace. Solo estilo: no lleva enlace ni permiso. --}}
<p {{ $attributes->merge(['class' => 'mb-1 mt-3 px-3 text-[10px] font-medium uppercase tracking-wide text-gray-400/90 first:mt-0 dark:text-paper-500/90']) }}>
    {{ $slot }}
</p>
