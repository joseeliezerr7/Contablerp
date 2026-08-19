@php
    /** @var \App\Support\Tenancy\CompanyContext $context */
    $context = app(\App\Support\Tenancy\CompanyContext::class);
    $company = $context->company();
    $user = auth()->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Panel' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
{{-- `sidebar` solo gobierna el cajón en pantallas pequeñas; en escritorio el
     menú está siempre fijo. --}}
<div x-data="appShell" @keydown.escape.window="close()" class="flex min-h-full">

    {{-- Fondo oscuro del cajón móvil --}}
    <div x-show="sidebar" x-cloak x-transition.opacity @click="close()"
         class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden" aria-hidden="true"></div>

    {{-- Menú lateral --}}
    {{-- `x-trap` solo se activa cuando el cajón está abierto, cosa que en
         escritorio no ocurre nunca. Mientras está abierto lleva el foco al
         botón de cerrar, lo mantiene dentro del cajón —tabular no se escapa a
         la página de fondo— y al cerrarse lo devuelve al botón que lo abrió.
         `.noscroll` impide además que el fondo se desplace por debajo. --}}
    <aside aria-label="Menú lateral"
           x-trap.noscroll="sidebar"
           :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col bg-slate-900 text-slate-300 transition-transform duration-200 lg:static lg:w-60 lg:translate-x-0">
        <div class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-800 px-4">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white text-sm font-bold text-slate-900">C</span>
            <span class="truncate text-sm font-semibold text-white">{{ config('app.name') }}</span>

            <button type="button" @click="close()"
                    class="ml-auto rounded-md p-1 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden"
                    aria-label="Cerrar menú">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                </svg>
            </button>
        </div>

        {{-- El cajón se cierra al elegir una opción, pero **no** al desplegar
             una sección: plegar y desplegar ocurre dentro del propio menú. --}}
        <nav aria-label="Menú principal" @click="$event.target.closest('a') && close()"
             class="flex-1 space-y-2 overflow-y-auto px-3 py-4 text-sm">
            <div class="space-y-1">
                @unless ($user->isSuperAdmin() && ! $company)
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        Dashboard
                    </x-nav-link>
                @endunless

                {{-- Área del proveedor. No pasa por permisos de empresa porque no
                     pertenece a ninguna: es otro eje de autorización. --}}
                @if ($user->isSuperAdmin())
                    <x-nav-link :href="route('admin.tenants.index')" :active="request()->routeIs('admin.tenants.*')">
                        Cuentas del servicio
                    </x-nav-link>
                    <x-nav-link :href="route('admin.plans.index')" :active="request()->routeIs('admin.plans.*')">
                        Planes del servicio
                    </x-nav-link>
                @endif
            </div>

            @canany(['sales.invoices.view', 'customers.view', 'receipts.view'])
                <x-nav-section label="Ventas" name="ventas"
                               :active="request()->routeIs('sales.*', 'receipts.*', 'customers.*', 'products.*', 'receivables.*', 'credit-notes.*', 'catalog.*', 'pos')">
                        @can('sales.invoices.create')
                            <x-nav-link :href="route('pos')" :active="request()->routeIs('pos')">
                                Punto de venta
                            </x-nav-link>
                        @endcan
                        @can('sales.invoices.view')
                            <x-nav-link :href="route('sales.index')" :active="request()->routeIs('sales.*')">
                                Facturas
                            </x-nav-link>
                        @endcan
                        @can('sales.credit_notes.view')
                            <x-nav-link :href="route('credit-notes.index')" :active="request()->routeIs('credit-notes.*')">
                                Notas de crédito
                            </x-nav-link>
                        @endcan
                        @can('receipts.view')
                            <x-nav-link :href="route('receipts.index')" :active="request()->routeIs('receipts.*')">
                                Recibos de cobro
                            </x-nav-link>
                        @endcan
                        @can('customers.view')
                            <x-nav-link :href="route('customers.index')" :active="request()->routeIs('customers.*')">
                                Clientes
                            </x-nav-link>
                        @endcan
                        @can('catalog.products.view')
                            <x-nav-link :href="route('products.index')" :active="request()->routeIs('products.*')">
                                Productos
                            </x-nav-link>
                        @endcan
                        @can('catalog.masters.view')
                            <x-nav-link :href="route('catalog.index')" :active="request()->routeIs('catalog.*')">
                                Catálogos
                            </x-nav-link>
                        @endcan
                        @can('receivables.reports')
                            <x-nav-link :href="route('receivables.aging')" :active="request()->routeIs('receivables.aging')">
                                Antigüedad de saldos
                            </x-nav-link>
                            <x-nav-link :href="route('receivables.statement')" :active="request()->routeIs('receivables.statement')">
                                Estado de cuenta
                            </x-nav-link>
                        @endcan
                </x-nav-section>
            @endcanany

            @canany(['purchases.view', 'suppliers.view', 'payments.view'])
                <x-nav-section label="Compras" name="compras"
                               :active="request()->routeIs('purchases.*', 'payments.*', 'suppliers.*', 'payables.*')">
                        @can('purchases.view')
                            <x-nav-link :href="route('purchases.index')" :active="request()->routeIs('purchases.*')">
                                Compras
                            </x-nav-link>
                        @endcan
                        @can('payments.view')
                            <x-nav-link :href="route('payments.index')" :active="request()->routeIs('payments.*')">
                                Pagos a proveedores
                            </x-nav-link>
                        @endcan
                        @can('suppliers.view')
                            <x-nav-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')">
                                Proveedores
                            </x-nav-link>
                        @endcan
                        @can('payables.reports')
                            <x-nav-link :href="route('payables.aging')" :active="request()->routeIs('payables.aging')">
                                Antigüedad por pagar
                            </x-nav-link>
                            <x-nav-link :href="route('payables.statement')" :active="request()->routeIs('payables.statement')">
                                Estado de cuenta
                            </x-nav-link>
                        @endcan
                </x-nav-section>
            @endcanany

            @canany(['inventory.stock.view', 'inventory.kardex.view', 'inventory.adjustments.view', 'inventory.transfers.view'])
                <x-nav-section label="Inventario" name="inventario"
                               :active="request()->routeIs('inventory.*')">
                        @can('inventory.stock.view')
                            <x-nav-link :href="route('inventory.stock')" :active="request()->routeIs('inventory.stock')">
                                Existencias
                            </x-nav-link>
                        @endcan
                        @can('inventory.kardex.view')
                            <x-nav-link :href="route('inventory.kardex')" :active="request()->routeIs('inventory.kardex')">
                                Kardex
                            </x-nav-link>
                        @endcan
                        @can('inventory.adjustments.view')
                            <x-nav-link :href="route('inventory.adjustments.index')" :active="request()->routeIs('inventory.adjustments.*')">
                                Ajustes
                            </x-nav-link>
                        @endcan
                        @can('inventory.transfers.view')
                            <x-nav-link :href="route('inventory.transfers.index')" :active="request()->routeIs('inventory.transfers.*')">
                                Traslados
                            </x-nav-link>
                        @endcan
                </x-nav-section>
            @endcanany

            @canany(['treasury.banks.view', 'treasury.checks.view', 'treasury.reconciliation.view', 'treasury.cash.view'])
                <x-nav-section label="Tesorería" name="tesoreria" :active="request()->routeIs('treasury.*')">
                    @can('treasury.banks.view')
                        <x-nav-link :href="route('treasury.banks.index')" :active="request()->routeIs('treasury.banks.*')">
                            Cuentas bancarias
                        </x-nav-link>
                    @endcan
                    @can('treasury.reconciliation.view')
                        <x-nav-link :href="route('treasury.reconciliations.index')" :active="request()->routeIs('treasury.reconciliations.*')">
                            Conciliación
                        </x-nav-link>
                    @endcan
                    @can('treasury.checks.view')
                        <x-nav-link :href="route('treasury.checks.index')" :active="request()->routeIs('treasury.checks.*')">
                            Cheques
                        </x-nav-link>
                    @endcan
                    @can('treasury.cash.view')
                        <x-nav-link :href="route('treasury.cash.index')" :active="request()->routeIs('treasury.cash.*')">
                            Caja
                        </x-nav-link>
                    @endcan
                </x-nav-section>
            @endcanany

            @canany(['assets.view', 'assets.depreciation.view', 'taxes.withholdings.view', 'catalog.taxes.view'])
                <x-nav-section label="Activos e impuestos" name="activos"
                               :active="request()->routeIs('assets.*', 'taxes.*')">
                    @can('assets.view')
                        <x-nav-link :href="route('assets.index')" :active="request()->routeIs('assets.index')">
                            Activos fijos
                        </x-nav-link>
                        <x-nav-link :href="route('assets.categories.index')" :active="request()->routeIs('assets.categories.*')">
                            Categorías de activo
                        </x-nav-link>
                    @endcan
                    @can('assets.depreciation.view')
                        <x-nav-link :href="route('assets.depreciation.index')" :active="request()->routeIs('assets.depreciation.*')">
                            Depreciación
                        </x-nav-link>
                    @endcan
                    @can('catalog.taxes.view')
                        <x-nav-link :href="route('taxes.index')" :active="request()->routeIs('taxes.index')">
                            Impuestos
                        </x-nav-link>
                    @endcan
                    @can('taxes.withholdings.view')
                        <x-nav-link :href="route('taxes.withholdings.index')" :active="request()->routeIs('taxes.withholdings.*')">
                            Retenciones
                        </x-nav-link>
                    @endcan
                </x-nav-section>
            @endcanany

            @canany(['accounting.journal.view', 'accounting.ledger.view', 'accounting.accounts.view', 'accounting.periods.view', 'accounting.mappings.view'])
                <x-nav-section label="Contabilidad" name="contabilidad"
                               :active="request()->routeIs('journal.*', 'ledger.*', 'accounts.*', 'periods.*', 'accounting.mappings.*')">
                    @can('accounting.journal.view')
                        <x-nav-link :href="route('journal.index')" :active="request()->routeIs('journal.*')">
                            Libro diario
                        </x-nav-link>
                    @endcan
                    @can('accounting.ledger.view')
                        <x-nav-link :href="route('ledger.index')" :active="request()->routeIs('ledger.*')">
                            Libro mayor
                        </x-nav-link>
                    @endcan
                    @can('accounting.accounts.view')
                        <x-nav-link :href="route('accounts.index')" :active="request()->routeIs('accounts.*')">
                            Plan de cuentas
                        </x-nav-link>
                    @endcan
                    @can('accounting.mappings.view')
                        <x-nav-link :href="route('accounting.mappings.index')" :active="request()->routeIs('accounting.mappings.*')">
                            Cuentas por módulo
                        </x-nav-link>
                    @endcan
                    @can('accounting.periods.view')
                        <x-nav-link :href="route('periods.index')" :active="request()->routeIs('periods.*')">
                            Períodos
                        </x-nav-link>
                    @endcan
                </x-nav-section>
            @endcanany

            @can('accounting.reports.view')
                <x-nav-section label="Reportes" name="reportes" :active="request()->routeIs('reports.*')">
                        <x-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.index')">
                            Centro de reportes
                        </x-nav-link>
                        <x-nav-link :href="route('reports.trial-balance')" :active="request()->routeIs('reports.trial-balance')">
                            Balance de comprobación
                        </x-nav-link>
                        <x-nav-link :href="route('reports.income-statement')" :active="request()->routeIs('reports.income-statement')">
                            Estado de resultados
                        </x-nav-link>
                        <x-nav-link :href="route('reports.balance-sheet')" :active="request()->routeIs('reports.balance-sheet')">
                            Balance general
                        </x-nav-link>
                        <x-nav-link :href="route('reports.cash-flow')" :active="request()->routeIs('reports.cash-flow')">
                            Flujo de efectivo
                        </x-nav-link>
                </x-nav-section>
            @endcan

            @canany(['companies.view', 'branches.view', 'warehouses.view', 'fiscal.points.view', 'api.tokens.view', 'users.view'])
                <x-nav-section label="Configuración" name="configuracion"
                               :active="request()->routeIs('companies.*', 'branches.*', 'warehouses.*', 'fiscal.*', 'api.tokens.*', 'users.*', 'audit.*')">
                    @can('companies.view')
                        <x-nav-link :href="route('companies.index')" :active="request()->routeIs('companies.*')">
                            Empresas
                        </x-nav-link>
                    @endcan
                    @can('users.view')
                        <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                            Usuarios
                        </x-nav-link>
                    @endcan
                    @can('audit.view')
                        <x-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.*')">
                            Bitácora
                        </x-nav-link>
                    @endcan
                    @can('fiscal.points.view')
                        <x-nav-link :href="route('fiscal.points.index')" :active="request()->routeIs('fiscal.*')">
                            Facturación (CAI)
                        </x-nav-link>
                    @endcan
                    @can('api.tokens.view')
                        <x-nav-link :href="route('api.tokens.index')" :active="request()->routeIs('api.tokens.*')">
                            Tokens de API
                        </x-nav-link>
                    @endcan
                    @can('branches.view')
                        <x-nav-link :href="route('branches.index')" :active="request()->routeIs('branches.*')">
                            Sucursales
                        </x-nav-link>
                    @endcan
                    @can('warehouses.view')
                        <x-nav-link :href="route('warehouses.index')" :active="request()->routeIs('warehouses.*')">
                            Bodegas
                        </x-nav-link>
                    @endcan
                </x-nav-section>
            @endcanany
        </nav>
    </aside>

    {{-- Con el cajón abierto, el resto de la página queda inerte: no recibe
         foco ni clics, y los lectores de pantalla no se pasean por debajo del
         menú. `|| null` hace que el atributo se quite del todo al cerrarlo,
         en vez de quedar como inert="false", que seguiría siendo inerte. --}}
    <div :inert="sidebar || null" class="flex min-w-0 flex-1 flex-col">

        {{-- Barra superior --}}
        <header class="flex h-14 shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" @click="open()" x-ref="menuButton"
                        class="-ml-1 rounded-md p-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
                        aria-label="Abrir menú">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <h1 class="truncate text-sm font-semibold">{{ $title ?? 'Panel' }}</h1>
            </div>

            <div class="flex items-center gap-3">
                {{-- Selector de empresa --}}
                @if ($company)
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" @click.outside="open = false"
                                data-active-company="{{ $company->id }}"
                                class="flex max-w-56 items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            <span class="truncate font-medium">{{ $company->displayName() }}</span>
                            @if ($context->branch())
                                <span class="truncate text-xs text-slate-500">/ {{ $context->branch()->name }}</span>
                            @endif
                            <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>

                        <div x-show="open" x-cloak
                             class="absolute right-0 z-20 mt-1 w-72 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                            <p class="px-3 py-1.5 text-xs font-semibold tracking-wider text-slate-400 uppercase">
                                Cambiar de empresa
                            </p>
                            @foreach ($user->companies()->orderBy('legal_name')->get() as $option)
                                <form method="POST" action="{{ route('company.switch', $option) }}">
                                    @csrf
                                    <button type="submit"
                                            class="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50 {{ $option->is($company) ? 'bg-slate-50 font-semibold' : '' }}">
                                        <span class="mt-0.5 h-4 w-4 shrink-0">
                                            @if ($option->is($company))
                                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-emerald-600">
                                                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/>
                                                </svg>
                                            @endif
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate">{{ $option->displayName() }}</span>
                                            <span class="block truncate text-xs text-slate-500">RTN {{ $option->tax_id }}</span>
                                        </span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Menú de usuario --}}
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open" @click.outside="open = false"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                        {{ Str::of($user->name)->substr(0, 2)->upper() }}
                    </button>

                    <div x-show="open" x-cloak
                         class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                        <div class="border-b border-slate-100 px-3 py-2">
                            <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50">
                                Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- El mensaje flash lo renderiza cada componente Livewire, no el layout:
             una acción de Livewire solo re-renderiza el componente. Ponerlo en
             ambos lados lo duplicaría tras una redirección. --}}
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
