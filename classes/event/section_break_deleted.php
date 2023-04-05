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
 * The mod_bayesian section break deleted event.
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\event;

/**
 * The mod_bayesian section break deleted event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int bayesianid: the id of the bayesian.
 *      - int firstslotid: id of the slot which we will remove the section break before.
 *      - int firstslotnumber: slot number of the slot which we will remove the section break before.
 * }
 *
 * @package    mod_bayesian
 * @copyright  2021 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_break_deleted extends \core\event\base {
    protected function init() {
        $this->data['objecttable'] = 'bayesian_sections';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name() {
        return get_string('eventsectionbreakdeleted', 'mod_bayesian');
    }

    public function get_description() {
        return "The user with id '$this->userid' deleted the section break with id '{$this->objectid}' " .
            "before the slot with id '{$this->other['firstslotid']}' and slot number '{$this->other['firstslotnumber']}' " .
            "belonging to the bayesian with course module id '$this->contextinstanceid'.";
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

        if (!isset($this->other['firstslotid'])) {
            throw new \coding_exception('The \'firstslotid\' value must be set in other.');
        }

        if (!isset($this->other['firstslotnumber'])) {
            throw new \coding_exception('The \'firstslotnumber\' value must be set in other.');
        }
    }

    public static function get_objectid_mapping() {
        return ['db' => 'bayesian_sections', 'restore' => 'bayesian_section'];
    }

    public static function get_other_mapping() {
        $othermapped = [];
        $othermapped['bayesianid'] = ['db' => 'bayesian', 'restore' => 'bayesian'];
        $othermapped['firstslotid'] = ['db' => 'bayesian_slots', 'restore' => 'bayesian_question_instance'];

        return $othermapped;
    }
}
