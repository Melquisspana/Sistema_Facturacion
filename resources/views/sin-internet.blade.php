<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Sin internet</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; background: #f3f4f6; color: #111827; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; }
        main { margin: 1.5rem; padding: 2rem; max-width: 34rem; border-radius: 1rem; background: #fff; }
        h1 { font-size: 1.8rem; line-height: 1.25; }
        p { line-height: 1.6; }
        a { display: inline-block; padding: 1rem; border-radius: .5rem; background: #1d4ed8; color: #fff; text-decoration: none; font-weight: 600; }
        a:focus-visible { outline: 3px solid #f59e0b; outline-offset: 4px; }
        @media (prefers-color-scheme: dark) { :root { background: #111827; color: #f9fafb; } main { background: #1f2937; } }
    </style>
</head>
<body>
<main>
    <h1>Sin internet: entrá por la red del local</h1>
    <p>Conectate a la red del local y abrí el favorito «Facturación (sin internet)».</p>
    @if ($urlLocal)
        <a href="{{ $urlLocal }}">Abrir Facturación (sin internet)</a>
    @else
        <p>Pedí la dirección al administrador.</p>
    @endif
    <p>El navegador va a mostrar un aviso de certificado. Para entrar, elegí «Avanzado → Continuar».</p>
</main>
</body>
</html>
