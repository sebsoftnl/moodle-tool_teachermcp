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
}
