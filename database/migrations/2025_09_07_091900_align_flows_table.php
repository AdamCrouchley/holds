<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('flows')) {
            Schema::create('flows', function (Blueprint $table) {
                $table->id();
                $table->string('name');                  // used in listings/forms
                $table->text('description')->nullable(); // safe optional
                $table->json('config')->nullable();      // steps/triggers/etc
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();                    // created_at / updated_at
                $table->softDeletes();                   // deleted_at (Filament scopes)
            });
            return;
        }

        // Align existing table gently (no data loss)
        Schema::table('flows', function (Blueprint $table) {
            $columns = Schema::getColumnListing('flows');

            if (! in_array('name', $columns)) {
                $table->string('name')->default('Untitled Flow');
            }
            if (! in_array('description', $columns)) {
                $table->text('description')->nullable();
            }
            if (! in_array('config', $columns)) {
                $table->json('config')->nullable();
            }
            if (! in_array('is_active', $columns)) {
                $table->boolean('is_active')->default(false)->index();
            }
            if (! in_array('created_at', $columns)) {
                $table->timestamp('created_at')->nullable()->useCurrent();
            }
            if (! in_array('updated_at', $columns)) {
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            }
            if (! in_array('deleted_at', $columns)) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        // Keep it non-destructive; if you need to roll back hard, drop the table manually.
    }
};
