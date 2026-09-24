<?php
/**
 * Git Deploy — Hestia native UI controller
 * URL: /edit/web/git-deploy/?domain=example.com
 *
 * Must live under /usr/local/hestia/web/ (copied by install.sh).
 */

use function Hestiacp\quoteshellarg\quoteshellarg;

ob_start();
$TAB = "WEB";

// Soft-init for policies.php (included by main.php before $panel exists)
$panel = [];

include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// Prefer local lib next to this file (install copies web/git-deploy/lib/ui.php here)
$lib = __DIR__ . "/lib/ui.php";
if (!is_file($lib)) {
    http_response_code(500);
    echo "Git Deploy UI library missing at " . htmlspecialchars($lib) . ". Re-run: sudo ./install.sh";
    exit;
}
require_once $lib;

// $user / $user_plain are set by main.php (look/impersonation aware)
$user_plain = git_deploy_hestia_user();
if ($user_plain === "" && !empty($GLOBALS["user_plain"])) {
    $user_plain = (string) $GLOBALS["user_plain"];
}

$v_domain = isset($_GET["domain"]) ? (string) $_GET["domain"] : (string) ($_POST["domain"] ?? "");
$v_domain = preg_replace('/[^a-zA-Z0-9._-]/', '', $v_domain);
if ($v_domain === null || $v_domain === "") {
    $_SESSION["error_msg"] = _("Domain not found.");
    header("Location: /list/web/");
    exit();
}

if (!git_deploy_domain_allowed($user_plain, $v_domain)) {
    $_SESSION["error_msg"] = _("Domain not found.");
    header("Location: /list/web/");
    exit();
}

$ui_base = git_deploy_ui_base();

// JSON status poll
if (isset($_GET["ajax"]) && $_GET["ajax"] === "status") {
    header("Content-Type: application/json; charset=utf-8");
    $paths = git_deploy_paths($user_plain, $v_domain);
    echo json_encode(git_deploy_read_status($paths["status"]));
    exit();
}

$paths = git_deploy_paths($user_plain, $v_domain);

