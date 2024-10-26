# Slurm dashboard
This is a small dashboard that displays some information about a slurm cluster. It is written in PHP.

## Current features
- display cluster usage information (state, CPUs, memory, gpus per node)
- display the current job queue, including a job detail page
- authentication via LDAP or local, and it additionally checks whether the user exists in the accounting database
- communication with `slurmrestd` via unix socket
- caching of the `slurmrestd` responses (using APCu)
- display and filter job history (from `slurmdbd`)
- display list of users with their respective accounts (for administrators only)

### Planned features
- Allow administrators to requeue / cancel / ... jobs.
- Allow users to requeue and cancel their own jobs.
- Allow users to submit jobs via a web interface.

## Requirements
- PHP (currently only tested with PHP 8.2)<br>
  including
  - `php-apcu` for caching `slurmrestd` responses
  - `php_ldap` for LDAP authentication (if you are using LDAP; optional)
  - `php_ssh2` for local authentication (if you are using local authentication; optional)
- Apache2<br>
  including
  - `libapache2-mpm-itk` in order to use change to user to the same `slurmrestd` is running with.
  - `libapache2-mod-php`
- `slurmrestd` using Unix sockets, running with the same user that the website is running with, and `Environment=SLURMRESTD_SECURITY=disable_user_check` set.

## Setup
1. Install the requirements.
2. Add a unix user (and group) `slurm-dashboard`, preferably in the system UID range (e.g. 994 or 996).
3. Adapt the `slurmrestd` unit file (e.g. `/etc/systemd/slurmrestd.service.d/slurmrestd.conf`) like e.g.:
```
[Service]
# Unset vendor unit ExecStart to avoid cumulative definition
ExecStart=
Environment=
# Disable slurm user security check
Environment=SLURMRESTD_SECURITY=disable_user_check
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

## License
To be decided ...

## Contact
Nikolaus Süß, University of Vienna, Research Group Visualization and Data Analysis, see <a href="https://ufind.univie.ac.at/de/person.html?id=109904">u:find</a>.

Feel free to add new features and create pull requests, or add issues.