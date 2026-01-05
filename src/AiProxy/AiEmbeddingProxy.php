<?php declare(strict_types=1);

namespace Base3IliasTools\AiProxy;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;

class AiEmbeddingProxy implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'aiembeddingproxy';
	}

	public function getHelp() {
		return "REST proxy for LLM embedding endpoint.\n".
		       "POST JSON body compatible with embedding APIs.\n";
	}

	public function getOutput($out = 'html') {

		$upstream_url = (string)$this->configuration->get('assistant')['embeddingendpoint'] ?? '';
		$upstream_api_token = (string)$this->configuration->get('assistant')['apikey'] ?? '';
		$proxy_access_token = (string)$this->configuration->get('aiproxy')['apikey'] ?? '';

		// Only allow POST requests
		if ($this->request->server('REQUEST_METHOD') !== 'POST') {
			http_response_code(405);
			return json_encode([
				'error' => 'Method Not Allowed. Use POST.'
			]);
		}

		// Check proxy access token (client -> proxy)
		$clientToken = $this->request->server('HTTP_X_PROXY_TOKEN');
		if ($clientToken !== $proxy_access_token) {
			http_response_code(401);
			return json_encode([
				'error' => 'Unauthorized. Invalid proxy token.'
			]);
		}

		// Read JSON body from request
		$payload = $this->request->getJsonBody();
		if (empty($payload)) {
			http_response_code(400);
			return json_encode([
				'error' => 'Invalid or missing JSON body.'
			]);
		}

		// Initialize upstream request
		$ch = curl_init($upstream_url);

		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $upstream_api_token,
			],
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_TIMEOUT        => 120,
		]);

		$responseBody = curl_exec($ch);
		$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($responseBody === false) {
			$error = curl_error($ch);
			curl_close($ch);

			http_response_code(502);
			return json_encode([
				'error'   => 'Upstream request failed',
				'details' => $error,
			]);
		}

		curl_close($ch);

		// Forward upstream HTTP status code
		http_response_code($httpCode);

		// Return upstream response body unchanged
		return $responseBody;
	}
}
