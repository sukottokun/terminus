<?php

namespace Terminus\Commands;

use Terminus\Collections\Tokens;
use Terminus\Config;
use Terminus\Models\Auth;
use Terminus\Session;

/**
 * Authenticate to Pantheon and store a local secret token.
 *
 * @command auth
 */
class AuthCommand extends TerminusCommand {
  /**
   * @var Auth
   */
  private $auth;

  /**
   * Object constructor
   *
   * @param array $options Options to construct the command object
   * @return AuthCommand
   */
  public function __construct(array $options = []) {
    parent::__construct($options);
    $this->auth = new Auth();
  }

  /**
   * Log in as a user
   *
   *  ## OPTIONS
   * [<email>]
   * : Email address to log in as.
   *
   * [--password=<value>]
   * : Log in non-interactively with this password. Useful for automation.
   *
   * [--machine-token=<value>]
   * : Authenticates using a machine token from your dashboard. Stores the
   *   token for future use.
   *
   * [--debug]
   * : dump call information when logging in.
   */
  public function login($args, $assoc_args) {
    $tokens = new Tokens();
    $tokens_array = $tokens->all();
    $options = ['email' => null, 'machine-token' => null,];
    if (!empty($args)) {
      $options['email'] = array_shift($args);
    } elseif (isset($assoc_args['machine-token'])) {
      $options['machine-token'] = $assoc_args['machine-token'];
    } elseif (count($tokens_array) == 1) {
      $options['email'] = $tokens_array[0]->get('email');
      $this->log()->notice('Found a machine token for {email}.', $options);
    } elseif (!empty($email = Config::get('user'))) {
      $options['email'] = $email;
    }
    if (is_null($options['machine-token']) && is_null($options['email'])) {
      if (count($tokens_array) > 1) {
        throw new TerminusException(
          "Tokens were saved for the following email addresses:\n{tokens}\n You may
              log in via `terminus auth:login <email>` , or you may visit the dashboard 
              to generate a machine token:\n {url}",
          [
            'url' => $this->auth->getMachineTokenCreationUrl(),
            'tokens' => implode("\n", $tokens_array),
          ]
        );
      } else {
        throw new TerminusException(
          "Please visit the dashboard to generate a machine token:\n {url}",
          ['url' => $this->auth->getMachineTokenCreationUrl(),]
        );
      }

    }
    if (isset($assoc_args['password'])) {
      $this->log()->info('Logging in via email and password.');
      $this->auth->logInViaUsernameAndPassword($options['email'], $assoc_args['password']);
    } else {
      $this->log()->info('Logging in via machine token.');
      $this->auth->logInViaMachineToken($options);
    }

    $this->log()->debug(get_defined_vars());
    $this->helpers->launch->launchSelf(['command' => 'art', 'args' => ['fist']]);
  }

  /**
   * Log yourself out and remove the secret session key.
   */
  public function logout() {
    $this->log()->info('Logging out of Pantheon.');
    $this->auth->logOut();
  }

  /**
   * Find out what user you are logged in as.
   */
  public function whoami() {
    if (Session::getValue('user_uuid')) {
      $user = Session::getUser();
      $user->fetch();

      $data = $user->serialize();
      $this->output()->outputRecord($data);
    } else {
      $this->failure('You are not logged in.');
    }
  }

}
