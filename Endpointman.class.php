<?php
/**
 * Endpoint Manager Object Module
 *
 * @author Andrew Nagy
 * @author Javier Pastor
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules;
use FreePBX_Helpers;
use BMO;
use PDO;
use Exception;

require_once('lib/epm_system.class.php');
require_once('lib/epm_data_abstraction.class.php');
require_once('lib/epm_packages.class.php');
require_once('lib/epm_packages_db.class.php');

require_once('Endpointman_Config.class.php');
require_once('Endpointman_Advanced.class.php');
require_once('Endpointman_Templates.class.php');
require_once('Endpointman_Devices.class.php');
require_once('Endpointman_Import.class.php');

#[\AllowDynamicProperties]
class Endpointman extends FreePBX_Helpers implements BMO {

	public $epm_config 	 	 = null;
	public $epm_advanced 	 = null;
	public $epm_templates 	 = null;
	public $epm_devices 	 = null;
	public $epm_oss 		 = null;
	public $epm_placeholders = null;

	public $freepbx		= null;
	public $db			= null; //Database from FreePBX
	public $config		= null;
	public $system		= null;
	public $eda			= null; //endpoint data abstraction layer
	public $astman		= null;
	public $packages	= null;
	public $packagesdb	= null;
	
	private $endpoint = null;

    public $error   = array(); //error construct
    public $message = array(); //message construct

	public $URL_UPDATE;

    public $MODULES_PATH;
	public $MODULE_PATH;

	public $PHONE_MODULES_PATH;
	public $PROVISIONER_PATH;
	public $EXPORT_PATH;
	public $TEMP_PATH;

	public $PROVISIONER_BASE; // Obsolete now used PHONE_MODULES_PATH

	// const URL_PROVISIONER = "http://mirror.freepbx.org/provisioner/v3/";
	const URL_PROVISIONER = "https://raw.githubusercontent.com/billsimon/provisioner/packaging/";
	
	const TABLES = array(
		'devices' 		 	=> 'devices',
		'epm_line_list'  	=> 'endpointman_line_list',
		'epm_model_list' 	=> 'endpointman_model_list',
		'epm_template_list' => 'endpointman_template_list',
		'epm_product_list'  => 'endpointman_product_list',
		'epm_mac_list' 		=> 'endpointman_mac_list',
		'epm_brands_list'	=> 'endpointman_brand_list',
		'epm_oui_list'		=> 'endpointman_oui_list',
		'epm_global_vars'   => 'endpointman_global_vars',
	);

	/**
	 * Constructor
	 * 
	 * @param object $freepbx The FreePBX object
	 */
	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception(_("Not given a FreePBX Object"));
		}
		parent::__construct($freepbx);

		$this->freepbx 	 = $freepbx;
		$this->db 		 = $freepbx->Database;
		$this->config 	 = $freepbx->Config;
		$this->astman 	 = $freepbx->astman;
		$this->system 	 = new Endpointman\epm_system();

		$this->setConfig('disable_epm', false);

		if (!$this->isConfigExist('tz') || empty($this->getConfig('tz')))
		{
			// Set TimeZone by config FREEPBX is not set
			$this->setConfig('tz', $this->config->get('PHPTIMEZONE'));
		}
		date_default_timezone_set($this->getConfig('tz'));
		
		$this->eda 		 = new epm_data_abstraction($this);

		
        //Generate empty array
        $this->error   = array();
        $this->message = array();
		$this->URL_UPDATE   = $this->getConfig('update_server');
        $this->MODULES_PATH = $this->system->buildPath($this->config->get('AMPWEBROOT'), 'admin', 'modules');
		$this->MODULE_PATH 	= $this->system->buildPath($this->MODULES_PATH, "endpointman");

		// Define the location of phone modules, keeping it outside of the module directory so that when 
		// the user updates endpointmanager they don't lose all of their phones
		// TODO: Migrate _ep_phone_modules to spool or other location
		$this->PHONE_MODULES_PATH = $this->system->buildPath($this->MODULES_PATH, '_ep_phone_modules');

		$this->TEMP_PATH 		  = $this->system->buildPath($this->PHONE_MODULES_PATH, "temp");
		$this->PROVISIONER_PATH   = $this->system->buildPath($this->TEMP_PATH, "provisioner");
		$this->EXPORT_PATH		  = $this->system->buildPath($this->TEMP_PATH, "export");

        if (! file_exists($this->MODULE_PATH))
		{
            die(sprintf(_("Can't Load Local Endpoint Manager Directory (%s)!"), __CLASS__));
        }

		// Check if directories needed are created
		$this->checkPathsFiles();

		if (!file_exists($this->PHONE_MODULES_PATH))
		{
			die(_('Endpoint Manager can not create the modules folder!'));
		}

        //Define error reporting
        if (($this->getConfig('debug')) AND (!isset($_REQUEST['quietmode'])))
		{
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
		else
		{
            ini_set('display_errors', 0);
        }

        //Check if config location is writable and/or exists!
        if ($this->isConfigExist('config_location'))
		{
			$config_location = $this->getConfig('config_location');
            if (is_dir($config_location))
			{
                if (!is_writeable($config_location))
				{
                    $user  = exec('whoami');
                    $group = exec("groups");
                    $this->error['config_location'] = sprintf(_("Configuration Directory is not writable!<br />
                            Please change the location: <a href='%1\$s'>Here</a><br />
                            Or run this command on SSH: <br />
							'chown -hR root:%2\$s %3\$s'<br />
							'chmod g+w %3\$s'"), 'config.php?display=epm_advanced', $group, $config_location);
					$this->setConfig('disable_epm', true);
                }
            }
			else
			{
                $this->error['config_location'] = sprintf(_("Configuration Directory is not a directory or does not exist! Please change the location here: <a href='%s'>Here</a>"), "config.php?display=epm_advanced");
				$this->setConfig('disable_epm', true);
            }
        }

        //$this->tpl = new RainTPL(MODULE_PATH . '/_old/templates/freepbx', MODULE_PATH . '/_old/templates/freepbx/compiled', '/admin/assets/endpointman/images');
		//$this->tpl = new RainTPL('/admin/assets/endpointman/images');

		$this->packages			= new Endpointman\Packages($this);
		$this->packagesdb		= new Endpointman\PackagesDB($this);
		$this->epm_config 		= new Endpointman_Config($this);
		$this->epm_advanced 	= new Endpointman_Advanced($this);
		$this->epm_templates 	= new Endpointman_Templates($this);
		$this->epm_devices 		= new Endpointman_Devices($this);
		$this->epm_oss 		    = new Endpointman_Devices($this);
		$this->epm_placeholders = new Endpointman_Devices($this);
	}

	/**
	 * Check paths and files needed for the module and create them if they don't exist
	 * 
	 * @param bool $install If true, it will delete the setup.php file
	 * @return bool True if all paths and files are created, false otherwise
	 */
	private function checkPathsFiles(bool $install = false)
	{
		$return_data = true;
		$operations = [
			[
				'path'			=> $this->system->buildPath($this->PHONE_MODULES_PATH),
				'permissions'	=> 0755,
				'type'			=> 'dir',
				'action'		=> 'check',
				'action_error'	=> 'break'
			],
			[
				'path' 		  => $this->system->buildPath($this->PHONE_MODULES_PATH, "endpoint"),
				'permissions' => 0755,
				'type' 		  => 'dir',
				'action' 	  => 'check'
			],
			[
				'path' 		  => $this->TEMP_PATH,
				'permissions' => 0755,
				'type' 		  => 'dir',
				'action' 	  => 'check'
			],
			[
				'path' 		  => $this->PROVISIONER_PATH,
				'permissions' => 0755,
				'type' 		  => 'dir',
				'action' 	  => 'check'
			],
			[
				'path' 		  => $this->EXPORT_PATH,
				'permissions' => 0755,
				'type' 		  => 'dir',
				'action' 	  => 'check'
			],
		];

		if ($install)
		{
			$operations[] = [
				'path'	 => $this->system->buildPath($this->PHONE_MODULES_PATH, "setup.php"),
				'action' => 'del'
			];
		}

		foreach ($operations as $operation)
		{
			$path 			= $operation['path'] 				?? '';
			$permissions	= $operation['permissions'] 		?? 0755;
			$recursive		= $operation['recursive']			?? true;
			$action			= strtolower($operation['action'])	?? 'check';
			$action_error	= $operation['action_error'] 		?? '';
			$action_return 	= true;

			if (empty($path))
			{
				continue;
			}

			switch(strtolower($action))
			{
				case 'check':
					if (!file_exists($path))
					{
						if ($operation['type'] === 'dir')
						{
							$action_return = mkdir($path, $permissions, $recursive);
						}
						else
						{
							$action_return = touch($path);
							if ($action_return)
							{
								$action_return = chmod($path, $permissions);
							}
						}
					}
					else
					{
						// Get only the permission bits
						$currentPermissions = fileperms($path) & 0777;
						if ($currentPermissions !== $permissions)
						{
							$action_return = chmod($path, $permissions);
						}
					}
					break;

				case 'del':
					if (file_exists($path))
					{
						if (is_dir($path))
						{
							$action_return = rmdir($path);
						}
						else
						{
							$action_return = unlink($path);
						}
					}
					
					break;
			}

			if ($action_return === false)
			{
				$return_data = false;
				if ($action_error === 'break')
				{
					break;
				}
			}
		}

		return $return_data;
	}

	/**
	 * Set the permissions of the files and directories needed by the module.
	 * This function is called when the freePBX run the chown command
	 */
	public function chownFreepbx()
	{
		$files = array();
		$files[] = array(
			'type'  => 'dir',
			'path'  => $this->PHONE_MODULES_PATH,
			'perms' => 0755
		);
		$files[] = array(
			'type'  => 'file',
			'path'  => $this->system->buildPath($this->PHONE_MODULES_PATH, "setup.php"),
			'perms' => 0755
		);
		$files[] = array(
			'type'  => 'dir',
			'path'  => '/tftpboot',
			'perms' => 0755
		);
		return $files;
	}

	/**
	 * Allow or deny access to ajax requests
	 * 
	 * @param string $req The request command ($_REQUEST['command'])
	 * @param array $setting The setting array to be passed by reference
	 * 						$setting['authenticate'] = true/false (default true), if true, the request will be authenticated
	 * 						$setting['allowremote']  = true/false (default false), if true, the request will be allowed from remote
	 * @return bool True if the request is allowed, false otherwise
	 */
	public function ajaxRequest($req, &$setting)
	{
		$request 	= freepbxGetSanitizedRequest();

		$data = array(
			'epm' 		 => $this,
			'request' 	 => $request,
			'module_sec' => strtolower(trim($request['module_sec'] ?? '')),
		);

		switch($data['module_sec'])
		{
			case "epm_ajax":
				$setting['authenticate'] = true;
				$setting['allowremote']  = false;
				$return_status = in_array($req, array('model', 'template', 'mtemplate', 'template2', 'model_clone', 'lines'), true);
			break;

			case "epm_devices":
				$return_status = $this->epm_devices->ajaxRequest($req, $setting);
			break;

			case "epm_oss":
				$return_status = $this->epm_oss->ajaxRequest($req, $setting);
			break;

			case "epm_placeholders":
				$return_status = $this->epm_placeholders->ajaxRequest($req, $setting);
			break;

			case "epm_config":
				$return_status = $this->epm_config->ajaxRequest($req, $setting, $data);
			break;

			case "epm_advanced":
				$return_status = $this->epm_advanced->ajaxRequest($req, $setting, $data);
			break;

			case "epm_templates":
				$return_status = $this->epm_templates->ajaxRequest($req, $setting, $data);
			break;

			default:
				$return_status = false;
		}
        return $return_status;
    }

	/**
	 * When making an ajax query to the module, this function is in charge of processing it and returning with the results
	 * 
	 * @return array The data to be returned to the client
	 * 				$data_return['status'] = true/false
	 * 				$data_return['message'] = string
	 * 
	 */
    public function ajaxHandler()
	{
		$request = freepbxGetSanitizedRequest();
		$data = array(
			'epm' 		 => $this,
			'request' 	 => $request,
			'module_sec' => strtolower(trim($request['module_sec'] ?? '')),
			'module_tab' => strtolower(trim($request['module_tab'] ?? '')),
			'command' 	 => strtolower(trim($request['command']    ?? '')),
		);

		if (empty($data['command']))
		{
			$data_return = array( "status" => false, "message" => _("No command was sent!") );
		}
		else
		{
			switch ($data['module_sec'])
			{
				case "epm_devices":
					$data_return = $this->epm_devices->ajaxHandler($data['module_tab'], $data['command']);
				break;

				case "epm_oss":
					$data_return = $this->epm_oss->ajaxHandler($data['module_tab'], $data['command']);
				break;

				case "epm_placeholders":
					$data_return = $this->epm_placeholders->ajaxHandler($data['module_tab'], $data['command']);
				break;

				case "epm_templates":
					$data_return = $this->epm_templates->ajaxHandler($data);
				break;

				case "epm_config":
					$data_return = $this->epm_config->ajaxHandler($data);
				break;

				case "epm_advanced":
					$data_return = $this->epm_advanced->ajaxHandler($data);
				break;

				case "epm_ajax":
					$id = $request['id'] ?? null;
					
					//Although id == "0" is redundant since emty encompasses it, it is left for better code compression.
					if(empty($id) or $id == "0")
					{
						$data_return = array(
							0 => array(
								"optionValue"  => "",
								"optionDisplay" => ""
							)
						);
					}
					else
					{
						$macid = $request['macid'] ?? null;
						$mac   = $request['mac'] ?? null;

						switch($data['command'])
						{
							case "model":
								$sql = sprintf("SELECT * FROM %s WHERE enabled = 1 AND brand = %s", self::TABLES['epm_model_list'], $id);
								break;
							
							case "template":
								$sql = sprintf("SELECT id, name as model FROM  %s WHERE  product_id = '%s'", self::TABLES['epm_template_list'], $id);
								break;

							case "mtemplate":
								$sql = sprintf("SELECT id, name as model FROM  %s WHERE  model_id = '%s'", self::TABLES['epm_template_list'], $id);
								break;

							case "template2":
								$sql = sprintf(
									"SELECT DISTINCT etl.id, etl.name as model FROM %s as etl, %s as eml, %s as epl
									WHERE etl.product_id = eml.product_id AND eml.product_id = epl.id AND eml.id = '%s'",
									self::TABLES['epm_template_list'], self::TABLES['epm_model_list'], self::TABLES['epm_product_list'], $id
								);
								break;

							case "model_clone":
								$sql = sprintf(
									"SELECT eml.id, eml.model as model FROM %s as eml, %s as epl
									WHERE epl.id = eml.product_id AND eml.enabled = 1 AND eml.hidden = 0 AND product_id = '%s'",
									self::TABLES['epm_model_list'], self::TABLES['epm_product_list'], $id
								);
								break;

							case "lines":
								if(!empty($macid))
								{
									$sql = sprintf(
										"SELECT eml.max_lines FROM %s as eml, %s as ell, %s as emacl
										WHERE emacl.id = ell.mac_id AND eml.id = emacl.model AND ell.luid = %s",
										self::TABLES['epm_model_list'], self::TABLES['epm_line_list'], self::TABLES['epm_mac_list'], $macid
									);
								}
								elseif(!empty($mac))
								{
									$sql   = sprintf("SELECT id FROM %s WHERE mac = '%s'", self::TABLES['epm_mac_list'], $this->system->mac_check_clean($mac));
									$macid = $this->eda->sql($sql, 'getOne');
									if($macid)
									{
										$mac = $macid;
										$sql = sprintf(
											"SELECT eml.max_lines FROM %s as eml, %s as ell, %s as emacl
											WHERE emacl.id = ell.mac_id AND eml.id = emacl.model AND emacl.id = %s",
											self::TABLES['epm_model_list'], self::TABLES['epm_line_list'], self::TABLES['epm_mac_list'], $macid
										);
									}
									else
									{
										$mac = null;
										$sql = sprintf("SELECT max_lines FROM %s WHERE id = '%s'", self::TABLES['epm_model_list'], $id);
									}
								}
								else
								{
									$sql = sprintf("SELECT max_lines FROM %s WHERE id = '%s'", self::TABLES['epm_model_list'], $id);
								}
								break;	
						}

						switch($data['command'])
						{
							case "template":
							case "template2":
							case "mtemplate":
								$out[0]['optionValue'] = 0;
								$out[0]['optionDisplay'] = _("Custom...");
								$i=1;
								break;

							case "model":
								$out[0]['optionValue'] = 0;
								$out[0]['optionDisplay'] = "";
								$i=1;
								break;

							default:
								$i=0;
						}

						
					
						$result = array();
						if(($data['command'] == "lines") && (!empty($mac)) && (!empty($macid)))
						{
							$count = $this->eda->sql($sql, 'getOne');
							for($z=0; $z<$count; $z++)
							{
								$result[$z] = array(
									'id' => $z + 1,
									'model' => $z + 1,
								);
							}
						}
						elseif(!empty($macid))
						{
							$result = $this->linesAvailable($macid);
						}
						elseif(!empty($mac))
						{
							$result = $this->linesAvailable(NULL, $mac);
						}
						else
						{
							$result = $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
						}

						$data_return = array();
						foreach($result as $row)
						{
							if((!empty($macid)) OR (!empty($mac)))
							{
								$data_return[$i] = array(
									'optionValue'   => $row['value'],
									'optionDisplay' => $row['text'],
								);
							}
							else
							{
								$data_return[$i] = array(
									'optionValue'   => $row['id'],
									'optionDisplay' => $row['model'],
								);
							}
							$i++;
						}
					}
				break;

				default:
					$data_return = array("status" => false, "message" => _("Invalid section module '%s'!", $data['module_sec']));
			}
		}
		return $data_return;
    }






	public static function myDialplanHooks()
	{
		return true;
	}

	public function doDialplanHook(&$ext, $engine, $priority)
	{
		global $core_conf;

		if ($engine != "asterisk") { return; }

		// if (isset($core_conf) && is_a($core_conf, "core_conf") && (method_exists($core_conf, 'addSipNotify')))
		if (isset($core_conf) && $core_conf instanceof core_conf && method_exists($core_conf, 'addSipNotify')) {
			$sipNotifications = [
				'polycom-check-cfg' 	 => ['Event' => 'check-sync', 'Content-Length' => '0'],
				'polycom-reboot' 		 => ['Event' => 'check-sync', 'Content-Length' => '0'],
				'sipura-check-cfg' 		 => ['Event' => 'resync', 'Content-Length' => '0'],
				'grandstream-check-cfg'  => ['Event' => 'sys-control'],
				'cisco-check-cfg' 		 => ['Event' => 'check-sync', 'Content-Length' => '0'],
				'reboot-snom' 			 => ['Event' => 'reboot', 'Content-Length' => '0'],
				'aastra-check-cfg' 		 => ['Event' => 'check-sync', 'Content-Length' => '0'],
				'linksys-cold-restart' 	 => ['Event' => 'reboot_now', 'Content-Length' => '0'],
				'linksys-warm-restart' 	 => ['Event' => 'restart_now', 'Content-Length' => '0'],
				'spa-reboot' 			 => ['Event' => 'reboot', 'Content-Length' => '0'],
				'reboot-yealink' 		 => ['Event' => 'check-sync;reboot=true', 'Content-Length' => '0'],
				'reboot-gigaset' 		 => ['Event' => 'check-sync;reboot=true', 'Content-Length' => '0'],
				'panasonic-check-cfg' 	 => ['Event' => 'check-sync', 'Content-Length' => '0'],
				'snom-check-cfg' 		 => ['Event' => 'check-sync', 'Content-Length' => '0'],
			];

			foreach ($sipNotifications as $name => $params)
			{
				$core_conf->addSipNotify($name, $params);
			}
		}
	}	

	public static function myGuiHooks()
	{
		return array("core");
	}

	// Called when generating the page
	public function doGuiHook(&$cc)
	{
		$request = freepbxGetSanitizedRequest();
		$display = $request['display'] ?? '';


		if (!in_array($display, array('extensions', 'devices')))
		{
			return;
		}
		
		$action     = $request['action'] 	 	?? null;
		$extdisplay = $request['extdisplay'] 	?? null;
		$tech		= $request['tech_hardware'] ?? null;

		if (!empty($extdisplay))
		{
			$sql = sprintf("SELECT tech FROM %s WHERE id = %s", self::TABLES['devices'], $extdisplay);
			// $tech = $this->eda->sql($sql, 'getOne');
			$tech = $this->eda->sql($sql, 'getOne');
		}

		$extension_address = $this->astman->database_get("SIP", "Registry/".$extdisplay);
		$extension_address = explode(":", $extension_address);
		// echo $extension_address['0'];

		if (in_array($tech, array('sip', 'pjsip', 'sip_generic')))
		{
			// Don't display this stuff it it's on a 'This xtn has been deleted' page.
			if ($action != 'del')
			{
				$section = _('End Point Manager');

				$js = "
					$.ajaxSetup({ cache: false });
					$.ajax({
						url: window.FreePBX.ajaxurl,
						type: 'POST',
						data: {
							module: 'endpointman',
							module_sec: 'epm_ajax',
							module_tab: '',
							command: 'model',
							id: value
						},
						dataType: 'json',
						error: function(xhr, ajaxOptions, thrownError) {
							fpbxToast('"._('ERROR AJAX:')." ' + thrownError,'ERROR (' + xhr.status + ')!','error');
							return false;
						},
						success: function(j) {
							var options = '';
							for (var i = 0; i < j.length; i++) {
								options += sprintf('<option value=\"%s\">%s</option>', j[i].optionValue, j[i].optionDisplay);
							}
							$('#epm_model').html(options);
							$('#epm_model option:first').attr('selected', 'selected');
							$('#epm_temps').html('<option></option>');
							$('#epm_temps option:first').attr('selected', 'selected');
							$('#epm_line').html('<option></option>');
							$('#epm_line option:first').attr('selected', 'selected');
						}
					});
				";
				$cc->addjsfunc('brand_change(value)', $js);
				unset($js);

				// $sql = sprintf("SELECT mac_id, luid, line FROM %s WHERE ext = '%s'", self::TABLES['epm_line_list'], $extdisplay);
				// $line_info = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
				
				$sql = sprintf("SELECT * FROM %s WHERE ext = '%s'", self::TABLES['epm_line_list'], $extdisplay);
				$line_info = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
				if ($line_info)
				{
					$js = "
						$.ajaxSetup({ cache: false });
						$.ajax({
							url: window.FreePBX.ajaxurl,
							type: 'POST',
							data: {
								module: 'endpointman',
								module_sec: 'epm_ajax',
								module_tab: '',
								command: 'template2',
								id: value
							},
							dataType: 'json',
							error: function(xhr, ajaxOptions, thrownError) {
								fpbxToast('"._('ERROR AJAX:')." ' + thrownError,'ERROR (' + xhr.status + ')!','error');
								return false;
							},
							success: function(j) {
								var options = '';
								for (var i = 0; i < j.length; i++) {
									options += sprintf('<option value=\"%s\">%s</option>', j[i].optionValue, j[i].optionDisplay);
								}
								$('#epm_temps').html(options);
								$('#epm_temps option:first').attr('selected', 'selected');
							}
						});

						$.ajaxSetup({ cache: false });
						$.ajax({
							url: window.FreePBX.ajaxurl,
							type: 'POST',
							data: {
								module: 'endpointman',
								module_sec: 'epm_ajax',
								module_tab: '',
								macid: macid,
								command: 'lines',
								id: value
							},
							dataType: 'json',
							error: function(xhr, ajaxOptions, thrownError) {
								fpbxToast('"._('ERROR AJAX:')." ' + thrownError,'ERROR (' + xhr.status + ')!','error');
								return false;
							},
							success: function(j) {
								var options = '';
								for (var i = 0; i < j.length; i++) {
									options += sprintf('<option value=\"%s\">%s</option>', j[i].optionValue, j[i].optionDisplay);
								}
								$('#epm_line').html(options);
								$('#epm_line option:first').attr('selected', 'selected');
							}
						});
					";
					$cc->addjsfunc('model_change(value,macid)', $js);
					unset($js);

					$info = $this->get_phone_info($line_info['mac_id']);

					$brand_list = $this->brands_available($info['brand_id'], true);
					if (!empty($info['brand_id']))
					{
						$model_list 	= $this->models_available(NULL, $info['brand_id']);
						$line_list		= $this->linesAvailable($line_info['luid']);
						$template_list 	= $this->display_templates($info['product_id']);
					}
					else
					{
						$model_list 	= array();
						$line_list 		= array();
						$template_list  = array();
					}

					$checked = false;
					$cc->addguielem($section, new \gui_checkbox('epm_delete', $checked, _('Delete'), _('Delete this Extension from Endpoint Manager')), 9);
					$cc->addguielem($section, new \guitext('epm_account_phone', sprintf('<a href="%s" target="_blank" id ="%s">%s</a>', sprintf("http://%s", $extension_address[0]), "epm_account_phone", _('Go to phone web interface')) ));
					$cc->addguielem($section, new \gui_textbox('epm_mac', $info['mac'], _('MAC Address'), _('The MAC Address of the Phone Assigned to this Extension/Device. <br />(Leave Blank to Remove from Endpoint Manager)'), '', _('Please enter a valid MAC Address'), true, 17, false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_brand', $brand_list, $info['brand_id'], _('Brand'), _('The Brand of this Phone.'), false, 'frm_' . $display . '_brand_change(this.options[this.selectedIndex].value)', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_model', $model_list, $info['model_id'], _('Model'), _('The Model of this Phone.'), false, 'frm_' . $display . '_model_change(this.options[this.selectedIndex].value,\'' . $line_info['luid'] . '\')', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_line', $line_list, $line_info['line'], _('Line'), _('The Line of this Extension/Device.'), false, '', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_temps', $template_list, $info['template_id'], _('Template', 'The Template of this Phone.'), false, '', false), 9);
					$cc->addguielem($section, new \gui_checkbox('epm_reboot', $checked, _('Reboot'), _('Reboot this Phone on Submit')), 9);
				}
				else
				{

					$js = "
						$.ajaxSetup({ cache: false });
						$.ajax({
							url: window.FreePBX.ajaxurl,
							type: 'POST',
							data: {
								module: 'endpointman',
								module_sec: 'epm_ajax',
								module_tab: '',
								command: 'template2',
								id: value
							},
							dataType: 'json',
							error: function(xhr, ajaxOptions, thrownError) {
								fpbxToast('"._('ERROR AJAX:')." ' + thrownError,'ERROR (' + xhr.status + ')!','error');
								return false;
							},
							success: function(j) {
								var options = '';
								for (var i = 0; i < j.length; i++) {
									options += sprintf('<option value=\"%s\">%s</option>', j[i].optionValue, j[i].optionDisplay);
								}
								$('#epm_temps').html(options);
								$('#epm_temps option:first').attr('selected', 'selected');
							}
						});

						$.ajaxSetup({ cache: false });
						$.ajax({
							url: window.FreePBX.ajaxurl,
							type: 'POST',
							data: {
								module: 'endpointman',
								module_sec: 'epm_ajax',
								module_tab: '',
								mac: mac,
								command: 'lines',
								id: value
							},
							dataType: 'json',
							error: function(xhr, ajaxOptions, thrownError) {
								fpbxToast('"._('ERROR AJAX:')." ' + thrownError,'ERROR (' + xhr.status + ')!','error');
								return false;
							},
							success: function(j) {
								var options = '';
								for (var i = 0; i < j.length; i++) {
									options += sprintf('<option value=\"%s\">%s</option>', j[i].optionValue, j[i].optionDisplay);
								}
								$('#epm_line').html(options);
								$('#epm_line option:first').attr('selected', 'selected');
							}
						});
					";
					$cc->addjsfunc('model_change(value,mac)', $js);
					unset($js);

					$brand_list 	= $this->brands_available(NULL, true);
					$model_list 	= array();
					$line_list 		= array();
					$template_list 	= array();

					$cc->addguielem($section, new \gui_textbox('epm_mac', "", _('MAC Address'), _('The MAC Address of the Phone Assigned to this Extension/Device. <br />(Leave Blank to Remove from Endpoint Manager)'), '', _('Please enter a valid MAC Address'), true, 17, false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_brand', $brand_list, "", _('Brand'), _('The Brand of this Phone.'), false, 'frm_' . $display . '_brand_change(this.options[this.selectedIndex].value)', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_model', $model_list, "", _('Model'), _('The Model of this Phone.'), false, 'frm_' . $display . '_model_change(this.options[this.selectedIndex].value,document.getElementById(\'epm_mac\').value)', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_line', $line_list, "", _('Line'), _('The Line of this Extension/Device.'), false, '', false), 9);
					$cc->addguielem($section, new \gui_selectbox('epm_temps', $template_list, "", _('Template'), _('The Template of this Phone.'), false, '', false), 9);
					$cc->addguielem($section, new \guitext('epm_note', _('Note: This might reboot the phone if it\'s already registered to Asterisk')));
				}
			}
		}
		return;
	}



	/**
	 * Get the list of pages that the module will be displayed on the GUI
	 */
	public static function myConfigPageInits()
	{
		return array("extensions", "devices");
	}

	/**
	 * Get Inital Display
	 * @param {string} $display The Page name
	 */
	public function doConfigPageInit($display)
	{
		$request = freepbxGetSanitizedRequest();

		//TODO: Pendiente revisar y eliminar moule_tab.
		$data = array(
			'epm' 		 => $this,
			'request' 	 => $request,
			'module_sec' => $display,
			'module_tab' => strtolower(trim($request['module_tab'] ?? $request['subpage'] ?? '')),
			'command' 	 => strtolower(trim($request['command'] ?? '')),
		);

		// //TODO: Pendiente revisar y eliminar moule_tab.
		// $module_tab = isset($request['module_tab'])? trim($request['module_tab']) : '';
		// if ($module_tab == "") {
		// 	$module_tab = isset($request['subpage'])? trim($request['subpage']) : '';
		// }
		// $command = isset($request['command'])? trim($request['command']) : '';

		$extdisplay = '';
		$step2 		= false;
		switch ($display)
		{
			case "epm_devices":
				$this->epm_devices->doConfigPageInit($data['module_tab'], $data['command']);
				break;

			case "epm_oss":
				$this->epm_oss->doConfigPageInit($data['module_tab'], $data['command']);
				break;

			case "epm_placeholders":
				$this->epm_placeholders->doConfigPageInit($data['module_tab'], $data['command']);
				break;

			case "epm_templates":
				$this->epm_templates->doConfigPageInit($data['module_tab'], $data['command']);
				break;

			case "epm_config":
				$this->epm_config->doConfigPageInit($data);
				break;

			case "epm_advanced":
				$this->epm_advanced->doConfigPageInit($data);
				break;
			
			case "extensions":
				$extdisplay = $request['extension'] ?? $request['extdisplay'] ?? null;
				$step2 = true;
				break;
	
			case "devices":
				$extdisplay = $request['deviceid'] ?? $request['extdisplay'] ?? null;
				$step2 = true;
				break;

			default:
				// die(_("Invalid section module!"));
				return true;
		}

		if (!$step2)
		{
			return true;
		}


		$type       = '';
		$tech       = '';

		if (isset($extdisplay) && !empty($extdisplay))
		{
			$sql = "SELECT tech FROM devices WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $extdisplay]);
			if ($stmt->rowCount() === 0)
			{
				$tech = "sip";
				$type = 'new';
			}
			else
			{
				$tech = $stmt->fetch(\PDO::FETCH_ASSOC);
				if(in_array($tech, ['sip', 'pjsip']))
				{
					$type = 'edit';
				}
			}
		}
		elseif(isset($request['tech_hardware']) OR isset($request['tech']))
		{
			$tech = $request['tech_hardware'] ?? $request['tech'];
			if (in_array($tech, ['sip_generic', 'sip', 'pjsip']))
			{
				$tech = "sip";
				$type = 'new';
			}
		}

		if ((($tech == 'sip') OR ($tech == 'pjsip')) AND (!empty($type)))
		{
			$action = $request['action'] ?? null;
			$delete = $request['epm_delete'] ?? null;


			if (true)
			{

				switch($action)
				{
					case "del":
						$sql = sprintf("SELECT mac_id, luid FROM %s WHERE ext = %s", self::TABLES['epm_line_list'], $extdisplay);
						// $macid = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
						$macid = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
						if ($macid) {
							$this->delete_line($macid['luid'], TRUE);
						}
						break;

					case "add":
					case "edit":
						if (isset($delete))
						{
							$sql = sprintf("SELECT mac_id, luid FROM %s WHERE ext = %s", self::TABLES['epm_line_list'], $extdisplay);
							// $macid = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
							$macid = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
							if ($macid) {
								$this->delete_line($macid['luid'], TRUE);
							}
						}
	
						$mac = isset($request['epm_mac']) ? $request['epm_mac'] : null;
						if (!empty($mac))
						{
							//Mac is set
							$brand = isset($request['epm_brand']) ? $request['epm_brand'] : null;
							$model = isset($request['epm_model']) ? $request['epm_model'] : null;
							$line  = isset($request['epm_line']) ? $request['epm_line'] : null;
							$temp  = isset($request['epm_temps']) ? $request['epm_temps'] : null;
							if (isset($request['name']))
							{
								$name = isset($request['name']) ? $request['name'] : null;
							}
							else
							{
								$name = isset($request['description']) ? $request['description'] : null;
							}
							if (isset($request['deviceid']))
							{
								if ($request['devicetype'] == "fixed")
								{
									//SQL to get the Description of the  extension from the extension table
									$sql = sprintf("SELECT name FROM %s WHERE extension = '%s'", "users", $request['deviceuser']);
									// $name_o = $this->eda->sql($sql, 'getOne');
									$name_o = $this->eda->sql($sql, 'getOne');
									if($name_o) {
										$name = $name_o;
									}
								}
							}
	
							$reboot = isset($request['epm_reboot']) ? $request['epm_reboot'] : null;
	
							if ($this->system->mac_check_clean($mac))
							{
								$sql = sprintf("SELECT id FROM %s WHERE mac = '%s'", "endpointman_mac_list", $this->system->mac_check_clean($mac));
								// $macid = $this->eda->sql($sql, 'getOne');
								$macid = $this->eda->sql($sql, 'getOne');
								if ($macid)
								{
									//In Database already
	
									$sql = sprintf('SELECT * FROM %s WHERE ext = %s AND mac_id = %s', self::TABLES['epm_line_list'], $extdisplay, $macid);
									// $lines_list = & $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
									$lines_list = & $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
	
									if (($lines_list) AND (isset($model)) AND (isset($line)) AND (!isset($delete)) AND (isset($temp)))
									{
										//Modifying line already in the database
										$this->update_device($macid, $model, $temp, $lines_list['luid'], $name, $lines_list['line']);
	
										$this->rebuild_device($macid, isset($reboot));
									}
									elseif ((isset($model)) AND (!isset($delete)) AND (isset($line)) AND (isset($temp)))
									{
										//Add line to the database
	
										if (empty($line))
										{
											$this->add_line($macid, NULL, $extdisplay, $name);
										}
										else
										{
											$this->add_line($macid, $line, $extdisplay, $name);
										}
	
										$this->update_device($macid, $model, $temp, NULL, NULL, NULL, FALSE);
	
										$this->rebuild_device($macid, isset($reboot));
									}
								}
								elseif (!isset($delete))
								{
									//Add Extension/Phone to database
									$mac_id = $this->add_device($mac, $model, $extdisplay, $temp, NULL, $name);
	
									if ($mac_id)
									{
										$this->rebuild_device($mac_id, isset($reboot));
									}
								}
							}
						}
						break;
				}

				// global $currentcomponent;
				// Add the 'process' function - this gets called when the page is loaded, to hook into
				// displaying stuff on the page.
				// $currentcomponent->addguifunc('endpointman_configpageload');
			}
    	}


	}


	
	



	public function doGeneralPost()
	{
		if (!isset($_REQUEST['Submit'])) 	{ return; }
		if (!isset($_REQUEST['display'])) 	{ return; }

		needreload();
	}

	public function myShowPage()
	{
		$pagedata = array();
		$request  = freepbxGetSanitizedRequest();
		$data     = array(
			'epm' 	  => $this,
			'request' => $request,
			'display' => $request['display'] ?? '',
		);
		
		switch ($data['display'])
		{
			case "epm_devices":
				$this->epm_devices->myShowPage($pagedata);
				break;

			case "epm_oss":
				return $this->epm_oss->myShowPage($pagedata);
				break;

			case "epm_placeholders":
				return $this->epm_placeholders->myShowPage($pagedata);
				break;

			case "epm_templates":
				$this->epm_templates->myShowPage($pagedata, $data);
				return $pagedata;
				break;

			case "epm_config":
				$this->epm_config->myShowPage($pagedata, $data);
				break;

			// case "epm_advanced":
			// 	$this->epm_advanced->myShowPage($pagedata, $data);
			// 	break;

			case "":
			default:
				return $pagedata;
		}

		if(! empty($pagedata))
		{
			foreach($pagedata as &$page)
			{
				if (empty($page['page'] ?? ''))
				{
					continue;
				}
				$page['content'] = load_view($this->system->buildPath(__DIR__, $page['page']), $data);
			}
			return $pagedata;
		}
	}

	public function showPage($page, $params = array())
	{
		$request = freepbxGetSanitizedRequest();
		$data = array(
			"epm"		    => $this,
			'request'	    => $request,
			'page' 		    => $page ?? '',
			'endpoint_warn' => "",
		);

		if ($this->isActiveModule('endpoint'))
		{
			if ($this->getConfig("disable_endpoint_warning") !== "1")
			{
				$data['endpoint_warn'] = load_view(__DIR__."/views/page.epm_warning.php", $data);
			}
		}

		$data = array_merge($data, $params);
		switch ($page) 
		{
			case 'main.oos':
				$data_return = load_view(__DIR__."/views/page.main.oos.php", $data);
			break;
			
			case "main.placeholders":
				$data_return = load_view(__DIR__."/views/page.main.placeholders.php", $data);
			break;

			case "main.config":
				$data_return = load_view(__DIR__."/views/page.main.config.php", $data);
			break;

			case "main.templates":
				$this->epm_templates->showPage($data);
				$data_return = load_view(__DIR__."/views/page.main.templates.php", $data);
			break;

			case "main.advanced":
				$this->epm_advanced->showPage($data);
				$data_return = load_view(__DIR__."/views/page.main.advanced.php", $data);
				break;

			case "main.devices":
				$this->epm_devices->showPage($data);
				$data_return = load_view(__DIR__."/views/page.main.devices.php", $data);
				break;

			default:
				$data_return = sprintf(_("Page Not Found (%s)!!!!"), $page);
		}
		return $data_return;
	}

	public function getActiveModules()
	{
		global $active_modules;
		return $active_modules;
	}

	public function isActiveModule($name)
	{
		$modules = $this->getActiveModules();
		return !empty($modules[$name]['rawname']);
	}

	//http://wiki.freepbx.org/display/FOP/Adding+Floating+Right+Nav+to+Your+Module
	public function getRightNav($request, $params = array())
	{
		$data = array(
			"epm" 	  => $this,
			"request" => $request,
			"display" => strtolower(trim($request['display'] ?? '')),
		);
		$data = array_merge($data, $params);

		switch($data['display'])
		{
			case 'epm_oss':
			case 'epm_devices':
			case 'epm_placeholders':
			case 'epm_config':
			case 'epm_advanced':
			case 'epm_templates':
				$data_return = load_view(__DIR__.'/views/rnav.php', $data);
				break;

			default:
				$data_return = "";
		}
		return $data_return;
	}

	//http://wiki.freepbx.org/pages/viewpage.action?pageId=29753755
	public function getActionBar($request)
	{
		$data = array(
			"epm" 	  => $this,
			"request" => $request,
			"display" => strtolower(trim($request['display'] ?? '')),
		);

		switch($data['display'])
		{
			case "epm_devices":
				$data_return = $this->epm_devices->getActionBar($request);
				break;

			case "epm_oss":
				$data_return = $this->epm_oss->getActionBar($request);
				break;

			case "epm_placeholders":
				$data_return = $this->epm_placeholders->getActionBar($request);
				break;

			case "epm_config":
				$data_return = $this->epm_config->getActionBar($data);
				break;

			case "epm_advanced":
				$data_return = $this->epm_advanced->getActionBar($data);
				break;

			case "epm_templates":
				$data_return = $this->epm_templates->getActionBar($request);
				break;

			default:
				$data_return = "";
		}
		return $data_return;
	}

	public function install()
	{
		out(_("⚡ Endpoint Manager Installer ..."));
		outn(_("⚡ Createing Structure directories..."));
		$this->checkPathsFiles();
		out(" ✔");

		outn(_('⚡ Creating symlink to web provisioner...'));
		$provisioning_lnk = $this->system->buildPath($this->config->get('AMPWEBROOT'), "provisioning");
		$provisioning_src = $this->system->buildPath($this->MODULE_PATH, "provisioning");
		if (file_exists($provisioning_lnk))
		{
			if (is_link($provisioning_lnk))
			{
				$pathNowFile = readlink($provisioning_lnk);
				if ($pathNowFile === $provisioning_src)
				{
					out(_(" Skipped (already exists) ✔"));
				}
				else
				{
					out(" ❌");
					out(sprintf("<strong>%s</strong", sprintf(_("The file '%s' already exists and not pointing to the correct location, location is '%s'!")), $provisioning_lnk, $pathNowFile));
				}
			}
			else
			{
				out(" ❌");
				out(sprintf("❌ <strong>%s</strong", sprintf(_("The file '%s' already exists and is not a symbolic link!")), $provisioning_lnk));
			}
		}
		else
		{
			if (! is_writable($this->config->get('AMPWEBROOT')))
			{
				out(" ❌");
				out(sprintf("❌ <strong>%s</strong", sprintf(_("Your permissions are wrong on '%s', web provisioning link not created!")), $this->config->get('AMPWEBROOT')));
			
			}
			else if (!symlink($provisioning_src, $provisioning_lnk))
			{
				out(" ❌");
				out(sprintf("❌ <strong>%s</strong", sprintf(_("Failed to create symbolic link '%s' to '%s'!")), $provisioning_lnk, $provisioning_src));
			}
			else
			{
				out(" ✔");
			}
		}

		// if (!file_exists($this->PHONE_MODULES_PATH . "/setup.php"))
		// {
		// 	outn(_("Moving Auto Provisioner Class..."));
    	// 	copy($this->MODULE_PATH . "/install/setup.php", $this->PHONE_MODULES_PATH . "/setup.php");
		// 	out(_("OK"));
		// }

		$modinfo 	   = module_getinfo('endpointman');
		$epmxmlversion = $modinfo['endpointman']['version'];
		$epmdbversion  = !empty($modinfo['endpointman']['dbversion']) ? $modinfo['endpointman']['dbversion'] : null;
		
		outn(_("⚡ Inserting Config Global and Updating..."));
		$dataDefault = [
			'srvip' 					=> '',
			'srvport' 					=> '5060',
			'tz' 						=> '',
			'gmtoff' 					=> '',
			'gmthr' 					=> '',
			'config_location' 			=> '/tftpboot/',
			'update_server' 			=> self::URL_PROVISIONER,
			'version' 					=> '',
			'enable_ari' 				=> '0',
			'debug' 					=> '0',
			'arp_location' 				=> $this->system->find_exec("arp", false, false),
			'nmap_location' 			=> $this->system->find_exec("nmap", false, false),
			'asterisk_location' 		=> $this->system->find_exec("asterisk", false, false),
			'tar_location' 				=> $this->system->find_exec("tar", false, false),
			'netstat_location' 			=> $this->system->find_exec("netstat", false, false),
			'whoami_location' 			=> $this->system->find_exec("whoami", false, false),
			'nohup_location' 			=> $this->system->find_exec("nohup", false, false),
			'groups_location' 			=> $this->system->find_exec("groups", false, false),
			'language' 					=> '',
			'check_updates' 			=> '0',
			'disable_htaccess' 			=> '',
			'endpoint_vers' 			=> '0',
			'disable_help' 				=> '0',
			'show_all_registrations' 	=> '0',
			'ntp' 						=> '',
			'server_type' 				=> 'file',
			'allow_hdfiles' 			=> '0',
			'tftp_check' 				=> '0',
			'nmap_search' 				=> '',
			'backup_check' 				=> '0',
			'use_repo' 					=> '0',
			'adminpass' 				=> '123',
			'userpass' 					=> '111',
			'intsrvip' 					=> '',
			'disable_endpoint_warning' 	=> '0',
		];
		foreach ($dataDefault as $key => $val)
		{
			// Get the config if it exists (database old or new system kvstore), if not create it with the default value.
			//TODO: Pending remove old table 'endpointman_global_vars'
			$config = $this->getConfig($key, $val);
			switch ($key)
			{
				case "update_server":
					if ($epmdbversion < "17.0.0.0")
					{
						$config = self::URL_PROVISIONER;
					}
					break;

				case "version":
					$config = $epmxmlversion;
					break;
			}
			$this->setConfig($key, $config);
		}
		out(_(" ✔"));
		out(" ");
	}

	public function uninstall()
	{
		outn(_("⚡ Removing Structure Directories..."));
		if(file_exists($this->PHONE_MODULES_PATH))
		{
			$this->system->rmrf($this->PHONE_MODULES_PATH);
		}
		out(_(" ✔"));

		outn(_("⚡ Removing Symlink to web provisioner..."));
		$provisioning_lnk = $this->system->buildPath($this->config->get('AMPWEBROOT'), "provisioning");
		$provisioning_src = $this->system->buildPath($this->MODULE_PATH, "provisioning");
		if(file_exists($provisioning_lnk))
		{
			if (is_link($provisioning_lnk))
			{
				$provisioning_now_src = readlink($provisioning_lnk);
				if ($provisioning_now_src === $provisioning_src)
				{
					if (! unlink($provisioning_lnk))
					{
						out(_("❌"));
						out(sprintf("❌ <strong>%s</strong>", sprintf(_("Failed to remove symbolic link '%s' to '%s'!")), $provisioning_lnk, $provisioning_src));
					}
					else
					{
						out(" ✔");
					}
				}
				else
				{
					out(_("❌"));
					out(sprintf("❌ <strong>%s</strong>", sprintf(_("The file '%s' already exists and not pointing to the correct location, location is '%s, can't remove!")), $provisioning_lnk, $provisioning_now_src));
				}
			}
			else
			{
				out(_("❌"));
				out(sprintf("❌ <strong>%s</strong>", sprintf(_("The file '%s' already exists and is not a symbolic link, can't remove!")), $provisioning_lnk));
			}
		}
		else
		{
			out(" ✔");
		}
		out(" ");

		// if(!is_link($this->config->get('AMPWEBROOT').'/admin/assets/endpointman'))
		// {
		// 	$this->system->rmrf($this->config->get('AMPWEBROOT').'/admin/assets/endpointman');
		// }
		return true;
	}

	public function backup() { }
    public function restore($backup) { }

	public function setDatabase($pdo)
	{
		$this->db = $pdo;
		return $this;
	}
	
	public function resetDatabase()
	{
		$this->db = $this->FreePBX->Database;
		return $this;
	}

	private function epm_config_manual_install($install_type = "", $package ="")
	{
		if ($install_type == "") {
			throw new \Exception("Not send install_type!");
		}

		switch($install_type) {
			case "export_brand":

				break;

			case "upload_master_xml":


				$file_xml_tmp  = $this->system->buildPath($this->TEMP_PATH, 'master.xml');
				$file_xml_dest = $this->system->buildPath($this->PHONE_MODULES_PATH, 'master.xml');

				if (file_exists($file_xml_tmp)) {
					$handle = fopen($file_xml_tmp, "rb");
					$contents = stream_get_contents($handle);
					fclose($handle);
					@$a = simplexml_load_string($contents);
					if($a===FALSE) {
						echo "Not a valid xml file";
						break;
					} else {
						rename($file_xml_tmp, $file_xml_dest);
						echo "Move Successful<br />";
						$this->update_check();
						echo "Updating Brands<br />";
					}
				} else {
				}
				break;

			case "upload_provisioner":

				break;

			case "upload_brand":

				break;
		}
	}



	/**
	 * Checks if a value exists in a multidimensional array recursively.
	 *
	 * @param mixed $needle The value to search for.
	 * @param array $haystack The array to search in.
	 * @return bool Returns true if the value is found, false otherwise.
	 */
	public static function in_array_recursive($needle, $haystack)
	{
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($haystack));
        foreach ($it AS $element)
		{
            if ($element == $needle)
			{
                return TRUE;
            }
        }
        return FALSE;
    }

	

	/**
	 * Formats the given text with the specified CSS class and replaces any specified text.
	 *
	 * @param string $texto The text to be formatted.
	 * @param string $css_class The CSS class to be applied to the formatted text.
	 * @param array $remplace_txt An array of text replacements to be made in the formatted text.
	 * @return string The formatted text wrapped in a paragraph tag with the specified CSS class.
	 */
	function format_txt($texto = "", $css_class = "", $remplace_txt = array())
	{
		if (count($remplace_txt) > 0)
		{
			foreach ($remplace_txt as $clave => $valor)
			{
				$texto = str_replace($clave, $valor, $texto);
			}
		}
		return sprintf('<p class="%s">%s</p>', $css_class, $texto);
	}

	/**
	 * Generates an XML string from an array or object.
	 *
	 * This function recursively converts an array or object into an XML string.
	 * It uses the provided node name to create XML tags for each element.
	 * Numeric keys are replaced with the provided node name.
	 * The generated XML string is indented using tabs.
	 *
	 * @param array|object $array The array or object to convert to XML.
	 * @param string $node_name The name of the XML node.
	 * @param int $tab The current indentation level (default: -1).
	 * @return string The generated XML string.
	 */
	function generate_xml_from_array ($array, $node_name, &$tab = -1)
	{
		$tab++;
		$xml ="";
		if (is_array($array) || is_object($array))
		{
			foreach ($array as $key=>$value)
			{
				if (is_numeric($key))
				{
					$key = $node_name;
				}
				$xml .= sprintf("%s<%s>\n", str_repeat("	", $tab), $key);
				$xml .= $this->generate_xml_from_array($value, $node_name, $tab);
				$xml .= sprintf("%s</%s>\n", str_repeat("	", $tab), $key);

			}
		}
		else
		{
			$xml = sprintf("%s%s\n", str_repeat("	", $tab), htmlspecialchars($array, ENT_QUOTES));
		}
		$tab--;
		return $xml;
	}


	// TODO: Move to system class, only retrocompatibility
    public function file2json($file)
	{
		return $this->system->file2json($file);
    }


	





	//BulkHandler hooks
	public function bulkhandlerGetTypes()
	{
		return [
			'endpointman' => [
				'name' 		  => _('OSS End Point Manager'),
				'description' => _('Import/Export Device List')
			]
		];
	}

	public function bulkhandlerGetHeaders($type)
	{
		// <mac>,<brand>,<model>,<extension>,<line>
		// -> mac - is required (Dirección MAC)
		// ->brand - can be blank
		// ->model - can be blank
		// ->extension - can be blank
		// ->line - can be blank
		//
		// Examples:
		// -> 001122334455,Cisco/Linksys,7940,4321,1
		// -> 112233445566,Cisco/Linksys,7945,,

		$headers = [];
  		switch($type)
		{
			case 'endpointman':
				$headers = [
					'mac' => [
						'required' 	  => true,
						'identifier'  => "mac",
						'description' => _("The mac address of the device"),
					],
					'brand' => [
						'required' 	  => false,
						'identifier'  => "brand",
						'description' => _("The brand name of the device"),
					],
					'model' => [
						'required' 	  => false,
						'identifier'  => "model",
						'description' => _("The model id of the device"),
					],
					'extension' => [
						'required' 	  => false,
						'identifier'  => "extension",
						'description' => _("The extension number"),
					],
					'line' => [
						'required' 	  => false,
						'identifier'  => "line",
						'description' => _("The line of the device"),
					]
				];
			break;
		}
		return $headers;
	}

	public function bulkhandlerExport($type)
	{
		$data = null;
		switch ($type)
		{
			case 'endpointman':
				$data = $this->bulkhandlerExport_run();
			break;
		}
		return $data;
	}

	public function bulkhandlerImport($type, $rawData)
	{
		$ret = null;
		switch ($type)
		{
			case 'endpointman':
				$ret = $this->bulkhandlerImport_run($rawData);
			break;
		}
		return $ret;
	}

	public function bulkhandlerExport_run()
	{
		//TODO: Pending upate code
		// <mac>,<brand>,<model>,<extension>,<line>
		// -> mac - is required (Dirección MAC)
		// ->brand - can be blank
		// ->model - can be blank
		// ->extension - can be blank
		// ->line - can be blank
		//
		// Examples:
		// -> 001122334455,Cisco/Linksys,7940,4321,1
		// -> 112233445566,Cisco/Linksys,7945,,

		$sql = 'SELECT
			endpointman_mac_list.id,
			endpointman_mac_list.mac,
			endpointman_line_list.ipei,
			endpointman_brand_list.name,
			endpointman_model_list.model,
			endpointman_line_list.ext,
			endpointman_line_list.line 
				FROM 
					endpointman_mac_list,
					endpointman_model_list,
					endpointman_brand_list,
					endpointman_line_list
						WHERE
							endpointman_line_list.mac_id = endpointman_mac_list.id AND 
							endpointman_model_list.id = endpointman_mac_list.model AND 
							endpointman_model_list.brand = endpointman_brand_list.id
		';
		$result = sql($sql,'getAll',\PDO::FETCH_ASSOC);

		$data = [];
		foreach ($result as $row)
		{
			$data[] = [
				'id' 		=> $row['id'],
				'mac' 		=> $row['mac'],
				'ipei' 		=> $row['ipei'],
				'brand' 	=> $row['name'],
				'model' 	=> $row['model'],
				'extension' => $row['ext'],
				'line' 		=> $row['line']
			];
		}
		return $data;
	}

	public function bulkhandlerImport_run($rawData = [])
	{
		// <mac>,<brand>,<model>,<extension>,<line>
		// -> mac - is required (Dirección MAC)
		// ->brand - can be blank
		// ->model - can be blank
		// ->extension - can be blank
		// ->line - can be blank
		//
		// Examples:
		// -> 001122334455,Cisco/Linksys,7940,4321,1
		// -> 112233445566,Cisco/Linksys,7945,,

		if (is_array($rawData) && count($rawData) > 0)
		{
			foreach ($rawData as $data)
			{
				//TODO: Pending tested
				continue;

				// $data['mac']
				// $data['brand']
				// $data['model']
				// $data['extension']
				// $data['line']

				if (empty($data) || empty($data['mac']))
				{
					continue;
				}

				$mac = $this->system->mac_check_clean($data['mac']);
				if (! $mac)
				{
					// out(sprintf(_("Error: Invalid Mac on line %d!"), $i));
					continue;
				}

				
				$sql = sprintf("SELECT id FROM endpointman_brand_list WHERE name LIKE '%%%s%%' LIMIT 1", $data['brand']);
				$res = sql($sql, 'getAll', \PDO::FETCH_ASSOC);

				if (count(array($res)) == 0) {
					// out(sprintf(_("Error: Invalid Brand Specified on line %d!"), $i));
					continue;
				}


				$brand_id  = sql($sql, 'getOne');
				$sql_model = sprintf("SELECT id FROM endpointman_model_list WHERE brand = %s AND model LIKE '%%%s%%' LIMIT 1", $brand_id, $data['model']);
				$sql_ext   = sprintf("SELECT extension, name FROM users WHERE extension LIKE '%%%s%%' LIMIT 1", $data['extension']);
				$line_id   = $data['line'] ?? 1;
				$res_model = sql($sql_model);

				if (count(array($res_model)) == 0)
				{
					// out(sprintf(_("Error: Invalid Model Specified on line %d!"), $i));
					continue;
				}
	
				$model_id = sql($sql_model, 'getRow', \PDO::FETCH_ASSOC);
				$model_id = $model_id['id'];
				$res_ext  = sql($sql_ext);

				if (count(array($res_ext)) == 0)
				{
					// out(sprintf(_("Error: Invalid Extension Specified on line %d!"), $i));
					continue;
				}

				$ext 		  = sql($sql_ext, 'getRow', \PDO::FETCH_ASSOC);
				$description = $ext['name'];
				$ext 		 = $ext['extension'];

				$this->epm->add_device($mac, $model_id, $ext, 0, $line_id, $description);

				// out(_("<font color='#FF0000'><b>Please reboot & rebuild all imported phones</b></font>"));
			}
		}
		$ret = ['status' => true];
		return $ret;
	}

















	/**
	 * Retrieves the value of a configuration key.
	 *
	 * @param string $key The configuration key to retrieve.
	 * @param mixed $default The default value to return if the key is not found.
	 * @param string $section The configuration section where the key is stored. Defaults to `globalSettings`.
	 * @return mixed The value of the configuration key, or the default value if not found.
	 *
	 * @deprecated This method currently supports retrocompatibility with data saved in the database, but it will be removed in the future.
	 */
	public function getConfig($key = null, $default = false, $section = "globalSettings")
	{
		// dbug("getConfig: $key, Default: $default");
		$data = $default;
		if (empty($section))
		{
			$section = "globalSettings";
		}
		if (! empty($key))
		{
			// TODO: Retrocompatibility data saved in the database 
			$where = array(
				'var_name' => array( 'value' => 'endpoint_vers', 'operator' => "LIKE")
			);
			$old_value =  $this->get_database_data(self::TABLES['epm_global_vars'], $where, 'value');
			// TODO: Retrocompatibility data saved in the database

			if ( $this->isConfigExist($key, $section))
			{
				$data = parent::getConfig($key, $section);
			}
			elseif (! empty($old_value))
			{
				$data = $old_value;
			}
			else
			{
				$data = $default;
			}
		}
		return $data;
	}

	/**
	 * Check if a configuration key exists.
	 * 
	 * @param string $key The configuration key to retrieve.
	 * @param string $section The configuration section where the key is stored. Defaults to `globalSettings`.
	 * @return array Returns an true if the configuration key exists, false otherwise.
	 */
	public function isConfigExist(string $key = '', ?string $section = "globalSettings")
	{
		if (empty($key))
		{
			return false;
		}
		if (empty($section))
		{
			$section = "globalSettings";
		}
		return in_array($key, $this->getAllKeys($section));
	}

	/**
	 * Sets the value of a configuration key.
	 *
	 * @param string $key The configuration key to set.
	 * @param mixed $value The value to set for the configuration key.
	 * @param string $section The configuration section where the key will be stored. Defaults to `globalSettings`.
	 * @return bool Returns true if the configuration key was set, false otherwise.
	 *
	 * @deprecated This method currently supports retrocompatibility with data saved in the database, but it will be removed in the future.
	 */
	public function setConfig($key = '', $value = false, $section = "globalSettings")
	{
		if (empty($key))
		{
			return false;
		}
		if (empty($section))
		{
			$section = "globalSettings";
		}
		return parent::setConfig($key, $value, $section);
	}







	/**
	 * set_database_data
	 *
	 * Inserts or updates data in a specified database table based on the presence of a record matching a given condition.
	 *
	 * This function performs an `INSERT` or `UPDATE` operation on a database table depending on whether a record
	 * already exists that matches the specified `where` condition with the given `find` value.
	 *
	 * @param string $table The name of the database table where the operation will be performed. This is a required parameter.
	 * @param mixed $find The value to be searched for in the specified column (`$where`). If a record with this value exists, an `UPDATE` operation is performed; otherwise, an `INSERT` operation is executed.
	 * @param array $data The associative array of data to be inserted or updated. Keys represent the column names, and values represent the corresponding data. This array is required and must not be empty.
	 * @param string $where The column name used for searching the record in the table. Defaults to `id`. This is a required parameter.
	 * @param array $data_insert Optional. An additional associative array of data to be merged with `$data` for the `INSERT` operation. If not provided, only `$data` is used for insertion.
	 * @param array $data_update Optional. An additional associative array of data to be merged with `$data` for the `UPDATE` operation. If not provided, only `$data` is used for updating.
	 * @param bool $debug Optional. If set to `true`, the function will return the generated SQL query instead of executing it. Defaults to `false`.
	 *
	 * @return mixed Returns the ID of the newly inserted record if an `INSERT` operation is performed, or `true` if an `UPDATE` operation is performed successfully.
	 *               Returns `false` if any of the required parameters (`$data`, `$where`, `$table`) are missing or invalid.
	 *
	 * @throws PDOException If the database operation fails, an exception is thrown by the PDO layer.
	 *
	 * @version 1.0.0
 	 * @author Javier Pastor
 	 *
	 * @example
	 * // Example usage for updating an existing record
	 * $table = 'users';
	 * $find = 5; // Assuming this is the ID of the user
	 * $data = ['username' => 'new_username'];
	 * $data_update = ['email' => 'new_email@example.com'];
	 * $result = $this->set_database_data($table, $find, $data, 'id', [], $data_update);
	 * // Updates the 'username' and 'email' for the user with ID 5.
	 *
	 * @example
	 * // Example usage for inserting a new record
	 * $table = 'users';
	 * $data = ['username' => 'new_user', 'email' => 'new_user@example.com'];
	 * $data_insert = ['created_at' => date('Y-m-d H:i:s')];
	 * $result = $this->set_database_data($table, null, $data, 'id', $data_insert, []);
	 * // Inserts a new user with the provided data.
	 */
	public function set_database_data($table = null, $find = null, $data = array(), $where = "id", $data_insert = array(), $data_update = array(), $debug = false)
	{
		if (empty($table) || !is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table))
		{
			if ($debug)
			{
				dbug("Invalid table name: $table");
			}
			return false;
		}
		if (!is_array($data) || empty($data) || empty($where))
		{
			if ($debug)
			{
				dbug("Invalid data or where condition!");
			}
			return false;
		}

		$isExistKeyFind = function () use ($table, $where, $find, $data) {

			// TODO: REMOVE DEBUG!!!!
			// dbug('0000000000000000000000000000000000000');
			// dbug(array(
			// 	'table' => array(
			// 		'type' => gettype($table),
			// 		'data' => $table
			// 	),
			// 	'where' => array(
			// 		'type' => gettype($where),
			// 		'data' => $where
			// 	),
			// 	'find' => array(
			// 		'type' => gettype($find),
			// 		'data' => $find
			// 	),
			// 	'data' => array(
			// 		'type' => gettype($data),
			// 		'data' => $data
			// 	)
			// ));


			$sql  = sprintf("SELECT COUNT(*) as total FROM %s WHERE %s = :find", $table, $where);
			$stmt = $this->db->prepare($sql);
			$stmt->execute(
				[':find' => $find]
			);
			$result = $stmt->fetch(\PDO::FETCH_ASSOC);
			return ($result['total'] ?? 0) > 0;
		};

		// Check if the record exists in the database, not used $this->count_database_data since it generate loop infinite.
		$isUpdate  = !empty($find) && $isExistKeyFind();
		$params    = [];
		if ($isUpdate)
		{
			// Generate a random key to avoid conflicts with the data array
			$key_where 							= sprintf("%s_%s", "where_filter_value", rand(1000, 9999));
			$params[sprintf(":%s", $key_where)] = $find;

			// Combine the data arrays and generate the SQL query for updating the data in the database
			$data 	   = array_merge($data, $data_update);
			$setPart   = [];
			foreach ($data as $column => $value)
			{
				$setPart[] = sprintf("%s = :%s", $column, $column);
			}
			$setPart = implode(", ", $setPart);
			$sql 	 = sprintf("UPDATE %s SET %s WHERE %s = :%s", $table, $setPart, $where, $key_where);
		}
		else
		{
			// Combine the data arrays and generate the SQL query for inserting the data into the database
			$data 		  = array_merge($data, $data_insert);
			$columns 	  = implode(", ", array_keys($data));
			$placeholders = implode(", ", array_map(function($column) { return sprintf(":%s",$column); }, array_keys($data)));
			$sql 		  = sprintf("INSERT INTO %s (%s) VALUES (%s)", $table, $columns, $placeholders);
		}
		if ($debug)
		{
			$data_debug = array(
				'table' 	  => $table,
				'find' 		  => $find,
				'data' 		  => $data,
				'where' 	  => $where,
				'isUpdate' 	  => $isUpdate ? 'TRUE' : 'FALSE',
				'sql' 		  => $sql,
				'data' 		  => $data,
				'data_insert' => $data_insert,
				'data_update' => $data_update,
			);
			dbug($data_debug);
		}
		$stmt = $this->db->prepare($sql);

		// Bind the parameters to the query and execute it
		foreach ($data as $key => $value)
		{
			$params[sprintf(":%s", $key)] = $value;
		}
		$stmt->execute($params);
		return $isUpdate ? true : $this->db->lastInsertId();
	}
	

	/**
	 * Retrieves data from a database table based on the specified conditions.
	 *
	 * This function retrieves data from a database table based on the specified conditions.
	 * The data is returned as an associative array of rows, where each row is an associative array of columns.
	 *
	 * @param string $table The name of the database table to retrieve data from. This is a required parameter.
	 * @param array $where An associative array of conditions to filter the data. The keys represent the column names, and the values are arrays with the following keys:
	 *                     - `operator`: The comparison operator to use in the condition (e.g., '=', '>', '<', 'LIKE', 'IN', 'NOT IN').
	 *                     - `value`: The value to compare against in the condition.
	 * @param string $select The columns to select from the table. Defaults to `*` (all columns).
	 * @param string $order_by The column to use for sorting the results. Defaults to `null` (no sorting).
	 * @param string $order_dir The direction to use for sorting the results. Defaults to `null` (no sorting).
	 * @param bool $return_bool Whether to return a boolean value instead of an empty array when no data is found. Defaults to `false`.
	 * @param bool $return_stml Whether to return the PDO statement object instead of the data array. Defaults to `false`.
	 * @param bool $debug Whether to output the generated SQL query and parameters for debugging purposes. Defaults to `false`.
	 * @return array The data retrieved from the database as an associative array of rows, where each row is an associative array of columns.
	 *
	 * @throws PDOException If the database operation fails, an exception is thrown by the PDO layer.
	 *
	 * @version 1.0.0
 	 * @author Javier Pastor
 	 *
	 * @example
	 * // Example usage for retrieving data from a database table
	 * $table = 'users';
	 * $where = [
	 *     'id' => ['operator' => '>', 'value' => 0],
	 *     'status' => ['operator' => '=', 'value' => 'active']
	 * ];
	 * $select = 'id, username, email';
	 * $order_by = 'created_at';
	 * $order_dir = 'DESC';
	 * $result = $this->get_database_data($table, $where, $select, $order_by, $order_dir);
	 * // Retrieves the 'id', 'username', and 'email' columns from the 'users' table where 'id' is greater than 0 and 'status' is 'active', ordered by 'created_at' in descending order.
	 *
	 * @example 
	 * $where = array(
	 *		'brand' => array( 'operator' => '=', 'value' => $id)
	 * );
	 * if (!$show_all)
	 * {
 	 * 		$where['hidden'] = array('operator' => '=', 'value' => "0");
	 * }
	 * return $this->get_database_data(self::TABLES['epm_product_list'], $where, '*', $order_by, $order_dir);
	 * 
	 */
	
	public function get_database_data($table, $where = array(), $select = null, $order_by = null, $order_dir = null, $return_bool = false, $return_stml = false, $debug = false)
	{
		if (empty($select))
		{
			$select = "*";
		}
		if (empty($table) || !is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table) || !is_string($select) || !preg_match('/^[a-zA-Z0-9_.*,\\s()]+$/', $select))
		{
			if ($return_bool)
			{
				return false;
			}
			return [];
		}

		$params = [];
		$sql = sprintf('SELECT %s FROM %s', $select, $table);

		if (!empty($where) && is_array($where))
		{
			$where_sql = [];
			foreach ($where as $key => $value)
			{
				$operator  = strtoupper($value['operator'] ?? "");
				$where_val = $value['value'] ?? null;

				if (empty($where_val) ||empty($operator) || !in_array($operator, ['=', '>', '<', '>=', '<=', '!=', 'LIKE', 'IN', 'NOT IN']))
				{
					continue;
				}
				$where_sql[] = sprintf('%1$s %2$s :%1$s', $key, $operator);
				$params[sprintf(":%s", $key)] = $where_val;
			}
			if (count($where_sql) > 0)
			{
				$sql .= sprintf(' WHERE %s', implode(" AND ", $where_sql));
			}
		}

		if (!empty($order_by))
		{
			// Sanitize the order by column name
			$order_by  = preg_replace('/[^a-zA-Z0-9_]/', '', $order_by);
			$order_dir = strtoupper($order_dir ?? 'ASC') == 'DESC' ? 'DESC' : 'ASC';
			$sql 	  .= sprintf(' ORDER BY %s %s', $order_by, $order_dir);
		}
		
		if ($debug)
		{
			dbug($sql);
			dbug($params);
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
	
		if ($return_stml)
		{
			return $stmt;
		}
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Counts the number of database records in a specified table.
	 *
	 * @param string $table The name of the table to count records from.
	 * @param mixed $find The value to search for in the specified column.
	 * @param string $filter The column name to filter the search by.
	 * @param array $where An associative array of conditions to filter the data. The keys represent the column names, and the values are arrays with the following keys:
	 * @return bool Returns true if there are records matching the search criteria, false otherwise.
	 * /example
	 * // Example usage for counting records in a database table
	 * $table = 'users';
	 * $find = 5; // Assuming this is the ID of the user
	 * $result = $this->count_database_data($table, $find, 'id');
	 * // Counts the number of records in the 'users' table where 'id' is equal to 5.
	 */
	public function count_database_data($table, $find = null, $filter = null, $where = array())
	{
		$return_data = false;
		if (!is_array($where))
		{
			$where = [];
		}
		if (!empty($find) && !empty($filter))
		{
			$where[$filter] = array( 'operator' => '=', 'value' => $find);
		}
		$result = $this->get_database_data($table, $where, 'COUNT(*) as total');
		$return_data = ($result[0]['total'] ?? 0) > 0;
		return $return_data;
	}






	/**
	 * Sets the database data for a brand.
	 *
	 * @param mixed $find The value to search for in the database.
	 * @param array $data An array of data to be set for the brand.
	 * @param string $where The column name to use for the search.
	 * @param array $data_insert An array of data to be inserted into the database.
	 * @param array $data_update An array of data to be updated in the database.
	 * @return mixed The result of the set_database_data method.
	 */
	public function set_hw_brand($find = null, $data = array(), $where = "id", $data_insert = array(), $data_update = array())
	{
		// Set true for debug mode to show the generated SQL query
		$debug 				  = false;

		$data 				  = is_array($data)			? $data 		: [];
		$data_insert  		  = is_array($data_insert)	? $data_insert	: [];
		$data_update  		  = is_array($data_update)	? $data_update	: [];
		$data_default 		  = [];
		$data_insert_defaults = [];
		$data_update_defaults = [
			// 'local' 	=> $data['local'] 	  ?? 1,
			// 'installed' => $data['installed'] ?? 1,
			// 'hidden' 	=> $data['hidden'] 	  ?? 0,
		];
		$data 		 = array_merge($data_default, $data);
		$data_insert = array_merge($data_insert_defaults, $data_insert);
		$data_update = array_merge($data_update_defaults, $data_update);

		return $this->set_database_data(self::TABLES['epm_brands_list'], $find, $data, $where, $data_insert, $data_update, $debug);
	}

	/**
	 * Retrieves the brand data from the database.
	 *
	 * @param mixed $find The value to search for in the database.
	 * @param string $where The column name to use for the search.
	 * @param string $select The columns to select from the database.
	 * @param string $order_by The column to use for sorting the results.
	 * @param string $order_dir The direction to use for sorting the results.
	 * @return array The result of the get_database_data method.
	 */
	public function get_hw_brand_list($show_all = false, $order_by = null, $order_dir = null)
	{
		$where = array(
			'id' => array( 'operator' => '>', 'value' => "0")
		);
		if (!$show_all)
		{
			$where['hidden'] = array('operator' => '=', 'value' => "0");
		}
		return $this->get_database_data(self::TABLES['epm_brands_list'], $where, '*', $order_by, $order_dir);
	}
	

	/**
	 * Retrieves the brand data from the database.
	 *
	 * @param mixed $id The ID of the brand to retrieve. The value is ignored if the `$where` parameter is an array.
	 * @param string|array $where The column name to use for the search (default: "directory") or an array of conditions.
	 * @param string $select The columns to select from the database (default: "*").
	 * @param bool $getOne Whether to return only the first result or all results (default: false).
	 * @return array The result of the get_database_data method.
	 * @example
	 * // Example usage for retrieving brand data from the database
	 * $id = 1;
	 * $where = 'id';
	 * $select = 'id, name, directory';
	 * $result = $this->get_hw_brand($id, $where, $select, true);
	 * // Retrieves the 'id', 'name', and 'directory' columns for the brand with ID 1 and returns only the first result.
	 * @example
	 * $id = 1;
	 * $where = array('name' => array( 'operator' => 'LIKE', 'value' => "cisco"));
	 * $select = 'id, name, directory';
	 * $result = $this->get_hw_brand($id, $where, $select, false);
	 * // Retrieves the 'id', 'name', and 'directory' columns for the brand with name like "cisco" and returns all results.
	 */
	public function get_hw_brand($id, $where = "directory", ?string $select = "*", ?bool $getOne = false)
	{
		$debug = false;
		if (empty($id) && !is_array($where)) { return []; }
		if (empty($where))					 { $where  = "directory"; }
		if (empty($select))					 { $select = "*"; }
		if (is_array($where))				 { $where_query = $where; }
		else
		{
			$where_query = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
		}

		$return_db = $this->get_database_data(self::TABLES['epm_brands_list'], $where_query, $select, null, null, false, false, $debug);
		
		if ($getOne && !empty($return_db))
		{
			$return_db = $return_db[0] ?? [];
		}
		return $return_db;
	}

	


	/**
	 * Check if a hardware brand exists.
	 *
	 * @param string|null $id The hardware brand to check.
	 * @param string $where The column to search for the hardware brand (default: "directory").
	 * @param bool $find_all Whether to find all brands or only the visible ones (default: false).
	 * @return bool Returns true if the brand exists, false otherwise.
	 */
	public function is_exist_hw_brand($id = null, $where = "directory", $find_all = false)
	{
		$return_data = false;
		if (empty($where))
		{
			$where = "directory";
		}
		if (!empty($id))
		{
			$final_where = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
			if (!$find_all)
			{
				$final_where['hidden'] = array( 'operator' => '=', 'value' => "0");
			}
			$count = $this->count_database_data(self::TABLES['epm_brands_list'], null, null, $final_where);
			$return_data = $count > 0;
		}
		return $return_data;
	}

	public function is_local_hw_brand($brand = null, $findby = "directory")
	{
		$return_data = null;
		if (!empty($brand) && !empty($findby))
		{
			$sql  = sprintf("SELECT local FROM %s WHERE %s = :findby", self::TABLES['epm_brands_list'], $findby);
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':findby' => $brand
			]);
			$return_data = $stmt->rowCount() === 0 ? false : ($stmt->fetchColumn() === "1");
		}
		return $return_data;
	}




	/**
	 * Check if a hardware product exists in the database.
	 *
	 * @param int|null $id The ID of the hardware product.
	 * @param string $where The column to search for the hardware product. (default: "id")
	 * @param bool $find_all Whether to find all products or only the visible ones.
	 * @return bool Returns true if the hardware product exists, false otherwise.
	 */
	public function is_exist_hw_product($id = null, $where = "id", $find_all = false)
	{
		$return_data = false;
		if (empty($where))
		{
			$where = "id";
		}
		if (!empty($id))
		{
			$final_where = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
			if (!$find_all)
			{
				$final_where['hidden'] = array( 'operator' => '=', 'value' => "0");
			}
			$count = $this->count_database_data(self::TABLES['epm_product_list'], null, null, $final_where);
			$return_data = $count > 0;
		}
		return $return_data;
	}

	/**
	 * Sets the brand and product information in the database.
	 *
	 * @param mixed $find The value to search for in the "where" column.
	 * @param array $data An array containing the brand and product data.
	 * @param string $where The column name to use in the WHERE clause (Only INSERT).
	 * @param array $data_insert An optional array of additional data to insert.
	 * @param array $data_update An optional array of additional data to update.
	 * @return bool|int Returns true if the update was successful, or the inserted row ID if a new record was inserted. Returns false if the data is invalid or the "where" column is empty.
	 */
	public function set_hw_product($find = null, $data = array(), $where = "id", $data_insert = array(), $data_update = array())
	{
		// Set true for debug mode to show the generated SQL query
		$debug 				  = false;

		$data 				  = is_array($data)			? $data 		: [];
		$data_insert  		  = is_array($data_insert)	? $data_insert	: [];
		$data_update  		  = is_array($data_update)	? $data_update	: [];
		$data_default 		  = [];
		$data_insert_defaults = [
			// 'hidden' => $data['hidden'] ?? 0,
		];
		$data_update_defaults = [];
		$data 		 = array_merge($data_default, $data);
		$data_insert = array_merge($data_insert_defaults, $data_insert);
		$data_update = array_merge($data_update_defaults, $data_update);
		
		return $this->set_database_data(self::TABLES['epm_product_list'], $find, $data, $where, $data_insert, $data_update, $debug);
	}

	/**
	 * Retrieves the hardware product list based on the provided ID.
	 *
	 * @param int $id The ID of the brand.
	 * @param bool $show_all (Optional) Whether to show all products or not. Default is false.
	 * @param string|null $order_by (Optional) The column to order the results by. Default is null.
	 * @param string|null $order_dir (Optional) The direction to order the results in. Default is null.
	 * @return array The hardware product list.
	 */
	public function get_hw_product_list($id, $show_all = false, $order_by = null, $order_dir = null)
	{
		if (empty($id))
		{
			return array();
		}
		$where = array(
			'brand' => array( 'operator' => '=', 'value' => $id)
		);
		if (!$show_all)
		{
			$where['hidden'] = array('operator' => '=', 'value' => "0");
		}
		return $this->get_database_data(self::TABLES['epm_product_list'], $where, '*', $order_by, $order_dir);
	}

	public function get_hw_product($id, $where = "id", ?string $select = "*", ?bool $getOne = false)
	{
		$debug = false;
		if (empty($id) && !is_array($where)) { return []; }
		if (empty($where))					 { $where  = "id"; }
		if (empty($select))					 { $select = "*"; }
		if (is_array($where))				 { $where_query = $where; }
		else
		{
			$where_query = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
		}

		$return_db = $this->get_database_data(self::TABLES['epm_product_list'], $where_query, $select, null, null, false, false, $debug);

		if ($getOne && !empty($return_db))
		{
			$return_db = $return_db[0] ?? [];
		}
		return $return_db;
	}



	

	/**
	 * Retrieves the hardware model list based on the given ID.
	 *
	 * @param int $id The ID of the product.
	 * @param bool $show_all (Optional) Whether to show all models or not. Default is false.
	 * @param string|null $order_by (Optional) The column to order the results by. Default is null.
	 * @param string|null $order_dir (Optional) The direction to order the results in. Default is null.
	 * @return array The array of hardware models matching the given ID and conditions.
	 */
	public function get_hw_model_list($id, $show_all = false, $order_by = null, $order_dir = null)
	{
		if (empty($id))
		{
			return array();
		}
		$where = array(
			'product_id' => array( 'operator' => '=', 'value' => $id)
		);
		if (!$show_all)
		{
			$where['hidden'] = array('operator' => '=', 'value' => "0");
		}
		return $this->get_database_data(self::TABLES['epm_model_list'], $where, '*', $order_by, $order_dir);
	}

	/**
	 * Checks if a hardware model exists in the database.
	 *
	 * @param int|null $id The ID of the hardware model to check. Defaults to null.
	 * @param string $where The column to search for the hardware model. (Default: "id")
	 * @param bool $find_all Determines whether to find all matching hardware models or just one. Defaults to false.
	 *
	 * @return bool Returns true if the hardware model exists, false otherwise.
	 */
	public function is_exist_hw_model($id = null, $where = "id", $find_all = false)
	{
		$return_data = false;
		if (empty($where))
		{
			$where = "id";
		}
		if (!empty($id))
		{
			$final_where = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
			if (!$find_all)
			{
				$final_where['hidden'] = array( 'operator' => '=', 'value' => "0");
			}
			$count = $this->count_database_data(self::TABLES['epm_model_list'], null, null, $final_where);
			$return_data = $count > 0;
		}
		return $return_data;
	}

	/**
	 * Deletes a hardware model from the database.
	 *
	 * @param int|null $id The ID of the hardware model to delete. Defaults to null.
	 * @return bool Returns true if the hardware model was deleted, false otherwise.
	 */
	public function del_hw_model($id = null)
	{
		if (!$this->is_exist_hw_model($id))
		{
			return false;
		}

		$sql  = sprintf("DELETE FROM %s WHERE id = :id", self::TABLES['epm_model_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':id' => $id
		]);
		return true;
	}






	/**
	 * Synchronizes the MAC brand by model.
	 *
	 * @param string $model The model name.
	 * @param int $model_id The model ID.
	 * @return bool Returns true if the synchronization is successful, false otherwise.
	 */
	public function sync_mac_brand_by_model($model, $model_id)
	{
		if (empty($model) || empty($model_id))
		{
			return false;
		}

		$sql  = sprintf("SELECT id FROM %s WHERE model LIKE :model", self::TABLES['epm_model_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':model' => $model,
		]);
		$new_model_id = $stmt->rowCount() === 0 ? false : ($stmt->fetchColumn() ?? false);
	
		// if ($new_model_id)
		// {
		// 	$sql  = sprintf("UPDATE %s SET  model = :new_model_id WHERE  model = :model", "endpointman_mac_list");
		// 	$stmt = $this->db->prepare($sql);
		// 	$stmt->execute([
		// 		':new_model_id' => $new_model_id,
		// 		':model' 		=> $model_id,
		// 	]);
		// }
		// else
		// {
		// 	$sql  = sprintf("UPDATE %s SET  model = '0' WHERE model = :model", self::TABLES["epm_mac_list"]);
		// 	$stmt = $this->db->prepare($sql);
		// 	$stmt->execute([
		// 		':model' => $model_id,
		// 	]);
		// }

		$data_oid = array(
			':model' => $new_model_id ? $new_model_id : 0
		);
		$this->set_database_data(self::TABLES['epm_mac_list'], $model_id, $data_oid, 'model');
	}

 
	public function get_hw_mac($id = null, $where = 'id', $select = "*")
	{
		$where_query = [];
		if (empty($select))
		{
			$select = "*";
		}
		if (! empty($id))
		{
			if (empty($where))
			{
				$where = 'id';
			}
			$where_query = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);
		}
		return $this->get_database_data(self::TABLES['epm_mac_list'], $where_query, $select);
		
		// $sql = sprintf('SELECT id, global_custom_cfg_data, global_user_cfg_data FROM %s WHERE model = :brand_id_family_line_model', "endpointman_mac_list");
		// $stmt = $this->db->prepare($sql);
		// $stmt->execute([
		// 	':brand_id_family_line_model' => $brand_id_family_line_model
		// ]);
		// $old_data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function set_hw_mac($find = null, $data = array(), $where = "id", $data_insert = array(), $data_update = array())
	{
		$data 				  = is_array($data)			? $data 		: [];
		$data_insert  		  = is_array($data_insert)	? $data_insert	: [];
		$data_update  		  = is_array($data_update)	? $data_update	: [];
		$data_default 		  = [];
		$data_insert_defaults = [
			// 'hidden' => $data['hidden'] ?? 0,
		];
		$data_update_defaults = [];
		$data 		 = array_merge($data_default, $data);
		$data_insert = array_merge($data_insert_defaults, $data_insert);
		$data_update = array_merge($data_update_defaults, $data_update);
		
		return $this->set_database_data(self::TABLES['epm_mac_list'], $find, $data, $where, $data_insert, $data_update);
	}








	public function get_hw_template($id = null, $where = 'id', $select = "*")
	{
		$where_query = [];
		if (empty($select))
		{
			$select = "*";
		}
		if (! empty($id))
		{
			if (empty($where))
			{
				$where = 'id';
			}
			$where_query = array(
				$where => array( 'operator' => '=', 'value' => $id)
			);	
		}
		
		return $this->get_database_data(self::TABLES['epm_template_list'], $where_query, $select);
		
		// $sql = sprintf('SELECT id, global_custom_cfg_data FROM %s WHERE model_id = :brand_id_family_line_model', "endpointman_template_list");
		// $stmt = $this->db->prepare($sql);
		// $stmt->execute([
		// 	':brand_id_family_line_model' => $brand_id_family_line_model
		// ]);
		// $old_data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function set_hw_template($find = null, $data = array(), $where = "id", $data_insert = array(), $data_update = array())
	{
		$data 				  = is_array($data)			? $data 		: [];
		$data_insert  		  = is_array($data_insert)	? $data_insert	: [];
		$data_update  		  = is_array($data_update)	? $data_update	: [];
		$data_default 		  = [];
		$data_insert_defaults = [
			// 'hidden' => $data['hidden'] ?? 0,
		];
		$data_update_defaults = [];
		$data 		 = array_merge($data_default, $data);
		$data_insert = array_merge($data_insert_defaults, $data_insert);
		$data_update = array_merge($data_update_defaults, $data_update);
		
		return $this->set_database_data(self::TABLES['epm_mac_list'], $find, $data, $where, $data_insert, $data_update);
	}







	public function set_hw_oui($oui = null, $brand_id = null, $custom = 0)
	{
		if(empty($oui) || empty($brand_id))
		{
			return false;
		}

		$sql = sprintf("REPLACE INTO %s (`oui`, `brand`, `custom`) VALUES (:oui, :brand_id, :custom)", self::TABLES['epm_oui_list']);
		$sth = $this->db->prepare($sql);
		$sth->execute([
			':oui' 		=> $oui,
			':brand_id' => $brand_id,
			':custom' 	=> $custom,
		]);
		return true;
	}





	/*****************************************
	****** CODIGO ANTIGUO -- REVISADO ********
	*****************************************/


	/**
     * Returns list of Brands that are installed and not hidden and that have at least one model enabled under them
     * @param integer $selected ID Number of the brand that is supposed to be selected in a drop-down list box
     * @return array Number array used to generate a select box
     */
    function brands_available($selected = NULL, $show_blank=TRUE)
	{
        $data = $this->eda->all_active_brands();
		$temp = [];
        if ($show_blank) {
            $temp[0]['value'] = "";
            $temp[0]['text'] = "";
            $i = 1;
        } else {
            $i = 0;
        }
        foreach ($data as $row) {
            $temp[$i]['value'] = $row['id'];
            $temp[$i]['text'] = $row['name'];
            if ($row['id'] == $selected) {
                $temp[$i]['selected'] = TRUE;
            } else {
                $temp[$i]['selected'] = NULL;
            }
            $i++;
        }
        return($temp);
    }

	function listTZ($selected) {
        $data = \DateTimeZone::listIdentifiers();
        $i = 0;
        foreach ($data as $key => $row) {
            $temp[$i]['value'] = $row;
            $temp[$i]['text'] = $row;
            if (strtoupper ($temp[$i]['value']) == strtoupper($selected)) {
                $temp[$i]['selected'] = 1;
            } else {
                $temp[$i]['selected'] = 0;
            }
            $i++;
        }

        return($temp);
    }

	/**
	 * Checks if Git is installed on the system.
	 *
	 * @return string|false The path to Git executable if Git is installed, false otherwise.
	 */
	public function has_git()
	{
        exec('which git', $output);
        $git = file_exists($line = trim(current($output))) ? $line : 'git';
        unset($output);

        exec($git . ' --version', $output);
        preg_match('#^(git version)#', current($output), $matches);

        return !empty($matches[0]) ? $git : false;
        echo !empty($matches[0]) ? 'installed' : 'nope';
    }

	/*********************************************************************************
	 * Device / line / provisioning engine.
	 *
	 * Revived from the code that release/17.0-dev carried as a block comment
	 * ("pending revision") after the _old/ move, ported to the current class layout
	 * (eda, system, getConfig, packages) and to PHP 8.2: no echo/out() from inside
	 * the engine, every failure lands in $this->error[...] and returns false.
	 *********************************************************************************/

	/**
	 * Quote a value for direct use in SQL built by the legacy engine.
	 */
	private function q($value)
	{
		return $this->db->quote((string) $value);
	}

	/**
	 * Run a callable with PHP notices/warnings/deprecations turned into debug
	 * messages instead of exceptions. The Provisioner backend (_ep_phone_modules)
	 * is 2012-era code that is noisy under PHP 8 but functionally fine; FreePBX
	 * installs an error handler that would otherwise abort the whole request.
	 */
	private function runLegacy(callable $fn)
	{
		$collected = array();
		set_error_handler(function ($errno, $errstr, $errfile = '', $errline = 0) use (&$collected) {
			$collected[] = sprintf('%s [%s:%s]', $errstr, basename((string) $errfile), $errline);
			return true;
		}, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED | E_STRICT);
		try
		{
			$result = $fn();
		}
		finally
		{
			restore_error_handler();
		}
		if (!empty($collected) && $this->getConfig('debug'))
		{
			$this->message['legacy_notices'] = implode('<br />', array_unique($collected));
		}
		return $result;
	}

	/**
	 * Lines (1..max_lines of the model) that are still free on a device.
	 * @param int|null $lineid luid of an existing line (its own line number comes back selected)
	 * @param int|null $macid  endpointman_mac_list.id
	 * @return array|false  [n => ['value'=>n,'text'=>n,'selected'?]] or false when nothing is free
	 */
	public function linesAvailable($lineid = NULL, $macid = NULL)
	{
		$line = array('line' => 0, 'mac_id' => null);
		if (!empty($lineid))
		{
			$sql_l = sprintf("SELECT line, mac_id FROM %s WHERE luid = %d", self::TABLES['epm_line_list'], (int) $lineid);
			$row   = $this->eda->sql($sql_l, 'getRow', \PDO::FETCH_ASSOC);
			if (empty($row))
			{
				return false;
			}
			$line  = $row;
			$macid = $row['mac_id'];
		}
		if (empty($macid))
		{
			return false;
		}

		$sql        = sprintf("SELECT max_lines FROM %s WHERE id = (SELECT model FROM %s WHERE id = %d)", self::TABLES['epm_model_list'], self::TABLES['epm_mac_list'], (int) $macid);
		$sql_lu     = sprintf("SELECT line FROM %s WHERE mac_id = %d", self::TABLES['epm_line_list'], (int) $macid);
		$max_lines  = (int) $this->eda->sql($sql, 'getOne');
		$lines_used = $this->eda->sql($sql_lu, 'getAll', \PDO::FETCH_ASSOC);
		$used       = array_map(function ($r) { return (int) $r['line']; }, is_array($lines_used) ? $lines_used : array());

		$temp = array();
		for ($i = 1; $i <= $max_lines; $i++)
		{
			if ($i == (int) $line['line'])
			{
				$temp[$i] = array('value' => $i, 'text' => $i, 'selected' => 'selected');
			}
			elseif (!in_array($i, $used, true))
			{
				$temp[$i] = array('value' => $i, 'text' => $i);
			}
		}
		return empty($temp) ? false : $temp;
	}

	/**
	 * Models available for a select box, optionally filtered by brand or product.
	 * @return array|false
	 */
	public function models_available($model = NULL, $brand = NULL, $product = NULL)
	{
		if (!empty($brand))
		{
			$result = $this->eda->all_models_by_brand((int) $brand);
		}
		elseif (!empty($product))
		{
			$result = $this->eda->all_models_by_product((int) $product);
		}
		else
		{
			$result = $this->eda->all_models();
		}

		$temp = array();
		$i = 1;
		foreach ((array) $result as $row)
		{
			$temp[$i] = array('value' => $row['id'], 'text' => $row['model'], 'selected' => ($row['id'] == $model) ? 'selected' : 0);
			$i++;
		}
		if (empty($temp))
		{
			$this->error['modelsAvailable'] = _("You need to enable at least ONE model");
			return false;
		}
		return $temp;
	}

	/**
	 * Registrations (devices) not yet mapped to a phone, for a select box.
	 * When $line_id is given, that line's own extension is appended and selected.
	 */
	public function display_registration_list($line_id = NULL)
	{
		$result    = $this->eda->all_unused_registrations();
		$line_data = !empty($line_id) ? $this->eda->get_line_information((int) $line_id) : NULL;

		$i    = 1;
		$temp = array();
		foreach ((array) $result as $row)
		{
			$temp[$i] = array('value' => $row['id'], 'text' => $row['id'] . " --- " . $row['description']);
			$i++;
		}
		if (!empty($line_data))
		{
			$sql  = sprintf("SELECT description FROM %s WHERE id = %s", self::TABLES['devices'], $this->q($line_data['ext']));
			$desc = $this->eda->sql($sql, 'getOne');
			$temp[$i] = array('value' => $line_data['ext'], 'text' => $line_data['ext'] . " --- " . ($desc ?: $line_data['description']), 'selected' => 'selected');
		}
		return $temp;
	}

	/**
	 * Templates of a product for a select box, plus the "Custom..." entry (value 0).
	 */
	public function display_templates($product_id, $temp_select = NULL)
	{
		$sql  = sprintf("SELECT id, name FROM %s WHERE product_id = %d ORDER BY name", self::TABLES['epm_template_list'], (int) $product_id);
		$data = $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);

		$temp = array();
		$i = 0;
		foreach ((array) $data as $row)
		{
			$temp[$i] = array('value' => $row['id'], 'text' => $row['name']);
			if ($row['id'] == $temp_select)
			{
				$temp[$i]['selected'] = 'selected';
			}
			$i++;
		}
		$temp[$i] = array('value' => 0, 'text' => _("Custom..."));
		if ((string) $temp_select === '0')
		{
			$temp[$i]['selected'] = 'selected';
		}
		return $temp;
	}

	/**
	 * Brand that owns the OUI of a MAC address.
	 * @return array|false ['id' => brand id or 0, 'name' => name or "Unknown"]
	 */
	public function get_brand_from_mac($mac)
	{
		$clean = $this->system->mac_check_clean($mac);
		if (!$clean)
		{
			return false;
		}
		$oui = substr($clean, 0, 6);
		$sql = sprintf(
			"SELECT b.name, b.id FROM %s AS o, %s AS b WHERE o.oui = %s AND b.id = o.brand AND b.installed = 1 LIMIT 1",
			self::TABLES['epm_oui_list'], self::TABLES['epm_brands_list'], $this->q($oui)
		);
		$brand = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
		if (empty($brand))
		{
			return array('id' => 0, 'name' => _("Unknown"));
		}
		return array('id' => $brand['id'], 'name' => $brand['name']);
	}

	public function retrieve_device_by_mac($mac)
	{
		$clean = $this->system->mac_check_clean($mac);
		if (!$clean)
		{
			return false;
		}
		$sql = sprintf("SELECT id FROM %s WHERE mac = %s", self::TABLES['epm_mac_list'], $this->q($clean));
		return $this->eda->sql($sql, 'getOne');
	}

	public function retrieve_device_by_ext($ext)
	{
		$sql = sprintf("SELECT DISTINCT mac_id FROM %s WHERE ext = %s", self::TABLES['epm_line_list'], $this->q($ext));
		return $this->eda->sql($sql, 'getOne');
	}

	/**
	 * Check that a model can be provisioned: it exists, its brand and family
	 * directories are installed and the family JSON is present. The model's
	 * template_data is filled by the Package Manager at install time
	 * (ProvisionerModel::importTemplates), so nothing is rewritten here.
	 */
	public function sync_model($model)
	{
		if (empty($model))
		{
			$this->error['sync_model'] = _("No model given");
			return false;
		}
		$sql = sprintf(
			"SELECT m.id, m.model, m.template_data, p.cfg_dir, b.directory FROM %s AS m, %s AS p, %s AS b WHERE m.id = %d AND p.id = m.product_id AND b.id = m.brand",
			self::TABLES['epm_model_list'], self::TABLES['epm_product_list'], self::TABLES['epm_brands_list'], (int) $model
		);
		$row = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
		if (empty($row))
		{
			$this->error['sync_model'] = _("Model not found in the database");
			return false;
		}
		$family_dir = $this->system->buildPath($this->PHONE_MODULES_PATH, 'endpoint', $row['directory'], $row['cfg_dir']);
		if (!is_dir($this->system->buildPath($this->PHONE_MODULES_PATH, 'endpoint', $row['directory'])))
		{
			$this->error['sync_model'] = sprintf(_("Brand directory '%s' does not exist, install the brand package first"), $row['directory']);
			return false;
		}
		if (!is_dir($family_dir) || !file_exists($this->system->buildPath($family_dir, 'family_data.json')))
		{
			$this->error['sync_model'] = sprintf(_("Product directory '%s' or its family_data.json is missing"), $row['cfg_dir']);
			return false;
		}
		if (empty($row['template_data']))
		{
			$this->error['sync_model'] = sprintf(_("Model '%s' has no template data, reinstall its brand package"), $row['model']);
			return false;
		}
		return true;
	}

	/**
	 * Add a phone (or a line to an existing phone with the same MAC).
	 * @return int|false endpointman_mac_list.id
	 */
	public function add_device($mac, $model, $ext, $template = NULL, $line = NULL, $displayname = NULL)
	{
		$mac = $this->system->mac_check_clean($mac);
		if (!$mac)
		{
			$this->error['add_device'] = _("Invalid MAC Address") . "!";
			return false;
		}
		if (empty($model))
		{
			$this->error['add_device'] = _("You Must Select A Model From the Drop Down") . "!";
			return false;
		}
		if (empty($ext))
		{
			$this->error['add_device'] = _("You Must Select an Extension/Device From the Drop Down") . "!";
			return false;
		}
		if (!$this->sync_model($model))
		{
			return false;
		}

		$sql = sprintf("SELECT id, template_id FROM %s WHERE mac = %s", self::TABLES['epm_mac_list'], $this->q($mac));
		$dup = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
		if (!empty($dup))
		{
			if (!isset($template) || $template === '')
			{
				$template = $dup['template_id'];
			}
			$sql = sprintf("UPDATE %s SET model = %d, template_id = %d WHERE id = %d", self::TABLES['epm_mac_list'], (int) $model, (int) $template, (int) $dup['id']);
			$this->eda->sql($sql);
			return $this->add_line($dup['id'], $line, $ext, $displayname) ? (int) $dup['id'] : false;
		}

		if (!isset($template) || $template === '')
		{
			$template = 0;
		}

		$sql  = sprintf("SELECT mac_id FROM %s WHERE ext = %s", self::TABLES['epm_line_list'], $this->q($ext));
		$used = $this->eda->sql($sql, 'getOne');
		if ($used && !$this->getConfig('show_all_registrations'))
		{
			$this->error['add_device'] = _("You can't assign the same user to multiple devices") . "!";
			return false;
		}

		if (!isset($displayname) || $displayname === '')
		{
			$sql  = sprintf("SELECT description FROM %s WHERE id = %s", self::TABLES['devices'], $this->q($ext));
			$name = (string) $this->eda->sql($sql, 'getOne');
		}
		else
		{
			$name = $displayname;
		}

		// every text column is NOT NULL without a default (strict-mode MariaDB rejects the row otherwise)
		$sql = sprintf(
			"INSERT INTO %s (mac, model, template_id, global_custom_cfg_data, global_user_cfg_data, config_files_override, global_settings_override, specific_settings) VALUES (%s, %d, %d, '', '', '', '', '')",
			self::TABLES['epm_mac_list'], $this->q($mac), (int) $model, (int) $template
		);
		$this->eda->sql($sql);
		$mac_id = (int) $this->eda->sql('SELECT LAST_INSERT_ID()', 'getOne');

		if (empty($line))
		{
			$line = 1;
		}
		$sql = sprintf(
			"INSERT INTO %s (mac_id, ext, line, description, ipei, custom_cfg_data, user_cfg_data) VALUES (%d, %s, %d, %s, '', '', '')",
			self::TABLES['epm_line_list'], $mac_id, $this->q($ext), (int) $line, $this->q(mb_substr($name, 0, 20))
		);
		$this->eda->sql($sql);

		$this->message['add_device'] = sprintf(_("Added %s to line %s"), $name, $line);
		return $mac_id;
	}

	/**
	 * Add a line to an existing phone. With no $line the next free line is used,
	 * with no $ext the first unused registration is used.
	 * @return int|false mac_id
	 */
	public function add_line($mac_id, $line = NULL, $ext = NULL, $displayname = NULL)
	{
		$mac_id = (int) $mac_id;
		$lines  = $this->linesAvailable(NULL, $mac_id);
		if ($lines === false)
		{
			$this->error['add_line'] = _("No Lines Left to Add") . "!";
			return false;
		}
		$lines = array_values($lines);

		if (empty($ext))
		{
			$reg = $this->eda->all_unused_registrations();
			if (empty($reg))
			{
				$this->error['add_line'] = _("No Devices/Extensions Left to Add") . "!";
				return false;
			}
			$ext = $reg[0]['id'];
		}
		if (empty($line))
		{
			$line = $lines[0]['value'];
		}
		else
		{
			$sql  = sprintf("SELECT luid FROM %s WHERE line = %d AND mac_id = %d", self::TABLES['epm_line_list'], (int) $line, $mac_id);
			if ($this->eda->sql($sql, 'getOne'))
			{
				$this->error['add_line'] = _("This line has already been assigned!");
				return false;
			}
		}

		if (!isset($displayname) || $displayname === '')
		{
			$sql  = sprintf("SELECT description FROM %s WHERE id = %s", self::TABLES['devices'], $this->q($ext));
			$name = (string) $this->eda->sql($sql, 'getOne');
		}
		else
		{
			$name = $displayname;
		}

		$sql = sprintf(
			"INSERT INTO %s (mac_id, ext, line, description, ipei, custom_cfg_data, user_cfg_data) VALUES (%d, %s, %d, %s, '', '', '')",
			self::TABLES['epm_line_list'], $mac_id, $this->q($ext), (int) $line, $this->q(mb_substr($name, 0, 20))
		);
		$this->eda->sql($sql);
		$this->message['add_line'] = sprintf(_("Added '%s' (%s) to line %s. Configuration files are not generated until you click Save."), $name, $ext, $line);
		return $mac_id;
	}

	public function update_device($macid, $model, $template, $luid = NULL, $name = NULL, $line = NULL, $update_lines = TRUE)
	{
		$sql = sprintf("UPDATE %s SET model = %d, template_id = %d WHERE id = %d", self::TABLES['epm_mac_list'], (int) $model, (int) $template, (int) $macid);
		$this->eda->sql($sql);
		if ($update_lines)
		{
			if (!empty($luid))
			{
				$this->update_line($luid, NULL, $name, $line);
			}
			else
			{
				$this->update_line(NULL, $macid);
			}
		}
		return true;
	}

	/**
	 * Refresh a line (or every line of a phone): line number, and the description
	 * copied from the FreePBX device so the phone label follows extension renames.
	 */
	public function update_line($luid = NULL, $macid = NULL, $name = NULL, $line = NULL)
	{
		if (!empty($luid))
		{
			$sql = sprintf("SELECT * FROM %s WHERE luid = %d", self::TABLES['epm_line_list'], (int) $luid);
			$row = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
			if (empty($row))
			{
				return false;
			}
			if (!isset($name) || $name === '')
			{
				$sql  = sprintf("SELECT description FROM %s WHERE id = %s", self::TABLES['devices'], $this->q($row['ext']));
				$name = (string) $this->eda->sql($sql, 'getOne');
			}
			if (!isset($line) || $line === '')
			{
				$line = $row['line'];
			}
			$sql = sprintf("UPDATE %s SET line = %d, description = %s WHERE luid = %d", self::TABLES['epm_line_list'], (int) $line, $this->q(mb_substr($name, 0, 20)), (int) $luid);
			$this->eda->sql($sql);
			return true;
		}

		$sql   = sprintf("SELECT * FROM %s WHERE mac_id = %d", self::TABLES['epm_line_list'], (int) $macid);
		$lines = $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC);
		foreach ((array) $lines as $row)
		{
			$sql  = sprintf("SELECT description FROM %s WHERE id = %s", self::TABLES['devices'], $this->q($row['ext']));
			$name = (string) $this->eda->sql($sql, 'getOne');
			$sql  = sprintf("UPDATE %s SET description = %s WHERE luid = %d", self::TABLES['epm_line_list'], $this->q(mb_substr($name, 0, 20)), (int) $row['luid']);
			$this->eda->sql($sql);
		}
		return true;
	}

	/**
	 * Delete a line; the last line of a phone is only removed (together with the
	 * phone) when $allow_device_remove is true.
	 */
	public function delete_line($lineid, $allow_device_remove = FALSE)
	{
		$sql    = sprintf("SELECT mac_id FROM %s WHERE luid = %d", self::TABLES['epm_line_list'], (int) $lineid);
		$mac_id = $this->eda->sql($sql, 'getOne');
		if (empty($mac_id))
		{
			$this->error['delete_line'] = _("Line not found");
			return false;
		}
		$sql       = sprintf("SELECT COUNT(*) FROM %s WHERE mac_id = %d", self::TABLES['epm_line_list'], (int) $mac_id);
		$num_lines = (int) $this->eda->sql($sql, 'getOne');

		if ($num_lines > 1)
		{
			$this->eda->sql(sprintf("DELETE FROM %s WHERE luid = %d", self::TABLES['epm_line_list'], (int) $lineid));
			$this->message['delete_line'] = _("Deleted") . "!";
			return true;
		}
		if ($allow_device_remove)
		{
			return $this->delete_device($mac_id);
		}
		$this->error['delete_line'] = _("You can't remove the only line left") . "!";
		return false;
	}

	public function delete_device($mac_id)
	{
		$this->eda->sql(sprintf("DELETE FROM %s WHERE mac_id = %d", self::TABLES['epm_line_list'], (int) $mac_id));
		$this->eda->sql(sprintf("DELETE FROM %s WHERE id = %d", self::TABLES['epm_mac_list'], (int) $mac_id));
		$this->message['delete_device'] = _("Deleted") . "!";
		return true;
	}

	public function delete_device_by_mac($mac)
	{
		$mac_id = $this->retrieve_device_by_mac($mac);
		return $mac_id ? $this->delete_device($mac_id) : false;
	}

	/**
	 * Everything known about a phone: brand, product, model, template, MAC and its
	 * lines (with the FreePBX device row and the SIP secret of each extension).
	 * @return array|false
	 */
	public function get_phone_info($mac_id = NULL)
	{
		if (empty($mac_id))
		{
			$this->error['get_phone_info'] = _("Mac ID is not set");
			return false;
		}
		$mac_id = (int) $mac_id;

		$sql = sprintf("SELECT id, mac, model FROM %s WHERE id = %d", self::TABLES['epm_mac_list'], $mac_id);
		$row = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
		if (empty($row))
		{
			$this->error['get_phone_info'] = sprintf(_("Device %s not found"), $mac_id);
			return false;
		}

		$lines_sql = sprintf(
			"SELECT l.*, s.data AS secret, d.*, l.description AS epm_description
			 FROM %s AS l
			 JOIN %s AS d ON l.ext = d.id
			 LEFT JOIN sip AS s ON s.id = l.ext AND s.keyword = 'secret'
			 WHERE l.mac_id = %d ORDER BY l.line ASC",
			self::TABLES['epm_line_list'], self::TABLES['devices'], $mac_id
		);

		if ((int) $row['model'] > 0)
		{
			$sql = sprintf(
				"SELECT mac.id, mac.specific_settings, mac.config_files_override, mac.global_user_cfg_data, mac.global_settings_override,
						mac.mac, mac.template_id, mac.global_custom_cfg_data,
						m.id AS model_id, m.model, m.template_data, m.enabled, m.max_lines,
						b.id AS brand_id, b.name, b.directory,
						p.long_name, p.id AS product_id, p.cfg_dir, p.cfg_ver
				 FROM %s AS mac, %s AS m, %s AS b, %s AS p
				 WHERE mac.model = m.id AND b.id = m.brand AND p.id = m.product_id AND mac.id = %d",
				self::TABLES['epm_mac_list'], self::TABLES['epm_model_list'], self::TABLES['epm_brands_list'], self::TABLES['epm_product_list'], $mac_id
			);
			$phone_info = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
			if (empty($phone_info))
			{
				$this->error['get_phone_info'] = sprintf(_("Device %s points at a model that no longer exists; edit it and pick a model"), $mac_id);
				return false;
			}
			if ((int) $phone_info['template_id'] > 0)
			{
				$sql = sprintf("SELECT name, global_custom_cfg_data, config_files_override, global_settings_override FROM %s WHERE id = %d", self::TABLES['epm_template_list'], (int) $phone_info['template_id']);
				$phone_info['template_data_info'] = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
			}
		}
		else
		{
			// Unknown model: enough information for the list and the edit form
			$brand = $this->get_brand_from_mac($row['mac']);
			$phone_info = array(
				'id'                  => $mac_id,
				'mac'                 => $row['mac'],
				'brand_id'            => $brand ? $brand['id'] : 0,
				'name'                => $brand ? $brand['name'] : _("Unknown"),
				'model_id'            => 0,
				'model'               => _("Unknown"),
				'product_id'          => 0,
				'template_id'         => 0,
				'custom_cfg_template' => 0,
			);
		}

		$phone_info['line'] = array();
		$lines_info = $this->eda->sql($lines_sql, 'getAll', \PDO::FETCH_ASSOC);
		foreach ((array) $lines_info as $line)
		{
			$n = (int) $line['line'];
			$phone_info['line'][$n] = $line;
			$phone_info['line'][$n]['description']    = $line['epm_description'];
			$phone_info['line'][$n]['user_extension'] = $line['user'] ?? $line['ext'];
		}
		return $phone_info;
	}

	/**
	 * Registration state of every SIP/PJSIP device, keyed by extension.
	 * @return array [ext => ['status' => bool, 'ip' => string]]
	 */
	public function device_status_map()
	{
		$out = array();
		$run = function ($cmd) {
			$text = '';
			if (is_object($this->astman) && method_exists($this->astman, 'Command'))
			{
				try
				{
					$res  = $this->astman->Command($cmd);
					$text = is_array($res) ? ($res['data'] ?? implode("\n", $res)) : (string) $res;
				}
				catch (\Throwable $e)
				{
					$text = '';
				}
			}
			if ($text === '')
			{
				$bin  = $this->getConfig('asterisk_location', 'asterisk');
				$text = (string) shell_exec(escapeshellcmd($bin) . " -rx " . escapeshellarg($cmd) . " 2>/dev/null");
			}
			return explode("\n", $text);
		};

		foreach ($run('pjsip show contacts') as $data)
		{
			if (preg_match('/Contact:\s+(\d+)\/sip:[^@]*@(\d{1,3}(?:\.\d{1,3}){3})/i', $data, $m))
			{
				$out[$m[1]] = array('status' => (bool) preg_match('/\b(Avail|NonQual)\b/i', $data), 'ip' => $m[2]);
			}
		}
		foreach ($run('sip show peers') as $data)
		{
			if (preg_match('/^(\d+)\/[^\s]+\s+(\d{1,3}(?:\.\d{1,3}){3})/', $data, $m))
			{
				$out[$m[1]] = array('status' => (bool) preg_match('/OK \(/i', $data), 'ip' => $m[2]);
			}
		}
		return $out;
	}

	public function validate_netmask($mask)
	{
		return (bool) preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/', (string) $mask);
	}

	/**
	 * Discover phones on the LAN from the ARP cache, optionally after an nmap ping sweep.
	 * @return array|false [['ip','mac','mac_strip','oui','brand','brand_id','endpoint_managed']]
	 */
	public function discover_new($netmask, $use_nmap = TRUE)
	{
		if (!$this->validate_netmask($netmask))
		{
			$this->error['discover_new'] = _("Invalid Netmask");
			return false;
		}
		$nmap = $this->getConfig('nmap_location', '/usr/bin/nmap');
		$arp  = $this->getConfig('arp_location', '/usr/sbin/arp');
		if ($use_nmap)
		{
			if (!empty($nmap) && is_executable($nmap))
			{
				shell_exec(escapeshellcmd($nmap) . ' -n -sn ' . escapeshellarg($netmask) . ' 2>/dev/null');
			}
			else
			{
				$this->message['discover_new'] = _("Could Not Find NMAP, Using ARP Only");
			}
		}

		$arp_list = '';
		if (!empty($arp) && is_executable($arp))
		{
			$arp_list = (string) shell_exec(escapeshellcmd($arp) . ' -an 2>/dev/null');
		}
		if (trim($arp_list) === '' && is_executable('/usr/sbin/ip'))
		{
			// arp(8) missing (Debian 12+ minimal): translate "ip neigh" into arp -an lines
			foreach (explode("\n", (string) shell_exec('/usr/sbin/ip -4 neigh show 2>/dev/null')) as $l)
			{
				if (preg_match('/^(\S+)\s+.*lladdr\s+([0-9a-f:]{17})/i', $l, $m))
				{
					$arp_list .= "? (" . $m[1] . ") at " . $m[2] . "\n";
				}
			}
		}

		$rows  = array_values(array_unique(preg_grep('/([0-9a-f]{2}[:-]){5}[0-9a-f]{2}/i', explode("\n", $arp_list))));
		$final = array();
		foreach ($rows as $value)
		{
			if (!preg_match('/\((\d{1,3}(?:\.\d{1,3}){3})\)/', $value, $ipm) || !preg_match('/(([0-9a-f]{2}[:-]){5}[0-9a-f]{2})/i', $value, $macm))
			{
				continue;
			}
			$ip        = $ipm[1];
			$mac       = $macm[1];
			$mac_strip = strtoupper(preg_replace('/[^0-9a-f]/i', '', $mac));
			if ($mac_strip === '000000000000' || $mac_strip === 'FFFFFFFFFFFF')
			{
				continue;
			}
			$brand   = $this->get_brand_from_mac($mac_strip);
			$sql     = sprintf("SELECT id FROM %s WHERE mac = %s", self::TABLES['epm_mac_list'], $this->q($mac_strip));
			$managed = (bool) $this->eda->sql($sql, 'getOne');
			$final[] = array(
				'ip'               => $ip,
				'mac'              => $mac,
				'mac_strip'        => $mac_strip,
				'oui'              => substr($mac_strip, 0, 6),
				'brand'            => ($brand && $brand['id']) ? $brand['name'] : false,
				'brand_id'         => ($brand && $brand['id']) ? $brand['id'] : null,
				'endpoint_managed' => $managed,
			);
		}
		return empty($final) ? false : $final;
	}

	/**
	 * Load the Provisioner backend classes for a phone and return a configured
	 * instance of endpoint_<brand>_<family>_phone.
	 * @return object|false
	 */
	private function load_provisioner($phone_info)
	{
		$base_dir  = $this->system->buildPath($this->PHONE_MODULES_PATH, 'endpoint');
		$brand_dir = $this->system->buildPath($base_dir, $phone_info['directory']);
		$fam_dir   = $this->system->buildPath($brand_dir, $phone_info['cfg_dir']);
		$files     = array(
			'endpoint_base'                                                              => $this->system->buildPath($base_dir, 'base.php'),
			'endpoint_' . $phone_info['directory'] . '_base'                             => $this->system->buildPath($brand_dir, 'base.php'),
			'endpoint_' . $phone_info['directory'] . '_' . $phone_info['cfg_dir'] . '_phone' => $this->system->buildPath($fam_dir, 'phone.php'),
		);
		$class = 'endpoint_' . $phone_info['directory'] . '_' . $phone_info['cfg_dir'] . '_phone';

		$ok = $this->runLegacy(function () use ($files) {
			foreach ($files as $cls => $file)
			{
				if (!class_exists($cls, false))
				{
					if (!is_file($file))
					{
						return $file;
					}
					require_once $file;
				}
			}
			return true;
		});
		if ($ok !== true)
		{
			$this->error['parse_configs'] = sprintf(_("Provisioner file '%s' is missing. Reinstall the brand package from the Package Manager."), $ok);
			return false;
		}
		if (!class_exists($class, false))
		{
			$this->error['parse_configs'] = sprintf(_("Can't load class '%s' from the Provisioner package"), $class);
			return false;
		}

		$root    = rtrim($this->PHONE_MODULES_PATH, '/') . '/';
		$engine  = $this->getConfig('asterisk_location', 'asterisk') ?: 'asterisk';
		$version = "EndPoint Manager Version " . $this->getConfig('version');
		// Every write into the backend object happens under runLegacy(): the backend
		// declares few properties and PHP 8.2 reports each dynamic one as deprecated,
		// which FreePBX's error handler would otherwise turn into an exception.
		return $this->runLegacy(function () use ($class, $root, $engine, $version, $phone_info) {
			$lib = new $class();
			$lib->root_dir        = $root;
			$lib->engine          = 'asterisk';
			$lib->engine_location = $engine;
			$lib->system          = 'unix';
			$lib->brand_name      = $phone_info['directory'];
			$lib->family_line     = $phone_info['cfg_dir'];
			$lib->model           = $phone_info['model'];
			$lib->processor_info  = $version;
			return $lib;
		});
	}

	/**
	 * Global settings that apply to a phone: the template's override, else the
	 * device's own override, else the module settings.
	 */
	private function effective_settings($phone_info)
	{
		$settings = null;
		if ((int) ($phone_info['template_id'] ?? 0) > 0 && !empty($phone_info['template_data_info']['global_settings_override']))
		{
			$settings = @unserialize($phone_info['template_data_info']['global_settings_override']);
		}
		elseif (!empty($phone_info['global_settings_override']))
		{
			$settings = @unserialize($phone_info['global_settings_override']);
		}
		if (!is_array($settings))
		{
			$settings = array();
		}
		$defaults = array(
			'srvip'           => $this->getConfig('srvip'),
			'srvport'         => $this->getConfig('srvport', '5060'),
			'ntp'             => $this->getConfig('ntp'),
			'config_location' => $this->getConfig('config_location'),
			'tz'              => $this->getConfig('tz'),
		);
		foreach ($defaults as $k => $v)
		{
			if (!isset($settings[$k]) || $settings[$k] === '' || $settings[$k] === null)
			{
				$settings[$k] = $v;
			}
		}
		return $settings;
	}

	/**
	 * Build the data Provisioner expects, generate the files and (optionally) write
	 * them and reboot the phone.
	 * @param array $phone_info From get_phone_info()
	 * @param bool  $reboot     Reboot after writing
	 * @param bool  $write      Write files (true) or return the generated files (false)
	 * @return bool|array
	 */
	public function prepare_configs($phone_info, $reboot = TRUE, $write = TRUE)
	{
		if (empty($phone_info) || empty($phone_info['directory']) || empty($phone_info['cfg_dir']))
		{
			$this->error['parse_configs'] = _("This device has no model assigned, edit it and pick a brand and model first");
			return false;
		}
		if (empty($phone_info['line']))
		{
			$this->error['parse_configs'] = sprintf(_("Device %s has no lines"), $phone_info['mac']);
			return false;
		}

		$lib = $this->load_provisioner($phone_info);
		if (!$lib)
		{
			return false;
		}

		$settings = $this->effective_settings($phone_info);
		if (empty($settings['srvip']))
		{
			$this->error['parse_configs'] = _("The IP address of the phone server is not set (Advanced Settings)");
			return false;
		}

		try
		{
			$tz = new \DateTimeZone($settings['tz'] ?: date_default_timezone_get());
		}
		catch (\Exception $e)
		{
			$this->error['parse_configs'] = _("Error Returned From Timezone Library: ") . $e->getMessage();
			return false;
		}

		$global_user_cfg_data = @unserialize((string) ($phone_info['global_user_cfg_data'] ?? ''));
		if ((int) $phone_info['template_id'] > 0)
		{
			$global_custom_cfg_data = @unserialize((string) ($phone_info['template_data_info']['global_custom_cfg_data'] ?? ''));
			$override_ids           = @unserialize((string) ($phone_info['template_data_info']['config_files_override'] ?? ''));
		}
		else
		{
			$global_custom_cfg_data = @unserialize((string) ($phone_info['global_custom_cfg_data'] ?? ''));
			$override_ids           = @unserialize((string) ($phone_info['config_files_override'] ?? ''));
		}

		// Alternate configuration files stored in the database instead of the ones on disk
		$override_files = array();
		if (is_array($override_ids))
		{
			foreach ($override_ids as $list)
			{
				$sql  = sprintf("SELECT original_name, data FROM endpointman_custom_configs WHERE id = %d", (int) $list);
				$data = $this->eda->sql($sql, 'getRow', \PDO::FETCH_ASSOC);
				if (!empty($data))
				{
					$override_files[$data['original_name']] = $data['data'];
				}
			}
		}

		$global_custom_cfg_ari = array();
		if (is_array($global_custom_cfg_data) && array_key_exists('data', $global_custom_cfg_data))
		{
			$global_custom_cfg_ari  = is_array($global_custom_cfg_data['ari'] ?? null) ? $global_custom_cfg_data['ari'] : array();
			$global_custom_cfg_data = is_array($global_custom_cfg_data['data']) ? $global_custom_cfg_data['data'] : array();
		}
		else
		{
			$global_custom_cfg_data = array();
		}
		$use_ari = ($this->getConfig('enable_ari') == 1) && is_array($global_user_cfg_data);

		$new_template_data = array();
		$line_ops          = array();
		foreach ($global_custom_cfg_data as $full_key => $data)
		{
			$value = ($use_ari && isset($global_custom_cfg_ari[$full_key]) && isset($global_user_cfg_data[$full_key])) ? $global_user_cfg_data[$full_key] : $data;
			$key   = explode('|', $full_key);
			switch (count($key))
			{
				case 1:
					$new_template_data[$full_key] = $value;
					break;
				case 2:
					$breaks = explode('_', $key[1]);
					if (isset($breaks[2]))
					{
						$new_template_data['loops'][$breaks[0]][$breaks[2]][$breaks[1]] = $value;
					}
					break;
				case 3:
					$line_ops[$key[1]][$key[2]] = $value;
					break;
			}
		}

		if (!$write)
		{
			$new_template_data['provision'] = array(
				'type'       => 'dynamic',
				'protocol'   => 'http',
				'path'       => rtrim($settings['srvip'] . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/', '/'),
				'encryption' => FALSE,
			);
		}
		else
		{
			$new_template_data['provision'] = array(
				'type'       => 'file',
				'protocol'   => 'tftp',
				'path'       => $settings['srvip'],
				'encryption' => FALSE,
			);
		}
		$new_template_data['ntp'] = $settings['ntp'];

		$specific_settings = array();
		if (!empty($phone_info['specific_settings']))
		{
			$specific_settings = @unserialize($phone_info['specific_settings']);
			$specific_settings = is_array($specific_settings) ? $specific_settings : array();
		}

		$lib_settings = $new_template_data;

		// SIP port for {$server_port.line.N} / {$server.port.1}: template or device override, else global setting, else 5060
		$server_port = (string) $settings['srvport'];
		if (!ctype_digit($server_port) || (int) $server_port < 1 || (int) $server_port > 65535)
		{
			$server_port = '5060';
		}

		$li = 0;
		foreach ($phone_info['line'] as $line)
		{
			$line_options = (isset($line_ops[$line['line']]) && is_array($line_ops[$line['line']])) ? $line_ops[$line['line']] : array();
			$line_statics = array(
				'line'           => $line['line'],
				'username'       => $line['ext'],
				'authname'       => $line['ext'],
				'secret'         => $line['secret'] ?? '',
				'displayname'    => $line['description'],
				'server_host'    => $settings['srvip'],
				'server_port'    => $server_port,
				'user_extension' => $line['user_extension'] ?? $line['ext'],
			);
			$lib_settings['line'][$li] = array_merge($line_options, $line_statics);
			$li++;
		}

		if (array_key_exists('data', $specific_settings) && is_array($specific_settings['data']))
		{
			foreach ($specific_settings['data'] as $key => $data)
			{
				$default_exp = explode('|', $key);
				if (isset($default_exp[2]))
				{
					$var  = $default_exp[2];
					$line = $default_exp[1];
					$loc  = $this->system->arraysearchrecursive($line, $lib_settings['line'], 'line');
					if ($loc !== FALSE)
					{
						$lib_settings['line'][$loc[0]][$var] = $data;
					}
					elseif (isset($specific_settings['data']['line|' . $line . '|line_enabled']))
					{
						$keys    = array_keys($lib_settings['line']);
						$lastkey = ((int) array_pop($keys)) + 1;
						$lib_settings['line'][$lastkey]['line'] = $line;
						$lib_settings['line'][$lastkey][$var]   = $data;
					}
				}
				else
				{
					switch ($key)
					{
						case "connection_type":
						case "primary_dns":
							$lib_settings['network'][$key] = $data;
							break;
						case "ip4_address":
							$lib_settings['network']['ipv4'] = $data;
							break;
						case "ip6_address":
							$lib_settings['network']['ipv6'] = $data;
							break;
						case "subnet_mask":
							$lib_settings['network']['subnet'] = $data;
							break;
						case "gateway_address":
							$lib_settings['network']['gateway'] = $data;
							break;
						default:
							$lib_settings[$key] = $data;
							break;
					}
				}
			}
		}

		$lib_settings['mac'] = $phone_info['mac'];
		$mac   = $phone_info['mac'];
		$debug = (bool) $this->getConfig('debug');

		$time_start = microtime(true);
		try
		{
			$returned_data = $this->runLegacy(function () use ($lib, $lib_settings, $override_files, $tz, $mac, $debug) {
				$lib->DateTimeZone = $tz;
				foreach ($override_files as $name => $content)
				{
					$lib->config_files_override[$name] = $content;
				}
				$lib->settings = $lib_settings;
				$lib->mac      = $mac;
				$lib->debug    = $debug;
				return $lib->generate_all_files();
			});
		}
		catch (\Throwable $e)
		{
			$this->error['prepare_configs'] = _("Error Returned From Provisioner Library: ") . $e->getMessage();
			return false;
		}
		if (!is_array($returned_data) || empty($returned_data))
		{
			$this->error['prepare_configs'] = _("The Provisioner library generated no files");
			return false;
		}
		if ((microtime(true) - $time_start) > 360)
		{
			$this->error['generate_time'] = sprintf(_("It took an awfully long time to generate configs (%s seconds)"), round(microtime(true) - $time_start, 2));
		}

		if (!$write)
		{
			return $returned_data;
		}
		return $this->write_configs($lib, $reboot, $settings['config_location'], $phone_info, $returned_data);
	}

	/**
	 * Write generated files (plus the package's static directories/files) to the
	 * configuration location, keeping a copy of the previous version, then reboot.
	 */
	public function write_configs($lib, $reboot, $write_path, $phone_info, $returned_data)
	{
		$write_path = rtrim((string) $write_path, '/') . '/';
		if ($write_path === '/' || !is_dir($write_path) || !is_writable($write_path))
		{
			$this->error['parse_configs'] = sprintf(_("Configuration Location '%s' is not a writable directory (Advanced Settings)"), $write_path);
			return false;
		}
		$pkg_dir = $this->system->buildPath($this->PHONE_MODULES_PATH, 'endpoint', $phone_info['directory'], $phone_info['cfg_dir']) . '/';

		if (!empty($lib->directory_structure))
		{
			foreach ((array) $lib->directory_structure as $data)
			{
				$src = $pkg_dir . $data;
				if (is_dir($src))
				{
					$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
					foreach ($iterator as $file)
					{
						$dir = $write_path . str_replace($pkg_dir, '', dirname((string) $file));
						if (!is_dir($dir) && !@mkdir($dir, 0775, true))
						{
							$this->error['parse_configs'] = _("Could Not Create Directory: ") . $data;
							return false;
						}
					}
				}
				elseif (!is_dir($write_path . $data) && !@mkdir($write_path . $data, 0775, true))
				{
					$this->error['parse_configs'] = _("Could Not Create Directory: ") . $data;
					return false;
				}
			}
		}

		if (!empty($lib->copy_files))
		{
			foreach ((array) $lib->copy_files as $data)
			{
				$orig = $pkg_dir . $data;
				if (!file_exists($orig))
				{
					continue;
				}
				$dest = $write_path . $data;
				if (!is_dir(dirname($dest)))
				{
					@mkdir(dirname($dest), 0775, true);
				}
				if (!@copy($orig, $dest))
				{
					$this->error['parse_configs'] = _("Could Not Create File: ") . $data;
					return false;
				}
				@chmod($dest, 0664);
			}
		}

		$protected = is_array($lib->protected_files) ? $lib->protected_files : array();
		$backup    = !$this->getConfig('backup_check');
		foreach ($returned_data as $file => $data)
		{
			$target = $write_path . $file;
			if (file_exists($target) && in_array($file, $protected, true))
			{
				continue;
			}
			if (!is_dir(dirname($target)))
			{
				@mkdir(dirname($target), 0775, true);
			}
			if (file_exists($target) && !is_writable($target))
			{
				$this->error['parse_configs'] = sprintf(_("File %s is not writable"), $target);
				return false;
			}
			if ($backup && file_exists($target))
			{
				if (!is_dir($write_path . 'config_bkup') && !@mkdir($write_path . 'config_bkup', 0775, true))
				{
					$this->error['parse_configs'] = _("Could Not Create Backup Directory");
					return false;
				}
				@copy($target, $write_path . 'config_bkup/' . basename($file) . '.' . time());
			}
			if (@file_put_contents($target, $data) === false)
			{
				$this->error['parse_configs'] = sprintf(_("File (%s) not written to hard drive!"), $file);
				return false;
			}
			@chmod($target, 0664);
		}

		$this->message['write_configs'][] = sprintf(_("Configuration written for %s (%d files in %s)"), $phone_info['mac'], count($returned_data), $write_path);
		if ($reboot)
		{
			$this->runLegacy(function () use ($lib) { $lib->reboot(); });
		}
		return true;
	}

	/**
	 * Reboot a phone through its Provisioner class (SIP NOTIFY etc.) without rewriting files.
	 */
	public function reboot_phone($phone_info)
	{
		if (empty($phone_info) || empty($phone_info['directory']) || empty($phone_info['line']))
		{
			return false;
		}
		$lib = $this->load_provisioner($phone_info);
		if (!$lib)
		{
			return false;
		}
		$first = reset($phone_info['line']);
		$line0 = array('line' => $first['line'], 'username' => $first['ext'], 'authname' => $first['ext'], 'tech' => $first['tech'] ?? 'pjsip');
		$mac   = $phone_info['mac'];
		$this->runLegacy(function () use ($lib, $line0, $mac) {
			$lib->settings['line'][0] = $line0;
			$lib->mac = $mac;
			$lib->reboot();
		});
		return true;
	}

	/**
	 * Rebuild the configuration of one phone by mac id (used by the pages and the Extensions hook).
	 */
	public function rebuild_device($mac_id, $reboot = FALSE)
	{
		$phone_info = $this->get_phone_info($mac_id);
		if (!$phone_info)
		{
			return false;
		}
		$this->update_line(NULL, $mac_id);
		$phone_info = $this->get_phone_info($mac_id);
		return $this->prepare_configs($phone_info, $reboot, TRUE) === true;
	}

	/**
	 * Messages and errors collected by the engine, as the page shows them.
	 * @return array ['message' => [...], 'error' => [...]]
	 */
	public function collect_messages(bool $clear = true)
	{
		$flat = function ($list) {
			$out = array();
			foreach ((array) $list as $key => $item)
			{
				foreach ((array) $item as $text)
				{
					if ((string) $text !== '')
					{
						$out[] = (string) $text;
					}
				}
			}
			return array_values(array_unique($out));
		};
		$out = array('message' => $flat($this->message), 'error' => $flat($this->error));
		if ($clear)
		{
			$this->message = array();
			$this->error   = array();
		}
		return $out;
	}

    /**
     * Save template from the template view pain
     * @param int $id Either the MAC ID or Template ID
     * @param int $custom Either 0 or 1, it determines if $id is MAC ID or Template ID
     * @param array $variables The variables sent from the form. usually everything in $_REQUEST[]
     * @return string Location of area to return to in Endpoint Manager
     */
    function save_template($id, $custom, $variables) {
        //Custom Means specific to that MAC
        //This function is reversed. Not sure why

        if ($custom != "0") {
            $sql = "SELECT endpointman_model_list.max_lines, endpointman_product_list.config_files, endpointman_mac_list.*, endpointman_product_list.id as product_id, endpointman_product_list.long_name, endpointman_model_list.template_data, endpointman_product_list.cfg_dir, endpointman_brand_list.directory FROM endpointman_brand_list, endpointman_mac_list, endpointman_model_list, endpointman_product_list WHERE endpointman_mac_list.id=" . $id . " AND endpointman_mac_list.model = endpointman_model_list.id AND endpointman_model_list.brand = endpointman_brand_list.id AND endpointman_model_list.product_id = endpointman_product_list.id";
        } else {
            $sql = "SELECT endpointman_model_list.max_lines, endpointman_brand_list.directory, endpointman_product_list.cfg_dir, endpointman_product_list.config_files, endpointman_product_list.long_name, endpointman_model_list.template_data, endpointman_model_list.id as model_id, endpointman_template_list.* FROM endpointman_brand_list, endpointman_product_list, endpointman_model_list, endpointman_template_list WHERE endpointman_product_list.id = endpointman_template_list.product_id AND endpointman_brand_list.id = endpointman_product_list.brand AND endpointman_template_list.model_id = endpointman_model_list.id AND endpointman_template_list.id = " . $id;
        }

        //Load template data
        $row = sql($sql, 'getRow', \PDO::FETCH_ASSOC);

        $cfg_data = unserialize($row['template_data']);
        $count = count($cfg_data);

        $custom_cfg_data_ari = array();

        foreach ($cfg_data['data'] as $cats) {
            foreach ($cats as $items) {
                foreach ($items as $key_name => $config_options) {
                    if (preg_match('/(.*)\|(.*)/i', $key_name, $matches)) {
                        $type = $matches[1];
                        $key = $matches[2];
                    } else {
                        die('invalid');
                    }
                    switch ($type) {
                        case "loop":
                            $stuffing = explode("_", $key);
                            $key2 = $stuffing[0];
                            foreach ($config_options as $item_key => $item_data) {
                                $lc = isset($item_data['loop_count']) ? $item_data['loop_count'] : '';
                                $key = 'loop|' . $key2 . '_' . $item_key . '_' . $lc;
                                if ((isset($item_data['loop_count'])) AND (isset($variables[$key]))) {
                                    $custom_cfg_data[$key] = $variables[$key];
                                    $ari_key = "ari_" . $key;
                                    if (isset($variables[$ari_key])) {
                                        if ($variables[$ari_key] == "on") {
                                            $custom_cfg_data_ari[$key] = 1;
                                        }
                                    }
                                }
                            }
                            break;
                        case "lineloop":
                            foreach ($config_options as $item_key => $item_data) {
                                $lc = isset($item_data['line_count']) ? $item_data['line_count'] : '';
                                $key = 'line|' . $lc . '|' . $item_key;
                                if ((isset($item_data['line_count'])) AND (isset($variables[$key]))) {
                                    $custom_cfg_data[$key] = $variables[$key];
                                    $ari_key = "ari_" . $key;
                                    if (isset($variables[$ari_key])) {
                                        if ($variables[$ari_key] == "on") {
                                            $custom_cfg_data_ari[$key] = 1;
                                        }
                                    }
                                }
                            }
                            break;
                        case "option":
                            if (isset($variables[$key])) {
                                $custom_cfg_data[$key] = $variables[$key];
                                $ari_key = "ari_" . $key;
                                if (isset($variables[$ari_key])) {
                                    if ($variables[$ari_key] == "on") {
                                        $custom_cfg_data_ari[$key] = 1;
                                    }
                                }
                            }
                            break;
                        default:
                            break;
                    }
                }
            }
        }

        $config_files = explode(",", $row['config_files']);

        $i = 0;
        while ($i < count($config_files)) {
            $config_files[$i] = str_replace(".", "_", $config_files[$i]);

            if (isset($variables['config_files'][$i])) {

                $variables[$config_files[$i]] = explode("_", $variables['config_files'][$i], 2);

                $variables[$config_files[$i]] = $variables[$config_files[$i]][0];
                if ($variables[$config_files[$i]] > 0) {
                    $config_files_selected[$config_files[$i]] = $variables[$config_files[$i]];


                }
            }
            $i++;
        }
        if (!isset($config_files_selected)) {
            $config_files_selected = "";
        } else {
            $config_files_selected = serialize($config_files_selected);
        }
        $custom_cfg_data_temp['data'] = $custom_cfg_data;
        $custom_cfg_data_temp['ari'] = $custom_cfg_data_ari;

        $save = serialize($custom_cfg_data_temp);
        if ($custom == "0") {
            $sql = 'UPDATE endpointman_template_list SET config_files_override = \'' . addslashes($config_files_selected) . '\', global_custom_cfg_data = \'' . addslashes($save) . '\' WHERE id =' . $id;
            $location = "template_manager";
			//print_r($sql);
        } else {
            $sql = 'UPDATE endpointman_mac_list SET config_files_override = \'' . addslashes($config_files_selected) . '\', template_id = 0, global_custom_cfg_data = \'' . addslashes($save) . '\' WHERE id =' . $id;
            $location = "devices_manager";
        }
        sql($sql);

        $phone_info = array();
/*
        if ($custom != 0) {
            $phone_info = $this->get_phone_info($id);
            if (isset($variables['epm_reboot'])) {
                $this->prepare_configs($phone_info);
            } else {
                $this->prepare_configs($phone_info, FALSE);
            }
        } else {
            $sql = 'SELECT id FROM endpointman_mac_list WHERE template_id = ' . $id;
            $phones = sql($sql, 'getAll', \PDO::FETCH_ASSOC);
            foreach ($phones as $data) {
                $phone_info = $this->get_phone_info($data['id']);
                if (isset($variables['epm_reboot'])) {
                    $this->prepare_configs($phone_info);
                } else {
                    $this->prepare_configs($phone_info, FALSE);
                }
            }
        }
*/

        if (isset($variables['silent_mode'])) {
            echo '<script language="javascript" type="text/javascript">window.close();</script>';
        } else {
            return($location);
        }
    }



    function display_configs() {

    }












    //BORRAR!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
	//OBSOLETO, ANTIGUAMENTE VENTANAS EMERGENTES, AHORA SON DIALOGOS JQUERY.
 /*   function prepare_message_box() {
        $error_message = NULL;
        foreach ($this->error as $key => $error) {
            $error_message .= $error;
            if ($this->eda->global_cfg['debug']) {
                $error_message .= " Function: [" . $key . "]";
            }
            $error_message .= "<br />";
        }
        $message = NULL;
        foreach ($this->message as $key => $error) {
            if (is_array($error)) {
                foreach ($error as $sub_error) {
                    $message .= $sub_error;
                    if ($this->eda->global_cfg['debug']) {
                        $message .= " Function: [" . $key . "]";
                    }
                    $message .= "<br />";
                }
            } else {
                $message .= $error;
                if ($this->eda->global_cfg['debug']) {
                    $message .= " Function: [" . $key . "]";
                }
                $message .= "<br />";
            }
        }

        if (isset($message)) {
            $this->display_message_box($message, 0);
        }

        if (isset($error_message)) {
            $this->display_message_box($error_message, 1);
        }
    }
*/




}
