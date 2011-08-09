<?php
function usage() {
    echo "usage: {$_SERVER['argv'][0]} init|sync mercurial_src \n";
    exit(1);
}

function get_clean_name($mercurial_src) {
    //Extract "clean" repo name for our folders.
    if (is_dir($mercurial_src)) {
        //clean name is base name of folder.
        $repo_name = basename(rtrim($mercurial_src, '/'));
        return $repo_name;
    } else {
        //Assume an url, extract last word.
        $repo_name = rtrim(shell_exec("echo $mercurial_src | awk -F \/ {'print \$NF'}"));
        return $repo_name;
    }
}

function create_svn_repo($svn_repo,$subversion_target) {
    if (file_exists($svn_repo) || file_exists($subversion_target)) {
        echo "Error: $subversion_repo or $subversion_target must NOT exist when init is specified.  Aborting.\n";
        exit(1);
    } else {
        echo_verbose("Creating new svn repo, and checking it out as $subversion_target\n");
        shell_exec("svnadmin create $svn_repo");
        shell_exec("svn co file://$svn_repo $subversion_target");
    }
}
  
function echo_verbose($message) {
    if (VERBOSITY) {
        echo $message;
    }
}
?>
