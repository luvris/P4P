<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============ groups: เพิ่ม duty_id ============
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('duty_id')
                ->nullable()
                ->after('id')
                ->constrained('duties')
                ->cascadeOnDelete();
        });

        // ============ works: เพิ่ม group_id ============
        Schema::table('works', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('id')
                ->constrained('groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['duty_id']);
            $table->dropColumn('duty_id');
        });
    }
};
