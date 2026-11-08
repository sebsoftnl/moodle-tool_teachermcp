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
 * MCP tool to extract complete substantive course learning material into LLM-friendly context.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_course_learning_material extends base_tool {
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'export_course_learning_material';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Export permitted course learning material as teacher-view Markdown or JSON, including visible-to-teacher ' .
            'drafts. Visibility flags and availability restrictions are identified; access is not evaluated for ' .
            'individual students. Unsupported extraction and size limits still apply.';
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
                    'description' => 'Moodle course ID to extract learning content from.',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Optional section number (e.g. 2 for Week 2). If omitted, extracts all sections accessible to the calling ' .
                        'teacher, including permitted hidden sections.',
                ],
                'include_glossary' => [
                    'type' => 'boolean',
                    'description' => 'Include course glossary definitions and concepts (default: true).',
                    'default' => true,
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Output format: "markdown" (default, ideal for LLM context) or "json".',
                    'enum' => ['markdown', 'json'],
                    'default' => 'markdown',
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
        $targetsection = isset($params['section_number']) ? (int)$params['section_number'] : null;
        $includeglossary = !empty($params['include_glossary'] ?? true);
        $format = $params['format'] ?? 'markdown';

        // H2: Teachers have course access via enrollment; require manageactivities instead of course:view.
        $course = $this->require_course_access($courseid, 'moodle/course:manageactivities');
        $modinfo = get_fast_modinfo($course);

        // L6: Verify section_number exists if specified.
        if ($targetsection !== null) {
            $sectioninfos = $modinfo->get_section_info_all();
            if (!isset($sectioninfos[$targetsection])) {
                throw new moodle_exception('sectionnotexist', 'error');
            }
        }

        $outsections = [];
        $markdownlines = [];

        $markdownlines[] = "# Course Knowledge Base: {$course->fullname} ({$course->shortname})";
        $markdownlines[] = '> Teacher view: may include unpublished drafts. Student access has not been evaluated; enrolment, ' .
            'groups, dates and availability restrictions still apply.';
        $markdownlines[] = $course->visible ? '> Course visibility: shown.' : '> Hidden course.';
        if (!empty($course->summary)) {
            $markdownlines[] = "\n## Course Summary\n" . trim(html_to_text($course->summary, 0, false));
        }

        foreach ($modinfo->get_section_info_all() as $sectionnum => $secinfo) {
            if (!$secinfo->uservisible) {
                continue;
            }
            if ($targetsection !== null && $sectionnum !== $targetsection) {
                continue;
            }

            $secname = $secinfo->name ?: "Section {$sectionnum}";
            $secdata = [
                'section_number' => $sectionnum,
                'name' => $secname,
                'summary' => trim(html_to_text($secinfo->summary ?? '', 0, false)),
                'visible' => (bool)$secinfo->visible,
                'availability' => $secinfo->availability ? json_decode($secinfo->availability, true, 512, JSON_THROW_ON_ERROR) : null,
                'materials' => [],
            ];

            $markdownlines[] = "\n---\n## Section {$sectionnum}: {$secname}";
            $markdownlines[] = $secinfo->visible ? '> Section visibility: shown.' : '> Hidden section.';
            if ($secinfo->availability) {
                $markdownlines[] = '> Access restrictions configured for this section.';
            }
            if (!empty($secdata['summary'])) {
                $markdownlines[] = "> " . $secdata['summary'];
            }

            if (!empty($modinfo->sections[$sectionnum])) {
                foreach ($modinfo->sections[$sectionnum] as $cmid) {
                    $cm = $modinfo->cms[$cmid];
                    if (!$cm->uservisible) {
                        continue;
                    }

                    $mat = $this->extract_module_content($cm);
                    if ($mat) {
                        $mat['visible'] = (bool)$cm->visible;
                        $mat['visible_on_course_page'] = (bool)$cm->visibleoncoursepage;
                        $mat['availability'] = $cm->availability ? json_decode($cm->availability, true, 512, JSON_THROW_ON_ERROR) : null;
                        $secdata['materials'][] = $mat;
                        $markdownlines[] = "\n### [{$mat['type']}] {$mat['title']}";
                        $markdownlines[] = $cm->visible ? '> Activity visibility: shown.' : '> Hidden activity.';
                        if (!$cm->visibleoncoursepage) {
                            $markdownlines[] = '> Not listed on the course page.';
                        }
                        if ($cm->availability) {
                            $markdownlines[] = '> Access restrictions configured for this activity.';
                        }
                        if (!empty($mat['content'])) {
                            $markdownlines[] = $mat['content'];
                        }
                    }
                }
            }

            $outsections[] = $secdata;
        }

        // L6: Include glossary only from visible modules.
        if ($includeglossary) {
            $glossarycms = $modinfo->get_instances_of('glossary');
            $glossaryitems = [];
            $hasglossary = false;

            foreach ($glossarycms as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $entries = $DB->get_records('glossary_entries', ['glossaryid' => $cm->instance, 'approved' => 1], 'concept ASC');
                if (!empty($entries)) {
                    $hasglossary = true;
                    foreach ($entries as $entry) {
                        $def = trim(html_to_text($entry->definition, 0, false));
                        $glossaryitems[] = [
                            'concept' => $entry->concept,
                            'definition' => $def,
                            'source_cmid' => (int)$cm->id,
                            'source_title' => $cm->name,
                            'source_visible' => (bool)$cm->visible,
                            'source_section_visible' => (bool)$modinfo->get_section_info($cm->sectionnum)->visible,
                            'source_restricted' => !empty($cm->availability)
                                || !empty($modinfo->get_section_info($cm->sectionnum)->availability),
                        ];
                    }
                }
            }

            if ($hasglossary) {
                $markdownlines[] = "\n---\n## Glossary & Core Concepts";
                $lastsource = null;
                foreach ($glossaryitems as $gi) {
                    if ($lastsource !== $gi['source_cmid']) {
                        $markdownlines[] = "\n### {$gi['source_title']}";
                        $markdownlines[] = $gi['source_visible'] ? '> Activity visibility: shown.' : '> Hidden activity.';
                        if (!$gi['source_section_visible']) {
                            $markdownlines[] = '> Hidden section.';
                        }
                        if ($gi['source_restricted']) {
                            $markdownlines[] = '> Access restrictions configured for this glossary or its section.';
                        }
                        $lastsource = $gi['source_cmid'];
                    }
                    $markdownlines[] = "- **{$gi['concept']}**: {$gi['definition']}";
                }
            }
        }

        $maxbytes = 750 * 1024; // 750 KB limit to prevent LLM context overflow.
        if ($format === 'json') {
            $result = [
                'course_id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'audience' => 'teacher',
                'student_access_evaluated' => false,
                'visible' => (bool)$course->visible,
                'sections' => $outsections,
            ];
            $jsonstr = json_encode($result);
            if (strlen($jsonstr) > $maxbytes) {
                $result['warning'] = 'Course materials exceed 750 KB. Consider exporting individual sections using the section_number parameter.';
            }
            return $result;
        }

        $fulltext = implode("\n", $markdownlines);
        $truncated = false;
        if (strlen($fulltext) > $maxbytes) {
            $fulltext = \core_text::str_max_bytes($fulltext, $maxbytes) . "\n\n... [Output truncated at 750 KB. Use the section_number parameter to inspect individual sections.]";
            $truncated = true;
        }

        return [
            'course_id' => $course->id,
            'title' => $course->fullname,
            'format' => 'markdown',
            'audience' => 'teacher',
            'student_access_evaluated' => false,
            'content' => $fulltext,
            'truncated' => $truncated,
        ];
    }

    /**
     * Extract substantive content for a specific module type.
     */
    protected function extract_module_content(\cm_info $cm): ?array {
        global $DB;

        switch ($cm->modname) {
            case 'page':
                $page = $DB->get_record('page', ['id' => $cm->instance]);
                if (!$page) {
                    return null;
                }
                $clean = trim(html_to_text($page->content, 0, false));
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Page',
                    'title' => $page->name,
                    'content' => $clean,
                ];

            case 'book':
                $book = $DB->get_record('book', ['id' => $cm->instance]);
                if (!$book) {
                    return null;
                }
                $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id, 'hidden' => 0], 'pagenum ASC');
                $chaptexts = [];
                foreach ($chapters as $chap) {
                    $chaptexts[] = "#### Chapter: " . $chap->title . "\n" . trim(html_to_text($chap->content, 0, false));
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Book',
                    'title' => $book->name,
                    'content' => implode("\n\n", $chaptexts),
                ];

            case 'lesson':
                $lesson = $DB->get_record('lesson', ['id' => $cm->instance]);
                if (!$lesson) {
                    return null;
                }

                // L6: Traverse lesson pages in true linked-list order via prevpageid / nextpageid.
                $allpages = $DB->get_records('lesson_pages', ['lessonid' => $lesson->id]);
                $pagesbyid = [];
                $firstpageid = null;
                foreach ($allpages as $lp) {
                    $pagesbyid[$lp->id] = $lp;
                    if ((int)$lp->prevpageid === 0) {
                        $firstpageid = $lp->id;
                    }
                }

                $orderedpages = [];
                $curr = $firstpageid;
                $visited = [];
                while ($curr && isset($pagesbyid[$curr]) && !isset($visited[$curr])) {
                    $visited[$curr] = true;
                    $orderedpages[] = $pagesbyid[$curr];
                    $curr = (int)$pagesbyid[$curr]->nextpageid;
                }
                foreach ($allpages as $lp) {
                    if (!isset($visited[$lp->id])) {
                        $orderedpages[] = $lp;
                    }
                }

                $lessontexts = [];
                foreach ($orderedpages as $lp) {
                    $lessontexts[] = "#### " . $lp->title . "\n" . trim(html_to_text($lp->contents, 0, false));
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Lesson',
                    'title' => $lesson->name,
                    'content' => implode("\n\n", $lessontexts),
                ];

            case 'assign':
                $assign = $DB->get_record('assign', ['id' => $cm->instance]);
                if (!$assign) {
                    return null;
                }
                $text = trim(html_to_text($assign->intro ?? '', 0, false));
                if (!empty($assign->activity)) {
                    $text .= "\n\nInstructions:\n" . trim(html_to_text($assign->activity, 0, false));
                }

                // Check for attached case studies, rubrics, or assignment files.
                $attachedfiles = \tool_teachermcp\file_extractor::get_module_files($cm->id);
                if (!empty($attachedfiles)) {
                    $text .= "\n\n**Attached Source Files:**";
                    foreach ($attachedfiles as $af) {
                        $dl = \tool_teachermcp\file_extractor::get_download_url($af);
                        $sz = display_size($af->get_filesize());
                        $text .= "\n- `{$af->get_filename()}` ({$sz}) — [Download Source File]({$dl})";

                        if (\tool_teachermcp\file_extractor::is_extractable($af)) {
                            $ext = \tool_teachermcp\file_extractor::extract_text($af, 20000);
                            if ($ext['success'] && !empty($ext['content'])) {
                                $text .= "\n\n#### Document Content (`{$af->get_filename()}`):\n" . $ext['content'];
                            }
                            if (!empty($ext['notice'])) {
                                $text .= "\n\n*(Note: {$ext['notice']})*";
                            }
                        }
                    }
                }

                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Assignment Brief',
                    'title' => $assign->name,
                    'content' => $text,
                ];

            case 'quiz':
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
                if (!$quiz) {
                    return null;
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Quiz',
                    'title' => $quiz->name,
                    'content' => trim(html_to_text($quiz->intro ?? '', 0, false)),
                ];

            case 'h5pactivity':
                return $this->extract_h5p_activity($cm);

            case 'hvp':
                return $this->extract_hvp_activity($cm);

            case 'forum':
                $forum = $DB->get_record('forum', ['id' => $cm->instance]);
                if (!$forum) {
                    return null;
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Forum',
                    'title' => $forum->name,
                    'content' => trim(html_to_text($forum->intro ?? '', 0, false)),
                ];

            case 'resource':
                $resource = $DB->get_record('resource', ['id' => $cm->instance]);
                if (!$resource) {
                    return null;
                }
                $desc = trim(html_to_text($resource->intro ?? '', 0, false));
                $rescontent = !empty($desc) ? $desc : '';

                $resfiles = \tool_teachermcp\file_extractor::get_module_files($cm->id);
                if (!empty($resfiles)) {
                    foreach ($resfiles as $rf) {
                        $dl = \tool_teachermcp\file_extractor::get_download_url($rf);
                        $sz = display_size($rf->get_filesize());
                        $fileline = "**Source File**: `{$rf->get_filename()}` ({$sz}) — [Download Source File]({$dl})";
                        $rescontent = empty($rescontent) ? $fileline : "{$rescontent}\n\n{$fileline}";

                        if (\tool_teachermcp\file_extractor::is_extractable($rf)) {
                            $ext = \tool_teachermcp\file_extractor::extract_text($rf, 30000);
                            if ($ext['success'] && !empty($ext['content'])) {
                                $rescontent .= "\n\n#### Extracted Document Content:\n" . $ext['content'];
                            }
                            if (!empty($ext['notice'])) {
                                $rescontent .= "\n\n*(Note: {$ext['notice']})*";
                            }
                        }
                    }
                }

                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'File Resource',
                    'title' => $resource->name,
                    'content' => $rescontent,
                ];

            case 'folder':
                $folder = $DB->get_record('folder', ['id' => $cm->instance]);
                if (!$folder) {
                    return null;
                }
                $folderdesc = trim(html_to_text($folder->intro ?? '', 0, false));
                $folderfiles = \tool_teachermcp\file_extractor::get_module_files($cm->id);
                $filelines = [];
                foreach ($folderfiles as $ff) {
                    $dl = \tool_teachermcp\file_extractor::get_download_url($ff);
                    $sz = display_size($ff->get_filesize());
                    $line = "- `{$ff->get_filename()}` ({$sz}) — [Download Source File]({$dl})";
                    if (\tool_teachermcp\file_extractor::is_extractable($ff)) {
                        $ext = \tool_teachermcp\file_extractor::extract_text($ff, 15000);
                        if ($ext['success'] && !empty($ext['content'])) {
                            $line .= "\n  Document text preview:\n  " . str_replace("\n", "\n  ", $ext['content']);
                        }
                        if (!empty($ext['notice'])) {
                            $line .= "\n  *(Note: {$ext['notice']})*";
                        }
                    }
                    $filelines[] = $line;
                }
                $content = $folderdesc;
                if (!empty($filelines)) {
                    $content = (empty($content) ? '' : $content . "\n\n") . "**Folder Documents:**\n" . implode("\n", $filelines);
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'Folder',
                    'title' => $folder->name,
                    'content' => $content,
                ];

            case 'url':
                $url = $DB->get_record('url', ['id' => $cm->instance]);
                if (!$url) {
                    return null;
                }
                return [
                    'cmid' => (int)$cm->id,
                    'type' => 'External Link',
                    'title' => $url->name,
                    'content' => "URL: {$url->externalurl}\n" . trim(html_to_text($url->intro ?? '', 0, false)),
                ];

            default:
                if (!empty($cm->content)) {
                    return [
                        'cmid' => (int)$cm->id,
                        'type' => ucfirst($cm->modname),
                        'title' => $cm->name,
                        'content' => trim(html_to_text($cm->content, 0, false)),
                    ];
                }
                return null;
        }
    }

    /**
     * Extract substantive content and questions from core mod_h5pactivity packages.
     */
    protected function extract_h5p_activity(\cm_info $cm): ?array {
        global $DB;

        $h5pact = $DB->get_record('h5pactivity', ['id' => $cm->instance]);
        if (!$h5pact) {
            return null;
        }

        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);

        $h5precord = null;
        $libname = 'H5P Interactive Activity';

        if (!empty($files)) {
            $packagefile = reset($files);
            $pathnamehash = $packagefile->get_pathnamehash();
            $h5precord = $DB->get_record('h5p', ['pathnamehash' => $pathnamehash]);
            if (!$h5precord) {
                $h5precord = $DB->get_record('h5p', ['contenthash' => $packagefile->get_contenthash()]);
            }
        }

        $contentlines = [];
        if (!empty($h5pact->intro)) {
            $contentlines[] = "Description: " . trim(html_to_text($h5pact->intro, 0, false));
        }

        if ($h5precord) {
            if (!empty($h5precord->mainlibraryid)) {
                $lib = $DB->get_record('h5p_libraries', ['id' => $h5precord->mainlibraryid]);
                if ($lib) {
                    $libname = $lib->title ?: $lib->machinename;
                }
            }

            if (!empty($h5precord->jsoncontent)) {
                $decoded = json_decode($h5precord->jsoncontent, true);
                if ($decoded) {
                    $collected = [];
                    $this->extract_text_from_h5p_json($decoded, $collected);
                    $uniquecollected = array_unique($collected);
                    if (!empty($uniquecollected)) {
                        $contentlines[] = "Substantive H5P Learning Content & Questions:\n- " . implode("\n- ", $uniquecollected);
                    }
                }
            }
        }

        return [
            'cmid' => (int)$cm->id,
            'type' => "H5P ({$libname})",
            'title' => $h5pact->name,
            'content' => implode("\n\n", $contentlines),
        ];
    }

    /**
     * Extract substantive content from legacy/plugin mod_hvp instances.
     */
    protected function extract_hvp_activity(\cm_info $cm): ?array {
        global $DB;

        if (!$DB->get_manager()->table_exists('hvp')) {
            return null;
        }

        $hvp = $DB->get_record('hvp', ['id' => $cm->instance]);
        if (!$hvp) {
            return null;
        }

        $libname = 'H5P Activity';
        if (!empty($hvp->main_library_id) && $DB->get_manager()->table_exists('hvp_libraries')) {
            $lib = $DB->get_record('hvp_libraries', ['id' => $hvp->main_library_id]);
            if ($lib) {
                $libname = $lib->title ?: $lib->machine_name;
            }
        }

        $contentlines = [];
        if (!empty($hvp->intro)) {
            $contentlines[] = "Description: " . trim(html_to_text($hvp->intro, 0, false));
        }

        if (!empty($hvp->json_content)) {
            $decoded = json_decode($hvp->json_content, true);
            if ($decoded) {
                $collected = [];
                $this->extract_text_from_h5p_json($decoded, $collected);
                $uniquecollected = array_unique($collected);
                if (!empty($uniquecollected)) {
                    $contentlines[] = "Substantive H5P Learning Content & Questions:\n- " . implode("\n- ", $uniquecollected);
                }
            }
        }

        return [
            'cmid' => (int)$cm->id,
            'type' => "H5P ({$libname})",
            'title' => $hvp->name,
            'content' => implode("\n\n", $contentlines),
        ];
    }

    /**
     * Recursively traverse H5P parameter trees to discover didactic text and questions.
     */
    protected function extract_text_from_h5p_json($node, array &$collected): void {
        if (is_string($node)) {
            $trimmed = trim(html_to_text($node, 0, false));
            if (strlen($trimmed) >= 4 && !preg_match('/^(true|false|null|[0-9\.\-\_\s]+)$/i', $trimmed)) {
                $collected[] = $trimmed;
            }
            return;
        }

        if (is_array($node)) {
            foreach ($node as $key => $val) {
                if (in_array($key, ['path', 'mime', 'copyright', 'subContentId', 'disable'], true)) {
                    continue;
                }
                $this->extract_text_from_h5p_json($val, $collected);
            }
        }
    }
}
