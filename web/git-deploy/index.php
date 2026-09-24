<?php
/**
 * Git Deploy — Hestia native UI controller
 * URL: /git-deploy/?domain=example.com
 */

declare(strict_types=1);

$TAB = "web";

include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";
require_once dirname(__DIR__) . "/lib/ui.php";

$user_plain = git_deploy_hestia_user();
$user = quoteshellarg($user_plain);

$v_domain = isset($_GET["domain"]) ? (string) $_GET["domain"] : (string) ($_POST["domain"] ?? "");
$v_domain = preg_replace('/[^a-zA-Z0-9._-]/', '', $v_domain) ?? "";

if ($v_domain === "" || !git_deploy_domain_allowed($user_plain, $v_domain)) {
    $_SESSION["error_msg"] = _("Domain not found.");
    header("Location: /list/web/");
    exit();
}

// JSON status poll
if (isset($_GET["ajax"]) && $_GET["ajax"] === "status") {
    header("Content-Type: application/json; charset=utf-8");
    $paths = git_deploy_paths($user_plain, $v_domain);
    echo json_encode(git_deploy_read_status($paths["status"]));
    exit();
}

$flash_secret = "";
$paths = git_deploy_paths($user_plain, $v_domain);

if (!empty($_POST["token"])) {
    verify_csrf($_POST);

    $action = (string) ($_POST["action"] ?? "");

    if ($action === "enable" && empty($_SESSION["error_msg"])) {
        $repo = trim((string) ($_POST["repo_url"] ?? ""));
        $branch = trim((string) ($_POST["branch"] ?? "main")) ?: "main";
        if ($repo === "") {
            $_SESSION["error_msg"] = _("Repository URL is required.");
        } else {
            $r = git_deploy_run("v-plugin-git-add", [$user_plain, $v_domain, $repo, $branch]);
            if ($r["code"] !== 0) {
                $_SESSION["error_msg"] = $r["output"] ?: _("Failed to enable Git Deploy.");
            } else {
                if (preg_match('/WEBHOOK_SECRET:\s*(\S+)/', $r["output"], $m)) {
                    $flash_secret = $m[1];
                    $_SESSION["git_deploy_flash_secret"] = $flash_secret;
                }
                $_SESSION["ok_msg"] = _("Git Deploy enabled. Add the deploy key to your Git host.");
            }
        }
    }

    if ($action === "save" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $pairs = [
            "REPO_URL=" . trim((string) ($_POST["repo_url"] ?? "")),
            "BRANCH=" . (trim((string) ($_POST["branch"] ?? "main")) ?: "main"),
            "AUTH_METHOD=" . ((($_POST["auth_method"] ?? "") === "https") ? "https" : "ssh"),
            "INSTALL_CMD=" . (string) ($_POST["install_cmd"] ?? ""),
            "OUTPUT_DIR=" . (trim((string) ($_POST["output_dir"] ?? "dist")) ?: "dist"),
            "EXCLUDE=" . trim((string) ($_POST["exclude"] ?? ".git,.env,.env.*,node_modules")),
            "AUTO_DEPLOY=" . (!empty($_POST["auto_deploy"]) ? "yes" : "no"),
            "TIMEOUT_SECONDS=" . (preg_match('/^\d+$/', (string) ($_POST["timeout_seconds"] ?? "")) ? $_POST["timeout_seconds"] : "300"),
            "MAX_RELEASES=" . (preg_match('/^\d+$/', (string) ($_POST["max_releases"] ?? "")) ? $_POST["max_releases"] : "5"),
            "HEALTHCHECK_URL=" . trim((string) ($_POST["healthcheck_url"] ?? "")),
            "HEALTHCHECK_EXPECT=" . (preg_match('/^\d+$/', (string) ($_POST["healthcheck_expect"] ?? "")) ? $_POST["healthcheck_expect"] : "200"),
            "GIT_SUBMODULES=" . (!empty($_POST["git_submodules"]) ? "yes" : "no"),
        ];
        $r = git_deploy_run("v-plugin-git-set", array_merge([$user_plain, $v_domain], $pairs));
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] ?: _("Failed to save configuration.");
        } else {
            // secrets — only overwrite when user replaced masked values
            $secrets_raw = (string) ($_POST["secrets_env"] ?? "");
            if (str_contains($secrets_raw, "=********")) {
                $_SESSION["ok_msg"] = _("Changes have been saved.");
            } else {
                $tmp = tempnam(sys_get_temp_dir(), "gds");
                file_put_contents($tmp, $secrets_raw);
                chmod($tmp, 0600);
                $r2 = git_deploy_run("v-plugin-git-secrets-write", [$user_plain, $v_domain, $tmp]);
                @unlink($tmp);
                if ($r2["code"] !== 0) {
                    $_SESSION["error_msg"] = $r2["output"] ?: _("Config saved but secrets write failed.");
                } else {
                    $_SESSION["ok_msg"] = _("Changes have been saved.");
                }
            }
        }
    }

    if ($action === "deploy" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $force = !empty($_POST["force"]) ? "force" : "";
        $args = [$user_plain, $v_domain];
        if ($force !== "") {
            $args[] = "force";
        }
        // async so UI returns quickly
        $bin = git_deploy_bin("v-plugin-git-deploy");
        $cmd = escapeshellarg($bin) . " " . escapeshellarg($user_plain) . " " . escapeshellarg($v_domain);
        if ($force !== "") {
            $cmd .= " force";
        }
        if (is_executable("/usr/bin/systemd-run")) {
            exec("sudo systemd-run --uid=root --collect " . $cmd . " >/dev/null 2>&1 &");
        } else {
            exec("sudo " . $cmd . " >/dev/null 2>&1 &");
        }
        $_SESSION["ok_msg"] = _("Deploy started.");
    }

    if ($action === "rollback" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $release = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_POST["release_id"] ?? "")) ?? "";
        $args = [$user_plain, $v_domain];
        if ($release !== "") {
            $args[] = $release;
        }
        $r = git_deploy_run("v-plugin-git-rollback", $args);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] ?: _("Rollback failed.");
        } else {
            $_SESSION["ok_msg"] = _("Rollback completed.");
        }
    }

    if ($action === "regen_key" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-key-generate", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] ?: _("Key generation failed.");
        } else {
            $_SESSION["ok_msg"] = _("Deploy key regenerated. Update the key on your Git host.");
        }
    }

    if ($action === "regen_secret" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-secret-regenerate", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] ?: _("Secret regeneration failed.");
        } else {
            if (preg_match('/WEBHOOK_SECRET:\s*(\S+)/', $r["output"], $m)) {
                $_SESSION["git_deploy_flash_secret"] = $m[1];
            }
            $_SESSION["ok_msg"] = _("Webhook secret regenerated. Copy it now — it will not be shown again.");
        }
    }

    if ($action === "disable" && $paths["configured"] && empty($_SESSION["error_msg"])) {
        $r = git_deploy_run("v-plugin-git-delete", [$user_plain, $v_domain]);
        if ($r["code"] !== 0) {
            $_SESSION["error_msg"] = $r["output"] ?: _("Failed to disable Git Deploy.");
        } else {
            $_SESSION["ok_msg"] = _("Git Deploy disabled. public_html was left intact.");
        }
    }

    header("Location: /git-deploy/?domain=" . rawurlencode($v_domain));
    exit();
}

// Reload state for render
$paths = git_deploy_paths($user_plain, $v_domain);
$cfg = $paths["configured"] ? git_deploy_read_config($paths["config"]) : git_deploy_read_config("");
$status = git_deploy_read_status($paths["status"]);
$releases = git_deploy_list_releases($user_plain, $v_domain);
$current_release = git_deploy_current_release($user_plain, $v_domain);
$pubkey = $paths["configured"] && is_readable($paths["pub"]) ? trim((string) file_get_contents($paths["pub"])) : "";
$log_tail = $paths["configured"] ? git_deploy_tail_log($paths["log"]) : "";
$secrets_masked = $paths["configured"] ? git_deploy_read_secrets_masked($paths["secrets"]) : "";
$webhook_url = git_deploy_webhook_url($user_plain, $v_domain);
$flash_secret = (string) ($_SESSION["git_deploy_flash_secret"] ?? "");
unset($_SESSION["git_deploy_flash_secret"]);

$v_configured = $paths["configured"];

render_page($user, $TAB, "git_deploy");
