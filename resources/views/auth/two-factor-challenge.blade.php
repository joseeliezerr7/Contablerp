<x-layouts.guest title="Verificación en dos pasos">
    <h2 class="mb-1 text-lg font-semibold">Verificación en dos pasos</h2>
    <p class="mb-6 text-sm text-slate-500">
        Ingresa el código de tu aplicación de autenticación, o uno de tus códigos de recuperación.
    </p>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="mb-1 block text-sm font-medium text-slate-700">Código de autenticación</label>
            <input id="code" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
        </div>

        <div>
            <label for="recovery_code" class="mb-1 block text-sm font-medium text-slate-700">Código de recuperación</label>
            <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Verificar
        </button>
    </form>
</x-layouts.guest>
