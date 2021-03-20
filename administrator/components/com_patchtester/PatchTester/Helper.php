<?php
/**
 * Patch testing component for the Joomla! CMS
 *
 * @copyright  Copyright (C) 2011 - 2012 Ian MacLennan, Copyright (C) 2013 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace PatchTester;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use PatchTester\GitHub\GitHub;

/**
 * Helper class for the patch tester component
 *
 * @since  2.0
 */
abstract class Helper
{
	/**
	 * Initializes the GitHub object
	 *
	 * @return  GitHub
	 *
	 * @since   2.0
	 */
	public static function initializeGithub()
	{
		$params = ComponentHelper::getParams('com_patchtester');

		$options = new Registry;

		// Set a user agent for the request
		$options->set('userAgent', 'PatchTester/3.0');

		// Set the default timeout to 120 seconds
		$options->set('timeout', 120);

		// Set the API URL
		$options->set('api.url', 'https://api.github.com');

		// If an API token is set in the params, use it for authentication
		if ($params->get('gh_token', ''))
		{
			$options->set('gh.token', $params->get('gh_token', ''));
		}
		// Display a message about the lowered API limit without credentials
		else
		{
			Factory::getApplication()->enqueueMessage(Text::_('COM_PATCHTESTER_NO_CREDENTIALS'), 'notice');
		}

		return new GitHub($options);
	}
}
