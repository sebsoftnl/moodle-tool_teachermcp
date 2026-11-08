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

defined('MOODLE_INTERNAL') || die();

use tool_teachermcp\local\resource_registry;

/**
 * Announcements remain read-only and follow native forum visibility.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class announcements_resource_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_missing_announcements_forum_read_does_not_change_course_state(): void {
        [$course, $teacher] = $this->course_fixture();
        set_config('enable_write_tools', 0, 'tool_teachermcp');
        $before = $this->course_state((int)$course->id);
        $this->assertSame(0, $before['forums']);
        $this->setUser($teacher);

        $result = resource_registry::read_resource("moodle://course/{$course->id}/announcements");

        $this->assertNotNull($result);
        $this->assertSame(
            $before,
            $this->course_state((int)$course->id),
            'Reading announcements must not create a forum, module or section entry.'
        );
        $this->assertStringContainsString('No announcements forum configured', $result['contents'][0]['text']);
    }

    public function test_announcements_display_first_post_creation_date(): void {
        global $DB;
        [$course, $teacher] = $this->course_fixture();
        $forum = $this->news_forum($course);
        $discussion = $this->discussion($course, $forum, 'Dated announcement', -1, 1792566923);
        $created = 1704110400;
        $DB->set_field('forum_posts', 'created', $created, ['id' => $discussion->firstpost]);
        $this->setUser($teacher);

        $text = $this->read_text($course);

        $this->assertStringContainsString(
            '**Posted:** ' . userdate($created),
            $text,
            'The posting date must come from the first post, not a missing discussion field.'
        );
        $this->assertStringContainsString('Body for Dated announcement', $text);
    }

    public function test_announcements_match_native_group_visibility(): void {
        [$course, $teacher, $role] = $this->course_fixture(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $forum = $this->news_forum($course);
        $generator = $this->getDataGenerator();
        $first = $generator->create_group(['courseid' => $course->id]);
        $second = $generator->create_group(['courseid' => $course->id]);
        $other = $generator->create_group(['courseid' => $course->id]);
        foreach ([$first, $second] as $group) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        }
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $role, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches(true);
        $visible = $this->discussion($course, $forum, 'First teaching group', (int)$first->id, 1704110400);
        $this->discussion($course, $forum, 'Second teaching group', (int)$second->id, 1704110500);
        $this->discussion($course, $forum, 'All participants', -1, 1704110600);
        $unavailable = $this->discussion($course, $forum, 'Other teaching group', (int)$other->id, 1704110700);
        $this->setUser($teacher);
        $context = \context_module::instance($forum->cmid);
        $this->assertTrue(forum_user_can_see_discussion($forum, $visible, $context));
        $this->assertFalse(forum_user_can_see_discussion($forum, $unavailable, $context));

        $text = $this->read_text($course);

        $this->assertStringNotContainsString(
            'Other teaching group',
            $text,
            'Announcements must agree with the native discussion visibility decision.'
        );
        foreach (['First teaching group', 'Second teaching group', 'All participants'] as $title) {
            $this->assertStringContainsString('## ' . $title, $text);
        }
    }

    public function test_announcement_limit_applies_after_visibility_filtering(): void {
        [$course, $teacher, $role] = $this->course_fixture(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $forum = $this->news_forum($course);
        $generator = $this->getDataGenerator();
        $own = $generator->create_group(['courseid' => $course->id]);
        $other = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $own->id, 'userid' => $teacher->id]);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $role, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches(true);
        for ($index = 1; $index <= 6; $index++) {
            $this->discussion($course, $forum, "Available {$index}", (int)$own->id, 1704110400 + $index);
            $this->discussion($course, $forum, "Other group {$index}", (int)$other->id, 1704110500 + $index);
        }
        $this->setUser($teacher);

        $text = $this->read_text($course);

        $this->assertSame(5, substr_count($text, "\n## "), 'Return five permitted announcements when five are available.');
        $this->assertStringNotContainsString('Other group', $text);
        $this->assertStringNotContainsString('## Available 1', $text);
        foreach ([6, 5, 4, 3, 2] as $index) {
            $this->assertStringContainsString("## Available {$index}", $text);
        }
        $this->assertLessThan(strpos($text, '## Available 2'), strpos($text, '## Available 6'));
    }

    public function test_hidden_announcements_activity_is_not_returned(): void {
        [$course, $teacher, $role] = $this->course_fixture();
        $forum = $this->news_forum($course, ['visible' => 0]);
        assign_capability(
            'moodle/course:viewhiddenactivities',
            CAP_PROHIBIT,
            $role,
            \context_course::instance($course->id)->id
        );
        accesslib_clear_all_caches(true);
        $this->setUser($teacher);
        $cm = get_fast_modinfo($course)->get_cm($forum->cmid);
        $this->assertFalse($cm->uservisible);

        $this->assertNull(
            resource_registry::read_resource("moodle://course/{$course->id}/announcements"),
            'A course resource must not expose a forum activity unavailable to its reader.'
        );
    }

    public function test_announcements_respect_native_timed_visibility(): void {
        [$course, $teacher, $role] = $this->course_fixture();
        $forum = $this->news_forum($course);
        set_config('forum_enabletimedposts', 1);
        assign_capability(
            'mod/forum:viewhiddentimedposts',
            CAP_PROHIBIT,
            $role,
            \context_module::instance($forum->cmid)->id
        );
        accesslib_clear_all_caches(true);
        $this->discussion($course, $forum, 'Current announcement', -1, time() - HOURSECS);
        $future = $this->discussion($course, $forum, 'Scheduled announcement', -1, time(), [
            'timestart' => time() + DAYSECS,
        ]);
        $this->setUser($teacher);
        $this->assertFalse(forum_user_can_see_discussion($forum, $future, \context_module::instance($forum->cmid)));

        $text = $this->read_text($course);

        $this->assertStringContainsString('Current announcement', $text);
        $this->assertStringNotContainsString('Scheduled announcement', $text);
    }

    private function course_fixture(array $settings = []): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course($settings + ['newsitems' => 0]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $role = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        return [$course, $teacher, $role];
    }

    private function news_forum(\stdClass $course, array $settings = []): \stdClass {
        return $this->getDataGenerator()->create_module('forum', $settings + [
            'course' => $course->id, 'type' => 'news', 'name' => 'Announcements',
        ]);
    }

    private function discussion(
        \stdClass $course,
        \stdClass $forum,
        string $name,
        int $groupid,
        int $modified,
        array $settings = []
    ): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($settings + [
            'course' => $course->id, 'forum' => $forum->id, 'userid' => get_admin()->id,
            'groupid' => $groupid, 'name' => $name, 'message' => 'Body for ' . $name,
            'timemodified' => $modified,
        ]);
    }

    private function read_text(\stdClass $course): string {
        $result = resource_registry::read_resource("moodle://course/{$course->id}/announcements");
        $this->assertNotNull($result);
        return $result['contents'][0]['text'];
    }

    private function course_state(int $courseid): array {
        global $DB;
        return [
            'forums' => $DB->count_records('forum', ['course' => $courseid]),
            'modules' => $DB->count_records('course_modules', ['course' => $courseid]),
            'general_sequence' => $DB->get_field('course_sections', 'sequence', ['course' => $courseid, 'section' => 0]),
        ];
    }
}
