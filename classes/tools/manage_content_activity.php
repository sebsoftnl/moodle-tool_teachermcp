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
 * MCP tool to create, inspect, and update core Moodle content activities: Page (mod_page) and Book (mod_book).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_content_activity extends base_tool {
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'manage_content_activity';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Creates or updates core Moodle content activities: Pages (mod_page) and Books (mod_book with multiple ' .
            'chapters and subchapters). Supports rich HTML formatting, tables, callout boxes, and embedded media ' .
            'assets uploaded via upload_course_asset.';
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
                'action' => [
                    'type' => 'string',
                    'description' => 'Operation to perform.',
                    'enum' => [
                        'create_page',
                        'update_page',
                        'create_book',
                        'add_book_chapter',
                        'update_book_chapter',
                        'get_content',
                    ],
                ],
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Target course ID (required when creating new activities).',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Course section/topic index where activity should be placed (default: 0).',
                    'default' => 0,
                ],
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module ID (required when updating or reading existing page/book).',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Activity title (for page or book) or chapter title.',
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'Brief introductory description visible on the course page.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Rich HTML content of the page or book chapter.',
                ],
                'chapter_title' => [
                    'type' => 'string',
                    'description' => 'Title of the book chapter (when adding or updating chapters).',
                ],
                'chapter_id' => [
                    'type' => 'integer',
                    'description' => 'Specific chapter ID within a book (when updating an existing chapter).',
                ],
                'is_subchapter' => [
                    'type' => 'boolean',
                    'description' => 'Whether this book chapter is a subchapter (level 2) under the preceding chapter.',
                    'default' => false,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Activity visibility to students (true = visible, false = hidden; default: false).',
                    'default' => false,
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * Execute the tool using the validated arguments.
     *
     * @param array $params params value.
     */
    public function execute(array $params) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $action = $params['action'] ?? '';

        switch ($action) {
            case 'create_page':
                return $this->create_page($params);

            case 'update_page':
                return $this->update_page($params);

            case 'create_book':
                return $this->create_book($params);

            case 'add_book_chapter':
                return $this->add_book_chapter($params);

            case 'update_book_chapter':
                return $this->update_book_chapter($params);

            case 'get_content':
                return $this->get_content($params);

            default:
                throw new moodle_exception('invalidaction', 'error');
        }
    }

    /**
     * Create a new mod_page activity.
     */
    protected function create_page(array $params): array {
        global $CFG, $DB;

        $courseid = (int)($params['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'course_id');
        }

        $course = $this->require_course_access($courseid, 'mod/page:addinstance');
        $title = isset($params['title']) ? clean_param($params['title'], PARAM_TEXT) : 'Untitled Page';
        $intro = isset($params['intro']) ? $this->clean_agent_html($params['intro']) : '';
        $content = isset($params['content']) ? $this->clean_agent_html($params['content']) : '<p>Content in preparation.</p>';
        $sectionnum = (int)($params['section_number'] ?? 0);

        // N5: Verify section exists.
        $modinfo = get_fast_modinfo($course);
        $sectioninfos = $modinfo->get_section_info_all();
        if (!isset($sectioninfos[$sectionnum])) {
            throw new moodle_exception('sectionnotexist', 'error');
        }

        // N5: Activities are hidden by default (visible = 0).
        $visible = !empty($params['visible']) ? 1 : 0;
        if ($visible === 1) {
            $context = \context_course::instance($course->id);
            require_capability('moodle/course:activityvisibility', $context);
        }

        // N5: Use core create_module() API.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'page';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $title;
        $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->page = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->content = $content;
        $moduleinfo->contentformat = FORMAT_HTML;
        $moduleinfo->display = 0;
        $moduleinfo->printheading = 1;
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        $moduleinfo->visible = $visible;
        $moduleinfo->visibleold = $visible;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;

        $created = create_module($moduleinfo);
        $cmid = (int)$created->coursemodule;
        $pageid = (int)$created->instance;

        return [
            'status' => 'success',
            'activity_type' => 'page',
            'cmid' => $cmid,
            'visible' => (bool)$DB->get_field('course_modules', 'visible', ['id' => $cmid], MUST_EXIST),
            'page_id' => $pageid,
            'title' => $title,
            'course_id' => (int)$course->id,
            'section_number' => $sectionnum,
            'view_url' => (new moodle_url('/mod/page/view.php', ['id' => $cmid]))->out(false),
            'message' => "Page '{$title}' created successfully.",
        ];
    }

    /**
     * Update an existing mod_page activity.
     */
    protected function update_page(array $params): array {
        global $DB;

        $cmid = (int)($params['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'cmid');
        }

        // N1: get_course_and_cm_from_cmid checking 'page' module type followed by require_cm_access.
        [$cm, $course, $context] = $this->require_cm_access($cmid, 'moodle/course:manageactivities', 'page');
        if (isset($params['visible'])) {
            require_capability('moodle/course:activityvisibility', $context);
        }
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        if (isset($params['title']) && trim($params['title']) !== '') {
            $page->name = clean_param($params['title'], PARAM_TEXT);
        }
        if (isset($params['intro'])) {
            $page->intro = $this->clean_agent_html($params['intro']);
        }
        if (isset($params['content'])) {
            $page->content = $this->clean_agent_html($params['content']);
        }
        $page->revision++;
        $page->timemodified = time();

        $DB->update_record('page', $page);

        if (isset($params['visible'])) {
            set_coursemodule_visible($cm->id, $params['visible'] ? 1 : 0);
        }

        \course_modinfo::purge_course_module_cache($course->id, $cm->id);
        rebuild_course_cache($course->id, true);

        return [
            'status' => 'success',
            'activity_type' => 'page',
            'cmid' => (int)$cm->id,
            'visible' => (bool)$DB->get_field('course_modules', 'visible', ['id' => $cm->id], MUST_EXIST),
            'title' => $page->name,
            'revision' => (int)$page->revision,
            'message' => "Page '{$page->name}' updated successfully.",
        ];
    }

    /**
     * Create a new mod_book activity with an optional initial chapter.
     */
    protected function create_book(array $params): array {
        global $CFG, $DB;

        $courseid = (int)($params['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'course_id');
        }

        // N5: Require mod/book:addinstance and mod/book:edit.
        $course = $this->require_course_access($courseid, ['mod/book:addinstance', 'mod/book:edit']);
        $title = isset($params['title']) ? clean_param($params['title'], PARAM_TEXT) : 'Untitled Book';
        $intro = isset($params['intro']) ? $this->clean_agent_html($params['intro']) : '';
        $firsttitle = isset($params['first_chapter_title']) ? clean_param($params['first_chapter_title'], PARAM_TEXT)
            : (isset($params['chapter_title']) ? clean_param($params['chapter_title'], PARAM_TEXT) : '1. Introduction');
        $firstcontent = isset($params['first_chapter_content']) ? $this->clean_agent_html($params['first_chapter_content'])
            : (isset($params['content']) ? $this->clean_agent_html($params['content']) : '<p>Welcome to this book.</p>');
        $sectionnum = (int)($params['section_number'] ?? 0);

        // N5: Verify section exists.
        $modinfo = get_fast_modinfo($course);
        $sectioninfos = $modinfo->get_section_info_all();
        if (!isset($sectioninfos[$sectionnum])) {
            throw new moodle_exception('sectionnotexist', 'error');
        }

        // N5: Activities are hidden by default (visible = 0).
        $visible = !empty($params['visible']) ? 1 : 0;
        if ($visible === 1) {
            $context = \context_course::instance($course->id);
            require_capability('moodle/course:activityvisibility', $context);
        }

        // N5: Use core create_module() API.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'book';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $title;
        $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->numbering = 1;
        $moduleinfo->navstyle = 1;
        $moduleinfo->customtitles = 0;
        $moduleinfo->visible = $visible;
        $moduleinfo->visibleold = $visible;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;

        $created = create_module($moduleinfo);
        $cmid = (int)$created->coursemodule;
        $bookid = (int)$created->instance;
        $modcontext = \context_module::instance($cmid);

        // Insert first chapter.
        $chapter = new stdClass();
        $chapter->bookid = $bookid;
        $chapter->pagenum = 1;
        $chapter->subchapter = 0;
        $chapter->title = $firsttitle;
        $chapter->content = $firstcontent;
        $chapter->contentformat = FORMAT_HTML;
        $chapter->hidden = 0;
        $chapter->importsrc = '';
        $chapter->timecreated = time();
        $chapter->timemodified = time();

        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        require_once($CFG->dirroot . '/mod/book/locallib.php');
        $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);
        \mod_book\event\chapter_created::create_from_chapter($book, $modcontext, $chapter)->trigger();
        book_preload_chapters($book);

        \course_modinfo::purge_course_module_cache($course->id, $cmid);
        rebuild_course_cache($course->id, true);

        return [
            'status' => 'success',
            'activity_type' => 'book',
            'cmid' => $cmid,
            'visible' => (bool)$DB->get_field('course_modules', 'visible', ['id' => $cmid], MUST_EXIST),
            'book_id' => $bookid,
            'first_chapter_id' => (int)$chapter->id,
            'title' => $title,
            'course_id' => (int)$course->id,
            'section_number' => $sectionnum,
            'view_url' => (new moodle_url('/mod/book/view.php', ['id' => $cmid]))->out(false),
            'message' => "Book '{$title}' created successfully with first chapter '{$firsttitle}'.",
        ];
    }

    /**
     * Add a new chapter to an existing Book.
     */
    protected function add_book_chapter(array $params): array {
        global $CFG, $DB;

        $cmid = (int)($params['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'cmid');
        }

        // N1: Module validation via get_course_and_cm_from_cmid('book') and mod/book:edit capability.
        [$cm, $course, $context] = $this->require_cm_access($cmid, 'mod/book:edit', 'book');
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);

        $chaptertitle = isset($params['chapter_title']) ? clean_param($params['chapter_title'], PARAM_TEXT)
            : (isset($params['title']) ? clean_param($params['title'], PARAM_TEXT) : 'New Chapter');
        $content = isset($params['content']) ? $this->clean_agent_html($params['content']) : '<p>Chapter content.</p>';
        $issub = !empty($params['is_subchapter']) ? 1 : 0;

        // Find next pagenum.
        $maxpage = (int)$DB->get_field_sql('SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?', [$book->id]);
        $pagenum = $maxpage + 1;

        $chapter = new stdClass();
        $chapter->bookid = $book->id;
        $chapter->pagenum = $pagenum;
        $chapter->subchapter = $issub;
        $chapter->title = $chaptertitle;
        $chapter->content = $content;
        $chapter->contentformat = FORMAT_HTML;
        $chapter->hidden = 0;
        $chapter->importsrc = '';
        $chapter->timecreated = time();
        $chapter->timemodified = time();

        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        // Bump book revision.
        $DB->execute('UPDATE {book} SET revision = revision + 1, timemodified = ? WHERE id = ?', [time(), $book->id]);

        // N1: Trigger chapter_created event and refresh book cache.
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        \mod_book\event\chapter_created::create_from_chapter($book, $context, $chapter)->trigger();
        book_preload_chapters($book);

        \course_modinfo::purge_course_module_cache($course->id, $cm->id);
        rebuild_course_cache($course->id, true);

        return [
            'status' => 'success',
            'activity_type' => 'book',
            'cmid' => (int)$cm->id,
            'visible' => (bool)$DB->get_field('course_modules', 'visible', ['id' => $cm->id], MUST_EXIST),
            'book_id' => (int)$book->id,
            'chapter_id' => (int)$chapter->id,
            'chapter_title' => $chaptertitle,
            'page_number' => $pagenum,
            'is_subchapter' => (bool)$issub,
            'message' => "Chapter '{$chaptertitle}' added to Book '{$book->name}'.",
        ];
    }

    /**
     * Update an existing chapter in a Book.
     */
    protected function update_book_chapter(array $params): array {
        global $CFG, $DB;

        $cmid = (int)($params['cmid'] ?? 0);
        $chapterid = (int)($params['chapter_id'] ?? 0);

        if ($cmid <= 0 || $chapterid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'cmid and chapter_id');
        }

        // N1: Module validation via get_course_and_cm_from_cmid('book') and mod/book:edit capability.
        [$cm, $course, $context] = $this->require_cm_access($cmid, 'mod/book:edit', 'book');
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $chapter = $DB->get_record('book_chapters', ['id' => $chapterid, 'bookid' => $book->id], '*', MUST_EXIST);

        if (isset($params['chapter_title']) && trim($params['chapter_title']) !== '') {
            $chapter->title = clean_param($params['chapter_title'], PARAM_TEXT);
        }
        if (isset($params['content'])) {
            $chapter->content = $this->clean_agent_html($params['content']);
        }
        if (isset($params['is_subchapter'])) {
            $chapter->subchapter = $params['is_subchapter'] ? 1 : 0;
        }
        $chapter->timemodified = time();

        $DB->update_record('book_chapters', $chapter);
        $DB->execute('UPDATE {book} SET revision = revision + 1, timemodified = ? WHERE id = ?', [time(), $book->id]);

        // N1: Trigger chapter_updated event and refresh book cache.
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        \mod_book\event\chapter_updated::create_from_chapter($book, $context, $chapter)->trigger();
        book_preload_chapters($book);

        \course_modinfo::purge_course_module_cache($course->id, $cm->id);
        rebuild_course_cache($course->id, true);

        return [
            'status' => 'success',
            'activity_type' => 'book',
            'cmid' => (int)$cm->id,
            'visible' => (bool)$DB->get_field('course_modules', 'visible', ['id' => $cm->id], MUST_EXIST),
            'chapter_id' => (int)$chapter->id,
            'chapter_title' => $chapter->title,
            'message' => "Chapter '{$chapter->title}' updated successfully.",
        ];
    }

    /**
     * Get content of a page or book.
     */
    protected function get_content(array $params): array {
        global $DB;

        $cmid = (int)($params['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'cmid');
        }

        // N1 & H2: Use require_cm_access with moodle/course:manageactivities (not moodle/course:view).
        [$cm, $course, $context] = $this->require_cm_access($cmid, 'moodle/course:manageactivities');
        if ($cm->modname !== 'page' && $cm->modname !== 'book') {
            throw new moodle_exception('invalidcoursemoduleid', 'error', '', $cm->id);
        }

        if ($cm->modname === 'page') {
            $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
            return [
                'type' => 'page',
                'cmid' => (int)$cm->id,
                'title' => $page->name,
                'intro' => $page->intro,
                'content' => $page->content,
                'revision' => (int)$page->revision,
            ];
        } else {
            $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
            $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum ASC');
            $chapterlist = [];
            foreach ($chapters as $c) {
                $chapterlist[] = [
                    'chapter_id' => (int)$c->id,
                    'page_number' => (int)$c->pagenum,
                    'is_subchapter' => (bool)$c->subchapter,
                    'title' => $c->title,
                    'content' => $c->content,
                ];
            }
            return [
                'type' => 'book',
                'cmid' => (int)$cm->id,
                'title' => $book->name,
                'intro' => $book->intro,
                'revision' => (int)$book->revision,
                'total_chapters' => count($chapterlist),
                'chapters' => $chapterlist,
            ];
        }
    }
}
