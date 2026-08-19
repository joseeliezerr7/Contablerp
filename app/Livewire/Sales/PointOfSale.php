<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\PointOfSaleService;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Punto de venta de mostrador.
 *
 * La pantalla se maneja con el teclado porque el mostrador se maneja con el
 * teclado: la mano izquierda sostiene el producto y la derecha teclea. El ratón
 * está para las excepciones.
 *
 *   F2  volver al buscador     F4  cobrar
 *   Esc cancelar el diálogo    ↑↓  elegir del resultado de búsqueda
 *
 * La pistola de código de barras escribe en el buscador y manda un Enter, así
 * que no necesita nada especial: es un teclado que escribe muy rápido.
 */
#[Title('Punto de venta')]
class PointOfSale extends Component
{
    public string $term = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public ?int $customerId = null;

    public bool $checkingOut = false;

    /** @var array<int, array<string, mixed>> */
    public array $payments = [];

    public string $tendered = '';

    public ?int $lastSaleId = null;

    public function mount(PointOfSaleService $pos): void
    {
        $this->authorize('create', Sale::class);

        $this->customerId = $this->resolveWalkIn($pos);
    }

    /*
    |--------------------------------------------------------------------------
    | Líneas
    |--------------------------------------------------------------------------
    */

    /**
     * Añade lo que haya escrito el cajero.
     *
     * Con una coincidencia exacta —lo normal con la pistola— entra directo. Con
     * varias, la pantalla muestra la lista y él elige.
     *
     * **El término llega por parámetro, leído del campo en el instante del
     * Enter.** No se confía en la propiedad sincronizada: el buscador usa
     * `.live.debounce` para ir mostrando coincidencias mientras se escribe, y
     * una pistola de código de barras teclea trece dígitos y manda el Enter
     * antes de que ese retardo termine. Leyendo del evento, lo que se busca es
     * siempre lo que hay en pantalla.
     */
    public function submitTerm(PointOfSaleService $pos, ?string $typed = null): void
    {
        if ($typed !== null) {
            $this->term = trim($typed);
        }

        $found = $pos->search($this->term);

        if ($found->isEmpty()) {
            $this->addError('term', 'No hay ningún producto con ese código o nombre.');

            return;
        }

        if ($found->count() === 1) {
            $this->addProduct($found->first()->id, $pos);
        }
    }

