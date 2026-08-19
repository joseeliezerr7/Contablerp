<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Importe monetario con aritmética exacta.
 *
 * Todo se guarda como string y se opera con bcmath. Nunca float: `0.1 + 0.2`
 * no es `0.3` en coma flotante, y un libro contable que descuadra por
 * 0.0000000001 es un libro descuadrado.
 *
 * Escala interna de 4 decimales para que los prorrateos (descuentos por línea,
 * impuestos, distribución de fletes) no pierdan precisión antes del redondeo
 * final a los 2 decimales de presentación.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public const SCALE = 4;

    private function __construct(public string $amount) {}

    /**
     * Acepta enteros y strings. **No acepta float**: un float ya llega con el
     * error de representación incorporado y no hay forma de recuperarlo.
     * Los formularios y la base de datos entregan strings.
     */
    public static function of(int|string $amount): self
    {
        $normalized = self::normalize($amount);

        return new self($normalized);
    }

    /**
     * Como `of()`, pero **redondeando** los decimales sobrantes en vez de
     * truncarlos.
     *
     * `of()` trunca porque su origen habitual es un importe que ya viene con la
     * escala correcta —un formulario, una columna DECIMAL(18,4)— y truncar deja
     * en evidencia al que llegue con más decimales de los que dice tener. Pero
     * un cociente no es eso: repartir 100.00 entre 3 da 33.333333…, y truncar
     * ahí pierde sistemáticamente hacia abajo. Esta puerta existe para los
     * cálculos que producen decimales de más por naturaleza.
     */
    public static function ofRounded(int|string $amount): self
    {
        return new self(self::roundTo(self::validated($amount), self::SCALE));
    }

    public static function zero(): self
    {
        return new self(self::normalize(0));
    }

    /**
     * Puerta de entrada explícita para float, cuando el origen es inevitable
     * (una API externa, un CSV). Obliga a decidir la precisión de forma
     * consciente en vez de arrastrar el error en silencio.
     */
    public static function fromFloat(float $amount, int $decimals = 2): self
    {
        if (! is_finite($amount)) {
            throw new InvalidArgumentException('El importe no es un número finito.');
        }

        return self::of(number_format($amount, $decimals, '.', ''));
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /**
     * Multiplica calculando con precisión extra y redondeando al final.
     *
     * bcmul con escala fija *trunca*: 2.5 × 33.333333 daría 83.3333 en vez de
     * 83.3334. En una factura de muchas líneas esos truncamientos se acumulan
     * siempre hacia abajo y el total deja de coincidir con la suma impresa.
     */
    public function times(int|string $factor): self
    {
        return new self(self::roundTo(
            bcmul($this->amount, (string) $factor, self::SCALE + 6),
            self::SCALE,
        ));
    }

    public function dividedBy(int|string $divisor): self
    {
        if (bccomp((string) $divisor, '0', self::SCALE + 6) === 0) {
            throw new InvalidArgumentException('División por cero.');
        }

        return new self(self::roundTo(
            bcdiv($this->amount, (string) $divisor, self::SCALE + 6),
            self::SCALE,
        ));
    }

    /**
     * Porcentaje de este importe. `percentOf('15')` devuelve el 15 %.
     */
    public function percent(int|string $rate): self
    {
        return $this->times($rate)->dividedBy(100);
    }

    public function negated(): self
    {
        return new self(bcmul($this->amount, '-1', self::SCALE));
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    public function compareTo(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    /**
     * Redondeo comercial (half-up), que es el que espera un contador: 0.125 a
     * dos decimales es 0.13, no 0.12 como haría el redondeo bancario de PHP.
     */
    public function round(int $decimals = 2): self
    {
        return new self(self::roundTo($this->amount, $decimals));
    }

    /**
     * El importe como cadena con los decimales que se piden, sin separadores.
     *
     * `round(2)->toString()` devuelve «1234.5600»: redondea el valor pero
     * conserva la escala interna de cuatro decimales. Eso está bien dentro del
     * sistema y mal en una API pública, donde publicar la escala interna obliga
     * a quien integra a limpiarla y sugiere una precisión que no es la del
     * documento. `format()` tampoco sirve ahí: mete separador de miles, que en
     * JSON es un estorbo.
     */
    public function toScale(int $decimals = 2): string
    {
        return bcadd(self::roundTo($this->amount, $decimals), '0', $decimals);
    }

    /**
     * Redondeo comercial (half-up) de una cadena numérica a los decimales
     * indicados, devuelta ya normalizada a la escala interna.
     */
    private static function roundTo(string $value, int $decimals): string
    {
        $factor = bcpow('10', (string) $decimals, 0);
        $shifted = bcmul($value, $factor, self::SCALE + 6);

        $half = str_starts_with($value, '-') ? '-0.5' : '0.5';
        $rounded = bcadd($shifted, $half, 0);

        return bcdiv($rounded, $factor, self::SCALE);
    }

    /**
     * @param  array<int, self>  $items
     */
    public static function sum(array $items): self
    {
        return array_reduce(
            $items,
            static fn (self $carry, self $item): self => $carry->plus($item),
            self::zero()
        );
    }

    /**
     * Cadena lista para persistir en una columna DECIMAL(18,4).
     */
    public function toString(): string
    {
        return $this->amount;
    }

    /**
     * Formato de presentación, ya redondeado a los decimales de la empresa.
     */
    public function format(int $decimals = 2): string
    {
        return number_format((float) $this->round($decimals)->amount, $decimals, '.', ',');
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }

    /**
     * Comprueba que la cadena es un número decimal y la devuelve tal cual, sin
     * tocarle los decimales.
     */
    private static function validated(int|string $amount): string
    {
        $value = trim((string) $amount);

        if ($value === '') {
            $value = '0';
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Importe monetario inválido: [{$amount}].");
        }

        return $value;
    }

    private static function normalize(int|string $amount): string
    {
        // bcadd con escala fija normaliza la cantidad de decimales.
        $normalized = bcadd(self::validated($amount), '0', self::SCALE);

        // bcmath produce «-0.0000»; el cero no tiene signo.
        return $normalized === '-'.bcadd('0', '0', self::SCALE) ? bcadd('0', '0', self::SCALE) : $normalized;
    }
}
