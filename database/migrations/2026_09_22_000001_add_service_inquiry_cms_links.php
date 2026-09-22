<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addNullableUuid('cms_contents', 'inquiry_form_id');
        $this->addNullableUuid('inquiries', 'cms_content_id');

        $this->addIndex('cms_contents', 'inquiry_form_id', 'cms_contents_inquiry_form_id_index');
        $this->addIndex('inquiries', 'cms_content_id', 'inquiries_cms_content_id_index');

        $this->addNullOnDeleteForeignKey(
            'cms_contents',
            'inquiry_form_id',
            'inquiry_forms',
            'cms_contents_inquiry_form_id_foreign'
        );
        $this->addNullOnDeleteForeignKey(
            'inquiries',
            'cms_content_id',
            'cms_contents',
            'inquiries_cms_content_id_foreign'
        );
    }

    public function down(): void
    {
        $this->dropLink('inquiries', 'cms_content_id', 'inquiries_cms_content_id_foreign');
        $this->dropLink('cms_contents', 'inquiry_form_id', 'cms_contents_inquiry_form_id_foreign');
    }

    private function addNullableUuid(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->uuid($column)->nullable();
        });
    }

    private function addIndex(string $table, string $column, string $name): void
    {
        if (! Schema::hasColumn($table, $column) || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $name): void {
            $blueprint->index($column, $name);
        });
    }

    private function addNullOnDeleteForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $name
    ): void {
        if (! Schema::hasTable($referencedTable)
            || ! Schema::hasColumn($table, $column)
            || $this->foreignKeyExists($table, $name, $column, $referencedTable)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $name): void {
            $blueprint->foreign($column, $name)
                ->references('id')
                ->on($referencedTable)
                ->nullOnDelete();
        });
    }

    private function dropLink(string $table, string $column, string $foreignKey): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        if ($this->foreignKeyExists($table, $foreignKey, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        }

        $index = "{$table}_{$column}_index";
        if ($this->indexExists($table, $index)) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    private function foreignKeyExists(
        string $table,
        string $name,
        string $column,
        ?string $referencedTable = null
    ): bool {
        return collect(Schema::getForeignKeys($table))
            ->contains(function (array $foreignKey) use ($name, $column, $referencedTable): bool {
                if (($foreignKey['name'] ?? null) === $name) {
                    return true;
                }

                return in_array($column, $foreignKey['columns'] ?? [], true)
                    && ($referencedTable === null
                        || ($foreignKey['foreign_table'] ?? null) === $referencedTable);
            });
    }
};
