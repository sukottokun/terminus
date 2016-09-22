<?php
namespace Pantheon\Terminus\Commands\Site;
use Pantheon\Terminus\Commands\TerminusCommand;
use Terminus\Collections\Sites;
use Terminus\Models\Environment;
use Symfony\Component\Console\Question\ConfirmationQuestion;
class ImportCommand extends TerminusCommand
{
    /**
     * @var boolean True if the command requires the user to be logged in
     */
    protected $authorized = true;

    /**
     * Imports a site archive onto a Pantheon site
     *
     * @name site:import
     * @alias import
     * @field-labels
     *   site: Environment
     *   url: Parameter
     * @option string $site Name of the site to import to
     * @option string $url  URL at which the import archive exists
     * @usage terminus import --site=<site_name> --url=<archive_url>
     *   Imports the file at the archive URL to the site named.
     */
    public function import(array $options = ['site' => null, 'url' => null,]) {
        // hook into sites 
        $sites = new Sites();
        // get site
        $site = $sites->get($options['site']);
        // set up url
        $url = $options['url'];
        // always import to dev
        $workflow = $site->environments->get('dev')->import($url);
        //wait for workflow to do it's thing
        $workflow->wait();
        //check the output and respond with the correct notice type:
        if ($workflow->get('active_description')) {
            $this->log()->notice("Success");
        }
        if ($workflow->get('final_task')->reason) {
            new TerminusException();
        }

    }   

}




