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

namespace mod_bayesian\event;

/**
 * The mod_bayesian attempt manual grading complete event.
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_manual_grading_completed extends \core\event\base {

    protected function init() {
        $this->data['objecttable'] = 'bayesian_attempts';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public function get_description() {
        return "The attempt with id '$this->objectid' for the user with id '$this->relateduserid' " .
            "for the bayesian with course module id '$this->contextinstanceid' is now fully graded. Sending notification.";
    }

    public static function get_name() {
        return get_string('eventattemptmanualgradingcomplete', 'mod_bayesian');
    }

    public function get_url() {
        return new \moodle_url('/mod/bayesian/review.php', ['attempt' => $this->objectid]);
    }

    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->other['bayesianid'])) {
            throw new \coding_exception('The \'bayesianid\' value must be set in other.');
        }
    }

    public static function get_objectid_mapping() {
        return ['db' => 'bayesian_attempts', 'restore' => 'bayesian_attempt'];
    }

    public static function get_other_mapping() {
        $othermapped = [];
        $othermapped['bayesianid'] = ['db' => 'bayesian', 'restore' => 'bayesian'];

        return $othermapped;
    }
}