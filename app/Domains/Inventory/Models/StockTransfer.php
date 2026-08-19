<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Policies\StockTransferPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traslado entre bodegas. No tiene partida contable: el valor no sale de la
 * empresa, solo cambia de estante.
 *
 * @property string|null $number
 * @property StockDocumentStatus $status
 * @property string $total_value
 */
#[UsePolicy(StockTransferPolicy::class)]
#[Fillable(['branch_id', 'from_warehouse_id', 'to_warehouse_id', 'date', 'notes'])]
class StockTransfer extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'stock_transfer';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => StockDocumentStatus::class,
            'total_value' => 'decimal:4',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function valueAmount(): Money
    {
        return Money::of($this->total_value);
    }

    public function isDraft(): bool
    {
        return $this->status === StockDocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }

    public function isVoided(): bool
    {
        return $this->status === StockDocumentStatus::Voided;
    }

    public function label(): string
    {
        return $this->number ?? 'Borrador #'.$this->id;
    }
}
