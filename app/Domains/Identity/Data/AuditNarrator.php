<?php

declare(strict_types=1);

namespace App\Domains\Identity\Data;

use App\Domains\Identity\Models\AuditLog;
use App\Support\Money;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Traduce un renglón de la bitácora a algo que un contador pueda leer.
 *
 * La tabla guarda lo que le sirve a la máquina: `voided`,
 * `App\Domains\Sales\Models\Sale`, `{"status":"issued"}`. Quien audita necesita
 * leer «Ana anuló la factura 001-001-01-00000123», y necesita verlo sin pedirle
 * a nadie que le explique la base de datos.
 *
 * ## Por qué los valores no se traducen a mano
 *
 * Los `old_values` y `new_values` guardan el valor crudo de la columna, que casi
 * siempre es el `value` de un enum. En vez de repetir aquí un diccionario de
 * estados —que se desactualizaría en el primer enum nuevo—, se lee el `casts()`
 * del propio modelo y se le pide su `label()`. El día que un enum cambie sus
 * etiquetas, la bitácora cambia con él.
 */
final class AuditNarrator
{
    /**
     * Verbos en tercera persona: el renglón se lee «Ana anuló la factura…».
     *
     * @var array<string, string>
     */
    private const EVENTS = [
        'created' => 'creó',
        'updated' => 'editó',
        'deleted' => 'eliminó',
        'issued' => 'emitió',
        'posted' => 'contabilizó',
        'voided' => 'anuló',
        'reversed' => 'revirtió',
        'received' => 'registró la recepción de',
        'opened' => 'abrió',
        'closed' => 'cerró',
        'reopened' => 'reabrió',
        'cleared' => 'marcó como cobrado',
        'disposed' => 'dio de baja',
        'registered' => 'registró',
        'retired' => 'retiró',
        'revoked' => 'revocó',
        'activated' => 'activó',
        'deactivated' => 'desactivó',
        'granted_access' => 'dio acceso a',
        'access_revoked' => 'quitó el acceso a',
        'password_reset' => 'generó una contraseña temporal para',
        'role_changed' => 'cambió el rol de',
    ];

    /**
     * Nombre de cada cosa auditable, con su artículo, tal como se dice en la
     * oficina: no «Sale» ni «Venta», sino «la factura».
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'Sale' => 'la factura',
        'CreditNote' => 'la nota de crédito',
        'Receipt' => 'el recibo de cobro',
        'Customer' => 'el cliente',
        'Product' => 'el producto',
        'Purchase' => 'la compra',
        'Payment' => 'el pago a proveedor',
        'Supplier' => 'el proveedor',
        'JournalEntry' => 'la partida contable',
        'Account' => 'la cuenta contable',
        'AccountingPeriod' => 'el período contable',
        'FiscalYear' => 'el ejercicio fiscal',
        'BankAccount' => 'la cuenta bancaria',
        'BankReconciliation' => 'la conciliación bancaria',
        'Check' => 'el cheque',
        'CashSession' => 'la caja',
        'FixedAsset' => 'el activo fijo',
        'DepreciationRun' => 'la corrida de depreciación',
        'StockAdjustment' => 'el ajuste de inventario',
        'StockTransfer' => 'el traslado entre bodegas',
        'FiscalAuthorization' => 'la autorización (CAI)',
        'FiscalPoint' => 'el punto de emisión',
        'ApiToken' => 'el token de API',
        'User' => 'el usuario',
        'Company' => 'la empresa',
        'Branch' => 'la sucursal',
        'Warehouse' => 'la bodega',
        'Withholding' => 'la retención',
        'Tax' => 'el impuesto',
        'Unit' => 'la unidad',
        'PriceList' => 'la lista de precios',
        'ProductCategory' => 'la categoría',
        'FixedAssetCategory' => 'la categoría de activos',
        'DocumentSeries' => 'la serie de documentos',
        'Plan' => 'el plan',
        'Subscription' => 'la suscripción',
        'Tenant' => 'la cuenta',
    ];

    /**
     * El módulo se guarda con el nombre del namespace —«receivables»— porque lo
     * deriva el `AuditLogger` del dominio. En pantalla va como se llama en la
     * oficina y como aparece en el menú.
     *
     * @var array<string, string>
     */
    private const MODULES = [
        'accounting' => 'Contabilidad',
        'api' => 'API',
        'assets' => 'Activos fijos',
        'billing' => 'Suscripción',
        'fiscal' => 'Facturación (CAI)',
        'identity' => 'Usuarios',
        'inventory' => 'Inventario',
        'payables' => 'Cuentas por pagar',
        'purchases' => 'Compras',
        'receivables' => 'Cuentas por cobrar',
        'sales' => 'Ventas',
        'tenancy' => 'Empresa',
        'treasury' => 'Tesorería',
    ];

