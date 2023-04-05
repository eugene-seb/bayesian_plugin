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
 * Privacy Subsystem implementation for mod_bayesian.
 *
 * @package    mod_bayesian
 * @category   privacy
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\transform;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/lib.php');
require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

/**
 * Privacy Subsystem implementation for mod_bayesian.
 *
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // This plugin has data.
    \core_privacy\local\metadata\provider,

    // This plugin currently implements the original plugin_provider interface.
    \core_privacy\local\request\plugin\provider,

    // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   collection  $items  The collection to add metadata to.
     * @return  collection  The array of metadata
     */
    public static function get_metadata(collection $items) : collection {
        // The table 'bayesian' stores a record for each bayesian.
        // It does not contain user personal data, but data is returned from it for contextual requirements.

        // The table 'bayesian_attempts' stores a record of each bayesian attempt.
        // It contains a userid which links to the user making the attempt and contains information about that attempt.
        $items->add_database_table('bayesian_attempts', [
                'attempt'                    => 'privacy:metadata:bayesian_attempts:attempt',
                'currentpage'                => 'privacy:metadata:bayesian_attempts:currentpage',
                'preview'                    => 'privacy:metadata:bayesian_attempts:preview',
                'state'                      => 'privacy:metadata:bayesian_attempts:state',
                'timestart'                  => 'privacy:metadata:bayesian_attempts:timestart',
                'timefinish'                 => 'privacy:metadata:bayesian_attempts:timefinish',
                'timemodified'               => 'privacy:metadata:bayesian_attempts:timemodified',
                'timemodifiedoffline'        => 'privacy:metadata:bayesian_attempts:timemodifiedoffline',
                'timecheckstate'             => 'privacy:metadata:bayesian_attempts:timecheckstate',
                'sumgrades'                  => 'privacy:metadata:bayesian_attempts:sumgrades',
                'gradednotificationsenttime' => 'privacy:metadata:bayesian_attempts:gradednotificationsenttime',
            ], 'privacy:metadata:bayesian_attempts');

        // The table 'bayesian_feedback' contains the feedback responses which will be shown to users depending upon the
        // grade they achieve in the bayesian.
        // It does not identify the user who wrote the feedback item so cannot be returned directly and is not
        // described, but relevant feedback items will be included with the bayesian export for a user who has a grade.

        // The table 'bayesian_grades' contains the current grade for each bayesian/user combination.
        $items->add_database_table('bayesian_grades', [
                'bayesian'                  => 'privacy:metadata:bayesian_grades:bayesian',
                'userid'                => 'privacy:metadata:bayesian_grades:userid',
                'grade'                 => 'privacy:metadata:bayesian_grades:grade',
                'timemodified'          => 'privacy:metadata:bayesian_grades:timemodified',
            ], 'privacy:metadata:bayesian_grades');

        // The table 'bayesian_overrides' contains any user or group overrides for users.
        // It should be included where data exists for a user.
        $items->add_database_table('bayesian_overrides', [
                'bayesian'                  => 'privacy:metadata:bayesian_overrides:bayesian',
                'userid'                => 'privacy:metadata:bayesian_overrides:userid',
                'timeopen'              => 'privacy:metadata:bayesian_overrides:timeopen',
                'timeclose'             => 'privacy:metadata:bayesian_overrides:timeclose',
                'timelimit'             => 'privacy:metadata:bayesian_overrides:timelimit',
            ], 'privacy:metadata:bayesian_overrides');

        // These define the structure of the bayesian.

        // The table 'bayesian_sections' contains data about the structure of a bayesian.
        // It does not contain any user identifying data and does not need a mapping.

        // The table 'bayesian_slots' contains data about the structure of a bayesian.
        // It does not contain any user identifying data and does not need a mapping.

        // The table 'bayesian_reports' does not contain any user identifying data and does not need a mapping.

        // The table 'bayesian_statistics' contains abstract statistics about question usage and cannot be mapped to any
        // specific user.
        // It does not contain any user identifying data and does not need a mapping.

        // The bayesian links to the 'core_question' subsystem for all question functionality.
        $items->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');

        // The bayesian has two subplugins..
        $items->add_plugintype_link('bayesian', [], 'privacy:metadata:bayesian');
        $items->add_plugintype_link('bayesianaccess', [], 'privacy:metadata:bayesianaccess');

        // Although the bayesian supports the core_completion API and defines custom completion items, these will be
        // noted by the manager as all activity modules are capable of supporting this functionality.

        return $items;
    }

    /**
     * Get the list of contexts where the specified user has attempted a bayesian, or been involved with manual marking
     * and/or grading of a bayesian.
     *
     * @param   int             $userid The user to search.
     * @return  contextlist     $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        $resultset = new contextlist();

        // Users who attempted the bayesian.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_attempts} qa ON qa.bayesian = q.id
                 WHERE qa.userid = :userid AND qa.preview = 0";
        $params = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'bayesian', 'userid' => $userid];
        $resultset->add_from_sql($sql, $params);

        // Users with bayesian overrides.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_overrides} qo ON qo.bayesian = q.id
                 WHERE qo.userid = :userid";
        $params = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'bayesian', 'userid' => $userid];
        $resultset->add_from_sql($sql, $params);

        // Get the SQL used to link indirect question usages for the user.
        // This includes where a user is the manual marker on a question attempt.
        $qubaid = \core_question\privacy\provider::get_related_question_usages_for_user('rel', 'mod_bayesian', 'qa.uniqueid', $userid);

        // Select the context of any bayesian attempt where a user has an attempt, plus the related usages.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_attempts} qa ON qa.bayesian = q.id
            " . $qubaid->from . "
            WHERE " . $qubaid->where() . " AND qa.preview = 0";
        $params = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'bayesian'] + $qubaid->from_where_params();
        $resultset->add_from_sql($sql, $params);

        return $resultset;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $params = [
            'cmid'    => $context->instanceid,
            'modname' => 'bayesian',
        ];

        // Users who attempted the bayesian.
        $sql = "SELECT qa.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_attempts} qa ON qa.bayesian = q.id
                 WHERE cm.id = :cmid AND qa.preview = 0";
        $userlist->add_from_sql('userid', $sql, $params);

        // Users with bayesian overrides.
        $sql = "SELECT qo.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_overrides} qo ON qo.bayesian = q.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Question usages in context.
        // This includes where a user is the manual marker on a question attempt.
        $sql = "SELECT qa.uniqueid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_attempts} qa ON qa.bayesian = q.id
                 WHERE cm.id = :cmid AND qa.preview = 0";
        \core_question\privacy\provider::get_users_in_context_from_sql($userlist, 'qn', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!count($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;
        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT
                    q.*,
                    qg.id AS hasgrade,
                    qg.grade AS bestgrade,
                    qg.timemodified AS grademodified,
                    qo.id AS hasoverride,
                    qo.timeopen AS override_timeopen,
                    qo.timeclose AS override_timeclose,
                    qo.timelimit AS override_timelimit,
                    c.id AS contextid,
                    cm.id AS cmid
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {bayesian} q ON q.id = cm.instance
             LEFT JOIN {bayesian_overrides} qo ON qo.bayesian = q.id AND qo.userid = :qouserid
             LEFT JOIN {bayesian_grades} qg ON qg.bayesian = q.id AND qg.userid = :qguserid
                 WHERE c.id {$contextsql}";

        $params = [
            'contextlevel'      => CONTEXT_MODULE,
            'modname'           => 'bayesian',
            'qguserid'          => $userid,
            'qouserid'          => $userid,
        ];
        $params += $contextparams;

        // Fetch the individual bayesianzes.
        $bayesianzes = $DB->get_recordset_sql($sql, $params);
        foreach ($bayesianzes as $bayesian) {
            list($course, $cm) = get_course_and_cm_from_cmid($bayesian->cmid, 'bayesian');
            $bayesianobj = new \bayesian($bayesian, $cm, $course);
            $context = $bayesianobj->get_context();

            $bayesiandata = \core_privacy\local\request\helper::get_context_data($context, $contextlist->get_user());
            \core_privacy\local\request\helper::export_context_files($context, $contextlist->get_user());

            if (!empty($bayesiandata->timeopen)) {
                $bayesiandata->timeopen = transform::datetime($bayesian->timeopen);
            }
            if (!empty($bayesiandata->timeclose)) {
                $bayesiandata->timeclose = transform::datetime($bayesian->timeclose);
            }
            if (!empty($bayesiandata->timelimit)) {
                $bayesiandata->timelimit = $bayesian->timelimit;
            }

            if (!empty($bayesian->hasoverride)) {
                $bayesiandata->override = (object) [];

                if (!empty($bayesiandata->override_override_timeopen)) {
                    $bayesiandata->override->timeopen = transform::datetime($bayesian->override_timeopen);
                }
                if (!empty($bayesiandata->override_timeclose)) {
                    $bayesiandata->override->timeclose = transform::datetime($bayesian->override_timeclose);
                }
                if (!empty($bayesiandata->override_timelimit)) {
                    $bayesiandata->override->timelimit = $bayesian->override_timelimit;
                }
            }

            $bayesiandata->accessdata = (object) [];

            $components = \core_component::get_plugin_list('bayesianaccess');
            $exportparams = [
                    $bayesianobj,
                    $user,
                ];
            foreach (array_keys($components) as $component) {
                $classname = manager::get_provider_classname_for_component("bayesianaccess_$component");
                if (class_exists($classname) && is_subclass_of($classname, bayesianaccess_provider::class)) {
                    $result = component_class_callback($classname, 'export_bayesianaccess_user_data', $exportparams);
                    if (count((array) $result)) {
                        $bayesiandata->accessdata->$component = $result;
                    }
                }
            }

            if (empty((array) $bayesiandata->accessdata)) {
                unset($bayesiandata->accessdata);
            }

            writer::with_context($context)
                ->export_data([], $bayesiandata);
        }
        $bayesianzes->close();

        // Store all bayesian attempt data.
        static::export_bayesian_attempts($contextlist);
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param   context                 $context   The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel != CONTEXT_MODULE) {
            // Only bayesian module will be handled.
            return;
        }

        $cm = get_coursemodule_from_id('bayesian', $context->instanceid);
        if (!$cm) {
            // Only bayesian module will be handled.
            return;
        }

        $bayesianobj = \bayesian::create($cm->instance);
        $bayesian = $bayesianobj->get_bayesian();

        // Handle the 'bayesianaccess' subplugin.
        manager::plugintype_class_callback(
                'bayesianaccess',
                bayesianaccess_provider::class,
                'delete_subplugin_data_for_all_users_in_context',
                [$bayesianobj]
            );

        // Delete all overrides - do not log.
        bayesian_delete_all_overrides($bayesian, false);

        // This will delete all question attempts, bayesian attempts, and bayesian grades for this bayesian.
        bayesian_delete_all_attempts($bayesian);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
            // Only bayesian module will be handled.
                continue;
            }

            $cm = get_coursemodule_from_id('bayesian', $context->instanceid);
            if (!$cm) {
                // Only bayesian module will be handled.
                continue;
            }

            // Fetch the details of the data to be removed.
            $bayesianobj = \bayesian::create($cm->instance);
            $bayesian = $bayesianobj->get_bayesian();
            $user = $contextlist->get_user();

            // Handle the 'bayesianaccess' bayesianaccess.
            manager::plugintype_class_callback(
                    'bayesianaccess',
                    bayesianaccess_provider::class,
                    'delete_bayesianaccess_data_for_user',
                    [$bayesianobj, $user]
                );

            // Remove overrides for this user.
            $overrides = $DB->get_records('bayesian_overrides' , [
                'bayesian' => $bayesianobj->get_bayesianid(),
                'userid' => $user->id,
            ]);

            foreach ($overrides as $override) {
                bayesian_delete_override($bayesian, $override->id, false);
            }

            // This will delete all question attempts, bayesian attempts, and bayesian grades for this bayesian.
            bayesian_delete_user_attempts($bayesianobj, $user);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            // Only bayesian module will be handled.
            return;
        }

        $cm = get_coursemodule_from_id('bayesian', $context->instanceid);
        if (!$cm) {
            // Only bayesian module will be handled.
            return;
        }

        $bayesianobj = \bayesian::create($cm->instance);
        $bayesian = $bayesianobj->get_bayesian();

        $userids = $userlist->get_userids();

        // Handle the 'bayesianaccess' bayesianaccess.
        manager::plugintype_class_callback(
                'bayesianaccess',
                bayesianaccess_user_provider::class,
                'delete_bayesianaccess_data_for_users',
                [$userlist]
        );

        foreach ($userids as $userid) {
            // Remove overrides for this user.
            $overrides = $DB->get_records('bayesian_overrides' , [
                'bayesian' => $bayesianobj->get_bayesianid(),
                'userid' => $userid,
            ]);

            foreach ($overrides as $override) {
                bayesian_delete_override($bayesian, $override->id, false);
            }

            // This will delete all question attempts, bayesian attempts, and bayesian grades for this user in the given bayesian.
            bayesian_delete_user_attempts($bayesianobj, (object)['id' => $userid]);
        }
    }

    /**
     * Store all bayesian attempts for the contextlist.
     *
     * @param   approved_contextlist    $contextlist
     */
    protected static function export_bayesian_attempts(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $qubaid = \core_question\privacy\provider::get_related_question_usages_for_user('rel', 'mod_bayesian', 'qa.uniqueid', $userid);

        $sql = "SELECT
                    c.id AS contextid,
                    cm.id AS cmid,
                    qa.*
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'bayesian'
                  JOIN {bayesian} q ON q.id = cm.instance
                  JOIN {bayesian_attempts} qa ON qa.bayesian = q.id
            " . $qubaid->from. "
            WHERE (
                qa.userid = :qauserid OR
                " . $qubaid->where() . "
            ) AND qa.preview = 0
        ";

        $params = array_merge(
                [
                    'contextlevel'      => CONTEXT_MODULE,
                    'qauserid'          => $userid,
                ],
                $qubaid->from_where_params()
            );

        $attempts = $DB->get_recordset_sql($sql, $params);
        foreach ($attempts as $attempt) {
            $bayesian = $DB->get_record('bayesian', ['id' => $attempt->bayesian]);
            $context = \context_module::instance($attempt->cmid);
            $attemptsubcontext = helper::get_bayesian_attempt_subcontext($attempt, $contextlist->get_user());
            $options = bayesian_get_review_options($bayesian, $attempt, $context);

            if ($attempt->userid == $userid) {
                // This attempt was made by the user.
                // They 'own' all data on it.
                // Store the question usage data.
                \core_question\privacy\provider::export_question_usage($userid,
                        $context,
                        $attemptsubcontext,
                        $attempt->uniqueid,
                        $options,
                        true
                    );

                // Store the bayesian attempt data.
                $data = (object) [
                    'state' => \bayesian_attempt::state_name($attempt->state),
                ];

                if (!empty($attempt->timestart)) {
                    $data->timestart = transform::datetime($attempt->timestart);
                }
                if (!empty($attempt->timefinish)) {
                    $data->timefinish = transform::datetime($attempt->timefinish);
                }
                if (!empty($attempt->timemodified)) {
                    $data->timemodified = transform::datetime($attempt->timemodified);
                }
                if (!empty($attempt->timemodifiedoffline)) {
                    $data->timemodifiedoffline = transform::datetime($attempt->timemodifiedoffline);
                }
                if (!empty($attempt->timecheckstate)) {
                    $data->timecheckstate = transform::datetime($attempt->timecheckstate);
                }
                if (!empty($attempt->gradednotificationsenttime)) {
                    $data->gradednotificationsenttime = transform::datetime($attempt->gradednotificationsenttime);
                }

                if ($options->marks == \question_display_options::MARK_AND_MAX) {
                    $grade = bayesian_rescale_grade($attempt->sumgrades, $bayesian, false);
                    $data->grade = (object) [
                            'grade' => bayesian_format_grade($bayesian, $grade),
                            'feedback' => bayesian_feedback_for_grade($grade, $bayesian, $context),
                        ];
                }

                writer::with_context($context)
                    ->export_data($attemptsubcontext, $data);
            } else {
                // This attempt was made by another user.
                // The current user may have marked part of the bayesian attempt.
                \core_question\privacy\provider::export_question_usage(
                        $userid,
                        $context,
                        $attemptsubcontext,
                        $attempt->uniqueid,
                        $options,
                        false
                    );
            }
        }
        $attempts->close();
    }
}
