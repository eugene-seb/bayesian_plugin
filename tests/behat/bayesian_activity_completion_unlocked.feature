@mod @mod_bayesian @core_completion
Feature: Activity completion in the bayesian activity with unlocked and re-grading.
  In order to have visibility of bayesian completion requirements
  As a student
  I need to be able to view my bayesian completion progress even teacher have re-grading the grade pass.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following config values are set as admin:
      | grade_item_advanced | hiddenuntil |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name            | questiontext               | defaultmark |
      | Test questions   | truefalse | First question  | Answer the first question  | 8           |
      | Test questions   | truefalse | Second question | Answer the second question | 2           |
    And the following "activity" exists:
      | activity            | bayesian           |
      | course              | C1             |
      | idnumber            | bayesian1          |
      | name                | Test bayesian name |
      | section             | 1              |
      | gradepass           | 10.00          |
      | grade               | 10             |
      | grademethod         | 1              |
      | completion          | 2              |
      | completionusegrade  | 1              |
      | completionpassgrade | 1              |
    And bayesian "Test bayesian name" contains the following questions:
      | question        | page |
      | First question  | 1    |
      | Second question | 2    |

  @javascript
  Scenario: Student will receive correct completion even when teacher unlocked completion and re-grading.
    Given I am on the "Test bayesian name" "bayesian activity" page logged in as student1
    And the "Receive a grade" completion condition of "Test bayesian name" is displayed as "todo"
    And the "Receive a passing grade" completion condition of "Test bayesian name" is displayed as "todo"
    And user "student1" has attempted "Test bayesian name" with responses:
      | slot | response |
      | 1    | True     |
      | 2    | False    |
    And I am on "Course 1" course homepage
    And I follow "Test bayesian name"
    And the "Receive a grade" completion condition of "Test bayesian name" is displayed as "done"
    And the "Receive a passing grade" completion condition of "Test bayesian name" is displayed as "failed"
    And I log out
    When I am on the "Test bayesian name" "bayesian activity" page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I press "Unlock completion settings"
    And I set the following fields to these values:
      | gradepass | 8 |
    And I press "Save and return to course"
    And I navigate to "Reports > Activity completion" in current page administration
    Then "Completed (achieved pass grade)" "icon" should exist in the "Student 1" "table_row"
