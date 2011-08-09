<?php
  ##########################################
  # Cmdline parsing
  $verbose = 0;
  foreach( $_SERVER['argv'] as $idx => $parameter ) {
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

    default:
      $reworked = false;
      break;
    }

    if ( $reworked ) {
      unset($_SERVER['argv'][$idx]);
    }
  }
  define('VERBOSE_OUTPUT', $verbose);
  define('VERBOSE_LOW'   , 0);
  define('VERBOSE_NORMAL', 1);
  define('VERBOSE_HIGH'  , 2);
  define('VERBOSE_DEBUG' , 3);
  
  unset($verbose);


  $_SERVER['argv'] = array_values($_SERVER['argv']); # reset indexes of this array
  $_SERVER['argc'] = count($_SERVER['argv']);
  ##########################################


  ##########################################
  # Config file loading
  $config = parse_ini_file(dirname(__FILE__) . "/config.ini");
  ##########################################

  ##########################################
  # Functions
  ##########################################

  function usage($msg = '') {
    if ( $msg != '' ) {
      echo "!!! ".trim($msg)."\n\n";
    }

    echo "Usage: {$_SERVER['argv'][0]} [--verbose|-v] [--help|-h] init|sync\n";
    echo " with:    init <hg repository> <svn repository>\n";
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
  function create_directory_structure() {
    $tempnam = tempnam(get_cache_dir(), 'hg2svn');
    //@mkdir($tempnam.'_hg', 0755, true);
    //@mkdir($tempnam.'_svn', 0755, true);

    return $tempnam;
  }

  function check_out_svn_repo($svn_repo,$svn_target) {
    cout("Checking out $svn_repo as $svn_target\n");
    //** TODO ** Add some error handeling.
    shell_exec("svn co $svn_repo $svn_target");
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
