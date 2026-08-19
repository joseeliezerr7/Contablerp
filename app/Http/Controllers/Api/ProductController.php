<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\InventoryStock;
use App\Http\Resources\Api\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogo de productos.
 *
 * Todas las consultas pasan por el scope global de empresa, así que no hay un
 * solo `where('company_id', ...)` escrito a mano. Esa es justamente la garantía:
 * si alguien olvidara ponerlo, el aislamiento seguiría en pie.
 */
class ProductController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with(['tax', 'prices'])
            ->when($request->boolean('only_active', true), fn ($q) => $q->active())
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->query('search')))
            ->when($request->filled('barcode'), fn ($q) => $q->where('barcode', $request->query('barcode')))
            ->orderBy('code')
            ->paginate($this->perPage($request));

        return ProductResource::collection($products);
    }

    public function show(int $product): ProductResource|JsonResponse
    {
        $found = Product::query()->with(['tax', 'prices'])->find($product);

        if ($found === null) {
            return $this->fail('No existe ese producto.', 404, 'not_found');
        }

        return new ProductResource($found);
    }

    /**
     * Existencia por bodega.
     *
     * Va aparte del recurso del producto porque es un dato caro y volátil: quien
     * lista el catálogo casi nunca lo necesita, y quien lo necesita lo pregunta
     * para un producto concreto.
     */
    public function stock(int $product): JsonResponse
    {
        $found = Product::query()->find($product);

        if ($found === null) {
            return $this->fail('No existe ese producto.', 404, 'not_found');
        }

        $rows = InventoryStock::query()
            ->with('warehouse:id,code,name')
            ->where('product_id', $found->id)
            ->get();

        return response()->json([
            'data' => [
                'product_id' => $found->id,
                'tracks_inventory' => (bool) $found->track_inventory,
                'warehouses' => $rows->map(fn (InventoryStock $row) => [
                    'warehouse' => [
                        'id' => $row->warehouse->id,
                        'code' => $row->warehouse->code,
                        'name' => $row->warehouse->name,
                    ],
                    'quantity' => (string) $row->quantity,
                ])->values(),
                'total' => (string) $rows->sum(fn (InventoryStock $row) => (float) $row->quantity),
            ],
        ]);
    }
}
