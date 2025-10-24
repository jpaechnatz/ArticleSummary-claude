<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
  /** @var bool */
  private $isAjax = false;

  public function firstAction(): void
  {
    $this->isAjax = Minz_Request::paramBoolean('ajax');
    if ($this->isAjax) {
      $this->view->_layout(null);
      Minz_Request::_param('ajax');
    }
  }

  public function indexAction()
  {
    if ($this->isAjax) {
      $this->renderSummaryResponse();
      return;
    }

    Minz_Request::forward(array('c' => 'index', 'a' => 'index'), true);
  }

  public function summarizeAction()
  {
    $this->renderSummaryResponse();
  }

  private function renderSummaryResponse(): void
  {
    $oai_url = $this->getUserConfigValue('oai_url');
    $oai_key = $this->getUserConfigValue('oai_key');
    $oai_model = $this->getUserConfigValue('oai_model');
    $oai_prompt = $this->getUserConfigValue('oai_prompt');
    $oai_provider = strtolower((string)$this->getUserConfigValue('oai_provider', 'openai'));
    if (!in_array($oai_provider, array('openai', 'mistral', 'ollama'), true)) {
      $oai_provider = 'openai';
    }
    $oai_temperature = $this->getUserConfigValue('oai_temperature');
    $oai_max_tokens = $this->getUserConfigValue('oai_max_tokens');

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      $this->sendJson(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    $entry_id = Minz_Request::paramString('id');
    if ($entry_id === '' && isset($_POST['id'])) {
      $entry_id = trim((string)$_POST['id']);
    }
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      http_response_code(404);
      $this->sendJson(array('status' => 404));
      return;
    }

    $content = $entry->content(); // Replace with article content

    // Process $oai_url
    $oai_url = rtrim($oai_url, '/'); // Remove trailing slash
    if (!preg_match('/\/v\d+\/?$/', $oai_url)) {
        $oai_url .= '/v1'; // If there is no version information, add /v1
    }
    $temperature = $this->normalizeTemperature($oai_temperature);
    $maxTokens = $this->normalizeMaxTokens($oai_max_tokens);

    $markdownContent = $this->htmlToMarkdown($content);
    $summaryResult = $this->fetchSummary($oai_provider, array(
      'url' => $oai_url,
      'key' => $oai_key,
      'model' => $oai_model,
      'prompt' => $oai_prompt,
      'content' => $markdownContent,
      'temperature' => $temperature,
      'max_tokens' => $maxTokens,
    ));

    if ($summaryResult['error'] !== null) {
      Minz_Log::warning('ArticleSummary: Provider error (' . $summaryResult['status'] . '): ' . $summaryResult['error']);
      $status = $summaryResult['status'] >= 400 ? $summaryResult['status'] : 502;
      http_response_code($status);
      $this->sendJson(array(
        'response' => array(
          'data' => $summaryResult['error'],
          'error' => 'provider'
        ),
        'status' => $status
      ));
      return;
    }

    $summaryText = isset($summaryResult['summary']) ? trim((string)$summaryResult['summary']) : '';
    if ($summaryText === '') {
      $message = 'Provider returned an empty summary';
      Minz_Log::warning('ArticleSummary: ' . $message . ' (' . $summaryResult['status'] . ')');
      http_response_code(502);
      $this->sendJson(array(
        'response' => array(
          'data' => $message,
          'error' => 'provider'
        ),
        'status' => 502
      ));
      return;
    }

    http_response_code(200);
    $this->sendJson(array(
      'response' => array(
        'data' => array(
          'summary' => $summaryText
        ),
        'error' => null
      ),
      'status' => 200
    ));
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function getUserConfigValue(string $key, $default = null)
  {
    $conf = FreshRSS_Context::$user_conf;
    if (isset($conf->$key)) {
      return $conf->$key;
    }

    return $default;
  }

  private function sendJson(array $payload): void
  {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  }

  /**
   * @return array{status:int, summary:string|null, error:string|null}
   */
  private function fetchSummary($provider, array $config): array
  {
    $endpoint = $config['url'];

    if ($provider === 'ollama') {
      $endpoint .= '/api/generate';
      $payload = array(
        'model' => $config['model'],
        'system' => $config['prompt'],
        'prompt' => $config['content'],
        'stream' => false,
      );
      if ($config['temperature'] !== null) {
        $payload['temperature'] = $config['temperature'];
      }
      if ($config['max_tokens'] > 0) {
        $payload['max_tokens'] = $config['max_tokens'];
      }
    } else {
      $endpoint .= '/chat/completions';
      $payload = array(
        'model' => $config['model'],
        'messages' => array(
          array(
            'role' => 'system',
            'content' => $config['prompt'],
          ),
          array(
            'role' => 'user',
            'content' => "input: \n" . $config['content'],
          ),
        ),
        'stream' => false,
      );

      if ($provider === 'openai') {
        $temperature = $this->resolveOpenAiTemperature($config['model'], $config['temperature']);
        if ($temperature !== null) {
          $payload['temperature'] = $temperature;
        }
        if ($config['max_tokens'] > 0) {
          $payload['max_completion_tokens'] = $config['max_tokens'];
          if ($this->isGpt5Model($config['model'])) {
            $payload['max_output_tokens'] = $config['max_tokens'];
          }
        }
      } else {
        if ($config['temperature'] !== null) {
          $payload['temperature'] = $config['temperature'];
        }
        if ($config['max_tokens'] > 0) {
          $payload['max_tokens'] = $config['max_tokens'];
        }
      }
    }

    $response = $this->performHttpRequest($endpoint, $payload, $config['key']);
    if ($response['error'] !== null) {
      return array(
        'status' => $response['status'],
        'summary' => '',
        'error' => $response['error'],
      );
    }

    $body = (string)$response['body'];
    $json = json_decode($body, true);

    if ($response['status'] < 200 || $response['status'] >= 300) {
      $message = $this->extractProviderErrorMessage($response['status'], $json, $body);
      return array(
        'status' => $response['status'],
        'summary' => '',
        'error' => $message,
      );
    }

    if (!is_array($json)) {
      return array(
        'status' => $response['status'],
        'summary' => '',
        'error' => 'Invalid JSON response from provider',
      );
    }

    if ($provider === 'ollama') {
      $summary = isset($json['response']) ? (string)$json['response'] : '';
    } else {
      $refusalMessage = $this->extractOpenAiRefusal($json);
      if ($refusalMessage !== null) {
        return array(
          'status' => $response['status'],
          'summary' => '',
          'error' => $refusalMessage,
        );
      }

      $summary = $this->extractOpenAiSummary($json);
    }

    return array(
      'status' => $response['status'],
      'summary' => $summary,
      'error' => null,
    );
  }

  /**
   * @return array{status:int, body:string|null, error:string|null}
   */
  private function performHttpRequest(string $url, array $payload, string $apiKey): array
  {
    if (!function_exists('curl_init')) {
      return array(
        'status' => 500,
        'body' => null,
        'error' => 'cURL extension is not available on the server',
      );
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error !== null) {
      return array(
        'status' => 500,
        'body' => null,
        'error' => $error,
      );
    }

    return array(
      'status' => $status,
      'body' => $body === false ? null : $body,
      'error' => null,
    );
  }

  private function extractProviderErrorMessage(int $status, $decodedBody, string $rawBody): string
  {
    if (is_array($decodedBody)) {
      if (isset($decodedBody['error'])) {
        $error = $decodedBody['error'];
        if (is_string($error) && trim($error) !== '') {
          return $error;
        }

        if (is_array($error)) {
          if (isset($error['message'])) {
            $message = $error['message'];
            if (is_string($message) && trim($message) !== '') {
              return $message;
            }
            if (is_array($message)) {
              $encoded = json_encode($message);
              if ($encoded !== false) {
                return $encoded;
              }
            }
          }
          if (isset($error['code'])) {
            $code = $error['code'];
            if (is_string($code) && trim($code) !== '') {
              return 'Provider error: ' . $code;
            }
          }
        }
      }

      if (isset($decodedBody['message'])) {
        $message = $decodedBody['message'];
        if (is_string($message) && trim($message) !== '') {
          return $message;
        }
        if (is_array($message)) {
          $encoded = json_encode($message);
          if ($encoded !== false) {
            return $encoded;
          }
        }
      }
    }

    $trimmedBody = trim($rawBody);
    if ($trimmedBody === '') {
      return 'Provider request failed with HTTP ' . $status;
    }

    if (strlen($trimmedBody) > 500) {
      $trimmedBody = substr($trimmedBody, 0, 500) . '…';
    }

    return $trimmedBody;
  }

  private function resolveOpenAiTemperature(string $model, ?float $configuredTemperature): ?float
  {
    if (preg_match('/^gpt-5/i', $model)) {
      return null;
    }

    return $configuredTemperature;
  }

  private function isGpt5Model(string $model): bool
  {
    return preg_match('/^gpt-5/i', $model) === 1;
  }

  private function extractOpenAiSummary(array $decoded): string
  {
    if (!isset($decoded['choices'][0]['message']) || !is_array($decoded['choices'][0]['message'])) {
      return '';
    }

    $message = $decoded['choices'][0]['message'];
    if (!isset($message['content'])) {
      return '';
    }

    $parts = array();
    $this->collectOpenAiContent($message['content'] ?? null, $parts);

    if (empty($parts) && isset($message['text']) && is_string($message['text'])) {
      $parts[] = trim($message['text']);
    }

    $parts = array_values(array_filter(array_map(function ($fragment) {
      return trim((string)$fragment);
    }, $parts), function ($fragment) {
      return $fragment !== '';
    }));

    return trim(implode("\n", $parts));
  }

  private function extractOpenAiRefusal(array $decoded): ?string
  {
    if (!isset($decoded['choices'][0]['message']) || !is_array($decoded['choices'][0]['message'])) {
      return null;
    }

    $message = $decoded['choices'][0]['message'];
    if (!isset($message['refusal'])) {
      return null;
    }

    $refusal = $message['refusal'];
    if (is_array($refusal) && isset($refusal['text']) && is_string($refusal['text'])) {
      $refusal = $refusal['text'];
    }

    if (!is_string($refusal)) {
      return null;
    }

    $refusal = trim($refusal);
    if ($refusal === '') {
      return null;
    }

    return $refusal;
  }

  private function collectOpenAiContent($node, array &$parts): void
  {
    if ($node === null) {
      return;
    }

    if (is_string($node)) {
      $parts[] = $node;
      return;
    }

    if (is_array($node)) {
      foreach ($node as $key => $value) {
        if ($key === 'type' || $key === 'role' || $key === 'refusal') {
          continue;
        }
        $this->collectOpenAiContent($value, $parts);
      }
    }
  }

  private function normalizeTemperature($value): ?float
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (!is_numeric($value)) {
      return 0.7;
    }

    $temperature = (float)$value;
    if ($temperature < 0) {
      $temperature = 0.0;
    } elseif ($temperature > 2) {
      $temperature = 2.0;
    }

    return $temperature;
  }

  private function normalizeMaxTokens($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }

    if (!is_numeric($value)) {
      return 0;
    }

    $tokens = (int)$value;
    if ($tokens < 1) {
      return 0;
    }

    return $tokens;
  }

  private function htmlToMarkdown($content)
  {
    // Create DOMDocument object
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath object
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Process text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Process element nodes
      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'a':
            // $markdown .= "[";
            // foreach ($node->childNodes as $child) {
            //   $markdown .= $processNode($child);
            // }
            // $markdown .= "](" . $node->getAttribute('href') . ")";
            $markdown .= "`";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

}
