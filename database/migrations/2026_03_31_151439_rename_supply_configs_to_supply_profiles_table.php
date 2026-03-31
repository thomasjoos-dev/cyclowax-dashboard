<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('supply_configs', 'supply_profiles');
    }

    public function down(): void
    {
        Schema::rename('supply_profiles', 'supply_configs');
    }
};
