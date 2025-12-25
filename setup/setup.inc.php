<?php
/**
 * EGroupware AI Chatbot - Setup Configuration
 * 
 * @package chatbot
 * @author EGroupware Team
 * @license GPL-3.0-or-later
 */

use EGroupware\AIAssistant\Hooks;
use EGroupware\AIAssistant\Bo;

$setup_info['aiassistant']['name']      = 'aiassistant';
$setup_info['aiassistant']['title']     = 'AI Assistant';
$setup_info['aiassistant']['version']   = '1.0.004';
$setup_info['aiassistant']['app_order'] = 60;
$setup_info['aiassistant']['enable']    = 5;    // do NOT show in navbar
$setup_info['aiassistant']['index']     = 'aiassistant.'.EGroupware\AIAssistant\Ui::class.'.index&ajax=true';

$setup_info['aiassistant']['author'] = array(
    'name'  => 'EGroupware Team',
    'email' => 'info@egroupware.org'
);

$setup_info['aiassistant']['license']  = 'GPL-3.0-or-later';
$setup_info['aiassistant']['description'] = 'AI-powered assistant with Model Context Protocol (MCP) integration for EGroupware assistance';

$setup_info['aiassistant']['maintainer'] = array(
    'name'  => 'EGroupware Team',
    'email' => 'info@egroupware.org'
);

/* Dependencies for this app to work */
$setup_info['aiassistant']['depends'][] = array(
    'appname' => 'api',
    'versions' => Array('23.1')
);

/* Define the tables this app uses */
$setup_info['aiassistant']['tables'][] = 'egw_ai_assistant_history';

/* The hooks this app includes, needed for hooks registration */
$setup_info['aiassistant']['hooks']['admin'] = Hooks::class.'::all_hooks';
$setup_info['aiassistant']['hooks']['sidebox_menu'] = Hooks::class.'::all_hooks';
$setup_info['aiassistant']['hooks']['settings'] = Hooks::class.'::settings';
$setup_info['aiassistant']['hooks']['search_link'] = Hooks::class.'::search_link';
$setup_info['aiassistant']['hooks']['config'] = Hooks::class.'::config';
$setup_info['aiassistant']['hooks']['config_validate'] = Hooks::class.'::config_validate';
$setup_info['aiassistant']['hooks']['acl_rights'] = Hooks::class.'::acl_rights';
$setup_info['aiassistant']['hooks']['categories'] = Hooks::class.'::categories';
$setup_info['aiassistant']['hooks']['export_limit'] = Hooks::class.'::getAppExportLimit';
$setup_info['aiassistant']['hooks']['delete_category'] = 'aiassistant.'.Bo::class.'.delete_category';
