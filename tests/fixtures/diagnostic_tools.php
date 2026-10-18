<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

final class diagnostic_fixtures {
    public const PRIVATE_MARKER = 'QA_DIAGNOSTIC_PRIVATE_DETAIL';
    public const BUILTINS = [
        'get_my_profile', 'get_teacher_courses', 'export_course_learning_material', 'get_course_contents',
        'scaffold_assessment_unit', 'detect_at_risk_students', 'analyze_class_understanding', 'prepare_grading_session',
        'manage_activity', 'grade_submission', 'manage_grades', 'get_group_students', 'manage_course_completion',
        'send_student_message', 'manage_question_bank', 'duplicate_activity', 'read_activity_file', 'delete_activity',
        'upload_course_asset', 'manage_content_activity', 'get_h5p_libraries', 'create_h5p_activity',
        'create_assignment', 'manage_course_calendar',
    ];

    public static function providers(): array {
        $common = ['scope' => 'read', 'capability' => 'tool/teachermcp:manageall',
            'enabled_setting' => 'tool_teachermcp/enable_diagnostic_fixture'];
        return ['api_version' => 1, 'provider_id' => 'tool_teachermcp', 'tools' => [
            ['id' => 'diagnostic_good', 'class' => diagnostic_external::class] + $common,
            ['id' => 'diagnostic_bad', 'class' => diagnostic_invalid_external::class] + $common,
        ]];
    }
}

class diagnostic_builtin extends \tool_teachermcp\tools\base_tool {
    public function get_name(): string {
        return 'get_my_profile';
    }

    public function get_description(): string {
        return 'Owned diagnostic fixture.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $params): array {
        return ['fixture_only' => true];
    }
}

final class diagnostic_constructor_failure extends diagnostic_builtin {
    public function __construct() {
        throw new \RuntimeException(diagnostic_fixtures::PRIVATE_MARKER);
    }
}

final class diagnostic_name_failure extends diagnostic_builtin {
    public function get_name(): string {
        throw new \RuntimeException(diagnostic_fixtures::PRIVATE_MARKER);
    }
}

final class diagnostic_description_failure extends diagnostic_builtin {
    public function get_description(): string {
        throw new \RuntimeException(diagnostic_fixtures::PRIVATE_MARKER);
    }
}

final class diagnostic_schema_failure extends diagnostic_builtin {
    public function get_input_schema(): array {
        throw new \RuntimeException(diagnostic_fixtures::PRIVATE_MARKER);
    }
}

final class diagnostic_wrong_name extends diagnostic_builtin {
    public function get_name(): string {
        return 'diagnostic_wrong_name';
    }
}

final class diagnostic_invalid_builtin {
}

class diagnostic_external implements \tool_teachermcp\local\external_tool {
    public function get_description(): string {
        return 'Owned external diagnostic fixture.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, \tool_teachermcp\local\execution_context $context): array {
        return ['fixture_only' => true];
    }
}

final class diagnostic_invalid_external extends diagnostic_external {
    public function get_input_schema(): array {
        return ['type' => 'string'];
    }
}