if (!empty($_POST["token"])) {
    verify_csrf($_POST);

    $action = (string) ($_POST["action"] ?? "");

    if ($action === "enable" && empty($_SESSION["error_msg"])) {
        $repo = trim((string) ($_POST["repo_url"] ?? ""));
        $branch = trim((string) ($_POST["branch"] ?? "main"));
        if ($branch === "") {
            $branch = "main";
        }
        if ($repo === "") {
            $_SESSION["error_msg"] = _("Repository URL is required.");
        } else {
            $r = git_deploy_run("v-plugin-git-add", [$user_plain, $v_domain, $repo, $branch]);
            if ($r["code"] !== 0) {
                $detail = trim((string) $r["output"]);
                $_SESSION["error_msg"] = $detail !== ""
                    ? $detail
                    : _("Failed to enable Git Deploy.");
            } else {
                if (preg_match('/WEBHOOK_SECRET:\s*(\S+)/', $r["output"], $m)) {
                    $_SESSION["git_deploy_flash_secret"] = $m[1];
                }
                if (strpos($r["output"], "already configured") !== false) {
                    $_SESSION["ok_msg"] = _("Git Deploy was already enabled. Continuing setup — add the deploy key if you have not yet.");
                } else {
                    $_SESSION["ok_msg"] = _("Git Deploy enabled. Follow the setup steps below — start by adding the deploy key to your Git host.");
                }
            }
        }
    }

    if ($action === "save_setup" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $output_dir = trim((string) ($_POST["output_dir"] ?? "dist"));
        if ($output_dir === "") {
            $output_dir = "dist";
        }
        $pairs = [
            "INSTALL_CMD=" . (string) ($_POST["install_cmd"] ?? ""),
            "OUTPUT_DIR=" . $output_dir,
        ];
        $r = git_deploy_run("v-plugin-git-set", array_merge([$user_plain, $v_domain], $pairs));
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Failed to save build settings.");
        } else {
            $_SESSION["ok_msg"] = _("Build settings saved.");
        }
    }

    if ($action === "test_connection" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-test", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Connection test failed. Add the deploy key on your Git host and try again.");
        } else {
            $commit = "";
            if (preg_match('/COMMIT:\s*(\S+)/', $r["output"], $m)) {
                $commit = $m[1];
            }
            $_SESSION["ok_msg"] = $commit !== ""
                ? sprintf(_("Connection OK — remote branch reachable (commit %s)."), $commit)
                : _("Connection OK — remote branch reachable.");
        }
    }

    if ($action === "skip_setup" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-set", [$user_plain, $v_domain, "SETUP_DONE=yes"]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Failed to skip setup.");
        } else {
            $_SESSION["ok_msg"] = _("Setup skipped. You can change settings anytime.");
        }
    }

    if ($action === "save" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $branch = trim((string) ($_POST["branch"] ?? "main"));
        if ($branch === "") {
            $branch = "main";
        }
        $output_dir = trim((string) ($_POST["output_dir"] ?? "dist"));
        if ($output_dir === "") {
            $output_dir = "dist";
        }
        $pairs = [
            "REPO_URL=" . trim((string) ($_POST["repo_url"] ?? "")),
            "BRANCH=" . $branch,
            "AUTH_METHOD=" . ((($_POST["auth_method"] ?? "") === "https") ? "https" : "ssh"),
            "INSTALL_CMD=" . (string) ($_POST["install_cmd"] ?? ""),
            "OUTPUT_DIR=" . $output_dir,
            "EXCLUDE=" . trim((string) ($_POST["exclude"] ?? ".git,.env,.env.*,node_modules")),
            "AUTO_DEPLOY=" . (!empty($_POST["auto_deploy"]) ? "yes" : "no"),
            "TIMEOUT_SECONDS=" . (preg_match('/^\d+$/', (string) ($_POST["timeout_seconds"] ?? "")) ? (string) $_POST["timeout_seconds"] : "300"),
            "MAX_RELEASES=" . (preg_match('/^\d+$/', (string) ($_POST["max_releases"] ?? "")) ? (string) $_POST["max_releases"] : "5"),
            "HEALTHCHECK_URL=" . trim((string) ($_POST["healthcheck_url"] ?? "")),
            "HEALTHCHECK_EXPECT=" . (preg_match('/^\d+$/', (string) ($_POST["healthcheck_expect"] ?? "")) ? (string) $_POST["healthcheck_expect"] : "200"),
            "GIT_SUBMODULES=" . (!empty($_POST["git_submodules"]) ? "yes" : "no"),
        ];
        $r = git_deploy_run("v-plugin-git-set", array_merge([$user_plain, $v_domain], $pairs));
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Failed to save configuration.");
        } else {
            $secrets_raw = (string) ($_POST["secrets_env"] ?? "");
            if (strpos($secrets_raw, "=********") !== false) {
                $_SESSION["ok_msg"] = _("Changes have been saved.");
            } else {
                $tmp = tempnam(sys_get_temp_dir(), "gds");
                file_put_contents($tmp, $secrets_raw);
                chmod($tmp, 0600);
                $r2 = git_deploy_run("v-plugin-git-secrets-write", [$user_plain, $v_domain, $tmp]);
                @unlink($tmp);
                if ($r2["code"] !== 0) {
                    $_SESSION["error_msg"] = $r2["output"] !== "" ? $r2["output"] : _("Config saved but secrets write failed.");
                } else {
                    $_SESSION["ok_msg"] = _("Changes have been saved.");
                }
            }
        }
    }

    if ($action === "deploy" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $args = [$user_plain, $v_domain];
        if (!empty($_POST["force"])) {
            $args[] = "force";
        }
        // Must stay under /usr/local/hestia/bin/* (hestiaweb sudoers). Do NOT call systemd-run from PHP.
        $r = git_deploy_run("v-plugin-git-deploy-async", $args);
        if ($r["code"] !== 0) {
            $detail = trim((string) $r["output"]);
            $_SESSION["error_msg"] = $detail !== ""
                ? $detail
                : _("Failed to start deploy.");
        } else {
            $_SESSION["ok_msg"] = _("Deploy started.");
        }
    }

    if ($action === "rollback" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $release = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_POST["release_id"] ?? ""));
        $args = [$user_plain, $v_domain];
        if (!empty($release)) {
            $args[] = $release;
        }
        $r = git_deploy_run("v-plugin-git-rollback", $args);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Rollback failed.");
        } else {
            $_SESSION["ok_msg"] = _("Rollback completed.");
        }
    }

    if ($action === "regen_key" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-key-generate", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Key generation failed.");
        } else {
            $_SESSION["ok_msg"] = _("Deploy key regenerated. Update the key on your Git host.");
        }
    }

    if ($action === "regen_secret" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-secret-regenerate", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Secret regeneration failed.");
        } else {
            if (preg_match('/WEBHOOK_SECRET:\s*(\S+)/', $r["output"], $m)) {
                $_SESSION["git_deploy_flash_secret"] = $m[1];
            }
            $_SESSION["ok_msg"] = _("Webhook secret regenerated. Copy it now — it will not be shown again.");
        }
    }

    if ($action === "disable" && !empty($paths["configured"]) && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-delete", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] !== "" ? $r["output"] : _("Failed to disable Git Deploy.");
        } else {
            $_SESSION["ok_msg"] = _("Git Deploy disabled. public_html was left intact.");
        }
    }

    header("Location: " . $ui_base . "/?domain=" . rawurlencode($v_domain));
    exit();
}

// State for template
$paths = git_deploy_paths($user_plain, $v_domain);
$cfg = !empty($paths["configured"]) ? git_deploy_read_config($paths["config"]) : git_deploy_read_config("");
$status = git_deploy_read_status($paths["status"]);
$releases = git_deploy_list_releases($user_plain, $v_domain);
$current_release = git_deploy_current_release($user_plain, $v_domain);
$pubkey = "";
if (!empty($paths["configured"]) && is_readable($paths["pub"])) {
    $pubkey = trim((string) file_get_contents($paths["pub"]));
}
$log_tail = !empty($paths["configured"]) ? git_deploy_tail_log($paths["log"]) : "";
$secrets_masked = !empty($paths["configured"]) ? git_deploy_read_secrets_masked($paths["secrets"]) : "";
$webhook_url = git_deploy_webhook_url($user_plain, $v_domain);
$flash_secret = isset($_SESSION["git_deploy_flash_secret"]) ? (string) $_SESSION["git_deploy_flash_secret"] : "";
unset($_SESSION["git_deploy_flash_secret"]);
$v_configured = !empty($paths["configured"]);
$v_setup = $v_configured && git_deploy_setup_needed($cfg, $status);
$key_settings_url = $v_configured ? git_deploy_key_settings_url($cfg["REPO_URL"] ?? "") : "";

// Ensure $panel exists before footer/policies consumers (Hestia expects it from top_panel)
render_page($user, $TAB, "git_deploy");

$_SESSION["back"] = $_SERVER["REQUEST_URI"];
