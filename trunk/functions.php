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
  $program_not_found = false;
  foreach( array('svn' => 'svn --version', 'hg' => 'hg --version', 'patch' => 'patch --version') as $program => $checking_line ) {
    exec($checking_line.' 2>&1', $out, $ret);
    if ( $ret != 0 ) {
      cout("Cannot find '{$program}', please install it!");
      $program_not_found = true;
    }
  }
  if ( $program_not_found ) {
    exit(1);
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
    
  function cout($message, $level = VERBOSE_NORMAL) {
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
      cout(" -> return code: {$return_var}.", VERBOSE_HIGH);
      cout(" -> generated output:\n{$output}", VERBOSE_DEBUG);
      exit($return_var);
    }

    return $output;
  }

  function get_revision_properties($to_svn_repo) {
    $out = safe_exec('svn proplist --revprop -r 0 '.escapeshellarg($to_svn_repo).' | grep -v "Unversioned properties on revision 0"');
    return array_diff(array_map('trim', explode("\n", $out)), array(''));
  }

  function parse_hg_log_message($revision) {
    $out = explode("\n", safe_exec("hg -v log -r {$revision}"));
    $ret = array();
    $current_name  = '';
    $current_value = '';
    foreach( $out as $line ) {
      if ( preg_match('^\s*([a-z]+)\s*:(.*)$', $line, $m) > 0 ) {
        if ( $current_name != '' ) {
          $ret[$current_name] = $current_value;
        }
        $current_name  = trim($m[1]);
        $current_value = $m[2];
      }
      else {
        $current_value .= "\n{$line}";
      }
    }

    if ( $current_name != '' ) {
      $ret[$current_name] = $current_value;
    }

    return array_map('rtrim', $ret);
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
        if ( count($last_action) > 0 ) {
          if ( isset($last_action['binary_patch']) ) {
            // decode binary patch
            $patch['patch'] = decode_85($patch['patch']);
            // check size
            if ( strlen($patch['patch']) != $last_action['binary_patch'] ) {
              throw new Exception('failed to decode binary patch!');
            }
          }
          else if ( isset($last_action['patch']) ) {
            $last_action['patch'] = str_replace("\n\\ No newline at end of file", '', $last_action['patch']);
          }

          if ( $last_action['action'] == 'symlink' ) {
            $patch = array_slice(explode("\n", $last_action['patch']), 3);
            unset($last_action['patch']);

            foreach( $patch as $patch_line ) {
              if ( substr($patch_line, 0, 1) == '+' ) {
                $last_action['file2'] = substr($patch_line, 1);
                break;
              }
            }
          }

          $todo[] = $last_action;
        }
        $last_action    = array('file1' => $matches[1], 'file2' => $matches[2]);
        $next_is_action = true;
        $next_is_patch  = false;
      }
      else if ( $next_is_action ) {
        if ( substr($line, 0, 4) == '--- ' ) {
          $last_action['action'] = 'update';
          $last_action['patch'] = $line;
        }
        else if ( substr($line, 0, 17) == 'new file mode 120' ) {
          # Hardlinks are reported as normal files
          # Directory symlinks work the same as file symlinks
          $last_action['action'] = 'symlink';
          $last_action['patch'] = '';
        }
        else if ( substr($line, 0, 17) == 'new file mode 100' ) {
          $last_action['action'] = 'add';
          $last_action['chmod'] = substr($line, 17);
          $last_action['patch'] = '';
        }
        else if ( substr($line, 0, 18) == 'deleted file mode ' ) {
          $last_action['action'] = 'delete';
          $last_action['chmod'] = substr($line, 18);
          $last_action['patch'] = '';
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
        else {
          throw new Exception("Invalid action-line '{$line}'");
        }
        $next_is_patch = true;
        $next_is_action = false;
      }
      else if ( $next_is_patch && (substr($line, 0, 6) == 'index ') ) {
        if ( my_getline($fp) != 'GIT binary patch' ) {
          throw new Exception("Invalid order of things?!");
        }
        $line = my_getline($fp);
        if ( substr($line, 0, 8) != 'literal ' ) {
          throw new Exception("Invalid order (expected 'literal')");
        }
        $last_action['binary_patch'] = substr($line, 8);
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

    unlink($tmp);

    return $todo;
  }

  /**
  * This function removes a file from svn and any empty directories up the tree
  * 
  * @param string $filename
  */
  function remove_file( $filename ) {
    safe_exec('svn remove '.escapeshellarg($filename));

    $prev_path = null;
    $path = dirname($filename);
    while ( $path != '.' ) {
      $d = opendir($path);
      $entries = array();
      while ( ($e=readdir($d)) !== false ) {
        if ( in_array($e, array('.', '..', '.svn')) ) {
          continue;
        }

        if ( !is_null($prev_path) && (basename($prev_path) == $e) ) {
          continue;
        }

        $entries[] = $e;
      }

      if ( count($entries) == 0 ) {
        safe_exec('svn remove '.escapeshellarg($path));
        $prev_path = $path;
        $path = dirname($path);
      }
      else {
        break;
      }
    }
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
    if ( preg_match('|^\s*changeset\s*:\s*([0-9]+)\s*:\s*([0-9a-f]+)\s*$|iU', $out, $matches) > 0 ) {
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
