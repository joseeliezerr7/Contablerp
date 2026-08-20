<?php

declare(strict_types=1);

namespace App\Domains\Sales\Services;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Services\FiscalNumberService;
use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Domains\Treasury\Models\CashSession;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Venta de mostrador.
 *
 * ## Qué añade sobre `SaleService`
 *
 * Nada del motor: una venta de POS **es** una factura de contado y pasa por el
 * mismo `createAndIssue`, con el mismo CAI, el mismo kardex y la misma partida.
 * Lo que este servicio aporta es lo que el mostrador da por supuesto y la
 * pantalla de facturación pregunta: qué caja está abierta, en qué cuenta cae el
 * efectivo, quién es el cliente cuando no hay cliente.
 *
 * ## Sin caja abierta no se vende
 *
 * Es la regla que hace que el arqueo signifique algo. El efectivo entra en la
 * cuenta contable de **la sesión abierta**, y el arqueo recorre esa
 * cuenta durante la ventana de la sesión: si una venta pudiera cobrarse sin
 * sesión, ese dinero aparecería en la caja sin que ningún cierre lo hubiera
 * contado, y el faltante saldría al día siguiente sin explicación.
 *
 * ## El vuelto no es un cobro
 *
 * Lo que se registra como cobrado es el total de la factura. Lo que el cliente
 * entregó y lo que se le devolvió se guardan aparte, porque son lo que permite
 * reconstruir un arqueo cuando el cajero recuerda mal, pero no mueven ni un
 * lempira en el libro.
 */
final class PointOfSaleService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleService $sales,
        private readonly FiscalNumberService $fiscal,
    ) {}

    /**
     * Sesión de caja abierta que puede usar el usuario en esta sucursal.
     *
     * Se busca la abierta por **él**: dos cajeros en el mismo mostrador tienen
     * cada uno su caja, y cobrarle a la ajena le arruina el arqueo al otro.
     */
    public function openSessionFor(Branch $branch, ?int $userId = null): ?CashSession
    {
        return CashSession::query()
            ->with('account')
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->where('opened_by', $userId ?? Auth::id())
            ->latest('opened_at')
            ->first();
    }

    /**
     * Qué le impide vender ahora mismo, en una frase. Null si todo está listo.
     *
     * Lo consulta la pantalla al abrirse, para que el cajero se entere antes de
     * marcar treinta productos y no al pulsar «Cobrar».
     */
    public function blockingReason(Branch $branch): ?string
    {
        if ($this->openSessionFor($branch) === null) {
            return 'No tenés una caja abierta en esta sucursal. Abrila en Tesorería → Caja antes de vender.';
        }

        return $this->fiscal->blockingReason($branch, FiscalDocumentType::Invoice);
    }

    /**
     * Busca un producto por código de barras, SKU o nombre.
     *
     * El código de barras se comprueba primero y por igualdad exacta: es lo que
     * dispara la pistola del mostrador, y una coincidencia parcial con otro
     * producto sería un cobro equivocado.
     *
     * @return Collection<int, Product>
     */
    public function search(string $term, int $limit = 8)
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $exact = Product::query()
            ->with(['tax', 'prices'])
            ->active()
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('code', $term))
            ->get();

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return Product::query()
            ->with(['tax', 'prices'])
            ->active()
            ->search($term)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Precio de mostrador de un producto.
     *
     * Sale de la lista predeterminada; si el producto no está listado, del
     * precio de referencia del catálogo. Un producto sin ninguno de los dos
     * devuelve cero y la pantalla lo señala: cobrar cero por descuido es peor
     * que no poder venderlo.
     */
    public function priceFor(Product $product, ?PriceList $list = null): Money
    {
        $list ??= PriceList::default();

        return $product->priceIn($list?->id) ?? Money::of($product->price ?? '0');
    }

    /**
     * Cliente por defecto del mostrador.
     *
     * Casi ninguna venta de contado lleva cliente identificado, y obligar a
     * elegir uno en cada una convertiría el POS en un formulario. El cajero
     * puede cambiarlo cuando alguien pide la factura a su nombre.
     *
     * Sale del cliente marcado como «de mostrador», no del primero de la lista:
     * adivinarlo es lo que hacía que el mostrador le facturara todo a la primera
     * constructora que hubiera comprado alguna vez.
     */
    public function walkInCustomer(): Customer
    {
        $customer = Customer::query()
            ->where('is_active', true)
            ->where('is_walk_in', true)
            ->first();

        if ($customer === null) {
            throw SalesException::noWalkInCustomer();
        }

        return $customer;
    }

    /**
     * Cobra la venta y emite la factura.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function checkout(
        Branch $branch,
        array $lines,
        array $payments,
        ?int $customerId = null,
        ?int $warehouseId = null,
    ): Sale {
        $session = $this->openSessionFor($branch);

        if ($session === null) {
            throw SalesException::noOpenCashSession($branch);
        }

        if ($lines === []) {
            throw SalesException::noLines();
        }

        return DB::transaction(function () use ($branch, $lines, $payments, $customerId, $warehouseId, $session): Sale {
            return $this->sales->createAndIssue(
                [
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouseId ?? $this->defaultWarehouseId($branch),
                    'customer_id' => $customerId ?? $this->walkInCustomer()->id,
                    'date' => now()->toDateString(),
                    'payment_condition' => PaymentCondition::Cash,
                ],
                $lines,
                payments: $this->resolvePaymentAccounts($payments, $session),
            );
        });
    }

    /**
     * Pone a cada cobro la cuenta contable donde cae el dinero.
     *
     * El efectivo va **siempre** a la cuenta de la caja abierta, y eso no lo
     * elige el cajero: es lo que enlaza la venta con el arqueo. Los demás medios
     * llevan la cuenta bancaria que se haya indicado.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function resolvePaymentAccounts(array $payments, CashSession $session): array
    {
        return array_map(function (array $payment) use ($session): array {
            $method = $payment['method'] instanceof PaymentMethod
                ? $payment['method']
                : PaymentMethod::from((string) $payment['method']);

            if ($method === PaymentMethod::Cash) {
                $payment['account_id'] = $session->account_id;
            }

            if (($payment['account_id'] ?? null) === null) {
                throw SalesException::paymentNeedsAccount($method);
            }

            return $payment;
        }, $payments);
    }

    private function defaultWarehouseId(Branch $branch): ?int
    {
        return Warehouse::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->value('id');
    }
}
