Feature: CLI Commands
  In order to control Terminus
  As a user
  I need to be able to check and clear system files.

  Scenario: Dumping a big list of commands
    When I run "terminus cli cmd-dump"
    Then I should get:
    """
    Dump the list of installed commands, as JSON.
    """

  Scenario: Displaying Terminus information
    When I run "terminus cli info"
    Then I should get:
    """
    Terminus version
    """

  Scenario: Dumping Terminus' parameters
    When I run "terminus cli param-dump"
    Then I should get:
    """
    Answer yes to all prompts
    """
  @vcr cli_session-clear
  Scenario: Clearing a session
    Given I am authenticated
    And I run "terminus cli session-clear"
    And I run "terminus cli session-dump --format=json"
    Then I should get:
    """
    []
    """

  Scenario: Dumping an empty session
    Given I am not authenticated
    When I run "terminus cli session-dump --format=json"
    Then I should get:
    """
    []
    """

  @vcr cli_session-dump
  Scenario: Dumping a session
    Given I am authenticated
    When I run "terminus cli session-dump"
    Then I should get:
    """
    [user_uuid] => [[user_uuid]]
    """

  Scenario: Displaying the current Terminus version
    When I run "terminus cli version"
    Then I should get:
    """
    Terminus version
    """
