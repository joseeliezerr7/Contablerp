<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planes del servicio.
 *
 * Los precios están en lempiras y pensados para la pequeña empresa hondureña,
 * que es a quien se dirige el sistema. Se siembran aparte del resto porque
 * pertenecen al proveedor y no a ninguna empresa cliente: existen antes de que
 * exista el primer cliente.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'emprende',
                'name' => 'Emprende',
                'description' => 'Para el negocio que empieza: una empresa, una sucursal y lo esencial para facturar y llevar la contabilidad.',
                'price' => '450.00',
                'trial_days' => 30,
                'max_companies' => 1,
                'max_users' => 3,
                'max_branches' => 1,
                'max_monthly_documents' => 300,
                'has_inventory' => true,
                'has_treasury' => false,
                'has_fixed_assets' => false,
                'has_multi_company' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'negocio',
                'name' => 'Negocio',
                'description' => 'Varias sucursales, bancos y conciliación, activos fijos y retenciones.',
                'price' => '1200.00',
                'trial_days' => 30,
                'max_companies' => 1,
                'max_users' => 10,
                'max_branches' => 5,
                'max_monthly_documents' => 2000,
                'has_inventory' => true,
                'has_treasury' => true,
                'has_fixed_assets' => true,
                'has_multi_company' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'corporativo',
                'name' => 'Corporativo',
                'description' => 'Varias empresas bajo una sola cuenta, sin límite de usuarios ni de documentos.',
                'price' => '2900.00',
                'trial_days' => 15,
                'max_companies' => null,
                'max_users' => null,
                'max_branches' => null,
                'max_monthly_documents' => null,
                'has_inventory' => true,
                'has_treasury' => true,
                'has_fixed_assets' => true,
                'has_multi_company' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::query()->updateOrCreate(
                ['code' => $attributes['code']],
                [...$attributes, 'currency_code' => 'HNL', 'interval' => 'monthly', 'is_public' => true, 'is_active' => true],
            );
        }
    }
}
