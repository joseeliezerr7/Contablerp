<?php

declare(strict_types=1);

use App\Livewire\Billing\SignupForm;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/**
 * Los mensajes que ve el usuario están en español.
 *
 * Esto estuvo roto desde la Fase 0 y ninguna prueba lo notó: `assertHasErrors`
 * comprueba **la regla** que falló, no el texto, así que una pantalla que
 * mostraba «validation.required» pasaba todas las pruebas. El sistema corre con
 * `APP_LOCALE=es` y no existía la carpeta `lang/`, de modo que Laravel devolvía
 * la clave cruda en cada formulario del sistema.
 *
 * La lección: una aserción sobre la regla no dice nada sobre lo que se lee en
 * pantalla. Estas pruebas miran el texto.
 */
it('traduce los mensajes de validación', function () {
    $validator = Validator::make(
        ['motivo' => ''],
        ['motivo' => ['required']],
        attributes: ['motivo' => 'motivo']
    );

    expect($validator->errors()->first('motivo'))
        ->toBe('El campo motivo es obligatorio.')
        ->not->toContain('validation.');
});

it('traduce los mensajes de autenticación y de paginación', function () {
    expect(trans('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(trans('passwords.sent'))->not->toContain('passwords.')
        ->and(trans('pagination.previous'))->not->toContain('pagination.');
});

it('no deja ninguna clave sin traducir en un formulario real', function () {
    $this->seed(PlanSeeder::class);

    $errores = Livewire::test(SignupForm::class)
        ->call('register')
        ->errors()
        ->all();

    expect($errores)->not->toBeEmpty();

    foreach ($errores as $mensaje) {
        expect($mensaje)->not->toStartWith('validation.');
    }
});

it('tiene traducción para todas las reglas que usa la aplicación', function () {
    // Las reglas que aparecen en los formularios del sistema. Si alguien añade
    // una regla nueva sin traducción, el usuario vería la clave cruda.
    $reglas = [
        'accepted', 'after_or_equal', 'array', 'before_or_equal', 'boolean', 'confirmed',
        'date', 'decimal', 'different', 'email', 'exists', 'gt', 'in', 'integer', 'max',
        'min', 'numeric', 'regex', 'required', 'required_if', 'same', 'string', 'unique',
    ];

    foreach ($reglas as $regla) {
        expect(Lang::has("validation.{$regla}"))->toBeTrue("Falta la traducción de validation.{$regla}");
    }
});
