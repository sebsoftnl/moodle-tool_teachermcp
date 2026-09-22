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
use moodle_url;
use stdClass;

/**
 * MCP tool to manage course forum discussions, post announcements, and reply to student queries.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_course_forum extends base_tool {

    public function get_name(): string {
        return 'manage_course_forum';
    }

    public function get_description(): string {
        return 'Interact with course forums: list discussions, read posts, post teacher announcements, and reply to student questions.';
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
                    'enum' => ['list_discussions', 'get_discussion', 'post_announcement', 'reply_to_post'],
                    'description' => 'Forum action to perform.',
                ],
                'forum_id' => [
                    'type' => 'integer',
                    'description' => 'Specific forum instance ID (optional; defaults to the course Announcements / News forum).',
                ],
                'discussion_id' => [
                    'type' => 'integer',
                    'description' => 'Discussion thread ID (for get_discussion or reply_to_post).',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Target post ID being replied to (for reply_to_post).',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Subject/title for announcement or discussion topic.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Body text / HTML content of the post or reply.',
                ],
            ],
            'required' => ['course_id', 'action'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $action = $params['action'] ?? 'list_discussions';

        $course = $this->require_course_access($courseid, 'mod/forum:viewdiscussion');
        $context = \context_course::instance($course->id);

        $forumid = (int)($params['forum_id'] ?? 0);
        if ($forumid > 0) {
            $forum = $DB->get_record('forum', ['id' => $forumid, 'course' => $course->id], '*', MUST_EXIST);
        } else {
            $forum = forum_get_course_forum($course->id, 'news');
            if (!$forum) {
                // Fallback to first forum in course
                $forums = $DB->get_records('forum', ['course' => $course->id], 'id ASC', '*', 0, 1);
                $forum = reset($forums);
            }
        }

        if (!$forum) {
            throw new moodle_exception('error_item_not_found', 'tool_teachermcp');
        }

        switch ($action) {
            case 'list_discussions':
                $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'timemodified DESC', '*', 0, 20);
                $list = [];
                foreach ($discussions as $d) {
                    $author = $DB->get_record('user', ['id' => $d->userid], 'id, firstname, lastname');
                    $list[] = [
                        'discussion_id' => (int)$d->id,
                        'subject' => $d->name,
                        'author' => $author ? fullname($author) : 'Unknown',
                        'replies_count' => (int)$DB->count_records('forum_posts', ['discussion' => $d->id]) - 1,
                        'created' => userdate($d->created),
                        'modified' => userdate($d->timemodified),
                    ];
                }
                return [
                    'course_id' => $courseid,
                    'forum_id' => (int)$forum->id,
                    'forum_name' => $forum->name,
                    'discussions' => $list,
                ];

            case 'get_discussion':
                $discid = (int)($params['discussion_id'] ?? 0);
                $discussion = $DB->get_record('forum_discussions', ['id' => $discid, 'forum' => $forum->id], '*', MUST_EXIST);
                $posts = $DB->get_records('forum_posts', ['discussion' => $discussion->id], 'created ASC');
                $thread = [];
                foreach ($posts as $p) {
                    $author = $DB->get_record('user', ['id' => $p->userid], 'id, firstname, lastname');
                    $thread[] = [
                        'post_id' => (int)$p->id,
                        'parent_post_id' => (int)$p->parent,
                        'subject' => $p->subject,
                        'author' => $author ? fullname($author) : 'Unknown',
                        'message' => strip_tags($p->message),
                        'created' => userdate($p->created),
                    ];
                }
                return [
                    'discussion_id' => (int)$discussion->id,
                    'subject' => $discussion->name,
                    'posts' => $thread,
                ];

            case 'post_announcement':
                require_capability('mod/forum:addnews', $context);
                $subject = clean_param($params['subject'] ?? 'Course Announcement', PARAM_TEXT);
                $message = isset($params['message']) ? $this->clean_agent_html($params['message']) : '';

                $discussion = new stdClass();
                $discussion->course = $course->id;
                $discussion->forum = $forum->id;
                $discussion->name = $subject;
                $discussion->intro = $message;
                $discussion->message = $message;
                $discussion->messageformat = FORMAT_HTML;
                $discussion->mailnow = 1;
                $discussion->timemodified = time();

                $discussionid = forum_add_discussion($discussion, null);

                return [
                    'status' => 'success',
                    'forum_id' => (int)$forum->id,
                    'discussion_id' => (int)$discussionid,
                    'subject' => $subject,
                    'message' => 'Announcement posted successfully.',
                ];

            case 'reply_to_post':
                require_capability('mod/forum:replypost', $context);
                $discid = (int)($params['discussion_id'] ?? 0);
                $parentid = (int)($params['post_id'] ?? 0);
                $discussion = $DB->get_record('forum_discussions', ['id' => $discid, 'forum' => $forum->id], '*', MUST_EXIST);

                if ($parentid === 0) {
                    $parentid = (int)$discussion->firstpost;
                }

                $parentpost = $DB->get_record('forum_posts', ['id' => $parentid, 'discussion' => $discussion->id], '*', MUST_EXIST);
                $message = isset($params['message']) ? $this->clean_agent_html($params['message']) : '';
                $subject = !empty($params['subject']) ? clean_param($params['subject'], PARAM_TEXT) : 'Re: ' . $parentpost->subject;

                $post = new stdClass();
                $post->discussion = $discussion->id;
                $post->parent = $parentpost->id;
                $post->userid = $USER->id;
                $post->created = time();
                $post->modified = time();
                $post->subject = $subject;
                $post->message = $message;
                $post->messageformat = FORMAT_HTML;
                $post->messagetrust = 0;
                $post->mailnow = 0;

                $postid = $DB->insert_record('forum_posts', $post);
                $discussion->timemodified = time();
                $discussion->usermodified = $USER->id;
                $DB->update_record('forum_discussions', $discussion);

                return [
                    'status' => 'success',
                    'discussion_id' => (int)$discussion->id,
                    'post_id' => (int)$postid,
                    'subject' => $subject,
                    'message' => 'Reply posted successfully.',
                ];

            default:
                throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', $action);
        }
    }
}
