<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Local H5P package and native-state fixtures for PHPUnit and owned CLI QA.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class h5p_fixtures {
    public static function install(): array {
        global $CFG, $DB, $USER;
        $path = $CFG->dirroot . '/h5p/tests/fixtures/filltheblanks.h5p';
        $file = \core_h5p\helper::create_fake_stored_file_from_path($path, (int)$USER->id, \context_system::instance());
        $id = \core_h5p\helper::save_h5p(new \core_h5p\factory(), $file,
            (object)['frame' => 1, 'export' => 1, 'embed' => 1, 'copyright' => 1], false, false);
        if (!$id) {
            throw new \RuntimeException('The pinned local H5P fixture did not install');
        }
        $content = $DB->get_record('h5p', ['id' => $id], '*', MUST_EXIST);
        $library = $DB->get_record('h5p_libraries', ['id' => $content->mainlibraryid], '*', MUST_EXIST);
        return ['h5p_id' => (int)$id, 'file_id' => (int)$file->get_id(), 'content' => $content, 'library' => $library,
            'source_sha256' => hash_file('sha256', $path), 'package' => self::package($file)];
    }

    public static function package(\stored_file $file): array {
        $path = $file->copy_content_to_temp('teachermcp', 'h5p-fixture-');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('The H5P package is not a readable ZIP archive');
            }
            try {
                $manifest = $zip->getFromName('h5p.json');
                $params = $zip->getFromName('content/content.json');
                if ($manifest === false || $params === false) {
                    throw new \RuntimeException('The H5P package is missing its required JSON entries');
                }
                return ['manifest' => json_decode($manifest, true, 512, JSON_THROW_ON_ERROR),
                    'params' => json_decode($params, true, 512, JSON_THROW_ON_ERROR)];
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
    }

    public static function activity(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        if (count($files) !== 1) {
            throw new \RuntimeException('Native H5P activity must have exactly one package');
        }
        $file = reset($files);
        return ['cm' => $cm, 'instance' => $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST),
            'file' => ['id' => (int)$file->get_id(), 'contextid' => (int)$file->get_contextid(),
                'pathnamehash' => $file->get_pathnamehash(), 'contenthash' => $file->get_contenthash(),
                'sha256' => hash('sha256', $file->get_content())],
            'h5p' => $DB->get_record('h5p', ['pathnamehash' => $file->get_pathnamehash()], '*', MUST_EXIST),
            'package' => self::package($file)];
    }

    public static function snapshot(int $courseid): array {
        global $DB;
        $cmids = array_map('intval', array_keys(get_fast_modinfo($courseid)->get_cms()));
        $result = [];
        foreach (['course', 'course_modules', 'course_sections', 'context', 'grade_items', 'h5pactivity',
                'contentbank_content', 'files', 'h5p', 'h5p_contents_libraries', 'h5p_libraries',
                'h5p_library_dependencies'] as $table) {
            $result[$table] = array_values($DB->get_records($table, [], 'id ASC'));
        }
        $result['modinfo_cmids'] = $cmids;
        return $result;
    }
}

final class failed_h5p_save extends \tool_teachermcp\tools\create_h5p_activity {
    public bool $returnnull = false;
    public ?array $nativewrite = null;

    protected function save_activity_content(\core_h5p\factory $factory, \stored_file $file): int|false|null {
        global $DB;
        $id = parent::save_activity_content($factory, $file);
        if (!$id) {
            throw new \RuntimeException('Native H5P positive save must complete before the injected result');
        }
        $context = \context::instance_by_id($file->get_contextid());
        $cm = $DB->get_record('course_modules', ['id' => $context->instanceid], '*', MUST_EXIST);
        $this->nativewrite = ['h5p_id' => $id, 'cmid' => (int)$cm->id, 'instance_id' => (int)$cm->instance,
            'h5p_exists' => $DB->record_exists('h5p', ['id' => $id]),
            'instance_exists' => $DB->record_exists('h5pactivity', ['id' => $cm->instance]),
            'file_exists' => $DB->record_exists('files', ['id' => $file->get_id()])];
        return $this->returnnull ? null : false;
    }
}
