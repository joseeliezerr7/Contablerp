<?php

declare(strict_types=1);

namespace App\Domains\Identity\Data;

/**
 * Catálogo de permisos y roles.
 *
 * Los permisos siguen el formato `modulo.recurso.accion`. Los roles se siembran
 * por empresa (spatie con `teams = company_id`), de modo que una misma persona
 * puede ser Contador en una empresa y Vendedor en otra.
 *
 * Los permisos sensibles —anular, revertir, cerrar período, tocar el plan de
 * cuentas— se conceden solo a los roles que deben tenerlos, nunca por defecto.
 */
final class PermissionCatalog
{
    public const ADMIN = 'Administrador';

    public const ACCOUNTANT = 'Contador';

    public const MANAGER = 'Gerente';

    public const SALESPERSON = 'Vendedor';

    public const CASHIER = 'Cajero';

    public const WAREHOUSE = 'Bodeguero';

    public const AUDITOR = 'Auditor';

    /**
     * Todos los permisos que existen hoy, con su descripción.
     *
     * @return array<string, string>
     */
    public static function permissions(): array
    {
        return [
            // Configuración de la empresa
            'companies.view' => 'Ver empresas',
            'companies.create' => 'Crear empresas',
            'companies.update' => 'Editar empresas',
            'branches.view' => 'Ver sucursales',
            'branches.create' => 'Crear sucursales',
            'branches.update' => 'Editar sucursales',
            'branches.delete' => 'Eliminar sucursales',
            'warehouses.view' => 'Ver bodegas',
            'warehouses.create' => 'Crear bodegas',
            'warehouses.update' => 'Editar bodegas',
            'warehouses.delete' => 'Eliminar bodegas',

            // Usuarios y seguridad
            'users.view' => 'Ver usuarios',
            'users.create' => 'Crear usuarios',
            'users.update' => 'Editar usuarios',
            'users.delete' => 'Eliminar usuarios',
            'roles.manage' => 'Administrar roles y permisos',
            'audit.view' => 'Consultar la bitácora de auditoría',

            // Plan de cuentas
            'accounting.accounts.view' => 'Ver el plan de cuentas',
            'accounting.accounts.create' => 'Crear cuentas contables',
            'accounting.accounts.update' => 'Editar cuentas contables',
            'accounting.accounts.delete' => 'Eliminar cuentas contables',
            'accounting.mappings.view' => 'Ver las cuentas por módulo',
            'accounting.mappings.update' => 'Configurar las cuentas por módulo',

            // Períodos y ejercicios
            'accounting.periods.view' => 'Ver períodos contables',
            'accounting.periods.create' => 'Crear ejercicios fiscales',
            'accounting.periods.close' => 'Cerrar períodos contables',
            'accounting.periods.reopen' => 'Reabrir períodos contables',

            // Libro diario
            'accounting.journal.view' => 'Ver el libro diario',
            'accounting.journal.create' => 'Crear partidas contables',
            'accounting.journal.update' => 'Editar partidas en borrador',
            'accounting.journal.delete' => 'Eliminar partidas en borrador',
            'accounting.journal.post' => 'Contabilizar partidas',
            'accounting.journal.void' => 'Anular partidas contabilizadas',
            'accounting.journal.reverse' => 'Revertir partidas contabilizadas',

            // Consultas
            'accounting.ledger.view' => 'Consultar el libro mayor',
            'accounting.reports.view' => 'Ver reportes contables',
            'accounting.reports.export' => 'Exportar reportes contables',

            // Catálogo comercial
            'catalog.products.view' => 'Ver productos y servicios',
            'catalog.products.create' => 'Crear productos',
            'catalog.products.update' => 'Editar productos',
            'catalog.products.delete' => 'Eliminar productos',
            'catalog.products.view_cost' => 'Ver el costo de los productos',
            'catalog.prices.update' => 'Editar los precios de un producto',
            // Unidades, categorías y listas de precios van juntas: son datos de
            // referencia, los mantiene la misma persona y separarlas en seis
            // permisos solo llenaría la pantalla de roles sin decidir nada.
            'catalog.masters.view' => 'Ver unidades, categorías y listas de precios',
            'catalog.masters.manage' => 'Configurar unidades, categorías y listas de precios',
            'catalog.taxes.view' => 'Ver los impuestos configurados',
            'catalog.taxes.manage' => 'Configurar impuestos',

            // Clientes
            'customers.view' => 'Ver clientes',
            'customers.create' => 'Crear clientes',
            'customers.update' => 'Editar clientes',
            'customers.delete' => 'Eliminar clientes',

            // Ventas
            'sales.invoices.view' => 'Ver facturas de venta',
            'sales.invoices.create' => 'Crear facturas de venta',
            'sales.invoices.update' => 'Editar facturas en borrador',
            'sales.invoices.delete' => 'Eliminar facturas en borrador',
            'sales.invoices.issue' => 'Emitir facturas de venta',
            'sales.invoices.void' => 'Anular facturas de venta',
            'sales.invoices.print' => 'Imprimir facturas de venta',
            'sales.invoices.override_price' => 'Cambiar el precio de una línea',
            'sales.invoices.override_credit_limit' => 'Facturar por encima del límite de crédito',

            // Cuentas por cobrar
            'receivables.view' => 'Consultar cuentas por cobrar',
            'receivables.reports' => 'Ver antigüedad de saldos y estados de cuenta',
            'receipts.view' => 'Ver recibos de cobro',
            'receipts.create' => 'Registrar recibos de cobro',
            'receipts.void' => 'Anular recibos de cobro',

            // Proveedores
            'suppliers.view' => 'Ver proveedores',
            'suppliers.create' => 'Crear proveedores',
            'suppliers.update' => 'Editar proveedores',
            'suppliers.delete' => 'Eliminar proveedores',

            // Compras
            'purchases.view' => 'Ver compras',
            'purchases.create' => 'Registrar compras',
            'purchases.update' => 'Editar compras en borrador',
            'purchases.delete' => 'Eliminar compras en borrador',
            'purchases.receive' => 'Recibir compras',
            'purchases.void' => 'Anular compras',

            // Cuentas por pagar
            'payables.view' => 'Consultar cuentas por pagar',
            'payables.reports' => 'Ver antigüedad de saldos por pagar y estados de cuenta',
            'payments.view' => 'Ver pagos a proveedores',
            'payments.create' => 'Registrar pagos a proveedores',
            'payments.void' => 'Anular pagos a proveedores',

            // Inventario
            'inventory.stock.view' => 'Consultar existencias',
            'inventory.stock.reorder' => 'Configurar puntos de reorden',
            'inventory.kardex.view' => 'Ver el kardex de un producto',
            'inventory.adjustments.view' => 'Ver ajustes de inventario',
            'inventory.adjustments.create' => 'Capturar ajustes de inventario',
            'inventory.adjustments.post' => 'Aprobar y contabilizar ajustes',
            'inventory.adjustments.void' => 'Anular ajustes de inventario',
            'inventory.transfers.view' => 'Ver traslados entre bodegas',
            'inventory.transfers.create' => 'Capturar traslados entre bodegas',
            'inventory.transfers.post' => 'Aplicar traslados entre bodegas',
            'inventory.transfers.void' => 'Anular traslados entre bodegas',

            // Tesorería
            'treasury.banks.view' => 'Ver cuentas bancarias',
            'treasury.banks.manage' => 'Administrar cuentas bancarias',
            'treasury.checks.view' => 'Ver cheques girados',
            'treasury.checks.manage' => 'Marcar cheques entregados y cobrados',
            'treasury.reconciliation.view' => 'Ver conciliaciones bancarias',
            'treasury.reconciliation.manage' => 'Armar conciliaciones bancarias',
            'treasury.reconciliation.close' => 'Cerrar y reabrir conciliaciones',
            'treasury.cash.view' => 'Ver sesiones de caja y arqueos',
            'treasury.cash.operate' => 'Abrir y cerrar caja',

            // Activos fijos
            'assets.view' => 'Ver activos fijos',
            'assets.manage' => 'Dar de alta y editar activos fijos',
            'assets.dispose' => 'Dar de baja activos fijos',
            'assets.depreciation.view' => 'Ver corridas de depreciación',
            'assets.depreciation.run' => 'Ejecutar la depreciación mensual',
            'assets.depreciation.void' => 'Anular corridas de depreciación',

            // Retenciones
            'taxes.withholdings.view' => 'Ver tipos de retención',
            'taxes.withholdings.manage' => 'Configurar tipos de retención',

            // Régimen de facturación (CAI)
            'fiscal.points.view' => 'Ver puntos de emisión',
            'fiscal.points.manage' => 'Configurar puntos de emisión',
            'fiscal.authorizations.view' => 'Ver autorizaciones del SAR',
            'fiscal.authorizations.manage' => 'Registrar autorizaciones del SAR (CAI)',

            // API pública
            'api.tokens.view' => 'Ver los tokens de API de la empresa',
            'api.tokens.manage' => 'Emitir y revocar tokens de API',

            // Notas de crédito
            'sales.credit_notes.view' => 'Ver notas de crédito',
            'sales.credit_notes.create' => 'Crear notas de crédito',
            'sales.credit_notes.issue' => 'Emitir notas de crédito',
            'sales.credit_notes.void' => 'Anular notas de crédito',
        ];
    }

