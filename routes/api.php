<?php

declare(strict_types=1);

use App\Domains\Api\Data\ApiScope;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API pública v1
|--------------------------------------------------------------------------
|
| Todo cuelga de tres capas, en este orden:
|
|   auth:sanctum   autentica el token
|   api.company    activa la empresa **del token** y comprueba que siga válido
|   api.scope:…    exige el alcance del token y el permiso de su dueño
|
| El prefijo `/api/v1` lo pone `bootstrap/app.php`. Está versionado desde el
| primer día porque una API pública sin versión no se puede cambiar sin
| romperle el software a alguien.
|
| El límite de peticiones es por token y no por IP: varios clientes detrás del
| mismo NAT no deben estorbarse, y un token que se descontrola no debe poder
| dejar fuera a los demás.
*/

Route::middleware(['auth:sanctum', 'api.company', 'throttle:api'])->group(function (): void {

    // Quién soy: sirve para que un integrador compruebe sus credenciales sin
    // tocar datos y para depurar «¿sobre qué empresa estoy actuando?».
    Route::get('/me', function (Request $request) {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'data' => [
                'company' => [
                    'id' => $token->company->id,
                    'legal_name' => $token->company->legal_name,
                    'tax_id' => $token->company->tax_id,
                ],
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ],
                'token' => [
                    'name' => $token->name,
                    'scopes' => $token->scopes(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ],
            ],
        ]);
    })->name('api.me');

    // Catálogo
    Route::middleware('api.scope:'.ApiScope::CATALOG_READ)->group(function (): void {
        Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('api.products.show');
    });

    Route::get('/products/{product}/stock', [ProductController::class, 'stock'])
        ->middleware('api.scope:'.ApiScope::INVENTORY_READ)
        ->name('api.products.stock');

    // Clientes
    Route::middleware('api.scope:'.ApiScope::CUSTOMERS_READ)->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])->name('api.customers.index');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('api.customers.show');
    });

    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('api.scope:'.ApiScope::CUSTOMERS_WRITE)
        ->name('api.customers.store');

    Route::get('/customers/{customer}/receivables', [CustomerController::class, 'receivables'])
        ->middleware('api.scope:'.ApiScope::RECEIVABLES_READ)
        ->name('api.customers.receivables');

    // Facturas
    Route::middleware('api.scope:'.ApiScope::SALES_READ)->group(function (): void {
        Route::get('/sales', [SaleController::class, 'index'])->name('api.sales.index');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('api.sales.show');
    });

    Route::middleware('api.scope:'.ApiScope::SALES_WRITE)->group(function (): void {
        Route::post('/sales', [SaleController::class, 'store'])->name('api.sales.store');
        Route::post('/sales/{sale}/void', [SaleController::class, 'void'])->name('api.sales.void');
    });
});
