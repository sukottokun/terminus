<?php

namespace Terminus\Commands;

use Terminus\Collections\Sites;
use Terminus\Config;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Organization;
use Terminus\Request;
use Terminus\Session;

/**
 * Actions to be taken on an individual site
 *
 * @command site
 */
class SiteCommand extends TerminusCommand
{

    protected $_headers = false;

  /**
   * Object constructor
   *
   * @param array $options Options to construct the command object
   * @return SiteCommand
   */
    public function __construct(array $options = [])
    {
        $options['require_login'] = true;
        parent::__construct($options);
        $this->sites = new Sites();
    }

  /**
   * Get, load, create, or list backup information
   *
   * ## OPTIONS
   *
   * <get|load|create|list|get-schedule|set-schedule|cancel-schedule>
   * : Function to run - get, load, create, list, get schedule, set schedule,
   *   or cancel schedule
   *
   * [--site=<site>]
   * : Site to load
   *
   * [--env=<env>]
   * : Environment to load
   *
   * [--element=<code|files|db|all>]
   * : Element to download or create. `all` is only used for 'create'
   *
   * [--to=<directory|file>]
   * : Absolute path of a directory or filename to save the downloaded backup to
   *
   * [--file=<filename>]
   * : Select one of the files from the list subcommand. Only used for 'get'
   *
   * [--latest]
   * : If set the latest backup will be selected automatically
   *
   * [--keep-for]
   * : Number of days to keep this backup
   *
   * [--day]
   * : Day of the week on which to run weekly backups
   *
   * [--username]
   * : MySQL username (Used for site backups load --element=db)
   *
   * [--password]
   * : MySQL password (Used for site backups load --element=db)
   *
   * [--database]
   * : MySQL database name (Used for site backups load --element=db)
   *
   * @subcommand backups
   *
   */
    public function backups($args, $assoc_args)
    {
        $action = array_shift($args);

        switch ($action) {
            case 'get-schedule':
                $this->showBackupSchedule($assoc_args);
                break;
            case 'set-schedule':
                $this->setBackupSchedule($assoc_args);
                break;
            case 'cancel-schedule':
                $this->cancelBackupSchedule($assoc_args);
                break;
            case 'get':
                $url = $this->getBackup($assoc_args);
                $this->output()->outputValue($url);
                break;
            case 'load':
                $this->loadBackup($assoc_args);
                break;
            case 'create':
                $workflow = $this->createBackup($assoc_args);
                $workflow->wait();
                $this->workflowOutput($workflow);
                break;
            case 'list':
            default:
                $data = $this->listBackups($assoc_args);
                $this->output()->outputRecordList(
                    $data,
                    ['file' => 'File', 'size' => 'Size', 'date' => 'Date',]
                );
                return $data;
              break;
        }
    }

