<?php
use Dinfini\Migration\BitBucketToGithub;
require_once 'vendor/autoload.php';

// autoload classes based on a 1:1 mapping from namespace to directory structure.
spl_autoload_register(function ($className) {

    # Usually I would just concatenate directly to $file variable below
    # this is just for easy viewing on Stack Overflow)
    $ds = DIRECTORY_SEPARATOR;
    $dir = 'src';

    // replace namespace separator with directory separator (prolly not required)
    $className = str_replace('\\', $ds, $className);

    // get full name of file containing the required class
    $file = "{$dir}{$ds}{$className}.php";

    // get file if it is readable
    if (is_readable($file)) require_once $file;
});

//=-- Include credentials and repo paths
include(dirname(__FILE__).'/data.php');

//=-- Set Bitbucket credentials
BitBucketToGithub::setBitBucketCredentials($bbUser, $bbPass);

//=-- Set Github credentials
BitBucketToGithub::setGithubCredentials($ghUser, $ghPass);

//=-- Perform migration
BitBucketToGithub::doMigrateIssues($bbAccountName, $bbRepoSlug, $ghRepo);