    public function addProduct(int $productId, PointOfSaleService $pos): void
    {
        $product = Product::query()->with(['tax', 'prices'])->findOrFail($productId);

        $this->resetValidation();
        $this->term = '';

        // Marcar dos veces el mismo producto suma cantidad en vez de abrir otra
        // línea: es lo que espera quien pasa tres botellas iguales por la
        // pistola.
        foreach ($this->lines as $index => $line) {
            if ($line['product_id'] === $product->id) {
                $this->lines[$index]['quantity'] = (string) ((float) $line['quantity'] + 1);

                return;
            }
        }

        $this->lines[] = [
            'product_id' => $product->id,
            'description' => $product->name,
            'code' => $product->code,
            'quantity' => '1',
            'unit_price' => $pos->priceFor($product)->round(2)->toString(),
            'tax_id' => $product->tax_id,
            'tax_rate' => (string) ($product->tax?->rate ?? '0'),
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function clear(): void
    {
        $this->reset(['lines', 'term', 'payments', 'tendered', 'checkingOut', 'lastSaleId']);
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Totales
    |--------------------------------------------------------------------------
    */

    /**
     * Totales calculados en el servidor.
     *
     * No se confía en lo que traiga el navegador ni siquiera para mostrar: es la
     * regla del sistema desde la Fase 0, y aquí además el importe que se muestra
     * es el que el cliente va a pagar en efectivo.
     *
     * @return array{subtotal: Money, tax: Money, total: Money}
     */
    public function totals(): array
    {
        $subtotal = Money::zero();
        $tax = Money::zero();

        foreach ($this->lines as $line) {
            $lineSubtotal = Money::ofRounded(
                bcmul((string) $line['quantity'], (string) $line['unit_price'], 8)
            );

            $subtotal = $subtotal->plus($lineSubtotal);
            $tax = $tax->plus(Money::ofRounded(
                bcdiv(bcmul($lineSubtotal->toString(), (string) $line['tax_rate'], 8), '100', 8)
            ));
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal->plus($tax),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Cobro
    |--------------------------------------------------------------------------
    */

    public function startCheckout(): void
    {
        if ($this->lines === []) {
            $this->addError('term', 'No hay nada que cobrar.');

            return;
        }

        $total = $this->totals()['total'];

        // Arranca con el importe exacto en efectivo, que es el caso de nueve de
        // cada diez ventas: si el cliente paga justo, basta con confirmar.
        $this->payments = [[
            'method' => PaymentMethod::Cash->value,
            'amount' => $total->round(2)->toString(),
            'account_id' => null,
            'reference' => '',
        ]];

        $this->tendered = '';
        $this->checkingOut = true;
    }

    public function addPaymentLine(): void
    {
        $this->payments[] = [
            'method' => PaymentMethod::Card->value,
            'amount' => $this->pendingAmount()->round(2)->toString(),
            'account_id' => null,
            'reference' => '',
        ];
    }

    public function removePaymentLine(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    /**
     * Lo que falta por cubrir del total.
     */
    public function pendingAmount(): Money
    {
        $paid = Money::zero();

        foreach ($this->payments as $payment) {
            $amount = (string) ($payment['amount'] ?? '0');

            if ($amount !== '' && is_numeric($amount)) {
                $paid = $paid->plus(Money::of($amount));
            }
        }

        return $this->totals()['total']->minus($paid);
    }

    /**
     * El vuelto: lo que el cliente entregó menos lo que se cobra en efectivo.
     */
    public function changeAmount(): Money
    {
        if ($this->tendered === '' || ! is_numeric($this->tendered)) {
            return Money::zero();
        }

        $cash = Money::zero();

        foreach ($this->payments as $payment) {
            if (($payment['method'] ?? null) !== PaymentMethod::Cash->value) {
                continue;
            }

            $amount = (string) ($payment['amount'] ?? '0');

            if ($amount !== '' && is_numeric($amount)) {
                $cash = $cash->plus(Money::of($amount));
            }
        }

        $change = Money::of($this->tendered)->minus($cash);

        return $change->isNegative() ? Money::zero() : $change;
    }

    public function checkout(PointOfSaleService $pos): void
    {
        $branch = $this->branch();

        $payments = [];

        foreach ($this->payments as $payment) {
            $amount = (string) ($payment['amount'] ?? '0');

            if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $isCash = ($payment['method'] ?? null) === PaymentMethod::Cash->value;

            $payments[] = [
                'method' => $payment['method'],
                'amount' => $amount,
                'account_id' => $payment['account_id'] ?? null,
                'reference' => $payment['reference'] ?? null,
                'tendered' => $isCash && $this->tendered !== '' ? $this->tendered : null,
                'change_given' => $isCash && $this->tendered !== ''
                    ? $this->changeAmount()->round(2)->toString()
                    : null,
            ];
        }

        try {
            $sale = $pos->checkout(
                $branch,
                array_map(fn (array $line) => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_id' => $line['tax_id'],
                ], $this->lines),
                $payments,
                $this->customerId,
            );
        } catch (SalesException|FiscalException $e) {
            $this->addError('checkout', $e->getMessage());

            return;
        }

        $this->clear();
        $this->lastSaleId = $sale->id;
        $this->customerId = $this->resolveWalkIn($pos);

        session()->flash('success', 'Factura '.$sale->number.' emitida.');
    }

    public function cancelCheckout(): void
    {
        $this->reset(['checkingOut', 'payments', 'tendered']);
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    private function branch(): Branch
    {
        $branch = app(CompanyContext::class)->branch();

        return $branch ?? Branch::query()->active()->orderByDesc('is_main')->firstOrFail();
    }

    private function resolveWalkIn(PointOfSaleService $pos): ?int
    {
        try {
            return $pos->walkInCustomer()->id;
        } catch (SalesException) {
            return null;
        }
    }

    public function render(PointOfSaleService $pos): View
    {
        $branch = $this->branch();

        return view('livewire.sales.point-of-sale', [
            'branch' => $branch,
            'session' => $pos->openSessionFor($branch),
            'blocked' => $pos->blockingReason($branch),
            'results' => $this->term === '' ? collect() : $pos->search($this->term),
            'totals' => $this->totals(),
            'pending' => $this->pendingAmount(),
            'change' => $this->changeAmount(),
            'methods' => PaymentMethod::cases(),
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->limit(200)->get(),
            'bankAccounts' => $this->bankAccounts(),
            'lastSale' => $this->lastSaleId === null ? null : Sale::query()->find($this->lastSaleId),
        ]);
    }

    /**
     * Cuentas donde puede caer un cobro que no es efectivo.
     *
     * @return Collection<int, Account>
     */
    private function bankAccounts()
    {
        return Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get();
    }
}
