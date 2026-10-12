<?php

namespace Tests\Feature;

use App\Enums\OcrProviderStatus;
use App\Models\OcrProvider;
use App\Models\User;
use App\Services\OcrProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OcrProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.ocr.url' => null, 'services.ocr.control_url' => 'http://control.test']);
    }

    private function bee(string $name = 'tesseract.celaut.bee'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'bee');
    }

    public function test_install_launches_the_package_and_activates_it(): void
    {
        Http::fake(['control.test/ocr/start' => Http::response(['service_hash' => 'abc123', 'uri' => '10.0.0.5:8000'])]);

        $provider = app(OcrProviderManager::class)->install($this->bee());

        $this->assertSame(OcrProviderStatus::ACTIVE, $provider->status);
        $this->assertSame('tesseract', $provider->name);
        $this->assertSame('abc123', $provider->service_hash);
        $this->assertSame('http://10.0.0.5:8000', app(OcrProviderManager::class)->baseUrl());
        Storage::disk('local')->assertExists($provider->bee_path);
    }

    public function test_installing_a_new_package_replaces_the_previous_one(): void
    {
        Http::fake(['control.test/ocr/start' => Http::sequence()
            ->push(['service_hash' => 'one', 'uri' => 'a:1'])
            ->push(['service_hash' => 'two', 'uri' => 'b:2'])]);
        $manager = app(OcrProviderManager::class);

        $first = $manager->install($this->bee('one.celaut.bee'));
        $second = $manager->install($this->bee('two.celaut.bee'));

        $this->assertSame(1, OcrProvider::query()->count());
        $this->assertTrue(OcrProvider::active()->is($second));
        Storage::disk('local')->assertMissing($first->bee_path);
    }

    public function test_failed_launch_keeps_the_previous_service_active(): void
    {
        Http::fake(['control.test/ocr/start' => Http::sequence()
            ->push(['service_hash' => 'one', 'uri' => 'a:1'])
            ->push(['message' => 'sin saldo'], 500)]);
        $manager = app(OcrProviderManager::class);
        $first = $manager->install($this->bee('one.celaut.bee'));

        try {
            $manager->install($this->bee('two.celaut.bee'));
            $this->fail('Debía fallar');
        } catch (RuntimeException) {
        }

        $this->assertTrue(OcrProvider::active()->is($first));
        $this->assertSame(1, OcrProvider::query()->where('status', OcrProviderStatus::FAILED)->count());
    }

    public function test_install_outside_a_celaut_node_explains_what_to_do(): void
    {
        config(['services.ocr.control_url' => null]);

        $this->expectExceptionMessage('OCR_SERVICE_URL');

        app(OcrProviderManager::class)->install($this->bee());
    }

    public function test_env_url_overrides_the_uploaded_service(): void
    {
        config(['services.ocr.url' => 'ocr.local:9000/']);

        $this->assertSame('http://ocr.local:9000', app(OcrProviderManager::class)->baseUrl());
    }

    public function test_restore_relaunches_the_active_package(): void
    {
        Http::fake(['control.test/ocr/start' => Http::response(['service_hash' => 'abc', 'uri' => 'new:1'])]);
        $provider = OcrProvider::query()->create([
            'bee_path' => 'ocr-providers/x.celaut.bee',
            'status' => OcrProviderStatus::ACTIVE,
            'uri' => 'old:1',
        ]);

        app(OcrProviderManager::class)->restore();

        $this->assertSame('new:1', $provider->fresh()->uri);
    }

    public function test_endpoint_requires_login(): void
    {
        $this->postJson('/ocr-service')->assertUnauthorized();
    }

    public function test_endpoint_accepts_a_celaut_bee_and_returns_the_active_service(): void
    {
        Http::fake(['control.test/ocr/start' => Http::response(['service_hash' => 'abc123', 'uri' => 'h:1'])]);

        $this->actingAs(User::factory()->create())
            ->postJson('/ocr-service', ['service' => $this->bee()])
            ->assertCreated()
            ->assertJson(['service_hash' => 'abc123', 'status' => 'ACTIVE']);
    }

    public function test_endpoint_rejects_other_file_types(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/ocr-service', ['service' => UploadedFile::fake()->create('ocr.zip', 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service');
    }
}
