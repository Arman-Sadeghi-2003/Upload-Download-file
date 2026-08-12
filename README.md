# 📁 File Hub

> A self-hosted file sharing hub for local networks — upload, download, and control access by IP address. Built with PHP and runs on IIS.

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat&logo=php&logoColor=white)
![IIS](https://img.shields.io/badge/IIS-Windows-0078D6?style=flat&logo=windows&logoColor=white)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue?style=flat)

---

## ✨ Features

| Feature | Description |
|---|---|
| 📤 Upload | Drag & drop or browse — supports large files (MP4, ZIP, etc.) |
| ☁️ Private / 🌍 Public | Two drop zones — restrict an upload to a set of IPs, or share it with everyone |
| 📥 Download | Access files from any device on the network — resumable, with HTTP Range support |
| 🌍 IP Control | Whitelist or blacklist IPs globally or per file |
| 🔐 Admin Panel | Password-protected panel to manage rules, defaults, limits, and files |
| 📋 Access Logs | See who accessed, downloaded, or was blocked |
| 🎨 Modern UI | Clean dark theme, no frameworks needed |

---

## 🖥️ Preview

| Main Hub | Admin Panel |
|---|---|
| Upload & download files | Manage IP rules & logs |

---

## 📦 Requirements

- **OS**: Windows
- **Web Server**: IIS with FastCGI
- **PHP**: 8.0 or higher
- **PHP Extensions**: `session`, `json`

---

## 🚀 Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/yourusername/file-hub.git
cd file-hub

# 2. Create required folders
mkdir uploads data

# 3. Set write permissions on uploads/ and data/
#    Right-click → Properties → Security
#    Add IIS_IUSRS → check Modify → Apply

# 4. Create an IIS site pointing to the project folder
#    IIS Manager → Sites → Add Website → choose port

# 5. Add web.config to project root (see below)

# 6. Set PHP limits in php.ini (see below)

# 7. Restart IIS
iisreset

# 8. Open in browser
start http://localhost:[YOUR_PORT]/
```

---

## ⚙️ Configuration

### `config.php`

```php
define('ADMIN_PASSWORD', 'your_password');         // Change this!
define('MAX_FILE_SIZE',  500 * 1024 * 1024);       // 500 MB — fallback only
define('DEFAULT_ACCESS_IPS', ['::1']);             // Fallback only
```

> `MAX_FILE_SIZE` and `DEFAULT_ACCESS_IPS` are only used on a fresh install. Once
> saved from the admin panel, the live values live in `data/settings.json`.
> The upload limit is clamped to 1 MB – 5 GB regardless of what is stored.

### `web.config` (project root)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>

    <defaultDocument enabled="true">
      <files>
        <clear />
        <add value="index.php" />
      </files>
    </defaultDocument>

    <directoryBrowse enabled="false" />

    <security>
      <requestFiltering>
        <!-- 600 MB = 629145600 bytes -->
        <requestLimits maxAllowedContentLength="629145600" />
      </requestFiltering>
    </security>

  </system.webServer>

  <system.web>
    <!-- 600 MB in KB -->
    <httpRuntime maxRequestLength="614400" executionTimeout="600" />
  </system.web>
</configuration>
```

### `php.ini`

```ini
upload_max_filesize = 500M
post_max_size       = 520M
max_execution_time  = 600
max_input_time      = 600
memory_limit        = 512M
```

> After editing `php.ini`, run `iisreset` to apply changes.

---

## 🌐 Accessing from Other Devices

```bash
# Find your server IP
ipconfig

# Other devices on the same network open:
http://192.168.x.x:[YOUR_PORT]/
```

---

## 🔒 IP Access Control

The admin panel supports three rule formats:

```
192.168.1.10        # single IP
192.168.1.*         # wildcard
192.168.1.0/24      # CIDR range
```

| Mode | Behavior |
|---|---|
| **Blacklist** (default) | Everyone allowed except blocked IPs |
| **Whitelist** | Only listed IPs allowed, all others denied |
| **Per-file rules** | Override global rules for a specific file |

### Private vs. public uploads

The hub has two drop zones:

- **☁️ Private** — the file is restricted to the *default IP list* plus the uploader's own IP. Nobody else sees it in the listing or can download it.
- **🌍 Public** — no per-file restrictions. Everyone the global rules allow can see and download it.

The default IP list is managed in **Admin Panel → Global Rules → Default IPs for New Uploads**. It applies to new private uploads only; existing files keep the rules they already have. `::1` is always in the list so the admin never loses access.

---

## 📂 Project Structure

```
file-hub/
├── config.php          # Settings, IP logic, helpers
├── index.php           # Main hub page
├── upload.php          # File upload handler
├── download.php        # File download handler
├── api.php             # Admin API endpoints
├── admin.php           # Admin panel
├── web.config          # IIS configuration
├── docs/
│   └── project-overview.md   # Architecture & data model
├── assets/
│   ├── css/
│   │   ├── style.css   # Hub styles
│   │   └── admin.css   # Admin styles
│   └── js/
│       ├── hub.js      # Hub scripts
│       └── admin.js    # Admin scripts
├── uploads/            # Uploaded files (auto-created)
└── data/               # JSON storage & logs (auto-created)
```

---

## ⚠️ Security Notice

This project is designed for **local / LAN use only**.  
If exposing to the internet, consider:

- Enabling **HTTPS**
- Using a stronger **admin password**
- Restricting admin access to localhost only
