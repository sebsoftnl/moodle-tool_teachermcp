<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

use tool_teachermcp\tools\manage_activity;

/**
 * Native activity publication and settings-boundary contracts.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_publication_test extends \advanced_testcase {
    public function test_supported_modules_publish_and_unpublish_without_editing_settings(): void {
        global $DB;
        [$course, $teacher, $student] = $this->fixture();
        foreach (['page', 'assign', 'quiz', 'book', 'h5pactivity'] as $type) {
            $this->setUser($teacher);
            $activity = $this->getDataGenerator()->create_module($type, ['course' => $course->id,
                'name' => 'Publication ' . $type, 'visible' => 0]);
            $before = $DB->get_record($type, ['id' => $activity->id], '*', MUST_EXIST);
            foreach ([true, false] as $visible) {
                $this->setUser($teacher);
                $result = (new manage_activity())->execute(['action' => 'update_settings',
                    'cmid' => (int)$activity->cmid, 'visible' => $visible]);
                $this->assertSame($visible, $result['visible']);
                $after = $DB->get_record($type, ['id' => $activity->id], '*', MUST_EXIST);
                $this->assertGreaterThanOrEqual((int)$before->timemodified, (int)$after->timemodified);
                $settings = clone $before;
                unset($settings->timemodified, $after->timemodified);
                $this->assertEquals($settings, $after, 'Publication must preserve all non-timestamp instance settings');
                $this->setUser($student);
                get_fast_modinfo($course->id, $student->id, true);
                $cm = get_fast_modinfo($course->id, $student->id)->get_cm($activity->cmid);
                $this->assertSame($visible, (bool)$cm->uservisible, $type . ' must respect student visibility');
            }
        }
    }

    public function test_nonpage_combined_edits_and_unsupported_modules_reject_without_changes(): void {
        global $DB;
        [$course] = $this->fixture();
        foreach (['assign', 'quiz', 'book', 'h5pactivity', 'forum'] as $type) {
            $activity = $this->getDataGenerator()->create_module($type, ['course' => $course->id, 'visible' => 0]);
            $before = $DB->get_record('course_modules', ['id' => $activity->cmid], '*', MUST_EXIST);
            $instance = $DB->get_record($type, ['id' => $activity->id], '*', MUST_EXIST);
            foreach ([['name' => 'Not allowed'], ['intro' => 'Not allowed'], ['timelimit' => 60]] as $extra) {
                try {
                    (new manage_activity())->execute(['action' => 'update_settings', 'cmid' => (int)$activity->cmid,
                        'visible' => true] + $extra);
                    $this->fail('Non-Page publication must not silently ignore broader settings');
                } catch (\moodle_exception $error) {
                    $this->assertSame('error_invalid_action', $error->errorcode);
                    $this->assertStringNotContainsString('{$a}', $error->getMessage());
                    $this->assertStringContainsString($type, $error->getMessage());
                }
                $this->assertEquals($before, $DB->get_record('course_modules', ['id' => $activity->cmid], '*', MUST_EXIST));
                $this->assertEquals($instance, $DB->get_record($type, ['id' => $activity->id], '*', MUST_EXIST));
            }
        }
    }

    public function test_publication_retains_availability_and_requires_visibility_permission(): void {
        global $DB, $CFG;
        [$course, $teacher, $student] = $this->fixture();
        $CFG->enableavailability = 1;
        $restriction = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]],
            'showc' => [true]]);
        $activity = $this->getDataGenerator()->create_module('assign', ['course' => $course->id,
            'visible' => 0, 'availability' => $restriction]);
        $tool = new manage_activity();
        $tool->execute(['action' => 'update_settings', 'cmid' => (int)$activity->cmid, 'visible' => true]);
        $this->assertSame($restriction, $DB->get_field('course_modules', 'availability', ['id' => $activity->cmid]));
        $this->setUser($student);
        get_fast_modinfo($course->id, $student->id, true);
        $this->assertFalse(get_fast_modinfo($course->id, $student->id)->get_cm($activity->cmid)->uservisible);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:activityvisibility', CAP_PROHIBIT, $role,
            \context_module::instance($activity->cmid)->id);
        accesslib_clear_all_caches(true);
        $this->setUser($teacher);
        $before = $DB->get_record('course_modules', ['id' => $activity->cmid]);
        try {
            $tool->execute(['action' => 'update_settings', 'cmid' => (int)$activity->cmid, 'visible' => false]);
            $this->fail('Unpublishing requires visibility permission');
        } catch (\required_capability_exception $error) {
            $this->assertEquals($before, $DB->get_record('course_modules', ['id' => $activity->cmid]));
        }
    }

    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/course/lib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        return [$course, $teacher, $student];
    }
}
