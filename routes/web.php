<?php

declare(strict_types=1);

use App\Http\Controllers\PrintDocumentController;
use App\Http\Controllers\SwitchCompanyController;
use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\AccountMappingIndex;
use App\Livewire\Accounting\JournalForm;
use App\Livewire\Accounting\JournalIndex;
use App\Livewire\Accounting\LedgerView;
use App\Livewire\Accounting\PeriodIndex;
use App\Livewire\Accounting\Reports\BalanceSheetReport;
use App\Livewire\Accounting\Reports\CashFlowReport;
use App\Livewire\Accounting\Reports\IncomeStatementReport;
use App\Livewire\Accounting\Reports\ReportCenter;
use App\Livewire\Accounting\Reports\TrialBalanceReport;
use App\Livewire\Admin\PlanIndex;
use App\Livewire\Admin\TenantIndex;
use App\Livewire\Api\TokenIndex;
use App\Livewire\Assets\AssetCategoryIndex;
use App\Livewire\Assets\DepreciationIndex;
use App\Livewire\Assets\DepreciationShow;
use App\Livewire\Assets\FixedAssetIndex;
use App\Livewire\Assets\FixedAssetShow;
use App\Livewire\Assets\WithholdingTypeIndex;
use App\Livewire\Billing\SignupForm;
use App\Livewire\Catalog\CatalogIndex;
use App\Livewire\Dashboard;
use App\Livewire\Fiscal\FiscalPointIndex;
use App\Livewire\Identity\AuditIndex;
use App\Livewire\Identity\UserIndex;
use App\Livewire\Inventory\AdjustmentForm;
use App\Livewire\Inventory\AdjustmentIndex;
use App\Livewire\Inventory\AdjustmentShow;
use App\Livewire\Inventory\KardexView;
use App\Livewire\Inventory\StockIndex;
use App\Livewire\Inventory\TransferForm;
use App\Livewire\Inventory\TransferIndex;
use App\Livewire\Inventory\TransferShow;
use App\Livewire\Purchases\PayableAgingReport;
use App\Livewire\Purchases\PaymentIndex;
use App\Livewire\Purchases\PaymentShow;
use App\Livewire\Purchases\PurchaseForm;
use App\Livewire\Purchases\PurchaseIndex;
use App\Livewire\Purchases\PurchaseShow;
use App\Livewire\Purchases\SupplierIndex;
use App\Livewire\Purchases\SupplierStatement;
use App\Livewire\Sales\AgingReport;
use App\Livewire\Sales\CreditNoteForm;
use App\Livewire\Sales\CreditNoteIndex;
use App\Livewire\Sales\CreditNoteShow;
use App\Livewire\Sales\CustomerIndex;
use App\Livewire\Sales\CustomerStatement;
use App\Livewire\Sales\PointOfSale;
use App\Livewire\Sales\ProductIndex;
use App\Livewire\Sales\ReceiptIndex;
use App\Livewire\Sales\ReceiptShow;
use App\Livewire\Sales\SaleForm;
use App\Livewire\Sales\SaleIndex;
use App\Livewire\Sales\SaleShow;
use App\Livewire\Taxation\TaxIndex;
use App\Livewire\Tenancy\BranchIndex;
use App\Livewire\Tenancy\CompanyIndex;
use App\Livewire\Tenancy\WarehouseIndex;
use App\Livewire\Treasury\BankAccountIndex;
use App\Livewire\Treasury\CashSessionIndex;
use App\Livewire\Treasury\CheckIndex;
use App\Livewire\Treasury\CheckShow;
use App\Livewire\Treasury\ReconciliationIndex;
use App\Livewire\Treasury\ReconciliationView;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Alta self-service. Pública a propósito: es la puerta de entrada del servicio.
Route::livewire('/registro', SignupForm::class)
    ->middleware('guest')
    ->name('signup');

