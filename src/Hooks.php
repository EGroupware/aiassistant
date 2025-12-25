<?php
/**
 * AI Assistant - diverse hooks: Admin-, Preferences- and SideboxMenu-Hooks
 *
 * @link http://www.egroupware.org
 * @package aiassistant
 * @copyright (c) 2025 EGroupware Team
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AIAssistant;

use EGroupware\Api;
use EGroupware\Api\Egw;
use EGroupware\Api\Acl;

if (!defined('AI_ASSISTANT_APP'))
{
	define('AI_ASSISTANT_APP','aiassistant');
}

/**
 * diverse hooks as static methods
 */
class Hooks
{
	/**
	 * Instance of AI Assistant business object
	 *
	 * @var Bo
	 */
	static $bo;

	/**
	 * Hook called by link-class to include AI Assistant in the appregistry of the linkage
	 *
	 * @param array/string $location location and other parameters (not used)
	 * @return array with method-names
	 */
	static function search_link($location)
	{
		unset($location);	// not used, but required by function signature

		return array(
			'query' => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Bo.link_query',
			'title' => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Bo.link_title',
			'titles'=> AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Bo.link_titles',
			'view'  => array(
				'menuaction' => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Ui.index',
			),
			'view_id' => 'history_id',
			'view_popup'  => '800x600',
			'edit_popup'  => '800x600',
			'list' => array(
				'menuaction' => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Ui.index',
				'ajax' => 'true'
			),
			'add' => array(
				'menuaction' => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Ui.index',
			),
			'add_app'	=> 'link_app',
			'add_id'	 => 'link_id',
			'add_popup'  => '800x600',
			'notify'	 => AI_ASSISTANT_APP.'.EGroupware\\AIAssistant\\Bo.notify',
			'merge'	  => false,
		);
	}

	/**
	 * hooks to build AI Assistant's sidebox-menu plus the admin and Api\Preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function all_hooks($args)
	{
		$appname = AI_ASSISTANT_APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'sidebox_menu')
		{
			$file = array(
				'New Chat' => Egw::link('/index.php',array(
					'menuaction' => 'aiassistant.EGroupware\\AIAssistant\\Ui.index',
					'ajax' => 'true')),
				array(
					'text' => lang('Chat History'),
					'no_lang' => true,
					'link' => Egw::link('/index.php',array(
						'menuaction' => 'aiassistant.EGroupware\\AIAssistant\\Ui.index',
						'ajax' => 'true',
						'view' => 'history'
					))
				),
				array(
					'text' => lang('Configuration'),
					'no_lang' => true,
					'link' => Egw::link('/index.php',array(
						'menuaction' => 'aiassistant.EGroupware\\AIAssistant\\Ui.edit',
						'ajax' => 'true'
					))
				),
			);
			
			// Add separator
			$file[] = ['text'=>'--'];
			
			// Add admin functions for admins
			if ($GLOBALS['egw_info']['user']['apps']['admin'])
			{
				$file['Global Settings'] = Egw::link('/index.php','menuaction=admin.admin_ui.index&load=aiassistant');
			}
			
			// Add chat history management
			$file['Clear History'] = "javascript:if(confirm('".lang('Clear all chat history?')."')){egw.json('aiassistant.EGroupware\\\\AIAssistant\\\\Ui.ajax_api',{action:'clear_history'}).sendRequest();}";
			
			display_sidebox($appname, lang('AI Assistant'), $file);
		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname.'&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname, $file);
			}
			else
			{
				display_sidebox($appname, lang('Admin'), $file);
			}
		}
	}

	/**
	 * populates $GLOBALS['settings'] for the Api\Preferences
	 */
	static function settings()
	{
		$settings = [
			'ai_model_preference' => [
				'type'   => 'select',
				'label'  => 'Preferred AI Model',
				'name'   => 'ai_model_preference',
				'values' => [
					'gpt-4o-mini' => 'GPT-4o Mini (Fast)',
					'gpt-4o' => 'GPT-4o (Advanced)',
					'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Legacy)',
				],
				'help'   => 'Select your preferred AI model for conversations',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => 'gpt-4o-mini',
			],
			'chat_history_limit' => [
				'type'   => 'select',
				'label'  => 'Chat History Limit',
				'name'   => 'chat_history_limit',
				'values' => [
					'10' => '10 messages',
					'25' => '25 messages',
					'50' => '50 messages',
					'100' => '100 messages',
					'unlimited' => 'Unlimited',
				],
				'help'   => 'Maximum number of chat messages to keep in history',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => '50',
			],
			'enable_tool_execution' => [
				'type'   => 'check',
				'label'  => 'Enable Tool Execution',
				'name'   => 'enable_tool_execution',
				'help'   => 'Allow AI to execute actions like creating contacts, calendar events, etc.',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => '1',
			],
			'auto_save_conversations' => [
				'type'   => 'check',
				'label'  => 'Auto-save Conversations',
				'name'   => 'auto_save_conversations',
				'help'   => 'Automatically save chat conversations for future reference',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => '1',
			],
			'enable_notifications' => [
				'type'   => 'check',
				'label'  => 'Enable AI Notifications',
				'name'   => 'enable_notifications',
				'help'   => 'Get notified when AI completes long-running tasks',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => '1',
			],
			'response_format' => [
				'type'   => 'select',
				'label'  => 'Response Format',
				'name'   => 'response_format',
				'values' => [
					'conversational' => 'Conversational',
					'detailed' => 'Detailed with explanations',
					'concise' => 'Brief and to the point',
				],
				'help'   => 'How detailed should AI responses be',
				'xmlrpc' => True,
				'admin'  => False,
				'default' => 'conversational',
			],
		];

		return $settings;
	}

