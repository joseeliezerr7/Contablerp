<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Exceptions\ClosedPeriodException;
use App\Domains\Accounting\Exceptions\ImmutableEntryException;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Exceptions\UnbalancedEntryException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountBalance;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\PeriodService;
use App\Domains\Identity\Models\AuditLog;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->engine = app(AccountingEngine::class);

    // Cuentas del catálogo hondureño sembrado.
    $this->clientes = account('1.1.03.01');
    $this->ventas = account('4.1.01');
    $this->isv = account('2.1.02.01');
    $this->banco = account('1.1.02.01');
});

/*
|--------------------------------------------------------------------------
| La regla que sostiene el sistema: debe = haber
|--------------------------------------------------------------------------
*/

it('contabiliza una partida cuadrada', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Factura FAC-001')
            ->debit($this->clientes->id, '11500.00')
            ->credit($this->ventas->id, '10000.00')
            ->credit($this->isv->id, '1500.00')
    );

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->number)->not->toBeNull()
        ->and($entry->totalDebit()->equals(Money::of('11500')))->toBeTrue()
        ->and($entry->totalCredit()->equals(Money::of('11500')))->toBeTrue()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->lines)->toHaveCount(3);
});

it('rechaza una partida descuadrada', function () {
    $draft = JournalDraft::on('2026-03-15', 'Partida mal cuadrada')
        ->debit($this->clientes->id, '11500.00')
        ->credit($this->ventas->id, '10000.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(UnbalancedEntryException::class);

    expect(JournalEntry::query()->count())->toBe(0);
});

it('detecta descuadres de céntimos que un float ocultaría', function () {
    // 0.1 + 0.2 en coma flotante es 0.30000000000000004: con floats esta
    // partida se daría por cuadrada.
    $draft = JournalDraft::on('2026-03-15', 'Descuadre mínimo')
        ->debit($this->clientes->id, '0.30')
        ->credit($this->ventas->id, '0.1')
        ->credit($this->isv->id, '0.2001');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(UnbalancedEntryException::class);
});

it('suma importes con decimales sin perder precisión', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Prorrateo con decimales')
            ->debit($this->clientes->id, '1000.00')
            ->credit($this->ventas->id, '333.33')
            ->credit($this->isv->id, '333.33')
            ->credit($this->banco->id, '333.34')
    );

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->totalCredit()->toString())->toBe('1000.0000');
});

