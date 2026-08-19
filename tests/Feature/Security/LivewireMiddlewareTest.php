<?php

declare(strict_types=1);

use App\Http\Middleware\SetCurrentCompany;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * La ruta /livewire/update se registra solo con ['web', RequireLivewireHeaders].
 * Livewire reaplica por su cuenta el middleware de autenticación, pero no el
 * nuestro: sin registrarlo como persistente, toda acción de un componente
 * (guardar, editar, eliminar) corre sin empresa activa.
 *
 * Este fallo no lo detectan las pruebas de componentes, porque Livewire::test()
 * no atraviesa la ruta HTTP y el contexto se fija a mano en el helper.
 */
it('reaplica el middleware de empresa en las peticiones de Livewire', function () {
    expect(Livewire::getPersistentMiddleware())
        ->toContain(SetCurrentCompany::class);
});

it('registra la ruta de actualización de Livewire', function () {
    expect(app(HandleRequests::class)->getUpdateUri())->toBeString();
});
