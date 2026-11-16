<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tarjeta de regalo</title>
    <style>
        <x-documento.estilos acento="rosa" />
        @page { size: letter portrait; margin: 20mm; }
        body { font-size: 18px; padding: 24px; }
        #regalo { max-width: 650px; margin: 48px auto; padding: 64px 32px; border: 2px solid #853452; border-radius: 18px; text-align: center; }
        #regalo h1 { font: 14px Arial, sans-serif; letter-spacing: 2px; color: #853452; }
        #regalo h1 span { display: block; margin-top: 16px; font: bold 36px Georgia, serif; letter-spacing: normal; color: #20242C; }
        #mensaje { white-space: pre-wrap; overflow-wrap: anywhere; margin: 40px 0; font: italic 24px Georgia, serif; line-height: 1.5; }
        #firma { font-size: 14px; color: #4A4F58; }
    </style>
</head>
<body>
    {{-- Tarjeta personal: sin recuadro tributario, datos del comprador ni precios (guía). --}}
    <main id="regalo">
        <h1>Un regalo para <span>{{ $regalo['nombre'] ?? '' }}</span></h1>
        <p id="mensaje">{{ $regalo['mensaje'] ?? '' }}</p>
        <p id="firma">Dulces La Negrita · Hechos a mano desde 1996</p>
    </main>
    @include('fabrica.pedidos._imprimir-web')
</body>
</html>
