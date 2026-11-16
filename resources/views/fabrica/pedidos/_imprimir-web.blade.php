<style>
    #volver-pedidos { display: block; padding: 16px; min-height: 48px; font: 18px Arial, sans-serif; }
    @media print { #volver-pedidos { display: none; } }
</style>
<a id="volver-pedidos" href="{{ route('fabrica.pedidos.index') }}">Volver a Pedidos</a>
<script>window.addEventListener('load', () => window.print());</script>
