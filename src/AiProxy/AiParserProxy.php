<?php declare(strict_types=1);

namespace Base3IliasTools\AiProxy;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;

class AiParserProxy implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'aiparserproxy';
	}

	public function getHelp() {
		return "REST proxy for file conversion parser.\n"
			. "POST multipart/form-data with field 'file'.\n"
			. "Header: X-Proxy-Token.\n";
	}

	public function getOutput($out = 'html') {

		$upstream_url = (string)$this->configuration->get('assistant')['parserendpoint'] ?? '';
		$upstream_api_token = (string)$this->configuration->get('assistant')['apikey'] ?? '';
		$proxy_access_token = (string)$this->configuration->get('aiproxy')['apikey'] ?? '';

		// Only allow POST requests
		if ($this->request->server('REQUEST_METHOD') !== 'POST') {
			http_response_code(405);
			return json_encode(['error' => 'Method Not Allowed. Use POST.']);
		}

		// Check proxy access token (client -> proxy)
		$clientToken = $this->request->server('HTTP_X_PROXY_TOKEN');
		if ($clientToken !== $proxy_access_token) {
			http_response_code(401);
			return json_encode(['error' => 'Unauthorized. Invalid proxy token.']);
		}

		// Expect multipart upload
		if (empty($_FILES) || empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
			http_response_code(400);
			return json_encode([
				'error' => "Missing upload. Use multipart/form-data field 'file'.",
			]);
		}

		$upload = $_FILES['file'];

		// Basic upload validation
		if (!empty($upload['error']) && $upload['error'] !== UPLOAD_ERR_OK) {
			http_response_code(400);
			return json_encode([
				'error' => 'File upload error',
				'details' => $upload['error'],
			]);
		}

		$tmpPath = $upload['tmp_name'];
		$filename = $upload['name'] ?? 'upload.bin';
		$mime = $upload['type'] ?? 'application/octet-stream';

		// Build multipart body for upstream
		$cfile = new \CURLFile($tmpPath, $mime, $filename);
		$postFields = [
			'file' => $cfile,
		];

		// Optional: pass through additional form fields if your parser supports them
		// foreach ($_POST as $k => $v) {
		// 	$postFields[$k] = $v;
		// }

		$ch = curl_init($upstream_url);

		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => $postFields,
			CURLOPT_HTTPHEADER => [
				'Authorization: Bearer ' . $upstream_api_token,
				// Do NOT set Content-Type! cURL sets the multipart boundary correctly.
			],
			CURLOPT_TIMEOUT => 300,
		]);

		$responseBody = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		// $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); // If you want to forward it, read it before close.

		if ($responseBody === false) {
			$error = curl_error($ch);
			curl_close($ch);

			http_response_code(502);
			return json_encode([
				'error' => 'Upstream request failed',
				'details' => $error,
			]);
		}

		curl_close($ch);

		// Forward upstream HTTP status code
		http_response_code($httpCode);

		// Forward upstream content-type if provided (usually application/json)
		// if (!empty($contentType)) {
		// 	header('Content-Type: ' . $contentType);
		// }

		return $responseBody;
	}
}
