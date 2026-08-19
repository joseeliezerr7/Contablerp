<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Partners\Models\Customer;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Clientes')]
class CustomerIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $trade_name = '';

    public string $tax_id = '';

    public string $type = 'company';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public ?int $price_list_id = null;

    public string $credit_limit = '0.00';

    public int $credit_days = 0;

    public bool $is_active = true;

    public bool $is_walk_in = false;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('customers', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:individual,company'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'price_list_id' => ['nullable', 'integer'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'credit_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
            'is_walk_in' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'tax_id' => 'RTN',
            'credit_limit' => 'límite de crédito',
            'credit_days' => 'días de crédito',
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('create', Customer::class);

        $this->resetForm();
        $this->code = $this->nextCode();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        $this->authorize('update', $customer);

        $this->editingId = $customer->id;
        $this->code = $customer->code;
        $this->name = $customer->name;
        $this->trade_name = (string) $customer->trade_name;
        $this->tax_id = (string) $customer->tax_id;
        $this->type = $customer->type;
        $this->email = (string) $customer->email;
        $this->phone = (string) $customer->phone;
        $this->address = (string) $customer->address;
        $this->price_list_id = $customer->price_list_id;
        $this->credit_limit = (string) $customer->credit_limit;
        $this->credit_days = $customer->credit_days;
        $this->is_active = $customer->is_active;
        $this->is_walk_in = $customer->is_walk_in;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            // Solo puede haber un cliente de mostrador. Marcar otro desmarca al
            // anterior en vez de reventar contra el índice único: quien lo
            // cambia está diciendo cuál quiere, no pidiendo dos.
            if ($data['is_walk_in']) {
                Customer::query()
                    ->where('is_walk_in', true)
                    ->when($this->editingId !== null, fn ($q) => $q->whereKeyNot($this->editingId))
                    ->update(['is_walk_in' => false]);
            }

            if ($this->editingId !== null) {
                $customer = Customer::query()->findOrFail($this->editingId);
                $this->authorize('update', $customer);
                $customer->update($data);
                session()->flash('success', 'Cliente actualizado.');

                return;
            }

            $this->authorize('create', Customer::class);
            Customer::create($data);
            session()->flash('success', 'Cliente creado.');
        });

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        $this->authorize('delete', $customer);

        if ($customer->sales()->exists()) {
            session()->flash('error', 'No se puede eliminar un cliente con facturas. Desactívalo en su lugar.');

            return;
        }

        $customer->delete();
        session()->flash('success', 'Cliente eliminado.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Customer::class);

        return view('livewire.sales.customer-index', [
            'customers' => Customer::query()
                ->with('priceList:id,name')
                ->when($this->search !== '', fn ($q) => $q->search($this->search))
                ->orderBy('name')
                ->paginate(20),
            'priceLists' => PriceList::query()->active()->orderBy('name')->get(),
        ]);
    }

    /**
     * Sugiere el siguiente código correlativo, para no obligar a inventarlo.
     */
    private function nextCode(): string
    {
        $last = Customer::query()
            ->where('code', 'like', 'CLI%')
            ->orderByDesc('code')
            ->value('code');

        $number = $last === null ? 1 : ((int) substr($last, 3)) + 1;

        return 'CLI'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'trade_name', 'tax_id',
            'email', 'phone', 'address', 'price_list_id',
        ]);
        $this->type = 'company';
        $this->credit_limit = '0.00';
        $this->credit_days = 0;
        $this->is_active = true;
        $this->is_walk_in = false;
        $this->resetValidation();
    }
}
