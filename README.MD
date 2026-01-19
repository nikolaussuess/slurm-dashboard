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
- Display cluster usage information (state, CPUs, memory, gpus per node).
- Display the current job queue, including a job detail page.
- Authentication via LDAP or local, and it additionally checks whether the user exists in the accounting database.
- Communication with `slurmrestd` via unix socket.
- Caching of the `slurmrestd` responses (using APCu).
- Display and filter job history (from `slurmdbd`).
- Display list of users with their respective accounts (for administrators and privileged users only). Unprivileged users may only view their own user (incl. Fair-share value, accounts and so on).
- Show planned maintenances (`flags=maint`) that were created with e.g. `scontrol create reservation starttime=2024-11-02T13:30:00 duration=5 user=root flags=maint nodes=mynode`.
- List transitive dependencies for a job.
- Authentication (at `slurmrestd`) via JWT (optional).
- Allow administrators to requeue / cancel / ... jobs (requires JWT).
- Allow users to requeue and cancel their own jobs (requires JWT).
- Allow users to edit their own jobs and allow admins to edit all jobs (requires JWT).
- Allow administrators to `DRAIN` or undrain nodes (requires JWT).

![Job queue](./imgs/queue.png?raw=true "Job queue")

![Job history](./imgs/job_history.png?raw=true "Job history")

### Planned features
#### Currently not under active development
- Allow users to submit jobs via a web interface.
- Support TCP sockets (in addition to unix sockets).

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
1) Install the requirements.

2) Add a unix user (and group) `slurm-dashboard`, preferably in the system UID range (e.g. 994 or 996).

