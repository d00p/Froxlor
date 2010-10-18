<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Michael Kaufmann <mkaufmann@nutime.de>
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Multiserver
 * @version    $Id$
 * @link       http://www.nutime.de/
 * @since      0.9.14-svn8
 *
 * Multiserver - FroxlorClient-Class
 */

class froxlorclient
{
	/**
	 * Userinfo
	 * @var array
	 */
	private $userinfo = array();

	/**
	 * Database handler
	 * @var db
	 */
	private $db = false;

	/**
	 * Settings array
	 * @var settings
	 */
	private $settings = array();

	/**
	 * Client ID
	 * @var cid
	 */
	private $cid = -1;

	/**
	 * Client Data Array
	 * @var c_data
	 */
	private $c_data = array();

	/**
	 * Client-Object-Array
	 * @var clients
	 */
	static private $clients = array();

	/**
	 * Class constructor.
	 *
	 * @param array    $userinfo userdetails array of logged in user
	 * @param resource $db       database-object
	 * @param array    $settings settings-array
	 * @param int      $cid      client-id
	 */
	private function __construct($userinfo, $db, $settings, $cid = -1)
	{
		$this->userinfo = $userinfo;
		$this->db = $db;
		$this->settings = $settings;
		$this->cid = $cid;

		// read data from database
		$this->_readData();
	}

	/**
	 * static function to initialize the class using
	 * singleton design pattern
	 * 
	 * @param array    $_usernfo  userdetails array of logged in user
	 * @param resource $_db       database-object
	 * @param array    $_settings settings-array
	 * @param int      $_cid      client-id
	 */
	static public function getInstance($_usernfo, $_db, $_settings, $_cid)
	{
		if(!isset(self::$clients[$_cid]))
		{
			self::$clients[$_cid] = new froxlorclient($_usernfo, $_db, $_settings, $_cid);
		}

		return self::$clients[$_cid];
	}

	/**
	 * This functions deploys the needed files
	 * to the client destination server
	 * 
	 * @TODO
	 * - get information about what files need to be transfered
	 * - generate userdata.inc.php for client (db = master db)
	 */
	public function Deploy()
	{
		// get FroxlorSshTransport-object
		if($this->_getSetting('client', 'deploy_mode') !== null
			&& $this->_getSetting('client', 'deploy_mode') == 'pubkey'
		) {
			$ssh = FroxlorSshTransport::usePublicKey(
				$this->_getSetting('client', 'hostname'), 
				$this->_getSetting('client', 'ssh_port'), 
				$this->_getSetting('client', 'ssh_user'), 
				$this->_getSetting('client', 'ssh_pubkey'), 
				$this->_getSetting('client', 'ssh_privkey'), 
				$this->_getSetting('client', 'ssh_passphrase')
			);
		} else if($this->_getSetting('client', 'deploy_mode') !== null) {
			$ssh = FroxlorSshTransport::usePlainPassword(
				$this->_getSetting('client', 'hostname'), 
				$this->_getSetting('client', 'ssh_port'), 
				$this->_getSetting('client', 'ssh_user'), 
				$this->_getSetting('client', 'ssh_passphrase')
			);
		} else {
			throw new Exception('NO_DEPLOY_METHOD_GIVEN');
		}
	}

