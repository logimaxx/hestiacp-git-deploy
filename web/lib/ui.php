<?php
/**
 * Shared helpers for Git Deploy UI.
 */

if (!function_exists("tohtml")) {
    function tohtml($str) {
        return htmlspecialchars((string) $str, ENT_QUOTES, "UTF-8");
    }
}

function git_deploy_plugin_root() {
    if (defined("GIT_DEPLOY_PLUGIN_ROOT")) {
        return (string) GIT_DEPLOY_PLUGIN_ROOT;
    }
    return "/usr/local/hestia/plugins/git-deploy";
}

function git_deploy_ui_base() {
    return "/edit/web/git-deploy";
}

function git_deploy_hestia_user() {
    if (!empty($_SESSION["look"])) {
        return (string) $_SESSION["look"];
    }
    return isset($_SESSION["user"]) ? (string) $_SESSION["user"] : "";
}

function git_deploy_bin($cmd) {
    return "/usr/local/hestia/bin/" . $cmd;
}

function git_deploy_run($cmd, $args = []) {
    $bin = "/usr/local/hestia/bin/" . $cmd;
    if (!is_file($bin) && !is_link($bin)) {
        return [
            "code" => 127,
            "output" => "Command not found: {$bin}. Re-run: sudo ./install.sh on the panel host.",
            "lines" => [],
        ];
    }
    if (defined("HESTIA_CMD")) {
        $full = HESTIA_CMD . $cmd;
    } else {
        $full = "sudo " . $bin;
    }
    foreach ($args as $a) {
        $full .= " " . escapeshellarg((string) $a);
    }
    $output = [];
    $code = 0;
    exec($full . " 2>&1", $output, $code);
    $text = implode("\n", $output);
    if ($code !== 0 && trim($text) === "") {
        $text = "Command failed (exit {$code}): {$cmd}. Check that hestiaweb can sudo /usr/local/hestia/bin/{$cmd} (see /etc/sudoers.d/hestiaweb).";
    }
    return [
        "code" => $code,
        "output" => $text,
        "lines" => $output,
    ];
}

function git_deploy_domain_allowed($user, $domain) {
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $user) || !preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
        return false;
    }
    if (defined("HESTIA_CMD")) {
        $output = [];
        $code = 0;
        exec(
            HESTIA_CMD . "v-list-web-domain " . escapeshellarg($user) . " " . escapeshellarg($domain) . " json",
            $output,
            $code
        );
        return $code === 0;
    }
    return is_dir("/home/" . $user . "/web/" . $domain . "/public_html");
}

function git_deploy_paths($user, $domain) {
    $base = "/home/" . $user . "/web/" . $domain . "/git-deploy";
    return [
        "base" => $base,
        "config" => $base . "/config.conf",
        "secrets" => $base . "/secrets.env",
        "status" => $base . "/status.json",
        "log" => $base . "/deploy.log",
        "pub" => $base . "/deploy_key.pub",
        "configured" => is_file($base . "/config.conf"),
    ];
}

function git_deploy_read_config($path) {
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
        "SETUP_DONE" => "no",
    ];
    if ($path === "" || !is_readable($path)) {
        return $out;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") {
            continue;
        }
        if (strpos($line, "=") === false) {
            continue;
        }
        $parts = explode("=", $line, 2);
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        $len = strlen($v);
        if ($len >= 2) {
            $q = $v[0];
            if (($q === '"' || $q === "'") && $v[$len - 1] === $q) {
                $v = stripcslashes(substr($v, 1, -1));
            }
        }
        $out[$k] = $v;
    }
    return $out;
}

function git_deploy_read_status($path) {
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
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function git_deploy_read_secrets_masked($path) {
    if (!is_readable($path)) {
        return "";
    }
    $lines = [];
    $file_lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($file_lines === false) {
        return "";
    }
    foreach ($file_lines as $line) {
        $trim = trim($line);
        if ($trim === "" || (isset($trim[0]) && $trim[0] === "#")) {
            $lines[] = $line;
            continue;
        }
        if (strpos($line, "=") === false) {
            continue;
        }
        $parts = explode("=", $line, 2);
        $lines[] = trim($parts[0]) . "=********";
    }
    return implode("\n", $lines);
}

function git_deploy_list_releases($user, $domain) {
    $dir = "/home/" . $user . "/web/" . $domain . "/git-deploy/releases";
    if (!is_dir($dir)) {
        return [];
    }
    $ids = [];
    $scan = scandir($dir);
    if ($scan === false) {
        return [];
    }
    foreach ($scan as $d) {
        if ($d === "." || $d === "..") {
            continue;
        }
        if (is_dir($dir . "/" . $d)) {
            $ids[] = $d;
        }
    }
    rsort($ids);
    return $ids;
}

function git_deploy_current_release($user, $domain) {
    $link = "/home/" . $user . "/web/" . $domain . "/git-deploy/current";
    if (!is_link($link)) {
        return "";
    }
    $target = realpath($link);
    return $target ? basename($target) : "";
}

function git_deploy_panel_host() {
    $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "panel.example.com";
    return "https://" . $host;
}

function git_deploy_webhook_url($user, $domain) {
    return git_deploy_panel_host() .
        "/git-deploy/webhook.php?" .
        http_build_query(["user" => $user, "domain" => $domain]);
}

/**
 * Parse REPO_URL into owner/repo and return a host settings URL for deploy keys, or "".
 * Supports git@host:owner/repo.git and https://host/owner/repo.git
 */
function git_deploy_key_settings_url($repo_url) {
    $repo_url = trim((string) $repo_url);
    if ($repo_url === "") {
        return "";
    }
    $host = "";
    $path = "";
    if (preg_match('#^git@([^:]+):(.+)$#', $repo_url, $m)) {
        $host = strtolower($m[1]);
        $path = $m[2];
    } elseif (preg_match('#^ssh://git@([^/]+)/(.+)$#', $repo_url, $m)) {
        $host = strtolower($m[1]);
        $path = $m[2];
    } elseif (preg_match('#^https?://([^/]+)/(.+)$#', $repo_url, $m)) {
        $host = strtolower($m[1]);
        $path = $m[2];
    } else {
        return "";
    }
    $path = preg_replace('#\.git$#', "", $path);
    $path = trim($path, "/");
    if ($path === "" || strpos($path, "/") === false) {
        return "";
    }
    if ($host === "github.com" || substr($host, -11) === ".github.com") {
        return "https://github.com/" . $path . "/settings/keys";
    }
    if ($host === "gitlab.com" || strpos($host, "gitlab") !== false) {
        return "https://" . $host . "/" . $path . "/-/settings/repository";
    }
    if ($host === "bitbucket.org") {
        return "https://bitbucket.org/" . $path . "/admin/access-keys/";
    }
    return "";
}

function git_deploy_setup_needed($cfg, $status) {
    if (($cfg["SETUP_DONE"] ?? "no") === "yes") {
        return false;
    }
    if (($status["last_status"] ?? "") === "success") {
        return false;
    }
    return true;
}

function git_deploy_tail_log($path, $lines = 80) {
    if (!is_readable($path)) {
        return "";
    }
    $content = file($path);
    if ($content === false) {
        return "";
    }
    return implode("", array_slice($content, -$lines));
}
