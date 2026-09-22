"use strict";
/**
 * Extension Mapping page (display=epm_devices)
 * epm_global.js calls epm_devices_document_ready() on load.
 */

function epm_devices_document_ready ()
{
	// Add form: brand -> model -> (lines, templates)
	$('#epm_devices_brand').on('change', function () {
		epm_devices_fill_models($('#epm_devices_model'), $(this).val(), 0, function () {
			$('#epm_devices_model').trigger('change');
		});
	});
	$('#epm_devices_model').on('change', function () {
		var model = $(this).val();
		if ($(this).data('edit') == 1)
		{
			// edit mode: only the template list follows the model
			epm_devices_fill_templates($('#epm_devices_template'), model, $('#epm_devices_template').val());
			return;
		}
		epm_devices_fill_lines($('#epm_devices_line'), model, $('#epm_devices_mac').val());
		epm_devices_fill_templates($('#epm_devices_template'), model, '');
	});
	$('#epm_devices_mac').on('blur', function () {
		var model = $('#epm_devices_model').val();
		if (model) { epm_devices_fill_lines($('#epm_devices_line'), model, $(this).val()); }
	});

	// Add form: validate MAC before submit
	$('#epm_devices_form_add').on('submit', function () {
		if ($('#epm_devices_add_sub_type').val() !== 'add') { return true; }
		var mac = $('#epm_devices_mac').val().replace(/[^0-9a-fA-F]/g, '');
		if (mac.length !== 12)
		{
			fpbxToast(_('Please enter a valid MAC address (12 hex digits)'), '', 'error');
			return false;
		}
		if (!$('#epm_devices_model').val())
		{
			fpbxToast(_('Please select a brand and a model'), '', 'error');
			return false;
		}
		return true;
	});

	// Edit mode: add / delete line
	$('#epm_devices_bt_add_line').on('click', function () {
		$('#epm_devices_add_sub_type').val('edit_add_line');
		$('#epm_devices_form_add').submit();
	});
	$('.epm_devices_bt_delete_line').on('click', function () {
		if (!confirm(_('Delete this line from the device?'))) { return; }
		epm_devices_single('edit_delete_line', '', $(this).data('luid'));
	});

	// Managed table
	$('#epm_devices_bt_select_all').on('click', function () { $('.epm_devices_selected').prop('checked', true); });
	$('#epm_devices_bt_select_none').on('click', function () { $('.epm_devices_selected').prop('checked', false); });

	$('.epm_devices_managed_action').on('click', function () {
		var action = $(this).data('action');
		if ($('.epm_devices_selected:checked').length === 0)
		{
			fpbxToast(_('No phones selected'), '', 'warning');
			return;
		}
		if (action === 'change_brand' && !$('#epm_devices_model_selected').val())
		{
			fpbxToast(_('Please select a brand and a model'), '', 'warning');
			return;
		}
		var msg = $(this).data('confirm');
		if (msg && !confirm(msg)) { return; }
		$('#epm_devices_managed_sub_type').val(action);
		$('#epm_devices_form_managed').submit();
	});

	$('.epm_devices_bt_rebuild_one').on('click', function () {
		epm_devices_single('rebuild_selected', '', '', $(this).data('id'));
	});
	$('.epm_devices_bt_delete_one').on('click', function () {
		if (!confirm(sprintf(_('Delete phone %s from Endpoint Manager?'), $(this).data('mac')))) { return; }
		epm_devices_single('delete_device', $(this).data('id'));
	});

	// Selected phones: brand -> model
	$('#epm_devices_brand_selected').on('change', function () {
		epm_devices_fill_models($('#epm_devices_model_selected'), $(this).val(), 0);
	});

	// Global reconfigure: product -> templates, model -> templates
	$('#epm_devices_product_select').on('change', function () {
		epm_devices_fill_options($('#epm_devices_template_selector'), { command: 'ptemplates', product: $(this).val() });
	});
	$('#epm_devices_model_select').on('change', function () {
		epm_devices_fill_options($('#epm_devices_model_template_selector'), { command: 'mtemplates', model: $(this).val() });
	});

	// Search can take a while with nmap
	$('#epm_devices_form_search').on('submit', function () {
		$('#epm_devices_bt_search').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + _('Searching...'));
		return true;
	});
}

function epm_devices_windows_load (nTab = "") { }
function epm_devices_change_tab (nTab = "") { }

/**
 * Submit the hidden single-device form with the given action.
 */
function epm_devices_single(sub_type, edit_id = '', luid = '', selected = '')
{
	$('#epm_devices_single_sub_type').val(sub_type);
	$('#epm_devices_single_edit_id').val(edit_id);
	$('#epm_devices_single_luid').val(luid);
	$('#epm_devices_single_selected').val(selected);
	$('#epm_devices_form_single').submit();
}

/**
 * Fill a <select> from the epm_devices ajax handler.
 */
function epm_devices_fill_options($select, params, callback)
{
	callback = callback || function () {};
	var data = $.extend({ module: 'endpointman', module_sec: 'epm_devices', module_tab: 'manager' }, params);
	epm_gloabl_manager_ajax(data, function (status, resp) {
		$select.empty();
		if (status && resp && resp.options)
		{
			$.each(resp.options, function (i, o) {
				$select.append($('<option>', { value: o.id, text: o.name, selected: !!o.is_select }));
			});
		}
		callback(status);
	});
}

function epm_devices_fill_models($select, brand, selected, callback)
{
	epm_devices_fill_options($select, { command: 'models', brand: brand, selected: selected }, callback);
}

function epm_devices_fill_templates($select, model, selected, callback)
{
	epm_devices_fill_options($select, { command: 'templates', model: model, selected: selected }, callback);
}

function epm_devices_fill_lines($select, model, mac, callback)
{
	epm_devices_fill_options($select, { command: 'lines', model: model, mac: mac }, callback);
}