	/**
	 * Update data in database
	 */
	public function Update()
	{
		$this->db->query("UPDATE 
			`" . TABLE_FROXLOR_CLIENTS . "` 
		SET
			`name` = '" . $this->db->escape($this->Get('name')) . "',  
			`ip` = '" . $this->db->escape($this->Get('ip')) . "', 
			`enabled` = '" . (int)$this->Get('enabled') . "'
		WHERE 
			`id` = '" . (int)$this->cid . "';
		");
		return true;
	}

	/**
	 * This function removes a Froxlor-Client and its settings
	 * from the database. Optionally the Froxlor-Client data
	 * can be removed by setting the $delete_me parameter
	 * 
	 * @param bool $delete_me removes client-data (not customer data) on the client
	 * 
	 * @return bool
	 * 
	 * @TODO 
	 * - remove client settings in panel_settings (sid = client-id)
	 * - implement $delete_me parameter
	 */
	public function Delete($delete_me = false)
	{
		// delete froxlor-client from the database
		$this->db->query('DELETE FROM 
			`' . TABLE_FROXLOR_CLIENTS . '` 
		WHERE 
			`id` = "' . (int)$this->cid . '";
		');

		// Delete settings from panel_settings
		$this->db->query('DELETE FROM 
			`' . TABLE_PANEL_SETTINGS . '` 
		WHERE 
			`sid` = "' . (int)$this->cid . '";
		');

		return true;
	}

	/**
	 * get a value from the internal data array
	 * 
	 * @param string $_var
	 * @param string $_vartrusted
	 * 
	 * @return mixed or null if not found
	 */
	public function Get($_var = '', $_vartrusted = false)
	{
		if($_var != '')
		{
			if(!$_vartrusted)
			{
				$_var = htmlspecialchars($_var);
			}

			if(isset($this->c_data[$_var]))
			{
				return $this->c_data[$_var];
			}
			else
			{
				return null;
			}
		}
	}

	/**
	 * set a value in the internal data array
	 * 
	 * @param string $_var
	 * @param string $_value
	 * @param bool   $_vartrusted
	 * @param bool   $_valuetrusted
	 */
	public function Set($_var = '', $_value = '', $_vartrusted = false, $_valuetrusted = false)
	{
		if($_var != ''
			&& $_value != ''
		) {
			if(!$_vartrusted)
			{
				$_var = htmlspecialchars($_var);
			}

			if(!$_valuetrusted)
			{
				$_value = htmlspecialchars($_value);
			}

			$this->c_data[$_var] = $_value;
		}
	}

	/**
	 * set a value in the internal settings array
	 * 
	 * @param string $_var
	 * @param string $_value
	 * @param bool   $_vartrusted
	 * @param bool   $_valuetrusted
	 */
	private function _getSetting($_var = '', $_vartrusted = false)
	{
		if($_var != '')
		{
			if(!$_vartrusted)
			{
				$_var = htmlspecialchars($_var);
			}

			if(isset($this->c_data['settings'][$_var]))
			{
				return $this->c_data['settings'][$_var];
			}
			else
			{
				return null;
			}
		}
	}

	/**
	 * set a value in the internal settings array
	 * 
	 * @param string $_var
	 * @param string $_value
	 * @param bool   $_vartrusted
	 * @param bool   $_valuetrusted
	 */
	private function _setSetting($_var = '', $_value = '', $_vartrusted = false, $_valuetrusted = false)
	{
		if($_var != ''
			&& $_value != ''
		) {
			if(!$_vartrusted)
			{
				$_var = htmlspecialchars($_var);
			}

			if(!$_valuetrusted)
			{
				$_value = htmlspecialchars($_value);
			}

			if(!is_array($this->c_data['settings'])) {
				$this->c_data['settings'] = array();
			}

			$this->c_data['settings'][$_var] = $_value;
		}
	}

	/**
	 * read client settings from database
	 */
	private function _readSettings()
	{
		if(isset($this->cid)
			&& $this->cid != - 1
		) {
			$_settings = $this->db->query_first("SELECT * FROM `".TABLE_PANEL_SETTINGS."` WHERE `sid` = '".(int)$this->cid."'");

			foreach($_settings as $field => $value)
			{
				$this->_setSetting($field, $value, true, true);
			}
		}
	}

	/**
	 * Read client data from database.
	 */
	private function _readData()
	{
		if(isset($this->cid)
			&& $this->cid != - 1
		) {
			$_client = $this->db->query_first('SELECT * FROM `' . TABLE_FROXLOR_CLIENTS . '` WHERE `id` = "' . $this->cid . '"');

			foreach($_client as $field => $value)
			{
				$this->Set($field, $value, true, true);
			}
		}
	}
}
