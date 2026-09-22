<?php
/**
 * Endpoint Manager Object Module - Sec Devices (Extension Mapping)
 *
 * Maps phones (MAC addresses) to FreePBX extensions, keeps their lines and
 * templates, and drives config generation through Endpointman::prepare_configs().
 *
 * @author Javier Pastor
 * @author JD Austin
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Endpointman_Devices
{
	const FLASH_KEY  = 'endpointman_devices_flash';
	const SEARCH_KEY = 'endpointman_devices_search';

	public function __construct($epm)
	{
		$this->epm       = $epm;
		$this->freepbx   = $epm->freepbx;
		$this->db 	     = $epm->freepbx->Database;
		$this->config    = $epm->freepbx->Config;
		$this->system    = $epm->system;
		$this->eda       = $epm->eda;
	}

	public function myShowPage(&$pagedata)
	{
		if (empty($pagedata))
		{
			$pagedata['main'] = array(
				"name" => _("Devices"),
				"page" => '/views/page.main.devices.php'
			);
		}
	}

	/*********************************************************************
	 * AJAX: cascading select boxes (brand -> model -> template / lines)
	 *********************************************************************/
	public function ajaxRequest($req, &$setting)
	{
		$allowed = array('models', 'templates', 'lines', 'ptemplates', 'mtemplates', 'status');
		if (in_array($req, $allowed, true))
		{
			$setting['authenticate'] = true;
			$setting['allowremote']  = false;
			return true;
		}
		return false;
	}

	public function ajaxHandler($module_tab = "", $command = "")
	{
		$request = freepbxGetSanitizedRequest();
		$options = array();

		switch ($command)
		{
			case 'models':
				$brand = (int) ($request['brand'] ?? 0);
				$sel   = (int) ($request['selected'] ?? 0);
				$options[] = array('id' => 0, 'name' => '', 'is_select' => ($sel === 0));
				if ($brand > 0)
				{
					foreach ((array) $this->eda->all_models_by_brand($brand) as $row)
					{
						$options[] = array('id' => (int) $row['id'], 'name' => $row['model'], 'is_select' => ((int) $row['id'] === $sel));
					}
				}
				break;

			case 'templates':
				// Templates that belong to the product (family) of a model, plus "Custom..."
				$model = (int) ($request['model'] ?? 0);
				$sel   = $request['selected'] ?? '';
				if ($model > 0)
				{
					$sql  = sprintf("SELECT product_id FROM %s WHERE id = %d", Endpointman::TABLES['epm_model_list'], $model);
					$prod = (int) $this->eda->sql($sql, 'getOne');
					foreach ($this->epm->display_templates($prod, ($sel === '' ? NULL : $sel)) as $row)
					{
						$options[] = array('id' => $row['value'], 'name' => $row['text'], 'is_select' => !empty($row['selected']));
					}
				}
				else
				{
					$options[] = array('id' => 0, 'name' => _("Custom..."), 'is_select' => true);
				}
				break;

			case 'ptemplates':
				$product = (int) ($request['product'] ?? 0);
				$options[] = array('id' => '', 'name' => '', 'is_select' => true);
				if ($product > 0)
				{
					$sql = sprintf("SELECT id, name FROM %s WHERE product_id = %d ORDER BY name", Endpointman::TABLES['epm_template_list'], $product);
					foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $row)
					{
						$options[] = array('id' => (int) $row['id'], 'name' => $row['name'], 'is_select' => false);
					}
				}
				break;

			case 'mtemplates':
				$model = (int) ($request['model'] ?? 0);
				$options[] = array('id' => '', 'name' => '', 'is_select' => true);
				if ($model > 0)
				{
					$sql = sprintf("SELECT id, name FROM %s WHERE model_id = %d ORDER BY name", Endpointman::TABLES['epm_template_list'], $model);
					foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $row)
					{
						$options[] = array('id' => (int) $row['id'], 'name' => $row['name'], 'is_select' => false);
					}
				}
				break;

			case 'lines':
				$model = (int) ($request['model'] ?? 0);
				$macid = (int) ($request['macid'] ?? 0);
				$luid  = (int) ($request['luid'] ?? 0);
				if ($macid <= 0 && !empty($request['mac']))
				{
					// adding a line to a phone that already exists: only its free lines
					$macid = (int) $this->epm->retrieve_device_by_mac($request['mac']);
				}
				if ($luid > 0)
				{
					$lines = $this->epm->linesAvailable($luid);
				}
				elseif ($macid > 0)
				{
					$lines = $this->epm->linesAvailable(NULL, $macid);
				}
				elseif ($model > 0)
				{
					$sql   = sprintf("SELECT max_lines FROM %s WHERE id = %d", Endpointman::TABLES['epm_model_list'], $model);
					$max   = (int) $this->eda->sql($sql, 'getOne');
					$lines = array();
					for ($i = 1; $i <= $max; $i++)
					{
						$lines[$i] = array('value' => $i, 'text' => $i);
					}
				}
				else
				{
					$lines = array();
				}
				foreach ((array) $lines as $row)
				{
					$options[] = array('id' => $row['value'], 'name' => $row['text'], 'is_select' => !empty($row['selected']));
				}
				break;

			case 'status':
				return array('status' => true, 'devices' => $this->epm->device_status_map());

			default:
				return array("status" => false, "message" => _("Command not found!") . " [" . $command . "]");
		}
		return array('status' => true, 'options' => $options);
	}

	/**
	 * Send tools/export-commercial-epm.php as a download and stop the request.
	 * The script is CLI-only (it refuses to run under a web server), so the raw file is safe to hand out.
	 */
	public function send_export_tool()
	{
		$file = $this->epm->system->buildPath($this->epm->MODULE_PATH, 'tools', 'export-commercial-epm.php');
		if (!is_file($file) || !is_readable($file))
		{
			header('HTTP/1.1 404 Not Found');
			echo _("The export tool is missing from this module installation.");
			exit;
		}
		while (ob_get_level() > 0) { ob_end_clean(); }
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="export-commercial-epm.php"');
		header('Content-Length: ' . filesize($file));
		header('X-Content-Type-Options: nosniff');
		readfile($file);
		exit;
	}

	/*********************************************************************
	 * Page actions (POST), processed before the page renders
	 *********************************************************************/
	public function doConfigPageInit($module_tab = "", $command = "")
	{
		$request  = freepbxGetSanitizedRequest();
		$sub_type = strtolower(trim($request['sub_type'] ?? ''));
		if ($sub_type === '' || $sub_type === 'edit')
		{
			return;
		}
		if ($sub_type === 'download_export_tool')
		{
			// The migration script for the old system (commercial Endpoint Manager -> this module).
			// Served as a download so nobody has to dig it out of the module directory.
			$this->send_export_tool();
			return;
		}
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
		{
			return;
		}

		$reboot   = !empty($request['reboot']);
		$redirect = 'config.php?display=epm_devices';

		switch ($sub_type)
		{
			case 'add':
				$mac_id = $this->epm->add_device(
					$request['mac'] ?? '',
					$request['model_list'] ?? '',
					$request['ext_list'] ?? '',
					($request['template_list'] ?? '') === '' ? NULL : $request['template_list'],
					empty($request['line_list']) ? NULL : $request['line_list']
				);
				if ($mac_id)
				{
					if (!empty($request['ipei']))
					{
						$this->set_line_ipei_first($mac_id, $request['ipei']);
					}
					$this->epm->rebuild_device($mac_id, $reboot);
				}
				break;

			case 'edit_save':
				$edit_id = (int) ($request['edit_id'] ?? 0);
				if ($edit_id > 0 && $this->save_edit($request, $edit_id))
				{
					$this->epm->rebuild_device($edit_id, $reboot);
				}
				elseif ($edit_id > 0)
				{
					$redirect .= '&sub_type=edit&edit_id=' . $edit_id;
				}
				break;

			case 'edit_add_line':
				$edit_id = (int) ($request['edit_id'] ?? 0);
				if ($edit_id > 0)
				{
					$this->epm->add_line($edit_id);
					$redirect .= '&sub_type=edit&edit_id=' . $edit_id;
				}
				break;

			case 'edit_delete_line':
				$luid   = (int) ($request['luid'] ?? 0);
				$sql    = sprintf("SELECT mac_id FROM %s WHERE luid = %d", Endpointman::TABLES['epm_line_list'], $luid);
				$mac_id = (int) $this->eda->sql($sql, 'getOne');
				if ($luid > 0)
				{
					$this->epm->delete_line($luid, FALSE);
				}
				if ($mac_id > 0)
				{
					$redirect .= '&sub_type=edit&edit_id=' . $mac_id;
				}
				break;

			case 'delete_device':
				$mac_id = (int) ($request['edit_id'] ?? 0);
				if ($mac_id > 0)
				{
					$this->epm->delete_device($mac_id);
				}
				break;

			case 'delete_selected':
				$ids = $this->selected_ids($request);
				if (empty($ids))
				{
					$this->epm->error['page'] = _("No Phones Selected") . "!";
					break;
				}
				foreach ($ids as $id)
				{
					$this->epm->delete_device($id);
				}
				$this->epm->message['page'] = sprintf(_("Deleted %d phone(s)"), count($ids));
				break;

			case 'rebuild_selected':
				$ids = $this->selected_ids($request);
				if (empty($ids))
				{
					$this->epm->error['page'] = _("No Phones Selected") . "!";
					break;
				}
				$ok = 0;
				foreach ($ids as $id)
				{
					if ($this->epm->rebuild_device($id, $reboot))
					{
						$ok++;
					}
				}
				$this->epm->message['page'] = sprintf($reboot ? _("Rebuilt configs and rebooted %d of %d selected phone(s)") : _("Rebuilt configs for %d of %d selected phone(s)"), $ok, count($ids));
				break;

			case 'rebuild_all':
				$ids = $this->all_mac_ids();
				$ok  = 0;
				foreach ($ids as $id)
				{
					if ($this->epm->rebuild_device($id, $reboot))
					{
						$ok++;
					}
				}
				$this->epm->message['page'] = sprintf($reboot ? _("Rebuilt configs and rebooted %d of %d phone(s)") : _("Rebuilt configs for %d of %d phone(s)"), $ok, count($ids));
				break;

			case 'reboot_brand':
				$brand = (int) ($request['rb_brand'] ?? 0);
				if ($brand <= 0)
				{
					$this->epm->error['page'] = _("No Brand Selected for Reboot");
					break;
				}
				$sql = sprintf(
					"SELECT mac.id FROM %s AS mac, %s AS m WHERE m.id = mac.model AND m.brand = %d",
					Endpointman::TABLES['epm_mac_list'], Endpointman::TABLES['epm_model_list'], $brand
				);
				$rows = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
				if (empty($rows))
				{
					$this->epm->error['page'] = _("No Phones to Reboot");
					break;
				}
				$n = 0;
				foreach ($rows as $row)
				{
					if ($this->epm->reboot_phone($this->epm->get_phone_info($row['id'])))
					{
						$n++;
					}
				}
				$this->epm->message['page'] = sprintf(_("Sent a reboot to %d phone(s)"), $n);
				break;

			case 'change_brand':
				$ids   = $this->selected_ids($request);
				$model = (int) ($request['model_list_selected'] ?? 0);
				if (empty($ids))
				{
					$this->epm->error['page'] = _("No Phones Selected") . "!";
					break;
				}
				if ($model <= 0 || (int) ($request['brand_list_selected'] ?? 0) <= 0)
				{
					$this->epm->error['page'] = _("Please select a Brand and a Model");
					break;
				}
				foreach ($ids as $id)
				{
					$sql = sprintf(
						"UPDATE %s SET global_custom_cfg_data = '', template_id = 0, global_user_cfg_data = '', config_files_override = '', model = %d WHERE id = %d",
						Endpointman::TABLES['epm_mac_list'], $model, $id
					);
					$this->eda->sql($sql);
					$this->epm->rebuild_device($id, !empty($request['reboot_change']));
				}
				$this->epm->message['page'] = sprintf(_("Changed %d phone(s) to the selected model"), count($ids));
				break;

			case 'rebuild_product':
			case 'rebuild_model':
				$by_model = ($sub_type === 'rebuild_model');
				$target   = (int) ($by_model ? ($request['model_select'] ?? 0) : ($request['product_select'] ?? 0));
				$template = (int) ($by_model ? ($request['model_template_selector'] ?? 0) : ($request['template_selector'] ?? 0));
				if ($target <= 0)
				{
					$this->epm->error['page'] = $by_model ? _("Please select a model") : _("Please select a product");
					break;
				}
				if ($template <= 0)
				{
					$this->epm->error['page'] = _("Please select a template");
					break;
				}
				$sql = sprintf(
					"SELECT mac.id FROM %s AS mac, %s AS m WHERE mac.model = m.id AND %s = %d",
					Endpointman::TABLES['epm_mac_list'], Endpointman::TABLES['epm_model_list'], ($by_model ? 'm.id' : 'm.product_id'), $target
				);
				$rows = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
				foreach ($rows as $row)
				{
					$this->eda->sql(sprintf("UPDATE %s SET template_id = %d WHERE id = %d", Endpointman::TABLES['epm_mac_list'], $template, (int) $row['id']));
					$this->epm->rebuild_device($row['id'], $reboot);
				}
				$this->epm->message['page'] = sprintf(_("Reconfigured %d phone(s) with the selected template"), count($rows));
				break;

			case 'search':
				$netmask = trim($request['netmask'] ?? '');
				$this->epm->setConfig('nmap_search', $netmask);
				$found = $this->epm->discover_new($netmask, !empty($request['nmap']));
				$list  = array();
				if (is_array($found))
				{
					foreach ($found as $key => $data)
					{
						if ($data['endpoint_managed'] || empty($data['brand_id']))
						{
							continue;
						}
						$data['id']     = $key;
						$data['models'] = (array) $this->eda->all_models_by_brand((int) $data['brand_id']);
						$list[$key]     = $data;
					}
				}
				if (empty($list))
				{
					$this->epm->message['page'] = _("No unmanaged phones found");
				}
				$_SESSION[self::SEARCH_KEY] = $list;
				break;

			case 'import_upload':
				$imp = new Endpointman_Import($this->epm);
				$doc = $imp->load_upload($_FILES['import_file'] ?? array());
				if (is_string($doc))
				{
					$this->epm->error['import'] = $doc;
				}
				else
				{
					$pv = $imp->preview($doc);
					$this->epm->message['import'] = sprintf(_("Preview ready: %d phone(s) can be created, %d cannot. Nothing has been written yet."), $pv['stats']['create'], $pv['stats']['skip']);
				}
				break;

			case 'import_commit':
				$imp = new Endpointman_Import($this->epm);
				$imp->commit((array) ($request['import_include'] ?? array()), (array) ($request['import_template'] ?? array()), !empty($request['import_rebuild']));
				break;

			case 'import_cancel':
				(new Endpointman_Import($this->epm))->discard();
				$this->epm->message['import'] = _("Import cancelled; nothing was written.");
				break;

			case 'add_selected':
				$added = 0;
				foreach ((array) ($request['add'] ?? array()) as $num)
				{
					$num    = (int) $num;
					$mac_id = $this->epm->add_device($request['mac_' . $num] ?? '', $request['model_list_' . $num] ?? '', $request['ext_list_' . $num] ?? '');
					if ($mac_id)
					{
						$this->epm->rebuild_device($mac_id, !empty($request['reboot_sel']));
						$added++;
					}
				}
				if ($added === 0 && empty($this->epm->error))
				{
					$this->epm->error['page'] = _("No Phones Selected") . "!";
				}
				unset($_SESSION[self::SEARCH_KEY]);
				break;

			default:
				return;
		}

		$_SESSION[self::FLASH_KEY] = $this->epm->collect_messages(true);
		if (!headers_sent())
		{
			header('Location: ' . $redirect);
			exit;
		}
	}

	/*********************************************************************
	 * Page data
	 *********************************************************************/
	public function showPage(array &$data)
	{
		$request = $data['request'] ?? freepbxGetSanitizedRequest();
		$epm     = $this->epm;

		$flash = $_SESSION[self::FLASH_KEY] ?? array('message' => array(), 'error' => array());
		unset($_SESSION[self::FLASH_KEY]);
		$now = $epm->collect_messages(true);
		$flash['message'] = array_merge($flash['message'], $now['message']);
		$flash['error']   = array_merge($flash['error'], $now['error']);

		$family_list = $this->eda->all_products();
		$brands      = $epm->brands_available(NULL, true);
		$exts        = $epm->display_registration_list();
		$no_add      = false;
		$warnings    = array();

		if (empty($family_list) || count($brands) <= 1)
		{
			$no_add     = true;
			$warnings[] = sprintf(_("No phone brands are installed and enabled. Install a brand and enable at least one model in the %s first."), '<a href="config.php?display=epm_config">' . _("Package Manager") . '</a>');
		}
		if ($epm->getConfig('srvip') == '')
		{
			$no_add     = true;
			$warnings[] = sprintf(_("The IP address of the phone server is not set. Head over to %s to set up your configuration."), '<a href="config.php?display=epm_advanced">' . _("Advanced Settings") . '</a>');
		}
		$config_location = (string) $epm->getConfig('config_location');
		if ($config_location === '' || !is_dir($config_location) || !is_writable($config_location))
		{
			$warnings[] = sprintf(_("Configuration Location '%s' is not a writable directory; configs cannot be written until it is fixed in %s."), htmlspecialchars($config_location), '<a href="config.php?display=epm_advanced">' . _("Advanced Settings") . '</a>');
		}

		// Managed devices
		$status  = $epm->device_status_map();
		$devices = array();
		foreach ((array) $this->eda->all_devices() as $row)
		{
			$row['template_name'] = $this->template_name($row);
			$row['lines']         = (array) $this->eda->get_lines_from_device($row['id']);
			$first                = $row['lines'][0]['ext'] ?? null;
			$row['status']        = ($first !== null && isset($status[$first])) ? $status[$first] : array('status' => false, 'ip' => '');
			$devices[]            = $row;
		}
		foreach ((array) $this->eda->all_unknown_devices() as $row)
		{
			$brand = $epm->get_brand_from_mac($row['mac']);
			$row['name']          = $brand ? $brand['name'] : _("Unknown");
			$row['model']         = _("Unknown");
			$row['enabled']       = 1;
			$row['template_name'] = _("N/A");
			$row['lines']         = (array) $this->eda->get_lines_from_device($row['id']);
			$row['unknown']       = true;
			$first                = $row['lines'][0]['ext'] ?? null;
			$row['status']        = ($first !== null && isset($status[$first])) ? $status[$first] : array('status' => false, 'ip' => '');
			$devices[]            = $row;
		}

		// Edit mode
		$mode = null;
		$edit = null;
		if (strtolower(trim($request['sub_type'] ?? '')) === 'edit' && (int) ($request['edit_id'] ?? 0) > 0)
		{
			$edit = $epm->get_phone_info((int) $request['edit_id']);
			if ($edit)
			{
				$mode            = 'EDIT';
				$edit['models']  = $epm->models_available($edit['model_id'], $edit['brand_id']) ?: array();
				$edit['templates'] = $epm->display_templates($edit['product_id'], $edit['template_id']);
				foreach ($edit['line'] as $n => &$line)
				{
					$line['reg_list']  = $epm->display_registration_list($line['luid']);
					$line['line_list'] = $epm->linesAvailable($line['luid']) ?: array($n => array('value' => $n, 'text' => $n, 'selected' => 'selected'));
				}
				unset($line);
				$edit['can_add_line'] = ($epm->linesAvailable(NULL, $edit['id']) !== false) && !empty($this->eda->all_unused_registrations());
			}
			else
			{
				$flash['error'][] = _("Device not found");
			}
		}

		// Product and model lists for the global reconfigure forms
		$sql = sprintf(
			"SELECT DISTINCT p.id, p.short_name FROM %s AS p, %s AS m WHERE p.id = m.product_id AND m.hidden = 0 AND m.enabled = 1 AND p.hidden != 1 AND p.cfg_dir != '' ORDER BY p.short_name",
			Endpointman::TABLES['epm_product_list'], Endpointman::TABLES['epm_model_list']
		);
		$products = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
		$sql = sprintf(
			"SELECT DISTINCT m.id, m.model FROM %s AS p, %s AS m WHERE p.id = m.product_id AND m.hidden = 0 AND m.enabled = 1 AND p.hidden != 1 AND p.cfg_dir != '' ORDER BY m.model",
			Endpointman::TABLES['epm_product_list'], Endpointman::TABLES['epm_model_list']
		);
		$models = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);

		$netmask = (string) $epm->getConfig('nmap_search');
		if ($netmask === '' && !empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			$netmask = $_SERVER['SERVER_ADDR'] . '/24';
		}

		$search = $_SESSION[self::SEARCH_KEY] ?? null;
		unset($_SESSION[self::SEARCH_KEY]);

		$data['devices_page'] = array(
			'flash'           => $flash,
			'warnings'        => $warnings,
			'no_add'          => $no_add,
			'mode'            => $mode,
			'edit'            => $edit,
			'brands'          => $brands,
			'exts'            => $exts,
			'devices'         => $devices,
			'products'        => $products,
			'models'          => $models,
			'netmask'         => $netmask,
			'nmap'            => is_executable((string) $epm->getConfig('nmap_location', '/usr/bin/nmap')),
			'search'          => $search,
			'config_location' => $config_location,
			'srvip'           => (string) $epm->getConfig('srvip'),
			'srvport'         => (string) $epm->getConfig('srvport', '5060'),
			'import'          => (new Endpointman_Import($epm))->current_preview(),
		);
	}

	public function getRightNav($request, $params = array())
	{
		return "";
	}

	public function getActionBar($request)
	{
		return "";
	}

	/*********************************************************************
	 * Helpers
	 *********************************************************************/
	private function selected_ids(array $request)
	{
		$ids = array();
		foreach ((array) ($request['selected'] ?? array()) as $id)
		{
			if ((int) $id > 0)
			{
				$ids[] = (int) $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function all_mac_ids()
	{
		$sql  = sprintf("SELECT id FROM %s WHERE model > 0 ORDER BY id", Endpointman::TABLES['epm_mac_list']);
		$rows = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
		return array_map(function ($r) { return (int) $r['id']; }, $rows);
	}

	private function template_name(array $row)
	{
		if ((int) $row['template_id'] > 0)
		{
			$sql  = sprintf("SELECT name FROM %s WHERE id = %d", Endpointman::TABLES['epm_template_list'], (int) $row['template_id']);
			$name = $this->eda->sql($sql, 'getOne');
			return $name ?: _("N/A");
		}
		return !empty($row['global_custom_cfg_data']) ? "Custom-" . $row['mac'] : _("Custom...");
	}

	private function set_line_ipei_first($mac_id, $ipei)
	{
		$ipei = preg_replace('/[^0-9A-Za-z]/', '', (string) $ipei);
		$sql  = sprintf("UPDATE %s SET ipei = %s WHERE mac_id = %d ORDER BY line ASC LIMIT 1", Endpointman::TABLES['epm_line_list'], $this->db->quote(mb_substr($ipei, 0, 15)), (int) $mac_id);
		$this->eda->sql($sql);
	}

	/**
	 * Save the edit form: model, template and every line (extension, line number, IPEI).
	 */
	private function save_edit(array $request, int $edit_id)
	{
		$model    = (int) ($request['model_list'] ?? 0);
		$template = (int) ($request['template_list'] ?? 0);
		if ($model <= 0)
		{
			$this->epm->error['page'] = _("You Must Select A Model From the Drop Down") . "!";
			return false;
		}
		if (!$this->epm->sync_model($model))
		{
			return false;
		}

		$sql   = sprintf("SELECT * FROM %s WHERE mac_id = %d ORDER BY line ASC", Endpointman::TABLES['epm_line_list'], $edit_id);
		$lines = (array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);

		$sql       = sprintf("SELECT max_lines FROM %s WHERE id = %d", Endpointman::TABLES['epm_model_list'], $model);
		$max_lines = (int) $this->eda->sql($sql, 'getOne');

		$seen_line = array();
		$seen_ext  = array();
		$updates   = array();
		foreach ($lines as $row)
		{
			$luid = (int) $row['luid'];
			$ext  = trim((string) ($request['ext_list_' . $luid] ?? $row['ext']));
			$line = (int) ($request['line_list_' . $luid] ?? $row['line']);
			$ipei = preg_replace('/[^0-9A-Za-z]/', '', (string) ($request['ipei_' . $luid] ?? $row['ipei']));
			if ($ext === '' || $line < 1)
			{
				$this->epm->error['page'] = _("Every line needs an extension and a line number");
				return false;
			}
			if ($max_lines > 0 && $line > $max_lines)
			{
				$this->epm->error['page'] = sprintf(_("Line %d does not exist on this model (max %d)"), $line, $max_lines);
				return false;
			}
			if (isset($seen_line[$line]))
			{
				$this->epm->error['page'] = sprintf(_("Line %d is used twice"), $line);
				return false;
			}
			if (isset($seen_ext[$ext]) && !$this->epm->getConfig('show_all_registrations'))
			{
				$this->epm->error['page'] = sprintf(_("Extension %s is used twice"), $ext);
				return false;
			}
			$seen_line[$line] = true;
			$seen_ext[$ext]   = true;

			$sql  = sprintf("SELECT description FROM %s WHERE id = %s", Endpointman::TABLES['devices'], $this->db->quote($ext));
			$name = (string) $this->eda->sql($sql, 'getOne');
			if ($name === '' && !$this->eda->sql(sprintf("SELECT id FROM %s WHERE id = %s", Endpointman::TABLES['devices'], $this->db->quote($ext)), 'getOne'))
			{
				$this->epm->error['page'] = sprintf(_("Extension %s does not exist"), $ext);
				return false;
			}
			$updates[] = sprintf(
				"UPDATE %s SET ipei = %s, line = %d, ext = %s, description = %s WHERE luid = %d",
				Endpointman::TABLES['epm_line_list'], $this->db->quote(mb_substr($ipei, 0, 15)), $line, $this->db->quote($ext), $this->db->quote(mb_substr($name, 0, 20)), $luid
			);
		}
		foreach ($updates as $sql)
		{
			$this->eda->sql($sql);
		}
		$this->epm->update_device($edit_id, $model, $template, NULL, NULL, NULL, FALSE);
		$this->epm->message['page'] = _("Saved") . "!";
		return true;
	}
}
