<?php
/**
 * Endpoint Manager - import of an extension mapping exported from another system
 *
 * Reads the JSON written by tools/export-commercial-epm.php (format "oss-epm-mapping"),
 * checks every phone and line against what THIS system knows, shows a preview, and only on
 * confirmation creates the phones through the same add_device()/add_line() path as the
 * Add Device form. Nothing is written by the preview.
 *
 * Policy: a phone is skipped only when it cannot be created at all (unusable MAC, brand not
 * installed here, model unknown here, MAC already mapped, no usable line). A line is skipped
 * only when it cannot be created (extension does not exist here, already mapped to another
 * phone, line number beyond the model, duplicate line number). Everything else imports.
 *
 * @author JD Austin
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Endpointman_Import
{
	const SESSION_KEY = 'endpointman_import_preview';
	const FORMAT      = 'oss-epm-mapping';
	const MAX_BYTES   = 4194304; // 4 MB is thousands of phones

	public function __construct($epm)
	{
		$this->epm    = $epm;
		$this->db     = $epm->freepbx->Database;
		$this->system = $epm->system;
		$this->eda    = $epm->eda;
	}

	/*********************************************************************
	 * Loading
	 *********************************************************************/

	/**
	 * Parse an uploaded file. Returns the document array or a string error.
	 */
	public function load_upload(array $file)
	{
		if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
		{
			$code = isset($file['error']) ? (int) $file['error'] : -1;
			return $code === UPLOAD_ERR_NO_FILE ? _("No file was uploaded.") : sprintf(_("Upload failed (error %d)."), $code);
		}
		if ($file['size'] > self::MAX_BYTES)
		{
			return sprintf(_("File is larger than %d MB."), self::MAX_BYTES / 1048576);
		}
		$raw = @file_get_contents($file['tmp_name']);
		if ($raw === false || $raw === '')
		{
			return _("The uploaded file is empty.");
		}
		$doc = json_decode($raw, true);
		if (!is_array($doc))
		{
			return sprintf(_("Not valid JSON: %s"), json_last_error_msg());
		}
		if (($doc['format'] ?? '') !== self::FORMAT)
		{
			return sprintf(_("Not an Endpoint Manager mapping export (format is '%s', expected '%s')."), (string) ($doc['format'] ?? ''), self::FORMAT);
		}
		if ((int) ($doc['version'] ?? 0) !== 1)
		{
			return sprintf(_("Unsupported export version %s."), (string) ($doc['version'] ?? '?'));
		}
		if (empty($doc['phones']) || !is_array($doc['phones']))
		{
			return _("The export contains no phones.");
		}
		return $doc;
	}

	/*********************************************************************
	 * Preview: validate every phone and line against this system
	 *********************************************************************/

	public function preview(array $doc)
	{
		$brands  = $this->brands_installed();      // directory => row
		$models  = $this->models_by_brand();        // brand id => [normalized model => row]
		$exts    = $this->extensions_here();        // ext => description
		$mapped  = $this->extensions_mapped();      // ext => mac
		$show_all = (bool) $this->epm->getConfig('show_all_registrations');

		$source_problems = array();
		foreach ((array) ($doc['problems'] ?? array()) as $pr)
		{
			$key = strtoupper(preg_replace('/[^0-9a-f]/i', '', (string) ($pr['mac'] ?? '')));
			$source_problems[$key][] = $pr;
		}

		$out   = array();
		$stats = array('create' => 0, 'skip' => 0, 'lines' => 0, 'lines_skipped' => 0);
		foreach ($doc['phones'] as $idx => $p)
		{
			$row = array(
				'idx'         => $idx,
				'mac_raw'     => (string) ($p['mac'] ?? ''),
				'mac'         => '',
				'brand_src'   => strtolower(trim((string) ($p['brand'] ?? ''))),
				'model_src'   => trim((string) ($p['model'] ?? '')),
				'template_src'=> (string) ($p['template'] ?? ''),
				'brand_id'    => 0,
				'brand_name'  => '',
				'model_id'    => 0,
				'model_name'  => '',
				'max_lines'   => 0,
				'product_id'  => 0,
				'templates'   => array(),   // OSS templates for this product: id => name
				'template_id' => 0,
				'lines'       => array(),
				'status'      => 'create',  // create | skip
				'reasons'     => array(),   // why skipped
				'notes'       => array(),   // informational
				'source'      => array(),   // problems the exporter flagged
			);
			foreach ($source_problems[strtoupper(preg_replace('/[^0-9a-f]/i', '', $row['mac_raw']))] ?? array() as $pr)
			{
				$row['source'][] = strtoupper((string) $pr['level']) . ': ' . (string) $pr['message'];
			}

			// MAC
			$mac = $this->system->mac_check_clean($row['mac_raw']);
			if (!$mac)
			{
				$row['reasons'][] = _("MAC address is not usable");
			}
			elseif (preg_match('/^(.)\1{11}$/', $mac))
			{
				$row['reasons'][] = _("MAC address is a placeholder, not a device");
			}
			elseif ($this->epm->retrieve_device_by_mac($mac))
			{
				$row['reasons'][] = _("this MAC is already mapped on this system");
			}
			$row['mac'] = $mac ?: '';

			// brand
			$brand = $brands[$row['brand_src']] ?? null;
			if (!$brand)
			{
				$row['reasons'][] = sprintf(_("brand '%s' is not installed here (Package Manager)"), $row['brand_src']);
			}
			else
			{
				$row['brand_id']   = (int) $brand['id'];
				$row['brand_name'] = (string) $brand['name'];
				$model = $this->match_model($models[$row['brand_id']] ?? array(), $row['model_src']);
				if (!$model)
				{
					$row['reasons'][] = sprintf(_("model '%s' is not known for brand %s"), $row['model_src'], $brand['name']);
				}
				else
				{
					$row['model_id']   = (int) $model['id'];
					$row['model_name'] = (string) $model['model'];
					$row['max_lines']  = (int) $model['max_lines'];
					$row['product_id'] = (int) $model['product_id'];
					if (!(int) $model['enabled'])
					{
						$row['notes'][] = sprintf(_("model %s is disabled in the Package Manager; it will be enabled by the import"), $model['model']);
					}
					$row['templates'] = $this->templates_for_product($row['product_id']);
					$row['template_id'] = $this->guess_template($row['templates'], $row['template_src']);
				}
			}

			// lines
			$seen = array();
			foreach ((array) ($p['lines'] ?? array()) as $l)
			{
				$line = (int) ($l['line'] ?? 0);
				$ext  = trim((string) ($l['ext'] ?? ''));
				$lr   = array('line' => $line, 'ext' => $ext, 'description' => (string) ($l['description'] ?? ''), 'status' => 'create', 'reason' => '');
				if ($ext === '')
				{
					$lr['status'] = 'skip'; $lr['reason'] = _("no extension");
				}
				elseif ($line < 1)
				{
					$lr['status'] = 'skip'; $lr['reason'] = _("line number missing");
				}
				elseif ($row['max_lines'] > 0 && $line > $row['max_lines'])
				{
					$lr['status'] = 'skip'; $lr['reason'] = sprintf(_("line %d is beyond this model's %d lines"), $line, $row['max_lines']);
				}
				elseif (isset($seen[$line]))
				{
					$lr['status'] = 'skip'; $lr['reason'] = sprintf(_("line %d already used by extension %s in this export"), $line, $seen[$line]);
				}
				elseif (!isset($exts[$ext]))
				{
					$lr['status'] = 'skip'; $lr['reason'] = sprintf(_("extension %s does not exist on this system"), $ext);
				}
				elseif (isset($mapped[$ext]) && $mapped[$ext] !== $mac && !$show_all)
				{
					$lr['status'] = 'skip'; $lr['reason'] = sprintf(_("extension %s is already mapped to phone %s"), $ext, $mapped[$ext]);
				}
				else
				{
					$seen[$line] = $ext;
					$lr['description'] = $exts[$ext] !== '' ? $exts[$ext] : $lr['description'];
				}
				$row['lines'][] = $lr;
			}
			$usable = 0;
			foreach ($row['lines'] as $lr) { if ($lr['status'] === 'create') $usable++; }
			if ($usable === 0 && empty($row['reasons']))
			{
				$row['reasons'][] = _("no usable line (every extension is missing here, already mapped, or beyond the model)");
			}
			if (!empty($row['reasons']))
			{
				$row['status'] = 'skip';
				$stats['skip']++;
			}
			else
			{
				$stats['create']++;
				$stats['lines'] += $usable;
				$stats['lines_skipped'] += count($row['lines']) - $usable;
			}
			$out[] = $row;
		}

		$preview = array(
			'source'   => (array) ($doc['source'] ?? array()),
			'created'  => (string) ($doc['created'] ?? ''),
			'phones'   => $out,
			'stats'    => $stats,
			'show_all' => $show_all,
			'time'     => time(),
		);
		$_SESSION[self::SESSION_KEY] = $preview;
		return $preview;
	}

	public function current_preview()
	{
		$p = $_SESSION[self::SESSION_KEY] ?? null;
		return is_array($p) ? $p : null;
	}

	public function discard()
	{
		unset($_SESSION[self::SESSION_KEY]);
	}

	/*********************************************************************
	 * Commit: create what the preview said, with the operator's choices
	 *********************************************************************/

	/**
	 * @param array $include  phone idx => 1 for the phones ticked in the preview
	 * @param array $template phone idx => template id chosen in the preview
	 * @param bool  $rebuild  write the configuration files right away
	 * @return array ['created' => n, 'lines' => n, 'skipped' => n, 'failed' => n]
	 */
	public function commit(array $include, array $template, $rebuild = false)
	{
		$preview = $this->current_preview();
		$result  = array('created' => 0, 'lines' => 0, 'skipped' => 0, 'failed' => 0);
		if (!$preview)
		{
			$this->epm->error['import'] = _("There is no import preview to confirm; upload the file again.");
			return $result;
		}
		$this->discard();

		foreach ($preview['phones'] as $row)
		{
			$idx = $row['idx'];
			if ($row['status'] !== 'create' || empty($include[$idx]))
			{
				$result['skipped']++;
				continue;
			}
			$tpl = isset($template[$idx]) ? (int) $template[$idx] : (int) $row['template_id'];
			if ($tpl !== 0 && !isset($row['templates'][$tpl]))
			{
				$tpl = 0;
			}

			// the Add form refuses disabled models; the operator chose to import this one
			$this->enable_model((int) $row['model_id']);

			$first  = null;
			$others = array();
			foreach ($row['lines'] as $lr)
			{
				if ($lr['status'] !== 'create') { continue; }
				if ($first === null) { $first = $lr; } else { $others[] = $lr; }
			}
			if ($first === null)
			{
				$result['skipped']++;
				continue;
			}
			$mac_id = $this->epm->add_device($row['mac'], $row['model_id'], $first['ext'], $tpl, $first['line']);
			if (!$mac_id)
			{
				$result['failed']++;
				$this->epm->error['import_' . $row['mac']] = sprintf(_("%s: %s"), $row['mac'], implode(' ', (array) ($this->epm->error['add_device'] ?? array(_("add_device failed")))));
				unset($this->epm->error['add_device']);
				continue;
			}
			unset($this->epm->message['add_device']);
			$result['created']++;
			$result['lines']++;
			foreach ($others as $lr)
			{
				if ($this->epm->add_line($mac_id, $lr['line'], $lr['ext']))
				{
					$result['lines']++;
					unset($this->epm->message['add_line']);
				}
				else
				{
					$this->epm->error['import_' . $row['mac'] . '_' . $lr['line']] = sprintf(_("%s line %d (%s): %s"), $row['mac'], $lr['line'], $lr['ext'], implode(' ', (array) ($this->epm->error['add_line'] ?? array(_("add_line failed")))));
					unset($this->epm->error['add_line']);
				}
			}
			if ($rebuild)
			{
				$this->epm->rebuild_device($mac_id, false);
			}
		}
		$this->epm->message['import'] = sprintf(
			_("Import finished: %d phone(s) created with %d line(s), %d skipped, %d failed."),
			$result['created'], $result['lines'], $result['skipped'], $result['failed']
		);
		return $result;
	}

	/*********************************************************************
	 * Lookups
	 *********************************************************************/

	private function brands_installed()
	{
		$out = array();
		$sql = "SELECT id, name, directory FROM endpointman_brand_list WHERE installed = 1 AND hidden = 0";
		foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $r)
		{
			$out[strtolower((string) $r['directory'])] = $r;
			// the commercial module names brands by display name too ("Cisco", "Sangoma")
			$out[strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $r['name']))] = $r;
		}
		return $out;
	}

	private function models_by_brand()
	{
		$out = array();
		$sql = "SELECT id, brand, model, max_lines, product_id, enabled, hidden FROM endpointman_model_list WHERE hidden = 0";
		foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $r)
		{
			$out[(int) $r['brand']][$this->norm_model((string) $r['model'])] = $r;
		}
		return $out;
	}

	/**
	 * Match a model name from another system: exact, then with vendor prefixes
	 * dropped (CP7960 -> 7960, SPA504G stays), then by digits alone.
	 */
	private function match_model(array $models, $name)
	{
		$n = $this->norm_model($name);
		if ($n === '') { return null; }
		if (isset($models[$n])) { return $models[$n]; }
		foreach (array('cp', 'cisco', 'sangoma', 'ip') as $prefix)
		{
			if (strpos($n, $prefix) === 0 && isset($models[substr($n, strlen($prefix))]))
			{
				return $models[substr($n, strlen($prefix))];
			}
		}
		$digits = preg_replace('/[^0-9]/', '', $n);
		if ($digits !== '')
		{
			foreach ($models as $key => $row)
			{
				if (preg_replace('/[^0-9]/', '', $key) === $digits)
				{
					return $row;
				}
			}
		}
		return null;
	}

	private function norm_model($name)
	{
		return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $name));
	}

	private function extensions_here()
	{
		$out = array();
		foreach ((array) $this->eda->sql("SELECT id, description FROM devices", 'getAll', \PDO::FETCH_ASSOC) as $r)
		{
			$out[(string) $r['id']] = (string) $r['description'];
		}
		return $out;
	}

	private function extensions_mapped()
	{
		$out = array();
		$sql = "SELECT l.ext, m.mac FROM endpointman_line_list l, endpointman_mac_list m WHERE l.mac_id = m.id";
		foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $r)
		{
			$out[(string) $r['ext']] = strtoupper((string) $r['mac']);
		}
		return $out;
	}

	private function templates_for_product($product_id)
	{
		$out = array();
		$sql = sprintf("SELECT id, name FROM endpointman_template_list WHERE product_id = %d ORDER BY name", (int) $product_id);
		foreach ((array) $this->eda->sql($sql, 'getAll', \PDO::FETCH_ASSOC) as $r)
		{
			$out[(int) $r['id']] = (string) $r['name'];
		}
		return $out;
	}

	private function guess_template(array $templates, $name)
	{
		$want = strtolower(trim((string) $name));
		foreach ($templates as $id => $tname)
		{
			if (strtolower($tname) === $want) { return (int) $id; }
		}
		return 0; // "Custom": the product's defaults, same as the Add form with no template
	}

	private function enable_model($model_id)
	{
		$sql = sprintf("UPDATE endpointman_model_list SET enabled = 1 WHERE id = %d AND enabled = 0", (int) $model_id);
		$this->eda->sql($sql);
	}
}
