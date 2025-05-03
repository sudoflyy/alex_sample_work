@api @javascript @vouchers
Feature: Create Apprenticeship
  In order to create a Subscription of type Apprenticeship
  Users serving as Administrators
  Should be able to do this via an admin form for the content type

  Scenario: Create Apprenticeship via Admin form
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    When I hover over "Commerce"
    And I click "Add product"



