#!/usr/bin/env php
<?php
/**
 * Hestia Git Deploy — webhook listener (Phase 2)
 *
 * Deploy behind the panel (or any PHP-FPM vhost). Example nginx location:
 *
 *   location ~ ^/git-deploy/([a-zA-Z0-9._-]+)/([a-zA-Z0-9._-]+)$ {
 *       include fastcgi_params;
 *       fastcgi_param SCRIPT_FILENAME /usr/local/hestia/plugins/git-deploy/webhook/listener.php;
 *       fastcgi_param GIT_DEPLOY_USER $1;
 *       fastcgi_param GIT_DEPLOY_DOMAIN $2;
 *       fastcgi_pass unix:/run/hestia-php.sock;  # adjust
 *   }
 *
 * Or call with query: ?user=alice&domain=example.com
 *
 * Validates GitHub (X-Hub-Signature-256) or GitLab (X-Gitlab-Token),
 * filters branch, responds 200 immediately, starts async deploy.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo $msg;
    exit;
}

function read_config(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function timing_safe_eq(string $a, string $b): bool {
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    return hash_equals($a, $b);
}

$user = $_SERVER['GIT_DEPLOY_USER'] ?? ($_GET['user'] ?? '');
$domain = $_SERVER['GIT_DEPLOY_DOMAIN'] ?? ($_GET['domain'] ?? '');

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $user) || !preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
    fail(400, "bad user/domain\n");
}

$configPath = "/home/{$user}/web/{$domain}/git-deploy/config.conf";
$cfg = read_config($configPath);
if ($cfg === [] || empty($cfg['WEBHOOK_SECRET'])) {
    fail(404, "not configured\n");
}

$secret = $cfg['WEBHOOK_SECRET'];
$branch = $cfg['BRANCH'] ?? 'main';
$auto = strtolower($cfg['AUTO_DEPLOY'] ?? 'yes');

$raw = file_get_contents('php://input') ?: '';
$sig256 = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$gitlabToken = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';

$ok = false;
if ($sig256 !== '') {
    // GitHub: sha256=<hex>
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $ok = timing_safe_eq($expected, $sig256);
} elseif ($gitlabToken !== '') {
    $ok = timing_safe_eq($secret, $gitlabToken);
}

if (!$ok) {
    fail(403, "forbidden\n");
}

if ($auto !== 'yes' && $auto !== 'true' && $auto !== '1') {
    http_response_code(200);
    echo "auto_deploy disabled\n";
    exit;
}

// Branch filter
$payload = json_decode($raw, true);
$ref = is_array($payload) ? ($payload['ref'] ?? '') : '';
$expectedRef = 'refs/heads/' . $branch;
if ($ref !== '' && $ref !== $expectedRef) {
    http_response_code(200);
    echo "ignored ref {$ref}\n";
    exit;
}

$bin = '/usr/local/hestia/bin/v-plugin-git-deploy';
if (!is_executable($bin)) {
    // fallback to plugin path
    $bin = '/usr/local/hestia/plugins/git-deploy/bin/v-plugin-git-deploy';
}

$cmd = escapeshellarg($bin) . ' ' . escapeshellarg($user) . ' ' . escapeshellarg($domain);
$started = false;

if (is_executable('/usr/bin/systemd-run')) {
    // transient unit; detach immediately
    $full = sprintf(
        'systemd-run --uid=root --property=Type=oneshot --collect %s >/dev/null 2>&1 &',
        $cmd
    );
    exec($full);
    $started = true;
} elseif (is_executable('/usr/bin/at')) {
    $full = sprintf('echo %s | at now >/dev/null 2>&1', escapeshellarg($cmd));
    exec($full);
    $started = true;
} else {
    // last resort — background shell (still returns quickly)
    exec($cmd . ' >/dev/null 2>&1 &');
    $started = true;
}

http_response_code(200);
echo $started ? "accepted\n" : "error\n";
