<?php

namespace Snorky22\ApiForge\Web;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The templates of the interface: Twig templates, looked for as Symfony looks for a bundle's templates, first in the
 * project (templates/bundles/ApiForgeBundle/, to override one) then in the bundle (templates/). Pages extend
 * layout.html.twig; values are escaped by Twig.
 */
final class View
{
    /** Where a project overrides the templates of the bundle, as for any Symfony bundle. */
    public const OVERRIDES = 'templates/bundles/ApiForgeBundle';

    private readonly Environment $twig;

    public function __construct(?string $projectDir = null)
    {
        $paths = [];
        if (null !== $projectDir && is_dir($projectDir.'/'.self::OVERRIDES)) {
            $paths[] = $projectDir.'/'.self::OVERRIDES;
        }
        $paths[] = \dirname(__DIR__, 2).'/templates';
        $this->twig = new Environment(new FilesystemLoader($paths), ['autoescape' => 'html', 'strict_variables' => false, 'cache' => false]);
        $this->twig->addFilter(new TwigFilter('highlight', NoteFormatter::highlight(...), ['is_safe' => ['html']]));
        $this->twig->addFilter(new TwigFilter('role', NoteFormatter::roleOfFile(...)));
        $this->twig->addFunction(new TwigFunction('role', NoteFormatter::roleOfFile(...)));
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = [], string $title = 'api-forge'): string
    {
        // A page extends the layout itself
        return $this->twig->render($template.'.html.twig', ['title' => $title] + $vars);
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
        return $this->twig->render($template.'.html.twig', $vars);
    }
}
