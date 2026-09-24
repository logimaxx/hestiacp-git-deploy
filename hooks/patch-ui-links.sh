#!/usr/bin/env bash
# Inject "Git Deploy" links into Hestia panel templates (no Pluginable required).
# Idempotent.

set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
EDIT_TPL="${HESTIA}/web/templates/pages/edit_web.php"
LIST_TPL="${HESTIA}/web/templates/pages/list_web.php"
MARKER="git-deploy-ui-link"

patch_edit_web() {
	local f="$EDIT_TPL"
	[[ -f "$f" ]] || {
		echo "Warning: $f not found — skip edit_web link" >&2
		return 0
	}
	if grep -q "$MARKER" "$f"; then
		echo "edit_web.php already has Git Deploy link"
		return 0
	fi
	[[ -f "${f}.bak-git-deploy" ]] || cp -a "$f" "${f}.bak-git-deploy"

	python3 - "$f" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
marker = "git-deploy-ui-link"
if marker in text:
    raise SystemExit(0)

block = '''
			<!-- git-deploy-ui-link -->
			<a href="/edit/web/git-deploy/?<?= tohtml(http_build_query(["domain" => $v_domain])) ?>" class="button button-secondary" title="<?= tohtml( _("Git Deploy")) ?>">
				<i class="fas fa-code-branch icon-green"></i><?= tohtml( _("Git Deploy")) ?>
			</a>
'''

# Insert before Save button in toolbar
needle = '\t\t\t<button type="submit" class="button" form="main-form">'
if needle not in text:
    # try alternate whitespace
    needle = '<button type="submit" class="button" form="main-form">'
    if needle not in text:
        raise SystemExit(f"Could not find Save button in {path}")
    text = text.replace(needle, block + "\t\t\t" + needle, 1)
else:
    text = text.replace(needle, block + needle, 1)

path.write_text(text)
print(f"Patched {path}")
PY
}

patch_list_web() {
	local f="$LIST_TPL"
	[[ -f "$f" ]] || {
		echo "Warning: $f not found — skip list_web link" >&2
		return 0
	}
	if grep -q "$MARKER" "$f"; then
		echo "list_web.php already has Git Deploy link"
		return 0
	fi
	[[ -f "${f}.bak-git-deploy" ]] || cp -a "$f" "${f}.bak-git-deploy"

	python3 - "$f" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
marker = "git-deploy-ui-link"
if marker in text:
    raise SystemExit(0)

block = '''
								<!-- git-deploy-ui-link -->
								<li class="units-table-row-action" data-key-action="href">
									<a
										class="units-table-row-action-link"
										href="/edit/web/git-deploy/?<?= tohtml(http_build_query(["domain" => $key])) ?>"
										title="<?= tohtml( _("Git Deploy")) ?>"
									>
										<i class="fas fa-code-branch icon-green"></i>
										<span class="u-hide-desktop"><?= tohtml( _("Git Deploy")) ?></span>
									</a>
								</li>
'''

# Insert after Edit Domain action block (after the edit </li>)
needle = '''href="/edit/web/?<?= tohtml(http_build_query(["domain" => $key, "token" => $_SESSION["token"]])) ?>"
										title="<?= tohtml( _("Edit Domain")) ?>"
									>
										<i class="fas fa-pencil icon-orange"></i>
										<span class="u-hide-desktop"><?= tohtml( _("Edit Domain")) ?></span>
									</a>
								</li>'''

if needle not in text:
    # looser match: after first Edit Domain </li> following pencil icon
    import re
    m = re.search(
        r'(fa-pencil icon-orange.*?</li>)',
        text,
        flags=re.S,
    )
    if not m:
        raise SystemExit(f"Could not find Edit Domain action in {path}")
    insert_at = m.end(1)
    text = text[:insert_at] + "\n" + block + text[insert_at:]
else:
    text = text.replace(needle, needle + "\n" + block, 1)

path.write_text(text)
print(f"Patched {path}")
PY
}

patch_edit_web
patch_list_web
echo "OK — Git Deploy links injected into Hestia UI"
