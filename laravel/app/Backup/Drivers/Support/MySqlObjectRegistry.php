<?php

namespace App\Backup\Drivers\Support;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Captures/drops/recreates MySQL/MariaDB views, triggers, routines and
 * events for AbstractMySqlFamilyDriver::swap(). Kept separate purely to
 * keep swap() itself readable; not meant to be reused outside it.
 *
 * RENAME TABLE moves tables between schemas, but views/triggers/routines/
 * events belong to the schema itself and are never carried along - they
 * have to be captured, dropped and recreated by hand. SHOW CREATE for all
 * four stores same-schema references unqualified (verified empirically
 * against MariaDB 11.4/MySQL 8.4: `CREATE VIEW v2 AS SELECT * FROM
 * swaptest.t1` comes back as `SELECT * FROM \`t1\`` - the explicit
 * schema-qualification a user may have typed is not preserved). That
 * means no text rewriting is needed at all: every method here takes an
 * already-connected-to-the-right-database Connection and replays the
 * captured DDL verbatim; the connection's own database context is what
 * makes unqualified references resolve correctly.
 */
class MySqlObjectRegistry
{
    /**
     * @return array{views: list<array>, triggers: list<array>, routines: list<array>, events: list<array>}
     */
    public static function capture(Connection $connection, string $database): array
    {
        return [
            'views' => self::captureViews($connection, $database),
            'triggers' => self::captureTriggers($connection, $database),
            'routines' => self::captureRoutines($connection, $database),
            'events' => self::captureEvents($connection, $database),
        ];
    }

    public static function dropTriggers(Connection $connection, array $captured): void
    {
        foreach ($captured['triggers'] as $trigger) {
            $connection->statement('drop trigger '.self::quoteIdentifier($trigger['name']));
        }
    }

    /** Drops views/routines/events (not triggers - see dropTriggers(), called separately before the table rename). */
    public static function dropViewsRoutinesEvents(Connection $connection, array $captured): void
    {
        foreach ($captured['events'] as $event) {
            $connection->statement('drop event '.self::quoteIdentifier($event['name']));
        }
        foreach ($captured['routines'] as $routine) {
            $connection->statement('drop '.$routine['type'].' '.self::quoteIdentifier($routine['name']));
        }
        foreach ($captured['views'] as $view) {
            $connection->statement('drop view '.self::quoteIdentifier($view['name']));
        }
    }

    /** Recreates everything captured (routines/events/triggers in one pass, views with a dependency-order retry). */
    public static function recreateAll(Connection $connection, array $captured): void
    {
        foreach ($captured['routines'] as $routine) {
            self::applySessionContext($connection, $routine);
            $connection->statement($routine['ddl']);
        }

        foreach ($captured['events'] as $event) {
            self::applySessionContext($connection, $event);
            $connection->statement($event['ddl']);
        }

        self::recreateViewsWithRetry($connection, $captured['views']);

        self::recreateTriggers($connection, $captured['triggers']);
    }

    public static function recreateTriggers(Connection $connection, array $triggers): void
    {
        foreach ($triggers as $trigger) {
            self::applySessionContext($connection, $trigger);
            $connection->statement($trigger['ddl']);
        }
    }

    /**
     * A view can select from another view captured in the same batch; the
     * order SHOW CREATE happened to return them in is not necessarily a
     * valid creation order. Retries whatever still fails after each full
     * pass, until a pass makes no progress at all.
     */
    private static function recreateViewsWithRetry(Connection $connection, array $views): void
    {
        $pending = $views;
        $lastError = null;

        while (count($pending) > 0) {
            $stillPending = [];
            $progressed = false;

            foreach ($pending as $view) {
                try {
                    self::applySessionContext($connection, $view);
                    $connection->statement($view['ddl']);
                    $progressed = true;
                } catch (Throwable $e) {
                    $stillPending[] = $view;
                    $lastError = $e;
                }
            }

            $pending = $stillPending;

            if (! $progressed) {
                break;
            }
        }

        if (count($pending) > 0) {
            $names = implode(', ', array_column($pending, 'name'));

            throw new RuntimeException(
                "Could not recreate view(s) [{$names}]: ".($lastError?->getMessage() ?? 'unknown error'),
                previous: $lastError,
            );
        }
    }

    /** @return list<array{name: string, charset: string, collation: string, ddl: string}> */
    private static function captureViews(Connection $connection, string $database): array
    {
        $names = $connection->select(
            'select table_name as name from information_schema.views where table_schema = ?',
            [$database]
        );

        return array_map(function ($row) use ($connection) {
            $show = (array) $connection->selectOne('show create view '.self::quoteIdentifier($row->name));

            return [
                'name' => $row->name,
                'charset' => $show['character_set_client'],
                'collation' => $show['collation_connection'],
                'ddl' => $show['Create View'],
            ];
        }, $names);
    }

    /** @return list<array{name: string, sqlMode: string, charset: string, collation: string, ddl: string}> */
    private static function captureTriggers(Connection $connection, string $database): array
    {
        $names = $connection->select(
            'select trigger_name as name from information_schema.triggers where trigger_schema = ?',
            [$database]
        );

        return array_map(function ($row) use ($connection) {
            $show = (array) $connection->selectOne('show create trigger '.self::quoteIdentifier($row->name));

            return [
                'name' => $row->name,
                'sqlMode' => $show['sql_mode'],
                'charset' => $show['character_set_client'],
                'collation' => $show['collation_connection'],
                'ddl' => $show['SQL Original Statement'],
            ];
        }, $names);
    }

    /** @return list<array{name: string, type: string, sqlMode: string, charset: string, collation: string, ddl: string}> */
    private static function captureRoutines(Connection $connection, string $database): array
    {
        $rows = $connection->select(
            'select routine_name as name, lower(routine_type) as type
             from information_schema.routines where routine_schema = ?',
            [$database]
        );

        return array_map(function ($row) use ($connection) {
            $show = (array) $connection->selectOne(
                'show create '.$row->type.' '.self::quoteIdentifier($row->name)
            );
            $ddlKey = $row->type === 'procedure' ? 'Create Procedure' : 'Create Function';

            return [
                'name' => $row->name,
                'type' => $row->type,
                'sqlMode' => $show['sql_mode'],
                'charset' => $show['character_set_client'],
                'collation' => $show['collation_connection'],
                'ddl' => $show[$ddlKey],
            ];
        }, $rows);
    }

    /** @return list<array{name: string, sqlMode: string, timeZone: string, charset: string, collation: string, ddl: string}> */
    private static function captureEvents(Connection $connection, string $database): array
    {
        $names = $connection->select(
            'select event_name as name from information_schema.events where event_schema = ?',
            [$database]
        );

        return array_map(function ($row) use ($connection) {
            $show = (array) $connection->selectOne('show create event '.self::quoteIdentifier($row->name));

            return [
                'name' => $row->name,
                'sqlMode' => $show['sql_mode'],
                'timeZone' => $show['time_zone'],
                'charset' => $show['character_set_client'],
                'collation' => $show['collation_connection'],
                'ddl' => $show['Create Event'],
            ];
        }, $names);
    }

    private static function applySessionContext(Connection $connection, array $captured): void
    {
        $vars = [
            'character_set_client' => $captured['charset'] ?? null,
            'collation_connection' => $captured['collation'] ?? null,
            'sql_mode' => $captured['sqlMode'] ?? null,
            'time_zone' => $captured['timeZone'] ?? null,
        ];

        foreach ($vars as $variable => $value) {
            if ($value !== null) {
                $connection->statement("set session {$variable} = ".self::quoteLiteral($value));
            }
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
