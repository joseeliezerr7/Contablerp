<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripción de un tenant a un plan.
 *
 * El precio se copia al suscribirse. Si mañana sube la tarifa, las cuentas que
 * ya estaban pagan lo pactado hasta que se les cambie explícitamente: subirle
 * el precio a alguien porque se editó una fila de otra tabla sería una forma
 * rápida de perder clientes.
 *
 * Los límites también se copian, por la misma razón y por una más: una cuenta
 * puede tener condiciones negociadas que no son las del plan. La suscripción es
 * el contrato; el plan es solo de dónde salió.
 *
 * ## La facturación del servicio no es contabilidad del cliente
 *
 * `subscription_invoices` es lo que el proveedor le cobra al cliente por usar
 * el sistema. **No genera ninguna partida en el libro de nadie**: es ingreso
 * del proveedor, y el proveedor no lleva su contabilidad aquí. Confundir las
 * dos cosas metería el precio del software dentro de los gastos del cliente sin
 * que él lo hubiera registrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->enum('status', ['trialing', 'active', 'past_due', 'suspended', 'cancelled'])
                ->default('trialing');

            // Copiados del plan al suscribirse: son el contrato.
            $table->decimal('price', 18, 4);
            $table->char('currency_code', 3)->default('HNL');
            $table->enum('interval', ['monthly', 'yearly'])->default('monthly');

            $table->unsignedInteger('max_companies')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->date('current_period_start');
            $table->date('current_period_end');

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('current_period_end');
        });

        // Un tenant no puede tener dos suscripciones vivas a la vez: no habría
        // forma de saber qué límites aplicar.
        DB::statement(<<<'SQL'
            ALTER TABLE subscriptions
            ADD COLUMN live_key TINYINT
            GENERATED ALWAYS AS (
                CASE WHEN status IN ('trialing', 'active', 'past_due', 'suspended') THEN 1 END
            ) STORED,
            ADD UNIQUE INDEX subscriptions_live_unique (tenant_id, live_key)
        SQL);

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('number', 30);
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3)->default('HNL');

            $table->enum('status', ['pending', 'paid', 'void'])->default('pending');
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 60)->nullable();

            $table->timestamps();

            $table->unique('number');
            $table->index(['tenant_id', 'status']);
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
    }
};