it('exige al menos dos líneas', function () {
    $draft = JournalDraft::on('2026-03-15', 'Una sola línea')
        ->debit($this->clientes->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(UnbalancedEntryException::class, 'al menos dos líneas');
});

it('rechaza importes en cero', function () {
    expect(fn () => JournalDraft::on('2026-03-15', 'Sin importe')
        ->debit($this->clientes->id, '0')
        ->credit($this->ventas->id, '0'))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Validación de cuentas
|--------------------------------------------------------------------------
*/

it('rechaza una cuenta de agrupación', function () {
    $grupo = account('1.1.03'); // Cuentas por Cobrar, tiene subcuentas

    $draft = JournalDraft::on('2026-03-15', 'Contra cuenta de agrupación')
        ->debit($grupo->id, '100.00')
        ->credit($this->ventas->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(InvalidAccountException::class, 'no admite movimientos');
});

it('rechaza una cuenta inactiva', function () {
    $this->clientes->forceFill(['is_active' => false])->save();

    $draft = JournalDraft::on('2026-03-15', 'Contra cuenta inactiva')
        ->debit($this->clientes->id, '100.00')
        ->credit($this->ventas->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(InvalidAccountException::class, 'inactiva');
});

it('rechaza una cuenta de otra empresa', function () {
    $otra = accountingCompany();
    $cuentaAjena = acrossCompanies(
        fn () => Account::acrossCompanies()->where('company_id', $otra->id)->where('code', '4.1.01')->firstOrFail()
    );

    $draft = JournalDraft::on('2026-03-15', 'Contra cuenta ajena')
        ->debit($this->clientes->id, '100.00')
        ->credit($cuentaAjena->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(InvalidAccountException::class);
});

/*
|--------------------------------------------------------------------------
| Períodos
|--------------------------------------------------------------------------
*/

it('rechaza contabilizar en un período cerrado', function () {
    // Los períodos se cierran en orden, así que hay que cerrar enero y febrero
    // antes de poder cerrar marzo.
    $periods = app(PeriodService::class);
    $periods->close(periodFor('2026-01-15'));
    $periods->close(periodFor('2026-02-15'));
    $periods->close(periodFor('2026-03-15'));

    $draft = JournalDraft::on('2026-03-15', 'Sobre período cerrado')
        ->debit($this->clientes->id, '100.00')
        ->credit($this->ventas->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(ClosedPeriodException::class);
});

it('rechaza una fecha sin período contable', function () {
    $draft = JournalDraft::on('2019-01-15', 'Fuera de todo ejercicio')
        ->debit($this->clientes->id, '100.00')
        ->credit($this->ventas->id, '100.00');

    expect(fn () => $this->engine->post($draft))
        ->toThrow(ClosedPeriodException::class, 'No existe un período contable');
});

/*
|--------------------------------------------------------------------------
| Idempotencia
|--------------------------------------------------------------------------
*/

it('no contabiliza dos veces el mismo documento', function () {
    $draft = fn () => JournalDraft::on('2026-03-15', 'Factura FAC-001')
        ->debit($this->clientes->id, '100.00')
        ->credit($this->ventas->id, '100.00')
        ->fromDocument('sale', 42);

    $this->engine->post($draft());

    expect(fn () => $this->engine->post($draft()))
        ->toThrow(AccountingException::class, 'ya tiene la partida');

    expect(JournalEntry::query()->count())->toBe(1);
});

it('permite recontabilizar un documento después de anular su partida', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Factura FAC-001')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
            ->fromDocument('sale', 42)
    );

    $this->engine->void($entry, 'Error de captura');

    $nueva = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Factura FAC-001 corregida')
            ->debit($this->clientes->id, '150.00')
            ->credit($this->ventas->id, '150.00')
            ->fromDocument('sale', 42)
    );

    expect($nueva->isPosted())->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Inmutabilidad, anulación y reversión
|--------------------------------------------------------------------------
*/

it('no permite editar una partida contabilizada', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Original')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $otro = JournalDraft::on('2026-03-15', 'Modificada')
        ->debit($this->clientes->id, '999.00')
        ->credit($this->ventas->id, '999.00');

    expect(fn () => $this->engine->updateDraft($entry, $otro))
        ->toThrow(ImmutableEntryException::class);
});

it('conserva la partida anulada en el historial', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Se anulará')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $numero = $entry->number;
    $anulada = $this->engine->void($entry, 'Cliente equivocado');

    expect($anulada->status)->toBe(JournalEntryStatus::Voided)
        ->and($anulada->number)->toBe($numero)
        ->and($anulada->void_reason)->toBe('Cliente equivocado')
        ->and($anulada->lines()->count())->toBe(2)
        ->and(JournalEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('exige un motivo para anular', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Sin motivo')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    expect(fn () => $this->engine->void($entry, '   '))
        ->toThrow(AccountingException::class, 'motivo');
});

it('no permite anular una partida de un período ya cerrado', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Marzo')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    app(PeriodService::class)->close(periodFor('2026-01-15'));
    app(PeriodService::class)->close(periodFor('2026-02-15'));
    app(PeriodService::class)->close(periodFor('2026-03-15'));

    expect(fn () => $this->engine->void($entry->refresh(), 'Tarde'))
        ->toThrow(AccountingException::class, 'Genera una reversión');
});

it('revierte invirtiendo cada línea y deja el original intacto', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Original')
            ->debit($this->clientes->id, '11500.00')
            ->credit($this->ventas->id, '10000.00')
            ->credit($this->isv->id, '1500.00')
    );

    $reversion = $this->engine->reverse($entry, 'Ajuste de auditoría', '2026-04-10');

    expect($reversion->type)->toBe(JournalEntryType::Reversal)
        ->and($reversion->reversal_of_id)->toBe($entry->id)
        ->and($reversion->isBalanced())->toBeTrue()
        ->and($entry->refresh()->status)->toBe(JournalEntryStatus::Posted);

    $lineaClientes = $reversion->lines->firstWhere('account_id', $this->clientes->id);
    $lineaVentas = $reversion->lines->firstWhere('account_id', $this->ventas->id);

    // En el original Clientes iba al debe y Ventas al haber; en la reversión,
    // exactamente al revés.
    expect($lineaClientes->creditAmount()->toString())->toBe('11500.0000')
        ->and($lineaClientes->debitAmount()->isZero())->toBeTrue()
        ->and($lineaVentas->debitAmount()->toString())->toBe('10000.0000');
});

it('deja el saldo neto en cero tras revertir', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Original')
            ->debit($this->clientes->id, '500.00')
            ->credit($this->ventas->id, '500.00')
    );

    $this->engine->reverse($entry, 'Se revierte', '2026-03-20');

    $debe = AccountBalance::query()->where('account_id', $this->clientes->id)->sum('period_debit');
    $haber = AccountBalance::query()->where('account_id', $this->clientes->id)->sum('period_credit');

    expect(Money::of((string) $debe)->minus(Money::of((string) $haber))->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Borradores
|--------------------------------------------------------------------------
*/

it('guarda un borrador sin folio ni efecto en los saldos', function () {
    $entry = $this->engine->saveDraft(
        JournalDraft::on('2026-03-15', 'Borrador')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    expect($entry->status)->toBe(JournalEntryStatus::Draft)
        ->and($entry->number)->toBeNull()
        ->and(AccountBalance::query()->count())->toBe(0);
});

it('asigna folio al contabilizar el borrador', function () {
    $entry = $this->engine->saveDraft(
        JournalDraft::on('2026-03-15', 'Borrador')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $contabilizada = $this->engine->postEntry($entry);

    expect($contabilizada->number)->not->toBeNull()
        ->and($contabilizada->status)->toBe(JournalEntryStatus::Posted)
        ->and(AccountBalance::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Saldos
|--------------------------------------------------------------------------
*/

it('acumula los saldos del período', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Venta 1')
            ->debit($this->clientes->id, '1000.00')
            ->credit($this->ventas->id, '1000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-20', 'Venta 2')
            ->debit($this->clientes->id, '500.00')
            ->credit($this->ventas->id, '500.00')
    );

    $periodo = periodFor('2026-03-15');

    $saldo = AccountBalance::query()
        ->where('account_id', $this->clientes->id)
        ->where('accounting_period_id', $periodo->id)
        ->firstOrFail();

    expect($saldo->debit()->toString())->toBe('1500.0000')
        ->and($saldo->credit()->isZero())->toBeTrue();
});

it('separa los saldos por período', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Marzo')
            ->debit($this->clientes->id, '1000.00')
            ->credit($this->ventas->id, '1000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-04-05', 'Abril')
            ->debit($this->clientes->id, '700.00')
            ->credit($this->ventas->id, '700.00')
    );

    expect(AccountBalance::query()->where('account_id', $this->clientes->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Numeración
|--------------------------------------------------------------------------
*/

it('numera las partidas de forma correlativa y sin repetir', function () {
    $numeros = collect(range(1, 5))->map(fn (int $i) => $this->engine->post(
        JournalDraft::on('2026-03-15', "Partida {$i}")
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    )->number);

    expect($numeros->unique())->toHaveCount(5)
        ->and($numeros->first())->toBe('PD-2026-000001')
        ->and($numeros->last())->toBe('PD-2026-000005');
});

/*
|--------------------------------------------------------------------------
| Auditoría
|--------------------------------------------------------------------------
*/

it('registra en la bitácora al contabilizar y al anular', function () {
    $entry = $this->engine->post(
        JournalDraft::on('2026-03-15', 'Auditada')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $this->engine->void($entry, 'Motivo de prueba');

    $eventos = AuditLog::query()
        ->forModel($entry)
        ->pluck('event')
        ->all();

    expect($eventos)->toContain('posted')
        ->and($eventos)->toContain('voided');

    $anulacion = AuditLog::query()
        ->forModel($entry)
        ->where('event', 'voided')
        ->firstOrFail();

    expect($anulacion->reason)->toBe('Motivo de prueba')
        ->and($anulacion->user_id)->toBe($this->user->id);
});
