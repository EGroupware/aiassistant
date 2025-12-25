<?php
/**
 * EGroupware AI Assistant - Storage Object
 *
 * @link http://www.egroupware.org
 * @package aiassistant
 * @copyright (c) 2025 EGroupware Team
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AIAssistant;

use EGroupware\Api;

/**
 * Storage object for AI Assistant database operations
 */
class So
{
	/**
	 * Database instance
	 * @var Api\Db
	 */
	private $db;

	const APP = 'aiassistant';
	const HISTORY_TABLE = 'egw_ai_assistant_history';
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->db = $GLOBALS['egw']->db;
	}
	
	/**
	 * Save a chat message to database
	 */
	public function save_message($account_id, $type, $content, $tool_calls = null, $conversation_title = null, $tokens_used = 0, $response_time = 0)
	{
		$data = [
			'account_id' => $account_id,
			'session_id' => sha1(session_id()),
			'message_type' => $type,
			'message_content' => $content,
			'tool_calls' => $tool_calls ? json_encode($tool_calls) : null,
			'conversation_title' => $conversation_title,
			'tokens_used' => (int)$tokens_used,
			'response_time' => (int)$response_time,
			'created' => time()
		];
		
		return $this->db->insert(self::HISTORY_TABLE, $data, false, __LINE__, __FILE__, self::APP);
	}
	
	/**
	 * Get chat history for a user
	 */
	public function get_user_history(int $account_id, int $limit = 50)
	{
		$history = [];
		foreach($this->db->select(
			self::HISTORY_TABLE,
			'*',
			['account_id' => $account_id],
			__LINE__,
			__FILE__,
			false,
			'ORDER BY created ASC',
			self::APP,
			$limit
		) as $row)
		{
			$history[] = [
				'history_id' => $row['history_id'],
				'message_type' => $row['message_type'],
				'message_content' => $row['message_content'],
				'tool_calls' => $row['tool_calls'] ? json_decode($row['tool_calls'], true) : null,
				'created' => $row['created'],
				'created_formatted' => Api\DateTime::to($row['created'])
			];
		}
		
		return $history; // Return chronological order
	}
	
	/**
	 * Clear chat history for a user
	 */
	public function clear_user_history($account_id)
	{
		return $this->db->delete(self::HISTORY_TABLE, ['account_id' => $account_id], __LINE__, __FILE__, self::APP);
	}
	
	/**
	 * Get configuration value
	 */
	public function get_config($name, $default = null)
	{
		// Use standard EGroupware config system
		$config = Api\Config::read(self::APP);
		return $config[$name] ?? $default;
	}
	
	/**
	 * Set configuration value
	 */
	public function save_config($name, $value)
	{
		// Use standard EGroupware config system
		Api\Config::save_value($name, $value, self::APP);
		
		return true;
	}
	
	/**
	 * Get user-specific configuration
	 */
	public function get_user_config($account_id, $name, $default = null)
	{
		// Use standard EGroupware preferences system for user-specific settings
		$prefs = new Api\Preferences($account_id);
		$prefs->read_repository();
		$user_prefs = $prefs->data[self::APP] ?? [];
		
		return $user_prefs[$name] ?? $this->get_config($name, $default);
	}
	
	/**
	 * Set user-specific configuration
	 */
	public function save_user_config($account_id, $name, $value)
	{
		// Use standard EGroupware preferences system for user-specific settings
		$prefs = new Api\Preferences($account_id);
		$prefs->read_repository();
		$prefs->add(self::APP, $name, $value, 'user');
		$prefs->save_repository(true);
		
		return true;
	}

	/**
	 * @var int total number of rows from last search query
	 */
	protected $total;
	protected $total_criteria;

	/**
	 * Search conversations with criteria
	 *
	 * @param array $criteria Search criteria
	 * @param int $start Start index
	 * @param int $limit Number of results
	 * @param string $order Order field
	 * @param string $sort Sort direction
	 * @return array
	 */
	public function search($criteria = array(), int $start = 0, int $limit = 25, $order = 'created', $sort = 'DESC')
	{
		$where = [];
		$join = '';
		
		// Handle search criteria
		if (!empty($criteria['search']))
		{
			$search = $this->db->quote('%' . $criteria['search'] . '%');
			$where[] = "(message_content LIKE $search OR conversation_title LIKE $search)";
		}
		
		if (!empty($criteria['account_id']))
		{
			$where['account_id'] = $criteria['account_id'];
		}
		
		if (!empty($criteria['message_type']))
		{
			$where['message_type'] = $criteria['message_type'];
		}
		
		if (!empty($criteria['created']))
		{
			if (is_array($criteria['created']))
			{
				$where[] = 'created BETWEEN ' . $this->db->quote($criteria['created'][0], 'datetime') .
					' AND ' . $this->db->quote($criteria['created'][1], 'datetime');
			}
			else
			{
				$where[] = 'created >= ' . $this->db->quote($criteria['created'], 'datetime');
			}
		}

		if (preg_match('/^[a-z_]+$/', $order) && in_array(strtoupper($sort), ['ASC', 'DESC']))
		{
			$order_clause = "ORDER BY $order $sort";
		}

		if ($this->db->Type == 'mysql' && (float)$this->db->ServerInfo['version'] >= 4.0)
		{
			$mysql_calc_rows = 'SQL_CALC_FOUND_ROWS ';
		}

		$results = [];
		foreach($this->db->select(self::HISTORY_TABLE, ($mysql_calc_rows??'').'*', $where, __LINE__, __FILE__,
			$limit > 0 ? $start : false, $order_clause ?? '', self::APP, $limit, $join) as $row)
		{
			$results[] = $this->format_row($row);
		}
		if (isset($mysql_calc_rows))
		{
			$this->total = $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
			$this->total_criteria = $criteria;
		}
		else
		{
			$this->total = $this->db->select(self::HISTORY_TABLE, 'COUNT(*)', $where, __LINE__, __FILE__,
				false, '', self::APP, 0, $join)->fetchColumn();
		}
		
		return $results;
	}

	/**
	 * Get total count of conversations
	 *
	 * @param array $criteria Search criteria
	 * @return int
	 */
	public function total($criteria = array())
	{
		if ($this->total === null || $this->total_criteria != $criteria)
		{
			$this->search($criteria);
		}
		return (int)$this->total;
	}

	/**
	 * Read a single conversation record
	 *
	 * @param int $id History ID
	 * @return array|false
	 */
	public function read($id)
	{
		if (($row = $this->db->select(
			self::HISTORY_TABLE,
			'*',
			['history_id' => (int)$id],
			__LINE__,
			__FILE__,
			false,
			'',
			self::APP,
		)->fetch()))
		{
			return $this->format_row($row);
		}
		
		return false;
	}

	/**
	 * Save conversation data
	 *
	 * @param array $data Conversation data
	 * @return int|false Record ID on success, false on failure
	 */
	public function save($data)
	{
		if (!empty($data['history_id']))
		{
			// Update existing record
			$id = $data['history_id'];
			unset($data['history_id']);
			
			// Set modified timestamp
			$data['modified'] = time();
			
			$result = $this->db->update(
				self::HISTORY_TABLE,
				$data,
				['history_id' => $id],
				__LINE__,
				__FILE__,
				self::APP,
			);
			
			return $result ? $id : false;
		}
		
		// Insert new record
		$data['created'] = time();
		$data['modified'] = time();
		
		return $this->db->insert(self::HISTORY_TABLE, $data, false, __LINE__, __FILE__, self::APP) ?
			$this->db->get_last_insert_id(self::HISTORY_TABLE, 'history_id') : false;
	}

	/**
	 * Delete a conversation record
	 *
	 * @param int $id History ID
	 * @return bool
	 */
	public function delete($id)
	{
		return $this->db->delete(self::HISTORY_TABLE, ['history_id' => (int)$id], __LINE__, __FILE__, self::APP);
	}

	/**
	 * Format a database row for output
	 *
	 * @param array $row Database row
	 * @return array Formatted row
	 */
	private function format_row($row)
	{
		return [
			'history_id' => $row['history_id'],
			'account_id' => $row['account_id'],
			'session_id' => $row['session_id'],
			'message_type' => $row['message_type'],
			'message_content' => $row['message_content'],
			'conversation_title' => $row['conversation_title'] ?? '',
			'tool_calls' => $row['tool_calls'] ? json_decode($row['tool_calls'], true) : null,
			'tokens_used' => $row['tokens_used'] ?? 0,
			'response_time' => $row['response_time'] ?? 0,
			'created' => $row['created'],
			'modified' => $row['modified'] ?? $row['created'],
			'created_formatted' => Api\DateTime::to($row['created'])
		];
	}

	/**
	 * Get user statistics
	 *
	 * @param int $limit Number of users to return
	 * @return array
	 */
	public function get_user_statistics(int $limit = 10)
	{
		$sql = "SELECT account_id, COUNT(*) as count, SUM(tokens_used) as total_tokens 
				FROM egw_ai_assistant_history 
				GROUP BY account_id 
				ORDER BY count DESC 
				LIMIT $limit";
		
		$stats = [];
		foreach($this->db->query($sql, __LINE__, __FILE__) as $row)
		{
			$stats[] = [
				'account_id' => $row['account_id'],
				'count' => $row['count'],
				'total_tokens' => $row['total_tokens'] ?? 0
			];
		}
		
		return $stats;
	}

	/**
	 * Get tool usage statistics
	 *
	 * @return array
	 */
	public function get_tool_statistics()
	{
		$sql = "SELECT tool_calls, COUNT(*) as usage_count 
				FROM egw_ai_assistant_history 
				WHERE tool_calls IS NOT NULL 
				GROUP BY tool_calls 
				ORDER BY usage_count DESC";

		$stats = [];
		foreach($this->db->query($sql, __LINE__, __FILE__) as $row)
		{
			$tool_calls = json_decode($row['tool_calls'], true);
			if ($tool_calls)
			{
				foreach ($tool_calls as $tool_call)
				{
					$tool_name = $tool_call['function']['name'] ?? 'unknown';
					if (!isset($stats[$tool_name]))
					{
						$stats[$tool_name] = 0;
					}
					$stats[$tool_name] += $row['usage_count'];
				}
			}
		}
		
		return $stats;
	}

	/**
	 * Get average response time
	 *
	 * @return float
	 */
	public function get_average_response_time()
	{
		return $this->db->select(
			self::HISTORY_TABLE,
			'AVG(response_time)',
			'response_time > 0',
			__LINE__,
			__FILE__,
			false,
			'',
			self::APP
		)->fetchColumn() ?: 0;
	}

	/**
	 * Get token usage for a time period
	 *
	 * @param int $start_time Start timestamp
	 * @param int $end_time End timestamp
	 * @return int
	 */
	public function get_token_usage(int $start_time, int $end_time)
	{
		$start_time = $this->db->quote($start_time, 'datetime');
		$end_time = $this->db->quote($end_time, 'datetime');
		return $this->db->select(
			self::HISTORY_TABLE,
			'SUM(tokens_used)',
			"created BETWEEN $start_time AND $end_time",
			__LINE__,
			__FILE__,
			false,
			'',
			self::APP
		)->fetchColumn() ?: 0;
	}
}