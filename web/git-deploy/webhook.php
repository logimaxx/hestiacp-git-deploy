<?php
/**
 * Panel-hosted webhook endpoint.
 * Lives under /usr/local/hestia/web/git-deploy/ after install.
 */
$_GET["user"] = isset($_GET["user"]) ? $_GET["user"] : (isset($_SERVER["GIT_DEPLOY_USER"]) ? $_SERVER["GIT_DEPLOY_USER"] : "");
$_GET["domain"] = isset($_GET["domain"]) ? $_GET["domain"] : (isset($_SERVER["GIT_DEPLOY_DOMAIN"]) ? $_SERVER["GIT_DEPLOY_DOMAIN"] : "");

$listener = __DIR__ . "/listener.php";
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
