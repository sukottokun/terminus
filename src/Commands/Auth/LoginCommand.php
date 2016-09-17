<?php

namespace Pantheon\Terminus\Commands\Auth;

use Pantheon\Terminus\Commands\TerminusCommand;
use Terminus\Collections\Tokens;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Auth;

class LoginCommand extends TerminusCommand
{

    /**
     * Logs a user into Pantheon
     *
     * @command auth:login
     * @aliases login
     *
     * @option machine-token A machine token to be saved for future logins
     * @usage terminus auth:login --machine-token=111111111111111111111111111111111111111111111
     *   Logs in the user granted machine token "111111111111111111111111111111111111111111111"
     * @usage terminus auth:login
     *   Logs in your user with a previously saved machine token
     * @usage terminus auth:login <email_address>
     *   Logs in your user with a previously saved machine token belonging to the account linked to the given email
     */
    public function logIn(array $options = ['machine-token' => null, 'email' => null,])
    {
        $auth = new Auth();
        $tokens = new Tokens();
        if (is_null($options['machine-token']) && is_null($options['email']))
        {
            $tokens_array = $tokens->all();
            if (count($tokens_array) == 1)
            {
                $email = $tokens_array[0]->get('email');
                $this->log()->notice('Found a machine token for {email}.', compact('email'));
                $options['email'] = $email;
            }
            elseif (count($tokens_array) > 1)
            {
                throw new TerminusException(
                    "Tokens were saved for the following email addresses:\n{tokens}\n You may log in via "
                        . "`terminus auth:login <email>` , or you may visit the dashboard to generate a machine "
                        . " token:\n {url}"
                    ['url' => $auth->getMachineTokenCreationUrl(), 'tokens' => implode("\n", $tokens_array),]
                );
            }
            else
            {
                throw new TerminusException(
                  "Please visit the dashboard to generate a machine token:\n {url}"
                  ['url' => $auth->getMachineTokenCreationUrl(),]
            }

        }
        $this->log()->notice('Logging in via machine token.');
        $auth->logInViaMachineToken($options);
    }
}
