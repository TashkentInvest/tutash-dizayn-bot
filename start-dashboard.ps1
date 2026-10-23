# Starts the admin dashboard on http://127.0.0.1:8000/admin (needs PHP 8.4; the default `php` on this PC is 8.1).
# PHPRC points PHP at web/phpini/php.ini, which allows uploads of up to 2 GB.
$php = "C:\Users\inves\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"
$env:Path = "$php;" + $env:Path
Set-Location "$PSScriptRoot\web"
$env:PHPRC = "$PSScriptRoot\web\phpini"
php artisan serve --port=8000
