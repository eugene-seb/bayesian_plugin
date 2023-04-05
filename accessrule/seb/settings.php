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
 * Global configuration settings for the bayesianaccess_seb plugin.
 *
 * @package    bayesianaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  2019 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

global $ADMIN;

if ($hassiteconfig) {

    $settings->add(new admin_setting_heading(
        'bayesianaccess_seb/supportedversions',
        '',
        $OUTPUT->notification(get_string('setting:supportedversions', 'bayesianaccess_seb'), 'warning')));

    $settings->add(new admin_setting_configcheckbox('bayesianaccess_seb/autoreconfigureseb',
        get_string('setting:autoreconfigureseb', 'bayesianaccess_seb'),
        get_string('setting:autoreconfigureseb_desc', 'bayesianaccess_seb'),
        '1'));

    $links = [
        'seb' => get_string('setting:showseblink', 'bayesianaccess_seb'),
        'http' => get_string('setting:showhttplink', 'bayesianaccess_seb')
    ];
    $settings->add(new admin_setting_configmulticheckbox('bayesianaccess_seb/showseblinks',
        get_string('setting:showseblinks', 'bayesianaccess_seb'),
        get_string('setting:showseblinks_desc', 'bayesianaccess_seb'),
        $links, $links));

    $settings->add(new admin_setting_configtext('bayesianaccess_seb/downloadlink',
        get_string('setting:downloadlink', 'bayesianaccess_seb'),
        get_string('setting:downloadlink_desc', 'bayesianaccess_seb'),
        'https://safeexambrowser.org/download_en.html',
        PARAM_URL));

    $settings->add(new admin_setting_configcheckbox('bayesianaccess_seb/bayesianpasswordrequired',
        get_string('setting:bayesianpasswordrequired', 'bayesianaccess_seb'),
        get_string('setting:bayesianpasswordrequired_desc', 'bayesianaccess_seb'),
        '0'));

    $settings->add(new admin_setting_configcheckbox('bayesianaccess_seb/displayblocksbeforestart',
        get_string('setting:displayblocksbeforestart', 'bayesianaccess_seb'),
        get_string('setting:displayblocksbeforestart_desc', 'bayesianaccess_seb'),
        '0'));

    $settings->add(new admin_setting_configcheckbox('bayesianaccess_seb/displayblockswhenfinished',
        get_string('setting:displayblockswhenfinished', 'bayesianaccess_seb'),
        get_string('setting:displayblockswhenfinished_desc', 'bayesianaccess_seb'),
        '1'));
}

if (has_capability('bayesianaccess/seb:managetemplates', context_system::instance())) {
    $ADMIN->add('modsettingsbayesiancat',
        new admin_externalpage(
            'bayesianaccess_seb/template',
            get_string('manage_templates', 'bayesianaccess_seb'),
            new moodle_url('/mod/bayesian/accessrule/seb/template.php'),
            'bayesianaccess/seb:managetemplates'
        )
    );
}
