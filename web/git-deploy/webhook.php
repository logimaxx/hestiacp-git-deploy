<?php
/**
 * Panel-hosted webhook endpoint.
 * URL: /git-deploy/webhook.php?user=...&domain=...
 */
declare(strict_types=1);

// Map query params into listener expectations
$_GET["user"] = $_GET["user"] ?? ($_SERVER["GIT_DEPLOY_USER"] ?? "");
$_GET["domain"] = $_GET["domain"] ?? ($_SERVER["GIT_DEPLOY_DOMAIN"] ?? "");

$listener = dirname(__DIR__, 2) . "/webhook/listener.php";
// When installed under /usr/local/hestia/web/git-deploy/, resolve via plugin path
if (!is_file($listener)) {
    $listener = "/usr/local/hestia/plugins/git-deploy/webhook/listener.php";
}
if (!is_file($listener)) {
    http_response_code(500);
    header("Content-Type: text/plain");
    echo "listener missing\n";
    exit;
}
require $listener;
