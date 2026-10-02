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
        $text = call_user_func($def['generator'], $arguments);

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
     * Raw prompt definitions with localized description keys and generator closures.
     *
     * @return array<string, array>
     */
    private static function get_definitions(): array {
        return [
            'ingest_course' => [
                'desc_key' => 'prompt_ingest_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID to ingest.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    return "Use \"export_course_learning_material\" for course ID {$cid}. " .
                        "Give me a summary of the 5 key concepts, explain the required knowledge per section, " .
                        "and list any attached source files you extracted.";
                },
            ],
            'create_page' => [
                'desc_key' => 'prompt_page_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number (default: 1).',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Title of the page.',
                        'required' => false,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? '1';
                    $title = $args['title'] ?? 'Core Concepts and Summary';
                    return "Using \"manage_content_activity\", create a Page in course ID {$cid} in section {$sec} " .
                        "titled \"{$title}\". Include structured headings, a callout box summarizing key takeaways, " .
                        "and clean formatting.";
                },
            ],
            'create_book' => [
                'desc_key' => 'prompt_book_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number (default: 1).',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Title of the book.',
                        'required' => false,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? '1';
                    $title = $args['title'] ?? 'Course Study Guide';
                    return "Create a Book in course ID {$cid} section {$sec} titled \"{$title}\". " .
                        "Create Chapter 1: Introduction & Definitions, Chapter 2: Core Theory & Analysis, " .
                        "and Chapter 3: Practical Applications.";
                },
            ],
            'upload_image' => [
                'desc_key' => 'prompt_image_desc',
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
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $file = $args['filename'] ?? 'diagram.png';
                    $sec = $args['section_number'] ?? '1';
                    return "Upload the provided diagram to course ID {$cid} as \"{$file}\" using \"upload_course_asset\". " .
                        "Then create a Page in section {$sec} with the image embedded inside.";
                },
            ],
            'inspect_h5p' => [
                'desc_key' => 'prompt_h5p_inspect_desc',
                'arguments' => [],
                'generator' => function(array $args): string {
                    return "Run \"get_h5p_libraries\" with include_schema_examples=true. " .
                        "Which interactive content types are installed on this Moodle site and what is the parameter schema " .
                        "for Flashcards and Multiple Choice?";
                },
            ],
            'h5p_flashcards' => [
                'desc_key' => 'prompt_h5p_flashcards_desc',
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
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? '1';
                    $title = $args['title'] ?? 'Interactive Flashcards';
                    return "Use \"create_h5p_activity\" to build an interactive Flashcard set in course ID {$cid}, " .
                        "section {$sec} titled \"{$title}\" with library \"H5P.Flashcards\". " .
                        "Add definition cards covering key terminology.";
                },
            ],
            'h5p_quiz' => [
                'desc_key' => 'prompt_h5p_quiz_desc',
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
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? '1';
                    $title = $args['title'] ?? 'Check Your Understanding';
                    return "Use \"create_h5p_activity\" to deploy an interactive \"H5P.MultiChoice\" in course ID {$cid}, " .
                        "section {$sec} titled \"{$title}\". Include question text, 4 answer choices, " .
                        "didactic feedback per choice, and enable the solutions button.";
                },
            ],
            'inspect_files' => [
                'desc_key' => 'prompt_files_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    return "Inspect the attached documents in course ID {$cid} using \"read_activity_file\" or " .
                        "\"export_course_learning_material\". Formulate 5 high-level exam questions based strictly " .
                        "on the theory in the uploaded documents.";
                },
            ],
            'scaffold_quiz' => [
                'desc_key' => 'prompt_scaffold_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number (default: 1).',
                        'required' => false,
                    ],
                    [
                        'name' => 'question_count',
                        'description' => 'Number of questions to generate (default: 5).',
                        'required' => false,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? '1';
                    $count = $args['question_count'] ?? '5';
                    return "Use \"scaffold_assessment_unit\" to create a formative quiz of {$count} multiple-choice " .
                        "questions in course ID {$cid}, section {$sec}. Base questions on the course material, " .
                        "include didactic feedback for distractors, and keep the quiz hidden for teacher review.";
                },
            ],
            'detect_at_risk' => [
                'desc_key' => 'prompt_atrisk_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    return "Run \"detect_at_risk_students\" for course ID {$cid}. Which students are inactive or have " .
                        "missing work? Draft a motivating check-in message for the students with highest risk that I " .
                        "can send via \"send_student_message\".";
                },
            ],
            'analyze_quiz' => [
                'desc_key' => 'prompt_analytics_desc',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the quiz.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cmid = $args['cmid'] ?? '[enter_cmid]';
                    return "Run \"analyze_class_understanding\" for quiz CMID {$cmid}. Which questions had a facility index " .
                        "below 50% and what misconceptions were common? Provide a 10-minute recap lesson plan for the next lecture.";
                },
            ],
            'prepare_grading' => [
                'desc_key' => 'prompt_grading_desc',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the assignment.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cmid = $args['cmid'] ?? '[enter_cmid]';
                    return "Prepare ungraded submissions for assignment CMID {$cmid} using \"prepare_grading_session\". " .
                        "Evaluate the submitted work against the rubric and propose grades with constructive feedback " .
                        "for \"grade_submission\".";
                },
            ],
            'delete_activity_safe' => [
                'desc_key' => 'prompt_delete_desc',
                'arguments' => [
                    [
                        'name' => 'cmid',
                        'description' => 'Course module ID (cmid) of the activity to inspect and delete.',
                        'required' => true,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cmid = $args['cmid'] ?? '[enter_cmid]';
                    return "Inspect activity cmid {$cmid} and run \"delete_activity\" with confirm_token=''. " .
                        "Show me the activity details and warn me about any consequences. " .
                        "Do not proceed until I explicitly give confirmation.";
                },
            ],
            'scaffold_assignment' => [
                'desc_key' => 'prompt_assignment_desc',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Target Moodle course ID.',
                        'required' => true,
                    ],
                    [
                        'name' => 'section_number',
                        'description' => 'Target section number (default: 1).',
                        'required' => false,
                    ],
                    [
                        'name' => 'title',
                        'description' => 'Assignment title.',
                        'required' => false,
                    ],
                ],
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $sec = $args['section_number'] ?? 1;
                    $title = $args['title'] ?? 'AI Ethics Case Study';
                    return "Use \"create_assignment\" to create an assignment in course ID {$cid}, section {$sec} " .
                        "titled \"{$title}\". Set the due date 14 days from now, allow online text and PDF submissions, " .
                        "and include rubric evaluation criteria with percentage weights.";
                },
            ],

            'schedule_event' => [
                'desc_key' => 'prompt_calendar_desc',
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
                'generator' => function(array $args): string {
                    $cid = $args['course_id'] ?? '[course_id]';
                    $name = $args['name'] ?? 'Midterm Q&A Review Session';
                    return "Use \"manage_course_calendar\" with action=\"create_event\" in course ID {$cid}. " .
                        "Schedule a 60-minute \"{$name}\" for tomorrow at 14:00 with meeting guidelines " .
                        "and preparation notes.";
                },
            ],
        ];
    }
}
