<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Warehouse;
use App\Livewire\Tenancy\BranchIndex;
use App\Livewire\Tenancy\CompanyIndex;
use App\Livewire\Tenancy\WarehouseIndex;
use Livewire\Livewire;

it('crea la empresa junto con su casa matriz y su bodega por defecto', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    Livewire::test(CompanyIndex::class)
        ->call('create')
        ->set('legal_name', 'Ferretería del Valle, S. de R.L.')
        ->set('trade_name', 'Ferrevalle')
        ->set('tax_id', '08019990001122')
        ->set('currency_code', 'HNL')
        ->call('save')
        ->assertHasNoErrors();

    $nueva = acrossCompanies(
        fn () => Company::where('tax_id', '08019990001122')->firstOrFail()
    );

    expect($nueva->legal_name)->toBe('Ferretería del Valle, S. de R.L.');

    [$branches, $warehouses] = acrossCompanies(fn () => [
        Branch::acrossCompanies()->where('company_id', $nueva->id)->get(),
        Warehouse::acrossCompanies()->where('company_id', $nueva->id)->get(),
    ]);

    expect($branches)->toHaveCount(1)
        ->and($branches->first()->is_main)->toBeTrue()
        ->and($branches->first()->name)->toBe('Casa Matriz')
        ->and($warehouses)->toHaveCount(1)
        ->and($warehouses->first()->is_default)->toBeTrue()
        ->and($warehouses->first()->branch_id)->toBe($branches->first()->id);
});

it('da acceso a la empresa recién creada a quien la crea', function () {
    $company = companyWithBranch();
    $user = actingAsUserOf($company);

    Livewire::test(CompanyIndex::class)
        ->call('create')
        ->set('legal_name', 'Transportes Sula, S.A.')
        ->set('tax_id', '05019990003344')
        ->call('save')
        ->assertHasNoErrors();

    $nueva = acrossCompanies(fn () => Company::where('tax_id', '05019990003344')->firstOrFail());

    expect($user->refresh()->belongsToCompany($nueva->id))->toBeTrue();
});

it('exige RTN único dentro de la misma cuenta', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    Livewire::test(CompanyIndex::class)
        ->call('create')
        ->set('legal_name', 'Duplicada, S.A.')
        ->set('tax_id', $company->tax_id)
        ->call('save')
        ->assertHasErrors(['tax_id' => 'unique']);
});

it('crea una sucursal en la empresa activa', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    Livewire::test(BranchIndex::class)
        ->call('create')
        ->set('code', '002')
        ->set('name', 'Sucursal Comayagüela')
        ->call('save')
        ->assertHasNoErrors();

    $branch = Branch::query()->where('code', '002')->firstOrFail();

    expect($branch->company_id)->toBe($company->id)
        ->and($branch->name)->toBe('Sucursal Comayagüela');
});

it('exige código de sucursal único por empresa pero lo permite en otra', function () {
    $companyA = companyWithBranch();
    $companyB = companyWithBranch();

    Branch::factory()->forCompany($companyB)->create(['code' => '077']);

    actingAsUserOf($companyA);

    // El código existe en la empresa B: en la A debe aceptarse.
    Livewire::test(BranchIndex::class)
        ->call('create')
        ->set('code', '077')
        ->set('name', 'Sucursal Nueva')
        ->call('save')
        ->assertHasNoErrors();

    // Repetirlo dentro de la misma empresa no.
    Livewire::test(BranchIndex::class)
        ->call('create')
        ->set('code', '077')
        ->set('name', 'Otra más')
        ->call('save')
        ->assertHasErrors(['code' => 'unique']);
});

it('deja una sola casa matriz por empresa', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    $original = Branch::query()->where('is_main', true)->firstOrFail();

    Livewire::test(BranchIndex::class)
        ->call('create')
        ->set('code', '900')
        ->set('name', 'Nueva Matriz')
        ->set('is_main', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Branch::query()->where('is_main', true)->count())->toBe(1)
        ->and($original->refresh()->is_main)->toBeFalse();
});

it('no elimina una sucursal con bodegas asignadas', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    $branch = Branch::factory()->forCompany($company)->create();
    Warehouse::factory()->forBranch($branch)->create();

    Livewire::test(BranchIndex::class)
        ->call('delete', $branch->id);

    expect(Branch::query()->whereKey($branch->id)->exists())->toBeTrue();
});

it('deja una sola bodega por defecto por empresa', function () {
    $company = companyWithBranch();
    actingAsUserOf($company);

    $original = Warehouse::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->firstOrFail();

    Livewire::test(WarehouseIndex::class)
        ->call('create')
        ->set('code', 'BOD77')
        ->set('name', 'Bodega Nueva')
        ->set('branch_id', $branch->id)
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Warehouse::query()->where('is_default', true)->count())->toBe(1)
        ->and($original->refresh()->is_default)->toBeFalse();
});
