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
    safe_exec('svn propset '.escapeshellarg(SVNPROP_HG_REPO).' --revprop -r 0 '.escapeshellarg($from_hg_repo)      .' '.escapeshellarg($to_svn_repo));

    cout("Successfully initialized. Ready for sync.");
  }

  # TODO: symlinks
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
    if ( $last_hg_rev === '' ) { // First revision!
      $last_hg_rev = -1;
    }

    define('CACHING_DIR', dirname($tmp_dir));

    # Step 2: get tmp directory
    create_and_check_directory_structure($tmp_dir);
    $tmpdir_hg  = $tmp_dir.'_hg'; 
    $tmpdir_svn = $tmp_dir.'_svn'; 
    unset($tmp_dir);

    # Step 3: check for cached hg/svn repositories
    if ( !is_dir($tmpdir_hg.'/.hg') ) {
      if ( !completely_remove($tmpdir_hg) ) {
        cout('Cannot remove hg tmpdir!', VERBOSE_ERROR);
        exit(1);
      }
      safe_exec('hg clone '.escapeshellarg($from_hg_repo).' '.escapeshellarg($tmpdir_hg));
    }

    if ( !is_dir($tmpdir_svn.'/.svn') ) {
      if ( !completely_remove($tmpdir_svn) ) {
        cout('Cannot remove svn tmpdir!', VERBOSE_ERROR);
        exit(1);
      }
      safe_exec('svn checkout '.escapeshellarg($to_svn_repo).' '.escapeshellarg($tmpdir_svn));
    }

    # Step 4: updating hg (no need to update svn as that should be read only to the outside world!)
    cout("Ensuring HG repo is up to date before sync.", VERBOSE_INFO);
    chdir($tmpdir_hg);
    safe_exec('hg up');

    # Step 5: check which is the revision I need to stop
    $stop_rev = get_tip_revision();
    if ( $stop_rev === false ) {
      cout("No tip revision found?!", VERBOSE_ERROR);
      exit(1);
    }

    # Step 6... The final looping...
    $prev_rev = $last_hg_rev;
    $tempfile = tempnam('/tmp', 'hg2svn');
    $tempfile_esc = escapeshellarg($tempfile);

    for ($current_rev = $last_hg_rev + 1; $current_rev <= $stop_rev; $current_rev++) {
      if ( defined('STOP_AT_REVISION') && ($current_rev >= STOP_AT_REVISION) ) {
        cout("Reached target revision (".STOP_AT_REVISION."), stopping...", VERBOSE_NORMAL);
        break;
      }
      # Sub 1: fetch the changes between the 2 revisions
      chdir($tmpdir_hg);
      cout("Fetching Mercurial revision {$current_rev}/{$stop_rev}");

      $log  = parse_hg_log_message($current_rev);

      $hg_log_msg       = $log['description'];
      $hg_log_changeset = explode(':', $log['changeset']);
      $hg_log_changeset = array_shift($hg_log_changeset);
      $hg_log_user      = $log['user'];
      $hg_log_date      = gmstrftime('%Y-%m-%dT%H:%M:%S.000000Z', strtotime($log['date']));

      cout("Current log message: '{$hg_log_msg}'", VERBOSE_INFO);

      $diff = parse_hg_diff($current_rev);

      # Sub 2: apply them to SVN
      chdir($tmpdir_svn);
      cout('- Applying differences');
      foreach( $diff as $patch ) {
        switch( $patch['action'] ) {
        case 'add':
        case 'update': # TODO: what with binary updates?
          patch_file($patch);
          break;

        case 'delete':
          remove_file($patch['file1']);
          break;

        case 'symlink':
          create_dir_in_svn(dirname($patch['file1']));
          if ( IS_WINDOWS ) {
            file_put_contents($patch['file1'], "link ".$patch['file2']);
            safe_exec('svn add --parents '.escapeshellarg($patch['file1']));
            safe_exec('svn propset svn:special yes '.escapeshellarg($patch['file1']));
          }
          else {
            symlink($patch['file2'], $patch['file1']);
            safe_exec('svn add --parents '.escapeshellarg($patch['file1']));
          }
          break;

        case 'copy':
          create_dir_in_svn(dirname($patch['to']));
          safe_exec('svn copy '.escapeshellarg($patch['from']).' '.escapeshellarg($patch['to']));
          patch_file($patch);
          break;

        case 'rename':
          create_dir_in_svn(dirname($patch['to']));
          safe_exec('svn move '.escapeshellarg($patch['from']).' '.escapeshellarg($patch['to']));
          remove_dirtree_if_empty(dirname($patch['from']));
          patch_file($patch);
          break;

        default:
          throw new Exception("Unimplemented action '{$patch['action']}'");
          break;
        }
      }

      # Sub 3: apply svn:ignore if needed (see if .hgignore was added/updated/removed)
      # TODO: take previous .hgignores & see if they are changed...
      #       parse if needed & apply svn:ignore properties accordingly.

      # Sub 4: commit
      cout('- Committing');
      $out = safe_exec('svn commit . -m '.escapeshellarg($hg_log_msg));
      if ( preg_match('|Committed revision ([0-9]+)\.|iUms', $out, $m) > 0 ) {
        $svn_revision = $m[1];
      }
      else {
        $svn_revision = null;
      }

      if ( !is_null($svn_revision) ) {
        # Remove empty subdirectories from the tree
        foreach( $diff as $patch ) {
          if ( $patch['action'] == 'delete' ) {
            remove_file_step2($patch['file1']);
          }
          else if ( $patch['action'] == 'rename' ) {
            remove_file_step2($patch['from']);
          }
        }

        # Sub 5: adjust dates/author and the likes
        safe_exec('svn propset '.escapeshellarg('svn:author').' --revprop -r '.escapeshellarg($svn_revision).' '.escapeshellarg($hg_log_user).' '.escapeshellarg($to_svn_repo));
        safe_exec('svn propset '.escapeshellarg('svn:date')  .' --revprop -r '.escapeshellarg($svn_revision).' '.escapeshellarg($hg_log_date).' '.escapeshellarg($to_svn_repo));
      }

      # Sub 6: setting last fetched svn property
      safe_exec('svn propset '.escapeshellarg(SVNPROP_HG_REV).' --revprop -r 0 '.escapeshellarg($current_rev).' '.escapeshellarg($to_svn_repo));
    }
  }
