<x-layouts.guest title="Recuperar contraseña">
    <h2 class="mb-1 text-lg font-semibold">Recuperar contraseña</h2>
    <p class="mb-6 text-sm text-slate-500">
        Te enviaremos un enlace para restablecerla.
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Correo electrónico</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Enviar enlace
        </button>

        <a href="{{ route('login') }}" class="block text-center text-sm text-slate-600 underline hover:text-slate-900">
            Volver a iniciar sesión
        </a>
    </form>
</x-layouts.guest>
