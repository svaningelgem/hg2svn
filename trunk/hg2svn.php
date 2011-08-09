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
    $from_hg = $_SERVER['argv'][2];
    $to_svn  = $_SERVER['argv'][3];

    # @ pieter: Contact "to_svn" & get properties of SVN level 0 to see if we need to use a special cache directory
    #  --> Adjust create_directory_structure() to take in an optional name which checks if it already exists & creates if not, if it already exists exit with error.
    #  --> If dir does not exist: create the structure

    $checkout_directory = create_directory_structure();
    # @ pieter: 1) checkout HG under $checkout_directory . '_hg'
    # @ pieter: 2) create SVN repository under $checkout_directory . '_svn' (we should not need this in fact!! svnsync can work without. Please investigate)
    # @ pieter: 3) set level 0 SVN properties with the temporary directory name, hg repository, last fetched revision from hg ("" in this initial step)
  }

  function sync() {
    $to_svn  = $_SERVER['argv'][2];

    # @ pieter: contact SVN level 0 to fetch: temporary directory name, hg repository, last fetched revision
    # @ pieter: see that the directory structure exists
    # @ pieter: go synching from the last revision + 1, incrementally committing all changes, log entries, submitters, dates, ...
    #   --> Watch out for file renames.
  }
/*
      if ( $_SERVER['argv']['2'] ) {
	  // **TODO** Confirm propr HG URL, extract HG name from URL for cleaner directory structure later.
          $mercurial_src = $_SERVER['argv']['2'];
          //Extract "clean" repo name for our folders.
          $repo_name = get_clean_name($mercurial_src);

	  $mercurial_target = $config['cache_dir'] . "$repo_name" . '_hg';
          
          $subversion_target = $config['cache_dir'] . "$repo_name" . '_svn';

          //Check if mecurial target exists, else clonse it from $mercurial_src
          if ( ! is_dir($mercurial_target)) {
              echo_verbose("Cloning Mercurial repository at {$mercurial_src} into Mercurial working copy at {$mercurial_target}.\n");
              shell_exec("hg $quiet_flag clone $mercurial_src $mercurial_target");
          } else { 
              usage("$mercurial_target already looks like it was inititalized. Perhaps rather use 'sync'.\n");
          }
	  chdir($mercurial_target);

	  $hg_tip_rev = rtrim(shell_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));
          if  (is_dir($subversion_target)) {
              usage("$subversion_target already exists.  Perhaps rather use 'sync'.\n");
          } else {
              $svn_repo = $config['cache_dir'] . $repo_name . '_svn_repo';
              $start_rev = 0;
              create_svn_repo($svn_repo,$subversion_target);
              echo_verbose("Converting Mercurial repository at {$mercurial_target} into Subversion working copy at {$subversion_target}.\n");
              // Turn the SVN location into a mercurial one
              chdir($subversion_target);
              shell_exec("hg $quiet_flag init .");
          }
      }  else {
          usage("Please ensure mercurial src was specified after init.\n");
      }
  } elseif ( $_SERVER['argv']['1'] == 'sync' ) {
      if ( $_SERVER['argv']['2'] ) {
          $mercurial_src = $_SERVER['argv']['2'];
          //Extract "clean" repo name for our folders.
          $repo_name = get_clean_name($mercurial_src);
	  $mercurial_target = $config['cache_dir'] . "$repo_name" . '_hg';
          
          $subversion_target = $config['cache_dir'] . "$repo_name" . '_svn';
	  
          chdir($mercurial_target);
          echo_verbose("Ensuring HG repo is up to date before sync.\n");
          shell_exec("hg up");
	  $hg_tip_rev = rtrim(shell_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));

          echo_verbose("Syncing Mercurial repository at {$mercurial_src} into Subversion working copy at {$subversion_target}.\n");
          $start_rev = $hg_tip_rev;
      } else {
          usage("Please ensure mercurial src was specified after sync.\n");
      }
  } else {
      usage("usage: {$_SERVER['argv'][0]} init|sync mercurial_src \n");
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
      $hg_log_msg="$hg_log_changeset\n$hg_log_user\n$hg_log_date\n$hg_log_msg";
      $svn_commit_results = rtrim(shell_exec("svn commit $quiet_flag -m \"$hg_log_msg\""));
      echo_verbose($svn_commit_results);
  }
*/