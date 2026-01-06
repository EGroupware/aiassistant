/**
 * EGroupware AI Assistant - Client-side TypeScript
 * 
 * @package aiassistant
 */

import {EgwApp} from '../../api/js/jsapi/egw_app';
import {Et2Dialog} from "../../api/js/etemplate/Et2Dialog/Et2Dialog";
import {app, egw} from "../../api/js/jsapi/egw_global";
import {Et2Textarea} from "../../api/js/etemplate/Et2Textarea/Et2Textarea";
import {et2_htmlarea} from "../../api/js/etemplate/et2_widget_htmlarea";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2Template} from "../../api/js/etemplate/Et2Template/Et2Template";

/**
 * UI for EGroupware AI Assistant application
 */
class AIAssistantApp extends EgwApp
{
	isLoading = false;

	/**
	 * This function is called when the etemplate2 object is loaded
	 */
	et2_ready(et2, name)
	{
		super.et2_ready(et2, name);

		switch (name)
		{
			case 'aiassistant.index':
				this.init_ai_assistant();
				break;
		}
	}

	/**
	 * AI model changed
	 *
	 * config runs as admin, not aiassistent, therefore this.et2 is never set.
	 *
	 * @param _ev
	 * @param _widget
	 */
	configModelChanged(_ev? : Event, _widget : Et2Select|Et2Template)
	{
		if (!this.et2) this.et2 = _widget.getRoot();
		const model = _ev.type === 'load' ? this.et2.getInputWidgetById('newsettings[ai_model]') : _widget;
		const custom_model = this.et2.getWidgetById('newsettings[ai_custom_model]');
		custom_model.hidden = model.value !== 'custom';
		custom_model.required = model.value === 'custom';
		const custom_url = this.et2.getWidgetById('newsettings[ai_api_url]');
		custom_url.required = model.value === 'custom';
	}
	
	/**
	 * Initialize the AI assistant interface
	 */
	init_ai_assistant()
	{
		// Set focus on input field
		var input = this.et2.getWidgetById('message_input');
		if (input) {
			input.focus();
		}
		
		// Load and display chat history
		this.load_chat_history();
		
		// Auto-scroll to bottom of messages
		this.scroll_to_bottom();
	}
	
	/**
	 * Handle keypress in message input
	 */
	handle_keypress(event)
	{
		if (event.key === 'Enter' && !event.shiftKey) { // Enter key without Shift
			event.preventDefault();
			this.send_message();
		}
	}
	
	/**
	 * Send a message to the AI assistant
	 */
	send_message()
	{
		if (this.isLoading) {
			return;
		}
		
		var input = this.et2.getWidgetById('message_input');
		if (!input) {
			console.error('Message input not found');
			return;
		}
		
		var message = input.get_value().trim();
		
		if (!message) {
			return;
		}
		
		// Clear input
		input.set_value('');
		
		// Add user message to display
		this.add_message('user', message);
		
		// Show loading state
		this.set_loading(true);
		
		// Send to server with proper parameter format
		egw.json('aiassistant.EGroupware\\AIAssistant\\Ui.ajax_api', [
			'send_message',
			message
		], this.handle_response.bind(this), this, true, this).sendRequest();
	}
	
	/**
	 * Handle response from server
	 */
	handle_response(data)
	{
		this.set_loading(false);
		
		if (data.error) {
			this.show_error(data.error);
			return;
		}
		
		if (data.response) {
			// Add assistant response
			this.add_message('assistant', data.response.content);
			
			// Note: We don't display tool calls to keep the interface clean
			// The AI should include the tool results directly in its response content
		}
	}
	
	/**
	 * Format message content for display
	 */
	format_message_content(content: string): string
	{
		if (!content) return '';
		
		// Convert newlines to HTML breaks
		content = content.replace(/\n/g, '<br>');
		
		// Basic markdown-like formatting
		content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
		content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
		content = content.replace(/`(.*?)`/g, '<code>$1</code>');
		
		// Convert URLs to links
		content = content.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>');
		
		return content;
	}

