<?php

declare(strict_types=1);

namespace App\Livewire\Api;

use App\Domains\Api\Data\ApiScope;
use App\Domains\Api\Models\ApiToken;
use App\Domains\Api\Services\ApiTokenService;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Tokens de la API pública.
 *
 * El secreto se muestra **una sola vez**, al emitirlo. Es incómodo a propósito:
 * un sistema que puede volver a enseñártelo mañana también puede enseñárselo a
 * quien entre a la base de datos.
 */
#[Title('Tokens de API')]
class TokenIndex extends Component
{
    public bool $showingForm = false;

    public string $name = '';

    public ?int $userId = null;

    /** @var array<int, string> */
    public array $scopes = [];

    public string $expiresAt = '';

    /**
     * El secreto recién emitido. Vive solo mientras dure la pantalla.
     */
    public ?string $plainToken = null;

    public ?int $revoking = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ApiToken::class);

        $this->userId = auth()->id();
    }

    public function create(): void
    {
        $this->authorize('create', ApiToken::class);

        $this->reset(['name', 'scopes', 'expiresAt', 'plainToken']);
        $this->userId = auth()->id();
        $this->showingForm = true;
        $this->resetValidation();
    }

    public function save(ApiTokenService $tokens): void
    {
        $this->authorize('create', ApiToken::class);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'userId' => ['required', 'integer'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['in:'.implode(',', ApiScope::values())],
            // Un token sin vencimiento es una llave que nadie recoge. Se permite
            // —hay integraciones que viven años— pero la pantalla empuja a poner
            // fecha ofreciéndola por defecto.
            'expiresAt' => ['nullable', 'date', 'after:today'],
        ], attributes: [
            'name' => 'nombre',
            'userId' => 'usuario',
            'scopes' => 'alcances',
            'expiresAt' => 'fecha de vencimiento',
        ]);

        try {
            $result = $tokens->issue(
                User::query()->findOrFail($this->userId),
                trim($this->name),
                $this->scopes,
                $this->expiresAt === '' ? null : $this->expiresAt,
            );
        } catch (RuntimeException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->plainToken = $result['plain'];
        $this->showingForm = false;
        $this->reset(['name', 'scopes', 'expiresAt']);
    }

    public function confirmRevoke(int $id): void
    {
        $token = ApiToken::query()->findOrFail($id);
        $this->authorize('revoke', $token);

        $this->revoking = $id;
    }

    public function revoke(ApiTokenService $tokens): void
    {
        $token = ApiToken::query()->findOrFail($this->revoking);
        $this->authorize('revoke', $token);

        $tokens->revoke($token);

        session()->flash('success', 'El token quedó revocado. Cualquier integración que lo use dejará de funcionar.');
        $this->revoking = null;
    }

    public function dismissSecret(): void
    {
        $this->plainToken = null;
    }

    public function cancel(): void
    {
        $this->reset(['showingForm', 'name', 'scopes', 'expiresAt', 'revoking']);
        $this->resetValidation();
    }

    public function render(ApiTokenService $tokens, CompanyContext $context): View
    {
        $this->authorize('viewAny', ApiToken::class);

        return view('livewire.api.token-index', [
            'tokens' => $tokens->forCurrentCompany(),
            'allScopes' => ApiScope::all(),
            'company' => $context->companyOrFail(),
            // Solo gente de esta empresa puede ser dueña de un token suyo.
            'users' => $context->companyOrFail()->users()->orderBy('name')->get(),
        ]);
    }
}
