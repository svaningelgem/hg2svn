#!/bin/bash 

# Taken from: http://qa-ex-consultant.blogspot.com/2009/10/converting-mercurial-repo-to-subversion.html and http://techdiary.peterbecker.de/2009/02/moving-from-mercurial-to-subversion.html
# Credit where deserved.

# DANGER: written as a once-off script, not suitable for naive consumption. Use at
# your own peril. It has been tested on one case only.
#
# Potential issues for reuse:
# * no sanity checks anywhere (don't call it with the wrong parameters)
# * no handling of branches
# * certain layout of the results of hg commands is assumed
# * all commits come from the user running the script
# * move operations probably won't appear in the history as such
# * we assume the SVN side doesn't change
#

HG_SOURCE=$1   # the source is a normal hg repository
SVN_TARGET=$2  # the target is a folder within a SVN working copy (e.g. /trunk)

export QUIET_FLAG= # -q for quiet, empty for verbose

echo Converting Mercurial repository at $HG_SOURCE into Subversion working copy at $SVN_TARGET

# Turn the SVN location into a mercurial one
pushd $SVN_TARGET
hg init .

# Get the revision counts (Overridable with START_REV, STOP_REV so you can restart if something bad happens)
pushd $HG_SOURCE
TIP_REV=`hg tip | head -1 | sed -e "s/[^ ]* *\([^:]*\)/\1/g"`
STOP_REV=$TIP_REV
START_REV=0
popd # out of $HG_SOURCE

# Pull all the changes from the mercurial repo, don't update though (helps with 
hg $QUIET_FLAG pull -r $TIP_REV $HG_SOURCE

for i in `seq $START_REV $STOP_REV`
do
    echo "Fetching Mercurial revision $i/$TIP_REV"
    hg $QUIET_FLAG update -C -r $i
    # Parse out the incoming log message
    HG_LOG_MESSAGE=`hg -R $HG_SOURCE -v log -r $i | grep -A10 ^description:$ | grep -v ^description:$ | head --lines=-2` 
    HG_LOG_CHANGESET=`hg -R $HG_SOURCE -v log -r $i | grep ^changeset: | head -1`
    HG_LOG_USER=`hg -R $HG_SOURCE -v log -r $i | grep ^user: | head -1`
    HG_LOG_DATE=`hg -R $HG_SOURCE -v log -r $i | grep ^date: | head -1`

echo "MessagePIETER: $HG_LOG_MESSAGE"
    
    echo "- removing deleted files"
    svn status | grep '^!' | sed -e 's/^! *\(.*\)/\1/g' | while read fileToRemove
    do
        svn remove $QUIET_FLAG "$fileToRemove"
    done
    
    echo "- removing empty directories" # needed since Mercurial doesn't manage directories
    find . -name '.svn' -prune -o -type d -printf '%p+++' -exec ls -am {} \; | grep '., .., .svn$' | sed -e 's/^\(.*\)+++.*/\1/g' | while read dirToRemove
    do
        rm -rf "$dirToRemove" # remove first, otherwise working copy is broken for some reason only SVN knows
        svn remove $QUIET_FLAG "$dirToRemove"
    done
    
    echo "- adding files to SVN control"
    # 'svn add' recurses and snags .hg* files, we are pulling those out, so do our own recursion (slower but more stable)
    #   This is mostly important if you have sub-sites, as they each have a large .hg file in them
    while [ `svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | wc -l` -gt 0 ]
    do
        svn status | grep '^\?' | grep -v '[ /].hg\(tags\|ignore\|sub\|substate\)\?\b' | sed -e 's/^\? *\(.*\)/\1/g' | while read fileToAdd
        do
            if [ -d "$fileToAdd" ]
            then
                # Mercurial seems to copy existing directories on moves or something like that -- we
                # definitely get some .svn subdirectories in newly created directories if the original
                # action was a move. New directories should never contain a .svn folder since that breaks
                # SVN 
                find "$fileToAdd" -type d -name ".svn" | while read accidentalSvnFolder
                do
                    rm -rf "$accidentalSvnFolder"
                done
            fi
            # Using --depth empty here, so we don't recurisivly add, we are taking care of that ourselves.
            svn add $QUIET_FLAG --depth empty "$fileToAdd"
        done
    done
    
    echo "- committing"
    # This section is here to specificly fix symlinks that get swapped between symlinks and real files
    #  But could be extended to catch any commit errors you can't know about until you try to submit
    # NOTE: This is a "done << EOF" style while loop so we can have a controlling variable to exit the loop
    #       otherwise NEED_COMMIT can't be modified in the body of the loop (bash pipe thingie)
    NEED_COMMIT=true
    while $NEED_COMMIT
    do
        NEED_COMMIT=false
        SVN_COMMIT_RESULT=`svn commit $QUIET_FLAG -m "$HG_LOG_MESSAGE"`
        #SVN_COMMIT_RESULT=`svn ci $QUIET_FLAG -m "$HG_LOG_CHANGESET $HG_LOG_USER $HG_LOG_DATE $HG_LOG_MESSAGE" 2>&1`
        echo $SVN_COMMIT_RESULT
        
        while read needFixin;
        do
            if [ "$needFixin" != "" ]
            then
                svn pd svn:special $needFixin
                NEED_COMMIT=true
            fi
        done << EOF
$(echo "$SVN_COMMIT_RESULT" | grep "has unexpectedly changed special status" | sed -e 's/^svn: Entry \x27\(.*\)\x27 has unexpectedly .*$/\1/g')
EOF
    done
    echo "- done"
done

popd # out of $SVN_TARGET
