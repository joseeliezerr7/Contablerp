<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\Rules\BelongsToCurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Categorías de activo fijo.
 *
 * Sin esta pantalla **no se podía dar de alta un activo en una empresa nueva**:
 * el formulario de activos exige categoría, las categorías nunca se sembraron y
 * no había forma de crearlas. El módulo entero quedaba muerto salvo en la
 * empresa de demostración, cuyas categorías las crea el seeder.
 *
 * Una categoría decide dos cosas que después no se improvisan: cuántos meses
 * dura el activo y contra qué tres cuentas se registra —el activo, el gasto por
 * depreciación del mes y la depreciación acumulada—.
 */
#[Title('Categorías de activo')]
class AssetCategoryIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $useful_life_months = '';

    public ?int $asset_account_id = null;

    public ?int $depreciation_account_id = null;

    public ?int $accumulated_account_id = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', FixedAssetCategory::class);
    }

    public function create(): void
    {
        $this->authorize('create', FixedAssetCategory::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $category = FixedAssetCategory::query()->findOrFail($id);
        $this->authorize('update', $category);

        $this->editingId = $category->id;
        $this->code = $category->code;
        $this->name = $category->name;
        $this->useful_life_months = (string) $category->useful_life_months;
        $this->asset_account_id = $category->asset_account_id;
        $this->depreciation_account_id = $category->depreciation_account_id;
        $this->accumulated_account_id = $category->accumulated_account_id;
        $this->is_active = (bool) $category->is_active;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('fixed_asset_categories', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:120'],
            // Un año es lo mínimo que la ley reconoce como activo; lo que dura
            // menos es gasto del período, no algo que se deprecie.
            'useful_life_months' => ['required', 'integer', 'min:12', 'max:1200'],
            // `BelongsToCurrentCompany` y no `exists`: `exists` deja pasar la
            // cuenta de otra empresa, y el asiento saldría contra un plan ajeno.
            'asset_account_id' => ['required', 'integer', new BelongsToCurrentCompany('accounts')],
            'depreciation_account_id' => ['required', 'integer', new BelongsToCurrentCompany('accounts')],
            'accumulated_account_id' => ['required', 'integer', new BelongsToCurrentCompany('accounts')],
        ], attributes: [
            'code' => 'código',
            'name' => 'nombre',
            'useful_life_months' => 'vida útil',
            'asset_account_id' => 'cuenta del activo',
            'depreciation_account_id' => 'cuenta de gasto por depreciación',
            'accumulated_account_id' => 'cuenta de depreciación acumulada',
        ]);

        $data['is_active'] = $this->is_active;

        if ($this->editingId === null) {
            $this->authorize('create', FixedAssetCategory::class);
            $category = new FixedAssetCategory;
        } else {
            $category = FixedAssetCategory::query()->findOrFail($this->editingId);
            $this->authorize('update', $category);
        }

        $category->fill($data)->save();

        session()->flash('success', 'Categoría guardada.');

        $this->closeForm();
    }

    public function delete(int $id): void
    {
        $category = FixedAssetCategory::query()->findOrFail($id);
        $this->authorize('delete', $category);

        $category->delete();

        session()->flash('success', 'Categoría eliminada.');
    }

    public function toggleActive(int $id): void
    {
        $category = FixedAssetCategory::query()->findOrFail($id);
        $this->authorize('update', $category);

        $category->forceFill(['is_active' => ! $category->is_active])->save();

        session()->flash('success', $category->is_active
            ? 'Quedó activa.'
            : 'Quedó desactivada y ya no se ofrece al dar de alta un activo.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', FixedAssetCategory::class);

        return view('livewire.assets.asset-category-index', [
            'categories' => FixedAssetCategory::query()
                ->withCount('assets')
                ->with(['assetAccount:id,code,name', 'depreciationAccount:id,code,name', 'accumulatedAccount:id,code,name'])
                ->orderBy('code')
                ->get(),
            'assetAccounts' => $this->accountsOfType(AccountType::Asset),
            'expenseAccounts' => $this->accountsOfType(AccountType::Expense),
        ]);
    }

    /**
     * Solo cuentas imputables del tipo pedido.
     *
     * La depreciación acumulada es una cuenta de activo —de naturaleza
     * acreedora— así que sale del mismo grupo que la del activo; el gasto del
     * mes sale de las de gasto.
     *
     * @return Collection<int, Account>
     */
    private function accountsOfType(AccountType $type): Collection
    {
        return Account::query()
            ->postable()
            ->active()
            ->ofType($type)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'useful_life_months',
            'asset_account_id', 'depreciation_account_id', 'accumulated_account_id',
        ]);
        $this->is_active = true;
    }
}
