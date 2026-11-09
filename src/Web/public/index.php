<?php

/*
 * Router of the PHP built-in server started by api-forge:serve. It does not boot the application: the project's
 * autoloader and the settings come from the environment.
 */

use Snorky22\ApiForge\Web\Request;
use Snorky22\ApiForge\Web\WebApp;
use Snorky22\ApiForge\Web\WebConfig;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
if (1 === preg_match('#^/assets/(?:ace/)?[a-z0-9_.-]+$#i', $path) && is_file(__DIR__.$path)) {
    return false; // a stylesheet, a script: the server sends the file
}

// WebConfig::ENV_NAME: the autoloader is not loaded yet
$settings = json_decode((string) getenv('API_FORGE_WEB'), true);
if (!\is_array($settings) || !isset($settings['autoload']) || !is_file($settings['autoload'])) {
    http_response_code(500);
    echo 'api-forge web: start it with "bin/console api-forge:serve".';

    return true;
}

require $settings['autoload'];
chdir($settings['projectDir']);

(new WebApp(WebConfig::fromArray($settings)))->handle(Request::fromGlobals())->send();

return true;
