@props([
    'title',
    'description' => null,
    'branches' => [],
    'showFrom' => true,
    'warning' => null,
])

@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">{{ $title }}</h2>
            @if ($description)
                <p class="text-sm text-slate-500">{{ $description }}</p>
            @endif
        </div>

        @can('accounting.reports.export')
            <div class="flex gap-2">
                <button type="button" wire:click="exportPdf"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">
                    Exportar PDF
                </button>
                <button type="button" wire:click="exportExcel"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">
                    Exportar Excel
                </button>
            </div>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        @if ($showFrom)
            <label class="text-sm">
                <span class="mb-1 block font-medium text-slate-700">Desde</span>
                <input type="date" wire:model.live="from" class="{{ $input }}">
            </label>
        @endif

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">{{ $showFrom ? 'Hasta' : 'Al' }}</span>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </label>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Sucursal</span>
            <select wire:model.live="branchId" class="{{ $input }}">
                <option value="">Todas</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($warning)
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ $warning }}
        </div>
    @endif

    {{ $slot }}
</div>
