<!-- Begin toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back js-button-back" href="/edit/web/?domain=<?= tohtml($v_domain) ?>">
				<i class="fas fa-arrow-left icon-blue"></i><?= tohtml(_("Back")) ?>
			</a>
			<?php if (!empty($v_setup)) { ?>
				<form method="post" class="u-inline">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="skip_setup">
					<button type="submit" class="button button-secondary">
						<?= tohtml(_("Skip to full settings")) ?>
					</button>
				</form>
			<?php } ?>
		</div>
		<div class="toolbar-buttons">
			<?php if (!empty($v_configured) && empty($v_setup)) { ?>
				<form method="post" class="u-inline" onsubmit="return confirm('<?= tohtml(_("Start deploy now?")) ?>');">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="deploy">
					<button type="submit" class="button button-secondary" <?= ($status["state"] ?? "") === "running" ? "disabled" : "" ?>>
						<i class="fas fa-rocket icon-green"></i><?= tohtml(_("Deploy now")) ?>
					</button>
				</form>
				<form method="post" class="u-inline">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="deploy">
					<input type="hidden" name="force" value="1">
					<button type="submit" class="button button-secondary" title="<?= tohtml(_("Force (skip debounce)")) ?>">
						<i class="fas fa-bolt icon-orange"></i><?= tohtml(_("Force")) ?>
					</button>
				</form>
				<button type="submit" class="button" form="git-deploy-form">
					<i class="fas fa-floppy-disk icon-purple"></i><?= tohtml(_("Save")) ?>
				</button>
			<?php } ?>
		</div>
	</div>
</div>
<!-- End toolbar -->

