@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <h2 class="mb-1 text-lg font-semibold">Crea tu cuenta</h2>
    <p class="mb-6 text-sm text-slate-500">
        En un minuto tienes tu empresa lista para facturar: catálogo de cuentas hondureño,
        ejercicio fiscal y bodega incluidos.
    </p>

    <form wire:submit="register" class="space-y-6">
        {{-- Plan --}}
        <fieldset>
            <legend class="mb-2 text-sm font-medium text-slate-700">Elige tu plan</legend>

            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ($plans as $plan)
                    <label class="cursor-pointer rounded-xl border p-4 transition
                                  {{ $planCode === $plan->code
                                        ? 'border-slate-900 bg-slate-50 ring-1 ring-slate-900'
                                        : 'border-slate-200 hover:border-slate-400' }}">
                        <span class="flex items-start justify-between">
                            <span class="text-sm font-semibold">{{ $plan->name }}</span>
                            <input type="radio" wire:model.live="planCode" value="{{ $plan->code }}"
                                   name="planCode" class="mt-0.5 border-slate-300 text-slate-900 focus:ring-slate-900">
                        </span>

                        <span class="mt-2 block font-mono text-lg font-semibold">
                            L {{ $plan->priceAmount()->format() }}
                        </span>
                        <span class="block text-xs text-slate-500">
                            al {{ $plan->interval === 'yearly' ? 'año' : 'mes' }}
                            @if ($plan->trial_days > 0)
                                · {{ $plan->trial_days }} días gratis
                            @endif
                        </span>

                        <span class="mt-3 block text-xs text-slate-600">
                            {{ $plan->max_companies === null ? 'Empresas ilimitadas' : $plan->max_companies.' empresa(s)' }},
                            {{ $plan->max_users === null ? 'usuarios ilimitados' : $plan->max_users.' usuario(s)' }}
                        </span>
                    </label>
                @endforeach
            </div>

            @error('planCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Tus datos --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Tu nombre" for="name" error="name">
                <input id="name" type="text" wire:model="name" autocomplete="name" class="{{ $input }}">
            </x-field>

            <x-field label="Correo electrónico" for="email" error="email">
                <input id="email" type="email" wire:model="email" autocomplete="username" class="{{ $input }}">
            </x-field>

            <x-field label="Contraseña" for="password" error="password">
                <input id="password" type="password" wire:model="password" autocomplete="new-password" class="{{ $input }}">
            </x-field>

            <x-field label="Repite la contraseña" for="password_confirmation">
                <input id="password_confirmation" type="password" wire:model="password_confirmation"
                       autocomplete="new-password" class="{{ $input }}">
            </x-field>
        </div>

        {{-- La empresa --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Razón social" for="legal_name" error="legal_name" class="sm:col-span-2"
                     hint="El nombre con el que la empresa está inscrita.">
                <input id="legal_name" type="text" wire:model="legal_name" class="{{ $input }}">
            </x-field>

            <x-field label="Nombre comercial" for="trade_name" error="trade_name">
                <input id="trade_name" type="text" wire:model="trade_name" class="{{ $input }}">
            </x-field>

            <x-field label="RTN" for="tax_id" error="tax_id">
                <input id="tax_id" type="text" wire:model="tax_id" inputmode="numeric" class="{{ $input }}">
            </x-field>

            <x-field label="Teléfono" for="phone" error="phone">
                <input id="phone" type="text" wire:model="phone" autocomplete="tel" class="{{ $input }}">
            </x-field>
        </div>

        <div>
            <label for="accepted" class="flex items-start gap-2 text-sm text-slate-600">
                <input id="accepted" type="checkbox" wire:model="accepted"
                       class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                Acepto los términos del servicio y el tratamiento de mis datos.
            </label>
            @error('accepted') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('login') }}" class="text-sm text-slate-600 underline hover:text-slate-900">
                Ya tengo cuenta
            </a>

            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-md bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:ring-2 focus:ring-slate-900 focus:ring-offset-2 focus:outline-none disabled:opacity-50">
                <span wire:loading.remove wire:target="register">Crear mi cuenta</span>
                <span wire:loading wire:target="register">Preparando tu empresa…</span>
            </button>
        </div>
    </form>
</div>
