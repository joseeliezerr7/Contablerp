<x-layouts.guest title="Sin empresa asignada">
    <h2 class="mb-1 text-lg font-semibold">Sin empresa asignada</h2>
    <p class="mb-6 text-sm text-slate-500">
        Tu usuario no tiene acceso a ninguna empresa activa. Pide a un administrador
        que te asigne una para poder entrar al sistema.
    </p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Cerrar sesión
        </button>
    </form>
</x-layouts.guest>