	/**
	 * Add a message to the chat interface
	 */
	add_message(type: 'user' | 'assistant' | 'error' | 'tool', content: string, timestamp?: string, tool_name?: string, tool_args?: any)
	{
		const messagesContainer = this.et2.getWidgetById('messages_container');
		if (!messagesContainer) return;

		// Remove empty state if it exists
		const emptyState = messagesContainer.getDOMNode().querySelector('.aiassistant_empty_state');
		if (emptyState) {
			emptyState.remove();
		}

		// Create message element
		const messageDiv = document.createElement('div');
		messageDiv.className = `aiassistant_message ${type}`;

		// Create avatar
		const avatar = document.createElement('div');
		avatar.className = 'aiassistant_avatar';
		avatar.textContent = type === 'user' ? 'U' : (type === 'assistant' ? 'AI' : '🔧');

		// Create message content
		const messageContent = document.createElement('div');
		messageContent.className = 'aiassistant_message_content';

		// Debug - log what we're trying to display
		console.log('Adding message:', type, content);

		if (type === 'tool') {
			// Special formatting for tool messages
			messageContent.className = 'aiassistant_tool_message';
			messageContent.innerHTML = `
				<div class="aiassistant_tool_header">
					<span class="aiassistant_tool_icon">🔧</span>
					<span>Using ${tool_name || 'tool'}</span>
				</div>
				<div class="aiassistant_text_content">${this.format_message_content(content)}</div>
				${tool_args ? `<details class="aiassistant_tool_details">
					<summary>Details</summary>
					<pre class="aiassistant_tool_args">${JSON.stringify(tool_args, null, 2)}</pre>
				</details>` : ''}
			`;
		} else {
			// Wrap content in a div with special class for visibility
			messageContent.innerHTML = `<div class="aiassistant_text_content">${this.format_message_content(content)}</div>`;
		}

		// Add timestamp
		if (timestamp) {
			const timeDiv = document.createElement('div');
			timeDiv.className = 'aiassistant_message_time';
			timeDiv.textContent = new Date(timestamp).toLocaleTimeString();
			messageContent.appendChild(timeDiv);
		}

		// Append elements
		if (type === 'user') {
			messageDiv.appendChild(messageContent);
			messageDiv.appendChild(avatar);
		} else {
			messageDiv.appendChild(avatar);
			messageDiv.appendChild(messageContent);
		}

		messagesContainer.getDOMNode().appendChild(messageDiv);
		this.scroll_to_bottom();
	}	/**
	 * Add a tool call display
	 */
	add_tool_call(tool_call)
	{
		var container = this.et2.getWidgetById('messages_container');
		if (!container) return;
		
		var containerNode = container.getDOMNode();
		
		var toolDiv = document.createElement('div');
		toolDiv.className = 'aiassistant_message tool';
		
	var avatarDiv = document.createElement('div');
	avatarDiv.className = 'aiassistant_avatar tool';
	avatarDiv.textContent = '🔧';
		
		var toolContent = document.createElement('div');
		toolContent.className = 'aiassistant_message_bubble aiassistant_tool_bubble';
		
		var toolHeader = document.createElement('div');
		toolHeader.className = 'aiassistant_tool_header';
		toolHeader.innerHTML = `<span class="aiassistant_tool_icon">🛠️</span> <strong>${tool_call.function.name}</strong>`;
		
		var toolArgs = document.createElement('details');
		toolArgs.className = 'aiassistant_tool_details';
		
	var summary = document.createElement('summary');
	summary.textContent = 'View Parameters';
		
		var argsContent = document.createElement('pre');
		argsContent.className = 'aiassistant_tool_args';
		argsContent.textContent = JSON.stringify(tool_call.function.arguments, null, 2);
		
		toolArgs.appendChild(summary);
		toolArgs.appendChild(argsContent);
		
		toolContent.appendChild(toolHeader);
		toolContent.appendChild(toolArgs);
		
		if (tool_call.result) {
			var resultDiv = document.createElement('div');
			resultDiv.className = 'aiassistant_tool_result';

			if (tool_call.result.success) {
				resultDiv.classList.add('success');
				resultDiv.innerHTML = `<strong>✅ Success:</strong> ${tool_call.result.message || 'Operation completed'}`;
			} else {
				resultDiv.classList.add('error');
				resultDiv.innerHTML = `<strong>❌ Error:</strong> ${tool_call.result.error || 'Operation failed'}`;
			}
			
			toolContent.appendChild(resultDiv);
		}
		
		toolDiv.appendChild(avatarDiv);
		toolDiv.appendChild(toolContent);
		
		containerNode.appendChild(toolDiv);
		
		this.scroll_to_bottom();
	}
	
