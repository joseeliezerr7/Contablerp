<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\FiscalYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property FiscalYearStatus $status
 */
#[UseFactory(FiscalYearFactory::class)]
#[Fillable(['name', 'starts_on', 'ends_on', 'status'])]
class FiscalYear extends Model
{
    /** @use HasFactory<FiscalYearFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalYearStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Ojo: viene ordenada por número. Añadirle `orderByDesc` no sustituye ese
     * orden, lo encadena; para consultar en otro orden usa `reorder()` o una
     * consulta directa sobre AccountingPeriod.
     *
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('number');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }

    public function contains(\DateTimeInterface $date): bool
    {
        return $date >= $this->starts_on->startOfDay() && $date <= $this->ends_on->endOfDay();
    }
}
