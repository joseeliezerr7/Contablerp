<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\AuditLog;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Escritura de la bitácora de auditoría.
 *
 * Funciona igual dentro de una petición HTTP que en consola o en una cola: si
 * no hay request, IP y user agent quedan en NULL en vez de fallar.
 */
final class AuditLogger
{
    public function __construct(private readonly CompanyContext $context) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $event,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?string $module = null,
    ): AuditLog {
        return AuditLog::create([
            'company_id' => $this->context->id(),
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'module' => $module ?? $this->moduleFor($model),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'ip_address' => $this->requestValue(fn ($request) => $request->ip()),
            'user_agent' => $this->truncate($this->requestValue(fn ($request) => $request->userAgent())),
        ]);
    }

    /**
     * Registra un cambio comparando el estado anterior con el actual, guardando
     * solo los atributos que realmente cambiaron.
     */
    public function logChanges(string $event, Model $model, ?string $reason = null): ?AuditLog
    {
        $changes = $model->getChanges();

        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        $original = array_intersect_key($model->getOriginal(), $changes);

        return $this->log($event, $model, $original, $changes, $reason);
    }

    /**
     * Deriva el módulo del namespace del dominio: App\Domains\Accounting\Models\X
     * produce «accounting».
     */
    private function moduleFor(Model $model): ?string
    {
        if (preg_match('/App\\\\Domains\\\\(\w+)\\\\/', $model::class, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        return null;
    }

    private function requestValue(callable $resolver): ?string
    {
        if (! app()->bound('request') || app()->runningInConsole()) {
            return null;
        }

        $value = $resolver(request());

        return is_string($value) ? $value : null;
    }

    private function truncate(?string $value, int $length = 255): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