    /**
     * Columnas cuyo nombre humanizado no se entendería.
     *
     * Las demás se humanizan solas: `document_type` sale «Documento», y eso
     * basta. Aquí van las que engañan —`opening_float` saldría «Opening
     * Float»— y todas las que la aplicación registra hoy.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'status' => 'Estado',
        'number' => 'Número',
        'document_number' => 'Número de documento',
        'code' => 'Código',
        'name' => 'Nombre',
        'cai' => 'CAI',
        // En este esquema `tax_id` es el RTN de la empresa, el cliente o el
        // proveedor, no una llave a la tabla de impuestos.
        'tax_id' => 'RTN',
        'reason' => 'Motivo',
        'role' => 'Rol',
        'company' => 'Empresa',
        'is_active' => 'Activo',
        'total' => 'Total',
        'subtotal' => 'Subtotal',
        'tax_total' => 'Impuestos',
        'balance' => 'Saldo',
        'cost' => 'Costo',
        'paid_amount' => 'Abonado',
        'credited_amount' => 'Acreditado',
        'counted' => 'Efectivo contado',
        'counted_amount' => 'Efectivo contado',
        'expected' => 'Efectivo esperado',
        'difference' => 'Diferencia',
        'opening_float' => 'Fondo inicial',
        'proceeds' => 'Valor de venta',
        'net_profit' => 'Resultado',
        'book_value' => 'Valor en libros',
        'book_balance' => 'Saldo según libros',
        'statement_balance' => 'Saldo del estado de cuenta',
        'total_debit' => 'Total debe',
        'total_credit' => 'Total haber',
        'total_value' => 'Valor total',
        'entries' => 'Partidas',
        'period' => 'Período',
        'range' => 'Rango',
        'scopes' => 'Permisos del token',
        'unused' => 'Sin usar',
        'sale' => 'Factura',
        'supplier_invoice' => 'Factura del proveedor',
        'bank_name' => 'Banco',
        'reversal_number' => 'Reversión',
        'reversal_entry_id' => 'Partida de reversión',
        'voided_at' => 'Anulado el',
        'voided_by' => 'Anulado por',
        'posted_at' => 'Contabilizado el',
        'closed_at' => 'Cerrado el',
        'cleared_on' => 'Cobrado el',
        'disposed_on' => 'Dado de baja el',
        'cutoff_date' => 'Fecha de corte',
        'expires_at' => 'Vence el',
        'range_from' => 'Rango desde',
        'range_to' => 'Rango hasta',
        'limit_date' => 'Fecha límite de emisión',

        // Llaves cuyo papel no se deduce del nombre de la tabla: todas apuntan
        // a `accounts`, y lo que distingue una de otra es para qué sirve.
        'deposit_account_id' => 'Cuenta de depósito',
        'payment_account_id' => 'Cuenta de pago',
        'income_account_id' => 'Cuenta de ingreso',
        'expense_account_id' => 'Cuenta de gasto',
        'cost_account_id' => 'Cuenta de costo',
        'inventory_account_id' => 'Cuenta de inventario',
        'asset_account_id' => 'Cuenta del activo',
        'accumulated_account_id' => 'Cuenta de depreciación acumulada',
        'depreciation_account_id' => 'Cuenta de gasto por depreciación',
        'adjustment_account_id' => 'Cuenta de ajuste',
        'creditable_account_id' => 'Cuenta acreditable',
        'payable_account_id' => 'Cuenta por pagar',

        'from_warehouse_id' => 'Bodega de origen',
        'to_warehouse_id' => 'Bodega de destino',
        'default_branch_id' => 'Sucursal predeterminada',
        'default_company_id' => 'Empresa predeterminada',
        'withholding_type_id' => 'Tipo de retención',
        'journal_entry_line_id' => 'Renglón de la partida',
        'sale_item_id' => 'Renglón de la factura',
        'reversal_of_id' => 'Reversión de',
    ];

    /**
     * Cache de los `casts()` por clase: se instancia el modelo una sola vez y
     * nunca toca la base de datos.
     *
     * @var array<string, array<string, string>>
     */
    private array $casts = [];

    /**
     * Lo que hizo la persona, en pasado. Un evento sin traducir se muestra tal
     * cual en vez de esconderse: es una bitácora, no puede callar nada.
     */
    public function event(string $event): string
    {
        return self::EVENTS[$event] ?? str_replace('_', ' ', $event);
    }

    /**
     * En qué parte del sistema pasó.
     */
    public function module(?string $module): string
    {
        if ($module === null || $module === '') {
            return '—';
        }

        return self::MODULES[$module] ?? Str::headline($module);
    }

    /**
     * Sobre qué lo hizo: «la factura 001-001-01-00000123».
     */
    public function subject(AuditLog $log): string
    {
        return trim($this->subjectType($log->auditable_type).' '.$this->identifier($log));
    }

    /**
     * Solo el tipo: «la factura». Sirve para agrupar y filtrar.
     */
    public function subjectType(string $auditableType): string
    {
        $class = class_basename($auditableType);

        return self::SUBJECTS[$class] ?? mb_strtolower(Str::headline($class));
    }

