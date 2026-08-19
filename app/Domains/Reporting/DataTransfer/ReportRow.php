<?php

declare(strict_types=1);

namespace App\Domains\Reporting\DataTransfer;

use App\Support\Money;

/**
 * Una fila del reporte. El estilo se declara por su papel —detalle, grupo,
 * total, separador— y cada formato de salida decide cómo representarlo.
 */
final readonly class ReportRow
{
    public const DETAIL = 'detail';

    public const GROUP = 'group';

    public const SUBTOTAL = 'subtotal';

    public const TOTAL = 'total';

    public const SPACER = 'spacer';

    /**
     * @param  array<int, string|Money|null>  $cells
     */
    public function __construct(
        public array $cells,
        public string $style = self::DETAIL,
        public int $indent = 0,
    ) {}

    /**
     * @param  array<int, string|Money|null>  $cells
     */
    public static function detail(array $cells, int $indent = 0): self
    {
        return new self($cells, self::DETAIL, $indent);
    }

    /**
     * @param  array<int, string|Money|null>  $cells
     */
    public static function group(array $cells): self
    {
        return new self($cells, self::GROUP);
    }

    /**
     * @param  array<int, string|Money|null>  $cells
     */
    public static function subtotal(array $cells): self
    {
        return new self($cells, self::SUBTOTAL);
    }

    /**
     * @param  array<int, string|Money|null>  $cells
     */
    public static function total(array $cells): self
    {
        return new self($cells, self::TOTAL);
    }

    public static function spacer(int $columns): self
    {
        return new self(array_fill(0, $columns, null), self::SPACER);
    }

    public function isEmphasised(): bool
    {
        return in_array($this->style, [self::GROUP, self::SUBTOTAL, self::TOTAL], strict: true);
    }
}
