<?php
/**
 * Patch testing component for the Joomla! CMS
 *
 * @copyright  Copyright (C) 2011 - 2012 Ian MacLennan, Copyright (C) 2013 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace PatchTester\Model;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Pagination\Pagination;
use Joomla\Registry\Registry;
use PatchTester\GitHub\Exception\UnexpectedResponse;
use PatchTester\Helper;

/**
 * Model class for the pulls list view
 *
 * @since  2.0
 */
class PullsModel extends AbstractModel
{
	/**
	 * The object context
	 *
	 * @var    string
	 * @since  2.0
	 */
	protected $context;

	/**
	 * Array of fields the list can be sorted on
	 *
	 * @var    array
	 * @since  2.0
	 */
	protected $sortFields = array('pulls.pull_id', 'pulls.title');

	/**
	 * Instantiate the model.
	 *
	 * @param   string            $context  The model context.
	 * @param   Registry          $state    The model state.
	 * @param   \JDatabaseDriver  $db       The database adpater.
	 *
	 * @since   2.0
	 */
	public function __construct($context, Registry $state = null,
		\JDatabaseDriver $db = null
	) {
		parent::__construct($state, $db);

		$this->context = $context;
	}

	/**
	 * Method to get an array of branches.
	 *
	 * @return  array
	 *
	 * @since   3.0.0
	 */
	public function getBranches(): array
	{
		$db    = $this->getDb();
		$query = $db->getQuery(true);

		// Select distinct branches excluding empty values
		$query->select('DISTINCT(branch) AS text')
			->from('#__patchtester_pulls')
			->where($db->quoteName('branch') . ' != ' . $db->quote(''))
			->order('branch ASC');

		return $db->setQuery($query)->loadAssocList();
	}

	/**
	 * Method to get an array of labels.
	 *
	 * @return  array The list of labels
	 *
	 * @since   4.0.0
	 */
	public function getLabels(): array
	{
		$db    = $this->getDb();
		$query = $db->getQuery(true);

		// Select distinct branches excluding empty values
		$query->select(
			'DISTINCT(' . $db->quoteName('name') . ') AS ' . $db->quoteName(
				'text'
			)
		)
			->from($db->quoteName('#__patchtester_pulls_labels'))
			->order($db->quoteName('name') . ' ASC');

		return $db->setQuery($query)->loadAssocList();
	}

	/**
	 * Method to get an array of data items.
	 *
	 * @return  mixed  An array of data items on success, false on failure.
	 *
	 * @since   2.0
	 */
	public function getItems()
	{
		$store = $this->getStoreId();

		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		$items = $this->getList(
			$this->getListQueryCache(), $this->getStart(),
			$this->getState()->get('list.limit')
		);

		$db    = $this->getDb();
		$query = $db->getQuery(true)
			->select($db->quoteName(['name', 'color']))
			->from($db->quoteName('#__patchtester_pulls_labels'));

		array_walk(
			$items,
			static function ($item) use ($db, $query) {
				$query->clear('where');
				$query->where(
					$db->quoteName('pull_id') . ' = ' . $item->pull_id
				);
				$db->setQuery($query);

				$item->labels = $db->loadObjectList();
			}
		);

		$this->cache[$store] = $items;

		return $this->cache[$store];
	}

	/**
	 * Method to get a store id based on the model configuration state.
	 *
	 * This is necessary because the model is used by the component and
	 * different modules that might need different sets of data or different
	 * ordering requirements.
	 *
	 * @param   string  $id  An identifier string to generate the store id.
	 *
	 * @return  string  A store id.
	 *
	 * @since   2.0
	 */
	protected function getStoreId($id = '')
	{
		// Add the list state to the store id.
		$id .= ':' . $this->getState()->get('list.start');
		$id .= ':' . $this->getState()->get('list.limit');
		$id .= ':' . $this->getState()->get('list.ordering');
		$id .= ':' . $this->getState()->get('list.direction');

		return md5($this->context . ':' . $id);
	}

	/**
	 * Gets an array of objects from the results of database query.
	 *
	 * @param   \JDatabaseQuery|string  $query       The query.
	 * @param   integer                 $limitstart  Offset.
	 * @param   integer                 $limit       The number of records.
	 *
	 * @return  array  An array of results.
	 *
	 * @since   2.0
	 * @throws  RuntimeException
	 */
	protected function getList($query, $limitstart = 0, $limit = 0)
	{
		return $this->getDb()->setQuery($query, $limitstart, $limit)
			->loadObjectList();
	}

