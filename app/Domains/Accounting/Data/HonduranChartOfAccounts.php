<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Data;

use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\CashFlowClass;

/**
 * Catálogo base de cuentas para una PyME hondureña.
 *
 * Punto de partida editable, no una imposición: la empresa puede renombrar,
 * agregar y desactivar cuentas. Las marcadas `system` son las que el motor
 * contable necesita para operar y no se pueden eliminar.
 *
 * Ojo con las cuentas de naturaleza contraria (`nature` explícita): la
 * depreciación acumulada es de tipo activo pero saldo acreedor, y las
 * devoluciones sobre ventas son de tipo ingreso pero saldo deudor. Derivar la
 * naturaleza solo del tipo daría un balance invertido en esas cuentas.
 */
final class HonduranChartOfAccounts
{
    /**
     * Cada fila: [código, nombre, tipo, imputable, naturaleza|null, flujo|null, sistema]
     *
     * @return array<int, array{code: string, name: string, type: AccountType, postable: bool, nature: ?AccountNature, cash_flow: ?CashFlowClass, system: bool}>
     */
    public static function definition(): array
    {
        return array_map(self::row(...), [
            // ─────────────────────────── 1. ACTIVO ───────────────────────────
            ['1', 'ACTIVO', AccountType::Asset, false],
            ['1.1', 'ACTIVO CORRIENTE', AccountType::Asset, false],

            ['1.1.01', 'Caja', AccountType::Asset, false],
            ['1.1.01.01', 'Caja General', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.01.02', 'Caja Chica', AccountType::Asset, true, null, CashFlowClass::Operating],

            ['1.1.02', 'Bancos', AccountType::Asset, false],
            ['1.1.02.01', 'Banco Moneda Nacional', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.02.02', 'Banco Moneda Extranjera', AccountType::Asset, true, null, CashFlowClass::Operating],

            ['1.1.03', 'Cuentas por Cobrar', AccountType::Asset, false],
            ['1.1.03.01', 'Clientes', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.03.02', 'Documentos por Cobrar', AccountType::Asset, true, null, CashFlowClass::Operating],
            ['1.1.03.03', 'Cuentas por Cobrar Empleados', AccountType::Asset, true, null, CashFlowClass::Operating],
            // Contra-activo: reduce el activo, por eso saldo acreedor.
            ['1.1.03.04', 'Estimación para Cuentas Incobrables', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Operating],

            ['1.1.04', 'Inventarios', AccountType::Asset, false],
            ['1.1.04.01', 'Inventario de Mercadería', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.04.02', 'Mercadería en Tránsito', AccountType::Asset, true, null, CashFlowClass::Operating],

            ['1.1.05', 'Impuestos Pagados por Anticipado', AccountType::Asset, false],
            ['1.1.05.01', 'ISV Acreditable (Crédito Fiscal)', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.05.02', 'Retenciones de ISR a Favor', AccountType::Asset, true, null, CashFlowClass::Operating, true],
            ['1.1.05.03', 'Pagos a Cuenta de ISR', AccountType::Asset, true, null, CashFlowClass::Operating],

            ['1.1.06', 'Gastos Pagados por Anticipado', AccountType::Asset, false],
            ['1.1.06.01', 'Seguros Pagados por Anticipado', AccountType::Asset, true, null, CashFlowClass::Operating],
            ['1.1.06.02', 'Alquileres Pagados por Anticipado', AccountType::Asset, true, null, CashFlowClass::Operating],

            ['1.2', 'ACTIVO NO CORRIENTE', AccountType::Asset, false],
            ['1.2.01', 'Propiedad, Planta y Equipo', AccountType::Asset, false],
            ['1.2.01.01', 'Terrenos', AccountType::Asset, true, null, CashFlowClass::Investing],
            ['1.2.01.02', 'Edificios', AccountType::Asset, true, null, CashFlowClass::Investing],
            ['1.2.01.03', 'Mobiliario y Equipo de Oficina', AccountType::Asset, true, null, CashFlowClass::Investing],
            ['1.2.01.04', 'Equipo de Cómputo', AccountType::Asset, true, null, CashFlowClass::Investing],
            ['1.2.01.05', 'Vehículos', AccountType::Asset, true, null, CashFlowClass::Investing],
            ['1.2.01.06', 'Maquinaria y Equipo', AccountType::Asset, true, null, CashFlowClass::Investing],

            ['1.2.02', 'Depreciación Acumulada', AccountType::Asset, false],
            ['1.2.02.01', 'Depreciación Acumulada de Edificios', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Investing, true],
            ['1.2.02.02', 'Depreciación Acumulada de Mobiliario y Equipo', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Investing],
            ['1.2.02.03', 'Depreciación Acumulada de Equipo de Cómputo', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Investing],
            ['1.2.02.04', 'Depreciación Acumulada de Vehículos', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Investing],
            ['1.2.02.05', 'Depreciación Acumulada de Maquinaria', AccountType::Asset, true, AccountNature::Credit, CashFlowClass::Investing],

            // ─────────────────────────── 2. PASIVO ───────────────────────────
            ['2', 'PASIVO', AccountType::Liability, false],
            ['2.1', 'PASIVO CORRIENTE', AccountType::Liability, false],

            ['2.1.01', 'Cuentas por Pagar', AccountType::Liability, false],
            ['2.1.01.01', 'Proveedores', AccountType::Liability, true, null, CashFlowClass::Operating, true],
            ['2.1.01.02', 'Documentos por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],
            ['2.1.01.03', 'Acreedores Varios', AccountType::Liability, true, null, CashFlowClass::Operating],

            ['2.1.02', 'Impuestos por Pagar', AccountType::Liability, false],
            ['2.1.02.01', 'ISV por Pagar (Débito Fiscal)', AccountType::Liability, true, null, CashFlowClass::Operating, true],
            ['2.1.02.02', 'ISR por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],
            ['2.1.02.03', 'Retenciones por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating, true],

            ['2.1.03', 'Obligaciones Laborales', AccountType::Liability, false],
            ['2.1.03.01', 'Sueldos por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],
            ['2.1.03.02', 'IHSS por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],
            ['2.1.03.03', 'RAP por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],
            ['2.1.03.04', 'Vacaciones y Décimos por Pagar', AccountType::Liability, true, null, CashFlowClass::Operating],

            ['2.1.04', 'Anticipos de Clientes', AccountType::Liability, false],
            ['2.1.04.01', 'Anticipos Recibidos', AccountType::Liability, true, null, CashFlowClass::Operating],

            ['2.2', 'PASIVO NO CORRIENTE', AccountType::Liability, false],
            ['2.2.01', 'Préstamos a Largo Plazo', AccountType::Liability, false],
            ['2.2.01.01', 'Préstamos Bancarios a Largo Plazo', AccountType::Liability, true, null, CashFlowClass::Financing],

            // ────────────────────────── 3. PATRIMONIO ────────────────────────
            ['3', 'PATRIMONIO', AccountType::Equity, false],
            ['3.1', 'CAPITAL', AccountType::Equity, false],
            ['3.1.01', 'Capital Social', AccountType::Equity, true, null, CashFlowClass::Financing],
            ['3.1.02', 'Aportaciones para Futuros Aumentos', AccountType::Equity, true, null, CashFlowClass::Financing],

            ['3.2', 'RESULTADOS', AccountType::Equity, false],
            ['3.2.01', 'Utilidades Retenidas', AccountType::Equity, true, null, null, true],
            ['3.2.02', 'Utilidad o Pérdida del Ejercicio', AccountType::Equity, true, null, null, true],
            ['3.2.03', 'Reserva Legal', AccountType::Equity, true],
            // Cuenta puente del cierre anual: se salda contra utilidades retenidas.
            ['3.2.04', 'Resumen de Resultados', AccountType::Equity, true, null, null, true],

            // ─────────────────────────── 4. INGRESOS ─────────────────────────
            ['4', 'INGRESOS', AccountType::Income, false],
            ['4.1', 'INGRESOS OPERATIVOS', AccountType::Income, false],
            ['4.1.01', 'Ventas', AccountType::Income, true, null, CashFlowClass::Operating, true],
            ['4.1.02', 'Ventas Exentas', AccountType::Income, true, null, CashFlowClass::Operating],
            // Contra-ingreso: disminuye la venta, por eso saldo deudor.
            ['4.1.03', 'Devoluciones sobre Ventas', AccountType::Income, true, AccountNature::Debit, CashFlowClass::Operating, true],
            ['4.1.04', 'Descuentos y Rebajas sobre Ventas', AccountType::Income, true, AccountNature::Debit, CashFlowClass::Operating, true],

            ['4.2', 'OTROS INGRESOS', AccountType::Income, false],
            ['4.2.01', 'Ingresos Financieros', AccountType::Income, true, null, CashFlowClass::Operating],
            ['4.2.02', 'Ganancia por Diferencial Cambiario', AccountType::Income, true, null, CashFlowClass::Operating, true],
            ['4.2.03', 'Otros Ingresos', AccountType::Income, true, null, CashFlowClass::Operating],

            // ──────────────────────────── 5. COSTOS ──────────────────────────
            ['5', 'COSTOS', AccountType::Cost, false],
            ['5.1', 'COSTO DE VENTAS', AccountType::Cost, false],
            ['5.1.01', 'Costo de Ventas', AccountType::Cost, true, null, CashFlowClass::Operating, true],
            // Contra-costo: disminuye el costo, por eso saldo acreedor.
            ['5.1.02', 'Devoluciones sobre Compras', AccountType::Cost, true, AccountNature::Credit, CashFlowClass::Operating, true],
            ['5.1.03', 'Ajustes de Inventario', AccountType::Cost, true, null, CashFlowClass::Operating, true],

            // ──────────────────────────── 6. GASTOS ──────────────────────────
            ['6', 'GASTOS', AccountType::Expense, false],
            ['6.1', 'GASTOS DE ADMINISTRACIÓN', AccountType::Expense, false],
            ['6.1.01', 'Sueldos y Salarios', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.02', 'Beneficios y Prestaciones', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.03', 'Alquileres', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.04', 'Servicios Públicos', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.05', 'Papelería y Útiles de Oficina', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.06', 'Depreciación', AccountType::Expense, true, null, CashFlowClass::Operating, true],
            ['6.1.07', 'Honorarios Profesionales', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.08', 'Mantenimiento y Reparaciones', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.09', 'Seguros', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.10', 'Impuestos y Tasas Municipales', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.1.11', 'Cuentas Incobrables', AccountType::Expense, true, null, CashFlowClass::Operating],
            // Destino por defecto de las compras que no son inventario. Cada
            // producto o línea puede apuntar a un gasto más específico.
            ['6.1.12', 'Compras y Gastos Varios', AccountType::Expense, true, null, CashFlowClass::Operating, true],

            ['6.2', 'GASTOS DE VENTA', AccountType::Expense, false],
            ['6.2.01', 'Publicidad y Mercadeo', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.2.02', 'Comisiones sobre Ventas', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.2.03', 'Fletes y Acarreos', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.2.04', 'Combustibles y Lubricantes', AccountType::Expense, true, null, CashFlowClass::Operating],

            ['6.3', 'GASTOS FINANCIEROS', AccountType::Expense, false],
            ['6.3.01', 'Intereses Bancarios', AccountType::Expense, true, null, CashFlowClass::Financing],
            ['6.3.02', 'Comisiones Bancarias', AccountType::Expense, true, null, CashFlowClass::Operating],
            ['6.3.03', 'Pérdida por Diferencial Cambiario', AccountType::Expense, true, null, CashFlowClass::Operating, true],
            // Destino de las diferencias de arqueo. Un faltante se carga aquí y
            // un sobrante se abona: la cuenta acaba mostrando lo que la caja se
            // desvía en el mes, que es justo lo que hay que vigilar.
            ['6.3.04', 'Sobrantes y Faltantes de Caja', AccountType::Expense, true, null, CashFlowClass::Operating, true],
            // Resultado de dar de baja un activo fijo por menos de lo que
            // valía en libros. La ganancia va a «Otros Ingresos».
            ['6.3.05', 'Pérdida en Baja de Activo Fijo', AccountType::Expense, true, null, CashFlowClass::Investing, true],
        ]);
    }

    /**
     * Cuentas de efectivo y equivalentes.
     *
     * Es lo que el estado de flujo de efectivo considera «caja». Se declara
     * aparte y no como una columna más de la definición para no atar el reporte
     * a los códigos de este catálogo: cualquier plan de cuentas puede marcar
     * sus propias cuentas de efectivo.
     *
     * @return array<int, string>
     */
    public static function cashAccounts(): array
    {
        return [
            '1.1.01.01', // Caja General
            '1.1.01.02', // Caja Chica
            '1.1.02.01', // Banco Moneda Nacional
            '1.1.02.02', // Banco Moneda Extranjera
        ];
    }

    /**
     * Cuentas que cada clave del puente módulo ↔ contabilidad usa por defecto.
     *
     * @return array<string, string> clave => código de cuenta
     */
    public static function mappings(): array
    {
        return [
            AccountMappingKey::SalesRevenue->value => '4.1.01',
            AccountMappingKey::SalesReceivable->value => '1.1.03.01',
            AccountMappingKey::SalesTaxPayable->value => '2.1.02.01',
            AccountMappingKey::SalesReturns->value => '4.1.03',
            AccountMappingKey::SalesDiscount->value => '4.1.04',
            AccountMappingKey::SalesCostOfGoods->value => '5.1.01',

            AccountMappingKey::PurchasesPayable->value => '2.1.01.01',
            AccountMappingKey::PurchasesTaxCredit->value => '1.1.05.01',
            AccountMappingKey::PurchasesReturns->value => '5.1.02',
            AccountMappingKey::PurchasesExpense->value => '6.1.12',

            AccountMappingKey::InventoryAsset->value => '1.1.04.01',
            AccountMappingKey::InventoryAdjustment->value => '5.1.03',

            AccountMappingKey::TreasuryCash->value => '1.1.01.01',
            AccountMappingKey::TreasuryBankDefault->value => '1.1.02.01',
            AccountMappingKey::TreasuryCashOverShort->value => '6.3.04',

            AccountMappingKey::AssetsDepreciationExpense->value => '6.1.06',
            AccountMappingKey::AssetsAccumulatedDepreciation->value => '1.2.02.01',
            AccountMappingKey::AssetsDisposalGain->value => '4.2.03',
            AccountMappingKey::AssetsDisposalLoss->value => '6.3.05',

            AccountMappingKey::WithholdingReceivable->value => '1.1.05.02',
            AccountMappingKey::WithholdingPayable->value => '2.1.02.03',

            AccountMappingKey::ClosingIncomeSummary->value => '3.2.04',
            AccountMappingKey::ClosingRetainedEarnings->value => '3.2.01',

            AccountMappingKey::ForeignExchangeGain->value => '4.2.02',
            AccountMappingKey::ForeignExchangeLoss->value => '6.3.03',
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: AccountType, 3: bool, 4?: ?AccountNature, 5?: ?CashFlowClass, 6?: bool}  $row
     * @return array{code: string, name: string, type: AccountType, postable: bool, nature: ?AccountNature, cash_flow: ?CashFlowClass, system: bool}
     */
    private static function row(array $row): array
    {
        return [
            'code' => $row[0],
            'name' => $row[1],
            'type' => $row[2],
            'postable' => $row[3],
            'nature' => $row[4] ?? null,
            'cash_flow' => $row[5] ?? null,
            'system' => $row[6] ?? false,
        ];
    }
}
