<?php

namespace Terminus\Models;

use Terminus\Exceptions\TerminusException;
use Terminus\Session;

class Workflow extends TerminusModel
{
  /**
   * @var Environment
   */
    private $environment;
  /**
   * @var Organization
   */
    private $organization;
  /**
   * @var Site
   */
    private $site;
  /**
   * @var User
   */
    private $user;

  /**
   * Object constructor
   *
   * @param object $attributes Attributes of this model
   * @param array  $options    Options with which to configure this model
   * @return Workflow
   */
    public function __construct($attributes = null, array $options = [])
    {
        parent::__construct($attributes, $options);
        $owner = null;
        if (isset($options['collection'])) {
            $owner = $options['collection']->getOwnerObject();
        } elseif (isset($options['environment'])) {
            $owner = $options['environment'];
        } elseif (isset($options['organization'])) {
            $owner = $options['organization'];
        } elseif (isset($options['site'])) {
            $owner = $options['site'];
        } elseif (isset($options['user'])) {
            $owner = $options['user'];
        }

        switch (get_class($owner)) {
            case 'Terminus\Models\Environment':
                $this->environment = $owner;
                $this->url = sprintf(
                    'sites/%s/workflows/%s',
                    $this->environment->site->id,
                    $this->id
                );
                break;
            case 'Terminus\Models\Organization':
                $this->organization = $owner;
                $this->url  = sprintf(
                    'users/%s/organizations/%s/workflows/%s',
                    Session::getUser()->id,
                    $this->organization->id,
                    $this->id
                );
                break;
            case 'Terminus\Models\Site':
                $this->site = $owner;
                $this->url = sprintf(
                    'sites/%s/workflows/%s',
                    $this->site->id,
                    $this->id
                );
                break;
            case 'Terminus\Models\User':
                $this->user = $owner;
                $this->url = sprintf(
                    'users/%s/workflows/%s',
                    $this->user->id,
                    $this->id
                );
                break;
        }
    }

  /**
   * Re-fetches workflow data hydrated with logs
   *
   * @return Workflow
   */
    public function fetchWithLogs()
    {
        $options = ['query' => ['hydrate' => 'operations_with_logs',],];
        $this->fetch($options);
        return $this;
    }

  /**
   * Returns the status of this workflow
   *
   * @return string
   */
    public function getStatus()
    {
        $status = 'running';
        if ($this->isFinished()) {
            $status = 'failed';
            if ($this->isSuccessful()) {
                $status = 'succeeded';
            }
        }
        return $status;
    }

  /**
   * Detects if the workflow has finished
   *
   * @return bool True if workflow has finished
   */
    public function isFinished()
    {
        $is_finished = (boolean)$this->get('result');
        return $is_finished;
    }

  /**
   * Detects if the workflow was successful
   *
   * @return bool True if workflow succeeded
   */
    public function isSuccessful()
    {
        $is_successful = ($this->get('result') == 'succeeded');
        return $is_successful;
    }

  /**
   * Returns a list of WorkflowOperations for this workflow
   *
   * @return WorkflowOperation[]
   */
    public function operations()
    {
        if (is_array($this->get('operations'))) {
            $operations_data = $this->get('operations');
        } else {
            $operations_data = [];
        }

        $operations = [];
        foreach ($operations_data as $operation_data) {
            $operations[] = new WorkflowOperation($operation_data);
        }

        return $operations;
    }

  /**
   * Formats workflow object into an associative array for output
   *
   * @return array Associative array of data for output
   */
    public function serialize()
    {
        $user = 'Pantheon';
        if (isset($this->get('user')->email)) {
            $user = $this->get('user')->email;
        }
        if ($this->get('total_time')) {
            $elapsed_time = $this->get('total_time');
        } else {
            $elapsed_time = time() - $this->get('created_at');
        }

        $operations_data = [];
        foreach ($this->operations() as $operation) {
            $operations_data[] = $operation->serialize();
        }

        $data = [
        'id'             => $this->id,
        'env'            => $this->get('environment'),
        'workflow'       => $this->get('description'),
        'user'           => $user,
        'status'         => $this->getStatus(),
        'time'           => sprintf("%ds", $elapsed_time),
        'operations'     => $operations_data,
        ];

        return $data;
    }

  /**
   * Waits on this workflow to finish
   *
   * @return Workflow|void
   * @throws TerminusException
   */
    public function wait()
    {
        while (!$this->isFinished()) {
            $this->fetch();
            sleep(3);
            /**
       * TODO: Output this to stdout so that it doesn't get mixed with any
       *   actual output. We can't use the logger here because that might be
       *   redirected to a log file where each line is timestamped.
       */
            fwrite(STDERR, '.');
        }
        echo "\n";
        if ($this->isSuccessful()) {
            return $this;
        } else {
            $final_task = $this->get('final_task');
            if (($final_task != null) && !empty($final_task->messages)) {
                foreach ($final_task->messages as $data => $message) {
                    if (!is_string($message->message)) {
                        $message->message = print_r($message->message, true);
                    }
                    throw new TerminusException((string)$message->message);
                }
            }
        }
    }
}
