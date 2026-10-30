<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantIndexDropper
{
    public static function drop(string $tableName, array $index): void
    {
        if ($index['type'] === 'unique' && DB::getDriverName() === 'pgsql') {
            $constraintExists = DB::table('pg_constraint as c')
                ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
                ->join('pg_namespace as n', 'n.oid', '=', 't.relnamespace')
                ->whereRaw('n.nspname = current_schema()')
                ->where('t.relname', $tableName)
                ->where('c.conname', $index['name'])
                ->exists();

            if ($constraintExists) {
                $grammar = DB::connection()->getQueryGrammar();
                DB::statement('ALTER TABLE '.$grammar->wrapTable($tableName).' DROP CONSTRAINT '.$grammar->wrap($index['name']));
            } else {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index['name']));
            }

            return;
        }

        $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
        Schema::table($tableName, fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
    }
}