Route::middleware('auth')->group(function (): void {
    // Fuera del middleware `company`: es justamente la salida para el usuario
    // que no tiene ninguna empresa asignada.
    Route::view('/sin-empresa', 'companies.none')->name('companies.none');

    // Panel del proveedor. Va con `superadmin` **en lugar de** `company`: quien
    // administra el servicio no pertenece a ninguna empresa cliente.
    Route::middleware('superadmin')->group(function (): void {
        Route::livewire('/admin/cuentas', TenantIndex::class)->name('admin.tenants.index');
        Route::livewire('/admin/planes', PlanIndex::class)->name('admin.plans.index');
    });

    Route::middleware('company')->group(function (): void {
        Route::livewire('/dashboard', Dashboard::class)->name('dashboard');

        Route::post('/empresa/cambiar/{company}', SwitchCompanyController::class)
            ->name('company.switch');

        Route::livewire('/empresas', CompanyIndex::class)->name('companies.index');
        Route::livewire('/facturacion/puntos-emision', FiscalPointIndex::class)->name('fiscal.points.index');
        Route::livewire('/api/tokens', TokenIndex::class)->name('api.tokens.index');
        Route::livewire('/usuarios', UserIndex::class)->name('users.index');
        Route::livewire('/bitacora', AuditIndex::class)->name('audit.index');
        Route::livewire('/sucursales', BranchIndex::class)->name('branches.index');
        Route::livewire('/bodegas', WarehouseIndex::class)->name('warehouses.index');

        // Contabilidad
        Route::livewire('/contabilidad/cuentas', AccountIndex::class)->name('accounts.index');
        Route::livewire('/contabilidad/periodos', PeriodIndex::class)->name('periods.index');
        Route::livewire('/contabilidad/diario', JournalIndex::class)->name('journal.index');
        Route::livewire('/contabilidad/diario/nueva', JournalForm::class)->name('journal.create');
        Route::livewire('/contabilidad/diario/{entry}/editar', JournalForm::class)->name('journal.edit');
        Route::livewire('/contabilidad/mayor', LedgerView::class)->name('ledger.index');
        Route::livewire('/contabilidad/cuentas-por-modulo', AccountMappingIndex::class)
            ->name('accounting.mappings.index');

        // Reportes y estados financieros
        Route::livewire('/reportes', ReportCenter::class)->name('reports.index');
        Route::livewire('/reportes/balance-comprobacion', TrialBalanceReport::class)
            ->name('reports.trial-balance');
        Route::livewire('/reportes/estado-resultados', IncomeStatementReport::class)
            ->name('reports.income-statement');
        Route::livewire('/reportes/balance-general', BalanceSheetReport::class)
            ->name('reports.balance-sheet');
        Route::livewire('/reportes/flujo-efectivo', CashFlowReport::class)
            ->name('reports.cash-flow');

        // Ventas y cuentas por cobrar
        Route::livewire('/clientes', CustomerIndex::class)->name('customers.index');
        Route::livewire('/productos', ProductIndex::class)->name('products.index');
        Route::livewire('/catalogos', CatalogIndex::class)->name('catalog.index');
        Route::livewire('/pos', PointOfSale::class)->name('pos');
        Route::livewire('/ventas/facturas', SaleIndex::class)->name('sales.index');
        Route::livewire('/ventas/facturas/nueva', SaleForm::class)->name('sales.create');
        Route::livewire('/ventas/facturas/{sale}/editar', SaleForm::class)->name('sales.edit');
        // `whereNumber` para que no se trague `/ventas/facturas/nueva`. Hoy el
        // orden de registro ya lo protege, pero eso es frágil: mover una línea
        // rompería la pantalla de crear factura sin que nada avise.
        Route::livewire('/ventas/facturas/{sale}', SaleShow::class)
            ->whereNumber('sale')
            ->name('sales.show');
        Route::get('/ventas/facturas/{sale}/imprimir', [PrintDocumentController::class, 'invoice'])
            ->name('sales.print');
        Route::livewire('/ventas/notas-credito', CreditNoteIndex::class)->name('credit-notes.index');
        Route::livewire('/ventas/notas-credito/nueva', CreditNoteForm::class)->name('credit-notes.create');
        Route::livewire('/ventas/notas-credito/{creditNote}/editar', CreditNoteForm::class)->name('credit-notes.edit');
        Route::livewire('/ventas/notas-credito/{creditNote}', CreditNoteShow::class)
            ->whereNumber('creditNote')
            ->name('credit-notes.show');
        Route::get('/ventas/notas-credito/{creditNote}/imprimir', [PrintDocumentController::class, 'creditNote'])
            ->name('credit-notes.print');
        Route::livewire('/ventas/recibos', ReceiptIndex::class)->name('receipts.index');
        Route::livewire('/ventas/recibos/{receipt}', ReceiptShow::class)
            ->whereNumber('receipt')
            ->name('receipts.show');
        Route::livewire('/ventas/antiguedad', AgingReport::class)->name('receivables.aging');
        Route::livewire('/ventas/estado-cuenta', CustomerStatement::class)->name('receivables.statement');

        // Compras y cuentas por pagar
        Route::livewire('/proveedores', SupplierIndex::class)->name('suppliers.index');
        Route::livewire('/compras', PurchaseIndex::class)->name('purchases.index');
        Route::livewire('/compras/nueva', PurchaseForm::class)->name('purchases.create');
        Route::livewire('/compras/{purchase}/editar', PurchaseForm::class)->name('purchases.edit');
        Route::livewire('/compras/{purchase}', PurchaseShow::class)
            ->whereNumber('purchase')
            ->name('purchases.show');
        Route::livewire('/compras/pagos', PaymentIndex::class)->name('payments.index');
        Route::livewire('/compras/pagos/{payment}', PaymentShow::class)
            ->whereNumber('payment')
            ->name('payments.show');
        Route::livewire('/compras/antiguedad', PayableAgingReport::class)->name('payables.aging');
        Route::livewire('/compras/estado-cuenta', SupplierStatement::class)->name('payables.statement');

        // Inventario
        Route::livewire('/inventario/existencias', StockIndex::class)->name('inventory.stock');
        Route::livewire('/inventario/kardex', KardexView::class)->name('inventory.kardex');
        Route::livewire('/inventario/ajustes', AdjustmentIndex::class)->name('inventory.adjustments.index');
        Route::livewire('/inventario/ajustes/nuevo', AdjustmentForm::class)->name('inventory.adjustments.create');
        Route::livewire('/inventario/ajustes/{adjustment}/editar', AdjustmentForm::class)->name('inventory.adjustments.edit');
        Route::livewire('/inventario/ajustes/{adjustment}', AdjustmentShow::class)
            ->whereNumber('adjustment')
            ->name('inventory.adjustments.show');
        Route::livewire('/inventario/traslados', TransferIndex::class)->name('inventory.transfers.index');
        Route::livewire('/inventario/traslados/nuevo', TransferForm::class)->name('inventory.transfers.create');
        Route::livewire('/inventario/traslados/{transfer}/editar', TransferForm::class)->name('inventory.transfers.edit');
        Route::livewire('/inventario/traslados/{transfer}', TransferShow::class)
            ->whereNumber('transfer')
            ->name('inventory.transfers.show');

        // Tesorería
        Route::livewire('/tesoreria/bancos', BankAccountIndex::class)->name('treasury.banks.index');
        Route::livewire('/tesoreria/cheques', CheckIndex::class)->name('treasury.checks.index');
        Route::livewire('/tesoreria/cheques/{check}', CheckShow::class)
            ->whereNumber('check')
            ->name('treasury.checks.show');
        Route::livewire('/tesoreria/conciliaciones', ReconciliationIndex::class)->name('treasury.reconciliations.index');
        Route::livewire('/tesoreria/conciliaciones/{reconciliation}', ReconciliationView::class)->name('treasury.reconciliations.show');
        Route::livewire('/tesoreria/caja', CashSessionIndex::class)->name('treasury.cash.index');

        // Activos fijos y retenciones
        Route::livewire('/activos', FixedAssetIndex::class)->name('assets.index');
        Route::livewire('/activos/categorias', AssetCategoryIndex::class)->name('assets.categories.index');
        Route::livewire('/activos/{asset}', FixedAssetShow::class)
            ->whereNumber('asset')
            ->name('assets.show');
        Route::livewire('/activos/depreciacion/{run}', DepreciationShow::class)
            ->whereNumber('run')
            ->name('assets.depreciation.show');
        Route::livewire('/activos/depreciacion', DepreciationIndex::class)->name('assets.depreciation.index');
        Route::livewire('/impuestos', TaxIndex::class)->name('taxes.index');
        Route::livewire('/impuestos/retenciones', WithholdingTypeIndex::class)->name('taxes.withholdings.index');
    });
});
