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

namespace bayesianaccess_seb\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use invalid_parameter_exception;
use bayesian;
use bayesianaccess_seb\event\access_prevented;
use bayesianaccess_seb\access_manager;

require_once($CFG->dirroot . '/mod/bayesian/accessmanager.php');
require_once($CFG->dirroot . '/mod/bayesian/attemptlib.php');
require_once($CFG->libdir . '/externallib.php');

/**
 * Validate browser exam key and config key.
 *
 * @package    bayesianaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2021 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_bayesian_keys extends external_api {

    /**
     * External function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
           'cmid' => new external_value(PARAM_INT, 'Course module ID',
                VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
           'url' => new external_value(PARAM_URL, 'Page URL to check',
                VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
           'configkey' => new external_value(PARAM_ALPHANUMEXT, 'SEB config key',
                VALUE_DEFAULT, null),
           'browserexamkey' => new external_value(PARAM_ALPHANUMEXT, 'SEB browser exam key',
                VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Validate a SEB config key or browser exam key.
     *
     * @param string $cmid Course module ID.
     * @param string $url URL of the page on which the SEB JS API generated the keys.
     * @param string|null $configkey A SEB config key hash. Includes URL in the hash.
     * @param string|null $browserexamkey A SEB browser exam key hash. Includes the URL in the hash.
     * @return array
     */
    public static function execute(string $cmid, string $url, ?string $configkey = null, ?string $browserexamkey = null): array {
        list(
                'cmid' => $cmid,
                'url' => $url,
                'configkey' => $configkey,
                'browserexamkey' => $browserexamkey
            ) = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'url' => $url,
            'configkey' => $configkey,
            'browserexamkey' => $browserexamkey,
        ]);

        self::validate_context(\context_module::instance($cmid));

        // At least one SEB key must be provided.
        if (empty($configkey) && empty($browserexamkey)) {
            throw new invalid_parameter_exception(get_string('error:ws:nokeyprovided', 'bayesianaccess_seb'));
        }

        // Check bayesian exists corresponding to cmid.
        if (($bayesianid = self::get_bayesian_id($cmid)) === 0) {
            throw new invalid_parameter_exception(get_string('error:ws:bayesiannotexists', 'bayesianaccess_seb', $cmid));
        }

        $result = ['configkey' => true, 'browserexamkey' => true];

        $accessmanager = new access_manager(bayesian::create($bayesianid));

        // Check if there is a valid config key.
        if (!$accessmanager->validate_config_key($configkey, $url)) {
            access_prevented::create_strict($accessmanager, get_string('invalid_config_key', 'bayesianaccess_seb'),
                    $configkey, $browserexamkey)->trigger();
            $result['configkey'] = false;
        }

        // Check if there is a valid browser exam key.
        if (!$accessmanager->validate_browser_exam_key($browserexamkey, $url)) {
            access_prevented::create_strict($accessmanager, get_string('invalid_browser_key', 'bayesianaccess_seb'),
                    $configkey, $browserexamkey)->trigger();
            $result['browserexamkey'] = false;
        }

        if ($result['configkey'] && $result['browserexamkey']) {
            // Set the state of the access for this Moodle session.
            $accessmanager->set_session_access(true);
        }

        return $result;
    }

    /**
     * External function returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'configkey' => new external_value(PARAM_BOOL, 'Is a provided config key valid?',
                    VALUE_REQUIRED, 0, NULL_NOT_ALLOWED),
            'browserexamkey' => new external_value(PARAM_BOOL, 'Is a provided browser exam key valid?',
                VALUE_REQUIRED, 0, NULL_NOT_ALLOWED)
        ]);
    }

    /**
     * Check if there is a valid bayesian corresponding to a course module it.
     *
     * @param string $cmid Course module ID.
     * @return int Returns bayesian id if cmid matches valid bayesian, or 0 if there is no match.
     */
    private static function get_bayesian_id(string $cmid): int {
        $bayesianid = 0;

        $coursemodule = get_coursemodule_from_id('bayesian', $cmid);
        if (!empty($coursemodule)) {
            $bayesianid = $coursemodule->instance;
        }

        return $bayesianid;
    }
}

