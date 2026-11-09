<?php

namespace Snorky22\ApiForge\Web;

/**
 * Plain PHP templates in a layout. Every value shown goes through e().
 */
final class View
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = [], string $title = 'api-forge'): string
    {
        $content = $this->include($template, $vars);

        return $this->include('layout', ['content' => $content, 'title' => $title] + $vars);
    }

    /**
     * A template alone, without the layout (a fragment the page's script inserts).
     *
     * @param array<string, mixed> $vars
     */
    public function fragment(string $template, array $vars = []): string
    {
        return $this->include($template, $vars);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function include(string $template, array $vars): string
    {
        $file = $this->directory.'/'.$template.'.php';
        if (!is_file($file)) {
            throw new \LogicException(\sprintf('Unknown template "%s".', $template));
        }

        return (function (string $__file, array $__vars): string {
            extract($__vars, \EXTR_SKIP);
            $e = View::e(...);
            $partial = fn (string $template, array $values = []): string => $this->include($template, $values + $__vars);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }

            return (string) ob_get_clean();
        })($file, $vars);
    }
}
