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
 * Administration settings definitions for the bayesian module.
 *
 * @package   mod_bayesian
 * @copyright 2010 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/lib.php');

// First get a list of bayesian reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = core_component::get_plugin_list_with_file('bayesian', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'bayesian_'.$report);
    $reportsbyname[$strreportname] = $report;
}
core_collator::ksort($reportsbyname);

// First get a list of bayesian reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = core_component::get_plugin_list_with_file('bayesianaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'bayesianaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
core_collator::ksort($rulesbyname);

// Create the bayesian settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'bayesian');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$bayesiansettings = new admin_settingpage('modsettingbayesian', $pagetitle, 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Introductory explanation that all the settings are defaults for the add bayesian form.
    $bayesiansettings->add(new admin_setting_heading('bayesianintro', '', get_string('configintro', 'bayesian')));

    // Time limit.
    $setting = new admin_setting_configduration('bayesian/timelimit',
            get_string('timelimit', 'bayesian'), get_string('configtimelimitsec', 'bayesian'),
            '0', 60);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Delay to notify graded attempts.
    $bayesiansettings->add(new admin_setting_configduration('bayesian/notifyattemptgradeddelay',
        get_string('attemptgradeddelay', 'bayesian'), get_string('attemptgradeddelay_desc', 'bayesian'), 5 * HOURSECS, HOURSECS));

    // What to do with overdue attempts.
    $setting = new mod_bayesian_admin_setting_overduehandling('bayesian/overduehandling',
            get_string('overduehandling', 'bayesian'), get_string('overduehandling_desc', 'bayesian'),
            array('value' => 'autosubmit', 'adv' => false), null);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Grace period time.
    $setting = new admin_setting_configduration('bayesian/graceperiod',
            get_string('graceperiod', 'bayesian'), get_string('graceperiod_desc', 'bayesian'),
            '86400');
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Minimum grace period used behind the scenes.
    $bayesiansettings->add(new admin_setting_configduration('bayesian/graceperiodmin',
            get_string('graceperiodmin', 'bayesian'), get_string('graceperiodmin_desc', 'bayesian'),
            60, 1));

    // Number of attempts.
    $options = array(get_string('unlimited'));
    for ($i = 1; $i <= bayesian_MAX_ATTEMPT_OPTION; $i++) {
        $options[$i] = $i;
    }
    $setting = new admin_setting_configselect('bayesian/attempts',
            get_string('attemptsallowed', 'bayesian'), get_string('configattemptsallowed', 'bayesian'),
            0, $options);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Grading method.
    $setting = new mod_bayesian_admin_setting_grademethod('bayesian/grademethod',
            get_string('grademethod', 'bayesian'), get_string('configgrademethod', 'bayesian'),
            array('value' => bayesian_GRADEHIGHEST, 'adv' => false), null);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Maximum grade.
    $setting = new admin_setting_configtext('bayesian/maximumgrade',
            get_string('maximumgrade'), get_string('configmaximumgrade', 'bayesian'), 10, PARAM_INT);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Questions per page.
    $perpage = array();
    $perpage[0] = get_string('never');
    $perpage[1] = get_string('aftereachquestion', 'bayesian');
    for ($i = 2; $i <= bayesian_MAX_QPP_OPTION; ++$i) {
        $perpage[$i] = get_string('afternquestions', 'bayesian', $i);
    }
    $setting = new admin_setting_configselect('bayesian/questionsperpage',
            get_string('newpageevery', 'bayesian'), get_string('confignewpageevery', 'bayesian'),
            1, $perpage);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Navigation method.
    $setting = new admin_setting_configselect('bayesian/navmethod',
            get_string('navmethod', 'bayesian'), get_string('confignavmethod', 'bayesian'),
            bayesian_NAVMETHOD_FREE, bayesian_get_navigation_options());
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Shuffle within questions.
    $setting = new admin_setting_configcheckbox('bayesian/shuffleanswers',
            get_string('shufflewithin', 'bayesian'), get_string('configshufflewithin', 'bayesian'),
            1);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Preferred behaviour.
    $setting = new admin_setting_question_behaviour('bayesian/preferredbehaviour',
            get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'bayesian'),
            'deferredfeedback');
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Can redo completed questions.
    $setting = new admin_setting_configselect('bayesian/canredoquestions',
            get_string('canredoquestions', 'bayesian'), get_string('canredoquestions_desc', 'bayesian'),
            0,
            array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'bayesian')));
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Each attempt builds on last.
    $setting = new admin_setting_configcheckbox('bayesian/attemptonlast',
            get_string('eachattemptbuildsonthelast', 'bayesian'),
            get_string('configeachattemptbuildsonthelast', 'bayesian'),
            0);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Review options.
    $bayesiansettings->add(new admin_setting_heading('reviewheading',
            get_string('reviewoptionsheading', 'bayesian'), ''));
    foreach (mod_bayesian_admin_review_setting::fields() as $field => $name) {
        $default = mod_bayesian_admin_review_setting::all_on();
        $forceduring = null;
        if ($field == 'attempt') {
            $forceduring = true;
        } else if ($field == 'overallfeedback') {
            $default = $default ^ mod_bayesian_admin_review_setting::DURING;
            $forceduring = false;
        }
        $bayesiansettings->add(new mod_bayesian_admin_review_setting('bayesian/review' . $field,
                $name, '', $default, $forceduring));
    }

    // Show the user's picture.
    $setting = new mod_bayesian_admin_setting_user_image('bayesian/showuserpicture',
            get_string('showuserpicture', 'bayesian'), get_string('configshowuserpicture', 'bayesian'),
            array('value' => 0, 'adv' => false), null);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Decimal places for overall grades.
    $options = array();
    for ($i = 0; $i <= bayesian_MAX_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $setting = new admin_setting_configselect('bayesian/decimalpoints',
            get_string('decimalplaces', 'bayesian'), get_string('configdecimalplaces', 'bayesian'),
            2, $options);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Decimal places for question grades.
    $options = array(-1 => get_string('sameasoverall', 'bayesian'));
    for ($i = 0; $i <= bayesian_MAX_Q_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $setting = new admin_setting_configselect('bayesian/questiondecimalpoints',
            get_string('decimalplacesquestion', 'bayesian'),
            get_string('configdecimalplacesquestion', 'bayesian'),
            -1, $options);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Show blocks during bayesian attempts.
    $setting = new admin_setting_configcheckbox('bayesian/showblocks',
            get_string('showblocks', 'bayesian'), get_string('configshowblocks', 'bayesian'),
            0);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Password.
    $setting = new admin_setting_configpasswordunmask('bayesian/bayesianpassword',
            get_string('requirepassword', 'bayesian'), get_string('configrequirepassword', 'bayesian'),
            '');
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_required_flag_options(admin_setting_flag::ENABLED, false);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // IP restrictions.
    $setting = new admin_setting_configtext('bayesian/subnet',
            get_string('requiresubnet', 'bayesian'), get_string('configrequiresubnet', 'bayesian'),
            '', PARAM_TEXT);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Enforced delay between attempts.
    $setting = new admin_setting_configduration('bayesian/delay1',
            get_string('delay1st2nd', 'bayesian'), get_string('configdelay1st2nd', 'bayesian'),
            0, 60);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);
    $setting = new admin_setting_configduration('bayesian/delay2',
            get_string('delaylater', 'bayesian'), get_string('configdelaylater', 'bayesian'),
            0, 60);
    $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    // Browser security.
    $setting = new mod_bayesian_admin_setting_browsersecurity('bayesian/browsersecurity',
            get_string('showinsecurepopup', 'bayesian'), get_string('configpopup', 'bayesian'),
            array('value' => '-', 'adv' => true), null);
    $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
    $bayesiansettings->add($setting);

    $bayesiansettings->add(new admin_setting_configtext('bayesian/initialnumfeedbacks',
            get_string('initialnumfeedbacks', 'bayesian'), get_string('initialnumfeedbacks_desc', 'bayesian'),
            2, PARAM_INT, 5));

    // Allow user to specify if setting outcomes is an advanced setting.
    if (!empty($CFG->enableoutcomes)) {
        $bayesiansettings->add(new admin_setting_configcheckbox('bayesian/outcomes_adv',
            get_string('outcomesadvanced', 'bayesian'), get_string('configoutcomesadvanced', 'bayesian'),
            '0'));
    }

    // Autosave frequency.
    $bayesiansettings->add(new admin_setting_configduration('bayesian/autosaveperiod',
            get_string('autosaveperiod', 'bayesian'), get_string('autosaveperiod_desc', 'bayesian'), 60, 1));
}

// Now, depending on whether any reports have their own settings page, add
// the bayesian setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $bayesiansettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsbayesiancat',
            get_string('modulename', 'bayesian'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsbayesiancat', $bayesiansettings);

    // Add settings pages for the bayesian report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsbayesiancat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        include($CFG->dirroot . "/mod/bayesian/report/$reportname/settings.php");
        if (!empty($settings)) {
            $ADMIN->add('modsettingsbayesiancat', $settings);
        }
    }

    // Add settings pages for the bayesian access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsbayesiancat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        include($CFG->dirroot . "/mod/bayesian/accessrule/$rule/settings.php");
        if (!empty($settings)) {
            $ADMIN->add('modsettingsbayesiancat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
