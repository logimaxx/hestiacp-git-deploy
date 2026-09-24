<?php
/**
 * Pluginable custom page entry (?p=git-deploy&domain=...)
 * Redirects to the native UI path for a single implementation.
 */
$domain = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_GET["domain"] ?? "")) ?? "";
if ($domain === "") {
    header("Location: /list/web/");
    exit;
}
header("Location: /edit/web/git-deploy/?domain=" . rawurlencode($domain));
exit;
