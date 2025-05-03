@api @javascript @products
Feature: Verify Product Details
  In order to perform actions on Products
  Users serving as Instructor
  Should be able to view Product Listings and Product Details

  Scenario: View product details - No pre-reqs
    Given I am on the homepage
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_6"
    And I confirm that the "Course Title Short" field is correct on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Description" field is correct on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Language" field is correct on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Modality" field is correct on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Quantity" field is correct on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Course Content" tab exists on Product Details Page for "TEST_PRODUCT_6"
    And I confirm that the "Who Should Take This Course" tab exists on Product Details Page for "TEST_PRODUCT_6"
    Then I confirm that the "Modality" tab exists on Product Details Page for "TEST_PRODUCT_6"

  Scenario: View product details - With suggested pre-reqs
    Given I am on the homepage
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_7"
    And I confirm that the "Course Title Short" field is correct on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Description" field is correct on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Language" field is correct on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Modality" field is correct on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Quantity" field is correct on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Course Content" tab exists on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Who Should Take This Course" tab exists on Product Details Page for "TEST_PRODUCT_7"
    And I confirm that the "Modalities" tab exists on Product Details Page for "TEST_PRODUCT_7"
    Then I confirm that the "Suggested Prerequisites" tab exists on Product Details Page for "TEST_PRODUCT_7"

  Scenario: View product details - With pre-requisite
    Given I am on the homepage
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_8"
    And I confirm that the "Course Title" field is correct on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Description" field is correct on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Language" field is correct on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Modality" field is correct on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Quantity" field is correct on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Course Content" tab exists on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Who Should Take This Course" tab exists on Product Details Page for "TEST_PRODUCT_8"
    And I confirm that the "Modality" tab exists on Product Details Page for "TEST_PRODUCT_8"
    Then I confirm that the "Prerequisites" tab exists on Product Details Page for "TEST_PRODUCT_8"
