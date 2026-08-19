<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Posted => 'Contabilizada',
            self::Voided => 'Anulada',
        };
    }

    /**
     * Solo el borrador puede editarse. Una partida contabilizada se corrige con
     * una partida de reversión o de ajuste, nunca modificándola.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Únicamente las partidas contabilizadas afectan saldos y reportes.
     */
    public function affectsBalances(): bool
    {
        return $this === self::Posted;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-700',
            self::Posted => 'bg-emerald-50 text-emerald-700',
            self::Voided => 'bg-red-50 text-red-700',
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
