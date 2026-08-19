<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\CashSession;
use App\Domains\Treasury\Services\CashSessionService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->sessions = app(CashSessionService::class);
    $this->engine = app(AccountingEngine::class);

    $this->branch = mainBranch();
    $this->caja = account('1.1.01.01');
    $this->ventas = account('4.1.01');
});

/**
 * Abre una sesión de caja con el fondo indicado.
 */
function openTill(object $ctx, string $float = '500.00'): CashSession
{
    return $ctx->sessions->open([
        'branch_id' => $ctx->branch->id,
        'account_id' => $ctx->caja->id,
        'opening_float' => $float,
    ]);
}

/**
 * Cobro en efectivo durante el turno.
 */
function cashIn(object $ctx, string $amount): void
{
    $ctx->engine->post(
        JournalDraft::on(now()->toDateString(), 'Venta de contado')
            ->inBranch($ctx->branch->id)
            ->debit($ctx->caja->id, $amount)
            ->credit($ctx->ventas->id, $amount)
    );
}

/*
|--------------------------------------------------------------------------
| Apertura
|--------------------------------------------------------------------------
*/

it('abre la caja con su fondo y su correlativo', function () {
    $sesion = openTill($this, '500.00');

    expect($sesion->number)->toBe('CAJ-000001')
        ->and($sesion->isOpen())->toBeTrue()
        ->and($sesion->openingFloat()->toString())->toBe('500.0000');
});

it('no genera partida al abrir', function () {
    openTill($this);

    // El fondo ya estaba en la cuenta: declararlo no mueve dinero.
    expect(JournalEntry::query()->count())->toBe(0);
});

it('impide dos sesiones abiertas en la misma caja', function () {
    openTill($this);

    expect(fn () => openTill($this))
        ->toThrow(TreasuryException::class, 'ya tiene una sesión abierta');
});

it('permite abrir otra sesión después de cerrar la anterior', function () {
    $primera = openTill($this);
    $this->sessions->close($primera, Money::of('500.00'));

    $segunda = openTill($this);

    expect($segunda->number)->toBe('CAJ-000002')
        ->and($segunda->isOpen())->toBeTrue();
});

it('rechaza una cuenta que no es de efectivo', function () {
    expect(fn () => $this->sessions->open([
        'branch_id' => $this->branch->id,
        'account_id' => account('6.1.01')->id,
        'opening_float' => '0',
    ]))->toThrow(TreasuryException::class, 'no está marcada como efectivo');
});

/*
|--------------------------------------------------------------------------
| Arqueo
|--------------------------------------------------------------------------
*/

it('calcula lo esperado sumando el fondo y los cobros del turno', function () {
    $sesion = openTill($this, '500.00');

    cashIn($this, '1200.00');
    cashIn($this, '800.50');

    expect($this->sessions->expectedAmount($sesion)->toString())->toBe('2500.5000');
});

it('cierra sin partida cuando el conteo cuadra', function () {
    $sesion = openTill($this, '500.00');
    cashIn($this, '1200.00');

    $sesion = $this->sessions->close($sesion, Money::of('1700.00'));

    expect($sesion->isClosed())->toBeTrue()
        ->and($sesion->differenceAmount()->isZero())->toBeTrue()
        ->and($sesion->journalEntry())->toBeNull();
});

it('contabiliza el faltante contra sobrantes y faltantes', function () {
    $sesion = openTill($this, '500.00');
    cashIn($this, '1200.00');

    // Se contaron 1 685: faltan 15.
    $sesion = $this->sessions->close($sesion, Money::of('1685.00'));

    $lines = $sesion->journalEntry()->lines->keyBy('account_id');

    expect($sesion->differenceAmount()->toString())->toBe('-15.0000')
        ->and($sesion->isShort())->toBeTrue()
        // La pérdida al debe, la caja al haber: el dinero ya no está.
        ->and($lines[account('6.3.04')->id]->debitAmount()->toString())->toBe('15.0000')
        ->and($lines[$this->caja->id]->creditAmount()->toString())->toBe('15.0000');
});

it('contabiliza el sobrante en sentido contrario', function () {
    $sesion = openTill($this, '500.00');
    cashIn($this, '1200.00');

    $sesion = $this->sessions->close($sesion, Money::of('1710.00'));

    $lines = $sesion->journalEntry()->lines->keyBy('account_id');

    expect($sesion->differenceAmount()->toString())->toBe('10.0000')
        ->and($sesion->isShort())->toBeFalse()
        ->and($lines[$this->caja->id]->debitAmount()->toString())->toBe('10.0000')
        ->and($lines[account('6.3.04')->id]->creditAmount()->toString())->toBe('10.0000');
});

it('deja la caja contable igual a lo contado tras el arqueo', function () {
    $sesion = openTill($this, '0');
    cashIn($this, '1200.00');

    $this->sessions->close($sesion, Money::of('1185.00'));

    // Contabilidad y realidad vuelven a coincidir: es el objetivo del arqueo.
    expect(ledgerBalanceOf('1.1.01.01')->toString())->toBe('1185.0000');
});

it('no deja que la partida del arqueo altere lo esperado', function () {
    $sesion = openTill($this, '500.00');
    cashIn($this, '1200.00');

    $sesion = $this->sessions->close($sesion, Money::of('1685.00'));

    // Lo esperado sigue siendo lo de antes del ajuste, no 1 685.
    expect($sesion->expectedAmount()->toString())->toBe('1700.0000')
        ->and($this->sessions->expectedAmount($sesion)->toString())->toBe('1700.0000');
});

it('rechaza cerrar una sesión ya cerrada', function () {
    $sesion = openTill($this);
    $this->sessions->close($sesion, Money::of('500.00'));

    expect(fn () => $this->sessions->close($sesion->refresh(), Money::of('500.00')))
        ->toThrow(TreasuryException::class, 'ya está cerrada');
});

it('rechaza un conteo negativo', function () {
    $sesion = openTill($this);

    expect(fn () => $this->sessions->close($sesion, Money::of('-10.00')))
        ->toThrow(TreasuryException::class, 'no puede ser negativo');
});

it('mantiene el libro cuadrado tras el arqueo', function () {
    $sesion = openTill($this, '500.00');
    cashIn($this, '1200.00');
    $this->sessions->close($sesion, Money::of('1685.00'));

    $totales = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totales->debit)->equals(Money::of((string) $totales->credit)))->toBeTrue();
});

it('aísla las sesiones de caja entre empresas', function () {
    openTill($this);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(CashSession::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => CashSession::acrossCompanies()->count()))->toBe(1);
});
