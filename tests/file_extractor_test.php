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

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/content_files.php');

use tool_teachermcp\tests\fixtures\content_files;

/**
 * Stored-file extraction contracts using local binary fixtures.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_extractor_test extends \advanced_testcase {
    public function test_text_formats_preserve_unicode_and_html_distinction(): void {
        $context = $this->fixture_context();
        $text = "Local caf\u{00e9} \u{03a9} \u{65e5}\u{672c}\u{8a9e}";
        $formats = ['txt' => $text, 'md' => '# ' . $text, 'markdown' => '# ' . $text,
            'csv' => "label,value\nname,{$text}", 'tsv' => "label\t{$text}",
            'json' => json_encode(['message' => $text], JSON_UNESCAPED_UNICODE), 'xml' => '<doc>' . $text . '</doc>',
            'html' => '<p>' . $text . '</p>', 'htm' => '<p>' . $text . '</p>'];
        foreach ($formats as $extension => $content) {
            $file = $this->file($context, 'unicode.' . $extension, $content);
            $result = file_extractor::extract_text($file);
            $this->assertTrue(file_extractor::is_extractable($file));
            $this->assertTrue($result['success'], $extension);
            $this->assertNull($result['notice']);
            $this->assertStringContainsString($text, $result['content']);
            $this->assertTrue(mb_check_encoding($result['content'], 'UTF-8'));
            if (in_array($extension, ['html', 'htm'], true)) {
                $this->assertSame($text, $result['content']);
            } else if ($extension === 'xml') {
                $this->assertSame($content, $result['content'], 'XML is returned as text, not parsed as a document model.');
            }
        }
    }

    public function test_character_budget_preserves_complete_unicode_characters(): void {
        $context = $this->fixture_context();
        $text = str_repeat("\u{03a9}\u{1f331}", 10);
        foreach ([11, 12, 13] as $length) {
            $content = mb_substr($text, 0, $length);
            $file = $this->file($context, "length-{$length}.txt", $content);
            $result = file_extractor::extract_text($file, 12);
            $expected = $length > 12 ? mb_substr($content, 0, 12) . "\n\n... [Content truncated at 12 characters]" : $content;
            $this->assertTrue($result['success']);
            $this->assertSame($expected, $result['content']);
            $this->assertTrue(mb_check_encoding($result['content'], 'UTF-8'));
        }
    }

    public function test_office_and_complete_pdf_fixtures_extract_known_text(): void {
        $context = $this->fixture_context();
        foreach (
            ['docx' => 'This is a test', 'pptx' => 'Sample presentation',
                'odt' => "Local ODT caf\u{00e9} \u{03a9}"] as $extension => $expected
        ) {
            $file = $this->file($context, 'document.' . $extension, content_files::office($extension));
            $result = file_extractor::extract_text($file);
            $this->assertTrue($result['success'], $extension . ': ' . ($result['notice'] ?? ''));
            $this->assertNull($result['notice']);
            $this->assertStringContainsString($expected, $result['content']);
        }
        foreach ([false, true] as $compressed) {
            $pdf = content_files::pdf([content_files::pdf_text('Native PDF text (local)')], $compressed);
            $file = $this->file($context, 'text-' . (int)$compressed . '.pdf', $pdf);
            $result = file_extractor::extract_text($file);
            $this->assertTrue($result['success']);
            $this->assertSame('Native PDF text (local)', $result['content']);
            $this->assertNull($result['notice']);
        }
    }

    public function test_unsupported_and_malformed_files_report_extraction_failure(): void {
        $context = $this->fixture_context();
        $fixtures = [
            'unsupported.bin' => 'Local unsupported bytes',
            'image.png' => content_files::image(),
            'scanned.pdf' => content_files::pdf(['q 100 0 0 100 10 10 cm /Im0 Do Q'], false, true),
            'invalid.docx' => 'Not a ZIP archive',
            'missing.docx' => content_files::package(['unrelated.xml' => '<document/>']),
            'empty.docx' => content_files::package(['word/document.xml' => '']),
            'invalid.odt' => 'Not a ZIP archive',
            'missing.odt' => content_files::package(['unrelated.xml' => '<document/>']),
            'empty.odt' => content_files::package(['content.xml' => '']),
            'empty.pptx' => content_files::package(['[Content_Types].xml' => '<Types/>']),
        ];
        foreach ($fixtures as $name => $content) {
            $result = file_extractor::extract_text($this->file($context, $name, $content));
            $this->assertFalse($result['success'], $name);
            $this->assertSame('', $result['content'], $name);
            $this->assertNotEmpty($result['notice'], $name . ' must explain the unsupported/limited outcome.');
        }
    }

    public function test_discovery_preserves_safe_material_and_excludes_assignment_private_files(): void {
        $this->fixture_context();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assignment = $generator->create_module('assign', ['course' => $course->id]);
        $context = \context_module::instance($assignment->cmid);
        $intro = content_files::store($context, 'brief.txt', 'Teaching brief', 'mod_assign', 'introattachment');
        content_files::store($context, 'submission.txt', 'Private submission', 'assignsubmission_file', 'submission_files', 1);
        content_files::store($context, 'feedback.txt', 'Private feedback', 'assignfeedback_file', 'feedback_files', 1);
        $files = file_extractor::get_module_files($assignment->cmid);
        $this->assertSame([(int)$intro->get_id()], array_map(static fn($file) => (int)$file->get_id(), $files));
        foreach (
            [['question', 'questiontext'], ['question', 'generalfeedback'], ['mod_page', 'content'],
                ['mod_book', 'chapter'], ['mod_resource', 'content'], ['mod_folder', 'content']] as [$component, $area]
        ) {
            $this->assertTrue(file_extractor::is_allowed_filearea($component, $area));
        }
        $this->assertFalse(file_extractor::is_allowed_filearea('mod_forum', 'attachment'));
    }

    public function test_file_size_limit_accepts_boundary_and_rejects_larger_files(): void {
        $context = $this->fixture_context();
        foreach ([-1, 0, 1] as $offset) {
            $size = file_extractor::MAX_EXTRACT_SIZE + $offset;
            $file = $this->file($context, "size-{$size}.txt", str_repeat('x', $size));
            $this->assertSame($size, (int)$file->get_filesize());
            $result = file_extractor::extract_text($file, 1000);
            $this->assertSame($offset <= 0, $result['success'], 'Decoded stored-file byte boundary');
            if ($offset > 0) {
                $this->assertSame('', $result['content']);
                $this->assertStringContainsString('maximum size', $result['notice']);
            } else {
                $this->assertStringStartsWith(str_repeat('x', 1000), $result['content']);
                $this->assertStringContainsString('[Content truncated at 1000 characters]', $result['content']);
            }
        }
    }

    public function test_question_response_areas_are_not_teaching_material(): void {
        $this->assertFalse(
            file_extractor::is_allowed_filearea('question', 'response_attachments'),
            'Student answer attachments must not enter the learning-material whitelist.'
        );
        $this->assertFalse(file_extractor::is_allowed_filearea('question', 'response_answer'));
        $this->assertTrue(file_extractor::is_allowed_filearea('question', 'questiontext'));
        $this->assertTrue(file_extractor::is_allowed_filearea('question', 'generalfeedback'));
    }

    public function test_discovery_keeps_teaching_files_but_excludes_stored_student_responses(): void {
        global $CFG, $DB;
        $this->fixture_context();
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => $context->id]);
        $question = $questions->create_question('essay', null, ['category' => $category->id,
            'responseformat' => 'editorfilepicker', 'attachments' => 2]);
        \quiz_add_quiz_question($question->id, $quiz);
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $teaching = content_files::store($context, 'teaching.txt', 'Public teaching text', 'question', 'questiontext', $question->id);
        $this->setUser($student);
        $attempt = $generator->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $student->id);
        $usage = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $stepid = (int)$usage->get_question_attempt(1)->get_last_step()->get_id();
        $this->assertTrue($DB->record_exists('question_attempt_steps', ['id' => $stepid]));
        foreach (['response_attachments', 'response_answer'] as $area) {
            $file = content_files::store($context, $area . '.txt', 'PRIVATE STUDENT RESPONSE', 'question', $area, $stepid);
            $this->assertSame((int)$student->id, (int)$file->get_userid());
        }
        $this->setUser($teacher);
        $files = file_extractor::get_module_files($quiz->cmid);
        $this->assertSame(
            [(int)$teaching->get_id()],
            array_map(static fn($file) => (int)$file->get_id(), $files),
            'Discover actual teaching files, never files belonging to the student response step.'
        );
        $result = (new \tool_teachermcp\tools\read_activity_file())->execute(['cmid' => (int)$quiz->cmid]);
        $this->assertArrayHasKey('selected_file', $result);
        $this->assertSame('Public teaching text', $result['selected_file']['content_text']);
        $this->assertStringNotContainsString('response_attachments.txt', json_encode($result));
        $this->assertStringNotContainsString('response_answer.txt', json_encode($result));
        $this->assertStringNotContainsString('PRIVATE STUDENT RESPONSE', json_encode($result));
    }

    public function test_docx_and_odt_required_entry_size_boundaries(): void {
        $context = $this->fixture_context();
        foreach (['docx' => 'word/document.xml', 'odt' => 'content.xml'] as $extension => $entry) {
            foreach ([-1, 0, 1] as $offset) {
                $size = file_extractor::MAX_ZIP_ENTRY_SIZE + $offset;
                $xml = content_files::office_xml($extension, 'Entry boundary text', $size);
                $this->assertSame($size, strlen($xml));
                $bytes = content_files::package([$entry => $xml]);
                unset($xml);
                $this->assertLessThan(file_extractor::MAX_EXTRACT_SIZE, strlen($bytes));
                $file = $this->file($context, "entry-{$size}.{$extension}", $bytes);
                $result = file_extractor::extract_text($file);
                $this->assertSame($offset <= 0, $result['success'], "{$extension}: {$size}");
                if ($offset > 0) {
                    $this->assertSame('', $result['content']);
                    $this->assertNotEmpty($result['notice']);
                } else {
                    $this->assertSame('Entry boundary text', $result['content']);
                }
            }
        }
    }

    public function test_pdf_stream_count_accepts_supported_boundary(): void {
        $context = $this->fixture_context();
        foreach ([499, 500] as $count) {
            $streams = array_map(static fn($index) => content_files::pdf_text("Stream {$index}"), range(1, $count));
            $file = $this->file($context, "streams-{$count}.pdf", content_files::pdf($streams));
            $result = file_extractor::extract_text($file);
            $this->assertTrue($result['success']);
            $this->assertSame($count, substr_count($result['content'], 'Stream '));
            $this->assertStringContainsString("Stream {$count}", $result['content']);
            $this->assertNull($result['notice']);
        }
    }

    public function test_pdf_decoded_stream_accepts_supported_size_boundary(): void {
        $context = $this->fixture_context();
        $text = content_files::pdf_text('Within decoded budget');
        foreach ([-1, 0] as $offset) {
            $size = file_extractor::MAX_DECOMPRESS_SIZE + $offset;
            $stream = str_repeat(' ', $size - strlen($text)) . $text;
            $file = $this->file($context, "decoded-{$size}.pdf", content_files::pdf([$stream], true));
            unset($stream);
            $result = file_extractor::extract_text($file);
            $this->assertTrue($result['success'], 'A small text block inside a supported decoded stream must remain readable.');
            $this->assertSame('Within decoded budget', $result['content']);
            $this->assertNull($result['notice']);
        }
    }

    public function test_pptx_entry_size_boundary_preserves_supported_slides(): void {
        $context = $this->fixture_context();
        foreach ([-1, 0, 1] as $offset) {
            $size = file_extractor::MAX_ZIP_ENTRY_SIZE + $offset;
            $xml = content_files::office_xml('pptx', 'Slide boundary text', $size);
            $bytes = content_files::package(['ppt/slides/slide1.xml' => $xml]);
            unset($xml);
            $file = $this->file($context, "slide-{$size}.pptx", $bytes);
            $result = file_extractor::extract_text($file);
            $this->assertSame($offset <= 0, $result['success']);
            if ($offset > 0) {
                $this->assertSame('', $result['content']);
                $this->assertNotEmpty($result['notice']);
            } else {
                $this->assertStringContainsString('Slide boundary text', $result['content']);
                $this->assertNull($result['notice']);
            }
        }
    }

    public function test_pptx_skipped_oversize_slide_reports_partial_extraction(): void {
        $context = $this->fixture_context();
        $oversize = content_files::office_xml('pptx', 'Skipped slide sentinel', file_extractor::MAX_ZIP_ENTRY_SIZE + 1);
        $bytes = content_files::package([
            'ppt/slides/slide1.xml' => content_files::office_xml('pptx', 'Readable slide'),
            'ppt/slides/slide2.xml' => $oversize,
        ]);
        unset($oversize);
        $result = file_extractor::extract_text($this->file($context, 'partial.pptx', $bytes));
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Readable slide', $result['content']);
        $this->assertStringNotContainsString('Skipped slide sentinel', $result['content']);
        $this->assertNotEmpty($result['notice'], 'Omitting an oversized slide must be disclosed as partial extraction.');
    }

    public function test_pdf_stream_count_overflow_reports_partial_extraction(): void {
        $context = $this->fixture_context();
        $streams = array_map(static fn($index) => content_files::pdf_text("Stream {$index}"), range(1, 501));
        $result = file_extractor::extract_text($this->file($context, 'partial-streams.pdf', content_files::pdf($streams)));
        $this->assertTrue($result['success']);
        $this->assertSame(500, substr_count($result['content'], 'Stream '));
        $this->assertStringNotContainsString('Stream 501', $result['content']);
        $this->assertNotEmpty($result['notice'], 'The PDF stream-count cap must not silently claim complete extraction.');
    }

    public function test_pdf_decoded_stream_overflow_reports_partial_extraction(): void {
        $context = $this->fixture_context();
        $text = content_files::pdf_text('Skipped decoded-stream sentinel');
        $actual = [];
        $expected = [];
        foreach ([1, 2, file_extractor::MAX_DECOMPRESS_SIZE] as $offset) {
            $oversize = str_repeat(' ', file_extractor::MAX_DECOMPRESS_SIZE + $offset - strlen($text)) . $text;
            $bytes = content_files::pdf([$oversize, content_files::pdf_text('Readable PDF stream')], true);
            unset($oversize);
            $result = file_extractor::extract_text($this->file($context, "partial-decoded-{$offset}.pdf", $bytes));
            $actual[$offset] = ['success' => $result['success'], 'content' => $result['content'],
                'limited' => is_string($result['notice']) && $result['notice'] !== ''];
            $expected[$offset] = ['success' => true, 'content' => 'Readable PDF stream', 'limited' => true];
        }
        $this->assertSame($expected, $actual, 'Decoded overshoot and decoder refusal must both disclose the omitted stream.');
    }

    public function test_nonempty_office_xml_without_normalized_text_reports_failure(): void {
        $context = $this->fixture_context();
        $actual = [];
        $expected = [];
        foreach (['docx' => 'word/document.xml', 'odt' => 'content.xml'] as $extension => $entry) {
            foreach (['empty' => '', 'whitespace' => " \t\n ", 'zero' => '0'] as $kind => $text) {
                $xml = content_files::office_xml($extension, $text);
                $this->assertGreaterThan(0, strlen($xml), 'The required XML entry is nonempty.');
                $file = $this->file($context, "textless-{$kind}.{$extension}", content_files::package([$entry => $xml]));
                $result = file_extractor::extract_text($file);
                $key = $extension . '/' . $kind;
                $actual[$key] = ['success' => $result['success'], 'content' => $result['content'],
                    'notice' => is_string($result['notice']) && $result['notice'] !== ''];
                $expected[$key] = ['success' => $kind === 'zero', 'content' => $kind === 'zero' ? '0' : '',
                    'notice' => $kind !== 'zero'];
            }
        }
        $this->assertSame($expected, $actual, 'Nonempty Office XML with no normalized text must not report extraction success.');
    }

    public function test_pdf_empty_normalized_text_is_not_success_even_when_partial(): void {
        $context = $this->fixture_context();
        $empty = content_files::pdf_text('');
        $fixtures = [
            'empty' => [$empty],
            'whitespace' => [content_files::pdf_text(" \t\n ")],
            'array-operator' => ['BT [() 0 (   )] TJ ET'],
            'partial-empty' => array_fill(0, 501, $empty),
            'omitted-only-text' => [...array_fill(0, 500, $empty), content_files::pdf_text('Omitted text')],
            'partial-readable' => [content_files::pdf_text('Readable partial text'), ...array_fill(0, 500, $empty)],
            'zero' => [content_files::pdf_text('0')],
        ];
        $actual = [];
        $expected = [];
        foreach ([false, true] as $compressed) {
            foreach ($fixtures as $kind => $streams) {
                $key = (int)$compressed . '/' . $kind;
                $file = $this->file(
                    $context,
                    'textless-' . (int)$compressed . '-' . $kind . '.pdf',
                    content_files::pdf($streams, $compressed)
                );
                $result = file_extractor::extract_text($file);
                $actual[$key] = ['success' => $result['success'], 'content' => $result['content'],
                    'notice' => is_string($result['notice']) && $result['notice'] !== ''];
                $content = match ($kind) {
                    'zero' => '0',
                    'partial-readable' => 'Readable partial text',
                    default => '',
                };
                $expected[$key] = ['success' => $content !== '', 'content' => $content, 'notice' => $kind !== 'zero'];
            }
        }
        $this->assertSame($expected, $actual, 'A PDF with no usable normalized text must fail, including partial extraction.');
    }

    private function fixture_context(): \context_module {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        return \context_module::instance($folder->cmid);
    }

    private function file(\context $context, string $name, string $content): \stored_file {
        return content_files::store($context, $name, $content, 'mod_folder');
    }
}
