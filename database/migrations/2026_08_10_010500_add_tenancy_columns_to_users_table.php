<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            // Empresa y sucursal que se activan al iniciar sesión. La pertenencia
            // real se valida siempre contra company_user, no contra estos campos.
            $table->foreignId('default_company_id')->nullable()->after('password')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('default_branch_id')->nullable()->after('default_company_id')
                ->constrained('branches')->nullOnDelete();

            $table->boolean('is_active')->default(true)->after('default_branch_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropConstrainedForeignId('default_company_id');
            $table->dropConstrainedForeignId('default_branch_id');
            $table->dropColumn(['is_active', 'last_login_at', 'last_login_ip']);
        });
    }
};
