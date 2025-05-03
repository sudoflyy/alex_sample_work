@api @javascript @nagios @disabled  # Disabled from running in the pipelines because it's designed for PROD
Feature: Test User Login
  In order to gain access to their Dashboard
  Users with any role
  Should be able to login to the site

  Scenario: Perform user login
    Given I am on the homepage
    And I click the link to allow all cookies
    When I am logged-in via SSO as "TEST_USER_1"
    Then I should see "My IPC EDGE" in the ".site-header-top" element
    And I should see "Log out" in the ".site-header-top" element
