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
 * Library code used by bayesian cron.
 *
 * @package   mod_bayesian
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/locallib.php');


/**
 * This class holds all the code for automatically updating all attempts that have
 * gone over their time limit.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_bayesian_overdue_attempt_updater {

    /**
     * Do the processing required.
     * @param int $timenow the time to consider as 'now' during the processing.
     * @param int $processto only process attempt with timecheckstate longer ago than this.
     * @return array with two elements, the number of attempt considered, and how many different bayesianzes that was.
     */
    public function update_overdue_attempts($timenow, $processto) {
        global $DB;

        $attemptstoprocess = $this->get_list_of_overdue_attempts($processto);

        $course = null;
        $bayesian = null;
        $cm = null;

        $count = 0;
        $bayesiancount = 0;
        foreach ($attemptstoprocess as $attempt) {
            try {

                // If we have moved on to a different bayesian, fetch the new data.
                if (!$bayesian || $attempt->bayesian != $bayesian->id) {
                    $bayesian = $DB->get_record('bayesian', array('id' => $attempt->bayesian), '*', MUST_EXIST);
                    $cm = get_coursemodule_from_instance('bayesian', $attempt->bayesian);
                    $bayesiancount += 1;
                }

                // If we have moved on to a different course, fetch the new data.
                if (!$course || $course->id != $bayesian->course) {
                    $course = $DB->get_record('course', array('id' => $bayesian->course), '*', MUST_EXIST);
                }

                // Make a specialised version of the bayesian settings, with the relevant overrides.
                $bayesianforuser = clone($bayesian);
                $bayesianforuser->timeclose = $attempt->usertimeclose;
                $bayesianforuser->timelimit = $attempt->usertimelimit;

                // Trigger any transitions that are required.
                $attemptobj = new bayesian_attempt($attempt, $bayesianforuser, $cm, $course);
                $attemptobj->handle_if_time_expired($timenow, false);
                $count += 1;

            } catch (moodle_exception $e) {
                // If an error occurs while processing one attempt, don't let that kill cron.
                mtrace("Error while processing attempt {$attempt->id} at {$attempt->bayesian} bayesian:");
                mtrace($e->getMessage());
                mtrace($e->getTraceAsString());
                // Close down any currently open transactions, otherwise one error
                // will stop following DB changes from being committed.
                $DB->force_transaction_rollback();
            }
        }

        $attemptstoprocess->close();
        return array($count, $bayesiancount);
    }

    /**
     * @return moodle_recordset of bayesian_attempts that need to be processed because time has
     *     passed. The array is sorted by courseid then bayesianid.
     */
    public function get_list_of_overdue_attempts($processto) {
        global $DB;


        // SQL to compute timeclose and timelimit for each attempt:
        $bayesianausersql = bayesian_get_attempt_usertime_sql(
                "ibayesiana.state IN ('inprogress', 'overdue') AND ibayesiana.timecheckstate <= :iprocessto");

        // This query should have all the bayesian_attempts columns.
        return $DB->get_recordset_sql("
         SELECT bayesiana.*,
                bayesianauser.usertimeclose,
                bayesianauser.usertimelimit

           FROM {bayesian_attempts} bayesiana
           JOIN {bayesian} bayesian ON bayesian.id = bayesiana.bayesian
           JOIN ( $bayesianausersql ) bayesianauser ON bayesianauser.id = bayesiana.id

          WHERE bayesiana.state IN ('inprogress', 'overdue')
            AND bayesiana.timecheckstate <= :processto
       ORDER BY bayesian.course, bayesiana.bayesian",

                array('processto' => $processto, 'iprocessto' => $processto));
    }
}