    /**
     * Cómo se llama el registro para quien lo busca: su folio, su código o su
     * nombre. Si el registro ya no existe —un borrador eliminado— queda su id,
     * que es justamente lo único que sobrevive.
     */
    public function identifier(AuditLog $log): string
    {
        $model = $log->auditable;

        if ($model instanceof Model) {
            // Se leen las columnas crudas, no `getAttribute()`: la aplicación
            // corre con `preventAccessingMissingAttributes`, así que preguntar
            // por una columna que ese modelo no tiene revienta en vez de
            // devolver null. Y aquí se pregunta a ciegas por veinte modelos.
            $attributes = $model->getAttributes();

            foreach (['document_number', 'number', 'code', 'name', 'legal_name', 'cai'] as $attribute) {
                $value = $attributes[$attribute] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return '#'.$log->auditable_id;
    }

    /**
     * Qué cambió, campo por campo, ya traducido.
     *
     * @return list<array{field: string, from: string|null, to: string|null}>
     */
    public function changes(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        $fields = array_keys($old + $new);
        $changes = [];

        foreach ($fields as $field) {
            $field = (string) $field;

            $changes[] = [
                'field' => $this->field($field),
                'from' => array_key_exists($field, $old)
                    ? $this->value($log->auditable_type, $field, $old[$field])
                    : null,
                'to' => array_key_exists($field, $new)
                    ? $this->value($log->auditable_type, $field, $new[$field])
                    : null,
            ];
        }

        return $changes;
    }

    public function field(string $field): string
    {
        if (isset(self::FIELDS[$field])) {
            return self::FIELDS[$field];
        }

        // `customer_id` se lee «Cliente»: el id es ruido para quien audita, y el
        // nombre de la entidad ya está traducido en SUBJECTS. Sin esto saldría
        // «Customer», que es exactamente el inglés que no debe llegar a
        // pantalla.
        $entity = Str::of($field)->replaceEnd('_id', '')->toString();
        $subject = self::SUBJECTS[Str::studly($entity)] ?? null;

        if ($subject !== null) {
            return Str::ucfirst(Str::of($subject)->after(' ')->toString());
        }

        return Str::headline($entity);
    }

    /**
     * Traduce el valor crudo de una columna usando el `casts()` de su modelo.
     */
    public function value(string $auditableType, string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        $cast = $this->castFor($auditableType, $field);

        if ($cast !== null && enum_exists($cast)) {
            return $this->enumLabel($cast, $value);
        }

        if ($cast !== null && $this->isDateCast($cast)) {
            return $this->dateLabel($cast, (string) $value);
        }

        if ($cast === 'bool' || $cast === 'boolean') {
            return $value ? 'Sí' : 'No';
        }

        if (is_numeric($value)) {
            return $this->numberLabel($field, (string) $value);
        }

        return (string) $value;
    }

    /**
     * Las columnas de dinero y cantidad son DECIMAL con cuatro decimales, así
     * que en crudo se leen «724.5000». El importe va con separador de miles y
     * dos decimales; a la cantidad se le quitan los ceros que no dicen nada.
     */
    private function numberLabel(string $field, string $value): string
    {
        if ($this->isMoneyField($field)) {
            return Money::of($value)->format();
        }

        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function isMoneyField(string $field): bool
    {
        $exact = [
            'total', 'subtotal', 'balance', 'amount', 'debit', 'credit', 'difference',
            'cost', 'counted', 'expected', 'proceeds', 'net_profit', 'opening_float',
            'total_debit', 'total_credit',
        ];

        if (in_array($field, $exact, strict: true)) {
            return true;
        }

        foreach (['_amount', '_total', '_cost', '_price', '_value', '_balance'] as $suffix) {
            if (str_ends_with($field, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function enumLabel(string $enum, mixed $value): string
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            return (string) $value;
        }

        $case = $enum::tryFrom(is_int($value) ? $value : (string) $value);

        if ($case === null) {
            return (string) $value;
        }

        return method_exists($case, 'label') ? $case->label() : $case->name;
    }

    private function dateLabel(string $cast, string $value): string
    {
        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return $value;
        }

        return str_starts_with($cast, 'datetime') || str_starts_with($cast, 'immutable_datetime')
            ? $date->format('d/m/Y H:i')
            : $date->format('d/m/Y');
    }

    private function isDateCast(string $cast): bool
    {
        foreach (['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'] as $prefix) {
            if ($cast === $prefix || str_starts_with($cast, $prefix.':')) {
                return true;
            }
        }

        return false;
    }

    private function castFor(string $auditableType, string $field): ?string
    {
        if (! array_key_exists($auditableType, $this->casts)) {
            $this->casts[$auditableType] = $this->resolveCasts($auditableType);
        }

        return $this->casts[$auditableType][$field] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function resolveCasts(string $auditableType): array
    {
        if (! class_exists($auditableType) || ! is_subclass_of($auditableType, Model::class)) {
            return [];
        }

        try {
            /** @var Model $model */
            $model = new $auditableType;

            return array_filter($model->getCasts(), 'is_string');
        } catch (Throwable) {
            // Un modelo que ya no existe o que no se puede instanciar no puede
            // tumbar la pantalla: el renglón se muestra con el valor crudo.
            return [];
        }
    }
}
