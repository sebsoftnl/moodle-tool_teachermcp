<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

use tool_teachermcp\local\prompt_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Prompt rendering and discovery contracts.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_registry_test extends \advanced_testcase {
    public function test_course_prompts_use_dashboard_language_templates(): void {
        $prompts = [
            'ingest_course' => 'ingest',
            'create_page' => 'page',
            'create_book' => 'book',
            'upload_image' => 'image',
            'inspect_h5p' => 'h5p_inspect',
            'h5p_flashcards' => 'h5p_flashcards',
            'h5p_quiz' => 'h5p_quiz',
            'inspect_files' => 'files',
            'scaffold_quiz' => 'scaffold',
            'detect_at_risk' => 'atrisk',
            'scaffold_assignment' => 'assignment',
            'schedule_event' => 'calendar',
        ];
        $context = (object)['courseid' => '73', 'coursename' => '[course_name]'];
        foreach ($prompts as $name => $key) {
            $prompt = prompt_registry::get_prompt($name, ['course_id' => '73']);
            $this->assertSame(get_string('prompt_' . $key . '_text', 'tool_teachermcp', $context),
                $prompt['messages'][0]['content']['text'], $name . ' must use the shared localized template');
            $this->assertSame('user', $prompt['messages'][0]['role']);
            $this->assertSame('text', $prompt['messages'][0]['content']['type']);
        }
    }

    public function test_h5p_discovery_declares_course_context(): void {
        $prompts = array_column(prompt_registry::get_all_prompts(), null, 'name');
        $arguments = array_column($prompts['inspect_h5p']['arguments'], null, 'name');
        $this->assertArrayHasKey('course_id', $arguments, 'H5P inspection needs a discoverable course argument');
        $this->assertTrue($arguments['course_id']['required']);
    }

    public function test_optional_arguments_are_preserved_as_data(): void {
        foreach (prompt_registry::get_all_prompts() as $definition) {
            $arguments = [];
            foreach ($definition['arguments'] as $argument) {
                if (!$argument['required']) {
                    $arguments[$argument['name']] = $argument['name'] === 'section_number' ? '0' :
                        "A \"quoted\" value\nSecond line with \u{00e9}";
                }
            }
            if (!$arguments) {
                continue;
            }
            $prompt = prompt_registry::get_prompt($definition['name'], ['course_id' => '73'] + $arguments);
            $text = $prompt['messages'][0]['content']['text'];
            $encoded = substr($text, strpos($text, '{'));
            $this->assertSame($arguments, json_decode($encoded, true),
                $definition['name'] . ': supplied task details must round-trip as JSON');
        }
    }

    public function test_unknown_arguments_do_not_change_prompt(): void {
        $expected = prompt_registry::get_prompt('schedule_event', ['course_id' => '73']);
        $this->assertSame($expected,
            prompt_registry::get_prompt('schedule_event', ['course_id' => '73', 'unknown' => 'unrelated data']));
    }

    public function test_module_targets_and_unknown_prompt_are_preserved(): void {
        foreach (['analyze_quiz', 'prepare_grading', 'delete_activity_safe'] as $name) {
            $prompt = prompt_registry::get_prompt($name, ['cmid' => '987654']);
            $this->assertStringContainsString('987654', $prompt['messages'][0]['content']['text']);
        }
        $this->assertNull(prompt_registry::get_prompt('unknown_prompt'));
    }

    public function test_missing_context_remains_an_explicit_placeholder(): void {
        $prompt = prompt_registry::get_prompt('inspect_h5p');
        $this->assertStringContainsString('[course_id]', $prompt['messages'][0]['content']['text']);
    }
}
