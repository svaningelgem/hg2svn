<?php
  ##########################################
  # Cmdline parsing
  $verbose = 0;
  $skip_next = 0; # not true/false, just in case we will support multiple parameters (euh?)
  $new_argv = array();
  $temporary_path = '/var/lib/hg2svn';
  foreach( $_SERVER['argv'] as $idx => $parameter ) {
    if ( $skip_next > 0 ) {
      --$skip_next;
      continue;
    }

    $reworked = true;

    switch( strtolower($parameter) ) {
    case '-v':
    case '--verbose':
      $verbose++;
      break;

    case '-h':
    case '--help':
      usage();
      break;

    case '-t':
    case '--temporary-path':
      $tmp_dir = @$_SERVER['argv'][$idx+1];
      if ( ($tmp_dir == '') || !is_writable($tmp_dir) || !is_dir($tmp_dir) ) {
        usage('Please provide a writable temporary path!');
      }
      $temporary_path = $tmp_dir;
      $skip_next = 1; # Skip next parameter
      break;

    default:
      $new_argv[] = $parameter;
      break;
    }
  }

  define('VERBOSE_OUTPUT', $verbose);
  unset($verbose);

  define('VERBOSE_ERROR' , -1);
  define('VERBOSE_LOW'   , 0);
  define('VERBOSE_NORMAL', 1);
  define('VERBOSE_HIGH'  , 2);
  define('VERBOSE_DEBUG' , 3);

  define('SVNPROP_TEMPDIR', 'svn:hg2svn-tmpdir');
  define('SVNPROP_HG_REPO', 'svn:hg2svn-hg-repo');
  define('SVNPROP_HG_REV' , 'svn:hg2svn-hg-last-rev');
  

  $_SERVER['argv'] = $new_argv;
  $_SERVER['argc'] = count($new_argv);
  unset($new_argv);
  ##########################################


  ##########################################
  # Check for required programs
  ##########################################
  foreach( array('svn', 'hg') as $exe ) {
    if ( trim(shell_exec('which '.escapeshellarg($exe))) == '' ) {
      cout("Cannot find '{$exe}' executable, please install it!");
      exit(1);
    }
  }
  ##########################################


  ##########################################
  # Functions
  ##########################################

  function usage($msg = '') {
    if ( $msg != '' ) {
      echo "!!! ".trim($msg)."\n\n";
    }

    echo "Usage: {$_SERVER['argv'][0]} [--verbose|-v] [--help|-h] init|sync\n";
    echo " with:    init [--temporary-path <temporary path>] <hg repository> <svn repository>\n";
    echo " with:    sync <svn repository>\n";

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

  /**
  * Creates the directory structures needed for hg/svn work
  * 
  * @returns string temporary directory name to be used
  */
  function create_and_check_directory_structure($use_this_dir = null) {
    $tempnam = is_null($use_this_dir) ? tempnam(CACHING_DIR, 'hg2svn') : $use_this_dir;

    @mkdir($tempnam.'_hg', 0755, true);
    @mkdir($tempnam.'_svn', 0755, true);

    if ( !is_writable($tempnam.'_hg')  || !is_dir($tempnam.'_hg') || 
         !is_writable($tempnam.'_svn') || !is_dir($tempnam.'_svn') ) {
      cout('Temporary directories are unavailable!', VERBOSE_ERROR);
      exit(1);
    }

    return $tempnam;
  }
    
  function cout($message, $level = 0) {
    if ($level < VERBOSE_OUTPUT) {
      return;
    }

    echo $message . "\n";
  }

  function get_cache_dir( ) {
    static $cache_dir = null;
    if ( is_null($cache_dir) ) {
      global $config;

      $cache_dir = $config['cache_dir'];

      is_dir($cache_dir) || (
        @mkdir($cache_dir, 0755, true)
        &&
        cout("Succesfully created {$cache_dir}", VERBOSE_NORMAL)
      );

      if ( !is_writable($cache_dir) ) {
        cout("Error: Could not create {$cache_dir} please check permissions.");
        exit(1);
      }
    }

    return $cache_dir;
  }

  function safe_exec($cmd) {
    $output = array();
    $return_var = 0;

    exec($cmd, $output, $return_var);
    $output = implode("\n", $output);
    if ( $return_var != 0 ) {
      cout("'{$cmd}' failed to execute.", VERBOSE_NORMAL);
      cout(" -> return code: {$return_var}.", VERBOSE_INFO);
      cout(" -> generated output:\n{$output}", VERBOSE_DEBUG);
      exit($return_var);
    }

    return $output;
  }

  function get_revision_properties($to_svn_repo) {
    $out = safe_exec('svn proplist --revprop -r 0 '.escapeshellarg($to_svn_repo).' | grep -v "Unversioned properties on revision 0"');
    return array_diff(array_map('trim', explode("\n", $out)), array(''));
  }
