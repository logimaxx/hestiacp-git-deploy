<?php
/**
 * Shared helpers for Git Deploy UI (native Hestia + Pluginable).
 */

declare(strict_types=1);

if (!function_exists("tohtml")) {
    function tohtml($str) {
        return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

function git_deploy_plugin_root(): string {
    return dirname(__DIR__, 2);
}

function git_deploy_hestia_user(): string {
    if (!empty($_SESSION["look"])) {
        return (string) $_SESSION["look"];
    }
    return (string) ($_SESSION["user"] ?? "");
}

function git_deploy_bin(string $cmd): string {
    $hestia = "/usr/local/hestia/bin/" . $cmd;
    if (is_executable($hestia)) {
        return $hestia;
    }
    $local = git_deploy_plugin_root() . "/bin/" . $cmd;
    return $local;
}

function git_deploy_run(string $cmd, array $args = []): array {
    $parts = [escapeshellarg(git_deploy_bin($cmd))];
    foreach ($args as $a) {
        $parts[] = escapeshellarg((string) $a);
    }
    $line = implode(" ", $parts);
    $full = "sudo " . $line;
    // Prefer Hestia's sudo pattern when available
    if (defined("HESTIA_CMD")) {
        $full = HESTIA_CMD . $cmd;
        foreach ($args as $a) {
            $full .= " " . escapeshellarg((string) $a);
        }
    }
    $output = [];
    $code = 0;
    exec($full . " 2>&1", $output, $code);
    return [
        "code" => $code,
        "output" => implode("\n", $output),
        "lines" => $output,
    ];
}

function git_deploy_domain_allowed(string $user, string $domain): bool {
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $user) || !preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
        return false;
    }
    if (defined("HESTIA_CMD")) {
        exec(
            HESTIA_CMD . "v-list-web-domain " . escapeshellarg($user) . " " . escapeshellarg($domain) . " json",
            $output,
            $code
        );
        return $code === 0;
    }
    return is_dir("/home/{$user}/web/{$domain}/public_html");
}

function git_deploy_paths(string $user, string $domain): array {
    $base = "/home/{$user}/web/{$domain}/git-deploy";
    return [
        "base" => $base,
        "config" => "{$base}/config.conf",
        "secrets" => "{$base}/secrets.env",
        "status" => "{$base}/status.json",
        "log" => "{$base}/deploy.log",
        "pub" => "{$base}/deploy_key.pub",
        "configured" => is_file("{$base}/config.conf"),
    ];
}

function git_deploy_read_config(string $path): array {
    $out = [
        "REPO_URL" => "",
        "BRANCH" => "main",
        "AUTH_METHOD" => "ssh",
        "INSTALL_CMD" => "",
        "OUTPUT_DIR" => "dist",
        "EXCLUDE" => ".git,.env,.env.*,node_modules",
        "AUTO_DEPLOY" => "yes",
        "TIMEOUT_SECONDS" => "300",
        "MAX_RELEASES" => "5",
        "HEALTHCHECK_URL" => "",
        "HEALTHCHECK_EXPECT" => "200",
        "GIT_SUBMODULES" => "no",
        "LAST_DEPLOYED_COMMIT" => "",
        "WEBHOOK_SECRET" => "",
    ];
    if (!is_readable($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") {
            continue;
        }
        if (!str_contains($line, "=")) {
            continue;
        }
        [$k, $v] = explode("=", $line, 2);
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function git_deploy_read_status(string $path): array {
    $defaults = [
        "state" => "idle",
        "last_status" => "",
        "last_commit" => "",
        "last_release" => "",
        "started_at" => null,
        "finished_at" => null,
        "duration_seconds" => 0,
        "message" => "",
    ];
    if (!is_readable($path)) {
        return $defaults;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function git_deploy_read_secrets_masked(string $path): string {
    if (!is_readable($path)) {
        return "";
    }
    $lines = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trim = trim($line);
        if ($trim === "" || str_starts_with($trim, "#")) {
            $lines[] = $line;
            continue;
        }
        if (!str_contains($line, "=")) {
            continue;
        }
        [$k] = explode("=", $line, 2);
        $lines[] = trim($k) . "=********";
    }
    return implode("\n", $lines);
}

function git_deploy_list_releases(string $user, string $domain): array {
    $dir = "/home/{$user}/web/{$domain}/git-deploy/releases";
    if (!is_dir($dir)) {
        return [];
    }
    $ids = array_values(
        array_filter(scandir($dir) ?: [], static fn($d) => $d !== "." && $d !== ".." && is_dir("{$dir}/{$d}"))
    );
    rsort($ids);
    return $ids;
}

function git_deploy_current_release(string $user, string $domain): string {
    $link = "/home/{$user}/web/{$domain}/git-deploy/current";
    if (!is_link($link)) {
        return "";
    }
    $target = realpath($link);
    return $target ? basename($target) : "";
}

function git_deploy_panel_host(): string {
    $host = $_SERVER["HTTP_HOST"] ?? "panel.example.com";
    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "https";
    return $scheme . "://" . $host;
}

function git_deploy_webhook_url(string $user, string $domain): string {
    // Prefer panel query form; nginx may rewrite to listener.php
    return git_deploy_panel_host() .
        "/git-deploy/webhook.php?" .
        http_build_query(["user" => $user, "domain" => $domain]);
}

function git_deploy_tail_log(string $path, int $lines = 80): string {
    if (!is_readable($path)) {
        return "";
    }
    $content = file($path) ?: [];
    return implode("", array_slice($content, -$lines));
}
