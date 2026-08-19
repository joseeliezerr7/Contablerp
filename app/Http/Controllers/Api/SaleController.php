<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Http\Resources\Api\SaleResource;
use App\Support\Tenancy\Rules\BelongsToCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Facturas de venta.
 *
 * ## Escribe por el servicio, nunca por el modelo
 *
 * `store()` llama a `SaleService::createAndIssue`, el mismo que usan la pantalla
 * de facturación y el punto de venta. Con eso la factura creada por API tiene
 * garantizado lo mismo que cualquier otra: correlativo del CAI, descarga de
 * inventario al costo real, partida contable cuadrada y cuenta por cobrar si es
 * al crédito. Una API que escribiera directo en las tablas sería una segunda
 * implementación de la contabilidad, y las dos se separarían en la primera
 * corrección que alguien olvidara replicar.
 *
 * ## Idempotencia
 *
 * Una integración reintenta. Si el reintento llega después de que la primera
 * petición emitió la factura, sin protección se emitirían dos y se gastarían dos
 * correlativos del SAR. La cabecera `Idempotency-Key` hace que el segundo intento
 * devuelva la factura del primero en vez de crear otra.
 */
class SaleController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::query()
            ->with(['customer:id,name,tax_id', 'receivable'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->query('to')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('number'), fn ($q) => $q->where('number', $request->query('number')))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return SaleResource::collection($sales);
    }

    public function show(int $sale): SaleResource|JsonResponse
    {
        $found = Sale::query()
            ->with(['customer', 'items', 'payments', 'receivable'])
            ->find($sale);

        if ($found === null) {
            return $this->fail('No existe esa factura.', 404, 'not_found');
        }

        return new SaleResource($found);
    }

    public function store(Request $request, SaleService $sales): JsonResponse
    {
        try {
            // `exists:` no basta: comprueba que el id exista en la tabla, no que
            // sea de esta empresa. El scope global protege las **lecturas**, no
            // los identificadores que llegan en el cuerpo de la petición. Sin
            // `BelongsToCurrentCompany`, mandar el id de un cliente ajeno no
            // filtraba datos pero sí reventaba con un 500 a mitad del servicio.
            $data = $request->validate([
                'customer_id' => ['required', 'integer', new BelongsToCurrentCompany('customers')],
                'branch_id' => ['nullable', 'integer', new BelongsToCurrentCompany('branches')],
                'warehouse_id' => ['nullable', 'integer', new BelongsToCurrentCompany('warehouses')],
                'date' => ['nullable', 'date'],
                'payment_condition' => ['nullable', Rule::in(PaymentCondition::values())],
                'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'reference' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:1000'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.product_id' => ['required', 'integer', new BelongsToCurrentCompany('products')],
                'items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
                'items.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'items.*.tax_id' => ['nullable', 'integer', new BelongsToCurrentCompany('taxes')],

                'payments' => ['nullable', 'array'],
                'payments.*.method' => ['required_with:payments', Rule::in(PaymentMethod::values())],
                'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0'],
                'payments.*.account_id' => ['nullable', 'integer', new BelongsToCurrentCompany('accounts')],
                'payments.*.reference' => ['nullable', 'string', 'max:100'],
            ]);
        } catch (ValidationException $e) {
            return $this->fail('Los datos no son válidos.', 422, 'validation_failed', [
                'fields' => $e->errors(),
            ]);
        }

        // Reintento de una petición ya atendida: se devuelve lo mismo, no otra
        // factura. Sin esto, un timeout del cliente se cobra dos veces.
        if ($existing = $this->previousAttempt($request)) {
            return (new SaleResource($existing->load(['customer', 'items', 'payments', 'receivable'])))
                ->response()
                ->setStatusCode(200)
                ->header('Idempotent-Replay', 'true');
        }

        $condition = PaymentCondition::from($data['payment_condition'] ?? 'cash');

        $branchId = $data['branch_id'] ?? $this->defaultBranchId();

        try {
            $sale = $sales->createAndIssue(
                [
                    'branch_id' => $branchId,
                    // Si no la mandan, la bodega predeterminada de la sucursal.
                    // Quien integra desde una tienda en línea no sabe —ni tiene
                    // por qué saber— de qué bodega sale la mercadería; obligarlo
                    // a mandarla era rechazarle su primera factura con un error
                    // sobre un concepto que no maneja.
                    'warehouse_id' => $data['warehouse_id'] ?? $this->defaultWarehouseId($branchId),
                    'customer_id' => $data['customer_id'],
                    'date' => $data['date'] ?? now()->toDateString(),
                    'payment_condition' => $condition,
                    'credit_days' => $data['credit_days'] ?? 0,
                    'deposit_account_id' => null,
                    'reference' => $this->idempotencyKey($request) ?? ($data['reference'] ?? null),
                    'notes' => $data['notes'] ?? null,
                ],
                $data['items'],
                payments: $data['payments'] ?? [],
            );
        } catch (SalesException|FiscalException|InventoryException $e) {
            // Quedarse sin existencia, sin CAI o con un cobro que no cuadra son
            // condiciones de negocio corrientes, no fallos del servidor. Un
            // integrador tiene que poder distinguirlas y reaccionar; un 500 con
            // una traza no le dice nada y ensucia el registro de errores.
            return $this->fail($e->getMessage(), 422, 'cannot_issue');
        }

        return (new SaleResource($sale->load(['customer', 'items', 'payments', 'receivable'])))
            ->response()
            ->setStatusCode(201);
    }

    public function void(int $sale, Request $request, SaleService $sales): SaleResource|JsonResponse
    {
        $found = Sale::query()->find($sale);

        if ($found === null) {
            return $this->fail('No existe esa factura.', 404, 'not_found');
        }

        $reason = trim((string) $request->input('reason'));

        if (mb_strlen($reason) < 5) {
            return $this->fail('La anulación exige un motivo de al menos 5 caracteres.', 422);
        }

        try {
            $voided = $sales->void($found, $reason);
        } catch (SalesException $e) {
            return $this->fail($e->getMessage(), 422, 'cannot_void');
        }

        return new SaleResource($voided->load(['customer', 'items', 'payments']));
    }

    /**
     * La factura que dejó un intento anterior con la misma clave.
     *
     * La clave se guarda en `reference`, que ya existía y es único de hecho por
     * empresa en la práctica. Guardarla en una tabla aparte sería más limpio,
     * pero también una tabla más que purgar; con el volumen de una PYME esto
     * alcanza y no añade nada que mantener.
     */
    private function previousAttempt(Request $request): ?Sale
    {
        $key = $this->idempotencyKey($request);

        if ($key === null) {
            return null;
        }

        return Sale::query()
            ->where('reference', $key)
            ->where('status', '!=', SaleStatus::Draft)
            ->first();
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : mb_substr($key, 0, 100);
    }

    private function defaultBranchId(): int
    {
        return (int) Branch::query()
            ->active()
            ->orderByDesc('is_main')
            ->value('id');
    }

    private function defaultWarehouseId(int $branchId): ?int
    {
        return Warehouse::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->value('id');
    }
}
