<?php

namespace App\Backup\Drivers\Support;

use Illuminate\Database\Connection;

/**
 * Hands ownership of everything $fromRole owns in the current database to
 * $toRole - used by PostgresDriver::import() after importing as a
 * superuser, so restored objects end up owned by the app user regardless
 * of whether the dump itself set ownership (an uploaded/foreign dump may
 * not have).
 *
 * PostgreSQL's own `REASSIGN OWNED BY x TO y` looks like the obvious tool
 * for this, but it reassigns *everything* x owns in the database,
 * including pg_catalog/information_schema/pg_toast - which, for the
 * superuser role used to import, always fails with "cannot reassign
 * ownership of objects owned by role ... because they are required by the
 * database system" (verified against PostgreSQL 18). So this targets each
 * object type individually, filtered to schemas outside that ignore list.
 */
class PostgresOwnershipFixer
{
    /** @var list<string> */
    private const IGNORED_SCHEMA_NAMES = ['pg_catalog', 'information_schema', 'pg_toast'];

    private const IGNORED_SCHEMA_PATTERNS = ['pg\\_temp\\_%', 'pg\\_toast\\_temp\\_%'];

    public static function fix(Connection $connection, string $fromRole, string $toRole): void
    {
        if ($fromRole === $toRole) {
            return;
        }

        self::fixSchemas($connection, $fromRole, $toRole);
        self::fixRelations($connection, $fromRole, $toRole);
        self::fixRoutines($connection, $fromRole, $toRole);
        self::fixTypes($connection, $fromRole, $toRole);
    }

    private static function fixSchemas(Connection $connection, string $fromRole, string $toRole): void
    {
        $rows = $connection->select(
            'select nspname as name from pg_namespace where nspowner = (select oid from pg_roles where rolname = ?) and '.self::schemaExclusion('nspname'),
            [$fromRole]
        );

        foreach ($rows as $row) {
            $connection->statement('alter schema '.self::quoteIdentifier($row->name).' owner to '.self::quoteIdentifier($toRole));
        }
    }

    /** Tables, views, sequences, materialized views, foreign tables, partitioned tables. */
    private static function fixRelations(Connection $connection, string $fromRole, string $toRole): void
    {
        $verbByRelkind = [
            'r' => 'table', 'p' => 'table', 'f' => 'foreign table',
            'v' => 'view', 'm' => 'materialized view', 'S' => 'sequence',
        ];

        $rows = $connection->select(
            "select n.nspname as schema, c.relname as name, c.relkind as relkind
             from pg_class c
             join pg_namespace n on n.oid = c.relnamespace
             where c.relowner = (select oid from pg_roles where rolname = ?)
               and c.relkind in ('r', 'p', 'f', 'v', 'm', 'S')
               and ".self::schemaExclusion('n.nspname'),
            [$fromRole]
        );

        foreach ($rows as $row) {
            $verb = $verbByRelkind[$row->relkind] ?? null;

            if ($verb === null) {
                continue;
            }

            $connection->statement(sprintf(
                'alter %s %s.%s owner to %s',
                $verb,
                self::quoteIdentifier($row->schema),
                self::quoteIdentifier($row->name),
                self::quoteIdentifier($toRole),
            ));
        }
    }

    /** Functions, procedures and aggregates. */
    private static function fixRoutines(Connection $connection, string $fromRole, string $toRole): void
    {
        $verbByProkind = ['f' => 'function', 'p' => 'procedure', 'a' => 'aggregate', 'w' => 'function'];

        $rows = $connection->select(
            "select n.nspname as schema, p.proname as name,
                    pg_get_function_identity_arguments(p.oid) as args, p.prokind as prokind
             from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace
             where p.proowner = (select oid from pg_roles where rolname = ?)
               and ".self::schemaExclusion('n.nspname'),
            [$fromRole]
        );

        foreach ($rows as $row) {
            $verb = $verbByProkind[$row->prokind] ?? 'function';

            $connection->statement(sprintf(
                'alter %s %s.%s(%s) owner to %s',
                $verb,
                self::quoteIdentifier($row->schema),
                self::quoteIdentifier($row->name),
                $row->args,
                self::quoteIdentifier($toRole),
            ));
        }
    }

    /** Domains, enums and composite types (base/array/pseudo types aren't user-owned objects to begin with). */
    private static function fixTypes(Connection $connection, string $fromRole, string $toRole): void
    {
        $rows = $connection->select(
            "select n.nspname as schema, t.typname as name, t.typtype as typtype
             from pg_type t
             join pg_namespace n on n.oid = t.typnamespace
             where t.typowner = (select oid from pg_roles where rolname = ?)
               and t.typtype in ('d', 'e', 'c')
               and t.typname not like '\_%' escape '\\'
               and ".self::schemaExclusion('n.nspname'),
            [$fromRole]
        );

        foreach ($rows as $row) {
            $verb = $row->typtype === 'd' ? 'domain' : 'type';

            $connection->statement(sprintf(
                'alter %s %s.%s owner to %s',
                $verb,
                self::quoteIdentifier($row->schema),
                self::quoteIdentifier($row->name),
                self::quoteIdentifier($toRole),
            ));
        }
    }

    private static function schemaExclusion(string $column): string
    {
        $notIn = implode(', ', array_map(fn ($n) => "'{$n}'", self::IGNORED_SCHEMA_NAMES));
        $notLike = implode(' and ', array_map(fn ($p) => "{$column} not like '{$p}'", self::IGNORED_SCHEMA_PATTERNS));

        return "{$column} not in ({$notIn}) and {$notLike}";
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
