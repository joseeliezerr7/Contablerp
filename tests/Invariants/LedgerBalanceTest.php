<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\AccountBalance;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Invariante fundamental del sistema.
 *
 * Se ejercitan todos los caminos que escriben en el libro —alta, borrador
 * contabilizado, anulación, reversión y varias empresas a la vez— y después se
 * comprueba que el libro sigue cuadrado. Si algún módulo futuro descuadra la
 * contabilidad, esta prueba falla aunque las suyas pasen.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->engine = app(AccountingEngine::class);
});

function exerciseEveryWritePath(AccountingEngine $engine): void
{
    $clientes = account('1.1.03.01');
    $ventas = account('4.1.01');
    $isv = account('2.1.02.01');
    $banco = account('1.1.02.01');
    $inventario = account('1.1.04.01');
    $proveedores = account('2.1.01.01');
    $costo = account('5.1.01');

    // Venta con impuesto.
    $engine->post(
        JournalDraft::on('2026-03-05', 'Venta FAC-001')
            ->debit($clientes->id, '11500.00')
            ->credit($ventas->id, '10000.00')
            ->credit($isv->id, '1500.00')
    );

    // Compra con impuesto acreditable.
    $engine->post(
        JournalDraft::on('2026-03-06', 'Compra FAC-PROV-77')
            ->debit($inventario->id, '8000.00')
            ->debit(account('1.1.05.01')->id, '1200.00')
            ->credit($proveedores->id, '9200.00')
    );

    // Costo de ventas.
    $engine->post(
        JournalDraft::on('2026-03-06', 'Costo de la venta')
            ->debit($costo->id, '6000.00')
            ->credit($inventario->id, '6000.00')
    );

    // Cobro.
    $engine->post(
        JournalDraft::on('2026-03-10', 'Cobro parcial')
            ->debit($banco->id, '5000.00')
            ->credit($clientes->id, '5000.00')
    );

    // Borrador contabilizado después.
    $borrador = $engine->saveDraft(
        JournalDraft::on('2026-03-12', 'Pago a proveedor')
            ->debit($proveedores->id, '9200.00')
            ->credit($banco->id, '9200.00')
    );
    $engine->postEntry($borrador);

    // Partida anulada.
    $anulable = $engine->post(
        JournalDraft::on('2026-03-14', 'Se anulará')
            ->debit($clientes->id, '750.00')
            ->credit($ventas->id, '750.00')
    );
    $engine->void($anulable, 'Error de captura durante la prueba');

    // Partida revertida.
    $revertible = $engine->post(
        JournalDraft::on('2026-03-16', 'Se revertirá')
            ->debit($clientes->id, '1234.56')
            ->credit($ventas->id, '1234.56')
    );
    $engine->reverse($revertible, 'Ajuste durante la prueba', '2026-04-02');

    // Importes con decimales que no reparten en partes iguales.
    $engine->post(
        JournalDraft::on('2026-03-18', 'Prorrateo de flete')
            ->debit($inventario->id, '333.33')
            ->debit($costo->id, '333.33')
            ->debit($banco->id, '333.34')
            ->credit($proveedores->id, '1000.00')
    );

    // Borrador que se queda sin contabilizar: no debe afectar nada.
    $engine->saveDraft(
        JournalDraft::on('2026-03-20', 'Queda en borrador')
            ->debit($clientes->id, '99999.99')
            ->credit($ventas->id, '99999.99')
    );
}

it('mantiene SUM(debitos) = SUM(creditos) en todo el libro', function () {
    exerciseEveryWritePath($this->engine);

    $totals = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    $debit = Money::of((string) $totals->debit);
    $credit = Money::of((string) $totals->credit);

    expect($debit->isPositive())->toBeTrue('La prueba no ejercitó ninguna partida')
        ->and($debit->equals($credit))->toBeTrue(
            "El libro está descuadrado: debe {$debit->format()}, haber {$credit->format()}."
        );
});

it('mantiene cada partida cuadrada por separado', function () {
    exerciseEveryWritePath($this->engine);

    $descuadradas = JournalEntry::query()
        ->with('lines')
        ->get()
        ->filter(function (JournalEntry $entry): bool {
            $debit = Money::sum($entry->lines->map(fn ($l) => $l->debitAmount())->all());
            $credit = Money::sum($entry->lines->map(fn ($l) => $l->creditAmount())->all());

            return ! $debit->equals($credit);
        });

    expect($descuadradas)->toBeEmpty(
        'Partidas descuadradas: '.$descuadradas->pluck('number')->implode(', ')
    );
});

it('mantiene los totales de cabecera iguales a la suma de sus líneas', function () {
    exerciseEveryWritePath($this->engine);

    $inconsistentes = JournalEntry::query()
        ->with('lines')
        ->get()
        ->filter(function (JournalEntry $entry): bool {
            $debit = Money::sum($entry->lines->map(fn ($l) => $l->debitAmount())->all());
            $credit = Money::sum($entry->lines->map(fn ($l) => $l->creditAmount())->all());

            return ! $debit->equals($entry->totalDebit()) || ! $credit->equals($entry->totalCredit());
        });

    expect($inconsistentes)->toBeEmpty(
        'Cabeceras que no coinciden con sus líneas: '.$inconsistentes->pluck('id')->implode(', ')
    );
});

it('mantiene los saldos materializados iguales al diario', function () {
    exerciseEveryWritePath($this->engine);

    // account_balances es una materialización: debe coincidir con la suma de
    // las líneas contabilizadas y no anuladas, cuenta por cuenta.
    $fromLedger = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->groupBy('l.account_id', 'e.accounting_period_id')
        ->selectRaw('l.account_id, e.accounting_period_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
        ->get()
        ->keyBy(fn ($row) => $row->account_id.':'.$row->accounting_period_id);

    $materialized = AccountBalance::query()->get()
        ->keyBy(fn ($row) => $row->account_id.':'.$row->accounting_period_id);

    foreach ($fromLedger as $key => $expected) {
        $actual = $materialized->get($key);

        expect($actual)->not->toBeNull("Falta el saldo materializado de {$key}")
            ->and($actual->debit()->equals(Money::of((string) $expected->debit)))->toBeTrue(
                "Debe distinto en {$key}: diario {$expected->debit}, saldo {$actual->period_debit}"
            )
            ->and($actual->credit()->equals(Money::of((string) $expected->credit)))->toBeTrue(
                "Haber distinto en {$key}: diario {$expected->credit}, saldo {$actual->period_credit}"
            );
    }
});

it('mantiene el libro cuadrado con varias empresas operando', function () {
    exerciseEveryWritePath($this->engine);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);
    exerciseEveryWritePath($this->engine);

    $porEmpresa = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->groupBy('l.company_id')
        ->selectRaw('l.company_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
        ->get();

    expect($porEmpresa)->toHaveCount(2);

    foreach ($porEmpresa as $row) {
        expect(Money::of((string) $row->debit)->equals(Money::of((string) $row->credit)))
            ->toBeTrue("La empresa {$row->company_id} tiene el libro descuadrado.");
    }
});

it('no deja líneas con debe y haber a la vez', function () {
    exerciseEveryWritePath($this->engine);

    $invalidas = DB::table('journal_entry_lines')
        ->where(fn ($q) => $q->where(fn ($w) => $w->where('debit', '>', 0)->where('credit', '>', 0))
            ->orWhere(fn ($w) => $w->where('debit', 0)->where('credit', 0)))
        ->count();

    expect($invalidas)->toBe(0);
});
