<?php

namespace Snorky22\ApiForge\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * The router script of the built-in server boots the interface from the environment only (the application is not
 * booted): it must work before any class is loaded.
 */
final class RouterTest extends TestCase
{
    private const ROUTER = __DIR__.'/../../src/Web/public/index.php';

    /**
     * @param array<string, string> $env
     */
    private function router(array $env): string
    {
        $process = proc_open([\PHP_BINARY, self::ROUTER], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
        $output = (string) stream_get_contents($pipes[1]);
        proc_close($process);

        return $output;
    }

    public function testExplainsHowToStartItWithoutSettings(): void
    {
        self::assertStringContainsString('api-forge:serve', $this->router([]));
    }

    public function testBootsTheInterfaceFromTheSettings(): void
    {
        $project = sys_get_temp_dir();
        $settings = json_encode([
            'autoload' => __DIR__.'/../../vendor/autoload.php',
            'projectDir' => $project,
            'descriptorDir' => $project.'/config/api',
            'namespace' => 'App\\Api',
            'sourceDir' => 'src/Api',
            'configDir' => 'config/packages',
            'console' => ['php', 'bin/console'],
            'secret' => 's',
            'hosts' => ['127.0.0.1:8765'],
        ], \JSON_THROW_ON_ERROR);

        // No Host header on the command line: the interface answers, and refuses
        self::assertStringContainsString('Unexpected Host header', $this->router(['API_FORGE_WEB' => $settings]));
    }
}
