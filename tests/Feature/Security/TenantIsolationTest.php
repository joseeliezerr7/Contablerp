<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Livewire\Tenancy\BranchIndex;
use App\Livewire\Tenancy\WarehouseIndex;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\MissingCompanyContextException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Capa 2 — Lectura: el scope global
|--------------------------------------------------------------------------
*/

it('solo devuelve registros de la empresa activa', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    Branch::factory()->count(3)->forCompany($companyA)->create();
    Branch::factory()->count(5)->forCompany($companyB)->create();

    actingAsUserOf($companyA);

    // 3 creadas + la Casa Matriz de withMainBranch()
    expect(Branch::query()->count())->toBe(4)
        ->and(Branch::query()->pluck('company_id')->unique()->all())->toBe([$companyA->id]);
});

it('no encuentra por id un registro de otra empresa', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    $branchOfB = Branch::factory()->forCompany($companyB)->create();

    actingAsUserOf($companyA);

    expect(Branch::query()->find($branchOfB->id))->toBeNull();
});

it('falla en vez de devolver todo cuando no hay empresa activa', function () {
    companyWithBranch();

    app(CompanyContext::class)->clear();

    expect(fn () => Branch::query()->get())
        ->toThrow(MissingCompanyContextException::class);
});

it('permite cruzar empresas solo de forma explícita', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    actingAsUserOf($companyA);

    $total = acrossCompanies(fn () => Branch::query()->count());

    expect($total)->toBe(2)
        ->and(Branch::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Capa 3 — Escritura: el company_id nunca viene del cliente
|--------------------------------------------------------------------------
*/

it('asigna la empresa activa al crear, ignorando lo que llegue del cliente', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    actingAsUserOf($companyA);

    $branch = Branch::create(['code' => 'X1', 'name' => 'Nueva']);

    expect($branch->company_id)->toBe($companyA->id)
        ->not->toBe($companyB->id);
});

it('prohíbe mover un registro de una empresa a otra', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    actingAsUserOf($companyA);

    $branch = Branch::factory()->forCompany($companyA)->create();
    $branch->company_id = $companyB->id;

    expect(fn () => $branch->save())->toThrow(RuntimeException::class);

    expect(acrossCompanies(fn () => Branch::acrossCompanies()->find($branch->id)->company_id))
        ->toBe($companyA->id);
});

/*
|--------------------------------------------------------------------------
| Capa 4 — Autorización: policies
|--------------------------------------------------------------------------
*/

it('deniega editar la sucursal de otra empresa desde el componente', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    $branchOfB = Branch::factory()->forCompany($companyB)->create();

    actingAsUserOf($companyA);

    // ModelNotFoundException se traduce en 404 sobre HTTP; en la prueba del
    // componente se observa como excepción.
    expect(fn () => Livewire::test(BranchIndex::class)->call('edit', $branchOfB->id))
        ->toThrow(ModelNotFoundException::class);
});

it('deniega eliminar la sucursal de otra empresa', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    $branchOfB = Branch::factory()->forCompany($companyB)->create();

    actingAsUserOf($companyA);

    expect(fn () => Livewire::test(BranchIndex::class)->call('delete', $branchOfB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(acrossCompanies(fn () => Branch::acrossCompanies()->whereKey($branchOfB->id)->exists()))
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Capa 5 — Validación: llaves foráneas que llegan en el request
|--------------------------------------------------------------------------
*/

it('rechaza una sucursal de otra empresa como llave foránea', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    $branchOfB = Branch::factory()->forCompany($companyB)->create();

    actingAsUserOf($companyA);

    Livewire::test(WarehouseIndex::class)
        ->set('code', 'BOD99')
        ->set('name', 'Bodega intrusa')
        ->set('branch_id', $branchOfB->id)
        ->call('save')
        ->assertHasErrors('branch_id');

    expect(Warehouse::query()->where('code', 'BOD99')->exists())->toBeFalse();
});

it('acepta una sucursal de la propia empresa', function () {
    $company = companyWithBranch();
    $branch = Branch::factory()->forCompany($company)->create();

    actingAsUserOf($company);

    Livewire::test(WarehouseIndex::class)
        ->set('code', 'BOD50')
        ->set('name', 'Bodega válida')
        ->set('branch_id', $branch->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Warehouse::query()->where('code', 'BOD50')->first()->company_id)->toBe($company->id);
});

/*
|--------------------------------------------------------------------------
| Aislamiento sobre HTTP, atravesando el middleware real
|--------------------------------------------------------------------------
*/

it('no muestra en la lista las sucursales de otra empresa', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    Branch::factory()->forCompany($companyA)->create(['name' => 'Sucursal Propia']);
    Branch::factory()->forCompany($companyB)->create(['name' => 'Sucursal Ajena']);

    actingAsUserOf($companyA);

    $this->get(route('branches.index'))
        ->assertOk()
        ->assertSee('Sucursal Propia')
        ->assertDontSee('Sucursal Ajena');
});

it('impide activar una empresa a la que el usuario no pertenece', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    actingAsUserOf($companyA);

    $this->post(route('company.switch', $companyB))->assertNotFound();

    expect(session('current_company_id'))->not->toBe($companyB->id);
});

it('ignora una empresa ajena inyectada en la sesión', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    $user = actingAsUserOf($companyA);

    // Sesión manipulada: el middleware debe volver a resolver contra company_user.
    session(['current_company_id' => $companyB->id]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($companyA->displayName())
        ->assertDontSee($companyB->displayName());

    expect(session('current_company_id'))->toBe($companyA->id);
});
