<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Datos mínimos del catálogo para que una empresa nueva pueda facturar el
 * mismo día: unidades de medida, listas de precios e impuestos hondureños.
 *
 * Todo es editable después; esto solo evita que el usuario tenga que configurar
 * lo obvio antes de emitir su primera factura.
 */
final class CatalogProvisioner
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    public function provisionFor(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $this->createUnits($company);
            $this->createPriceLists($company);
            $this->createTaxes($company);
        });
    }

    private function createUnits(Company $company): void
    {
        $units = [
            ['UND', 'Unidad'],
            ['CJA', 'Caja'],
            ['LB', 'Libra'],
            ['KG', 'Kilogramo'],
            ['LT', 'Litro'],
            ['MT', 'Metro'],
            ['SRV', 'Servicio'],
        ];

        foreach ($units as [$code, $name]) {
            $unit = new Unit;
            $unit->forceFill([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
            ])->save();
        }
    }

    /**
     * Tres niveles, que es la segmentación habitual en distribución.
     */
    private function createPriceLists(Company $company): void
    {
        $lists = [
            ['DET', 'Detalle', true],
            ['MAY', 'Mayorista', false],
            ['DIS', 'Distribuidor', false],
        ];

        foreach ($lists as [$code, $name, $isDefault]) {
            $list = new PriceList;
            $list->forceFill([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'is_default' => $isDefault,
                'is_active' => true,
            ])->save();
        }
    }

    private function createTaxes(Company $company): void
    {
        $payable = $this->mappings->resolveId(AccountMappingKey::SalesTaxPayable);
        $creditable = $this->mappings->resolveId(AccountMappingKey::PurchasesTaxCredit);

        $taxes = [
            ['ISV15', 'ISV 15%', '15.000000', true],
            ['ISV18', 'ISV 18%', '18.000000', false],
            ['EXE', 'Exento', '0.000000', false],
        ];

        foreach ($taxes as [$code, $name, $rate, $isDefault]) {
            $tax = new Tax;
            $tax->forceFill([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'rate' => $rate,
                'is_included' => false,
                'payable_account_id' => $payable,
                'creditable_account_id' => $creditable,
                'is_default' => $isDefault,
                'is_active' => true,
            ])->save();
        }
    }
}
