# Introduction #

Maybe you came here looking for the same reasons as I made this script? You have an external linked into your subversion repository and suddenly they decide to switch to mercurial. Or maybe you just want to have the hg repository in your local svn repository list as a way to safekeep it (backups anyone?). Well anyway, this app might suite your needs


# Details #

Well, most importantly: how to use it?

  1. set up an svn repository as usual. See that only you have write access to it. This is important because it's a one way sync. Hence the name mercurial to subversion!
  1. Add a hook "pre-revprop-change":
```
#!/bin/sh
exit 0;
```
  1. Make the hook executable (for windows users create a .bat file with the previous name & don't add a thing in it)
  1. Call this script like: php hg2svn.php init <hg repository> <svn repository>
**!! Both hg & svn repositories should have the username & password in it.**
  1. Start the sync: php hg2svn.php sync <svn repository>
**!! Again add the username & password in the svn url.**



---


If you like to see inner workings add
--verbose: once for information
--verbose: twice for debug information
--quiet: for less information
--help: for the usage screen
--stop-at: to stop at the specified (numeric) revision.
--temporary-path: temporary working path (default /var/lib/hg2svn)