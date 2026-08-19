<?php

declare(strict_types=1);

namespace App\Domains\Reporting\DataTransfer;

final readonly class ReportColumn
{
    public function __construct(
        public string $label,
        public string $align = 'left',
        public int $width = 20,
    ) {}

    public static function text(string $label, int $width = 30): self
    {
        return new self($label, 'left', $width);
    }

    public static function amount(string $label, int $width = 16): self
    {
        return new self($label, 'right', $width);
    }

    public function isAmount(): bool
    {
        return $this->align === 'right';
    }
}
