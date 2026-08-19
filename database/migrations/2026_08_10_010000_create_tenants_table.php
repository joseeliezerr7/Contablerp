<?php

declare(strict_types=1);

use App\Domains\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un tenant es la cuenta SaaS. Agrupa a los usuarios y a las empresas
 * contables que esos usuarios administran (caso típico: un despacho
 * contable que lleva la contabilidad de varios clientes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 60)->unique();
            $table->enum('status', TenantStatus::values())->default(TenantStatus::Trial->value);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
