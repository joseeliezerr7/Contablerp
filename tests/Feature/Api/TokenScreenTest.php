<?php

declare(strict_types=1);

use App\Domains\Api\Data\ApiScope;
use App\Domains\Api\Models\ApiToken;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Livewire\Api\TokenIndex;
use Livewire\Livewire;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
});

it('emite un token y muestra el secreto una sola vez', function () {
    $componente = Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'Tienda en línea')
        ->set('userId', $this->user->id)
        ->set('scopes', [ApiScope::CATALOG_READ, ApiScope::SALES_WRITE])
        ->set('expiresAt', now()->addYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $secreto = $componente->get('plainToken');

    expect($secreto)->toBeString()->toContain('|');

    $token = ApiToken::query()->firstOrFail();

    expect($token->company_id)->toBe($this->company->id)
        ->and($token->scopes())->toBe([ApiScope::CATALOG_READ, ApiScope::SALES_WRITE]);

    // Lo guardado es el hash: el secreto en claro no está en ninguna columna.
    expect($token->token)->not->toContain(explode('|', $secreto)[1]);

    // Y al descartarlo desaparece de la pantalla para siempre.
    $componente->call('dismissSecret')->assertSet('plainToken', null);
});

it('exige nombre y al menos un alcance', function () {
    Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('scopes', [])
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'scopes' => 'required']);
});

it('no acepta una fecha de vencimiento ya pasada', function () {
    Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'Vencido de nacimiento')
        ->set('userId', $this->user->id)
        ->set('scopes', [ApiScope::CATALOG_READ])
        ->set('expiresAt', now()->subDay()->toDateString())
        ->call('save')
        ->assertHasErrors('expiresAt');
});

it('revoca el token sin borrarlo', function () {
    $componente = Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'Para revocar')
        ->set('userId', $this->user->id)
        ->set('scopes', [ApiScope::CATALOG_READ])
        ->call('save');

    $token = ApiToken::query()->firstOrFail();

    $componente->call('confirmRevoke', $token->id)->call('revoke')->assertHasNoErrors();

    // Queda registrado, no desaparece: es lo que sirve si hay que reconstruir
    // qué pasó.
    expect($token->refresh()->isRevoked())->toBeTrue()
        ->and($token->isUsable())->toBeFalse()
        ->and(ApiToken::query()->count())->toBe(1);
});

it('muestra el dueño de cada token sin cargarlo de a uno', function () {
    Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'Con dueño')
        ->set('userId', $this->user->id)
        ->set('scopes', [ApiScope::CATALOG_READ])
        ->call('save')
        // La fila muestra el nombre del dueño. Con `shouldBeStrict` encendido,
        // olvidar el eager load no es un N+1 silencioso: es una excepción.
        ->assertSee($this->user->name);
});

it('solo lista los tokens de la empresa activa', function () {
    Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'De la primera')
        ->set('userId', $this->user->id)
        ->set('scopes', [ApiScope::CATALOG_READ])
        ->call('save');

    // Otra empresa, otro contador, otro token.
    $otra = accountingCompany();
    $otroUsuario = actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    Livewire::test(TokenIndex::class)
        ->call('create')
        ->set('name', 'De la segunda')
        ->set('userId', $otroUsuario->id)
        ->set('scopes', [ApiScope::CATALOG_READ])
        ->call('save')
        ->assertSee('De la segunda')
        ->assertDontSee('De la primera');
});

it('le niega la pantalla a quien no administra tokens', function () {
    // Un token es una llave a los datos de la empresa: repartirlas no es del
    // vendedor.
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('api.tokens.index'))->assertForbidden();
});
