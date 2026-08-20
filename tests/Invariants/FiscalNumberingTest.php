<?php

declare(strict_types=1);

use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\CreditNoteService;
use App\Domains\Sales\Services\SaleService;

/**
 * Invariante: **todo documento fiscal emitido es válido.**
 *
 * Tres condiciones, y las tres son de ley, no del sistema:
 *
 *  1. Su correlativo cae dentro del rango que autorizó el SAR.
 *  2. Su fecha de emisión es anterior o igual a la fecha límite del CAI.
 *  3. La numeración no tiene huecos ni repetidos.
 *
 * La tercera es la que más fácil se rompe sin que nadie se entere: un documento
 * que falla después de tomar el número deja un hueco, y un hueco es lo primero
 * que busca una auditoría. Aquí se comprueba emitiendo de verdad, con fallos
 * intercalados, y contando los números que quedaron.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->sales = app(SaleService::class);
    $this->notes = app(CreditNoteService::class);
    $this->branch = mainBranch();
    $this->cash = account('1.1.02.01');

    withFiscalAuthorization($this->company, FiscalDocumentType::CreditNote);
});

function invoiceFor(object $ctx, string $price = '100.00'): Sale
{
    return $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'customer_id' => makeCustomer()->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $ctx->cash->id,
    ], [['product_id' => makeProduct($price)->id, 'quantity' => '1', 'unit_price' => $price, 'tax_id' => tax()->id]]);
}

it('emite todos los documentos dentro de su rango autorizado', function () {
    foreach (range(1, 6) as $i) {
        invoiceFor($this);
    }

    $sale = Sale::query()->first();

    $this->notes->issue($this->notes->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución de la primera factura',
    ], [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]));

    $documentos = Sale::query()->whereNotNull('number')->get()
        ->concat(CreditNote::query()->whereNotNull('number')->get());

    expect($documentos)->toHaveCount(7);

    foreach ($documentos as $documento) {
        expect($documento->fiscal_sequence)
            ->toBeGreaterThanOrEqual($documento->fiscal_range_from, "El documento {$documento->number} quedó por debajo de su rango.")
            ->toBeLessThanOrEqual($documento->fiscal_range_to, "El documento {$documento->number} quedó por encima de su rango.");

        expect($documento->date->startOfDay()->lessThanOrEqualTo($documento->fiscal_limit_date->startOfDay()))
            ->toBeTrue("El documento {$documento->number} se emitió después de su fecha límite.");
    }
});

it('no deja huecos en el correlativo aunque fallen documentos', function () {
    invoiceFor($this);
    invoiceFor($this);

    // Una factura que falla a mitad de camino: el cliente no existe.
    try {
        $this->sales->createAndIssue([
            'branch_id' => $this->branch->id,
            'customer_id' => 999_999,
            'date' => now()->toDateString(),
            'payment_condition' => PaymentCondition::Cash,
            'deposit_account_id' => $this->cash->id,
        ], [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']]);
    } catch (Throwable) {
        // Esperado: lo que importa es que no se lleve un correlativo.
    }

    invoiceFor($this);

    $secuencias = Sale::query()->whereNotNull('number')
        ->orderBy('fiscal_sequence')
        ->pluck('fiscal_sequence')
        ->all();

    // 1, 2, 3 — sin saltarse el que se llevó la factura fallida.
    expect($secuencias)->toBe([1, 2, 3]);
});

it('no repite un número fiscal', function () {
    foreach (range(1, 5) as $i) {
        invoiceFor($this);
    }

    $numeros = Sale::query()->whereNotNull('number')->pluck('number')->all();

    expect($numeros)->toHaveCount(5)
        ->and(array_unique($numeros))->toHaveCount(5);
});

it('se detiene al agotar el rango en vez de seguir numerando', function () {
    $authorization = FiscalAuthorization::query()
        ->where('document_type', FiscalDocumentType::Invoice)
        ->firstOrFail();

    // Se deja el rango con dos números disponibles.
    $authorization->forceFill(['next_number' => $authorization->range_to - 1])->save();

    invoiceFor($this);
    invoiceFor($this);

    $emitidas = Sale::query()->whereNotNull('number')->count();

    // La tercera no puede existir.
    try {
        invoiceFor($this);
        $this->fail('Se emitió una factura fuera del rango autorizado.');
    } catch (FiscalException) {
        // Esperado.
    }

    expect(Sale::query()->whereNotNull('number')->count())->toBe($emitidas)
        ->and($authorization->refresh()->hasRangeLeft())->toBeFalse();
});

it('mantiene separadas las series de factura y de nota de crédito', function () {
    $primera = invoiceFor($this);
    invoiceFor($this);

    $this->notes->issue($this->notes->saveDraft($primera, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución completa de la primera',
    ], [['sale_item_id' => $primera->items->first()->id, 'quantity' => '1']]));

    $facturas = Sale::query()->whereNotNull('number')->pluck('fiscal_sequence')->all();
    $notas = CreditNote::query()->whereNotNull('number')->pluck('fiscal_sequence')->all();

    // Cada serie arranca en su propio 1: no comparten correlativo.
    expect($facturas)->toBe([1, 2])
        ->and($notas)->toBe([1])
        ->and(CreditNote::query()->value('number'))->toStartWith('000-001-03-');
});

it('conserva los datos fiscales de los documentos ya emitidos al cambiar de CAI', function () {
    $vieja = invoiceFor($this);
    $caiViejo = $vieja->cai;

    withFiscalAuthorization($this->company, overrides: [
        'cai' => 'SEGUNDO-AAAAAA-BBBBBB-CCCCCC-DDDDDD-55',
        'range_from' => 5001,
        'range_to' => 9000,
    ]);

    $nueva = invoiceFor($this);

    expect($vieja->refresh()->cai)->toBe($caiViejo)
        ->and($vieja->fiscal_range_to)->toBe(5000)
        ->and($nueva->cai)->toBe('SEGUNDO-AAAAAA-BBBBBB-CCCCCC-DDDDDD-55')
        ->and($nueva->fiscal_sequence)->toBe(5001)
        ->and($nueva->number)->toBe('000-001-01-00005001');
});
