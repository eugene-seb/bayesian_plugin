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
 * The mod_bayesian attempt question restarted event.
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\event;

/**
 * The mod_bayesian attempt question restarted event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int bayesianid: the id of the bayesian.
 *      - int page: the page number of attempt.
 * }
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_question_restarted extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'bayesian_attempts';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptquestionrestarted', 'mod_bayesian');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $pagenumber = $this->other['page'] + 1;

        return "The user with id '$this->userid' has restarted question at slot '{$this->other['slot']}' on page " .
            "'{$pagenumber}' of the attempt with id '$this->objectid' belonging to the user " .
            "with id '$this->relateduserid' for the bayesian with course module id '$this->contextinstanceid', " .
            "and the new question id is '{$this->other['newquestionid']}'.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/bayesian/review.php', [
            'attempt' => $this->objectid,
            'page' => $this->other['page']
        ]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->other['bayesianid'])) {
            throw new \coding_exception('The \'bayesianid\' value must be set in other.');
        }

        if (!isset($this->other['page'])) {
            throw new \coding_exception('The \'page\' value must be set in other.');
        }

        if (!isset($this->other['slot'])) {
            throw new \coding_exception('The \'slot\' value must be set in other.');
        }

        if (!isset($this->other['newquestionid'])) {
            throw new \coding_exception('The \'newquestionid\' value must be set in other.');
        }
    }

    /**
     * This is used when restoring course logs where it is required that we
     * map the information in 'other' to it's new value in the new course.
     *
     * @return array List of mapping of other ids.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'bayesian_attempts', 'restore' => 'bayesian_attempt'];
    }

    /**
     * This is used when restoring course logs where it is required that we
     * map the information in 'other' to it's new value in the new course.
     *
     * @return array List of mapping of other ids.
     */
    public static function get_other_mapping() {
        $othermapped = [];
        $othermapped['bayesianid'] = ['db' => 'bayesian', 'restore' => 'bayesian'];
        $othermapped['newquestionid'] = ['db' => 'question', 'restore' => 'question'];

        return $othermapped;
    }
}
