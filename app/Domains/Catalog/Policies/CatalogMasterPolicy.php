<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Policies;

use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidades de medida, categorías de producto y listas de precios.
 *
 * Las tres comparten policy a propósito: son datos de referencia con la misma
 * forma —código, nombre, activo—, el mismo riesgo y la misma persona a cargo.
 * Tres policies idénticas serían tres sitios donde olvidarse de arreglar el
 * mismo error.
 *
 * `$model` se tipa como `Model` porque la policy sirve a tres clases; lo que
 * importa es que pertenezca a la empresa activa, y eso lo comprueba la base.
 */
class CatalogMasterPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'catalog.masters.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, 'catalog.masters.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'catalog.masters.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, 'catalog.masters.manage', $model);
    }

    /**
     * No hay `delete`.
     *
     * Un producto vendido hace dos años apunta a su unidad y a su categoría, y
     * una factura apunta a la lista de precios con la que se cobró. Borrar
     * cualquiera de las tres dejaría documentos históricos hablando de algo que
     * ya no existe. Lo que se hace es desactivarlas: dejan de ofrecerse en los
     * selectores y los documentos viejos siguen leyéndose.
     */
    public function deactivate(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
