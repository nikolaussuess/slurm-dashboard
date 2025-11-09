# Slurm dashboard
This is a small dashboard that displays some information about a slurm cluster. It is written in PHP, using `slurmrestd`.

It currently supports the following `slurmrestd` openAPI versions:
- `v0.0.40` (supported in Slurm versions 23.11 until excl. 25.11)
- `v0.0.43` (supported in Slurm versions 25.05 until excl. 27.05)

Unless you explicitly override the constant `REST_API_VERSION` from `"auto"` to another value (e.g., `v0.0.40`) in `globals.inc.php`, the dashboard will dynamically choose the latest API version that is supported by both the dashboard and `slurmrestd`. This behavior is, however, only supported by the more recent slurm versions.

![Resource overview](./imgs/resources.png?raw=true "Resource overview")

Currently tested with the following Slurm versions:
- 23.11.x (tested with version `<= v1.0.0` only but should still work)
- 24.05.x
- 25.05.x (supported by version `> v1.0.0` only)

However, any slurm version `>= 23.11.x` should work. If you encounter any issues, please write a bug report.

## Current features
- display cluster usage information (state, CPUs, memory, gpus per node)
- display the current job queue, including a job detail page
- authentication via LDAP or local, and it additionally checks whether the user exists in the accounting database
- communication with `slurmrestd` via unix socket
- caching of the `slurmrestd` responses (using APCu)
- display and filter job history (from `slurmdbd`)
- display list of users with their respective accounts (for administrators and privileged users only)
- show planned maintenances (`flags=maint`) that were created with e.g. `scontrol create reservation starttime=2024-11-02T13:30:00 duration=5 user=root flags=maint nodes=mynode`

![Job queue](./imgs/queue.png?raw=true "Job queue")

![Job history](./imgs/job_history.png?raw=true "Job history")

### Planned features
- Authentication (at `slurmrestd`) via JWT.
- Allow administrators to requeue / cancel / ... jobs.
- Allow users to requeue and cancel their own jobs.
- Allow users to submit jobs via a web interface.

But currently none of these features is under active development.

## Requirements
- PHP (currently only tested with PHP 8.2)<br>
  including
  - `php-apcu` for caching `slurmrestd` responses
  - `php-ldap` for LDAP authentication (if you are using LDAP; optional)
  - `php-ssh2` for local authentication (if you are using local authentication; optional)
- Apache2<br>
  including
  - `libapache2-mpm-itk` in order to change to user to the same `slurmrestd` is running with.
  - `libapache2-mod-php`
- `slurmrestd` using Unix sockets, running with the same user that the website is running with.

## Setup
1. Install the requirements.
2. Add a unix user (and group) `slurm-dashboard`, preferably in the system UID range (e.g. 994 or 996).
3. Adapt the `slurmrestd` unit file (e.g. `/etc/systemd/slurmrestd.service.d/slurmrestd.conf`) like e.g.:
```
[Service]
# Unset vendor unit ExecStart to avoid cumulative definition
ExecStart=
Environment=
# Disable slurm user security check - might be required.
#Environment=SLURMRESTD_SECURITY=disable_user_check
ExecStart=/usr/local/sbin/slurmrestd $SLURMRESTD_OPTIONS unix:/run/slurmrestd/slurmrestd.socket
RuntimeDirectory=slurmrestd
RuntimeDirectoryMode=0755
User=slurm-dashboard
Group=slurm-dashboard
```
4. Add correct user to the apache vhost (e.g. `/etc/apache2/sites-enabled/slurm-dashboard.conf`):
```
<VirtualHost *:80>
    ...
    AssignUserId slurm-dashboard slurm-dashboard
    ...
</VirtualHost>
```
5. Optionally, enable SSL etc.
6. Adapt `CLUSTER_NAME` and the other constants in `globals.inc.php`.
7. If you use LDAP authentication: Adapt parameters in `auth/auth.ldap.inc.php`.
8. If you use local authentication: Adapt parameters in `auth/auth.local.inc.php`.
9. Start or restart the services:
```
systemctl enable --now slurmrestd
systemctl enable --now apache2
# or
systemctl restart slurmrestd
systemctl restart apache2
```
10. You might also want to edit the default page in `templates/about.html`.

### Update
Once you have set up the dashboard, you can use the following bash script to upgrade to a new version. (It might also be helpful for the initial setup.)

