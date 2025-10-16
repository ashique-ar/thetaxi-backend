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
        // Modify model_has_permissions table to support UUIDs
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropPrimary(['permission_id', 'model_id', 'model_type']);
            $table->dropIndex('model_has_permissions_model_id_model_type_index');
            $table->string('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        // Modify model_has_roles table to support UUIDs
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropPrimary(['role_id', 'model_id', 'model_type']);
            $table->dropIndex('model_has_roles_model_id_model_type_index');
            $table->string('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert model_has_permissions table
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropPrimary(['permission_id', 'model_id', 'model_type']);
            $table->dropIndex('model_has_permissions_model_id_model_type_index');
            $table->unsignedBigInteger('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        // Revert model_has_roles table
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropPrimary(['role_id', 'model_id', 'model_type']);
            $table->dropIndex('model_has_roles_model_id_model_type_index');
            $table->unsignedBigInteger('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });
    }
};