	/**
	 * ACL rights and labels used by AI Assistant
	 *
	 * @param string|array $params string with location or array with parameters incl. "location", specially "owner" for selected acl owner
	 */
	public static function acl_rights($params)
	{
		unset($params);	// not used, but required by function signature

		return array(
			Acl::READ	=> 'read',
			Acl::EDIT	=> 'edit',
			Acl::DELETE  => 'delete',
			Acl::CUSTOM1 => 'admin_access', // Custom ACL for admin features
		);
	}

	/**
	 * Hook to tell framework we use standard categories method
	 *
	 * @param string|array $data hook-data or location
	 * @return boolean
	 */
	public static function categories($data)
	{
		unset($data);	// not used, but required by function signature

		return true;
	}

	/**
	 * Hook called for config values
	 *
	 * @param array $data
	 * @return array with config values or sel_options
	 */
	public static function config($data)
	{
		// Load current configuration values from standard EGroupware config
		$config = \EGroupware\Api\Config::read('aiassistant');

		// Return select options for dropdowns and current/default values
		return array(
			'sel_options' => array(
				'ai_model' => array(
					'egroupware:openai/gpt-oss-120b' => 'EGroupware/IONOS OpenAI GPT 4 120B',
					'egroupware:meta-llama/Llama-3.3-70B-Instruct' => 'EGroupware/IONOS Meta Llama 3.3 70B',
					'egroupware:mistralai/Mistral-Small-24B-Instruct' => 'EGroupware/IONOS Mistral Small 24B',
					'ollama:mistral-small3.1:24b-instruct-2503-q4_K_M' => 'Ollama Mistral 3.1 Small (24b-instruct-2503-q4_K_M)',
					'openai:gpt-4o' => 'OpenAI GPT-4o',
					'openai:gpt-4o-mini' => 'OpenAI GPT-4o Mini',
					'openai:gpt-4-turbo' => 'OpenAI GPT-4 Turbo',
					'openai:gpt-3.5-turbo' => 'OpenAI GPT-3.5 Turbo',
					'anthropic:claude-3-5-sonnet-20241022' => 'Anthropic Claude 3.5 Sonnet',
					'anthropic:claude-3-5-haiku-20241022' => 'Anthropic Claude 3.5 Haiku',
					'anthropic:claude-3-opus-20240229' => 'Anthropic Claude 3 Opus',
					'google:gemini-1.5-pro' => 'Google Gemini 1.5 Pro',
					'google:gemini-1.5-flash' => 'Google Gemini 1.5 Flash',
					'azure:gpt-4o' => 'Azure OpenAI GPT-4o',
					'azure:gpt-4o-mini' => 'Azure OpenAI GPT-4o Mini',
				)
			),
			'default_values' => array_merge([
				'ai_model' => 'openai:gpt-4o-mini',
				'ai_api_url' => 'https://api.openai.com/v1',
				'max_history_length' => 100,
			], $config)
		);
	}

	/**
	 * Hook called for config validation
	 *
	 * @param array $data
	 */
	public static function config_validate($data)
	{
		// Auto-populate API URL based on selected model
		if (!empty($data['ai_model'])) {
			[$provider] = explode(':', $data['ai_model']);
			$urlMapping = self::getModelUrlMapping();
			
			if (isset($urlMapping[$provider]) && empty($data['ai_api_url'])) {
				$data['ai_api_url'] = $urlMapping[$provider];
			}
		}
		
		return $data;
	}

	/**
	 * Get URL mapping for different AI models
	 *
	 * @return array
	 */
	private static function getModelUrlMapping()
	{
		return array(
			'egroupware'=> 'https://ai.egroupware.org/v1',
			'ionos'     => 'https://openai.inference.de-txl.ionos.com/v1',
			'ollama'    => 'http://172.17.0.1:11434/v1',
			'openai'    => 'https://api.openai.com/v1',
			'anthropic' => 'https://api.anthropic.com/v1',
			'google'    => 'https://generativelanguage.googleapis.com/v1',
			'azure'     => 'https://models.inference.ai.azure.com',
		);
	}

	/**
	 * Hook for sidebox menu
	 *
	 * @param string|array $data hook-data or location
	 */
	public static function sidebox_menu($data)
	{
		self::all_hooks($data);
	}

	/**
	 * Hook for admin menu
	 *
	 * @param string|array $data hook-data or location
	 */
	public static function admin($data)
	{
		self::all_hooks($data);
	}

	/**
	 * Hook for preferences
	 *
	 * @param string|array $data hook-data or location
	 */
	public static function preferences($data)
	{
		unset($data);	// not used, but required by function signature
		
		return self::settings();
	}

	/**
	 * Export limit hook
	 *
	 * @return int export limit
	 */
	static function getAppExportLimit()
	{
		return (int)$GLOBALS['egw_info']['server']['export_limit'] ?: 1000;
	}
}