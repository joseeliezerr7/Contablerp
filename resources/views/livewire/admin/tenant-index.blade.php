@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Cuentas del servicio</h2>
        <p class="text-sm text-slate-500">Panel del proveedor. Es la única pantalla que mira entre cuentas.</p>
    </div>

    {{-- Métricas del negocio --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">Ingreso recurrente</p>
            <p class="mt-1 font-mono text-2xl font-semibold">{{ $summary['mrr']->format() }}</p>
            <p class="text-xs text-slate-500">al mes, de las cuentas que pagan</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">Cuentas activas</p>
            <p class="mt-1 font-mono text-2xl font-semibold">{{ $summary['active'] }}</p>
            <p class="text-xs text-slate-500">{{ $summary['trialing'] }} en prueba · {{ $summary['cancelled'] }} canceladas</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">Por cobrar</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $summary['outstanding']->isPositive() ? 'text-amber-700' : '' }}">
                {{ $summary['outstanding']->format() }}
            </p>
            <p class="text-xs text-slate-500">{{ $summary['past_due'] }} con pago pendiente</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">Pruebas por vencer</p>
            <p class="mt-1 font-mono text-2xl font-semibold">{{ $summary['expiring_trials'] }}</p>
            <p class="text-xs text-slate-500">en los próximos 7 días</p>
        </div>
    </div>

    @if ($byPlan !== [])
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($byPlan as $row)
                <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs">
                    <span class="font-semibold">{{ $row['plan'] }}</span>
                    · {{ $row['count'] }} cuenta(s)
                    · <span class="font-mono">{{ $row['mrr']->format() }}</span>
                </span>
            @endforeach
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Nombre de la cuenta…"
               class="{{ $input }} w-64">
        <select wire:model.live="statusFilter" class="{{ $input }}">
            <option value="">Todos los estados</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuenta</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Plan</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Precio</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Consumo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Período</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($subscriptions as $subscription)
                    @php $usage = $subscription->getAttribute('usage'); @endphp
                    <tr role="row" class="hover:bg-slate-50 {{ $subscription->isCancelled() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Cuenta" class="px-4 py-1.5">
                            <span class="font-medium">{{ $subscription->tenant->name }}</span>
                            <span class="block font-mono text-xs text-slate-500">{{ $subscription->tenant->slug }}</span>
                        </td>
                        <td role="cell" data-label="Plan" class="px-4 py-1.5">{{ $subscription->plan->name }}</td>
                        <td role="cell" data-label="Precio" class="px-4 py-1.5 text-right font-mono">
                            {{ $subscription->priceAmount()->format() }}
                            <span class="block text-xs text-slate-500">
                                {{ $subscription->interval === 'yearly' ? 'al año' : 'al mes' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Consumo" class="px-4 py-1.5 text-xs text-slate-600">
                            {{ $usage['companies'] }} empresa(s) ·
                            {{ $usage['users'] }} usuario(s) ·
                            {{ $usage['branches'] }} sucursal(es)
                        </td>
                        <td role="cell" data-label="Período" class="px-4 py-1.5 text-xs whitespace-nowrap">
                            {{ $subscription->current_period_start->format('d/m/Y') }} –
                            {{ $subscription->current_period_end->format('d/m/Y') }}
                            @if ($subscription->isTrialing() && $subscription->trial_ends_at)
                                <span class="block text-sky-700">
                                    prueba: {{ $subscription->trialDaysLeft() }} día(s)
                                </span>
                            @endif
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $subscription->status->badgeClasses() }}">
                                {{ $subscription->status->label() }}
                            </span>
                        </td>
                        {{-- Las acciones se envuelven en vez de ensanchar la tabla:
                             con la de cobro son seis, y en una pantalla de portátil
                             empujaban el resto fuera de la vista. --}}
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            @unless ($subscription->isCancelled())
                                <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                    <button type="button" wire:click="confirm({{ $subscription->id }}, 'renew')"
                                            class="text-slate-600 underline hover:text-slate-900">Facturar</button>
                                    @if ($subscription->pending_invoices_count > 0)
                                        <button type="button" wire:click="confirm({{ $subscription->id }}, 'pay')"
                                                class="font-medium text-amber-700 underline hover:text-amber-900">
                                            Cobrar ({{ $subscription->pending_invoices_count }})
                                        </button>
                                    @endif
                                    <button type="button" wire:click="confirm({{ $subscription->id }}, 'change_plan')"
                                            class="text-slate-600 underline hover:text-slate-900">Plan</button>
                                    @if ($subscription->status !== \App\Domains\Billing\Enums\SubscriptionStatus::Active)
                                        <button type="button" wire:click="confirm({{ $subscription->id }}, 'activate')"
                                                class="text-emerald-700 underline hover:text-emerald-900">Activar</button>
                                    @endif
                                    <button type="button" wire:click="confirm({{ $subscription->id }}, 'suspend')"
                                            class="text-amber-700 underline hover:text-amber-900">Suspender</button>
                                    <button type="button" wire:click="confirm({{ $subscription->id }}, 'cancel')"
                                            class="text-red-600 underline hover:text-red-800">Cancelar</button>
                                </div>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay cuentas registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscriptions->links() }}</div>

    @if ($actingOn)
        <x-modal :title="$action === 'pay' ? 'Facturas pendientes de cobro' : 'Cambiar la suscripción'"
                 onClose="cancelAction">
            <form wire:submit="apply">
                <div class="space-y-3 p-5">
                    @error('action')
                        <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
                    @enderror

                    @if ($action === 'change_plan')
                        <x-field label="Nuevo plan" for="newPlanId" error="newPlanId"
                                 hint="El precio nuevo entra en el período siguiente: no se prorratea.">
                            <select id="newPlanId" wire:model="newPlanId" class="{{ $input }} w-full">
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}">
                                        {{ $plan->name }} — {{ $plan->priceAmount()->format() }}
                                    </option>
                                @endforeach
                            </select>
                        </x-field>
                    @elseif ($action === 'pay')
                        <div class="divide-y divide-slate-100 rounded-md border border-slate-200">
                            @foreach ($pending as $invoice)
                                <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                    <div class="text-sm">
                                        <span class="font-mono font-medium">{{ $invoice->number }}</span>
                                        <span class="block text-xs text-slate-500">
                                            {{ $invoice->period_start->format('d/m/Y') }} –
                                            {{ $invoice->period_end->format('d/m/Y') }}
                                            · vence {{ $invoice->due_on->format('d/m/Y') }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="font-mono text-sm">{{ $invoice->amountMoney()->format() }}</span>
                                        @if ($invoice->isOverdue())
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">vencida</span>
                                        @endif
                                        <button type="button" wire:click="pay({{ $invoice->id }})"
                                                class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
                                            Registrar cobro
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <x-field label="Referencia del cobro" for="paymentReference" error="paymentReference"
                                 hint="El número de la transferencia o del depósito. Es lo que permite después saber qué se cobró.">
                            <input id="paymentReference" type="text" wire:model="paymentReference" class="{{ $input }} w-full">
                        </x-field>
                    @elseif ($action === 'renew')
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            Se emite la factura del período en curso y se avanza al siguiente. La cuenta
                            queda con pago pendiente pero <strong>sigue trabajando</strong>: cortar el
                            acceso es una decisión aparte.
                        </p>
                    @elseif ($action === 'activate')
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            La cuenta pasa a activa y termina el período de prueba.
                        </p>
                    @else
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            @if ($action === 'suspend')
                                Se le corta el acceso al sistema hasta que se reactive. Sus datos se conservan.
                            @else
                                La cuenta queda cancelada. Sus datos se conservan y podría volver a suscribirse.
                            @endif
                        </p>

                        <x-field label="Motivo" for="reason" error="reason">
                            <textarea id="reason" wire:model="reason" rows="3" class="{{ $input }} w-full"></textarea>
                        </x-field>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelAction"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        {{ $action === 'pay' ? 'Cerrar' : 'Cancelar' }}
                    </button>

                    {{-- En el cobro cada factura tiene su propio botón: no hay
                         una única acción que confirmar. --}}
                    @unless ($action === 'pay')
                        <button type="submit"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            Confirmar
                        </button>
                    @endunless
                </div>
            </form>
        </x-modal>
    @endif
</div>
