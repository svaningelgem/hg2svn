<?php

$config = parse_ini_file(dirname(__FILE__) . "/config.ini");

// **TODO** Sanity check if paths exist.

$subversion_target = $config['subversion_target'];
$mercurial_src = $config['mercurial_src'];

define ('VERBOSITY', $config['verbosity']);

if (VERBOSITY) {
    $quiet_flag = '';
} else {
    $quiet_flag = '-q';
}

function usage() {
    echo "Please specify first or incremental run: {$_SERVER['argv'][0]} init|sync\n";
    exit(1);
}
  
if ( $_SERVER['argc'] < 2 ) {
    usage();
}

function echo_verbose($message) {
    if (VERBOSITY) {
        echo $message;
    }
}

// **TODO**: What if I give 2 remote urls?
// **TODO**:  --> store data in revision 0 of the subversion repository of where we left off etc.

chdir($mercurial_src);

$hg_tip_rev = rtrim(shell_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));

if ( $_SERVER['argv']['1'] == 'init' ) {
    echo_verbose("Converting Mercurial repository at {$mercurial_src} into Subversion working copy at {$subversion_target}.\n");
    $start_rev = 0;
    // Turn the SVN location into a mercurial one
    chdir($subversion_target);
    shell_exec("hg $quiet_flag init .");
} elseif ( $_SERVER['argv']['1'] == 'sync' ) {
    echo_verbose("Syncing Mercurial repository at {$mercurial_src} into Subversion working copy at {$subversion_target}.\n");
    $start_rev = $hg_tip_rev;
} else {
    usage();
}

$stop_rev = $hg_tip_rev;

//TODO maybe have the program do the svncheckout instead of the user having to it first.  Can do via subversion_repo config variable.

chdir($subversion_target);
shell_exec("hg $quiet_flag pull -r $hg_tip_rev $mercurial_src");

for ($i = $start_rev; $i <= $stop_rev; $i++) {
    echo_verbose("Fetching Mercurial revision $i/$hg_tip_rev\n");
    rtrim(shell_exec("hg $quiet_flag update -C -r $i"));
    //Parse out the incoming log message
    // **TODO**: see that we can fetch longer messages than 10 lines.
    $hg_log_msg = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep -A10 ^description:$ | grep -v ^description:$ | head --lines=-2"));
    $hg_log_changeset = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^changeset: | head -1"));
    $hg_log_user = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^user: | head -1"));
    $hg_log_date = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^date: | head -1"));
    echo_verbose("- removing deleted files\n");

    shell_exec('svn status | grep \'^!\' | sed -e \'s/^! *\(.*\)/\1/g\' | while read fileToRemove; do svn remove "$fileToRemove"; done');
 

    echo_verbose("- removing empty directories\n");
    // **TODO**: load into memory, creating files all around is the bash way ;-)
    $fp = fopen("/tmp/empty_dirs.txt", "w");
    fputs ($fp, shell_exec("find . -name '.svn' -prune -o -type d -printf '%p+++' -exec ls -am {} \; | grep '., .., .svn$' | sed -e 's/^\(.*\)+++.*/\1/g'"));
    fclose ($fp);

    $handle = @fopen("/tmp/empty_dirs.txt", "r");
    if ($handle) {
        while (($dir_to_remove = fgets($handle, 4096)) !== false) {
            echo $dir_to_remove;
            $dir_to_remove = rtrim($dir_to_remove); 
	    shell_exec("rm -rf \"$dir_to_remove \"");
            shell_exec("svn remove $quiet_flag \"$dir_to_remove\"");
        }  
        if (!feof($handle)) {
           echo "Error: unexpected fgets() fail\n";
        }
        fclose($handle);
    }

    echo_verbose("- adding files to SVN control\n");
    # 'svn add' recurses and snags .hg* files, we are pulling those out, so do our own recursion (slower but more stable)
    #   This is mostly important if you have sub-sites, as they each have a large .hg file in them
    $count = trim(shell_exec("svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l"));
    while ( rtrim(shell_exec("svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l")) > 0 ) {
        $f2p = fopen("/tmp/files_to_add.txt", "w");
        fputs ($f2p, shell_exec('svn status | grep \'^\?\' | grep -v \'[ /].hg\(tags\|ignore\|sub\|substate\)\?\b\' | sed -e \'s/^\? *\(.*\)/\1/g\''));
        fclose ($f2p);

        $handle = @fopen("/tmp/files_to_add.txt", "r");
        if ($handle) {
            while (($files_to_add = fgets($handle, 4096)) !== false) {
                $files_to_add = rtrim($files_to_add);
        
                if (is_dir($files_to_add)) {
                    # Mercurial seems to copy existing directories on moves or something like that -- we
                    # definitely get some .svn subdirectories in newly created directories if the original
                    # action was a move. New directories should never contain a .svn folder since that breaks
                    # SVN
                    shell_exec("find \"$files_to_add\" -type d -name \".svn\" -exec rm -rf {} \\;");
                }

            shell_exec("svn add $quiet_flag --depth empty \"$files_to_add\"");
            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
        fclose($handle);
        }
    } 
    echo_verbose("- comitting\n");
    /* might need consideration for symlinks, but not going to worry about that now. */
    $svn_commit_results = rtrim(shell_exec("svn commit $quiet_flag -m \"$hg_log_msg\""));
    echo_verbose($svn_commit_results);
}
?>
