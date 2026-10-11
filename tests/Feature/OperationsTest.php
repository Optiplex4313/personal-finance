<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_backup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'backup:database'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertSame('Europe/Madrid', $event->timezone);
    }

    public function test_ensure_user_creates_the_user_once(): void
    {
        $this->artisan('app:ensure-user', ['email' => 'yo@example.com', '--name' => 'Yo'])
            ->expectsQuestion('Contraseña', 'una-clave-bastante-larga')
            ->assertSuccessful();

        $user = User::query()->where('email', 'yo@example.com')->sole();
        $this->assertTrue(Hash::check('una-clave-bastante-larga', $user->password));

        $this->artisan('app:ensure-user', ['email' => 'yo@example.com'])
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('una-clave-bastante-larga', $user->fresh()->password));
    }

    public function test_ensure_user_reads_password_from_environment(): void
    {
        putenv('PF_ADMIN_PASSWORD=desde-el-entorno-seguro');

        try {
            $this->artisan('app:ensure-user', ['email' => 'env@example.com'])->assertSuccessful();
        } finally {
            putenv('PF_ADMIN_PASSWORD');
        }

        $user = User::query()->where('email', 'env@example.com')->sole();
        $this->assertTrue(Hash::check('desde-el-entorno-seguro', $user->password));
    }

    public function test_ensure_user_rejects_short_passwords(): void
    {
        $this->artisan('app:ensure-user', ['email' => 'yo@example.com'])
            ->expectsQuestion('Contraseña', 'corta')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