```bash
#!/bin/bash

# TODO: REPLACE THE FOLLOWING VARIABLES
# Replace by your web directory
WEB_DIRECTORY="/var/www/slurm-dashboard"
DASHBOARD_USER="slurm-dashboard"
SLURM_USER="slurm"
SLURM_LOGIN_NODE="slurm.example.com"
CLUSTER_NAME="Test cluster"
ADMIN_NAMES="[should contain contact info]"
ADMIN_EMAIL="[should contain an email address - publicly available]"
WIKI_LINK="https://example.com/my-slurm-wiki"

LDAP_URI='dc.example.com'
LDAP_BASE='cn=users,dc=i,dc=example,dc=com'
LDAP_ADMIN_USER='ldapuser'
LDAP_ADMIN_PASSWORD='replaceme'

SSH_SERVER_URL='localhost'
# END OF REPLACE-ME

# PRODUCTIVE INSTANCE
tput setab 1
echo -n "                                                  "
tput sgr0
echo 
tput setab 1
echo -n "        upgrading productive instance             "
tput sgr0
echo
tput setab 1
echo -n "                                                  "
tput sgr0
echo 
read -p "Are you sure that you want to update the productive instance? " -n 1 -r
echo    # (optional) move to a new line
if [[ $REPLY =~ ^[Yy]$ ]]; then

    pushd "$WEB_DIRECTORY"

    if [ "`pwd`" != "$WEB_DIRECTORY" ]; then
        tput bold
        tput setaf 1
        echo -n "[ERROR] " >&2
        tput sgr0
        echo "Wrong directory." >&2
        exit 1

    fi

    if [ "$USER" != "$DASHBOARD_USER" ]; then
        tput bold
        tput setaf 1
        echo -n "[ERROR] " >&2
        tput sgr0
        echo "MUST be logged in as user $DASHBOARD_USER." >&2
        echo "Try to replace user ..."
        exec su -s /bin/bash $DASHBOARD_USER "$0" -- "$@"
    fi

    if [ "$#" -eq 1 ]; then
        branch=$1
    else
        branch="master"
    fi

    echo "Pulling repository."
    set +x
    git config pull.rebase true
    git checkout -b $branch
    git branch --set-upstream-to=origin/$branch $branch
    git checkout .
    git pull -f

    set -x

    sed -i "s/const CLUSTER_NAME = '<TO BE REPLACED>';/const CLUSTER_NAME = '${CLUSTER_NAME}';/g" globals.inc.php
    sed -i "s#const ADMIN_NAMES = '<TO BE REPLACED>';#const ADMIN_NAMES = '${ADMIN_NAMES}';#g" globals.inc.php
    sed -i "s#const ADMIN_EMAIL = '<TO BE REPLACED>';#const ADMIN_EMAIL = '${ADMIN_EMAIL}';#g" globals.inc.php
    sed -i "s#const WIKI_LINK = '<TO BE REPLACED>';#const WIKI_LINK = '${WIKI_LINK}';#g" globals.inc.php
    sed -i "s#const SLURM_LOGIN_NODE = '<TO BE REPLACED>';#const SLURM_LOGIN_NODE = '${SLURM_LOGIN_NODE}';#g" globals.inc.php

    sed -i "s#const URI = TO_BE_REPLACED;#const URI = '${LDAP_URI}';#g" auth/auth.ldap.inc.php
    sed -i "s#const BASE = TO_BE_REPLACED;#const BASE = '${LDAP_BASE}';#g" auth/auth.ldap.inc.php
    sed -i "s#const ADMIN_USER = TO_BE_REPLACED;#const ADMIN_USER = '${LDAP_ADMIN_USER}';#g" auth/auth.ldap.inc.php
    sed -i "s!const ADMIN_PASSWORD = TO_BE_REPLACED;!const ADMIN_PASSWORD = '${LDAP_ADMIN_PASSWORD}" auth/auth.ldap.inc.php

    sed -i "s#private const SERVER_URL = TO_BE_REPLACED;#private const SERVER_URL = '${SSH_SERVER_URL}';#g" auth/auth.local.inc.php

    find $WEB_DIRECTORY -type f -exec chmod 640 {} \;
fi
```

## License
This project is currently licensed under the [MIT License](https://github.com/nikolaussuess/slurm-dashboard/blob/master/LICENSE).

## Contact
Nikolaus Süß, University of Vienna, Faculty of Computer Science, Research Group Visualization and Data Analysis, see <a href="https://ufind.univie.ac.at/de/person.html?id=109904">u:find</a>.

Feel free to add new features and create pull requests, or add issues.