<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Models\WithholdingType;
use App\Domains\Assets\Services\DepreciationService;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductPrice;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Domains\Partners\Models\Customer;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Warehouse;
use App\Domains\Tenancy\Services\CompanyService;
use App\Domains\Treasury\Services\BankAccountService;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Datos de demostración de la Fase 0.
 *
 * Crea dos empresas bajo la misma cuenta para poder ejercitar el selector de
 * empresa, y un usuario con acceso a una sola de ellas para comprobar a mano el
 * aislamiento que las pruebas verifican de forma automática.
 */
class DatabaseSeeder extends Seeder
{
    public function run(CompanyService $companies, RoleProvisioner $roles): void
    {
        // Los planes existen antes que el primer cliente: son del proveedor.
        $this->call(PlanSeeder::class);

        $tenant = Tenant::create([
            'name' => 'Grupo Demo',
            'slug' => 'grupo-demo',
            'status' => TenantStatus::Active,
        ]);

        // El grupo demo lleva dos empresas, así que necesita el plan que lo
        // permite; sin suscripción la cuota no bloquearía nada, pero el panel
        // del proveedor aparecería vacío.
        app(SubscriptionService::class)->subscribe(
            $tenant,
            Plan::query()->where('code', 'corporativo')->firstOrFail(),
        );

        // Un superadministrador para poder mirar el panel del proveedor. No
        // pertenece a ninguna empresa: opera entre cuentas.
        User::create([
            'name' => 'Soporte del servicio',
            'email' => 'soporte@contable.test',
            'password' => Hash::make('password'),
            'is_active' => true,
            'is_super_admin' => true,
        ]);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Administrador',
            'email' => 'admin@contable.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $laCeiba = $companies->create([
            'legal_name' => 'Comercial La Ceiba, S. de R.L.',
            'trade_name' => 'Comercial La Ceiba',
            'tax_id' => '08019995123456',
            'address' => 'Barrio El Centro, La Ceiba, Atlántida',
            'phone' => '2443-1122',
            'email' => 'info@laceiba.test',
            'currency_code' => 'HNL',
            'fiscal_year_start_month' => 1,
            'is_active' => true,
        ], $admin);

        $tegucigalpa = $companies->create([
            'legal_name' => 'Distribuidora Tegucigalpa, S.A.',
            'trade_name' => 'DisTegus',
            'tax_id' => '05019998765432',
            'address' => 'Col. Palmira, Tegucigalpa, Francisco Morazán',
            'phone' => '2232-4455',
            'email' => 'ventas@distegus.test',
            'currency_code' => 'HNL',
            'fiscal_year_start_month' => 1,
            'is_active' => true,
        ], $admin);

        // Usuario limitado a una sola empresa.
        $vendedor = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendedor La Ceiba',
            'email' => 'vendedor@contable.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'default_company_id' => $laCeiba->id,
            'is_active' => true,
        ]);

        $vendedor->companies()->attach($laCeiba->id, ['branch_id' => null]);
        $roles->assign($vendedor, $laCeiba, PermissionCatalog::SALESPERSON);

        // Contador con acceso a ambas empresas, para probar el módulo contable.
        $contador = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contador General',
            'email' => 'contador@contable.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'default_company_id' => $laCeiba->id,
            'is_active' => true,
        ]);

        $contador->companies()->attach([$laCeiba->id, $tegucigalpa->id], ['branch_id' => null]);
        $roles->assign($contador, $laCeiba, PermissionCatalog::ACCOUNTANT);
        $roles->assign($contador, $tegucigalpa, PermissionCatalog::ACCOUNTANT);

        // Bodeguero: captura ajustes y traslados, pero no los aprueba. Existe
        // en los datos de ejemplo para poder comprobar esa separación.
        $bodeguero = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bodeguero La Ceiba',
            'email' => 'bodega@contable.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'default_company_id' => $laCeiba->id,
            'is_active' => true,
        ]);

        $bodeguero->companies()->attach($laCeiba->id, ['branch_id' => null]);
        $roles->assign($bodeguero, $laCeiba, PermissionCatalog::WAREHOUSE);

        // Sin CAI no hay factura. En los datos de ejemplo se carga uno de
        // ensayo; una empresa real captura el que le entrega el SAR.
        $this->seedFiscalDemo($laCeiba);
        $this->seedFiscalDemo($tegucigalpa);

        // El orden importa desde la Fase 5: no se puede vender mercadería que
        // todavía no se ha comprado.
        $this->seedSampleEntries($laCeiba, $contador);
        $this->seedCatalogDemo($laCeiba, $contador);
        $this->seedPurchasesDemo($laCeiba, $contador);
        $this->seedSalesDemo($laCeiba, $contador);
        $this->seedTreasuryDemo($laCeiba, $contador);
        $this->seedAssetsDemo($laCeiba, $contador);

        $this->command->info('Empresas creadas:');
        $this->command->table(
            ['ID', 'Empresa', 'RTN'],
            Company::all(['id', 'legal_name', 'tax_id'])->map->only(['id', 'legal_name', 'tax_id'])->all()
        );
        $this->command->info('Acceso (contraseña: password):');
        $this->command->line('  admin@contable.test     — Administrador, dos empresas');
        $this->command->line('  contador@contable.test  — Contador, dos empresas');
        $this->command->line('  vendedor@contable.test  — Vendedor, solo La Ceiba');
        $this->command->line('  bodega@contable.test    — Bodeguero, solo La Ceiba');
    }

    /**
     * Autorizaciones de ensayo para poder facturar en la demostración.
     *
     * **El CAI es inventado.** Empieza por `DEMO` justamente para que nadie lo
     * confunda con uno real: el que vale lo emite el SAR y se captura en la
     * pantalla de autorizaciones.
     */
    private function seedFiscalDemo(Company $company): void
    {
        app(CompanyContext::class)->runFor($company, function (): void {
            $service = app(FiscalAuthorizationService::class);

            foreach (FiscalPoint::query()->get() as $point) {
                foreach ([FiscalDocumentType::Invoice, FiscalDocumentType::CreditNote] as $type) {
                    $service->register($point, [
                        'document_type' => $type,
                        'document_type_code' => $type->suggestedCode(),
                        'cai' => 'DEMO'.mb_strtoupper(Str::random(2))
                            .'-'.mb_strtoupper(Str::random(6))
                            .'-'.mb_strtoupper(Str::random(6))
                            .'-'.mb_strtoupper(Str::random(6))
                            .'-'.mb_strtoupper(Str::random(6))
                            .'-'.mb_strtoupper(Str::random(2)),
                        'range_from' => 1,
                        'range_to' => 5000,
                        'issued_on' => now()->startOfYear()->toDateString(),
                        'limit_date' => now()->addYear()->toDateString(),
                        'notes' => 'Autorización de demostración. No es válida ante el SAR.',
                    ]);
                }
            }
        });
    }

    /**
     * Productos y clientes. Va antes que las compras porque las compras los
     * necesitan, y las compras van antes que las ventas porque hay que tener
     * mercadería para poder despacharla.
     */
    private function seedCatalogDemo(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);

        Auth::login($user);

        $context->runFor($company, function () use ($company): void {
            $isv = Tax::query()->where('code', 'ISV15')->firstOrFail();
            $detalle = PriceList::query()->where('code', 'DET')->firstOrFail();
            $mayorista = PriceList::query()->where('code', 'MAY')->firstOrFail();
            $unidad = Unit::query()->where('code', 'UND')->firstOrFail();

            // Segunda bodega, para que los traslados tengan a dónde ir.
            $sucursal = $company->branches()->where('is_main', true)->firstOrFail();

            $patio = new Warehouse;
            $patio->forceFill([
                'company_id' => $company->id,
                'branch_id' => $sucursal->id,
                'code' => 'BOD02',
                'name' => 'Patio de materiales',
                'is_default' => false,
                'is_active' => true,
            ])->save();

            $catalogo = [
                ['PRD0001', 'Cemento gris 42.5 kg', '210.00', '195.00'],
                ['PRD0002', 'Varilla de hierro 3/8"', '145.00', '132.00'],
                ['PRD0003', 'Lámina de zinc calibre 26', '380.00', '350.00'],
            ];

            foreach ($catalogo as [$code, $name, $detail, $wholesale]) {
                $product = new Product;
                $product->forceFill([
                    'company_id' => $company->id,
                    'code' => $code,
                    'name' => $name,
                    'type' => 'product',
                    'unit_id' => $unidad->id,
                    'tax_id' => $isv->id,
                    'track_inventory' => true,
                    'is_active' => true,
                ])->save();

                foreach ([[$detalle->id, $detail], [$mayorista->id, $wholesale]] as [$listId, $price]) {
                    $row = new ProductPrice;
                    $row->forceFill([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'price_list_id' => $listId,
                        'price' => $price,
                    ])->save();
                }

            }

            $constructora = new Customer;
            $constructora->forceFill([
                'company_id' => $company->id,
                'code' => 'CLI0001',
                'name' => 'Constructora del Atlántico, S.A.',
                'tax_id' => '08019991234567',
                'type' => 'company',
                'phone' => '2443-7788',
                'price_list_id' => $mayorista->id,
                'credit_limit' => '150000.00',
                'credit_days' => 30,
                'is_active' => true,
            ])->save();

            $mostrador = new Customer;
            $mostrador->forceFill([
                'company_id' => $company->id,
                'code' => 'CLI0002',
                'name' => 'Cliente de mostrador',
                'type' => 'individual',
                'price_list_id' => $detalle->id,
                'credit_limit' => '0',
                'credit_days' => 0,
                'is_active' => true,
                // A este le factura el punto de venta cuando nadie se identifica.
                'is_walk_in' => true,
            ])->save();
        });

        Auth::logout();
    }

    /**
     * Cuenta bancaria y una conciliación a medias, para que la pantalla se
     * pueda mirar sin tener que armarla primero.
     *
     * Se deja **con diferencia a propósito**: una conciliación que ya cuadra no
     * enseña nada, y lo que hay que ver es cómo el sistema señala lo que falta
     * por explicar.
     */
    private function seedTreasuryDemo(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);

        Auth::login($user);

        $context->runFor($company, function () use ($company): void {
            $banks = app(BankAccountService::class);
            $reconciliations = app(BankReconciliationService::class);

            $cuenta = $company->accounts()->where('code', '1.1.02.01')->firstOrFail();

            $bancaria = $banks->create([
                'account_id' => $cuenta->id,
                'bank_name' => 'Banco Atlántida',
                'number' => '01-234-567890',
                'alias' => 'Cuenta operativa',
                'type' => 'checking',
                'next_check_number' => 1001,
            ]);

            $corte = now()->endOfMonth();

            // El extracto trae 1 250 menos que el libro: es la comisión que el
            // banco cobró y todavía no se ha registrado.
            $conciliacion = $reconciliations->open(
                $bancaria,
                $corte,
                $banks->bookBalance($bancaria, $corte)->minus(Money::of('1250.00')),
                'Extracto de ejemplo: falta registrar la comisión del mes.',
            );

            $reconciliations->markAll($conciliacion);
        });

        Auth::logout();
    }

    /**
     * Activos fijos con un mes ya depreciado, y los tipos de retención
     * hondureños más habituales.
     */
    private function seedAssetsDemo(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);

        Auth::login($user);

        $context->runFor($company, function () use ($company): void {
            $branch = $company->branches()->where('is_main', true)->firstOrFail();
            $account = fn (string $code): int => $company->accounts()->where('code', $code)->firstOrFail()->id;

            $categories = [
                ['COMP', 'Equipo de cómputo', 36, '1.2.01.04', '1.2.02.03'],
                ['MOB', 'Mobiliario y equipo', 60, '1.2.01.03', '1.2.02.02'],
                ['VEH', 'Vehículos', 60, '1.2.01.05', '1.2.02.04'],
            ];

            $created = [];

            foreach ($categories as [$code, $name, $life, $assetCode, $accumulatedCode]) {
                $category = new FixedAssetCategory;
                $category->forceFill([
                    'company_id' => $company->id,
                    'code' => $code,
                    'name' => $name,
                    'useful_life_months' => $life,
                    'asset_account_id' => $account($assetCode),
                    'depreciation_account_id' => $account('6.1.06'),
                    'accumulated_account_id' => $account($accumulatedCode),
                    'is_active' => true,
                ])->save();

                $created[$code] = $category->id;
            }

            $assets = app(FixedAssetService::class);
            $engine = app(AccountingEngine::class);

            // Comprado hace dos meses, para que ya haya algo que depreciar.
            $acquired = now()->subMonths(2)->startOfMonth()->addDays(9);

            $items = [
                ['AF-0001', 'Laptop de gerencia', 'COMP', '32400.00', '1.2.01.04', 36],
                ['AF-0002', 'Escritorios y sillas', 'MOB', '48000.00', '1.2.01.03', 60],
                ['AF-0003', 'Pickup de reparto', 'VEH', '385000.00', '1.2.01.05', 60],
            ];

            foreach ($items as [$code, $name, $categoryCode, $cost, $assetCode, $life]) {
                // La compra es la que mete el activo al balance; darlo de alta
                // solo declara que hay que depreciarlo.
                $engine->post(
                    JournalDraft::on($acquired, 'Compra de activo fijo '.$code)
                        ->withReference($code)
                        ->debit($account($assetCode), $cost)
                        ->credit($account('1.1.02.01'), $cost)
                );

                $assets->create([
                    'branch_id' => $branch->id,
                    'fixed_asset_category_id' => $created[$categoryCode],
                    'code' => $code,
                    'name' => $name,
                    'acquired_on' => $acquired->toDateString(),
                    'cost' => $cost,
                    'salvage_value' => $categoryCode === 'VEH' ? '35000.00' : '0',
                    'useful_life_months' => $life,
                ]);
            }

            // Un mes ya corrido, para que la pantalla no aparezca vacía.
            app(DepreciationService::class)->run(now()->subMonth()->startOfMonth());

            // Retenciones hondureñas habituales.
            $withholdings = [
                ['ISR125', 'Retención de ISR sobre servicios', 'income_tax', '12.5', 'purchase', '2.1.02.03'],
                ['ISR1', 'Retención de ISR del 1 %', 'income_tax', '1', 'purchase', '2.1.02.03'],
                ['ISRC', 'ISR que nos retiene el cliente', 'income_tax', '12.5', 'sale', '1.1.05.02'],
            ];

            foreach ($withholdings as [$code, $name, $kind, $rate, $scope, $accountCode]) {
                $type = new WithholdingType;
                $type->forceFill([
                    'company_id' => $company->id,
                    'code' => $code,
                    'name' => $name,
                    'kind' => $kind,
                    'base' => 'total',
                    'rate' => $rate,
                    'applies_to' => $scope,
                    'account_id' => $account($accountCode),
                    'is_active' => true,
                ])->save();
            }
        });

        Auth::logout();
    }

    /**
     * Proveedores, compras y un pago, para que el módulo de compras tampoco
     * aparezca vacío.
     */
    private function seedPurchasesDemo(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);

        Auth::login($user);

        $context->runFor($company, function () use ($company): void {
            $branch = $company->branches()->where('is_main', true)->firstOrFail();
            $bodega = $branch->warehouses()->orderBy('code')->firstOrFail();
            $banco = $company->accounts()->where('code', '1.1.02.01')->firstOrFail();
            $productos = Product::query()->orderBy('code')->get();

            $ferretera = new Supplier;
            $ferretera->forceFill([
                'company_id' => $company->id,
                'code' => 'PRV0001',
                'name' => 'Ferretera Industrial de Honduras, S.A.',
                'tax_id' => '08019997654321',
                'type' => 'company',
                'phone' => '2550-3344',
                'contact_name' => 'Marta Lagos',
                'credit_days' => 30,
                'is_active' => true,
            ])->save();

            $servicios = new Supplier;
            $servicios->forceFill([
                'company_id' => $company->id,
                'code' => 'PRV0002',
                'name' => 'Servicios Generales del Norte',
                'tax_id' => '08019993216549',
                'type' => 'company',
                'phone' => '2443-9900',
                'credit_days' => 15,
                'is_active' => true,
            ])->save();

            $purchases = app(PurchaseService::class);
            $mes = now()->startOfMonth();

            $compra = $purchases->createAndReceive([
                'branch_id' => $branch->id,
                'warehouse_id' => $bodega->id,
                'supplier_id' => $ferretera->id,
                'supplier_invoice_number' => '000-001-01-00004521',
                'date' => $mes->copy()->addDays(4)->toDateString(),
                'payment_condition' => 'credit',
                'credit_days' => 30,
            ], [
                ['product_id' => $productos[0]->id, 'quantity' => '200', 'unit_price' => '150.00'],
                ['product_id' => $productos[1]->id, 'quantity' => '120', 'unit_price' => '95.00'],
                ['product_id' => $productos[2]->id, 'quantity' => '40', 'unit_price' => '295.00'],
            ]);

            // Compra de servicio: va a gasto, no a inventario.
            $purchases->createAndReceive([
                'branch_id' => $branch->id,
                'supplier_id' => $servicios->id,
                'supplier_invoice_number' => '000-002-01-00000187',
                'date' => $mes->copy()->addDays(8)->toDateString(),
                'payment_condition' => 'cash',
                'payment_account_id' => $banco->id,
            ], [[
                'description' => 'Mantenimiento de montacargas',
                'quantity' => '1',
                'unit_price' => '8500.00',
                'tax_id' => Tax::query()->where('code', 'ISV15')->value('id'),
                'expense_account_id' => $company->accounts()->where('code', '6.1.08')->value('id'),
            ]]);

            app(PaymentService::class)->create([
                'branch_id' => $branch->id,
                'supplier_id' => $ferretera->id,
                'date' => $mes->copy()->addDays(18)->toDateString(),
                'payment_method' => 'check',
                'reference' => 'CHQ-004512',
                'payment_account_id' => $banco->id,
            ], [
                ['payable_id' => $compra->payable->id, 'amount' => '15000.00'],
            ]);
        });

        Auth::logout();
    }

    /**
     * Facturas y un cobro. Va después de las compras: desde la Fase 5 cada
     * factura descarga la bodega, y no se puede despachar lo que no se ha
     * recibido.
     */
    private function seedSalesDemo(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);

        Auth::login($user);

        $context->runFor($company, function () use ($company): void {
            $branch = $company->branches()->where('is_main', true)->firstOrFail();
            $bodega = $branch->warehouses()->orderBy('code')->firstOrFail();
            $banco = $company->accounts()->where('code', '1.1.02.01')->firstOrFail();
            $productos = Product::query()->orderBy('code')->get();

            $constructora = Customer::query()->where('code', 'CLI0001')->firstOrFail();
            $mostrador = Customer::query()->where('code', 'CLI0002')->firstOrFail();

            $sales = app(SaleService::class);
            $mes = now()->startOfMonth();

            $credito = $sales->createAndIssue([
                'branch_id' => $branch->id,
                'warehouse_id' => $bodega->id,
                'customer_id' => $constructora->id,
                'date' => $mes->copy()->addDays(12)->toDateString(),
                'payment_condition' => 'credit',
                'credit_days' => 30,
            ], [
                ['product_id' => $productos[0]->id, 'quantity' => '100', 'unit_price' => '195.00'],
                ['product_id' => $productos[1]->id, 'quantity' => '50', 'unit_price' => '132.00'],
            ]);

            $sales->createAndIssue([
                'branch_id' => $branch->id,
                'warehouse_id' => $bodega->id,
                'customer_id' => $mostrador->id,
                'date' => $mes->copy()->addDays(14)->toDateString(),
                'payment_condition' => 'cash',
                'deposit_account_id' => $banco->id,
            ], [
                ['product_id' => $productos[2]->id, 'quantity' => '8', 'unit_price' => '380.00'],
            ]);

            app(ReceiptService::class)->create([
                'branch_id' => $branch->id,
                'customer_id' => $constructora->id,
                'date' => $mes->copy()->addDays(20)->toDateString(),
                'payment_method' => 'transfer',
                'reference' => 'TRF-88213',
                'deposit_account_id' => $banco->id,
            ], [
                ['receivable_id' => $credito->receivable->id, 'amount' => '10000.00'],
            ]);
        });

        Auth::logout();
    }

    /**
     * Partidas de ejemplo para que el libro diario y el mayor no aparezcan
     * vacíos al abrir el sistema por primera vez.
     *
     * Solo se siembran operaciones que de verdad se registran a mano. Las
     * cuentas de control —Clientes y Proveedores— se dejan exclusivamente a
     * los documentos de ventas y compras: un asiento manual contra ellas
     * cuadra el libro pero descuadra el auxiliar, y la antigüedad de saldos
     * deja de coincidir con el balance.
     */
    private function seedSampleEntries(Company $company, User $user): void
    {
        $context = app(CompanyContext::class);
        $engine = app(AccountingEngine::class);

        Auth::login($user);

        $context->runFor($company, function () use ($engine, $company): void {
            $account = fn (string $code): int => $company->accounts()
                ->where('code', $code)->firstOrFail()->id;

            $mes = now()->startOfMonth();

            $engine->post(
                JournalDraft::on($mes->copy()->addDays(2), 'Aporte inicial de capital')
                    ->debit($account('1.1.02.01'), '250000.00', 'Depósito en cuenta')
                    ->credit($account('3.1.01'), '250000.00')
            );

            $engine->post(
                JournalDraft::on($mes->copy()->addDays(6), 'Pago de alquiler del local')
                    ->withReference('CHQ-1001')
                    ->debit($account('6.1.03'), '18000.00', 'Alquiler del mes')
                    ->credit($account('1.1.02.01'), '18000.00', 'Cheque 1001')
            );

            $engine->post(
                JournalDraft::on($mes->copy()->addDays(20), 'Planilla de la quincena')
                    ->debit($account('6.1.01'), '35000.00', 'Sueldos')
                    ->credit($account('2.1.03.02'), '2450.00', 'IHSS')
                    ->credit($account('1.1.02.01'), '32550.00', 'Pago neto')
            );

            $engine->post(
                JournalDraft::on($mes->copy()->addDays(26), 'Comisiones y cargos bancarios')
                    ->debit($account('6.3.02'), '650.00', 'Comisión mensual')
                    ->credit($account('1.1.02.01'), '650.00')
            );
        });

        Auth::logout();
    }
}
