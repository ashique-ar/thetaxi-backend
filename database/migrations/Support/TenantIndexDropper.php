<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantIndexDropper
{
    public static function drop(string $tableName, array $index): void
    {
        $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';

        if ($isUnique && DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $isConstraint = DB::table('pg_constraint as c')
                ->join('pg_class as r', 'r.oid', '=', 'c.conrelid')
                ->join('pg_namespace as n', 'n.oid', '=', 'r.relnamespace')
                ->where('c.contype', 'u')
                ->where('c.conname', $index['name'])
                ->where('r.relname', $tableName)
                ->whereRaw('n.nspname = current_schema()')
                ->exists();

            if ($isConstraint) {
                DB::statement('ALTER TABLE '.$grammar->wrapTable($tableName).' DROP CONSTRAINT '.$grammar->wrap($index['name']));
            } else {
                DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap($index['name']));
            }

            return;
        }

        $dropMethod = $isUnique ? 'dropUnique' : 'dropIndex';
        Schema::table($tableName, fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
    }
}