3) Now there are two options: With or without JWT authentication. Please note that if you decide not to use JWT, users will not be able to cancel or submit jobs.
  - If you do not want JWT authentication:
    - Adapt the `slurmrestd` unit file (e.g. `/etc/systemd/slurmrestd.service.d/slurmrestd.conf`) like e.g.:
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
    - Note that users will not be able to submit or cancel jobs (i.e., the dashboard is read-only).
 
  - If you want JWT authentication:
    - Follow the instructions [in the official documentation](https://slurm.schedmd.com/jwt.html). In short:
      - Make sure that you compiled SLURM with JWT and that you have `libjwt-dev` installed.
      - Generate a JWT key as follows.<br>
        Note: Instead of storing the key in `/var/spool/slurm/statesave` (or whatever `StateSaveLocation` is defined in `slurm.conf`), you might want to store the key e.g. in a `/var/lib/slurm-dashboard` directory instead. In this case, either specify the path in the `slurmrestd.service` explicitly, or make sure that the path exists and is the same on (a) the controller node where `slurmctld` is running, (b) the slurm database node with `slurmdbd` and (c) the node where the dashboard is running and (d) the node where `slurmrestd` is running (if not the same as (c)).
      ```
      dd if=/dev/random of=/var/spool/slurm/statesave/jwt_hs256.key bs=32 count=1
      chown slurm:slurm /var/spool/slurm/statesave/jwt_hs256.key
      chmod 0400 /var/spool/slurm/statesave/jwt_hs256.key
      chown slurm:slurm /var/spool/slurm/statesave
      chmod 0755 /var/spool/slurm/statesave
      ```
      - Enable JWT auth in both `slurm.conf` and `slurmdbd.conf` (adapt the path if necessary):
      ```
      AuthAltTypes=auth/jwt
      AuthAltParameters=jwt_key=/var/spool/slurm/statesave/jwt_hs256.key
      ```
      - Restart `slurmctl` and `slurmdbd` (e.g., with `scontrol reconfigure`) and look for errors. Reading the logs (`slurmctld.log`, `slurmdbd.log` and `/var/log/syslog`) might help. (While for `slurmctl` `scontrol reconfigure` seems to be sufficient, for `slurmdbd` it was not in my tests. So, please also run `systemctl restart slurmdbd`.)
    - Copy the key to the node where the dashboard is running. Use locations and permissions so that other users cannot read the key. For example, copy the key to `/var/lib/slurm-dashboard` and set the ownership:
    ```
    # Somehow copy the key, e.g. with scp ...
    scp ...
    mkdir -p /var/lib/slurm-dashboard
    mv jwt.key /var/lib/slurm-dashboard
    chown slurm-dashboard:slurm-dashboard /var/lib/slurm-dashboard
    chmod 644 /var/lib/slurm-dashboard
    chown slurm-dashboard:slurm-dashboard /var/lib/slurm-dashboard/jwt.key
    chmod 0400 /var/lib/slurm-dashboard/jwt.key
    ```
    - Configure `slurmrestd` to use JWT. Edit `/etc/systemd/system/slurmrestd.service` e.g. as follows:
    ```ini
    [Unit]
    Description=Slurm REST daemon
    After=network-online.target remote-fs.target slurmctld.service
    Wants=network-online.target
    ConditionPathExists=/usr/local/etc/slurm/slurm.conf
    
    [Service]
    # Unset vendor unit ExecStart to avoid cumulative definition
    ExecStart=
    Environment=
    Environment=SLURM_JWT=daemon
    ExecStart=/usr/local/sbin/slurmrestd $SLURMRESTD_OPTIONS -a rest_auth/jwt unix:/run/slurmrestd/slurmrestd.socket
    RuntimeDirectory=slurmrestd
    RuntimeDirectoryMode=0755
    User=slurm-dashboard
    Group=slurm-dashboard
    
    [Install]
    WantedBy=multi-user.target
    ```

4) Add correct user to the apache vhost (e.g. `/etc/apache2/sites-enabled/slurm-dashboard.conf`). Also set the environment variables accordingly to configure the dashboard. (Alternatively, you could also edit the `globals.inc.php` file to set them, but this is not recommended anymore.
```apacheconf
<VirtualHost *:80>
    ...
    # Assign user ID
    AssignUserId slurm-dashboard slurm-dashboard

    # Set DocumentRoot to subfolder 'public', e.g.:
    DocumentRoot /var/www/slurm-dashboard/public
    ...
    
    # REQUIRED PARAMETERS
    # Name of the cluster.
    SetEnv CLUSTER_NAME     "MyCluster"
    # Name of the admin. Publicly available in error messages.
    SetEnv ADMIN_NAMES      "John Doe"
    # Hostname of the login node.
    SetEnv SLURM_LOGIN_NODE "slurm01.example.com"
    # Link to a wiki page where one can find more info about the cluster.
    SetEnv WIKI_LINK        "https://wiki.example.com"
    
    # OPTIONAL REST API PARAMETERS
    # Unix is already the default connection mode
    # SetEnv CONNECTION_MODE "unix"
    # REST_API_VERSION must be set only if it cannot be auto detected
    # SetEnv REST_API_VERSION "v0.0.40"
    
    # OPTIONAL LDAP PARAMETERS
    # URI for LDAP server, e.g. 'dc.example.com'. OPTIONAL
    SetEnv LDAP_URI            "dc1.example.com"
    # LDAP base, e.g. 'cn=users,dc=i,dc=example,dc=com'. OPTIONAL
    SetEnv LDAP_BASE           "cn=users,dc=i,dc=example,dc=com"
    # LDAP admin user used for querying users, e.g. 'adminuser'. OPTIONAL
    SetEnv LDAP_ADMIN_USER     "ldapadmin"
    # LDAP admin password
    SetEnv LDAP_ADMIN_PASSWORD "password"
    
    # OPTIONAL SSH/LOCAL AUTHENTICATION PARAMETERS
    # URL used for SSH connections, e.g. the login node. OPTIONAL
    # SetEnv SSH_SERVER_URL     "slurm01.example.com"
    
    # OPTIONAL JWT CONFIGURATION
    # Path to the JWT key. OPTIONAL
    SetEnv JWT_PATH            "/var/lib/slurm-dashboard/jwt.key"
    # Username of the Slurm user. Defaults to 'slurm'. OPTIONAL
    # SetEnv SLURM_USER        "slurm"
    
    # Privileged users (OPTIONAL)
    # SetEnv PRIV_USERS        "user1,user2"
</VirtualHost>
```
5) Make sure that your apache2 config file is not readable by other users (especially if it contains passwords)!
```bash
chown root:root /etc/apache2/sites-available/slurm-dashboard.conf
chmod 600 /etc/apache2/sites-available/slurm-dashboard.conf
```
6) Optionally (but strongly recommended), enable SSL etc.
7) Start or restart the services:
```
systemctl enable --now slurmrestd
systemctl enable --now apache2
# or
systemctl restart slurmrestd
systemctl restart apache2
```
8) You might also want to edit the default page in `templates/about.html`.
9) Give the files the correct permissions (recommended especially if you set passwords in `globals.inc.php` instead of the apache config):
```bash
find /var/www/slurm-dashboard -type f -exec chmod 640 {} \;
```

### Update
Once you have set up the dashboard, you can use the following bash script to upgrade to a new version. (It might also be helpful for the initial setup.)

```bash
#!/bin/bash

# TODO: REPLACE THE FOLLOWING VARIABLES or leave the value "TO_BE_REPLACED"
# Replace by your web directory
WEB_DIRECTORY="/var/www/slurm-dashboard"
DASHBOARD_USER="slurm-dashboard"
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

    find $WEB_DIRECTORY -type f -exec chmod 640 {} \;
fi
```

## License
This project is currently licensed under the [MIT License](https://github.com/nikolaussuess/slurm-dashboard/blob/master/LICENSE).

## Contact
Nikolaus Süß, University of Vienna, Faculty of Computer Science, Research Group Visualization and Data Analysis, see <a href="https://ufind.univie.ac.at/de/person.html?id=109904">u:find</a>.

Feel free to add new features and create pull requests, or add issues.