<div class="container">
	<div class="form-container">
		<h1 class="u-mb10"><?= tohtml(_("Git Deploy")) ?> — <?= tohtml($v_domain) ?></h1>
		<?php show_alert_message($_SESSION); ?>

		<?php if (!empty($flash_secret)) { ?>
			<div class="u-mb20 inline-alert inline-alert-warning">
				<strong><?= tohtml(_("Webhook secret (copy now)")) ?>:</strong>
				<code id="gd-flash-secret"><?= tohtml($flash_secret) ?></code>
				<button type="button" class="u-unstyled-button u-ml5" onclick="navigator.clipboard.writeText(document.getElementById('gd-flash-secret').textContent)">
					<i class="fas fa-copy"></i>
				</button>
			</div>
		<?php } ?>

		<?php if (empty($v_configured)) { ?>
			<p class="u-mb10"><?= tohtml(_("Connect this domain to a Git repository for automated builds and deploys.")) ?></p>
			<p class="u-mb20 hint"><?= tohtml(_("Next: we generate a deploy key → you add it to GitHub/GitLab → test the connection → deploy.")) ?></p>
			<form method="post" id="git-enable-form">
				<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
				<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
				<input type="hidden" name="action" value="enable">
				<div class="u-mb10">
					<label for="repo_url" class="form-label"><?= tohtml(_("Repository URL (SSH)")) ?></label>
					<input type="text" class="form-control" name="repo_url" id="repo_url" placeholder="git@github.com:org/repo.git" required>
					<small class="hint"><?= tohtml(_("Use an SSH URL. After enable you will add a read-only deploy key on the Git host.")) ?></small>
				</div>
				<div class="u-mb20">
					<label for="branch" class="form-label"><?= tohtml(_("Branch")) ?></label>
					<input type="text" class="form-control" name="branch" id="branch" value="main">
				</div>
				<button type="submit" class="button">
					<i class="fas fa-plug icon-green"></i><?= tohtml(_("Enable Git Deploy")) ?>
				</button>
			</form>

		<?php } elseif (!empty($v_setup)) { ?>
			<p class="u-mb20"><?= tohtml(_("Finish these steps to connect the repository. You can skip anytime and use full settings.")) ?></p>

			<!-- Step 1 -->
			<div class="u-mb30">
				<h2 class="u-mb10">1. <?= tohtml(_("Add the deploy key")) ?></h2>
				<p class="u-mb10 hint"><?= tohtml(_("Copy this public key and add it as a read-only Deploy Key in your repository settings.")) ?></p>
				<textarea class="form-control u-min-height100" id="gd-pubkey" readonly><?= tohtml($pubkey) ?></textarea>
				<button type="button" class="button button-secondary u-mt10" onclick="navigator.clipboard.writeText(document.getElementById('gd-pubkey').value)">
					<i class="fas fa-copy"></i><?= tohtml(_("Copy public key")) ?>
				</button>
				<?php if (!empty($key_settings_url)) { ?>
					<a class="button button-secondary u-mt10" href="<?= tohtml($key_settings_url) ?>" target="_blank" rel="noopener noreferrer">
						<i class="fas fa-external-link"></i><?= tohtml(_("Open deploy key settings")) ?>
					</a>
				<?php } ?>
				<ul class="u-mt15 hint">
					<li><?= tohtml(_("GitHub: Settings → Deploy keys → Add deploy key (Allow write access: off)")) ?></li>
					<li><?= tohtml(_("GitLab: Settings → Repository → Deploy keys")) ?></li>
				</ul>
			</div>

			<!-- Step 2 -->
			<div class="u-mb30">
				<h2 class="u-mb10">2. <?= tohtml(_("Test connection")) ?></h2>
				<p class="u-mb10 hint"><?= tohtml(_("Verifies that this server can reach the repository with the deploy key.")) ?></p>
				<form method="post">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="test_connection">
					<button type="submit" class="button button-secondary">
						<i class="fas fa-plug"></i><?= tohtml(_("Test connection")) ?>
					</button>
				</form>
			</div>

			<!-- Step 3 -->
			<div class="u-mb30">
				<h2 class="u-mb10">3. <?= tohtml(_("Build settings")) ?></h2>
				<p class="u-mb10 hint"><?= tohtml(_("Optional. Leave the install command empty for a static site. Output directory . publishes the repository root.")) ?></p>
				<form method="post">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="save_setup">
					<div class="u-mb10">
						<label for="install_cmd" class="form-label"><?= tohtml(_("Install command")) ?></label>
						<input type="text" class="form-control" name="install_cmd" id="install_cmd" value="<?= tohtml($cfg["INSTALL_CMD"] ?? "") ?>" placeholder="npm ci && npm run build">
					</div>
					<div class="u-mb15">
						<label for="output_dir" class="form-label"><?= tohtml(_("Output directory")) ?></label>
						<input type="text" class="form-control" name="output_dir" id="output_dir" value="<?= tohtml($cfg["OUTPUT_DIR"] ?? "dist") ?>" placeholder=".">
						<small class="hint"><?= tohtml(_("Use . to copy the repository into public_html. dist is only for a build output folder.")) ?></small>
					</div>
					<button type="submit" class="button button-secondary">
						<i class="fas fa-floppy-disk"></i><?= tohtml(_("Save build settings")) ?>
					</button>
				</form>
			</div>

			<!-- Step 4 -->
			<div class="u-mb20">
				<h2 class="u-mb10">4. <?= tohtml(_("First deploy")) ?></h2>
				<p class="u-mb10 hint"><?= tohtml(_("Runs clone → build → publish. After a successful deploy you will get the full settings page.")) ?></p>
				<form method="post" onsubmit="return confirm('<?= tohtml(_("Start first deploy now?")) ?>');">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="deploy">
					<button type="submit" class="button" <?= ($status["state"] ?? "") === "running" ? "disabled" : "" ?>>
						<i class="fas fa-rocket icon-green"></i><?= tohtml(_("Deploy now")) ?>
					</button>
				</form>
				<?php if (($status["state"] ?? "") === "running") { ?>
					<p class="u-mt10" id="gd-setup-running"><?= tohtml(_("Deploy in progress…")) ?></p>
					<script>
					(function () {
						var domain = <?= json_encode($v_domain) ?>;
						function poll() {
							fetch(<?= json_encode($ui_base . "/") ?> + '?domain=' + encodeURIComponent(domain) + '&ajax=status', { credentials: 'same-origin' })
								.then(function (r) { return r.json(); })
								.then(function (s) {
									if (s.state === 'running') setTimeout(poll, 2000);
									else location.reload();
								})
								.catch(function () { setTimeout(poll, 3000); });
						}
						poll();
					})();
					</script>
				<?php } ?>
			</div>

		<?php } else { ?>

			<!-- Status -->
			<div class="u-mb20" id="gd-status-box" data-state="<?= tohtml($status["state"] ?? "idle") ?>">
				<h2 class="u-mb10"><?= tohtml(_("Status")) ?></h2>
				<div class="u-mb5"><strong><?= tohtml(_("State")) ?>:</strong> <span id="gd-state"><?= tohtml($status["state"] ?? "idle") ?></span></div>
				<div class="u-mb5"><strong><?= tohtml(_("Last status")) ?>:</strong> <span id="gd-last-status"><?= tohtml($status["last_status"] ?? "") ?></span></div>
				<div class="u-mb5"><strong><?= tohtml(_("Commit")) ?>:</strong> <span id="gd-last-commit"><?= tohtml($status["last_commit"] ?? "") ?></span></div>
				<div class="u-mb5"><strong><?= tohtml(_("Release")) ?>:</strong> <span id="gd-last-release"><?= tohtml($status["last_release"] ?? "") ?></span></div>
				<div class="u-mb5"><strong><?= tohtml(_("Message")) ?>:</strong> <span id="gd-message"><?= tohtml($status["message"] ?? "") ?></span></div>
			</div>

			<form method="post" id="git-deploy-form">
				<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
				<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
				<input type="hidden" name="action" value="save">

				<h2 class="u-mb10"><?= tohtml(_("Repository")) ?></h2>
				<div class="u-mb10">
					<label for="repo_url" class="form-label"><?= tohtml(_("Repository URL")) ?></label>
					<input type="text" class="form-control" name="repo_url" id="repo_url" value="<?= tohtml($cfg["REPO_URL"] ?? "") ?>">
				</div>
				<div class="u-mb10">
					<label for="branch" class="form-label"><?= tohtml(_("Branch")) ?></label>
					<input type="text" class="form-control" name="branch" id="branch" value="<?= tohtml($cfg["BRANCH"] ?? "main") ?>">
				</div>
				<div class="u-mb20">
					<label for="auth_method" class="form-label"><?= tohtml(_("Auth method")) ?></label>
					<select class="form-select" name="auth_method" id="auth_method">
						<option value="ssh" <?= ($cfg["AUTH_METHOD"] ?? "") === "ssh" ? "selected" : "" ?>>SSH deploy key</option>
						<option value="https" <?= ($cfg["AUTH_METHOD"] ?? "") === "https" ? "selected" : "" ?>>HTTPS + token</option>
					</select>
				</div>

				<h2 class="u-mb10"><?= tohtml(_("Deploy key")) ?></h2>
				<div class="u-mb10">
					<label class="form-label"><?= tohtml(_("Public key (add as Deploy Key, read-only)")) ?></label>
					<textarea class="form-control u-min-height100" id="gd-pubkey" readonly><?= tohtml($pubkey) ?></textarea>
					<button type="button" class="button button-secondary u-mt10" onclick="navigator.clipboard.writeText(document.getElementById('gd-pubkey').value)">
						<i class="fas fa-copy"></i><?= tohtml(_("Copy")) ?>
					</button>
					<?php if (!empty($key_settings_url)) { ?>
						<a class="button button-secondary u-mt10" href="<?= tohtml($key_settings_url) ?>" target="_blank" rel="noopener noreferrer">
							<i class="fas fa-external-link"></i><?= tohtml(_("Open settings")) ?>
						</a>
					<?php } ?>
				</div>

				<h2 class="u-mb10"><?= tohtml(_("Build")) ?></h2>
				<div class="u-mb10">
					<label for="install_cmd" class="form-label"><?= tohtml(_("Install command")) ?></label>
					<input type="text" class="form-control" name="install_cmd" id="install_cmd" value="<?= tohtml($cfg["INSTALL_CMD"] ?? "") ?>" placeholder="npm ci && npm run build">
				</div>
				<div class="u-mb10">
					<label for="output_dir" class="form-label"><?= tohtml(_("Output directory")) ?></label>
					<input type="text" class="form-control" name="output_dir" id="output_dir" value="<?= tohtml($cfg["OUTPUT_DIR"] ?? "dist") ?>" placeholder=".">
					<small class="hint"><?= tohtml(_("Use . to copy the repository into public_html. dist is only for a build output folder.")) ?></small>
					<?php if (($cfg["OUTPUT_DIR"] ?? "") === "." || ($cfg["OUTPUT_DIR"] ?? "") === "") { ?>
						<small class="hint"><?= tohtml(_("Warning: deploying repo root — ensure EXCLUDE covers .env and .git")) ?></small>
					<?php } ?>
				</div>
				<div class="u-mb10">
					<label for="exclude" class="form-label"><?= tohtml(_("Exclude patterns")) ?></label>
					<input type="text" class="form-control" name="exclude" id="exclude" value="<?= tohtml($cfg["EXCLUDE"] ?? "") ?>">
				</div>
				<div class="u-mb10">
					<label for="timeout_seconds" class="form-label"><?= tohtml(_("Timeout (seconds)")) ?></label>
					<input type="number" class="form-control" name="timeout_seconds" id="timeout_seconds" value="<?= tohtml($cfg["TIMEOUT_SECONDS"] ?? "300") ?>" min="30" max="3600">
				</div>
				<div class="u-mb10">
					<label for="max_releases" class="form-label"><?= tohtml(_("Max releases kept")) ?></label>
					<input type="number" class="form-control" name="max_releases" id="max_releases" value="<?= tohtml($cfg["MAX_RELEASES"] ?? "5") ?>" min="1" max="50">
				</div>
				<div class="form-check u-mb10">
					<input class="form-check-input" type="checkbox" name="git_submodules" id="git_submodules" value="yes" <?= ($cfg["GIT_SUBMODULES"] ?? "") === "yes" ? "checked" : "" ?>>
					<label for="git_submodules"><?= tohtml(_("Initialize Git submodules")) ?></label>
				</div>

				<h2 class="u-mb10"><?= tohtml(_("Healthcheck (optional)")) ?></h2>
				<div class="u-mb10">
					<label for="healthcheck_url" class="form-label"><?= tohtml(_("URL")) ?></label>
					<input type="text" class="form-control" name="healthcheck_url" id="healthcheck_url" value="<?= tohtml($cfg["HEALTHCHECK_URL"] ?? "") ?>" placeholder="http://127.0.0.1/">
				</div>
				<div class="u-mb20">
					<label for="healthcheck_expect" class="form-label"><?= tohtml(_("Expected HTTP code")) ?></label>
					<input type="number" class="form-control" name="healthcheck_expect" id="healthcheck_expect" value="<?= tohtml($cfg["HEALTHCHECK_EXPECT"] ?? "200") ?>">
				</div>

				<h2 class="u-mb10"><?= tohtml(_("Webhook")) ?></h2>
				<div class="form-check u-mb10">
					<input class="form-check-input" type="checkbox" name="auto_deploy" id="auto_deploy" value="yes" <?= ($cfg["AUTO_DEPLOY"] ?? "") === "yes" ? "checked" : "" ?>>
					<label for="auto_deploy"><?= tohtml(_("Auto-deploy on push")) ?></label>
				</div>
				<div class="u-mb10">
					<label class="form-label"><?= tohtml(_("Webhook URL")) ?></label>
					<input type="text" class="form-control" id="gd-webhook-url" value="<?= tohtml($webhook_url) ?>" readonly>
					<button type="button" class="button button-secondary u-mt10" onclick="navigator.clipboard.writeText(document.getElementById('gd-webhook-url').value)">
						<i class="fas fa-copy"></i><?= tohtml(_("Copy URL")) ?>
					</button>
				</div>
				<p class="u-mb20 hint"><?= tohtml(_("Secret is masked. Regenerate to obtain a new one (shown once).")) ?></p>

				<h2 class="u-mb10"><?= tohtml(_("Build secrets")) ?></h2>
				<p class="hint u-mb10"><?= tohtml(_("KEY=VALUE lines, build-only. Never deployed to public_html. Values are masked below — paste full file to replace.")) ?></p>
				<div class="u-mb20">
					<textarea class="form-control u-min-height100" name="secrets_env" id="secrets_env" placeholder="GIT_TOKEN=&#10;GIT_USERNAME=git"><?= tohtml($secrets_masked) ?></textarea>
				</div>
			</form>

			<!-- Side actions -->
			<div class="u-mb20">
				<form method="post" class="u-inline">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="test_connection">
					<button type="submit" class="button button-secondary"><?= tohtml(_("Test connection")) ?></button>
				</form>
				<form method="post" class="u-inline">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="regen_key">
					<button type="submit" class="button button-secondary"><?= tohtml(_("Regenerate deploy key")) ?></button>
				</form>
				<form method="post" class="u-inline">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="regen_secret">
					<button type="submit" class="button button-secondary"><?= tohtml(_("Regenerate webhook secret")) ?></button>
				</form>
			</div>

			<!-- Releases / rollback -->
			<h2 class="u-mb10"><?= tohtml(_("Releases")) ?></h2>
			<?php if (empty($releases)) { ?>
				<p class="u-mb20"><?= tohtml(_("No releases yet. Run a deploy first.")) ?></p>
			<?php } else { ?>
				<div class="units-table js-units-container u-mb20">
					<div class="units-table-header">
						<div class="units-table-cell"><?= tohtml(_("Release")) ?></div>
						<div class="units-table-cell"><?= tohtml(_("Actions")) ?></div>
					</div>
					<?php foreach ($releases as $rid) { ?>
						<div class="units-table-row">
							<div class="units-table-cell">
								<?= tohtml($rid) ?>
								<?php if ($rid === $current_release) { ?>
									<span class="badge badge-success"><?= tohtml(_("current")) ?></span>
								<?php } ?>
							</div>
							<div class="units-table-cell">
								<?php if ($rid !== $current_release) { ?>
									<form method="post" class="u-inline" onsubmit="return confirm('<?= tohtml(_("Rollback to this release?")) ?>');">
										<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
										<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
										<input type="hidden" name="action" value="rollback">
										<input type="hidden" name="release_id" value="<?= tohtml($rid) ?>">
										<button type="submit" class="button button-secondary button-danger-outline"><?= tohtml(_("Rollback")) ?></button>
									</form>
								<?php } ?>
							</div>
						</div>
					<?php } ?>
				</div>
				<form method="post" class="u-mb20" onsubmit="return confirm('<?= tohtml(_("Rollback to previous release?")) ?>');">
					<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
					<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
					<input type="hidden" name="action" value="rollback">
					<button type="submit" class="button button-secondary">
						<i class="fas fa-rotate-left"></i><?= tohtml(_("Rollback to previous")) ?>
					</button>
				</form>
			<?php } ?>

			<!-- Log -->
			<h2 class="u-mb10"><?= tohtml(_("Deploy log")) ?></h2>
			<textarea class="form-control u-min-height200 u-mb20" id="gd-log" readonly><?= tohtml($log_tail) ?></textarea>

			<!-- Disable -->
			<form method="post" onsubmit="return confirm('<?= tohtml(_("Disable Git Deploy? public_html will be kept.")) ?>');">
				<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
				<input type="hidden" name="domain" value="<?= tohtml($v_domain) ?>">
				<input type="hidden" name="action" value="disable">
				<button type="submit" class="button button-danger">
					<i class="fas fa-trash icon-red"></i><?= tohtml(_("Disable Git Deploy")) ?>
				</button>
			</form>

			<script>
			(function () {
				var box = document.getElementById('gd-status-box');
				if (!box) return;
				var domain = <?= json_encode($v_domain) ?>;
				function poll() {
					if (box.getAttribute('data-state') !== 'running') return;
					fetch(<?= json_encode($ui_base . "/") ?> + '?domain=' + encodeURIComponent(domain) + '&ajax=status', { credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (s) {
							box.setAttribute('data-state', s.state || 'idle');
							var map = {
								state: 'gd-state',
								last_status: 'gd-last-status',
								last_commit: 'gd-last-commit',
								last_release: 'gd-last-release',
								message: 'gd-message'
							};
							Object.keys(map).forEach(function (k) {
								var el = document.getElementById(map[k]);
								if (el) el.textContent = s[k] || '';
							});
							if (s.state === 'running') setTimeout(poll, 2000);
							else if (s.state === 'idle') location.reload();
						})
						.catch(function () { setTimeout(poll, 3000); });
				}
				if (box.getAttribute('data-state') === 'running') poll();
			})();
			</script>
		<?php } ?>
	</div>
</div>
