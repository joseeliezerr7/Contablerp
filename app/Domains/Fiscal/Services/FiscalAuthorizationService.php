<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Services;

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Alta y ciclo de vida de las autorizaciones del SAR.
 *
 * Todo lo que entra aquí viene copiado de un papel: el CAI, el rango y la fecha
 * límite los emite la administración tributaria. El servicio no los genera, los
 * comprueba —que el rango vaya hacia adelante, que no se cruce con otro ya
 * cargado— y se asegura de que solo haya una vigente por punto y tipo.
 */
final class FiscalAuthorizationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra una autorización y la deja vigente, reemplazando a la anterior.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(FiscalPoint $point, array $data): FiscalAuthorization
    {
        $type = $data['document_type'] instanceof FiscalDocumentType
            ? $data['document_type']
            : FiscalDocumentType::from((string) $data['document_type']);

        $from = (int) $data['range_from'];
        $to = (int) $data['range_to'];

        if ($from > $to) {
            throw FiscalException::invalidRange($from, $to);
        }

        $this->guardNoOverlap($point, $type, $from, $to);

        return DB::transaction(function () use ($point, $type, $data, $from, $to): FiscalAuthorization {
            // La anterior deja de estar vigente en la misma transacción. Si no,
            // el índice único de «una activa por punto y tipo» rechazaría el
            // insert y el usuario vería un error de base de datos en vez del
            // comportamiento que espera: cargar la nueva sustituye a la vieja.
            $this->retireActive($point, $type);

            $authorization = new FiscalAuthorization;
            $authorization->forceFill([
                'company_id' => $point->company_id,
                'fiscal_point_id' => $point->id,
                'document_type' => $type,
                'document_type_code' => str_pad((string) $data['document_type_code'], 2, '0', STR_PAD_LEFT),
                'cai' => mb_strtoupper(trim((string) $data['cai'])),
                'range_from' => $from,
                'range_to' => $to,
                // Arranca en el inicio del rango salvo que se esté migrando una
                // numeración ya empezada en otro sistema.
                'next_number' => max($from, (int) ($data['next_number'] ?? $from)),
                'issued_on' => CarbonImmutable::parse($data['issued_on'])->toDateString(),
                'limit_date' => CarbonImmutable::parse($data['limit_date'])->toDateString(),
                'status' => AuthorizationStatus::Active,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ])->save();

            $this->audit->log('registered', $authorization, newValues: [
                'cai' => $authorization->cai,
                'range' => $from.'-'.$to,
                'limit_date' => $authorization->limit_date->toDateString(),
            ], module: 'fiscal');

            return $authorization->refresh();
        });
    }

    /**
     * Corrige una autorización que todavía no numeró nada.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FiscalAuthorization $authorization, array $data): FiscalAuthorization
    {
        if ($authorization->used() > 0) {
            throw FiscalException::alreadyUsed($authorization);
        }

        $from = (int) $data['range_from'];
        $to = (int) $data['range_to'];

        if ($from > $to) {
            throw FiscalException::invalidRange($from, $to);
        }

        $this->guardNoOverlap(
            $authorization->point,
            $authorization->document_type,
            $from,
            $to,
            $authorization->id,
        );

        $authorization->forceFill([
            'document_type_code' => str_pad((string) $data['document_type_code'], 2, '0', STR_PAD_LEFT),
            'cai' => mb_strtoupper(trim((string) $data['cai'])),
            'range_from' => $from,
            'range_to' => $to,
            'next_number' => max($from, (int) ($data['next_number'] ?? $from)),
            'issued_on' => CarbonImmutable::parse($data['issued_on'])->toDateString(),
            'limit_date' => CarbonImmutable::parse($data['limit_date'])->toDateString(),
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $authorization->refresh();
    }

    /**
     * Da por terminada la autorización vigente sin agotar el rango.
     */
    public function retire(FiscalAuthorization $authorization, AuthorizationStatus $status): FiscalAuthorization
    {
        $authorization->forceFill(['status' => $status])->save();

        $this->audit->log('retired', $authorization, newValues: [
            'status' => $status->value,
            'unused' => $authorization->remaining(),
        ], module: 'fiscal');

        return $authorization->refresh();
    }

    /**
     * Marca como vencidas las autorizaciones cuya fecha límite ya pasó.
     *
     * Es una tarea de mantenimiento, no una guarda: la emisión ya comprueba la
     * fecha en cada documento. Esto solo mantiene la pantalla diciendo la verdad
     * sin depender de que alguien intente facturar.
     *
     * @return int Cuántas se marcaron.
     */
    public function expireOverdue(DateTimeInterface|string|null $asOf = null): int
    {
        $date = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        $overdue = FiscalAuthorization::query()
            ->active()
            ->whereDate('limit_date', '<', $date->toDateString())
            ->get();

        foreach ($overdue as $authorization) {
            $this->retire($authorization, AuthorizationStatus::Expired);
        }

        return $overdue->count();
    }

    /**
     * Autorizaciones que conviene renovar: quedan pocos correlativos o poco
     * tiempo. Es lo que alimenta el aviso de la pantalla.
     *
     * @return Collection<int, FiscalAuthorization>
     */
    public function needingRenewal(int $daysAhead = 30, int $percentUsed = 85): Collection
    {
        return FiscalAuthorization::query()
            ->with('point.branch')
            ->active()
            ->get()
            ->filter(fn (FiscalAuthorization $a) => $a->daysToLimit() <= $daysAhead
                || $a->usedPercent() >= $percentUsed)
            ->values();
    }

    /**
     * Dos autorizaciones del mismo punto y tipo no pueden compartir
     * correlativos: producirían dos documentos con el mismo número fiscal.
     *
     * Se comprueba contra **todas**, no solo contra la vigente. Una autorización
     * agotada ya emitió sus números, y volver a emitirlos sería peor.
     */
    private function guardNoOverlap(
        FiscalPoint $point,
        FiscalDocumentType $type,
        int $from,
        int $to,
        ?int $ignoreId = null,
    ): void {
        $clash = FiscalAuthorization::query()
            ->where('fiscal_point_id', $point->id)
            ->where('document_type', $type)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('range_from', '<=', $to)
            ->where('range_to', '>=', $from)
            ->first();

        if ($clash !== null) {
            throw FiscalException::rangeOverlaps($clash);
        }
    }

    private function retireActive(FiscalPoint $point, FiscalDocumentType $type): void
    {
        $current = $point->activeAuthorization($type);

        if ($current === null) {
            return;
        }

        $this->retire(
            $current,
            $current->isExpiredOn() ? AuthorizationStatus::Expired : AuthorizationStatus::Replaced,
        );
    }
}