	/**
	 * Method to cache the last query constructed.
	 *
	 * This method ensures that the query is constructed only once for a given state of the model.
	 *
	 * @return  \JDatabaseQuery  A JDatabaseQuery object
	 *
	 * @since   2.0
	 */
	protected function getListQueryCache()
	{
		// Capture the last store id used.
		static $lastStoreId;

		// Compute the current store id.
		$currentStoreId = $this->getStoreId();

		// If the last store id is different from the current, refresh the query.
		if ($lastStoreId != $currentStoreId || empty($this->query))
		{
			$lastStoreId = $currentStoreId;
			$this->query = $this->getListQuery();
		}

		return $this->query;
	}

	/**
	 * Method to get a JDatabaseQuery object for retrieving the data set from a database.
	 *
	 * @return  \JDatabaseQuery  A JDatabaseQuery object to retrieve the data set.
	 *
	 * @since   2.0
	 */
	protected function getListQuery()
	{
		// Create a new query object.
		$db         = $this->getDb();
		$query      = $db->getQuery(true);
		$labelQuery = $db->getQuery(true);

		$query->select('pulls.*')
			->select($db->quoteName('tests.id', 'applied'))
			->from($db->quoteName('#__patchtester_pulls', 'pulls'))
			->leftJoin(
				$db->quoteName('#__patchtester_tests', 'tests')
				. ' ON ' . $db->quoteName('tests.pull_id') . ' = '
				. $db->quoteName('pulls.pull_id')
			);

		// Filter by search
		$search = $this->getState()->get('filter.search');

		if (!empty($search))
		{
			if (stripos($search, 'id:') === 0)
			{
				$query->where(
					$db->quoteName('pulls.pull_id') . ' = ' . (int) substr(
						$search, 3
					)
				);
			}
			elseif (is_numeric($search))
			{
				$query->where(
					$db->quoteName('pulls.pull_id') . ' = ' . (int) $search
				);
			}
			else
			{
				$query->where(
					'(' . $db->quoteName('pulls.title') . ' LIKE ' . $db->quote(
						'%' . $db->escape($search, true) . '%'
					) . ')'
				);
			}
		}

		// Filter for applied patches
		$applied = $this->getState()->get('filter.applied');

		if (!empty($applied))
		{
			// Not applied patches have a NULL value, so build our value part of the query based on this
			$value = $applied === 'no' ? ' IS NULL' : ' = 1';

			$query->where($db->quoteName('applied') . $value);
		}

		// Filter for branch
		$branch = $this->getState()->get('filter.branch');

		if (!empty($branch))
		{
			$query->where(
				$db->quoteName('pulls.branch') . ' = ' . $db->quote($branch)
			);
		}

		// Filter for RTC patches
		$applied = $this->getState()->get('filter.rtc');

		if (!empty($applied))
		{
			// Not applied patches have a NULL value, so build our value part of the query based on this
			$value = $applied === 'no' ? '0' : '1';

			$query->where($db->quoteName('pulls.is_rtc') . ' = ' . $value);
		}

		// Filter for NPM patches
		$npm = $this->getState()->get('filter.npm');

		if (!empty($npm))
		{
			// Not applied patches have a NULL value, so build our value part of the query based on this
			$value = $npm === 'no' ? '0' : '1';

			$query->where($db->quoteName('pulls.is_npm') . ' = ' . $value);
		}

		$labels = $this->getState()->get('filter.label');

		if (!empty($labels) && $labels[0] !== '')
		{
			$labelQuery
				->select($db->quoteName('pulls_labels.pull_id'))
				->select(
					'COUNT(' . $db->quoteName('pulls_labels.name') . ') AS '
					. $db->quoteName('labelCount')
				)
				->from(
					$db->quoteName(
						'#__patchtester_pulls_labels', 'pulls_labels'
					)
				)
				->where(
					$db->quoteName('pulls_labels.name') . ' IN (' . implode(
						',', $db->quote($labels)
					) . ')'
				)
				->group($db->quoteName('pulls_labels.pull_id'));

			$query->leftJoin(
				'(' . $labelQuery->__toString() . ') AS ' . $db->quoteName(
					'pulls_labels'
				)
				. ' ON ' . $db->quoteName('pulls_labels.pull_id') . ' = '
				. $db->quoteName('pulls.pull_id')
			)
				->where(
					$db->quoteName('pulls_labels.labelCount') . ' = ' . count(
						$labels
					)
				);
		}

		$ordering  = $this->getState()->get('list.ordering', 'pulls.pull_id');
		$direction = $this->getState()->get('list.direction', 'DESC');

		if (!empty($ordering))
		{
			$query->order(
				$db->escape($ordering) . ' ' . $db->escape($direction)
			);
		}

		return $query;
	}

