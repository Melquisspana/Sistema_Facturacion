<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Support\PasswordRules;
use Database\Seeders\UsuarioAdminInicialSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class UsuarioAdminInicialSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_password_aleatoria_la_anuncia_una_vez_y_no_la_cambia(): void
    {
        $mostrada = null;
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('getOutput')->andReturn(new OutputStyle(
            new ArrayInput([]),
            new BufferedOutput,
        ));
        $command->shouldReceive('warn')->once()->withArgs(function (string $mensaje) use (&$mostrada) {
            $mostrada = substr($mensaje, strlen('Contraseña inicial: '));

            return str_starts_with($mensaje, 'Contraseña inicial: ');
        });
        $command->shouldReceive('warn')->once()->with('Cámbiela al entrar. No se volverá a mostrar.');

        $seeder = app(UsuarioAdminInicialSeeder::class);
        $seeder->setCommand($command);
        // Evita salida de sub-seeders: solo se captura el anuncio del administrador.
        $seeder->setContainer(app());
        $seeder->run();

        $admin = User::where('email', 'admin@dulceslanegrita.test')->sole();
        $hash = $admin->password;
        $this->assertTrue($admin->hasRole('administrador'));
        $this->assertTrue(Hash::check($mostrada, $hash));
        $this->assertTrue(Validator::make(['password' => $mostrada], ['password' => [PasswordRules::reglas()]])->passes());
        $this->assertStringNotContainsString($mostrada, Activity::all()->toJson());

        $seeder->run();

        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame(1, User::where('email', $admin->email)->count());
    }
}
