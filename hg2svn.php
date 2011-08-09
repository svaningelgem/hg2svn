<?php
  require_once( dirname(__FILE__) . '/functions.php' );


  # Parameter checking!
  switch( strtolower(@$_SERVER['argv'][1]) )
  {
  case 'init':
    if ( $_SERVER['argc'] < 4 ) {
      usage();
    }
    else {
      init();
    }
    break;

  case 'sync':
    if ( $_SERVER['argc'] < 3 ) {
      usage();
    }
    else {
      sync();
    }
    break;
    
  default:
    usage();
    break;
  }
  exit(0);

  // **TODO**: What if I give 2 remote urls?
  // **TODO**:  --> store data in revision 0 of the subversion repository of where we left off etc.

  function init() {
    // **TODO** @steven@ Check how we can see if a revision property can be modified (it's a hook somewhere I know) & fail if this won't work.
    $from_hg_repo = $_SERVER['argv'][2];
    $from_svn_repo  = $_SERVER['argv'][3];

    # @ pieter: Contact "to_svn" & get properties of SVN level 0 to see if we need to use a special cache directory
    #  --> Adjust create_directory_structure() to take in an optional name which checks if it already exists & creates if not, if it already exists exit with error.
    #  --> If dir does not exist: create the structure
    $checkout_directory = create_directory_structure();
    $cloned_hg = $checkout_directory.'_hg'; 
    $svn_target = $checkout_directory.'_svn'; 
    
    // See if checked_out_svn already has any properties, which means it has already been initialized - in which case abort. 
    if (is_dir($svn_target)) {
        $properties = $rtrim($shell_exec("svn proplist $svn_target"));
        if ( $properies != "" ) {
            usage("Svn repo seems to have been already initialized.");
        }
    }
    
    # @ pieter: 1) checkout HG under $checkout_directory . '_hg'
    //Check if mecurial target exists, else clonse it from $from_hg_repo
    if ( ! is_dir($cloned_hg)) {
        cout("Cloning Mercurial repository at {$from_hg_repo} into Mercurial working copy at {$cloned_hg}.\n");
        shell_exec("hg clone $from_hg_repo $cloned_hg");
    } else {
        usage("$cloned_hg already looks like it was inititalized. Perhaps rather use 'sync'.\n");
    }
    
    chdir($cloned_hg);
    
    $hg_tip_rev = rtrim(shell_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));
    $start_rev = 0;
    
    # @ pieter: 2) create SVN repository under $checkout_directory . '_svn' (we should not need this in fact!! svnsync can work without. Please investigate)
    check_out_svn_repo($from_svn_repo,$svn_target);
    // Turn the SVN location into a mercurial one
    cout("Converting Mercurial repository at {$cloned_hg} into Subversion working copy at {$svn_target}.\n");
    chdir($svn_target);
    shell_exec("hg init .");
    # @ pieter: 3) set level 0 SVN properties with the temporary directory name, hg repository, last fetched revision from hg ("" in this initial step)
    shell_exec("svn propset tempdir $checkout_directory .");
    shell_exec("svn propset hgrepo $from_hg_repo .");
    //Think 0 is a better value than ""
    shell_exec("svn propset last_fetched_rev 0 .");
    shell_exec("svn commit -m 'Initialized.'");
  }
  
  for ($i = $start_rev; $i <= $stop_rev; $i++) {
      cout("Fetching Mercurial revision $i/$hg_tip_rev\n");
      rtrim(shell_exec("hg update -C -r $i"));
      //Parse out the incoming log message
      // **TODO**: see that we can fetch longer messages than 10 lines.
      $hg_log_msg = rtrim(shell_exec("hg -R $from_hg_repo -v log -r $i | grep -A10 ^description:$ | grep -v ^description:$ | head --lines=-2"));
      $hg_log_changeset = rtrim(shell_exec("hg -R $from_hg_repo -v log -r $i | grep ^changeset: | head -1"));
      $hg_log_user = rtrim(shell_exec("hg -R $from_hg_repo -v log -r $i | grep ^user: | head -1"));
      $hg_log_date = rtrim(shell_exec("hg -R $from_hg_repo -v log -r $i | grep ^date: | head -1"));
      cout("- removing deleted files\n");

      shell_exec('svn status | grep \'^!\' | sed -e \'s/^! *\(.*\)/\1/g\' | while read fileToRemove; do svn remove "$fileToRemove"; done');
   

      cout("- removing empty directories\n");
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
              shell_exec("svn remove \"$dir_to_remove\"");
          }  
          if (!feof($handle)) {
             echo "Error: unexpected fgets() fail\n";
          }
          fclose($handle);
      }

      cout("- adding files to SVN control\n");
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

              shell_exec("svn add --depth empty \"$files_to_add\"");
              }
              if (!feof($handle)) {
                  echo "Error: unexpected fgets() fail\n";
              }
          fclose($handle);
          }
      } 
      cout("- comitting\n");
      /* might need consideration for symlinks, but not going to worry about that now. */
      $hg_log_msg="$hg_log_changeset\n$hg_log_user\n$hg_log_date\n$hg_log_msg";
      $svn_commit_results = rtrim(shell_exec("svn commit -m \"$hg_log_msg\""));
      cout($svn_commit_results);
}
