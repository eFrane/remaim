<?php

// WRITE TESTS!!!!!

namespace Remaim;

use Redmine\Client;
use Redmine\Api\Issue;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;
$phabricator_users = [];

// There is a composer package for libphutil, but it's unofficial and not 100% compatible:
// https://packagist.org/packages/mtrunkat/libphutil

require_once '/usr/share/libphutil/src/__phutil_library_init__.php';
require_once 'vendor/autoload.php';

/**
 * redmine:
    host:
    user:
    password:
    phabricator:
    host:
    token:
 */

try {
    $yaml = new Parser();
    $config = $yaml->parse(file_get_contents('remaim.yml'));
} catch (ParseException $e) {
    printf("Unable to parse the YAML string: %s", $e->getMessage());
}

function representProject($project)
{
    if (isset($project['parent'])) {
        return sprintf("\t%s", representProject($project));
    } else {
        return sprintf("[%d]\t[%s]\n", $project['id'], $project['name']);
    }
}

function addToProject($container, $project)
{
    return array_map(function ($slot, $key) use ($project) {
        if ($key == $project['parent']['id']) {
            $slot[] = $project;
        } else {
            //return addToProject($container, ...)  should have finished this …
        }
        return $slot;
    }, $container, array_keys($container));
}

/**
 * Caching user lookup.
 * Checks local cache for known users and queries conduit for any
 * not yet known users.
 * 
 * @param  ConduitClient $conduit   Conduit client instance
 * @param  array         $fullnames Array of full names to look up
 * 
 * @return array         PHIDs for users in $fullnames
 */
function getPhabricatorUserPhid($conduit, array $fullnames)
{
    global $phabricator_users;
    $unknown_users = array_diff($fullnames, array_keys($phabricator_users));

    if (!empty($unknown_users)) {
        $api_parameters = [
            'realnames' => $unknown_users,
        ];
        $result = $conduit->callMethodSynchronous('user.query', $api_parameters);
        $queried_users = array_reduce($result, function ($carry, $user) {
            $carry[$user['realName']] = $user['phid'];
            return $carry;
        });

        if (!empty($queried_users)) {
            $phabricator_users = array_merge($phabricator_users, $queried_users);
        }
    }

    return array_values(
        array_intersect_key($phabricator_users, array_flip($fullnames))
    );
}

function watchersToSubscribers($conduit, $redmine_watchers)
{
    $watchers = [];
    foreach ($redmine_watchers as $watcher) {
        if (!isset($watcher['name']) || empty($watcher['name'])) {
            continue;
        }
        $watchers[] = $watcher['name'];
    }      

    return getPhabricatorUserPhid($conduit, $watchers);  
}

function comment_tansaction($conduit, array $journal) {

    $comment = sprintf(
        "%s originally wrote:\n> %s",
        $journal['user']['name'],
        $journal['notes']
    );

    $transactions[] = [
                'type' => 'comment',
                'value' => $comment,
    ];
    return $transactions;
}

// OK, the API treats APIKeys as usernames,
// Client::prepareRequest() looks at isset(Password) and replaces it by a random string in the opposite case
// $redmine = new Client('https://redmine.1024.lu', '4ff32c96a52dfe3c850b4cd22be33cfcce02cb54');
// It always sets CURLOPT_USERPWD though…
// Well, this is kind of a a limiation of our redmine setup too because of basic Auth based on ldap!
//
// Read URL, token/password from config file?
$redmine = new Client(
    $config['redmine']['host'],
    $config['redmine']['user'],
    $config['redmine']['password']
);

$conduit = new \ConduitClient($config['phabricator']['host']);
$conduit->setConduitToken($config['phabricator']['token']);

// DR: can we find another, simpler method for checking connection than this?
// Unfortunately, the Client does not have a way of checking whether the connection was successfull,
// since it never established a connection.
$project_listing = $redmine->project->listing();
if (empty($project_listing)) {
    die("\n" . 'Your project list is empty or we were unable to connect to redmine. Check your credentials!' . "\n");
}

// Which project should get permissions on the newly created projects and tickets?
// 

// First list available projects, then allow the user to select one
$reply = $redmine->project->all(['limit' => 1024]);
printf('%d total projects retrieved from your redmine instance.', $reply['total_count'][0]);
$projects = $reply['projects'];

$projects = array_reduce($projects, function ($container, $project) {
    if (isset($project['parent'])) {
        $container = addToProject($container, $project);
    } else {
        $container[$project['id']] = $project;
    }
    return $container;
}, []);

// use ($sortkey) from $argv to allow to sort by name or by id?
usort($projects, function ($a, $b) {
    return $a['id'] > $b['id'];
});
foreach ($projects as $project) {
    print(representProject($project));
}
print('Select a project: [0] ' . "\n> ");
$fp = fopen('php://stdin', 'r');
$project = trim(fgets($fp, 1024));
fclose($fp);

