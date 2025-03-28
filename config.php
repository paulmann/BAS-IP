<?php
/**
 * Configuration file for BAS-IP API client and control panel
 * 
 * This file contains sensitive information such as API credentials and access settings.
 * Ensure it is stored outside the web-accessible directory or protected via server configuration (e.g., .htaccess).
 * 
 * @return array Configuration settings
 */
return [
	'baseUrl' => 'http://1.0.0.1:80/api/v0/',	// Base URL of the BAS-IP device API
	'login' => 'admin',				// API username
	'password' => 'password',			// API password
	'allowedIps' => ['1.1.1.1', '8.8.8.8'],	// IPs allowed to access the API and interface
	'tokenFile' => __DIR__ . '/token/.api_token',	// Path to store the token (must be secure and writable)
	'tokenTtl' => 1600,				// Token time-to-live in seconds
];
