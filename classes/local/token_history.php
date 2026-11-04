<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Inactive history scoped to the current user, or to a site connection manager.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_history {
    public static function is_active(\stdClass $token, ?int $now = null): bool {
        $now = $now ?? time();
        return (int)$token->status === 1 && ((int)$token->timeexpires === 0 || (int)$token->timeexpires >= $now);
    }

    public static function status(\stdClass $token, ?int $now = null): string {
        $now = $now ?? time();
        if ((int)$token->timeexpires > 0 && (int)$token->timeexpires < $now) {
            return 'expired';
        }
        return (int)$token->status === 1 ? 'active' : 'revoked';
    }

    public static function count(bool $allusers = false): int {
        global $DB;
        [$where, $params] = self::selection($allusers);
        return $DB->count_records_select('tool_teachermcp_tokens', $where, $params);
    }

    public static function clear(bool $allusers = false): int {
        global $DB, $USER;
        [$where, $params] = self::selection($allusers);
        $transaction = $DB->start_delegated_transaction();
        $tokens = $DB->get_records_select('tool_teachermcp_tokens', $where, $params, 'id', 'id');
        if (!$tokens) {
            $transaction->allow_commit();
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($tokens), SQL_PARAMS_NAMED);
        $DB->set_field_select('tool_teachermcp_oauth_codes', 'issuedtokenid', null, 'issuedtokenid ' . $insql, $inparams);
        $DB->delete_records_select('tool_teachermcp_tokens', 'id ' . $insql, $inparams);
        $count = count($tokens);
        \tool_teachermcp\event\token_history_cleared::create([
            'context' => \context_system::instance(),
            'relateduserid' => $allusers ? null : $USER->id,
            'other' => ['count' => $count, 'allusers' => $allusers],
        ])->trigger();
        $transaction->allow_commit();
        return $count;
    }

    private static function selection(bool $allusers): array {
        global $USER;
        if ($allusers) {
            require_capability('tool/teachermcp:manageall', \context_system::instance());
        } else if (!\tool_teachermcp\auth::user_can_connect($USER->id)) {
            throw new \moodle_exception('error_no_capability', 'tool_teachermcp');
        }
        $where = '(status = 0 OR (timeexpires > 0 AND timeexpires < :now))';
        $params = ['now' => time()];
        if (!$allusers) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $USER->id;
        }
        return [$where, $params];
    }
}
