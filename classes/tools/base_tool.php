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
use context_course;
use context_module;
use stdClass;

/**
 * Base abstract class for all Teacher MCP tools.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_tool {

    /** @var string Tool name property for array_column and catalog compatibility */
    public string $name = '';

    /**
     * Constructor initializing tool properties.
     */
    public function __construct() {
        $this->name = $this->get_name();
    }

    /**
     * Get the unique tool identifier name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Get the human/LLM-readable description of what this tool does.
     *
     * @return string
     */
    abstract public function get_description(): string;

    /**
     * Get JSON schema defining the tool input parameters.
     *
     * @return array
     */
    abstract public function get_input_schema(): array;

    /**
     * Execute the tool with validated parameters.
     *
     * @param array $params Input arguments provided by the LLM agent.
     * @return mixed Tool execution result (serializable to JSON).
     * @throws moodle_exception On validation or authorization failures.
     */
    abstract public function execute(array $params);

    /**
     * Helper to verify teacher permissions on a course (H7, M1, B1).
     * Strictly verifies tool/teachermcp:connectagent in this course context.
     *
     * @param int $courseid Course ID
     * @param string|array|null $capability Additional capability or capabilities required (e.g. moodle/course:update)
     * @return stdClass Course object.
     * @throws moodle_exception If not enrolled or lacks capability.
     */
    protected function require_course_access(int $courseid, string|array|null $capability = null): stdClass {
        global $DB, $USER;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        // H7: Always require connectagent capability in this course context.
        require_capability('tool/teachermcp:connectagent', $context);

        // H2: Require active enrolment (onlyactive = true) or moodle/course:view.
        if (!is_enrolled($context, $USER->id, '', true) && !has_capability('moodle/course:view', $context)) {
            throw new moodle_exception('error_not_enrolled', 'tool_teachermcp');
        }

        if ($capability !== null) {
            $caps = is_array($capability) ? $capability : [$capability];
            foreach ($caps as $cap) {
                require_capability($cap, $context);
            }
        }

        return $course;
    }

    /**
     * Helper to verify teacher permissions on a course module (H7, M1, B1, N1).
     *
     * @param int|stdClass $cmorid Course module ID or cm_info/stdClass object
     * @param string|array|null $capability Additional capability or capabilities required
     * @param string $modulename Optional module name (e.g. 'page', 'book', 'quiz') to validate
     * @return array [$cm, $course, $context]
     * @throws moodle_exception If module does not exist, wrong type, or lacks capability.
     */
    protected function require_cm_access(int|stdClass $cmorid, string|array|null $capability = null, string $modulename = ''): array {
        if (is_object($cmorid)) {
            $cm = $cmorid;
            $course = get_course((int)$cm->course);
            if ($modulename && $cm->modname !== $modulename) {
                throw new moodle_exception('invalidcoursemoduleid', 'error', '', $cm->id);
            }
        } else {
            list($course, $cm) = get_course_and_cm_from_cmid((int)$cmorid, $modulename);
        }
        $context = context_module::instance($cm->id);

        // Module capabilities can remain assigned after an enrolment is suspended.
        // Keep module-bound tools consistent with require_course_access().
        $coursecontext = context_course::instance($course->id);
        global $USER;
        if (!is_enrolled($coursecontext, $USER->id, '', true) && !has_capability('moodle/course:view', $coursecontext)) {
            throw new moodle_exception('error_not_enrolled', 'tool_teachermcp');
        }

        // H7: Always require connectagent capability in this context.
        require_capability('tool/teachermcp:connectagent', $context);

        if ($capability !== null) {
            $caps = is_array($capability) ? $capability : [$capability];
            foreach ($caps as $cap) {
                require_capability($cap, $context);
            }
        }

        return [$cm, $course, $context];
    }

    /**
     * Clean HTML supplied by an AI agent or user (N2).
     *
     * @param string $html
     * @return string
     */
    protected function clean_agent_html(string $html): string {
        return clean_text($html, FORMAT_HTML);
    }
}