	/**
	 * Set loading state
	 */
	set_loading(loading)
	{
		this.isLoading = loading;
		
		var sendButton = this.et2.getWidgetById('send_button');
		var statusMessage = this.et2.getWidgetById('status_message');
		
		if (sendButton) {
			sendButton.set_disabled(loading);
		}
		
		if (statusMessage) {
			if (loading) {
				statusMessage.set_value('🤔 AI is thinking...');
				statusMessage.set_class('aiassistant_status loading');
			} else {
				statusMessage.set_value('✅ Ready');
				statusMessage.set_class('aiassistant_status connected');
			}
		}
		
		// Add/remove typing indicator
		if (loading) {
			this.add_typing_indicator();
		} else {
			this.remove_typing_indicator();
		}
	}
	
	/**
	 * Add typing indicator
	 */
	add_typing_indicator()
	{
		var container = this.et2.getWidgetById('messages_container');
		if (!container) return;
		
		var containerNode = container.getDOMNode();
		
		// Remove existing typing indicator
		var existing = containerNode.querySelector('.aiassistant_typing_indicator');
		if (existing) {
			existing.remove();
		}
		
		// Create typing indicator
		var typingDiv = document.createElement('div');
		typingDiv.className = 'aiassistant_message assistant aiassistant_typing_indicator';
		
		var avatarDiv = document.createElement('div');
		avatarDiv.className = 'aiassistant_avatar';
		avatarDiv.textContent = 'AI';
		
	var bubbleDiv = document.createElement('div');
	bubbleDiv.className = 'aiassistant_message_bubble aiassistant_typing_bubble';
		
		var dotsDiv = document.createElement('div');
	dotsDiv.className = 'aiassistant_typing_dots';
	dotsDiv.innerHTML = '<span class="aiassistant_typing_dot"></span><span class="aiassistant_typing_dot"></span><span class="aiassistant_typing_dot"></span>';
		
		bubbleDiv.appendChild(dotsDiv);
		typingDiv.appendChild(avatarDiv);
		typingDiv.appendChild(bubbleDiv);
		
		containerNode.appendChild(typingDiv);
		this.scroll_to_bottom();
	}
	
	/**
	 * Remove typing indicator
	 */
	remove_typing_indicator()
	{
		var container = this.et2.getWidgetById('messages_container');
		if (!container) return;
		
		var containerNode = container.getDOMNode();
		var indicator = containerNode.querySelector('.aiassistant_typing_indicator');
		if (indicator) {
			indicator.remove();
		}
	}
	
	/**
	 * Show error message
	 */
	show_error(error)
	{
		egw.message(error, 'error');
		this.add_message('error', '❌ Sorry, I encountered an error: ' + error);
		
		var statusMessage = this.et2.getWidgetById('status_message');
		if (statusMessage) {
			statusMessage.set_value('❌ Error occurred');
			statusMessage.set_class('aiassistant_status disconnected');
		}
	}
	
	/**
	 * Load chat history from server
	 */
	load_chat_history()
	{
		egw.json('aiassistant.EGroupware\\AIAssistant\\Ui.ajax_api', [
			'get_history'
		], this.display_history.bind(this), this, true, this).sendRequest();
	}
	
	/**
	 * Display chat history
	 */
	display_history(data)
	{
		if (!data || !data.history || data.history.length === 0) {
			return; // Keep empty state
		}
		
		// Clear empty state first
		var container = this.et2.getWidgetById('messages_container');
		if (container) {
			var containerNode = container.getDOMNode();
			var emptyState = containerNode.querySelector('.aiassistant_empty_state');
			if (emptyState) {
				emptyState.remove();
			}
		}
		
		for (var i = 0; i < data.history.length; i++) {
			var entry = data.history[i];
			// Handle the message_type and message_content in the right order
			this.add_message(entry.message_type, entry.message_content);
			
			// Note: We don't display tool calls to keep the interface clean
			// The AI should include the tool results directly in its response content
		}
	}
	
	/**
	 * Clear chat history
	 */
	clear_history()
	{
		Et2Dialog.show_dialog(
			(button) => {
				if (button === Et2Dialog.YES_BUTTON) {
					this.do_clear_history();
				}
			},
			'Are you sure you want to clear your chat history? This action cannot be undone.',
			'Clear Chat History',
			{},
			Et2Dialog.BUTTONS_YES_NO,
			Et2Dialog.QUESTION_MESSAGE
		);
	}
	
