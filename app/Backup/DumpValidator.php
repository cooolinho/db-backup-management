<?php

namespace App\Backup;

use App\Backup\Exceptions\InvalidDumpException;

/**
 * Rejects a plain-SQL dump that could act outside the tmp database it's
 * about to be imported into - a CREATE/DROP/ALTER DATABASE or a database
 * switch would run against whatever database name it names, not
 * necessarily the tmp one DatabaseDriver::import() connects to. This is
 * the main defense for uploaded/foreign dumps; our own dump() never
 * produces any of these (see AbstractMySqlFamilyDriver/PostgresDriver).
 *
 * Reads line by line rather than loading the whole file, since a dump can
 * be gigabytes.
 */
class DumpValidator
{
    private const MYSQL_FAMILY_PATTERN = '/^\s*(CREATE|DROP)\s+(DATABASE|SCHEMA)\b|^\s*USE\s+\S/i';

    private const POSTGRES_PATTERN = '/^\s*(\\\\connect\b|\\\\c\s)|^\s*(CREATE|DROP|ALTER)\s+DATABASE\b/i';

    /** Content that identifies a dump as belonging to the OTHER engine family, for a clearer error than a generic SQL failure. */
    private const POSTGRES_SIGNATURE = '/^\s*(SET\s+search_path|CREATE\s+EXTENSION|COPY\s+\S+\s+\(.*\)\s+FROM\s+stdin)/i';

    // Not anchored to the line start: ENGINE=/AUTO_INCREMENT= are trailing
    // clauses on a CREATE TABLE line, not statements of their own.
    private const MYSQL_FAMILY_SIGNATURE = '/^\s*\/\*!\d+|\bENGINE\s*=|\bAUTO_INCREMENT\s*=/i';

    /** @param 'mysql'|'mariadb'|'pgsql' $engine */
    public function validate(string $sqlFilePath, string $engine): void
    {
        $handle = fopen($sqlFilePath, 'r');

        if ($handle === false) {
            throw new InvalidDumpException("Cannot read dump file [{$sqlFilePath}].");
        }

        $forbidden = $engine === 'pgsql' ? self::POSTGRES_PATTERN : self::MYSQL_FAMILY_PATTERN;
        $otherEngineSignature = $engine === 'pgsql' ? self::MYSQL_FAMILY_SIGNATURE : self::POSTGRES_SIGNATURE;
        $otherEngineLabel = $engine === 'pgsql' ? 'a MySQL/MariaDB' : 'a PostgreSQL';

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if (preg_match($forbidden, $line)) {
                    throw new InvalidDumpException(sprintf(
                        'Line %d looks like it changes or switches databases (%s), which is not allowed in an uploaded dump: %s',
                        $lineNumber,
                        trim($line),
                        $this->engineLabel($engine),
                    ));
                }

                if (preg_match($otherEngineSignature, $line)) {
                    throw new InvalidDumpException(
                        "This looks like {$otherEngineLabel} dump, but the target database is ".$this->engineLabel($engine).'.'
                    );
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function engineLabel(string $engine): string
    {
        return match ($engine) {
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql' => 'PostgreSQL',
            default => $engine,
        };
    }
}
