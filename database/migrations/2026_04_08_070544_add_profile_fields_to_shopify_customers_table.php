<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('shopify_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('locale', 10)->nullable()->after('country_code');
            $table->string('tags')->nullable()->after('locale');
            $table->string('email_marketing_consent', 20)->nullable()->after('tags');
            $table->datetime('shopify_created_at')->nullable()->after('email_marketing_consent');
            $table->string('gender', 10)->nullable()->after('shopify_created_at');
            $table->float('gender_probability')->nullable()->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_customers', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'locale',
                'tags',
                'email_marketing_consent',
                'shopify_created_at',
                'gender',
                'gender_probability',
            ]);
        });
    }
};
