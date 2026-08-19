<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Services;

use App\Domains\Fiscal\DataTransfer\FiscalNumber;
use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Numeración fiscal bajo el régimen de facturación hondureño.
 *
 * ## Qué garantiza
 *
 * Cada número entregado sale del rango que autorizó el SAR, es anterior a la
 * fecha límite de emisión y no se repite ni deja huecos. Las tres cosas son
 * requisitos legales, no preferencias: un documento fuera de rango o emitido
 * después de la fecha límite no es una factura.
 *
 * ## Por qué el bloqueo
 *
 * La fila de la autorización se toma con `SELECT ... FOR UPDATE` dentro de la
 * transacción del documento, igual que `DocumentSeriesService`. Dos cajas
 * facturando al mismo tiempo se serializan en la base de datos. `MAX(numero)+1`
 * daría duplicados, y aquí un duplicado no es un inconveniente: son dos facturas
 * con el mismo número fiscal.
 *
 * ## Qué NO hace
 *
 * No salta a otra autorización cuando la vigente se agota. Un correlativo nuevo
 * empieza donde diga la resolución del SAR, no donde terminó el anterior, y
 * elegirlo solo sería inventar un número. Cuando se acaba el rango, el sistema
 * se detiene y lo dice.
 */
final class FiscalNumberService
{
    /**
     * Reserva el siguiente correlativo para un documento.
     *
     * Debe llamarse dentro de la transacción del documento: fuera de ella el
     * bloqueo se soltaría de inmediato y dejaría de proteger.
     */
    public function reserve(
        Branch $branch,
        FiscalDocumentType $type,
        DateTimeInterface|string|null $date = null,
    ): FiscalNumber {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'FiscalNumberService::reserve() debe ejecutarse dentro de una transacción; '
                .'de lo contrario el bloqueo de la autorización no protege contra la concurrencia.'
            );
        }

        $point = $this->pointFor($branch);
        $issuedOn = CarbonImmutable::parse($date ?? now())->startOfDay();

        // Se relee con bloqueo: entre elegir la autorización y tomar el número,
        // otra caja pudo haberse llevado el correlativo.
        $authorization = FiscalAuthorization::query()
            ->whereKey($this->activeAuthorizationFor($point, $type)->id)
            ->lockForUpdate()
            ->first();

        $this->guard($authorization, $issuedOn);

        $sequence = $authorization->next_number;

        $authorization->forceFill([
            'next_number' => $sequence + 1,
            // Al consumir el último del rango, la autorización queda agotada en
            // el mismo movimiento. Dejarlo para un proceso posterior abriría una
            // ventana en la que el sistema cree que todavía puede facturar.
            'status' => $sequence >= $authorization->range_to
                ? AuthorizationStatus::Exhausted
                : $authorization->status,
        ])->save();

        // `refresh()` descarta las relaciones cargadas, y `formatNumber()`
        // necesita el punto de emisión: se vuelve a cargar antes de formatear.
        $authorization->refresh()->setRelation('point', $point);

        return new FiscalNumber(
            $authorization,
            $sequence,
            $authorization->formatNumber($sequence),
        );
    }

    /**
     * Si la sucursal puede emitir este tipo de documento hoy.
     *
     * Es la consulta que hace la pantalla antes de mostrar el botón, para que el
     * usuario se entere del problema antes de capturar la factura entera y no
     * al pulsar «Emitir».
     */
    public function canEmit(
        Branch $branch,
        FiscalDocumentType $type,
        DateTimeInterface|string|null $date = null,
    ): bool {
        try {
            $point = $this->pointFor($branch);
            $authorization = $this->activeAuthorizationFor($point, $type);
            $this->guard($authorization, CarbonImmutable::parse($date ?? now()));

            return true;
        } catch (FiscalException) {
            return false;
        }
    }

    /**
     * El motivo por el que no se puede emitir, para mostrarlo en pantalla.
     */
    public function blockingReason(
        Branch $branch,
        FiscalDocumentType $type,
        DateTimeInterface|string|null $date = null,
    ): ?string {
        try {
            $point = $this->pointFor($branch);
            $authorization = $this->activeAuthorizationFor($point, $type);
            $this->guard($authorization, CarbonImmutable::parse($date ?? now()));

            return null;
        } catch (FiscalException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Autorización vigente de la sucursal, sin reservar nada. La usan las
     * pantallas para avisar de correlativos escasos o de vencimiento próximo.
     */
    public function currentAuthorization(Branch $branch, FiscalDocumentType $type): ?FiscalAuthorization
    {
        $point = FiscalPoint::query()
            ->where('branch_id', $branch->id)
            ->active()
            ->orderBy('emission_point_code')
            ->first();

        return $point?->activeAuthorization($type);
    }

    /**
     * Punto de emisión de la sucursal.
     *
     * Con varios puntos activos toma el de menor código, que es el que la
     * empresa registró primero. Elegir caja es un asunto del punto de venta y
     * llegará con él; mientras tanto, una sucursal factura por su punto
     * principal.
     */
    private function pointFor(Branch $branch): FiscalPoint
    {
        $point = FiscalPoint::query()
            ->where('branch_id', $branch->id)
            ->active()
            ->orderBy('emission_point_code')
            ->first();

        if ($point === null) {
            throw FiscalException::noPointForBranch($branch);
        }

        return $point;
    }

    private function activeAuthorizationFor(FiscalPoint $point, FiscalDocumentType $type): FiscalAuthorization
    {
        $authorization = $point->activeAuthorization($type);

        if ($authorization !== null) {
            return $authorization->setRelation('point', $point);
        }

        // No basta con decir «no hay autorización vigente». La forma habitual de
        // llegar aquí es que el CAI se acabó o se venció hace un momento, y a
        // quien tiene un cliente esperando le sirve saber cuál de las dos cosas
        // pasó y con qué correlativo o con qué fecha se quedó.
        $last = $point->authorizations()
            ->where('document_type', $type)
            ->orderByDesc('range_to')
            ->first();

        if ($last !== null) {
            $last->setRelation('point', $point);

            throw match ($last->status) {
                AuthorizationStatus::Exhausted => FiscalException::rangeExhausted($last),
                AuthorizationStatus::Expired => FiscalException::expired($last, now()->toDateString()),
                default => FiscalException::noAuthorization($point, $type),
            };
        }

        throw FiscalException::noAuthorization($point, $type);
    }

    /**
     * Las tres condiciones que hacen válido a un documento fiscal.
     */
    private function guard(FiscalAuthorization $authorization, CarbonImmutable $date): void
    {
        if (! $authorization->status->canEmit()) {
            throw FiscalException::notActive($authorization);
        }

        if (! $authorization->hasRangeLeft()) {
            throw FiscalException::rangeExhausted($authorization);
        }

        if ($authorization->isExpiredOn($date)) {
            throw FiscalException::expired($authorization, $date->toDateString());
        }
    }
}
