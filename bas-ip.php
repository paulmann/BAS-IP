<?php
/**
 * BAS-IP API Control Panel Interface
 * 
 * (c) Mikhail Deynekin
 * Date: 2025-03-28 12:00:00 UTC
 * Key changes:
 * - Replaced SSI with PHP for IP validation
 * - Added detailed JavaScript comments
 * - Improved security and usability
 * Version: 1.04.0
 */

$config = require 'config.php';
if (!in_array($_SERVER['REMOTE_ADDR'], $config['allowedIps'], true)) {
	header('HTTP/1.1 403 Forbidden');
	echo '<h1>FORBIDDEN</h1>';
	echo htmlspecialchars($_SERVER['REMOTE_ADDR']);
	exit;
}
?>

<!DOCTYPE HTML>
<html lang="en">
<head>
	<title>BAS-IP Control Panel</title>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
	<link rel="stylesheet" href="css/main.css" />
	<link rel="icon" href="/favicon.svg" type="image/svg">
</head>
<body class="is-preload">
	<div id="confirmationModal" class="confirmation-modal">
		<div class="modal-content">
			<h3>Confirm Action</h3>
			<p id="modalMessage">Are you sure you want to perform this action?</p>
			<div class="modal-buttons">
				<button class="button biggest alt" onclick="processConfirmation(false)">Cancel</button>
				<button class="button biggest" onclick="processConfirmation(true)">Confirm</button>
			</div>
		</div>
	</div>

	<div id="wrapper">
		<section id="status-panel" class="wrapper style1 fade-up">
			<div class="inner">
				<h2>System Status</h2>
				<div class="features">
					<section>
						<span class="icon solid major fa-network-wired"></span>
						<h3>Current Status</h3>
						<p><code id="status">Initializing...</code></p>
					</section>
				</div>
			</div>
		</section>

		<section id="controls" class="wrapper style2 spotlights">
			<div class="inner">
				<h2>Controls</h2>
				<div class="split style1">
					<section>
						<ul class="actions">
							<li><button class="button primary icon solid fa-door-open" id="openBtn">Open</button></li>
							<li><button class="button icon solid fa-sync-alt" id="rebootBtn">Reboot</button></li>
						</ul>
					</section>
					<section>
						<ul class="actions stacked">
							<li><button class="button" onclick="sendCommand('status')"><i class="icon solid fa-heartbeat"></i> Check Status</button></li>
							<li><button class="button" onclick="sendCommand('get_time')"><i class="icon solid fa-clock"></i> Device Time</button></li>
							<li><button class="button" onclick="sendCommand('sip_enable')"><i class="icon solid fa-heartbeat"></i> Enable SIP</button></li>
							<li><button class="button" onclick="sendCommand('sip_disable')"><i class="icon solid fa-clock"></i> Disable SIP</button></li>
						</ul>
					</section>
				</div>
			</div>
		</section>

		<section id="camera" class="wrapper style3 fade-up">
			<div class="inner">
				<h2>Video Surveillance</h2>
				<div class="box alt">
					<div class="row gtr-uniform">
						<div class="col-12">
							<div class="image fit" id="camera-container">
								<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
									alt="Video Stream" 
									id="camera-image"
									style="min-height: 480px;">
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</div>

	<script src="assets/js/jquery.min.js"></script>
	<script src="assets/js/jquery.scrollex.min.js"></script>
	<script src="assets/js/jquery.scrolly.min.js"></script>
	<script src="assets/js/browser.min.js"></script>
	<script src="assets/js/breakpoints.min.js"></script>
	<script src="assets/js/util.js"></script>
	<script src="assets/js/main.js"></script>
	<script>
		let currentCommand = null;
		let lastImageUrl = null;

		// Formats device time from UNIX timestamp and timezone
		const formatDeviceTime = (unix, tz) => {
			try {
				const date = new Date(unix * 1000);
				return date.toLocaleString('en-US', {
					timeZone: tz.replace('UTC', 'Etc/GMT'),
					year: 'numeric',
					month: 'long',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit'
				}) + ` (${tz})`;
			} catch (e) {
				console.error(e);
				return `Invalid data: ${unix} | ${tz}`;
			}
		};

		// Sends commands to bas_ip.php and updates status
		const sendCommand = async (cmd) => {
			const statusEl = document.getElementById('status');
			try {
				statusEl.textContent = 'Processing request...';
				statusEl.style.color = '#ffd700';
				const response = await fetch(`/bas_ip.php?cmd=${cmd}`);
				if (!response.ok) throw new Error(`HTTP ${response.status}`);
				const data = await response.json();

				switch (cmd) {
					case 'status':
						statusEl.textContent = `SIP Status: ${data.sip_status}`;
						break;
					case 'get_time':
						statusEl.textContent = `Device Time: ${formatDeviceTime(data.device_time_unix, data.device_timezone)}`;
						break;
					case 'lock_open':
						statusEl.textContent = 'Opening barrier...';
						setTimeout(() => sendCommand('status'), 3000);
						break;
					default:
						statusEl.textContent = data.error ? `Error: ${data.error}` : 'Command executed successfully';
				}
				statusEl.style.color = data.error ? '#ff4444' : '#00ff00';
			} catch (e) {
				statusEl.textContent = `Error: ${e.message}`;
				statusEl.style.color = '#ff4444';
				console.error(e);
			}
		};

		// Displays confirmation modal for critical actions
		const showConfirmation = (message, command) => {
			currentCommand = command;
			document.getElementById('modalMessage').textContent = message;
			document.getElementById('confirmationModal').style.display = 'flex';
		};

		// Processes modal confirmation
		const processConfirmation = (confirmed) => {
			document.getElementById('confirmationModal').style.display = 'none';
			if (confirmed && currentCommand) sendCommand(currentCommand);
			currentCommand = null;
		};

		// Updates camera image periodically
		function updateCameraImage() {
			const img = document.getElementById('camera-image');
			const container = document.getElementById('camera-container');
			fetch('/get_last_image.php')
				.then(response => {
					if (!response.ok) throw new Error('Network error');
					return response.text();
				})
				.then(newUrl => {
					if (newUrl && newUrl !== lastImageUrl) {
						container.classList.add('loading');
						const cacheBustedUrl = `${newUrl}?t=${Date.now()}`;
						img.src = '';
						img.src = cacheBustedUrl;
						img.onload = () => {
							container.classList.remove('loading');
							lastImageUrl = newUrl;
						};
						img.onerror = () => container.classList.remove('loading');
					}
				})
				.catch(error => {
					console.error('Fetch error:', error);
					container.classList.remove('loading');
				});
		}

		// Event listeners
		document.getElementById('openBtn').addEventListener('click', () =>
			showConfirmation('Are you sure you want to open the barrier?', 'lock_open')
		);
		document.getElementById('rebootBtn').addEventListener('click', () =>
			showConfirmation('Are you sure you want to reboot the device?', 'reboot')
		);
		window.addEventListener('DOMContentLoaded', () => {
			sendCommand('status');
			setInterval(() => sendCommand('status'), 300000); // 5 min
			updateCameraImage();
			setInterval(updateCameraImage, 5000); // 5 sec
		});
		window.addEventListener('click', (e) => {
			if (e.target.matches('.confirmation-modal')) processConfirmation(false);
		});
	</script>
</body>
</html>
