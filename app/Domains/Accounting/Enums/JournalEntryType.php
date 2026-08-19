<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

enum JournalEntryType: string
{
    case Opening = 'opening';
    case Standard = 'standard';
    case Adjustment = 'adjustment';
    case Closing = 'closing';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Apertura',
            self::Standard => 'Diario',
            self::Adjustment => 'Ajuste',
            self::Closing => 'Cierre',
            self::Reversal => 'Reversión',
        };
    }

    /**
     * Apertura, cierre y reversión las genera el sistema; el usuario no las
     * captura a mano desde el libro diario.
     */
    public function isSystemGenerated(): bool
    {
        return in_array($this, [self::Opening, self::Closing, self::Reversal], strict: true);
    }

    /**
     * @return array<int, self>
     */
    public static function manualTypes(): array
    {
        return [self::Standard, self::Adjustment];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
