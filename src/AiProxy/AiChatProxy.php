<?php declare(strict_types=1);

namespace Base3IliasTools\AiProxy;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;

final class AiChatProxy implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'aichatproxy';
	}

	public function getHelp() {
		return "REST proxy for LLM chat completions.\n"
			. "POST JSON body compatible with OpenAI-style APIs.\n"
			. "Set stream=true to enable SSE streaming.\n"
			. "Header: X-Proxy-Token.\n";
	}

	public function getOutput($out = 'html') {
		$assistantCfg = (array)($this->configuration->get('assistant') ?? []);
		$proxyCfg     = (array)($this->configuration->get('aiproxy') ?? []);

		$upstream_url       = (string)($assistantCfg['endpoint'] ?? '');
		$upstream_api_token = (string)($assistantCfg['apikey'] ?? '');
		$proxy_access_token = (string)($proxyCfg['apikey'] ?? '');

		if ($upstream_url === '' || $upstream_api_token === '' || $proxy_access_token === '') {
			http_response_code(500);
			return json_encode([
				'error' => 'Proxy misconfigured (missing endpoint/apikey).',
			], JSON_UNESCAPED_UNICODE);
		}

		// Only allow POST requests
		if ($this->request->server('REQUEST_METHOD') !== 'POST') {
			http_response_code(405);
			return json_encode([
				'error' => 'Method Not Allowed. Use POST.',
			], JSON_UNESCAPED_UNICODE);
		}

		// Auth (client -> proxy)
		$clientToken = $this->request->server('HTTP_X_PROXY_TOKEN');
		if ($clientToken !== $proxy_access_token) {
			http_response_code(401);
			return json_encode([
				'error' => 'Unauthorized. Invalid proxy token.',
			], JSON_UNESCAPED_UNICODE);
		}

		// Read JSON body
		$payload = $this->request->getJsonBody();
		if (empty($payload) || !is_array($payload)) {
			http_response_code(400);
			return json_encode([
				'error' => 'Invalid or missing JSON body.',
			], JSON_UNESCAPED_UNICODE);
		}

		$isStream = (($payload['stream'] ?? false) === true);

		if ($isStream) {
			// Ensure stream=true upstream
			$payload['stream'] = true;

			$this->handleStream($upstream_url, $upstream_api_token, $payload);
			exit; // Important: stop framework output handling
		}

		// Non-stream mode: explicitly disable streaming
		$payload['stream'] = false;

		return $this->handleNonStream($upstream_url, $upstream_api_token, $payload);
	}

	private function handleNonStream(string $upstream_url, string $upstream_api_token, array $payload): string {
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
		$httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($responseBody === false) {
			$error = curl_error($ch);
			curl_close($ch);

			http_response_code(502);
			return json_encode([
				'error'   => 'Upstream request failed',
				'details' => $error,
			], JSON_UNESCAPED_UNICODE);
		}

		curl_close($ch);

		// Forward upstream status code
		http_response_code($httpCode);

		// Return upstream body unchanged
		return (string)$responseBody;
	}

	private function handleStream(string $upstream_url, string $upstream_api_token, array $payload): void {
		// SSE headers for the client
		header('X-Accel-Buffering: no'); // Nginx: disable buffering
		header('Cache-Control: no-cache, no-transform');
		header('Connection: keep-alive');
		header('Content-Type: text/event-stream; charset=utf-8');

		// Disable PHP output buffering/compression as much as possible
		@ini_set('zlib.output_compression', '0');
		@ini_set('output_buffering', '0');
		@ini_set('implicit_flush', '1');

		// Clear any active output buffers
		while (ob_get_level() > 0) {
			@ob_end_flush();
		}
		ob_implicit_flush(true);

		// Send an initial comment to open SSE quickly (optional)
		echo ": proxy stream started\n\n";
		$this->flushNow();

		$ch = curl_init($upstream_url);

		curl_setopt_array($ch, [
			CURLOPT_POST => true,

			// Important: do not buffer response
			CURLOPT_RETURNTRANSFER => false,

			// Stream chunks via WRITEFUNCTION
			CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) {
				// Pass through exactly (upstream already formats SSE lines)
				echo $chunk;
				$this->flushNow();
				return strlen($chunk);
			},

			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: text/event-stream',
				'Authorization: Bearer ' . $upstream_api_token,
			],

			CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),

			// Keep it alive
			CURLOPT_TIMEOUT => 0, // No overall timeout
			CURLOPT_CONNECTTIMEOUT => 15,
		]);

		$ok = curl_exec($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($ok === false) {
			// In streaming mode, we might have already sent SSE headers.
			// Best effort: send an SSE error event.
			$err = curl_error($ch);
			curl_close($ch);

			echo "event: error\n";
			echo "data: " . json_encode([
				'error' => 'Upstream request failed',
				'details' => $err,
			], JSON_UNESCAPED_UNICODE) . "\n\n";
			$this->flushNow();
			return;
		}

		curl_close($ch);

		// If upstream returned non-2xx, we cannot change HTTP status/headers anymore.
		// Best effort: emit an SSE error event.
		if ($httpCode >= 400) {
			echo "event: error\n";
			echo "data: " . json_encode([
				'error'  => 'Upstream error',
				'status' => $httpCode,
			], JSON_UNESCAPED_UNICODE) . "\n\n";
			$this->flushNow();
			return;
		}

		// Normal end (upstream usually sends: data: [DONE])
		echo ": proxy stream finished\n\n";
		$this->flushNow();
	}

	private function flushNow(): void {
		// Flush PHP + webserver buffers
		@ob_flush();
		@flush();
	}
}
