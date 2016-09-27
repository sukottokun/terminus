<?php
namespace Pantheon\Terminus\Commands\Site;

use Pantheon\Terminus\Commands\TerminusCommand;
use Terminus\Collections\Sites;
use Terminus\Models\Environment;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ImportCommand extends TerminusCommand
{
    /**
     * Imports a site archive onto a Pantheon site
     *
     * @authorized
     *
     * @name site:import
     * @alias import
     *
     * @option string $site Name of the site to import to
     * @option string $url  URL at which the import archive exists
     * @usage terminus import --site=<site_name> --url=<archive_url>
     *   Imports the file at the archive URL to the site named.
     */
    public function import($sitename, $url)
    {
        print_r($sitename);
        $sites = new Sites(); 
        $site = $sites->get($site); 
        $workflow = $site->environments->get('dev')->import($url);
        $workflow->wait();
        $this->log()->notice("Imported site onto Pantheon");
    }
}
