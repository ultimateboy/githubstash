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

  // Create the empty repo in Stash.
  $url = STASH_API_URL . '/repos';
  $fields = array(
    'name' => $name,
    'scmId' => 'git',
  );
  $json_fields = json_encode($fields);
  $headers = array();
  $headers[] = 'Authorization: Basic ' . STASH_AUTH;
  $headers[] = 'Content-Type: application/json';
  $headers[] = 'Content-Length: ' . strlen($json_fields);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json_fields);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_SSLVERSION, 3);
  $result = curl_exec($ch);
  $r = json_decode($result);
  curl_close($ch);

  // cd in to the repo directory.
  chdir('tmp/'.$name);

  // Add stash remote.
  $clone_url = 'ssh://git@' . STASH_URL . ':' . STASH_PORT . '/' . STASH_PROJECT . '/' . $name . '.git';
  shell_exec('git remote add stash ' . $clone_url);

  // Push to stash.
  shell_exec('git push stash --all');
  shell_exec('git push stash --tags');

  // Set stash's default branch to whatever github's default branch was.
  $url = STASH_API_URL . '/repos/' . $name . '/branches/default';
  $fields = array(
    'id' => 'refs/heads/'. $repo['default_branch'],
  );
  $json_fields = json_encode($fields);
  $headers = array();
  $headers[] = 'Authorization: Basic ' . STASH_AUTH;
  $headers[] = 'Content-Type: application/json';
  $headers[] = 'Content-Length: ' . strlen($json_fields);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json_fields);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_SSLVERSION, 3);
  $result = curl_exec($ch);
  $r = json_decode($result);
  curl_close($ch);

  // For each branch, Find/Replace github url with stash url in .gitmodules.
  foreach ($repo['branches'] as $branch) {
    // Checkout the branch.
    shell_exec("git checkout -b $branch origin/$branch");
    // @todo handle default branch.
    $dotgitmodules = dirname(__FILE__) . "/tmp/$name/.gitmodules";
    if (is_file($dotgitmodules)) {
      $gitmodules = file_get_contents($dotgitmodules);
      if ($gitmodules) {
        // Find/Replace 'github' url to 'stash' url in .gitmodules file.
        $gitmodules = str_replace('git@github.com:' . GITHUB_ORGANIZATION . '/', 'ssh://git@' . STASH_URL . ':' . STASH_PORT . '/' . STASH_PROJECT . '/', $gitmodules);
        file_put_contents($dotgitmodules, $gitmodules);
        // Add and commit.
        shell_exec('git add .gitmodules');
        shell_exec('git commit -m "Switching submodule references to stash instead of github."');
      }
    }
  }

  // @todo Because the git commit creates a new git commit sha1, parent repos
  // which reference this submodule also need to be updated. Currently, this
  // is unsupported - it would involve looping through all the repos again.

  // Push all branches.
  shell_exec('git push stash --all');

  chdir('../..');
}

