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
    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider;

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
    $markdownContent = $this->htmlToMarkdown($content);
    $summaryResult = $this->fetchSummary($oai_provider, array(
      'url' => $oai_url,
      'key' => $oai_key,
      'model' => $oai_model,
      'prompt' => $oai_prompt,
      'content' => $markdownContent,
    ));

    if ($summaryResult['error'] !== null) {
      http_response_code($summaryResult['status']);
      $this->sendJson(array(
        'response' => array(
          'data' => $summaryResult['error'],
          'error' => 'api'
        ),
        'status' => $summaryResult['status']
      ));
      return;
    }

    http_response_code($summaryResult['status']);
    $this->sendJson(array(
      'response' => array(
        'data' => array(
          'summary' => $summaryResult['summary']
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
        'temperature' => 0.7,
        'stream' => false,
      );

      if ($provider === 'openai') {
        $payload['max_completion_tokens'] = 2048;
      } else {
        $payload['max_tokens'] = 2048;
        $payload['n'] = 1;
      }
    }

    $response = $this->performHttpRequest($endpoint, $payload, $config['key']);
    if ($response['error'] !== null) {
      return $response;
    }

    $body = (string)$response['body'];
    $json = json_decode($body, true);

    if ($response['status'] < 200 || $response['status'] >= 300) {
      $message = $this->extractProviderErrorMessage($response['status'], $json, $body);
      return array(
        'status' => $response['status'],
        'summary' => null,
        'error' => $message,
      );
    }

    if (!is_array($json)) {
      return array(
        'status' => $response['status'],
        'summary' => null,
        'error' => 'Invalid JSON response from provider',
      );
    }

    if ($provider === 'ollama') {
      $summary = isset($json['response']) ? (string)$json['response'] : '';
    } else {
      $summary = isset($json['choices'][0]['message']['content']) ? (string)$json['choices'][0]['message']['content'] : '';
    }

    if ($summary === '') {
      return array(
        'status' => $response['status'],
        'summary' => null,
        'error' => 'Provider returned an empty summary',
      );
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
          if (isset($error['message']) && trim((string)$error['message']) !== '') {
            return (string)$error['message'];
          }
          if (isset($error['code']) && trim((string)$error['code']) !== '') {
            return 'Provider error: ' . (string)$error['code'];
          }
        }
      }

      if (isset($decodedBody['message']) && trim((string)$decodedBody['message']) !== '') {
        return (string)$decodedBody['message'];
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
