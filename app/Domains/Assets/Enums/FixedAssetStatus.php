<?php

declare(strict_types=1);

namespace App\Domains\Assets\Enums;

enum FixedAssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'En uso',
            self::FullyDepreciated => 'Totalmente depreciado',
            self::Disposed => 'Dado de baja',
        };
    }

    /**
     * Si todavía entra en la corrida mensual.
     *
     * Un activo totalmente depreciado sigue en uso y en el balance, pero ya no
     * genera gasto: su valor en libros llegó al residual.
     */
    public function depreciates(): bool
    {
        return $this === self::Active;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::FullyDepreciated => 'bg-slate-100 text-slate-700',
            self::Disposed => 'bg-red-50 text-red-700',
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