    /**
     * Permisos de cada rol. El administrador los recibe todos.
     *
     * @return array<string, array<int, string>>
     */
    public static function roles(): array
    {
        $all = array_keys(self::permissions());

        $readOnlyAccounting = [
            'accounting.accounts.view',
            'accounting.periods.view',
            'accounting.journal.view',
            'accounting.ledger.view',
            'accounting.reports.view',
            'accounting.mappings.view',
            // Los impuestos son configuración contable: quien revisa las
            // cuentas necesita ver con qué tasa se está facturando.
            'catalog.taxes.view',
        ];

        $readOnlyCommercial = [
            'catalog.products.view',
            // Solo los roles que revisan la configuración —contador, gerente,
            // auditor— reciben esta pantalla. Un vendedor ve «CJA» y «Mayorista»
            // en los selectores del producto y de la factura, que no comprueban
            // este permiso; no necesita además la pantalla que los mantiene.
            'catalog.masters.view',
            'customers.view',
            'sales.invoices.view',
            'sales.invoices.print',
            'sales.credit_notes.view',
            'receivables.view',
            'receivables.reports',
            'receipts.view',
            'suppliers.view',
            'purchases.view',
            'payables.view',
            'payables.reports',
            'payments.view',
        ];

        $readOnlyInventory = [
            'inventory.stock.view',
            'inventory.kardex.view',
            'inventory.adjustments.view',
            'inventory.transfers.view',
        ];

        $readOnlyTreasury = [
            'treasury.banks.view',
            'treasury.checks.view',
            'treasury.reconciliation.view',
            'treasury.cash.view',
        ];

        $readOnlyAssets = [
            'assets.view',
            'assets.depreciation.view',
            'taxes.withholdings.view',
        ];

        // Quien factura necesita ver cuántos correlativos le quedan y cuándo
        // vence su CAI, aunque no sea quien tramita la autorización.
        $readOnlyFiscal = [
            'fiscal.points.view',
            'fiscal.authorizations.view',
        ];

        return [
            self::ADMIN => $all,

            // Lleva la contabilidad: captura, contabiliza, corrige y cierra.
            self::ACCOUNTANT => [
                'companies.view',
                'branches.view',
                'warehouses.view',
                'accounting.accounts.view',
                'accounting.accounts.create',
                'accounting.accounts.update',
                'accounting.accounts.delete',
                'accounting.mappings.view',
                'accounting.mappings.update',
                'accounting.periods.view',
                'accounting.periods.create',
                'accounting.periods.close',
                'accounting.periods.reopen',
                'accounting.journal.view',
                'accounting.journal.create',
                'accounting.journal.update',
                'accounting.journal.delete',
                'accounting.journal.post',
                'accounting.journal.void',
                'accounting.journal.reverse',
                'accounting.ledger.view',
                'accounting.reports.view',
                'accounting.reports.export',
                'audit.view',
                ...$readOnlyCommercial,
                // El contador corrige facturas mal emitidas y configura los
                // impuestos, pero no es quien vende.
                'sales.invoices.void',
                'catalog.products.view_cost',
                'catalog.taxes.view',
                'catalog.taxes.manage',
                // El contador es quien arma el catálogo cuando se instala el
                // sistema: unidades, categorías y listas de precios.
                'catalog.masters.manage',
                'receipts.void',
                // Las compras sí las lleva el contador: es quien registra la
                // factura del proveedor y quien paga.
                'suppliers.create',
                'suppliers.update',
                'purchases.create',
                'purchases.update',
                'purchases.delete',
                'purchases.receive',
                'purchases.void',
                'payments.create',
                'payments.void',
                // El inventario lo mueve el bodeguero, pero la diferencia de un
                // ajuste es una pérdida contable: aprobarla y anularla es del
                // contador. También puede capturarla él mismo —en una empresa
                // pequeña no hay bodeguero—, y por eso la segregación vive en
                // el rol Bodeguero, que captura sin aprobar, y no en un bloqueo
                // al contador.
                ...$readOnlyInventory,
                'inventory.stock.reorder',
                'inventory.adjustments.create',
                'inventory.adjustments.post',
                'inventory.adjustments.void',
                'inventory.transfers.create',
                'inventory.transfers.post',
                'inventory.transfers.void',
                // La tesorería es territorio del contador: administra las
                // cuentas bancarias, arma la conciliación y la da por buena.
                // También opera la caja —en una empresa pequeña no hay cajero—,
                // y por eso la segregación vive en el rol Cajero, que opera sin
                // ver bancos ni conciliaciones, y no en un bloqueo al contador.
                ...$readOnlyTreasury,
                'treasury.banks.manage',
                'treasury.checks.manage',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.close',
                'treasury.cash.operate',
                // Los activos fijos y las retenciones son contabilidad pura.
                ...$readOnlyAssets,
                'assets.manage',
                'assets.dispose',
                'assets.depreciation.run',
                'assets.depreciation.void',
                'taxes.withholdings.manage',
                // El trámite ante el SAR y la nota de crédito son suyos: quien
                // pide la autorización es quien lleva la contabilidad, y
                // acreditarle a un cliente rebaja el ingreso declarado.
                ...$readOnlyFiscal,
                'fiscal.points.manage',
                'fiscal.authorizations.manage',
                'sales.credit_notes.create',
                'sales.credit_notes.issue',
                'sales.credit_notes.void',
                // Un token de API es una llave a los datos de la empresa; darla
                // es del mismo nivel que dar acceso a alguien.
                'api.tokens.view',
                'api.tokens.manage',
            ],

            // Supervisa: ve todo y autoriza, pero no captura contabilidad.
            self::MANAGER => [
                'companies.view',
                'branches.view',
                'warehouses.view',
                'users.view',
                ...$readOnlyAccounting,
                ...$readOnlyCommercial,
                ...$readOnlyInventory,
                ...$readOnlyTreasury,
                ...$readOnlyAssets,
                ...$readOnlyFiscal,
                'inventory.adjustments.post',
                'treasury.reconciliation.close',
                'accounting.reports.export',
                'catalog.products.view_cost',
                'catalog.prices.update',
                'catalog.masters.manage',
                'sales.invoices.override_price',
                'sales.invoices.override_credit_limit',
                'audit.view',
            ],

            // Vende y factura, pero no ve costos ni anula lo ya emitido.
            self::SALESPERSON => [
                'branches.view',
                'warehouses.view',
                'catalog.products.view',
                'customers.view',
                'customers.create',
                'customers.update',
                'sales.invoices.view',
                'sales.invoices.create',
                'sales.invoices.update',
                'sales.invoices.delete',
                'sales.invoices.issue',
                'sales.invoices.print',
                'receivables.view',
                // Necesita saber si hay mercadería antes de prometer una
                // entrega, pero no ve el costo ni mueve el inventario.
                'inventory.stock.view',
                // Ve el estado de su CAI porque es quien se queda sin
                // correlativos a media venta; tramitarlo no le toca a él.
                ...$readOnlyFiscal,
                // Captura la devolución que recibe en el mostrador, pero no la
                // emite: acreditar es lo que reduce el ingreso, y esa firma es
                // del contador. Misma segregación que el ajuste de inventario.
                //
                // El permiso de ver va junto al de capturar y no es opcional:
                // quien captura tiene que poder volver a mirar lo que capturó.
                'sales.credit_notes.view',
                'sales.credit_notes.create',
            ],

            // Atiende el mostrador: factura de contado en el punto de venta,
            // cobra recibos y lleva su caja. No anula nada.
            //
            // Hasta la Fase 9 no podía facturar, porque no existía el POS y
            // facturar era capturar un documento entero. Con el mostrador, quien
            // cobra **es** quien emite: negárselo obligaría a que un vendedor
            // firmara cada venta de contado. Lo que sigue fuera de su alcance es
            // anular —deshacer una venta ya emitida no es trabajo de quien la
            // hizo— y todo lo que sea crédito.
            self::CASHIER => [
                'branches.view',
                'catalog.products.view',
                'customers.view',
                'customers.create',
                'sales.invoices.view',
                'sales.invoices.create',
                'sales.invoices.issue',
                'sales.invoices.print',
                'receivables.view',
                'receipts.view',
                'receipts.create',
                // Necesita saber si hay existencia antes de prometer la entrega.
                'inventory.stock.view',
                // Es quien abre y cierra su caja y quien cuenta el dinero.
                'treasury.cash.view',
                'treasury.cash.operate',
                // Ve el estado del CAI: es quien se queda sin correlativos a
                // media fila de clientes.
                ...$readOnlyFiscal,
            ],

            // Mueve la mercadería: cuenta, ajusta y traslada. Captura el ajuste
            // pero no lo aprueba —quien cuenta no debería ser quien justifica
            // el faltante—, y no ve precios ni documentos comerciales.
            self::WAREHOUSE => [
                'branches.view',
                'warehouses.view',
                'catalog.products.view',
                ...$readOnlyInventory,
                'inventory.stock.reorder',
                'inventory.adjustments.create',
                'inventory.transfers.create',
                'inventory.transfers.post',
            ],

            // Solo lectura, incluida la bitácora. No modifica nada.
            self::AUDITOR => [
                'companies.view',
                'branches.view',
                'warehouses.view',
                'users.view',
                ...$readOnlyAccounting,
                ...$readOnlyCommercial,
                ...$readOnlyInventory,
                ...$readOnlyTreasury,
                ...$readOnlyAssets,
                ...$readOnlyFiscal,
                'accounting.reports.export',
                'catalog.products.view_cost',
                'audit.view',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function roleNames(): array
    {
        return array_keys(self::roles());
    }
}
