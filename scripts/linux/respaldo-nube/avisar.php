<?php

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;

// Correo de aviso a ALERTAS_CORREO. Uso, dentro del contenedor web: php avisar.php "asunto" "detalle".
// Aplica la configuración SMTP del Centro de Configuración antes de enviar, como los envíos del
// sistema: el .env puede tener una clave de correo vieja.
chdir('/var/www/html');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

app(ConfiguracionCorreoRuntime::class)->aplicar();
$destinos = array_values(array_filter(array_map('trim', explode(',', (string) config('alertas.correo')))));
if ($destinos === []) {
    fwrite(STDERR, "ALERTAS_CORREO está vacío: no hay a quién avisar.\n");
    exit(1);
}
$asunto = '[Facturación] '.($argv[1] ?? 'Aviso del servidor');
$detalle = ($argv[2] ?? '')."\n\nServidor: ".gethostname().' — '.now()->format('d/m/Y H:i');
Mail::raw($detalle, fn ($m) => $m->to($destinos)->subject($asunto));
echo "aviso enviado\n";