$tasks = $redmine->issue->all([
    'project_id' => $project,
    'limit' => 1024
]);

$project_detail = $redmine->project->show($project);


if (!$tasks || empty($tasks['issues'])) {
    printf('No tasks found on project %s', $project. "\n"); 
    // exit;
}
$issues = $tasks['issues'];


print("Please enter the id/slug of the project in phabricator.\n Press [Enter] to see a list of available projects or\n enter [0] to create a new project from the Redmine project's details\n> ");
$fp = fopen('php://stdin', 'r');
$phab_project = trim(fgets($fp, 1024));
fclose($fp);

if ('0' === $phab_project) {


    $detail = $redmine->project->show($project);
    $memberships = $redmine->membership->all($project);
    $members = array_filter(
        array_map(function ($relation) {
            return isset($relation['user']) ? $relation['user']['name'] : null;
        }, $memberships['memberships']),
        function ($member) {
            return $member != null;
        }
    );

    $phab_members = getPhabricatorUserPhid($conduit, $members);
    
    $api_parameters = [
        'name' => $detail['project']['name'],
        'members' => $phab_members,
        'viewPolicy' => '',
    ];
    $found = $conduit->callMethodSynchronous('project.create', $api_parameters);
    
    // TO BE FINISHED
    // printf('OK, created project "%s" with phid %s')

} elseif ('' === $phab_project) {
    // TO BE FINISHED
    
} else { 

     if (is_numeric($phab_project)) {
        $api_parameters = [
            'ids' => [$phab_project],
        ];
        $result = $conduit->callMethodSynchronous('project.query', $api_parameters);
        $found = array_pop($result['data']);
        if (isset($found['phid'])) {
            printf(
                'OK, found project named "%s" with PHID %s' . "\n",
                $found['name'],
                $found['phid']
            );
        }
    } else {
        $api_parameters = [
             'slugs' => [$phab_project],
        ];
        $result = $conduit->callMethodSynchronous('project.query', $api_parameters);
        $found = array_pop($result['data']);
        if (isset($found['phid'])) {
            printf(
                'OK, found project named "%s" with PHID %s' . "\n",
                $found['name'],
                $found['phid']
            );
        }
    }
}

printf(
    'Redmine project named "%s" with ID %s' . "\n",
    $project_detail['project']['name'],
    $project
);
printf(
    'Target phabricator project named "%s" with ID %s' . "\n",        
    $found['name'],
    $found['id']
);

printf(
    '%d tickets to be migrated! OK to continue? [y/N]' . "\n> ",
    sizeof($issues)
);
$fp = fopen('php://stdin', 'r');
$checking = trim(fgets($fp, 1024));
fclose($fp);

if (!($checking == 'y' || $checking == 'Y')) {
    die('bye'. "\n");
}

/** Here is what i am going to do:
 * redmine project: numm, id
 * target phabricator project: numm, ev. id
 * X tickets to be migrated (sizeof($issues))
 * OK to continuie?

if no then die('bye'); */
// exit;



// $project_issuerelation = $redmine->issuerelation->show($relation);
// var_dump($project_issuerelation); 

// $project_issuestatus = $redmine->issue_status->all([
//     'project_id' => $project,
//     'limit' => 1024
// ]);
// var_dump($project_issuestatus);

// Well, this will probably have to go into the yml file?

$priority_map = [
    'Immediate' => 100, // unbreak now!
    'Urgent' => 100,    // unbreak now!
    'High' => 80,       // High
    'Normal' => 50,     // Normal
    'Low' => 25         // Low
     // Wishlist
];

/**
 * Once we have a list of all issues on the selected project from redmine,
 * we will loop through them using array_map and add each issue to the
 * new project on phabricator
 */
