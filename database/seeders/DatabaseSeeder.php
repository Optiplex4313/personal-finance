<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Sin SEED_PASSWORD se genera una aleatoria y se muestra una vez: nunca "password".
        $password = env('SEED_PASSWORD') ?: Str::password(20, symbols: false);

        foreach ([
            ['name' => 'Josemi', 'email' => 'josemi@server.com'],
            ['name' => 'Elena', 'email' => 'elena@server.com'],
        ] as $user) {
            User::factory()->create([...$user, 'password' => Hash::make($password)]);
        }

        if (! env('SEED_PASSWORD')) {
            $this->command?->warn("Contraseña de los usuarios de ejemplo: {$password}");
        }
    }
}
