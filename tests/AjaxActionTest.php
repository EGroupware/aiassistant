<?php
/**
 * Test the ajax endpoint the AI Assistant history list's Delete action now calls
 *
 * @link https://www.egroupware.org
 * @package aiassistant
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AIAssistant;

use EGroupware\Api;
use EGroupware\Api\LoggedInTest;

require_once realpath(__DIR__ . '/../../api/tests/LoggedInTest.php');

/**
 * Ui::ajax_action() is a new endpoint, and Delete reaching a handler at all is new with it:
 * Ui::list() rebuilds $content from scratch on every call and never looks at
 * $content['nm']['action'], so the submit this replaces ran action() never.
 *
 * SETUP
 * Each test writes its own conversation rows straight through So and removes them again, so the
 * tests do not depend on an instance having any history.
 *
 * PASS CRITERIA
 * The rows really went away (read back through Bo), the response carries an egw.refresh call -
 * without which the list drops no rows - and "select all" acts on the filter the list last ran,
 * not on everything.
 */
class AjaxActionTest extends LoggedInTest
{
	/** @var Ui */
	protected $ui;
	/** @var Bo */
	protected $bo;
	/** @var int[] history_ids this test created */
	protected $ids = [];

	protected function setUp() : void
	{
		Api\Json\Response::get()->initResponseArray();
		$this->ui = new Ui();
		$this->bo = new Bo();
		Api\Cache::unsetSession('aiassistant', 'list');
	}

	protected function tearDown() : void
	{
		foreach($this->ids as $id)
		{
			$GLOBALS['egw']->db->delete(So::HISTORY_TABLE, ['history_id' => $id], __LINE__, __FILE__, 'aiassistant');
		}
		$this->ids = [];
		Api\Cache::unsetSession('aiassistant', 'list');
	}

	/**
	 * A real eTemplate request id, the way the browser sends one along - the endpoint refuses
	 * without it, see Nextmatch::validateExecId().  Writing to the request is what persists it.
	 */
	protected function execId() : string
	{
		$request = \EGroupware\Api\Etemplate\Request::read();
		$id = $request->id();
		$request->content = ['nm' => []];
		unset($request);
		return $id;
	}

	/**
	 * The egw.refresh call the response should carry, or null
	 */
	protected function refreshCall() : ?array
	{
		$response = Api\Json\Response::get();
		$prop = (new \ReflectionClass($response))->getProperty('responseArray');
		$prop->setAccessible(true);
		foreach((array)$prop->getValue($response) as $chunk)
		{
			$chunk = (array)$chunk;
			if (($chunk['type'] ?? null) === 'apply' && (($chunk['data']['func'] ?? null) === 'egw.refresh'))
			{
				return (array)$chunk['data']['parms'];
			}
		}
		return null;
	}

	/**
	 * One history row owned by the logged-in user
	 */
	protected function makeConversation(string $title) : int
	{
		$GLOBALS['egw']->db->insert(So::HISTORY_TABLE, [
			'account_id'         => $GLOBALS['egw_info']['user']['account_id'],
			'session_id'         => 'AjaxActionTest',
			'conversation_title' => $title,
			'message_content'    => 'created by aiassistant/tests/AjaxActionTest.php',
			'message_type'       => 'user',
			'created'            => time(),
		], false, __LINE__, __FILE__, 'aiassistant');

		return $this->ids[] = $GLOBALS['egw']->db->get_last_insert_id(So::HISTORY_TABLE, 'history_id');
	}

	protected function exists($id) : bool
	{
		return (bool)$this->bo->read($id);
	}

	/**
	 * The regression shape: the endpoint has to reach action()'s body and really delete.
	 */
	public function testDeleteRemovesTheConversation()
	{
		$id = $this->makeConversation('AjaxActionTest delete');
		$this->assertTrue($this->exists($id), 'fixture was not created');

		$this->ui->ajax_action($this->execId(), 'delete', [$id]);

		$this->assertFalse($this->exists($id), 'delete must remove the conversation');
		$this->assertNotNull($this->refreshCall(),
			'the endpoint must answer with egw.refresh, or the list drops no rows');
	}

