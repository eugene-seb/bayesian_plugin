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
 * A test helper trait.
 *
 * @package    bayesianaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2019 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use bayesianaccess_seb\access_manager;
use bayesianaccess_seb\settings_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . "/mod/bayesian/accessrule/seb/rule.php"); // Include plugin rule class.
require_once($CFG->dirroot . "/mod/bayesian/mod_form.php"); // Include plugin rule class.

/**
 * A test helper trait. It has some common helper methods.
 *
 * @copyright  2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait bayesianaccess_seb_test_helper_trait {

    /** @var \stdClass $course Test course to contain bayesian. */
    protected $course;

    /** @var \stdClass $bayesian A test bayesian. */
    protected $bayesian;

    /** @var \stdClass $user A test logged-in user. */
    protected $user;

    /**
     * Assign a capability to $USER
     * The function creates a student $USER if $USER->id is empty
     *
     * @param string $capability Capability name.
     * @param int $contextid Context ID.
     * @param int $roleid Role ID.
     * @return int The role id - mainly returned for creation, so calling function can reuse it.
     */
    protected function assign_user_capability($capability, $contextid, $roleid = null) {
        global $USER;

        // Create a new student $USER if $USER doesn't exist.
        if (empty($USER->id)) {
            $user = $this->getDataGenerator()->create_user();
            $this->setUser($user);
        }

        if (empty($roleid)) {
            $roleid = \create_role('Dummy role', 'dummyrole', 'dummy role description');
        }

        \assign_capability($capability, CAP_ALLOW, $roleid, $contextid);

        \role_assign($roleid, $USER->id, $contextid);

        \accesslib_clear_all_caches_for_unit_testing();

        return $roleid;
    }

    /**
     * Strip the seb_ prefix from each setting key.
     *
     * @param \stdClass $settings Object containing settings.
     * @return \stdClass The modified settings object.
     */
    protected function strip_all_prefixes(\stdClass $settings) : \stdClass {
        $newsettings = new \stdClass();
        foreach ($settings as $name => $setting) {
            $newname = preg_replace("/^seb_/", "", $name);
            $newsettings->$newname = $setting; // Add new key.
        }
        return $newsettings;
    }

    /**
     * Creates a file in the user draft area.
     *
     * @param string $xml
     * @return int The user draftarea id
     */
    protected function create_test_draftarea_file(string $xml) : int {
        global $USER;

        $itemid = 0;
        $usercontext = \context_user::instance($USER->id);
        $filerecord = [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'test.xml'
        ];

        $fs = get_file_storage();
        $fs->create_file_from_string($filerecord, $xml);

        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $usercontext->id, 'user', 'draft', 0);

        return $draftitemid;
    }

    /**
     * Create a file in a modules filearea.
     *
     * @param string $xml XML content of the file.
     * @param string $cmid Course module id.
     * @return int Item ID of file.
     */
    protected function create_module_test_file(string $xml, string $cmid) : int {
        $itemid = 0;
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cmid)->id,
            'component' => 'bayesianaccess_seb',
            'filearea' => 'filemanager_sebconfigfile',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'test.xml'
        ];
        $fs->create_file_from_string($filerecord, $xml);
        return $itemid;
    }

    /**
     * Create a test bayesian for the specified course.
     *
     * @param \stdClass $course
     * @param int $requiresafeexambrowser How to use SEB for this bayesian?
     * @return  array
     */
    protected function create_test_bayesian($course, $requiresafeexambrowser = settings_provider::USE_SEB_NO) {
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');

        $bayesian = $bayesiangenerator->create_instance([
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 2,
            'seb_requiresafeexambrowser' => $requiresafeexambrowser,
        ]);
        $bayesian->seb_showsebdownloadlink = 1;
        $bayesian->coursemodule = $bayesian->cmid;

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();

        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        bayesian_add_bayesian_question($saq->id, $bayesian);
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        bayesian_add_bayesian_question($numq->id, $bayesian);

        return $bayesian;
    }

    /**
     * Answer questions for a bayesian + user.
     *
     * @param \stdClass $bayesian bayesian to attempt.
     * @param \stdClass $user A user to attempt the bayesian.
     * @return  array
     */
    protected function attempt_bayesian($bayesian, $user) {
        $this->setUser($user);

        $starttime = time();
        $bayesianobj = \bayesian::create($bayesian->id, $user->id);

        $quba = \question_engine::make_questions_usage_by_activity('mod_bayesian', $bayesianobj->get_context());
        $quba->set_preferred_behaviour($bayesianobj->get_bayesian()->preferredbehaviour);

        // Start the attempt.
        $attempt = bayesian_create_attempt($bayesianobj, 1, false, $starttime, false, $user->id);
        bayesian_start_new_attempt($bayesianobj, $quba, $attempt, 1, $starttime);
        bayesian_attempt_save_started($bayesianobj, $quba, $attempt);

        // Answer the questions.
        $attemptobj = \bayesian_attempt::create($attempt->id);

        $tosubmit = [
            1 => ['answer' => 'frog'],
            2 => ['answer' => '3.14'],
        ];

        $attemptobj->process_submitted_actions($starttime, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = \bayesian_attempt::create($attempt->id);
        $attemptobj->process_finish($starttime, false);

        $this->setUser();

        return [$bayesianobj, $quba, $attemptobj];
    }

    /**
     * Create test template.
     *
     * @param string|null $xml Template content.
     * @return \bayesianaccess_seb\template Just created template.
     */
    public function create_template(string $xml = null) {
        $data = [];

        if (!is_null($xml)) {
            $data['content'] = $xml;
        }

        return $this->getDataGenerator()->get_plugin_generator('bayesianaccess_seb')->create_template($data);
    }

    /**
     * Get access manager for testing.
     *
     * @return \bayesianaccess_seb\access_manager
     */
    protected function get_access_manager() {
        return new access_manager(new \bayesian($this->bayesian,
            get_coursemodule_from_id('bayesian', $this->bayesian->cmid), $this->course));
    }

    /**
     * A helper method to make the rule form the currently created bayesian and  course.
     *
     * @return \bayesian_access_rule_base|null
     */
    protected function make_rule() {
        return \bayesianaccess_seb::make(
            new \bayesian($this->bayesian, get_coursemodule_from_id('bayesian', $this->bayesian->cmid), $this->course),
            0,
            true
        );
    }

    /**
     * A helper method to set up bayesian view page.
     */
    protected function set_up_bayesian_view_page() {
        global $PAGE;

        $page = new \moodle_page();
        $page->set_context(\context_module::instance($this->bayesian->cmid));
        $page->set_course($this->course);
        $page->set_pagelayout('standard');
        $page->set_pagetype("mod-bayesian-view");
        $page->set_url('/mod/bayesian/view.php?id=' . $this->bayesian->cmid);

        $PAGE = $page;
    }

    /**
     * Get a test object containing mock test settings.
     *
     * @return \stdClass Settings.
     */
    protected function get_test_settings(array $settings = []) : \stdClass {
        return (object) array_merge([
            'bayesianid' => 1,
            'cmid' => 1,
            'requiresafeexambrowser' => '1',
            'showsebtaskbar' => '1',
            'showwificontrol' => '0',
            'showreloadbutton' => '1',
            'showtime' => '0',
            'showkeyboardlayout' => '1',
            'allowuserquitseb' => '1',
            'quitpassword' => 'test',
            'linkquitseb' => '',
            'userconfirmquit' => '1',
            'enableaudiocontrol' => '1',
            'muteonstartup' => '0',
            'allowspellchecking' => '0',
            'allowreloadinexam' => '1',
            'activateurlfiltering' => '1',
            'filterembeddedcontent' => '0',
            'expressionsallowed' => 'test.com',
            'regexallowed' => '',
            'expressionsblocked' => '',
            'regexblocked' => '',
            'showsebdownloadlink' => '1',
        ], $settings);
    }

}