	/**
	 * Actually clear the history
	 */
	do_clear_history()
	{
		egw.json('aiassistant.EGroupware\\AIAssistant\\Ui.ajax_api', [
			'clear_history'
		], this.handle_clear_response.bind(this), this, true, this).sendRequest();
	}
	
	/**
	 * Handle clear history response
	 */
	handle_clear_response(data)
	{
		if (data.success) {
			// Clear the display
			var container = this.et2.getWidgetById('messages_container');
			if (container) {
				var containerNode = container.getDOMNode();
				
				// Clear all messages
				while (containerNode.firstChild) {
					containerNode.removeChild(containerNode.firstChild);
				}
				
				// Add empty state back
				var emptyStateDiv = document.createElement('div');
				emptyStateDiv.className = 'aiassistant_empty_state';
				
				var iconDiv = document.createElement('div');
				iconDiv.className = 'aiassistant_empty_icon';
				iconDiv.textContent = '🤖';
				
				var titleDiv = document.createElement('div');
				titleDiv.className = 'aiassistant_empty_title';
				titleDiv.textContent = 'Welcome to AI Assistant';
				
				var textDiv = document.createElement('div');
				textDiv.className = 'aiassistant_empty_text';
				textDiv.textContent = 'I can help you manage your EGroupware data. Ask me to create contacts, schedule calendar events, search information, send emails, or manage tasks. Just type your request below!';
				
				emptyStateDiv.appendChild(iconDiv);
				emptyStateDiv.appendChild(titleDiv);
				emptyStateDiv.appendChild(textDiv);
				
				containerNode.appendChild(emptyStateDiv);
			}
			
			egw.message('Chat history cleared successfully', 'success');
		} else {
			egw.message('Failed to clear chat history: ' + (data.error || 'Unknown error'), 'error');
		}
	}
	
	/**
	 * Scroll messages container to bottom
	 */
	scroll_to_bottom()
	{
		setTimeout(() => {
			var container = this.et2.getWidgetById('messages_container');
			if (container) {
				var containerNode = container.getDOMNode();
				containerNode.scrollTop = containerNode.scrollHeight;
			}
		}, 100);
	}

	getTextareaPromptList(widget? : Et2Textarea | et2_htmlarea)
	{
		if(widget instanceof Et2Textarea)
		{
			// Plain text box - return simplified menu options
			return this._getTextAreaPrompts(widget);
		}
		else if(widget.getType && widget.getType() == "htmlarea")
		{
			// Widget is RTEditor, give it what it wants for toolbar
			return this._getHtmlAreaPrompts(widget);
		}
		
		return [];
	}
	
	/**
	 * Get prompts for plain textarea elements
	 */
	_getTextAreaPrompts(widget : Et2Textarea)
	{
		// For plain text areas, we return a simpler set of options
		// These could be used in a context menu or button dropdown
		return [
			{
				id: 'aiassist.summarize',
				label: 'Summarize text',
				action: () => this.handleTextboxPrompt('aiassist.summarize', widget)
			},
			{
				id: 'aiassist.formal',
				label: 'Make more formal',
				action: () => this.handleTextboxPrompt('aiassist.formal', widget)
			},
			{
				id: 'aiassist.grammar',
				label: 'Fix grammar & spelling',
				action: () => this.handleTextboxPrompt('aiassist.grammar', widget)
			},
			{
				id: 'aiassist.concise',
				label: 'Make concise',
				action: () => this.handleTextboxPrompt('aiassist.concise', widget)
			}
		];
	}

