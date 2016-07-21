<?php

// WRITE TESTS!!!!!

namespace Remaim;

use Redmine\Client;
use Redmine\Api\Issue;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;

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
print('Select a project: [0]' . "\n");
$fp = fopen('php://stdin', 'r');
$project = trim(fgets($fp, 1024));
fclose($fp);

/////
// $detail = $redmine->project->show($project);
// var_dump($detail); exit;

$tasks = $redmine->issue->all([
    'project_id' => $project,
    'limit' => 1024
]);    // 94 == VM Test Software
if (!$tasks || empty($tasks['issues'])) {
    printf('No tasks found on project %s', $project); exit;
}
$issues = $tasks['issues'];


print('Enter the id/slug of the project, press [Enter] to see projects or enter [0] to create a new project in phabricator' . "\n");
$fp = fopen('php://stdin', 'r');
$phab_project = trim(fgets($fp, 1024));
fclose($fp);

if ('0' === $phab_project) {
    $detail = $redmine->project->show($project);
    var_dump($detail);

    $api_parameters = [
        'name' => $detail['project']['name']
        ];
    //  here be redmine to phabricator conversion magic
    // $api_parameters = $detail;
    $result = $conduit->callMethodSynchronous('project.create', $api_parameters);
    $found = array_pop($result['data']);
    if (isset($found['phid'])) {
        printf(
               'OK, found project named "%s" with PHID %s' . "\n",
               $found['name'],
               $found['phid']
        );
    }

} elseif ('' === $phab_project) {
    $detail = $redmine->project->show($project);
    var_dump($detail);
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
        // print_r($found['phid']);
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


// $project_issuerelation = $redmine->issuerelation->show($relation);
// var_dump($project_issuerelation); 

// $project_issuestatus = $redmine->issue_status->all([
//     'project_id' => $project,
//     'limit' => 1024
// ]); 
// var_dump($project_issuestatus); 

// Grab issues for the selected project
$tasks = $redmine->issue->all([
    'project_id' => $project,
    'limit' => 1024
]);    // 94 == VM Test Software
if (!$tasks || empty($tasks['issues'])) {
    printf('No tasks found on project %s', $project);
}
$issues = $tasks['issues'];

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
$results = array_map(function ($issue) use ($conduit, $redmine, $found, $priority_map) {
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

    // printf('Looking for existing tickets with text "%s"' . "\n", $details['issue']['project']['name']);
    $api_parameters = [
        'fullText' => $description,
    ];
    $ticket = $conduit->callMethodSynchronous('maniphest.query', $api_parameters);
    var_dump($ticket);

    // $api_parameters = [
    //     'priority' => [$details['issue']['priority']['name']],
    // ];
    // $result = $conduit->callMethodSynchronous('maniphest.query', $api_parameters);
    // $priority = array_pop($result);

    printf(
             'Do you want to migrate "%s" to %s [y|N]' . "\n",
             $found['name'],
             $ticket['PHID-TASK-avp53clc72oqwdetydod']['id']
         );
    $fp = fopen('php://stdin', 'r');
    $check = trim(fgets($fp, 1024));
    fclose($fp);exit;

if ($check == 'y') {

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
    }

    $api_parameters = [
      'objectIdentifier' => $ticket['PHID-TASK-avp53clc72oqwdetydod']['id'],
      'transactions' => [
        [
            'type' => 'title',
            'value' => $details['issue']['subject'], // war et label?
        ],
      ]
    ];

    $titlefix = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);
/*
    $api_parameters = [
    'objectIdentifier' => $ticket['phid'],
    'transactions' => [
        [
            'type' => 'comment',
            'value' => $details['issue']['journals']['notes'],
        ],
      ]
    ];

    $commentfix = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);

    $api_parameters = [
    'objectIdentifier' => $ticket['phid'],
    'transactions' => [
        [
            'type' => 'title',
            'value' => $details['issue']['subject'],
        ],
      ]
    ];

    $titlefix = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);*/

    }

elseif ($check == 'N') {
    exit;
}

    // $attachment = download($details['issue']['attachments']);

    // $api_parameters = [
    //     'name' => $attachment['0']['filename'],
    //     'data_base64' =>
    //     'viewPolicy' => 
    // ];
    // $result = $conduit->callMethodSynchronous('file.upload', $api_parameters);
    // var_dump($api_parameters);



    // $api_parameters = array(
    //     'comments' => 'test comment',
    // );

    // $result = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);
}, $issues);

// Make this nicer obviously ;)
print_r($results);