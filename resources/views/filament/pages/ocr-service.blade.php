@php($provider = $this->getProvider())

<x-filament-panels::page>
    <x-filament::section heading="Servicio activo">
        @if ($provider)
            <p><strong>{{ $provider->label() }}</strong> — {{ $provider->status->getLabel() }}</p>
            @if ($provider->service_hash)
                <p class="text-sm text-gray-500">{{ $provider->service_hash }}</p>
            @endif
            @if ($provider->error_message)
                <p class="text-sm text-danger-600">{{ $provider->error_message }}</p>
            @endif

            @if ($provider->status === \App\Enums\OcrProviderStatus::ACTIVE)
                <form method="POST" action="{{ route('filament.admin.ocr-service.destroy') }}" class="mt-4">
                    @csrf
                    @method('DELETE')
                    <x-filament::button type="submit" color="danger">Parar servicio</x-filament::button>
                </form>
            @endif
        @else
            <p>No hay ningún servicio OCR. Sin él, el OCR de tickets no funciona.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Cambiar de servicio OCR" description="Sube un paquete .celaut.bee que cumpla el contrato de docs/ocr-service.md. El nodo lo lanza y sustituye al actual.">
        <form method="POST" action="{{ route('filament.admin.ocr-service.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="service" accept=".bee" required>
            <input type="text" name="name" maxlength="100" placeholder="Nombre (opcional)" class="block w-full rounded-lg border-gray-300">
            <x-filament::button type="submit">Subir y lanzar</x-filament::button>
            <p class="text-sm text-gray-500">El arranque puede tardar varios minutos si el servicio descarga modelos.</p>
        </form>
    </x-filament::section>
</x-filament-panels::page>
