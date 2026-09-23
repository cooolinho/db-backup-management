<?php

namespace App\Backup;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Packs a driver's raw .sql dump into the configured backup format, and
 * unpacks any supported format back into plain SQL for DatabaseDriver::import().
 */
class ArchiveFormatter
{
    public const FORMATS = ['sql', 'sql.gz', 'zip', 'tar.gz'];

    /**
     * @param string $rawSqlPath Local plain-SQL file, as written by a DatabaseDriver::dump() call.
     * @param string $destinationWithoutExtension Full path, without the format's extension.
     * @param string $entryName Base filename used inside zip/tar archives, e.g. "app.sql".
     * @return string The final archive path actually written (destination + the format's extension).
     */
    public function pack(string $rawSqlPath, string $destinationWithoutExtension, string $format, string $entryName): string
    {
        return match ($format) {
            'sql' => $this->packSql($rawSqlPath, $destinationWithoutExtension),
            'sql.gz' => $this->packGzip($rawSqlPath, $destinationWithoutExtension),
            'zip' => $this->packZip($rawSqlPath, $destinationWithoutExtension, $entryName),
            'tar.gz' => $this->packTarGz($rawSqlPath, $destinationWithoutExtension, $entryName),
            default => throw new InvalidArgumentException("Unsupported backup format [{$format}]."),
        };
    }

    /**
     * Extracts $archivePath (any format in self::FORMATS, detected from its
     * extension) into a plain .sql file at $destinationSqlPath.
     */
    public function unpack(string $archivePath, string $destinationSqlPath): void
    {
        $format = $this->detectFormat($archivePath);

        match ($format) {
            'sql' => $this->unpackSql($archivePath, $destinationSqlPath),
            'sql.gz' => $this->unpackGzip($archivePath, $destinationSqlPath),
            'zip' => $this->unpackZip($archivePath, $destinationSqlPath),
            'tar.gz' => $this->unpackTarGz($archivePath, $destinationSqlPath),
        };
    }

    public function detectFormat(string $path): string
    {
        foreach (self::FORMATS as $format) {
            if (str_ends_with($path, '.'.$format)) {
                return $format;
            }
        }

        throw new InvalidArgumentException(
            "Cannot detect backup format from [{$path}]. Expected one of: ".implode(', ', self::FORMATS)
        );
    }

    private function packSql(string $rawSqlPath, string $destination): string
    {
        $target = "{$destination}.sql";
        $this->moveOrCopy($rawSqlPath, $target);

        return $target;
    }

    private function packGzip(string $rawSqlPath, string $destination): string
    {
        $target = "{$destination}.sql.gz";
        $this->streamCopy($rawSqlPath, 'compress.zlib://'.$target);
        @unlink($rawSqlPath);

        return $target;
    }

    private function packZip(string $rawSqlPath, string $destination, string $entryName): string
    {
        $target = "{$destination}.zip";

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create zip archive at [{$target}].");
        }
        $zip->addFile($rawSqlPath, $entryName);
        $zip->close();
        @unlink($rawSqlPath);

        return $target;
    }

    private function packTarGz(string $rawSqlPath, string $destination, string $entryName): string
    {
        $target = "{$destination}.tar.gz";
        $stagingDir = dirname($rawSqlPath).'/tar_'.bin2hex(random_bytes(6));
        mkdir($stagingDir);
        $stagedFile = "{$stagingDir}/{$entryName}";
        rename($rawSqlPath, $stagedFile);

        $this->runProcess(['tar', '-czf', $target, '-C', $stagingDir, $entryName]);

        @unlink($stagedFile);
        @rmdir($stagingDir);

        return $target;
    }

    private function unpackSql(string $archivePath, string $destination): void
    {
        $this->moveOrCopy($archivePath, $destination, keepSource: true);
    }

    private function unpackGzip(string $archivePath, string $destination): void
    {
        $this->streamCopy('compress.zlib://'.$archivePath, $destination);
    }

    private function unpackZip(string $archivePath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException("Cannot open zip archive at [{$archivePath}].");
        }

        if ($zip->numFiles !== 1) {
            $zip->close();
            throw new RuntimeException("Expected exactly one file inside [{$archivePath}], found {$zip->numFiles}.");
        }

        $contents = $zip->getFromIndex(0);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException("Cannot read the contents of [{$archivePath}].");
        }

        file_put_contents($destination, $contents);
    }

    private function unpackTarGz(string $archivePath, string $destination): void
    {
        $stagingDir = dirname($archivePath).'/tar_'.bin2hex(random_bytes(6));
        mkdir($stagingDir);

        $this->runProcess(['tar', '-xzf', $archivePath, '-C', $stagingDir]);

        $entries = array_values(array_diff(scandir($stagingDir) ?: [], ['.', '..']));

        if (count($entries) !== 1) {
            $this->removeDirectory($stagingDir);
            throw new RuntimeException("Expected exactly one file inside [{$archivePath}], found ".count($entries).'.');
        }

        rename("{$stagingDir}/{$entries[0]}", $destination);
        $this->removeDirectory($stagingDir);
    }

    private function moveOrCopy(string $from, string $to, bool $keepSource = false): void
    {
        if ($keepSource) {
            copy($from, $to);

            return;
        }

        // rename() fails across filesystem boundaries (e.g. a tmp dir on a
        // different mount than BACKUP_PATH); fall back to copy+unlink.
        if (! @rename($from, $to)) {
            copy($from, $to);
            @unlink($from);
        }
    }

    private function streamCopy(string $from, string $to): void
    {
        $in = fopen($from, 'r');
        $out = fopen($to, 'w');

        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot open stream for [{$from}] -> [{$to}].");
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function runProcess(array $command): void
    {
        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink("{$dir}/{$entry}");
        }
        @rmdir($dir);
    }
}