  /**
   * Clear all site caches
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : site to use
   *
   * [--env=<env>]
   * : Environment to clear
   *
   * ## EXAMPLES
   *  terminus site clear-cache --site=mysite --env=live
   *
   * @subcommand clear-cache
   */
    public function clearCache($args, $assoc_args)
    {
        $site        = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $environment = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'site' => $site,])
        );
        $workflow    = $environment->clearCache();
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Overwrites the content (database and/or files) of one environment with
   * content from another environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--from-env]
   * : Environment you want to clone from
   *
   * [--to-env]
   * : Environment you want to clone to
   *
   * [--db-only]
   * : Clone only the the database
   *
   * [--files-only]
   * : Clone only the files
   *
   * @subcommand clone-content
   */
    public function cloneContent($args, $assoc_args)
    {
        $site     = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $from_env = $this->input()->env(
            [
            'args'  => $assoc_args,
            'key'   => 'from-env',
            'label' => 'Choose environment you want to clone from',
            'site'  => $site,
            ]
        );
        $to_env = $site->environments->get(
            $this->input()->env(
                [
                'args'  => $assoc_args,
                'key'   => 'to-env',
                'label' => 'Choose environment you want to clone to',
                'site'  => $site,
                ]
            )
        );

        $db    = isset($assoc_args['db-only']);
        $files = isset($assoc_args['files-only']);
        if (!$files && !$db) {
            $files = $db = true;
        }

        $append = [];
        if ($db) {
            $append[] = 'DATABASE';
        }
        if ($files) {
            $append[] = 'FILES';
        }
        $append  = implode(' and ', $append);
        $this->input()->confirm(
            [
            'message' => "Are you sure?\n\tClone from %s to %s\n\tInclude: %s\n",
            'context' => [
            strtoupper($from_env),
            strtoupper($to_env->id),
            $append,
            ],
            ]
        );

        if ($db) {
            $this->log()->info('Cloning database ... ');
            $workflow = $to_env->cloneDatabase($from_env);
            $workflow->wait();
        }

        if ($files) {
            $this->log()->info('Cloning files ... ');
            $workflow = $to_env->cloneFiles($from_env);
            $workflow->wait();
        }
        if (isset($workflow)) {
            $this->workflowOutput($workflow);
        }
        return true;
    }

  /**
   * Code related commands
   *
   * ## OPTIONS
   *
   * <log|branches|diffstat|commit>
   * : options are log, branches, diffstat, commit
   *
   * [--site=<site>]
   * : name of the site
   *
   * [--env=<env>]
   * : site environment
   *
   * [--message=<message>]
   * : message to use when committing on server changes
   */
    public function code($args, $assoc_args)
    {
        $subcommand = array_shift($args);
        $site       = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args])
        );
        $data       = $headers = [];
        switch ($subcommand) {
            case 'log':
                $env  = $site->environments->get(
                    $this->input()->env(array('args' => $assoc_args, 'site' => $site))
                );
                $logs = $env->commits->all();
                $data = [];
                foreach ($logs as $log) {
                        $data[] = [
                          'time'    => $log->get('datetime'),
                          'author'  => $log->get('author'),
                          'labels'  => implode(', ', $log->get('labels')),
                          'hash'    => $log->get('hash'),
                          'message' => trim(
                              str_replace(
                                  "\n",
                                  '',
                                  str_replace("\t", '', substr($log->get('message'), 0, 50))
                              )
                          ),
                        ];
                }
                if (!empty($data)) {
                          $this->output()->outputRecordList($data);
                }
                break;
            case 'branches':
                $data = $site->getTips();
                foreach ($data as $key => $value) {
                    $data[$key] = ['title' => $value];
                }
                if (!empty($data)) {
                    $this->output()->outputRecordList($data);
                }
                break;
            case 'commit':
                $env     = $site->environments->get(
                    $this->input()->env(['args' => $assoc_args, 'site' => $site])
                );
                $diff    = $env->diffstat();
                $count   = count((array)$diff);
                $message = "Commit changes to $count files?";
                if ($count === 0) {
                        $message = 'There are no changed files. Commit anyway?';
                        $this->input()->confirm(compact('message'));
                }
                $message  = $this->input()->string(
                    [
                    'args'    => $assoc_args,
                    'key'     => 'message',
                    'message' => 'Please enter a commit message.',
                    'default' => 'Terminus commit.'
                    ]
                );
                $workflow = $env->commitChanges($message);
                $workflow->wait();
                $this->workflowOutput($workflow);
                $data = true;
                break;
            case 'diffstat':
                $env     = $site->environments->get(
                    $this->input()->env(['args' => $assoc_args, 'site' => $site])
                );
                $diff = (array)$env->diffstat();
                if (empty($diff)) {
                        $this->log()->info('No changes on server.');
                        return true;
                }
                $data   = [];
                $filter = false;
                if (isset($assoc_args['filter'])) {
                          $filter = $assoc_args['filter'];
                }
                foreach ($diff as $file => $stats) {
                    if ($filter) {
                        $filter = preg_quote($filter, '/');
                        $regex  = '/' . $filter . '/';
                        if (!preg_match($regex, $file)) {
                            continue;
                        }
                    }
                          $data[] = array_merge(compact('file'), (array)$stats);
                }
                if (!empty($data)) {
                          $this->output()->outputRecordList($data);
                }
                break;
        }

        return $data;
    }

  /**
   * Completes a site migration in progress
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of the site for which to complete a migration
   *
   * @subcommand complete-migration
   */
    public function completeMigration($args, $assoc_args)
    {
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $workflow = $site->completeMigration();
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Retrieve connection info for a specific environment
   * e.g. git, sftp, mysql, redis
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of the site from which to fetch connection info
   *
   * [--env=<env>]
   * : Name of the specific environment from which to fetch connection info
   *
   * [--field=<field>]
   * : Name of a specfic field in the connection info to return
   *   Choices are git_command, git_host, git_port, git_url, git_username,
   *   mysql_command, mysql_database, mysql_host, mysql_password, mysql_port,
   *   mysql_url, mysql_username, redis_command, redis_password, redis_port,
   *   redis_url, sftp_command, sftp_host, sftp_password, sftp_url, and
   *   sftp_username
   *
   * @subcommand connection-info
   */
    public function connectionInfo($args, $assoc_args)
    {
        $site        = $this->sites->get(
            $this->input()->siteName(array('args' => $assoc_args))
        );
        $env_id      = $this->input()->env(array('args' => $assoc_args, 'site' => $site));
        $environment = $site->environments->get($env_id);
        $info        = $environment->connectionInfo();

        if (isset($assoc_args['field'])) {
            $field = $assoc_args['field'];
            $this->output()->outputValue($info[$field]);
        } else {
            $this->output()->outputRecord($info);
        }
    }

  /**
   * Create a MultiDev environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--to-env=<env>]
   * : Name of environment to create
   *
   * [--from-env=<env>]
   * : Environment clone content from, default = dev
   *
   * @subcommand create-env
   */
    public function createEnv($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));

        if ((boolean)$site->getFeature('multidev')) {
            if (isset($assoc_args['to-env'])) {
                $to_env_id = $assoc_args['to-env'];
            } else {
                $to_env_id = $this->input()->prompt(
                    array('message' => 'Name of new multidev environment')
                );
            }

            $from_env = $site->environments->get(
                $this->input()->env(
                    array(
                    'args' => $assoc_args,
                    'key' => 'from-env',
                    'label' => 'Environment to clone content from',
                    'site' => $site,
                    )
                )
            );

            $workflow = $site->environments->create($to_env_id, $from_env);
            $workflow->wait();
            $this->workflowOutput($workflow);
        } else {
            $this->failure(
                'This site does not have the authority to conduct this operation.'
            );
        }
    }

  /**
   * Open the Pantheon site dashboard in a browser
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : site dashboard to open
   *
   * [--env=<env>]
   * : site environment to display in the dashboard
   *
   * [--print]
   * : don't try to open the link, just print it
   *
   * @subcommand dashboard
   */
    public function dashboard($args, $assoc_args)
    {
        switch (php_uname('s')) {
            case 'Linux':
                $cmd = 'xdg-open';
                break;
            case 'Darwin':
                $cmd = 'open';
                break;
            case 'Windows NT':
                $cmd = 'start';
                break;
        }
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $this->input()->optional(array('key' => 'env', 'choices' => $assoc_args));
        if (isset($env) && ($env != null)) {
            $env = '#' . $env;
        }
        $url = sprintf(
            '%s://%s/sites/%s%s',
            Config::get('dashboard_protocol'),
            Config::get('dashboard_host'),
            $site->id,
            $env
        );
        if (isset($assoc_args['print'])
        || ($this->runner->getConfig('format') != 'normal')
        ) {
            $this->output()->outputValue($url);
        } else {
            $message = 'Do you want to open your dashboard link in a web browser?';
            $this->input()->confirm(compact('message'));
            $command = sprintf('%s %s', $cmd, $url);
            exec($command);
        }
    }

  /**
   * Delete a site from pantheon
   *
   * ## OPTIONS
   * [--site=<site>]
   * : UUID or name of the site you want to delete
   *
   * [--force]
   * : to skip the confirmations
   */
    public function delete($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));

        $this->input()->confirm(
            [
            'message' => 'Are you sure you want to delete %s?',
            'context' => $site->get('name'),
            'args'    => $assoc_args,
            ]
        );
        $this->input()->confirm(
            ['message' => 'Are you really sure?', 'args' => $assoc_args]
        );
        $this->log()->info(
            'Deleting {site} ...',
            array('site' => $site->get('name'))
        );
        $response = $site->delete();

        $this->log()->info('Deleted {site}!', array('site' => $site->get('name')));
    }

  /**
   * Delete a git branch from site remote
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--branch=<branch>]
   * : name of branch to delete
   *
   * @subcommand delete-branch
   */
    public function deleteBranch($args, $assoc_args)
    {
        $site     = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $branches = array_diff(
            (array)$site->getTips(),
            ['master', 'live', 'test',]
        );
        if (empty($branches)) {
            $this->failure(
                'The site {site} has no branches which may be deleted.',
                ['site' => $site->get('name'),]
            );
        }
        $branch   = $this->input()->menu(
            [
            'args'            => $assoc_args,
            'autoselect_solo' => false,
            'key'             => 'branch',
            'label'           => 'Select the branch to delete',
            'choices'         => $branches,
            'return_value'    => true,
            ]
        );

        $message = 'Are you sure you want to delete the "%s" branch from %s?';
        $this->input()->confirm(
            [
            'message' => $message,
            'context' => [$branch, $site->get('name'),],
            ]
        );

        $workflow = $site->deleteBranch($branch);
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Delete a multidev environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env=<env>]
   * : name of environment to delete
   *
   * [--remove-branch]
   * : delete branch corresponding to env
   *
   * @subcommand delete-env
   */
    public function deleteEnv($args, $assoc_args)
    {
        $site          = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $multidev_envs = array_diff(
            $site->environments->ids(),
            ['dev', 'test', 'live',]
        );
        if (empty($multidev_envs)) {
            $this->failure(
                '{site} does not have any multidev environments to delete.',
                ['site' => $site->get('name'),]
            );
        }
        $environment = $site->environments->get(
            $this->input()->env(
                [
                'args'    => $assoc_args,
                'label'   => 'Environment to delete',
                'choices' => $multidev_envs,
                ]
            )
        );

        $message = 'Are you sure you want to delete the "%s" environment from %s?';
        $this->input()->confirm(
            [
            'message' => $message,
            'context' => [$environment->id, $site->get('name'),],
            ]
        );

        $workflow = $environment->delete(
            ['delete_branch' => isset($assoc_args['remove-branch'])]
        );
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Deploy dev environment to test or live
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to deploy from
   *
   * [--env=<test|live>]
   * : Environment to deploy to (Test or Live)
   *
   * [--sync-content]
   * : If deploying test, copy database and files from Live
   *
   * [--cc]
   * : Clear cache after deploy?
   *
   * [--updatedb]
   * : (Drupal only) run update.php after deploy
   *
   * [--note=<note>]
   * : deploy log message
   *
   */
    public function deploy($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));
        $env  = $site->environments->get(
            $this->input()->env(
                [
                'args' => $assoc_args,
                'label' => 'Choose environment to deploy to',
                'choices' => ['test', 'live',],
                ]
            )
        );

        if (!$env || !in_array($env->id, ['test', 'live',])) {
            $this->failure('You can only deploy to the test or live environment.');
        }
        if (!$env->hasDeployableCode()) {
            $this->log()->info('There is nothing to deploy.');
            return 0;
        }

        $sync_content = (
        $env->id == 'test'
        && isset($assoc_args['sync-content'])
        );

        $annotation = $this->input()->prompt(
            [
            'args' => $assoc_args,
            'key' => 'note',
            'message' => 'Custom note for the deploy log',
            'default' => 'Deploy from Terminus 2.0',
            ]
        );

        $cc       = (integer)array_key_exists('cc', $assoc_args);
        $updatedb = (integer)array_key_exists('updatedb', $assoc_args);

        $params = [
        'updatedb' => $updatedb,
        'clear_cache' => $cc,
        'annotation' => $annotation,
        ];

        if ($sync_content) {
            $params['clone_database'] = ['from_environment' => 'live',];
            $params['clone_files'] = ['from_environment' => 'live',];
        }

        $workflow = $env->deploy($params);
        $workflow->wait();
        $this->workflowOutput($workflow, ['failure' => 'Deployment failed.',]);
    }

  /**
   * see the current version of Drush being used
   *
   * [--site=<site>]
   * : The name of your site on Pantheon
   *
   * [--env=<environment>]
   * : The Pantheon environment to check the Drush version of.
   *   Note: Leaving this blank will check the versions on all environments.
   *
   * @subcommand drush-version
   */
    public function drushVersion($args, $assoc_args)
    {
        $sites = new Sites();
        $site = $sites->get($this->input()->siteName(['args' => $assoc_args,]));
        if (isset($assoc_args['env'])) {
            $environment = $site->environments->get($assoc_args['env']);
            $this->output()->outputValue($environment->getDrushVersion());
        } else {
            $environments = $site->environments->all();
            $versions = [];
            foreach ($environments as $environment) {
                $versions[$environment->id] = $environment->getDrushVersion();
            }
            $this->output()->outputValueList($versions);
        }
    }

  /**
   * Shows environment information for a site
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : name of the site to get info on
   *
   * [--env=<env>]
   * : name of environment of <site> to get info on
   *
   * [--field=<field>]
   * : field to return
   *
   * @subcommand environment-info
   */
    public function environmentInfo($args, $assoc_args)
    {
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args])
        );
        $env = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'site' => $site,])
        );

        $data = $env->serialize();
        if (isset($assoc_args['field'])) {
            $field = $assoc_args['field'];
            if (!isset($data[$field])) {
                throw new TerminusException('There is no field {field}.', compact('field'));
            }
            $this->output()->outputValue($data[$field], $field);
        } else {
            $this->output()->outputRecord($data);
        }
    }

  /**
   * List environments for a site
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of site to check
   *
   */
    public function environments($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));
        $data = array_map(
            function ($env) {
                return $env->serialize();
            },
            $site->environments->all()
        );
        $this->output()->outputRecordList(
            $data,
            [
            'name'        => 'Name',
            'created'     => 'Created',
            'domain'      => 'Domain',
            'onserverdev' => 'OnServer Dev?',
            'locked'      => 'Locked?',
            'initialized' => 'Initialized?',
            ]
        );
        return $data;
    }

  /**
   * Hostname operations
   *
   * ## OPTIONS
   *
   * <list|add|remove|lookup|get-recommendations>
   * : Options are list, add, delete, lookup, and get-recommendations
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env=<env>]
   * : environment to use
   *
   * [--hostname=<hostname>]
   * : hostname to add
   *
   */
    public function hostnames($args, $assoc_args)
    {
        $action = array_shift($args);
        if ($action != 'lookup') {
            $site   = $this->sites->get(
                $this->input()->siteName(['args' => $assoc_args,])
            );
            $env    = $site->environments->get(
                $this->input()->env(['args' => $assoc_args, 'site' => $site,])
            );
        }

        switch ($action) {
            case 'add':
                if (!isset($assoc_args['hostname'])) {
                    $this->failure('Must specify hostname with --hostname');
                }
                $data = $env->hostnames->addHostname($assoc_args['hostname']);
                $this->log()->debug(json_encode($data));
                $this->log()->info(
                    'Added {hostname} to {site}-{env}',
                    [
                    'hostname' => $assoc_args['hostname'],
                    'site'     => $site->get('name'),
                    'env'      => $env->id,
                    ]
                );
                break;
            case 'remove':
                if (!isset($assoc_args['hostname'])) {
                    $this->failure('Must specify hostname with --hostname');
                }
                $hostname = $env->hostnames->get($assoc_args['hostname']);
                $data     = $hostname->delete();
                $this->log()->info(
                    'Deleted {hostname} from {site}-{env}',
                    [
                    'hostname' => $assoc_args['hostname'],
                    'site'     => $site->get('name'),
                    'env'      => $env->id,
                    ]
                );
                break;
            case 'lookup':
                $this->log()->warning('This operation may take a long time to run.');
                $hostname  = $this->input()->string(
                    [
                    'args'    => $assoc_args,
                    'key'     => 'hostname',
                    'message' => 'Please enter a hostname to look up.',
                    ]
                );
                $sites = $this->sites->fetch()->all();
                $data = [];
                foreach ($sites as $site_id => $site) {
                        $environments = ['dev', 'test', 'live',];
                    foreach ($environments as $env_name) {
                        $environment = $site->environments->get($env_name);
                        $hostnames   = $environment->hostnames->ids();
                        if (in_array($hostname, $hostnames)) {
                            $data = [
                              [
                                'site'        => $site->get('name'),
                                'environment' => $environment->id,
                              ],
                            ];
                            break 2;
                        }
                    }
                }
                if (empty($data)) {
                          $this->log()->info(
                              'Could not locate an environment with the hostname "{hostname}".',
                              compact('hostname')
                          );
                }
                $this->output()->outputRecordList($data);
                break;
            case 'get-recommendations':
                $env->hostnames->setHydration('recommendations');
                $hostnames = $env->hostnames->all();
                $data      = [];
                foreach ($hostnames as $hostname) {
                    $data = array_merge(
                        $data,
                        array_values((array)$hostname->get('dns_recommendations'))
                    );
                }
                $this->output()->outputRecordList($data);
                break;
            default:
            case 'list':
                $data = array_map(
                    function ($hostname) {
                        $info = $hostname->serialize();
                        return $info;
                    },
                    $env->hostnames->all()
                );
                $this->output()->outputRecordList($data);
                break;
        }
        return $data;
    }

  /**
   * Import a site onto Pantheon
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of the site to migrate import archive into
   *
   * [--url=<url>]
   * : The URL to the archive file to import onto Pantheon
   */
    public function import($args, $assoc_args)
    {
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
    }

  /**
   * Import a zip archive == see this article for more info:
   * http://helpdesk.getpantheon.com/customer/portal/articles/...
   *   ...1458058-importing-a-wordpress-site
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to import content to
   *
   * [--env=<env>]
   * : Name of the environment to import to
   *
   * [--url=<url>]
   * : URL of archive to import
   *
   * [--element=<element>]
   * : Site element to import. Options are files or db.
   *
   * @subcommand import-content
   */
    public function importContent($args, $assoc_args)
    {
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        if (isset($assoc_args['env'])) {
            $env_name = $assoc_args['env'];
        } else {
            $env_name = $this->input()->env(
                [
                'args'    => $assoc_args,
                'choices' => array_diff($site->environments->ids(), ['test', 'live',]),
                ]
            );
        }
        $env = $site->environments->get($env_name);
        $url = $this->input()->string(
            [
            'args'    => $assoc_args,
            'key'     => 'url',
            'message' => 'URL of archive to import',
            ]
        );
        if (!$url) {
            $this->log()->error('Please enter a URL.');
        }

        if (!isset($assoc_args['element'])) {
            $element_key = $this->input()->menu(
                [
                'choices' => ['db', 'files',],
                'message' => 'Which element are you importing?',
                ]
            );
            $element     = $element_options[$element_key];
        } else {
            $element = $assoc_args['element'];
        }

        switch ($element) {
            case 'db':
            case 'database':
                $workflow = $env->importDatabase($url);
                break;
            case 'files':
                $workflow = $env->importFiles($url);
                break;
        }
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Retrieve information about the site
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : name of the site to work with
   *
   * [--field=<field>]
   * : field to return
   */
    public function info($args, $assoc_args)
    {
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );

        // Fetch environment data for sftp/git connection info
        $site->environments->all();

        if (isset($assoc_args['field'])) {
            $this->output()->outputValue($site->serialize()[$assoc_args['field']]);
        } else {
            $this->output()->outputRecord($site->serialize());
        }
    }

  /**
   * Init dev to test or test to live
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env]
   * : Environment you want to initialize
   *
   * @subcommand init-env
   */
    public function initEnv($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $site->environments->get(
            $this->input()->env(
                array('args' => $assoc_args, 'choices' => array('test', 'live'))
            )
        );

        if ($env->isInitialized()) {
            $this->log()->warning(
                'The {env} environment has already been initialized',
                array('env' => $env->id)
            );
            return;
        }

        $workflow = $env->initializeBindings();
        $workflow->wait();
        $this->workflowOutput($workflow);
        return true;
    }

  /**
   * Lock an environment to prevent changes
   *
   * ## OPTIONS
   *
   * <info|add|remove>
   * : action to execute ( i.e. info, add, remove )
   *
   * [--site=<site>]
   * : site name
   *
   * [--env=<env>]
   * : site environment
   *
   * [--username=<username>]
   * : your username
   *
   * [--password=<password>]
   * : your password
   *
   */
    public function lock($args, $assoc_args)
    {
        $action = array_shift($args);
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));
        $env = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'site' => $site,])
        );
        switch ($action) {
            case 'info':
                $info = $env->lockinfo();
                $this->output()->outputRecord($info);
                break;
            case 'add':
                $this->log()->info(
                    'Creating new lock on {site}-{env}',
                    ['site' => $site->get('name'), 'env' => $env->id,]
                );
                $params = [
                  'username' => $this->input()->prompt(
                      [
                      'args' => $assoc_args,
                      'key' => 'username',
                      'message' => 'Username for the lock',
                      ]
                  ),
                  'password' => $this->input()->promptSecret(
                      [
                      'args' => $assoc_args,
                      'key' => 'password',
                      'message' => 'Password for the lock',
                      ]
                  )
                ];

                $workflow = $env->lock($params);
                $workflow->wait();
                break;
            case 'remove':
                $this->log()->info(
                    'Removing lock from {site}-{env}',
                    ['site' => $site->get('name'), 'env' => $env->id,]
                );
                $workflow = $env->unlock();
                $workflow->wait();
                break;
        }
        if (isset($workflow)) {
            $this->workflowOutput($workflow);
        }
    }

  /**
   * Looks up a site name
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of the site to look up
   *
   * @subcommand lookup
   */
    public function lookup($args, $assoc_args)
    {
        $site_name = $this->input()->string(
            [
            'args' => $assoc_args,
            'key'  => 'site',
            'required' => true,
            'message' => 'Enter the name of a site to look up',
            ]
        );
        $response = (array)$this->sites->findUuidByName($site_name);
        $this->output()->outputValueList($response);
    }

  /**
   * Merge the dev environment (i.e. master) into a multidev environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env=<env>]
   * : Name of multidev to environment to merge Dev into
   *
   * @subcommand merge-from-dev
   */
    public function mergeFromDev($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));

        $multidev_ids = array_map(
            function ($env) {
                $env_id = $env->id;
                return $env_id;
            },
            $site->environments->multidev()
        );
        $multidev_id = $this->input()->env(
            array(
            'args'    => $assoc_args,
            'label'   =>
            'Multidev environment that the dev environment will be merged into',
            'choices' => $multidev_ids
            )
        );
        $environment = $site->environments->get($multidev_id);

        $workflow = $environment->mergeFromDev();
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Merge a multidev environment into dev environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env=<env>]
   * : Name of multidev to environment to merge into Dev
   *
   * @subcommand merge-to-dev
   */
    public function mergeToDev($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));

        $multidev_ids = array_map(
            function ($env) {
                return $env->id;
            },
            $site->environments->multidev()
        );
        $params['from_environment'] = $this->input()->env(
            [
            'args' => $assoc_args,
            'label' => 'Multidev environment to merge into dev environment',
            'choices' => $multidev_ids,
            ]
        );

        $workflow = $site->environments->get('dev')->mergeToDev($params);
        $workflow->wait();

        $this->log()->info(
            'Merged the {env} environment into dev',
            ['env' => $params['from_environment'],]
        );
    }

  /**
   * List site organizations
   *
   * ## OPTIONS
   *
   * <list|add|remove>
   * : subfunction to run
   *
   * [--site=<site>]
   * : Site's name
   *
   * [--org=<name|id>]
   * : Organization to add/remove from membership, name or UUID
   *
   * [--role=<role>]
   * : Max role for organization on this site ... default "team_member"
   *
   */
    public function organizations($args, $assoc_args)
    {
        $action = array_shift($args);
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $data = [];
        switch ($action) {
            case 'add':
                $role = $this->input()->optional(
                    array(
                    'key'     => 'role',
                    'choices' => $assoc_args,
                    'default' => 'team_member'
                    )
                );
                $org  = $this->input()->orgName(['args' => $assoc_args,]);
                $workflow = $site->org_memberships->create($org, $role);
                $workflow->wait();
                break;
            case 'remove':
                $org = $this->input()->orgId(['args' => $assoc_args,]);
                $member = $site->org_memberships->get($org);
                if ($member == null) {
                    $this->failure(
                        '{org} is not a member of {site}',
                        ['org' => $org, 'site' => $site->get('name'),]
                    );
                }
                $workflow = $member->delete('organization', $org);
                $workflow->wait();
                break;
            case 'list':
            default:
                $orgs = $site->getOrganizations();
                if (empty($orgs)) {
                    $this->log()->warning('No organizations');
                }

                foreach ($orgs as $org) {
                    $data[]   = [
                    'label' => $org->get('profile')->name,
                    'name'  => $org->get('profile')->machine_name,
                    'role'  => $org->get('role'),
                    'id'    => $org->get('organization_id'),
                    ];
                }
                $this->output()->outputRecordList($data);
                break;
        }
        if (isset($workflow)) {
            $this->workflowOutput($workflow);
        }
    }

  /**
   * Mount a site with sshfs
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to deploy from
   *
   * --destination=<path>
   * : local directory to mount
   *
   * [--env=<env>]
   * : Environment (dev,test)
   *
   */
    public function mount($args, $assoc_args)
    {
        exec('which sshfs', $stdout, $exit);
        if ($exit !== 0) {
            $this->failure('Must install sshfs first');
        }

        $destination = $this->helpers->file->destinationIsValid($assoc_args['destination']);

        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $this->input()->env(array('args' => $assoc_args, 'site' => $site));

        exec('uname', $output, $ret);
        $darwin = '';
        if (is_array($output)
        && isset($output[0])
        && strpos($output[0], 'Darwin') !== false
        ) {
            $darwin = '-o defer_permissions ';
        }
        $user = $env . '.' . $site->id;
        $host = sprintf(
            'appserver.%s.%s.drush.in',
            $env,
            $site->id
        );
        $cmd  = sprintf(
            'sshfs %s -p 2222 %s@%s:./ %s',
            $darwin,
            $user,
            $host,
            $destination
        );
        exec($cmd, $stdout, $exit);
        if ($exit != 0) {
            $this->failure("Couldn't mount $destination");
        }
        $message  = 'Site mounted to {destination}.';
        $message .= ' To unmount, run: umount {destination}';
        $message .= ' (or fusermount -u {destination}).';
        $this->log()->info($message, compact('destination'));
    }

  /**
   * Interacts with New Relic
   *
   * ## OPTIONS
   *
   * <enable|disable|status>
   * : Options are enable, disable and status.
   *
   * [--site=<site>]
   * : site name
   *
   * ## Examples
   *
   *    terminus site new-relic enable --site=behat-tests
   *    terminus site new-relic disable --site=behat-tests
   *    terminus site new-relic status --site=behat-tests
   *
   * @subcommand new-relic
   */
    public function newRelic($args, $assoc_args)
    {
        $action = array_shift($args);
        $site   = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));
        switch ($action) {
            case 'enable':
                $newrelic = $site->enableNewRelic($site);
                if ($newrelic) {
                    $this->log()->info('New Relic enabled.');
                }
                break;
            case 'disable':
                $newrelic = $site->disableNewRelic($site);
                if ($newrelic) {
                    $this->log()->info('New Relic disabled.');
                }
                break;
            case 'status':
                $newrelic = $site->newRelic();
                if ($newrelic) {
                    $data = [
                    'name' => $newrelic->name,
                    'status' => $newrelic->status,
                    'subscribed' => $newrelic->subscription->starts_on,
                    'state' => $newrelic->{'primary admin'}->state,
                    ];
                    $this->output()->outputRecord($data);
                } else {
                    $this->log()->info('New Relic disabled.');
                }
                break;
        }
    }

  /**
   * Get the site owner
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to check
   *
   * @subcommand owner
   */
    public function owner($args, $assoc_args)
    {
        $site = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args])
        );
        $this->output()->outputValue($site->get('owner'));
    }

  /**
   * Interacts with redis
   *
   * ## OPTIONS
   *
   * <enable|disable|clear>
   * : Options are enable, disable, and clear.
   *
   * [--site=<site>]
   * : Name of the site to use Redis on
   *
   * [--env=<env>]
   * : Name of the environment to clear Redis on (Only works for clear)
   *
   * ## Examples
   *
   *    terminus site redis clear --site=behat-tests --env=live
   *    terminus site redis enable --site=behat-tests
   *    terminus site redis disable --site=behat-tests
   */
    public function redis($args, $assoc_args)
    {
        $action = array_shift($args);
        $site   = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));
        switch ($action) {
            case 'enable':
                $redis = $site->enableRedis();
                if ($redis) {
                    $this->log()->info('Redis enabled. Converging bindings...');
                }
                break;
            case 'disable':
                $redis       = $site->disableRedis();
                if ($redis) {
                    $this->log()->info('Redis disabled. Converging bindings...');
                }
                break;
            case 'clear':
                if (isset($assoc_args['env'])) {
                    $environments = [$site->environments->get($assoc_args['env'])];
                } else {
                    $environments = $site->environments->all();
                }
                $commands = array();
                foreach ($environments as $environment) {
                    $connection_info = $environment->connectionInfo();
                    if (!isset($connection_info['redis_host'])) {
                        $this->failure('Redis cache is not enabled.');
                    }
                    $args = [
                    $environment->id,
                    $site->id,
                    $environment->id,
                    $site->id,
                    $connection_info['redis_host'],
                    $connection_info['redis_port'],
                    $connection_info['redis_password'],
                    ];
                    array_filter(
                        $args,
                        function ($a) {
                            $escaped_arg = escapeshellarg($a);
                            return $escaped_arg;
                        }
                    );
                    $command  = 'ssh -p 2222 %s.%s@appserver.%s.%s.drush.in';
                    $command .= ' "redis-cli -h %s -p %s -a %s flushall" 2> /dev/null';
                    $commands[$environment->id] = vsprintf($command, $args);
                }
                foreach ($commands as $env => $command) {
                    $this->log()->info('Clearing Redis on {env}.', compact('env'));
                    exec($command, $stdout, $return);
                    if (!empty($stdout)) {
                        $this->log()->info($stdout[0]);
                    }
                }
                break;
        }
    }

  /**
   * Change connection mode between SFTP and Git
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : name of the site
   *
   * [--env=<env>]
   * : site environment
   *
   * [--mode=<value>]
   * : set connection to sftp or git
   *
   * @subcommand set-connection-mode
   */
    public function setConnectionMode($args, $assoc_args)
    {
        if (!isset($assoc_args['mode'])
        || !in_array($assoc_args['mode'], ['sftp', 'git',])
        ) {
            $this->failure('You must specify the mode as either sftp or git.');
        }
        $mode = strtolower($assoc_args['mode']);
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));
        // Only present dev and multidev environments; Test/Live cannot be modified
        $environments = array_diff(
            $site->environments->ids(),
            ['test', 'live',]
        );

        $env = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'choices' => $environments,])
        );
        if (in_array($env->id, ['test', 'live',])) {
            $this->failure('Connection mode cannot be set in test or live environments');
        }
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->info($workflow);
        } else {
            $workflow->wait();
            $this->workflowOutput($workflow);
        }
        return true;
    }

  /**
   * Add/replace an HTTPS certificate for an environment
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Name of the site to apply the HTTPS certificate to
   *
   * [--env=<env>]
   * : Environment of the site to apply the HTTPS certificate to
   *
   * [--certificate=<value>]
   * : Certificate
   *
   * [--private-key=<value>]
   * : RSA private key
   *
   * [--intermediate-certificate=<value>]
   * : (optional) CA intermediate certificate(s)
   *
   * @subcommand set-https-certificate
   */
    public function setHttpsCertificate($args, $assoc_args)
    {
        $site        = $this->sites->get(
            $this->input()->sitename(['args' => $assoc_args,])
        );
        $environment = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'site' => $site,])
        );

        $https = [
        'cert' => $this->input()->optional(
            ['choices' => $assoc_args, 'key' => 'certificate',]
        ),
        'key' => $this->input()->optional(
            ['choices' => $assoc_args, 'key' => 'private-key',]
        ),
        'intermediary' => $this->input()->optional(
            ['choices' => $assoc_args, 'key' => 'intermediate-certificate',]
        ),
        ];
        if (is_null($https['cert'])
        && is_null($https['intermediary'])
        && ($this->log()->getOptions('logFormat') == 'normal')
        ) {
            $message  = 'No certificate was provided.';
            $message .= ' Please provide a CA intemediate certificate.';
            $https['intermediary'] = $this->input()->string(
                ['message' => $message, 'required' => true,]
            );
        }

        $workflow = $environment->setHttpsCertificate($https);
        $this->log()->info('SSL certificate updated. Converging loadbalancer.');
        $workflow->wait();
        $this->workflowOutput($workflow);
        return true;
    }

  /**
   * Change the site payment instrument
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--instrument=<UUID>]
   * : Change the instrument by setting the ID
   *
   * @subcommand set-instrument
   *
   * ## EXAMPLES
   *
   *  terminus site set-instrument --site=sitename
   */
    public function setInstrument($args, $assoc_args)
    {
        $user        = Session::getUser();
        $instruments = $user->instruments->getMemberList('id', 'label');
        if (!isset($assoc_args['instrument'])) {
            $instrument_id = $this->input()->menu(
                array(
                'choices' => $instruments,
                'message' => 'Select a payment instrument',
                )
            );
        } else {
            $instrument_id = $assoc_args['instrument'];
        }

        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        if ($instrument_id == 0) {
            $workflow = $site->removeInstrument();
        } else {
            $workflow = $site->addInstrument($instrument_id);
        }
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Set the site owner
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to check
   *
   * [--member=<email>]
   * : The email of the user to set as the new owner
   *
   * @subcommand set-owner
   */
    public function setOwner($args, $assoc_args)
    {
        $site             = $this->sites->get(
            $this->input()->siteName(array('args' => $assoc_args))
        );
        $user_memberships = $site->user_memberships->all();
        if (count($user_memberships) <= 1) {
            throw new TerminusException(
                'The new owner must be added with "{cmd}" before promoting.',
                ['cmd' => 'terminus site team add-member'],
                1
            );
        }
        foreach ($user_memberships as $uuid => $user_membership) {
            $user      = $user_membership->get('user');
            $choices[$user->email] = sprintf(
                '%s %s <%s>',
                $user->profile->firstname,
                $user->profile->lastname,
                $user->email
            );
        }
        $member      = $this->input()->menu(
            [
            'args'            => $assoc_args,
            'autoselect_solo' => false,
            'choices'         => $choices,
            'key'             => 'member',
            'message'         => 'Enter a tag to add',
            ]
        );
        $workflow = $site->setOwner($member);
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Set the site's service level
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to set the service level on
   *
   * [--level=<value>]
   * : New service level to set. Options are free, basic, pro, and business.
   *
   * @subcommand set-service-level
   */
    public function setServiceLevel($args, $assoc_args)
    {
        $site     = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args])
        );
        $level    = $this->input()->serviceLevel(['args' => $assoc_args]);
        $workflow = $site->updateServiceLevel($level);
        $workflow->wait();
        $this->workflowOutput($workflow);
    }

  /**
   * Enable or disable Solr indexing
   *
   * ## OPTIONS
   *
   * <enable|disable>
   * : Options are enable and disable
   *
   * [--site=<site>]
   * : Sitei on which to change Solr
   *
   * @subcommand solr
   */
    public function solr($args, $assoc_args)
    {
        $action = array_shift($args);
        $site   = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));
        switch ($action) {
            case 'enable':
                $solr = $site->enableSolr();
                if ($solr) {
                    $this->log()->info('Solr enabled. Converging bindings...');
                }
                break;
            case 'disable':
                $solr = $site->disableSolr();
                if ($solr) {
                    $this->log()->info('Solr disabled. Converging bindings...');
                }
                break;
        }
    }

  /**
   * Manage site organization tags
   *
   * ## OPTIONS
   *
   * <add|remove|list>
   * : subfunction to run
   *
   * [--site=<site>]
   * : Site's name
   *
   * [--org=<name|id>]
   * : Organization to apply tag with
   *
   * [--tag=<tag>]
   * : Tag to add or remove
   *
   * @subcommand tags
   */
    public function tags($args, $assoc_args)
    {
        $action = array_shift($args);
        $org = new Organization(
            (object)['id' => $this->input()->orgId(['args' => $assoc_args,]),]
        );
        $org_sites = $org->getSites();
        $org_site_list = array_combine(
            array_map(
                function ($site) {
                    return $site->id;
                },
                $org_sites
            ),
            array_map(
                function ($site) {
                    $site_name = $site->get('name');
                    return $site_name;
                },
                $org_sites
            )
        );
        $site = $this->sites->get(
            $this->input()->siteName(
                ['args' => $assoc_args, 'choices' => $org_site_list,]
            )
        );

        switch ($action) {
            case 'add':
                $tag      = $this->input()->string(
                    [
                    'args'    => $assoc_args,
                    'key'     => 'tag',
                    'message' => 'Enter a tag to add',
                    ]
                );
                $response = $site->addTag($tag, $org);

                $context = array(
                  'tag'  => $tag,
                  'site' => $site->get('name')
                );
                if ($response['status_code'] == 200) {
                        $this->log()->info(
                            'Tag "{tag}" has been added to {site}',
                            $context
                        );
                } else {
                          $this->failure(
                              'Tag "{tag}" could not be added to {site}',
                              $context
                          );
                }
                break;
            case 'remove':
                $tags = $site->getTags($org);
                if (count($tags) === 0) {
                    $message  = 'This organization does not have any tags associated';
                    $message .= ' with this site.';
                    $this->failure($message);
                } elseif (!isset($assoc_args['tag'])
                || !in_array($assoc_args['tag'], $tags)
                ) {
                    $tag = $tags[$this->input()->menu(
                        ['choices' => $tags, 'message' => 'Select a tag to delete',]
                    )];
                } else {
                    $tag = $assoc_args['tag'];
                }
                $response = $site->removeTag($tag, $org);

                $context = [
                'tag'  => $tag,
                'site' => $site->get('name'),
                ];
                if ($response['status_code'] == 200) {
                    $this->log()->info(
                        'Tag "{tag}" has been removed from {site}',
                        $context
                    );
                } else {
                    $this->failure(
                        'Tag "{tag}" could not be removed from {site}',
                        $context
                    );
                }
                break;
            case 'list':
            default:
                $tags = $site->getTags($org);
                $this->output()->outputRecord(compact('tags'));
                break;
        }
    }

  /**
   * Get or set team members
   *
   * ## OPTIONS
   *
   * <list|add-member|remove-member|change-role>
   * : Options are list, add-member, remove-member, and change-role.
   *
   * [--site=<site>]
   * : Site to check
   *
   * [--member=<email>]
   * : Email of the member to add. Member will receive an invite
   *
   * [--role=<role>]
   * : Role to designate the member as. Options are developer, team_member,
   *   and admin.
   *
   * @subcommand team
   */
    public function team($args, $assoc_args)
    {
        $action = 'list';
        if (!empty($args)) {
            $action = array_shift($args);
        }
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $data = array();
        $team = $site->user_memberships;
        switch ($action) {
            case 'add-member':
                if ((boolean)$site->getFeature('change_management')) {
                    $role = $this->input()->siteRole(['args' => $assoc_args]);
                } else {
                    $role = 'team_member';
                }
                $workflow = $team->create($assoc_args['member'], $role);
                $this->workflowOutput($workflow);
                break;
            case 'remove-member':
                $user = $team->get($assoc_args['member']);
                if ($user != null) {
                    $workflow = $user->delete($assoc_args['member']);
                    $this->workflowOutput($workflow);
                } else {
                    $this->failure(
                        '"{member}" is not a valid member.',
                        array('member' => $assoc_args['member'])
                    );
                }
                break;
            case 'change-role':
                if ((boolean)$site->getFeature('change_management')) {
                    $role = $this->input()->siteRole(array('args' => $assoc_args));
                    $user = $team->get($assoc_args['member']);
                    if ($user != null) {
                        $workflow = $user->setRole($role);
                        $this->workflowOutput($workflow);
                    } else {
                        $this->failure(
                            '"{member}" is not a valid member.',
                            array('member' => $assoc_args['member'])
                        );
                    }
                } else {
                    $this->failure(
                        'This site does not have its change-management option enabled.'
                    );
                }
                break;
            case 'list':
            default:
                $user_memberships = $team->all();
                foreach ($user_memberships as $uuid => $user_membership) {
                    $user   = $user_membership->get('user');
                    $data[] = array(
                    'First' => $user->profile->firstname,
                    'Last'  => $user->profile->lastname,
                    'Email' => $user->email,
                    'Role'  => $user_membership->get('role'),
                    'UUID'  => $user->id,
                    );
                }
                ksort($data);
                break;
        }
        if (!empty($data)) {
            $this->output()->outputRecordList($data);
        }
    }

  /**
   * Show upstream updates
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to check
   *
   * @subcommand upstream-info
   */
    public function upstreamInfo($args, $assoc_args)
    {
        $upstream = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        )->upstream;
        $this->output()->outputRecord($upstream->serialize());
    }

  /**
   * Show or apply upstream updates
   *
   * ## OPTIONS
   *
   * [<list|apply>]
   * : Are we inspecting or applying upstreams?
   *
   * [--site=<site>]
   * : Site to check
   *
   * [--env=<name>]
   * : Environment (dev or multidev) to apply updates to; Default: dev
   *
   * [--accept-upstream]
   * : Attempt to automatically resolve conflicts in favor of the upstream
   *   repository.
   *
   * [--updatedb]
   * : (Drupal only) run update.php after updating,
   *
   * @subcommand upstream-updates
   */
    public function upstreamUpdates($args, $assoc_args)
    {
        $action = 'list';
        if (!empty($args)) {
            $action = array_shift($args);
        }
        $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args,]));
        $upstream_updates = $site->upstream->getUpdates();
        if (isset($upstream_updates->remote_url) && isset($upstream_updates->behind)) {
            if (!isset($upstream_updates) || empty((array)$upstream_updates->update_log)) {
                $this->log()->info("No updates to $action.");
                exit(0);
            }
        } else {
            $this->failure('There was a problem checking your upstream status. Please try again.');
        }

        switch ($action) {
            default:
            case 'list':
                $data = array_map(
                    function ($commit) {
                        $info = [
                        'hash'     => $commit->hash,
                        'datetime' => $commit->datetime,
                        'message'  => $commit->message,
                        'author'   => $commit->author,
                        ];
                        return $info;
                    },
                    (array)$upstream_updates->update_log
                );
                $this->output()->outputRecordList($data);
                break;
            case 'apply':
                if (!empty($upstream_updates->update_log)) {
                    $env = $site->environments->get(
                        $this->input()->env(['args' => $assoc_args, 'site' => $site,])
                    );
                    if (in_array($env->id, ['test', 'live',])) {
                              $this->failure(
                                  'Upstream updates cannot be applied to the {env} environment',
                                  ['env' => $env->id,]
                              );
                    }

                    $updatedb = (isset($assoc_args['updatedb']) && $assoc_args['updatedb']);
                    $acceptupstream = (
                    isset($assoc_args['accept-upstream'])
                    && $assoc_args['accept-upstream']
                    );
                    $message = 'Are you sure you want to apply the upstream updates to %s-dev';
                    $this->input()->confirm(
                        [
                        'message' => $message,
                        'context' => array($site->get('name'), $env->id),
                        ]
                    );
                    $workflow = $env->applyUpstreamUpdates($updatedb, $acceptupstream);
                    $workflow->wait();
                    $this->workflowOutput($workflow);
                } else {
                    $this->log()->warning('There are no upstream updates to apply.');
                }
                break;
        }
    }

  /**
   * Pings a site to ensure it responds
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : site to ping
   *
   * [--env=<env>]
   * : environment to ping
   *
   * ## Examples
   *  terminus site wake --site='testsite' --env=dev
  */
    public function wake($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $this->input()->env(array('args' => $assoc_args, 'site' => $site));
        $data = $site->environments->get($env)->wake();
        if (!$data['success']) {
            $this->failure(
                'Could not reach {target}',
                $data
            );
        }
        if (!$data['styx']) {
            $this->failure('Pantheon headers missing, which is not quite right.');
        }
        $context = array('target' => $data['target'], 'time' => $data['time']);
        $this->log()->info(
            'OK >> {target} responded in {time}',
            $context
        );
    }

  /**
   * Complete wipe and reset a site
   *
   * ## OPTIONS
   *
   * [--site=<site>]
   * : Site to use
   *
   * [--env=<env>]
   * : Environment to be wiped
   */
    public function wipe($args, $assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $site->environments->get(
            $this->input()->env(array('args' => $assoc_args, 'site' => $site))
        );

        $this->input()->confirm(
            array(
            'message' => 'Are you sure you want to wipe %s-%s?',
            'context' => array($site->get('name'), $env->id),
            )
        );

        $workflow = $env->wipe();
        $workflow->wait();
        $this->log()->info(
            'Successfully wiped {site}-{env}',
            array(
            'site' => $site->get('name'),
            'env' => $env->id
            )
        );
    }

  /**
   * Cancels an environment's regular backup schedule
   *
   * @param array $assoc_args Parameters and flags from the command line
   * @return void
   */
    private function cancelBackupSchedule(array $assoc_args)
    {
        $site     = $this->sites->get(
            $this->input()->siteName(array('args' => $assoc_args))
        );
        $env      = $site->environments->get(
            $this->input()->env(
                array('args' => $assoc_args, 'choices' => array('dev', 'live'))
            )
        );
        $success = $env->backups->cancelBackupSchedule();
        $this->log()->info('Cancelled backup schedule.');
    }

  /**
   * Creates a backup
   *
   * @param array $assoc_args Parameters and flags from the command line
   * @return Workflow
   */
    private function createBackup($assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $site->environments->get(
            $this->input()->env(array('args' => $assoc_args, 'site' => $site))
        );
        $args = $assoc_args;
        unset($args['site']);
        unset($args['env']);
        $args['element'] = $this->input()->backupElement(
            array(
            'args'    => $args,
            'choices' => array('all', 'code', 'database', 'files'),
            )
        );
        $workflow        = $env->backups->create($args);
        return $workflow;
    }

  /**
   * Retrieves a single backup or downloads it as requested
   *
   * @param array $assoc_args Parameters and flags from the command line
   * @return string
   */
    private function getBackup($assoc_args)
    {
        $site = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env  = $site->environments->get(
            $this->input()->env(array('args' => $assoc_args, 'site' => $site))
        );
        $file = $this->input()->optional(
            array(
            'key'     => 'file',
            'choices' => $assoc_args,
            'default' => false
            )
        );
        if ($file) {
            $backup  = $env->backups->getBackupByFileName($file);
            $element = $backup->getElement();
        } else {
            $element = $this->input()->backupElement(array('args' => $assoc_args));
            $latest  = (boolean)$this->input()->optional(
                array(
                'key'     => 'latest',
                'choices' => $assoc_args,
                'default' => false
                )
            );
            $backups = $env->backups->getFinishedBackups($element);

            if (empty($backups)) {
                $message  = 'No backups available. Please create one with ';
                $message .= '`terminus site backups create --site={site} --env={env}`';
                throw new TerminusException(
                    $message,
                    [
                        'site' => $site->get('name'),
                        'env'  => $env->id
                    ],
                    1
                );
            }

            if ($latest) {
                $backup = array_shift($backups);
            } else {
                $context = array(
                'site' => $site->get('name'),
                'env' => $env->id
                );
                $backup  = $this->input()->backup(
                    array('backups' => $backups, 'context' => $context)
                );
            }
        }

        $url = $backup->getUrl();

        if (isset($assoc_args['to'])) {
            $target = str_replace('~', $_SERVER['HOME'], $assoc_args['to']);
            if (is_dir($target)) {
                $filename = $this->helpers->file->getFilenameFromUrl($url);
                $target   = sprintf('%s/%s', $target, $filename);
            }
            $this->log()->info('Downloading ... please wait ...');
            if (Request::download($url, $target)) {
                $this->log()->info('Downloaded {target}', compact('target'));
                return $target;
            } else {
                $this->failure('Could not download file');
            }
        }
        return $url;
    }

  /**
   * Lists available backups
   *
   * @params array $assoc_args Parameters and flags from the command line
   * @return array $data Elements as follows:
   *         [string] file The backup's file name
   *         [string] size The backup file's size
   *         [string] date The datetime of the backup's creation
   */
    private function listBackups($assoc_args)
    {
        $site    = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args,])
        );
        $env     = $site->environments->get(
            $this->input()->env(['args' => $assoc_args, 'site' => $site,])
        );
        $element = null;
        if (isset($assoc_args['element']) && ($assoc_args['element'] != 'all')) {
            $element = $this->input()->backupElement(['args' => $assoc_args,]);
        }
        $backups = $env->backups->getFinishedBackups($element);
        $latest  = (boolean)$this->input()->optional(
            [
            'choices' => $assoc_args,
            'default' => false,
            'key'     => 'latest',
            ]
        );
        $data = [];
        if (empty($backups)) {
            $this->log()->warning('No backups found.');
        } else {
            if ($latest) {
                array_splice($backups, 1);
            }
            foreach ($backups as $id => $backup) {
                $data[] = [
                'file'      => $backup->get('filename'),
                'size'      => $backup->getSizeInMb(),
                'date'      => $backup->getDate(),
                'initiator' => $backup->getInitiator(),
                ];
            }
        }
        return $data;
    }

  /**
   * Loads a single backup
   *
   * @params array $assoc_args Parameters and flags from the command line
   * @return bool Always true, else the function has thrown an exception
   */
    private function loadBackup($assoc_args)
    {
        $assoc_args['to']      = '/tmp';
        $assoc_args['element'] = 'database';
        if (isset($assoc_args['database'])) {
            $database = $assoc_args['database'];
        } else {
            $database = escapeshellarg(
                $this->input()->prompt(array('message' =>'Name of database to import to'))
            );
        }
        if (isset($assoc_args['username'])) {
            $username = $assoc_args['username'];
        } else {
            $username = escapeshellarg($this->input()->prompt(array('message' =>'Username')));
        }
        if (isset($assoc_args['password'])) {
            $password = $assoc_args['password'];
        } else {
            $password = $this->input()->promptSecret(
                array('message' => 'Your MySQL password (input will not be shown)')
            );
        }

        exec('mysql --version', $stdout, $exit);
        if ($exit != 0) {
            $this->failure(
                'MySQL does not appear to be installed on your server.'
            );
        }

        $target = $this->getBackup($assoc_args);
        $target = '/tmp/' . $this->helpers->file->getFilenameFromUrl($target);

        if (!file_exists($target)) {
            $this->failure(
                'Cannot read database file {target}',
                compact('target')
            );
        }

        $this->log()->info('Unziping database');
        exec("gunzip $target", $stdout, $exit);

        // trim the gz of the target
        $target = $this->helpers->file->sqlFromZip($target);
        $target = escapeshellarg($target);
        exec(
            sprintf(
                'mysql %s -u %s -p"%s" < %s',
                $database,
                $username,
                $password,
                $target
            ),
            $stdout,
            $exit
        );
        if ($exit != 0) {
            $this->failure('Could not import database');
        }

        $this->log()->info(
            '{target} successfully imported to {db}',
            array('target' => $target, 'db' => $database)
        );
        return true;
    }

  /**
   * Sets an environment's regular backup schedule
   *
   * @param array $assoc_args Parameters and flags from the command line
   * @return void
   */
    private function setBackupSchedule($assoc_args)
    {
        $site     = $this->sites->get(
            $this->input()->siteName(array('args' => $assoc_args))
        );
        $env      = $site->environments->get(
            $this->input()->env(
                array('args' => $assoc_args, 'choices' => array('dev', 'live'))
            )
        );
        $day      = $this->input()->day(array('args' => $assoc_args));
        $schedule = $env->backups->setBackupSchedule($day);
        $this->log()->info('Backup schedule successfully set.');
    }

  /**
   * Displays an environment's regular backup schedule
   *
   * @params array $assoc_args Parameters and flags from the command line
   * @return void
   */
    private function showBackupSchedule($assoc_args)
    {
        $site     = $this->sites->get($this->input()->siteName(array('args' => $assoc_args)));
        $env      = $site->environments->get(
            $this->input()->env(
                array('args' => $assoc_args, 'choices' => array('dev', 'live'))
            )
        );
        $schedule = $env->backups->getBackupSchedule();
        if (is_null($schedule['daily_backup_hour'])) {
            $this->log()->info('Backups are not currently scheduled to be run.');
        } else {
            $this->output()->outputRecord($schedule);
        }
    }
}
