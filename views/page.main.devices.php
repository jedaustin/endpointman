<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
/**
 * Extension Mapping page (devices manager)
 *
 * Data comes from Endpointman_Devices::showPage() in $devices_page.
 */
$p    = $devices_page;
$h    = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$edit = $p['edit'];
$is_edit = ($p['mode'] === 'EDIT' && is_array($edit));
$opts = function (array $list, $blank = false) use ($h) {
	$out = $blank ? '<option value=""></option>' : '';
	foreach ($list as $row)
	{
		$out .= sprintf('<option value="%s"%s>%s</option>', $h($row['value']), !empty($row['selected']) ? ' selected' : '', $h($row['text']));
	}
	return $out;
};
?>
<?= $endpoint_warn ?>
<div class="container-fluid" id="epm_devices">
	<h1><?= _("End Point Configuration Manager") ?></h1>
	<h2><?= _("Extension Mapping") ?></h2>

	<?php foreach ($p['flash']['error'] as $msg): ?>
		<div class="alert alert-danger alert-dismissible" role="alert"><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button><?= $msg ?></div>
	<?php endforeach; ?>
	<?php foreach ($p['flash']['message'] as $msg): ?>
		<div class="alert alert-success alert-dismissible" role="alert"><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button><?= $msg ?></div>
	<?php endforeach; ?>
	<?php foreach ($p['warnings'] as $msg): ?>
		<div class="alert alert-warning" role="alert"><strong><?= _("Warning!") ?></strong> <?= $msg ?></div>
	<?php endforeach; ?>

	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display full-border">

					<!-- ============ Add / Edit device ============ -->
					<h3><?= $is_edit ? _("Edit Device") : _("Add Device") ?></h3>
					<?php if ($p['no_add'] && !$is_edit): ?>
						<p class="text-muted"><?= _("Adding phones is disabled until the warnings above are resolved.") ?></p>
					<?php else: ?>
					<form name="epm_devices_form_add" id="epm_devices_form_add" action="config.php?display=epm_devices" method="POST" class="form-horizontal" autocomplete="off">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" id="epm_devices_add_sub_type" value="<?= $is_edit ? 'edit_save' : 'add' ?>">
						<?php if ($is_edit): ?><input type="hidden" name="edit_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
						<div class="table-responsive">
						<table class="table table-condensed" id="epm_devices_table_add">
							<thead>
								<tr>
									<th><?= _("MAC Address") ?></th>
									<th><?= _("IPEI (DECT)") ?></th>
									<th><?= _("Brand") ?></th>
									<th><?= _("Model") ?></th>
									<th><?= _("Line") ?></th>
									<th><?= _("Extension") ?></th>
									<th><?= _("Template") ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
							<?php if (!$is_edit): ?>
								<tr>
									<td><input name="mac" id="epm_devices_mac" type="text" class="form-control input-sm" maxlength="17" placeholder="AA:BB:CC:DD:EE:FF"></td>
									<td><input name="ipei" type="text" class="form-control input-sm" maxlength="15"></td>
									<td><select name="brand_list" id="epm_devices_brand" class="form-control input-sm"><?= $opts($p['brands']) ?></select></td>
									<td><select name="model_list" id="epm_devices_model" class="form-control input-sm"><option value=""></option></select></td>
									<td><select name="line_list" id="epm_devices_line" class="form-control input-sm"><option value=""></option></select></td>
									<td><select name="ext_list" id="epm_devices_ext" class="form-control input-sm"><?= $opts($p['exts']) ?></select></td>
									<td><select name="template_list" id="epm_devices_template" class="form-control input-sm"><option value="0"><?= _("Custom...") ?></option></select></td>
									<td class="text-nowrap">
										<button type="submit" class="btn btn-success btn-sm" id="epm_devices_bt_add"><i class="fa fa-plus"></i> <?= _("Add") ?></button>
										<button type="reset" class="btn btn-default btn-sm"><i class="fa fa-rotate-left"></i> <?= _("Reset") ?></button>
									</td>
								</tr>
								<tr>
									<td colspan="8" class="text-muted small"><label><input type="checkbox" name="reboot" value="1"> <?= _("Reboot the phone after writing its configuration") ?></label></td>
								</tr>
							<?php else: ?>
								<tr>
									<td><strong><?= $h($edit['mac']) ?></strong></td>
									<td></td>
									<td><?= $h($edit['name']) ?></td>
									<td><select name="model_list" id="epm_devices_model" class="form-control input-sm" data-edit="1"><?= $opts($edit['models']) ?></select></td>
									<td></td>
									<td></td>
									<td>
										<select name="template_list" id="epm_devices_template" class="form-control input-sm"><?= $opts($edit['templates']) ?></select>
										<?php $custom = ((int) $edit['template_id'] === 0); ?>
										<a href="config.php?display=epm_templates&amp;subpage=edit&amp;custom=<?= $custom ? '1' : '0' ?>&amp;idsel=<?= $custom ? (int) $edit['id'] : (int) $edit['template_id'] ?>" class="small" title="<?= _("Open in the Template Editor") ?>"><i class="fa fa-pencil"></i> <?= _("Edit template") ?></a>
									</td>
									<td class="text-nowrap">
										<button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> <?= _("Save") ?></button>
										<a href="config.php?display=epm_devices" class="btn btn-default btn-sm"><?= _("Cancel") ?></a>
									</td>
								</tr>
								<?php foreach ($edit['line'] as $n => $line): ?>
								<tr class="epm_devices_edit_line">
									<td class="text-right text-muted"><?= sprintf(_("Line %d"), (int) $line['line']) ?></td>
									<td><input name="ipei_<?= (int) $line['luid'] ?>" type="text" class="form-control input-sm" maxlength="15" value="<?= $h($line['ipei'] ?? '') ?>"></td>
									<td></td>
									<td></td>
									<td><select name="line_list_<?= (int) $line['luid'] ?>" class="form-control input-sm"><?= $opts($line['line_list']) ?></select></td>
									<td><select name="ext_list_<?= (int) $line['luid'] ?>" class="form-control input-sm"><?= $opts($line['reg_list']) ?></select></td>
									<td></td>
									<td>
										<?php if (count($edit['line']) > 1): ?>
										<button type="button" class="btn btn-danger btn-xs epm_devices_bt_delete_line" data-luid="<?= (int) $line['luid'] ?>" title="<?= _("Delete this line") ?>"><i class="fa fa-times"></i></button>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
								<tr>
									<td colspan="8">
										<label class="small"><input type="checkbox" name="reboot" value="1"> <?= _("Reboot the phone after writing its configuration") ?></label>
										<?php if (!empty($edit['can_add_line'])): ?>
										&nbsp; <button type="button" class="btn btn-default btn-xs" id="epm_devices_bt_add_line"><i class="fa fa-plus"></i> <?= _("Add a line") ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
							</tbody>
						</table>
						</div>
					</form>
					<?php endif; ?>

					<!-- ============ Search results ============ -->
					<?php if (is_array($p['search'])): ?>
					<h3><?= _("Unmanaged phones found") ?></h3>
					<?php if (empty($p['search'])): ?>
						<p class="text-muted"><?= _("No unmanaged phones of an installed brand were found.") ?></p>
					<?php else: ?>
					<form id="epm_devices_form_unmanaged" action="config.php?display=epm_devices" method="POST">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="add_selected">
						<div class="table-responsive">
						<table class="table table-condensed table-striped">
							<thead><tr><th></th><th><?= _("MAC") ?></th><th><?= _("IP") ?></th><th><?= _("Brand") ?></th><th><?= _("Model") ?></th><th><?= _("Extension") ?></th></tr></thead>
							<tbody>
							<?php foreach ($p['search'] as $row): ?>
								<tr>
									<td><input type="checkbox" name="add[]" value="<?= (int) $row['id'] ?>"><input type="hidden" name="mac_<?= (int) $row['id'] ?>" value="<?= $h($row['mac_strip']) ?>"></td>
									<td><?= $h($row['mac_strip']) ?></td>
									<td><?= $h($row['ip']) ?></td>
									<td><?= $h($row['brand']) ?></td>
									<td><select name="model_list_<?= (int) $row['id'] ?>" class="form-control input-sm"><?php foreach ($row['models'] as $m): ?><option value="<?= (int) $m['id'] ?>"><?= $h($m['model']) ?></option><?php endforeach; ?></select></td>
									<td><select name="ext_list_<?= (int) $row['id'] ?>" class="form-control input-sm"><?= $opts($p['exts']) ?></select></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<button type="submit" class="btn btn-success btn-sm"><i class="fa fa-plus"></i> <?= _("Add Selected Phones") ?></button>
						<label class="small">&nbsp; <input type="checkbox" name="reboot_sel" value="1"> <?= _("Reboot Phones") ?></label>
					</form>
					<?php endif; ?>
					<?php endif; ?>

					<!-- ============ Managed devices ============ -->
					<h3><?= _("Current Managed Extensions") ?></h3>
					<form name="epm_devices_form_managed" id="epm_devices_form_managed" action="config.php?display=epm_devices" method="POST">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" id="epm_devices_managed_sub_type" value="">
						<p>
							<button type="button" class="btn btn-default btn-xs" id="epm_devices_bt_select_all"><i class="fa fa-check-square-o"></i> <?= _("Select All") ?></button>
							<button type="button" class="btn btn-default btn-xs" id="epm_devices_bt_select_none"><i class="fa fa-square-o"></i> <?= _("Deselect All") ?></button>
							<span class="text-muted small">&nbsp; <?= sprintf(_("Configs are written to %s for server %s port %s"), '<code>' . $h($p['config_location']) . '</code>', '<code>' . $h($p['srvip']) . '</code>', '<code>' . $h($p['srvport']) . '</code>') ?></span>
						</p>
						<div class="table-responsive">
						<table class="table table-striped table-condensed" id="epm_devices_table_managed">
							<thead>
								<tr>
									<th></th>
									<th><?= _("MAC Address") ?></th>
									<th><?= _("Brand") ?></th>
									<th><?= _("Model") ?></th>
									<th><?= _("Line") ?></th>
									<th><?= _("Extension") ?></th>
									<th><?= _("IPEI") ?></th>
									<th><?= _("Template") ?></th>
									<th><?= _("Actions") ?></th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($p['devices'])): ?>
								<tr><td colspan="9" class="text-muted"><?= _("No phones are mapped yet. Add one above or search the network below.") ?></td></tr>
							<?php endif; ?>
							<?php foreach ($p['devices'] as $dev): ?>
								<?php $lines = !empty($dev['lines']) ? $dev['lines'] : array(array('line' => '', 'ext' => '', 'description' => '', 'ipei' => '', 'luid' => 0)); $first = true; ?>
								<?php foreach ($lines as $line): ?>
								<tr>
									<?php if ($first): ?>
									<td rowspan="<?= count($lines) ?>" class="text-nowrap">
										<input type="checkbox" class="epm_devices_selected" name="selected[]" value="<?= (int) $dev['id'] ?>">
										<i class="fa fa-power-off <?= !empty($dev['status']['status']) ? 'text-success' : 'text-danger' ?>" title="<?= !empty($dev['status']['status']) ? $h(_("Registered from ") . $dev['status']['ip']) : $h(_("Not registered")) ?>"></i>
									</td>
									<td rowspan="<?= count($lines) ?>"><?= $h($dev['mac']) ?><?php if (!empty($dev['status']['ip'])): ?><br><small class="text-muted"><?= $h($dev['status']['ip']) ?></small><?php endif; ?></td>
									<td rowspan="<?= count($lines) ?>"><?= $h($dev['name']) ?></td>
									<td rowspan="<?= count($lines) ?>"><?= $h($dev['model']) ?><?php if (empty($dev['enabled'])): ?> <em class="text-muted">(<?= _("Disabled") ?>)</em><?php endif; ?></td>
									<?php endif; ?>
									<td><?= $h($line['line']) ?></td>
									<td><?= $h($line['ext']) ?><?php if ($line['description'] !== ''): ?> - <?= $h($line['description']) ?><?php endif; ?></td>
									<td><?= $h($line['ipei'] ?? '') ?></td>
									<?php if ($first): ?>
									<td rowspan="<?= count($lines) ?>">
										<?php if (empty($dev['unknown'])): ?>
										<a href="config.php?display=epm_templates&amp;subpage=edit&amp;custom=<?= ((int) $dev['template_id'] === 0) ? '1' : '0' ?>&amp;idsel=<?= ((int) $dev['template_id'] === 0) ? (int) $dev['id'] : (int) $dev['template_id'] ?>" title="<?= _("Open in the Template Editor") ?>"><?= $h($dev['template_name']) ?></a>
										<?php else: ?><?= $h($dev['template_name']) ?><?php endif; ?>
									</td>
									<td rowspan="<?= count($lines) ?>" class="text-nowrap">
										<a href="config.php?display=epm_devices&amp;sub_type=edit&amp;edit_id=<?= (int) $dev['id'] ?>" class="btn btn-default btn-xs" title="<?= _("Edit phone") ?>"><i class="fa fa-edit"></i></a>
										<?php if (empty($dev['unknown'])): ?>
										<button type="button" class="btn btn-default btn-xs epm_devices_bt_rebuild_one" data-id="<?= (int) $dev['id'] ?>" title="<?= _("Rebuild this phone's configuration") ?>"><i class="fa fa-refresh"></i></button>
										<?php endif; ?>
										<button type="button" class="btn btn-danger btn-xs epm_devices_bt_delete_one" data-id="<?= (int) $dev['id'] ?>" data-mac="<?= $h($dev['mac']) ?>" title="<?= _("Delete phone") ?>"><i class="fa fa-trash"></i></button>
									</td>
									<?php endif; ?>
								</tr>
								<?php $first = false; endforeach; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>

						<h4><?= _("Selected Phone(s) Options") ?></h4>
						<div class="form-inline" style="margin-bottom:6px">
							<button type="button" class="btn btn-default btn-sm epm_devices_managed_action" data-action="rebuild_selected"><i class="fa fa-refresh text-success"></i> <?= _("Rebuild") ?></button>
							<?= _("Rebuild Configs for Selected Phones") ?>
							<label class="small">(<input type="checkbox" name="reboot" value="1"> <?= _("Reboot Phones") ?>)</label>
						</div>
						<div class="form-inline" style="margin-bottom:6px">
							<button type="button" class="btn btn-default btn-sm epm_devices_managed_action" data-action="change_brand"><i class="fa fa-random text-primary"></i> <?= _("Update") ?></button>
							<?= _("Change Selected Phones to") ?>
							<select name="brand_list_selected" id="epm_devices_brand_selected" class="form-control input-sm"><?= $opts($p['brands']) ?></select>
							<select name="model_list_selected" id="epm_devices_model_selected" class="form-control input-sm"><option value=""></option></select>
							<label class="small">(<input type="checkbox" name="reboot_change" value="1"> <?= _("Reboot Phones") ?>)</label>
						</div>
						<div class="form-inline" style="margin-bottom:6px">
							<button type="button" class="btn btn-danger btn-sm epm_devices_managed_action" data-action="delete_selected" data-confirm="<?= _("Delete the selected phones from Endpoint Manager? Their configuration files are left on disk.") ?>"><i class="fa fa-trash"></i> <?= _("Delete") ?></button>
							<?= _("Delete Selected Phones") ?>
						</div>
					</form>

					<!-- ============ Global options ============ -->
					<h4><?= _("Global Phone Options") ?></h4>
					<?php if (!$p['no_add']): ?>
					<form action="config.php?display=epm_devices" method="POST" class="form-inline" style="margin-bottom:6px" id="epm_devices_form_search">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="search">
						<button type="submit" class="btn btn-default btn-sm" id="epm_devices_bt_search"><i class="fa fa-search text-primary"></i> <?= _("Search") ?></button>
						<?= _("Search for new devices in netmask") ?>
						<input name="netmask" type="text" class="form-control input-sm" value="<?= $h($p['netmask']) ?>" size="18">
						<label class="small">(<input name="nmap" type="checkbox" value="1" <?= $p['nmap'] ? 'checked' : 'disabled' ?>> <?= _("Use NMAP") ?><?= $p['nmap'] ? '' : ' - ' . _("not installed") ?>)</label>
					</form>
					<?php endif; ?>

					<form action="config.php?display=epm_devices" method="POST" class="form-inline" style="margin-bottom:6px">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="rebuild_all">
						<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-refresh text-success"></i> <?= _("Rebuild") ?></button>
						<?= _("Rebuild Configs for All Phones") ?>
						<label class="small">(<input type="checkbox" name="reboot" value="1"> <?= _("Reboot Phones") ?>)</label>
					</form>

					<form action="config.php?display=epm_devices" method="POST" class="form-inline" style="margin-bottom:6px">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="reboot_brand">
						<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-power-off text-danger"></i> <?= _("Reboot") ?></button>
						<?= _("Reboot This Brand") ?>
						<select name="rb_brand" class="form-control input-sm"><?= $opts($p['brands']) ?></select>
					</form>

					<form action="config.php?display=epm_devices" method="POST" class="form-inline" style="margin-bottom:6px">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="rebuild_product">
						<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-random text-primary"></i> <?= _("Configure") ?></button>
						<?= _("Reconfigure all (products)") ?>
						<select name="product_select" id="epm_devices_product_select" class="form-control input-sm"><option value=""></option><?php foreach ($p['products'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $h($row['short_name']) ?></option><?php endforeach; ?></select>
						<?= _("with") ?>
						<select name="template_selector" id="epm_devices_template_selector" class="form-control input-sm"><option value=""></option></select>
						<label class="small">(<input type="checkbox" name="reboot" value="1"> <?= _("Reboot Phones") ?>)</label>
					</form>

					<form action="config.php?display=epm_devices" method="POST" class="form-inline" style="margin-bottom:6px">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" value="rebuild_model">
						<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-random text-primary"></i> <?= _("Configure") ?></button>
						<?= _("Reconfigure all (models)") ?>
						<select name="model_select" id="epm_devices_model_select" class="form-control input-sm"><option value=""></option><?php foreach ($p['models'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $h($row['model']) ?></option><?php endforeach; ?></select>
						<?= _("with") ?>
						<select name="model_template_selector" id="epm_devices_model_template_selector" class="form-control input-sm"><option value=""></option></select>
						<label class="small">(<input type="checkbox" name="reboot" value="1"> <?= _("Reboot Phones") ?>)</label>
					</form>

					<!-- hidden single-device action form -->
					<form id="epm_devices_form_single" action="config.php?display=epm_devices" method="POST" style="display:none">
						<input type="hidden" name="display" value="epm_devices">
						<input type="hidden" name="sub_type" id="epm_devices_single_sub_type" value="">
						<input type="hidden" name="edit_id" id="epm_devices_single_edit_id" value="">
						<input type="hidden" name="luid" id="epm_devices_single_luid" value="">
						<input type="hidden" name="selected[]" id="epm_devices_single_selected" value="">
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
