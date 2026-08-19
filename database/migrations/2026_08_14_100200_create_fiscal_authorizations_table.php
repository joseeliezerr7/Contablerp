<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorización de impresión (CAI).
 *
 * El SAR autoriza un rango de correlativos para un punto de emisión y un tipo de
 * documento, con una fecha límite de emisión. Fuera de ese rango o pasada esa
 * fecha, el documento **no es válido**: no es una advertencia del sistema, es la
 * diferencia entre una factura y un papel.
 *
 * ## Por qué el correlativo vive aquí y no en `document_series`
 *
 * `document_series` numera documentos internos —partidas, recibos— y su único
 * requisito es no repetir. Un correlativo fiscal tiene además un techo, un piso
 * y una fecha de caducidad, y cuando se agota **no continúa**: hay que pedir
 * otra autorización, que empieza donde diga el SAR y no donde terminó la
 * anterior. Meterlo en la misma tabla obligaría a que la numeración interna
 * cargara con reglas que no son suyas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_point_id')->constrained()->restrictOnDelete();

            $table->string('document_type', 20)->comment('invoice, credit_note, debit_note');

            // El código de dos dígitos que va dentro del número lo dicta la
            // resolución del SAR, así que se captura en vez de deducirse: si la
            // administración lo cambia, se corrige en pantalla y no en el código.
            $table->char('document_type_code', 2);

            $table->string('cai', 40);

            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedBigInteger('next_number');

            $table->date('issued_on');
            $table->date('limit_date')->comment('Fecha límite de emisión');

            $table->string('status', 20)->default('active')
                ->comment('active, exhausted, expired, replaced');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'limit_date']);

            // Una sola autorización activa por punto de emisión y tipo de
            // documento: dos vigentes a la vez son dos correlativos avanzando en
            // paralelo, y eso es exactamente lo que el régimen prohíbe.
            //
            // La columna generada solo mira `status` y `document_type`; el punto
            // de emisión va en el índice pero no en la expresión, porque MySQL
            // no admite una FK con ON DELETE en la base de una columna STORED.
            $table->string('active_key', 20)->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN document_type ELSE NULL END");

            $table->unique(['fiscal_point_id', 'active_key'], 'fiscal_authorizations_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_authorizations');
    }
};
