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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_teachermcp;

use tool_teachermcp\local\token_history;

defined('MOODLE_INTERNAL') || die();

/**
 * Inactive connection cleanup must preserve authentication and ownership boundaries.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_history_test extends \advanced_testcase {
    public function test_teacher_clears_only_own_inactive_history(): void {
        global $DB;
        [$owner, $other] = $this->teachers();
        $active = $this->token($owner, 1, time() + 3600);
        $unlimited = $this->token($owner, 1, 0);
        $expired = $this->token($owner, 1, time() - 3600);
        $revoked = $this->token($owner, 0, time() + 3600);
        $foreign = $this->token($other, 0, time() + 3600);
        $survivors = $DB->get_records_list('tool_teachermcp_tokens', 'id', [$active, $unlimited, $foreign], 'id');
        $this->setUser($owner);
        $this->assertSame(2, token_history::count());
        $sink = $this->redirectEvents();
        $this->assertSame(2, token_history::clear());
        $this->assertSame(0, token_history::count());
        $this->assertFalse($DB->record_exists('tool_teachermcp_tokens', ['id' => $expired]));
        $this->assertFalse($DB->record_exists('tool_teachermcp_tokens', ['id' => $revoked]));
        $this->assertEquals($survivors, $DB->get_records('tool_teachermcp_tokens', null, 'id'));
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_teachermcp\event\token_history_cleared::class, $events[0]);
        $this->assertEquals($owner->id, $events[0]->userid);
        $this->assertSame(2, $events[0]->other['count']);
        $this->assertFalse($events[0]->other['allusers']);
    }

    public function test_teacher_cannot_clear_site_wide_history(): void {
        global $DB;
        [$owner, $other] = $this->teachers();
        $this->token($other, 0, 0);
        $before = $DB->get_records('tool_teachermcp_tokens');
        $this->setUser($owner);
        try {
            token_history::clear(true);
            $this->fail('Teachers must not clear site-wide history');
        } catch (\required_capability_exception $error) {
            $this->assertEquals($before, $DB->get_records('tool_teachermcp_tokens'));
        }
    }

    public function test_manager_clears_inactive_history_without_deleting_active_connections(): void {
        global $DB;
        [$owner, $other] = $this->teachers();
        $this->token($owner, 0, 0);
        $this->token($other, 1, time() - 3600);
        $active = $this->token($other, 1, time() + 3600);
        $this->setAdminUser();
        $this->assertSame(2, token_history::count(true));
        $this->assertSame(2, token_history::clear(true));
        $this->assertSame([$active], array_map('intval', array_keys($DB->get_records('tool_teachermcp_tokens'))));
    }

    public function test_cleanup_detaches_only_removed_token_references(): void {
        global $DB;
        [$owner] = $this->teachers();
        $expired = $this->token($owner, 1, time() - 3600);
        $active = $this->token($owner, 1, time() + 3600);
        $codes = [];
        foreach ([$expired, $active] as $id) {
            $codes[$id] = $DB->insert_record('tool_teachermcp_oauth_codes', (object)[
                'codehash' => hash('sha256', 'history-code-' . $id), 'clientid' => 'history-test',
                'userid' => $owner->id, 'redirecturi' => 'https://example.invalid/callback',
                'status' => 1, 'issuedtokenid' => $id, 'timecreated' => time(), 'timeexpires' => time() + 600,
            ]);
        }
        $this->setUser($owner);
        $this->assertSame(1, token_history::clear());
        $this->assertNull($DB->get_record('tool_teachermcp_oauth_codes', ['id' => $codes[$expired]], '*', MUST_EXIST)->issuedtokenid);
        $this->assertEquals($active, $DB->get_field('tool_teachermcp_oauth_codes', 'issuedtokenid', ['id' => $codes[$active]]));
        $this->assertSame(2, $DB->count_records('tool_teachermcp_oauth_codes'));
    }

    public function test_empty_cleanup_is_idempotent_and_does_not_emit_events(): void {
        [$owner] = $this->teachers();
        $this->setUser($owner);
        $sink = $this->redirectEvents();
        $this->assertSame(0, token_history::clear());
        $this->assertSame(0, token_history::clear());
        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    public function test_status_uses_expiry_even_before_scheduled_cleanup(): void {
        $this->resetAfterTest(true);
        $now = 2000000000;
        foreach (
            [[1, 0, true, 'active'], [1, $now, true, 'active'], [1, $now - 1, false, 'expired'],
                [0, $now + 1, false, 'revoked'], [0, $now - 1, false, 'expired']] as [$status, $expiry, $active, $label]
        ) {
            $token = (object)['status' => $status, 'timeexpires' => $expiry];
            $this->assertSame($active, token_history::is_active($token, $now));
            $this->assertSame($label, token_history::status($token, $now));
        }
    }

    private function teachers(): array {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $users = [$generator->create_user(), $generator->create_user()];
        foreach ($users as $user) {
            $generator->enrol_user($user->id, $course->id, 'editingteacher');
        }
        return $users;
    }

    private function token(\stdClass $user, int $status, int $expiry): int {
        global $DB;
        $token = auth::generate_token_record($user->id, 'History fixture', 1);
        $DB->update_record('tool_teachermcp_tokens', (object)[
            'id' => $token['id'], 'status' => $status, 'timeexpires' => $expiry,
        ]);
        return (int)$token['id'];
    }
}
