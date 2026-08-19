<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\DocumentSeries;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Correlativos de documentos.
 *
 * Toma la fila de la serie con SELECT ... FOR UPDATE, de modo que dos usuarios
 * facturando a la vez se serializan en la base de datos y no pueden obtener el
 * mismo número. `MAX(numero) + 1` produce duplicados bajo concurrencia y deja
 * huecos cuando una transacción hace rollback.
 */
final class DocumentSeriesService
{
    public const JOURNAL_ENTRY = 'journal_entry';

    public const RECEIPT = 'receipt';

    public function __construct(private readonly CompanyContext $context) {}

    /**
     * Reserva el siguiente número de la serie y lo devuelve ya formateado.
     *
     * Debe llamarse dentro de una transacción: el bloqueo de fila solo dura
     * hasta el commit, y fuera de una transacción se liberaría de inmediato.
     *
     * @param  string|null  $prefix  Solo se usa si la serie aún no existe.
     */
    public function next(
        string $type,
        ?string $periodKey = null,
        ?int $branchId = null,
        ?string $prefix = null,
    ): string {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'DocumentSeriesService::next() debe ejecutarse dentro de una transacción; '
                .'de lo contrario el bloqueo de la serie no protege contra la concurrencia.'
            );
        }

        $periodKey ??= '*';
        $companyId = $this->context->idOrFail();

        $series = DocumentSeries::query()
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->where('period_key', $periodKey)
            ->when(
                $branchId === null,
                fn ($query) => $query->whereNull('branch_id'),
                fn ($query) => $query->where('branch_id', $branchId),
            )
            ->lockForUpdate()
            ->first();

        if ($series === null) {
            $series = $this->createSeries($type, $periodKey, $branchId, $prefix);

            // Se vuelve a leer con bloqueo: entre el INSERT y aquí otra
            // transacción pudo haber tomado un número.
            $series = DocumentSeries::query()->whereKey($series->id)->lockForUpdate()->first();
        }

        $number = $series->next_number;

        $series->forceFill(['next_number' => $number + 1])->save();

        return $series->format($number);
    }

    /**
     * Serie de partidas contables: correlativo por empresa y por ejercicio.
     */
    public function nextJournalNumber(string $fiscalYearName): string
    {
        return $this->next(self::JOURNAL_ENTRY, $fiscalYearName);
    }

    /**
     * Las facturas de venta **no** se numeran aquí. Desde la Fase 9 su
     * correlativo lo entrega `FiscalNumberService` contra la autorización del
     * SAR, que tiene rango, techo y fecha de caducidad. Una serie interna no
     * puede prometer nada de eso.
     */
    public function nextReceipt(int $branchId, string $prefix = 'REC-'): string
    {
        return $this->next(self::RECEIPT, '*', $branchId, $prefix);
    }

    private function createSeries(
        string $type,
        string $periodKey,
        ?int $branchId,
        ?string $prefix,
    ): DocumentSeries {
        return DocumentSeries::create([
            'branch_id' => $branchId,
            'type' => $type,
            'period_key' => $periodKey,
            'prefix' => $prefix ?? $this->defaultPrefix($type, $periodKey),
            'next_number' => 1,
            'padding' => 6,
        ]);
    }

    private function defaultPrefix(string $type, string $periodKey): string
    {
        if ($type !== self::JOURNAL_ENTRY || $periodKey === '*') {
            return '';
        }

        // 'PD-2026-000001' se lee mejor en un libro diario impreso.
        return 'PD-'.$periodKey.'-';
    }
}
