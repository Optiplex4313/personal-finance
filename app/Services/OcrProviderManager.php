<?php

namespace App\Services;

use App\Enums\OcrProviderStatus;
use App\Models\OcrProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Gestiona el servicio OCR activo: recibe un paquete .celaut.bee, pide al controlador
 * local de pf-entrypoint que lo lance en el nodo y guarda dónde escucha.
 */
class OcrProviderManager
{
    private const DIRECTORY = 'ocr-providers';

    public function isAvailable(): bool
    {
        return $this->baseUrl() !== null;
    }

    /**
     * URL base del servicio OCR a usar, sin barra final.
     */
    public function baseUrl(): ?string
    {
        $override = trim((string) config('services.ocr.url'));
        $uri = $override !== '' ? $override : OcrProvider::active()?->uri;

        if ($uri === null || trim($uri) === '') {
            return null;
        }

        $uri = trim($uri);

        if (! str_starts_with($uri, 'http://') && ! str_starts_with($uri, 'https://')) {
            $uri = 'http://'.$uri;
        }

        return rtrim($uri, '/');
    }

    /**
     * Nombre del servicio en uso, para registrarlo en cada OcrJob.
     */
    public function activeLabel(): ?string
    {
        if (trim((string) config('services.ocr.url')) !== '') {
            return 'OCR_SERVICE_URL';
        }

        return OcrProvider::active()?->label();
    }

    public function install(UploadedFile $file, ?string $name = null): OcrProvider
    {
        $path = $file->storeAs(self::DIRECTORY, $file->hashName().'.celaut.bee', 'local');

        if ($path === false) {
            throw new RuntimeException('No se pudo guardar el archivo .celaut.bee.');
        }

        $provider = OcrProvider::query()->create([
            'name' => $name ?: $this->nameFromFilename($file->getClientOriginalName()),
            'bee_path' => $path,
            'status' => OcrProviderStatus::STOPPED,
        ]);

        return $this->start($provider);
    }

    /**
     * Lanza el servicio del paquete y lo deja como el único activo.
     */
    public function start(OcrProvider $provider): OcrProvider
    {
        try {
            $started = $this->control('ocr/start', [
                'path' => Storage::disk('local')->path($provider->bee_path),
            ], (int) config('services.ocr.start_timeout'));
        } catch (Throwable $e) {
            Log::error('[OcrProviderManager] No se pudo lanzar el servicio OCR', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            $provider->forceFill([
                'status' => OcrProviderStatus::FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $provider->forceFill([
            'service_hash' => $started['service_hash'] ?? null,
            'uri' => $started['uri'],
            'status' => OcrProviderStatus::ACTIVE,
            'error_message' => null,
        ])->save();

        // El controlador para el servicio anterior al lanzar este.
        $this->pruneBeeFiles($provider);

        return $provider;
    }

    public function stop(): void
    {
        $active = OcrProvider::active();

        if ($active === null) {
            return;
        }

        $this->control('ocr/stop', [], 60);

        $active->forceFill(['status' => OcrProviderStatus::STOPPED, 'uri' => null])->save();
    }

    /**
     * Tras reiniciar pf-app el nodo ya no tiene la instancia: vuelve a lanzar la activa.
     */
    public function restore(): ?OcrProvider
    {
        $active = OcrProvider::active();

        return $active === null ? null : $this->start($active);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function control(string $endpoint, array $payload, int $timeout): array
    {
        $base = trim((string) config('services.ocr.control_url'));

        if ($base === '') {
            throw new RuntimeException(
                'No hay controlador de nodo Celaut (PF_CONTROL_URL): fuera de un nodo no se pueden lanzar paquetes .celaut.bee. '
                .'Define OCR_SERVICE_URL para usar un servicio OCR ya levantado.'
            );
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->post(rtrim($base, '/').'/'.$endpoint, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'El controlador rechazó %s (HTTP %d): %s',
                $endpoint,
                $response->status(),
                mb_substr(trim($response->body()), 0, 500)
            ));
        }

        $data = $response->json();

        if (! is_array($data) || ($endpoint === 'ocr/start' && empty($data['uri']))) {
            throw new RuntimeException('El controlador devolvió una respuesta sin "uri".');
        }

        return $data;
    }

    private function nameFromFilename(string $filename): string
    {
        return mb_substr(preg_replace('/\.celaut\.bee$/i', '', $filename) ?? $filename, 0, 100);
    }

    /**
     * Conserva solo el paquete del servicio activo: pueden pesar cientos de MB.
     */
    private function pruneBeeFiles(OcrProvider $keep): void
    {
        $stale = OcrProvider::query()->whereKeyNot($keep->id)->get();

        foreach ($stale as $old) {
            Storage::disk('local')->delete($old->bee_path);
            $old->delete();
        }
    }
}
