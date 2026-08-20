<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Services\PeriodService;
use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Domains\Partners\Models\Customer;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Warehouse;
use App\Domains\Tenancy\Services\CompanyService;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

/**
 * Crea un usuario con acceso a la empresa dada, lo autentica y activa la
 * empresa en el contexto.
 *
 * Activar el contexto a mano es necesario porque las pruebas de componentes
 * Livewire no atraviesan el middleware `company`; en las pruebas HTTP el
 * middleware lo haría de todos modos.
 */
function actingAsUserOf(Company $company, ?Branch $branch = null, ?string $role = null): User
{
    $user = User::factory()->forCompany($company, $branch)->create();

    test()->actingAs($user);
    app(CompanyContext::class)->set($company, $branch);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    if ($role !== null) {
        app(RoleProvisioner::class)->assign($user, $company, $role);
        $user->load('roles.permissions');
    }

    return $user;
}

/**
 * Empresa mínima para las pruebas de tenancy: sucursal principal y bodega, sin
 * contabilidad.
 */
function companyWithBranch(): Company
{
    return Company::factory()->withMainBranch()->create();
}

/**
 * Empresa completa creada por el servicio real: sucursal, bodega, plan de
 * cuentas, cuentas por módulo, ejercicio fiscal con sus doce períodos y roles.
 */
function accountingCompany(): Company
{
    $owner = User::factory()->create();

    $company = app(CompanyService::class)->create([
        'legal_name' => 'Empresa de Pruebas, S. de R.L.',
        'trade_name' => 'Pruebas',
        'tax_id' => (string) fake()->unique()->numerify('##############'),
        'currency_code' => 'HNL',
        'fiscal_year_start_month' => 1,
        'is_active' => true,
    ], $owner);

    // Sin CAI no se puede facturar, que es justo lo que exige la ley. Las
    // pruebas necesitan uno para llegar a la parte que están probando, así que
    // se carga aquí con un rango generoso y una fecha límite lejana.
    // Las pruebas del propio régimen fiscal cargan el suyo a medida.
    withFiscalAuthorization($company);

    return $company;
}

/**
 * Carga una autorización de ensayo en el punto de emisión de la empresa.
 *
 * @param  array<string, mixed>  $overrides
 */
function withFiscalAuthorization(
    Company $company,
    FiscalDocumentType $type = FiscalDocumentType::Invoice,
    array $overrides = [],
): FiscalAuthorization {
    return app(CompanyContext::class)->runFor($company, function () use ($type, $overrides): FiscalAuthorization {
        $point = FiscalPoint::query()->orderBy('emission_point_code')->firstOrFail();

        return app(FiscalAuthorizationService::class)->register($point, [
            'document_type' => $type,
            'document_type_code' => $type->suggestedCode(),
            'cai' => strtoupper(fake()->regexify('[0-9A-F]{6}(-[0-9A-F]{6}){4}-[0-9A-F]{2}')),
            'range_from' => 1,
            'range_to' => 5000,
            'issued_on' => now()->startOfYear()->toDateString(),
            'limit_date' => now()->addYear()->toDateString(),
            ...$overrides,
        ]);
    });
}

/**
 * Cuenta del plan de la empresa activa, por código.
 */
function account(string $code): Account
{
    return Account::query()->where('code', $code)->firstOrFail();
}

/**
 * Período contable de la empresa activa que contiene la fecha indicada.
 */
function periodFor(string $date = 'today'): AccountingPeriod
{
    return AccountingPeriod::query()
        ->containing(new DateTimeImmutable($date))
        ->firstOrFail();
}

/**
 * Atajo habitual: empresa contable lista y un usuario Contador dentro de ella.
 *
 * @return array{0: Company, 1: User}
 */
function accountingCompanyWithAccountant(): array
{
    $company = accountingCompany();
    $user = actingAsUserOf($company, role: PermissionCatalog::ACCOUNTANT);

    return [$company, $user];
}

/**
 * Impuesto sembrado de la empresa activa. Por defecto el ISV general del 15 %.
 */
function tax(string $code = 'ISV15'): Tax
{
    return Tax::query()->where('code', $code)->firstOrFail();
}

/**
 * Lista de precios sembrada de la empresa activa.
 */
function priceList(string $code = 'DET'): PriceList
{
    return PriceList::query()->where('code', $code)->firstOrFail();
}

function mainBranch(): Branch
{
    return Branch::query()->where('is_main', true)->firstOrFail();
}

/**
 * Cliente de la empresa activa.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeCustomer(array $attributes = []): Customer
{
    return Customer::factory()
        ->forCompany(app(CompanyContext::class)->idOrFail())
        ->create($attributes);
}

/**
 * Proveedor de la empresa activa.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeSupplier(array $attributes = []): Supplier
{
    return Supplier::factory()
        ->forCompany(app(CompanyContext::class)->idOrFail())
        ->create($attributes);
}

/**
 * Producto de la empresa activa, con precio en la lista indicada.
 *
 * `$tracked` decide si lleva control de existencias. Por defecto no lo lleva,
 * para que las pruebas de ventas y compras que no ejercitan inventario sigan
 * describiendo lo que describían.
 */
function makeProduct(
    string $price = '100.00',
    ?Tax $tax = null,
    string $cost = '0',
    bool $tracked = false,
): Product {
    return Product::factory()
        ->forCompany(app(CompanyContext::class)->idOrFail())
        ->when($tracked, fn (ProductFactory $f) => $f->tracked())
        ->withCost($cost)
        ->withTax($tax ?? tax())
        ->pricedAt($price)
        ->create();
}

/**
 * Abre los ejercicios fiscales de los años indicados.
 *
 * Hace falta en cuanto una prueba cruza de año —una depreciación a 36 meses,
 * por ejemplo—: el motor contable se niega, con razón, a asentar en un período
 * que nadie ha creado.
 */
function openFiscalYears(int ...$years): void
{
    $company = app(CompanyContext::class)->company();
    $periods = app(PeriodService::class);

    foreach ($years as $year) {
        if ($company->fiscalYears()->where('name', (string) $year)->exists()) {
            continue;
        }

        $periods->createFiscalYear($company, $year);
    }
}

/**
 * Bodega de la empresa activa. Devuelve la existente o crea una nueva.
 */
function warehouse(?string $code = null): Warehouse
{
    if ($code === null) {
        return Warehouse::query()->orderBy('id')->firstOrFail();
    }

    if ($existing = Warehouse::query()->where('code', $code)->first()) {
        return $existing;
    }

    $warehouse = new Warehouse;
    $warehouse->forceFill([
        'company_id' => app(CompanyContext::class)->idOrFail(),
        'branch_id' => mainBranch()->id,
        'code' => $code,
        'name' => 'Bodega '.$code,
        'is_default' => false,
        'is_active' => true,
    ])->save();

    return $warehouse;
}

/**
 * Saldo contable de una cuenta de la empresa activa: debe − haber sobre las
 * partidas contabilizadas.
 *
 * Es el número contra el que se contrastan los auxiliares —cuentas por cobrar,
 * por pagar, kardex—, así que va aquí y no en cada prueba.
 */
function ledgerBalanceOf(string $code): Money
{
    $row = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('a.code', $code)
        ->where('a.company_id', app(CompanyContext::class)->idOrFail())
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    return Money::of((string) $row->debit)->minus(Money::of((string) $row->credit));
}

/**
 * Ejecuta el callback sin filtro de empresa, para poder afirmar sobre datos de
 * varias empresas dentro de una misma prueba.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function acrossCompanies(Closure $callback): mixed
{
    return app(CompanyContext::class)->unscoped($callback);
}
