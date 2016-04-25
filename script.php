<?php

require_once 'vendor/autoload.php';
require_once 'githubstash.config.php';

global $conf;

define('GITHUB_USERNAME', $conf['github']['username']);
define('GITHUB_PASSWORD', $conf['github']['password']);
define('GITHUB_ORGANIZATION', $conf['github']['organization']);
define('STASH_USERNAME', $conf['stash']['username']);
define('STASH_PASSWORD', $conf['stash']['password']);
define('STASH_URL', $conf['stash']['url']);
define('STASH_PROJECT', $conf['stash']['project']);
define('STASH_API_URL', 'https://' . STASH_URL . '/rest/api/1.0/projects/' . STASH_PROJECT);
define('STASH_AUTH', base64_encode(STASH_USERNAME . ':' . STASH_PASSWORD));

$client = new Github\Client();
$client->authenticate(GITHUB_USERNAME, GITHUB_PASSWORD, Github\Client::AUTH_HTTP_PASSWORD);

foreach (range(0,1) as $page) {
  $repos = $client->api('organizations')->repositories(GITHUB_ORGANIZATION, 'private', 1, $page);
  foreach ($repos as $r) {

    // Grab a list of all branches in use.
    $branches = $client->api('repo')->branches(GITHUB_ORGANIZATION, $r['name']);
    $r_branches = array();
    foreach ($branches as $b) {
      $r_branches[] = $b['name'];
    }

    // Create an array of all our repos with all needed values.
    $repo_urls[$r['name']] = array(
      'url' => $r['ssh_url'],
      'default_branch' => $r['default_branch'],
      'branches' => $r_branches,
    );

  }
}

// Create a tmp directory to hold our repos while we switch things around.
shell_exec('mkdir tmp');

// Loop through all repos, clone them, make changes, push to stash.
foreach ($repo_urls as $name => $repo) {

  shell_exec('git clone ' . $repo['url'] . ' tmp/'.$name);

}

