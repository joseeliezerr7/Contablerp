@php
    $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm transition focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
@endphp

<x-layouts.guest title="Iniciar sesión" :split="true">
    <h2 class="text-lg font-semibold">Buenas, entrá a tu cuenta</h2>
    <p class="mt-1 mb-6 text-sm text-slate-500">Con el correo que te dieron en tu empresa.</p>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        {{-- role="alert" para que un lector de pantalla lo anuncie sin que haya
             que ir a buscarlo. --}}
        <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            <ul class="space-y-1 {{ count($errors->all()) > 1 ? 'list-inside list-disc' : '' }}">
                @foreach ($errors->all() as $error)
                    <li class="{{ count($errors->all()) === 1 ? 'list-none' : '' }}">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Correo electrónico</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   autocomplete="username" inputmode="email" placeholder="vos@tuempresa.hn"
                   @if ($errors->has('email')) aria-invalid="true" @endif
                   class="{{ $input }}">
        </div>

        <div>
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-slate-500 underline hover:text-slate-900">
                        ¿La olvidaste?
                    </a>
                @endif
            </div>

            {{--
                Mostrar la contraseña ayuda de verdad aquí: el sistema reparte
                temporales de doce caracteres que se dictan por teléfono, y
                escribirlas a ciegas es la mitad de los intentos fallidos.

                Va en JavaScript de la página y **no en Alpine**: Alpine lo
                inyecta Livewire, y esta pantalla es Blade sin componente
                Livewire, así que aquí no existe. El `type="password"` va escrito
                en el HTML —no enlazado— para que el campo sea correcto aunque el
                script no corra nunca; el botón arranca oculto y lo revela el
                propio script, de modo que sin JavaScript no queda un botón
                muerto en la pantalla.
            --}}
            <div class="relative">
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       @if ($errors->has('password')) aria-invalid="true" @endif
                       class="{{ $input }} pr-11">

                <button type="button" id="togglePassword" tabindex="-1" hidden
                        aria-label="Mostrar la contraseña"
                        class="absolute inset-y-0 right-0 items-center px-3 text-slate-400 hover:text-slate-700">
                    <svg id="eyeShow" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10 4C5.5 4 2.2 7.1 1 10c1.2 2.9 4.5 6 9 6s7.8-3.1 9-6c-1.2-2.9-4.5-6-9-6zm0 10a4 4 0 110-8 4 4 0 010 8zm0-2a2 2 0 100-4 2 2 0 000 4z"/>
                    </svg>
                    <svg id="eyeHide" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" hidden>
                        <path d="M3.3 2.3 2 3.6l2.3 2.3C2.9 7 1.7 8.4 1 10c1.2 2.9 4.5 6 9 6 1.5 0 2.9-.3 4.1-.9l2.3 2.3 1.3-1.3L3.3 2.3zM10 14c-4.5 0-7.8-3.1-9-4 .6-1.1 1.6-2.3 2.9-3.2l1.9 1.9A4 4 0 0010 14zm9-4c-.7-1.6-1.9-3-3.3-4.1l-1.4 1.4c1 .7 1.9 1.6 2.6 2.7-.6 1.1-1.6 2.3-2.9 3.2l1.4 1.4C17 12.5 18.3 11.3 19 10z"/>
                    </svg>
                </button>
            </div>
        </div>

        <label for="remember" class="flex items-center gap-2 text-sm text-slate-600">
            <input id="remember" name="remember" type="checkbox"
                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
            Mantener la sesión abierta
        </label>

        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:ring-2 focus:ring-slate-900 focus:ring-offset-2 focus:outline-none">
            Entrar
        </button>
    </form>

    <p class="mt-6 border-t border-slate-100 pt-5 text-center text-sm text-slate-500">
        ¿Todavía no tenés cuenta?
        <a href="{{ route('signup') }}" class="font-medium text-slate-900 underline">Creá una gratis</a>
    </p>

    <script>
        (() => {
            const field = document.getElementById('password')
            const button = document.getElementById('togglePassword')
            const show = document.getElementById('eyeShow')
            const hide = document.getElementById('eyeHide')

            // El botón solo aparece si este script corrió.
            button.hidden = false
            button.classList.add('flex')

            button.addEventListener('click', () => {
                const visible = field.type === 'text'

                field.type = visible ? 'password' : 'text'
                show.hidden = ! visible
                hide.hidden = visible
                button.setAttribute('aria-label', visible ? 'Mostrar la contraseña' : 'Ocultar la contraseña')
            })
        })()
    </script>
</x-layouts.guest>
