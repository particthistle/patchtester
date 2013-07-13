<?php
/**
 * @package        PatchTester
 * @copyright      Copyright (C) 2011 Ian MacLennan, Inc. All rights reserved.
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

jimport('joomla.application.component.modellist');

/**
 * Methods supporting a list of pull request.
 *
 * @package        PatchTester
 */
class PatchtesterModelPulls extends JModelList
{
	/**
	 * Github object
	 *
	 * @var  PTGithub
	 */
	protected $github;

	/**
	 * Object containing the rate limit data
	 *
	 * @var  object
	 */
	protected $rate;

	/**
	 * Constructor.
	 *
	 * @param   array  $config  An optional associative array of configuration settings.
	 *
	 * @see     JController
	 * @since   11.1
	 */
	public function __construct($config = array())
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array(
				'id', 'id',
				'title', 'title',
				'updated_at', 'updated_at',
				'user', 'user'
			);
		}

		parent::__construct($config);

		// Set up the Github object
		$params = JComponentHelper::getParams('com_patchtester');

		$options = new JRegistry;

		// Set the username and password if set in the params
		if ($params->get('gh_user', '') && $params->get('gh_password'))
		{
			$options->set('api.username', $params->get('gh_user', ''));
			$options->set('api.password', $params->get('gh_password', ''));
		}
		else
		{
			// Display a message about the lowered API limit without credentials
			JFactory::getApplication()->enqueueMessage(JText::_('COM_PATCHTESTER_NO_CREDENTIALS'), 'notice');
		}

		$this->github = new PTGithub($options);

		// Store the rate data for reuse during this request cycle
		$this->rate = $this->github->account->getRateLimit()->rate;

		// Check the API rate limit, display a message if over
		if ($this->rate->remaining == 0)
		{
			JFactory::getApplication()->enqueueMessage(JText::sprintf('COM_PATCHTESTER_API_LIMIT_LIST', JFactory::getDate($this->rate->reset)), 'notice');
		}
	}


	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @since    1.6
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		// Load the filter state.
		$search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
		$this->setState('filter.search', $search);

		$searchId = $this->getUserStateFromRequest($this->context . '.filter.searchid', 'filter_searchid');
		$this->setState('filter.searchid', $searchId);

        // Load the parameters.
        $params = JComponentHelper::getParams('com_patchtester');

        $this->setState('params', $params);
        $this->setState('github_user', $params->get('org', 'joomla'));
        $this->setState('github_repo', $params->get('repo', 'joomla-cms'));

        // List state information.
        parent::populateState('number', 'desc');

        // GitHub's default list limit is 30
        $this->setState('list.limit', 30);
	}

	public function getAppliedPatches()
	{
		$query = $this->_db->getQuery(true);
		$query->select('*');
		$query->from('#__patchtester_tests');
		$query->where('applied = 1');

		$this->_db->setQuery($query);
		$tests = $this->_db->loadObjectList('pull_id');
		return $tests;
	}

	public function getItems()
	{
		if ($this->getState('github_user') == '' || $this->getState('github_repo') == '')
		{
			return array();
		}

		$this->ordering = $this->getState('list.ordering', 'title');
		$this->orderDir = $this->getState('list.direction', 'asc');
		$search = $this->getState('filter.search');
		$searchId = $this->getState('filter.searchid');

        $page = $this->getPagination()->pagesCurrent;

		try
		{
			// If over the API limit, we can't build this list
			// TODO - Cache the request data in case of API limiting
			if ($this->rate->remaining > 0)
			{
				$pulls = $this->github->pulls->getList($this->getState('github_user'), $this->getState('github_repo'), 'open', $page);
				usort($pulls, array($this, 'sortItems'));

				foreach ($pulls as $i => &$pull)
				{
					if ($search && false === strpos($pull->title, $search))
					{
						unset($pulls[$i]);
						continue;
					}

					if ($searchId && $pull->number != $searchId)
					{
						unset($pulls[$i]);
						continue;
					}

					// Try to find a joomlacode issue number
					$pulls[$i]->joomlacode_issue = 0;

					$matches = array();

					preg_match('#\[\#([0-9]+)\]#', $pull->title, $matches);

					if (isset($matches[1]))
					{
						$pulls[$i]->joomlacode_issue = (int) $matches[1];
					}
					else
					{
						preg_match('#(http://joomlacode[-\w\./\?\S]+)#', $pull->body, $matches);

						if (isset($matches[1]))
						{
							preg_match('#tracker_item_id=([0-9]+)#', $matches[1], $matches);

							if (isset($matches[1]))
							{
								$pulls[$i]->joomlacode_issue = (int) $matches[1];
							}
						}
					}
				}
			}
			else
			{
				$pulls = array();
			}

			return $pulls;
		}
		catch (Exception $e)
		{
			JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');

			return array();
		}
	}

	public function sortItems($a, $b)
	{
		switch ($this->ordering)
		{
			case 'title' :
				return ($this->orderDir == 'asc') ? strcasecmp($a->title, $b->title) : strcasecmp($b->title, $a->title);

			case 'number' :
			default :
				return ($this->orderDir == 'asc') ? $b->number < $a->number : $b->number > $a->number;
		}
	}

    public function getTotal()
    {
	    if ($this->rate->remaining > 0)
	    {
	        return $this->github->repos->get('joomla', 'joomla-cms')->open_issues_count;
	    }
	    else
	    {
		    return 0;
	    }
    }
}
