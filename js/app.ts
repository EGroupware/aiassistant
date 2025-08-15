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
import {Et2InputWidgetInterface} from "../../api/js/etemplate/Et2InputWidget/Et2InputWidget";

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

		if (name === 'aiassistant.index') {
			this.init_ai_assistant();
		}
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
			
			// Add tool calls if any
			if (data.response.tool_calls && data.response.tool_calls.length > 0) {
				for (var i = 0; i < data.response.tool_calls.length; i++) {
					this.add_tool_call(data.response.tool_calls[i]);
				}
			}
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
	add_message(content: string, type: 'user' | 'assistant' | 'error' | 'tool', timestamp?: string, tool_name?: string, tool_args?: any)
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

		if (type === 'tool') {
			// Special formatting for tool messages
			messageContent.className = 'aiassistant_tool_message';
			messageContent.innerHTML = `
				<div class="aiassistant_tool_header">
					<span class="aiassistant_tool_icon">🔧</span>
					<span>Using ${tool_name || 'tool'}</span>
				</div>
				<div>${this.format_message_content(content)}</div>
				${tool_args ? `<details class="aiassistant_tool_details">
					<summary>Details</summary>
					<pre class="aiassistant_tool_args">${JSON.stringify(tool_args, null, 2)}</pre>
				</details>` : ''}
			`;
		} else {
			messageContent.innerHTML = this.format_message_content(content);
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
		avatarDiv.className = 'aiassistant_avatar';
		avatarDiv.style.background = 'linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%)';
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
		summary.style.cursor = 'pointer';
		summary.style.color = '#667eea';
		
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
			resultDiv.style.marginTop = '12px';
			resultDiv.style.padding = '12px';
			resultDiv.style.borderRadius = '8px';
			
			if (tool_call.result.success) {
				resultDiv.style.background = 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)';
				resultDiv.style.border = '1px solid #b8dacc';
				resultDiv.style.color = '#155724';
				resultDiv.innerHTML = `<strong>✅ Success:</strong> ${tool_call.result.message || 'Operation completed'}`;
			} else {
				resultDiv.style.background = 'linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%)';
				resultDiv.style.border = '1px solid #f1b0b7';
				resultDiv.style.color = '#721c24';
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
		bubbleDiv.className = 'aiassistant_message_bubble';
		bubbleDiv.style.padding = '16px 20px';
		
		var dotsDiv = document.createElement('div');
		dotsDiv.className = 'aiassistant_typing_dots';
		dotsDiv.innerHTML = '<span></span><span></span><span></span>';
		
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
		this.add_message('❌ Sorry, I encountered an error: ' + error, 'error');
		
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
			this.add_message(entry.message_type, entry.message_content);
			
			if (entry.tool_calls) {
				try {
					var toolCalls = typeof entry.tool_calls === 'string' 
						? JSON.parse(entry.tool_calls) 
						: entry.tool_calls;
					
					if (Array.isArray(toolCalls)) {
						for (var j = 0; j < toolCalls.length; j++) {
							this.add_tool_call(toolCalls[j]);
						}
					}
				} catch (e) {
					console.warn('Failed to parse tool calls:', e);
				}
			}
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
			// Plain text box, not sure what we do here.  Might be the same, might be different.
			return [];
		}
		else if(widget.getType && widget.getType() == "htmlarea")
		{
			// Widget is RTEditor, give it what it wants for toolbar
			return this._getHtmlAreaPrompts(widget);
		}
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
				text: 'Make me sound smart',
				onAction: (action) => this.handleTextboxPrompt('aiassist.help', widget)
			},
			{
				type: 'menuitem',
				text: 'Make safe to send to client',
				onAction: (action) => this.handleTextboxPrompt('aiassist.safe_for_client', widget)
			},
			{
				type: 'nestedmenuitem',
				text: 'Translate',
				getSubmenuItems: () =>
				{
					// This should come from getInstalledLanguages or \Translation::list_langs()
					return [{
						type: 'menuitem',
						text: 'English',
						onAction: () => {this.handleTextboxPrompt('aiassist.translate-en', widget)}
					}];
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
	handleTextboxPrompt(promptID : string, widget : et2_htmlarea | Et2InputWidgetInterface)
	{
		console.log(`Predefined prompt called: ${promptID} with input ` + widget.get_value(), widget);
		debugger;

		// AI Call - this is where we would call the AI Assistant
		const value = widget.get_value();
		widget.set_value("AI Assistant is thinking...");
		window.setTimeout(() =>
		{
			widget.set_value("<p>AI Assistant says:</p>\n" + value);
		}, 2000);
	}
}

// Register the app with EGroupware
app.classes.aiassistant = AIAssistantApp;