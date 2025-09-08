<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            if (! Schema::hasColumn('flows', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('flows', 'tags')) {
                $table->json('tags')->nullable()->after('description');
            }
            if (! Schema::hasColumn('flows', 'auto_renew_days')) {
                $table->unsignedSmallInteger('auto_renew_days')->nullable()->after('hold_amount_cents');
            }
            if (! Schema::hasColumn('flows', 'auto_release_days')) {
                $table->unsignedSmallInteger('auto_release_days')->nullable()->after('auto_renew_days');
            }
            if (! Schema::hasColumn('flows', 'allow_partial_capture')) {
                $table->boolean('allow_partial_capture')->default(false)->after('auto_release_days');
            }
            if (! Schema::hasColumn('flows', 'auto_capture_on_damage')) {
                $table->boolean('auto_capture_on_damage')->default(false)->after('allow_partial_capture');
            }
            if (! Schema::hasColumn('flows', 'auto_cancel_if_no_capture')) {
                $table->boolean('auto_cancel_if_no_capture')->default(false)->after('auto_capture_on_damage');
            }
            if (! Schema::hasColumn('flows', 'auto_cancel_after_days')) {
                $table->unsignedSmallInteger('auto_cancel_after_days')->nullable()->after('auto_cancel_if_no_capture');
            }
            if (! Schema::hasColumn('flows', 'required_fields')) {
                $table->json('required_fields')->nullable()->after('auto_cancel_after_days');
            }
            if (! Schema::hasColumn('flows', 'comms')) {
                $table->json('comms')->nullable()->after('required_fields');
            }
            if (! Schema::hasColumn('flows', 'webhooks')) {
                $table->json('webhooks')->nullable()->after('comms');
            }
        });

        // Optional: backfill from meta JSON if present
        try {
            DB::table('flows')->select('id', 'meta')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    if (!is_null($r->meta)) {
                        $m = json_decode($r->meta, true) ?: [];
                        DB::table('flows')->where('id', $r->id)->update([
                            'description'              => $m['description'] ?? null,
                            'tags'                     => isset($m['tags']) ? json_encode($m['tags']) : null,
                            'auto_renew_days'          => $m['auto_renew_days'] ?? null,
                            'auto_release_days'        => $m['auto_release_days'] ?? null,
                            'allow_partial_capture'    => (bool)($m['allow_partial_capture'] ?? false),
                            'auto_capture_on_damage'   => (bool)($m['auto_capture_on_damage'] ?? false),
                            'auto_cancel_if_no_capture'=> (bool)($m['auto_cancel_if_no_capture'] ?? false),
                            'auto_cancel_after_days'   => $m['auto_cancel_after_days'] ?? null,
                            'required_fields'          => isset($m['required_fields']) ? json_encode($m['required_fields']) : null,
                            'comms'                    => isset($m['comms']) ? json_encode($m['comms']) : null,
                            'webhooks'                 => isset($m['webhooks']) ? json_encode($m['webhooks']) : null,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            // ignore backfill errors on older MySQL; columns are created regardless
        }
    }

    public function down(): void { /* no destructive down on prod */ }
};
