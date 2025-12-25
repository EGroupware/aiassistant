<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package aiassistant
 * @subpackage setup
 */


function aiassistant_upgrade1_0_3()
{
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_ai_assistant_history','session_id',array(
		'type' => 'ascii',
		'precision' => '40',
		'nullable' => False,
		'comment' => 'sha1 of session_id'
	));
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_ai_assistant_history','created',array(
		'type' => 'timestamp',
		'nullable' => False
	));
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_ai_assistant_history','modified',array(
		'type' => 'timestamp',
		'meta' => 'account',
		'nullable' => False,
		'default' => 'current_timestamp'
	));

	return $GLOBALS['setup_info']['aiassistant']['currentver'] = '1.0.004';
}