	/**
	 * Without a valid exec id the endpoint must do nothing at all.
	 */
	public function testABogusExecIdDeletesNothing()
	{
		$id = $this->makeConversation('AjaxActionTest bogus exec id');

		$this->ui->ajax_action('aiassistant_nobody_not-a-real-request-id', 'delete', [$id]);

		$this->assertTrue($this->exists($id), 'a rejected request must not run the action');
		$this->assertNull($this->refreshCall(), 'and must not answer with egw.refresh either');
	}

	/**
	 * "Select all" expands from the criteria the list last ran, so it acts on what the user can
	 * see and not on their whole history.
	 */
	public function testSelectAllUsesTheCachedCriteria()
	{
		$match = $this->makeConversation('AjaxActionTest SELECTALL match');
		$control = $this->makeConversation('AjaxActionTest control');

		// what Bo::get_rows() caches when the list is searched for "SELECTALL"
		$rows = $readonlys = [];
		$query = ['search' => 'SELECTALL', 'start' => 0, 'num_rows' => 25];
		$this->bo->get_rows($query, $rows, $readonlys);

		$this->ui->ajax_action($this->execId(), 'delete', [], true);

		$this->assertFalse($this->exists($match), 'select all must delete what the search matched');
		$this->assertTrue($this->exists($control), 'and must not touch what it did not');
	}

	/**
	 * With nothing cached, "select all" has no way to know what the user was looking at - and an
	 * empty query would mean every conversation they own.
	 */
	public function testSelectAllRefusesWithoutACachedQuery()
	{
		$id = $this->makeConversation('AjaxActionTest no cache');
		Api\Cache::unsetSession('aiassistant', 'list');

		$this->ui->ajax_action($this->execId(), 'delete', [], true);

		$this->assertTrue($this->exists($id), 'nothing may be deleted without a cached query');
	}

	/**
	 * _targetapp must be a real app name: egw.refresh() resolves it before its msg-only
	 * early-return, and a name that is not an app throws in the kdots framework.
	 */
	public function testRefreshNamesTheAppInBothSlots()
	{
		$id = $this->makeConversation('AjaxActionTest refresh args');

		$this->ui->ajax_action($this->execId(), 'delete', [$id]);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertSame('aiassistant', $parms[1],
			'aiassistant sends no push, so it cannot use the msg-only sentinel');
		$this->assertSame('aiassistant', $parms[4], 'never the msg-only-push-refresh sentinel');
	}

	/**
	 * More than one row changed means no id at all: egw.refresh() takes a single id, and
	 * Et2Nextmatch.refresh(id, null) only defaults its type when that type is undefined - a
	 * literal null falls through and updates nothing.
	 */
	public function testAMultiRowDeleteAsksForAFullReload()
	{
		$first = $this->makeConversation('AjaxActionTest multi 1');
		$second = $this->makeConversation('AjaxActionTest multi 2');

		$this->ui->ajax_action($this->execId(), 'delete', [$first, $second]);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertNull($parms[2], 'no single id for a multi-row action');
		$this->assertNull($parms[3], 'and no type, so egw.refresh reloads the list');
	}

	/**
	 * The endpoint has to be named explicitly: the client falls back to
	 * "<app>.<app>_ui.ajax_action", which for a namespaced class is a menuaction that does not
	 * exist - the request 400s and the action is silently lost.
	 *
	 * The 'separator' entry is gone for a different reason: it had a caption and nothing to
	 * execute, so clicking it submitted the whole eTemplate. Groups already draw the line.
	 */
	public function testDeleteCarriesTheNamespacedMenuaction()
	{
		$actions = $this->ui->get_actions();

		$this->assertSame('javaScript:app.aiassistant.ajax_action', $actions['delete']['onExecute'] ?? null);
		$this->assertSame('aiassistant.EGroupware\\AIAssistant\\Ui.ajax_action',
			$actions['delete']['data']['menuaction'] ?? null);
		$this->assertArrayNotHasKey('separator', $actions,
			'a menu entry with nothing to execute falls through to a submit');
	}
}
