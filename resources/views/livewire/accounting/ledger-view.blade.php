@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Libro mayor</h2>
        <p class="text-sm text-slate-500">Movimientos contabilizados de una cuenta, con saldo acumulado.</p>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Cuenta</span>
            <input type="text" list="cuentas-mayor" wire:model.live="accountCode" placeholder="1.1.03.01"
                   class="{{ $input }} w-48 font-mono">
        </label>

        <datalist id="cuentas-mayor">
            @foreach ($accounts as $option)
                <option value="{{ $option->code }}">{{ $option->name }}</option>
            @endforeach
        </datalist>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Desde</span>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </label>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Hasta</span>
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

    @if ($account === null)
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
            Escribe el código de una cuenta para ver su mayor.
        </div>
    @elseif (! $account->is_postable)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            {{ $account->label() }} es una cuenta de agrupación y no tiene movimientos propios.
            Consulta una de sus cuentas de detalle.
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">{{ $account->label() }}</h3>
            <p class="text-sm text-slate-500">
                Naturaleza {{ mb_strtolower($account->nature->label()) }} ·
                {{ $account->type->label() }}
            </p>
        </div>

        <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Folio</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Concepto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Debe</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Haber</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    <tr role="row" class="bg-slate-50/60">
                        <td role="cell" colspan="5" class="px-4 py-1.5 text-right text-xs font-semibold tracking-wider text-slate-500 uppercase">
                            Saldo inicial
                        </td>
                        <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono font-semibold">
                            {{ $result['opening']->format() }}
                        </td>
                    </tr>

                    @forelse ($result['rows'] as $row)
                        <tr role="row" class="hover:bg-slate-50">
                            <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $row['date']->format('d/m/Y') }}</td>
                            <td role="cell" data-label="Folio" class="px-4 py-1.5 font-mono text-xs">{{ $row['number'] }}</td>
                            <td role="cell" data-label="Concepto" class="px-4 py-1.5">
                                {{ $row['concept'] }}
                                @if ($row['branch'])
                                    <span class="text-xs text-slate-500">· {{ $row['branch'] }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Debe" class="px-4 py-1.5 text-right font-mono">
                                {{ $row['debit']->isZero() ? '—' : $row['debit']->format() }}
                            </td>
                            <td role="cell" data-label="Haber" class="px-4 py-1.5 text-right font-mono">
                                {{ $row['credit']->isZero() ? '—' : $row['credit']->format() }}
                            </td>
                            <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono">{{ $row['balance']->format() }}</td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                                Sin movimientos en el rango seleccionado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot role="rowgroup" class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                    <tr role="row">
                        <td role="cell" colspan="3" class="px-4 py-2 text-right">Movimiento del período</td>
                        <td role="cell" data-label="Debe" class="px-4 py-2 text-right font-mono">{{ $result['debit']->format() }}</td>
                        <td role="cell" data-label="Haber" class="px-4 py-2 text-right font-mono">{{ $result['credit']->format() }}</td>
                        <td role="cell" data-label="Saldo final" class="px-4 py-2 text-right font-mono">{{ $result['closing']->format() }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
