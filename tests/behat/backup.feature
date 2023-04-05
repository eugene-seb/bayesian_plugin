@mod @mod_bayesian
Feature: Backup and restore of bayesianzes
  In order to reuse my bayesianzes
  As a teacher
  I need to be able to back them up and restore them.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And I log in as "admin"

  @javascript
  Scenario: Duplicate a bayesian with two questions
    Given the following "activities" exist:
      | activity   | name   | intro              | course | idnumber |
      | bayesian       | bayesian 1 | For testing backup | C1     | bayesian1    |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext    |
      | Test questions   | truefalse   | TF1  | First question  |
      | Test questions   | truefalse   | TF2  | Second question |
    And bayesian "bayesian 1" contains the following questions:
      | question | page |
      | TF1      | 1    |
      | TF2      | 2    |
    When I am on "Course 1" course homepage with editing mode on
    And I duplicate "bayesian 1" activity editing the new copy with:
      | Name | bayesian 2 |
    And I am on the "bayesian 1" "mod_bayesian > Edit" page
    Then I should see "TF1"
    And I should see "TF2"

  @javascript
  Scenario: Backup and restore a course containing a bayesian with user data.
    Given the following "activities" exist:
      | activity   | name   | intro              | course | idnumber |
      | bayesian       | bayesian 1 | For testing backup | C1     | bayesian1    |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext    |
      | Test questions   | truefalse   | TF1  | First question  |
      | Test questions   | truefalse   | TF2  | Second question |
    And bayesian "bayesian 1" contains the following questions:
      | question | page |
      | TF1      | 1    |
      | TF2      | 2    |
    And the following "users" exist:
      | username |
      | student  |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | student | C1     | student |
    And user "student" has attempted "bayesian 1" with responses:
      | slot | response |
      | 1    | True     |
      | 2    | False    |
    When I backup "Course 1" course using this options:
      | Confirmation | Filename | test_backup.mbz |
    And I restore "test_backup.mbz" backup into a new course using this options:
      | Schema | Course name | Restored course |
    Then I should see "Restored course"
    And I click on "bayesian 1" "link" in the "region-main" "region"
    And I should see "Attempts: 1"

  @javascript @_file_upload
  Scenario: Restore a Moodle 2.8 bayesian backup
    When I am on the "Course 1" "restore" page
    And I press "Manage backup files"
    And I upload "mod/bayesian/tests/fixtures/moodle_28_bayesian.mbz" file to "Files" filemanager
    And I press "Save changes"
    And I restore "moodle_28_bayesian.mbz" backup into "Course 1" course using this options:
    And I am on the "Restored Moodle 2.8 bayesian" "mod_bayesian > Edit" page
    Then I should see "TF1"
    And I should see "TF2"
