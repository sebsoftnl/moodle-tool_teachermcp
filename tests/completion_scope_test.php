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
 * Completion read-scope and contextual access regression tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class completion_scope_test extends \advanced_testcase {
    public function test_read_presets_list_completion_without_granting_unrelated_mutations(): void {
        $this->resetAfterTest(true);
        foreach ([['read'], ['read', 'grade'], ['read', 'write']] as $scopes) {
            $token = (object)['permissions' => json_encode(['scopes' => $scopes])];
            $this->assertArrayHasKey(
                'manage_course_completion',
                server::get_tools($token),
                'A preset containing read must list completion inspection'
            );
        }
        $readonly = $this->readonly_token();
        $this->assertSame('read', server::get_tool_scope('manage_course_completion'));
        foreach (['manage_activity', 'manage_content_activity', 'grade_submission', 'delete_activity'] as $name) {
            $this->assertFalse(server::is_tool_allowed_by_token($name, $readonly));
            $this->assertArrayNotHasKey($name, server::get_tools($readonly));
            $this->assert_denied(new server(), $readonly, $name, [], 'error_tool_not_permitted');
        }
        set_config('enable_write_tools', 0, 'tool_teachermcp');
        $this->assertArrayHasKey('manage_course_completion', server::get_tools($readonly));
    }

    public function test_readonly_completion_calls_both_actions_on_both_dispatch_paths(): void {
        $fixture = $this->fixture();
        $server = new server();
        $token = $this->readonly_token();
        $catalog = $this->rpc($server, $token, 'tools/list', []);
        $this->assertContains('manage_course_completion', array_column($catalog['result']['tools'], 'name'));
        foreach (['get_criteria', 'get_student_progress'] as $action) {
            $arguments = ['course_id' => (int)$fixture['course']->id, 'action' => $action,
                'student_user_id' => (int)$fixture['student']->id];
            $direct = $server->execute_tool('manage_course_completion', $arguments, $token);
            $response = $this->rpc($server, $token, 'tools/call', [
                'name' => 'manage_course_completion', 'arguments' => $arguments,
            ]);
            $this->assertFalse($response['result']['isError']);
            $this->assertSame($direct, json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame((int)$fixture['course']->id, $direct['course_id']);
            if ($action === 'get_criteria') {
                $this->assertTrue($direct['completion_enabled']);
                $this->assertSame(0, $direct['total_criteria']);
                $this->assertSame([], $direct['criteria']);
            } else {
                $this->assert_progress($direct, $fixture);
            }
        }
    }

    public function test_populated_progress_uses_native_completion_timestamps(): void {
        $fixture = $this->fixture();
        $tool = new \tool_teachermcp\tools\manage_course_completion();
        $arguments = ['course_id' => (int)$fixture['course']->id, 'action' => 'get_student_progress',
            'student_user_id' => (int)$fixture['student']->id];
        $this->assert_progress($tool->execute($arguments), $fixture);

        $completion = new \completion_info($fixture['course']);
        $cm = get_coursemodule_from_id('page', $fixture['done']->cmid, 0, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $fixture['student']->id);
        $states = array_column($tool->execute($arguments)['activities_progress'], null, 'cmid');
        $this->assertSame('incomplete', $states[$fixture['done']->cmid]['state']);
        $this->assertNull($states[$fixture['done']->cmid]['timecompleted']);
        $this->assertNull($states[$fixture['done']->cmid]['timecompleted_iso']);
    }

    public function test_existing_completion_guards_with_a_full_grant(): void {
        $this->assert_access_guards((object)['permissions' => json_encode(['all_teacher_tools' => true])]);
    }

    public function test_readonly_completion_preserves_course_group_and_enrolment_guards(): void {
        $this->assert_access_guards($this->readonly_token());
    }

    private function readonly_token(): \stdClass {
        return (object)['id' => 1, 'permissions' => json_encode(['scopes' => ['read']])];
    }

    private function fixture(): array {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $CFG->enablecompletion = 1;
        require_once($CFG->libdir . '/completionlib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $othercourse = $generator->create_course();
        $users = [];
        foreach (['teacher', 'student', 'otherstudent', 'suspendedstudent', 'suspendedteacher', 'outsider'] as $name) {
            $users[$name] = $generator->create_user();
        }
        foreach (['teacher', 'suspendedteacher'] as $name) {
            $generator->enrol_user($users[$name]->id, $course->id, 'editingteacher');
        }
        foreach (['student', 'otherstudent', 'suspendedstudent'] as $name) {
            $generator->enrol_user($users[$name]->id, $course->id, 'student');
        }
        $generator->enrol_user($users['outsider']->id, $othercourse->id, 'editingteacher');
        foreach (['suspendedteacher', 'suspendedstudent'] as $name) {
            $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $users[$name]->id]);
        }
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        foreach (['teacher', 'student', 'suspendedstudent', 'suspendedteacher'] as $name) {
            $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $users[$name]->id]);
        }
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $users['otherstudent']->id]);
        $context = \context_course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $roleid, $context->id, true);
        $done = $generator->create_module('page', ['course' => $course->id, 'name' => 'Completed activity',
            'visible' => 1, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $pending = $generator->create_module('page', ['course' => $course->id, 'name' => 'Incomplete activity',
            'visible' => 1, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', $done->cmid, 0, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, $users['student']->id);
        $completed = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $done->cmid, 'userid' => $users['student']->id,
        ], '*', MUST_EXIST);
        $this->setUser($users['teacher']);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        return $users + compact('course', 'context', 'roleid', 'done', 'pending', 'completed');
    }

    private function assert_progress(array $result, array $fixture): void {
        $this->assertSame((int)$fixture['student']->id, $result['student_user_id']);
        $states = array_column($result['activities_progress'], null, 'cmid');
        $this->assertCount(2, $states);
        $done = $states[$fixture['done']->cmid];
        $pending = $states[$fixture['pending']->cmid];
        $this->assertSame('complete', $done['state']);
        $timestamp = (int)$fixture['completed']->timemodified;
        $this->assertGreaterThan(0, $timestamp);
        $this->assertSame($timestamp, $done['timecompleted']);
        $this->assertSame(date('c', $timestamp), $done['timecompleted_iso']);
        $this->assertSame('incomplete', $pending['state']);
        $this->assertNull($pending['timecompleted']);
        $this->assertNull($pending['timecompleted_iso']);
    }

    private function assert_access_guards(\stdClass $token): void {
        global $DB;
        $fixture = $this->fixture();
        $server = new server();
        $courseid = (int)$fixture['course']->id;
        $before = $DB->get_records('course_modules_completion');
        foreach (['otherstudent' => 'error_no_group_access', 'suspendedstudent' => 'error_not_enrolled'] as $name => $code) {
            $this->assert_denied($server, $token, 'manage_course_completion', [
                'course_id' => $courseid, 'action' => 'get_student_progress',
                'student_user_id' => (int)$fixture[$name]->id,
            ], $code);
        }
        $this->setUser($fixture['outsider']);
        $this->assert_denied($server, $token, 'manage_course_completion', [
            'course_id' => $courseid, 'action' => 'get_criteria',
        ], 'nopermissions', 'tool/teachermcp:connectagent');
        $this->setUser($fixture['suspendedteacher']);
        $this->assert_denied($server, $token, 'manage_course_completion', [
            'course_id' => $courseid, 'action' => 'get_criteria',
        ], 'error_not_enrolled');
        assign_capability('report/completion:view', CAP_PROHIBIT, $fixture['roleid'], $fixture['context']->id, true);
        $this->setUser($fixture['teacher']);
        $this->assert_denied($server, $token, 'manage_course_completion', [
            'course_id' => $courseid, 'action' => 'get_criteria',
        ], 'nopermissions', 'report/completion:view');
        $this->assertEquals($before, $DB->get_records('course_modules_completion'));
    }

    private function assert_denied(
        server $server,
        \stdClass $token,
        string $name,
        array $arguments,
        string $code,
        ?string $capability = null
    ): void {
        try {
            $server->execute_tool($name, $arguments, $token);
            $this->fail('Expected exact completion/scope denial: ' . $code);
        } catch (\moodle_exception $error) {
            $this->assertSame($code, $error->errorcode);
            if ($capability !== null) {
                $this->assertStringContainsString(get_capability_string($capability), $error->getMessage());
            }
        }
    }

    private function rpc(server $server, \stdClass $token, string $method, array $params): array {
        $original = auth::get_current_token();
        $property = new \ReflectionProperty(auth::class, 'currenttoken');
        $property->setAccessible(true);
        $property->setValue(null, $token);
        try {
            return $server->handle_payload(json_encode([
                'jsonrpc' => '2.0', 'id' => 6, 'method' => $method, 'params' => $params,
            ], JSON_THROW_ON_ERROR));
        } finally {
            $property->setValue(null, $original);
        }
    }
}
