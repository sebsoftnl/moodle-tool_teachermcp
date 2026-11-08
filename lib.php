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
 * Navigation callbacks for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add link to user profile page for teacher AI agent dashboard.
 *
 * @param \core_user\output\myprofile\tree $tree Profile tree object.
 * @param stdClass $user Target user object.
 * @param bool $iscurrentuser True if viewing own profile.
 * @param stdClass|null $course Course context if viewing from a course.
 */
function tool_teachermcp_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course = null) {
    if ($iscurrentuser && \tool_teachermcp\auth::user_can_connect($user->id)) {
        $url = new moodle_url('/admin/tool/teachermcp/my_agent.php');
        $node = new \core_user\output\myprofile\node(
            'miscellaneous',
            'tool_teachermcp_myagent',
            get_string('dashboard_title', 'tool_teachermcp'),
            null,
            $url
        );
        $tree->add_node($node);
    }
}

/**
 * Extend user preferences navigation to add teacher AI agent link.
 *
 * @param navigation_node $navigation
 * @param stdClass $user
 * @param context_user $usercontext
 * @param stdClass|null $course
 * @param context_course|null $coursecontext
 */
function tool_teachermcp_extend_navigation_user_settings(navigation_node $navigation, stdClass $user, $usercontext, $course, $coursecontext) {
    global $PAGE, $USER;

    if ($USER->id == $user->id && \tool_teachermcp\auth::user_can_connect($user->id)) {
        $url = new moodle_url('/admin/tool/teachermcp/my_agent.php');
        $node = navigation_node::create(
            get_string('dashboard_title', 'tool_teachermcp'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'tool_teachermcp_myagent_pref',
            new pix_icon('i/settings', '')
        );
        $usernode = $navigation->find('useraccount', navigation_node::TYPE_CONTAINER);
        if ($usernode) {
            $usernode->add_node($node);
        } else {
            $navigation->add_node($node);
        }
    }
}

/**
 * Add link into course secondary/tertiary navigation for teachers.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function tool_teachermcp_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (has_capability('tool/teachermcp:connectagent', $context)) {
        $url = new moodle_url('/admin/tool/teachermcp/my_agent.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('dashboard_title', 'tool_teachermcp'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'teachermcp_course'
        );
    }
}

/**
 * Serves plugin files for tool_teachermcp (such as uploaded course assets).
 *
 * @param stdClass $course Course object.
 * @param stdClass|null $cm Course module object.
 * @param context $context Context.
 * @param string $filearea File area name.
 * @param array $args File path arguments.
 * @param bool $forcedownload Force download flag.
 * @param array $options Additional options.
 * @return bool False on failure; sends file and terminates on success.
 */
function tool_teachermcp_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_COURSE) {
        return false;
    }

    if ($filearea !== 'course_assets') {
        return false;
    }

    // Staged course assets are private to teachers, including when a learner knows the URL.
    require_capability('moodle/course:manageactivities', $context);
    require_capability('moodle/course:managefiles', $context);
    require_course_login($course);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'tool_teachermcp', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Only safe raster images (PNG, JPEG, GIF, WEBP) are served inline. All others force download.
    $safeimagemimetypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!in_array($file->get_mimetype(), $safeimagemimetypes, true)) {
        $forcedownload = true;
    }

    send_stored_file($file, 86400, 0, $forcedownload, $options);
    return false;
}
