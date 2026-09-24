<?php
/**
 * Pluginable integration for Git Deploy.
 * Method names map to HCPP hooks automatically via HCPP_Hooks.
 */

declare(strict_types=1);

class Git_Deploy_Plugin extends HCPP_Hooks {
    /**
     * Add "Git Deploy" button on Edit Web toolbar.
     */
    public function hcpp_edit_web_xpath($xpath) {
        $domain = $_GET["domain"] ?? "";
        if ($domain === "" || !is_object($xpath)) {
            return $xpath;
        }
        $toolbar = $xpath->query("//div[contains(@class,'toolbar-buttons')]")->item(1);
        if (!$toolbar) {
            $toolbar = $xpath->query("//div[contains(@class,'toolbar-buttons')]")->item(0);
        }
        if (!$toolbar) {
            return $xpath;
        }
        $a = $xpath->document->createElement("a");
        $a->setAttribute("href", "/git-deploy/?domain=" . rawurlencode((string) $domain));
        $a->setAttribute("class", "button button-secondary");
        $a->setAttribute("title", "Git Deploy");
        $icon = $xpath->document->createElement("i");
        $icon->setAttribute("class", "fas fa-code-branch icon-green");
        $a->appendChild($icon);
        $a->appendChild($xpath->document->createTextNode(" Git Deploy"));
        $toolbar->insertBefore($a, $toolbar->firstChild);
        return $xpath;
    }

    /**
     * Add per-domain Git Deploy action on Web list.
     */
    public function hcpp_list_web_xpath($xpath) {
        if (!is_object($xpath)) {
            return $xpath;
        }
        $rows = $xpath->query("//div[contains(@class,'units-table-row')][@data-unit]");
        if (!$rows || $rows->length === 0) {
            $edits = $xpath->query("//a[contains(@href,'/edit/web/?')]");
            foreach ($edits as $edit) {
                $href = $edit->getAttribute("href");
                if (!preg_match('/[?&]domain=([^&]+)/', $href, $m)) {
                    continue;
                }
                $domain = urldecode($m[1]);
                $li = $edit->parentNode;
                if (!$li || strtolower($li->nodeName) !== "li") {
                    continue;
                }
                $ul = $li->parentNode;
                if ($ul) {
                    $this->append_list_action($xpath, $ul, $domain);
                }
            }
            return $xpath;
        }
        foreach ($rows as $row) {
            $domain = $row->getAttribute("data-unit");
            $ul = $xpath->query(".//ul[contains(@class,'units-table-row-actions')]", $row)->item(0);
            if ($ul && $domain !== "") {
                $this->append_list_action($xpath, $ul, $domain);
            }
        }
        return $xpath;
    }

    private function append_list_action($xpath, $ul, string $domain): void {
        foreach ($ul->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && str_contains($child->textContent ?? "", "Git Deploy")) {
                return;
            }
        }
        $li = $xpath->document->createElement("li");
        $li->setAttribute("class", "units-table-row-action");
        $a = $xpath->document->createElement("a");
        $a->setAttribute("href", "/git-deploy/?domain=" . rawurlencode($domain));
        $a->setAttribute("title", "Git Deploy: " . $domain);
        $icon = $xpath->document->createElement("i");
        $icon->setAttribute("class", "fas fa-code-branch icon-green");
        $a->appendChild($icon);
        $span = $xpath->document->createElement("span", "Git Deploy");
        $span->setAttribute("class", "u-hide-desktop");
        $a->appendChild($span);
        $li->appendChild($a);
        $ul->appendChild($li);
    }

    /**
     * Cleanup git-deploy artefacts when domain is deleted.
     */
    public function v_delete_web_domain($args) {
        $user = $args[0] ?? "";
        $domain = $args[1] ?? "";
        if ($user && $domain) {
            $script = dirname(__FILE__) . "/hooks/cleanup-domain.sh";
            if (is_executable($script)) {
                exec(escapeshellarg($script) . " " . escapeshellarg($user) . " " . escapeshellarg($domain));
            }
        }
        return $args;
    }
}

global $hcpp;
if (isset($hcpp) && class_exists("HCPP_Hooks")) {
    $hcpp->register_install_script(dirname(__FILE__) . "/install.sh");
    $hcpp->register_uninstall_script(dirname(__FILE__) . "/uninstall.sh");
    $hcpp->add_custom_page("git-deploy", dirname(__FILE__) . "/pages/git-deploy.php");
    $hcpp->register_plugin(Git_Deploy_Plugin::class);
}
