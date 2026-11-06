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

/**
 * Assessment failure injection and rollback observation fixtures.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Test-only observation and failure scope, never loaded by product bootstrap.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_faults {
    /** Native entity tables retained in detail for rollback comparisons. */
    private const ENTITY_TABLES = [
        'course', 'course_sections', 'course_modules', 'context', 'course_format_options',
        'question_categories', 'question', 'question_answers', 'question_truefalse', 'question_bank_entries',
        'question_versions', 'question_references', 'question_set_references', 'qbank',
        'quiz', 'quiz_slots', 'quiz_sections', 'quiz_feedback', 'quiz_overrides', 'quiz_grades', 'quiz_attempts', 'quiz_grade_items',
        'grade_items', 'grade_categories', 'grade_grades', 'grade_items_history', 'grade_categories_history', 'grade_grades_history',
        'event', 'files', 'files_reference', 'task_adhoc', 'course_modules_completion', 'course_completion_criteria',
        'tag_instance', 'logstore_standard_log',
    ];

    /** @var array|null Currently observed operation and failure phase. */
    private static ?array $scope = null;
    /** @var array Invocation counts per phase. */
    private static array $hits = [];
    /** @var array Native boundary observations. */
    private static array $observations = [];
    /** @var assessment_injected_failure|null Exception to inject at the requested phase. */
    private static ?assessment_injected_failure $failure = null;

    /**
     * A null phase observes without throwing; the returned exception identifies an intentional failure exactly.
     *
     * @param string $operation Public tool operation under test.
     * @param int $courseid Only native work in this course is observed.
     * @param string|null $phase Failure phase, or null for characterization.
     * @param int $occurrence Phase invocation at which to fail.
     * @return assessment_injected_failure|null The exact exception instance that will be thrown.
     */
    public static function arm(
        string $operation,
        int $courseid,
        ?string $phase = null,
        int $occurrence = 1
    ): ?assessment_injected_failure {
        self::$scope = ['operation' => $operation, 'courseid' => $courseid, 'phase' => $phase, 'occurrence' => $occurrence];
        self::$hits = [];
        self::$observations = [];
        self::$failure = $phase === null ? null : new assessment_injected_failure('Injected assessment failure after ' . $phase);
        return self::$failure;
    }

    /**
     * Disarm the failure scope and discard observations.
     *
     * @return void
     */
    public static function clear(): void {
        self::$scope = null;
        self::$hits = [];
        self::$observations = [];
        self::$failure = null;
    }

    /**
     * Whether an observation or failure scope is active.
     *
     * @return bool Whether a scope is active.
     */
    public static function is_armed(): bool {
        return self::$scope !== null;
    }

    /**
     * Whether the active scope observes the given operation.
     *
     * @param string $operation Operation under observation.
     * @return bool Whether it is observed.
     */
    public static function observes(string $operation): bool {
        return self::$scope !== null && self::$scope['operation'] === $operation;
    }

    /**
     * Return observations recorded at native boundaries.
     *
     * @return array Native boundary observations.
     */
    public static function observations(): array {
        return self::$observations;
    }

    /**
     * Return invocation counts per native boundary.
     *
     * @return array Boundary invocation counts.
     */
    public static function hits(): array {
        return self::$hits;
    }

    /**
     * Record a native boundary and optionally inject a failure.
     *
     * @param string $phase Native boundary phase.
     * @param int $courseid Course being modified.
     * @param array $ids Native entity identifiers.
     * @return void
     */
    public static function hit(string $phase, int $courseid, array $ids): void {
        global $DB;
        if (self::$scope === null || self::$scope['courseid'] !== $courseid) {
            return;
        }
        self::$hits[$phase] = (self::$hits[$phase] ?? 0) + 1;
        $observation = ['phase' => $phase, 'occurrence' => self::$hits[$phase], 'ids' => $ids];
        if (self::$scope['phase'] === $phase && self::$scope['occurrence'] === self::$hits[$phase]) {
            $observation['transaction_active'] = $DB->is_transaction_started();
            $observation['modinfo'] = self::modinfo($courseid);
            $observation['snapshot'] = self::snapshot();
            self::$observations[] = $observation;
            throw self::$failure;
        }
        self::$observations[] = $observation;
    }

    /**
     * Find the course owning a question category.
     *
     * @param int $categoryid Question category ID.
     * @return int Owning course ID.
     */
    public static function category_course(int $categoryid): int {
        global $DB;
        $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid], MUST_EXIST);
        return (int)\context::instance_by_id($contextid)->get_course_context()->instanceid;
    }

    /**
     * Observe an authorized question in the create flow.
     *
     * @param int $questionid Authorized question ID.
     * @return void
     */
    public static function question_authorized(int $questionid): void {
        global $DB;
        if (!self::observes('create')) {
            return;
        }
        $categoryid = $DB->get_field_sql('SELECT qbe.questioncategoryid
                FROM {question_versions} qv JOIN {question_bank_entries} qbe ON qbe.id=qv.questionbankentryid
               WHERE qv.questionid=:id', ['id' => $questionid], MUST_EXIST);
        self::hit('question', self::category_course((int)$categoryid), ['questionid' => $questionid]);
    }

    /**
     * Fingerprint every table without exporting credential rows; retain detailed native assessment relationships.
     *
     * @return array Deterministically ordered row fingerprints and assessment entities.
     */
    public static function snapshot(): array {
        global $DB;
        $tables = array_values($DB->get_tables(false));
        sort($tables, SORT_STRING);
        $result = ['fingerprints' => [], 'entities' => []];
        foreach ($tables as $table) {
            $encoded = [];
            $details = [];
            $retain = in_array($table, self::ENTITY_TABLES, true);
            $records = $DB->get_recordset($table);
            try {
                foreach ($records as $record) {
                    $row = (array)$record;
                    ksort($row, SORT_STRING);
                    $encoded[] = serialize($row);
                    if ($retain) {
                        $details[(int)$row['id']] = $row;
                    }
                }
            } finally {
                $records->close();
            }
            sort($encoded, SORT_STRING);
            $result['fingerprints'][$table] = ['rows' => count($encoded), 'sha256' => hash('sha256', serialize($encoded))];
            if ($retain) {
                ksort($details, SORT_NUMERIC);
                $result['entities'][$table] = array_values($details);
            }
        }
        return $result;
    }

    /**
     * Snapshot course module IDs and section sequences.
     *
     * @param int $courseid Course ID.
     * @return array Module IDs and section sequences.
     */
    public static function modinfo(int $courseid): array {
        $info = get_fast_modinfo($courseid);
        $cmids = array_map('intval', array_keys($info->cms));
        sort($cmids, SORT_NUMERIC);
        $sections = $info->sections;
        ksort($sections, SORT_NUMERIC);
        foreach ($sections as &$sequence) {
            $sequence = array_map('intval', array_values($sequence));
        }
        unset($sequence);
        return ['cmids' => $cmids, 'sections' => $sections];
    }
}

require_once(__DIR__ . '/assessment_injected_failure.php');
require_once(__DIR__ . '/faulting_scaffold.php');