$phab_statuses = $conduit->callMethodSynchronous('maniphest.querystatuses', []);
$status_map = $phab_statuses['statusMap'];
$results = array_map(function ($issue) use ($conduit, $redmine, $found, $priority_map, $config, $status_map) {
    $details = $redmine->issue->show(
        $issue['id'],
        [
            'include' => [
                'children',
                'attachments',
                'relations',
                'watchers',
                'journals',
            ]
        ]
    );

    $api_parameters = [
        'realnames' => [$details['issue']['author']['name']],
    ];
    $result = $conduit->callMethodSynchronous('user.query', $api_parameters);
    $owner = array_pop($result);


    $description = str_replace("\r", '', $details['issue']['description']);
    $api_parameters = [
        'fullText' => $description,
    ];
    $tickets = $conduit->callMethodSynchronous('maniphest.query', $api_parameters);
    // var_dump($ticket);exit;

    if (!empty($tickets) && sizeof($tickets) === 1) {
        $ticket = array_pop($tickets);
    } else {
        var_dump($tickets);
        die('Argh, more than one ticket found, need to do something about this.'. "\n");
        // What do?
    }

    if (empty($ticket)) {
    
        $api_parameters = [
            'title' => $details['issue']['subject'],
            'description' => $description,
            'ownerPHID' => $owner['phid'],
            'priority' => $priority_map[$details['issue']['priority']['name']],
            'projectPHIDs' => array(
                $found['phid'],
            ),
            // 'viewPolicy' =>
        ];

        $task = $conduit->callMethodSynchronous('maniphest.createtask', $api_parameters);
        var_dump('task created is', $task);
    }

    /**
     * Is $task identical/similar to $ticket?
     */
    // DR: or !empty $task?
    if (!empty($ticket) && isset($ticket['phid'])) {

        $transactions = [];

        if ($ticket['title'] !== $details['issue']['subject']) {
            $transactions[] = [
                'type' => 'title',
                'value' => $details['issue']['subject'],
            ];
        };
    

        $file_ids = [];
        foreach ($details['issue']['attachments'] as $attachment) {
            $url = preg_replace(
                '/http(s?):\/\//', 
                sprintf(
                    'https://%s:%s@',
                    $config['redmine']['user'],
                    $config['redmine']['password']
                ),
                $attachment['content_url']
            );
            
            $encoded = base64_encode(file_get_contents($url));
            $api_parameters = [
                'name' => $attachment['filename'],
                'data_base64' => $encoded
               // 'viewPolicy' => todo!
            ];
            $file_phid = $conduit->callMethodSynchronous('file.upload', $api_parameters);
            $api_parameters = array(
              'phid' => $file_phid,
            );
            $result = $conduit->callMethodSynchronous('file.info', $api_parameters);
            $file_ids[] = sprintf('{%s}', $result['objectName']);
        }    
        
        $files = implode(' ', $file_ids);
        $transactions[] = [
            'type' => 'description',
            'value' => sprintf("%s\n\n%s", $description, $files)
        ];

        // query phabricator => save to list
        $status = $details['issue']['status']['name'];
        $key = array_search($status, $status_map);

        if (!$key) {
            printf('We could not find a matching key for your status "%s"!' . "\n> ", $status);
            foreach ($status_map as $key => $value) {
                printf("%s\n", $key);
            }
            printf(
                'Press [1] to add "%s" to the map_list; [2] if you want to give it a value from the map_list', 
                $status
            );
            $fp = fopen('php://stdin', 'r');
            $map_check = trim(fgets($fp, 1024));
            fclose($fp);

            if ($map_check == '1') {
                $status_map[] = $status;
            }
            elseif ($map_check == '2') {
                printf('Enter the wished value!');
                $fp = fopen('php://stdin', 'r');
                $new_value = trim(fgets($fp, 1024));
                fclose($fp);
                $status = $new_value;

            }
        }

        
        // this does not work
        

        // save new mapping to list

        $transactions[] = [
            'type' => 'status',
            'value' => $status,
        ];
        
        foreach ($details['issue']['journals'] as $journal) {
            if (!isset($journal['notes']) || empty($journal['notes'])) {
            continue;
    }
            comment_tansaction($conduit, $journal);
        }        
        
        $subscribers = watchersToSubscribers($conduit, $details['issue']['watchers']);
        if (!empty($subscribers)) {
            $transactions[] = [
                'type' => 'subscribers.set',
                'value' => $subscribers,
            ];
        }

        $prio = $details['issue']['priority']['name'];
        $priority = $priority_map[$prio];
        if (!$priority) {
            printf('We could not find a matching priority for your priority "%s"!' . "\n> ", $prio);
            foreach ($priority_map as $priority2 => $value) {
                printf("%s\n", $priority2);
            }

            printf('Press [1] to add %s to the map_list; [2] if you want to give it a value from the map_list');
            $fp = fopen('php://stdin', 'r');
            $map_check = trim(fgets($fp, 1024));
            fclose($fp);

            if ($map_check == '1') {
                $priority_map[] = $prio;
            }
            elseif ($map_check == '2') {
                printf('Enter the wished value!');
                $fp = fopen('php://stdin', 'r');
                $new_value = trim(fgets($fp, 1024));
                fclose($fp);
                $prio = $new_value;

            }

            $prio = $newpriority;
        }

        $transactions[] = [
            'type' => 'priority',
            'value' => $prio,
        ];
    

        // todo:
        // priority    Change the priority of the task. //?
        // view    Change the view policy of the object. //??//
        // edit    Change the edit policy of the object. //??//
        // subscribers.set Set subscribers, overwriting current value. //ok
        //  - refactor code into functions //ok
        //  - fix status array problem // ?
        //  - list of phabricator projects //??

        /**
         * Now update the ticket with additional information (comments, attachments, relations, subscribers, etc)
         */
        $api_parameters = [
          'objectIdentifier' => $ticket['phid'],
          'transactions' => $transactions
        ];

        $edit = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);
    }





}, $issues);

// Make this nicer obviously ;)
print_r($results);