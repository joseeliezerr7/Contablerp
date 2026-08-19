<?php

declare(strict_types=1);

namespace App\Livewire\Identity;

use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Exceptions\IdentityException;
use App\Domains\Identity\Services\CompanyUserService;
use App\Domains\Tenancy\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Quién entra a la empresa y con qué rol.
 *
 * Es la pantalla que faltaba para poder entregar el sistema: hasta ahora el
 * dueño entraba solo y añadir a su cajera exigía la consola del servidor.
 */
#[Title('Usuarios')]
class UserIndex extends Component
{
    public bool $showingForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = PermissionCatalog::SALESPERSON;

    public ?int $branchId = null;

    /**
     * Contraseña temporal recién generada. Se muestra una sola vez.
     */
    public ?string $temporaryPassword = null;

    public ?string $temporaryFor = null;

    public ?int $confirmingRevoke = null;

    public ?int $confirmingReset = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function create(): void
    {
        $this->authorize('create', User::class);

        $this->reset(['editingId', 'name', 'email', 'branchId', 'temporaryPassword', 'temporaryFor']);
        $this->role = PermissionCatalog::SALESPERSON;
        $this->showingForm = true;
        $this->resetValidation();
    }

    public function edit(int $id, CompanyUserService $users, CompanyContext $context): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $users->roleNameFor($user, $context->companyOrFail()) ?? PermissionCatalog::SALESPERSON;
        $this->branchId = $user->companies()
            ->whereKey($context->idOrFail())
            ->value('company_user.branch_id');
        $this->showingForm = true;
        $this->resetValidation();
    }

    public function save(CompanyUserService $users): void
    {
        $editing = $this->editingId !== null;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            // Sin regla `unique`: que el correo exista no es un error, es el
            // caso normal de alguien que ya trabaja en la empresa hermana. Quién
            // puede reutilizarse y quién no lo decide el servicio, que distingue
            // «ya está en esta empresa» de «pertenece a otra cuenta».
            //
            // Al editar el correo no se toca: es la identidad de la persona en
            // todo el sistema y cambiarla aquí rompería sus otros accesos.
            'email' => ['required', 'email', 'max:180'],
            'role' => ['required', Rule::in(array_keys(PermissionCatalog::roles()))],
            'branchId' => ['nullable', 'integer'],
        ], attributes: [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'role' => 'rol',
            'branchId' => 'sucursal',
        ]);

        try {
            if ($editing) {
                $user = User::query()->findOrFail($this->editingId);
                $this->authorize('update', $user);

                $users->update($user, [
                    'name' => $this->name,
                    'role' => $this->role,
                    'branch_id' => $this->branchId,
                ]);

                session()->flash('success', 'Usuario actualizado.');
            } else {
                $this->authorize('create', User::class);

                $result = $users->invite([
                    'name' => $this->name,
                    'email' => $this->email,
                    'role' => $this->role,
                    'branch_id' => $this->branchId,
                ]);

                // Solo hay contraseña cuando el usuario es nuevo. A quien ya
                // existía se le dio acceso, no una cuenta.
                $this->temporaryPassword = $result['password'];
                $this->temporaryFor = $result['user']->name;

                session()->flash('success', $result['password'] === null
                    ? 'Se le dio acceso a esta empresa.'
                    : 'Usuario creado.');
            }
        } catch (IdentityException|BillingException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }

        $this->showingForm = false;
        $this->reset(['editingId', 'name', 'email', 'branchId']);
    }

    public function toggleActive(int $id, CompanyUserService $users): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        try {
            $users->setActive($user, ! $user->is_active);
            session()->flash('success', $user->is_active
                ? 'El usuario quedó desactivado y ya no puede entrar.'
                : 'El usuario quedó activo.');
        } catch (IdentityException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmRevoke(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('revokeAccess', $user);

        $this->confirmingRevoke = $id;
    }

    public function revoke(CompanyUserService $users): void
    {
        $user = User::query()->findOrFail($this->confirmingRevoke);
        $this->authorize('revokeAccess', $user);

        try {
            $users->revokeAccess($user);
            session()->flash('success', 'Le quitamos el acceso a esta empresa. Su cuenta y sus documentos quedan intactos.');
        } catch (IdentityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->confirmingRevoke = null;
    }

    public function confirmReset(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('resetPassword', $user);

        $this->confirmingReset = $id;
    }

    public function resetPassword(CompanyUserService $users): void
    {
        $user = User::query()->findOrFail($this->confirmingReset);
        $this->authorize('resetPassword', $user);

        $this->temporaryPassword = $users->resetPassword($user);
        $this->temporaryFor = $user->name;
        $this->confirmingReset = null;

        session()->flash('success', 'Contraseña temporal generada.');
    }

    public function dismissPassword(): void
    {
        $this->reset(['temporaryPassword', 'temporaryFor']);
    }

    public function cancel(): void
    {
        $this->reset(['showingForm', 'editingId', 'name', 'email', 'branchId', 'confirmingRevoke', 'confirmingReset']);
        $this->resetValidation();
    }

    public function render(CompanyUserService $users, CompanyContext $context): View
    {
        $this->authorize('viewAny', User::class);

        $company = $context->companyOrFail();

        return view('livewire.identity.user-index', [
            'users' => $users->forCurrentCompany(),
            'company' => $company,
            'roles' => array_keys(PermissionCatalog::roles()),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'permissionsByRole' => PermissionCatalog::roles(),
        ]);
    }
}
