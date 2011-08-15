<?php
  ##########################################
  # Cmdline parsing
  $verbose = 0;
  $skip_next = 0; # not true/false, just in case we will support multiple parameters (euh?)
  $new_argv = array();
  $temporary_path = '/var/lib/hg2svn';
  if ( !isset($_SERVER['argv']) || !is_array($_SERVER['argv']) ) {
    $_SERVER['argv'] = array();
  }

  foreach( $_SERVER['argv'] as $idx => $parameter ) {
    if ( $skip_next > 0 ) {
      --$skip_next;
      continue;
    }

    switch( strtolower($parameter) ) {
    case '-v':
    case '--verbose':
      $verbose++;
      break;

    case '-q':
    case '--quiet':
      $verbose--;
      break;

    case '-h':
    case '--help':
      usage();
      break;

    case '--stop-at':
      $revision = @$_SERVER['argv'][$idx+1];
      if ( ($revision === '') || !is_numeric($revision) || ($revision <= 0) ) {
        usage('Please provide a numeric revision > 0!');
      }

      if ( defined('STOP_AT_REVISION') ) {
        cout("Please only provide '--stop-at' once!", VERBOSE_ERROR);
        exit(1);
      }

      define('STOP_AT_REVISION', intval($revision));
      $skip_next = 1; # Skip next parameter
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
  unset($idx, $parameter, $tmp_dir, $skip_next);

  define('VERBOSE_TRACE'  , 0);
  define('VERBOSE_DEBUG'  , 1);
  define('VERBOSE_INFO'   , 2);
  define('VERBOSE_NORMAL' , 3);
  define('VERBOSE_WARNING', 4);
  define('VERBOSE_ERROR'  , 5);

  define('VERBOSE_OUTPUT', VERBOSE_NORMAL - $verbose);
  unset($verbose);

  define('IS_WINDOWS', stripos(PHP_OS, 'win') !== false);

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
  $program_not_found = false;
  foreach( array('svn' => 'svn --version', 'hg' => 'hg --version', 'patch' => 'patch --version') as $program => $checking_line ) {
    $out = array();
    $ret = 0;
    exec($checking_line.' 2>&1', $out, $ret);
    if ( $ret != 0 ) {
      cout("Cannot find '{$program}', please install it!");
      $program_not_found = true;
    }
  }
  if ( $program_not_found ) {
    exit(1);
  }
  unset($program_not_found, $program, $checking_line, $out, $ret);
  ##########################################


  ##########################################
  # Functions
  ##########################################
  function usage($msg = '') {
    if ( $msg != '' ) {
      echo "!!! ".trim($msg)."\n\n";
    }

    echo "Usage: {$_SERVER['argv'][0]} [--quiet|-q] [--verbose|-v] [--help|-h] init|sync\n";
    echo " with:    init [--temporary-path <temporary path>] <hg repository> <svn repository>\n";
    echo " with:    sync [--stop-at <revision>] <svn repository>\n";

    exit(1);
  }

  function sort_todo_array($a, $b) {
    static $priorities = null;
    if ( is_null($priorities) ) {
      $priorities = array_flip( array('change_chmod', 'add', 'delete', 'copy', 'rename', 'symlink', 'update') );
    }

    $aa = $a['action'];
    $ba = $b['action'];

    $res = null;
    if ( $aa == $ba ) {
      $res = 0;
    }
    else if ( !array_key_exists($aa, $priorities) ) {
      cout("Invalid sorting action '{$aa}'", VERBOSE_ERROR);
      exit(1);
    }
    else if ( !array_key_exists($ba, $priorities) ) {
      cout("Invalid sorting action '{$ba}'", VERBOSE_ERROR);
      exit(1);
    }
    else if ( $priorities[ $aa ] < $priorities[ $ba ] ) {
      $res = -1;
    }
    else {
      $res = 1;
    }

    cout( "Sorting '{$aa}' <-> '{$ba}': {$res}", VERBOSE_DEBUG );
    return $res;
  }

  /**
  * Creates the directory structures needed for hg/svn work
  * 
  * @returns string temporary directory name to be used
  */
  function create_and_check_directory_structure($use_this_dir = null) {
    $tempnam = is_null($use_this_dir) ? tempnam(CACHING_DIR, 'hg2svn') : $use_this_dir;
    touch($tempnam);
    define('TMP_DIR', $tempnam);

    @mkdir($tempnam.'_hg', 0755, true);
    @mkdir($tempnam.'_svn', 0755, true);

    if ( !is_writable($tempnam.'_hg')  || !is_dir($tempnam.'_hg') || 
         !is_writable($tempnam.'_svn') || !is_dir($tempnam.'_svn') ) {
      cout('Temporary directories are unavailable!', VERBOSE_ERROR);
      exit(1);
    }

    return $tempnam;
  }
    
  function cout($message, $level = VERBOSE_NORMAL) {
    if ($level < VERBOSE_OUTPUT) {
      return;
    }

    $add = '';
    switch( $level ) {
    case VERBOSE_ERROR:   $add = '[ ERROR ] '; break;
    case VERBOSE_WARNING: $add = '[WARNING] '; break;
    case VERBOSE_NORMAL:  $add = '';           break;
    case VERBOSE_INFO:    $add = '[ INFO  ] '; break;
    case VERBOSE_DEBUG:   $add = '[ DEBUG ] '; break;
    case VERBOSE_TRACE:   $add = '[ TRACE ] '; break;
    }

    echo $add . $message . "\n";
    flush();
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

    cout("Executing '{$cmd}'", VERBOSE_DEBUG);

    exec($cmd . ' 2>&1', $output, $return_var);
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
    $out = safe_exec('svn proplist --revprop -r 0 '.escapeshellarg($to_svn_repo));
    return array_diff(array_map('trim', explode("\n", $out)), array('', 'Unversioned properties on revision 0:'));
  }

  function parse_hg_log_message_sub(&$ret, $current_name, $current_value) {
    if ( $current_name === '' ) {
      return;
    }

    if ( array_key_exists($current_name, $ret) ) {
      if ( !is_array($ret[$current_name]) ) {
        $ret[$current_name] = array($ret[$current_name]);
      }
      $ret[$current_name][] = trim($current_value);
    }
    else {
      $ret[$current_name] = trim($current_value);
    }
  }

  function parse_hg_log_message($revision) {
    $out = explode("\n", safe_exec("hg -v log -r {$revision}"));
    $ret = array();
    $current_name  = '';
    $current_value = '';
    foreach( $out as $line ) {
      if ( preg_match('|^\s*([a-z]+)\s*:(.*)$|iUxms', $line, $m) > 0 ) {
        parse_hg_log_message_sub($ret, $current_name, $current_value);
        $current_name  = trim($m[1]);
        $current_value = $m[2];
      }
      else {
        $current_value .= "\n{$line}";
      }
    }

    parse_hg_log_message_sub($ret, $current_name, $current_value);

    return $ret;
  }

  function my_getline(&$fp) {
    $line = fgets($fp);

    if ( substr($line, -1) === "\n" ) {
      return substr($line, 0, -1);
    }
    else {
      return $line;
    }
  }

  function add_and_rework_diff(&$todo, $last_action) {
    if ( count($last_action) == 0 ) {
      return;
    }

    if ( isset($last_action['binary_patch']) ) {
      // decode binary patch
      $last_action['patch'] = decode_85($last_action['patch']);
      // check size
      if ( strlen($last_action['patch']) != $last_action['binary_patch'] ) {
        throw new Exception('failed to decode binary patch!');
      }
    }
    else if ( isset($last_action['patch']) ) {
      $last_action['patch'] = str_replace("\n\\ No newline at end of file", '', $last_action['patch']);
    }

    $is_special_file = false;
    if ( $last_action['action'] == 'update' ) {
      // Check if the target file is a 'special file'.
      chdir(TMP_DIR.'_svn');
      $res = safe_exec('svn propget svn:special '.escapeshellarg($last_action['file1']));
      $is_special_file = ($res !== '');
      chdir(TMP_DIR.'_hg');
    }

    if ( ($last_action['action'] == 'symlink') || (($last_action['action'] == 'update') && $is_special_file) ) {
      $last_action['action'] = 'symlink';

      $patch = array_slice(explode("\n", $last_action['patch']), 3);
      unset($last_action['patch']);

      foreach( $patch as $patch_line ) {
        if ( substr($patch_line, 0, 1) == '+' ) {
          $last_action['file2'] = substr($patch_line, 1);
          break;
        }
      }
    }

    if ( IS_WINDOWS && isset($last_action['patch']) && !isset($last_action['binary_patch']) ) {
      // We need to change "\n" in the patch to "\r\n" otherwise patch will cause 'Assertion failed: hunk, file ../patch-2.5.9-src/patch.c, line 354'
      $last_action['patch'] = str_replace("\n", "\r\n", $last_action['patch']);
    }

    $todo[] = $last_action;
  }

  function parse_chmod($str) {
    $res = 0;
    for ( $i = 0; $i < strlen($str); ++$i ) {
      $res = $res * 8 + substr($str, $i, 1);
    }
    return $res;
  }

  function interpret_binary_patch(&$fp, &$last_action) {
    if ( my_getline($fp) != 'GIT binary patch' ) {
      throw new Exception("Invalid order of things?!");
    }
    $line = my_getline($fp);
    if ( substr($line, 0, 8) != 'literal ' ) {
      throw new Exception("Invalid order (expected 'literal')");
    }
    $last_action['binary_patch'] = substr($line, 8);
  }

  function parse_hg_diff($revision) {
    # Here I do work via files because this diff can become quite huge. The result is returned in an array nevertheless
    $tmp = tempnam('/tmp', 'hg2svn');
    safe_exec("hg diff -c{$revision} -g > {$tmp}");

    $fp = fopen($tmp, 'rb');

    $todo           = array();
    $last_action    = array();
    $next_is_action = false;
    $next_is_patch  = false;
    while ( !feof($fp) ) {
      $line = my_getline($fp);
      if ( $line === false ) {
        continue; // Prolly eof!
      }

#      echo "Line: {$line}\n";
      if ( preg_match('|^diff --git a/(.*) b/(.*)$|iU', $line, $matches) > 0 ) {
        // New entry between ... and ...
        add_and_rework_diff($todo, $last_action);
        $last_action    = array('file1' => $matches[1], 'file2' => $matches[2], 'patch' => '');
        $next_is_action = true;
        $next_is_patch  = false;
      }
      else if ( $next_is_action ) {
        if ( substr($line, 0, 4) == '--- ' ) {
          $last_action['action'] = 'update';
          $last_action['patch'] = $line . "\n";
        }
        else if ( substr($line, 0, 16) == 'new file mode 12' ) {
          # Hardlinks are reported as normal files
          # Directory symlinks work the same as file symlinks
          $last_action['action'] = 'symlink';
          $last_action['chmod'] = parse_chmod(substr($line, -4));
        }
        else if ( substr($line, 0, 16) == 'new file mode 10' ) {
          $last_action['action'] = 'add';
          $last_action['chmod'] = parse_chmod(substr($line, -4));
        }
        else if ( substr($line, 0, 18) == 'deleted file mode ' ) {
          $last_action['action'] = 'delete';
          $last_action['chmod'] = parse_chmod(substr($line, -4));
        }
        else if ( substr($line, 0, 12) == 'rename from ' ) {
          $last_action['action'] = 'rename';
          $last_action['from']   = substr($line, 12);
          $line = my_getline($fp);

          if ( substr($line, 0, 10) != 'rename to ' ) {
            throw new Exception("Expected 'rename to'");
          }
          $last_action['to'] = substr($line, 10);
        }
        else if ( substr($line, 0, 10) == 'copy from ' ) {
          $last_action['action'] = 'copy';
          $last_action['from']   = substr($line, 10);
          $line = my_getline($fp);

          if ( substr($line, 0, 8) != 'copy to ' ) {
            throw new Exception("Expected 'copy to'");
          }
          $last_action['to'] = substr($line, 8);
        }
        else if ( substr($line, 0, 6) == 'index ' ) {
          $last_action['action'] = 'update';
          interpret_binary_patch($fp, $last_action);
        }
        else if ( substr($line, 0, 9) == 'old mode ' ) {
          $last_action['action'] = 'change_chmod';

          $line = my_getline($fp);
          if ( substr($line, 0, 9) != 'new mode ' ) {
            throw new Exception("Expected 'new mode'");
          }
          $last_action['chmod'] = parse_chmod(substr($line, -4));
        }
        else {
          throw new Exception("Invalid action-line '{$line}'");
        }

        $next_is_patch = true;
        $next_is_action = false;
      }
      else if ( $next_is_patch && (substr($line, 0, 6) == 'index ') ) {
        interpret_binary_patch($fp, $last_action);
        $next_is_patch = true;
      }
      else if ( $next_is_patch ) {
        $last_action['patch'] .= $line . "\n";
      }
      else {
        throw new Exception("Unexpected line '{$line}'");
      }
    }
    fclose($fp);

    add_and_rework_diff($todo, $last_action);

    unlink($tmp);

    usort($todo, 'sort_todo_array');
    return $todo;
  }

  function patch_file( $patch ) {
    if ( !isset($patch['patch']) || ((strlen($patch['patch']) == 0) && ($patch['action'] != 'add')) ) {
      return;
    }

    if ( !isset($patch['binary_patch']) ) {
      if ( in_array($patch['action'], array('copy', 'rename')) ) {
        # Adjust the from file in the patch header
        $a = explode("\n", $patch['patch']);
        $a[0] = '--- a' . substr($a[1], 5);
        $patch['patch'] = implode("\n", $a);
      }

      if ( $patch['action'] == 'add' ) { // In case you add an empty file
        @mkdir(dirname($patch['file1']), 0755, true);
        touch($patch['file1']);
      }

      $tmp = tempnam('/tmp', 'hg2svn');
      file_put_contents($tmp, $patch['patch']);
      // Patch does also handle the creation of subdirectories if needed.
      safe_exec('patch -p1 < '.escapeshellarg($tmp));
      @unlink($tmp);
    }
    else {
      create_dir_in_svn(dirname($patch['file1']));
      if ( in_array($patch['action'], array('copy', 'rename')) ) {
        file_put_contents($patch['to'], $patch['patch']);
      }
      else {
        file_put_contents($patch['file1'], $patch['patch']);
      }
    }
    if ( isset($patch['chmod']) ) {
      chmod($patch['file1'], $patch['chmod']);
    }
    if ( $patch['action'] == 'add' ) {
      safe_exec('svn add --parents '.escapeshellarg($patch['file1']));
    }
  }

  function create_dir_in_svn($dir) {
    if ( $dir == '.' ) {
      return;
    }

    @mkdir($dir, 0755, true);
    if ( !is_dir($dir.'/.svn') ) {
      safe_exec('svn add --parents '.escapeshellarg($dir));
    }
  }

  function remove_file_step2($item) {
    $item = dirname($item);
    if ( $item == '.' ) {
      return;
    }

    if ( @rmdir($item) ) {
      remove_file_step2($item);
    }
  }

  function remove_dirtree_if_empty($path, $prev_path = null) {
    cout("remove_dirtree_if_empty(path: '{$path}', prev_path: '{$prev_path}')", VERBOSE_TRACE);
    if ( $path == '.' ) {
      return;
    }

    if ( !in_array(substr($path, -1), array('/', '\\')) ) {
      $path .= DIRECTORY_SEPARATOR;
    }

    $d = opendir($path);
    $entries = array();
    while ( ($e=readdir($d)) !== false ) {
      if ( in_array($e, array('.', '..', '.svn')) ) {
        continue;
      }
      else if ( $prev_path === $e ) {
        continue;
      }

      $status = safe_exec('svn status --depth=empty '.escapeshellarg($path.$e));
      if ( substr($status, 0, 1) == 'D' ) {
        continue;
      }

      $entries[] = $e;
      break; // 1 entry is enough to stop this process
    }
    closedir($d);

    if ( count($entries) != 0 ) {
      return;
    }

    safe_exec('svn remove '.escapeshellarg($path));
    remove_dirtree_if_empty(dirname($path), basename($path));
  }

  /**
  * This function removes a file from svn and any empty directories up the tree
  * 
  * @param string $filename
  */
  function remove_item( $item ) {
    if ( !is_dir($item) ) {
      safe_exec('svn remove '.escapeshellarg($item));
      $item = dirname($item);
    }

    remove_dirtree_if_empty($item);
  }


  function decode_85($buffer) {
    $en85 = array(
      '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
      'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
      'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T',
      'U', 'V', 'W', 'X', 'Y', 'Z',
      'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j',
      'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't',
      'u', 'v', 'w', 'x', 'y', 'z',
      '!', '#', '$', '%', '&', '(', ')', '*', '+', '-',
      ';', '<', '=', '>', '?', '@', '^', '_',  '`', '{',
      '|', '}', '~'
    );

    static $de85 = array();
    if ( count($de85) == 0 ) {
      foreach( $en85 as $i => $ch ) {
        $de85[ $ch ] = $i+1;
      }
    }

    $buffer = explode("\n", str_replace("\r\n", "\n", $buffer));

    $dec = '';
    foreach( $buffer as $line ) {
      $dec .= decode_85_sub($de85, $line);
    }
    return gzuncompress($dec);
  }

  function decode_85_sub($de85, $buffer) {
    $len = substr($buffer, 0, 1);
    if ( ('A' <= $len) && ($len <= 'Z') ) {
      $len = ord($len) - ord('A') + 1;
    }
    else if ( ('a' <= $len) && ($len <= 'z') ) {
      $len = ord($len) - ord('a') + 27;
    }
    else {
      $len = strlen($buffer);
    }

    $len_orig = $len;

    $buffer = substr($buffer, 1);

    $i = 0;
    $dst = '';

    while ( $len > 0 ) {
      $acc = 0;
      $cnt = 4;
      $ch = null;
      do {
        $ch = substr($buffer, $i, 1);
        ++$i;
        $de = $de85[$ch];
        if (--$de < 0) {
          throw new Exception("invalid base85 alphabet '{$ch}'");
        }
        $acc = $acc * 85 + $de;
      } while (--$cnt);

      $ch = $buffer{$i++};
      $de = $de85[$ch];
      if (--$de < 0) {
        throw new Exception("invalid base85 alphabet '{$ch}'");
      }

      // Detect overflow.
      if (0xffffffff / 85 < $acc || 0xffffffff - $de < ($acc *= 85)) {
        throw new Exception("invalid base85 sequence '".substr($buffer, $i-5,5)."'");
      }

      $acc += $de;


      $cnt = ($len < 4) ? $len : 4;
      $len -= $cnt;
      do {
        $acc = ($acc << 8) | (($acc >> 24) & 0xFF);
        $ch = chr($acc & 0xFF);
        $dst .= $ch;
      } while (--$cnt);
    }

    return substr($dst, 0, $len_orig);
  }

  function get_tip_revision() {
    $out = safe_exec('hg tip');
    if ( preg_match('|^\s*changeset\s*:\s*([0-9]+)\s*:\s*([0-9a-f]+)\s*$|iUxms', $out, $matches) > 0 ) {
      return $matches[1];
    }
    else {
      return false;
    }
  }
  
  function completely_remove($item) {
    if ( !is_dir($item) ) {
      return @unlink($item);
    }

    if ( substr($item, -1) != '/' ) {
      $item .= '/';
    }

    $d = @opendir($item);
    if ( $d === false ) {
      return false;
    }

    $retval = true;
    while ( ($e=readdir($d)) !== false ) {
      if ( in_array($e, array('.', '..')) ) {
        continue;
      }

      if ( !completely_remove($item . $e) ) {
        $retval = false;
      }
    }
    closedir($d);
    if ( !@rmdir($item) ) {
      return false;
    }
    else {
      return $retval;
    }
  }

  function force_remove_item($item) {
    if ( is_dir($item) ) {
      if ( !in_array(substr($item, -1), array('/', "\\")) ) {
        $item .= '/';
      }
      $d = opendir($item);
      while( ($e=readdir($d)) !== false ) {
        if ( in_array($e, array('.', '..')) ) {
          continue;
        }

        if ( is_dir($item.$e) ) {
          force_remove_item($item.$e);
        }
        else {
          unlink($item.$e);
        }
      }
      closedir($d);
      rmdir($item);
    }
    else {
      unlink($item);
    }
  }
