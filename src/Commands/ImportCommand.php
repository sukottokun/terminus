<?php

namespace Pantheon\Terminus\Commands;

class ImportCommand extends TerminusCommand
{
    /**
     * @var boolean True if the command requires the user to be logged in
     */
    protected $authorized = true;

    /**
     * Imports a site archive onto a Pantheon site
     *
     * @name import
     * @alias site:import
     *
     * @option string $site Name of the site to import to
     * @option string $url  URL at which the import archive exists
     * @usage terminus import --site=<site_name> --url=<archive_url>
     *   Imports the file at the archive URL to the site named.
     */
    public function import(array $options = []) {

    }

}


    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args,])
    );
    $url = $this->input()->string(
      [
        'args'     => $assoc_args,
        'key'      => 'url',
        'message'  => 'URL of archive to import',
        'required' => true,
      ]
    );
    $message  = 'Are you sure you want to import this archive?';
    $message .= ' The dev environment of %s will be overwritten.';
    $this->input()->confirm(
      [
        'message' => $message,
        'context' => $site->get('name'),
        'args'    => $assoc_args,
      ]
    );
    $workflow = $site->environments->get('dev')->import($url);
    $workflow->wait();
    $this->workflowOutput(
      $workflow,
      ['success' => 'Imported site onto Pantheon',]
    );
