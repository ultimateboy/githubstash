## Github Organization to Stash Project

This project migrates all repositories within a github organization account
in to a Stash project.

### Installation
1. cp example.githubstash.config.php githubstash.config.php
2. modify values in githubstash.config.php
3. php composer.phar install

### Usage
1. php script.php


### Known limitations

1. While basic support for migrating git repos which contain submodules is supported, submodules which contain refernces to other submodules will cause an issue. Solving this will involve multiple passes through the array of repositories - once to change the .gitmodules file (and add/commit) and then again to fix the references to those submodules in the super projects.
