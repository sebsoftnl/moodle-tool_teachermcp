<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use stdClass;

/**
 * Native numerical question form handling shared by assessment authoring tools.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class numerical_question {
    /**
     * Validate client options before creating or moving assessment records.
     *
     * @param mixed $options Client-supplied answer list.
     * @throws moodle_exception For invalid numerical input.
     */
    public static function validate_options(mixed $options): void {
        if (!is_array($options) || !$options || !array_is_list($options)) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                'Numerical questions require a nonempty list of answer options.');
        }
        $allowedfractions = array_map('floatval', array_keys(\question_bank::fraction_options()));
        $hasfullmark = false;
        foreach ($options as $index => $option) {
            if (!is_array($option) || !is_string($option['answer'] ?? null)
                    || !is_numeric(trim($option['answer'])) || !is_finite((float)$option['answer'])) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                    "Numerical option {$index} requires a finite numeric answer string without units.");
            }
            $tolerance = array_key_exists('tolerance', $option) ? $option['tolerance'] : 0;
            if ((!is_int($tolerance) && !is_float($tolerance)) || !is_finite((float)$tolerance) || $tolerance < 0) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                    "Numerical option {$index} requires a finite, nonnegative tolerance.");
            }
            $fraction = $option['fraction'] ?? null;
            if ((!is_int($fraction) && !is_float($fraction)) || !is_finite((float)$fraction)
                    || $fraction < 0 || $fraction > 1) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                    "Numerical option {$index} requires a grade fraction from 0 to 1.");
            }
            $fraction = (float)$fraction;
            $validfraction = false;
            foreach ($allowedfractions as $allowed) {
                if (abs($fraction - $allowed) < 0.0000001) {
                    $validfraction = true;
                    break;
                }
            }
            if (!$validfraction) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                    "Numerical option {$index} uses a grade fraction not supported by Moodle.");
            }
            if (array_key_exists('feedback', $option) && !is_string($option['feedback'])) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                    "Numerical option {$index} requires string feedback.");
            }
            $hasfullmark = $hasfullmark || $fraction === 1.0;
        }
        if (!$hasfullmark) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '',
                'Numerical questions require at least one answer with fraction 1.0.');
        }
    }

    /**
     * Populate a native form from validated input, preserving unedited version data.
     *
     * @param stdClass $form Common question form fields prepared by the caller.
     * @param array $params Validated authoring arguments.
     * @param stdClass|null $existing Existing question when creating a new version.
     */
    public static function prepare_form(stdClass $form, array $params, ?stdClass $existing = null): void {
        global $CFG, $DB;
        $qtype = \question_bank::get_qtype('numerical');
        $form->showunits = \qtype_numerical::UNITNONE;
        $form->unitgradingtype = 0;
        $form->unitpenalty = 0.1;
        $form->unitsleft = 0;
        $form->unit = [];
        $form->multiplier = [];
        $form->answer = [];
        $form->fraction = [];
        $form->tolerance = [];
        $form->feedback = [];
        $form->hint = [];

        if ($existing !== null) {
            require_once($CFG->libdir . '/filelib.php');
            $qtype->get_question_options($existing);
            foreach (['showunits', 'unitgradingtype', 'unitpenalty', 'unitsleft'] as $field) {
                $form->{$field} = $existing->options->{$field};
            }
            foreach ($existing->options->units as $unit) {
                $form->unit[] = $unit->unit;
                $form->multiplier[] = self::form_number((string)$unit->multiplier);
            }
            $contextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $form->category], MUST_EXIST);
            $form->idnumber = get_question_bank_entry($existing->id)->idnumber;
            $form->parent = $existing->parent;
            $form->status = $DB->get_field('question_versions', 'status', ['questionid' => $existing->id], MUST_EXIST);
            foreach (['questiontext', 'generalfeedback'] as $area) {
                $format = isset($params[$area]) ? FORMAT_HTML : (int)$existing->{$area . 'format'};
                $form->{$area} = self::editor($form->{$area}['text'], $format, $contextid, $area, (int)$existing->id);
            }
            if (!array_key_exists('options', $params)) {
                foreach ($existing->options->answers as $answer) {
                    $form->answer[] = self::form_number($answer->answer);
                    $form->fraction[] = (string)$answer->fraction;
                    $form->tolerance[] = self::form_number((string)$answer->tolerance);
                    $form->feedback[] = self::editor($answer->feedback, (int)$answer->feedbackformat,
                        $contextid, 'answerfeedback', (int)$answer->id);
                }
            }
            foreach ($existing->hints as $hint) {
                $form->hint[] = self::editor($hint->hint, (int)$hint->hintformat, $contextid, 'hint', (int)$hint->id);
            }
        }

        if (array_key_exists('options', $params)) {
            foreach ($params['options'] as $option) {
                $form->answer[] = self::form_number(trim($option['answer']));
                $form->fraction[] = (string)(float)$option['fraction'];
                $form->tolerance[] = self::form_number((string)($option['tolerance'] ?? 0));
                $form->feedback[] = ['text' => clean_text($option['feedback'] ?? '', FORMAT_HTML), 'format' => FORMAT_HTML];
            }
        }
        $form->noanswers = count($form->answer);
    }

    /**
     * Expose existing unit settings for inspection, not unit authoring.
     *
     * @param stdClass $options Options loaded by the native numerical question type.
     * @return array Native unit settings and conversion factors.
     */
    public static function describe_options(stdClass $options): array {
        return [
            'showunits' => (int)$options->showunits,
            'unitgradingtype' => (int)$options->unitgradingtype,
            'unitpenalty' => (float)$options->unitpenalty,
            'unitsleft' => (bool)$options->unitsleft,
            'units' => array_map(static fn($unit) => ['unit' => $unit->unit, 'multiplier' => (float)$unit->multiplier],
                array_values($options->units)),
        ];
    }

    private static function form_number(string $number): string {
        // MCP uses dot-decimal strings; Moodle's form parser uses the current locale.
        return str_replace('.', get_string('decsep', 'langconfig'), $number);
    }

    private static function editor(string $text, int $format, int $contextid, string $area, int $itemid): array {
        $draftid = 0;
        $text = file_prepare_draft_area($draftid, $contextid, 'question', $area, $itemid,
            ['subdirs' => true], $text);
        return ['text' => $text, 'format' => $format, 'itemid' => $draftid];
    }
}
