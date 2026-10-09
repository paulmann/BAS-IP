# BAS-IP API Client and Control Panel
Multi-apartment panels API 1.x.x Administrator interface for quick check status, reboot and other
This project provides a PHP-based API client and web interface for interacting with BAS-IP Camdroid panels using their /v0 API.

---------------------------------------------------------------------------------------------
## Features
---------------------------------------------------------------------------------------------
- Secure authentication and token management
- API commands: status check, time retrieval, reboot, lock control, SIP enable/disable
- Web interface with real-time status updates and camera feed
- Confirmation prompts for critical actions
- IP-based access control

---------------------------------------------------------------------------------------------
## Requirements
---------------------------------------------------------------------------------------------
- PHP 8.3+
- cURL extension
- Web server (e.g., Apache, Nginx)
- Write access for token storage

---------------------------------------------------------------------------------------------
## Installation
---------------------------------------------------------------------------------------------
1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/bas-ip-client.git
2. Copy files to your web server directory.
3. Edit config.php with your BAS-IP device details:
- baseUrl: API endpoint
- login/password: API credentials
- allowedIps: Authorized client IPs
- tokenFile: Secure path for token storage
4. Configure your web server to serve bas-ip.php and bas_ip.php as PHP 8.3 or higher version

---------------------------------------------------------------------------------------------
## Usage
---------------------------------------------------------------------------------------------
Access bas-ip.php in your browser from an allowed IP.
Use the interface to:
- Check system status
- Open the lock
- Reboot the device
- Enable/disable SIP
Status updates every 5 minutes; camera refreshes every 5 seconds.

---------------------------------------------------------------------------------------------
## Security
---------------------------------------------------------------------------------------------
Store config.php and the token file outside the web root or protect them with server rules.
Restrict access to trusted IPs via allowedIps.
Use HTTPS to encrypt communications.

---------------------------------------------------------------------------------------------
## Version
---------------------------------------------------------------------------------------------
bas_ip.php: 1.05.0
bas-ip.php: 1.04.0
