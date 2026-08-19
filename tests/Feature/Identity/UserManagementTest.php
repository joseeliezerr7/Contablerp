<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Exceptions\IdentityException;
use App\Domains\Identity\Services\CompanyUserService;
use App\Domains\Tenancy\Services\CompanyService;
use App\Livewire\Identity\UserIndex;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta y mantenimiento de usuarios.
 *
 * Es la pantalla que faltaba para poder **entregar** el sistema: sin ella, el
 * dueño de una ferretería entraba solo y darle acceso a su cajera exigía la
 * consola del servidor.
 *
 * Lo que se protege aquí no son datos sino la puerta: quién entra, con qué rol,
 * y que nunca se pueda dejar a una empresa sin nadie que pueda repartir accesos.
 */
beforeEach(function () {
    [$this->company, $this->contador] = accountingCompanyWithAccountant();

    // Administrar usuarios es del administrador, no del contador.
    $this->admin = actingAsUserOf($this->company, role: PermissionCatalog::ADMIN);
    $this->service = app(CompanyUserService::class);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('crea el usuario y devuelve una contraseña temporal una sola vez', function () {
    $componente = Livewire::test(UserIndex::class)
        ->call('create')
        ->set('name', 'Cajera del mostrador')
        ->set('email', 'cajera@ferreteria.hn')
        ->set('role', PermissionCatalog::CASHIER)
        ->call('save')
        ->assertHasNoErrors();

    $temporal = $componente->get('temporaryPassword');
    $usuario = User::query()->where('email', 'cajera@ferreteria.hn')->firstOrFail();

    expect($temporal)->toBeString()->toHaveLength(12)
        // Lo guardado es el hash: la contraseña en claro no está en la tabla.
        ->and(Hash::check($temporal, $usuario->password))->toBeTrue()
        ->and($usuario->password)->not->toBe($temporal)
        ->and($usuario->tenant_id)->toBe($this->company->tenant_id)
        ->and($usuario->companies()->whereKey($this->company->id)->exists())->toBeTrue();

    expect($this->service->roleNameFor($usuario, $this->company))
        ->toBe(PermissionCatalog::CASHIER);

    // Y al descartarla desaparece de la pantalla para siempre.
    $componente->call('dismissPassword')->assertSet('temporaryPassword', null);
});

it('el usuario nuevo puede entrar con su contraseña temporal', function () {
    $temporal = Livewire::test(UserIndex::class)
        ->call('create')
        ->set('name', 'Cajera')
        ->set('email', 'cajera@ferreteria.hn')
        ->set('role', PermissionCatalog::CASHIER)
        ->call('save')
        ->get('temporaryPassword');

    auth()->logout();

    $this->post(route('login'), [
        'email' => 'cajera@ferreteria.hn',
        'password' => $temporal,
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

it('a quien ya existe le da acceso en vez de crear otra cuenta', function () {
    // La misma persona ya trabaja en otra empresa del mismo tenant.
    $otra = app(CompanyService::class)->create([
        'legal_name' => 'Segunda del mismo dueño, S.A.',
        'tax_id' => '08019033333333',
        'currency_code' => 'HNL',
        'fiscal_year_start_month' => 1,
        'is_active' => true,
    ], $this->admin);

    $existente = app(CompanyContext::class)->runFor($otra, fn () => $this->service->invite([
        'name' => 'Contadora compartida',
        'email' => 'compartida@grupo.hn',
        'role' => PermissionCatalog::ACCOUNTANT,
    ])['user']);

    $componente = Livewire::test(UserIndex::class)
        ->call('create')
        ->set('name', 'Contadora compartida')
        ->set('email', 'compartida@grupo.hn')
        ->set('role', PermissionCatalog::AUDITOR)
        ->call('save')
        ->assertHasNoErrors();

    // No hay contraseña nueva: no se creó una cuenta, se dio un acceso.
    expect($componente->get('temporaryPassword'))->toBeNull()
        ->and(User::query()->where('email', 'compartida@grupo.hn')->count())->toBe(1);

    // Y el rol es distinto en cada empresa.
    expect($this->service->roleNameFor($existente, $this->company))->toBe(PermissionCatalog::AUDITOR)
        ->and($this->service->roleNameFor($existente, $otra))->toBe(PermissionCatalog::ACCOUNTANT);
});

it('no invita dos veces a la misma persona', function () {
    Livewire::test(UserIndex::class)
        ->call('create')
        ->set('name', 'Repetida')
        ->set('email', 'repetida@ferreteria.hn')
        ->set('role', PermissionCatalog::SALESPERSON)
        ->call('save');

    Livewire::test(UserIndex::class)
        ->call('create')
        ->set('name', 'Repetida')
        ->set('email', 'repetida@ferreteria.hn')
        ->set('role', PermissionCatalog::SALESPERSON)
        ->call('save')
        ->assertHasErrors('email');
});

/*
|--------------------------------------------------------------------------
| La guarda que evita el error irreversible
|--------------------------------------------------------------------------
*/

/**
 * El alta de la empresa ya deja un administrador —su dueño—, así que en estas
 * pruebas hay dos: el dueño y el que crea `actingAsUserOf`. Eso permite ver las
 * dos mitades de la guarda: con dos administradores se puede degradar a uno, y
 * con uno solo el sistema se niega.
 */
function otherAdmins(object $ctx): Collection
{
    return $ctx->company->users()
        ->where('users.id', '!=', $ctx->admin->id)
        ->get()
        ->filter(fn (User $u) => $ctx->service->roleNameFor($u, $ctx->company) === PermissionCatalog::ADMIN);
}

it('deja degradar a un administrador mientras quede otro', function () {
    $otro = otherAdmins($this)->first();

    expect($otro)->not->toBeNull();

    $this->service->update($otro, ['role' => PermissionCatalog::MANAGER]);

    expect($this->service->roleNameFor($otro->refresh(), $this->company))
        ->toBe(PermissionCatalog::MANAGER);
});

it('no deja a la empresa sin administrador al cambiar de rol', function () {
    // Se degrada a todos los demás: queda uno solo.
    otherAdmins($this)->each(
        fn (User $u) => $this->service->update($u, ['role' => PermissionCatalog::MANAGER])
    );

    // Quitarle el rol al último no tiene vuelta atrás: nadie dentro de la
    // empresa podría volver a asignarlo.
    $this->service->update($this->admin, ['role' => PermissionCatalog::SALESPERSON]);
})->throws(IdentityException::class, 'único administrador activo');

it('no deja a la empresa sin administrador al desactivarlo', function () {
    $otro = otherAdmins($this)->first();

    // Con dos, desactivar a uno se permite…
    $this->service->setActive($otro, false);
    expect($otro->refresh()->is_active)->toBeFalse();

    // …y el que queda ya no se puede tocar. Lo intenta otro administrador, para
    // que no se confunda con la guarda de «no sobre vos mismo».
    $this->actingAs($otro);
    $this->service->setActive($this->admin, false);
})->throws(IdentityException::class, 'único administrador activo');

it('no repite el punto cuando la razón social termina en abreviatura', function () {
    // «S. de R.L.» y «S.A. de C.V.» son la norma en Honduras, así que ningún
    // mensaje puede poner la razón social justo antes de un punto.
    $this->company->forceFill(['legal_name' => 'Ferretería El Clavo, S. de R.L.'])->save();

    otherAdmins($this)->each(
        fn (User $u) => $this->service->update($u, ['role' => PermissionCatalog::MANAGER])
    );

    try {
        $this->service->update($this->admin, ['role' => PermissionCatalog::SALESPERSON]);
        $this->fail('Se esperaba la guarda del último administrador.');
    } catch (IdentityException $e) {
        expect($e->getMessage())
            ->toContain('Ferretería El Clavo, S. de R.L.')
            ->not->toContain('..');
    }
});

it('nadie se desactiva ni se quita el acceso a sí mismo', function () {
    $this->service->setActive($this->admin, false);
})->throws(IdentityException::class, 'a vos mismo');

/*
|--------------------------------------------------------------------------
| Baja
|--------------------------------------------------------------------------
*/

it('desactivar corta el acceso pero conserva la cuenta', function () {
    $vendedor = $this->service->invite([
        'name' => 'Vendedor de paso',
        'email' => 'depaso@ferreteria.hn',
        'role' => PermissionCatalog::SALESPERSON,
    ])['user'];

    Livewire::test(UserIndex::class)->call('toggleActive', $vendedor->id);

    expect($vendedor->refresh()->is_active)->toBeFalse()
        ->and(User::query()->whereKey($vendedor->id)->exists())->toBeTrue();
});

it('quitar el acceso no borra al usuario ni lo saca de sus otras empresas', function () {
    $vendedor = $this->service->invite([
        'name' => 'Vendedor',
        'email' => 'vendedor@ferreteria.hn',
        'role' => PermissionCatalog::SALESPERSON,
    ])['user'];

    Livewire::test(UserIndex::class)
        ->call('confirmRevoke', $vendedor->id)
        ->call('revoke');

    expect(User::query()->whereKey($vendedor->id)->exists())->toBeTrue()
        ->and($vendedor->refresh()->companies()->whereKey($this->company->id)->exists())->toBeFalse()
        // Sin rol residual: si vuelve, se le asigna de nuevo.
        ->and($this->service->roleNameFor($vendedor, $this->company))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Contraseñas
|--------------------------------------------------------------------------
*/

it('genera una contraseña temporal que invalida la anterior', function () {
    $vendedor = $this->service->invite([
        'name' => 'Vendedor',
        'email' => 'vendedor@ferreteria.hn',
        'role' => PermissionCatalog::SALESPERSON,
    ]);

    $anterior = $vendedor['password'];

    $nueva = Livewire::test(UserIndex::class)
        ->call('confirmReset', $vendedor['user']->id)
        ->call('resetPassword')
        ->get('temporaryPassword');

    $usuario = $vendedor['user']->refresh();

    expect($nueva)->not->toBe($anterior)
        ->and(Hash::check($nueva, $usuario->password))->toBeTrue()
        ->and(Hash::check($anterior, $usuario->password))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Aislamiento y permisos
|--------------------------------------------------------------------------
*/

it('no toca usuarios de otra empresa', function () {
    $otra = accountingCompany();
    $ajeno = actingAsUserOf($otra, role: PermissionCatalog::SALESPERSON);

    // De vuelta como administrador de la primera.
    $this->actingAs($this->admin);
    app(CompanyContext::class)->set($this->company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    Livewire::test(UserIndex::class)
        ->call('edit', $ajeno->id)
        ->assertForbidden();
});

it('solo lista a la gente de la empresa activa', function () {
    $this->service->invite([
        'name' => 'De esta empresa',
        'email' => 'deesta@ferreteria.hn',
        'role' => PermissionCatalog::SALESPERSON,
    ]);

    $otra = accountingCompany();

    app(CompanyContext::class)->runFor($otra, fn () => app(CompanyUserService::class)->invite([
        'name' => 'De la otra empresa',
        'email' => 'delaotra@ferreteria.hn',
        'role' => PermissionCatalog::SALESPERSON,
    ]));

    Livewire::test(UserIndex::class)
        ->assertSee('De esta empresa')
        ->assertDontSee('De la otra empresa');
});

it('le niega la pantalla a quien no administra usuarios', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $this->get(route('users.index'))->assertForbidden();
});

it('deja mirar al gerente pero no invitar', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::MANAGER);

    $this->get(route('users.index'))->assertOk();

    Livewire::test(UserIndex::class)->call('create')->assertForbidden();
});
