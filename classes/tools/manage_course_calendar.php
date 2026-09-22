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
use stdClass;

/**
 * MCP tool to manage course calendar events and schedule deadlines/sessions.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_course_calendar extends base_tool {

    public function get_name(): string {
        return 'manage_course_calendar';
    }

    public function get_description(): string {
        return 'Manage course calendar: list scheduled events, create office hours or review sessions, and schedule deadlines.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Target Moodle course ID.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['list_events', 'create_event', 'delete_event'],
                    'description' => 'Calendar action to perform.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Event title/name (for create_event).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Event description or meeting link (for create_event).',
                ],
                'timestart' => [
                    'type' => 'integer',
                    'description' => 'Unix timestamp for event start time (for create_event).',
                ],
                'duration_minutes' => [
                    'type' => 'integer',
                    'description' => 'Duration in minutes (for create_event, default: 60).',
                ],
                'event_id' => [
                    'type' => 'integer',
                    'description' => 'Event ID (for delete_event).',
                ],
            ],
            'required' => ['course_id', 'action'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $action = $params['action'] ?? 'list_events';

        $course = $this->require_course_access($courseid, 'moodle/calendar:manageentries');

        switch ($action) {
            case 'list_events':
                $now = time() - 86400; // Past 24h to future
                $events = $DB->get_records_select(
                    'event',
                    'courseid = :cid AND timestart >= :start',
                    ['cid' => $course->id, 'start' => $now],
                    'timestart ASC',
                    '*',
                    0,
                    30
                );
                $list = [];
                foreach ($events as $e) {
                    $list[] = [
                        'event_id' => (int)$e->id,
                        'name' => $e->name,
                        'description' => strip_tags($e->description),
                        'timestart' => (int)$e->timestart,
                        'timestart_readable' => userdate($e->timestart),
                        'timeduration_minutes' => (int)round($e->timeduration / 60),
                        'eventtype' => $e->eventtype,
                    ];
                }
                return [
                    'course_id' => (int)$course->id,
                    'events' => $list,
                ];

            case 'create_event':
                $name = clean_param($params['name'] ?? 'Course Event', PARAM_TEXT);
                $desc = isset($params['description']) ? $this->clean_agent_html($params['description']) : '';
                $timestart = !empty($params['timestart']) ? (int)$params['timestart'] : time() + 3600;
                $durationmins = isset($params['duration_minutes']) ? max(0, (int)$params['duration_minutes']) : 60;

                $eventdata = new stdClass();
                $eventdata->name = $name;
                $eventdata->description = $desc;
                $eventdata->format = FORMAT_HTML;
                $eventdata->courseid = $course->id;
                $eventdata->groupid = 0;
                $eventdata->userid = $USER->id;
                $eventdata->modulename = '';
                $eventdata->instance = 0;
                $eventdata->eventtype = 'course';
                $eventdata->timestart = $timestart;
                $eventdata->timeduration = $durationmins * 60;
                $eventdata->visible = 1;

                $event = \calendar_event::create($eventdata, false);

                return [
                    'status' => 'success',
                    'event_id' => (int)$event->id,
                    'name' => $name,
                    'timestart' => userdate($timestart),
                    'duration_minutes' => $durationmins,
                    'message' => "Course event '{$name}' created successfully.",
                ];

            case 'delete_event':
                $eventid = (int)($params['event_id'] ?? 0);
                $eventrec = $DB->get_record('event', ['id' => $eventid, 'courseid' => $course->id], '*', MUST_EXIST);
                $event = \calendar_event::load($eventrec);
                $event->delete();

                return [
                    'status' => 'success',
                    'event_id' => $eventid,
                    'message' => 'Event deleted successfully.',
                ];

            default:
                throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', $action);
        }
    }
}
