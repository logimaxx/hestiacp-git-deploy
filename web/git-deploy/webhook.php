<?php
/**
 * Panel-hosted webhook endpoint.
 * URL: /git-deploy/webhook.php?user=...&domain=...
 *      /edit/web/git-deploy/webhook.php?user=...&domain=...
 */
declare(strict_types=1);

$_GET["user"] = $_GET["user"] ?? ($_SERVER["GIT_DEPLOY_USER"] ?? "");
$_GET["domain"] = $_GET["domain"] ?? ($_SERVER["GIT_DEPLOY_DOMAIN"] ?? "");

$root = defined("GIT_DEPLOY_PLUGIN_ROOT")
    ? (string) GIT_DEPLOY_PLUGIN_ROOT
    : dirname(__DIR__, 2);

$listener = $root . "/webhook/listener.php";
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
