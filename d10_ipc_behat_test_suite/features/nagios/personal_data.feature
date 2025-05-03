@api @javascript @nagios @disabled  # Disabled from running in the pipelines because it's designed for PROD
Feature: Test That Personal Data Is Displayed
  In order to access their personal data
  Users with any role
  Should be able to view it under the "MY IPC PROFILE" tab on the Dashboard

  Scenario: View Personal Data
    Given I am on the homepage
    And I click the link to allow all cookies
    When I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    When I click on "MY IPC PROFILE" tab
    Then I should see "TEST Instructor" in the ".views-field-field-job-title-redo" element
