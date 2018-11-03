#!/bin/bash

# set path of where NFS partition is mounted
MOUNT_FOLDER="/home/vcap/app/files"

# set name of folder in which to store files on the NFS partition
WPCONTENT_FOLDER="$(echo $VCAP_APPLICATION | jq -r .application_name)"

# Does the WPCONTENT_FOLDER exist under MOUNT_FOLDER? If not seed it.
TARGET="$MOUNT_FOLDER/$WPCONTENT_FOLDER"
if [ ! -d "$TARGET" ]; then
echo "First run, moving default WordPress files to the remote volume"
mv "/home/vcap/app/wordpress/wp-content-orig" "$TARGET"
ln -s "$TARGET" "/home/vcap/app/wordpress/wp-content"

# Write warning to remote folder
echo "!! WARNING !! DO NOT EDIT FILES IN THIS DIRECTORY!!" > \
"$TARGET/WARNING_DO_NOT_EDIT_THIS_DIRECTORY"
else
ln -s "$TARGET" "/home/vcap/app/wordpress/wp-content"
rm -rf "/home/vcap/app/wordpress/wp-content-orig" # we don't need this
fi