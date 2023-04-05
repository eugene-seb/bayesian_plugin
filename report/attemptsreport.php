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
 * The file defines a base class that can be used to build a report like the
 * overview or responses report, that has one row per attempt.
 *
 * @package   mod_bayesian
 * @copyright 2010 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/tablelib.php');


/**
 * Base class for bayesian reports that are basically a table with one row for each attempt.
 *
 * @copyright 2010 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class bayesian_attempts_report extends bayesian_default_report {
    /** @var int default page size for reports. */
    const DEFAULT_PAGE_SIZE = 30;

    /** @var string constant used for the options, means all users with attempts. */
    const ALL_WITH = 'all_with';
    /** @var string constant used for the options, means only enrolled users with attempts. */
    const ENROLLED_WITH = 'enrolled_with';
    /** @var string constant used for the options, means only enrolled users without attempts. */
    const ENROLLED_WITHOUT = 'enrolled_without';
    /** @var string constant used for the options, means all enrolled users. */
    const ENROLLED_ALL = 'enrolled_any';

    /** @var string the mode this report is. */
    protected $mode;

    /** @var context_module the bayesian context. */
    protected $context;

    /** @var mod_bayesian_attempts_report_form The settings form to use. */
    protected $form;

    /** @var string SQL fragment for selecting the attempt that gave the final grade,
     * if applicable. */
    protected $qmsubselect;

    /** @var boolean caches the results of {@link should_show_grades()}. */
    protected $showgrades = null;

    /**
     *  Initialise various aspects of this report.
     *
     * @param string $mode
     * @param string $formclass
     * @param object $bayesian
     * @param object $cm
     * @param object $course
     * @return array with four elements:
     *      0 => integer the current group id (0 for none).
     *      1 => \core\dml\sql_join Contains joins, wheres, params for all the students in this course.
     *      2 => \core\dml\sql_join Contains joins, wheres, params for all the students in the current group.
     *      3 => \core\dml\sql_join Contains joins, wheres, params for all the students to show in the report.
     *              Will be the same as either element 1 or 2.
     */
    public function init($mode, $formclass, $bayesian, $cm, $course) {
        $this->mode = $mode;

        $this->context = context_module::instance($cm->id);

        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $this->get_students_joins(
                $cm, $course);

        $this->qmsubselect = bayesian_report_qm_filter_select($bayesian);

        $this->form = new $formclass($this->get_base_url(),
                array('bayesian' => $bayesian, 'currentgroup' => $currentgroup, 'context' => $this->context));

        return array($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins);
    }

    /**
     * Get the base URL for this report.
     * @return moodle_url the URL.
     */
    protected function get_base_url() {
        return new moodle_url('/mod/bayesian/report.php',
                array('id' => $this->context->instanceid, 'mode' => $this->mode));
    }

    /**
     * Get sql fragments (joins) which can be used to build queries that
     * will select an appropriate set of students to show in the reports.
     *
     * @param object $cm the course module.
     * @param object $course the course settings.
     * @return array with four elements:
     *      0 => integer the current group id (0 for none).
     *      1 => \core\dml\sql_join Contains joins, wheres, params for all the students in this course.
     *      2 => \core\dml\sql_join Contains joins, wheres, params for all the students in the current group.
     *      3 => \core\dml\sql_join Contains joins, wheres, params for all the students to show in the report.
     *              Will be the same as either element 1 or 2.
     */
    protected function get_students_joins($cm, $course = null) {
        $currentgroup = $this->get_current_group($cm, $course, $this->context);

        $empty = new \core\dml\sql_join();
        if ($currentgroup == self::NO_GROUPS_ALLOWED) {
            return array($currentgroup, $empty, $empty, $empty);
        }

        $studentsjoins = get_enrolled_with_capabilities_join($this->context, '',
                array('mod/bayesian:attempt', 'mod/bayesian:reviewmyattempts'));

        if (empty($currentgroup)) {
            return array($currentgroup, $studentsjoins, $empty, $studentsjoins);
        }

        // We have a currently selected group.
        $groupstudentsjoins = get_enrolled_with_capabilities_join($this->context, '',
                array('mod/bayesian:attempt', 'mod/bayesian:reviewmyattempts'), $currentgroup);

        return array($currentgroup, $studentsjoins, $groupstudentsjoins, $groupstudentsjoins);
    }

    /**
     * Outputs the things you commonly want at the top of a bayesian report.
     *
     * Calls through to {@link print_header_and_tabs()} and then
     * outputs the standard group selector, number of attempts summary,
     * and messages to cover common cases when the report can't be shown.
     *
     * @param stdClass $cm the course_module information.
     * @param stdClass $course the course settings.
     * @param stdClass $bayesian the bayesian settings.
     * @param mod_bayesian_attempts_report_options $options the current report settings.
     * @param int $currentgroup the current group.
     * @param bool $hasquestions whether there are any questions in the bayesian.
     * @param bool $hasstudents whether there are any relevant students.
     */
    protected function print_standard_header_and_messages($cm, $course, $bayesian,
            $options, $currentgroup, $hasquestions, $hasstudents) {
        global $OUTPUT;

        $this->print_header_and_tabs($cm, $course, $bayesian, $this->mode);

        if (groups_get_activity_groupmode($cm)) {
            // Groups are being used, so output the group selector if we are not downloading.
            groups_print_activity_menu($cm, $options->get_url());
        }

        // Print information on the number of existing attempts.
        if ($strattemptnum = bayesian_num_attempt_summary($bayesian, $cm, true, $currentgroup)) {
            echo '<div class="bayesianattemptcounts">' . $strattemptnum . '</div>';
        }

        if (!$hasquestions) {
            echo bayesian_no_questions_message($bayesian, $cm, $this->context);
        } else if ($currentgroup == self::NO_GROUPS_ALLOWED) {
            echo $OUTPUT->notification(get_string('notingroup'));
        } else if (!$hasstudents) {
            echo $OUTPUT->notification(get_string('nostudentsyet'));
        } else if ($currentgroup && !$this->hasgroupstudents) {
            echo $OUTPUT->notification(get_string('nostudentsingroup'));
        }
    }

    /**
     * Add all the user-related columns to the $columns and $headers arrays.
     * @param table_sql $table the table being constructed.
     * @param array $columns the list of columns. Added to.
     * @param array $headers the columns headings. Added to.
     */
    protected function add_user_columns($table, &$columns, &$headers) {
        global $CFG;
        if (!$table->is_downloading() && $CFG->grade_report_showuserimage) {
            $columns[] = 'picture';
            $headers[] = '';
        }
        if (!$table->is_downloading()) {
            $columns[] = 'fullname';
            $headers[] = get_string('name');
        } else {
            $columns[] = 'lastname';
            $headers[] = get_string('lastname');
            $columns[] = 'firstname';
            $headers[] = get_string('firstname');
        }

        $extrafields = \core_user\fields::get_identity_fields($this->context);
        foreach ($extrafields as $field) {
            $columns[] = $field;
            $headers[] = \core_user\fields::get_display_name($field);
        }
    }

    /**
     * Set the display options for the user-related columns in the table.
     * @param table_sql $table the table being constructed.
     */
    protected function configure_user_columns($table) {
        $table->column_suppress('picture');
        $table->column_suppress('fullname');

        $extrafields = \core_user\fields::get_identity_fields($this->context);
        foreach ($extrafields as $field) {
            $table->column_suppress($field);
        }

        $table->column_class('picture', 'picture');
        $table->column_class('lastname', 'bold');
        $table->column_class('firstname', 'bold');
        $table->column_class('fullname', 'bold');
    }

    /**
     * Add the state column to the $columns and $headers arrays.
     * @param array $columns the list of columns. Added to.
     * @param array $headers the columns headings. Added to.
     */
    protected function add_state_column(&$columns, &$headers) {
        $columns[] = 'state';
        $headers[] = get_string('attemptstate', 'bayesian');
    }

    /**
     * Add all the time-related columns to the $columns and $headers arrays.
     * @param array $columns the list of columns. Added to.
     * @param array $headers the columns headings. Added to.
     */
    protected function add_time_columns(&$columns, &$headers) {
        $columns[] = 'timestart';
        $headers[] = get_string('startedon', 'bayesian');

        $columns[] = 'timefinish';
        $headers[] = get_string('timecompleted', 'bayesian');

        $columns[] = 'duration';
        $headers[] = get_string('attemptduration', 'bayesian');
    }

    /**
     * Add all the grade and feedback columns, if applicable, to the $columns
     * and $headers arrays.
     * @param object $bayesian the bayesian settings.
     * @param bool $usercanseegrades whether the user is allowed to see grades for this bayesian.
     * @param array $columns the list of columns. Added to.
     * @param array $headers the columns headings. Added to.
     * @param bool $includefeedback whether to include the feedbacktext columns
     */
    protected function add_grade_columns($bayesian, $usercanseegrades, &$columns, &$headers, $includefeedback = true) {
        if ($usercanseegrades) {
            $columns[] = 'sumgrades';
            $headers[] = get_string('grade', 'bayesian') . '/' .
                    bayesian_format_grade($bayesian, $bayesian->grade);
        }

        if ($includefeedback && bayesian_has_feedback($bayesian)) {
            $columns[] = 'feedbacktext';
            $headers[] = get_string('feedback', 'bayesian');
        }
    }

    /**
     * Set up the table.
     * @param table_sql $table the table being constructed.
     * @param array $columns the list of columns.
     * @param array $headers the columns headings.
     * @param moodle_url $reporturl the URL of this report.
     * @param mod_bayesian_attempts_report_options $options the display options.
     * @param bool $collapsible whether to allow columns in the report to be collapsed.
     */
    protected function set_up_table_columns($table, $columns, $headers, $reporturl,
            mod_bayesian_attempts_report_options $options, $collapsible) {
        $table->define_columns($columns);
        $table->define_headers($headers);
        $table->sortable(true, 'uniqueid');

        $table->define_baseurl($options->get_url());

        $this->configure_user_columns($table);

        $table->no_sorting('feedbacktext');
        $table->column_class('sumgrades', 'bold');

        $table->set_attribute('id', 'attempts');

        $table->collapsible($collapsible);
    }

    /**
     * Process any submitted actions.
     * @param object $bayesian the bayesian settings.
     * @param object $cm the cm object for the bayesian.
     * @param int $currentgroup the currently selected group.
     * @param \core\dml\sql_join $groupstudentsjoins (joins, wheres, params) the students in the current group.
     * @param \core\dml\sql_join $allowedjoins (joins, wheres, params) the users whose attempt this user is allowed to modify.
     * @param moodle_url $redirecturl where to redircet to after a successful action.
     */
    protected function process_actions($bayesian, $cm, $currentgroup, \core\dml\sql_join $groupstudentsjoins,
            \core\dml\sql_join $allowedjoins, $redirecturl) {
        if (empty($currentgroup) || $this->hasgroupstudents) {
            if (optional_param('delete', 0, PARAM_BOOL) && confirm_sesskey()) {
                if ($attemptids = optional_param_array('attemptid', array(), PARAM_INT)) {
                    require_capability('mod/bayesian:deleteattempts', $this->context);
                    $this->delete_selected_attempts($bayesian, $cm, $attemptids, $allowedjoins);
                    redirect($redirecturl);
                }
            }
        }
    }

    /**
     * Delete the bayesian attempts
     * @param object $bayesian the bayesian settings. Attempts that don't belong to
     * this bayesian are not deleted.
     * @param object $cm the course_module object.
     * @param array $attemptids the list of attempt ids to delete.
     * @param \core\dml\sql_join $allowedjoins (joins, wheres, params) This list of userids that are visible in the report.
     *      Users can only delete attempts that they are allowed to see in the report.
     *      Empty means all users.
     */
    protected function delete_selected_attempts($bayesian, $cm, $attemptids, \core\dml\sql_join $allowedjoins) {
        global $DB;

        foreach ($attemptids as $attemptid) {
            if (empty($allowedjoins->joins)) {
                $sql = "SELECT bayesiana.*
                          FROM {bayesian_attempts} bayesiana
                          JOIN {user} u ON u.id = bayesiana.userid
                         WHERE bayesiana.id = :attemptid";
            } else {
                $sql = "SELECT bayesiana.*
                          FROM {bayesian_attempts} bayesiana
                          JOIN {user} u ON u.id = bayesiana.userid
                        {$allowedjoins->joins}
                         WHERE {$allowedjoins->wheres} AND bayesiana.id = :attemptid";
            }
            $params = $allowedjoins->params + array('attemptid' => $attemptid);
            $attempt = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);
            if (!$attempt || $attempt->bayesian != $bayesian->id || $attempt->preview != 0) {
                // Ensure the attempt exists, belongs to this bayesian and belongs to
                // a student included in the report. If not skip.
                continue;
            }

            // Set the course module id before calling bayesian_delete_attempt().
            $bayesian->cmid = $cm->id;
            bayesian_delete_attempt($attempt, $bayesian);
        }
    }

    /**
     * Get information about which students to show in the report.
     * @param object $cm the coures module.
     * @param object $course the course settings.
     * @return array with four elements:
     *      0 => integer the current group id (0 for none).
     *      1 => array ids of all the students in this course.
     *      2 => array ids of all the students in the current group.
     *      3 => array ids of all the students to show in the report. Will be the
     *              same as either element 1 or 2.
     * @deprecated since Moodle 3.2 Please use get_students_joins() instead.
     */
    protected function load_relevant_students($cm, $course = null) {
        $msg = 'The function load_relevant_students() is deprecated. Please use get_students_joins() instead.';
        throw new coding_exception($msg);
    }
}
