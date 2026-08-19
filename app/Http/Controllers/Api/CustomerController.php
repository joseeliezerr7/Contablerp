<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Receivables\Services\ReceivableService;
use App\Http\Resources\Api\CustomerResource;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', '%'.$request->query('search').'%')
                    ->orWhere('code', 'like', $request->query('search').'%')
                    ->orWhere('tax_id', $request->query('search'))
            ))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($customers);
    }

    public function show(int $customer): CustomerResource|JsonResponse
    {
        $found = Customer::query()->find($customer);

        if ($found === null) {
            return $this->fail('No existe ese cliente.', 404, 'not_found');
        }

        return new CustomerResource($found);
    }

    public function store(Request $request, CompanyContext $context): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:200'],
                'trade_name' => ['nullable', 'string', 'max:200'],
                'tax_id' => ['nullable', 'string', 'max:20'],
                'type' => ['nullable', Rule::in(['individual', 'company'])],
                'email' => ['nullable', 'email', 'max:150'],
                'phone' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:255'],
                // Sin límite ni días, el cliente nace de contado. Es lo correcto:
                // una integración no debería poder abrir crédito por su cuenta.
                'credit_limit' => ['nullable', 'numeric', 'min:0'],
                'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'code' => [
                    'nullable', 'string', 'max:20',
                    Rule::unique('customers', 'code')
                        ->where('company_id', $context->idOrFail())
                        ->whereNull('deleted_at'),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->fail('Los datos no son válidos.', 422, 'validation_failed', [
                'fields' => $e->errors(),
            ]);
        }

        $customer = Customer::create([
            ...$data,
            'code' => $data['code'] ?? $this->nextCode(),
            'type' => $data['type'] ?? 'company',
            'credit_limit' => $data['credit_limit'] ?? '0',
            'credit_days' => $data['credit_days'] ?? 0,
            'is_active' => true,
        ]);

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    /**
     * Antigüedad de saldos del cliente: lo que debe y desde cuándo.
     */
    public function receivables(int $customer, ReceivableService $receivables): JsonResponse
    {
        $found = Customer::query()->find($customer);

        if ($found === null) {
            return $this->fail('No existe ese cliente.', 404, 'not_found');
        }

        $aging = $receivables->aging(customerId: $found->id);

        // Los tramos vienen del mismo servicio que alimenta el reporte de la
        // aplicación: si un día cambia el criterio de «vencido», cambia en los
        // dos sitios a la vez.
        $buckets = array_map(
            fn ($amount) => $amount->toScale(2),
            $aging['totals'],
        );

        $documents = Receivable::query()
            ->outstanding()
            ->where('customer_id', $found->id)
            ->orderBy('due_date')
            ->get()
            ->map(fn (Receivable $r) => [
                'document' => $r->document_number,
                'date' => $r->date->toDateString(),
                'due_date' => $r->due_date->toDateString(),
                'days_overdue' => max(0, $r->daysOverdue()),
                'original' => $r->originalAmount()->toScale(2),
                'paid' => $r->paidAmount()->toScale(2),
                'credited' => $r->creditedAmount()->toScale(2),
                'balance' => $r->balanceAmount()->toScale(2),
            ]);

        return response()->json([
            'data' => [
                'customer_id' => $found->id,
                'outstanding' => $found->outstandingBalance()->toScale(2),
                'buckets' => $buckets,
                'documents' => $documents,
            ],
        ]);
    }

    /**
     * Correlativo simple para clientes creados por API.
     */
    private function nextCode(): string
    {
        $last = Customer::query()->where('code', 'like', 'CLI%')->max('code');
        $number = $last === null ? 1 : ((int) mb_substr($last, 3)) + 1;

        return 'CLI'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
