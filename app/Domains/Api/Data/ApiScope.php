<?php

declare(strict_types=1);

namespace App\Domains\Api\Data;

/**
 * Alcances de un token de API.
 *
 * Son deliberadamente **más gruesos** que los permisos de la aplicación. Un rol
 * describe a una persona, que hace muchas cosas y cambia de tarea; un token
 * describe a un programa, que casi siempre hace una sola. La tienda en línea
 * necesita leer el catálogo y crear facturas, nada más; darle los cincuenta
 * permisos de un Vendedor porque «es como un vendedor» es regalar superficie de
 * ataque.
 *
 * ## Cómo se combinan con los permisos del usuario
 *
 * Un token no puede hacer lo que su dueño no podría. El alcance **acota**, no
 * concede: al emitir una factura por API se comprueban las dos cosas, el alcance
 * del token y el permiso del usuario. Un token con `sales:write` en manos de
 * alguien sin `sales.invoices.issue` sigue sin poder facturar.
 */
final class ApiScope
{
    public const CATALOG_READ = 'catalog:read';

    public const CUSTOMERS_READ = 'customers:read';

    public const CUSTOMERS_WRITE = 'customers:write';

    public const SALES_READ = 'sales:read';

    public const SALES_WRITE = 'sales:write';

    public const RECEIVABLES_READ = 'receivables:read';

    public const INVENTORY_READ = 'inventory:read';

    /**
     * Todos los alcances con su descripción, para la pantalla de tokens.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::CATALOG_READ => 'Leer productos, precios e impuestos',
            self::CUSTOMERS_READ => 'Leer clientes',
            self::CUSTOMERS_WRITE => 'Crear y editar clientes',
            self::SALES_READ => 'Leer facturas de venta',
            self::SALES_WRITE => 'Emitir facturas de venta',
            self::RECEIVABLES_READ => 'Consultar saldos y antigüedad',
            self::INVENTORY_READ => 'Consultar existencias',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_keys(self::all());
    }

    /**
     * Solo lectura: el conjunto que se le da a una integración que reporta.
     *
     * @return array<int, string>
     */
    public static function readOnly(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $scope) => str_ends_with($scope, ':read'),
        ));
    }

    /**
     * El permiso de la aplicación que además hace falta para cada alcance.
     *
     * Es el puente entre los dos sistemas: el alcance dice qué puede pedir el
     * programa, y esto dice qué tiene que poder hacer la persona detrás.
     *
     * @return array<string, string>
     */
    public static function requiredPermissions(): array
    {
        return [
            self::CATALOG_READ => 'catalog.products.view',
            self::CUSTOMERS_READ => 'customers.view',
            self::CUSTOMERS_WRITE => 'customers.create',
            self::SALES_READ => 'sales.invoices.view',
            self::SALES_WRITE => 'sales.invoices.issue',
            self::RECEIVABLES_READ => 'receivables.view',
            self::INVENTORY_READ => 'inventory.stock.view',
        ];
    }
}
