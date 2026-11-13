{{-- Instalar como app en el celular (13/11/2026): manifiesto e íconos (ver ManifiestoWebController).
     use-credentials: detrás de Cloudflare Access el manifiesto se pide con la cookie de Access.
     Barra de estado «black» y no «black-translucent»: con la translúcida el iPhone dibuja la
     página debajo de la hora y tapa la barra superior fija. --}}
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="{{ \App\Http\Controllers\ManifiestoWebController::COLOR }}">
<link rel="apple-touch-icon" href="/app-icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \App\Http\Controllers\ManifiestoWebController::nombreCorto(\App\Support\NombreEmpresa::actual()) }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
