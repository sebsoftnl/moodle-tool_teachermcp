<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for MCP token authentication and connection capability caching.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\auth
 */
final class auth_test extends \advanced_testcase {
    public function test_revocation_rejects_foreign_and_missing_tokens_without_mutation(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $other = $generator->create_user();
        $generator->enrol_user($owner->id, $course->id, 'editingteacher');
        $generator->enrol_user($other->id, $course->id, 'editingteacher');
        $this->setUser($other);
        $this->assertFalse(has_capability('tool/teachermcp:manageall', \context_system::instance()));
        $token = auth::generate_token_record($owner->id, 'Owned revocation fixture', 1);
        $fields = 'id,userid,status,timemodified';
        $before = $DB->get_record('tool_teachermcp_tokens', ['id' => $token['id']], $fields, MUST_EXIST);
        $sink = $this->redirectEvents();
        $this->assertFalse(auth::revoke_token($token['id'], $other->id));
        $this->assertFalse(auth::revoke_token(0, $owner->id));
        $this->assertEquals($before, $DB->get_record('tool_teachermcp_tokens', ['id' => $token['id']], $fields, MUST_EXIST));
        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    public function test_manager_revokes_another_users_token_without_affecting_others(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $generator->enrol_user($owner->id, $course->id, 'editingteacher');
        $target = auth::generate_token_record($owner->id, 'Manager revocation target', 1);
        $survivor = auth::generate_token_record($owner->id, 'Unaffected connection', 1);
        $this->setAdminUser();
        $this->assertTrue(has_capability('tool/teachermcp:manageall', \context_system::instance()));
        $this->assertNotEquals($owner->id, $USER->id);
        $sink = $this->redirectEvents();
        $this->assertTrue(auth::revoke_token($target['id'], $USER->id));
        $this->assertSame(0, (int)$DB->get_field('tool_teachermcp_tokens', 'status', ['id' => $target['id']], MUST_EXIST));
        $this->assertSame(1, (int)$DB->get_field('tool_teachermcp_tokens', 'status', ['id' => $survivor['id']], MUST_EXIST));
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\tool_teachermcp\event\token_revoked::class, $event);
        $this->assertEquals($target['id'], $event->objectid);
        $this->assertEquals($owner->id, $event->relateduserid);
    }

    public function test_token_lifecycle(): void {
        global $DB;

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'editingteacher');
        auth::invalidate_user_connect_cache($user->id);

        // Generate a token record and verify that only its hash is persisted.
        $result = auth::generate_token_record($user->id, 'Test Agent', 90);
        $this->assertStringStartsWith('mcp_', $result['token']);
        $this->assertGreaterThan(0, $result['id']);

        $stored = $DB->get_record('tool_teachermcp_tokens', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $result['token']), $stored->tokenhash);
        $this->assertSame(1, (int)$stored->status);

        // Revocation changes the stored token status.
        $this->assertTrue(auth::revoke_token($result['id'], $user->id));
        $stored = $DB->get_record('tool_teachermcp_tokens', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame(0, (int)$stored->status);
    }

    public function test_blok6_user_can_connect_muc_cache_and_invalidation(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $user = $generator->create_user();
        $course = $generator->create_course();

        // 1. Initial check: user has no roles or capabilities -> false.
        $this->assertFalse(\tool_teachermcp\auth::user_can_connect($user->id));

        // Enrol user as editingteacher in course.
        $generator->enrol_user($user->id, $course->id, 'editingteacher');

        // Without cache invalidation, cache returns stale false.
        $cache = \cache::make('tool_teachermcp', 'user_connect_capability');
        $this->assertEquals(0, $cache->get($user->id));

        // 2. Invalidate cache via observer / API.
        \tool_teachermcp\auth::invalidate_user_connect_cache($user->id);
        $this->assertFalse($cache->get($user->id));

        // Now user_can_connect calculates freshly and returns true (and caches it).
        $this->assertTrue(\tool_teachermcp\auth::user_can_connect($user->id));
        $this->assertEquals(1, $cache->get($user->id));

        // 3. Trigger role_unassigned event and verify cache is cleared.
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $context = \context_course::instance($course->id);
        role_unassign($teacherrole->id, $user->id, $context->id);

        // Observer automatically purges or invalidates.
        \tool_teachermcp\auth::invalidate_user_connect_cache($user->id);
        $this->assertFalse(\tool_teachermcp\auth::user_can_connect($user->id));

        // 4. Test observer callbacks directly.
        \tool_teachermcp\auth::user_can_connect($user->id);
        $this->assertEquals(0, $cache->get($user->id));

        $roleassignevent = \core\event\role_assigned::create([
            'objectid' => $teacherrole->id,
            'context' => $context,
            'relateduserid' => $user->id,
            'other' => ['id' => 1, 'component' => ''],
        ]);
        \tool_teachermcp\observer::role_assigned($roleassignevent);
        $this->assertFalse($cache->get($user->id));

        $cache->set($user->id, 1);
        $roleunassignevent = \core\event\role_unassigned::create([
            'objectid' => $teacherrole->id,
            'context' => $context,
            'relateduserid' => $user->id,
            'other' => ['id' => 1, 'component' => ''],
        ]);
        \tool_teachermcp\observer::role_unassigned($roleunassignevent);
        $this->assertFalse($cache->get($user->id));

        $cache->set($user->id, 1);
        $capevent = \core\event\capability_assigned::create([
            'context' => $context,
            'objectid' => 0,
        ]);
        \tool_teachermcp\observer::capability_assigned($capevent);
        $this->assertFalse($cache->get($user->id));
    }

    public function test_cleanup_task(): void {
        global $DB;

        $this->resetAfterTest(true);

        $now = time();
        $user = $this->getDataGenerator()->create_user();

        // 1. Insert an expired OAuth code.
        $expiredcodeid = $DB->insert_record('tool_teachermcp_oauth_codes', (object)[
            'codehash' => hash('sha256', 'expiredcode'),
            'clientid' => 'test-client',
            'userid' => $user->id,
            'redirecturi' => 'https://example.com/cb',
            'timecreated' => $now - 3600,
            'timeexpires' => $now - 1800,
            'status' => 0,
        ]);

        // 2. Insert an active (unexpired) OAuth code.
        $activecodeid = $DB->insert_record('tool_teachermcp_oauth_codes', (object)[
            'codehash' => hash('sha256', 'activecode'),
            'clientid' => 'test-client',
            'userid' => $user->id,
            'redirecturi' => 'https://example.com/cb',
            'timecreated' => $now,
            'timeexpires' => $now + 600,
            'status' => 0,
        ]);

        // 3. Insert an active token that has expired.
        $expiredtokenid = $DB->insert_record('tool_teachermcp_tokens', (object)[
            'userid' => $user->id,
            'name' => 'Expired Token',
            'tokenhash' => hash('sha256', 'expiredtok'),
            'tokenlastchars' => 'redtok',
            'timeexpires' => $now - 3600,
            'timecreated' => $now - 7200,
            'timemodified' => $now - 7200,
            'status' => 1,
        ]);

        // Run the cleanup task.
        $task = new \tool_teachermcp\task\cleanup_task();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Expired code should be deleted, active code preserved.
        $this->assertFalse($DB->record_exists('tool_teachermcp_oauth_codes', ['id' => $expiredcodeid]));
        $this->assertTrue($DB->record_exists('tool_teachermcp_oauth_codes', ['id' => $activecodeid]));

        // Expired active token should have status changed to 0 (revoked).
        $token = $DB->get_record('tool_teachermcp_tokens', ['id' => $expiredtokenid]);
        $this->assertSame(0, (int)$token->status);
    }
}
