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
      define('CACHING_DIR', $temporary_path);
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






  function init() {
    $from_hg_repo = $_SERVER['argv'][2];
    $to_svn_repo  = $_SERVER['argv'][3];

    # Step 1: check revprops of SVN to see if we already inited() it.
    $properties = get_revision_properties($to_svn_repo);

    if ( in_array(SVNPROP_TEMPDIR, $properties) ) {
      cout("SVN repository seems to be already initialized! Use sync instead.", VERBOSE_ERROR);
      exit(1);
    }

    # Step 2: create temporary structure
    $checkout_directory = create_and_check_directory_structure();
    $tmpdir_hg          = $checkout_directory.'_hg'; 
    $tmpdir_svn         = $checkout_directory.'_svn'; 

    # Step 3: clone hg & svn
    safe_exec('hg clone '.escapeshellarg($from_hg_repo).' '.escapeshellarg($tmpdir_hg));
    safe_exec('svn checkout '.escapeshellarg($to_svn_repo).' '.escapeshellarg($tmpdir_svn));

    # Step 4: set revprops on SVN target
    safe_exec('svn propset '.escapeshellarg(SVNPROP_TEMPDIR).' --revprop -r 0 '.escapeshellarg($checkout_directory).' '.escapeshellarg($to_svn_repo));
    safe_exec('svn propset '.escapeshellarg(SVNPROP_HG_REPO).' --revprop -r 0 '.escapeshellarg($from_hg_repo).' '.escapeshellarg($to_svn_repo));
    safe_exec('svn propset '.escapeshellarg(SVNPROP_HG_REV).' --revprop -r 0 \'0\' '.escapeshellarg($to_svn_repo));

    cout("Successfully initialized. Ready for sync.");
  }

  function sync() {
    $to_svn_repo = $_SERVER['argv'][2];

    # Step 1: check revprops of SVN to see if we already inited() it.
    $properties = get_revision_properties($to_svn_repo);
    if ( !in_array(SVNPROP_TEMPDIR, $properties) ) {
      cout("SVN repository doesn't seem to be initialized! Use init instead.", VERBOSE_ERROR);
      exit(1);
    }
    $tmp_dir      = safe_exec('svn propget '.escapeshellarg(SVNPROP_TEMPDIR).' --revprop -r 0 '.escapeshellarg($to_svn_repo));
    $from_hg_repo = safe_exec('svn propget '.escapeshellarg(SVNPROP_HG_REPO).' --revprop -r 0 '.escapeshellarg($to_svn_repo));
    $last_hg_rev  = safe_exec('svn propget '.escapeshellarg(SVNPROP_HG_REV) .' --revprop -r 0 '.escapeshellarg($to_svn_repo));
    define('CACHING_DIR', dirname($tmp_dir));

    # Step 2: get tmp directory
    create_and_check_directory_structure($tmp_dir);
    $tmpdir_hg  = $tmp_dir.'_hg'; 
    $tmpdir_svn = $tmp_dir.'_svn'; 
    unset($tmp_dir);

    # Step 3: check for cached hg/svn repositories
    if ( !is_dir($tmpdir_hg.'/.hg') ) {
      safe_exec('rm -rf '.escapeshellarg($tmpdir_hg));
      safe_exec('hg clone '.escapeshellarg($from_hg_repo).' '.escapeshellarg($tmpdir_hg));
    }

    if ( !is_dir($tmpdir_svn.'/.svn') ) {
      safe_exec('rm -rf '.escapeshellarg($tmpdir_svn));
      safe_exec('svn checkout '.escapeshellarg($to_svn_repo).' '.escapeshellarg($tmpdir_svn));
    }

    # Step 4: updating hg (no need to update svn as that should be read only to the outside world!)
    cout("Ensuring HG repo is up to date before sync.", VERBOSE_INFO);
    chdir($tmpdir_hg);
    safe_exec('hg up');

    # Step 5: check which is the revision I need to stop
    $stop_rev = trim(safe_exec('hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"'));

    # Step 6... The final looping...
    $prev_rev = $last_hg_rev;
    $tempfile = tempnam('/tmp', 'hg2svn');
    $tempfile_esc = escapeshellarg($tempfile);

    for ($current_rev = $last_hg_rev + 1; $current_rev <= $stop_rev; $current_rev++) {
      # Sub 1: fetch the changes between the 2 revisions
      chdir($tmpdir_hg);
      cout("Fetching Mercurial revision {$current_rev}/{$hg_tip_rev}", VERBOSE_NORMAL);
      safe_exec("hg diff -r {$prev_rev} -r {$current_rev} > {$tempfile_esc}");

      #### @pieter@: check copy/renames

      # Sub 2: apply them to SVN
      chdir($tmpdir_svn);
      safe_exec("patch -p1 < {$tempfile_esc}");

      # Sub 3: get the description for the log entry
      $log = parse_hg_log_message($current_rev);

      rtrim(shell_exec("hg update -C -r $current_rev"));
      //Parse out the incoming log message
      // **TODO**: see that we can fetch longer messages than 10 lines.
      $hg_log_msg = rtrim(shell_exec("hg -R $cloned_hg -v log -r $current_rev | grep -A10 ^description:$ | grep -v ^description:$ | head --lines=-2"));
      $hg_log_changeset = rtrim(shell_exec("hg -R $cloned_hg -v log -r $current_rev | grep ^changeset: | head -1"));
      $hg_log_user = rtrim(shell_exec("hg -R $cloned_hg -v log -r $current_rev | grep ^user: | head -1"));
      $hg_log_date = rtrim(shell_exec("hg -R $cloned_hg -v log -r $current_rev | grep ^date: | head -1"));
      cout("- removing deleted files\n");
      
      shell_exec("svn status | grep '^!' | sed -e 's/^! *\(.*\)/\1/g' | while read fileToRemove; do svn remove \"\$fileToRemove\"; done");

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
      shell_exec("svn propset last_fetched_rev $current_rev .");
      cout("- comitting\n");
      /* might need consideration for symlinks, but not going to worry about that now. */
      $hg_log_msg="$hg_log_changeset\n$hg_log_user\n$hg_log_date\n$hg_log_msg";
      $svn_commit_results = rtrim(shell_exec("svn commit -m \"$hg_log_msg\""));
      cout($svn_commit_results);
  }
}
