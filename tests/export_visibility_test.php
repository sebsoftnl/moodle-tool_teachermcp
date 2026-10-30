<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

use tool_teachermcp\tools\export_course_learning_material;

/**
 * Teacher-view export visibility and availability metadata contracts.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class export_visibility_test extends \advanced_testcase {
    public function test_exports_label_hidden_sections_materials_and_restrictions_without_dropping_content(): void {
        global $DB, $CFG;
        [$course] = $this->fixture();
        $CFG->enableavailability = 1;
        $restriction = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]],
            'showc' => [true]]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1,
            'name' => 'Hidden draft', 'content' => '<p>Keep the draft text.</p>', 'visible' => 0,
            'availability' => $restriction]);
        $DB->set_field('course_sections', 'visible', 0, ['course' => $course->id, 'section' => 1]);
        rebuild_course_cache($course->id, true);
        $tool = new export_course_learning_material();
        $json = $tool->execute(['course_id' => (int)$course->id, 'format' => 'json']);
        $this->assertSame('teacher', $json['audience']);
        $this->assertFalse($json['student_access_evaluated']);
        $section = array_column($json['sections'], null, 'section_number')[1];
        $this->assertFalse($section['visible']);
        $material = array_column($section['materials'], null, 'cmid')[$page->cmid];
        $this->assertFalse($material['visible']);
        $this->assertSame(json_decode($restriction, true), $material['availability']);
        $this->assertSame('Keep the draft text.', $material['content']);
        $markdown = $tool->execute(['course_id' => (int)$course->id]);
        $this->assertStringContainsString('Teacher view', $markdown['content']);
        $this->assertStringContainsString('Hidden section', $markdown['content']);
        $this->assertStringContainsString('Hidden activity', $markdown['content']);
        $this->assertStringContainsString('Access restrictions configured', $markdown['content']);
        $this->assertStringContainsString('Keep the draft text.', $markdown['content']);
    }

    public function test_hidden_glossary_definitions_have_source_visibility_labels(): void {
        [$course] = $this->fixture();
        $generator = $this->getDataGenerator();
        $glossary = $generator->create_module('glossary', ['course' => $course->id, 'visible' => 0,
            'name' => 'Draft terminology']);
        $generator->get_plugin_generator('mod_glossary')->create_content($glossary,
            ['concept' => 'Draft term', 'definition' => '<p>Unpublished definition</p>', 'approved' => 1]);
        $markdown = (new export_course_learning_material())->execute(['course_id' => (int)$course->id]);
        $this->assertMatchesRegularExpression('/### Draft terminology\s+> Hidden activity\.\s+- \*\*Draft term\*\*/',
            $markdown['content']);
        $this->assertStringContainsString('Unpublished definition', $markdown['content']);
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
