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
use core\message\message;
use tool_teachermcp\auth;

/**
 * MCP tool to send direct messages to enrolled students via Moodle messaging.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_student_message extends base_tool {

    public function get_name(): string {
        return 'send_student_message';
    }

    public function get_description(): string {
        return 'Send a direct notification/message to an enrolled student through Moodle messaging.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Course ID context in which the message is sent.',
                ],
                'student_user_id' => [
                    'type' => 'integer',
                    'description' => 'Target student user ID.',
                ],
                'message_text' => [
                    'type' => 'string',
                    'description' => 'The message text content to send.',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Optional group ID to validate student membership.',
                ],
            ],
            'required' => ['course_id', 'student_user_id', 'message_text'],
        ];
    }

    public function execute(array $params) {
        global $USER, $DB;

        $courseid = (int)($params['course_id'] ?? 0);
        $studentid = (int)($params['student_user_id'] ?? 0);
        $messagetext = trim($params['message_text'] ?? '');
        $targetgroupid = !empty($params['group_id']) ? (int)$params['group_id'] : 0;

        if (!$courseid) {
            throw new moodle_exception('missingparam', 'error', '', 'course_id');
        }

        if (!$studentid) {
            throw new moodle_exception('missingparam', 'error', '', 'student_user_id');
        }

        if (empty($messagetext)) {
            throw new moodle_exception('missingparam', 'error', '', 'message_text');
        }

        // M3 & H1: Verify sender course access and messaging capabilities.
        $course = $this->require_course_access($courseid, ['moodle/site:sendmessage', 'moodle/course:viewparticipants']);
        $context = \context_course::instance($course->id);

        $recipient = $DB->get_record('user', ['id' => $studentid, 'deleted' => 0], '*', MUST_EXIST);

        // M3: Verify recipient is an actively enrolled student in this course.
        if (!is_enrolled($context, $recipient->id, '', true)) {
            throw new moodle_exception('error_not_enrolled', 'tool_teachermcp');
        }

        // Must be a student (cannot be an editing teacher or course manager).
        if (has_capability('moodle/course:manageactivities', $context, $recipient->id)) {
            throw new moodle_exception('error_invalid_user', 'tool_teachermcp');
        }

        // M2: Group isolation.
        $groupmode = groups_get_course_groupmode($course);
        $canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
        if ($groupmode == SEPARATEGROUPS && !$canaccessallgroups) {
            $teachergroups = groups_get_user_groups($course->id, $USER->id)[0] ?? [];
            if (empty($teachergroups)) {
                throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
            }

            if ($targetgroupid > 0) {
                if (!in_array($targetgroupid, $teachergroups)) {
                    throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                }
                if (!groups_is_member($targetgroupid, $recipient->id)) {
                    throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                }
            } else {
                $studentgroups = groups_get_user_groups($course->id, $recipient->id)[0] ?? [];
                if (empty(array_intersect($teachergroups, $studentgroups))) {
                    throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                }
            }
        }

        // M3: Check user privacy/contact settings.
        if (!\core_message\api::can_send_message($recipient->id, $USER->id)) {
            throw new moodle_exception('error_cannot_message_user', 'tool_teachermcp');
        }

        // M3: Rate limit checking per hour.
        $cache = \cache::make('tool_teachermcp', 'ratelimit');
        $token = auth::get_current_token();
        $tokenkey = $token ? 'tok_' . $token->id : 'usr_' . $USER->id;
        $history = $cache->get('msg_' . $tokenkey) ?: [];
        $now = time();

        if (is_array($history)) {
            $history = array_filter($history, function($ts) use ($now) {
                return ($now - $ts) < 3600;
            });
        } else {
            $history = [];
        }

        $maxperhour = (int)get_config('tool_teachermcp', 'message_ratelimit_per_hour') ?: 50;
        if (count($history) >= $maxperhour) {
            throw new moodle_exception('error_rate_limited', 'tool_teachermcp');
        }

        $history[] = $now;
        $cache->set('msg_' . $tokenkey, $history);

        // Send message.
        $msg = new message();
        $msg->courseid = $courseid;
        $msg->component = 'moodle';
        $msg->name = 'instantmessage';
        $msg->userfrom = $USER;
        $msg->userto = $recipient;
        $msg->subject = get_string('message_subject_from', 'tool_teachermcp', fullname($USER));
        $msg->fullmessage = $messagetext;
        $msg->fullmessageformat = FORMAT_MARKDOWN;
        $msg->fullmessagehtml = markdown_to_html($messagetext);
        $msg->smallmessage = shorten_text(strip_tags($messagetext), 64);
        $msg->notification = 0;

        $messageid = message_send($msg);

        return [
            'success' => true,
            'message_id' => $messageid,
            'course_id' => $course->id,
            'recipient_id' => (int)$recipient->id,
            'recipient_name' => fullname($recipient),
            'sent_at' => $now,
            'sent_at_iso' => date('c', $now),
        ];
    }
}
