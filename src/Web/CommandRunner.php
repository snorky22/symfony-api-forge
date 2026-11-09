<?php

namespace Snorky22\ApiForge\Web;

/**
 * Runs a console command of the project and returns its output (the doctor needs the application, which the
 * web server does not boot).
 */
class CommandRunner
{
    /**
     * @param list<string> $command
     *
     * @return array{int, string} exit code, output without colors
     */
    public function run(array $command, string $cwd, int $timeout = 60): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
        if (!\is_resource($process)) {
            return [1, 'Cannot start the command.'];
        }
        stream_set_blocking($pipes[1], false);

        $output = '';
        $start = microtime(true);
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            if (microtime(true) - $start > $timeout) {
                proc_terminate($process);
                $output .= \sprintf("\n[stopped after %d seconds]", $timeout);
                break;
            }
            usleep(50000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        return [$code, (string) preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