	/**
	 * Get the list of pre-configured prompts we allow on textarea / htmlarea elements formatted to be displayed in the htmlarea toolbar
	 *
	 * @see https://www.tiny.cloud/docs/tinymce/latest/custom-toolbarbuttons/
	 *
	 * @param {et2_htmlarea} widget
	 * @return {({type : string, text : string, onAction : (action) => void} | {type : string, text : string, onAction : (action) => void} | {type : string, text : string, getSubmenuItems : () => [{type : string, text : string, onAction : () => void}]})[]}
	 */
	_getHtmlAreaPrompts(widget : et2_htmlarea)
	{
		// The toolbar needs the text to display, and the action to perform when clicked
		return [
			{
				type: 'menuitem',
				text: 'Summarize text',
				onAction: (action) => this.handleTextboxPrompt('aiassist.summarize', widget)
			},
			{
				type: 'menuitem',
				text: 'Make more formal',
				onAction: (action) => this.handleTextboxPrompt('aiassist.formal', widget)
			},
			{
				type: 'menuitem',
				text: 'Make more casual',
				onAction: (action) => this.handleTextboxPrompt('aiassist.casual', widget)
			},
			{
				type: 'menuitem',
				text: 'Fix grammar & spelling',
				onAction: (action) => this.handleTextboxPrompt('aiassist.grammar', widget)
			},
			{
				type: 'menuitem',
				text: 'Make concise',
				onAction: (action) => this.handleTextboxPrompt('aiassist.concise', widget)
			},
			{
				type: 'nestedmenuitem',
				text: 'Translate',
				getSubmenuItems: () =>
				{
					// This should come from getInstalledLanguages or \Translation::list_langs()
					return [
						{
							type: 'menuitem',
							text: 'English',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-en', widget)}
						},
						{
							type: 'menuitem',
							text: 'German',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-de', widget)}
						},
						{
							type: 'menuitem',
							text: 'French',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-fr', widget)}
						},
						{
							type: 'menuitem',
							text: 'Spanish',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-es', widget)}
						},
						{
							type: 'menuitem',
							text: 'Italian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-it', widget)}
						},
						{
							type: 'menuitem',
							text: 'Portuguese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-pt', widget)}
						},
						{
							type: 'menuitem',
							text: 'Dutch',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-nl', widget)}
						},
						{
							type: 'menuitem',
							text: 'Russian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ru', widget)}
						},
						{
							type: 'menuitem',
							text: 'Chinese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-zh', widget)}
						},
						{
							type: 'menuitem',
							text: 'Japanese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ja', widget)}
						},
						{
							type: 'menuitem',
							text: 'Korean',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ko', widget)}
						},
						{
							type: 'menuitem',
							text: 'Arabic',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ar', widget)}
						},
						{
							type: 'menuitem',
							text: 'Persian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-fa', widget)}
						}
					];
				}
			},
			{
				type: 'nestedmenuitem',
				text: 'Generate',
				getSubmenuItems: () =>
				{
					return [
						{
							type: 'menuitem',
							text: 'Professional reply',
							onAction: () => {this.handleTextboxPrompt('aiassist.generate_reply', widget)}
						},
						{
							type: 'menuitem',
							text: 'Meeting follow-up',
							onAction: () => {this.handleTextboxPrompt('aiassist.meeting_followup', widget)}
						},
						{
							type: 'menuitem',
							text: 'Thank you note',
							onAction: () => {this.handleTextboxPrompt('aiassist.thank_you', widget)}
						}
					];
				}
			}
		];
	}

	/**
	 * A widget has requested a predefined prompt be done to it
	 *
	 * @param promptID
	 * @param widget
	 */
	handleTextboxPrompt(promptID : string, widget : any)
	{
		console.log(`Predefined prompt called: ${promptID} with input ` + widget.get_value(), widget);
		
		const originalValue = widget.get_value();
		
		// Don't process if there's no content
		if (!originalValue || originalValue.trim() === '') {
			egw.message('Please enter some text first', 'info');
			return;
		}
		
		// Show loading state
		const loadingMessage = this.getLoadingMessage(promptID);
		widget.set_value(loadingMessage);
		
		// Make the AI call
		egw.json('aiassistant.EGroupware\\AIAssistant\\Ui.ajax_api', [
			'process_prompt',
			promptID,
			originalValue
		], (response) => {
			this.handlePromptResponse(response, widget, originalValue);
		}, this, true, this).sendRequest();
	}
	
	/**
	 * Get appropriate loading message based on prompt type
	 */
	getLoadingMessage(promptID: string): string
	{
		const loadingMessages = {
			'aiassist.summarize': 'Summarizing your text...',
			'aiassist.formal': 'Making text more formal...',
			'aiassist.casual': 'Making text more casual...',
			'aiassist.grammar': 'Checking grammar and spelling...',
			'aiassist.concise': 'Making text more concise...',
			'aiassist.generate_reply': 'Generating professional reply...',
			'aiassist.meeting_followup': 'Creating meeting follow-up...',
			'aiassist.thank_you': 'Composing thank you note...'
		};
		
		// Handle translation prompts
		if (promptID.startsWith('aiassist.translate-')) {
			const langCode = promptID.split('-')[1];
			const langNames = {
				'en': 'English',
				'de': 'German', 
				'fr': 'French',
				'es': 'Spanish',
				'it': 'Italian',
				'pt': 'Portuguese',
				'nl': 'Dutch',
				'ru': 'Russian',
				'zh': 'Chinese',
				'ja': 'Japanese',
				'ko': 'Korean',
				'ar': 'Arabic',
				'fa': 'Persian'
			};
			const langName = langNames[langCode] || langCode.toUpperCase();
			return `🌐 Translating to ${langName}...`;
		}
		
		return loadingMessages[promptID] || '🤖 AI is processing...';
	}
	
	/**
	 * Handle the response from AI prompt processing
	 */
	handlePromptResponse(response: any, widget: any, originalValue: string)
	{
		if (response.error) {
			// Restore original content on error
			widget.set_value(originalValue);
			egw.message('AI processing failed: ' + response.error, 'error');
			return;
		}
		
		if (response.success && response.result) {
			// Set the AI-processed content
			widget.set_value(response.result);
			
			// Show success message
			egw.message('Text processed successfully', 'success');
		} else {
			// Restore original content if no result
			widget.set_value(originalValue);
			egw.message('No result received from AI', 'warning');
		}
	}

	/**
	 * Static method for other apps to easily add AI assistance to their text widgets
	 * Usage: app.aiassistant.addAIButtonToWidget(widget, ['summarize', 'formal', 'grammar']);
	 */
	static addAIButtonToWidget(widget: any, promptTypes: string[] = ['summarize', 'formal', 'grammar'])
	{
		if (!widget || !widget.getDOMNode) {
			console.warn('Invalid widget provided to addAIButtonToWidget');
			return;
		}

		// Create AI assistant button
		const aiButton = document.createElement('button');
		aiButton.className = 'et2_button aiassistant_widget_button';
		aiButton.innerHTML = '🤖 AI';
		aiButton.title = 'AI Assistant';
		aiButton.type = 'button';

		// Create dropdown menu
		const aiMenu = document.createElement('div');
	aiMenu.className = 'aiassistant_widget_menu';

		// Add menu items based on promptTypes
		const menuItems = {
			'summarize': { label: 'Summarize text', icon: '📄' },
			'formal': { label: 'Make more formal', icon: '🎩' },
			'casual': { label: 'Make more casual', icon: '😊' },
			'grammar': { label: 'Fix grammar & spelling', icon: '✏️' },
			'concise': { label: 'Make concise', icon: '📝' },
			'safe_for_client': { label: 'Make client-safe', icon: '🛡️' },
			'translate_en': { label: 'Translate to English', icon: '🌐' }
		};

		promptTypes.forEach(promptType => {
			if (menuItems[promptType]) {
				const menuItem = document.createElement('div');
				menuItem.className = 'aiassistant_menu_item';
				menuItem.innerHTML = `${menuItems[promptType].icon} ${menuItems[promptType].label}`;
				
				menuItem.addEventListener('click', () => {
					aiMenu.style.display = 'none';
					if (app.aiassistant && (app.aiassistant as AIAssistantApp).handleTextboxPrompt) {
						(app.aiassistant as AIAssistantApp).handleTextboxPrompt('aiassist.' + promptType, widget);
					}
				});

				// Hover handled by CSS

				aiMenu.appendChild(menuItem);
			}
		});

		// Toggle menu on button click
		aiButton.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			
			if (aiMenu.style.display === 'none' || getComputedStyle(aiMenu).display === 'none') {
				// Position menu below button
				const rect = aiButton.getBoundingClientRect();
				aiMenu.style.left = rect.left + 'px';
				aiMenu.style.top = (rect.bottom + 2) + 'px';
				aiMenu.style.display = 'block';
			} else {
				aiMenu.style.display = 'none';
			}
		});

		// Close menu when clicking outside
		document.addEventListener('click', () => {
			aiMenu.style.display = 'none';
		});

		// Insert button after the widget
		const widgetNode = widget.getDOMNode();
		if (widgetNode && widgetNode.parentNode) {
			const container = document.createElement('div');
			container.className = 'aiassistant_widget_container';
			
			widgetNode.parentNode.insertBefore(container, widgetNode.nextSibling);
			container.appendChild(aiButton);
			container.appendChild(aiMenu);
		}
	}
}

// Register the app with EGroupware
app.classes.aiassistant = AIAssistantApp;