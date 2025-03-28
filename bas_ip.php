<?php
/**
 * PHP BAS_IP API command executor for Camdroid panels /v0
 * https://developers.bas-ip.com/Camdroid-panels/
 * 
 * (c) Mikhail Deynekin
 * Date: 2025-03-28 12:00:00 UTC
 * Key changes:
 * - Moved sensitive configuration to config.php
 * - Enhanced security with input validation and error handling
 * - Added detailed comments for maintainability
 * - Optimized cURL usage and resource management
 * Version: 1.05.0
 */

namespace ApiClient;

use RuntimeException;

// Load configuration
$config = require 'config.php';

class AuthenticationClient
{
	public readonly string $baseUrl;
	public readonly array $allowedIps;
	public readonly string $tokenFile;
	public readonly int $tokenTtl;
	public readonly string $login;
	public readonly string $password;

	/**
	 * Initializes the client with configuration values
	 */
	public function __construct(
		string $baseUrl,
		string $login,
		string $password,
		array $allowedIps,
		string $tokenFile,
		int $tokenTtl
	) {
		$this->baseUrl = $baseUrl;
		$this->allowedIps = $allowedIps;
		$this->tokenFile = $tokenFile;
		$this->tokenTtl = $tokenTtl;
		$this->login = $login;
		$this->password = $password;
	}

	/**
	 * Validates client IP against allowed list; exits with 403 if unauthorized
	 * Modify $allowedIps in config.php to change access permissions
	 * 
	 * @param string $remoteAddr Client IP address
	 */
	public function validateIp(string $remoteAddr): void
	{
		if (!in_array($remoteAddr, $this->allowedIps, true)) {
			header('HTTP/1.1 403 Forbidden');
			exit('Access Denied');
		}
	}

	/**
	 * Retrieves or refreshes the authentication token
	 * 
	 * @return string Authentication token
	 * @throws RuntimeException On token retrieval failure
	 */
	public function getToken(): string
	{
		if (
			file_exists($this->tokenFile) &&
			(time() - filemtime($this->tokenFile)) < $this->tokenTtl
		) {
			$data = file_get_contents($this->tokenFile) ?: throw new RuntimeException('Failed to read token file');
			$tokenData = json_decode($data, true) ?: throw new RuntimeException('Invalid token format');
			return $tokenData['token'] ?? throw new RuntimeException('Token missing in cache');
		}
		return $this->refreshToken();
	}

	/**
	 * Refreshes the token via API login request
	 * 
	 * @return string New token
	 * @throws RuntimeException On refresh failure
	 */
	protected function refreshToken(): string
	{
		$url = $this->baseUrl . 'login?username=' . urlencode($this->login) . '&password=' . urlencode($this->password);
		$response = $this->curlGetContents($url);
		$data = json_decode($response, true) ?: throw new RuntimeException('Invalid API response');
		$token = $data['token'] ?? throw new RuntimeException('Token not found');

		file_put_contents($this->tokenFile, json_encode(['token' => $token, 'timestamp' => time()]), LOCK_EX)
			?: throw new RuntimeException('Failed to write token');
		chmod($this->tokenFile, 0600);
		return $token;
	}

	/**
	 * Executes a GET request via cURL
	 * 
	 * @param string $url Request URL
	 * @return string|bool Response or false on failure
	 */
	protected function curlGetContents(string $url): string|bool
	{
		return $this->executeCurlRequest([
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => false,
		]);
	}

	/**
	 * Executes a cURL request with custom options
	 * 
	 * @param array $curlOptions cURL options
	 * @return mixed Response
	 * @throws RuntimeException On cURL failure
	 */
	public function executeCurlRequest(array $curlOptions): mixed
	{
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_TIMEOUT => 10,
		] + $curlOptions);

		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new RuntimeException("cURL error: $error");
		}
		curl_close($ch);
		return $response;
	}
}

enum Command: string
{
	case STATUS = 'status';
	case GET_TIME = 'get_time';
	case GET_CAMERAS = 'get_cameras';
	case REBOOT = 'reboot';
	case LOCK_OPEN = 'lock_open';
	case SIP_DISABLE = 'sip_disable';
	case SIP_ENABLE = 'sip_enable';
}

try {
	$client = new AuthenticationClient(
		$config['baseUrl'],
		$config['login'],
		$config['password'],
		$config['allowedIps'],
		$config['tokenFile'],
		$config['tokenTtl']
	);

	$client->validateIp($_SERVER['REMOTE_ADDR']);
	define('TOKEN', $client->getToken());

	$cmd = Command::tryFrom(mb_strtolower(trim($_REQUEST['cmd'] ?? '')))
		?? throw new RuntimeException('Invalid command');

	$response = match ($cmd) {
		Command::STATUS => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'sip/status',
			CURLOPT_HTTPHEADER => ['Accept: application/json'],
		]),
		Command::GET_TIME => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'device/time',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . TOKEN],
		]),
		Command::GET_CAMERAS => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'security/ipcam',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . TOKEN],
		]),
		Command::REBOOT => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'system/reboot/run',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . TOKEN],
		]),
		Command::LOCK_OPEN => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'access/general/lock/open/remote/accepted/1',
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . TOKEN],
		]),
		Command::SIP_ENABLE => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'device/sip/enable',
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'Authorization: Bearer ' . TOKEN,
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => json_encode([
				'sip_enable' => true,
				'is_reregister' => true,
				'reregister_time' => 315,
			]),
		]),
		Command::SIP_DISABLE => $client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'device/sip/enable',
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'Authorization: Bearer ' . TOKEN,
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => json_encode([
				'sip_enable' => false,
				'is_reregister' => true,
				'reregister_time' => 315,
			]),
		]),
	};

	if ($cmd === Command::REBOOT) {
		$client->executeCurlRequest([
			CURLOPT_URL => $client->baseUrl . 'logout',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . TOKEN],
		]);
		if (file_exists($client->tokenFile)) {
			unlink($client->tokenFile);
		}
	}

	echo $response ?: 'Command executed successfully';

} catch (RuntimeException $e) {
	header('HTTP/1.1 500 Internal Server Error');
	exit('Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
