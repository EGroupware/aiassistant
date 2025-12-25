<?php
/**
 * EGroupware AI Assistant - Business Logic
 *
 * @link http://www.egroupware.org  
 * @package aiassistant
 * @copyright (c) 2025 EGroupware Team
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AIAssistant;

use EGroupware\Api;

/**
 * Business logic for AI Assistant
 */
class Bo
{
	/**
	 * Storage object
	 * @var So
	 */
	protected $so;
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->so = new So();
		
		// Migrate old configuration on first use
		$this->migrate_config_if_needed();
	}
	
	/**
	 * Migrate configuration from standard EGroupware config to custom table
	 */
	private function migrate_config_if_needed()
	{
		// Check if we already have config in custom table
		$existing_config = $this->so->get_config('ai_api_key');
		if ($existing_config) {
			return; // Already migrated
		}
		
		// Check if there's config in the old standard table
		$old_config = Api\Config::read('aiassistant');
		if (empty($old_config)) {
			return; // No old config to migrate
		}
		
		// Migrate the configuration
		$config_keys = ['ai_model', 'ai_api_url', 'ai_api_key', 'max_history_length', 'temperature', 'max_tokens'];
		$migrated_any = false;
		
		foreach ($config_keys as $key) {
			if (isset($old_config[$key])) {
				$this->so->save_config($key, $old_config[$key]);
				$migrated_any = true;
			}
		}
		
		if ($migrated_any) {
			// Log the migration
			error_log("AI Assistant: Migrated configuration from egw_config to egw_ai_assistant_config");
		}
	}
	
	/**
	 * Send a message to the AI and get response
	 */
	public function send_message($message)
	{
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		
		// Save user message with conversation title if it's the first message
		$history = $this->so->get_user_history($account_id, 1);
		$conversation_title = empty($history) ? $this->generate_conversation_title($message) : null;
		
		$this->so->save_message($account_id, 'user', $message, null, $conversation_title);
		
		// Get AI configuration
		$api_config = $this->get_ai_config();
		if (empty($api_config['api_key']) && $api_config['provider'] !== 'ollama') {
			throw new \Exception('AI API not configured. Please contact your administrator.');
		}
		
		// Prepare conversation history
		$history = $this->so->get_user_history($account_id, 10); // Last 10 messages for context
		$messages = $this->prepare_messages($history, $message);
		
		// Get AI response
		$start_time = microtime(true);
		$response = $this->call_ai_api($api_config, $messages);
		$response_time = round((microtime(true) - $start_time) * 1000); // Convert to milliseconds
		
		// Save AI response with metrics
		$tool_calls = $response['tool_calls'] ?? null;
		$tokens_used = $response['usage']['total_tokens'] ?? 0;
		$this->so->save_message($account_id, 'assistant', $response['content'], $tool_calls, null, $tokens_used, $response_time);
		
		return $response;
	}
	
	/**
	 * Get user chat history
	 */
	public function get_user_history($limit = 50)
	{
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		return $this->so->get_user_history($account_id, $limit);
	}
	
	/**
	 * Clear user chat history
	 */
	public function clear_user_history()
	{
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		return $this->so->clear_user_history($account_id);
	}
	
	/**
	 * Process predefined prompts for text widgets
	 * 
	 * @param string $prompt_id The predefined prompt ID
	 * @param string $content The text content to process
	 * @return string The processed content
	 */
	public function process_predefined_prompt($prompt_id, $content)
	{
		// Get AI configuration
		$api_config = $this->get_ai_config();
		if (empty($api_config['api_key'])) {
			throw new \Exception('AI API not configured. Please contact your administrator.');
		}
		
		// Define predefined prompts
		$prompts = $this->get_predefined_prompts();
		
		if (!isset($prompts[$prompt_id])) {
			throw new \Exception('Unknown prompt ID: ' . $prompt_id);
		}
		
		$prompt_template = $prompts[$prompt_id];
		$system_message = str_replace('{content}', $content, $prompt_template);
		
		// Prepare messages for AI API call
		$messages = [
			[
				'role' => 'system',
				'content' => $system_message
			],
			[
				'role' => 'user', 
				'content' => $content
			]
		];
		
		// Call AI API
		$response = $this->call_ai_api($api_config, $messages);
		
		// Return just the processed content, not the full response structure
		return $response['content'] ?? $content;
	}
	
	/**
	 * Get predefined prompt templates
	 */
	private function get_predefined_prompts()
	{
		return [
			'aiassist.summarize' => 'Please summarize the following text concisely while preserving the key information and main points. Return only the summary without any additional commentary.',
			'aiassist.formal' => 'Please rewrite the following text to make it more professional and formal while maintaining the original meaning. Return only the revised text.',
			'aiassist.casual' => 'Please rewrite the following text to make it more casual and friendly while maintaining the original meaning. Return only the revised text.',
			'aiassist.grammar' => 'Please correct any grammar, spelling, and punctuation errors in the following text while preserving the original meaning and tone. Return only the corrected text.',
			'aiassist.concise' => 'Please make the following text more concise and to-the-point while preserving all important information. Return only the condensed text.',
			'aiassist.generate_reply' => 'Based on the following text, generate a professional email reply. Return only the reply content.',
			'aiassist.meeting_followup' => 'Based on the following content, create a professional meeting follow-up message. Return only the follow-up content.',
			'aiassist.thank_you' => 'Based on the following context, create a professional thank you note. Return only the thank you message.',
			'aiassist.translate-en' => 'Please translate the following text to English. Return only the translated text.',
			'aiassist.translate-de' => 'Please translate the following text to German. Return only the translated text.',
			'aiassist.translate-fr' => 'Please translate the following text to French. Return only the translated text.',
			'aiassist.translate-es' => 'Please translate the following text to Spanish. Return only the translated text.',
			'aiassist.translate-it' => 'Please translate the following text to Italian. Return only the translated text.',
			'aiassist.translate-pt' => 'Please translate the following text to Portuguese. Return only the translated text.',
			'aiassist.translate-nl' => 'Please translate the following text to Dutch. Return only the translated text.',
			'aiassist.translate-ru' => 'Please translate the following text to Russian. Return only the translated text.',
			'aiassist.translate-zh' => 'Please translate the following text to Chinese. Return only the translated text.',
			'aiassist.translate-ja' => 'Please translate the following text to Japanese. Return only the translated text.',
			'aiassist.translate-ko' => 'Please translate the following text to Korean. Return only the translated text.',
			'aiassist.translate-ar' => 'Please translate the following text to Arabic. Return only the translated text.',
			'aiassist.translate-fa' => 'Please translate the following text to Persian (Farsi). Return only the translated text.',
			'aiassist.generate_subject' => 'Based on the following email content, generate a clear and concise subject line that accurately summarizes the main topic or purpose. Return only the subject line without quotes or additional text.',
		];
	}
	
	/**
	 * Get AI configuration
	 */
	private function get_ai_config()
	{
		$api_url = $this->so->get_config('ai_api_url', 'https://api.openai.com/v1');
		$model = $this->so->get_config('ai_model', 'gpt-4o-mini');
		$api_key = $this->so->get_config('ai_api_key');
		
		// Clean up API key - remove any whitespace/newlines
		$api_key = trim($api_key);
		
		// Clean up model name - remove provider prefix if present
		if (strpos($model, ':') !== false) {
			[$provider, $model] = explode(':', $model, 2);
		}
		
		return [
			'api_url' => $api_url,
			'api_key' => $api_key,
			'model' => $model,
			'provider' => $provider ?? null,
		];
	}
	
	/**
	 * Prepare messages for AI API
	 */
	private function prepare_messages($history, $new_message)
	{
		$messages = [];
		
		// System prompt
		$messages[] = [
			'role' => 'system',
			'content' => $this->get_system_prompt()
		];
		
		// Add conversation history
		foreach ($history as $entry) {
			$messages[] = [
				'role' => $entry['message_type'],
				'content' => $entry['message_content'] ?? ''
			];
		}
		
		// Add new user message
		$messages[] = [
			'role' => 'user',
			'content' => $new_message ?? ''
		];
		
		return $messages;
	}
	
	/**
	 * Get system prompt for AI
	 */
	private function get_system_prompt()
	{
		$user_name = $GLOBALS['egw_info']['user']['account_fullname'] ?: $GLOBALS['egw_info']['user']['account_lid'];
		
		return "You are an AI assistant integrated into EGroupware, helping user '{$user_name}' with their daily business tasks. " .
			   "You have access to EGroupware's internal APIs and MUST perform real actions immediately. Available functions include:\n\n" .
			   "CURRENT DATE & TIME:\n" .
			   "- get_current_date: Get the current date and time (always use this when users ask about dates or 'today')\n\n" .
			   "CONTACTS:\n" .
			   "- create_contact: Create new contacts with name, email, phone, organization, title, notes\n" .
			   "- search_contacts: Search existing contacts by name, email, company, etc.\n\n" .
			   "CALENDAR:\n" .
			   "- create_calendar_event: Schedule meetings/appointments with title, time, location, attendees\n" .
			   "- search_calendar_events: Find existing events by date range or search terms\n\n" .
			   "TASKS:\n" .
			   "- create_task: Create tasks in InfoLog with title, description, due date, priority, assignments\n\n" .
			   "EMAIL:\n" .
			   "- send_email: Send emails through EGroupware with to/cc/bcc recipients\n\n" .
			   "CRITICAL WORKFLOW INSTRUCTIONS - FOLLOW THESE EXACTLY:\n" .
			   "1. ALWAYS execute tools immediately when requested - NEVER say 'I will' or 'Let me'\n" .
			   "2. For date-related queries: Call get_current_date first, then search_calendar_events\n" .
			   "3. For contact queries: Call search_contacts immediately\n" .
			   "4. ALWAYS call the tools and provide complete results in the SAME response\n" .
			   "5. When user asks multiple things, handle ALL requests in one response using multiple tool calls\n" .
			   "6. Present all results clearly with proper formatting\n" .
			   "7. If no results found, state clearly and offer next steps\n\n" .
			   "RESPONSE FORMAT:\n" .
			   "- Execute all necessary tools first\n" .
			   "- Present complete results immediately\n" .
			   "- Use clear headings and formatting\n" .
			   "- Include all requested information in one comprehensive response\n\n" .
			   "EXAMPLE: User asks 'What's my schedule for today?'\n" .
			   "YOUR RESPONSE: Call get_current_date + search_calendar_events, then immediately show:\n" .
			   "'### Today's Schedule (August 19, 2025)\n[Complete calendar results here]'";
	}
	
	/**
	 * Call AI API
	 */
	private function call_ai_api($config, $messages)
	{
		$tools = $this->get_available_tools();
		
		$data = [
			'model' => $config['model'],
			'messages' => $messages,
			'tools' => $tools,
			'tool_choice' => 'auto',
			'temperature' => 0.7,
			'max_tokens' => 1000
		];
		
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $config['api_key']
		];
		
		// Make API request
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $config['api_url'] . '/chat/completions');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for tool execution
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		
		if ($curl_error) {
			throw new \Exception('API request failed: ' . $curl_error);
		}
		
		if ($http_code !== 200) {
			$error_details = '';
			if ($response) {
				$error_response = json_decode($response, true);
				$error_details = $error_response['error']['message'] ?? $response;
			}
			
			$error_message = "AI API request failed with status: $http_code";
			if ($error_details) {
				$error_message .= " - " . $error_details;
			}
			
			// Add debugging info for common errors
			if ($http_code === 401) {
				$error_message .= "\nPlease verify your API key is correct and has the necessary permissions.";
			} elseif ($http_code === 404) {
				$error_message .= "\nPlease check the API URL: " . $config['api_url'];
			}
			
			throw new \Exception($error_message);
		}
		
		$result = json_decode($response, true);
		if (!$result || !isset($result['choices'][0]['message'])) {
			throw new \Exception('Invalid AI API response format');
		}
		
		$ai_message = $result['choices'][0]['message'];
		
		// Execute tools if requested and get a follow-up response
		if (!empty($ai_message['tool_calls'])) {
			error_log("AI Assistant Debug - Tool calls detected: " . count($ai_message['tool_calls']));
			
			$tool_results = $this->execute_tools($ai_message['tool_calls']);
			
			error_log("AI Assistant Debug - Tool results count: " . count($tool_results));
			
			// Add the assistant's tool call message
			$messages[] = [
				'role' => 'assistant',
				'content' => $ai_message['content'] ?? '',
				'tool_calls' => $ai_message['tool_calls']
			];
			
			// Add tool results as tool messages
			foreach ($tool_results as $tool_result) {
				$result_content = '';
				if (isset($tool_result['result']['message'])) {
					$result_content = $tool_result['result']['message'];
					error_log("AI Assistant Debug - Tool result message: " . substr($result_content, 0, 200) . "...");
				} elseif (isset($tool_result['result']['success']) && $tool_result['result']['success']) {
					$result_content = 'Operation completed successfully';
				} elseif (isset($tool_result['result']['error'])) {
					$result_content = 'Error: ' . $tool_result['result']['error'];
				} else {
					$result_content = json_encode($tool_result['result']);
				}
				
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $tool_result['id'],
					'content' => $result_content
				];
			}
			
			// Make a second API call to get the AI's response incorporating the tool results
			$follow_up_data = [
				'model' => $config['model'],
				'messages' => $messages,
				'temperature' => 0.7,
				'max_tokens' => 1000
			];
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $config['api_url'] . '/chat/completions');
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($follow_up_data));
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for follow-up
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			
			$follow_up_response = curl_exec($ch);
			$follow_up_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			
			if ($follow_up_http_code === 200) {
				$follow_up_result = json_decode($follow_up_response, true);
				if ($follow_up_result && isset($follow_up_result['choices'][0]['message']['content'])) {
					// Use the follow-up response as the final content
					$ai_message['content'] = $follow_up_result['choices'][0]['message']['content'];
					error_log("AI Assistant Debug - Final AI response: " . substr($ai_message['content'], 0, 200) . "...");
					// Update usage information if available
					if (isset($follow_up_result['usage'])) {
						$result['usage'] = $follow_up_result['usage'];
					}
				} else {
					error_log("AI Assistant Debug - Follow-up response missing content");
					// Fallback: create a response with tool results
					$ai_message['content'] = $this->format_tool_results_fallback($tool_results);
				}
			} else {
				error_log("AI Assistant Debug - Follow-up API call failed with code: " . $follow_up_http_code);
				// Fallback: create a response with tool results
				$ai_message['content'] = $this->format_tool_results_fallback($tool_results);
			}
			
			$ai_message['tool_calls'] = $tool_results;
		}
		
		return [
			'content' => $ai_message['content'] ?? 'I processed your request.',
			'tool_calls' => $ai_message['tool_calls'] ?? null,
			'usage' => $result['usage'] ?? null
		];
	}
	
	/**
	 * Format tool results as fallback when AI follow-up fails
	 */
	private function format_tool_results_fallback($tool_results)
	{
		$response = "Here are the results from your request:\n\n";
		
		foreach ($tool_results as $tool_result) {
			$function_name = $tool_result['function']['name'] ?? 'Unknown';
			$result = $tool_result['result'];
			
			if (isset($result['message'])) {
				$response .= $result['message'] . "\n\n";
			} elseif (isset($result['success']) && $result['success']) {
				$response .= "✅ $function_name completed successfully\n\n";
			} elseif (isset($result['error'])) {
				$response .= "❌ Error in $function_name: " . $result['error'] . "\n\n";
			}
		}
		
		return $response;
	}
	
	/**
	 * Get available tools for AI
	 */
	private function get_available_tools()
	{
		return [
			[
				'type' => 'function',
				'function' => [
					'name' => 'get_current_date',
					'description' => 'Get the current date and time information',
					'parameters' => [
						'type' => 'object',
						'properties' => (object)[],
						'required' => []
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'create_contact',
					'description' => 'Create a new contact in EGroupware addressbook',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'first_name' => ['type' => 'string', 'description' => 'First name'],
							'last_name' => ['type' => 'string', 'description' => 'Last name'],
							'email' => ['type' => 'string', 'description' => 'Email address'],
							'phone' => ['type' => 'string', 'description' => 'Phone number'],
							'organization' => ['type' => 'string', 'description' => 'Organization/Company'],
							'title' => ['type' => 'string', 'description' => 'Job title'],
							'notes' => ['type' => 'string', 'description' => 'Additional notes']
						],
						'required' => ['first_name', 'last_name']
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'search_contacts',
					'description' => 'Search for contacts in EGroupware addressbook',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'query' => ['type' => 'string', 'description' => 'Search query (name, email, company, etc.)'],
							'limit' => ['type' => 'integer', 'description' => 'Maximum results to return', 'default' => 10]
						],
						'required' => ['query']
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'create_calendar_event',
					'description' => 'Create a calendar event in EGroupware',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'title' => ['type' => 'string', 'description' => 'Event title'],
							'description' => ['type' => 'string', 'description' => 'Event description'],
							'start_time' => ['type' => 'string', 'description' => 'Start time (YYYY-MM-DD HH:MM)'],
							'end_time' => ['type' => 'string', 'description' => 'End time (YYYY-MM-DD HH:MM)'],
							'location' => ['type' => 'string', 'description' => 'Event location'],
							'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of attendee email addresses']
						],
						'required' => ['title', 'start_time']
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'search_calendar_events',
					'description' => 'Search for calendar events in EGroupware. Use this when users ask about appointments, meetings, events, or their schedule. For relative dates like "next week", "this month", calculate the actual dates first.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'start_date' => ['type' => 'string', 'description' => 'Start date for search (YYYY-MM-DD). For "next week" use Monday of next week.'],
							'end_date' => ['type' => 'string', 'description' => 'End date for search (YYYY-MM-DD). For "next week" use Sunday of next week.'],
							'query' => ['type' => 'string', 'description' => 'Search query for title/description'],
							'limit' => ['type' => 'integer', 'description' => 'Maximum results to return', 'default' => 10]
						]
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'send_email',
					'description' => 'Send an email through EGroupware',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Recipient email addresses'],
							'subject' => ['type' => 'string', 'description' => 'Email subject'],
							'body' => ['type' => 'string', 'description' => 'Email body content'],
							'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'CC recipients (optional)'],
							'bcc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'BCC recipients (optional)']
						],
						'required' => ['to', 'subject', 'body']
					]
				]
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'create_task',
					'description' => 'Create a task in EGroupware InfoLog',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'title' => ['type' => 'string', 'description' => 'Task title'],
							'description' => ['type' => 'string', 'description' => 'Task description'],
							'due_date' => ['type' => 'string', 'description' => 'Due date (YYYY-MM-DD)'],
							'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent'], 'description' => 'Task priority'],
							'assigned_to' => ['type' => 'string', 'description' => 'Email of person to assign task to'],
							'category' => ['type' => 'string', 'description' => 'Task category']
						],
						'required' => ['title']
					]
				]
			]
		];
	}
	
	/**
	 * Execute tool calls using EGroupware internal APIs
	 */
	private function execute_tools($tool_calls)
	{
		$results = [];
		
		foreach ($tool_calls as $tool_call) {
			$function_name = $tool_call['function']['name'];
			$arguments = json_decode($tool_call['function']['arguments'], true);
			
			try {
				// Add timeout protection for each tool call
				$start_time = microtime(true);
				
				switch ($function_name) {
					case 'get_current_date':
						$result = $this->get_current_date_internal($arguments);
						break;
						
					case 'create_contact':
						$result = $this->create_contact_internal($arguments);
						break;
						
					case 'search_contacts':
						$result = $this->search_contacts_internal($arguments);
						break;
						
					case 'create_calendar_event':
						$result = $this->create_calendar_event_internal($arguments);
						break;
						
					case 'send_email':
						$result = $this->send_email_internal($arguments);
						break;
						
					case 'search_calendar_events':
						$result = $this->search_calendar_events_internal($arguments);
						break;
						
					case 'create_task':
						$result = $this->create_task_internal($arguments);
						break;
						
					default:
						$result = ['error' => 'Unknown tool: ' . $function_name];
				}
				
				$execution_time = round((microtime(true) - $start_time) * 1000);
				error_log("AI Assistant Debug - Tool $function_name executed in {$execution_time}ms");
				
				$results[] = [
					'id' => $tool_call['id'],
					'function' => $tool_call['function'],
					'result' => $result
				];
				
			} catch (\Exception $e) {
				error_log("AI Assistant Debug - Tool $function_name failed: " . $e->getMessage());
				$results[] = [
					'id' => $tool_call['id'],
					'function' => $tool_call['function'],
					'result' => ['error' => $e->getMessage()]
				];
			}
		}
		
		return $results;
	}
	
	/**
	 * Get current date and time information for debugging
	 */
	private function get_current_date_internal($args)
	{
		$system_time = time();
		$user_tz = $GLOBALS['egw_info']['user']['preferences']['common']['tz'] ?? 'UTC';
		
		return [
			'success' => true,
			'message' => sprintf(
				"**Current Date & Time Information:**\n\n" .
				"🕒 **System Time:** %s UTC\n" .
				"🌍 **User Timezone:** %s\n" .
				"📅 **Today's Date:** %s\n" .
				"⏰ **Current Time:** %s\n" .
				"📆 **Week Info:** Week of %s",
				date('Y-m-d H:i:s', $system_time),
				$user_tz,
				date('Y-m-d', $system_time),
				date('H:i:s', $system_time),
				date('Y-m-d', strtotime('monday this week', $system_time))
			),
			'timestamp' => $system_time,
			'user_timezone' => $user_tz
		];
	}
	
	/**
	 * Create contact using EGroupware internal API
	 */
	private function create_contact_internal($args)
	{
		if (!class_exists('addressbook_bo')) {
			throw new \Exception('Addressbook application not available');
		}
		
		$contacts = new \addressbook_bo();
		
		$contact_data = [
			'n_given' => $args['first_name'],
			'n_family' => $args['last_name'],
			'email' => $args['email'] ?? '',
			'tel_work' => $args['phone'] ?? '',
			'org_name' => $args['organization'] ?? '',
			'title' => $args['title'] ?? '',
			'note' => $args['notes'] ?? '',
			'owner' => $GLOBALS['egw_info']['user']['account_id']
		];
		
		$contact_id = $contacts->save($contact_data);
		
		if ($contact_id) {
			return [
				'success' => true,
				'contact_id' => $contact_id,
				'message' => "Contact '{$args['first_name']} {$args['last_name']}' created successfully"
			];
		} else {
			throw new \Exception('Failed to create contact');
		}
	}
	
	/**
	 * Search contacts using EGroupware internal API
	 */
	private function search_contacts_internal($args)
	{
		if (!class_exists('addressbook_bo')) {
			throw new \Exception('Addressbook application not available');
		}
		
		$contacts = new \addressbook_bo();
		
		// Debug logging
		error_log("AI Assistant Debug - Searching for: " . ($args['query'] ?? 'empty query'));
		
		$results = $contacts->search($args['query'], false, '', '', '%', false, 'OR', [0, $args['limit'] ?? 10]);
		
		// Debug logging
		error_log("AI Assistant Debug - Search results count: " . (is_array($results) ? count($results) : 0));
		if (is_array($results) && count($results) > 0) {
			foreach (array_slice($results, 0, 3) as $contact) {
				error_log("AI Assistant Debug - Contact: " . ($contact['n_fn'] ?? 'No name') . " (ID: " . ($contact['id'] ?? $contact['contact_id'] ?? 'No ID') . ")");
			}
		}
		
		$formatted_results = [];
		if ($results) {
			foreach ($results as $contact) {
				// Use contact_id if id is not available
				$contact_id = $contact['id'] ?? $contact['contact_id'] ?? 0;
				
				$formatted_results[] = [
					'id' => $contact_id,
					'name' => $contact['n_fn'] ?? ($contact['n_given'] . ' ' . $contact['n_family']),
					'email' => $contact['email'] ?? $contact['contact_email'] ?? '',
					'phone' => $contact['tel_work'] ?? '',
					'organization' => $contact['org_name'] ?? '',
					'title' => $contact['title'] ?? $contact['contact_title'] ?? ''
				];
			}
		}
		
		// Format results for user display
		if (empty($formatted_results)) {
			return [
				'success' => true,
				'message' => "No contacts found matching '" . ($args['query'] ?? '') . "'.",
				'results' => [],
				'count' => 0
			];
		}
		
		$display_message = "Found " . count($formatted_results) . " contact(s) matching '" . ($args['query'] ?? '') . "':\n\n";
		foreach ($formatted_results as $contact) {
			$display_message .= "👤 **{$contact['name']}**\n";
			if (!empty($contact['email'])) {
				$display_message .= "   📧 {$contact['email']}\n";
			}
			if (!empty($contact['phone'])) {
				$display_message .= "   📞 {$contact['phone']}\n";
			}
			if (!empty($contact['organization'])) {
				$display_message .= "   🏢 {$contact['organization']}\n";
			}
			if (!empty($contact['title'])) {
				$display_message .= "   💼 {$contact['title']}\n";
			}
			$display_message .= "\n";
		}
		
		return [
			'success' => true,
			'message' => $display_message,
			'results' => $formatted_results,
			'count' => count($formatted_results)
		];
	}
	
	/**
	 * Create calendar event using EGroupware internal API
	 */
	private function create_calendar_event_internal($args)
	{
		if (!class_exists('calendar_boupdate')) {
			throw new \Exception('Calendar application not available');
		}
		
		$cal = new \calendar_boupdate();
		
		$start_time = strtotime($args['start_time']);
		$end_time = isset($args['end_time']) ? strtotime($args['end_time']) : $start_time + 3600; // Default 1 hour
		
		$participants = [$GLOBALS['egw_info']['user']['account_id'] => 'A'];
		
		// Add attendees if provided
		if (!empty($args['attendees'])) {
			foreach ($args['attendees'] as $email) {
				$account = $GLOBALS['egw']->accounts->name2id($email, 'account_email');
				if ($account) {
					$participants[$account] = 'U'; // Unknown status
				}
			}
		}
		
		$event_data = [
			'title' => $args['title'],
			'description' => $args['description'] ?? '',
			'start' => $start_time,
			'end' => $end_time,
			'location' => $args['location'] ?? '',
			'owner' => $GLOBALS['egw_info']['user']['account_id'],
			'participants' => $participants
		];
		
		$event_id = $cal->save($event_data);
		
		if ($event_id) {
			return [
				'success' => true,
				'event_id' => $event_id,
				'message' => "Calendar event '{$args['title']}' created successfully"
			];
		} else {
			throw new \Exception('Failed to create calendar event');
		}
	}
	
	/**
	 * Search calendar events using direct database access to egw_cal table
	 */
	private function search_calendar_events_internal($args)
	{
		if (!isset($GLOBALS['egw']->db)) {
			throw new \Exception('Database connection not available');
		}
		
		$db = $GLOBALS['egw']->db;
		$user_id = $GLOBALS['egw_info']['user']['account_id'];
		
		// Use direct timestamp calculations to avoid timezone issues
		$system_time = time();
		error_log("AI Assistant Debug - System time: " . date('Y-m-d H:i:s', $system_time));
		error_log("AI Assistant Debug - EGW user timezone: " . ($GLOBALS['egw_info']['user']['preferences']['common']['tz'] ?? 'not set'));
		
		if (isset($args['start_date'])) {
			$start_date = strtotime($args['start_date']);
		} else {
			// Use current day as start date if not specified
			$start_date = strtotime('today', $system_time);
		}
		
		if (isset($args['end_date'])) {
			$end_date = strtotime($args['end_date']);
		} else {
			// Default to end of day if not specified
			$end_date = strtotime('tomorrow', $start_date) - 1;
		}
		
		error_log("AI Assistant Debug - Search dates (direct): " . date('Y-m-d', $start_date) . " to " . date('Y-m-d', $end_date));
		
		try {
			// Build SQL query to search calendar events from egw_cal table
			$sql = "SELECT DISTINCT c.cal_id, c.cal_title, c.cal_description, c.cal_location, 
			               d.cal_start, d.cal_end, c.cal_owner, c.cal_created, c.cal_modified
			        FROM egw_cal c
			        JOIN egw_cal_dates d ON c.cal_id = d.cal_id
			        JOIN egw_cal_user u ON c.cal_id = u.cal_id
			        WHERE c.cal_deleted IS NULL
			        AND d.cal_start >= ?
			        AND d.cal_start <= ?
			        AND (u.cal_user_type = 'u' AND u.cal_user_id = ? AND u.cal_status != 'R')";
			
			$params = [$start_date, $end_date, $user_id];
			
			// Add search query if provided
			if (!empty($args['query'])) {
				$sql .= " AND (c.cal_title LIKE ? OR c.cal_description LIKE ?)";
				$search_term = '%' . $args['query'] . '%';
				$params[] = $search_term;
				$params[] = $search_term;
			}
			
			// Add ordering and limit
			$sql .= " ORDER BY d.cal_start ASC";
			if (!empty($args['limit'])) {
				$sql .= " LIMIT " . (int)$args['limit'];
			} else {
				$sql .= " LIMIT 50"; // Default limit to prevent huge results
			}
			
			error_log("AI Assistant Debug - SQL: " . $sql);
			error_log("AI Assistant Debug - Params: " . print_r($params, true));
			
			$result = $db->query($sql, __LINE__, __FILE__, 0, -1, $params);
			
			$formatted_results = [];
			if ($result) {
				while (($row = $db->row(true))) {
					$formatted_results[] = [
						'cal_id' => $row['cal_id'],
						'title' => $row['cal_title'],
						'description' => $row['cal_description'] ?? '',
						'location' => $row['cal_location'] ?? '',
						'start_time' => date('Y-m-d H:i', $row['cal_start']),
						'end_time' => date('Y-m-d H:i', $row['cal_end']),
						'owner' => $row['cal_owner'],
						'created' => $row['cal_created'],
						'modified' => $row['cal_modified']
					];
				}
			}
			
			error_log("AI Assistant Debug - Found " . count($formatted_results) . " events via direct SQL");
			if (count($formatted_results) > 0) {
				foreach (array_slice($formatted_results, 0, 3) as $event) {
					error_log("AI Assistant Debug - Event: " . $event['title'] . " on " . $event['start_time'] . " (cal_id: " . $event['cal_id'] . ")");
				}
			}
			
			// Format results for user display
			if (empty($formatted_results)) {
				$search_range = "Search range: " . date('Y-m-d', $start_date) . " to " . date('Y-m-d', $end_date);
				return [
					'success' => true,
					'message' => "No calendar events found for the specified criteria.\n$search_range",
					'results' => [],
					'count' => 0
				];
			}
			
			$search_range = "Search range: " . date('Y-m-d', $start_date) . " to " . date('Y-m-d', $end_date);
			$display_message = "Found " . count($formatted_results) . " calendar event(s) ($search_range):\n\n";
			foreach ($formatted_results as $event) {
				$display_message .= "📅 **{$event['title']}** (cal_id: {$event['cal_id']})\n";
				$display_message .= "   🕒 {$event['start_time']} - {$event['end_time']}\n";
				if (!empty($event['location'])) {
					$display_message .= "   📍 {$event['location']}\n";
				}
				if (!empty($event['description'])) {
					$display_message .= "   📝 {$event['description']}\n";
				}
				$display_message .= "\n";
			}
			
			return [
				'success' => true,
				'message' => $display_message,
				'results' => $formatted_results,
				'count' => count($formatted_results)
			];
			
		} catch (\Exception $e) {
			error_log("AI Assistant Debug - SQL Error: " . $e->getMessage());
			return [
				'success' => false,
				'error' => 'Failed to search calendar events: ' . $e->getMessage(),
				'message' => "Error searching calendar events: " . $e->getMessage()
			];
		}
	}
	
	/**
	 * Send email using EGroupware internal API
	 */
	private function send_email_internal($args)
	{
		if (!class_exists('EGroupware\Api\Mailer')) {
			throw new \Exception('Mail application not available');
		}
		
		try {
			$mailer = new Api\Mailer();
			
			// Set subject
			$mailer->addHeader('Subject', $args['subject']);
			
			// Set body
			$mailer->setBody($args['body']);
			
			// Add recipients
			if (is_array($args['to'])) {
				foreach ($args['to'] as $email) {
					$mailer->addAddress($email);
				}
			} else {
				$mailer->addAddress($args['to']);
			}
			
			// Add CC recipients
			if (!empty($args['cc'])) {
				if (is_array($args['cc'])) {
					foreach ($args['cc'] as $email) {
						$mailer->addCc($email);
					}
				} else {
					$mailer->addCc($args['cc']);
				}
			}
			
			// Add BCC recipients  
			if (!empty($args['bcc'])) {
				if (is_array($args['bcc'])) {
					foreach ($args['bcc'] as $email) {
						$mailer->addBcc($email);
					}
				} else {
					$mailer->addBcc($args['bcc']);
				}
			}
			
			// Send the email
			$mailer->send();
			
			$to_list = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];
			
			return [
				'success' => true,
				'message' => "Email sent successfully to: $to_list"
			];
			
		} catch (\Exception $e) {
			throw new \Exception('Failed to send email: ' . $e->getMessage());
		}
	}
	
	/**
	 * Create task using EGroupware internal API
	 */
	private function create_task_internal($args)
	{
		if (!class_exists('infolog_bo')) {
			throw new \Exception('InfoLog application not available');
		}
		
		$infolog = new \infolog_bo();
		
		$task_data = [
			'info_type' => 'task',
			'info_subject' => $args['title'],
			'info_des' => $args['description'] ?? '',
			'info_startdate' => time(),
			'info_priority' => $this->map_priority($args['priority'] ?? 'normal'),
			'info_responsible' => $GLOBALS['egw_info']['user']['account_id'],
			'info_owner' => $GLOBALS['egw_info']['user']['account_id']
		];
		
		// Set due date if provided
		if (!empty($args['due_date'])) {
			$task_data['info_enddate'] = strtotime($args['due_date']);
		}
		
		// Assign to user if provided
		if (!empty($args['assigned_to'])) {
			$account = $GLOBALS['egw']->accounts->name2id($args['assigned_to'], 'account_email');
			if ($account) {
				$task_data['info_responsible'] = $account;
			}
		}
		
		// Set category if provided
		if (!empty($args['category'])) {
			$task_data['info_cat'] = $args['category'];
		}
		
		$task_id = $infolog->write($task_data);
		
		if ($task_id) {
			return [
				'success' => true,
				'task_id' => $task_id,
				'message' => "Task '{$args['title']}' created successfully"
			];
		} else {
			throw new \Exception('Failed to create task');
		}
	}
	
	/**
	 * Map priority strings to InfoLog priority numbers
	 */
	private function map_priority($priority)
	{
		switch (strtolower($priority)) {
			case 'low': return 1;
			case 'normal': return 2;
			case 'high': return 3;
			case 'urgent': return 4;
			default: return 2; // normal
		}
	}

	/**
	 * Get rows for nextmatch widget (EGroupware standard pattern)
	 *
	 * @param array &$query Query parameters
	 * @param array &$rows Returned rows
	 * @param array &$readonlys Read-only status for rows
	 * @return int Total number of rows
	 */
	public function get_rows(&$query, &$rows, &$readonlys)
	{
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		
		// Build search criteria
		$criteria = array();
		
		if (!empty($query['search']))
		{
			$criteria['search'] = $query['search'];
		}
		
		if (!empty($query['filter']))
		{
			$criteria['message_type'] = $query['filter'];
		}
		
		// Add account filter if not admin
		if (!$GLOBALS['egw']->acl->check('admin', 1, 'aiassistant'))
		{
			$criteria['account_id'] = $account_id;
		}
		
		// Set up ordering
		$order = $query['order'] ?? 'created';
		$sort = $query['sort'] ?? 'DESC';
		
		// Get total count
		$total = $this->so->total($criteria);
		
		// Get actual data
		$start = $query['start'] ?? 0;
		$num_rows = $query['num_rows'] ?? 25;
		
		$conversations = $this->so->search($criteria, $start, $num_rows, $order, $sort);
		
		$rows = array();
		$readonlys = array();
		
		foreach ($conversations as $conv)
		{
			$row = array(
				'history_id' => $conv['history_id'],
				'conversation_title' => $this->get_conversation_title($conv),
				'message_content' => substr($conv['message_content'], 0, 100) . '...',
				'message_type' => $conv['message_type'],
				'account_id' => $conv['account_id'],
				'created' => $conv['created'],
				'modified' => $conv['modified'] ?? $conv['created'],
				'tool_calls' => $conv['tool_calls'],
			);
			
			// Add user info
			if ($conv['account_id'])
			{
				$user = $GLOBALS['egw']->accounts->read($conv['account_id']);
				$row['account_fullname'] = $user ? $user['account_fullname'] : 'Unknown';
			}
			
			$rows[] = $row;
			
			// Set read-only status
			$readonlys[] = array();
		}
		
		return $total;
	}

	/**
	 * Search conversations
	 *
	 * @param array $criteria Search criteria
	 * @param int $start Start index
	 * @param int $limit Number of results
	 * @param string $order Order field
	 * @param string $sort Sort direction
	 * @return array
	 */
	public function search($criteria = array(), $start = 0, $limit = 25, $order = 'created', $sort = 'DESC')
	{
		return $this->so->search($criteria, $start, $limit, $order, $sort);
	}

	/**
	 * Read a single conversation record
	 *
	 * @param int $id History ID
	 * @return array|false
	 */
	public function read($id)
	{
		return $this->so->read($id);
	}

	/**
	 * Save conversation data
	 *
	 * @param array $data Conversation data
	 * @return int|false Record ID on success, false on failure
	 */
	public function save($data)
	{
		// Set timestamps
		if (empty($data['history_id']))
		{
			$data['created'] = time();
		}
		$data['modified'] = time();
		
		return $this->so->save($data);
	}

	/**
	 * Delete conversation(s)
	 *
	 * @param int|array $ids ID or array of IDs to delete
	 * @return bool
	 */
	public function delete($ids)
	{
		if (!is_array($ids))
		{
			$ids = array($ids);
		}
		
		$deleted = 0;
		foreach ($ids as $id)
		{
			if ($this->so->delete($id))
			{
				$deleted++;
			}
		}
		
		return $deleted;
	}

	/**
	 * Get total count of conversations
	 *
	 * @param array $criteria Search criteria
	 * @return int
	 */
	public function total($criteria = array())
	{
		return $this->so->total($criteria);
	}

	/**
	 * Get conversation title for display
	 *
	 * @param array $conversation Conversation data
	 * @return string
	 */
	private function get_conversation_title($conversation)
	{
		if (!empty($conversation['conversation_title']))
		{
			return $conversation['conversation_title'];
		}
		
		if (!empty($conversation['message_content']))
		{
			$content = strip_tags($conversation['message_content']);
			return strlen($content) > 50 ? substr($content, 0, 47) . '...' : $content;
		}
		
		return lang('AI Conversation') . ' #' . $conversation['history_id'];
	}

	/**
	 * Link registry methods for EGroupware integration
	 */

	/**
	 * Query for link registry
	 *
	 * @param string $pattern Search pattern
	 * @param array $options Search options
	 * @return array
	 */
	public function link_query($pattern, $options = array())
	{
		$criteria = array(
			'search' => $pattern,
		);
		
		$limit = $options['limit'] ?? 10;
		$conversations = $this->search($criteria, 0, $limit);
		
		$results = array();
		foreach ($conversations as $conv)
		{
			$results[$conv['history_id']] = $this->get_conversation_title($conv);
		}
		
		return $results;
	}

	/**
	 * Get title for link registry
	 *
	 * @param int $id Record ID
	 * @return string
	 */
	public function link_title($id)
	{
		$conversation = $this->read($id);
		return $conversation ? $this->get_conversation_title($conversation) : '';
	}

	/**
	 * Get titles for multiple records
	 *
	 * @param array $ids Array of record IDs
	 * @return array
	 */
	public function link_titles($ids)
	{
		$titles = array();
		foreach ($ids as $id)
		{
			$titles[$id] = $this->link_title($id);
		}
		return $titles;
	}

	/**
	 * File access check for attachments
	 *
	 * @param int $id Record ID
	 * @param int $check Check type
	 * @param string $rel_path Relative path
	 * @param int $user User ID
	 * @return bool
	 */
	public function file_access($id, $check, $rel_path, $user = null)
	{
		// Check if user has access to this conversation
		$conversation = $this->read($id);
		if (!$conversation) return false;
		
		// Only allow access to own conversations (unless admin)
		$current_user = $GLOBALS['egw_info']['user']['account_id'];
		if ($conversation['account_id'] != $current_user && 
			!$GLOBALS['egw']->acl->check('admin', 1, 'aiassistant'))
		{
			return false;
		}
		
		return true;
	}

	/**
	 * Notification method
	 *
	 * @param array $data Event data
	 * @return bool
	 */
	public function notify($data)
	{
		// Handle notifications for AI assistant events
		// This could be extended to send notifications when AI completes tasks
		return true;
	}

	/**
	 * Get user statistics
	 *
	 * @param int $limit Number of users to return
	 * @return array
	 */
	public function get_user_statistics($limit = 10)
	{
		return $this->so->get_user_statistics($limit);
	}

	/**
	 * Get tool usage statistics
	 *
	 * @return array
	 */
	public function get_tool_statistics()
	{
		return $this->so->get_tool_statistics();
	}

	/**
	 * Get average response time
	 *
	 * @return float
	 */
	public function get_average_response_time()
	{
		return $this->so->get_average_response_time();
	}

	/**
	 * Get token usage for a time period
	 *
	 * @param int $start_time Start timestamp
	 * @param int $end_time End timestamp
	 * @return int
	 */
	public function get_token_usage($start_time, $end_time)
	{
		return $this->so->get_token_usage($start_time, $end_time);
	}

	/**
	 * Generate a conversation title from the first message
	 *
	 * @param string $message First message content
	 * @return string Generated title
	 */
	private function generate_conversation_title($message)
	{
		// Take first 50 characters and clean up
		$title = strip_tags($message);
		$title = substr($title, 0, 50);
		
		// Remove trailing incomplete words
		$last_space = strrpos($title, ' ');
		if ($last_space !== false && $last_space > 20)
		{
			$title = substr($title, 0, $last_space);
		}
		
		return $title . (strlen($message) > 50 ? '...' : '');
	}
}