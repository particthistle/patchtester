<?php
/**
 * Patch testing component for the Joomla! CMS
 *
 * @copyright  Copyright (C) 2011 - 2012 Ian MacLennan, Copyright (C) 2013 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

use Joomla\CMS\Installer\Adapter\ComponentAdapter;
use Joomla\CMS\Installer\InstallerScript;

/**
 * Installation class to perform additional changes during install/uninstall/update
 *
 * @since  2.0
 */
class Com_PatchtesterInstallerScript extends InstallerScript
{
	/**
	 * Extension script constructor.
	 *
	 * @since   3.0.0
	 */
	public function __construct()
	{
		$this->minimumJoomla = '3.9';
		$this->minimumPhp    = JOOMLA_MINIMUM_PHP;

		$this->deleteFiles = array(
			'/administrator/components/com_patchtester/PatchTester/View/Pulls/tmpl/default_errors.php',
			'/administrator/templates/hathor/html/com_patchtester/pulls/default.php',
			'/administrator/templates/hathor/html/com_patchtester/pulls/default_items.php',
		);

		$this->deleteFolders = array(
			'/administrator/components/com_patchtester/PatchTester/Table',
			'/administrator/templates/hathor/html/com_patchtester/pulls',
			'/administrator/templates/hathor/html/com_patchtester',
			'/components/com_patchtester',
		);
	}

	/**
	 * Function to perform changes during postflight
	 *
	 * @param   string            $type    The action being performed
	 * @param   ComponentAdapter  $parent  The class calling this method
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function postflight($type, $parent)
	{
		$this->removeFiles();
	}
}
