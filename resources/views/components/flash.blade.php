{{--
    Se incluye en el layout (para redirecciones, como el cambio de empresa) y
    también dentro de cada componente Livewire: una acción de Livewire re-renderiza
    solo el componente, así que un flash puesto únicamente en el layout nunca se ve.
--}}
@if (session('success'))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        {{ session('error') }}
    </div>
@endif
