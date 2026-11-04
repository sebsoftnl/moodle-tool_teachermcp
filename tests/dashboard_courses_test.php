<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

use tool_teachermcp\local\dashboard_courses;

defined('MOODLE_INTERNAL') || die();

/**
 * Course context must match the teacher's permitted catalog and visible selection.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_courses_test extends \advanced_testcase {
    public function test_selected_course_keeps_its_real_id_and_name(): void {
        [$first, $second] = $this->courses();
        $context = dashboard_courses::get((int)$second->id);
        $this->assertSame((int)$second->id, $context['selected']['id']);
        $this->assertSame($second->fullname, $context['selected']['fullname']);
        $this->assertNotSame((int)$first->id, $context['selected']['id']);
    }

    public function test_default_selection_comes_from_the_same_catalog(): void {
        $this->courses();
        $context = dashboard_courses::get();
        $this->assertCount(2, $context['courses']);
        $this->assertSame($context['courses'][0], $context['selected']);
    }

    public function test_invalid_and_unavailable_ids_do_not_fall_back_to_another_course(): void {
        $this->courses();
        $foreign = $this->getDataGenerator()->create_course();
        foreach ([(int)$foreign->id, -1, 987654321] as $id) {
            $context = dashboard_courses::get($id);
            $this->assertNull($context['selected'], 'An explicit unavailable course must not silently select another course');
            $this->assertNotContains($id, array_column($context['courses'], 'id'));
        }
    }

    public function test_student_only_and_suspended_enrolments_are_not_offered(): void {
        global $DB, $USER;
        [$first, $second] = $this->courses();
        $studentcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($USER->id, $studentcourse->id, 'student');
        $instance = $DB->get_record('enrol', ['courseid' => $second->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['enrolid' => $instance->id, 'userid' => $USER->id]);
        $context = dashboard_courses::get();
        $this->assertSame([(int)$first->id], array_column($context['courses'], 'id'));
        $this->assertNull(dashboard_courses::get((int)$studentcourse->id)['selected']);
        $this->assertNull(dashboard_courses::get((int)$second->id)['selected']);
    }

    public function test_no_courses_has_no_fabricated_context(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $context = dashboard_courses::get();
        $this->assertSame([], $context['courses']);
        $this->assertNull($context['selected']);
    }

    private function courses(): array {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $courses = [$generator->create_course(['fullname' => 'First teaching course']),
            $generator->create_course(['fullname' => 'Second "quoted" course'])];
        foreach ($courses as $course) {
            $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        }
        $this->setUser($teacher);
        return $courses;
    }
}
