<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->integer('xp')->default(0)->after('nickname');
            $table->integer('level')->default(1)->after('xp');
            $table->integer('streak')->default(0)->after('level');
            $table->integer('hearts')->default(5)->after('streak');
            $table->integer('coins')->default(0)->after('hearts');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['xp', 'level', 'streak', 'hearts', 'coins']);
        });
    }
};
