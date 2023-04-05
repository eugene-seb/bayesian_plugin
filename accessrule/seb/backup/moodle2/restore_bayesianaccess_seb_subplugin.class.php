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
 * Restore instructions for the seb (Safe Exam Browser) bayesian access subplugin.
 *
 * @package    bayesianaccess_seb
 * @category   backup
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2020 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use bayesianaccess_seb\bayesian_settings;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/backup/moodle2/restore_mod_bayesian_access_subplugin.class.php');

/**
 * Restore instructions for the seb (Safe Exam Browser) bayesian access subplugin.
 *
 * @copyright  2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_bayesianaccess_seb_subplugin extends restore_mod_bayesian_access_subplugin {

    /**
     * Provides path structure required to restore data for seb bayesian access plugin.
     *
     * @return array
     */
    protected function define_bayesian_subplugin_structure() {
        $paths = [];

        // bayesian settings.
        $path = $this->get_pathfor('/bayesianaccess_seb_settings'); // Subplugin root path.
        $paths[] = new restore_path_element('bayesianaccess_seb_settings', $path);

        // Template settings.
        $path = $this->get_pathfor('/bayesianaccess_seb_settings/bayesianaccess_seb_template');
        $paths[] = new restore_path_element('bayesianaccess_seb_template', $path);

        return $paths;
    }

    /**
     * Process the restored data for the bayesianaccess_seb_settings table.
     *
     * @param stdClass $data Data for bayesianaccess_seb_settings retrieved from backup xml.
     */
    public function process_bayesianaccess_seb_settings($data) {
        global $DB, $USER;

        // Process bayesiansettings.
        $data = (object) $data;
        $data->bayesianid = $this->get_new_parentid('bayesian'); // Update bayesianid with new reference.
        $data->cmid = $this->task->get_moduleid();

        unset($data->id);
        $data->timecreated = $data->timemodified = time();
        $data->usermodified = $USER->id;
        $DB->insert_record(bayesianaccess_seb\bayesian_settings::TABLE, $data);

        // Process attached files.
        $this->add_related_files('bayesianaccess_seb', 'filemanager_sebconfigfile', null);
    }

    /**
     * Process the restored data for the bayesianaccess_seb_template table.
     *
     * @param stdClass $data Data for bayesianaccess_seb_template retrieved from backup xml.
     */
    public function process_bayesianaccess_seb_template($data) {
        global $DB;

        $data = (object) $data;

        $bayesianid = $this->get_new_parentid('bayesian');

        $template = null;
        if ($this->task->is_samesite()) {
            $template = \bayesianaccess_seb\template::get_record(['id' => $data->id]);
        } else {
            // In a different site, try to find existing template with the same name and content.
            $candidates = \bayesianaccess_seb\template::get_records(['name' => $data->name]);
            foreach ($candidates as $candidate) {
                if ($candidate->get('content') == $data->content) {
                    $template = $candidate;
                    break;
                }
            }
        }

        if (empty($template)) {
            unset($data->id);
            $template = new \bayesianaccess_seb\template(0, $data);
            $template->save();
        }

        // Update the restored bayesian settings to use restored template.
        $DB->set_field(\bayesianaccess_seb\bayesian_settings::TABLE, 'templateid', $template->get('id'), ['bayesianid' => $bayesianid]);
    }

}

