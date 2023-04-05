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
 * The mod_bayesian slots created event.
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\event;

/**
 * The mod_bayesian slot created event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int bayesianid: the id of the bayesian.
 *      - int slotnumber: the slot number in bayesian.
 *      - int page: page number.
 * }
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_created extends \core\event\base {
    protected function init() {
        $this->data['objecttable'] = 'bayesian_slots';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name() {
        return get_string('eventslotcreated', 'mod_bayesian');
    }

    public function get_description() {
        return "The user with id '$this->userid' created a new slot with id '{$this->objectid}' " .
            "and slot number '{$this->other['slotnumber']}' " .
            "on page '{$this->other['page']}' " .
            "of the bayesian with course module id '$this->contextinstanceid'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/bayesian/edit.php', [
            'cmid' => $this->contextinstanceid
        ]);
    }

    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' value must be set.');
        }

        if (!isset($this->contextinstanceid)) {
            throw new \coding_exception('The \'contextinstanceid\' value must be set.');
        }

        if (!isset($this->other['bayesianid'])) {
            throw new \coding_exception('The \'bayesianid\' value must be set in other.');
        }

        if (!isset($this->other['slotnumber'])) {
            throw new \coding_exception('The \'slotnumber\' value must be set in other.');
        }

        if (!isset($this->other['page'])) {
            throw new \coding_exception('The \'page\' value must be set in other.');
        }
    }

    public static function get_objectid_mapping() {
        return ['db' => 'bayesian_slots', 'restore' => 'bayesian_question_instance'];
    }

    public static function get_other_mapping() {
        $othermapped = [];
        $othermapped['bayesianid'] = ['db' => 'bayesian', 'restore' => 'bayesian'];

        return $othermapped;
    }
}
