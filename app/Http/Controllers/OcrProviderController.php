<?php

namespace App\Http\Controllers;

use App\Services\OcrProviderManager;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Endpoint para indicar qué servicio OCR usa la app: se sube su paquete .celaut.bee,
 * el nodo lo lanza y pasa a ser el servicio activo (reemplaza al anterior).
 */
class OcrProviderController extends Controller
{
    public function store(Request $request, OcrProviderManager $manager): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', 'file', function (string $attribute, mixed $file, \Closure $fail): void {
                if (! str_ends_with(strtolower($file->getClientOriginalName()), '.celaut.bee')) {
                    $fail('El archivo debe ser un paquete .celaut.bee.');
                }
            }],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $provider = $manager->install($request->file('service'), $data['name'] ?? null);
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            Notification::make()->danger()->title('No se pudo lanzar el servicio OCR')->body($e->getMessage())->send();

            return redirect()->back();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $provider->id,
                'name' => $provider->name,
                'service_hash' => $provider->service_hash,
                'status' => $provider->status->value,
            ], 201);
        }

        Notification::make()->success()->title('Servicio OCR activo')->body($provider->label())->send();

        return redirect()->back();
    }

    public function destroy(Request $request, OcrProviderManager $manager): JsonResponse|RedirectResponse
    {
        try {
            $manager->stop();
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            Notification::make()->danger()->title('No se pudo parar el servicio OCR')->body($e->getMessage())->send();

            return redirect()->back();
        }

        if ($request->expectsJson()) {
            return response()->json(status: 204);
        }

        Notification::make()->success()->title('Servicio OCR parado')->send();

        return redirect()->back();
    }
}
