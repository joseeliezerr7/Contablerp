<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Importe en letras, en español.
 *
 * El régimen de facturación exige el total escrito con palabras en la factura.
 * No es un adorno: es lo que impide que alguien altere una cifra impresa.
 *
 * Los centavos van como fracción sobre 100 —«CON 45/100»—, que es la forma en
 * que se escriben en los documentos comerciales hondureños y la que evita
 * discutir si «cuarenta y cinco centavos» son 0.45 o 0.045.
 */
final class AmountInWords
{
    private const UNITS = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS',
        'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO',
        'VEINTINUEVE',
    ];

    private const TENS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    private const HUNDREDS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS',
        5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS',
        9 => 'NOVECIENTOS',
    ];

    /**
     * «CUATRO MIL QUINIENTOS TREINTA LEMPIRAS CON 45/100».
     */
    public static function of(Money $amount, string $currency = 'HNL'): string
    {
        $rounded = $amount->round(2)->toString();
        $negative = str_starts_with($rounded, '-');
        $absolute = ltrim($rounded, '-');

        [$whole, $cents] = array_pad(explode('.', $absolute, 2), 2, '00');
        $cents = str_pad(mb_substr($cents.'00', 0, 2), 2, '0');

        $units = (int) $whole;
        $words = self::integerToWords($units);

        // «UN MILLÓN **DE** LEMPIRAS», pero «UN MILLÓN QUINIENTOS MIL LEMPIRAS».
        // La preposición solo aparece cuando la cifra en millones es exacta.
        $de = $units >= 1_000_000 && $units % 1_000_000 === 0 ? 'DE ' : '';

        return trim(sprintf(
            '%s%s %s%s CON %s/100',
            $negative ? 'MENOS ' : '',
            $words,
            $de,
            self::currencyName($currency, $units),
            $cents,
        ));
    }

    private static function currencyName(string $currency, int $whole): string
    {
        return match (mb_strtoupper($currency)) {
            'HNL' => $whole === 1 ? 'LEMPIRA' : 'LEMPIRAS',
            'USD' => $whole === 1 ? 'DÓLAR' : 'DÓLARES',
            'EUR' => $whole === 1 ? 'EURO' : 'EUROS',
            default => mb_strtoupper($currency),
        };
    }

    private static function integerToWords(int $number): string
    {
        if ($number === 0) {
            return 'CERO';
        }

        if ($number >= 1_000_000) {
            $millions = intdiv($number, 1_000_000);
            $rest = $number % 1_000_000;

            $prefix = $millions === 1
                ? 'UN MILLÓN'
                : self::integerToWords($millions).' MILLONES';

            return trim($prefix.' '.($rest > 0 ? self::integerToWords($rest) : ''));
        }

        if ($number >= 1_000) {
            $thousands = intdiv($number, 1_000);
            $rest = $number % 1_000;

            $prefix = $thousands === 1 ? 'MIL' : self::integerToWords($thousands).' MIL';

            return trim($prefix.' '.($rest > 0 ? self::integerToWords($rest) : ''));
        }

        return self::underThousand($number);
    }

    private static function underThousand(int $number): string
    {
        if ($number === 100) {
            return 'CIEN';
        }

        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        $words = $hundreds > 0 ? self::HUNDREDS[$hundreds] : '';

        if ($rest === 0) {
            return $words;
        }

        return trim($words.' '.self::underHundred($rest));
    }

    /**
     * Apócope del uno.
     *
     * En español «uno» se acorta a «un» delante de un sustantivo masculino o de
     * «mil»: es UN LEMPIRA, VEINTIÚN MIL, CIENTO UN LEMPIRAS. Aquí el número
     * siempre precede a uno de los dos, así que la forma corta es la única
     * correcta. Escribir VEINTIUNO MIL en una factura la delata como salida de
     * un sistema que no revisó nadie.
     */
    private static function underHundred(int $number): string
    {
        if ($number === 1) {
            return 'UN';
        }

        if ($number === 21) {
            return 'VEINTIÚN';
        }

        if ($number < 30) {
            return self::UNITS[$number];
        }

        $tens = intdiv($number, 10);
        $units = $number % 10;

        if ($units === 0) {
            return self::TENS[$tens];
        }

        return self::TENS[$tens].' Y '.($units === 1 ? 'UN' : self::UNITS[$units]);
    }
}
