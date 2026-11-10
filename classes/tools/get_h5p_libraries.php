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

namespace tool_teachermcp\tools;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

/**
 * MCP tool to list available H5P content types, installed libraries, and JSON schemas.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_h5p_libraries extends base_tool {
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'get_h5p_libraries';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Lists enabled, runnable H5P content types and installed versions. Selected types include example ' .
            'parameters, not complete schemas for every library. When a filter is used, total_installed_types counts ' .
            'matching types, not the unfiltered site inventory. Call this tool before creating H5P activities and ' .
            'check the installed library\'s requirements.';
    }

    /**
     * Return the JSON input schema for this tool.
     *
     * @return array Result of the operation.
     */
    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Course ID in which the caller may create H5P activities.',
                ],
                'library_filter' => [
                    'type' => 'string',
                    'description' => 'Optional filter by machine name (e.g. "MultiChoice", "Flashcards", "Accordion").',
                ],
                'include_schema_examples' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include example parameters for selected content types; these are not complete library schemas.',
                    'default' => true,
                ],
            ],
            'required' => ['course_id'],
        ];
    }

    /**
     * Execute the tool using the validated arguments.
     *
     * @param array $params params value.
     */
    public function execute(array $params) {
        global $DB;

        $courseid = (int)($params['course_id'] ?? 0);
        $this->require_course_access($courseid, 'moodle/course:manageactivities');

        if (!$DB->get_manager()->table_exists('h5p_libraries')) {
            return [
                'total_installed_types' => 0,
                'libraries' => [],
                'usage_guide' => 'H5P subsystem is not installed or initialized on this site.',
            ];
        }

        $filter = trim($params['library_filter'] ?? '');
        $includeschema = !isset($params['include_schema_examples']) || !empty($params['include_schema_examples']);

        $sql = "SELECT id, machinename, title, majorversion, minorversion, patchversion, runnable, enabled
                  FROM {h5p_libraries}
                 WHERE runnable = 1 AND enabled = 1";
        $sqlparams = [];

        if (!empty($filter)) {
            $sql .= " AND " . $DB->sql_like('machinename', ':filter', false);
            $sqlparams['filter'] = '%' . $filter . '%';
        }

        $sql .= " ORDER BY machinename ASC, majorversion DESC, minorversion DESC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        if (empty($records)) {
            return [
                'total_installed_types' => 0,
                'libraries' => [],
                'usage_guide' => get_string(
                    !empty($filter) ? 'h5p_no_matching_libraries' : 'h5p_no_available_libraries',
                    'tool_teachermcp'
                ),
            ];
        }

        $libraries = [];
        $seen = [];
        foreach ($records as $rec) {
            if (isset($seen[$rec->machinename])) {
                continue; // Only take latest version.
            }
            $seen[$rec->machinename] = true;

            $item = [
                'machine_name' => $rec->machinename,
                'title' => $rec->title,
                'latest_version' => "{$rec->majorversion}.{$rec->minorversion}",
                'major_version' => (int)$rec->majorversion,
                'minor_version' => (int)$rec->minorversion,
            ];

            if ($includeschema) {
                $example = $this->get_example_params_for_library($rec->machinename);
                if ($example !== null) {
                    $item['example_params'] = $example;
                }
            }

            $libraries[] = $item;
        }

        return [
            'total_installed_types' => count($libraries),
            'libraries' => $libraries,
            'usage_guide' => 'To create an interactive H5P activity, choose a machine_name and pass your customized JSON into create_h5p_activity params.',
        ];
    }

    /**
     * Provide ready-to-use parameter templates for standard H5P content types.
     *
     * @param string $machinename H5P library machine name.
     * @return array|null Example parameters, if supported.
     */
    protected function get_example_params_for_library(string $machinename): ?array {
        switch ($machinename) {
            case 'H5P.MultiChoice':
                return [
                    'question' => '<p>Which organelle is primarily responsible for ATP production in eukaryotic cells?</p>',
                    'answers' => [
                        [
                            'text' => '<div>Mitochondrion</div>',
                            'correct' => true,
                            'tipsAndFeedback' => [
                                'chosenFeedback' => '<div>Correct! Mitochondria generate most of the chemical energy needed by the cell.</div>',
                            ],
                        ],
                        [
                            'text' => '<div>Ribosome</div>',
                            'correct' => false,
                            'tipsAndFeedback' => [
                                'chosenFeedback' => '<div>Incorrect. Ribosomes synthesize proteins.</div>',
                            ],
                        ],
                        [
                            'text' => '<div>Endoplasmic Reticulum</div>',
                            'correct' => false,
                            'tipsAndFeedback' => [
                                'chosenFeedback' => '<div>Incorrect. The ER is involved in protein and lipid synthesis.</div>',
                            ],
                        ],
                    ],
                    'behaviour' => [
                        'enableRetry' => true,
                        'enableSolutionsButton' => true,
                        'singlePoint' => true,
                        'randomAnswers' => true,
                        'showSolutionsRequiresInput' => true,
                    ],
                ];

            case 'H5P.Flashcards':
                return [
                    'description' => '<p>Test your knowledge with these interactive flashcards. Enter your answer and check result.</p>',
                    'cards' => [
                        [
                            'text' => 'The fundamental biological process where plants convert light energy into chemical energy.',
                            'answer' => 'Photosynthesis',
                            'tip' => 'Begins with Photo-',
                        ],
                        [
                            'text' => 'The molecule that carries genetic instructions in all living organisms.',
                            'answer' => 'DNA',
                            'tip' => 'Abbreviation for Deoxyribonucleic acid',
                        ],
                        [
                            'text' => 'The cell division process that produces four genetically diverse haploid daughter cells.',
                            'answer' => 'Meiosis',
                            'tip' => 'Contrasts with mitosis',
                        ],
                    ],
                    'progressText' => 'Card @card of @total',
                    'next' => 'Next',
                    'previous' => 'Previous',
                    'checkAnswerText' => 'Check',
                ];

            case 'H5P.TrueFalse':
                return [
                    'question' => '<p>DNA replication occurs during the S phase of interphase.</p>',
                    'correct' => 'true',
                    'behaviour' => [
                        'enableRetry' => true,
                        'enableSolutionsButton' => true,
                    ],
                    'l10n' => [
                        'trueText' => 'True',
                        'falseText' => 'False',
                    ],
                ];

            case 'H5P.Accordion':
                return [
                    'panels' => [
                        [
                            'title' => '1. Core Concepts & Terminology',
                            'content' => '<p>Overview of fundamental terminology and core concepts explained with examples.</p>',
                        ],
                        [
                            'title' => '2. Practical Application & Case Study',
                            'content' => '<p>Real-world examples, diagrams, and common pitfalls to avoid in practice.</p>',
                        ],
                        [
                            'title' => '3. Key Takeaways & Review',
                            'content' => '<p>Quick summary checklist for revision before the upcoming exam.</p>',
                        ],
                    ],
                ];

            default:
                return null;
        }
    }
}
