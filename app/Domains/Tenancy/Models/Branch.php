<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Policies\BranchPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sucursal. También actúa como centro de costo en las partidas contables.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_main
 * @property bool $is_active
 */
#[UseFactory(BranchFactory::class)]
#[UsePolicy(BranchPolicy::class)]
#[Fillable(['code', 'name', 'address', 'phone', 'is_main', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Warehouse, $this> */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
