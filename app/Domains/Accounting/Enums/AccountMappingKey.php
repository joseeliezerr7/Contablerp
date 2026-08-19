<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

/**
 * Claves del puente módulo ↔ plan de cuentas.
 *
 * Son un enum y no strings sueltos para que un módulo no pueda pedir una cuenta
 * con una clave inventada y descubrirlo en producción.
 */
enum AccountMappingKey: string
{
    // Ventas
    case SalesRevenue = 'sales.revenue';
    case SalesReceivable = 'sales.receivable';
    case SalesTaxPayable = 'sales.tax_payable';
    case SalesReturns = 'sales.returns';
    case SalesDiscount = 'sales.discount';
    case SalesCostOfGoods = 'sales.cogs';

    // Compras
    case PurchasesPayable = 'purchases.payable';
    case PurchasesTaxCredit = 'purchases.tax_credit';
    case PurchasesReturns = 'purchases.returns';
    case PurchasesExpense = 'purchases.expense';

    // Inventario
    case InventoryAsset = 'inventory.asset';
    case InventoryAdjustment = 'inventory.adjustment';

    // Tesorería
    case TreasuryCash = 'treasury.cash';
    case TreasuryBankDefault = 'treasury.bank_default';
    case TreasuryCashOverShort = 'treasury.cash_over_short';

    // Activos fijos
    case AssetsDepreciationExpense = 'assets.depreciation_expense';
    case AssetsAccumulatedDepreciation = 'assets.accumulated_depreciation';
    case AssetsDisposalGain = 'assets.disposal_gain';
    case AssetsDisposalLoss = 'assets.disposal_loss';

    // Retenciones
    case WithholdingReceivable = 'withholding.receivable';
    case WithholdingPayable = 'withholding.payable';

    // Cierre anual
    case ClosingIncomeSummary = 'closing.income_summary';
    case ClosingRetainedEarnings = 'closing.retained_earnings';

    // Diferencial cambiario (reservadas: multimoneda llega en una fase posterior)
    case ForeignExchangeGain = 'fx.gain';
    case ForeignExchangeLoss = 'fx.loss';

    public function label(): string
    {
        return match ($this) {
            self::SalesRevenue => 'Ventas',
            self::SalesReceivable => 'Clientes (cuentas por cobrar)',
            self::SalesTaxPayable => 'Impuesto sobre ventas por pagar',
            self::SalesReturns => 'Devoluciones sobre ventas',
            self::SalesDiscount => 'Descuentos sobre ventas',
            self::SalesCostOfGoods => 'Costo de ventas',
            self::PurchasesPayable => 'Proveedores (cuentas por pagar)',
            self::PurchasesTaxCredit => 'Impuesto acreditable',
            self::PurchasesReturns => 'Devoluciones sobre compras',
            self::PurchasesExpense => 'Compras que no son inventario',
            self::InventoryAsset => 'Inventario',
            self::InventoryAdjustment => 'Ajustes de inventario',
            self::TreasuryCash => 'Caja',
            self::TreasuryBankDefault => 'Banco predeterminado',
            self::TreasuryCashOverShort => 'Sobrantes y faltantes de caja',
            self::AssetsDepreciationExpense => 'Gasto por depreciación',
            self::AssetsAccumulatedDepreciation => 'Depreciación acumulada',
            self::AssetsDisposalGain => 'Ganancia en baja de activo fijo',
            self::AssetsDisposalLoss => 'Pérdida en baja de activo fijo',
            self::WithholdingReceivable => 'Retenciones a favor',
            self::WithholdingPayable => 'Retenciones por pagar',
            self::ClosingIncomeSummary => 'Resumen de resultados',
            self::ClosingRetainedEarnings => 'Utilidades retenidas',
            self::ForeignExchangeGain => 'Ganancia por diferencial cambiario',
            self::ForeignExchangeLoss => 'Pérdida por diferencial cambiario',
        };
    }

    /**
     * Módulo al que sirve la clave. Sale del prefijo del propio valor, así que
     * una clave nueva se agrupa sola.
     */
    public function module(): string
    {
        return match (explode('.', $this->value)[0]) {
            'sales' => 'Ventas',
            'purchases' => 'Compras',
            'inventory' => 'Inventario',
            'treasury' => 'Tesorería',
            'assets' => 'Activos fijos',
            'withholding' => 'Retenciones',
            'closing' => 'Cierre anual',
            'fx' => 'Diferencial cambiario',
            default => 'Otros',
        };
    }

    /**
     * Para qué sirve, en una línea. Es lo que decide si quien configura elige
     * bien: «Costo de ventas» no dice cuándo se usa, y esto sí.
     */
    public function hint(): string
    {
        return match ($this) {
            self::SalesRevenue => 'Se abona con cada factura emitida.',
            self::SalesReceivable => 'Se carga al facturar al crédito.',
            self::SalesTaxPayable => 'El ISV que cobrás y le debés al SAR.',
            self::SalesReturns => 'Se carga con cada nota de crédito, en vez de restarle a la venta.',
            self::SalesDiscount => 'Descuentos concedidos, si se llevan por separado.',
            self::SalesCostOfGoods => 'Se carga al costo promedio de lo que sale de bodega.',
            self::PurchasesPayable => 'Se abona al registrar la factura del proveedor.',
            self::PurchasesTaxCredit => 'El ISV que pagás y podés acreditar.',
            self::PurchasesReturns => 'Devoluciones al proveedor.',
            self::PurchasesExpense => 'Compras que se gastan de una vez, no las que entran a bodega.',
            self::InventoryAsset => 'El valor de lo que hay en bodega. Cuadra contra el kardex.',
            self::InventoryAdjustment => 'Sobrantes y faltantes de los conteos.',
            self::TreasuryCash => 'Efectivo de caja.',
            self::TreasuryBankDefault => 'Banco que se propone cuando no se elige otro.',
            self::TreasuryCashOverShort => 'La diferencia del arqueo al cerrar la caja.',
            self::AssetsDepreciationExpense => 'El gasto mensual de la depreciación.',
            self::AssetsAccumulatedDepreciation => 'Lo depreciado hasta hoy. Resta del activo.',
            self::AssetsDisposalGain => 'Si el activo se vende por más de su valor en libros.',
            self::AssetsDisposalLoss => 'Si se vende por menos, o se da de baja sin vender.',
            self::WithholdingReceivable => 'Lo que te retienen y podés aplicar contra tu impuesto.',
            self::WithholdingPayable => 'Lo que retenés y le debés al SAR.',
            self::ClosingIncomeSummary => 'Cuenta puente del cierre; queda en cero al terminar.',
            self::ClosingRetainedEarnings => 'A donde va el resultado del ejercicio cerrado.',
            self::ForeignExchangeGain => 'Reservada: la multimoneda llega en una fase posterior.',
            self::ForeignExchangeLoss => 'Reservada: la multimoneda llega en una fase posterior.',
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
