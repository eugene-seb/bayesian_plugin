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
 * Structure step to restore one bayesian activity
 *
 * @package    mod_bayesian
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_bayesian_activity_structure_step extends restore_questions_activity_structure_step {

    /**
     * @var bool tracks whether the bayesian contains at least one section. Before
     * Moodle 2.9 bayesian sections did not exist, so if the file being restored
     * did not contain any, we need to create one in {@link after_execute()}.
     */
    protected $sectioncreated = false;

    /** @var stdClass|null $currentbayesianattempt Track the current bayesian attempt being restored. */
    protected $currentbayesianattempt = null;

    /**
     * @var bool when restoring old bayesianzes (2.8 or before) this records the
     * shufflequestionsoption bayesian option which has moved to the bayesian_sections table.
     */
    protected $legacyshufflequestionsoption = false;

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $bayesian = new restore_path_element('bayesian', '/activity/bayesian');
        $paths[] = $bayesian;

        // A chance for access subplugings to set up their bayesian data.
        $this->add_subplugin_structure('bayesianaccess', $bayesian);

        $bayesianquestioninstance = new restore_path_element('bayesian_question_instance',
            '/activity/bayesian/question_instances/question_instance');
        $paths[] = $bayesianquestioninstance;
        if ($this->task->get_old_moduleversion() < 2021091700) {
            $paths[] = new restore_path_element('bayesian_slot_tags',
                '/activity/bayesian/question_instances/question_instance/tags/tag');
        } else {
            $this->add_question_references($bayesianquestioninstance, $paths);
            $this->add_question_set_references($bayesianquestioninstance, $paths);
        }
        $paths[] = new restore_path_element('bayesian_section', '/activity/bayesian/sections/section');
        $paths[] = new restore_path_element('bayesian_feedback', '/activity/bayesian/feedbacks/feedback');
        $paths[] = new restore_path_element('bayesian_override', '/activity/bayesian/overrides/override');

        if ($userinfo) {
            $paths[] = new restore_path_element('bayesian_grade', '/activity/bayesian/grades/grade');

            if ($this->task->get_old_moduleversion() > 2011010100) {
                // Restoring from a version 2.1 dev or later.
                // Process the new-style attempt data.
                $bayesianattempt = new restore_path_element('bayesian_attempt',
                        '/activity/bayesian/attempts/attempt');
                $paths[] = $bayesianattempt;

                // Add states and sessions.
                $this->add_question_usages($bayesianattempt, $paths);

                // A chance for access subplugings to set up their attempt data.
                $this->add_subplugin_structure('bayesianaccess', $bayesianattempt);

            } else {
                // Restoring from a version 2.0.x+ or earlier.
                // Upgrade the legacy attempt data.
                $bayesianattempt = new restore_path_element('bayesian_attempt_legacy',
                        '/activity/bayesian/attempts/attempt',
                        true);
                $paths[] = $bayesianattempt;
                $this->add_legacy_question_attempt_data($bayesianattempt, $paths);
            }
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the bayesian data.
     *
     * @param stdClass|array $data
     */
    protected function process_bayesian($data) {
        global $CFG, $DB, $USER;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        if (property_exists($data, 'questions')) {
            // Needed by {@link process_bayesian_attempt_legacy}, in which case it will be present.
            $this->oldbayesianlayout = $data->questions;
        }

        // The setting bayesian->attempts can come both in data->attempts and
        // data->attempts_number, handle both. MDL-26229.
        if (isset($data->attempts_number)) {
            $data->attempts = $data->attempts_number;
            unset($data->attempts_number);
        }

        // The old optionflags and penaltyscheme from 2.0 need to be mapped to
        // the new preferredbehaviour. See MDL-20636.
        if (!isset($data->preferredbehaviour)) {
            if (empty($data->optionflags)) {
                $data->preferredbehaviour = 'deferredfeedback';
            } else if (empty($data->penaltyscheme)) {
                $data->preferredbehaviour = 'adaptivenopenalty';
            } else {
                $data->preferredbehaviour = 'adaptive';
            }
            unset($data->optionflags);
            unset($data->penaltyscheme);
        }

        // The old review column from 2.0 need to be split into the seven new
        // review columns. See MDL-20636.
        if (isset($data->review)) {
            require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

            if (!defined('bayesian_OLD_IMMEDIATELY')) {
                define('bayesian_OLD_IMMEDIATELY', 0x3c003f);
                define('bayesian_OLD_OPEN',        0x3c00fc0);
                define('bayesian_OLD_CLOSED',      0x3c03f000);

                define('bayesian_OLD_RESPONSES',        1*0x1041);
                define('bayesian_OLD_SCORES',           2*0x1041);
                define('bayesian_OLD_FEEDBACK',         4*0x1041);
                define('bayesian_OLD_ANSWERS',          8*0x1041);
                define('bayesian_OLD_SOLUTIONS',       16*0x1041);
                define('bayesian_OLD_GENERALFEEDBACK', 32*0x1041);
                define('bayesian_OLD_OVERALLFEEDBACK',  1*0x4440000);
            }

            $oldreview = $data->review;

            $data->reviewattempt =
                    mod_bayesian_display_options::DURING |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_RESPONSES ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_RESPONSES ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_RESPONSES ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewcorrectness =
                    mod_bayesian_display_options::DURING |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewmarks =
                    mod_bayesian_display_options::DURING |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_SCORES ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewspecificfeedback =
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_FEEDBACK ?
                            mod_bayesian_display_options::DURING : 0) |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_FEEDBACK ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_FEEDBACK ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_FEEDBACK ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewgeneralfeedback =
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_GENERALFEEDBACK ?
                            mod_bayesian_display_options::DURING : 0) |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_GENERALFEEDBACK ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_GENERALFEEDBACK ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_GENERALFEEDBACK ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewrightanswer =
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_ANSWERS ?
                            mod_bayesian_display_options::DURING : 0) |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_ANSWERS ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_ANSWERS ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_ANSWERS ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);

            $data->reviewoverallfeedback =
                    0 |
                    ($oldreview & bayesian_OLD_IMMEDIATELY & bayesian_OLD_OVERALLFEEDBACK ?
                            mod_bayesian_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & bayesian_OLD_OPEN & bayesian_OLD_OVERALLFEEDBACK ?
                            mod_bayesian_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & bayesian_OLD_CLOSED & bayesian_OLD_OVERALLFEEDBACK ?
                            mod_bayesian_display_options::AFTER_CLOSE : 0);
        }

        // The old popup column from from <= 2.1 need to be mapped to
        // the new browsersecurity. See MDL-29627.
        if (!isset($data->browsersecurity)) {
            if (empty($data->popup)) {
                $data->browsersecurity = '-';
            } else if ($data->popup == 1) {
                $data->browsersecurity = 'securewindow';
            } else if ($data->popup == 2) {
                // Since 3.9 bayesianaccess_safebrowser replaced with a new bayesianaccess_seb.
                $data->browsersecurity = '-';
                $addsebrule = true;
            } else {
                $data->preferredbehaviour = '-';
            }
            unset($data->popup);
        } else if ($data->browsersecurity == 'safebrowser') {
            // Since 3.9 bayesianaccess_safebrowser replaced with a new bayesianaccess_seb.
            $data->browsersecurity = '-';
            $addsebrule = true;
        }

        if (!isset($data->overduehandling)) {
            $data->overduehandling = get_config('bayesian', 'overduehandling');
        }

        // Old shufflequestions setting is now stored in bayesian sections,
        // so save it here if necessary so it is available when we need it.
        $this->legacyshufflequestionsoption = !empty($data->shufflequestions);

        // Insert the bayesian record.
        $newitemid = $DB->insert_record('bayesian', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);

        // Process Safe Exam Browser settings for backups taken in Moodle < 3.9.
        if (!empty($addsebrule)) {
            $sebsettings = new stdClass();

            $sebsettings->bayesianid = $newitemid;
            $sebsettings->cmid = $this->task->get_moduleid();
            $sebsettings->templateid = 0;
            $sebsettings->requiresafeexambrowser = \bayesianaccess_seb\settings_provider::USE_SEB_CLIENT_CONFIG;
            $sebsettings->showsebtaskbar = null;
            $sebsettings->showwificontrol = null;
            $sebsettings->showreloadbutton = null;
            $sebsettings->showtime = null;
            $sebsettings->showkeyboardlayout = null;
            $sebsettings->allowuserquitseb = null;
            $sebsettings->quitpassword = null;
            $sebsettings->linkquitseb = null;
            $sebsettings->userconfirmquit = null;
            $sebsettings->enableaudiocontrol = null;
            $sebsettings->muteonstartup = null;
            $sebsettings->allowspellchecking = null;
            $sebsettings->allowreloadinexam = null;
            $sebsettings->activateurlfiltering = null;
            $sebsettings->filterembeddedcontent = null;
            $sebsettings->expressionsallowed = null;
            $sebsettings->regexallowed = null;
            $sebsettings->expressionsblocked = null;
            $sebsettings->regexblocked = null;
            $sebsettings->allowedbrowserexamkeys = null;
            $sebsettings->showsebdownloadlink = 1;
            $sebsettings->usermodified = $USER->id;
            $sebsettings->timecreated = time();
            $sebsettings->timemodified = time();

            $DB->insert_record('bayesianaccess_seb_settings', $sebsettings);
        }

        // If we are dealing with a backup from < 4.0 then we need to move completionpass to core.
        if (!empty($data->completionpass)) {
            $params = ['id' => $this->task->get_moduleid()];
            $DB->set_field('course_modules', 'completionpassgrade', $data->completionpass, $params);
        }
    }

    /**
     * Process the data for pre 4.0 bayesian data where the question_references and question_set_references table introduced.
     *
     * @param stdClass|array $data
     */
    protected function process_bayesian_question_legacy_instance($data) {
        global $DB;

        $questionid = $this->get_mappingid('question', $data->questionid);
        $sql = 'SELECT qbe.id as questionbankentryid,
                       qc.contextid as questioncontextid,
                       qc.id as category,
                       qv.version,
                       q.qtype,
                       q.id as questionid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = ?';
        $question = $DB->get_record_sql($sql, [$questionid]);
        $module = $DB->get_record('bayesian', ['id' => $data->bayesianid]);

        if ($question->qtype === 'random') {
            // Set reference data.
            $questionsetreference = new \stdClass();
            $questionsetreference->usingcontextid = context_module::instance(get_coursemodule_from_instance(
                "bayesian", $module->id, $module->course)->id)->id;
            $questionsetreference->component = 'mod_bayesian';
            $questionsetreference->questionarea = 'slot';
            $questionsetreference->itemid = $data->id;
            $questionsetreference->questionscontextid = $question->questioncontextid;
            $filtercondition = new stdClass();
            $filtercondition->questioncategoryid = $question->category;
            $filtercondition->includingsubcategories = $data->includingsubcategories;
            $questionsetreference->filtercondition = json_encode($filtercondition);
            $DB->insert_record('question_set_references', $questionsetreference);
            // Cleanup leftover random qtype data from question table.
            question_delete_question($question->questionid);
        } else {
            // Reference data.
            $questionreference = new \stdClass();
            $questionreference->usingcontextid = context_module::instance(get_coursemodule_from_instance(
                "bayesian", $module->id, $module->course)->id)->id;
            $questionreference->component = 'mod_bayesian';
            $questionreference->questionarea = 'slot';
            $questionreference->itemid = $data->id;
            $questionreference->questionbankentryid = $question->questionbankentryid;
            $questionreference->version = null; // Default to Always latest.
            $DB->insert_record('question_references', $questionreference);
        }
    }

    /**
     * Process bayesian slots.
     *
     * @param stdClass|array $data
     */
    protected function process_bayesian_question_instance($data) {
        global $CFG, $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Backwards compatibility for old field names (MDL-43670).
        if (!isset($data->questionid) && isset($data->question)) {
            $data->questionid = $data->question;
        }
        if (!isset($data->maxmark) && isset($data->grade)) {
            $data->maxmark = $data->grade;
        }

        if (!property_exists($data, 'slot')) {
            $page = 1;
            $slot = 1;
            foreach (explode(',', $this->oldbayesianlayout) as $item) {
                if ($item == 0) {
                    $page += 1;
                    continue;
                }
                if (isset($data->questionid) && $item == $data->questionid) {
                    $data->slot = $slot;
                    $data->page = $page;
                    break;
                }
                $slot += 1;
            }
        }

        if (!property_exists($data, 'slot')) {
            // There was a question_instance in the backup file for a question
            // that was not actually in the bayesian. Drop it.
            $this->log('question ' . $data->questionid . ' was associated with bayesian ' .
                    $this->get_new_parentid('bayesian') . ' but not actually used. ' .
                    'The instance has been ignored.', backup::LOG_INFO);
            return;
        }

        $data->bayesianid = $this->get_new_parentid('bayesian');

        $newitemid = $DB->insert_record('bayesian_slots', $data);
        // Add mapping, restore of slot tags (for random questions) need it.
        $this->set_mapping('bayesian_question_instance', $oldid, $newitemid);

        if ($this->task->get_old_moduleversion() < 2022020300) {
            $data->id = $newitemid;
            $this->process_bayesian_question_legacy_instance($data);
        }
    }

    /**
     * Process a bayesian_slot_tags to restore the tags to the new structure.
     *
     * @param stdClass|array $data The bayesian_slot_tags data
     */
    protected function process_bayesian_slot_tags($data) {
        global $DB;

        $data = (object) $data;
        $slotid = $this->get_new_parentid('bayesian_question_instance');

        if ($this->task->is_samesite() && $tag = core_tag_tag::get($data->tagid, 'id, name')) {
            $data->tagname = $tag->name;
        } else if ($tag = core_tag_tag::get_by_name(0, $data->tagname, 'id, name')) {
            $data->tagid = $tag->id;
        } else {
            $data->tagid = null;
            $data->tagname = $tag->name;
        }

        $tagstring = "{$data->tagid},{$data->tagname}";
        $setreferencedata = $DB->get_record('question_set_references',
            ['itemid' => $slotid, 'component' => 'mod_bayesian', 'questionarea' => 'slot']);
        $filtercondition = json_decode($setreferencedata->filtercondition);
        $filtercondition->tags[] = $tagstring;
        $setreferencedata->filtercondition = json_encode($filtercondition);
        $DB->update_record('question_set_references', $setreferencedata);
    }

    protected function process_bayesian_section($data) {
        global $DB;

        $data = (object) $data;
        $data->bayesianid = $this->get_new_parentid('bayesian');
        $oldid = $data->id;
        $newitemid = $DB->insert_record('bayesian_sections', $data);
        $this->sectioncreated = true;
        $this->set_mapping('bayesian_section', $oldid, $newitemid, true);
    }

    protected function process_bayesian_feedback($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->bayesianid = $this->get_new_parentid('bayesian');

        $newitemid = $DB->insert_record('bayesian_feedback', $data);
        $this->set_mapping('bayesian_feedback', $oldid, $newitemid, true); // Has related files.
    }

    protected function process_bayesian_override($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Based on userinfo, we'll restore user overides or no.
        $userinfo = $this->get_setting_value('userinfo');

        // Skip user overrides if we are not restoring userinfo.
        if (!$userinfo && !is_null($data->userid)) {
            return;
        }

        $data->bayesian = $this->get_new_parentid('bayesian');

        if ($data->userid !== null) {
            $data->userid = $this->get_mappingid('user', $data->userid);
        }

        if ($data->groupid !== null) {
            $data->groupid = $this->get_mappingid('group', $data->groupid);
        }

        // Skip if there is no user and no group data.
        if (empty($data->userid) && empty($data->groupid)) {
            return;
        }

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        $newitemid = $DB->insert_record('bayesian_overrides', $data);

        // Add mapping, restore of logs needs it.
        $this->set_mapping('bayesian_override', $oldid, $newitemid);
    }

    protected function process_bayesian_grade($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->bayesian = $this->get_new_parentid('bayesian');

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->grade = $data->gradeval;

        $DB->insert_record('bayesian_grades', $data);
    }

    protected function process_bayesian_attempt($data) {
        $data = (object)$data;

        $data->bayesian = $this->get_new_parentid('bayesian');
        $data->attempt = $data->attemptnum;

        // Get user mapping, return early if no mapping found for the bayesian attempt.
        $olduserid = $data->userid;
        $data->userid = $this->get_mappingid('user', $olduserid, 0);
        if ($data->userid === 0) {
            $this->log('Mapped user ID not found for user ' . $olduserid . ', bayesian ' . $this->get_new_parentid('bayesian') .
                ', attempt ' . $data->attempt . '. Skipping bayesian attempt', backup::LOG_INFO);

            $this->currentbayesianattempt = null;
            return;
        }

        if (!empty($data->timecheckstate)) {
            $data->timecheckstate = $this->apply_date_offset($data->timecheckstate);
        } else {
            $data->timecheckstate = 0;
        }

        if (!isset($data->gradednotificationsenttime)) {
            // For attempts restored from old Moodle sites before this field
            // existed, we never want to send emails.
            $data->gradednotificationsenttime = $data->timefinish;
        }

        // Deals with up-grading pre-2.3 back-ups to 2.3+.
        if (!isset($data->state)) {
            if ($data->timefinish > 0) {
                $data->state = 'finished';
            } else {
                $data->state = 'inprogress';
            }
        }

        // The data is actually inserted into the database later in inform_new_usage_id.
        $this->currentbayesianattempt = clone($data);
    }

    protected function process_bayesian_attempt_legacy($data) {
        global $DB;

        $this->process_bayesian_attempt($data);

        $bayesian = $DB->get_record('bayesian', array('id' => $this->get_new_parentid('bayesian')));
        $bayesian->oldquestions = $this->oldbayesianlayout;
        $this->process_legacy_bayesian_attempt_data($data, $bayesian);
    }

    protected function inform_new_usage_id($newusageid) {
        global $DB;

        $data = $this->currentbayesianattempt;
        if ($data === null) {
            return;
        }

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('bayesian_attempts', $data);

        // Save bayesian_attempt->id mapping, because logs use it.
        $this->set_mapping('bayesian_attempt', $oldid, $newitemid, false);
    }

    protected function after_execute() {
        global $DB;

        parent::after_execute();
        // Add bayesian related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_bayesian', 'intro', null);
        // Add feedback related files, matching by itemname = 'bayesian_feedback'.
        $this->add_related_files('mod_bayesian', 'feedback', 'bayesian_feedback');

        if (!$this->sectioncreated) {
            $DB->insert_record('bayesian_sections', array(
                    'bayesianid' => $this->get_new_parentid('bayesian'),
                    'firstslot' => 1, 'heading' => '',
                    'shufflequestions' => $this->legacyshufflequestionsoption));
        }
    }
}
