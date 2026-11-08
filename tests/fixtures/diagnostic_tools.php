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

/**
 * Diagnostic provider and tool fixtures for tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Constants and provider declarations for diagnostic tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_fixtures {
    /** Private exception detail that must not leak to clients. */
    public const PRIVATE_MARKER = 'QA_DIAGNOSTIC_PRIVATE_DETAIL';
    /** Expected built-in tool names. */
    public const BUILTINS = [
        'get_my_profile', 'get_teacher_courses', 'export_course_learning_material', 'get_course_contents',
        'scaffold_assessment_unit', 'detect_at_risk_students', 'analyze_class_understanding', 'prepare_grading_session',
        'manage_activity', 'grade_submission', 'manage_grades', 'get_group_students', 'manage_course_completion',
        'send_student_message', 'manage_question_bank', 'duplicate_activity', 'read_activity_file', 'delete_activity',
        'upload_course_asset', 'manage_content_activity', 'get_h5p_libraries', 'create_h5p_activity',
        'create_assignment', 'manage_course_calendar',
    ];

    /**
     * Describe valid and invalid external provider fixtures.
     *
     * @return array Provider descriptor with valid and invalid tools.
     */
    public static function providers(): array {
        $common = ['scope' => 'read', 'capability' => 'tool/teachermcp:manageall',
            'enabled_setting' => 'tool_teachermcp/enable_diagnostic_fixture'];
        return ['api_version' => 1, 'provider_id' => 'tool_teachermcp', 'tools' => [
            ['id' => 'diagnostic_good', 'class' => diagnostic_external::class] + $common,
            ['id' => 'diagnostic_bad', 'class' => diagnostic_invalid_external::class] + $common,
        ]];
    }
}

require_once(__DIR__ . '/diagnostic_builtin.php');
require_once(__DIR__ . '/diagnostic_constructor_failure.php');
require_once(__DIR__ . '/diagnostic_name_failure.php');
require_once(__DIR__ . '/diagnostic_description_failure.php');
require_once(__DIR__ . '/diagnostic_schema_failure.php');
require_once(__DIR__ . '/diagnostic_wrong_name.php');
require_once(__DIR__ . '/diagnostic_invalid_builtin.php');
require_once(__DIR__ . '/diagnostic_external.php');
require_once(__DIR__ . '/diagnostic_invalid_external.php');
