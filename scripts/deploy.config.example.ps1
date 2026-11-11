# Deploy configuration TEMPLATE. Copy this file to `deploy.config.ps1` (which is
# untracked; see .gitignore) and fill in your values. scripts/deploy.ps1
# dot-sources deploy.config.ps1 to resolve every environment-specific setting
# when it isn't passed as a -param or set via the matching $env:DEPLOY_* variable.
#
# Resolution order per setting (first non-empty wins):
#   1. the script -parameter (e.g. -VpsHost, -WebRoot)
#   2. the environment variable (e.g. $env:DEPLOY_VPS_HOST, $env:DEPLOY_WEB_ROOT)
#   3. the $Deploy* value below (this file's copy)
#
# Keeping these here (not in the tracked deploy.ps1) means the repo never reveals
# the host, SSH user, key path, or filesystem layout.

# deploy.ps1 reads these after dot-sourcing this file, so they're used, just not here.
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSUseDeclaredVarsMoreThanAssignments', '')]
param()

$DeployVpsHost = "<your-droplet-ip-or-hostname>"
$DeployVpsUser = "<ssh-user>"                        # e.g. root or a deploy user
$DeployKeyPath = "<path-to-your-deploy-key.ppk>"
$DeployWebRoot = "<laravel-app-folder-on-host>"     # e.g. /var/www/gallery.example.com (its public/ is the DocumentRoot)
# The folder under /etc/letsencrypt/live whose certificate covers the site's name; the vhost uses it.
$DeployCertName = "<certificate-folder-name>"       # e.g. example.com
# The address Apache shows on its error pages (ServerAdmin in the vhost).
$DeployServerAdmin = "<admin-email-address>"        # e.g. webmaster@example.com
