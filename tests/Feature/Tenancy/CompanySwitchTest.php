<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;

it('cambia la empresa activa entre las empresas del usuario', function () {
    $companyA = companyWithBranch();
    $companyB = Company::factory()->withMainBranch()->create(['tenant_id' => $companyA->tenant_id]);

    $user = User::factory()->forCompany($companyA)->create();
    $user->companies()->attach($companyB->id, ['branch_id' => null]);

    $this->actingAs($user);

    // El selector lista todas las empresas del usuario, así que el nombre no
    // distingue cuál está activa: se comprueba el marcador de empresa activa.
    $this->get(route('dashboard'))
        ->assertSee('data-active-company="'.$companyA->id.'"', escape: false);

    $this->post(route('company.switch', $companyB))
        ->assertRedirect(route('dashboard'));

    expect(session('current_company_id'))->toBe($companyB->id);

    $this->get(route('dashboard'))
        ->assertSee('data-active-company="'.$companyB->id.'"', escape: false)
        ->assertDontSee('data-active-company="'.$companyA->id.'"', escape: false);
});

it('cambia también los datos que se listan al cambiar de empresa', function () {
    $companyA = companyWithBranch();
    $companyB = Company::factory()->withMainBranch()->create(['tenant_id' => $companyA->tenant_id]);

    Branch::factory()->forCompany($companyA)->create(['name' => 'Sucursal Ceiba']);
    Branch::factory()->forCompany($companyB)->create(['name' => 'Sucursal Tegus']);

    $user = User::factory()->forCompany($companyA)->create();
    $user->companies()->attach($companyB->id, ['branch_id' => null]);

    $this->actingAs($user);

    $this->get(route('branches.index'))
        ->assertSee('Sucursal Ceiba')
        ->assertDontSee('Sucursal Tegus');

    $this->post(route('company.switch', $companyB));

    $this->get(route('branches.index'))
        ->assertSee('Sucursal Tegus')
        ->assertDontSee('Sucursal Ceiba');
});

it('olvida la sucursal seleccionada al cambiar de empresa', function () {
    $companyA = companyWithBranch();
    $companyB = Company::factory()->withMainBranch()->create(['tenant_id' => $companyA->tenant_id]);

    $branchOfA = Branch::factory()->forCompany($companyA)->create();

    $user = User::factory()->forCompany($companyA, $branchOfA)->create();
    $user->companies()->attach($companyB->id, ['branch_id' => null]);

    $this->actingAs($user);
    $this->get(route('dashboard'));

    expect(session('current_branch_id'))->toBe($branchOfA->id);

    $this->post(route('company.switch', $companyB));
    $this->get(route('dashboard'));

    // La sucursal de la empresa anterior no puede seguir activa.
    expect(session('current_branch_id'))->not->toBe($branchOfA->id);
});

it('no activa una empresa desactivada', function () {
    $company = companyWithBranch();
    $inactive = Company::factory()->withMainBranch()->inactive()
        ->create(['tenant_id' => $company->tenant_id]);

    $user = User::factory()->forCompany($company)->create();
    $user->companies()->attach($inactive->id, ['branch_id' => null]);

    $this->actingAs($user);

    $this->post(route('company.switch', $inactive))->assertNotFound();
});
