<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Tenancy\Models\Branch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $type
 * @property string $period_key
 * @property string $prefix
 * @property int $next_number
 * @property int $padding
 */
#[Table('document_series')]
#[Fillable(['branch_id', 'type', 'period_key', 'prefix', 'next_number', 'padding'])]
class DocumentSeries extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Formatea un número según el prefijo y el relleno de la serie.
     */
    public function format(int $number): string
    {
        return $this->prefix.str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT);
    }
}