	/**
	 * Method to get the starting number of items for the data set.
	 *
	 * @return  integer  The starting number of items available in the data set.
	 *
	 * @since   2.0
	 */
	public function getStart()
	{
		$store = $this->getStoreId('getStart');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		$start = $this->getState()->get('list.start', 0);
		$limit = $this->getState()->get('list.limit', 20);
		$total = $this->getTotal();

		if ($start > $total - $limit)
		{
			$start = max(0, (int) (ceil($total / $limit) - 1) * $limit);
		}

		// Add the total to the internal cache.
		$this->cache[$store] = $start;

		return $this->cache[$store];
	}

	/**
	 * Method to get the total number of items for the data set.
	 *
	 * @return  integer  The total number of items available in the data set.
	 *
	 * @since   2.0
	 */
	public function getTotal()
	{
		// Get a storage key.
		$store = $this->getStoreId('getTotal');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		// Load the total and add the total to the internal cache.
		$this->cache[$store] = (int) $this->getListCount(
			$this->getListQueryCache()
		);

		return $this->cache[$store];
	}

	/**
	 * Returns a record count for the query.
	 *
	 * @param   \JDatabaseQuery|string  $query  The query.
	 *
	 * @return  integer  Number of rows for query.
	 *
	 * @since   2.0
	 */
	protected function getListCount($query)
	{
		// Use fast COUNT(*) on JDatabaseQuery objects if there no GROUP BY or HAVING clause:
		if ($query instanceof \JDatabaseQuery && $query->type == 'select'
			&& $query->group === null
			&& $query->having === null)
		{
			$query = clone $query;
			$query->clear('select')->clear('order')->select('COUNT(*)');

			$this->getDb()->setQuery($query);

			return (int) $this->getDb()->loadResult();
		}

		// Otherwise fall back to inefficient way of counting all results.
		$this->getDb()->setQuery($query)->execute();

		return (int) $this->getDb()->getNumRows();
	}

	/**
	 * Method to get a Pagination object for the data set.
	 *
	 * @return  Pagination  A Pagination object for the data set.
	 *
	 * @since   2.0
	 */
	public function getPagination()
	{
		// Get a storage key.
		$store = $this->getStoreId('getPagination');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		// Create the pagination object and add the object to the internal cache.
		$this->cache[$store] = new Pagination(
			$this->getTotal(), $this->getStart(),
			(int) $this->getState()->get('list.limit', 20)
		);

		return $this->cache[$store];
	}

	/**
	 * Retrieves the array of authorized sort fields
	 *
	 * @return  array
	 *
	 * @since   2.0
	 */
	public function getSortFields()
	{
		return $this->sortFields;
	}

