<?php
/**
 * EGroupware AI Assistant - User Interface
 *
 * @link http://www.egroupware.org  
 * @package aiassistant
 * @copyright (c) 2025 EGroupware Team
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */namespace EGroupware\AIAssistant;

use EGroupware\Api;

/**
 * User Interface for AI Assistant
 */
class Ui
{
	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
		'edit' => true,
		'list' => true,
		'dialog' => true,
		'ajax_api' => true,
		'action' => true,
		'export' => true,
	];

	/**
	 * Main AI assistant interface
	 *
	 * @param array|null $content
	 */
	public function index(?array $content = null)
	{
		$tmpl = new Api\Etemplate('aiassistant.index');
		
		// Load user preferences and history
		$bo = new Bo();
		$history = $bo->get_user_history();
		
		$content = [
			'history' => $history,
			'user_name' => $GLOBALS['egw_info']['user']['account_fullname'] ?: $GLOBALS['egw_info']['user']['account_lid'],
		];

		$tmpl->exec('aiassistant.'.self::class.'.index', $content, null, null, null, 2);
	}

	/**
	 * Configuration/Settings interface
	 *
	 * @param array|null $content
	 */
	public function edit(?array $content = null)
	{
		// Check if user has admin access for global settings
		$is_admin = !empty($GLOBALS['egw_info']['user']['apps']['admin']);
		
		$tmpl = new Api\Etemplate('aiassistant.edit');
		$bo = new Bo();
		$so = new So();
		
		if (!empty($content['button']))
		{
			$button = key($content['button']);
			unset($content['button']);
			switch ($button)
			{
				case 'save':
					try
					{
						// Save configuration
						if ($is_admin)
						{
							// Save global admin settings
							$so->save_config('ai_api_url', $content['ai_api_url']);
							
							// Only save API key if it's provided and not empty
							if (!empty($content['ai_api_key']) && trim($content['ai_api_key']) !== '' && $content['ai_api_key'] !== '***hidden***') {
								$so->save_config('ai_api_key', trim($content['ai_api_key']));
							}
							
							$so->save_config('ai_model', $content['ai_model']);
							$so->save_config('max_history_length', $content['max_history_length']);
							$so->save_config('temperature', $content['temperature']);
							$so->save_config('max_tokens', $content['max_tokens']);
						}

						// Save user preferences
						$account_id = $GLOBALS['egw_info']['user']['account_id'];
						$so->save_user_config($account_id, 'user_chat_enabled', $content['user_chat_enabled']);
						$so->save_user_config($account_id, 'auto_save_conversations', $content['auto_save_conversations']);
						$so->save_user_config($account_id, 'show_tool_details', $content['show_tool_details']);

						Api\Framework::message('Settings saved successfully', 'success');
					} catch (\Exception $e)
					{
						Api\Framework::message('Error saving settings: ' . $e->getMessage(), 'error');
					}
					break;
				case 'test_connection':
					try
					{
						// Test API connection
						$config = [
							'api_url' => $content['ai_api_url'] ?: $so->get_config('ai_api_url'),
							'api_key' => $content['ai_api_key'] ?: $so->get_config('ai_api_key'),
							'model' => $content['ai_model'] ?: $so->get_config('ai_model')
						];

						// Simple test request
						$test_response = $this->test_api_connection($config);
						Api\Framework::message('API connection successful', 'success');
					} catch (\Exception $e)
					{
						Api\Framework::message('API connection failed: ' . $e->getMessage(), 'error');
					}
			}
		}
		// Load current settings
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		$current_api_key = $so->get_config('ai_api_key');
		
		$content = [
			// Global settings (admin only)
			'ai_api_url' => $so->get_config('ai_api_url', 'https://models.inference.ai.azure.com'),
			'ai_api_key' => $is_admin ? ($current_api_key ? '***hidden***' : '') : '',
			'ai_model' => $so->get_config('ai_model', 'gpt-4o-mini'),
			'max_history_length' => $so->get_config('max_history_length', 100),
			'temperature' => $so->get_config('temperature', 0.7),
			'max_tokens' => $so->get_config('max_tokens', 1000),
			
			// User preferences
			'user_chat_enabled' => $so->get_user_config($account_id, 'user_chat_enabled', true),
			'auto_save_conversations' => $so->get_user_config($account_id, 'auto_save_conversations', true),
			'show_tool_details' => $so->get_user_config($account_id, 'show_tool_details', false),
			
			'is_admin' => $is_admin
		];

		$tmpl->exec('aiassistant.'.self::class.'.edit', $content);
	}

	/**
	 * List view for conversation history
	 *
	 * @param array|null $content
	 */
	public function list(?array $content = null)
	{
		if (!$GLOBALS['egw']->acl->check('run', 1, 'aiassistant')) {
			Api\Framework::message('You do not have permission to access the AI Assistant', 'error');
			$GLOBALS['egw']->framework->render('<p>Access denied</p>', 'AI Assistant', true);
			return;
		}

		$tmpl = new Api\Etemplate('aiassistant.list');
		$bo = new Bo();
		
		$content = [
			'nm' => [
				'get_rows' => 'aiassistant.'.Bo::class.'.get_rows',
				'no_filter' => true,
				'no_filter2' => true,
				'no_cat' => true,
				'order' => 'created',
				'sort' => 'DESC',
				'row_id' => 'history_id',
				'actions' => $this->get_actions(),
				'header_left' => 'aiassistant.list.header_left',
				'header_right' => 'aiassistant.list.header_right',
			]
		];

		$tmpl->exec('aiassistant.'.self::class.'.list', $content);
	}

	/**
	 * Dialog for viewing conversation details
	 *
	 * @param array|null $content
	 */
	public function dialog(?array $content = null)
	{
		if (!$GLOBALS['egw']->acl->check('run', 1, 'aiassistant')) {
			Api\Framework::message('You do not have permission to access the AI Assistant', 'error');
			return;
		}

		$history_id = $_REQUEST['history_id'] ?? 0;
		if (!$history_id) {
			Api\Framework::message('Invalid conversation ID', 'error');
			return;
		}

		$bo = new Bo();
		$conversation = $bo->read($history_id);
		
		if (!$conversation) {
			Api\Framework::message('Conversation not found', 'error');
			return;
		}
		
		// Check permissions
		$current_user = $GLOBALS['egw_info']['user']['account_id'];
		if ($conversation['account_id'] != $current_user && 
			!$GLOBALS['egw']->acl->check('admin', 1, 'aiassistant')) {
			Api\Framework::message('Access denied', 'error');
			return;
		}

		$tmpl = new Api\Etemplate('aiassistant.dialog');
		
		// Get user info
		$user = $GLOBALS['egw']->accounts->read($conversation['account_id']);
		
		// Format tool calls for display
		$tool_calls_display = '';
		if (!empty($conversation['tool_calls'])) {
			$tool_calls = json_decode($conversation['tool_calls'], true);
			if ($tool_calls) {
				$tool_calls_display = json_encode($tool_calls, JSON_PRETTY_PRINT);
			}
		}
		
		$content = [
			'conversation_title' => $conversation['conversation_title'] ?: 'AI Conversation #' . $history_id,
			'account_fullname' => $user ? $user['account_fullname'] : 'Unknown User',
			'created' => $conversation['created'],
			'message_content' => $conversation['message_content'],
			'tokens_used' => $conversation['tokens_used'] ?: 'N/A',
			'response_time' => $conversation['response_time'] ? $conversation['response_time'] . 'ms' : 'N/A',
			'tool_calls_content' => $tool_calls_display ?: 'No tool calls',
			'tool_calls_label' => $tool_calls_display ? 'Tool Calls' : '', // Hide if no tool calls
		];

		$tmpl->exec('aiassistant.'.self::class.'.dialog', $content);
	}

	/**
	 * Save configuration via AJAX
	 */
	private function save_config_ajax()
	{
		try {
			$so = new So();
			$is_admin = $GLOBALS['egw']->acl->check('admin', 1, 'aiassistant');
			
			if ($is_admin) {
				// Save global admin settings
				$so->save_config('ai_api_url', $_REQUEST['ai_api_url']);
				$so->save_config('ai_api_key', $_REQUEST['ai_api_key']);
				$so->save_config('ai_model', $_REQUEST['ai_model']);
				$so->save_config('max_history_length', $_REQUEST['max_history_length']);
				$so->save_config('temperature', $_REQUEST['temperature']);
				$so->save_config('max_tokens', $_REQUEST['max_tokens']);
			}
			
			// Save user preferences
			$account_id = $GLOBALS['egw_info']['user']['account_id'];
			$so->save_user_config($account_id, 'user_chat_enabled', $_REQUEST['user_chat_enabled']);
			$so->save_user_config($account_id, 'auto_save_conversations', $_REQUEST['auto_save_conversations']);
			$so->save_user_config($account_id, 'show_tool_details', $_REQUEST['show_tool_details']);
			
			Api\Json\Response::get()->data([
				'success' => true,
				'message' => 'Settings saved successfully'
			]);
		} catch (\Exception $e) {
			Api\Json\Response::get()->data([
				'success' => false,
				'error' => 'Error saving settings: ' . $e->getMessage()
			]);
		}
	}

	/**
	 * Test API connection via AJAX
	 */
	private function test_api_ajax()
	{
		try {
			$bo = new Bo();
			$bo->test_api_connection();
			
			Api\Json\Response::get()->data([
				'success' => true,
				'message' => 'API connection successful'
			]);
		} catch (\Exception $e) {
			Api\Json\Response::get()->data([
				'success' => false,
				'error' => 'API connection failed: ' . $e->getMessage()
			]);
		}
	}

	/**
	 * AJAX API endpoint for chat interactions
	 */
	public function ajax_api()
	{
		Api\Json\Response::get();
		
		// Get parameters from egw.json call
		$params = func_get_args();
		$action = $params[0] ?? $_REQUEST['action'] ?? '';
		
		$bo = new Bo();
		
		try {
			switch ($action) {
				case 'send_message':
					$message = $params[1] ?? $_REQUEST['message'] ?? '';
					
					if (empty($message)) {
						throw new \Exception('Message cannot be empty');
					}
					
					$response = $bo->send_message($message);
					Api\Json\Response::get()->data([
						'success' => true,
						'response' => $response
					]);
					break;
					
				case 'get_history':
					$history = $bo->get_user_history();
					Api\Json\Response::get()->data([
						'success' => true,
						'history' => $history
					]);
					break;
					
				case 'clear_history':
					$bo->clear_user_history();
					Api\Json\Response::get()->data([
						'success' => true,
						'message' => 'Chat history cleared successfully'
					]);
					break;
					
				case 'process_prompt':
					$prompt_id = $params[1] ?? $_REQUEST['prompt_id'] ?? '';
					$content = $params[2] ?? $_REQUEST['content'] ?? '';
					
					if (empty($prompt_id) || empty($content)) {
						throw new \Exception('Both prompt ID and content are required');
					}
					
					$result = $bo->process_predefined_prompt($prompt_id, $content);
					Api\Json\Response::get()->data([
						'success' => true,
						'result' => $result
					]);
					break;
					
				case 'save_config':
					// Handle AJAX config saving
					$this->save_config_ajax();
					break;
					
				case 'test_api':
					// Handle AJAX API testing
					$this->test_api_ajax();
					break;
					
				default:
					throw new \Exception('Unknown action: ' . $action);
			}
		} catch (\Exception $e) {
			Api\Json\Response::get()->data([
				'success' => false,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Handle actions on conversations (delete, etc.)
	 *
	 * @param string $action Action to perform
	 * @param array $selected Selected conversation IDs
	 * @param bool $select_all Whether all rows are selected
	 * @param int &$success Number of successful operations
	 * @param int &$failed Number of failed operations
	 * @param string &$action_msg Action message
	 * @param string $checkboxes Checkbox name
	 * @param string &$msg Message to display
	 * @return bool
	 */
	public function action($action, $selected, $select_all, &$success, &$failed, &$action_msg, $checkboxes = 'nm', &$msg = '')
	{
		$success = $failed = 0;
		$action_msg = '';
		
		if (!$selected && !$select_all)
		{
			$msg = lang('You need to select some entries first!');
			return false;
		}
		
		$bo = new Bo();
		
		// Get all selected IDs if select_all is true
		if ($select_all)
		{
			$query = Api\Cache::getSession('aiassistant', 'index');
			$all_conversations = $bo->search($query ?? []);
			$selected = array_column($all_conversations, 'history_id');
		}
		
		switch ($action)
		{
			case 'delete':
				foreach ($selected as $id)
				{
					try
					{
						// Check permissions - only allow deleting own conversations
						$conversation = $bo->read($id);
						if (!$conversation)
						{
							$failed++;
							continue;
						}
						
						$current_user = $GLOBALS['egw_info']['user']['account_id'];
						if ($conversation['account_id'] != $current_user && 
							!$GLOBALS['egw']->acl->check('admin', 1, 'aiassistant'))
						{
							$failed++;
							continue;
						}
						
						if ($bo->delete($id))
						{
							$success++;
						}
						else
						{
							$failed++;
						}
					}
					catch (\Exception $e)
					{
						$failed++;
					}
				}
				$action_msg = 'deleted';
				break;
				
			default:
				$msg = lang('Unknown action: %1', $action);
				return false;
		}
		
		return $success > 0;
	}

	/**
	 * Get actions for nextmatch widget
	 *
	 * @param array $query Current query
	 * @return array
	 */
	public function get_actions($query = array())
	{
		$actions = array(
			'open' => array(
				'caption' => 'View Details',
				'default' => true,
				'allowOnMultiple' => false,
				'url' => 'menuaction=aiassistant.EGroupware\\AIAssistant\\Ui.dialog&history_id=$id',
				'popup' => '700x500',
				'group' => $group = 1,
			),
			'continue_chat' => array(
				'caption' => 'Continue Chat',
				'allowOnMultiple' => false,
				'url' => 'menuaction=aiassistant.EGroupware\\AIAssistant\\Ui.index&continue_from=$id',
				'popup' => '850x600',
				'group' => $group,
			),
			'separator' => array(
				'group' => ++$group,
				'caption' => '---'
			),
			'delete' => array(
				'caption' => 'Delete',
				'confirm' => 'Delete selected conversations?',
				'group' => $group,
				'allowOnMultiple' => true,
			),
			'export' => array(
				'caption' => 'Export',
				'allowOnMultiple' => true,
				'group' => $group,
				'popup' => '400x300',
				'url' => 'menuaction=aiassistant.EGroupware\\AIAssistant\\Ui.export&selected=$id',
			),
		);

		return $actions;
	}

	/**
	 * Export conversations
	 *
	 * @param array|null $content
	 */
	public function export(?array $content = null)
	{
		$selected = $_REQUEST['selected'] ?? [];
		if (empty($selected)) {
			Api\Framework::message('No conversations selected for export', 'error');
			return;
		}

		$bo = new Bo();
		$conversations = [];
		
		foreach ($selected as $id) {
			$conversation = $bo->read($id);
			if ($conversation) {
				// Check permissions
				$current_user = $GLOBALS['egw_info']['user']['account_id'];
				if ($conversation['account_id'] == $current_user || 
					$GLOBALS['egw']->acl->check('admin', 1, 'aiassistant')) {
					$conversations[] = $conversation;
				}
			}
		}
		
		if (empty($conversations)) {
			Api\Framework::message('No accessible conversations found', 'error');
			return;
		}
		
		// Export as JSON
		$export_data = [
			'export_date' => date('Y-m-d H:i:s'),
			'export_user' => $GLOBALS['egw_info']['user']['account_fullname'],
			'conversations' => $conversations
		];
		
		$filename = 'ai_conversations_' . date('Y-m-d_H-i-s') . '.json';
		
		header('Content-Type: application/json');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo json_encode($export_data, JSON_PRETTY_PRINT);
		exit;
	}
}