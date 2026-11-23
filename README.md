# MSP Tools — Multi-Brand Mobile Service Platform (Node.js + PHP Dual Engine)

Repository: `https://github.com/KhunMyintAung/MSP_Tools`

Zero external `npm` packages required. Runs directly on Windows using either **Node.js** (`MSP_Tools_Node.cmd`) or **PHP 8.2+** (`MSP_Tools_PHP.cmd`), sharing the exact same UI (`public/index.html`) and REST API contract (`/api/health`, `/api/scan`, `/api/execute`).

## Quick Run on Windows

- **Using Node.js:** Double-click `MSP_Tools_Node.cmd` (or run `node node-engine/server.js`)
- **Using PHP:** Double-click `MSP_Tools_PHP.cmd` (or run `php -S 127.0.0.1:8910 -t public php-engine/router.php`)

## Push Update to GitHub

```powershell
git add .
git commit -m "feat: add unified Node.js and PHP dual-runtime engine for MSP Tools"
git push -u origin main
```
