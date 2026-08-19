<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Accounting\Models\Account;
use App\Domains\Assets\Enums\WithholdingKind;
use App\Domains\Assets\Enums\WithholdingScope;
use App\Domains\Assets\Models\WithholdingType;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Retenciones')]
class WithholdingTypeIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $kind = 'income_tax';

    public string $base = 'total';

    public string $rate = '';

    public string $applies_to = 'purchase';

    public ?int $account_id = null;

    public bool $is_active = true;

    public function create(): void
    {
        $this->authorize('create', WithholdingType::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $type = WithholdingType::query()->findOrFail($id);
        $this->authorize('update', $type);

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->name = $type->name;
        $this->kind = $type->kind->value;
        $this->base = $type->base;
        $this->rate = $type->rate;
        $this->applies_to = $type->applies_to->value;
        $this->account_id = $type->account_id;
        $this->is_active = $type->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:income_tax,sales_tax'],
            'base' => ['required', 'in:subtotal,total'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'applies_to' => ['required', 'in:purchase,sale'],
            'account_id' => ['required', 'integer'],
        ], attributes: [
            'rate' => 'porcentaje',
            'applies_to' => 'ámbito',
            'account_id' => 'cuenta contable',
        ]);

        $data['is_active'] = $this->is_active;

        if ($this->editingId === null) {
            $this->authorize('create', WithholdingType::class);
            $type = new WithholdingType;
            $type->fill($data)->save();
            session()->flash('success', 'Retención configurada.');
        } else {
            $type = WithholdingType::query()->findOrFail($this->editingId);
            $this->authorize('update', $type);
            $type->fill($data)->save();
            session()->flash('success', 'Retención actualizada.');
        }

        $this->closeForm();
    }

    public function delete(int $id): void
    {
        $type = WithholdingType::query()->findOrFail($id);
        $this->authorize('delete', $type);

        $type->delete();
        session()->flash('success', 'Retención eliminada.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'rate', 'account_id']);
        $this->kind = 'income_tax';
        $this->base = 'total';
        $this->applies_to = 'purchase';
        $this->is_active = true;
    }

    public function render(): View
    {
        $this->authorize('viewAny', WithholdingType::class);

        return view('livewire.assets.withholding-type-index', [
            'types' => WithholdingType::query()->with('account:id,code,name')->orderBy('code')->get(),
            'kinds' => WithholdingKind::cases(),
            'scopes' => WithholdingScope::cases(),
            'accounts' => Account::query()
                ->where('is_postable', true)
                ->whereIn('code', ['2.1.02.03', '1.1.05.02', '2.1.02.02', '1.1.05.03'])
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
