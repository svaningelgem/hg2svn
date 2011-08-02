<?php
function usage() {
    echo "Please specify first or incremental run: {$_SERVER['argv'][0]} init|sync\n";
    exit(1);
}

function create_svn_repo($subversion_repo,$subversion_target) {
    if (file_exists($subversion_repo) || file_exists($subversion_target)) {
        echo "Error: $subversion_repo or $subversion_target must NOT exist when init is specified.  Aborting.\n";
        exit(1);
    } else {
        echo_verbose("Creating new svn repo, and checking it out as $subversion_target\n");
        shell_exec("svnadmin create $subversion_repo");
        shell_exec("svn co file://$subversion_repo $subversion_target");
    }
}
  
function echo_verbose($message) {
    if (VERBOSITY) {
        echo $message;
    }
}
?>
