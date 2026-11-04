<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

use tool_teachermcp\local\connection_details;

defined('MOODLE_INTERNAL') || die();

/**
 * Connection descriptions must match the existing permission predicate.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_details_test extends \advanced_testcase {
    public function test_scope_grants_are_summarised_without_expanding_access(): void {
        $this->resetAfterTest(true);
        $details = connection_details::get($this->token(['scopes' => ['read', 'grade']]));
        $this->assertSame(['read', 'grade'], array_column($details['permissions'], 'id'));
        foreach ($details['permissions'] as $permission) {
            $this->assertSame(get_string('oauth_perm_' . $permission['id'], 'tool_teachermcp'), $permission['description']);
        }
    }

    public function test_individual_tool_grant_does_not_claim_a_whole_scope(): void {
        $this->resetAfterTest(true);
        $details = connection_details::get($this->token(['get_my_profile' => true]));
        $this->assertSame(['get_my_profile'], array_column($details['permissions'], 'id'));
        $this->assertSame('get_my_profile', $details['permissions'][0]['name']);
    }

    public function test_full_and_legacy_grants_match_current_builtin_scopes(): void {
        $this->resetAfterTest(true);
        foreach ([['all_teacher_tools' => true], ['all'], null] as $permissions) {
            $details = connection_details::get($this->token($permissions));
            $this->assertSame(['read', 'write', 'grade', 'message', 'delete'], array_column($details['permissions'], 'id'));
        }
    }

    public function test_empty_and_false_grants_are_not_described_as_full_access(): void {
        $this->resetAfterTest(true);
        foreach ([[], ['read' => false], ['unknown' => true]] as $permissions) {
            $this->assertSame([], connection_details::get($this->token($permissions))['permissions']);
        }
    }

    public function test_grants_remain_visible_when_site_policy_disables_them(): void {
        $this->resetAfterTest(true);
        set_config('enable_write_tools', 0, 'tool_teachermcp');
        $details = connection_details::get($this->token(['scopes' => ['write']]));
        $this->assertSame(['write'], array_column($details['permissions'], 'id'));
    }

    public function test_expiry_is_formatted_and_unlimited_is_explicit(): void {
        $this->resetAfterTest(true);
        $token = $this->token(['scopes' => ['read']]);
        $token->timeexpires = 2000000000;
        $this->assertSame(userdate(2000000000), connection_details::get($token)['validuntil']);
        $token->timeexpires = 0;
        $this->assertSame(get_string('never', 'tool_teachermcp'), connection_details::get($token)['validuntil']);
    }

    public function test_scope_field_and_flat_tool_list_keep_existing_semantics(): void {
        $this->resetAfterTest(true);
        $token = $this->token(null);
        $token->scope = 'read';
        $this->assertSame(['read'], array_column(connection_details::get($token)['permissions'], 'id'));
        $this->assertSame(['get_my_profile'], array_column(
            connection_details::get($this->token(['get_my_profile']))['permissions'], 'id'));
    }

    private function token(?array $permissions): \stdClass {
        return (object)['permissions' => $permissions === null ? null : json_encode($permissions), 'timeexpires' => 0];
    }
}
