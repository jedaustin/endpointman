<?php
/**
 * Extension Mapping: import of a mapping exported from another system (partial view).
 * Expects $p (the devices_page array) and $h (the escaper) from page.main.devices.php.
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$imp = $p['import'] ?? null;
?>
					<!-- ============ Migration from the commercial Endpoint Manager ============ -->
					<h4><?= _("Migrating from the commercial Endpoint Manager?") ?></h4>
					<p class="small">
						<a class="btn btn-default btn-sm" href="config.php?display=epm_devices&amp;sub_type=download_export_tool"><i class="fa fa-download text-primary"></i> <?= _("Download export tool") ?></a>
						<?= _("Run it on the OLD FreePBX system (13 to 16, commercial \"endpoint\" module) as root:") ?>
						<code>php export-commercial-epm.php --check</code> <?= _("to see what it found, then") ?>
						<code>php export-commercial-epm.php --out=epm-mapping.json</code>.
						<?= _("It reads only the endpoint_* tables, exports no passwords or SIP secrets, and lists every phone with its MAC, brand, model, template and the extension on each line, plus the data problems to fix before importing.") ?>
					</p>
					<form action="config.php?display=epm_devices" method="POST" enctype="multipart/form-data" class="form-inline" style="margin-bottom:6px">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="import_upload">
						<input type="file" name="import_file" accept=".json,application/json" class="form-control input-sm" style="display:inline-block">
						<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-upload text-primary"></i> <?= _("Preview import") ?></button>
						<span class="small"><?= _("Nothing is written until you confirm the preview.") ?></span>
					</form>
<?php if (is_array($imp)): ?>
					<div class="panel panel-info" style="margin-top:10px">
						<div class="panel-heading"><strong><?= _("Import preview") ?></strong>
							<span class="small">
								<?= $h(sprintf(_("from %s (FreePBX %s, endpoint module %s, exported %s)"), $imp['source']['host'] ?? '?', $imp['source']['freepbx_version'] ?? '?', $imp['source']['endpoint_module'] ?? '?', $imp['created'] ?: '?')) ?>
								&middot; <?= $h(sprintf(_("%d phone(s) will be created with %d line(s); %d phone(s) skipped, %d line(s) skipped."), $imp['stats']['create'], $imp['stats']['lines'], $imp['stats']['skip'], $imp['stats']['lines_skipped'])) ?>
							</span>
						</div>
						<div class="panel-body" style="padding:6px">
						<form action="config.php?display=epm_devices" method="POST" id="epm_import_form">
							<input type="hidden" name="display" value="epm_devices">
							<input type="hidden" name="sub_type" value="import_commit">
							<table class="table table-condensed table-striped small" style="margin-bottom:6px">
								<thead><tr>
									<th><?= _("Import") ?></th><th><?= _("MAC") ?></th><th><?= _("Brand / Model") ?></th><th><?= _("Lines") ?></th><th><?= _("Template") ?></th><th><?= _("Notes") ?></th>
								</tr></thead>
								<tbody>
<?php foreach ($imp['phones'] as $row): $ok = ($row['status'] === 'create'); ?>
								<tr class="<?= $ok ? '' : 'danger' ?>">
									<td><?php if ($ok): ?><input type="checkbox" name="import_include[<?= (int) $row['idx'] ?>]" value="1" checked><?php else: ?><span class="text-danger" title="<?= $h(implode('; ', $row['reasons'])) ?>"><?= _("skipped") ?></span><?php endif; ?></td>
									<td><code><?= $h($row['mac'] ?: $row['mac_raw']) ?></code></td>
									<td><?= $h($ok ? $row['brand_name'] . ' / ' . $row['model_name'] : $row['brand_src'] . ' / ' . $row['model_src']) ?><?php if ($ok && $row['max_lines']): ?> <span class="text-muted">(<?= (int) $row['max_lines'] ?> <?= _("lines") ?>)</span><?php endif; ?></td>
									<td>
<?php foreach ($row['lines'] as $lr): ?>
										<div<?= $lr['status'] === 'skip' ? ' class="text-danger"' : '' ?>><?= (int) $lr['line'] ?> = <?= $h($lr['ext']) ?><?= $lr['description'] !== '' ? ' <span class="text-muted">' . $h(mb_substr($lr['description'], 0, 20)) . '</span>' : '' ?><?= $lr['status'] === 'skip' ? ' <em>' . $h($lr['reason']) . '</em>' : '' ?></div>
<?php endforeach; ?>
									</td>
									<td>
<?php if ($ok): ?>
										<select name="import_template[<?= (int) $row['idx'] ?>]" class="form-control input-sm">
											<option value="0"<?= (int) $row['template_id'] === 0 ? ' selected' : '' ?>><?= _("Custom (product defaults)") ?></option>
<?php foreach ($row['templates'] as $tid => $tname): ?>
											<option value="<?= (int) $tid ?>"<?= (int) $row['template_id'] === (int) $tid ? ' selected' : '' ?>><?= $h($tname) ?></option>
<?php endforeach; ?>
										</select>
										<div class="text-muted"><?= _("was") ?>: <?= $h($row['template_src']) ?></div>
<?php else: ?>
										<span class="text-muted"><?= $h($row['template_src']) ?></span>
<?php endif; ?>
									</td>
									<td>
<?php foreach ($row['reasons'] as $r): ?><div class="text-danger"><?= $h($r) ?></div><?php endforeach; ?>
<?php foreach ($row['notes'] as $r): ?><div><?= $h($r) ?></div><?php endforeach; ?>
<?php foreach ($row['source'] as $r): ?><div class="text-muted"><?= _("export said") ?>: <?= $h($r) ?></div><?php endforeach; ?>
									</td>
								</tr>
<?php endforeach; ?>
								</tbody>
							</table>
							<button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-check"></i> <?= _("Import the ticked phones") ?></button>
							<label class="small">(<input type="checkbox" name="import_rebuild" value="1"> <?= _("also write their configuration files now") ?>)</label>
							<button type="submit" class="btn btn-default btn-sm" formaction="config.php?display=epm_devices&amp;sub_type=import_cancel" name="sub_type" value="import_cancel"><?= _("Cancel") ?></button>
							<div class="small text-muted" style="margin-top:4px"><?= _("Skipped phones and lines are the ones this system cannot create (unknown MAC, brand or model, extension missing here or already mapped, line beyond the model). Untick a phone to leave it out. Configuration files are written into the Configuration Location only if you tick the box above, otherwise use Rebuild later.") ?></div>
						</form>
						</div>
					</div>
<?php endif; ?>
