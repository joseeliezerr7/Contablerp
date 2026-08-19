<?php

declare(strict_types=1);

use App\Support\AmountInWords;
use App\Support\Money;

/**
 * El total en letras es un requisito del régimen de facturación, y su motivo es
 * antifraude: una cifra impresa se puede alterar con un bolígrafo, una frase no.
 * Por eso las excepciones del español —CIEN contra CIENTO, VEINTIÚN contra
 * VEINTIUNO, UN MILLÓN contra DOS MILLONES— importan aquí más de lo habitual.
 */
it('escribe importes corrientes', function (string $amount, string $expected) {
    expect(AmountInWords::of(Money::of($amount)))->toBe($expected);
})->with([
    ['0.00', 'CERO LEMPIRAS CON 00/100'],
    ['1.00', 'UN LEMPIRA CON 00/100'],
    ['15.50', 'QUINCE LEMPIRAS CON 50/100'],
    ['100.00', 'CIEN LEMPIRAS CON 00/100'],
    ['115.00', 'CIENTO QUINCE LEMPIRAS CON 00/100'],
    ['200.00', 'DOSCIENTOS LEMPIRAS CON 00/100'],
    ['999.99', 'NOVECIENTOS NOVENTA Y NUEVE LEMPIRAS CON 99/100'],
    ['1000.00', 'MIL LEMPIRAS CON 00/100'],
    ['1500.75', 'MIL QUINIENTOS LEMPIRAS CON 75/100'],
    ['2000.00', 'DOS MIL LEMPIRAS CON 00/100'],
    ['4530.45', 'CUATRO MIL QUINIENTOS TREINTA LEMPIRAS CON 45/100'],
    ['2500000.00', 'DOS MILLONES QUINIENTOS MIL LEMPIRAS CON 00/100'],
]);

/**
 * Las trampas del español: «uno» se apocopa delante de sustantivo masculino y
 * de «mil», y los millones exactos llevan «de».
 */
it('apocopa el uno donde corresponde', function (string $amount, string $expected) {
    expect(AmountInWords::of(Money::of($amount)))->toBe($expected);
})->with([
    ['1.00', 'UN LEMPIRA CON 00/100'],
    ['21.00', 'VEINTIÚN LEMPIRAS CON 00/100'],
    ['31.00', 'TREINTA Y UN LEMPIRAS CON 00/100'],
    ['101.25', 'CIENTO UN LEMPIRAS CON 25/100'],
    ['21000.00', 'VEINTIÚN MIL LEMPIRAS CON 00/100'],
    ['131000.00', 'CIENTO TREINTA Y UN MIL LEMPIRAS CON 00/100'],
    // El once no se toca: «ONCE», no «ONC UN».
    ['11.00', 'ONCE LEMPIRAS CON 00/100'],
]);

it('usa «de» solo cuando los millones son exactos', function (string $amount, string $expected) {
    expect(AmountInWords::of(Money::of($amount)))->toBe($expected);
})->with([
    ['1000000.00', 'UN MILLÓN DE LEMPIRAS CON 00/100'],
    ['2000000.00', 'DOS MILLONES DE LEMPIRAS CON 00/100'],
    ['1000000.50', 'UN MILLÓN DE LEMPIRAS CON 50/100'],
    ['1500000.00', 'UN MILLÓN QUINIENTOS MIL LEMPIRAS CON 00/100'],
]);

it('redondea a dos decimales antes de escribir', function () {
    // El importe interno lleva cuatro decimales; el papel lleva dos.
    expect(AmountInWords::of(Money::of('10.9950')))->toBe('ONCE LEMPIRAS CON 00/100')
        ->and(AmountInWords::of(Money::of('10.9949')))->toBe('DIEZ LEMPIRAS CON 99/100');
});

it('acepta otras monedas', function () {
    expect(AmountInWords::of(Money::of('50.00'), 'USD'))->toBe('CINCUENTA DÓLARES CON 00/100')
        ->and(AmountInWords::of(Money::of('1.00'), 'USD'))->toBe('UN DÓLAR CON 00/100');
});

it('escribe importes negativos sin esconder el signo', function () {
    expect(AmountInWords::of(Money::of('-250.30')))
        ->toBe('MENOS DOSCIENTOS CINCUENTA LEMPIRAS CON 30/100');
});
