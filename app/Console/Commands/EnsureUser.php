<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureUser extends Command
{
    protected $signature = 'app:ensure-user
        {email : Email con el que se inicia sesión}
        {--name= : Nombre visible (por defecto, la parte local del email)}';

    protected $description = 'Crea el usuario si no existe. La contraseña se lee de PF_ADMIN_PASSWORD o se pregunta. Si ya existe, no lo modifica.';

    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email inválido: {$email}");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->info("El usuario {$email} ya existe; no se modifica.");

            return self::SUCCESS;
        }

        // Desde el entorno y no como argumento: así no aparece en `ps` ni en logs.
        $password = (string) (getenv('PF_ADMIN_PASSWORD') ?: $this->secret('Contraseña'));

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error(sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH));

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $this->option('name') ?: strstr($email, '@', true),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Usuario {$email} creado.");

        return self::SUCCESS;
    }
}
