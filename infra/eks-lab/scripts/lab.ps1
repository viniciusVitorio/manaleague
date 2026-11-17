# Forward arguments without shell interpolation to the dedicated lab WSL distro.
# Example: ./infra/eks-lab/scripts/lab.ps1 validate
$ErrorActionPreference = 'Stop'
$scriptPath = (Join-Path $PSScriptRoot 'lab.sh').Replace('\', '/')
# WSL's native argument parsing treats backslashes as escapes; use D:/... here.
$rawPath = & wsl.exe -d manaleague-docker -- wslpath -u $scriptPath
if ($LASTEXITCODE -ne 0 -or -not $rawPath) { throw 'Cannot resolve lab script in WSL distro manaleague-docker.' }
$linuxPath = ([string]$rawPath).Trim()
& wsl.exe -d manaleague-docker -- bash $linuxPath @args
exit $LASTEXITCODE
