  $program_not_found = false;
  foreach( array('svn' => 'svn --version', 'hg' => 'hg --version', 'patch' => 'patch --version') as $program => $checking_line ) {
    exec($checking_line.' 2>&1', $out, $ret);
    if ( $ret != 0 ) {
      cout("Cannot find '{$program}', please install it!");
      $program_not_found = true;
  if ( $program_not_found ) {
    exit(1);
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