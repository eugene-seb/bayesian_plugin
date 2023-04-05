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
 * Privacy Subsystem implementation for bayesianaccess_seb.
 *
 * @package    bayesianaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2019 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bayesianaccess_seb\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use bayesianaccess_seb\bayesian_settings;
use bayesianaccess_seb\template;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for bayesianaccess_seb.
 *
 * @copyright  2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Retrieve the user metadata stored by plugin.
     *
     * @param collection $collection Collection of metadata.
     * @return collection Collection of metadata.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'bayesianaccess_seb_settings',
             [
                 'bayesianid' => 'privacy:metadata:bayesianaccess_seb_settings:bayesianid',
                 'usermodified' => 'privacy:metadata:bayesianaccess_seb_settings:usermodified',
                 'timecreated' => 'privacy:metadata:bayesianaccess_seb_settings:timecreated',
                 'timemodified' => 'privacy:metadata:bayesianaccess_seb_settings:timemodified',
             ],
            'privacy:metadata:bayesianaccess_seb_settings'
        );

        $collection->add_database_table(
            'bayesianaccess_seb_template',
            [
                'usermodified' => 'privacy:metadata:bayesianaccess_seb_template:usermodified',
                'timecreated' => 'privacy:metadata:bayesianaccess_seb_template:timecreated',
                'timemodified' => 'privacy:metadata:bayesianaccess_seb_template:timemodified',
            ],
            'privacy:metadata:bayesianaccess_seb_template'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist A list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // The data is associated at the module context level, so retrieve the bayesian context id.
        $sql = "SELECT c.id
                  FROM {bayesianaccess_seb_settings} qs
                  JOIN {course_modules} cm ON cm.instance = qs.bayesianid
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {context} c ON c.instanceid = cm.id AND c.contextlevel = :context
                 WHERE qs.usermodified = :userid
              GROUP BY c.id";

        $params = [
            'context' => CONTEXT_MODULE,
            'modulename' => 'bayesian',
            'userid' => $userid
        ];

        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT c.id
                  FROM {bayesianaccess_seb_template} tem
                  JOIN {bayesianaccess_seb_settings} qs ON qs.templateid = tem.id
                  JOIN {course_modules} cm ON cm.instance = qs.bayesianid
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {context} c ON c.instanceid = cm.id AND c.contextlevel = :context
                 WHERE qs.usermodified = :userid
              GROUP BY c.id";

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        // Get all cmids that correspond to the contexts for a user.
        $cmids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_MODULE) {
                $cmids[] = $context->instanceid;
            }
        }

        // Do nothing if no matching bayesian settings are found for the user.
        if (empty($cmids)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params['modulename'] = 'bayesian';

        // SEB bayesian settings.
        $sql = "SELECT qs.id as id,
                       qs.bayesianid as bayesianid,
                       qs.usermodified as usermodified,
                       qs.timecreated as timecreated,
                       qs.timemodified as timemodified
                  FROM {bayesianaccess_seb_settings} qs
                  JOIN {course_modules} cm ON cm.instance = qs.bayesianid
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                 WHERE cm.id {$insql}";

        $bayesiansettingslist = $DB->get_records_sql($sql, $params);
        $index = 0;
        foreach ($bayesiansettingslist as $bayesiansettings) {
            // Data export is organised in: {Context}/{Plugin Name}/{Table name}/{index}/data.json.
            $index++;
            $subcontext = [
                get_string('pluginname', 'bayesianaccess_seb'),
                bayesian_settings::TABLE,
                $index
            ];

            $data = (object) [
                'bayesianid' => $bayesiansettings->bayesianid,
                'usermodified' => $bayesiansettings->usermodified,
                'timecreated' => transform::datetime($bayesiansettings->timecreated),
                'timemodified' => transform::datetime($bayesiansettings->timemodified)
            ];

            writer::with_context($context)->export_data($subcontext, $data);
        }

        // SEB template settings.
        $sql = "SELECT tem.id as id,
                       qs.bayesianid as bayesianid,
                       tem.usermodified as usermodified,
                       tem.timecreated as timecreated,
                       tem.timemodified as timemodified
                  FROM {bayesianaccess_seb_template} tem
                  JOIN {bayesianaccess_seb_settings} qs ON qs.templateid = tem.id
                  JOIN {course_modules} cm ON cm.instance = qs.bayesianid
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                 WHERE cm.id {$insql}";

        $templatesettingslist = $DB->get_records_sql($sql, $params);
        $index = 0;
        foreach ($templatesettingslist as $templatesetting) {
            // Data export is organised in: {Context}/{Plugin Name}/{Table name}/{index}/data.json.
            $index++;
            $subcontext = [
                get_string('pluginname', 'bayesianaccess_seb'),
                template::TABLE,
                $index
            ];

            $data = (object) [
                'templateid' => $templatesetting->id,
                'bayesianid' => $templatesetting->bayesianid,
                'usermodified' => $templatesetting->usermodified,
                'timecreated' => transform::datetime($templatesetting->timecreated),
                'timemodified' => transform::datetime($templatesetting->timemodified)
            ];

            writer::with_context($context)->export_data($subcontext, $data);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        // Sanity check that context is at the module context level, then get the bayesianid.
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cmid = $context->instanceid;
        $bayesianid = $DB->get_field('course_modules', 'instance', ['id' => $cmid]);

        $params['bayesianid'] = $bayesianid;
        $select = "id IN (SELECT templateid FROM {bayesianaccess_seb_settings} qs WHERE qs.bayesianid = :bayesianid)";
        $DB->set_field_select('bayesianaccess_seb_settings', 'usermodified', 0, "bayesianid = :bayesianid", $params);
        $DB->set_field_select('bayesianaccess_seb_template', 'usermodified', 0, $select, $params);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        // If the user has data, then only the User context should be present so get the first context.
        $contexts = $contextlist->get_contexts();
        if (count($contexts) == 0) {
            return;
        }

        $params['usermodified'] = $contextlist->get_user()->id;
        $DB->set_field_select('bayesianaccess_seb_settings', 'usermodified', 0, "usermodified = :usermodified", $params);
        $DB->set_field_select('bayesianaccess_seb_template', 'usermodified', 0, "usermodified = :usermodified", $params);
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        // The data is associated at the bayesian module context level, so retrieve the user's context id.
        $sql = "SELECT qs.usermodified AS userid
                  FROM {bayesianaccess_seb_settings} qs
                  JOIN {course_modules} cm ON cm.instance = qs.bayesianid
                  JOIN {modules} m ON cm.module = m.id AND m.name = ?
                 WHERE cm.id = ?";
        $params = ['bayesian', $context->instanceid];
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();

        // Sanity check that context is at the Module context level.
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $userids = $userlist->get_userids();
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->set_field_select('bayesianaccess_seb_settings', 'usermodified', 0, "usermodified {$insql}", $inparams);
        $DB->set_field_select('bayesianaccess_seb_template', 'usermodified', 0, "usermodified {$insql}", $inparams);
    }
}
