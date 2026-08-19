<?php

declare(strict_types=1);

use App\Support\Money;

it('normaliza la escala interna', function () {
    expect(Money::of('100')->toString())->toBe('100.0000')
        ->and(Money::of('100.5')->toString())->toBe('100.5000')
        ->and(Money::of(250)->toString())->toBe('250.0000');
});

it('suma sin el error de la coma flotante', function () {
    // 0.1 + 0.2 === 0.3 es falso en float.
    $suma = Money::of('0.1')->plus(Money::of('0.2'));

    expect($suma->equals(Money::of('0.3')))->toBeTrue()
        ->and($suma->toString())->toBe('0.3000');
});

it('acumula muchos decimales sin desviarse', function () {
    $total = Money::zero();

    for ($i = 0; $i < 1000; $i++) {
        $total = $total->plus(Money::of('0.01'));
    }

    expect($total->equals(Money::of('10')))->toBeTrue()
        ->and($total->toString())->toBe('10.0000');
});

it('rechaza floats para evitar arrastrar su error', function () {
    expect(fn () => Money::of(0.1 + 0.2))->toThrow(TypeError::class);
});

it('acepta float solo por la puerta explícita', function () {
    expect(Money::fromFloat(0.1 + 0.2, 2)->toString())->toBe('0.3000');
});

it('rechaza cadenas que no son importes', function () {
    expect(fn () => Money::of('mil quinientos'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1,500.00'))->toThrow(InvalidArgumentException::class);
});

it('redondea al alza en el punto medio, como espera un contador', function () {
    // El redondeo bancario de PHP daría 0.12 en el primer caso.
    expect(Money::of('0.125')->round(2)->toString())->toBe('0.1300')
        ->and(Money::of('0.135')->round(2)->toString())->toBe('0.1400')
        ->and(Money::of('-0.125')->round(2)->toString())->toBe('-0.1300');
});

it('resta, multiplica y divide con exactitud', function () {
    expect(Money::of('1000')->minus(Money::of('333.33'))->toString())->toBe('666.6700')
        ->and(Money::of('100')->times(3)->toString())->toBe('300.0000')
        ->and(Money::of('100')->dividedBy(3)->toString())->toBe('33.3333');
});

it('rechaza la división por cero', function () {
    expect(fn () => Money::of('100')->dividedBy(0))->toThrow(InvalidArgumentException::class);
});

it('compara importes', function () {
    expect(Money::of('100')->greaterThan(Money::of('99.9999')))->toBeTrue()
        ->and(Money::of('100')->lessThan(Money::of('100.0001')))->toBeTrue()
        ->and(Money::of('100.0000')->equals(Money::of('100')))->toBeTrue();
});

it('identifica el signo', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::of('-5')->isNegative())->toBeTrue()
        ->and(Money::of('5')->isPositive())->toBeTrue()
        ->and(Money::of('-5')->absolute()->toString())->toBe('5.0000');
});

it('no produce un cero con signo', function () {
    expect(Money::of('-0')->toString())->toBe('0.0000')
        ->and(Money::of('5')->minus(Money::of('5'))->toString())->toBe('0.0000');
});

it('suma colecciones', function () {
    $total = Money::sum([Money::of('10.50'), Money::of('20.25'), Money::of('0.25')]);

    expect($total->toString())->toBe('31.0000');
});

it('da formato de presentación con separador de miles', function () {
    expect(Money::of('1234567.891')->format(2))->toBe('1,234,567.89');
});
