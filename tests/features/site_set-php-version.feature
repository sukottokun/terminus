Feature: Managing the PHP version of sites and environments
  In order to make Pantheon work for my site
  As a user
  I need to be able to change which PHP version my site and environments are using.

  Background: I am authenticated and have a site named [[test_site_name]]
    Given I am authenticated
    And a site named "[[test_site_name]]"
    
  @vcr site_set-php-version 
  Scenario: Setting the site's PHP version
    When I run "terminus site set-php-version --site=[[test_site_name]] --version=5.5"
    Then I should get: "Sorry, setting the PHP version has moved to pantheon.yml. You can find out how to change it here: https://pantheon.io/docs/php-versions/"

  @vcr site_set-php-version_environment
  Scenario: Setting the site's PHP version
    When I run "terminus site set-php-version --site=[[test_site_name]] --version=5.3"
    Then I should get: "Sorry, setting the PHP version has moved to pantheon.yml. You can find out how to change it here: https://pantheon.io/docs/php-versions/"

  @vcr site_set-php-version_environment_unset
  Scenario: Setting an environment's PHP version to the site default
    When I run "terminus site set-php-version --site=[[test_site_name]] --env=dev --version=default"
    Then I should get: "Sorry, setting the PHP version has moved to pantheon.yml. You can find out how to change it here: https://pantheon.io/docs/php-versions/"