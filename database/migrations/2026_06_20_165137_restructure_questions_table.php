<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->renameColumn('image_path', 'source_image_path');
            $table->dropColumn('extracted_text');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->integer('question_number')->nullable()->after('source_image_path');
            $table->text('question_text')->nullable()->after('question_number');
            $table->boolean('has_image')->default(false)->after('options');
            $table->string('cropped_image_path')->nullable()->after('has_image');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['question_number', 'question_text', 'has_image', 'cropped_image_path']);
            $table->text('extracted_text')->nullable()->after('source_image_path');
            $table->renameColumn('source_image_path', 'image_path');
        });
    }
};
