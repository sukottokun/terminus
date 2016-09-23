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
    public function import($site,$url) {
        $sites = new Sites();
        $website = $sites->get($site);
        $workflow = $website->environments->get('dev')->import($url);
        $workflow->wait();
        $this->log()->notice("Imported site onto Pantheon");
    }   

}

