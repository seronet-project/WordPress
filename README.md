## Wordpress on Cloud Foundry
```
echo -e "<?php\n" > wp-keys.php
wget https://api.wordpress.org/secret-key/1.1/salt/ -O - >> wp-keys.php
cf push --no-start
cf cs nfs Existing wpvol -c '{\"share\":\"...\"}'
cf bs wp wpvol -c '{\"uid\":\"1000\",\"gid\":\"1000\",\"mount\":\"/home/vcap/app/files\"}'
cf wp start
´´´
