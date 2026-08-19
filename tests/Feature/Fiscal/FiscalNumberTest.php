<?php

declare(strict_types=1);

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Fiscal\Services\FiscalNumberService;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Numeración bajo el régimen de facturación hondureño.
 *
 * Las tres reglas que se comprueban aquí no son preferencias del sistema: un
 * documento fuera del rango autorizado, o emitido después de la fecha límite, o
 * con un número repetido, **no es una factura**. El contribuyente que lo entrega
 * responde por él.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->service = app(FiscalNumberService::class);
    $this->authorizations = app(FiscalAuthorizationService::class);
    $this->branch = mainBranch();
    $this->point = FiscalPoint::query()->firstOrFail();
});

/**
 * Reserva un número dentro de una transacción, como hacen los documentos.
 */
function reserveNumber(object $ctx, ?string $date = null): string
{
    return DB::transaction(
        fn () => $ctx->service->reserve($ctx->branch, FiscalDocumentType::Invoice, $date)->number
    );
}

it('arma el número con establecimiento, punto, tipo y correlativo', function () {
    expect(reserveNumber($this))->toBe('000-001-01-00000001')
        ->and(reserveNumber($this))->toBe('000-001-01-00000002');
});

it('respeta el correlativo inicial que autorizó el SAR', function () {
    // Una segunda autorización que empieza en el 5001, como llega en la vida
    // real: el rango nuevo continúa donde el SAR dijo, no donde uno quiera.
    $this->authorizations->register($this->point, [
        'document_type' => FiscalDocumentType::Invoice,
        'document_type_code' => '01',
        'cai' => 'AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE-01',
        'range_from' => 5001,
        'range_to' => 6000,
        'issued_on' => now()->toDateString(),
        'limit_date' => now()->addYear()->toDateString(),
    ]);

    expect(reserveNumber($this))->toBe('000-001-01-00005001');
});

it('no entrega números fuera del rango autorizado', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();
    $authorization->forceFill(['next_number' => $authorization->range_to])->save();

    // El último del rango sí sale.
    expect(reserveNumber($this))->toBe('000-001-01-00005000');

    // El siguiente ya no existe.
    reserveNumber($this);
})->throws(FiscalException::class, 'Se agotó el rango autorizado');

it('deja la autorización agotada en el mismo movimiento en que se acaba', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();
    $authorization->forceFill(['next_number' => $authorization->range_to])->save();

    reserveNumber($this);

    expect($authorization->refresh()->status)->toBe(AuthorizationStatus::Exhausted)
        ->and($authorization->remaining())->toBe(0);
});

it('no emite después de la fecha límite', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();
    $authorization->forceFill(['limit_date' => now()->subDay()->toDateString()])->save();

    reserveNumber($this);
})->throws(FiscalException::class, 'venció el');

it('sí emite el mismo día de la fecha límite', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();
    $limite = now()->addDays(3)->toDateString();
    $authorization->forceFill(['limit_date' => $limite])->save();

    expect(reserveNumber($this, $limite))->toBe('000-001-01-00000001');
});

it('exige punto de emisión configurado', function () {
    FiscalAuthorization::query()->delete();
    FiscalPoint::query()->delete();

    reserveNumber($this);
})->throws(FiscalException::class, 'no tiene punto de emisión configurado');

it('exige una autorización vigente', function () {
    FiscalAuthorization::query()->update(['status' => AuthorizationStatus::Replaced]);

    reserveNumber($this);
})->throws(FiscalException::class, 'no tiene una autorización vigente');

/**
 * La guarda de «solo dentro de una transacción» no se puede probar aquí: bajo
 * `RefreshDatabase` las pruebas ya corren dentro de una, así que `transactionLevel()`
 * nunca vale cero. Lo que sí se prueba es la consecuencia que importa —que un
 * documento fallido no se lleve un correlativo—, y es además lo que evita el
 * hueco en la numeración que el SAR sí revisa.
 */
it('devuelve el correlativo si el documento no llega a emitirse', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();

    try {
        DB::transaction(function (): void {
            $this->service->reserve($this->branch, FiscalDocumentType::Invoice);

            throw new RuntimeException('La factura falló después de numerar');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect($authorization->refresh()->next_number)->toBe(1);

    // Y el siguiente documento se lleva ese mismo número: sin hueco.
    expect(reserveNumber($this))->toBe('000-001-01-00000001');
});

it('lleva numeración independiente por tipo de documento', function () {
    withFiscalAuthorization($this->company, FiscalDocumentType::CreditNote, [
        'range_from' => 1,
        'range_to' => 500,
    ]);

    $factura = reserveNumber($this);
    $nota = DB::transaction(
        fn () => $this->service->reserve($this->branch, FiscalDocumentType::CreditNote)->number
    );

    // Mismo correlativo, distinto tipo: son dos series que no se estorban.
    expect($factura)->toBe('000-001-01-00000001')
        ->and($nota)->toBe('000-001-03-00000001');
});

it('cada punto de emisión numera por su cuenta', function () {
    $segunda = Branch::query()->create([
        'code' => '002',
        'name' => 'Sucursal El Progreso',
        'is_main' => false,
        'is_active' => true,
    ]);

    $otroPunto = FiscalPoint::query()->create([
        'branch_id' => $segunda->id,
        'establishment_code' => '001',
        'emission_point_code' => '001',
        'name' => 'Caja El Progreso',
        'is_active' => true,
    ]);

    $this->authorizations->register($otroPunto, [
        'document_type' => FiscalDocumentType::Invoice,
        'document_type_code' => '01',
        'cai' => 'FFFFFF-EEEEEE-DDDDDD-CCCCCC-BBBBBB-02',
        'range_from' => 1,
        'range_to' => 1000,
        'issued_on' => now()->toDateString(),
        'limit_date' => now()->addYear()->toDateString(),
    ]);

    $casaMatriz = reserveNumber($this);
    $progreso = DB::transaction(
        fn () => $this->service->reserve($segunda, FiscalDocumentType::Invoice)->number
    );

    expect($casaMatriz)->toBe('000-001-01-00000001')
        ->and($progreso)->toBe('001-001-01-00000001');
});

it('dice qué falta antes de que el usuario capture la factura', function () {
    FiscalAuthorization::query()->update(['status' => AuthorizationStatus::Exhausted]);

    // No dice «no hay autorización vigente», que es cierto pero inútil: dice que
    // el CAI se agotó y con qué correlativo se quedó.
    expect($this->service->canEmit($this->branch, FiscalDocumentType::Invoice))->toBeFalse()
        ->and($this->service->blockingReason($this->branch, FiscalDocumentType::Invoice))
        ->toContain('Se agotó el rango autorizado')
        ->toContain('000-001-01-00005000');
});

it('distingue el CAI vencido del agotado al explicar el bloqueo', function () {
    FiscalAuthorization::query()->update([
        'status' => AuthorizationStatus::Expired,
        'limit_date' => now()->subMonth()->toDateString(),
    ]);

    expect($this->service->blockingReason($this->branch, FiscalDocumentType::Invoice))
        ->toContain('venció el');
});

it('solo habla de autorización faltante cuando de verdad no hay ninguna', function () {
    FiscalAuthorization::query()->delete();

    expect($this->service->blockingReason($this->branch, FiscalDocumentType::Invoice))
        ->toContain('no tiene una autorización vigente');
});