	/**
	 * Method to request new data from GitHub
	 *
	 * @param   integer  $page  The page of the request
	 *
	 * @return  array
	 *
	 * @since   2.0
	 * @throws  \RuntimeException
	 */
	public function requestFromGithub($page)
	{
		// If on page 1, dump the old data
		if ($page === 1)
		{
			$this->getDb()->truncateTable('#__patchtester_pulls');
			$this->getDb()->truncateTable('#__patchtester_pulls_labels');
		}

		try
		{
			// TODO - Option to configure the batch size
			$batchSize = 100;

			$pullsResponse = Helper::initializeGithub()->getOpenPulls(
				$this->getState()->get('github_user'),
				$this->getState()->get('github_repo'),
				$page,
				$batchSize
			);

			$pulls = json_decode($pullsResponse->body);
		}
		catch (UnexpectedResponse $exception)
		{
			throw new \RuntimeException(
				Text::sprintf(
					'COM_PATCHTESTER_ERROR_GITHUB_FETCH',
					$exception->getMessage()
				),
				$exception->getCode(),
				$exception
			);
		}

		// If this is page 1, let's check to see if we need to paginate
		if ($page === 1)
		{
			// Default this to being a single page of results
			$lastPage = 1;

			if (isset($pullsResponse->headers['Link']))
			{
				$linkHeader = $pullsResponse->headers['Link'];

				// The `joomla/http` 2.0 package uses PSR-7 Responses which has a different format for headers, check for this
				if (is_array($linkHeader))
				{
					$linkHeader = $linkHeader[0];
				}

				preg_match(
					'/(\?page=[0-9]{1,3}&per_page=' . $batchSize
					. '+>; rel=\"last\")/', $linkHeader, $matches
				);

				if ($matches && isset($matches[0]))
				{
					$pageSegment = str_replace(
						'&per_page=' . $batchSize, '', $matches[0]
					);

					preg_match('/\d+/', $pageSegment, $pages);
					$lastPage = (int) $pages[0];
				}
			}
		}

		// If there are no pulls to insert then bail, assume we're finished
		if (count($pulls) === 0)
		{
			return ['complete' => true];
		}

		$data   = [];
		$labels = [];

		foreach ($pulls as $pull)
		{
			// Check if this PR is RTC and has a `PR-` branch label
			$isRTC  = false;
			$isNPM  = false;
			$branch = '';

			foreach ($pull->labels as $label)
			{
				if (strtolower($label->name) === 'rtc')
				{
					$isRTC = true;
				}
				elseif (strpos($label->name, 'PR-') === 0)
				{
					$branch = substr($label->name, 3);
				}
				elseif (in_array(
					strtolower($label->name),
					['npm resource changed', 'composer dependency changed'],
					true
				))
				{
					$isNPM = true;
				}

				$labels[] = implode(
					',',
					[
						(int) $pull->number,
						$this->getDb()->quote($label->name),
						$this->getDb()->quote($label->color),
					]
				);
			}

			// Build the data object to store in the database
			$pullData = [
				(int) $pull->number,
				$this->getDb()->quote(
					HTMLHelper::_('string.truncate', $pull->title, 150)
				),
				$this->getDb()->quote(
					HTMLHelper::_('string.truncate', $pull->body, 100)
				),
				$this->getDb()->quote($pull->html_url),
				(int) $isRTC,
				(int) $isNPM,
				$this->getDb()->quote($branch),
			];

			$data[] = implode(',', $pullData);
		}

		// If there are no pulls to insert then bail, assume we're finished
		if (count($data) === 0)
		{
			return array('complete' => true);
		}

		try
		{
			$this->getDb()->setQuery(
				$this->getDb()->getQuery(true)
					->insert('#__patchtester_pulls')
					->columns(
						['pull_id', 'title', 'description', 'pull_url',
						 'is_rtc', 'is_npm', 'branch']
					)
					->values($data)
			);

			$this->getDb()->execute();
		}
		catch (\RuntimeException $exception)
		{
			throw new \RuntimeException(
				Text::sprintf(
					'COM_PATCHTESTER_ERROR_INSERT_DATABASE',
					$exception->getMessage()
				),
				$exception->getCode(),
				$exception
			);
		}

		if ($labels)
		{
			try
			{
				$this->getDb()->setQuery(
					$this->getDb()->getQuery(true)
						->insert('#__patchtester_pulls_labels')
						->columns(['pull_id', 'name', 'color'])
						->values($labels)
				);
				$this->getDb()->execute();
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException(
					Text::sprintf(
						'COM_PATCHTESTER_ERROR_INSERT_DATABASE',
						$exception->getMessage()
					),
					$exception->getCode(),
					$exception
				);
			}
		}

		return [
			'complete' => false,
			'page'     => ($page + 1),
			'lastPage' => $lastPage ?? false,
		];
	}

	/**
	 * Truncates the pulls table
	 *
	 * @return  void
	 *
	 * @since   2.0
	 */
	public function truncateTable()
	{
		$this->getDb()->truncateTable('#__patchtester_pulls');
	}
}
