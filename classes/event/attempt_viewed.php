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
 * The mod_bayesian attempt viewed event.
 *
 * @package    mod_bayesian
 * @copyright  2014 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\event;

defined('MOODLE_INTERNAL') || die();

/**
 * The mod_bayesian attempt viewed event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int bayesianid: the id of the bayesian.
 *      - int page: the page number of attempt.
 * }
 *
 * @package    mod_bayesian
 * @since      Moodle 2.7
 * @copyright  2014 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_viewed extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'bayesian_attempts';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptviewed', 'mod_bayesian');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $page = isset($this->other['page']) ? $this->other['page'] + 1 : '';
        return "The user with id '$this->userid' has viewed page '$page' of the attempt with id " .
            "'$this->objectid' belonging to the user with id '$this->relateduserid' for the bayesian " .
            "with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/bayesian/review.php', [
            'attempt' => $this->objectid,
            'page' => isset($this->other['page']) ? $this->other['page'] : 0
        ]);
    }

    /**
     * Return the legacy event log data.
     *
     * @return array
     */
    protected function get_legacy_logdata() {
        return array($this->courseid, 'bayesian', 'continue attempt', 'review.php?attempt=' . $this->objectid,
            $this->other['bayesianid'], $this->contextinstanceid);
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
    }

    public static function get_objectid_mapping() {
        return array('db' => 'bayesian_attempts', 'restore' => 'bayesian_attempt');
    }

    public static function get_other_mapping() {
        $othermapped = array();
        $othermapped['bayesianid'] = array('db' => 'bayesian', 'restore' => 'bayesian');

        return $othermapped;
    }
}
