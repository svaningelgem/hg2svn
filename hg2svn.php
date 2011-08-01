<?php

$mercurial_src = $argv[1];
$subversion_target = $argv[2];

print "Converting Mercurial repository at $mercurial_src into Subversion working copy at $subversion_target.\n";

// Turn the SVN location into a mercurial one
$cwd = getcwd();
chdir($subversion_target); 
shell_exec('hg init .');

chdir($mercurial_src);

$hg_tip_rev = rtrim(shell_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));
$stop_rev = $hg_tip_rev;
$start_rev = 0;

chdir($subversion_target);

shell_exec("hg pull -r $hg_tip_rev $mercurial_src");


for ($i = $start_rev; $i <= $stop_rev; $i++) {
    echo  "Fetching Mercurial revision $i/$hg_tip_rev\n";
    rtrim(shell_exec("hg update -C -r $i"));
    //Parse out the incoming log message
    $hg_log_msg = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep -A10 ^description:$ | grep -v ^description:$ | head --lines=-2"));
    $hg_log_changeset = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^changeset: | head -1"));
    $hg_log_user = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^user: | head -1"));
    $hg_log_date = rtrim(shell_exec("hg -R $mercurial_src -v log -r $i | grep ^date: | head -1"));
    echo "- removing deleted files\n";

    shell_exec('svn status | grep \'^!\' | sed -e \'s/^! *\(.*\)/\1/g\' | while read fileToRemove; do svn remove "$fileToRemove"; done');
 

    echo "- removing empty directories\n"; 
    $fp = fopen("/tmp/empty_dirs.txt", "w");
    fputs ($fp, shell_exec("find . -name '.svn' -prune -o -type d -printf '%p+++' -exec ls -am {} \; | grep '., .., .svn$' | sed -e 's/^\(.*\)+++.*/\1/g'"));
    fclose ($fp);

    $handle = @fopen("/tmp/empty_dirs.txt", "r");
    if ($handle) {
      while (($dir_to_remove = fgets($handle, 4096)) !== false) {
        echo $dir_to_remove;
        $dir_to_remove = rtrim($dir_to_remove); 
	shell_exec("rm -rf \"$dir_to_remove \"");
        shell_exec("svn remove \"$dir_to_remove\"");
      }  
    if (!feof($handle)) {
        echo "Error: unexpected fgets() fail\n";
    }
    fclose($handle);

    echo "- adding files to SVN control\n";
    # 'svn add' recurses and snags .hg* files, we are pulling those out, so do our own recursion (slower but more stable)
    #   This is mostly important if you have sub-sites, as they each have a large .hg file in them
    $count = trim(shell_exec("svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l"));
    while ( rtrim(shell_exec("svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l")) > 0 ) {
     
    }
}
/*
    while [ `svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l` -gt 0 ]
    do
        svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | sed -e 's/^\? *\(.*\)/\1/g' | while read fileToAdd
        do
            if [ -d "$fileToAdd" ]
            then
                # Mercurial seems to copy existing directories on moves or something like that -- we
                # definitely get some .svn subdirectories in newly created directories if the original
                # action was a move. New directories should never contain a .svn folder since that breaks
                # SVN
                find "$fileToAdd" -type d -name ".svn" | while read accidentalSvnFolder
                do
                    rm -rf "$accidentalSvnFolder"
                done
            fi
            # Using --depth empty here, so we don't recurisivly add, we are taking care of that ourselves.
            svn add $QUIET_FLAG --depth empty "$fileToAdd"
        done
    done

}
*/

}      
/*


*/
?>

