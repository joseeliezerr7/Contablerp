<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Enums;

/**
 * Tipos de documento que el sistema emite bajo el régimen de facturación.
 *
 * **El código de dos dígitos no vive aquí.** Va en la autorización, capturado de
 * la resolución del SAR, porque es la administración tributaria la que lo asigna
 * y puede cambiarlo sin avisarle a nadie que escriba software. Lo que este enum
 * fija es qué clase de documento emite el sistema; los valores de abajo son solo
 * el código que **suele** venir, ofrecido como sugerencia en pantalla.
 */
enum FiscalDocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito',
        };
    }

    /**
     * Código sugerido para el número fiscal. Es un valor por defecto de la
     * pantalla, no una verdad: manda lo que diga la resolución.
     */
    public function suggestedCode(): string
    {
        return match ($this) {
            self::Invoice => '01',
            self::DebitNote => '02',
            self::CreditNote => '03',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
