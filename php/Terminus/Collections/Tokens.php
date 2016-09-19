<?php

namespace Terminus\Collections;

use Terminus\Config;
use Terminus\Exceptions\TerminusException;

class Tokens extends TerminusCollection {
    /**
     * @var string
     */
  protected $collected_class = 'Terminus\Models\Token';
    /**
     * @var string
     */
  protected $directory;

    /**
     * Object constructor
     *
     * @param array $options Options to configure this collection
     */
  public function __construct($options = []) {
    parent::__construct($options);
    if (isset($options['directory'])) {
      $this->directory = $options['directory'];
    } else {
      $this->directory = Config::get('tokens_dir');
    }
  }

    /**
     * Adds a record for a machine token to the tokens cache.
     *
     * @param string[] $token_data Elements as follow:
     *   string email Email address for the account associated with the token
     *   string token Token to be saved
     * @return bool
     * @throws TerminusException
     */
  public function create(array $token_data = []) {
    $file_name = "{$this->directory}/{$token_data['email']}";
    $token_data['date'] = time();
    $status = (boolean)file_put_contents($file_name, json_encode($token_data));
    return $status;
  }

    /**
     * Fetches model data from API and instantiates its model instances
     *
     * @param array $options Parameters to pass into the URL request
     * @return Plugins $this
     */
  public function fetch(array $options = []) {
    $tokens = $this->getCollectionData($options);
    foreach ($tokens as $token) {
      $token->id = $token->email;
      die(print_r($token, true));
      $this->add($token);
    }
    return $this;
  }

    /**
     * Retrieves the model of the given ID/email or machine token
     *
     * @param string $id Identifier of desired token
     * @return Token
     * @throws TerminusException
     */
  public function get($id) {
    $tokens = $this->getMembers();
    foreach ($tokens as $token) {
      if (in_array(
        $id,
        [$token->id, $token->get('token')]
      )) {
        return $token;
      }
    }
    throw new TerminusException(
      'Could not find {model} "{id}"',
      ['model' => $this->collected_class, 'id' => $id,]
    );
  }

    /**
     * Retrieves collection data from the tokens file
     *
     * @param array $options Options for the ancestor class
     * @return array
     */
  protected function getCollectionData($options = []) {
    if (isset($options['directory'])) {
      $directory = $options['directory'];
    } else {
      $directory = $this->directory;
    }
    $files = array_map(
      function ($file) use ($directory) {
          $file = json_decode(file_get_contents("$directory/$file"));
          return $file;
      },
      array_filter(
        scandir($directory),
        function ($member) use ($directory) {
            $is_file = is_file("$directory/$member");
            return $is_file;
        }
      )
    );
    return $files;
  }

}
