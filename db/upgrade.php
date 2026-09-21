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
 * Upgrade steps for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute tool_teachermcp upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_tool_teachermcp_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091801) {
        // Define table tool_teachermcp_oauth_clients to be created.
        $table = new xmldb_table('tool_teachermcp_oauth_clients');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('clientid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('clientsecret', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('clientname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('redirecturis', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('clientid_idx', XMLDB_INDEX_UNIQUE, ['clientid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table tool_teachermcp_oauth_codes to be created.
        $table = new xmldb_table('tool_teachermcp_oauth_codes');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('clientid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('redirecturi', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('codechallenge', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('codechallengemethod', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('codehash_idx', XMLDB_INDEX_UNIQUE, ['codehash']);
        $table->add_index('clientid_idx', XMLDB_INDEX_NOTUNIQUE, ['clientid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091801, 'tool', 'teachermcp');
    }

    if ($oldversion < 2026091802) {
        // 1. Add clientid to tool_teachermcp_tokens.
        $tabletokens = new xmldb_table('tool_teachermcp_tokens');
        $fieldclientid = new xmldb_field('clientid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'tokenlastchars');
        if (!$dbman->field_exists($tabletokens, $fieldclientid)) {
            $dbman->add_field($tabletokens, $fieldclientid);
        }

        // 2. Add isloopback to tool_teachermcp_oauth_clients.
        $tableclients = new xmldb_table('tool_teachermcp_oauth_clients');
        $fieldloopback = new xmldb_field('isloopback', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'redirecturis');
        if (!$dbman->field_exists($tableclients, $fieldloopback)) {
            $dbman->add_field($tableclients, $fieldloopback);
        }

        // 3. Add issuedtokenid to tool_teachermcp_oauth_codes.
        $tablecodes = new xmldb_table('tool_teachermcp_oauth_codes');
        $fieldtokenid = new xmldb_field('issuedtokenid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeexpires');
        if (!$dbman->field_exists($tablecodes, $fieldtokenid)) {
            $dbman->add_field($tablecodes, $fieldtokenid);
        }

        // 4. Ensure default claude-desktop client exists with isloopback = 1.
        $existingclient = $DB->get_record('tool_teachermcp_oauth_clients', ['clientid' => 'claude-desktop']);
        if (!$existingclient) {
            $record = (object)[
                'clientid' => 'claude-desktop',
                'clientsecret' => null,
                'clientname' => 'Claude Desktop (Local AI Assistant)',
                'redirecturis' => json_encode(['http://127.0.0.1/callback']),
                'isloopback' => 1,
                'timecreated' => time(),
            ];
            $DB->insert_record('tool_teachermcp_oauth_clients', $record);
        } else if (empty($existingclient->isloopback)) {
            $DB->set_field('tool_teachermcp_oauth_clients', 'isloopback', 1, ['id' => $existingclient->id]);
        }

        upgrade_plugin_savepoint(true, 2026091802, 'tool', 'teachermcp');
    }

    if ($oldversion < 2026091803) {
        // Upgrade savepoint for Question Bank Moodle 4.0+ schema alignment and security enhancements.
        upgrade_plugin_savepoint(true, 2026091803, 'tool', 'teachermcp');
    }

    if ($oldversion < 2026091804) {
        // Upgrade savepoint for complete security, group isolation, and validation audit remediations.
        upgrade_plugin_savepoint(true, 2026091804, 'tool', 'teachermcp');
    }

    if ($oldversion < 2026091901) {
        // 1. Add isverified to tool_teachermcp_oauth_clients (B6).
        $tableclients = new xmldb_table('tool_teachermcp_oauth_clients');
        $fieldverified = new xmldb_field('isverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'isloopback');
        if (!$dbman->field_exists($tableclients, $fieldverified)) {
            $dbman->add_field($tableclients, $fieldverified);
        }

        // Set isverified = 1 for default Claude Desktop client.
        $DB->set_field('tool_teachermcp_oauth_clients', 'isverified', 1, ['clientid' => 'claude-desktop']);

        // 2. Add claimtoken to tool_teachermcp_oauth_codes (B5).
        $tablecodes = new xmldb_table('tool_teachermcp_oauth_codes');
        $fieldclaim = new xmldb_field('claimtoken', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'issuedtokenid');
        if (!$dbman->field_exists($tablecodes, $fieldclaim)) {
            $dbman->add_field($tablecodes, $fieldclaim);
        }

        // 3. Migrate enable_oauth_registration to oauth_registration_enabled (B7).
        $oldsetting = get_config('tool_teachermcp', 'enable_oauth_registration');
        if ($oldsetting !== false) {
            set_config('oauth_registration_enabled', (int)$oldsetting, 'tool_teachermcp');
            unset_config('enable_oauth_registration', 'tool_teachermcp');
        }

        // 4. Default max_oauth_tokens_per_client to 5 if not set (B11).
        if (get_config('tool_teachermcp', 'max_oauth_tokens_per_client') === false) {
            set_config('max_oauth_tokens_per_client', 5, 'tool_teachermcp');
        }

        upgrade_plugin_savepoint(true, 2026091901, 'tool', 'teachermcp');
    }

    if ($oldversion < 2026091904) {
        $tableassets = new xmldb_table('tool_teachermcp_assets');
        $tableassets->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $tableassets->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableassets->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableassets->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableassets->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableassets->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tableassets->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $tableassets->add_key('fk_file', XMLDB_KEY_FOREIGN, ['fileid'], 'files', ['id']);
        $tableassets->add_index('user_context_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'contextid']);
        $tableassets->add_index('fileid_idx', XMLDB_INDEX_UNIQUE, ['fileid']);
        if (!$dbman->table_exists($tableassets)) {
            $dbman->create_table($tableassets);
        }

        // Bring upgraded schemas in line with the foreign key in install.xml.
        $tablecodes = new xmldb_table('tool_teachermcp_oauth_codes');
        $tablecodes->add_key(
            'fk_issuedtoken',
            XMLDB_KEY_FOREIGN,
            ['issuedtokenid'],
            'tool_teachermcp_tokens',
            ['id']
        );
        if ($dbman->table_exists($tablecodes)) {
            $dbman->add_key($tablecodes, $tablecodes->getKey('fk_issuedtoken'));
        }

        upgrade_plugin_savepoint(true, 2026091904, 'tool', 'teachermcp');
    }

    return true;
}
