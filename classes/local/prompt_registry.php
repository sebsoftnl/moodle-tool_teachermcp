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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Registry of pedagogical prompts exposed via MCP prompts/list and prompts/get.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_registry {
    /**
     * Get all available prompts for MCP prompts/list.
     *
     * @return array[] List of prompt definitions.
     */
    public static function get_all_prompts(): array {
        $definitions = self::get_definitions();
        $list = [];

        foreach ($definitions as $name => $def) {
            $list[] = [
                'name' => $name,
                'description' => get_string($def['desc_key'], 'tool_teachermcp'),
                'arguments' => $def['arguments'],
            ];
        }

        return $list;
    }

    /**
     * Get a rendered prompt by name and arguments for MCP prompts/get.
     *
     * @param string $name Prompt identifier.
     * @param array $arguments Provided arguments.
     * @return array|null Rendered prompt response, or null if not found.
     */
    public static function get_prompt(string $name, array $arguments = []): ?array {
        $definitions = self::get_definitions();
        if (!isset($definitions[$name])) {
            return null;
        }

        $def = $definitions[$name];
        $context = (object)[
            'courseid' => $arguments['course_id'] ?? '[course_id]',
            'coursename' => '[course_name]',
            'cmid' => $arguments['cmid'] ?? '[enter_cmid]',
        ];
        $text = get_string($def['text_key'], 'tool_teachermcp', $context);
        $details = [];
        foreach ($def['arguments'] as $argument) {
            $key = $argument['name'];
            if (!$argument['required'] && isset($arguments[$key])) {
                $details[$key] = $arguments[$key];
            }
        }
        if ($details) {
            $text .= "\n\n" . get_string(
                'prompt_supplied_arguments',
                'tool_teachermcp',
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'description' => get_string($def['desc_key'], 'tool_teachermcp'),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * Prompt definitions with localized text keys and public argument declarations.
     *
     * @return array<string, array>
     */
    private static function get_definitions(): array {
        return [
            'ingest_course' => [
                'desc_key' => 'prompt_ingest_desc',
                'text_key' => 'prompt_ingest_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID to ingest.',
                        'required' => true,
                    ],
                ],
            ],
            'create_page' => [
                'desc_key' => 'prompt_page_desc',
                'text_key' => 'prompt_page_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number; ask the teacher if omitted.',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Title of the page.',
                        'required' => false,
                    ],
                ],
            ],
            'create_book' => [
                'desc_key' => 'prompt_book_desc',
                'text_key' => 'prompt_book_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number; ask the teacher if omitted.',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Title of the book.',
                        'required' => false,
                    ],
                ],
            ],
            'upload_image' => [
                'desc_key' => 'prompt_image_desc',
                'text_key' => 'prompt_image_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'filename',
                        'description' => 'Filename for the asset (e.g. concept_map.png).',
                        'required' => false,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number for the embedding page.',
                        'required' => false,
                    ],
                ],
            ],
            'inspect_h5p' => [
                'desc_key' => 'prompt_h5p_inspect_desc',
                'text_key' => 'prompt_h5p_inspect_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                ],
            ],
            'h5p_flashcards' => [
                'desc_key' => 'prompt_h5p_flashcards_desc',
                'text_key' => 'prompt_h5p_flashcards_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number.',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Activity title.',
                        'required' => false,
                    ],
                ],
            ],
            'h5p_quiz' => [
                'desc_key' => 'prompt_h5p_quiz_desc',
                'text_key' => 'prompt_h5p_quiz_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number.',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Activity title.',
                        'required' => false,
                    ],
                ],
            ],
            'inspect_files' => [
                'desc_key' => 'prompt_files_desc',
                'text_key' => 'prompt_files_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                ],
            ],
            'scaffold_quiz' => [
                'desc_key' => 'prompt_scaffold_desc',
                'text_key' => 'prompt_scaffold_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number; ask the teacher if omitted.',
                        'required' => false,
                    ],
                    [
                        'name' => 'question_count',
                        'description' => 'Number of questions to generate; ask the teacher if omitted.',
                        'required' => false,
                    ],
                ],
            ],
            'detect_at_risk' => [
                'desc_key' => 'prompt_atrisk_desc',
                'text_key' => 'prompt_atrisk_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                ],
            ],
            'analyze_quiz' => [
                'desc_key' => 'prompt_analytics_desc',
                'text_key' => 'prompt_analytics_module_text',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the quiz.',
                        'required' => true,
                    ],
                ],
            ],
            'prepare_grading' => [
                'desc_key' => 'prompt_grading_desc',
                'text_key' => 'prompt_grading_module_text',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the assignment.',
                        'required' => true,
                    ],
                ],
            ],
            'delete_activity_safe' => [
                'desc_key' => 'prompt_delete_desc',
                'text_key' => 'prompt_delete_module_text',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the activity to inspect and delete.',
                        'required' => true,
                    ],
                ],
            ],
            'scaffold_assignment' => [
                'desc_key' => 'prompt_assignment_desc',
                'text_key' => 'prompt_assignment_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number; ask the teacher if omitted.',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Assignment title.',
                        'required' => false,
                    ],
                ],
            ],

            'schedule_event' => [
                'desc_key' => 'prompt_calendar_desc',
                'text_key' => 'prompt_calendar_text',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'name',
                        'description' => 'Name of the calendar event.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }
}
