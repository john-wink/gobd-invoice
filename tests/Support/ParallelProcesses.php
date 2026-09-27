<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs a unit of work in several forked OS processes that start at the same
 * instant, each on its own database connection. This is what two office
 * workers pressing "festschreiben" at the same moment look like to the
 * database — a single PHP process can never produce real row-lock contention.
 */
final class ParallelProcesses
{
    /**
     * @param  Closure(int): void  $work  receives the zero-based process index
     * @return list<string> one entry per failed process, empty when all succeeded
     */
    public static function run(int $count, Closure $work): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gobd-parallel-'.bin2hex(random_bytes(8));

        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $barrier = $directory.DIRECTORY_SEPARATOR.'start';

        // A forked child must never share the parent's open database socket.
        DB::disconnect();

        $children = [];

        for ($index = 0; $index < $count; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                self::runChild($index, $barrier, $directory, $work);
            }

            $children[$index] = $pid;
        }

        touch($barrier);

        $failures = [];

        foreach ($children as $index => $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                continue;
            }

            $report = $directory.DIRECTORY_SEPARATOR.'failure-'.$index;
            $failures[] = "process {$index}: ".(is_file($report) ? (string) file_get_contents($report) : 'exited abnormally');
        }

        array_map(unlink(...), glob($directory.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($directory);

        return $failures;
    }

    /**
     * @param  Closure(int): void  $work
     */
    private static function runChild(int $index, string $barrier, string $directory, Closure $work): never
    {
        $succeeded = false;

        try {
            while (! is_file($barrier)) {
                clearstatcache(true, $barrier);
                usleep(500);
            }

            $work($index);
            $succeeded = true;
        } catch (Throwable $throwable) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'failure-'.$index, $throwable::class.': '.$throwable->getMessage());
        }

        // Replace the process image instead of returning into the test runner,
        // so the child never runs PHPUnit's shutdown handlers or prints output.
        pcntl_exec('/bin/sh', ['-c', $succeeded ? 'exit 0' : 'exit 1']);

        exit(1);
    }
}
