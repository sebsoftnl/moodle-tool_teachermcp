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

use context_course;
use context_module;
use stdClass;

/**
 * MCP Resources Registry providing dynamic read-only URI resources to AI agents.
 *
 * Implements MCP resources/list, resources/templates/list, and resources/read.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_registry {

    /** Maximum number of announcements returned from one resource. */
    private const ANNOUNCEMENT_LIMIT = 5;

    /**
     * List all concrete static resources.
     *
     * @return array[]
     */
    public static function get_resources(): array {
        return [
            [
                'uri' => 'moodle://courses',
                'name' => 'My Teaching Courses',
                'description' => 'Real-time list of courses where the teacher has an active teaching role.',
                'mimeType' => 'application/json',
            ],
        ];
    }

    /**
     * List all parameterized resource templates.
     *
     * @return array[]
     */
    public static function get_templates(): array {
        return [
            [
                'uriTemplate' => 'moodle://course/{course_id}/syllabus',
                'name' => 'Course Syllabus & Structure',
                'description' => 'Detailed sections, activity outline, and completion requirements in Markdown.',
                'mimeType' => 'text/markdown',
            ],
            [
                'uriTemplate' => 'moodle://course/{course_id}/announcements',
                'name' => 'Course Announcements',
                'description' => 'Latest news and announcements posted to the course announcements forum.',
                'mimeType' => 'text/markdown',
            ],
            [
                'uriTemplate' => 'moodle://activity/{cmid}/rubric',
                'name' => 'Activity Grading Rubric',
                'description' => 'Existing grading method and rubric title/description. Use prepare_grading_session for full criteria.',
                'mimeType' => 'application/json',
            ],
        ];
    }

    /**
     * Read a resource by its URI.
     *
     * @param string $uri The target resource URI.
     * @return array|null Resource content array or null if not found.
     */
    public static function read_resource(string $uri): ?array {
        global $DB, $USER, $CFG;

        $uri = trim($uri);

        // 1. Static: moodle://courses
        if ($uri === 'moodle://courses') {
            require_once($CFG->dirroot . '/course/lib.php');
            $courses = enrol_get_my_courses(['id', 'shortname', 'fullname', 'summary', 'format', 'visible']);
            $list = [];
            foreach ($courses as $c) {
                $context = context_course::instance($c->id);
                if (self::can_access_course($context)) {
                    $list[] = [
                        'id' => (int)$c->id,
                        'shortname' => $c->shortname,
                        'fullname' => $c->fullname,
                        'format' => $c->format,
                        'visible' => (bool)$c->visible,
                        'uri_syllabus' => "moodle://course/{$c->id}/syllabus",
                        'uri_announcements' => "moodle://course/{$c->id}/announcements",
                    ];
                }
            }
            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode(['courses' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ];
        }

        // 2. Dynamic: moodle://course/{course_id}/syllabus
        if (preg_match('#^moodle://course/([0-9]+)/syllabus$#', $uri, $matches)) {
            $courseid = (int)$matches[1];
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return null;
            }
            $context = context_course::instance($courseid);
            if (!self::can_access_course($context)) {
                return null;
            }

            require_once($CFG->dirroot . '/course/lib.php');
            $modinfo = get_fast_modinfo($course);
            $sections = $modinfo->get_section_info_all();

            $md = "# Course Syllabus: {$course->fullname} ({$course->shortname})\n\n";
            if (!empty($course->summary)) {
                $md .= "## Summary\n" . clean_text($course->summary) . "\n\n";
            }
            $md .= "## Course Structure\n\n";

            foreach ($sections as $section) {
                $sectionname = get_section_name($course, $section);
                $md .= "### Section {$section->section}: {$sectionname}\n";
                if (!empty($section->summary)) {
                    $md .= strip_tags($section->summary) . "\n\n";
                }

                if (!empty($modinfo->sections[$section->section])) {
                    foreach ($modinfo->sections[$section->section] as $cmid) {
                        $cm = $modinfo->cms[$cmid];
                        if (!$cm->uservisible) {
                            continue;
                        }
                        $status = $cm->visible ? 'Visible' : 'Hidden';
                        $md .= "- **{$cm->name}** (`{$cm->modname}`, cmid: {$cm->id}) — *{$status}*\n";
                    }
                } else {
                    $md .= "*No activities in this section.*\n";
                }
                $md .= "\n";
            }

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/markdown',
                        'text' => $md,
                    ],
                ],
            ];
        }

        // 3. Dynamic: moodle://course/{course_id}/announcements
        if (preg_match('#^moodle://course/([0-9]+)/announcements$#', $uri, $matches)) {
            $courseid = (int)$matches[1];
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return null;
            }
            $context = context_course::instance($courseid);
            if (!self::can_access_course($context)) {
                return null;
            }

            require_once($CFG->dirroot . '/mod/forum/lib.php');
            $newsforums = $DB->get_records('forum', ['course' => $courseid, 'type' => 'news'], 'id ASC', '*', 0, 1);
            $newsforum = reset($newsforums);
            if (!$newsforum) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'text/markdown',
                            'text' => "# Announcements: {$course->fullname}\n\n*No announcements forum configured for this course.*",
                        ],
                    ],
                ];
            }

            $cm = get_fast_modinfo($course)->instances['forum'][$newsforum->id];
            if (!$cm->uservisible) {
                return null;
            }

            $firstposts = forum_get_discussions(
                $cm,
                'd.timemodified DESC',
                limit: self::ANNOUNCEMENT_LIMIT,
                groupid: FORUM_POSTS_ALL_USER_GROUPS
            );
            $md = "# Announcements: {$course->fullname}\n\n";

            if (empty($firstposts)) {
                $md .= "*No announcements have been posted yet.*\n";
            } else {
                foreach ($firstposts as $post) {
                    $date = userdate($post->created);

                    $md .= "## {$post->name}\n";
                    $md .= "**Posted:** {$date}\n\n";
                    $md .= strip_tags($post->message) . "\n\n";
                    $md .= "---\n\n";
                }
            }

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/markdown',
                        'text' => $md,
                    ],
                ],
            ];
        }

        // 4. Dynamic: moodle://activity/{cmid}/rubric
        if (preg_match('#^moodle://activity/([0-9]+)/rubric$#', $uri, $matches)) {
            $cmid = (int)$matches[1];
            $cm = get_coursemodule_from_id('', $cmid);
            if (!$cm) {
                return null;
            }
            $context = context_module::instance($cmid);
            if (!self::can_access_module($cm, $context)) {
                return null;
            }

            require_once($CFG->dirroot . '/grade/grading/lib.php');
            $gradingmanager = get_grading_manager($context, 'mod_' . $cm->modname, 'submissions');
            $controller = $gradingmanager ? $gradingmanager->get_active_controller() : null;

            if (!$controller) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode([
                                'cmid' => $cmid,
                                'activity_name' => $cm->name,
                                'has_advanced_grading' => false,
                                'message' => 'This activity uses simple direct grading (no active rubric or guide).',
                            ], JSON_PRETTY_PRINT),
                        ],
                    ],
                ];
            }

            $definition = $controller->get_definition();
            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode([
                            'cmid' => $cmid,
                            'activity_name' => $cm->name,
                                'method' => $gradingmanager->get_active_method(),
                            'rubric_title' => $definition ? $definition->name : '',
                            'rubric_description' => $definition ? $definition->description : '',
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * Check the Teacher MCP course-level access contract without revealing why access failed.
     *
     * A Teacher MCP user needs active enrolment and the plugin connection capability.
     * Resource content is read-only and deliberately available to both teacher and
     * editingteacher roles; individual mutation tools enforce their own stronger
     * capabilities.
     *
     * @param context_course $context Course context.
     * @return bool True when the current user may access Teacher MCP course resources.
     */
    private static function can_access_course(context_course $context): bool {
        global $USER;

        if (is_siteadmin()) {
            return true;
        }
        return is_enrolled($context, $USER->id, '', true)
            && has_capability('tool/teachermcp:connectagent', $context);
    }

    /**
     * Check the Teacher MCP module-level access contract.
     *
     * @param stdClass $cm Course module record.
     * @param context_module $context Module context.
     * @return bool True when the current user may access Teacher MCP module resources.
     */
    private static function can_access_module(stdClass $cm, context_module $context): bool {
        global $USER;

        if (is_siteadmin()) {
            return true;
        }
        $coursecontext = context_course::instance((int)$cm->course);
        return is_enrolled($coursecontext, $USER->id, '', true)
            && has_capability('tool/teachermcp:connectagent', $context);
    }
}
